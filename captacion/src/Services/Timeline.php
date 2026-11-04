<?php
/** Linea de tiempo unificada de un lead: convierte lead_events (y mensajes que no son llamadas) en entradas legibles con icono, tono, grupo de filtro y detalle. */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Db;

final class Timeline
{
    /**
     * @param array<int,string> $notes id de nota => texto
     * @return list<array{at:string,icon:string,tone:string,group:string,title:string,detail:string,by:?string}>
     */
    public static function build(array $lead, array $events, array $messages, array $notes, array $users): array
    {
        $out = [];
        foreach ($events as $e) {
            $p = Db::decode($e['payload']);
            $out[] = self::describe($lead, (string) $e['type'], $p, (string) $e['created_at'], $notes, isset($e['user_id']) && $e['user_id'] !== null ? ($users[(int) $e['user_id']] ?? null) : null);
        }
        foreach ($messages as $m) {
            $dir = $m['direction'] === 'in' ? 'Mensaje recibido' : 'Mensaje enviado';
            $out[] = ['at' => (string) $m['created_at'], 'icon' => $m['channel'] === 'email' ? 'mail' : 'chat', 'tone' => $m['direction'] === 'in' ? 'cyan' : 'navy', 'group' => 'contact',
                'title' => $dir . ' · ' . ucfirst((string) $m['channel']), 'detail' => mb_substr((string) $m['body'], 0, 240), 'by' => null];
        }
        usort($out, static fn($a, $b) => strcmp($b['at'], $a['at']));
        return $out;
    }

