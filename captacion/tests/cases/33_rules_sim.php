<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Services\JobHandlers;
use Captacion\Services\Queue;
use Captacion\Services\Rules;
use Captacion\Services\RuleSets;
use Captacion\Services\RuleSim;
use Captacion\Services\Scoring;
use Captacion\Services\Settings;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/**
 * Q5b-D: vista previa de impacto y simulación. Cuenta lo que cambiaría contra un cálculo independiente, muestras con antes y después, y sobre todo NO ESCRIBE NADA del negocio
 * (leads, eventos, tareas, consentimientos, mensajes, registro de aplicaciones) ni encola alertas ni envía nada. Simulación inline y por la cola, CSV con protección contra fórmulas y límites.
 */
return static function (array $ctx): void {
    T::reset(true);
    $admin = Panel::user('admin');
    $uid = (int) $admin['id'];
    foreach (['TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID', 'FMCSA_WEBKEY', 'TELEGRAM_API_BASE'] as $k) {
        Env::set($k, null);
    }
    Db::pdo()->exec("DELETE FROM settings WHERE `key` IN ('alerts','rules.enabled','rules.alert_cap')");
    Settings::clear();
    RuleSets::clearCache();
    $cond = static fn(string $f, string $op, mixed $v = null): array => ['field' => $f, 'op' => $op, 'value' => $v];
    $blk = static fn(string $mode, array ...$items): array => ['mode' => $mode, 'items' => $items];
    $R = static fn(string $id, string $name, array $when, array $then, array $o = []): array => $o + ['id' => $id, 'name' => $name, 'enabled' => true, 'stop' => false, 'example' => false, 'when' => $when, 'except' => null, 'then' => $then];
    $now = Clock::time();

    // 640 leads sintéticos y repartidos
    mt_srand(77);
    $states = ['TX', 'FL', 'GA', 'TN', 'CA', '=1'];
    $rows = [];
    for ($i = 1; $i <= 640; $i++) {
        $rows[] = [
            'profile' => ['owner_operator', 'owner_operator', 'small_carrier', 'company_driver'][$i % 4], 'first_name' => 'Sim' . $i, 'last_name' => 'Lead', 'phone_e164' => '+1404555' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'email' => "sim$i@example.com", 'state' => $states[$i % 6], 'language' => 'en', 'cdl_type' => $i % 31 === 0 ? 'non_domiciled' : 'standard', 'english_level' => 'yes', 'years_experience' => $i % 9,
            'equipment_types' => json_encode($i % 3 === 0 ? ['reefer'] : ['dry-van']), 'truck_count' => $i % 8, 'source' => $i % 5 === 0 ? 'indeed' : 'web_careers', 'authority_status' => 'active', 'pipeline' => 'A', 'stage' => 'new', 'attempt' => 1,
            'flags' => '[]', 'created_at' => Clock::at($now - ($i % 100) * 86400 - 600), 'updated_at' => Clock::now(),
        ];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        Db::run('INSERT INTO leads (' . implode(',', array_map(static fn($c) => "`$c`", array_keys($chunk[0]))) . ') VALUES ' . implode(',', array_fill(0, count($chunk), '(' . implode(',', array_fill(0, count($chunk[0]), '?')) . ')')), array_merge(...array_map('array_values', $chunk)));
    }
    // score y temperatura guardados coherentes con la puntuación del documento
    foreach (Db::all('SELECT * FROM leads') as $l) {
        $lead = \Captacion\Models\Lead::hydrate($l);
        $sc = Scoring::score($lead);
        Db::update('leads', (int) $l['id'], ['score' => $sc['score'], 'temperature' => $sc['temperature'], 'score_breakdown' => $sc['breakdown']]);
    }
    for ($i = 1; $i <= 60; $i++) {
        Fx::consent($i, $i % 2 ? ['call', 'sms', 'email'] : ['call']);
    }
    $total = (int) Db::value('SELECT COUNT(*) FROM leads');
    T::eq($total, 640, 'datos: 640 leads sintéticos');

    $s = RuleSets::create('scoring', 'Simulación', '', $uid);
    $sid = (int) $s['id'];
    $draftRules = Rules::normalize([$R('tx', 'Texas +20', $blk('all', $cond('state', 'is', 'TX')), [['type' => 'add_points', 'points' => 20]]),
        $R('al', 'Aviso FL', $blk('all', $cond('state', 'is', 'FL')), [['type' => 'min_temperature', 'level' => 'hot']])], 'scoring')['rules'];
    $target = ['set_id' => $sid, 'name' => 'Simulación', 'kind' => 'scoring', 'rules' => $draftRules];

    // Cálculo independiente: Texas +20 (tope 100) y Florida a caliente como mínimo
    $exp = ['changed' => 0, 'to_hot' => 0];
    foreach (Db::all('SELECT * FROM leads ORDER BY id DESC LIMIT 640') as $l) {
        $lead = \Captacion\Models\Lead::hydrate($l);
        $b = Scoring::score($lead);
        if ($b['temperature'] === 'disqualified') {
            continue;
        }
        $after = $b['score'];
        $temp = $b['temperature'];
        if ($l['state'] === 'TX') {
            $after = min(100, $after + 20);
            $temp = $after >= 70 ? 'hot' : ($after >= 40 ? 'warm' : 'cold');
        }
        if ($l['state'] === 'FL') {
            $temp = 'hot';
        }
        $exp['changed'] += ($after !== $b['score'] || $temp !== $b['temperature']) ? 1 : 0;
        $exp['to_hot'] += ($temp === 'hot' && $b['temperature'] !== 'hot') ? 1 : 0;
    }

    // Instantánea del negocio
    $snap = static function (): array {
        $o = [];
        foreach (['leads', 'lead_events', 'tasks', 'consents', 'messages', 'rule_applications', 'rule_versions', 'sequence_enrollments', 'appointments'] as $t) {
            $row = Db::pdo()->query("CHECKSUM TABLE `$t`")->fetch(PDO::FETCH_NUM);
            $o[$t] = $row[1];
        }
        $o['jobs_alert'] = (int) Db::value("SELECT COUNT(*) FROM jobs WHERE type IN ('telegram_alert','rule_alert','email_notify','telegram_alert_followup','sequence_step')");
        return $o;
    };
    $s0 = $snap();
    $pv = RuleSim::preview($target, RuleSim::scope(['mode' => 'last', 'n' => 640])['scope']);
    T::eq([$pv['summary']['scanned'], $pv['summary']['changed'], $pv['summary']['to_hot']], [640, $exp['changed'], $exp['to_hot']], 'vista previa: leads revisados, cambiados y que pasan a caliente coinciden con un cálculo independiente');
    T::ok($pv['summary']['matched'] > 0 && array_sum($pv['summary']['before']) === 640 && array_sum($pv['summary']['after']) === 640, '  y la distribución antes y después suma los 640');
    T::ok(count($pv['samples']) === 10 && $pv['samples'][0]['before'] !== $pv['samples'][0]['after'] && !empty($pv['samples'][0]['rules']) && $pv['samples'][0]['code'] !== '', '  hasta 10 muestras con antes, después y la regla que actuó');
    T::ok(!$pv['partial'] && $pv['seconds'] < 3, '  completa y rápida (' . $pv['seconds'] . ' s)');
    T::eq($snap(), $s0, 'LA VISTA PREVIA NO ESCRIBE NADA: leads, eventos, tareas, consentimientos, mensajes, aplicaciones, versiones ni alertas encoladas quedan idénticos');
    $pvDays = RuleSim::preview($target, ['mode' => 'days', 'days' => 7]);
    T::ok($pvDays['summary']['scanned'] > 0 && $pvDays['summary']['scanned'] < 640, 'vista previa: por días revisa solo los leads de ese periodo (' . $pvDays['summary']['scanned'] . ')');
    $pvSmall = RuleSim::preview($target, RuleSim::scope(['mode' => 'last', 'n' => 100])['scope']);
    T::eq($pvSmall['summary']['scanned'], 100, '  y «últimos 100» revisa exactamente 100');
    $slow = RuleSim::preview($target, ['mode' => 'last', 'n' => 640], 0.0);
    T::ok($slow['partial'] && $slow['summary']['scanned'] < 640 && $slow['summary']['scanned'] > 0, 'vista previa: con el tope de tiempo agotado devuelve lo calculado y dice que es parcial');

    // «Antes» usa lo publicado: un borrador igual al publicado no cambia nada
    RuleSets::saveDraft($sid, $draftRules, null, $uid);
    RuleSets::publish($sid, $uid);
    $same = RuleSim::preview($target, ['mode' => 'last', 'n' => 640]);
    T::eq([$same['summary']['changed'], $same['summary']['before'], $same['summary']['after']], [0, $same['summary']['before'], $same['summary']['before']], 'vista previa: el «antes» incluye lo ya publicado (un borrador igual a la versión publicada no cambia nada)');
    $tweak = $draftRules;
    $tweak[0]['then'][0]['points'] = 25;
    $t2 = RuleSim::preview(['rules' => $tweak] + $target, ['mode' => 'last', 'n' => 640]);
    T::ok($t2['summary']['changed'] > 0, '  y +5 puntos más sobre Texas cambia a algunos');
    RuleSets::unpublish($sid, $uid);

    // Alcance: validación
    T::ok(!RuleSim::scope(['mode' => 'range', 'from' => '2026-01-05', 'to' => '2026-01-01'])['ok'] && !RuleSim::scope(['mode' => 'range', 'from' => '2025-01-01', 'to' => '2026-06-01'])['ok'] && !RuleSim::scope(['mode' => 'range', 'from' => 'ayer', 'to' => 'hoy'])['ok'], 'alcance: rango invertido, de más de 400 días o con fechas inválidas se rechaza');
    T::eq([RuleSim::scope(['n' => 99999], true)['scope']['n'], RuleSim::scope(['n' => 1], true)['scope']['n'], RuleSim::scope(['n' => 99999])['scope']['n']], [20000, 10, 1500], 'alcance: el número de leads se acota (simulación 10 a 20.000, vista previa hasta 1.500)');
    T::eq(RuleSim::scope(['mode' => 'days', 'days' => 17])['scope']['days'], 30, '  y los días solo admiten los valores de la lista');

    // Simulación inline (≤ 400)
    $dv = RuleSets::saveDraft($sid, $draftRules, 'Para simular', $uid);
    $vid = (int) RuleSets::draft($sid)['id'];
    $s0 = $snap();
    $st = RuleSim::start($sid, $vid, $draftRules, RuleSim::scope(['mode' => 'last', 'n' => 300], true)['scope'], $uid);
    $sim = RuleSim::status($st['id']);
    T::ok($st['ok'] && $sim['status'] === 'done' && $sim['total'] === 300 && $sim['processed'] === 300 && $sim['percent'] === 100 && $sim['version_id'] === $vid, 'simulación pequeña: corre en el momento, termina y queda asociada a la versión borrador');
    $pvSame = RuleSim::preview($target, ['mode' => 'last', 'n' => 300]);
    T::eq([$sim['summary']['changed'], $sim['summary']['to_hot'], $sim['summary']['scanned']], [$pvSame['summary']['changed'], $pvSame['summary']['to_hot'], $pvSame['summary']['scanned']], '  y da el mismo resultado que la vista previa del mismo alcance');
    T::ok(count($sim['samples']) === 10 && $sim['samples'][0]['name'] !== '', '  con muestras');
    $after = $snap();
    unset($s0['jobs_alert'], $after['jobs_alert']);
    T::eq($after, $s0, 'LA SIMULACIÓN NO ESCRIBE NADA del negocio (leads, eventos, tareas, consentimientos, mensajes, aplicaciones, versiones)');
    T::eq((int) Db::value("SELECT COUNT(*) FROM jobs WHERE type IN ('telegram_alert','rule_alert','email_notify','telegram_alert_followup')"), 0, '  ni encola una sola alerta o aviso');
    $firstScore = (int) Db::value('SELECT score FROM leads ORDER BY id LIMIT 1');
    T::eq($firstScore, (int) Scoring::score(\Captacion\Models\Lead::hydrate(Db::one('SELECT * FROM leads ORDER BY id LIMIT 1')))['score'], '  y las puntuaciones guardadas siguen siendo las del documento');
    // Simulación grande por la cola
    $st = RuleSim::start($sid, $vid, $draftRules, RuleSim::scope(['mode' => 'last', 'n' => 640], true)['scope'], $uid);
    $run = RuleSim::status($st['id']);
    T::ok($st['ok'] && $run['status'] === 'running' && $run['processed'] === 0 && (int) Db::value("SELECT COUNT(*) FROM jobs WHERE type = 'rule_simulate' AND status = 'pending'") === 1, 'simulación grande: queda «en curso» y se encola el job rule_simulate (por lotes)');
    T::ok(!RuleSim::start($sid, $vid, $draftRules, ['mode' => 'last', 'n' => 500], $uid)['ok'], '  y no se puede lanzar otra mientras corre');
    Fx::runJobs(5);
    $done = RuleSim::status($st['id']);
    T::ok($done['status'] === 'done' && $done['processed'] === 640 && $done['summary']['scanned'] === 640 && $done['summary']['changed'] === $exp['changed'], 'simulación grande: la cola la termina por lotes con el mismo resultado exacto que la vista previa y el cálculo independiente');
    $s1 = $snap();
    unset($s1['jobs_alert']);
    T::eq($s1, $after, '  sin tocar el negocio tampoco por la cola');
    // Avance por lotes: una pasada corta procesa un lote y la siguiente sigue donde se quedó
    $st2 = RuleSim::start($sid, $vid, $draftRules, RuleSim::scope(['mode' => 'last', 'n' => 640], true)['scope'], $uid);
    Db::pdo()->exec("DELETE FROM jobs WHERE type = 'rule_simulate'");
    $seen = [];
    $guard = 0;
    while (($x = RuleSim::step($st2['id'], 0.0))['status'] === 'running' && $guard++ < 6) {
        $seen[] = $x['processed'];
    }
    $seen[] = $x['processed'];
    T::eq([$seen, $x['status'], $x['summary']['changed']], [[250, 500, 640], 'done', $exp['changed']], 'simulación: cada pasada procesa un lote de ' . RuleSim::BATCH . ' y la siguiente sigue donde se quedó (250, 500, 640)');
    $jobs0 = (int) Db::value("SELECT COUNT(*) FROM jobs WHERE type = 'rule_simulate'");
    RuleSim::jobStep(['sim' => $st2['id']]);
    T::eq((int) Db::value("SELECT COUNT(*) FROM jobs WHERE type = 'rule_simulate'"), $jobs0, '  y una simulación terminada no vuelve a encolarse');
    // CSV: una simulación donde coinciden TODOS los leads (incluido el estado «=1» sembrado a propósito)
    $allRules = Rules::normalize([$R('todos', 'Todos tibios', $blk('all', $cond('profile', 'exists')), [['type' => 'min_temperature', 'level' => 'warm']])], 'scoring')['rules'];
    $st = RuleSim::start($sid, $vid, $allRules, RuleSim::scope(['mode' => 'last', 'n' => 640], true)['scope'], $uid);
    Db::pdo()->exec("DELETE FROM jobs WHERE type = 'rule_simulate'");
    while (RuleSim::step($st['id'], 40.0)['status'] === 'running') {
    }
    $csv = RuleSim::csv($st['id']);
    $lines = explode("\r\n", rtrim($csv, "\r\n"));
    T::ok(str_starts_with($csv, "\xEF\xBB\xBFC") && count($lines) === 1 + (int) Db::value('SELECT COUNT(*) FROM rule_simulation_rows WHERE sim_id = ?', [$st['id']]) && count($lines) > 100, 'CSV: UTF-8 con BOM, cabecera y una fila por lead afectado (' . (count($lines) - 1) . ')');
    T::ok(str_contains($csv, ",'=1,") && !preg_match('/,=1,/', $csv), 'CSV: protección contra fórmulas (una celda que empieza por = se prefija con apóstrofo)');
    T::ok(!str_contains($csv, 'sim1@example.com') && !str_contains($csv, '+1404') && !str_contains($csv, 'Sim1,') && str_contains($csv, 'LD-'), 'CSV: sin nombre, teléfono ni correo; solo el código corto del lead');
    T::eq(RuleSim::csv(999999), null, 'CSV: una simulación inexistente o sin terminar no entrega nada');
    // Fallos
    Db::pdo()->exec("UPDATE rule_simulations SET status = 'failed' WHERE id = {$st['id']}");
    T::eq(RuleSim::csv($st['id']), null, '  ni una fallida');
    Db::pdo()->exec("UPDATE rule_simulations SET status = 'done' WHERE id = {$st['id']}");
    T::ok(RuleSim::start($sid, null, $draftRules, RuleSim::scope(['mode' => 'range', 'from' => '2020-01-01', 'to' => '2020-01-02'], true)['scope'], $uid)['ok'] === false, 'simulación: un alcance sin leads se rechaza con mensaje');
    T::eq(RuleSim::latest($sid)['id'], $st['id'], '  y latest() devuelve la más reciente');
    $big = [];
    for ($i = 0; $i < 70; $i++) {
        $big[] = $R('r' . $i, 'R' . $i, $blk('all', $cond('profile', 'exists')), [['type' => 'tag', 'tag' => 't' . $i]]);
    }
    T::ok(count(Rules::normalize($big, 'routing_tasks')['rules']) === 40 && !Rules::normalize($big, 'routing_tasks')['ok'], 'límite: 70 reglas se rechazan (tope de 40 por conjunto)');
    Db::pdo()->exec('DELETE FROM rule_simulation_rows');
    Db::pdo()->exec('DELETE FROM rule_simulations');
    Db::pdo()->exec('DELETE FROM rule_versions');
    Db::pdo()->exec('DELETE FROM rule_sets');
    RuleSets::clearCache();
};
