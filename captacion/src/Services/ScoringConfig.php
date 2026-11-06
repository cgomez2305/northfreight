<?php
/**
 * Configuracion de la puntuacion (doc §6) desde el panel: validacion de pesos y umbrales, guardado con historial, restablecer a los valores del documento,
 * vista previa del efecto sobre los leads existentes y recalculo en lotes por la cola (job `rescore_batch`, 200 leads por pasada, evento `scored` por lead que cambia).
 * El recalculo NO encola alertas (enqueue=false): cambiar los pesos no debe disparar Telegram por cientos de leads.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;

final class ScoringConfig
{
    public const MAX_POINTS = 50;
    public const BATCH = 200;

    public const OWNER_POINTS = [
        'cdl_standard' => 'CDL estándar', 'english_yes' => 'Inglés: sí', 'english_basic' => 'Inglés: básico', 'fmcsa_authority_active' => 'Autoridad activa verificada en FMCSA',
        'fmcsa_insured' => 'Seguro vigente registrado en FMCSA', 'dispatched_equipment' => 'Equipo que NorthStar despacha', 'experience' => 'Experiencia mínima cumplida',
        'referral' => 'Referido', 'complete' => 'Formulario completo',
    ];
    public const DRIVER_POINTS = [
        'cdl_a_standard' => 'CDL-A estándar', 'english_yes' => 'Inglés: sí', 'english_basic' => 'Inglés: básico', 'hiring_state' => 'Vive en un estado de contratación',
        'endorsement' => 'Endoso Hazmat o Tanker', 'referral' => 'Referido', 'complete' => 'Formulario completo',
    ];
    /** grupo => [clave del tramo => [etiqueta, unidad, tope del umbral]] */
    public const TIERS = [
        'owner'  => ['authority_age' => ['Antigüedad de la autoridad', 'meses', 600], 'trucks' => ['Número de camiones', 'camiones', 999]],
        'driver' => ['experience' => ['Experiencia del driver', 'años', 60]],
    ];
    public const PROVISIONAL = ['dispatched_equipment' => 'Equipos que NorthStar despacha', 'hiring_states' => 'Estados de contratación', 'driver_min_years' => 'Mínimo de experiencia de drivers', 'scoring.driver.experience' => 'Tramos de experiencia de drivers'];
    private const GROUP_KEY = ['owner' => 'scoring.owner', 'driver' => 'scoring.driver', 'temp' => 'scoring.temperature'];

    /** Valores vigentes en la forma del formulario: ['owner' => [...], 'driver' => [...], 'temp' => [...]]. */
    public static function current(): array
    {
        $s = Scoring::settings();
        return ['owner' => $s['scoring.owner'], 'driver' => $s['scoring.driver'], 'temp' => $s['scoring.temperature']];
    }

    /**
     * Lee el formulario. $form conserva lo escrito (aunque sea invalido) para volver a pintarlo; $errors usa rutas 'owner.cdl_standard', 'owner.trucks.1.lt', 'temp'.
     * @return array{form:array,errors:array<string,string>}
     */
    public static function fromPost(array $post): array
    {
        $form = self::current();
        $errors = [];
        $int = static function (mixed $v, int $min, int $max, string $path) use (&$errors): int|string {
            $raw = is_string($v) ? trim($v) : '';
            if (preg_match('/^\d{1,4}$/', $raw) !== 1) {
                $errors[$path] = $raw === '' ? 'Escribe un número.' : 'Solo números enteros, sin signos ni decimales.';
                return $raw;
            }
            $n = (int) $raw;
            if ($n < $min || $n > $max) {
                $errors[$path] = 'Debe estar entre ' . $min . ' y ' . $max . '.';
            }
            return $n;
        };
        foreach (['owner' => self::OWNER_POINTS, 'driver' => self::DRIVER_POINTS] as $g => $fields) {
            $in = is_array($post[$g] ?? null) ? $post[$g] : [];
            foreach (array_keys($fields) as $k) {
                $form[$g][$k] = $int($in[$k] ?? null, 0, self::MAX_POINTS, "$g.$k");
            }
            foreach (self::TIERS[$g] as $tk => [, , $cap]) {
                $tin = is_array($in[$tk] ?? null) ? $in[$tk] : [];
                $tiers = [];
                for ($i = 0; $i < 3; $i++) {
                    $row = is_array($tin[$i] ?? null) ? $tin[$i] : [];
                    $lt = $i < 2 ? $int($row['lt'] ?? null, 1, $cap, "$g.$tk.$i.lt") : null;
                    $tiers[] = ['lt' => $lt, 'points' => $int($row['points'] ?? null, 0, self::MAX_POINTS, "$g.$tk.$i.points")];
                }
                if (is_int($tiers[0]['lt']) && is_int($tiers[1]['lt']) && $tiers[0]['lt'] >= $tiers[1]['lt'] && !isset($errors["$g.$tk.0.lt"], $errors["$g.$tk.1.lt"])) {
                    $errors["$g.$tk.1.lt"] = 'Debe ser mayor que el límite del primer tramo.';
                }
                $form[$g][$tk] = $tiers;
            }
        }
        $form['owner']['experience_min_years'] = $int(($post['owner'] ?? [])['experience_min_years'] ?? null, 0, 20, 'owner.experience_min_years');
        $t = is_array($post['temp'] ?? null) ? $post['temp'] : [];
        $form['temp']['hot_min'] = $int($t['hot_min'] ?? null, 1, 100, 'temp.hot_min');
        $form['temp']['warm_min'] = $int($t['warm_min'] ?? null, 1, 100, 'temp.warm_min');
        if (is_int($form['temp']['hot_min']) && is_int($form['temp']['warm_min']) && $form['temp']['hot_min'] <= $form['temp']['warm_min'] && !isset($errors['temp.hot_min'], $errors['temp.warm_min'])) {
            $errors['temp'] = 'El umbral de «caliente» debe ser mayor que el de «tibio».';
        }
        return ['form' => $form, 'errors' => $errors];
    }

    /** Valores de $form ya validados a la forma de settings (conserva complete_fields). @return array<string,array> clave de settings => valor */
    public static function toSettings(array $form): array
    {
        $cur = Scoring::settings();
        $out = [];
        foreach (self::GROUP_KEY as $g => $key) {
            $out[$key] = array_replace($cur[$key], $form[$g]);
        }
        return $out;
    }

    /** Guarda los valores ya validados y deja el historial. @return int numero de grupos que cambiaron */
    public static function save(array $settings, int $userId): int
    {
        $changed = 0;
        $cur = Scoring::settings();
        foreach ($settings as $key => $value) {
            if ($value === $cur[$key]) {
                continue;
            }
            Settings::set($key, $value);
            ActivityLog::settingsChange($userId, $key, $cur[$key], $value);
            $changed++;
        }
        return $changed;
    }

    /** Restablece a los valores del documento: 'owner' | 'driver' | 'temp' | 'all'. */
    public static function reset(string $group, int $userId): array
    {
        $groups = $group === 'all' ? array_keys(self::GROUP_KEY) : (isset(self::GROUP_KEY[$group]) ? [$group] : []);
        if (!$groups) {
            return ['ok' => false, 'msg' => 'Grupo no válido.'];
        }
        $set = [];
        foreach ($groups as $g) {
            $set[self::GROUP_KEY[$g]] = Scoring::DEFAULTS[self::GROUP_KEY[$g]];
        }
        $n = self::save($set, $userId);
        return ['ok' => true, 'msg' => $n > 0 ? 'Valores del documento restablecidos.' : 'Ya estaban los valores del documento.'];
    }

    // --- Ajustes provisionales -----------------------------------------------------------------------------------------

    public static function provisionalList(): array
    {
        $p = Settings::get('provisional', []);
        return is_array($p) ? array_values(array_filter($p, 'is_string')) : [];
    }

    /** @return array{ok:bool,errors:array<string,string>,values:array} */
    public static function validateProvisional(array $in): array
    {
        $err = [];
        $eq = is_array($in['dispatched_equipment'] ?? null) ? array_values(array_unique(array_filter($in['dispatched_equipment'], 'is_string'))) : [];
        $bad = array_diff($eq, array_keys(Catalog::EQUIPMENT));
        if ($bad) {
            $err['dispatched_equipment'] = 'Hay equipos que no están en la lista.';
        }
        $eq = array_values(array_intersect($eq, array_keys(Catalog::EQUIPMENT)));
        $statesRaw = is_string($in['hiring_states'] ?? null) ? $in['hiring_states'] : '';
        $states = [];
        foreach (preg_split('/[\s,;]+/', strtoupper(trim($statesRaw))) ?: [] as $st) {
            if ($st === '') {
                continue;
            }
            if (!isset(UsStates::NAMES[$st])) {
                $err['hiring_states'] = 'No reconozco «' . mb_substr($st, 0, 12) . '». Usa abreviaturas de 2 letras separadas por comas (FL, TX, GA).';
                break;
            }
            $states[$st] = $st;
        }
        $years = is_string($in['driver_min_years'] ?? null) ? trim($in['driver_min_years']) : '';
        if (preg_match('/^\d{1,2}$/', $years) !== 1 || (int) $years > 10) {
            $err['driver_min_years'] = 'Escribe un número de años entre 0 y 10.';
        }
        $confirmed = is_array($in['confirmed'] ?? null) ? array_values(array_intersect(array_filter($in['confirmed'], 'is_string'), array_keys(self::PROVISIONAL))) : [];
        return ['ok' => !$err, 'errors' => $err, 'values' => [
            'dispatched_equipment' => $eq, 'hiring_states' => array_values($states), 'driver_min_years' => ctype_digit($years) ? (int) $years : 1,
            'provisional' => array_values(array_diff(array_keys(self::PROVISIONAL), $confirmed)),
        ]];
    }

    public static function saveProvisional(array $values, int $userId): int
    {
        $cur = Scoring::settings();
        $curList = self::provisionalList();
        $n = 0;
        foreach (['dispatched_equipment', 'hiring_states', 'driver_min_years'] as $k) {
            if ($values[$k] !== $cur[$k]) {
                Settings::set($k, $values[$k]);
                ActivityLog::settingsChange($userId, $k, [$k => $cur[$k]], [$k => $values[$k]]);
                $n++;
            }
        }
        if ($values['provisional'] !== $curList) {
            Settings::set('provisional', $values['provisional']);
            ActivityLog::settingsChange($userId, 'provisional', ['pendientes' => $curList], ['pendientes' => $values['provisional']]);
            $n++;
        }
        return $n;
    }

    /** Ultimos cambios de ajustes provisionales (del activity_log). @return list<array{at:string,user:?string,group:string,diff:array}> */
    public static function history(int $limit = 12): array
    {
        $keys = ['dispatched_equipment', 'hiring_states', 'driver_min_years', 'provisional', 'scoring.driver', 'scoring.temperature', 'scoring.owner'];
        $in = implode(',', array_fill(0, count($keys), '?'));
        $rows = Db::all("SELECT a.created_at, a.entity_id, a.changes, u.name FROM activity_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.action = 'settings_change' AND a.entity_id IN ($in) ORDER BY a.id DESC LIMIT " . (int) $limit, $keys);
        return array_map(static fn($r) => ['at' => (string) $r['created_at'], 'user' => $r['name'], 'group' => (string) $r['entity_id'], 'diff' => (Db::decode($r['changes'])['diff'] ?? [])], $rows);
    }

    // --- Vista previa y recalculo --------------------------------------------------------------------------------------

    /**
     * Efecto de $settings (forma de Scoring::settings()) sobre los leads existentes, sin escribir nada.
     * @return array{total:int,before:array<string,int>,after:array<string,int>,changed:int,to_hot:int,from_hot:int}
     */
    public static function preview(array $settings): array
    {
        $before = ['hot' => 0, 'warm' => 0, 'cold' => 0, 'disqualified' => 0];
        $after = $before;
        $changed = $toHot = $fromHot = 0;
        $total = 0;
        $last = 0;
        while (true) {
            $rows = Db::all('SELECT * FROM leads WHERE deleted_at IS NULL AND id > ? ORDER BY id LIMIT 500', [$last]);
            if (!$rows) {
                break;
            }
            foreach ($rows as $r) {
                $last = (int) $r['id'];
                $lead = Lead::hydrate($r);
                $new = RuleRunner::score($lead, $settings);   // base con los pesos del formulario + reglas publicadas (sin reglas, igual que antes)
                $total++;
                $before[$lead['temperature']] = ($before[$lead['temperature']] ?? 0) + 1;
                $after[$new['temperature']]++;
                if ($new['score'] !== (int) $lead['score'] || $new['temperature'] !== $lead['temperature']) {
                    $changed++;
                }
                $toHot += $new['temperature'] === 'hot' && $lead['temperature'] !== 'hot' ? 1 : 0;
                $fromHot += $lead['temperature'] === 'hot' && $new['temperature'] !== 'hot' ? 1 : 0;
            }
        }
        return ['total' => $total, 'before' => $before, 'after' => $after, 'changed' => $changed, 'to_hot' => $toHot, 'from_hot' => $fromHot];
    }

    /** @return array<string,int> */
    private static function tempCounts(): array
    {
        $c = ['hot' => 0, 'warm' => 0, 'cold' => 0, 'disqualified' => 0];
        foreach (Db::all('SELECT temperature, COUNT(*) AS n FROM leads WHERE deleted_at IS NULL GROUP BY temperature') as $r) {
            $c[$r['temperature']] = (int) $r['n'];
        }
        return $c;
    }

    public static function state(): ?array
    {
        $s = Settings::get('scoring.recalc');
        return is_array($s) && isset($s['run']) ? $s : null;
    }

    public static function running(): bool
    {
        $s = self::state();
        return $s !== null && ($s['status'] ?? '') === 'running' && Clock::time() - (int) Clock::parse((string) ($s['started_at'] ?? '')) < 1800;
    }

    /** Pone en marcha el recalculo por la cola. @return array{ok:bool,msg:string} */
    public static function startRecalc(int $userId): array
    {
        if (self::running()) {
            return ['ok' => false, 'msg' => 'Ya hay un recálculo en marcha. Espera a que termine.'];
        }
        $run = bin2hex(random_bytes(6));
        $before = self::tempCounts();
        Settings::set('scoring.recalc', ['run' => $run, 'status' => 'running', 'total' => array_sum($before), 'processed' => 0, 'changed' => 0, 'before' => $before, 'after' => null, 'started_at' => Clock::now(), 'finished_at' => null, 'by' => $userId]);
        Queue::push('rescore_batch', ['run' => $run, 'after_id' => 0], Clock::now(), 'rescore:' . $run . ':0');
        ActivityLog::record('scoring_recalc', $userId, 'settings', 'scoring', ['run' => $run, 'total' => array_sum($before)]);
        return ['ok' => true, 'msg' => 'Recálculo en marcha: corre por la cola, ' . self::BATCH . ' leads por pasada (cada minuto).'];
    }

    /** Handler del job rescore_batch. */
    public static function runBatch(array $payload, array $job = []): void
    {
        $st = self::state();
        $run = (string) ($payload['run'] ?? '');
        if ($st === null || $st['run'] !== $run || ($st['status'] ?? '') !== 'running') {
            return;   // un recalculo viejo o ya terminado
        }
        $after = max(0, (int) ($payload['after_id'] ?? 0));
        $ids = array_map('intval', array_column(Db::all('SELECT id FROM leads WHERE deleted_at IS NULL AND id > ? ORDER BY id LIMIT ' . self::BATCH, [$after]), 'id'));
        $changed = 0;
        foreach ($ids as $id) {
            $r = LeadIngest::rescore($id, 'config_changed', false);
            if ($r !== null && ($r['score'] !== $r['previous_score'] || $r['temperature'] !== $r['previous_temperature'])) {
                $changed++;
            }
        }
        $st['processed'] = (int) $st['processed'] + count($ids);
        $st['changed'] = (int) $st['changed'] + $changed;
        if (count($ids) < self::BATCH) {
            $st['status'] = 'done';
            $st['finished_at'] = Clock::now();
            $st['after'] = self::tempCounts();
        } else {
            $last = (int) end($ids);
            Queue::push('rescore_batch', ['run' => $run, 'after_id' => $last], Clock::now(), 'rescore:' . $run . ':' . $last);
        }
        Settings::set('scoring.recalc', $st);
    }
}
