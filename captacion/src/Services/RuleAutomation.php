<?php
/**
 * Reglas por eventos y por tiempo (fase AM) sobre el motor de reglas existente (Rules, RuleEngine, RuleSets con versiones, editor y 2FA).
 *
 * Un conjunto de tipo `automation` lleva, en cada regla, un DISPARADOR (`trigger`) ademas de CUANDO / ENTONCES / EXCEPTO:
 *   - stage_change      el lead pasa a una etapa (o a cualquiera)               -> se evalua al instante (LeadActions::event -> onEvent)
 *   - consent_revoked   se revoca el consentimiento (un canal o todos)          -> al instante (Consent::revoke*)
 *   - no_first_contact  N horas sin primer contacto (lead en «Nuevo»)           -> job `rules_time` cada 15 minutos (tick)
 *   - task_overdue      una tarea abierta del lead lleva N horas vencida         -> job `rules_time` cada 15 minutos (tick)
 * Acciones: avisar en la campana (rule_notices), enviar webhook saliente (rule.fired), avisar por Telegram (cola rule_alert) y crear una tarea. NUNCA cambian la etapa ni asignan
 * responsable: lo primero se saltaria las validaciones de etapa y lo segundo lo cubre el reparto automatico del frente de responsables.
 *
 * Idempotencia: rule_applications.dedupe_key (UNIQUE) por regla + lead + intento + discriminador del suceso (id de tarea y vencimiento; segundo del evento; «nfc»), asi que ni el job
 * recurrente, ni un reintento ni republicar una version repiten un efecto. Nada de esto rompe nunca la accion que lo origino: todo error se registra y se traga.
 * La simulacion (simLead) evalua los mismos disparadores sobre los datos que hay y NO escribe nada.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;

final class RuleAutomation
{
    public const TICK_SECONDS = 900;   // el job de tiempo corre cada 15 minutos
    private const SCAN_MAX = 500;      // leads o tareas revisados por pasada y por disparador
    private const SCAN_DAYS = 45;      // no se reactivan leads o tareas mas viejos que esto

    // --- Lo que ve el motor ----------------------------------------------------------------------------------------------------------

    /** @return list<array> conjuntos publicados de eventos y tiempo ([] si las reglas estan apagadas o no hay) */
    public static function sets(): array
    {
        if (!RuleRunner::enabled()) {
            return [];
        }
        try {
            return RuleSets::automationSets();
        } catch (\Throwable $t) {
            Storage::error('rules automation unavailable', get_class($t) . ': ' . $t->getMessage());
            return [];
        }
    }

    /** Reglas activas con un disparador dado: @return list<array{0:array,1:array}> pares [conjunto, regla] */
    private static function rulesFor(array $sets, string $trigger): array
    {
        $out = [];
        foreach ($sets as $s) {
            foreach ((array) $s['rules'] as $r) {
                if (!empty($r['enabled']) && ($r['trigger']['type'] ?? '') === $trigger) {
                    $out[] = [$s, $r];
                }
            }
        }
        return $out;
    }

    // --- Eventos al instante -------------------------------------------------------------------------------------------------------------

    /**
     * Gancho de lead_events (LeadActions::event y Consent): dispara lo que corresponde a un evento ya registrado. Nunca lanza.
     * Tambien emite el webhook saliente que corresponde (stage_changed, consent.revoked).
     */
    public static function onEvent(int $leadId, string $type, array $payload): void
    {
        try {
            if ($type === 'stage_changed') {
                $to = (string) ($payload['to'] ?? '');
                $from = (string) ($payload['from'] ?? '');
                $key = 'stage:' . $leadId . ':' . $from . '>' . $to . ':' . Clock::time();
                OutboundWebhooks::emit('lead.stage_changed', $leadId, ['from' => $from, 'to' => $to], $key);
                self::fire('stage_change', $leadId, ['to' => $to, 'from' => $from], 's' . substr(sha1($key), 0, 12));
            } elseif ($type === 'consent_revoked') {
                $chs = array_values(array_filter(explode('+', (string) ($payload['channel'] ?? ''))));
                $key = 'consent:' . $leadId . ':' . implode('+', $chs) . ':' . Clock::time();
                OutboundWebhooks::emit('consent.revoked', $leadId, ['channels' => $chs], $key);
                self::fire('consent_revoked', $leadId, ['channels' => $chs], 'c' . substr(sha1($key), 0, 12));
            }
        } catch (\Throwable $t) {
            Storage::error('rules onEvent failed', '#' . $leadId . ' ' . $type . ' ' . get_class($t) . ': ' . $t->getMessage());
        }
    }

    /** Evalua y aplica las reglas de un disparador de evento para un lead. @return int reglas que actuaron */
    public static function fire(string $trigger, int $leadId, array $ctx, string $disc): int
    {
        $sets = self::sets();
        $pairs = $sets ? self::rulesFor($sets, $trigger) : [];
        if (!$pairs) {
            return 0;
        }
        $lead = Lead::find($leadId);
        if ($lead === null) {
            return 0;
        }
        $facts = self::facts($lead, $sets);
        $n = 0;
        foreach ($pairs as [$set, $rule]) {
            if (!RuleEngine::triggerMatches((array) $rule['trigger'], $trigger, $ctx) || RuleEngine::matchesEvent($rule, $facts) !== 'match') {
                continue;
            }
            $n += self::apply($set, $rule, $lead, $trigger, $disc) ? 1 : 0;
        }
        return $n;
    }

    /** Datos del lead para las condiciones (incluye puntuacion y temperatura actuales). */
    private static function facts(array $lead, array $sets): array
    {
        $consent = RuleEngine::usesField($sets, 'consent') ? (RuleRunner::consentMap([$lead])[(int) $lead['id']] ?? []) : [];
        $f = RuleRunner::facts($lead, Clock::time(), $consent);
        $f['score'] = (int) ($lead['score'] ?? 0);
        $f['temperature'] = (string) ($lead['temperature'] ?? '');
        return $f;
    }

    /**
     * Ejecuta las acciones de UNA regla para un lead, una sola vez por (regla, lead, intento, suceso).
     * @return bool true si hizo algo (false si ya se habia aplicado)
     */
    private static function apply(array $set, array $rule, array $lead, string $trigger, string $disc): bool
    {
        $leadId = (int) $lead['id'];
        $base = $set['set_id'] . ':' . $rule['id'] . ':' . $leadId . ':' . (int) $lead['attempt'] . ':' . $disc;
        $a = ['set_id' => $set['set_id'], 'version_id' => $set['version_id'], 'rule_id' => (string) $rule['id'], 'name' => (string) $rule['name'], 'kind' => 'automation'];
        $vars = RuleRunner::vars($lead, (int) $lead['score'], (string) $lead['temperature']);
        $did = false;
        foreach ((array) $rule['then'] as $act) {
            $type = (string) ($act['type'] ?? '');
            $key = 'rule_evt:' . substr(sha1($base . ':' . $type), 0, 40);
            if (!RuleEffects::once($leadId, $a, $type, null, ['trigger' => $trigger], $key)) {
                continue;
            }
            $did = true;
            switch ($type) {
                case 'notify_bell':
                    $text = mb_substr(Rules::render((string) ($act['text'] ?? ''), $vars), 0, 190);
                    $text = $text !== '' ? $text : (string) $rule['name'];
                    Db::insert('rule_notices', ['lead_id' => $leadId, 'set_id' => $set['set_id'], 'rule_id' => (string) $rule['id'], 'rule_name' => mb_substr((string) $rule['name'], 0, 80), 'text' => $text, 'created_at' => Clock::now()]);
                    LeadIngest::event($leadId, 'rule_notice', ['rule' => (string) $rule['name'], 'text' => $text]);
                    break;
                case 'outbound_webhook':
                    OutboundWebhooks::emit('rule.fired', $leadId, ['rule_id' => (string) $rule['id'], 'rule_name' => (string) $rule['name'], 'trigger' => $trigger], 'rule_evt:' . substr(sha1($base), 0, 40));
                    break;
                case 'telegram_alert':
                    $urgent = ($act['urgency'] ?? '') === 'urgent';
                    Queue::push('rule_alert', ['lead_id' => $leadId, 'set_id' => $set['set_id'], 'version_id' => $set['version_id'], 'rule_id' => (string) $rule['id'], 'name' => (string) $rule['name'], 'urgency' => $urgent ? 'urgent' : 'normal', 'text' => (string) ($act['text'] ?? '')],
                        $urgent ? Clock::now() : Alerts::nonUrgentRunAt(), 'rule_alert_evt:' . substr(sha1($base), 0, 40));
                    break;
                case 'create_task':
                    $title = mb_substr(Rules::render((string) ($act['title'] ?? ''), $vars), 0, 190);
                    $due = Clock::at(Clock::time() + (int) ($act['due_hours'] ?? 2) * 3600);
                    $prio = (int) ($act['priority'] ?? 2);
                    $id = Db::insert('tasks', ['title' => $title !== '' ? $title : 'Revisar lead', 'lead_id' => $leadId, 'due_at' => $due, 'priority' => $prio, 'created_at' => Clock::now()]);
                    LeadIngest::event($leadId, 'task_created', ['task_id' => $id, 'title' => $title, 'due_at' => $due, 'priority' => $prio, 'rule' => (string) $rule['name']]);
                    break;
            }
        }
        return $did;
    }

    // --- Tiempo: job cada 15 minutos ---------------------------------------------------------------------------------------------------

    /** Encola la pasada de tiempo de este tramo de 15 minutos (lo llama cron/process_jobs.php en cada pasada; la clave de dedupe la hace idempotente). Sin reglas de tiempo ni webhook de task.overdue no encola nada. */
    public static function enqueueTick(): ?int
    {
        if (!self::sets() && !OutboundWebhooks::wants('task.overdue')) {
            return null;   // nada que evaluar por tiempo: ni reglas publicadas ni webhook suscrito a task.overdue
        }
        return Queue::push('rules_time', [], Clock::now(), 'rules_time:' . intdiv(Clock::time(), self::TICK_SECONDS));
    }

    /** Handler del job rules_time. */
    public static function jobTick(array $payload = []): void
    {
        self::tick();
    }

    /**
     * «Sin primer contacto tras N horas» y «tarea vencida». Idempotente: cada (regla, lead, intento, tarea) actua una sola vez aunque corra mil veces.
     * Tambien emite el webhook task.overdue (una vez por tarea y vencimiento) si esta suscrito, haya o no reglas.
     * @return array{nfc:int,overdue:int,fired:int}
     */
    public static function tick(?int $now = null): array
    {
        $now ??= Clock::time();
        $out = ['nfc' => 0, 'overdue' => 0, 'fired' => 0];
        $sets = self::sets();
        $nfc = $sets ? self::rulesFor($sets, 'no_first_contact') : [];
        $od = $sets ? self::rulesFor($sets, 'task_overdue') : [];
        $hookOver = OutboundWebhooks::wants('task.overdue');
        try {
            Settings::set('cron.rules_time', ['at' => Clock::at($now)]);
        } catch (\Throwable) {
            // el latido no es critico
        }
        $floor = Clock::at($now - self::SCAN_DAYS * 86400);
        if ($nfc) {
            $minH = min(array_map(static fn($p) => (int) ($p[1]['trigger']['hours'] ?? 1), $nfc));
            $rows = Db::all("SELECT * FROM leads WHERE deleted_at IS NULL AND first_contact_at IS NULL AND stage = 'new' AND created_at <= ? AND created_at > ? ORDER BY id LIMIT " . self::SCAN_MAX, [Clock::at($now - $minH * 3600), $floor]);
            foreach ($rows as $row) {
                $lead = Lead::hydrate($row);
                $out['nfc']++;
                $hours = ($now - (int) Clock::parse((string) $lead['created_at'])) / 3600;
                $facts = self::facts($lead, $sets);
                foreach ($nfc as [$set, $rule]) {
                    if (RuleEngine::triggerMatches((array) $rule['trigger'], 'no_first_contact', ['hours' => $hours]) && RuleEngine::matchesEvent($rule, $facts) === 'match') {
                        $out['fired'] += self::apply($set, $rule, $lead, 'no_first_contact', 'nfc') ? 1 : 0;
                    }
                }
            }
        }
        if ($od || $hookOver) {
            $minO = $od ? min(array_map(static fn($p) => (int) ($p[1]['trigger']['hours'] ?? 0), $od)) : 0;
            $rows = Db::all(
                'SELECT t.id, t.lead_id, t.due_at, t.title FROM tasks t JOIN leads l ON l.id = t.lead_id WHERE t.deleted_at IS NULL AND t.done_at IS NULL AND t.due_at IS NOT NULL AND t.due_at <= ? AND t.due_at > ? AND l.deleted_at IS NULL ORDER BY t.due_at LIMIT ' . self::SCAN_MAX,
                [Clock::at($now - $minO * 3600), $floor]
            );
            $leads = [];
            foreach ($rows as $t) {
                $out['overdue']++;
                $leadId = (int) $t['lead_id'];
                $dueTs = (int) Clock::parse((string) $t['due_at']);
                $disc = 't' . (int) $t['id'] . '.' . $dueTs;
                if ($hookOver) {
                    OutboundWebhooks::emit('task.overdue', $leadId, ['task' => ['id' => (int) $t['id'], 'due_at' => gmdate('c', $dueTs)]], 'task.overdue:' . (int) $t['id'] . ':' . $dueTs);
                }
                if (!$od) {
                    continue;
                }
                $lead = $leads[$leadId] ??= Lead::find($leadId);
                if ($lead === null) {
                    continue;
                }
                $facts = self::facts($lead, $sets);
                foreach ($od as [$set, $rule]) {
                    if (RuleEngine::triggerMatches((array) $rule['trigger'], 'task_overdue', ['overdue_hours' => ($now - $dueTs) / 3600]) && RuleEngine::matchesEvent($rule, $facts) === 'match') {
                        $out['fired'] += self::apply($set, $rule, $lead, 'task_overdue', $disc) ? 1 : 0;
                    }
                }
            }
        }
        return $out;
    }

    // --- Simulacion (sin escribir nada) ------------------------------------------------------------------------------------------------

    /**
     * Datos que la simulacion necesita de una tanda de leads: eventos de etapa y de consentimiento y horas de retraso de la tarea mas vencida (todo de lectura).
     * @param list<array> $leads
     * @return array{stage:array<int,list<string>>,consent:array<int,list<string>>,overdue:array<int,float>}
     */
    public static function simPrefetch(array $leads, int $now): array
    {
        $ids = array_values(array_filter(array_map(static fn($l) => (int) ($l['id'] ?? 0), $leads)));
        $out = ['stage' => [], 'consent' => [], 'overdue' => []];
        if (!$ids) {
            return $out;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (Db::all("SELECT lead_id, type, payload FROM lead_events WHERE type IN ('stage_changed','consent_revoked') AND lead_id IN ($in) ORDER BY id", $ids) as $e) {
            $p = Db::decode($e['payload']);
            if ($e['type'] === 'stage_changed') {
                $out['stage'][(int) $e['lead_id']][] = (string) ($p['to'] ?? '');
            } else {
                foreach (array_filter(explode('+', (string) ($p['channel'] ?? ''))) as $c) {
                    $out['consent'][(int) $e['lead_id']][] = $c;
                }
            }
        }
        foreach (Db::all("SELECT lead_id, MIN(due_at) AS due FROM tasks WHERE deleted_at IS NULL AND done_at IS NULL AND due_at IS NOT NULL AND due_at <= ? AND lead_id IN ($in) GROUP BY lead_id", [Clock::at($now), ...$ids]) as $t) {
            $out['overdue'][(int) $t['lead_id']] = ($now - (int) Clock::parse((string) $t['due'])) / 3600;
        }
        return $out;
    }

    /**
     * Que haria el conjunto con este lead (misma forma que RuleSim::evalLead: no cambia puntuacion ni temperatura). Sin escribir nada.
     * Aproximacion declarada: usa el estado ACTUAL del lead y su historial (eventos de etapa y de consentimiento ya ocurridos, tareas vencidas hoy).
     * @param list<array> $rules reglas normalizadas del conjunto
     */
    public static function simLead(array $lead, array $facts, array $rules, int $now, array $pre): array
    {
        $id = (int) $lead['id'];
        $facts['score'] = (int) ($lead['score'] ?? 0);
        $facts['temperature'] = (string) ($lead['temperature'] ?? '');
        $hours = ($now - (int) Clock::parse((string) ($lead['created_at'] ?? ''))) / 3600;
        $mine = [];
        $fx = ['alerts' => 0, 'tasks' => 0, 'bells' => 0, 'hooks' => 0];
        foreach ($rules as $r) {
            if (empty($r['enabled']) || !isset($r['trigger'])) {
                continue;
            }
            $t = (array) $r['trigger'];
            $hit = match ($t['type'] ?? '') {
                'no_first_contact' => ($lead['first_contact_at'] ?? null) === null && ($lead['stage'] ?? '') === 'new' && RuleEngine::triggerMatches($t, 'no_first_contact', ['hours' => $hours]),
                'task_overdue'     => isset($pre['overdue'][$id]) && RuleEngine::triggerMatches($t, 'task_overdue', ['overdue_hours' => $pre['overdue'][$id]]),
                'stage_change'     => array_reduce($pre['stage'][$id] ?? [], static fn($c, $to) => $c || RuleEngine::triggerMatches($t, 'stage_change', ['to' => $to]), false),
                'consent_revoked'  => ($pre['consent'][$id] ?? []) !== [] && RuleEngine::triggerMatches($t, 'consent_revoked', ['channels' => $pre['consent'][$id]]),
                default            => false,
            };
            if (!$hit || RuleEngine::matchesEvent($r, $facts) !== 'match') {
                continue;
            }
            $mine[] = (string) $r['name'];
            foreach ((array) $r['then'] as $a) {
                $fx['alerts'] += ($a['type'] ?? '') === 'telegram_alert' ? 1 : 0;
                $fx['tasks'] += ($a['type'] ?? '') === 'create_task' ? 1 : 0;
                $fx['bells'] += ($a['type'] ?? '') === 'notify_bell' ? 1 : 0;
                $fx['hooks'] += ($a['type'] ?? '') === 'outbound_webhook' ? 1 : 0;
            }
        }
        $same = ['score' => (int) $lead['score'], 'temperature' => (string) $lead['temperature']];
        return ['before' => $same, 'after' => $same, 'mine' => $mine, 'changed' => false, 'matched' => $mine !== [], 'tags' => 0, 'review' => 0] + $fx;
    }
}
