<?php
/**
 * Prospectos FMCSA (outbound, doc §7 y §9.3): lista de llamadas del dia, resultado con un clic, historial, conversion a lead y «no llamar mas» permanente.
 * Las llamadas son MANUALES (nada de marcadores, SMS ni correo en frio desde esta fase). Estados: to_call · no_answer (hasta 3 intentos seguidos) · no_answer_closed
 * (el 3.º sin respuesta; se reabre a mano) · talked · interested · not_interested · dnc (permanente: guarda tambien el telefono en dnc_numbers).
 * Cada accion deja activity_log; nada de datos personales en las URLs (solo ids).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class Prospects
{
    public const STATUSES = [
        'to_call' => 'Por llamar', 'no_answer' => 'Sin respuesta', 'no_answer_closed' => 'Sin respuesta (cerrada)', 'talked' => 'Conversó', 'interested' => 'Interesado',
        'not_interested' => 'No interesado', 'dnc' => 'No llamar más',
    ];
    /** Resultados que se registran con un clic. */
    public const RESULTS = ['no_answer' => 'Sin respuesta', 'talked' => 'Conversó', 'interested' => 'Interesado', 'not_interested' => 'No interesado', 'dnc' => 'No llamar más'];
    /** Pestañas (contadores). 'no_answer' agrupa abiertas y cerradas. */
    public const TABS = ['today' => 'Lista de hoy', 'to_call' => 'Por llamar', 'no_answer' => 'Sin respuesta', 'talked' => 'Conversó', 'interested' => 'Interesado', 'not_interested' => 'No interesado', 'dnc' => 'No llamar más'];
    public const MAX_ATTEMPTS = 3;
    public const PER = [25, 50, 100];
    public const FLEETS = ['1' => '1 unidad', '2-5' => '2 a 5', '6+' => '6 o más'];
    public const AGES = [3 => '3 meses o menos', 6 => '6 meses o menos', 12 => '12 meses o menos'];

    private static function fail(string $msg): array
    {
        return ['ok' => false, 'msg' => $msg];
    }

    private static function ok(string $msg, array $extra = []): array
    {
        return ['ok' => true, 'msg' => $msg] + $extra;
    }

    public static function find(int $id): ?array
    {
        $r = Db::one('SELECT * FROM prospects_fmcsa WHERE id = ? AND deleted_at IS NULL', [$id]);
        if ($r === null) {
            return null;
        }
        $r['cargo'] = Db::decode($r['cargo']);
        $r['fmcsa_data'] = Db::decode($r['fmcsa_data']);
        return $r;
    }

    // --- Consultas ------------------------------------------------------------------------------------------------------

    /** Filtros de la URL, validados con lista blanca. @return array{tab:string,q:string,us:string,fl:string,ag:int,ph:string} */
    public static function filters(array $in): array
    {
        $s = static fn(string $k, int $max = 80): string => is_string($in[$k] ?? null) ? mb_substr(trim($in[$k]), 0, $max) : '';
        $tab = $s('tab', 20);
        $us = strtoupper($s('us', 2));
        $ag = (int) $s('ag', 3);
        return [
            'tab' => isset(self::TABS[$tab]) ? $tab : 'today', 'q' => $s('q', 100), 'us' => isset(UsStates::NAMES[$us]) ? $us : '', 'fl' => isset(self::FLEETS[$s('fl', 3)]) ? $s('fl', 3) : '',
            'ag' => isset(self::AGES[$ag]) ? $ag : 0, 'ph' => $s('ph', 4) === 'mail' ? 'mail' : '',
        ];
    }

    /** Condicion de la «Lista de hoy»: por llamar + sin respuesta cuyo siguiente intento ya llego. Nunca prospectos de «no llamar». */
    private static function todayClause(int $now): array
    {
        return ["(p.status = 'to_call' OR (p.status = 'no_answer' AND (p.next_call_at IS NULL OR p.next_call_at <= ?)))", [Clock::at($now)]];
    }

    /** @return array{0:string,1:list<mixed>} */
    private static function where(array $f, int $now, bool $withTab = true): array
    {
        $w = ['p.deleted_at IS NULL'];
        $params = [];
        if ($withTab) {
            switch ($f['tab']) {
                case 'today':
                    [$sql, $p] = self::todayClause($now);
                    $w[] = $sql;
                    $params = array_merge($params, $p);
                    $w[] = 'p.do_not_call = 0';
                    break;
                case 'no_answer':
                    $w[] = "p.status IN ('no_answer', 'no_answer_closed')";
                    break;
                default:
                    $w[] = 'p.status = ?';
                    $params[] = $f['tab'];
            }
        }
        if ($f['us'] !== '') {
            $w[] = 'p.state = ?';
            $params[] = $f['us'];
        }
        if ($f['fl'] === '1') {
            $w[] = 'p.fleet_size = 1';
        } elseif ($f['fl'] === '2-5') {
            $w[] = 'p.fleet_size BETWEEN 2 AND 5';
        } elseif ($f['fl'] === '6+') {
            $w[] = 'p.fleet_size >= 6';
        }
        if ($f['ag'] > 0) {
            $w[] = 'p.authority_granted_at >= ?';
            $params[] = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('America/New_York'))->modify('-' . $f['ag'] . ' months')->format('Y-m-d');
        }
        if ($f['ph'] === 'mail') {
            $w[] = 'p.email IS NOT NULL';
        }
        if ($f['q'] !== '') {
            $q = $f['q'];
            $digits = preg_replace('/\D/', '', $q) ?? '';
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $parts = ['p.legal_name LIKE ?', 'p.dba LIKE ?', 'p.city LIKE ?', 'p.email LIKE ?'];
            $params = array_merge($params, [$like, $like, $like, $like]);
            if (strlen($digits) >= 3) {
                $parts[] = 'p.usdot LIKE ?';
                $parts[] = 'p.mc_number LIKE ?';
                $parts[] = 'p.phone LIKE ?';
                $d = '%' . $digits . '%';
                $params = array_merge($params, [$d, $d, $d]);
            }
            $w[] = '(' . implode(' OR ', $parts) . ')';
        }
        return [implode(' AND ', $w), $params];
    }

    /** Conteos por pestaña respetando el resto de filtros. @return array<string,int> */
    public static function counts(array $f, int $now): array
    {
        $out = [];
        foreach (array_keys(self::TABS) as $tab) {
            [$sql, $p] = self::where(['tab' => $tab] + $f, $now);
            $out[$tab] = (int) Db::value("SELECT COUNT(*) FROM prospects_fmcsa p WHERE $sql", $p);
        }
        return $out;
    }

    /**
     * Pagina de prospectos. Orden: autoridad mas reciente primero y, a igual fecha, dato mas completo primero (en la «Lista de hoy» los reintentos vencidos van al principio).
     * @return array{rows:list<array>,total:int,pages:int,page:int}
     */
    public static function page(array $f, int $now, int $page, int $per): array
    {
        [$sql, $p] = self::where($f, $now);
        $total = (int) Db::value("SELECT COUNT(*) FROM prospects_fmcsa p WHERE $sql", $p);
        $pages = max(1, (int) ceil($total / $per));
        $page = max(1, min($page, $pages));
        $order = $f['tab'] === 'today' ? "(p.status = 'no_answer') DESC, p.authority_granted_at DESC, p.completeness DESC, p.id ASC" : 'p.authority_granted_at DESC, p.completeness DESC, p.id ASC';
        if (in_array($f['tab'], ['talked', 'interested'], true)) {
            $order = 'p.last_call_at DESC, p.id DESC';
        }
        $rows = Db::all("SELECT p.* FROM prospects_fmcsa p WHERE $sql ORDER BY $order LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per), $p);
        foreach ($rows as &$r) {
            $r['cargo'] = Db::decode($r['cargo']);
        }
        unset($r);
        return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /** Historial de llamadas de varios prospectos: id => lista (mas reciente primero). */
    public static function historyFor(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (Db::all("SELECT c.*, u.name AS user_name FROM prospect_calls c LEFT JOIN users u ON u.id = c.user_id WHERE c.prospect_id IN ($in) ORDER BY c.id DESC", $ids) as $r) {
            $out[(int) $r['prospect_id']][] = $r;
        }
        return $out;
    }

    /** Resumen para el pie de la pantalla y el panel de estado. */
    public static function summary(int $now): array
    {
        $listed = self::etDay($now);
        return [
            'total' => (int) Db::value('SELECT COUNT(*) FROM prospects_fmcsa WHERE deleted_at IS NULL'),
            'new_today' => (int) Db::value('SELECT COUNT(*) FROM prospects_fmcsa WHERE listed_on = ?', [$listed]),
            'converted' => (int) Db::value('SELECT COUNT(*) FROM prospects_fmcsa WHERE converted_lead_id IS NOT NULL'),
            'dnc' => (int) Db::value('SELECT COUNT(*) FROM dnc_numbers'),
        ];
    }

    public static function etDay(int $ts): string
    {
        return ProspectImport::etDate($ts);
    }

    /** Seguimientos de hoy o vencidos (campana). @return array{n:int,max_id:int} */
    public static function followupsDue(int $now): array
    {
        $end = Fmt::fromEtInput(self::etDay($now), true) ?? Clock::at($now);
        $r = Db::one("SELECT COUNT(*) AS n, COALESCE(MAX(id), 0) AS m FROM prospects_fmcsa WHERE deleted_at IS NULL AND do_not_call = 0 AND converted_lead_id IS NULL
            AND status IN ('no_answer', 'talked', 'interested') AND next_call_at IS NOT NULL AND next_call_at <= ?", [$end]);
        return ['n' => (int) ($r['n'] ?? 0), 'max_id' => (int) ($r['m'] ?? 0)];
    }

    // --- Acciones -------------------------------------------------------------------------------------------------------

    /** Siguiente dia habil a las 9:00 a. m. ET (UTC). */
    public static function nextBusinessDay(int $now): string
    {
        $d = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('America/New_York'))->modify('+1 day');
        while (in_array((int) $d->format('N'), [6, 7], true)) {
            $d = $d->modify('+1 day');
        }
        return Fmt::fromEtInput($d->format('Y-m-d') . 'T09:00') ?? Clock::at($now + 86400);
    }

    /**
     * Registra el resultado de una llamada. $nextDate = 'AAAA-MM-DD' opcional (ET, 9:00 a. m.). Transaccion + historial + activity_log.
     * @return array{ok:bool,msg:string,status?:string}
     */
    public static function record(int $id, string $result, mixed $note, mixed $nextDate, int $userId): array
    {
        if (!isset(self::RESULTS[$result])) {
            return self::fail('Elige un resultado de la lista.');
        }
        $note = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', mb_scrub(is_string($note) ? $note : '')));
        $note = mb_substr($note, 0, 500);
        $next = null;
        if (is_string($nextDate) && trim($nextDate) !== '') {
            $next = Fmt::fromEtInput(trim($nextDate) . 'T09:00');
            if ($next === null) {
                return self::fail('La fecha del siguiente intento no es válida.');
            }
            if ((int) Clock::parse($next) < Clock::time() - 86400) {
                return self::fail('La fecha del siguiente intento ya pasó.');
            }
        }
        try {
            return Db::transaction(static function () use ($id, $result, $note, $next, $userId): array {
                $p = Db::one('SELECT * FROM prospects_fmcsa WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
                if ($p === null) {
                    return self::fail('El prospecto no existe.');
                }
                if ((int) $p['do_not_call'] === 1) {
                    return self::fail('Este prospecto está en «No llamar más»: no se puede registrar otra llamada.');
                }
                if ($p['converted_lead_id'] !== null) {
                    return self::fail('Este prospecto ya es un lead: sigue su seguimiento desde la ficha.');
                }
                $now = Clock::now();
                $attempts = (int) $p['attempts'];
                $u = ['last_call_at' => $now, 'last_result' => $result];
                $status = $result;
                if ($result === 'no_answer') {
                    $attempts++;
                    $u['attempts'] = $attempts;
                    if ($attempts >= self::MAX_ATTEMPTS) {
                        $status = 'no_answer_closed';
                        $u += ['closed_at' => $now, 'next_call_at' => null];
                        $next = null;
                    } else {
                        $next ??= self::nextBusinessDay(Clock::time());
                        $u['next_call_at'] = $next;
                    }
                } else {
                    $u['attempts'] = 0;
                    $u['next_call_at'] = in_array($result, ['talked', 'interested'], true) ? $next : null;
                    if (in_array($result, ['not_interested', 'dnc'], true)) {
                        $u['closed_at'] = $now;
                    }
                    if ($result === 'dnc') {
                        $u += ['do_not_call' => 1, 'dnc_at' => $now, 'next_call_at' => null];
                    }
                }
                $u['status'] = $status;
                Db::update('prospects_fmcsa', $id, $u);
                Db::insert('prospect_calls', ['prospect_id' => $id, 'user_id' => $userId, 'result' => $result, 'note' => $note !== '' ? $note : null,
                    'attempt_no' => $result === 'no_answer' ? $attempts : 0, 'next_call_at' => $u['next_call_at'] ?? null, 'created_at' => $now]);
                if ($result === 'dnc' && $p['phone'] !== null) {
                    Db::run('INSERT IGNORE INTO dnc_numbers (phone_e164, usdot, prospect_id, reason, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$p['phone'], $p['usdot'], $id, 'prospect', $userId, $now]);
                }
                ActivityLog::record('prospect_call', $userId, 'prospect', $id, ['result' => $result, 'status' => $status, 'attempts' => $attempts, 'has_note' => $note !== '', 'next' => $u['next_call_at'] ?? null]);
                $msg = match ($status) {
                    'no_answer_closed' => 'Tercer intento sin respuesta: el prospecto pasó a «Sin respuesta (cerrada)». Puedes reabrirlo a mano.',
                    'no_answer' => 'Sin respuesta registrada (intento ' . $attempts . ' de ' . self::MAX_ATTEMPTS . '). Se vuelve a listar el siguiente día hábil.',
                    'dnc' => 'Marcado «No llamar más». No volverá a listarse.',
                    'interested' => 'Marcado como interesado. Conviértelo en lead para seguirlo.',
                    default => 'Resultado registrado: ' . self::RESULTS[$status] . '.',
                };
                return self::ok($msg, ['status' => $status]);
            });
        } catch (\Throwable $t) {
            Storage::error('prospect record failed', get_class($t) . ': ' . $t->getMessage());
            return self::fail('No se pudo registrar el resultado. Inténtalo de nuevo.');
        }
    }

    /** Reabre un prospecto cerrado por falta de respuesta (o «no interesado»): vuelve a «Por llamar» con 0 intentos. «No llamar más» jamas se reabre. */
    public static function reopen(int $id, int $userId): array
    {
        return Db::transaction(static function () use ($id, $userId): array {
            $p = Db::one('SELECT * FROM prospects_fmcsa WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
            if ($p === null) {
                return self::fail('El prospecto no existe.');
            }
            if ((int) $p['do_not_call'] === 1 || $p['status'] === 'dnc') {
                return self::fail('«No llamar más» es permanente: no se puede reabrir.');
            }
            if (!in_array($p['status'], ['no_answer_closed', 'not_interested'], true)) {
                return self::fail('Solo se reabren los prospectos cerrados («Sin respuesta (cerrada)» o «No interesado»).');
            }
            Db::update('prospects_fmcsa', $id, ['status' => 'to_call', 'attempts' => 0, 'next_call_at' => null, 'closed_at' => null]);
            Db::insert('prospect_calls', ['prospect_id' => $id, 'user_id' => $userId, 'result' => 'reopened', 'note' => null, 'attempt_no' => 0, 'created_at' => Clock::now()]);
            ActivityLog::record('prospect_reopen', $userId, 'prospect', $id, ['from' => $p['status']]);
            return self::ok('Prospecto reabierto: vuelve a «Por llamar».');
        });
    }

    /**
     * Convierte un prospecto en lead: fuente fmcsa_outbound, perfil owner-operator (1 unidad o sin dato) o carrier pequeño (2 o mas), datos de FMCSA ya cargados,
     * etapa «Contactado» con first_contact_at ahora, SIN consentimiento de mensajeria y SIN alertas de Telegram ni correos (quiet). Si ya existe un lead con el mismo
     * telefono o correo se enlaza a ese en lugar de crear otro.
     * @return array{ok:bool,msg:string,lead_id?:int}
     */
    public static function convert(int $id, int $userId): array
    {
        $p = self::find($id);
        if ($p === null) {
            return self::fail('El prospecto no existe.');
        }
        if ((int) $p['do_not_call'] === 1) {
            return self::fail('Este prospecto está en «No llamar más»: no se convierte en lead.');
        }
        if ($p['converted_lead_id'] !== null) {
            return self::ok('Ya era un lead.', ['lead_id' => (int) $p['converted_lead_id']]);
        }
        $units = $p['fleet_size'] !== null ? (int) $p['fleet_size'] : null;
        $name = (string) ($p['legal_name'] ?: ($p['dba'] ?: 'USDOT ' . $p['usdot']));
        $grantedAt = $p['authority_granted_at'];
        $age = null;
        if ($grantedAt !== null) {
            $g = new \DateTimeImmutable($grantedAt . ' 00:00:00 UTC');
            $n = new \DateTimeImmutable('@' . Clock::time());
            $age = $g > $n ? 0 : ((int) $n->format('Y') - (int) $g->format('Y')) * 12 + ((int) $n->format('n') - (int) $g->format('n')) - ((int) $n->format('j') < (int) $g->format('j') ? 1 : 0);
        }
        $fm = [
            'found' => true, 'usdot' => $p['usdot'], 'source' => 'socrata', 'legal_name' => $p['legal_name'], 'dba' => $p['dba'], 'state' => $p['state'], 'allowed_to_operate' => true, 'authority_status' => 'active',
            'authority_granted_at' => $grantedAt, 'authority_age_months' => $age, 'insured' => null, 'bipd_on_file' => null, 'power_units' => $units, 'drivers' => $p['drivers'] !== null ? (int) $p['drivers'] : null,
            'driver_oos_rate' => null, 'vehicle_oos_rate' => null, 'safety_rating' => null, 'red_flags' => [], 'checked_at' => Clock::now(),
        ];
        $payload = [
            'profile' => $units !== null && $units >= 2 ? 'small_carrier' : 'owner_operator', 'first_name' => mb_substr($name, 0, 100), 'phone' => (string) $p['phone'], 'email' => (string) $p['email'],
            'state' => (string) $p['state'], 'city' => (string) $p['city'], 'usdot' => (string) $p['usdot'], 'truck_count' => $units !== null && $units >= 1 ? $units : null, 'authority_status' => 'active',
            'language' => 'en', 'consent' => ['given' => false],
        ];
        try {
            $r = LeadIngest::handle($payload, 'fmcsa_outbound', ['quiet' => true, 'skip_antispam' => true, 'fmcsa_data' => $fm]);
        } catch (\Throwable $t) {
            Storage::error('prospect convert failed', get_class($t) . ': ' . $t->getMessage());
            return self::fail('No se pudo crear el lead. Inténtalo de nuevo.');
        }
        if (!$r['ok'] || $r['lead_id'] === null) {
            return self::fail('No se pudo crear el lead (' . ($r['reason'] ?? 'datos no válidos') . ').');
        }
        $leadId = (int) $r['lead_id'];
        $existing = $r['status'] !== 'created';
        if (!$existing) {
            LeadActions::setStage($leadId, 'contacted', ['note' => 'Convertido desde un prospecto FMCSA (llamada manual)'], $userId);
        }
        LeadIngest::event($leadId, 'prospect_converted', ['prospect_id' => $id, 'usdot' => $p['usdot'], 'existing_lead' => $existing], null);
        Db::update('prospects_fmcsa', $id, ['converted_lead_id' => $leadId, 'converted_at' => Clock::now(), 'status' => in_array($p['status'], ['to_call', 'no_answer', 'no_answer_closed', 'talked', 'not_interested'], true) ? 'interested' : $p['status'], 'next_call_at' => null]);
        Db::insert('prospect_calls', ['prospect_id' => $id, 'user_id' => $userId, 'result' => 'converted', 'note' => null, 'attempt_no' => 0, 'created_at' => Clock::now()]);
        ActivityLog::record('prospect_convert', $userId, 'prospect', $id, ['lead_id' => $leadId, 'existing_lead' => $existing]);
        return self::ok($existing ? 'Ya existía un lead con ese teléfono o correo: se enlazó a él (#' . $leadId . ').' : 'Lead creado en «Contactado» (#' . $leadId . '). No se mandó ningún mensaje ni alerta.', ['lead_id' => $leadId]);
    }

    /** Filas para el CSV (mismos filtros que la pantalla, sin paginar, maximo 5000). */
    public static function exportRows(array $f, int $now): array
    {
        [$sql, $p] = self::where($f, $now);
        return Db::all("SELECT p.* FROM prospects_fmcsa p WHERE $sql ORDER BY p.authority_granted_at DESC, p.completeness DESC, p.id ASC LIMIT 5000", $p);
    }
}
