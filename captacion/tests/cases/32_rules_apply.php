<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Models\Lead;
use Captacion\Services\ActivityLog;
use Captacion\Services\Alerts;
use Captacion\Services\Catalog;
use Captacion\Services\JobHandlers;
use Captacion\Services\LeadIngest;
use Captacion\Services\Queue;
use Captacion\Services\RuleEffects;
use Captacion\Services\Rules;
use Captacion\Services\RuleSets;
use Captacion\Services\RuleSim;
use Captacion\Services\RuleTemplates;
use Captacion\Services\Scoring;
use Captacion\Services\ScoringConfig;
use Captacion\Services\Settings;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/**
 * Q5b-B/D/E: las reglas en el motor real (puntos, temperatura mínima, alerta, tarea, etiqueta, cola de revisión) con reloj simulado, versiones (borrador, publicar atómico, historial,
 * diferencia, revertir, retirar), vista previa y simulación SIN escritura, CSV, recálculo por la cola, plantillas y auditoría.
 */
return static function (array $ctx): void {
    $work = $ctx['work'];
    T::reset(true);
    $admin = Panel::user('admin');
    $uid = (int) $admin['id'];
    foreach (['TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID', 'FMCSA_WEBKEY', 'TELEGRAM_API_BASE'] as $k) {
        Env::set($k, null);
    }
    Db::pdo()->exec("DELETE FROM settings WHERE `key` IN ('alerts','rules.enabled','rules.alert_cap','scoring.recalc')");
    Settings::clear();
    $count = static fn(string $t, string $where = '1=1', array $p = []) => (int) Db::value("SELECT COUNT(*) FROM $t WHERE $where", $p);
    $cond = static fn(string $f, string $op, mixed $v = null): array => ['field' => $f, 'op' => $op, 'value' => $v];
    $blk = static fn(string $mode, array ...$items): array => ['mode' => $mode, 'items' => $items];
    $R = static fn(string $id, string $name, array $when, array $then, array $o = []): array => $o + ['id' => $id, 'name' => $name, 'enabled' => true, 'stop' => false, 'example' => false, 'when' => $when, 'except' => null, 'then' => $then];
    $mkSet = static function (string $kind, string $name, array $rules) use ($uid): int {
        $s = RuleSets::create($kind, $name, '', $uid);
        $v = Rules::normalize($rules, $kind);
        if (!$v['ok']) {
            throw new RuntimeException('reglas de prueba no validan: ' . json_encode($v['errors']));
        }
        RuleSets::saveDraft($s['id'], $v['rules'], null, $uid);
        return (int) $s['id'];
    };
    $payload = static fn(string $n, array $o = []): array => $o + ['source' => 'web_careers', 'profile' => 'owner_operator', 'name' => "Ana $n", 'phone' => '+1 305 555 ' . str_pad($n, 4, '0', STR_PAD_LEFT), 'email' => "ana$n@example.com",
        'state' => 'TX', 'cdl_type' => 'standard', 'english_level' => 'yes', 'years_experience' => 4, 'equipment_types' => ['dry-van'], 'truck_count' => 4, 'language' => 'en'];

    // ================================================================== Versiones
    $A = [$R('r1', 'Texas suma', $blk('all', $cond('state', 'is', 'TX')), [['type' => 'add_points', 'points' => 10]]),
          $R('r2', 'Flotas', $blk('all', $cond('trucks', 'gte', 5)), [['type' => 'add_points', 'points' => 5]])];
    $s = RuleSets::create('scoring', 'Puntos de prueba', 'Ajustes de prueba', $uid);
    T::ok($s['ok'] && $count('rule_sets') === 1, 'versiones: se crea un conjunto');
    $sid = (int) $s['id'];
    T::ok(!RuleSets::create('scoring', '', '', $uid)['ok'] && !RuleSets::create('scoring', 'A<b>', '', $uid)['ok'] && !RuleSets::create('otro', 'X', '', $uid)['ok'], '  nombre vacío, con < > o tipo desconocido se rechazan');
    T::eq(RuleSets::all()[0]['status'], 'empty', '  sin versiones el estado es «vacío»');
    $vA = Rules::normalize($A, 'scoring');
    $d1 = RuleSets::saveDraft($sid, $vA['rules'], 'Primer borrador', $uid);
    T::ok($d1['ok'] && $d1['version'] === 1 && $count('rule_versions') === 1 && RuleSets::all()[0]['status'] === 'draft', 'borrador: «Guardar borrador» crea la versión 1 como borrador');
    $A2 = $A;
    $A2[0]['then'][0]['points'] = 12;
    $d1b = RuleSets::saveDraft($sid, Rules::normalize($A2, 'scoring')['rules'], 'Segundo guardado', $uid);
    T::ok($d1b['version'] === 1 && $count('rule_versions') === 1 && RuleSets::draft($sid)['rules'][0]['then'][0]['points'] === 12 && RuleSets::draft($sid)['note'] === 'Segundo guardado', 'borrador: guardar otra vez ACTUALIZA el mismo borrador (un solo borrador por conjunto)');
    T::eq(RuleSets::engineSets(), [], '  el borrador NO se aplica al motor');
    T::ok(!RuleSets::saveDraft($sid, [], '<x>', $uid)['ok'], '  una nota con < > se rechaza');
    $p1 = RuleSets::publish($sid, $uid, 'Salida 1');
    T::ok($p1['ok'] && $p1['version'] === 1 && RuleSets::draft($sid) === null && RuleSets::published($sid)['version'] === 1 && RuleSets::all()[0]['status'] === 'published', 'publicar: el borrador pasa a publicado y ya no hay borrador');
    T::eq(array_column(RuleSets::engineSets(), 'version'), [1], '  y el motor ve la versión 1');
    T::ok(!RuleSets::publish($sid, $uid)['ok'], '  publicar sin borrador da error claro');
    $B = [$A2[0], $R('r3', 'Nueva', $blk('all', $cond('source', 'is', 'meta')), [['type' => 'add_points', 'points' => 3]])];
    RuleSets::saveDraft($sid, Rules::normalize($B, 'scoring')['rules'], null, $uid);
    T::eq([RuleSets::draft($sid)['version'], RuleSets::all()[0]['status']], [2, 'published_draft'], 'versiones: tras editar una publicada el borrador es la versión 2 (estado «publicada con borrador»)');
    $p2 = RuleSets::publish($sid, $uid);
    T::ok($p2['ok'] && $count('rule_versions', "set_id = ? AND status = 'published'", [$sid]) === 1 && $count('rule_versions', "set_id = ? AND status = 'archived'", [$sid]) === 1 && RuleSets::published($sid)['version'] === 2, 'publicar: archiva la anterior y deja UNA sola publicada');
    T::throws(static fn() => Db::pdo()->exec("INSERT INTO rule_versions (set_id, version, status, rules, pub_slot) VALUES ($sid, 77, 'published', '[]', 1)"), 'esquema: la base rechaza una segunda versión publicada del mismo conjunto');
    T::throws(static function () use ($sid): void {
        Db::pdo()->exec("INSERT INTO rule_versions (set_id, version, status, rules, draft_slot) VALUES ($sid, 78, 'draft', '[]', 1)");
        Db::pdo()->exec("INSERT INTO rule_versions (set_id, version, status, rules, draft_slot) VALUES ($sid, 79, 'draft', '[]', 1)");
    }, 'esquema: ni un segundo borrador');
    Db::pdo()->exec("DELETE FROM rule_versions WHERE version IN (77, 78, 79)");
    // Atomicidad: si algo falla en mitad de publicar, nada cambia
    RuleSets::saveDraft($sid, Rules::normalize($A, 'scoring')['rules'], null, $uid);
    Db::pdo()->exec("UPDATE rule_versions SET version = 99 WHERE set_id = $sid AND status = 'draft'");
    Db::pdo()->exec("CREATE TRIGGER rv_boom BEFORE UPDATE ON rule_versions FOR EACH ROW BEGIN IF NEW.status = 'published' AND NEW.version = 99 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'boom'; END IF; END");
    $threw = false;
    try {
        RuleSets::publish($sid, $uid);
    } catch (Throwable) {
        $threw = true;
    }
    Db::pdo()->exec('DROP TRIGGER rv_boom');
    RuleSets::clearCache();
    T::ok($threw && RuleSets::published($sid)['version'] === 2 && $count('rule_versions', "set_id = ? AND status = 'published'", [$sid]) === 1 && RuleSets::draft($sid)['version'] === 99, 'publicar es ATÓMICO: si falla a mitad, la publicada anterior sigue activa y el borrador intacto');
    Db::pdo()->exec("UPDATE rule_versions SET version = 3 WHERE version = 99 AND set_id = $sid");
    $h = RuleSets::history($sid);
    T::eq(array_column($h, 'version'), [3, 2, 1], 'historial: versiones de la más nueva a la más vieja');
    T::eq(array_column($h, 'status'), ['draft', 'published', 'archived'], '  con su estado');
    T::ok($h[2]['note'] === 'Salida 1' && $h[2]['by'] === $admin['email'] || $h[2]['by'] === 'Admin Prueba', '  y su nota y su autor');
    // Diferencia legible
    $diff = RuleSets::diff(RuleSets::published($sid)['rules'], Rules::normalize([$A[1], $A2[0], $R('r9', 'Otra', $blk('all', $cond('language', 'is', 'es')), [['type' => 'add_points', 'points' => 2]])], 'scoring')['rules']);
    $types = [];
    foreach ($diff as $d) {
        $types[$d['id']] = $d['type'];
    }
    T::eq($types, ['r2' => 'added', 'r1' => 'moved', 'r9' => 'added', 'r3' => 'removed'], 'diferencia: reglas añadidas, movida y quitada (por id de regla)');
    $changed = RuleSets::diff($vA['rules'], Rules::normalize($A2, 'scoring')['rules']);
    T::ok(count($changed) === 1 && $changed[0]['type'] === 'changed' && $changed[0]['changes'][0]['field'] === 'ENTONCES' && str_contains($changed[0]['changes'][0]['from'], 'sumar 10 puntos') && str_contains($changed[0]['changes'][0]['to'], 'sumar 12 puntos'), 'diferencia: una regla cambiada dice qué bloque cambió, antes y después, en español');
    $dis = $vA['rules'];
    $dis[0]['enabled'] = false;
    T::eq(RuleSets::diff($vA['rules'], $dis)[0]['changes'], [['field' => 'Estado', 'from' => 'activada', 'to' => 'desactivada']], 'diferencia: activar o desactivar una regla se ve como «Estado»');
    T::eq(RuleSets::diff($vA['rules'], $vA['rules']), [], '  y dos listas iguales no tienen diferencia');
    // Revertir
    $v1 = $h[2]['id'];
    $rv = RuleSets::revert($sid, $v1, $uid);
    T::ok($rv['ok'] && $rv['version'] === 4 && RuleSets::published($sid)['version'] === 4 && RuleSets::published($sid)['reverted_from'] === 1 && $count('rule_versions', 'set_id = ?', [$sid]) === 4, 'revertir: la versión 1 vuelve como versión NUEVA 4 publicada (la historia no se borra)');
    T::eq(RuleSets::published($sid)['rules'][0]['then'][0]['points'], RuleSets::version($sid, $v1)['rules'][0]['then'][0]['points'], '  con las reglas exactas de la versión 1');
    T::eq(RuleSets::draft($sid)['version'], 3, '  y el borrador pendiente sigue intacto');
    T::ok(!RuleSets::revert($sid, (int) RuleSets::published($sid)['id'], $uid)['ok'] && !RuleSets::revert($sid, (int) RuleSets::draft($sid)['id'], $uid)['ok'] && !RuleSets::revert($sid, 999999, $uid)['ok'], '  no se puede revertir a la publicada, a un borrador ni a una versión que no existe');
    $other = RuleSets::create('alerts', 'Otro', '', $uid);
    T::ok(!RuleSets::revert((int) $other['id'], $v1, $uid)['ok'], '  ni a una versión de otro conjunto');
    T::ok(RuleSets::discardDraft($sid, $uid)['ok'] && RuleSets::draft($sid) === null && !RuleSets::discardDraft($sid, $uid)['ok'], 'borrador: se puede descartar');
    // Retirar y borrar
    $un = RuleSets::unpublish($sid, $uid);
    T::ok($un['ok'] && RuleSets::published($sid) === null && RuleSets::engineSets() === [] && RuleSets::all()[0]['status'] === 'retired', 'retirar: el conjunto deja de aplicarse y queda «retirado»');
    T::ok(!RuleSets::unpublish($sid, $uid)['ok'], '  retirar lo que no está publicado da error');
    T::ok(RuleSets::deleteSet((int) $other['id'], $uid)['ok'] && $count('rule_sets', 'deleted_at IS NULL') === 1 && $count('rule_sets') === 2, 'borrar: borrado lógico (el historial sigue en la base)');
    for ($i = 0; $i < Rules::MAX_SETS + 1; $i++) {
        $r = RuleSets::create('alerts', "Extra $i", '', $uid);
        if (!$r['ok']) {
            break;
        }
    }
    T::ok(!$r['ok'] && $count('rule_sets', 'deleted_at IS NULL') === Rules::MAX_SETS, 'límite: máximo ' . Rules::MAX_SETS . ' conjuntos');
    Db::pdo()->exec('DELETE FROM rule_versions');
    Db::pdo()->exec('DELETE FROM rule_sets');
    RuleSets::clearCache();
    // Auditoría
    $log = Db::all("SELECT action, changes FROM activity_log WHERE action LIKE 'rules_%' ORDER BY id");
    $actions = array_column($log, 'action');
    T::ok(count(array_intersect(['rules_set_create', 'rules_draft_save', 'rules_publish', 'rules_revert', 'rules_unpublish', 'rules_set_delete', 'rules_draft_discard'], $actions)) === 7, 'auditoría: cada acción deja su registro (crear, guardar borrador, publicar, revertir, retirar, borrar, descartar)');
    $pub = null;
    foreach ($log as $l) {
        if ($l['action'] === 'rules_publish' && $pub === null) {
            $pub = Db::decode($l['changes']);
        }
    }
    T::ok($pub !== null && $pub['version'] === 1 && !empty($pub['diff']) && str_contains($pub['diff'][0], 'Texas suma'), '  publicar guarda la diferencia legible (anterior y nuevo)');
    T::ok(!str_contains(json_encode($log), 'TELEGRAM') && !str_contains(json_encode($log), 'password'), '  sin secretos');

    // ================================================================== Aplicación en el motor (reloj simulado)
    T::reset();
    Db::pdo()->exec("DELETE FROM settings WHERE `key` IN ('alerts','rules.alert_cap')");
    Settings::clear();
    RuleSets::clearCache();
    $logf = "$work/rules-tg.log";
    @unlink($logf);
    $modef = "$work/rules-tg.mode";
    file_put_contents($modef, 'ok');
    $port = Infra::phpServer('rulestg', $ctx['root'] . '/tests/lib', ['MOCK_LOG' => $logf, 'MOCK_MODE_FILE' => $modef], $ctx['root'] . '/tests/lib/telegram_mock.php');
    $tgOn = static function (bool $on) use ($port): void {
        Env::set('TELEGRAM_BOT_TOKEN', $on ? 'bot-token-prueba-q5b' : null);
        Env::set('TELEGRAM_CHAT_ID', $on ? '555001' : null);
        Env::set('TELEGRAM_API_BASE', $on ? "http://127.0.0.1:$port" : null);
    };
    $tgOn(true);
    $sent = static fn(): array => array_values(array_filter(Infra::readLog($logf), static fn($r) => str_contains((string) ($r['path'] ?? ''), 'sendMessage')));
    $runJobs = static function (int $max = 30): void {
        Fx::runJobs($max);
    };

    // Base sin reglas
    $r0 = LeadIngest::handle($payload('1001', ['truck_count' => 2]), 'web_careers');
    $base = (int) $r0['score'];
    T::ok($r0['ok'] && $base > 0 && $base < 90 && $count('rule_applications') === 0, 'motor: sin reglas publicadas un lead nuevo se puntúa como siempre y no deja registros de reglas (' . $base . ')');
    $bd0 = Db::decode(Db::value('SELECT score_breakdown FROM leads WHERE id = ?', [$r0['lead_id']]));
    T::ok(!isset($bd0['rules']) && !isset($bd0['rules_adjust']), '  y el desglose no trae claves de reglas');

    // Puntos
    $sc = $mkSet('scoring', 'Puntos', [$R('tx', 'Texas suma 10', $blk('all', $cond('state', 'is', 'TX')), [['type' => 'add_points', 'points' => 10]]), $R('tn', 'Tennessee sube a caliente', $blk('all', $cond('state', 'is', 'TN')), [['type' => 'min_temperature', 'level' => 'hot']])]);
    T::eq($count('rule_applications'), 0, 'motor: el borrador no se aplica a nadie');
    RuleSets::publish($sc, $uid);
    $r1 = LeadIngest::handle($payload('1002', ['truck_count' => 2]), 'web_careers');
    $lead1 = Lead::find((int) $r1['lead_id']);
    T::eq([$r1['score'], $lead1['score']], [$base + 10, $base + 10], 'motor: con una regla publicada el lead nuevo recibe sus puntos DESPUÉS de la base (' . ($base + 10) . ')');
    T::ok($lead1['score_breakdown']['rules'][0]['rule_id'] === 'tx' && $lead1['score_breakdown']['rules'][0]['effects'][0]['points'] === 10 && $lead1['score_breakdown']['rules_adjust'] === 10 && $lead1['score_breakdown']['score_base'] === $base, '  el desglose guarda qué regla influyó y cuánto (breakdown.rules)');
    $app = Db::one('SELECT * FROM rule_applications WHERE lead_id = ?', [$r1['lead_id']]);
    T::ok($app !== null && $app['rule_id'] === 'tx' && (int) $app['points'] === 10 && $app['kind'] === 'scoring' && $count('rule_applications') === 1, '  y el registro de aplicaciones lo anota una vez');
    $ev = Db::one("SELECT payload FROM lead_events WHERE lead_id = ? AND type = 'scored'", [$r1['lead_id']]);
    T::ok($ev !== null, '  y queda el evento scored');
    LeadIngest::rescore((int) $r1['lead_id']);
    T::eq($count('rule_applications'), 1, '  recalcular sin cambios no repite el registro de puntos');
    $r2 = LeadIngest::handle($payload('1003', ['state' => 'TN', 'truck_count' => 2]), 'web_careers');
    T::eq($r2['temperature'], 'hot', 'motor: «temperatura mínima» sube a caliente un lead tibio');
    $hotJobs = Db::all("SELECT type FROM jobs WHERE JSON_EXTRACT(payload, '$.lead_id') = ?", [$r2['lead_id']]);
    T::ok(in_array('telegram_alert', array_column($hotJobs, 'type'), true) && in_array('telegram_alert_followup', array_column($hotJobs, 'type'), true), '  y por ser caliente entran las alertas A2 y A3 de siempre');
    // Equivalencia en el motor: el mismo lead entra igual si la regla se retira
    RuleSets::unpublish($sc, $uid);
    $r3 = LeadIngest::handle($payload('1004', ['truck_count' => 2]), 'web_careers');
    T::eq($r3['score'], $base, 'motor: retirada la regla, el lead vuelve a puntuarse como en el documento');
    T::ok(RuleSets::revert($sc, (int) Db::value("SELECT id FROM rule_versions WHERE set_id = ? AND status = 'archived' ORDER BY version DESC LIMIT 1", [$sc]), $uid)['ok'], '  y «revertir» a la versión archivada la vuelve a activar como versión nueva');

    // Recálculo masivo por la cola: aplica a los existentes sin alertas ni tareas
    Db::pdo()->exec('DELETE FROM jobs');
    $before = (int) Db::value('SELECT score FROM leads WHERE id = ?', [$r3['lead_id']]);
    $rc = ScoringConfig::startRecalc($uid);
    $guard = 0;
    while (ScoringConfig::running() && $guard++ < 10) {
        $runJobs();
    }
    T::ok($rc['ok'] && (int) Db::value('SELECT score FROM leads WHERE id = ?', [$r3['lead_id']]) === $before + 10, 'recálculo: «Aplicar a leads existentes» (cola, en lotes) aplica la versión publicada a los leads que ya estaban');
    T::ok($count('lead_events', "lead_id = ? AND type = 'scored'", [$r3['lead_id']]) >= 2, '  y deja el evento scored');
    T::eq($count('jobs', "type IN ('telegram_alert','rule_alert')"), 0, '  sin disparar alertas por cientos de leads');
    T::ok(ScoringConfig::state()['status'] === 'done' && ScoringConfig::state()['after'] !== null && ScoringConfig::state()['before'] !== null, '  y guarda el recuento antes y después');
    RuleSets::unpublish($sc, $uid);

    // Alertas de reglas
    $al = $mkSet('alerts', 'Avisos', [$R('big', 'Flota grande', $blk('all', $cond('trucks', 'gte', 5)), [['type' => 'telegram_alert', 'urgency' => 'urgent', 'text' => 'Flota de {camiones} camiones: {nombre} ({estado}), {puntuacion}/100']])]);
    RuleSets::publish($al, $uid);
    Db::pdo()->exec('DELETE FROM jobs');
    $a1 = LeadIngest::handle($payload('2001', ['truck_count' => 6]), 'web_careers');
    $ja = Db::all("SELECT * FROM jobs WHERE type = 'rule_alert'");
    T::ok(count($ja) === 1 && Db::decode($ja[0]['payload'])['rule_id'] === 'big' && Db::decode($ja[0]['payload'])['urgency'] === 'urgent', 'alerta de regla: un lead que coincide encola UN job rule_alert');
    LeadIngest::rescore((int) $a1['lead_id']);
    LeadIngest::handle($payload('2001', ['truck_count' => 6, 'years_experience' => 9]), 'web_careers');   // reingreso del mismo lead
    T::eq([$count('jobs', "type = 'rule_alert'"), $count('rule_applications', "action = 'telegram_alert'")], [1, 1], '  recalcular o reingresar el mismo lead NO la repite (deduplicación por regla, lead e intento)');
    $a0 = LeadIngest::handle($payload('2002', ['truck_count' => 2]), 'web_careers');
    T::eq($count('jobs', "type = 'rule_alert'"), 1, '  un lead que no coincide no genera alerta');
    @unlink($logf);
    $runJobs();
    $msgs = $sent();
    $texts = array_map(static fn($m) => (string) ($m['body']['text'] ?? ''), $msgs);
    $ruleMsg = array_values(array_filter($texts, static fn($t) => str_contains($t, 'Regla: Flota grande')));
    T::ok(count($ruleMsg) === 1 && str_contains($ruleMsg[0], 'Flota de 6 camiones: Ana 2001 (TX)') && str_contains($ruleMsg[0], 'abrir lead #' . $a1['lead_id']), 'alerta de regla: Telegram recibe el nombre de la regla, el texto con las variables resueltas y el enlace al lead');
    T::ok(!str_contains($ruleMsg[0], '+1305') && !str_contains($ruleMsg[0], '@example.com') && !str_contains($ruleMsg[0], 'bot-token'), '  sin teléfono, correo ni secretos');
    T::eq($count('lead_events', "lead_id = ? AND type = 'alert_sent' AND payload LIKE '%\"rule\"%'", [$a1['lead_id']]), 1, '  y deja el evento alert_sent con el nombre de la regla');
    // Texto escapado en HTML
    $evil = $mkSet('alerts', 'Evil', [$R('ev', 'Nombre &amp; <raro>', $blk('all', $cond('trucks', 'gte', 1)), [['type' => 'telegram_alert', 'urgency' => 'urgent', 'text' => '{nombre} & {perfil}']])]);
    T::ok($count('rule_sets') >= 2, '  (conjunto con nombre raro creado: la validación del nombre de la REGLA solo limita longitud; se escapa al enviar)');
    RuleSets::publish($evil, $uid);
    @unlink($logf);
    LeadIngest::handle($payload('2003', ['name' => 'Luis <b>Ruiz</b> & Co', 'truck_count' => 1]), 'web_careers');
    $runJobs();
    $t = implode("\n", array_map(static fn($m) => (string) ($m['body']['text'] ?? ''), $sent()));
    T::ok(!str_contains($t, '<raro>') && str_contains($t, '&lt;raro&gt;') && !str_contains($t, '<b>Ruiz'), '  el nombre de la regla y el del lead salen ESCAPADOS (HTML de Telegram)');
    RuleSets::unpublish($evil, $uid);
    // Silencio: las normales esperan, las urgentes no
    $q = $mkSet('alerts', 'Normales', [$R('nq', 'Normal', $blk('all', $cond('trucks', 'is', 7)), [['type' => 'telegram_alert', 'urgency' => 'normal', 'text' => 'Normal {nombre}']]),
        $R('uq', 'Urgente', $blk('all', $cond('trucks', 'is', 8)), [['type' => 'telegram_alert', 'urgency' => 'urgent', 'text' => 'Urgente {nombre}']])]);
    RuleSets::publish($q, $uid);
    Settings::set('alerts', ['quiet' => ['enabled' => true, 'start' => '00:00', 'end' => '23:59']] + Alerts::get());
    Settings::clear();
    Db::pdo()->exec('DELETE FROM jobs');
    LeadIngest::handle($payload('2004', ['truck_count' => 7]), 'web_careers');
    LeadIngest::handle($payload('2005', ['truck_count' => 8]), 'web_careers');
    $nq = Db::one("SELECT run_at FROM jobs WHERE type = 'rule_alert' AND payload LIKE '%\"rule_id\":\"nq\"%'");
    $uq = Db::one("SELECT run_at FROM jobs WHERE type = 'rule_alert' AND payload LIKE '%\"rule_id\":\"uq\"%'");
    T::ok($nq !== null && (int) Clock::parse($nq['run_at']) > Clock::time() + 3600, 'silencio: la alerta NORMAL de una regla espera a que termine el horario de silencio');
    T::ok($uq !== null && (int) Clock::parse($uq['run_at']) <= Clock::time() + 2, '  y la URGENTE sale ya');
    Db::pdo()->exec("DELETE FROM settings WHERE `key` = 'alerts'");
    Settings::clear();
    // Tope por hora
    RuleSets::unpublish($q, $uid);
    RuleSets::unpublish($al, $uid);
    $cap = $mkSet('alerts', 'Tope', [$R('cp', 'Tope', $blk('all', $cond('trucks', 'is', 9)), [['type' => 'telegram_alert', 'urgency' => 'urgent', 'text' => 'Tope {nombre}']])]);
    RuleSets::publish($cap, $uid);
    Settings::set('rules.alert_cap', 2);
    foreach (glob(\Captacion\Services\Storage::dir('ratelimit') . '/*.json') ?: [] as $f) {
        @unlink($f);
    }
    Db::pdo()->exec('DELETE FROM jobs');
    @unlink($logf);
    $caps = [];
    foreach (['3001', '3002', '3003'] as $n) {
        $caps[] = (int) LeadIngest::handle($payload($n, ['truck_count' => 9]), 'web_careers')['lead_id'];
    }
    $runJobs();
    $capMsgs = array_filter(array_map(static fn($m) => (string) ($m['body']['text'] ?? ''), $sent()), static fn($t) => str_contains($t, 'Regla: Tope'));
    T::eq([count($capMsgs), $count('lead_events', "type = 'rule_alert_skipped'")], [2, 1], 'tope: con rules.alert_cap = 2 salen 2 alertas por hora y la tercera se omite con su evento');
    // Se retira antes de salir
    RuleSets::unpublish($cap, $uid);
    $cap2 = $mkSet('alerts', 'Tope2', [$R('cq', 'Tope2', $blk('all', $cond('trucks', 'is', 10)), [['type' => 'telegram_alert', 'urgency' => 'urgent', 'text' => 'x']])]);
    RuleSets::publish($cap2, $uid);
    Db::pdo()->exec('DELETE FROM jobs');
    @unlink($logf);
    LeadIngest::handle($payload('3004', ['truck_count' => 10]), 'web_careers');
    RuleSets::unpublish($cap2, $uid);
    $runJobs();
    T::eq(count(array_filter($sent(), static fn($m) => str_contains((string) ($m['body']['text'] ?? ''), 'Regla:'))), 0, 'alerta: si la regla se retira después de encolarse, el job no avisa');
    RuleSets::clearCache();

    // Tareas, etiquetas y cola de revisión
    $rt = $mkSet('routing_tasks', 'Rutas', [
        $R('call', 'Llamar a los calientes', $blk('all', $cond('temperature', 'is', 'hot'), $cond('stage', 'is', 'new')), [['type' => 'create_task', 'title' => 'Llamar a {nombre} ({perfil})', 'due_hours' => 2, 'priority' => 1]]),
        $R('rev', 'Revisar FMCSA', $blk('any', $cond('trucks', 'is', 11)), [['type' => 'review_queue'], ['type' => 'tag', 'tag' => 'Revisar FMCSA']]),
    ]);
    RuleSets::publish($rt, $uid);
    $sc2 = $mkSet('scoring', 'Calientes', [$R('hot', 'Todos calientes', $blk('all', $cond('profile', 'exists')), [['type' => 'min_temperature', 'level' => 'hot']])]);
    RuleSets::publish($sc2, $uid);
    Db::pdo()->exec('DELETE FROM jobs');
    $t0 = Clock::time();
    $tk = LeadIngest::handle($payload('4001', ['truck_count' => 11]), 'web_careers');
    $task = Db::one('SELECT * FROM tasks WHERE lead_id = ?', [$tk['lead_id']]);
    T::ok($task !== null && $task['title'] === 'Llamar a Ana 4001 (Owner-operator)' && abs((int) Clock::parse($task['due_at']) - ($t0 + 7200)) <= 3 && (int) $task['priority'] === 1, 'tarea de regla: se crea con las variables resueltas, vence en 2 h y con la prioridad pedida');
    T::ok($count('lead_events', "lead_id = ? AND type = 'task_created'", [$tk['lead_id']]) === 1, '  y queda el evento task_created');
    $tkLead = Lead::find((int) $tk['lead_id']);
    T::ok(in_array('tag:revisar-fmcsa', $tkLead['flags'], true) && in_array('review_queue', $tkLead['flags'], true), 'etiqueta y cola de revisión: quedan como banderas del lead (tag:revisar-fmcsa, review_queue)');
    T::eq([Catalog::label(Catalog::FLAGS, 'tag:revisar-fmcsa'), Catalog::label(Catalog::FLAGS, 'review_queue')], ['Etiqueta: revisar-fmcsa', 'Por revisar'], '  con etiquetas legibles en el panel');
    LeadIngest::rescore((int) $tk['lead_id']);
    LeadIngest::rescore((int) $tk['lead_id']);
    T::eq($count('tasks', 'lead_id = ?', [$tk['lead_id']]), 1, 'tarea de regla: recalcular no crea otra (deduplicada por regla, lead e intento)');
    // Retirar la regla y recalcular: las banderas de reglas se quitan
    RuleSets::unpublish($rt, $uid);
    LeadIngest::rescore((int) $tk['lead_id']);
    $f2 = Lead::find((int) $tk['lead_id'])['flags'];
    T::ok(!in_array('review_queue', $f2, true) && !array_filter($f2, static fn($f) => str_starts_with((string) $f, 'tag:')), 'etiquetas: al retirar la regla y recalcular, sus banderas se quitan');
    // Importaciones y recálculo masivo: banderas sí, tareas y alertas no
    RuleSets::revert($rt, (int) Db::value("SELECT id FROM rule_versions WHERE set_id = ? AND status = 'archived' ORDER BY version DESC LIMIT 1", [$rt]), $uid);
    $before = $count('tasks');
    $q1 = LeadIngest::handle($payload('4002', ['truck_count' => 11]), 'import', ['quiet' => true, 'skip_antispam' => true]);
    T::ok($count('tasks') === $before && in_array('review_queue', Lead::find((int) $q1['lead_id'])['flags'], true), 'importación silenciosa: pone banderas pero no crea tareas ni alertas');
    // Reloj simulado: horas desde la llegada
    $tm = $mkSet('routing_tasks', 'Tiempo', [$R('late', 'Pasaron 3 horas sin contacto', $blk('all', $cond('hours_since', 'gt', 3), $cond('stage', 'is', 'new')), [['type' => 'tag', 'tag' => 'sin-contacto']])]);
    RuleSets::publish($tm, $uid);
    $lt = LeadIngest::handle($payload('4003', ['truck_count' => 2]), 'web_careers');
    T::ok(!in_array('tag:sin-contacto', Lead::find((int) $lt['lead_id'])['flags'], true), 'reloj: «horas desde la llegada > 3» no coincide al llegar');
    Clock::travel(4 * 3600);
    LeadIngest::rescore((int) $lt['lead_id']);
    T::ok(in_array('tag:sin-contacto', Lead::find((int) $lt['lead_id'])['flags'], true), '  y sí coincide al recalcular con el reloj 4 horas después (hora inyectada, no oculta)');
    Clock::travel(0);
    // Consentimiento
    $cs = $mkSet('routing_tasks', 'Consentimiento', [$R('sms', 'Con SMS', $blk('all', $cond('consent', 'in', ['sms']), $cond('trucks', 'is', 12)), [['type' => 'tag', 'tag' => 'con-sms']])]);
    RuleSets::publish($cs, $uid);
    $c1 = LeadIngest::handle($payload('4004', ['truck_count' => 12, 'consent' => ['given' => true, 'text' => 'texto', 'version' => 'v1']]), 'web_careers');
    $c2 = LeadIngest::handle($payload('4005', ['truck_count' => 12]), 'web_careers');
    T::ok(in_array('tag:con-sms', Lead::find((int) $c1['lead_id'])['flags'], true) && !in_array('tag:con-sms', Lead::find((int) $c2['lead_id'])['flags'], true), 'condición de consentimiento: usa los canales con consentimiento vigente del lead');
    // Desglose en ficha
    foreach ([$sc, $sc2, $al, $rt, $tm, $cs, $q, $cap, $cap2, $evil] as $x) {
        RuleSets::unpublish($x, $uid);
    }

    // ================================================================== Plantillas
    Db::pdo()->exec('DELETE FROM rule_applications');
    Db::pdo()->exec('DELETE FROM rule_versions');
    Db::pdo()->exec('DELETE FROM rule_sets');
    RuleSets::clearCache();
    $ti = RuleTemplates::install($uid);
    $all = RuleSets::all();
    $nRules = 0;
    $allDisabled = true;
    $allExample = true;
    foreach ($all as $set) {
        $d = RuleSets::draft($set['id']);
        foreach ($d['rules'] as $rr) {
            $nRules++;
            $allDisabled = $allDisabled && $rr['enabled'] === false;
            $allExample = $allExample && $rr['example'] === true;
        }
    }
    T::ok($ti['created'] === 3 && count($all) === 3 && $nRules === 5 && $allDisabled && $allExample && array_unique(array_column($all, 'status')) === ['draft'], 'plantillas: 3 conjuntos y 5 reglas de ejemplo, TODAS desactivadas y marcadas «ejemplo», como borrador (ninguna publicada)');
    T::eq(RuleTemplates::install($uid)['created'], 0, '  instalar otra vez no duplica');
    foreach ($all as $set) {
        RuleSets::publish($set['id'], $uid);
    }
    $leads = array_map(static fn($i) => ['profile' => 'owner_operator', 'first_name' => 'N' . $i, 'last_name' => 'A', 'phone_e164' => '+13055550' . $i, 'email' => "t$i@example.com", 'state' => 'TX', 'language' => 'en', 'cdl_type' => 'standard', 'english_level' => 'yes',
        'years_experience' => 3, 'equipment_types' => ['dry-van'], 'truck_count' => 7, 'authority_status' => 'active', 'source' => 'indeed', 'stage' => 'new', 'created_at' => Clock::now(), 'flags' => [], 'fmcsa_data' => []], range(100, 140));
    $same = 0;
    foreach ($leads as $l) {
        $same += \Captacion\Services\RuleRunner::score($l) === Scoring::score($l) ? 1 : 0;
    }
    T::eq($same, count($leads), 'plantillas: publicadas pero desactivadas no cambian NADA (idéntico a la puntuación del documento)');
    $templates = RuleTemplates::all();
    T::ok(count(array_filter(array_merge(...array_column($templates, 'rules')), static fn($r) => $r['example'] && !$r['enabled'])) === 5, 'plantillas: en código también están marcadas y desactivadas');
    Db::pdo()->exec('DELETE FROM rule_versions');
    Db::pdo()->exec('DELETE FROM rule_sets');
    RuleSets::clearCache();
    $tgOn(false);
    Infra::stop('rulestg');
};
