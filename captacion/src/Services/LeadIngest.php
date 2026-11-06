<?php
/**
 * Motor de captacion (doc §5). Punto de entrada unico para todas las fuentes:
 *   LeadIngest::handle(array $data, string $source, array $ctx = []): array
 *
 * Pasos: 1 validar/normalizar (LeadPayload) -> 2 antispam (honeypot, 5/h por IP, correos desechables) -> 3 deduplicar (3 reglas del doc)
 *        -> 4 FMCSA (si hay USDOT; sin llave o con la API caida el lead NO se bloquea: bandera fmcsa_pending + job fmcsa_enrich)
 *        -> 5 puntuar (Scoring) -> 6 encolar acciones segun la temperatura. Lead + eventos + consentimiento + jobs se guardan en UNA transaccion.
 *
 * $ctx: ip, public (bool: aplica el limite de 5 envios/hora por IP), skip_antispam (importaciones), quiet (no encola alertas ni avisos; importaciones),
 *       fmcsa (instancia de Fmcsa, para pruebas), fmcsa_sync (false = no consultar FMCSA en linea; solo encolar), fmcsa_data (resultado normalizado ya conocido: se usa tal cual).
 *
 * Resultado: ['ok'=>bool, 'status'=>created|reentry|reopened|signed_reentry|duplicate_id|rejected, 'lead_id'=>?int, 'reason'=>?string, 'errors'=>[campo=>codigo],
 *             'score'=>?int, 'temperature'=>?string, 'flags'=>[...]]. Lanza solo ante errores de infraestructura (base caida...): quien llama decide.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Models\Lead;

final class LeadIngest
{
    /** Minutos por defecto de la segunda alerta A3 (el valor vigente sale de Alerts::followupMinutes(), editable en Configuracion). */
    public const FOLLOWUP_MINUTES = Alerts::DEFAULTS['followup_minutes'];

    public static function handle(array $data, string $source, array $ctx = []): array
    {
        $source = substr((string) preg_replace('/[^a-z0-9_]/', '', strtolower($source)), 0, 40) ?: 'api';
        $ip = isset($ctx['ip']) && is_string($ctx['ip']) ? $ctx['ip'] : '';
        $reject = static fn(string $reason, array $errors = []): array => ['ok' => false, 'status' => 'rejected', 'lead_id' => null, 'reason' => $reason, 'errors' => $errors, 'score' => null, 'temperature' => null, 'flags' => []];

        // 2) Antispam (primero lo barato: honeypot; despues el limite por IP; el correo desechable se mira tras normalizar)
        if (empty($ctx['skip_antispam'])) {
            if (isset($data['website']) && $data['website'] !== '' && $data['website'] !== null) {
                return $reject('honeypot');
            }
            if (!empty($ctx['public']) && $ip !== '' && RateLimiter::tooMany('ingest', $ip, Env::int('INGEST_RATE_LIMIT', 5), 3600)) {
                return $reject('rate_limited');
            }
        }

        // 1) Validacion y normalizacion
        $norm = LeadPayload::normalize($data);
        if ($norm['errors']) {
            return $reject('invalid', $norm['errors']);
        }
        $lead = $norm['lead'];
        $flags = $norm['flags'];
        if (empty($ctx['skip_antispam']) && $lead['email'] !== null && Disposable::isDisposable($lead['email'])) {
            return $reject('disposable_email', ['email' => 'disposable']);
        }
        if (!empty($ctx['public']) && $ip !== '' && empty($ctx['skip_antispam'])) {
            RateLimiter::hit('ingest', $ip, 3600);
        }

        // Idempotencia por id del lead del sitio (reimportar el JSONL no duplica)
        if ($lead['site_lead_id'] !== null) {
            $dup = Db::one('SELECT id, score, temperature FROM leads WHERE site_lead_id = ?', [$lead['site_lead_id']]);
            if ($dup !== null) {
                return ['ok' => true, 'status' => 'duplicate_id', 'lead_id' => (int) $dup['id'], 'reason' => null, 'errors' => [], 'score' => (int) $dup['score'], 'temperature' => (string) $dup['temperature'], 'flags' => []];
            }
        }

        // 4) FMCSA en linea (fuera de la transaccion: no se retiene ningun bloqueo mientras se espera a la API)
        $fm = null;
        $fmPending = false;
        if (is_array($ctx['fmcsa_data'] ?? null)) {
            $fm = $ctx['fmcsa_data'];   // datos de FMCSA ya conocidos (conversion de un prospecto del censo publico): no se consulta ni se encola nada
        } elseif ($lead['usdot'] !== null) {
            if (($ctx['fmcsa_sync'] ?? true) === false) {
                $fmPending = true;
            } else {
                try {
                    $fm = ($ctx['fmcsa'] ?? new Fmcsa())->lookup($lead['usdot']);
                } catch (FmcsaUnavailable $e) {
                    $fmPending = true;
                    Storage::log('fmcsa', 'lookup diferido usdot=' . $lead['usdot'] . ' :: ' . $e->getMessage());
                }
            }
        }

        if (!Db::lock('captacion_ingest', 5)) {
            throw new \RuntimeException('El motor de captacion esta ocupado (GET_LOCK).');
        }
        try {
            return Db::transaction(static fn() => self::persist($lead, $flags, $source, $ctx, $fm, $fmPending));
        } finally {
            Db::unlock('captacion_ingest');
        }
    }

    /** Pasos 3, 5 y 6 dentro de la transaccion. */
    private static function persist(array $lead, array $flags, string $source, array $ctx, ?array $fm, bool $fmPending): array
    {
        $quiet = !empty($ctx['quiet']);
        $now = Clock::now();
        if ($lead['referral_code'] !== null) {
            $refRow = Referrals::find($lead['referral_code']);
            $lead['referral_code'] = $refRow !== null ? (string) $refRow['code'] : null;   // codigo inexistente o desactivado: se ignora sin error y sin puntos de referido; si existe, se guarda en su forma canonica
        }
        if ($fm !== null) {
            $lead['fmcsa_data'] = $fm;
            if (($fm['found'] ?? false) && ($fm['authority_age_months'] ?? null) !== null) {
                $lead['authority_age_months'] = (int) $fm['authority_age_months'];
            }
        }

        $existing = Dedupe::find($lead['phone_e164'], $lead['email']);
        if ($existing === null) {
            return self::create($lead, $flags, $source, $fm, $fmPending, $quiet);
        }
        $leadId = (int) $existing['id'];

        // Regla 3: ya firmado -> evento + alerta a Carlos, sin lead nuevo
        if (Dedupe::isSigned($existing)) {
            self::event($leadId, 'reentry_signed', ['source' => $source, 'landing_page' => $lead['landing_page']]);
            if (!$quiet) {
                Queue::push('telegram_alert', ['lead_id' => $leadId, 'kind' => 'signed_reentry'], $now, 'telegram_signed:' . $leadId . ':' . gmdate('Ymd', Clock::time()));
            }
            return self::result('signed_reentry', $leadId, $existing);
        }

        // Regla 2: perdido hace mas de 90 dias -> se reabre como un intento nuevo
        $reopen = Dedupe::isReopenable($existing);
        // Regla 1: abierto (o perdido hace poco) -> evento "reingreso" + completar campos vacios
        $updates = $reopen ? self::overwrite($existing, $lead) : self::fillEmpty($existing, $lead);
        $filled = array_keys($updates);
        if ($fm !== null) {
            $updates['fmcsa_data'] = $fm;
            $updates['fmcsa_checked_at'] = $now;
            if (($fm['found'] ?? false) && ($fm['authority_age_months'] ?? null) !== null) {
                $updates['authority_age_months'] = (int) $fm['authority_age_months'];
            }
        }
        $attempt = (int) $existing['attempt'];
        if ($reopen) {
            $attempt++;
            $updates += ['stage' => 'new', 'stage_changed_at' => $now, 'lost_reason' => null, 'lost_at' => null, 'first_contact_at' => null, 'attempt' => $attempt, 'source' => $source];
        }
        $merged = array_merge($existing, $updates);
        $merged['fmcsa_data'] = is_array($merged['fmcsa_data'] ?? null) ? $merged['fmcsa_data'] : Db::decode($merged['fmcsa_data'] ?? null);
        $mflags = Db::decode($existing['flags']);
        if ($fmPending && ($merged['usdot'] ?? null) !== null) {
            $mflags = Flags::add($mflags, 'fmcsa_pending');
        }
        $mflags = Flags::add($mflags, ...array_values(array_diff($flags, ['phone_invalid'])));
        $scored = Scoring::score($merged);
        $mflags = Flags::derive($mflags, $merged);
        $updates += ['score' => $scored['score'], 'temperature' => $scored['temperature'], 'score_breakdown' => $scored['breakdown'], 'disqualified_reason' => $scored['disqualified_reason'], 'flags' => $mflags];
        Db::update('leads', $leadId, $updates);
        if (isset($updates['referral_code'])) {
            Referrals::attach($leadId, $updates['referral_code']);
        }

        if ($lead['consent']['given']) {
            Consent::record($leadId, $lead['consent'], $lead['consent']['channels'] ?? Consent::FORM_CHANNELS);
        }
        $daysLost = $reopen ? (int) floor((Clock::time() - (int) Clock::parse((string) $existing['lost_at'])) / 86400) : null;
        self::event($leadId, $reopen ? 'reopened' : 'reentry', [
            'source' => $source, 'filled' => $filled, 'landing_page' => $lead['landing_page'], 'utm' => $lead['utm'],
            'attempt' => $attempt, 'days_since_lost' => $daysLost, 'lost_recently' => ($existing['stage'] ?? '') === 'lost' && !$reopen,
        ]);
        if ((int) $existing['score'] !== $scored['score'] || $existing['temperature'] !== $scored['temperature']) {
            self::event($leadId, 'scored', ['score' => $scored['score'], 'previous' => (int) $existing['score'], 'temperature' => $scored['temperature'], 'reason' => $reopen ? 'reopened' : 'reentry']);
        }
        if ($scored['temperature'] === 'disqualified' && $existing['temperature'] !== 'disqualified') {
            self::event($leadId, 'disqualified', ['reasons' => $scored['breakdown']['disqualified']]);
        }
        if ($fm !== null) {
            self::event($leadId, 'fmcsa', self::fmcsaSummary($fm));
        }
        if (!$quiet) {
            if ($reopen) {
                self::enqueueFor($leadId, $scored['temperature'], $attempt);
            } else {
                if ($scored['temperature'] === 'hot' && $existing['temperature'] !== 'hot') {
                    self::enqueueFor($leadId, 'hot', $attempt);
                }
                self::pushEmail($leadId, 'reentry', null);
            }
        }
        if ($fmPending && ($merged['usdot'] ?? null) !== null) {
            Queue::push('fmcsa_enrich', ['lead_id' => $leadId], $now, 'fmcsa_enrich:' . $leadId . ':' . $merged['usdot']);
        }
        return self::result($reopen ? 'reopened' : 'reentry', $leadId, array_merge($existing, ['score' => $scored['score'], 'temperature' => $scored['temperature'], 'flags' => $mflags]));
    }

    private static function create(array $lead, array $flags, string $source, ?array $fm, bool $fmPending, bool $quiet): array
    {
        $now = Clock::now();
        if ($fmPending) {
            $flags[] = 'fmcsa_pending';
        }
        $lead['source'] = $source;
        $scored = Scoring::score($lead);
        $flags = Flags::derive($flags, $lead);

        $row = $lead;
        unset($row['consent']);
        $row += [
            'score' => $scored['score'], 'temperature' => $scored['temperature'], 'score_breakdown' => $scored['breakdown'], 'disqualified_reason' => $scored['disqualified_reason'],
            'pipeline' => $lead['profile'] === 'company_driver' ? 'B' : 'A', 'stage' => 'new', 'attempt' => 1,
            'fmcsa_data' => $fm, 'fmcsa_checked_at' => $fm !== null ? $now : null, 'flags' => $flags,
            'updated_at' => $now,
        ];
        $leadId = Db::insert('leads', $row);
        if ($lead['referral_code'] !== null) {
            Referrals::attach($leadId, $lead['referral_code']);
        }

        self::event($leadId, 'created', [
            'source' => $source, 'site_lead_id' => $lead['site_lead_id'], 'landing_page' => $lead['landing_page'], 'utm' => $lead['utm'],
            'consent_given' => $lead['consent']['given'], 'flags' => $flags,
        ], $lead['created_at']);
        if ($lead['consent']['given']) {
            Consent::record($leadId, $lead['consent'], $lead['consent']['channels'] ?? Consent::FORM_CHANNELS);
        }
        if ($fm !== null) {
            self::event($leadId, 'fmcsa', self::fmcsaSummary($fm));
        }
        self::event($leadId, 'scored', ['score' => $scored['score'], 'temperature' => $scored['temperature'], 'reason' => 'created']);
        if ($scored['temperature'] === 'disqualified') {
            self::event($leadId, 'disqualified', ['reasons' => $scored['breakdown']['disqualified']]);
        }
        if (!$quiet) {
            self::enqueueFor($leadId, $scored['temperature'], 1);
        }
        if ($fmPending) {
            Queue::push('fmcsa_enrich', ['lead_id' => $leadId], $now, 'fmcsa_enrich:' . $leadId . ':' . $lead['usdot']);
        }
        return self::result('created', $leadId, $row);
    }

    /** Paso 6: acciones segun la temperatura y los ajustes de Alertas. Caliente: A2 inmediato + A3 a los N min. Tibio: correo + (si esta activado) Telegram. Frio: correo. Descartado: nada. */
    public static function enqueueFor(int $leadId, string $temperature, int $attempt = 1): void
    {
        $now = Clock::now();
        $cfg = Alerts::get();
        if ($temperature === 'hot') {
            if ($cfg['hot_telegram']) {
                Queue::push('telegram_alert', ['lead_id' => $leadId, 'kind' => 'hot'], $now, 'telegram_alert:' . $leadId . ':' . $attempt);
                if ($cfg['followup_telegram']) {
                    Queue::push('telegram_alert_followup', ['lead_id' => $leadId], Clock::shift($cfg['followup_minutes'] * 60), 'telegram_followup:' . $leadId . ':' . $attempt);
                }
            } else {
                self::pushEmail($leadId, 'new_lead', 'email_notify:' . $leadId . ':' . $attempt);   // sin Telegram para los calientes, que al menos lleguen por correo
            }
        } elseif ($temperature === 'warm' || $temperature === 'cold') {
            self::pushEmail($leadId, 'new_lead', 'email_notify:' . $leadId . ':' . $attempt);
            if ($temperature === 'warm' && Alerts::warmTelegram($cfg)) {
                Queue::push('telegram_alert', ['lead_id' => $leadId, 'kind' => 'warm'], Alerts::nonUrgentRunAt(), 'telegram_warm:' . $leadId . ':' . $attempt);
            }
            if ($temperature === 'warm') {
                try {
                    Sequences::enrollWarm($leadId);   // A6: seguimiento por correo de los tibios (solo si las secuencias estan encendidas)
                } catch (\Throwable $t) {
                    Storage::error('enrollWarm failed', get_class($t) . ': ' . $t->getMessage());
                }
            }
        }
    }

    /** Aviso por correo (no urgente: respeta el horario de silencio). No hace nada si el correo esta apagado en Alertas. */
    private static function pushEmail(int $leadId, string $kind, ?string $dedupe): void
    {
        if (Alerts::get()['email']) {
            Queue::push('email_notify', ['lead_id' => $leadId, 'kind' => $kind], Alerts::nonUrgentRunAt(), $dedupe);
        }
    }

    /**
     * Recalcula puntuacion, temperatura y banderas de un lead (doc §6: "se recalcula cada vez que cambia un dato").
     * Si pasa a caliente, encola A2/A3 (los jobs son idempotentes por clave).
     * @return array{score:int,temperature:string,previous_score:int,previous_temperature:string}|null null si el lead no existe
     */
    public static function rescore(int $leadId, string $reason = 'data_changed', bool $enqueue = true): ?array
    {
        return Db::transaction(static function () use ($leadId, $reason, $enqueue) {
            $lead = Lead::find($leadId, true);
            if ($lead === null) {
                return null;
            }
            $scored = Scoring::score($lead);
            $flags = Flags::derive($lead['flags'], $lead);
            $changed = (int) $lead['score'] !== $scored['score'] || $lead['temperature'] !== $scored['temperature'];
            Db::update('leads', $leadId, ['score' => $scored['score'], 'temperature' => $scored['temperature'], 'score_breakdown' => $scored['breakdown'], 'disqualified_reason' => $scored['disqualified_reason'], 'flags' => $flags]);
            if ($changed) {
                self::event($leadId, 'scored', ['score' => $scored['score'], 'previous' => (int) $lead['score'], 'temperature' => $scored['temperature'], 'reason' => $reason]);
                if ($scored['temperature'] === 'disqualified') {
                    self::event($leadId, 'disqualified', ['reasons' => $scored['breakdown']['disqualified']]);
                }
                if ($enqueue && $lead['first_contact_at'] === null && (($scored['temperature'] === 'hot' && $lead['temperature'] !== 'hot')
                    || ($scored['temperature'] === 'warm' && !in_array($lead['temperature'], ['hot', 'warm'], true)))) {
                    self::enqueueFor($leadId, $scored['temperature'], (int) $lead['attempt']);
                }
            }
            return ['score' => $scored['score'], 'temperature' => $scored['temperature'], 'previous_score' => (int) $lead['score'], 'previous_temperature' => (string) $lead['temperature']];
        });
    }

    /** Guarda el resultado de FMCSA en el lead, quita fmcsa_pending, registra el evento y reevalua. Lo usa el job fmcsa_enrich. */
    public static function applyFmcsa(int $leadId, array $fm): void
    {
        Db::transaction(static function () use ($leadId, $fm): void {
            $lead = Lead::find($leadId, true);
            if ($lead === null) {
                throw new PermanentJobFailure('El lead #' . $leadId . ' ya no existe.');
            }
            $upd = ['fmcsa_data' => $fm, 'fmcsa_checked_at' => Clock::now(), 'flags' => Flags::remove($lead['flags'], 'fmcsa_pending')];
            if (($fm['found'] ?? false) && ($fm['authority_age_months'] ?? null) !== null) {
                $upd['authority_age_months'] = (int) $fm['authority_age_months'];
            }
            Db::update('leads', $leadId, $upd);
            self::event($leadId, 'fmcsa', self::fmcsaSummary($fm));
            self::rescore($leadId, 'fmcsa');
        });
    }

    // --- Utilidades ---------------------------------------------------------------------------------------------

    /** Campos que SOLO se completan si el lead existente los tiene vacios. */
    private const FILLABLE = ['last_name', 'phone_e164', 'phone_raw', 'email', 'state', 'city', 'cdl_class', 'cdl_type', 'english_level', 'years_experience', 'equipment_types',
        'truck_count', 'authority_status', 'usdot', 'endorsements', 'contact_pref', 'best_time', 'job_slug', 'utm', 'landing_page', 'referrer', 'referral_code'];

    private static function empty(mixed $v): bool
    {
        return $v === null || $v === '' || $v === [];
    }

    private static function fillEmpty(array $existing, array $new): array
    {
        $u = [];
        foreach (self::FILLABLE as $f) {
            if (self::empty($existing[$f] ?? null) && !self::empty($new[$f] ?? null)) {
                $u[$f] = $new[$f];
            }
        }
        if (($u['phone_e164'] ?? null) !== null && !isset($u['phone_raw'])) {
            $u['phone_raw'] = $new['phone_raw'];
        }
        return $u;
    }

    /** Reapertura (nuevo intento): los datos nuevos reemplazan a los viejos donde vengan informados. */
    private static function overwrite(array $existing, array $new): array
    {
        $u = [];
        foreach (array_merge(self::FILLABLE, ['first_name', 'language']) as $f) {
            if (!self::empty($new[$f] ?? null) && ($new[$f] ?? null) !== ($existing[$f] ?? null)) {
                $u[$f] = $new[$f];
            }
        }
        return $u;
    }

    private static function fmcsaSummary(array $fm): array
    {
        return ['found' => $fm['found'] ?? false, 'authority_status' => $fm['authority_status'] ?? null, 'insured' => $fm['insured'] ?? null, 'red_flags' => $fm['red_flags'] ?? [], 'authority_age_months' => $fm['authority_age_months'] ?? null];
    }

    public static function event(int $leadId, string $type, array $payload = [], ?string $at = null): void
    {
        Db::insert('lead_events', ['lead_id' => $leadId, 'type' => $type, 'payload' => $payload ?: null, 'created_at' => $at ?? Clock::now()]);
    }

    private static function result(string $status, int $leadId, array $row): array
    {
        return ['ok' => true, 'status' => $status, 'lead_id' => $leadId, 'reason' => null, 'errors' => [], 'score' => (int) ($row['score'] ?? 0), 'temperature' => (string) ($row['temperature'] ?? ''), 'flags' => is_array($row['flags'] ?? null) ? $row['flags'] : Db::decode($row['flags'] ?? null)];
    }

    /**
     * Adaptador del lead que guarda app/forms/lead.php (formato JSONL del sitio, fase P) al formato de entrada del motor.
     * Mapeo de CDL: cdl_standard yes -> standard, no -> non_domiciled, unsure -> unsure (lo hace LeadPayload). Nunca se pregunta ni se infiere nacionalidad ni estatus migratorio.
     */
    public static function fromSiteLead(array $s): array
    {
        $att = is_array($s['attribution'] ?? null) ? $s['attribution'] : [];
        $consent = is_array($s['consent'] ?? null) ? $s['consent'] : [];
        return [
            'site_lead_id' => $s['id'] ?? null,
            'created_at'   => $s['created_at'] ?? null,
            'profile'      => $s['profile'] ?? '',
            'name'         => $s['name'] ?? '',
            'phone'        => $s['phone'] ?? '',
            'email'        => $s['email'] ?? '',
            'state'        => $s['state'] ?? '',
            'language'     => $s['language'] ?? 'en',
            'cdl_standard' => $s['cdl_standard'] ?? '',
            'english'      => $s['english'] ?? '',
            'years'        => $s['years'] ?? null,
            'trucks'       => $s['trucks'] ?? null,
            'equipment'    => $s['equipment'] ?? [],
            'authority'    => $s['authority'] ?? '',
            'contact_pref' => $s['contact_pref'] ?? '',
            'best_time'    => $s['best_time'] ?? '',
            'job'          => $s['job'] ?? '',
            'attribution'  => $att,
            'referral_code' => (string) ($att['ref'] ?? ($s['referral_code'] ?? '')),
            'consent'      => [
                'given'      => !empty($consent['given']),
                'text'       => (string) ($consent['text'] ?? ''),
                'version'    => (string) ($consent['version'] ?? ''),
                'at'         => $consent['at'] ?? null,
                'ip'         => $consent['ip'] ?? null,
                'user_agent' => $consent['user_agent'] ?? null,
            ],
        ];
    }
}
