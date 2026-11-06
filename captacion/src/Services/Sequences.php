<?php
/**
 * Secuencias de correo (doc §9.6, A6, A7, A12) sobre la cola existente.
 *
 *  - a6_warm: leads TIBIOS. Dia 1 (caso real), dia 3 (calculadora de RPM), dia 7 (tarifas de la semana) y dia 14 (ultimo intento).
 *    El dia 7 se OMITE mientras no exista una fuente real de tarifas (settings['sequences.rates_source'] vacio): no hay nada honesto que enviar. Queda anotado en la linea de tiempo.
 *  - a12_referral: pedido de referidos a los 30 dias de firmar (se inscribe al firmar).
 *  - A7: a6_warm se pausa sola cuando el lead pasa a Contactado, Calificado, Perdido, Firmado… (cualquier etapa distinta de Nuevo) o con «Respondio». Se reanuda a mano.
 *
 * Reglas de envio (todas se comprueban al enviar, en el handler del job `sequence_step`):
 *   - Secuencias encendidas (Configuracion > Correo) Y direccion fisica configurada: si no, NO se encola nada y el panel lo dice.
 *   - Consentimiento de correo vigente (Consent::isGranted) y sin bandera STOP: si no, la inscripcion se detiene (`no_consent`).
 *   - Plantilla activa, sin marcadores [[…]] ni variables sin resolver: si no, el paso espera (reintento en 1 h) y el panel avisa.
 *   - Topes: correos de secuencias por hora (global) y por lead cada 24 h; si se topa, el paso espera 15 min.
 *   - Cabeceras List-Unsubscribe (mailto no; enlace https) y List-Unsubscribe-Post: List-Unsubscribe=One-Click, pie con direccion fisica y baja de un clic.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;

final class Sequences
{
    public const DEFS = [
        'a6_warm' => [
            'name' => 'A6 · Seguimiento de leads tibios', 'pause_on_progress' => true,
            'steps' => [
                ['day' => 1, 'tkey' => 'a6_d1', 'label' => 'Día 1 · Caso real'],
                ['day' => 3, 'tkey' => 'a6_d3', 'label' => 'Día 3 · Calculadora de RPM'],
                ['day' => 7, 'tkey' => 'a6_d7', 'label' => 'Día 7 · Tarifas de la semana', 'needs_source' => true],
                ['day' => 14, 'tkey' => 'a6_d14', 'label' => 'Día 14 · Último intento'],
            ],
        ],
        'a11_reactivation' => [
            'name' => 'A11 · Reactivación de leads perdidos', 'pause_on_progress' => true, 'requires_lost' => true,
            'steps' => [
                ['day' => 0, 'tkey' => 'a11_react_1', 'label' => 'Mensaje 1 · Reactivación'],
                ['day' => 10, 'tkey' => 'a11_react_2', 'label' => 'Mensaje 2 · Último mensaje'],
            ],
        ],
        'a12_referral' => [
            'name' => 'A12 · Pedido de referidos', 'pause_on_progress' => false,
            'steps' => [['day' => 30, 'tkey' => 'a12_referral', 'label' => '30 días después de firmar']],
        ],
    ];
    public const STATUS = ['active' => 'Activa', 'paused' => 'En pausa', 'completed' => 'Completada', 'stopped' => 'Detenida'];
    public const REASONS = [
        'stage' => 'El lead avanzó de etapa', 'responded' => 'El lead respondió', 'manual' => 'Pausada a mano', 'unsubscribed' => 'Se dio de baja', 'no_consent' => 'Sin consentimiento de correo vigente',
        'removed' => 'Sacada a mano', 'no_email' => 'El lead no tiene correo', 'lead_deleted' => 'El lead ya no existe',
    ];
    public const DEFAULT_CAP_HOUR = 20;
    public const DEFAULT_CAP_LEAD = 1;

    // --- Ajustes (Configuracion > Correo) --------------------------------------------------------------------------------------------

    public static function mailSettings(): array
    {
        $m = Settings::get('mail', []);
        $m = is_array($m) ? $m : [];
        $hour = (int) ($m['cap_hour'] ?? self::DEFAULT_CAP_HOUR);
        $lead = (int) ($m['cap_lead'] ?? self::DEFAULT_CAP_LEAD);
        return [
            'enabled'      => (bool) ($m['enabled'] ?? false),
            'address'      => trim(is_string($m['address'] ?? null) ? $m['address'] : ''),
            'from_name'    => is_string($m['from_name'] ?? null) && trim($m['from_name']) !== '' ? trim($m['from_name']) : 'NorthStar Freight Solutions',
            'reply_to'     => is_string($m['reply_to'] ?? null) && filter_var($m['reply_to'], FILTER_VALIDATE_EMAIL) ? $m['reply_to'] : '',
            'weekly_to'    => is_string($m['weekly_to'] ?? null) && filter_var($m['weekly_to'], FILTER_VALIDATE_EMAIL) ? $m['weekly_to'] : '',
            'monthly_to'   => array_values(array_filter(array_map('strval', (array) ($m['monthly_to'] ?? [])), static fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL) !== false)),
            'cap_hour'     => $hour >= 1 && $hour <= 500 ? $hour : self::DEFAULT_CAP_HOUR,
            'cap_lead'     => $lead >= 1 && $lead <= 5 ? $lead : self::DEFAULT_CAP_LEAD,
            'rates_source' => trim(is_string($m['rates_source'] ?? null) ? $m['rates_source'] : ''),
        ];
    }

    public static function enabled(): bool
    {
        $m = self::mailSettings();
        return $m['enabled'] && $m['address'] !== '';
    }

    /** Estado global para avisos de la interfaz. @return list<array{level:string,text:string}> */
    public static function warnings(): array
    {
        $m = self::mailSettings();
        $w = [];
        if ($m['address'] === '') {
            $w[] = ['level' => 'bad', 'text' => 'Falta la dirección física del remitente (CAN-SPAM): mientras no esté configurada, el envío de secuencias está deshabilitado. Configuración > Correo.'];
        }
        if (!$m['enabled']) {
            $w[] = ['level' => 'warn', 'text' => 'Las secuencias están apagadas: no se inscribe ni se envía nada. Se encienden en Configuración > Correo.'];
        }
        if (!Mailer::canDeliver()) {
            $w[] = ['level' => 'warn', 'text' => 'Sin SMTP: los correos solo se registran en storage/mail.log, no llegan a nadie.'];
        }
        if ($m['rates_source'] === '') {
            $w[] = ['level' => 'info', 'text' => 'El paso del día 7 (tarifas de la semana) se omite: no hay una fuente real de tarifas.'];
        }
        return $w;
    }

    // --- Inscripcion --------------------------------------------------------------------------------------------------------------

    private static function eligibleEmail(array $lead): bool
    {
        return $lead['email'] !== null && filter_var($lead['email'], FILTER_VALIDATE_EMAIL) !== false;
    }

    /** A6: inscribe a un lead tibio, sin contactar y con consentimiento de correo. Seguro de llamar varias veces. @return ?int id de la inscripcion nueva */
    public static function enrollWarm(int $leadId): ?int
    {
        if (!self::enabled()) {
            return null;
        }
        $lead = Lead::find($leadId);
        if ($lead === null || $lead['temperature'] !== 'warm' || $lead['stage'] !== 'new' || $lead['first_contact_at'] !== null || !self::eligibleEmail($lead) || !Consent::isGranted($leadId, 'email')) {
            return null;
        }
        return self::enroll($leadId, 'a6_warm', Clock::time(), null);
    }

    /** A12: al firmar (siempre; el envio sigue sujeto a las reglas de arriba). */
    public static function enrollReferral(int $leadId, int $signedAt): ?int
    {
        $lead = Lead::find($leadId);
        if ($lead === null || !self::eligibleEmail($lead)) {
            return null;
        }
        return self::enroll($leadId, 'a12_referral', $signedAt, null);
    }

    private static function enroll(int $leadId, string $key, int $start, ?int $userId): ?int
    {
        $first = self::DEFS[$key]['steps'][0];
        $st = Db::run(
            "INSERT IGNORE INTO sequence_enrollments (lead_id, sequence_key, step, status, next_run_at, started_by, created_at) VALUES (?, ?, 0, 'active', ?, ?, ?)",
            [$leadId, $key, Clock::at($start + $first['day'] * 86400), $userId, Clock::at($start)]
        );
        if ($st->rowCount() !== 1) {
            return null;
        }
        $id = (int) Db::pdo()->lastInsertId();
        LeadActions::event($leadId, 'sequence_enrolled', ['sequence' => $key, 'enrollment_id' => $id], $userId);
        return $id;
    }

    // --- A11: reactivacion de leads perdidos ----------------------------------------------------------------------------------------

    public const REACT_KEY = 'a11_reactivation';

    /** Interruptor propio (apagado por defecto) y dias minimos desde que se perdio. */
    public static function reactivationConfig(): array
    {
        $c = Settings::get('reactivation', []);
        $c = is_array($c) ? $c : [];
        $d = (int) ($c['min_days'] ?? 60);
        return ['enabled' => (bool) ($c['enabled'] ?? false), 'min_days' => $d >= 30 && $d <= 365 ? $d : 60];
    }

    /**
     * Reparte los leads perdidos hace N dias o mas segun puedan o no reactivarse. Solo pipeline A (los textos hablan de dispatch).
     * Motivos de exclusion (en este orden): enrolled, signed, disqualified, rejected, dnc (telefono en la lista de no llamar), stop (STOP o baja), no_email, no_consent.
     * @return array{eligible:list<int>,excluded:array<string,int>,total:int}
     */
    public static function reactivationScan(int $limit = 2000): array
    {
        $cfg = self::reactivationConfig();
        $out = ['eligible' => [], 'excluded' => array_fill_keys(['enrolled', 'signed', 'disqualified', 'rejected', 'dnc', 'stop', 'no_email', 'no_consent'], 0), 'total' => 0];
        $rows = Db::all(
            "SELECT l.* FROM leads l WHERE l.deleted_at IS NULL AND l.stage = 'lost' AND l.pipeline = 'A' AND l.lost_at IS NOT NULL AND l.lost_at <= ? ORDER BY l.lost_at ASC LIMIT " . (int) $limit,
            [Clock::shift(-$cfg['min_days'] * 86400)]
        );
        $enrolled = array_flip(array_map('intval', array_column(Db::all('SELECT lead_id FROM sequence_enrollments WHERE sequence_key = ?', [self::REACT_KEY]), 'lead_id')));
        $dnc = array_flip(array_column(Db::all('SELECT phone_e164 FROM dnc_numbers'), 'phone_e164'));
        foreach ($rows as $r) {
            $l = Lead::hydrate($r);
            $out['total']++;
            $why = match (true) {
                isset($enrolled[(int) $l['id']]) => 'enrolled',
                Dedupe::isSigned($l) => 'signed',
                $l['temperature'] === 'disqualified' => 'disqualified',
                $l['lost_reason'] === 'rejected' => 'rejected',
                $l['phone_e164'] !== null && isset($dnc[$l['phone_e164']]) => 'dnc',
                in_array('stop', $l['flags'], true) || in_array('stop_email', $l['flags'], true) => 'stop',
                !self::eligibleEmail($l) => 'no_email',
                !Consent::isGranted((int) $l['id'], 'email') => 'no_consent',
                default => null,
            };
            if ($why === null) {
                $out['eligible'][] = (int) $l['id'];
            } else {
                $out['excluded'][$why]++;
            }
        }
        return $out;
    }

    /** Inscribe a los elegibles (lotes de $limit). Solo con las secuencias encendidas, la direccion fisica y el interruptor propio. @return int inscritos */
    public static function enrollReactivation(int $limit = 25): int
    {
        if (!self::enabled() || !self::reactivationConfig()['enabled']) {
            return 0;
        }
        $n = 0;
        foreach (array_slice(self::reactivationScan()['eligible'], 0, $limit) as $id) {
            if (self::enroll($id, self::REACT_KEY, Clock::time(), null) !== null) {
                $n++;
            }
        }
        return $n;
    }

    // --- A7: pausar, reanudar, sacar ------------------------------------------------------------------------------------------------

    /** Pausa las secuencias que se detienen con el avance del lead (A6). @return int inscripciones pausadas */
    public static function pauseForLead(int $leadId, string $reason, ?int $userId): int
    {
        $keys = array_keys(array_filter(self::DEFS, static fn($d) => $d['pause_on_progress']));
        $rows = Db::all("SELECT id, sequence_key FROM sequence_enrollments WHERE lead_id = ? AND status = 'active'", [$leadId]);
        $n = 0;
        foreach ($rows as $r) {
            if (!in_array($r['sequence_key'], $keys, true)) {
                continue;
            }
            Db::run("UPDATE sequence_enrollments SET status = 'paused', paused_reason = ? WHERE id = ? AND status = 'active'", [$reason, (int) $r['id']]);
            LeadActions::event($leadId, 'sequence_paused', ['sequence' => $r['sequence_key'], 'reason' => $reason, 'enrollment_id' => (int) $r['id']], $userId);
            $n++;
        }
        if ($n > 0 && $userId !== null) {
            ActivityLog::record('sequence_pause', $userId, 'lead', $leadId, ['reason' => $reason, 'n' => $n]);
        }
        return $n;
    }

    /** Detiene TODAS las inscripciones vivas del lead (baja, sin consentimiento…). @return int */
    public static function stopAll(int $leadId, string $reason, ?int $userId): int
    {
        $rows = Db::all("SELECT id, sequence_key FROM sequence_enrollments WHERE lead_id = ? AND status IN ('active','paused')", [$leadId]);
        foreach ($rows as $r) {
            Db::run("UPDATE sequence_enrollments SET status = 'stopped', paused_reason = ?, blocked_reason = NULL WHERE id = ?", [$reason, (int) $r['id']]);
            LeadActions::event($leadId, 'sequence_stopped', ['sequence' => $r['sequence_key'], 'reason' => $reason, 'enrollment_id' => (int) $r['id']], $userId);
        }
        return count($rows);
    }

    /** Accion manual sobre una inscripcion: pause | resume | remove. @return array{ok:bool,msg:string} */
    public static function manage(int $enrollmentId, string $action, int $userId): array
    {
        return Db::transaction(static function () use ($enrollmentId, $action, $userId): array {
            $e = Db::one('SELECT * FROM sequence_enrollments WHERE id = ? FOR UPDATE', [$enrollmentId]);
            if ($e === null) {
                return ['ok' => false, 'msg' => 'Esa inscripción no existe.'];
            }
            $lead = (int) $e['lead_id'];
            $key = (string) $e['sequence_key'];
            if ($action === 'pause' && $e['status'] === 'active') {
                Db::run("UPDATE sequence_enrollments SET status = 'paused', paused_reason = 'manual' WHERE id = ?", [$enrollmentId]);
                LeadActions::event($lead, 'sequence_paused', ['sequence' => $key, 'reason' => 'manual', 'enrollment_id' => $enrollmentId], $userId);
                ActivityLog::record('sequence_pause', $userId, 'lead', $lead, ['enrollment_id' => $enrollmentId, 'reason' => 'manual']);
                return ['ok' => true, 'msg' => 'Inscripción en pausa.'];
            }
            if ($action === 'resume' && $e['status'] === 'paused') {
                if (!Consent::isGranted($lead, 'email')) {
                    return ['ok' => false, 'msg' => 'No se puede reanudar: el lead no tiene consentimiento de correo vigente.'];
                }
                $step = self::DEFS[$key]['steps'][(int) $e['step']] ?? null;
                $due = $step !== null ? max(Clock::time(), (int) Clock::parse((string) $e['created_at']) + $step['day'] * 86400) : Clock::time();
                Db::run("UPDATE sequence_enrollments SET status = 'active', paused_reason = NULL, blocked_reason = NULL, next_run_at = ? WHERE id = ?", [Clock::at($due), $enrollmentId]);
                LeadActions::event($lead, 'sequence_resumed', ['sequence' => $key, 'enrollment_id' => $enrollmentId], $userId);
                ActivityLog::record('sequence_resume', $userId, 'lead', $lead, ['enrollment_id' => $enrollmentId]);
                return ['ok' => true, 'msg' => 'Inscripción reanudada.'];
            }
            if ($action === 'remove' && in_array($e['status'], ['active', 'paused'], true)) {
                Db::run("UPDATE sequence_enrollments SET status = 'stopped', paused_reason = 'removed', blocked_reason = NULL WHERE id = ?", [$enrollmentId]);
                LeadActions::event($lead, 'sequence_stopped', ['sequence' => $key, 'reason' => 'removed', 'enrollment_id' => $enrollmentId], $userId);
                ActivityLog::record('sequence_remove', $userId, 'lead', $lead, ['enrollment_id' => $enrollmentId]);
                return ['ok' => true, 'msg' => 'Lead sacado de la secuencia.'];
            }
            return ['ok' => false, 'msg' => 'Esa acción no aplica al estado actual de la inscripción.'];
        });
    }

    /** Boton «Respondió» de la ficha: pausa las secuencias del lead. */
    public static function responded(int $leadId, int $userId): array
    {
        $n = self::pauseForLead($leadId, 'responded', $userId);
        ActivityLog::record('lead_responded', $userId, 'lead', $leadId, ['paused' => $n]);
        LeadActions::event($leadId, 'lead_responded', ['paused' => $n], $userId);
        return ['ok' => true, 'msg' => $n > 0 ? 'Marcado como respondido: ' . $n . ' secuencia' . ($n === 1 ? '' : 's') . ' en pausa.' : 'Marcado como respondido (no había secuencias activas).'];
    }

    // --- Envio ---------------------------------------------------------------------------------------------------------------------------

    /** Encola un job por inscripcion vencida. Lo llama cron/process_jobs.php antes de procesar la cola. @return int jobs nuevos */
    public static function enqueueDue(): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $n = 0;
        foreach (Db::all("SELECT id, step, blocks FROM sequence_enrollments WHERE status = 'active' AND next_run_at <= ? ORDER BY next_run_at LIMIT 200", [Clock::now()]) as $r) {
            if (Queue::push('sequence_step', ['enrollment_id' => (int) $r['id'], 'step' => (int) $r['step']], Clock::now(), 'seq:' . $r['id'] . ':' . $r['step'] . ':' . $r['blocks']) !== null) {
                $n++;
            }
        }
        return $n;
    }

    private static function sentLastHour(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM messages WHERE channel = 'email' AND direction = 'out' AND sequence_key IS NOT NULL AND status IN ('sent','logged') AND created_at > ?", [Clock::shift(-3600)]);
    }

    private static function sentToLead(int $leadId): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM messages WHERE lead_id = ? AND channel = 'email' AND direction = 'out' AND sequence_key IS NOT NULL AND status IN ('sent','logged') AND created_at > ?", [$leadId, Clock::shift(-86400)]);
    }

    private static function block(array $e, string $reason, int $waitSeconds): void
    {
        Db::run('UPDATE sequence_enrollments SET blocked_reason = ?, blocks = blocks + 1, next_run_at = ? WHERE id = ?', [mb_substr($reason, 0, 120), Clock::shift($waitSeconds), (int) $e['id']]);
    }

    /** Avanza una inscripcion despues de enviar u omitir un paso. */
    private static function advance(array $e, int $stepIdx, bool $sent): void
    {
        $steps = self::DEFS[$e['sequence_key']]['steps'];
        $next = $stepIdx + 1;
        $set = ['step' => $next, 'blocked_reason' => null, 'blocks' => 0];
        if ($sent) {
            $set['last_sent_at'] = Clock::now();
        }
        if (!isset($steps[$next])) {
            $set += ['status' => 'completed', 'completed_at' => Clock::now(), 'next_run_at' => null];
        } else {
            $set['next_run_at'] = Clock::at(max(Clock::time(), (int) Clock::parse((string) $e['created_at']) + $steps[$next]['day'] * 86400));
        }
        Db::update('sequence_enrollments', (int) $e['id'], $set);
    }

    /** Handler del job sequence_step. Nunca lanza por bloqueos de negocio (los anota y reprograma); solo lanza si el SMTP rechaza (la cola reintenta). */
    public static function runStep(array $payload): void
    {
        $e = Db::one('SELECT * FROM sequence_enrollments WHERE id = ?', [(int) ($payload['enrollment_id'] ?? 0)]);
        if ($e === null || $e['status'] !== 'active' || (int) $e['step'] !== (int) ($payload['step'] ?? -1)) {
            return;   // ya avanzo, se pauso o se detuvo
        }
        $def = self::DEFS[$e['sequence_key']] ?? null;
        $stepIdx = (int) $e['step'];
        $step = $def['steps'][$stepIdx] ?? null;
        if ($def === null || $step === null) {
            Db::update('sequence_enrollments', (int) $e['id'], ['status' => 'completed', 'completed_at' => Clock::now(), 'next_run_at' => null]);
            return;
        }
        $leadId = (int) $e['lead_id'];
        $lead = Lead::find($leadId);
        if ($lead === null) {
            Db::update('sequence_enrollments', (int) $e['id'], ['status' => 'stopped', 'paused_reason' => 'lead_deleted', 'next_run_at' => null]);
            return;
        }
        $cfg = self::mailSettings();
        if (!self::enabled()) {
            self::block($e, $cfg['address'] === '' ? 'Falta la dirección física' : 'Secuencias apagadas', 3600);
            return;
        }
        if (!empty($def['requires_lost'])) {
            if ($lead['stage'] !== 'lost') {
                self::pauseForLead($leadId, 'stage', null);   // el lead se reabrió o avanzó: la reactivación ya no aplica
                return;
            }
            if (!self::reactivationConfig()['enabled']) {
                self::block($e, 'La reactivación está apagada', 3600);
                return;
            }
        }
        if (!self::eligibleEmail($lead)) {
            Db::update('sequence_enrollments', (int) $e['id'], ['status' => 'stopped', 'paused_reason' => 'no_email', 'next_run_at' => null]);
            LeadActions::event($leadId, 'sequence_stopped', ['sequence' => $e['sequence_key'], 'reason' => 'no_email'], null);
            return;
        }
        if (!Consent::isGranted($leadId, 'email')) {
            Db::update('sequence_enrollments', (int) $e['id'], ['status' => 'stopped', 'paused_reason' => 'no_consent', 'blocked_reason' => null, 'next_run_at' => null]);
            LeadActions::event($leadId, 'sequence_stopped', ['sequence' => $e['sequence_key'], 'reason' => 'no_consent'], null);
            return;
        }
        if (!empty($step['needs_source']) && $cfg['rates_source'] === '') {
            LeadActions::event($leadId, 'sequence_step_skipped', ['sequence' => $e['sequence_key'], 'step' => $step['label'], 'why' => 'sin fuente real de tarifas'], null);
            self::advance($e, $stepIdx, false);
            return;
        }
        if (self::sentLastHour() >= $cfg['cap_hour']) {
            self::block($e, 'Tope de envíos por hora (' . $cfg['cap_hour'] . ')', 900);
            return;
        }
        if (self::sentToLead($leadId) >= $cfg['cap_lead']) {
            self::block($e, 'Tope de envíos por lead cada 24 h (' . $cfg['cap_lead'] . ')', 900);
            return;
        }
        $lang = in_array($lead['language'], ['en', 'es'], true) ? (string) $lead['language'] : 'en';
        $tpl = Templates::byKey($step['tkey'], $lang);
        if ($tpl === null || (int) $tpl['active'] !== 1) {
            self::block($e, 'La plantilla «' . $step['tkey'] . '» no existe o está desactivada', 3600);
            return;
        }
        $unsub = Unsub::url($leadId);
        $r = Templates::render($tpl, $lead, (string) $tpl['lang'], $unsub);
        if ($r['unresolved']) {
            self::block($e, 'Texto pendiente de confirmar en la plantilla: ' . implode(' ', array_map(static fn($m) => mb_substr($m, 0, 40), array_slice($r['unresolved'], 0, 2))), 3600);
            return;
        }
        $status = self::send($lead, $tpl, $r, $unsub, (string) $e['sequence_key']);
        LeadActions::event($leadId, 'sequence_email', ['sequence' => $e['sequence_key'], 'step' => $step['label'], 'status' => $status], null);
        self::advance($e, $stepIdx, true);
    }

    /** Envia (o registra) un correo de plantilla a un lead y lo anota en messages. @return 'sent'|'logged' */
    public static function send(array $lead, array $tpl, array $rendered, string $unsubUrl, ?string $sequenceKey): string
    {
        $cfg = self::mailSettings();
        $result = Mailer::deliver([
            'to' => (string) $lead['email'], 'subject' => $rendered['subject'], 'text' => $rendered['text'], 'html' => $rendered['html'], 'from_name' => $cfg['from_name'],
            'reply_to' => $cfg['reply_to'] !== '' ? $cfg['reply_to'] : Mailer::to(),
            'headers' => ['List-Unsubscribe' => '<' . $unsubUrl . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
        ]);
        Db::insert('messages', ['lead_id' => (int) $lead['id'], 'channel' => 'email', 'direction' => 'out', 'body' => mb_substr($rendered['text'], 0, 4000), 'template_key' => (string) $tpl['tkey'],
            'subject' => mb_substr($rendered['subject'], 0, 190), 'sequence_key' => $sequenceKey, 'status' => $result, 'created_at' => Clock::now()]);
        return $result;
    }

    // --- Para las pantallas -------------------------------------------------------------------------------------------------------------

    /** Inscripciones con datos del lead (solo admin). @return list<array> */
    public static function listEnrollments(string $status = '', string $key = '', int $limit = 200): array
    {
        $where = ['l.deleted_at IS NULL'];
        $p = [];
        if (isset(self::STATUS[$status])) {
            $where[] = 'e.status = ?';
            $p[] = $status;
        }
        if (isset(self::DEFS[$key])) {
            $where[] = 'e.sequence_key = ?';
            $p[] = $key;
        }
        return Db::all(
            'SELECT e.id, e.lead_id, e.sequence_key, e.step, e.status, e.paused_reason, e.blocked_reason, e.next_run_at, e.last_sent_at, e.created_at, l.first_name, l.last_name, l.language, l.email
               FROM sequence_enrollments e JOIN leads l ON l.id = e.lead_id WHERE ' . implode(' AND ', $where) . ' ORDER BY (e.status = \'active\') DESC, e.next_run_at IS NULL, e.next_run_at ASC, e.id DESC LIMIT ' . (int) $limit,
            $p
        );
    }

    /** @return list<array> inscripciones del lead para la ficha */
    public static function forLead(int $leadId): array
    {
        return Db::all('SELECT id, sequence_key, step, status, paused_reason, blocked_reason, next_run_at, last_sent_at FROM sequence_enrollments WHERE lead_id = ? ORDER BY id', [$leadId]);
    }

    public static function stepLabel(string $key, int $step): string
    {
        $s = self::DEFS[$key]['steps'][$step] ?? null;
        return $s !== null ? $s['label'] : 'Terminada';
    }
}
