<?php
/**
 * Efectos de las reglas sobre un lead real (Q5b): banderas (etiquetas y cola de revision), registro de aplicaciones, alertas por Telegram (a la cola), tareas y el handler del job `rule_alert`.
 * Todo efecto con consecuencias (alerta, tarea, etiqueta, cola) se registra UNA vez por regla, lead e intento (rule_applications.dedupe_key UNIQUE): republicar una version o recalcular no lo repite.
 * Las alertas respetan el horario de silencio (las normales), la deduplicacion y un tope por hora (ajuste rules.alert_cap, 20 por defecto). El recalculo masivo y las importaciones
 * ($fire = false) solo ponen banderas y registran los puntos: nunca crean tareas ni alertas por cientos de leads.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;

final class RuleEffects
{
    public const ALERT_CAP = 20;

    /** Quita las banderas que ponen las reglas (tag:*, review_queue) y vuelve a poner las vigentes. @param list<string> $flags */
    public static function flags(array $flags, ?array $effects): array
    {
        $flags = array_values(array_filter($flags, static fn($f) => !str_starts_with((string) $f, 'tag:') && $f !== 'review_queue'));
        if ($effects === null) {
            return $flags;
        }
        foreach ((array) $effects['tags'] as $t) {
            $flags[] = 'tag:' . $t;
        }
        if (!empty($effects['review'])) {
            $flags[] = 'review_queue';
        }
        return array_values(array_unique($flags));
    }

    /** Firma de lo que las reglas de puntuacion hicieron (para registrar solo cuando cambia). */
    private static function signature(array $breakdown): string
    {
        $sig = [];
        foreach ((array) ($breakdown['rules'] ?? []) as $r) {
            if (($r['kind'] ?? '') !== 'scoring') {
                continue;
            }
            foreach ((array) $r['effects'] as $e) {
                $sig[] = $r['set_id'] . ':' . $r['rule_id'] . ':' . $e['type'] . ':' . ($e['points'] ?? ($e['level'] ?? ''));
            }
        }
        sort($sig);
        return implode('|', $sig);
    }

    /** INSERT IGNORE en rule_applications; true si es la primera vez. */
    private static function once(int $leadId, array $a, string $action, ?int $points, array $detail, ?string $key): bool
    {
        $st = Db::run(
            'INSERT IGNORE INTO rule_applications (lead_id, set_id, version_id, rule_id, rule_name, kind, action, points, detail, dedupe_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$leadId, $a['set_id'], $a['version_id'], $a['rule_id'], mb_substr((string) $a['name'], 0, 80), $a['kind'], $action, $points, $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null, $key, Clock::now()]
        );
        return $st->rowCount() === 1;
    }

    /**
     * Despues de guardar la puntuacion de un lead: registra lo que hicieron las reglas y ejecuta los efectos con consecuencias.
     * @param array|null $prevBreakdown desglose anterior del lead (para registrar los puntos solo cuando cambian)
     */
    public static function persist(int $leadId, array $lead, array $scored, ?array $prevBreakdown, int $attempt, bool $fire): void
    {
        $fx = $scored['effects'] ?? null;
        if ($fx === null) {
            return;
        }
        // Puntos y temperatura minima: un registro por cada cambio de lo que las reglas de puntuacion hicieron.
        if (self::signature($scored['breakdown']) !== self::signature((array) $prevBreakdown)) {
            foreach ($fx['applied'] as $a) {
                if ($a['kind'] !== 'scoring') {
                    continue;
                }
                foreach ($a['effects'] as $e) {
                    self::once($leadId, $a, $e['type'], $e['type'] === 'add_points' ? (int) $e['points'] : null, array_diff_key($e, ['type' => 1]), null);
                }
            }
        }
        $vars = RuleRunner::vars($lead, (int) $scored['score'], (string) $scored['temperature']);
        foreach ($fx['applied'] as $a) {
            foreach ($a['effects'] as $e) {
                $base = $a['set_id'] . ':' . $a['rule_id'] . ':' . $leadId . ':' . $attempt;
                if ($e['type'] === 'tag') {
                    self::once($leadId, $a, 'tag', null, ['tag' => $e['tag']], 'rule_tag:' . $base . ':' . $e['tag']);
                } elseif ($e['type'] === 'review_queue') {
                    self::once($leadId, $a, 'review_queue', null, [], 'rule_review:' . $base);
                } elseif ($fire && $e['type'] === 'telegram_alert') {
                    if (self::once($leadId, $a, 'telegram_alert', null, ['urgency' => $e['urgency']], 'rule_alert:' . $base)) {
                        $at = $e['urgency'] === 'urgent' ? Clock::now() : Alerts::nonUrgentRunAt();
                        Queue::push('rule_alert', ['lead_id' => $leadId, 'set_id' => $a['set_id'], 'version_id' => $a['version_id'], 'rule_id' => $a['rule_id'], 'name' => $a['name'], 'urgency' => $e['urgency'], 'text' => $e['text']], $at, 'rule_alert:' . $base);
                    }
                } elseif ($fire && $e['type'] === 'create_task') {
                    $task = null;
                    foreach ($fx['tasks'] as $t) {
                        if ($t['rule_id'] === $a['rule_id'] && $t['set_id'] === $a['set_id']) {
                            $task = $t;
                        }
                    }
                    if ($task !== null && self::once($leadId, $a, 'create_task', null, ['title' => mb_substr($task['title'], 0, 120)], 'rule_task:' . $base)) {
                        $title = mb_substr(Rules::render($task['title'], $vars), 0, 190);
                        $due = Clock::at(Clock::time() + $task['due_hours'] * 3600);
                        $id = Db::insert('tasks', ['title' => $title !== '' ? $title : 'Revisar lead', 'lead_id' => $leadId, 'due_at' => $due, 'priority' => $task['priority'], 'created_at' => Clock::now()]);
                        LeadIngest::event($leadId, 'task_created', ['task_id' => $id, 'title' => $title, 'due_at' => $due, 'priority' => $task['priority'], 'rule' => $a['name']]);
                    }
                }
            }
        }
    }

    /** Handler del job rule_alert. */
    public static function jobAlert(array $p): void
    {
        $lead = Lead::find((int) ($p['lead_id'] ?? 0));
        if ($lead === null) {
            throw new PermanentJobFailure('El lead de la regla ya no existe.');
        }
        $name = (string) ($p['name'] ?? 'Regla');
        // Sigue publicada y activa? (si se retiro o se apago despues de encolarse, no se avisa)
        $live = false;
        foreach (RuleSets::engineSets() as $s) {
            if ($s['set_id'] === (int) ($p['set_id'] ?? 0)) {
                foreach ($s['rules'] as $r) {
                    $live = $live || ($r['id'] === ($p['rule_id'] ?? '') && !empty($r['enabled']));
                }
            }
        }
        if (!$live || !RuleRunner::enabled()) {
            return;
        }
        $cap = max(1, (int) Settings::get('rules.alert_cap', self::ALERT_CAP));
        if (RateLimiter::tooMany('rule_alert', 'all', $cap, 3600)) {
            LeadIngest::event((int) $lead['id'], 'rule_alert_skipped', ['rule' => $name, 'reason' => 'cap']);
            return;
        }
        RateLimiter::hit('rule_alert', 'all', 3600);
        $text = Rules::render((string) ($p['text'] ?? ''), RuleRunner::vars($lead, (int) $lead['score'], (string) $lead['temperature']));
        Telegram::send(Telegram::ruleText($lead, $name, $text));
        LeadIngest::event((int) $lead['id'], 'alert_sent', ['channel' => 'telegram', 'kind' => 'rule', 'rule' => $name]);
    }
}
