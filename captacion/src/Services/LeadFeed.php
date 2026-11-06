<?php
/**
 * Cronologia global (Q5c-D): la actividad de TODOS los leads en un solo hilo, de lo mas nuevo a lo mas viejo, agrupada por dia (en la zona de visualizacion).
 * Lee `lead_events` (la misma fuente de la linea de tiempo de la ficha, reusando Timeline::entry) con los MISMOS filtros de lead que la Lista, filtro por tipo y por lead.
 *
 * Paginacion por CURSOR (created_at, id): estable aunque lleguen eventos nuevos mientras se pagina y sin OFFSET (usa idx_events_created). Cada pagina trae PAGE eventos; la consulta
 * corre con un tope de tiempo (Db::withTimeout). Solo muestra lo que el administrador ya ve en la ficha: no incluye cuerpos de mensajes ni el texto de las notas, ni telefonos o correos.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Db;

final class LeadFeed
{
    public const PAGE = 30;
    public const TIMEOUT = 4;
    /** grupo => [etiqueta, icono por defecto, tipos de evento de lead_events]. Lo que no esta aqui (puntuacion, FMCSA, consentimientos…) es ruido y no se muestra. */
    public const TYPES = [
        'arrivals' => ['Llegadas', 'inbox', ['created', 'reentry', 'reopened', 'reentry_signed', 'prospect_converted', 'referral_linked']],
        'stages'   => ['Etapas', 'layers', ['stage_changed', 'reopened_manual']],
        'notes'    => ['Notas', 'note', ['note_added', 'note_edited']],
        'calls'    => ['Llamadas', 'phone', ['call_logged']],
        'tasks'    => ['Tareas', 'list-check', ['task_created', 'task_done', 'task_snoozed', 'task_rescheduled']],
        'appts'    => ['Citas', 'calendar', ['appointment_booked', 'appointment_rescheduled', 'appointment_cancelled']],
        'handoffs' => ['Entregas', 'send', ['handoff_created', 'handoff_resent', 'handoff_sent', 'handoff_failed', 'handoff_no_target']],
        'signed'   => ['Firmas', 'check-circle', ['handoff_confirmed', 'handoff_rejected', 'conversion', 'referral_signed']],
        'messages' => ['Mensajes', 'chat', ['inbound_message', 'lead_replied', 'message_sent', 'message_failed', 'sequence_email', 'sequence_message_sent', 'lead_responded']],
        'alerts'   => ['Alertas', 'bell', ['alert_sent', 'rule_alert_skipped', 'handoff_ready', 'automation_error']],
    ];

    /** Grupos validos de `tp` (lista separada por comas); vacio o sin ninguno valido = todos. @return list<string> */
    public static function types(mixed $raw): array
    {
        $in = is_string($raw) ? explode(',', $raw) : [];
        $out = array_values(array_filter(array_keys(self::TYPES), static fn($k) => in_array($k, $in, true)));
        return $out ?: array_keys(self::TYPES);
    }

    /** Lead elegido (`ld` de la URL): solo el id numerico. */
    public static function leadId(mixed $v): ?int
    {
        return is_string($v) && preg_match('/^\d{1,12}$/', $v) === 1 && (int) $v > 0 ? (int) $v : null;
    }

    /** @return array{0:string,1:int}|null cursor valido: [fecha UTC, id] */
    public static function decodeCursor(mixed $c): ?array
    {
        if (!is_string($c) || preg_match('/^[A-Za-z0-9_-]{8,60}$/', $c) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr($c, '-_', '+/'), true);
        if ($raw === false || preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d{1,12})$/', $raw, $m) !== 1) {
            return null;
        }
        return [$m[1], (int) $m[2]];
    }

    public static function encodeCursor(string $at, int $id): string
    {
        return rtrim(strtr(base64_encode($at . '|' . $id), '+/', '-_'), '=');
    }

    /**
     * Una pagina de la cronologia. @param array<string,string> $f filtros de LeadQuery @param list<string> $types
     * @return array{days:list<array{key:string,label:string,items:list<array>}>,count:int,next:?string}
     * @throws \Captacion\QueryTimeout
     */
    public static function page(array $f, array $types, ?int $leadId, ?string $cursor, int $now): array
    {
        [$where, $params] = LeadQuery::where($f);
        $names = [];
        foreach ($types as $g) {
            foreach (self::TYPES[$g][2] ?? [] as $t) {
                $names[] = $t;
            }
        }
        if (!$names) {
            return ['days' => [], 'count' => 0, 'next' => null];
        }
        $sql = "SELECT e.id, e.lead_id, e.type, e.payload, e.user_id, e.created_at, l.first_name, l.last_name, l.pipeline, l.stage, l.temperature
                  FROM lead_events e JOIN leads l ON l.id = e.lead_id
                 WHERE $where AND e.type IN (" . implode(',', array_fill(0, count($names), '?')) . ')';
        $params = [...$params, ...$names];
        if ($leadId !== null) {
            $sql .= ' AND e.lead_id = ?';
            $params[] = $leadId;
        }
        $cur = self::decodeCursor($cursor);
        if ($cur !== null) {
            $sql .= ' AND (e.created_at < ? OR (e.created_at = ? AND e.id < ?))';
            array_push($params, $cur[0], $cur[0], $cur[1]);
        }
        $sql .= ' ORDER BY e.created_at DESC, e.id DESC LIMIT ' . (self::PAGE + 1);
        $rows = Db::withTimeout(self::TIMEOUT, static fn() => Db::all($sql, $params));
        $more = count($rows) > self::PAGE;
        $rows = array_slice($rows, 0, self::PAGE);
        $users = [];
        foreach (Db::all('SELECT id, name FROM users') as $u) {
            $users[(int) $u['id']] = (string) $u['name'];
        }
        $groupOf = [];
        foreach (self::TYPES as $g => [, $icon, $ts]) {
            foreach ($ts as $t) {
                $groupOf[$t] = [$g, $icon];
            }
        }
        $days = [];
        foreach ($rows as $r) {
            $en = Timeline::entry(['pipeline' => $r['pipeline']], (string) $r['type'], Db::decode($r['payload']), (string) $r['created_at'], $r['user_id'] !== null ? ($users[(int) $r['user_id']] ?? null) : null);
            [$g, $icon] = $groupOf[$r['type']] ?? ['other', 'history'];
            if ($en['icon'] === 'history') {
                $en['icon'] = $icon;
            }
            $key = Fmt::et((string) $r['created_at'])->format('Y-m-d');
            $days[$key]['key'] = $key;
            $days[$key]['label'] = Tasks::dayName($key, $now);
            $days[$key]['items'][] = $en + ['id' => (int) $r['id'], 'lead_id' => (int) $r['lead_id'], 'lead' => Fmt::name($r), 'code' => Fmt::code((int) $r['lead_id']), 'type' => (string) $r['type'], 'cat' => $g, 'raw_at' => (string) $r['created_at'], 'temp' => (string) $r['temperature']];
        }
        $last = $rows ? $rows[count($rows) - 1] : null;
        return ['days' => array_values($days), 'count' => count($rows), 'next' => $more && $last !== null ? self::encodeCursor((string) $last['created_at'], (int) $last['id']) : null];
    }
}
