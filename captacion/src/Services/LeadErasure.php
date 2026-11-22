<?php
/**
 * Anonimizacion de un lead a peticion del titular (fase AM). IRREVERSIBLE y sin «Deshacer»: no hay copia del dato en ninguna parte del panel (solo las copias de seguridad
 * de la base, que caducan a los 30 dias; ver docs/fase-am-datos.md).
 *
 * DECISION: no se borra la fila, se anonimiza, para que las estadisticas agregadas (embudo, fuentes, tiempos, puntuacion) no cambien.
 *
 *  Se ELIMINA o se vacia: nombre y apellidos, telefono, correo, ciudad, USDOT, datos de FMCSA, referrer, pagina de llegada (queda solo la ruta, sin parametros), UTM de contenido y
 *  termino, id del CRM externo, cuerpos y asuntos de mensajes, notas libres (y sus ediciones), titulos de tareas y de citas, enlace de reunion, notas de rechazo de entregas,
 *  texto de la nota de WhatsApp «escribio primero», IP y user agent de los consentimientos, el detalle libre de la linea de tiempo (solo se conservan claves estructurales),
 *  los cambios guardados en activity_log para este lead, el telefono y correo de un prospecto FMCSA convertido y el nombre de un codigo de referido propio.
 *  Se CONSERVA: id, fechas, fuente, perfil, etapa, puntuacion, temperatura y su desglose, estado de EE. UU., equipo, camiones y años (agregados), las filas de `consents`
 *  (que consintio y cuando, ya revocadas, sin IP ni user agent) y site_lead_id (para que reimportar el JSONL del sitio no recree el lead).
 *  El telefono y el correo pasan a la lista de supresion (Suppression, razon `erasure`, solo hash). Se cancelan secuencias, tareas abiertas, citas futuras y trabajos pendientes.
 *  activity_log guarda solo quien lo hizo y el id (sin cambios).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;

final class LeadErasure
{
    public const CONFIRM_WORD = 'ANONIMIZAR';

    /** Claves de payload de lead_events que NO llevan texto libre ni datos personales y se conservan (el resto se descarta). */
    private const SAFE_KEYS = ['source', 'score', 'previous', 'temperature', 'reason', 'stage', 'from', 'to', 'pipeline', 'channel', 'via', 'revoked', 'attempt', 'sequence', 'step', 'status', 'enrollment_id',
        'found', 'authority_status', 'insured', 'red_flags', 'authority_age_months', 'reasons', 'kind', 'flags', 'filled', 'days_since_lost', 'lost_recently', 'consent_given', 'outcome', 'minutes', 'auto',
        'undo', 'override', 'note_id', 'why', 'paused', 'rule', 'target', 'version', 'blocked', 'consent_offered', 'from_reason', 'into'];

    /** Normaliza la confirmacion escrita: sin espacios ni mayusculas. */
    public static function confirmOk(mixed $typed): bool
    {
        return is_string($typed) && strtoupper(trim($typed)) === self::CONFIRM_WORD;
    }

    /** Leads que comparten telefono o correo con este (para avisar antes de anonimizar). @return list<array> */
    public static function sameIdentity(array $lead, int $limit = 5): array
    {
        if (($lead['phone_e164'] ?? null) === null && ($lead['email'] ?? null) === null) {
            return [];
        }
        return Db::all('SELECT id, first_name, last_name, stage FROM leads WHERE deleted_at IS NULL AND id <> ? AND anonymized_at IS NULL AND ((phone_e164 IS NOT NULL AND phone_e164 = ?) OR (email IS NOT NULL AND email = ?)) ORDER BY id DESC LIMIT ' . (int) $limit,
            [(int) $lead['id'], $lead['phone_e164'], $lead['email']]);
    }

    /** @return array{ok:bool,msg:string} */
    public static function anonymize(int $leadId, int $userId): array
    {
        if (!Db::lock('captacion_ingest', 5)) {
            return ['ok' => false, 'msg' => 'El motor de captación está ocupado. Inténtalo de nuevo en unos segundos.'];
        }
        try {
            return Db::transaction(static function () use ($leadId, $userId): array {
                $lead = Lead::find($leadId, true);
                if ($lead === null) {
                    return ['ok' => false, 'msg' => 'Ese lead no existe.'];
                }
                if (($lead['anonymized_at'] ?? null) !== null) {
                    return ['ok' => false, 'msg' => 'Ese lead ya está anonimizado.'];
                }
                $now = Clock::now();
                // 1) Lista de supresion ANTES de borrar el dato (telefono y correo, solo hash)
                $phone = $lead['phone_e164'] ?? null;
                if ($phone === null && !empty($lead['phone_raw'])) {
                    $p = Phone::normalize((string) $lead['phone_raw']);
                    $phone = $p['ok'] ? $p['e164'] : null;
                }
                Suppression::add('phone', $phone, Suppression::ERASURE, $leadId, $userId);
                Suppression::add('email', $lead['email'] ?? null, Suppression::ERASURE, $leadId, $userId);

                // 2) Estado a cancelar (ids antes de tocar nada)
                $apptIds = array_map('intval', array_column(Db::all('SELECT id FROM appointments WHERE lead_id = ?', [$leadId]), 'id'));
                $handoffIds = array_map('intval', array_column(Db::all('SELECT id FROM handoffs WHERE lead_id = ?', [$leadId]), 'id'));
                $enrollIds = array_map('intval', array_column(Db::all('SELECT id FROM sequence_enrollments WHERE lead_id = ?', [$leadId]), 'id'));

                // 3) El lead
                $utm = [];
                foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $k) {
                    if (isset($lead['utm'][$k]) && is_string($lead['utm'][$k])) {
                        $utm[$k] = mb_substr($lead['utm'][$k], 0, 100);
                    }
                }
                $path = is_string($lead['landing_page'] ?? null) && $lead['landing_page'] !== '' ? parse_url($lead['landing_page'], PHP_URL_PATH) : null;
                $flags = Flags::remove(Flags::add((array) $lead['flags'], 'stop', 'anonymized'), 'fmcsa_pending', 'phone_invalid');
                Db::update('leads', $leadId, [
                    'first_name' => 'Anonimizado', 'last_name' => '', 'phone_e164' => null, 'phone_raw' => null, 'email' => null, 'city' => null, 'usdot' => null,
                    'fmcsa_data' => null, 'referrer' => null, 'landing_page' => is_string($path) && $path !== '' ? mb_substr($path, 0, 300) : null, 'utm' => $utm ?: null,
                    'external_crm_id' => null, 'flags' => $flags, 'anonymized_at' => $now,
                ]);

                // 4) Datos relacionados
                Db::run("UPDATE lead_notes SET body = '', deleted_at = COALESCE(deleted_at, ?) WHERE lead_id = ?", [$now, $leadId]);
                Db::run('UPDATE messages SET body = NULL, subject = NULL WHERE lead_id = ?', [$leadId]);
                Db::run("UPDATE tasks SET title = 'Tarea eliminada', deleted_at = COALESCE(deleted_at, ?) WHERE lead_id = ? AND done_at IS NULL", [$now, $leadId]);
                Db::run("UPDATE tasks SET title = 'Tarea completada' WHERE lead_id = ? AND done_at IS NOT NULL", [$leadId]);
                Db::run('UPDATE appointments SET title = NULL, meeting_url = NULL WHERE lead_id = ?', [$leadId]);
                Db::run("UPDATE appointments SET status = 'cancelled', cancelled_at = ? WHERE lead_id = ? AND status = 'booked' AND starts_at >= ?", [$now, $leadId, $now]);
                Db::run("UPDATE consents SET ip = NULL, user_agent = NULL, revoked_at = COALESCE(revoked_at, ?), revoked_via = COALESCE(revoked_via, 'erasure') WHERE lead_id = ?", [$now, $leadId]);
                Db::run("UPDATE consents SET consent_text = 'El lead escribió primero por WhatsApp (contenido eliminado por anonimización).' WHERE lead_id = ? AND channel = 'whatsapp_inbound'", [$leadId]);
                Db::run('UPDATE handoffs SET reject_note = NULL WHERE lead_id = ?', [$leadId]);
                Db::run("UPDATE handoffs SET confirm_status = 'superseded' WHERE lead_id = ? AND confirm_status IS NULL", [$leadId]);   // los enlaces /c/{token} pendientes dejan de valer
                Db::run("UPDATE referral_codes SET owner_name = 'Anónimo', note = NULL, active = 0 WHERE owner_lead_id = ?", [$leadId]);
                Db::run("UPDATE prospects_fmcsa SET legal_name = 'Anónimo', dba = NULL, phone = NULL, email = NULL, city = NULL, fmcsa_data = NULL, do_not_call = 1, status = 'dnc', dnc_at = COALESCE(dnc_at, ?) WHERE converted_lead_id = ?", [$now, $leadId]);   // el USDOT se queda: evita que el censo lo vuelva a listar
                Db::run('UPDATE prospect_calls SET note = NULL WHERE prospect_id IN (SELECT id FROM prospects_fmcsa WHERE converted_lead_id = ?)', [$leadId]);
                Db::run('UPDATE rule_applications SET detail = NULL WHERE lead_id = ?', [$leadId]);
                Db::run("UPDATE activity_log SET changes = NULL WHERE entity_type = 'lead' AND entity_id = ?", [(string) $leadId]);

                // 5) Cancelar lo que sigue vivo
                $stopped = Sequences::stopAll($leadId, 'erasure', $userId);
                self::cancelJobs($leadId, $apptIds, $handoffIds, $enrollIds);

                // 6) Linea de tiempo: solo claves estructurales
                foreach (Db::all('SELECT id, payload FROM lead_events WHERE lead_id = ?', [$leadId]) as $ev) {
                    Db::update('lead_events', (int) $ev['id'], ['payload' => self::safePayload(Db::decode($ev['payload'])) ?: null]);
                }
                LeadActions::event($leadId, 'anonymized', [], $userId);
                ActivityLog::record('lead_anonymize', $userId, 'lead', $leadId);
                OutboundWebhooks::emit('lead.anonymized', $leadId, [], 'anon:' . $leadId);   // sin PII: solo id y estado
                return ['ok' => true, 'msg' => 'Lead anonimizado. Se eliminaron sus datos personales' . ($stopped > 0 ? ' y se detuvieron ' . $stopped . ' secuencia' . ($stopped === 1 ? '' : 's') : '') . '. No se puede deshacer.'];
            });
        } finally {
            Db::unlock('captacion_ingest');
        }
    }

    /** Solo claves de la lista blanca, con valores escalares (cadenas cortas) o listas planas de escalares. @return array<string,mixed> */
    public static function safePayload(array $payload): array
    {
        $out = [];
        foreach ($payload as $k => $v) {
            if (!in_array($k, self::SAFE_KEYS, true)) {
                continue;
            }
            if (is_scalar($v) || $v === null) {
                $out[$k] = is_string($v) ? mb_substr($v, 0, 60) : $v;
            } elseif (is_array($v) && array_is_list($v)) {
                $out[$k] = array_values(array_map(static fn($x) => is_string($x) ? mb_substr($x, 0, 60) : (is_scalar($x) ? $x : null), array_slice($v, 0, 30)));
            }
        }
        return $out;
    }

    /** Cancela los jobs pendientes ligados al lead (por lead, cita, entrega o inscripcion) y limpia el error de los fallidos. */
    public static function cancelJobs(int $leadId, array $apptIds = [], array $handoffIds = [], array $enrollIds = []): int
    {
        $n = 0;
        $keys = ['lead_id' => [$leadId], 'appointment_id' => $apptIds, 'handoff_id' => $handoffIds, 'enrollment_id' => $enrollIds];
        foreach ($keys as $key => $ids) {
            if (!$ids) {
                continue;
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $expr = "CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$." . $key . "')) AS UNSIGNED)";
            $n += Db::run("UPDATE jobs SET status = 'cancelled', last_error = 'Cancelado: lead anonimizado o fusionado' WHERE status IN ('pending','parked') AND $expr IN ($in)", array_map('intval', $ids))->rowCount();
            if ($key === 'lead_id') {
                Db::run("UPDATE jobs SET last_error = NULL WHERE status = 'failed' AND $expr IN ($in)", array_map('intval', $ids));
            }
        }
        return $n;
    }
}
