<?php
/**
 * Citas de Cal.com (A4/A5, doc §14: POST /api/webhooks/calcom.php). Reserva creada, reprogramada o cancelada:
 *   - asocia la cita a un lead por correo/telefono (Dedupe) o crea el lead con fuente `calcom`;
 *   - crea/actualiza `appointments` y la tarea «Llamada con X» (aparecen en /tareas y en el dashboard);
 *   - pipeline A: mueve a «Llamada agendada» (LeadActions::setStage, mismas reglas que la ficha); al cancelar vuelve a «Calificado» si no queda otra cita;
 *   - programa los recordatorios de 24 h y 1 h como jobs `appointment_reminder` que envian CORREO (el SMS/WhatsApp los hace n8n con GET /internal/reminders/due).
 * Los eventos pueden llegar duplicados o DESORDENADOS: cada cita guarda `last_event_at` y un evento mas viejo que el ultimo aplicado se ignora («obsoleto»); una reprogramacion
 * conserva UNA fila (prev_calcom_id = uid de la reserva anterior). El estado final es el mismo llegue en el orden que llegue.
 *
 * FORMATO (NO PROBADO contra Cal.com real; de memoria de la documentacion de webhooks): cabecera `X-Cal-Signature-256` = HMAC-SHA256 hex del cuerpo con el secreto del webhook;
 *   {"triggerEvent":"BOOKING_CREATED|BOOKING_RESCHEDULED|BOOKING_CANCELLED|PING","createdAt":"ISO","payload":{"uid","title","startTime","endTime","attendees":[{"name","email","timeZone",
 *   "language":{"locale"}}],"responses":{…},"metadata":{"videoCallUrl"},"location","rescheduleUid"?}}. El telefono puede venir en varios sitios: ver phoneOf().
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;

final class Appointments
{
    public const TRIGGERS = ['BOOKING_CREATED', 'BOOKING_RESCHEDULED', 'BOOKING_CANCELLED'];

    public static function config(): array
    {
        $c = Settings::get('calcom', []);
        $c = is_array($c) ? $c : [];
        return [
            'default_profile' => in_array($c['default_profile'] ?? '', LeadPayload::PROFILES, true) ? (string) $c['default_profile'] : 'owner_operator',
            'booking_url'     => is_string($c['booking_url'] ?? null) && preg_match('#^https://[^\s<>"\']{4,200}$#', trim($c['booking_url'])) === 1 ? trim($c['booking_url']) : '',
        ];
    }

    // --- Lectura del evento -----------------------------------------------------------------------------------------------------------

    private static function str(mixed $v, int $max): string
    {
        return Inbound::clean(is_scalar($v) ? (string) $v : '', $max);
    }

    /** Telefono del asistente: Cal.com lo puede mandar en varios sitios segun como se configuro el formulario de la reserva. */
    private static function phoneOf(array $p, array $att): string
    {
        $r = is_array($p['responses'] ?? null) ? $p['responses'] : [];
        foreach ([$att['phoneNumber'] ?? null, $r['attendeePhoneNumber']['value'] ?? null, $r['phone']['value'] ?? null, $r['phoneNumber']['value'] ?? null, $r['smsReminderNumber']['value'] ?? null, $p['smsReminderNumber'] ?? null] as $c) {
            if (is_string($c) && trim($c) !== '') {
                return mb_substr(trim($c), 0, 40);
            }
        }
        return '';
    }

    /**
     * Normaliza un evento ya firmado. @return array<string,mixed>|string array con el evento, o codigo de error: ping | not_handled | invalid_payload | invalid_uid | invalid_start | no_attendee
     */
    public static function parse(array $env): array|string
    {
        $trigger = strtoupper(self::str($env['triggerEvent'] ?? '', 40));
        if ($trigger === 'PING') {
            return 'ping';
        }
        if (!in_array($trigger, self::TRIGGERS, true)) {
            return 'not_handled';
        }
        $p = $env['payload'] ?? null;
        if (!is_array($p)) {
            return 'invalid_payload';
        }
        $uid = self::str($p['uid'] ?? '', 80);
        if (preg_match('/^[A-Za-z0-9_-]{6,60}$/', $uid) !== 1) {
            return 'invalid_uid';
        }
        $start = isset($p['startTime']) && is_string($p['startTime']) ? strtotime($p['startTime']) : false;
        if ($start === false || $start < Clock::time() - 400 * 86400 || $start > Clock::time() + 800 * 86400) {
            return 'invalid_start';
        }
        $end = isset($p['endTime']) && is_string($p['endTime']) ? strtotime($p['endTime']) : false;
        $att = is_array($p['attendees'][0] ?? null) ? $p['attendees'][0] : [];
        $r = is_array($p['responses'] ?? null) ? $p['responses'] : [];
        $email = strtolower(self::str($att['email'] ?? ($r['email']['value'] ?? ''), 190));
        $phone = self::phoneOf($p, $att);
        if (($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) && $phone === '') {
            return 'no_attendee';
        }
        $re = self::str($p['rescheduleUid'] ?? '', 80);
        $meeting = self::str($p['metadata']['videoCallUrl'] ?? '', 255);
        if (!str_starts_with($meeting, 'https://')) {
            $loc = self::str($p['location'] ?? '', 255);
            $meeting = str_starts_with($loc, 'https://') ? $loc : '';
        }
        $tz = self::str($att['timeZone'] ?? '', 40);
        $when = isset($env['createdAt']) && is_string($env['createdAt']) ? strtotime($env['createdAt']) : false;
        return [
            'trigger' => $trigger, 'uid' => $uid, 'reschedule_uid' => preg_match('/^[A-Za-z0-9_-]{6,60}$/', $re) === 1 ? $re : '', 'start' => (int) $start,
            'end' => $end !== false && $end > $start ? (int) $end : null, 'title' => self::str($p['title'] ?? ($p['eventTitle'] ?? ''), 190),
            'meeting_url' => $meeting, 'tz' => in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : '', 'name' => self::str($att['name'] ?? ($r['name']['value'] ?? ''), 120),
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '', 'phone' => $phone, 'lang' => strtolower(substr(self::str($att['language']['locale'] ?? '', 5), 0, 2)),
            'event_at' => $when !== false && $when > 946684800 && $when <= Clock::time() + 300 ? (int) $when : Clock::time(),
        ];
    }

    // --- Aplicar ----------------------------------------------------------------------------------------------------------------------

    private static function findRow(array $ev): ?array
    {
        $by = static fn(string $col, string $uid) => Db::one("SELECT *, '$col' AS matched_by FROM appointments WHERE $col = ? LIMIT 1", [$uid]);
        $row = $by('calcom_id', $ev['uid']) ?? $by('prev_calcom_id', $ev['uid']);
        if ($row === null && $ev['reschedule_uid'] !== '') {
            $row = $by('calcom_id', $ev['reschedule_uid']);
            if ($row !== null) {
                $row['matched_by'] = 'reschedule_uid';
            }
        }
        return $row;
    }

    private static function leadFor(array $ev, ?array $row): ?array
    {
        if ($row !== null) {
            $l = Lead::find((int) $row['lead_id']);
            if ($l !== null) {
                return $l;
            }
        }
        $ph = $ev['phone'] !== '' ? Phone::normalize($ev['phone']) : ['ok' => false, 'e164' => null];
        $found = Dedupe::find($ph['ok'] ? $ph['e164'] : null, $ev['email'] !== '' ? $ev['email'] : null);
        if ($found !== null) {
            return $found;
        }
        $name = $ev['name'] !== '' ? $ev['name'] : ($ev['email'] !== '' ? explode('@', $ev['email'])[0] : 'Cal.com');
        $r = LeadIngest::handle([
            'profile' => self::config()['default_profile'], 'name' => $name, 'email' => $ev['email'], 'phone' => $ev['phone'], 'language' => $ev['lang'] === 'es' ? 'es' : 'en',
            'site_lead_id' => 'cal_' . $ev['uid'], 'landing_page' => 'calcom',
        ], 'calcom', ['skip_antispam' => true, 'fmcsa_sync' => false]);
        return $r['ok'] && $r['lead_id'] !== null ? Lead::find((int) $r['lead_id']) : null;
    }

    /**
     * @param array<string,mixed> $ev resultado de parse()
     * @return array{status:string,summary:string,lead_id:?int,appointment_id:?int}  status: processed | stale | ignored
     */
    public static function apply(array $ev): array
    {
        return Db::transaction(static function () use ($ev): array {
            $row = self::findRow($ev);
            if ($row !== null) {
                $row = Db::one('SELECT *, ? AS matched_by FROM appointments WHERE id = ? FOR UPDATE', [$row['matched_by'], $row['id']]);
                if ($row['last_event_at'] !== null && $ev['event_at'] < (int) Clock::parse((string) $row['last_event_at'])) {
                    return ['status' => 'stale', 'summary' => 'evento obsoleto (ya se aplicó uno más reciente)', 'lead_id' => (int) $row['lead_id'], 'appointment_id' => (int) $row['id']];
                }
                if ($row['matched_by'] === 'prev_calcom_id') {
                    return ['status' => 'stale', 'summary' => 'la reserva fue reemplazada por una reprogramación', 'lead_id' => (int) $row['lead_id'], 'appointment_id' => (int) $row['id']];
                }
            }
            $lead = self::leadFor($ev, $row);
            if ($lead === null) {
                return ['status' => 'ignored', 'summary' => 'no se pudo crear ni encontrar el lead', 'lead_id' => null, 'appointment_id' => null];
            }
            $leadId = (int) $lead['id'];
            $startS = Clock::at($ev['start']);
            $endS = $ev['end'] !== null ? Clock::at($ev['end']) : null;
            $eventAt = Clock::at($ev['event_at']);
            $cancel = $ev['trigger'] === 'BOOKING_CANCELLED';
            $common = ['starts_at' => $startS, 'ends_at' => $endS, 'title' => $ev['title'] !== '' ? $ev['title'] : null, 'meeting_url' => $ev['meeting_url'] !== '' ? $ev['meeting_url'] : null, 'tz' => $ev['tz'] !== '' ? $ev['tz'] : null, 'last_event_at' => $eventAt];

            if ($row === null) {
                $id = Db::insert('appointments', $common + ['lead_id' => $leadId, 'calcom_id' => $ev['uid'], 'prev_calcom_id' => $ev['trigger'] === 'BOOKING_RESCHEDULED' && $ev['reschedule_uid'] !== '' ? $ev['reschedule_uid'] : null, 'status' => $cancel ? 'cancelled' : 'booked', 'cancelled_at' => $cancel ? $eventAt : null, 'source' => 'calcom', 'created_at' => Clock::now()]);
                $kind = $cancel ? 'cancelled' : ($ev['trigger'] === 'BOOKING_RESCHEDULED' ? 'rescheduled' : 'booked');
            } else {
                $id = (int) $row['id'];
                if ($cancel) {
                    if ($row['status'] === 'cancelled') {
                        Db::update('appointments', $id, ['last_event_at' => $eventAt]);
                        return ['status' => 'processed', 'summary' => 'cancelación repetida (sin cambios)', 'lead_id' => $leadId, 'appointment_id' => $id];
                    }
                    Db::update('appointments', $id, ['status' => 'cancelled', 'cancelled_at' => $eventAt, 'last_event_at' => $eventAt]);
                    $kind = 'cancelled';
                } else {
                    $upd = $common + ['status' => 'booked', 'cancelled_at' => null];
                    $moved = $row['starts_at'] !== $startS || $row['matched_by'] === 'reschedule_uid';
                    if ($row['calcom_id'] !== $ev['uid']) {
                        $upd += ['prev_calcom_id' => $row['calcom_id'], 'calcom_id' => $ev['uid']];
                    }
                    if ($moved) {
                        $upd['reschedules'] = (int) $row['reschedules'] + 1;
                    }
                    Db::update('appointments', $id, $upd);
                    $kind = $moved || $ev['trigger'] === 'BOOKING_RESCHEDULED' ? 'rescheduled' : ($row['status'] === 'cancelled' ? 'booked' : 'updated');
                }
            }
            $appt = Db::one('SELECT * FROM appointments WHERE id = ?', [$id]);
            $cancel ? self::cancelEffects($appt, $lead) : self::bookedEffects($appt, $lead, $kind);
            return ['status' => 'processed', 'summary' => ['booked' => 'cita creada', 'rescheduled' => 'cita reprogramada', 'cancelled' => 'cita cancelada', 'updated' => 'cita actualizada'][$kind], 'lead_id' => $leadId, 'appointment_id' => $id];
        });
    }

    private static function cancelReminders(int $apptId): void
    {
        Db::run("UPDATE jobs SET status = 'cancelled', finished_at = ?, last_error = 'Cancelado: la cita cambió' WHERE type = 'appointment_reminder' AND status = 'pending' AND dedupe_key LIKE ?", [Clock::now(), 'appt_rem:' . $apptId . ':%']);
    }

    private static function bookedEffects(array $a, array $lead, string $kind): void
    {
        $leadId = (int) $lead['id'];
        $id = (int) $a['id'];
        $start = (int) Clock::parse((string) $a['starts_at']);
        $name = trim($lead['first_name'] . ' ' . $lead['last_name']);
        LeadIngest::event($leadId, 'appointment_' . ($kind === 'updated' ? 'rescheduled' : $kind), ['appointment_id' => $id, 'starts_at' => $a['starts_at'], 'source' => 'calcom']);
        // Tarea de la llamada
        $title = mb_substr('Llamada con ' . ($name !== '' ? $name : 'el lead') . ' (Cal.com)', 0, 190);
        $task = Db::one('SELECT id FROM tasks WHERE appointment_id = ? AND deleted_at IS NULL AND done_at IS NULL ORDER BY id DESC LIMIT 1', [$id]);
        if ($task !== null) {
            Db::update('tasks', (int) $task['id'], ['due_at' => $a['starts_at'], 'title' => $title]);
        } else {
            $tid = Db::insert('tasks', ['title' => $title, 'lead_id' => $leadId, 'appointment_id' => $id, 'due_at' => $a['starts_at'], 'priority' => 1, 'created_at' => Clock::now()]);
            LeadIngest::event($leadId, 'task_created', ['task_id' => $tid, 'title' => $title, 'due_at' => $a['starts_at'], 'priority' => 1]);
        }
        // Recordatorios (solo los que todavia estan en el futuro)
        self::cancelReminders($id);
        foreach (['24h' => 86400, '1h' => 3600] as $which => $sec) {
            if ($start - $sec > Clock::time()) {
                Queue::push('appointment_reminder', ['appointment_id' => $id, 'which' => $which, 'starts_at' => $a['starts_at']], Clock::at($start - $sec), 'appt_rem:' . $id . ':' . $start . ':' . $which);
            }
        }
        // Etapa: solo el pipeline A y solo si todavia no paso de Calificado (nunca se mueve un lead presentado, firmado o perdido)
        if ($lead['pipeline'] === 'A' && in_array($lead['stage'], ['new', 'contacted', 'qualified'], true)) {
            LeadActions::setStage($leadId, 'call_booked', ['auto' => true], null);
        }
    }

    private static function cancelEffects(array $a, array $lead): void
    {
        $leadId = (int) $lead['id'];
        $id = (int) $a['id'];
        LeadIngest::event($leadId, 'appointment_cancelled', ['appointment_id' => $id, 'starts_at' => $a['starts_at'], 'source' => 'calcom']);
        foreach (Db::all('SELECT id, title FROM tasks WHERE appointment_id = ? AND deleted_at IS NULL AND done_at IS NULL', [$id]) as $t) {
            Db::update('tasks', (int) $t['id'], ['deleted_at' => Clock::now()]);
            LeadIngest::event($leadId, 'task_deleted', ['task_id' => (int) $t['id'], 'title' => $t['title'], 'why' => 'cita cancelada']);
        }
        self::cancelReminders($id);
        $other = (int) Db::value("SELECT COUNT(*) FROM appointments WHERE lead_id = ? AND id <> ? AND deleted_at IS NULL AND status = 'booked' AND starts_at > ?", [$leadId, $id, Clock::now()]);
        $fresh = Lead::find($leadId);
        if ($other === 0 && $fresh !== null && $fresh['pipeline'] === 'A' && $fresh['stage'] === 'call_booked') {
            LeadActions::setStage($leadId, 'qualified', ['auto' => true], null);
        }
    }

    // --- Recordatorios por correo (A5) ---------------------------------------------------------------------------------------------------

    private const DAYS = ['en' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], 'es' => ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado']];
    private const MONTHS = ['en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'es' => ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']];

    /** «Tuesday, November 10 at 3:00 PM (America/New_York)» / «martes 10 de noviembre a las 15:00 (America/New_York)». */
    public static function whenText(int $ts, ?string $tz, string $lang): string
    {
        $zone = $tz !== null && in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'America/New_York';
        $d = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone($zone));
        $dow = (int) $d->format('w');
        $mon = (int) $d->format('n') - 1;
        if ($lang === 'es') {
            return self::DAYS['es'][$dow] . ' ' . $d->format('j') . ' de ' . self::MONTHS['es'][$mon] . ' a las ' . $d->format('H:i') . ' (' . $zone . ')';
        }
        return self::DAYS['en'][$dow] . ', ' . self::MONTHS['en'][$mon] . ' ' . $d->format('j') . ' at ' . $d->format('g:i A') . ' (' . $zone . ')';
    }

    /** Variables extra de las plantillas de recordatorio. */
    public static function vars(array $a, string $lang): array
    {
        $url = (string) ($a['meeting_url'] ?? '');
        return [
            '{cita_fecha}' => self::whenText((int) Clock::parse((string) $a['starts_at']), $a['tz'] ?? null, $lang),
            '{cita_enlace}' => $url !== '' ? $url : ($lang === 'es' ? 'el enlace está en tu correo de confirmación de Cal.com' : 'the link is in your Cal.com confirmation email'),
        ];
    }

    /** Handler de `appointment_reminder`. Nunca lanza por motivos de negocio (los anota en la linea de tiempo); solo si el SMTP falla (la cola reintenta). */
    public static function jobReminder(array $payload): void
    {
        $a = Db::one('SELECT * FROM appointments WHERE id = ? AND deleted_at IS NULL', [(int) ($payload['appointment_id'] ?? 0)]);
        $which = ($payload['which'] ?? '') === '1h' ? '1h' : '24h';
        if ($a === null || $a['status'] !== 'booked' || $a['starts_at'] !== ($payload['starts_at'] ?? null)) {
            return;   // cancelada, reprogramada o borrada: este recordatorio ya no aplica
        }
        $leadId = (int) $a['lead_id'];
        $lead = Lead::find($leadId);
        if ($lead === null) {
            return;
        }
        $skip = static function (string $why) use ($leadId, $a, $which): void {
            LeadIngest::event($leadId, 'reminder_skipped', ['appointment_id' => (int) $a['id'], 'which' => $which, 'why' => $why]);
        };
        $start = (int) Clock::parse((string) $a['starts_at']);
        if ($start - Clock::time() < ($which === '24h' ? 7200 : 600)) {
            $skip('late');
            return;
        }
        if (!Sequences::enabled()) {
            $skip('mail_disabled');
            return;
        }
        if ($lead['email'] === null || filter_var($lead['email'], FILTER_VALIDATE_EMAIL) === false) {
            $skip('no_email');
            return;
        }
        if (!Consent::isGranted($leadId, 'email')) {
            $skip('no_consent');
            return;
        }
        $lang = in_array($lead['language'], ['en', 'es'], true) ? (string) $lead['language'] : 'en';
        $tpl = Templates::byKey('appt_reminder_' . $which, $lang);
        if ($tpl === null || (int) $tpl['active'] !== 1) {
            $skip('no_template');
            return;
        }
        $unsub = Unsub::url($leadId);
        $r = Templates::render($tpl, $lead, (string) $tpl['lang'], $unsub, self::vars($a, (string) $tpl['lang']));
        if ($r['unresolved']) {
            $skip('template_pending');
            return;
        }
        $status = Sequences::send($lead, $tpl, $r, $unsub, null);   // sequence_key NULL: los recordatorios no consumen los topes de las secuencias
        LeadIngest::event($leadId, 'reminder_sent', ['appointment_id' => (int) $a['id'], 'which' => $which, 'channel' => 'email', 'status' => $status]);
    }
}