    private static function describe(array $lead, string $type, array $p, string $at, array $notes, ?string $by): array
    {
        $pipeline = (string) ($lead['pipeline'] ?? 'A');
        $icon = 'history';
        $tone = 'slate';
        $detail = '';
        $title = Catalog::EVENT_LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type));
        switch ($type) {
            case 'created':
                $icon = 'inbox';
                $tone = 'cyan';
                $detail = 'Fuente: ' . Catalog::source($p['source'] ?? null) . (!empty($p['landing_page']) ? ' · ' . $p['landing_page'] : '');
                break;
            case 'reentry':
                $icon = 'refresh';
                $tone = 'cyan';
                $detail = !empty($p['filled']) ? 'Campos nuevos: ' . implode(', ', array_map('strval', (array) $p['filled'])) : 'Sin datos nuevos.';
                break;
            case 'reopened':
            case 'reentry_signed':
                $icon = 'refresh';
                $tone = 'cyan';
                break;
            case 'scored':
                $icon = 'sliders';
                $detail = 'Puntuación ' . (int) ($p['score'] ?? 0) . (isset($p['previous']) ? ' (antes ' . (int) $p['previous'] . ')' : '') . ' · ' . Catalog::label(Catalog::TEMPS, $p['temperature'] ?? null);
                break;
            case 'fmcsa':
                $icon = 'shield';
                $tone = !empty($p['red_flags']) ? 'bad' : 'slate';
                $detail = empty($p['found']) ? 'USDOT no encontrado en FMCSA.' : 'Autoridad: ' . Catalog::label(Catalog::AUTHORITY, $p['authority_status'] ?? null) . (isset($p['insured']) ? ' · Seguro: ' . ($p['insured'] ? 'sí' : 'no') : '')
                    . (!empty($p['red_flags']) ? ' · Banderas: ' . implode(', ', array_map(static fn($f) => Catalog::label(Catalog::FLAGS, 'red:' . $f), (array) $p['red_flags'])) : '');
                break;
            case 'disqualified':
                $icon = 'flag';
                $tone = 'bad';
                $detail = implode(', ', array_map(static fn($r) => Catalog::label(Catalog::DISQUALIFIED, (string) $r), (array) ($p['reasons'] ?? [])));
                break;
            case 'alert_sent':
                $icon = 'bell';
                $kinds = ['hot' => 'Alerta de lead caliente', 'followup' => 'Segunda alerta (sin contacto en 15 min)', 'signed_reentry' => 'Alerta: lead firmado volvió a escribir'];
                $title = $kinds[$p['kind'] ?? 'hot'] ?? 'Alerta enviada';
                $detail = 'Por ' . ucfirst((string) ($p['channel'] ?? 'telegram')) . '.';
                break;
            case 'a3_cancelled':
                $icon = 'bell';
                $detail = (string) ($p['why'] ?? '');
                break;
            case 'stage_changed':
                $icon = 'layers';
                $tone = ($p['to'] ?? '') === 'lost' ? 'bad' : (in_array($p['to'] ?? '', ['signed', 'hired'], true) ? 'good' : 'navy');
                $from = Catalog::stageLabel((string) ($p['pipeline'] ?? $pipeline), $p['from'] ?? null);
                $to = Catalog::stageLabel((string) ($p['pipeline'] ?? $pipeline), $p['to'] ?? null);
                $title = 'Etapa: ' . $from . ' → ' . $to;
                $bits = [];
                if (!empty($p['reason'])) {
                    $bits[] = 'Motivo: ' . Catalog::label(Catalog::LOSS_REASONS, (string) $p['reason']);
                }
                if (!empty($p['note'])) {
                    $bits[] = (string) $p['note'];
                }
                if (!empty($p['override'])) {
                    $bits[] = 'Salto de orden confirmado';
                }
                if (!empty($p['auto'])) {
                    $bits[] = 'Automático';
                }
                $detail = implode(' · ', $bits);
                break;
            case 'reopened_manual':
                $icon = 'refresh';
                $tone = 'cyan';
                $detail = 'Vuelve a «' . Catalog::stageLabel($pipeline, $p['to'] ?? 'new') . '»' . (!empty($p['from_reason']) ? ' · antes: ' . Catalog::label(Catalog::LOSS_REASONS, (string) $p['from_reason']) : '');
                break;
            case 'call_logged':
                $icon = 'phone';
                $tone = ($p['outcome'] ?? '') === 'answered' ? 'good' : 'slate';
                $title = 'Llamada · ' . Catalog::label(Catalog::CALL_OUTCOMES, $p['outcome'] ?? null);
                $detail = trim((isset($p['minutes']) ? (int) $p['minutes'] . ' min' : '') . (!empty($p['note']) ? (isset($p['minutes']) ? ' · ' : '') . $p['note'] : ''));
                break;
            case 'note_added':
            case 'note_edited':
            case 'note_deleted':
                $icon = 'note';
                $detail = $type === 'note_deleted' ? '' : mb_substr((string) ($notes[(int) ($p['note_id'] ?? 0)] ?? ''), 0, 200);
                break;
            case 'task_created':
                $icon = 'calendar';
                $detail = (string) ($p['title'] ?? '') . (!empty($p['due_at']) ? ' · para ' . Fmt::dateTime((string) $p['due_at']) : '');
                break;
            case 'task_done':
                $icon = 'check-circle';
                $tone = 'good';
                $detail = (string) ($p['title'] ?? '');
                break;
            case 'task_reopened':
            case 'task_edited':
            case 'task_deleted':
                $icon = 'calendar';
                $detail = (string) ($p['title'] ?? '');
                break;
            case 'task_snoozed':
                $icon = 'clock';
                $detail = (string) ($p['title'] ?? '') . (!empty($p['to']) ? ' · nueva fecha ' . Fmt::dateTime((string) $p['to']) : '');
                break;
            case 'consent_revoked':
                $icon = 'shield';
                $tone = 'bad';
                $detail = 'Canal: ' . ($p['channel'] ?? 'all') . ' · vía ' . ($p['via'] ?? '—');
                break;
        }
        $group = Catalog::EVENT_GROUPS[$type] ?? (in_array($type, ['created', 'scored', 'fmcsa', 'disqualified', 'alert_sent', 'a3_cancelled', 'reentry', 'reentry_signed', 'consent_revoked'], true) ? 'system' : 'system');
        return ['at' => $at, 'icon' => $icon, 'tone' => $tone, 'group' => $group, 'title' => $title, 'detail' => $detail, 'by' => $by];
    }
}
