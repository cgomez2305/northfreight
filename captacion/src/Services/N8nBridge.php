<?php
/**
 * Puente con n8n (A1, A4 a A8, A12). n8n envia los WhatsApp/SMS; el CRM decide A QUIEN y deja constancia. Todo POR CONSULTA (n8n pregunta cada pocos minutos): si n8n se cae
 * no se pierde nada, y el CRM no necesita llamar a n8n. Dos puertas:
 *   - POST /api/webhooks/n8n.php  (HMAC con N8N_WEBHOOK_SECRET): eventos de los flujos.
 *   - /internal/*  (Bearer INTERNAL_API_TOKEN): sequences/due, reminders/due, leads/by-phone, PATCH leads/{id}, leads/{id}/events, consent-check, handoff-ready.
 * Permisos acotados: solo estas rutas, solo estos campos, y solo los datos personales que hacen falta para enviar (el telefono SOLO si el canal tiene consentimiento vigente).
 *
 * Pasos que n8n puede enviar por WhatsApp: los habilita Carlos en Configuracion > Canales externos (settings n8n.wa_steps); vacio = /internal/sequences/due no devuelve nada.
 * Claves: a1_welcome (bienvenida al llegar un lead), a4_booking (enlace de Cal.com al pasar a Calificado), a6_d1, a6_d3, a6_d7, a6_d14 (A6), a12_referral (A12).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;

final class N8nBridge
{
    public const EVENTS = ['message_sent', 'message_failed', 'sequence_step_sent', 'reminder_sent', 'reply_received', 'bot_finished', 'error'];
    public const STEP_LABELS = [
        'a1_welcome' => 'A1 · Bienvenida al llegar un lead', 'a4_booking' => 'A4 · Enlace de Cal.com al pasar a Calificado', 'a6_d1' => 'A6 · Día 1 (caso real)', 'a6_d3' => 'A6 · Día 3 (calculadora)',
        'a6_d7' => 'A6 · Día 7 (tarifas)', 'a6_d14' => 'A6 · Día 14 (último intento)', 'a12_referral' => 'A12 · Pedido de referidos', 'appt_reminder' => 'A5 · Recordatorios de cita (24 h y 1 h)',
    ];
    public const OUTCOMES = ['qualified', 'needs_human', 'not_qualified', 'abandoned', 'opted_out'];
    /** Campos que n8n puede actualizar (PATCH). FILL = solo si el lead no lo tiene; SET = el dato nuevo (lo dijo el lead al bot) reemplaza al anterior. */
    public const PATCH_FILL = ['phone', 'email'];
    public const PATCH_SET = ['language', 'state', 'city', 'equipment_types', 'truck_count', 'years_experience', 'usdot', 'english_level', 'cdl_type', 'authority_status', 'best_time', 'contact_pref'];
    public const MAX_PER_CALL = 100;

    public static function config(): array
    {
        $c = Settings::get('n8n', []);
        $c = is_array($c) ? $c : [];
        return [
            'wa_steps'    => array_values(array_intersect(array_keys(self::STEP_LABELS), array_map('strval', (array) ($c['wa_steps'] ?? [])))),
            'sms_enabled' => (bool) ($c['sms_enabled'] ?? false),
        ];
    }

    private static function tkey(mixed $v): string
    {
        $s = is_string($v) ? $v : '';
        return preg_match('/^[A-Za-z0-9_.-]{1,60}$/', $s) === 1 ? $s : '';
    }

    // --- Eventos ----------------------------------------------------------------------------------------------------------------------

    /**
     * Aplica un evento de n8n a un lead. Idempotencia y firma son de quien llama.
     * @return array{ok:bool,error?:string,summary?:string}
     */
    public static function applyEvent(int $leadId, array $e): array
    {
        $lead = Lead::find($leadId);
        if ($lead === null) {
            return ['ok' => false, 'error' => 'lead_not_found'];
        }
        $type = (string) ($e['event'] ?? ($e['type'] ?? ''));
        $channel = in_array($e['channel'] ?? '', ['whatsapp', 'sms', 'email'], true) ? (string) $e['channel'] : '';
        $tpl = self::tkey($e['template'] ?? '');
        $mid = self::tkey($e['message_id'] ?? '');
        switch ($type) {
            case 'message_sent':
            case 'message_failed':
                if ($channel === '' || $tpl === '') {
                    return ['ok' => false, 'error' => 'channel_and_template_required'];
                }
                $failed = $type === 'message_failed';
                Db::insert('messages', ['lead_id' => $leadId, 'channel' => $channel, 'direction' => 'out', 'template_key' => $tpl, 'provider_id' => $mid !== '' ? $mid : null, 'status' => $failed ? 'failed' : 'sent', 'created_at' => Clock::now()]);
                LeadIngest::event($leadId, $type, array_filter(['channel' => $channel, 'template' => $tpl, 'error' => $failed ? Inbound::clean(Storage::redact((string) ($e['error'] ?? '')), 160) : null], static fn($v) => $v !== null && $v !== ''));
                return ['ok' => true, 'summary' => $type];
            case 'sequence_step_sent':
                $seq = (string) ($e['sequence'] ?? '');
                $step = isset($e['step']) && is_int($e['step']) ? $e['step'] : -1;
                $def = Sequences::DEFS[$seq] ?? null;
                if ($channel === '' || $def === null || !isset($def['steps'][$step])) {
                    return ['ok' => false, 'error' => 'invalid_sequence_step'];
                }
                Db::run('UPDATE sequence_enrollments SET wa_step = GREATEST(wa_step, ?) WHERE lead_id = ? AND sequence_key = ?', [$step + 1, $leadId, $seq]);
                Db::insert('messages', ['lead_id' => $leadId, 'channel' => $channel, 'direction' => 'out', 'template_key' => $def['steps'][$step]['tkey'], 'provider_id' => $mid !== '' ? $mid : null, 'status' => 'sent', 'sequence_key' => $seq, 'created_at' => Clock::now()]);
                LeadIngest::event($leadId, 'sequence_message_sent', ['sequence' => $seq, 'step' => $def['steps'][$step]['label'], 'channel' => $channel]);
                return ['ok' => true, 'summary' => 'sequence_step_sent'];
            case 'reminder_sent':
                $which = ($e['which'] ?? '') === '1h' ? '1h' : (($e['which'] ?? '') === '24h' ? '24h' : '');
                $ap = is_int($e['appointment_id'] ?? null) ? (int) $e['appointment_id'] : 0;
                if ($which === '' || $ap < 1 || Db::one('SELECT id FROM appointments WHERE id = ? AND lead_id = ?', [$ap, $leadId]) === null) {
                    return ['ok' => false, 'error' => 'invalid_reminder'];
                }
                Db::run('UPDATE appointments SET ' . ($which === '24h' ? 'wa_rem24_at' : 'wa_rem1_at') . ' = ? WHERE id = ?', [Clock::now(), $ap]);
                Db::insert('messages', ['lead_id' => $leadId, 'channel' => $channel !== '' ? $channel : 'whatsapp', 'direction' => 'out', 'template_key' => 'appt_reminder_' . $which . '_wa', 'provider_id' => $mid !== '' ? $mid : null, 'status' => 'sent', 'created_at' => Clock::now()]);
                LeadIngest::event($leadId, 'reminder_sent', ['appointment_id' => $ap, 'which' => $which, 'channel' => $channel !== '' ? $channel : 'whatsapp']);
                return ['ok' => true, 'summary' => 'reminder_sent'];
            case 'reply_received':
                if ($channel === '') {
                    return ['ok' => false, 'error' => 'channel_required'];
                }
                $text = Inbound::clean($e['text'] ?? '', 1000);
                Inbound::record($leadId, $channel, $text, Inbound::when($e['at'] ?? null), $mid !== '' ? $mid : null, 'n8n');
                Inbound::replied($leadId, $channel, $text);
                return ['ok' => true, 'summary' => 'reply_received'];
            case 'bot_finished':
                $out = in_array($e['outcome'] ?? '', self::OUTCOMES, true) ? (string) $e['outcome'] : '';
                if ($out === '') {
                    return ['ok' => false, 'error' => 'invalid_outcome'];
                }
                LeadIngest::event($leadId, 'bot_finished', ['outcome' => $out, 'summary' => Inbound::clean($e['summary'] ?? '', 300)]);
                if ($out === 'opted_out') {
                    Consent::revokeChannels($leadId, ['whatsapp', 'whatsapp_inbound'], 'bot');
                } elseif ($out === 'qualified' || $out === 'needs_human') {
                    self::callTask($lead, $out === 'qualified' ? 'el bot lo precalificó' : 'el bot pide que lo atiendas', 'bot_' . $out);
                }
                return ['ok' => true, 'summary' => 'bot_finished:' . $out];
            case 'error':
                $msg = Inbound::clean(Storage::redact((string) ($e['error'] ?? 'sin detalle')), 200);
                LeadIngest::event($leadId, 'automation_error', ['workflow' => Inbound::clean($e['workflow'] ?? '', 60), 'error' => $msg]);
                Integrations::webhook('n8n', 'err', 'Flujo de n8n: ' . $msg);
                return ['ok' => true, 'summary' => 'error'];
        }
        return ['ok' => false, 'error' => 'unknown_event'];
    }

    /** Tarea «Llamar a X» + aviso por Telegram. Una sola tarea abierta de este tipo por lead. */
    private static function callTask(array $lead, string $why, string $kind): ?int
    {
        $leadId = (int) $lead['id'];
        $title = mb_substr('Llamar a ' . trim($lead['first_name'] . ' ' . $lead['last_name']) . ' (' . $why . ')', 0, 190);
        if (Db::one('SELECT id FROM tasks WHERE lead_id = ? AND deleted_at IS NULL AND done_at IS NULL AND appointment_id IS NULL AND title LIKE ? LIMIT 1', [$leadId, 'Llamar a %(%']) !== null) {
            return null;
        }
        $tid = Db::insert('tasks', ['title' => $title, 'lead_id' => $leadId, 'due_at' => Clock::now(), 'priority' => 1, 'created_at' => Clock::now()]);
        LeadIngest::event($leadId, 'task_created', ['task_id' => $tid, 'title' => $title, 'due_at' => Clock::now(), 'priority' => 1]);
        Queue::push('telegram_notice', ['lead_id' => $leadId, 'kind' => $kind], Clock::now());
        return $tid;
    }

    /** POST /internal/leads/{id}/handoff-ready: el bot (o n8n) dice que el lead esta listo para hablar con Carlos. No cambia la etapa. */
    public static function handoffReady(int $leadId, array $in): array
    {
        $lead = Lead::find($leadId);
        if ($lead === null) {
            return ['ok' => false, 'error' => 'lead_not_found'];
        }
        $summary = Inbound::clean($in['summary'] ?? '', 400);
        LeadIngest::event($leadId, 'handoff_ready', ['summary' => $summary, 'by' => 'n8n']);
        $tid = self::callTask($lead, 'listo para hablar', 'handoff_ready');
        return ['ok' => true, 'task_created' => $tid !== null];
    }

    // --- Consultas de n8n ---------------------------------------------------------------------------------------------------------------

    /** GET /internal/leads/by-phone: solo id e idioma. @return ?array{lead_id:int,language:string} */
    public static function byPhone(string $raw): ?array
    {
        $p = Phone::normalize($raw);
        $r = $p['ok'] ? Db::one('SELECT id, language FROM leads WHERE phone_e164 = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$p['e164']]) : null;
        return $r === null ? null : ['lead_id' => (int) $r['id'], 'language' => (string) $r['language']];
    }

    private static function item(array $lead, array $check, string $channel, array $over): array
    {
        $extra = is_array($over['vars_extra'] ?? null) ? $over['vars_extra'] : [];
        unset($over['vars_extra']);
        $eq = array_map(static fn($t) => Catalog::label(Catalog::EQUIPMENT, (string) $t), (array) Db::decode($lead['equipment_types']));
        return $over + [
            'lead_id' => (int) $lead['id'], 'channel' => $channel, 'to' => $lead['phone_e164'], 'language' => (string) $lead['language'],
            'basis' => $check['basis'], 'free_text_allowed' => $check['free_text_allowed'],
            'vars' => ['nombre' => (string) ($lead['first_name'] ?: ($lead['language'] === 'es' ? 'amigo' : 'there')), 'equipo' => $eq ? implode(', ', $eq) : '', 'estado' => (string) ($lead['state'] ?? '')] + $extra,
        ];
    }

    /**
     * GET /internal/sequences/due?channel=whatsapp|sms: quien debe recibir que paso AHORA, solo con consentimiento vigente en ese canal.
     * @return list<array<string,mixed>>
     */
    public static function due(string $channel, int $limit): array
    {
        $cfg = self::config();
        $on = $cfg['wa_steps'];
        if ($on === [] || !in_array($channel, ['whatsapp', 'sms'], true) || ($channel === 'sms' && !$cfg['sms_enabled'])) {
            return [];
        }
        $limit = max(1, min(self::MAX_PER_CALL, $limit));
        $items = [];
        $ok = static function (array $lead) use ($channel): ?array {
            if ($lead['phone_e164'] === null) {
                return null;
            }
            $c = Messaging::consentCheck((int) $lead['id'], $channel);
            return $c['allowed'] ? $c : null;
        };
        $sentOnce = static fn(int $id, string $tpl) => (int) Db::value("SELECT COUNT(*) FROM messages WHERE lead_id = ? AND direction = 'out' AND template_key = ?", [$id, $tpl]) > 0;
        if (in_array('a1_welcome', $on, true)) {
            foreach (Db::all("SELECT * FROM leads WHERE deleted_at IS NULL AND temperature <> 'disqualified' AND phone_e164 IS NOT NULL AND created_at >= ? ORDER BY id ASC LIMIT 300", [Clock::shift(-3 * 86400)]) as $l) {
                if (count($items) >= $limit) {
                    break;
                }
                if (!$sentOnce((int) $l['id'], 'a1_welcome') && ($c = $ok($l)) !== null) {
                    $items[] = self::item($l, $c, $channel, ['sequence' => 'a1_welcome', 'step' => 0, 'template' => 'a1_welcome', 'due_at' => $l['created_at']]);
                }
            }
        }
        $booking = Appointments::config()['booking_url'];
        if (in_array('a4_booking', $on, true) && $booking !== '') {
            foreach (Db::all("SELECT * FROM leads WHERE deleted_at IS NULL AND pipeline = 'A' AND stage = 'qualified' AND phone_e164 IS NOT NULL AND stage_changed_at >= ? ORDER BY stage_changed_at ASC LIMIT 300", [Clock::shift(-7 * 86400)]) as $l) {
                if (count($items) >= $limit) {
                    break;
                }
                if (!$sentOnce((int) $l['id'], 'a4_booking') && ($c = $ok($l)) !== null) {
                    $items[] = self::item($l, $c, $channel, ['sequence' => 'a4_booking', 'step' => 0, 'template' => 'a4_booking', 'due_at' => $l['stage_changed_at']]
                        + ['vars_extra' => ['booking_url' => $booking]]);
                }
            }
        }
        $rates = Sequences::mailSettings()['rates_source'] !== '';
        foreach (['a6_warm', 'a12_referral'] as $seq) {
            $steps = Sequences::DEFS[$seq]['steps'];
            foreach (Db::all("SELECT * FROM sequence_enrollments WHERE sequence_key = ? AND status = 'active' AND wa_step < ? ORDER BY id ASC LIMIT 300", [$seq, count($steps)]) as $e) {
                if (count($items) >= $limit) {
                    break 2;
                }
                for ($i = (int) $e['wa_step']; $i < count($steps); $i++) {
                    $s = $steps[$i];
                    if (!in_array($s['tkey'], $on, true) || (!empty($s['needs_source']) && !$rates)) {
                        continue;   // paso no habilitado para WhatsApp (o sin fuente real): no se envia por este canal
                    }
                    $due = (int) Clock::parse((string) $e['created_at']) + $s['day'] * 86400;
                    $lead = $due <= Clock::time() ? Lead::find((int) $e['lead_id']) : null;
                    if ($lead !== null && $lead['phone_e164'] !== null && ($c = $ok($lead)) !== null) {
                        $items[] = self::item($lead, $c, $channel, ['sequence' => $seq, 'step' => $i, 'template' => $s['tkey'], 'due_at' => Clock::at($due)]
                            + ['vars_extra' => $seq === 'a6_warm' ? ['link_calculadora' => Templates::SITE . '/resources/rate-per-mile-calculator/'] : []]);
                    }
                    break;
                }
            }
        }
        return $items;
    }

    /**
     * GET /internal/reminders/due: recordatorios de citas (24 h / 1 h) por WhatsApp que n8n debe enviar ahora. Un recordatorio solo existe si la cita se agendo con tiempo
     * de sobra (no se manda «24 h antes» una cita reservada ayer para hoy) y solo con consentimiento de WhatsApp vigente.
     * @return list<array<string,mixed>>
     */
    public static function dueReminders(int $limit): array
    {
        if (!in_array('appt_reminder', self::config()['wa_steps'], true)) {
            return [];
        }
        $now = Clock::time();
        $rows = Db::all(
            "SELECT * FROM appointments WHERE deleted_at IS NULL AND status = 'booked' AND starts_at > ? AND starts_at <= ? ORDER BY starts_at ASC LIMIT 300",
            [Clock::at($now + 600), Clock::at($now + 86400)]
        );
        $items = [];
        foreach ($rows as $a) {
            $start = (int) Clock::parse((string) $a['starts_at']);
            $created = (int) Clock::parse((string) $a['created_at']);
            $which = null;
            if ($a['wa_rem24_at'] === null && $start - $now > 7200 && $created <= $start - 86400 + 1800) {
                $which = '24h';
            } elseif ($a['wa_rem1_at'] === null && $start - $now <= 3600 && $start - $now > 600 && $created <= $start - 3600 + 300) {
                $which = '1h';
            }
            if ($which === null || count($items) >= max(1, min(self::MAX_PER_CALL, $limit))) {
                continue;
            }
            $lead = Lead::find((int) $a['lead_id']);
            $c = $lead !== null && $lead['phone_e164'] !== null ? Messaging::consentCheck((int) $lead['id'], 'whatsapp') : null;
            if ($c !== null && $c['allowed']) {
                $items[] = self::item($lead, $c, 'whatsapp', ['appointment_id' => (int) $a['id'], 'which' => $which, 'template' => 'appt_reminder_' . $which . '_wa', 'starts_at' => $a['starts_at'],
                    'when_text' => Appointments::whenText($start, $a['tz'], (string) $lead['language']), 'meeting_url' => $a['meeting_url']]);
            }
        }
        return $items;
    }

    // --- PATCH /internal/leads/{id} --------------------------------------------------------------------------------------------------------

    /**
     * Actualiza SOLO la lista blanca. Valida con el mismo normalizador que el resto del CRM; recalcula la puntuacion y deja un evento (nombres de campo, sin valores).
     * @return array{ok:bool,error?:string,fields?:list<string>,updated?:list<string>,rejected?:array<string,string>,score?:int,temperature?:string}
     */
    public static function patch(int $leadId, array $in): array
    {
        $allowed = array_merge(self::PATCH_FILL, self::PATCH_SET);
        $unknown = array_values(array_diff(array_keys($in), $allowed));
        if ($unknown !== []) {
            return ['ok' => false, 'error' => 'fields_not_allowed', 'fields' => array_map(static fn($k) => mb_substr((string) $k, 0, 40), array_slice($unknown, 0, 10))];
        }
        if ($in === []) {
            return ['ok' => false, 'error' => 'empty'];
        }
        return Db::transaction(static function () use ($leadId, $in): array {
            $lead = Lead::find($leadId, true);
            if ($lead === null) {
                return ['ok' => false, 'error' => 'lead_not_found'];
            }
            $probe = ['profile' => $lead['profile'], 'name' => $lead['first_name'], 'email' => $lead['email'] ?? '', 'phone' => $lead['phone_raw'] ?? ($lead['phone_e164'] ?? '')];
            $rejected = [];
            $send = [];
            foreach ($in as $k => $v) {
                if (in_array($k, self::PATCH_FILL, true) && ($k === 'email' ? $lead['email'] : $lead['phone_e164']) !== null) {
                    $rejected[$k] = 'already_set';
                    continue;
                }
                $send[$k] = $v;
            }
            $n = LeadPayload::normalize($send + $probe)['lead'];
            $set = [];
            $flags = $lead['flags'];
            foreach ($send as $k => $v) {
                $new = match ($k) {
                    'phone' => $n['phone_e164'], 'language' => (is_string($v) && in_array(strtolower(substr($v, 0, 2)), ['en', 'es'], true)) ? $n['language'] : null,
                    default => $n[$k] ?? null,
                };
                if ($new === null || $new === '' || $new === []) {
                    $rejected[$k] = 'invalid';
                    continue;
                }
                if ($k === 'phone') {
                    if (Db::one('SELECT id FROM leads WHERE phone_e164 = ? AND id <> ? AND deleted_at IS NULL LIMIT 1', [$new, $leadId]) !== null) {
                        $rejected[$k] = 'phone_in_use';
                        continue;
                    }
                    $set['phone_e164'] = $new;
                    $set['phone_raw'] = $n['phone_raw'];
                    $flags = Flags::remove($flags, 'phone_invalid');
                    continue;
                }
                if ($new !== $lead[$k]) {
                    $set[$k] = $new;
                }
            }
            if (isset($set['usdot'])) {
                $set['fmcsa_data'] = null;   // el dato de FMCSA era de otro USDOT
                $set['fmcsa_checked_at'] = null;
                $flags = Flags::add(Flags::remove($flags, 'usdot_not_found'), 'fmcsa_pending');
                Queue::push('fmcsa_enrich', ['lead_id' => $leadId], Clock::now(), 'fmcsa_enrich:' . $leadId . ':' . $set['usdot']);
            }
            $out = ['ok' => true, 'updated' => array_values(array_map(static fn($c) => $c === 'phone_e164' ? 'phone' : $c, array_diff(array_keys($set), ['phone_raw', 'fmcsa_data', 'fmcsa_checked_at']))), 'rejected' => $rejected];
            if ($set !== []) {
                $set['flags'] = $flags;
                Db::update('leads', $leadId, $set);
                LeadIngest::event($leadId, 'lead_updated', ['by' => 'n8n', 'fields' => $out['updated']]);
                ActivityLog::record('internal_patch', null, 'lead', $leadId, ['fields' => $out['updated']]);
            }
            $r = LeadIngest::rescore($leadId, 'n8n_update');
            $row = Db::one('SELECT score, temperature FROM leads WHERE id = ?', [$leadId]);
            return $out + ['score' => (int) ($r['score'] ?? $row['score']), 'temperature' => (string) ($r['temperature'] ?? $row['temperature'])];
        });
    }
}
