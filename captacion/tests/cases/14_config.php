<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Services\ActivityLog;
use Captacion\Services\Alerts;
use Captacion\Services\Catalog;
use Captacion\Services\Costs;
use Captacion\Services\Dashboard;
use Captacion\Services\Display;
use Captacion\Services\Fmt;
use Captacion\Services\Integrations;
use Captacion\Services\JobHandlers;
use Captacion\Services\LeadIngest;
use Captacion\Services\Queue;
use Captacion\Services\Scoring;
use Captacion\Services\ScoringConfig;
use Captacion\Services\Settings;
use Captacion\Services\Telegram;
use Captacion\Services\Totp;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/** Configuracion (Q2b-C): alertas (tibios por Telegram, silencio, minutos), puntuacion (validacion, recalculo por lotes), costos y CPL, integraciones con servidores simulados, reautenticacion y roles. */
return static function (array $ctx): void {
    $wipe = static function (): void {
        Db::pdo()->exec("DELETE FROM settings WHERE `key` IN ('alerts','scoring.recalc','display.timezone','display.hours','scoring.owner','scoring.driver','scoring.temperature','dispatched_equipment','hiring_states','driver_min_years') OR `key` LIKE 'integration.%'");
        Db::pdo()->exec("UPDATE settings SET value = '[\"dispatched_equipment\",\"hiring_states\",\"driver_min_years\",\"scoring.driver.experience\"]' WHERE `key` = 'provisional'");
        Db::pdo()->exec('DELETE FROM channel_costs');
        Settings::clear();
    };
    T::reset(true);
    $wipe();
    Env::set('FMCSA_WEBKEY', null);
    Env::set('TELEGRAM_BOT_TOKEN', null);
    Env::set('TELEGRAM_CHAT_ID', null);
    $now = Clock::time();
    $mk = static function (array $o = []) use ($now): int {
        static $n = 0;
        $n++;
        $row = $o + ['profile' => 'owner_operator', 'first_name' => 'Cfg' . $n, 'last_name' => 'Lead' . $n, 'phone_e164' => '+1305558' . str_pad((string) (1000 + $n), 4, '0', STR_PAD_LEFT), 'email' => "c$n@example.com",
            'state' => 'FL', 'language' => 'en', 'score' => 50, 'temperature' => 'warm', 'pipeline' => 'A', 'stage' => 'new', 'source' => 'meta', 'attempt' => 1, 'equipment_types' => ['dry-van'], 'flags' => [],
            'created_at' => Clock::at($now - 3600), 'updated_at' => Clock::at($now - 3600)];
        return Db::insert('leads', $row);
    };
    $jobs = static fn(string $type) => Db::all('SELECT * FROM jobs WHERE type = ? ORDER BY id', [$type]);

    // ------------------------------------------------------------------ Alertas: valores y reglas
    $d = Alerts::get();
    T::eq([$d['hot_telegram'], $d['followup_telegram'], $d['followup_minutes'], $d['warm_telegram'], $d['email'], $d['quiet']['enabled']], [true, true, 15, 'auto', true, false], 'alertas: valores por defecto (A2 y A3 activas, 15 min, tibios en automatico, correo activo, sin silencio)');
    T::ok(Alerts::warmTelegram(), 'tibios por Telegram: ACTIVADO en automatico mientras no haya llave de FMCSA');
    Env::set('FMCSA_WEBKEY', 'KEY-DE-PRUEBA-1234');
    T::ok(!Alerts::warmTelegram(), '  y se apaga en automatico cuando ya hay llave');
    T::ok(Alerts::warmTelegram(['warm_telegram' => 'on'] + $d) && !Alerts::warmTelegram(['warm_telegram' => 'off'] + $d), '  «Siempre» y «Nunca» mandan sobre la llave');
    Env::set('FMCSA_WEBKEY', null);
    // validacion
    $ok = Alerts::validate(['hot_telegram' => '1', 'followup_minutes' => '30', 'warm_telegram' => 'on', 'email' => '1', 'mail_to' => 'ops@example.com', 'quiet_enabled' => '1', 'quiet_start' => '21:30', 'quiet_end' => '06:00']);
    T::ok($ok['ok'] && $ok['values']['followup_minutes'] === 30 && $ok['values']['followup_telegram'] === false && $ok['values']['quiet']['start'] === '21:30', 'validar alertas: un formulario correcto (las casillas ausentes quedan apagadas)');
    foreach ([['followup_minutes' => '4'], ['followup_minutes' => '121'], ['followup_minutes' => 'abc'], ['warm_telegram' => 'quiza'], ['mail_to' => 'no-es-correo'], ['quiet_enabled' => '1', 'quiet_start' => '25:00', 'quiet_end' => '06:00'], ['quiet_enabled' => '1', 'quiet_start' => '08:00', 'quiet_end' => '08:00']] as $bad) {
        T::ok(!Alerts::validate($bad + ['followup_minutes' => '15', 'warm_telegram' => 'auto'])['ok'], 'validar alertas: rechaza ' . json_encode($bad));
    }
    // silencio (22:00 a 07:00 en la zona del panel); reloj fijo a las 23:30 de hoy
    $at = static fn(string $hm, int $addDays = 0) => (new DateTimeImmutable("today $hm", Fmt::tz()))->modify("+$addDays days")->getTimestamp();
    $q = ['quiet' => ['enabled' => true, 'start' => '22:00', 'end' => '07:00']] + $d;
    T::eq(Alerts::quietUntil($at('23:30'), $q), $at('07:00', 1), 'silencio 22:00 a 07:00: a las 23:30 termina a las 07:00 de mañana');
    T::eq(Alerts::quietUntil($at('03:00'), $q), $at('07:00'), '  a las 03:00 termina a las 07:00 de hoy');
    T::eq(Alerts::quietUntil($at('12:00'), $q), null, '  a las 12:00 no hay silencio');
    T::eq(Alerts::quietUntil($at('23:30'), $d), null, '  desactivado: nunca hay silencio');
    $q2 = ['quiet' => ['enabled' => true, 'start' => '13:00', 'end' => '15:00']] + $d;
    T::eq([Alerts::quietUntil($at('14:00'), $q2), Alerts::quietUntil($at('16:00'), $q2)], [$at('15:00'), null], 'silencio dentro del mismo dia (13:00 a 15:00)');

    // ------------------------------------------------------------------ Motor: que se encola segun los ajustes
    $reset = static function () { T::reset(); };
    $hot = $mk(['temperature' => 'hot', 'score' => 90]);
    $reset();
    $hot = $mk(['temperature' => 'hot', 'score' => 90]);
    LeadIngest::enqueueFor($hot, 'hot', 1);
    $a2 = $jobs('telegram_alert');
    $a3 = $jobs('telegram_alert_followup');
    T::ok(count($a2) === 1 && json_decode($a2[0]['payload'], true)['kind'] === 'hot' && count($a3) === 1 && abs((int) Clock::parse($a3[0]['run_at']) - (Clock::time() + 900)) <= 3, 'caliente: A2 inmediata y A3 a los 15 min');
    T::eq(count($jobs('email_notify')), 0, '  y ningun correo');
    $reset();
    Settings::set('alerts', ['followup_minutes' => 25] + $d);
    $h2 = $mk(['temperature' => 'hot']);
    LeadIngest::enqueueFor($h2, 'hot', 1);
    T::ok(abs((int) Clock::parse($jobs('telegram_alert_followup')[0]['run_at']) - (Clock::time() + 1500)) <= 3, 'A3 con minutos configurables: 25 min');
    $reset();
    Settings::set('alerts', ['followup_telegram' => false] + $d);
    LeadIngest::enqueueFor($mk(['temperature' => 'hot']), 'hot', 1);
    T::ok(count($jobs('telegram_alert')) === 1 && count($jobs('telegram_alert_followup')) === 0, 'A3 apagada: solo la A2');
    $reset();
    Settings::set('alerts', ['hot_telegram' => false] + $d);
    LeadIngest::enqueueFor($mk(['temperature' => 'hot']), 'hot', 1);
    T::ok(count($jobs('telegram_alert')) === 0 && count($jobs('email_notify')) === 1, 'Telegram de calientes apagado: el lead caliente avisa por correo (no queda sin aviso)');
    // tibios
    $reset();
    Settings::set('alerts', $d);
    $w = $mk(['temperature' => 'warm']);
    LeadIngest::enqueueFor($w, 'warm', 1);
    $tw = $jobs('telegram_alert');
    T::ok(count($tw) === 1 && json_decode($tw[0]['payload'], true)['kind'] === 'warm' && count($jobs('email_notify')) === 1, 'TIBIO sin llave FMCSA (automatico): Telegram de tibios + correo');
    LeadIngest::enqueueFor($w, 'warm', 1);
    T::eq(count($jobs('telegram_alert')), 1, '  y no se duplica (clave unica por lead e intento)');
    $reset();
    Env::set('FMCSA_WEBKEY', 'KEY-DE-PRUEBA-1234');
    LeadIngest::enqueueFor($mk(['temperature' => 'warm']), 'warm', 1);
    T::ok(count($jobs('telegram_alert')) === 0 && count($jobs('email_notify')) === 1, 'TIBIO con llave FMCSA (automatico): solo correo');
    $reset();
    Settings::set('alerts', ['warm_telegram' => 'on'] + $d);
    LeadIngest::enqueueFor($mk(['temperature' => 'warm']), 'warm', 1);
    T::eq(count($jobs('telegram_alert')), 1, 'tibios «Siempre»: Telegram aun con llave');
    Env::set('FMCSA_WEBKEY', null);
    $reset();
    Settings::set('alerts', ['warm_telegram' => 'off'] + $d);
    LeadIngest::enqueueFor($mk(['temperature' => 'warm']), 'warm', 1);
    T::eq(count($jobs('telegram_alert')), 0, 'tibios «Nunca»: sin Telegram');
    $reset();
    Settings::set('alerts', ['email' => false] + $d);
    LeadIngest::enqueueFor($mk(['temperature' => 'cold']), 'cold', 1);
    LeadIngest::enqueueFor($mk(['temperature' => 'warm']), 'warm', 1);
    T::eq(count($jobs('email_notify')), 0, 'correo apagado: no se encolan correos (frios ni tibios)');
    T::eq(count($jobs('telegram_alert')), 1, '  y el tibio sigue yendo por Telegram');
    // silencio: los no urgentes esperan, los urgentes no
    $reset();
    Settings::set('alerts', ['quiet' => ['enabled' => true, 'start' => '00:00', 'end' => '23:59']] + $d);   // silencio casi todo el dia: ahora siempre cae dentro
    $until = Alerts::quietUntil(Clock::time());
    LeadIngest::enqueueFor($mk(['temperature' => 'warm']), 'warm', 1);
    LeadIngest::enqueueFor($mk(['temperature' => 'hot']), 'hot', 1);
    $emails = $jobs('email_notify');
    $tgs = $jobs('telegram_alert');
    $warmJob = array_values(array_filter($tgs, static fn($j) => json_decode($j['payload'], true)['kind'] === 'warm'))[0] ?? null;
    $hotJob = array_values(array_filter($tgs, static fn($j) => json_decode($j['payload'], true)['kind'] === 'hot'))[0] ?? null;
    T::ok($until !== null && $emails && (int) Clock::parse($emails[0]['run_at']) === $until && $warmJob !== null && (int) Clock::parse($warmJob['run_at']) === $until, 'silencio: el correo y el Telegram de tibios se retrasan hasta que termina el silencio');
    T::ok($hotJob !== null && (int) Clock::parse($hotJob['run_at']) <= Clock::time() + 2, '  la alerta A2 de un caliente NO espera (urgente)');
    // handlers
    $reset();
    Settings::set('alerts', $d);
    $log = $ctx['work'] . '/cfg-tg.log';
    @unlink($log);
    $modeFile = $ctx['work'] . '/cfg-tg.mode';
    file_put_contents($modeFile, 'ok');
    $port = Infra::phpServer('cfgmock', $ctx['root'] . '/tests/lib', ['MOCK_LOG' => $log, 'MOCK_MODE_FILE' => $modeFile], $ctx['root'] . '/tests/lib/telegram_mock.php');
    $token = '987654321:FAKE_CFG_TOKEN_not_real_9876543210';
    Env::set('TELEGRAM_BOT_TOKEN', $token);
    Env::set('TELEGRAM_CHAT_ID', '777000');
    Env::set('TELEGRAM_API_BASE', "http://127.0.0.1:$port");
    $reqs = static fn() => is_file($log) ? array_values(array_filter(array_map(static fn($l) => json_decode($l, true), file($log, FILE_IGNORE_NEW_LINES) ?: []))) : [];
    $wl = $mk(['temperature' => 'warm', 'first_name' => 'Tibia', 'last_name' => 'Prueba']);
    LeadIngest::enqueueFor($wl, 'warm', 1);
    Queue::runBatch(JobHandlers::all(), 5);
    $rq = $reqs();
    $texts = array_map(static fn($r) => (string) ($r['body']['text'] ?? ''), $rq);
    T::ok(count($rq) === 1 && str_contains($texts[0], 'Lead TIBIO') && str_contains($texts[0], 'Tibia Prueba'), 'handler: el Telegram del tibio sale con el encabezado «Lead TIBIO»');
    T::eq((int) Db::value("SELECT COUNT(*) FROM lead_events WHERE lead_id = ? AND type = 'alert_sent'", [$wl]), 1, '  y deja el evento alert_sent');
    // un tibio que ya fue contactado, o que subio a caliente, no avisa
    $reset();
    @unlink($log);
    $w2 = $mk(['temperature' => 'warm']);
    $w3 = $mk(['temperature' => 'warm']);
    LeadIngest::enqueueFor($w2, 'warm', 1);
    LeadIngest::enqueueFor($w3, 'warm', 1);
    Db::run("UPDATE leads SET first_contact_at = ? WHERE id = ?", [Clock::now(), $w2]);
    Db::run("UPDATE leads SET temperature = 'hot' WHERE id = ?", [$w3]);
    Queue::runBatch(JobHandlers::all(), 5);
    T::eq(count($reqs()), 0, 'handler: un tibio ya contactado, o que subio a caliente, no envia el aviso de tibio');
    // A3 usa los minutos configurados
    $reset();
    @unlink($log);
    Settings::set('alerts', ['followup_minutes' => 40] + $d);
    $h = $mk(['temperature' => 'hot', 'created_at' => Clock::at($now - 7200)]);
    LeadIngest::enqueueFor($h, 'hot', 1);
    Clock::travel(40 * 60 + 5);
    Queue::runBatch(JobHandlers::all(), 5);
    $texts = array_map(static fn($r) => (string) ($r['body']['text'] ?? ''), $reqs());
    T::ok(count($texts) === 2 && str_contains($texts[1], 'SIN CONTACTO tras 40 min'), 'A3: el texto dice los minutos configurados (40)');
    Clock::travel(0);
    // apagar A2 despues de encolar: el handler no envia
    $reset();
    @unlink($log);
    Settings::set('alerts', $d);
    LeadIngest::enqueueFor($mk(['temperature' => 'hot']), 'hot', 1);
    Settings::set('alerts', ['hot_telegram' => false] + $d);
    Queue::runBatch(JobHandlers::all(), 5);
    T::eq(count($reqs()), 0, 'apagar A2 despues de encolarla: el handler no envia');
    // correo con destinatario de Configuracion
    Settings::set('alerts', ['mail_to' => 'ops@example.com'] + $d);
    T::eq(Alerts::mailTo(), 'ops@example.com', 'correo de aviso: el de Configuracion manda sobre MAIL_TO');
    Settings::set('alerts', $d);
    T::eq(Alerts::mailTo(), 'carlos@example.com', '  vacio: usa MAIL_TO del .env');
    // rescore: pasar de frio a tibio avisa (una vez)
    $reset();
    $cold = $mk(['temperature' => 'cold', 'score' => 20, 'profile' => 'owner_operator', 'cdl_type' => 'standard', 'english_level' => 'yes', 'equipment_types' => ['reefer'], 'truck_count' => 6, 'years_experience' => 5, 'first_name' => 'Sube', 'last_name' => 'Tibio']);
    LeadIngest::rescore($cold, 'test', true);
    $kinds = array_map(static fn($j) => json_decode($j['payload'], true)['kind'], $jobs('telegram_alert'));
    T::eq(Db::value('SELECT temperature FROM leads WHERE id = ?', [$cold]), 'warm', 'rescore: un lead frio con buenos datos pasa a tibio');
    T::eq($kinds, ['warm'], '  y encola el aviso de tibio');

    // ------------------------------------------------------------------ Puntuacion: validacion y guardado
    $wipe();
    $cur = ScoringConfig::current();
    $post = static function (array $over = []) use ($cur): array {
        $p = ['owner' => array_map('strval', array_intersect_key($cur['owner'], ScoringConfig::OWNER_POINTS)), 'driver' => array_map('strval', array_intersect_key($cur['driver'], ScoringConfig::DRIVER_POINTS)), 'temp' => array_map('strval', $cur['temp'])];
        $p['owner']['experience_min_years'] = (string) $cur['owner']['experience_min_years'];
        foreach (['owner' => ['authority_age', 'trucks'], 'driver' => ['experience']] as $g => $tks) {
            foreach ($tks as $tk) {
                foreach ($cur[$g][$tk] as $i => $t) {
                    $p[$g][$tk][$i] = ['lt' => $t['lt'] === null ? '' : (string) $t['lt'], 'points' => (string) $t['points']];
                }
            }
        }
        return array_replace_recursive($p, $over);
    };
    $r = ScoringConfig::fromPost($post());
    T::ok($r['errors'] === [] && ScoringConfig::toSettings($r['form']) === ['scoring.owner' => $cur['owner'], 'scoring.driver' => $cur['driver'], 'scoring.temperature' => $cur['temp']] + [], 'puntuacion: el formulario con los valores vigentes valida y no cambia nada (ida y vuelta exacta)');
    $bad = [
        'puntos negativos' => [['owner' => ['cdl_standard' => '-5']], 'owner.cdl_standard'],
        'puntos con decimales' => [['owner' => ['cdl_standard' => '2.5']], 'owner.cdl_standard'],
        'puntos vacios' => [['owner' => ['referral' => '']], 'owner.referral'],
        'puntos sobre el tope' => [['driver' => ['hiring_state' => '51']], 'driver.hiring_state'],
        'texto en un peso' => [['owner' => ['complete' => 'abc']], 'owner.complete'],
        'tramos desordenados' => [['owner' => ['trucks' => [0 => ['lt' => '6'], 1 => ['lt' => '3']]]], 'owner.trucks.1.lt'],
        'limite de tramo en cero' => [['owner' => ['authority_age' => [0 => ['lt' => '0']]]], 'owner.authority_age.0.lt'],
        'caliente igual a tibio' => [['temp' => ['hot_min' => '40', 'warm_min' => '40']], 'temp'],
        'caliente menor que tibio' => [['temp' => ['hot_min' => '30', 'warm_min' => '40']], 'temp'],
        'caliente sobre 100' => [['temp' => ['hot_min' => '101']], 'temp.hot_min'],
        'tibio en cero' => [['temp' => ['warm_min' => '0']], 'temp.warm_min'],
        'minimo de experiencia fuera de rango' => [['owner' => ['experience_min_years' => '21']], 'owner.experience_min_years'],
    ];
    foreach ($bad as $name => [$over, $path]) {
        $res = ScoringConfig::fromPost($post($over));
        T::ok(isset($res['errors'][$path]), "puntuacion: rechaza $name (" . $path . ')');
    }
    $res = ScoringConfig::fromPost($post(['owner' => ['cdl_standard' => '-5']]));
    T::eq($res['form']['owner']['cdl_standard'], '-5', 'puntuacion: con error se conserva lo escrito para volver a pintarlo');
    $res = ScoringConfig::fromPost(['owner' => 'x', 'driver' => ['hiring_state' => ['a']], 'temp' => null]);
    T::ok($res['errors'] !== [], 'puntuacion: un POST con tipos raros no revienta y da errores');
    // guardar
    $uid = Panel::user('admin')['id'];
    $new = $post(['owner' => ['cdl_standard' => '25', 'trucks' => [2 => ['points' => '20']]], 'temp' => ['hot_min' => '75']]);
    $r = ScoringConfig::fromPost($new);
    T::eq(ScoringConfig::save(ScoringConfig::toSettings($r['form']), $uid), 2, 'puntuacion: guardar cambia 2 grupos (owner y umbrales)');
    $sc = Scoring::settings();
    T::ok($sc['scoring.owner']['cdl_standard'] === 25 && $sc['scoring.owner']['trucks'][2]['points'] === 20 && $sc['scoring.temperature']['hot_min'] === 75 && $sc['scoring.owner']['complete_fields'] === Scoring::DEFAULTS['scoring.owner']['complete_fields'], '  los valores quedan en settings y se conserva complete_fields');
    $alog = Db::all("SELECT entity_id, changes FROM activity_log WHERE action = 'settings_change' ORDER BY id");
    $diffs = array_map(static fn($l) => json_decode($l['changes'], true)['diff'], $alog);
    T::ok(count($alog) === 2 && $diffs[0]['cdl_standard'] === [20, 25] && $diffs[0]['trucks.2.points'] === [15, 20] && $diffs[1]['hot_min'] === [70, 75], '  activity_log guarda solo lo que cambio, con valor anterior y nuevo');
    T::eq(ScoringConfig::save(ScoringConfig::toSettings($r['form']), $uid), 0, '  guardar lo mismo no registra nada');
    T::ok(ScoringConfig::reset('owner', $uid)['ok'] && Scoring::settings()['scoring.owner'] === Scoring::DEFAULTS['scoring.owner'] && Scoring::settings()['scoring.temperature']['hot_min'] === 75, 'restablecer «owner»: vuelve a los valores del documento y no toca los umbrales');
    ScoringConfig::reset('all', $uid);
    T::ok(Scoring::settings()['scoring.temperature'] === Scoring::DEFAULTS['scoring.temperature'] && !ScoringConfig::reset('zzz', $uid)['ok'], 'restablecer «todo» y un grupo inexistente se rechaza');
    // provisionales
    $pv = ScoringConfig::validateProvisional(['dispatched_equipment' => ['reefer', 'dry-van'], 'hiring_states' => 'fl, tx;GA', 'driver_min_years' => '2', 'confirmed' => ['driver_min_years']]);
    T::ok($pv['ok'] && $pv['values']['hiring_states'] === ['FL', 'TX', 'GA'] && $pv['values']['provisional'] === ['dispatched_equipment', 'hiring_states', 'scoring.driver.experience'], 'provisionales: valida, normaliza estados y calcula lo que sigue pendiente');
    foreach ([['hiring_states' => 'ZZ'], ['driver_min_years' => '11'], ['driver_min_years' => 'x'], ['dispatched_equipment' => ['cohete']]] as $b) {
        T::ok(!ScoringConfig::validateProvisional($b + ['driver_min_years' => '1', 'hiring_states' => '', 'dispatched_equipment' => []])['ok'], 'provisionales: rechaza ' . json_encode($b));
    }
    T::eq(ScoringConfig::saveProvisional($pv['values'], $uid), 4, 'provisionales: guardar registra los 4 cambios');
    T::ok(Scoring::settings()['hiring_states'] === ['FL', 'TX', 'GA'] && ScoringConfig::provisionalList() === ['dispatched_equipment', 'hiring_states', 'scoring.driver.experience'], '  estados guardados y «driver_min_years» ya no es provisional');
    T::ok(count(ScoringConfig::history()) >= 3, '  el historial de cambios los muestra');
    $wipe();

    // ------------------------------------------------------------------ Vista previa y recalculo en lotes
    T::reset();
    $ids = [];
    for ($i = 0; $i < 450; $i++) {
        // 'warm' guardado con 50, pero los datos dan: CDL estandar + ingles si + 6 camiones + dry van + 5 anos = 20+15+10+15+5 = 65 (tibio)
        $ids[] = $mk(['score' => 50, 'temperature' => 'warm', 'cdl_type' => 'standard', 'english_level' => 'yes', 'truck_count' => 6, 'years_experience' => 5, 'equipment_types' => ['dry-van']]);
    }
    $before = Db::all('SELECT temperature, COUNT(*) n FROM leads GROUP BY temperature');
    $p = ScoringConfig::preview(Scoring::settings());
    T::ok($p['total'] === 450 && $p['changed'] === 450 && $p['before']['warm'] === 450 && $p['after']['warm'] === 450 && $p['to_hot'] === 0, 'vista previa con valores guardados: 450 leads, todos cambian de puntuacion (50 -> 65) pero siguen tibios');
    $hotSet = ScoringConfig::toSettings(ScoringConfig::fromPost($post(['temp' => ['hot_min' => '60']]))['form']);
    $p = ScoringConfig::preview(array_replace(Scoring::settings(), $hotSet));
    T::ok($p['after']['hot'] === 450 && $p['to_hot'] === 450 && $p['before']['hot'] === 0, 'vista previa con caliente desde 60: 450 pasarian a caliente (antes 0), sin escribir nada');
    T::eq(Db::value("SELECT COUNT(*) FROM leads WHERE temperature = 'hot'"), 0, '  la vista previa no modifica la base');
    ScoringConfig::save($hotSet, $uid);
    $r = ScoringConfig::startRecalc($uid);
    T::ok($r['ok'] && ScoringConfig::running() && !ScoringConfig::startRecalc($uid)['ok'], 'recalculo: arranca y no deja lanzar otro mientras corre');
    T::eq(count($jobs('rescore_batch')), 1, '  corre por la cola (un job rescore_batch)');
    $st = Queue::runBatch(JobHandlers::all(), 20, 20);
    $state = ScoringConfig::state();
    T::ok($state['status'] === 'done' && $state['processed'] === 450 && $state['changed'] === 450 && $state['before']['warm'] === 450 && $state['after']['hot'] === 450, 'recalculo en lotes (200 + 200 + 50): termina, procesa 450, antes 450 tibios y despues 450 calientes');
    T::eq(count($jobs('rescore_batch')), 3, '  encadeno 3 jobs de 200, 200 y 50');
    T::ok((int) Db::value("SELECT COUNT(*) FROM lead_events WHERE type = 'scored' AND JSON_EXTRACT(payload, '$.reason') = 'config_changed'") === 450 && (int) Db::value('SELECT score FROM leads WHERE id = ?', [$ids[0]]) === 65, '  cada lead deja el evento «scored» y su puntuacion queda al dia');
    T::eq([count($jobs('telegram_alert')), count($jobs('email_notify'))], [0, 0], '  el recalculo NO encola alertas (ni Telegram ni correo)');
    T::ok(!ScoringConfig::running() && ScoringConfig::startRecalc($uid)['ok'], '  terminado, se puede lanzar otro');
    Queue::runBatch(JobHandlers::all(), 20, 20);
    T::eq(ScoringConfig::state()['changed'], 0, '  un segundo recalculo sin cambios no modifica nada');
    $wipe();
    T::reset();

    // ------------------------------------------------------------------ Costos y CPL
    foreach ([['source' => 'zzz', 'period' => '2026-11', 'amount' => '10'], ['source' => 'meta', 'period' => '2026-13', 'amount' => '10'], ['source' => 'meta', 'period' => '2026-11', 'amount' => '-5'], ['source' => 'meta', 'period' => '2026-11', 'amount' => '12.345'], ['source' => 'meta', 'period' => '2026-11', 'amount' => 'abc'], ['source' => 'meta', 'period' => '2026-11', 'amount' => '2000000'], ['source' => 'meta', 'period' => '2099-01', 'amount' => '5']] as $b) {
        T::ok(!Costs::validate($b)['ok'], 'costos: rechaza ' . json_encode($b));
    }
    $vv = Costs::validate(['source' => 'meta', 'period' => Fmt::et($now)->format('Y-m'), 'amount' => '$1,250.50']);
    T::ok($vv['ok'] && $vv['values']['amount'] === '1250.50', 'costos: acepta «$1,250.50» y lo normaliza');
    $period = Fmt::et($now)->format('Y-m');
    T::eq(Dashboard::build('week', $now)['strip']['has_costs'], false, 'dashboard sin costos: «Sin costos cargados»');
    T::ok(Costs::save(['source' => 'meta', 'period' => $period, 'amount' => '400'], $uid)['ok'] && Costs::save(['source' => 'indeed', 'period' => $period, 'amount' => '100'], $uid)['ok'], 'costos: alta de meta (400) e indeed (100) del mes');
    Costs::save(['source' => 'meta', 'period' => $period, 'amount' => '500'], $uid);
    T::eq([(int) Db::value('SELECT COUNT(*) FROM channel_costs'), (float) Db::value("SELECT amount FROM channel_costs WHERE source = 'meta'")], [2, 500.0], 'costos: volver a guardar fuente+mes edita (no duplica)');
    // leads del mes: 4 de meta (1 firmado), 1 de indeed, 3 de web_careers (sin costo); 2 de meta del mes anterior
    $mkSrc = static function (string $src, array $o = []) use ($mk, $now): int {
        return $mk(['source' => $src, 'created_at' => Clock::at($now - 600)] + $o);
    };
    for ($i = 0; $i < 3; $i++) {
        $mkSrc('meta');
    }
    $sg = $mkSrc('meta', ['stage' => 'signed', 'signed_at' => Clock::now()]);
    $mkSrc('indeed');
    for ($i = 0; $i < 3; $i++) {
        $mkSrc('web_careers');
    }
    $prev = Fmt::et($now)->modify('first day of last month')->format('Y-m');
    $mk(['source' => 'meta', 'created_at' => Costs::window($prev)[0]]);
    $mk(['source' => 'meta', 'created_at' => Costs::window($prev)[0]]);
    $cm = Costs::currentMonth($now);
    T::ok($cm['amount'] === 600.0 && $cm['leads'] === 5 && $cm['signed'] === 1 && abs($cm['cpl'] - 120.0) < 0.001 && abs($cm['cps'] - 600.0) < 0.001, 'CPL del mes: (500+100) USD / 5 leads de meta+indeed = 120; costo por firmado = 600 / 1 firmado');
    T::ok(!(array_search('web_careers', array_keys(Costs::leadsBySource($period))) === false) && Costs::leadsBySource($period)['web_careers']['leads'] === 3, '  los leads de canales sin costo no entran en el CPL (solo en su fuente)');
    $strip = Dashboard::build('week', $now)['strip'];
    T::ok(abs($strip['cpl'] - 120.0) < 0.001 && abs($strip['cps'] - 600.0) < 0.001 && $strip['has_costs'], 'dashboard: muestra el mismo CPL y costo por firmado');
    $t = Costs::table();
    $meta = array_values(array_filter($t['rows'], static fn($r) => $r['source'] === 'meta'))[0];
    T::ok($meta['leads'] === 4 && abs($meta['cpl'] - 125.0) < 0.001 && $meta['signed'] === 1 && abs($meta['cps'] - 500.0) < 0.001, 'tabla de costos: meta = 500 / 4 leads = 125 por lead y 500 por firmado');
    Costs::save(['source' => 'meta', 'period' => $prev, 'amount' => '0'], $uid);
    $zero = array_values(array_filter(Costs::table()['rows'], static fn($r) => $r['period'] === $prev))[0];
    T::ok($zero['cpl'] === null && $zero['cps'] === null, 'un costo en cero no da CPL (nunca divide ni inventa)');
    $delId = (int) Db::value("SELECT id FROM channel_costs WHERE source = 'indeed'");
    T::ok(Costs::delete($delId, $uid)['ok'] && !Costs::delete($delId, $uid)['ok'] && abs(Costs::currentMonth($now)['cpl'] - 125.0) < 0.001, 'costos: borrar y recalcular el CPL sin ese canal (125)');
    T::eq((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action IN ('cost_save','cost_delete')"), 5, '  cada alta, edicion y borrado queda en activity_log');
    $wipe();

    // ------------------------------------------------------------------ Integraciones con servidores simulados
    $mockEnv = ['TELEGRAM_BOT_TOKEN' => $token, 'TELEGRAM_CHAT_ID' => '777000', 'TELEGRAM_API_BASE' => "http://127.0.0.1:$port", 'FMCSA_WEBKEY' => 'WEBKEY-ABCDEF123456', 'FMCSA_API_BASE' => "http://127.0.0.1:$port"];
    foreach ($mockEnv as $k => $v) {
        Env::set($k, $v);
    }
    @unlink($log);
    $res = Integrations::test('telegram');
    T::ok($res['ok'] && $res['level'] === 'ok' && count($reqs()) === 1 && str_contains((string) $reqs()[0]['body']['text'], 'Prueba de NorthStar'), 'probar Telegram: envia el mensaje de prueba por la API simulada');
    T::ok(isset(Integrations::last('telegram')['ok_at']), '  y queda registrado el ultimo envio correcto');
    file_put_contents($modeFile, 'fail');
    $res = Integrations::test('telegram');
    T::ok(!$res['ok'] && $res['level'] === 'bad' && str_contains($res['msg'], 'rechazo') && !str_contains($res['msg'], $token), 'probar Telegram con la API rechazando: error claro y sin el token');
    $card = array_values(array_filter(Integrations::status(), static fn($c) => $c['key'] === 'telegram'))[0];
    T::ok($card['level'] === 'bad' && $card['error'] !== '' && !str_contains(json_encode($card), $token) && $card['details'][0][1] === '•••• 3210', 'estado de Telegram: «bad» tras el fallo, token solo con los ultimos 4 caracteres');
    file_put_contents($modeFile, 'ok');
    Env::set('TELEGRAM_BOT_TOKEN', null);
    $res = Integrations::test('telegram');
    T::ok(!$res['ok'] && $res['level'] === 'warn' && str_contains($res['msg'], 'no está configurado'), 'probar Telegram sin configurar: lo explica');
    Env::set('TELEGRAM_BOT_TOKEN', $token);
    // FMCSA
    $res = Integrations::test('fmcsa', '1234567');
    T::ok($res['ok'] && $res['level'] === 'ok' && str_contains($res['msg'], 'USDOT 1234567') && str_contains($res['msg'], 'autoridad active'), 'probar FMCSA con un USDOT conocido: muestra el resultado');
    $res = Integrations::test('fmcsa', '9999999');
    T::ok($res['ok'] && $res['level'] === 'warn' && str_contains($res['msg'], 'no existe'), 'probar FMCSA con un USDOT que no existe: aviso');
    file_put_contents($modeFile, 'fail');
    $res = Integrations::test('fmcsa', '1234567');
    T::ok(!$res['ok'] && $res['level'] === 'bad' && str_contains($res['msg'], 'rechazo') && !str_contains($res['msg'], 'WEBKEY-ABCDEF123456'), 'probar FMCSA con la llave rechazada: error y sin la llave');
    file_put_contents($modeFile, 'ok');
    Env::set('FMCSA_WEBKEY', null);
    $res = Integrations::test('fmcsa');
    T::ok(!$res['ok'] && $res['level'] === 'warn' && str_contains($res['msg'], 'Sin llave'), 'probar FMCSA sin llave: explica «sin llave»');
    $fm = array_values(array_filter(Integrations::status(), static fn($c) => $c['key'] === 'fmcsa'))[0];
    T::ok($fm['level'] === 'warn' && $fm['details'][0][1] === 'sin configurar', 'estado de FMCSA sin llave: advertencia y «sin configurar»');
    Env::set('FMCSA_WEBKEY', 'WEBKEY-ABCDEF123456');
    $fm = array_values(array_filter(Integrations::status(), static fn($c) => $c['key'] === 'fmcsa'))[0];
    T::ok($fm['details'][0][1] === '•••• 3456' && !str_contains(json_encode(Integrations::status()), 'WEBKEY-ABCDEF123456'), 'estado de FMCSA: solo los ultimos 4 de la llave; ninguna tarjeta trae la llave completa');
    Env::set('FMCSA_WEBKEY', null);
    // SMTP y cron
    $res = Integrations::test('smtp');
    T::ok($res['ok'] && $res['level'] === 'warn' && str_contains($res['msg'], 'mail.log'), 'probar SMTP sin SMTP: lo dice y deja la prueba en mail.log');
    T::ok(str_contains((string) @file_get_contents(\Captacion\Services\Storage::dir('mail.log')), 'Prueba de correo'), '  y esta en storage/mail.log');
    Settings::set('cron.process_jobs', ['at' => Clock::shift(-30), 'processed' => 3, 'failed' => 0]);
    $cron = array_values(array_filter(Integrations::status(), static fn($c) => $c['key'] === 'cron'))[0];
    T::ok($cron['level'] === 'ok' && !$cron['testable'], 'cron: «Corriendo» si la ultima pasada fue hace 30 s');
    Settings::set('cron.process_jobs', ['at' => Clock::shift(-200), 'processed' => 0, 'failed' => 0]);
    $cron = array_values(array_filter(Integrations::status(), static fn($c) => $c['key'] === 'cron'))[0];
    T::ok($cron['level'] === 'bad' && str_contains($cron['headline'], '3 minutos'), 'cron: «Lleva mas de 3 minutos sin correr» a los 200 s');
    T::ok(!Integrations::test('otra')['ok'], 'probar una integracion desconocida: rechazada');
    Infra::stop('cfgmock');
    foreach (array_keys($mockEnv) as $k) {
        Env::set($k, null);
    }
    $wipe();
    Settings::clear();

    // ------------------------------------------------------------------ Horario y zona
    T::eq([Display::timezone(), Display::zoneLabel(), Display::hours()['start']], ['America/New_York', 'ET', '09:00'], 'horario: por defecto America/New_York, «ET» y 09:00');
    foreach ([['timezone' => 'Mars/Base', 'start' => '09:00', 'end' => '18:00', 'days' => ['1']], ['timezone' => 'America/Chicago', 'start' => '9am', 'end' => '18:00', 'days' => ['1']], ['timezone' => 'America/Chicago', 'start' => '18:00', 'end' => '09:00', 'days' => ['1']], ['timezone' => 'America/Chicago', 'start' => '09:00', 'end' => '18:00', 'days' => []], ['timezone' => 'America/Chicago', 'start' => '09:00', 'end' => '18:00', 'days' => ['9']]] as $b) {
        T::ok(!Display::validate($b)['ok'], 'horario: rechaza ' . json_encode($b));
    }
    $dv = Display::validate(['timezone' => 'America/Chicago', 'start' => '08:30', 'end' => '17:00', 'days' => ['2', '1', '3']]);
    T::ok($dv['ok'] && $dv['hours']['days'] === [1, 2, 3], 'horario: valida y ordena los dias');
    Settings::set('display.timezone', 'America/Chicago');
    $utc = '2026-07-01 18:00:00';
    T::eq([Fmt::et($utc)->format('H:i'), Fmt::zone(), Fmt::dateTime($utc)], ['13:00', 'CT', '1 jul, 13:00 CT'], 'zona Chicago: las horas se muestran en CT');
    T::eq(Fmt::fromEtInput('2026-07-01T13:00'), $utc, '  y las fechas escritas se leen en esa zona');
    Settings::set('display.timezone', 'America/New_York');
    T::eq(Fmt::dateTime($utc), '1 jul, 14:00 ET', 'de vuelta a Nueva York: 14:00 ET');
    $wipe();

    // ------------------------------------------------------------------ HTTP: roles, reautenticacion, CSRF y secretos
    T::reset(true);
    $admin = Panel::user('admin');
    $viewer = Panel::user('viewer');
    $short = Panel::start($ctx, 'panel-cfg', ['REAUTH_SECONDS' => '2', 'TELEGRAM_BOT_TOKEN' => $token, 'TELEGRAM_CHAT_ID' => '777000', 'FMCSA_WEBKEY' => 'WEBKEY-ABCDEF123456', 'SMTP_PASS' => 'SECRETO-SMTP-9999']);
    $anon = $short->another();
    foreach (['/config', '/config?tab=alertas'] as $pth) {
        T::eq($anon->get($pth)['status'], 303, "sin sesion: $pth redirige a /login");
    }
    T::eq($anon->post('/config/alerts', ['hot_telegram' => '1'])['status'], 303, 'sin sesion: guardar alertas redirige a /login');
    T::ok($short->login($admin['email']), 'login del administrador');
    $v = $short->another();
    T::ok($v->login($viewer['email']), 'login del lector');
    foreach (['', '?tab=alertas', '?tab=costos', '?tab=integraciones', '?tab=horario'] as $q) {
        T::eq($v->get('/config' . $q)['status'], 403, "lector: /config$q responde 403");
    }
    foreach (['/config/alerts', '/config/scoring', '/config/costs/save', '/config/display', '/config/integrations/test', '/config/unlock', '/config/scoring/recalc', '/config/provisional'] as $pth) {
        $r = $v->post($pth, ['_csrf' => $v->csrf, 'name' => 'telegram']);
        T::ok($r['status'] === 403, "lector: POST $pth responde 403");
    }
    T::ok(!str_contains($v->get('/leads')['body'], 'href="/config"'), 'el lector no ve «Configuración» en la barra lateral');
    foreach (['puntuacion' => '', 'alertas' => '?tab=alertas', 'costos' => '?tab=costos', 'integraciones' => '?tab=integraciones', 'horario' => '?tab=horario'] as $name => $q) {
        $pg = $short->get('/config' . $q);
        T::ok($pg['status'] === 200 && !preg_match('/\sstyle="/i', $pg['body']) && !preg_match('/\son\w+="/i', $pg['body']) && preg_match('/<script(?![^>]*\bnonce=")/i', $pg['body']) === 0 && str_contains($pg['body'], '/assets/config.css'), "pestana $name: 200, sin estilos ni handlers en linea, scripts con nonce");
        T::ok(!str_contains($pg['body'], 'WEBKEY-ABCDEF123456') && !str_contains($pg['body'], $token) && !str_contains($pg['body'], 'SECRETO-SMTP-9999'), "pestana $name: ninguna llave, token ni contrasena en el HTML");
    }
    $al = $short->get('/config?tab=alertas');
    T::ok(str_contains($al['body'], 'Edición bloqueada') && str_contains($al['body'], '<fieldset class="cfg-fs" disabled>'), 'sin confirmacion reciente: formularios bloqueados (fieldset disabled) y aviso');
    $before = Alerts::get();
    $r = $short->post('/config/alerts', ['_csrf' => $short->csrf, 'hot_telegram' => '1', 'followup_minutes' => '30', 'warm_telegram' => 'off', 'email' => '1']);
    T::ok($r['status'] === 303 && Alerts::get() === $before, 'guardar alertas SIN confirmar identidad: el servidor lo rechaza y no cambia nada');
    T::ok(str_contains($short->get('/config?tab=alertas')['body'], 'Confirma tu identidad'), '  y avisa que hay que confirmar');
    // unlock mal
    Settings::clear();
    Db::run('UPDATE users SET totp_last_step = NULL WHERE id = ?', [$admin['id']]);
    $r = $short->post('/config/unlock', ['_csrf' => $short->csrf, 'tab' => 'alertas', 'password' => 'mal-mal-mal-123', 'code' => Totp::code(Panel::SECRET)]);
    $flash = $short->get('/config?tab=alertas')['body'];
    T::ok($r['status'] === 303 && str_contains($flash, 'no son correctos') && str_contains($flash, 'disabled'), 'confirmar con contraseña incorrecta: rechazado y sigue bloqueado');
    $r = $short->post('/config/unlock', ['_csrf' => $short->csrf, 'tab' => 'alertas', 'password' => Panel::PASSWORD, 'code' => '000000']);
    T::ok(str_contains($short->get('/config?tab=alertas')['body'], 'no son correctos'), 'confirmar con codigo 2FA incorrecto: rechazado');
    $r = $short->post('/config/unlock', ['_csrf' => 'mal', 'tab' => 'alertas', 'password' => Panel::PASSWORD, 'code' => Totp::code(Panel::SECRET)]);
    T::ok(str_contains($short->get('/config?tab=alertas')['body'], 'caducó') || str_contains($short->get('/config?tab=alertas')['body'], 'bloqueada'), 'confirmar sin CSRF valido: no desbloquea');
    T::eq((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'reauth_failed'"), 2, 'los intentos fallidos de confirmar quedan en activity_log (reauth_failed)');
    // unlock bien
    Db::run('UPDATE users SET totp_last_step = NULL WHERE id = ?', [$admin['id']]);
    $r = $short->post('/config/unlock', ['_csrf' => $short->csrf, 'tab' => 'alertas', 'password' => Panel::PASSWORD, 'code' => Totp::code(Panel::SECRET)]);
    $al = $short->get('/config?tab=alertas');
    T::ok($r['status'] === 303 && str_contains($al['body'], 'Edición habilitada') && !str_contains($al['body'], '<fieldset class="cfg-fs" disabled>'), 'confirmar identidad correctamente: edicion habilitada y formularios activos');
    $r = $short->post('/config/alerts', ['_csrf' => $short->csrf, 'hot_telegram' => '1', 'followup_minutes' => '30', 'warm_telegram' => 'off', 'email' => '1', 'mail_to' => 'ops@example.com']);
    $now2 = Alerts::get();
    T::ok($r['status'] === 303 && $now2['followup_minutes'] === 30 && $now2['warm_telegram'] === 'off' && $now2['followup_telegram'] === false && $now2['mail_to'] === 'ops@example.com', 'guardar alertas con confirmacion vigente: cambia');
    T::eq((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'settings_change' AND entity_id = 'alerts'"), 1, '  y queda en activity_log con valores anterior y nuevo');
    T::ok(str_contains((string) Db::value("SELECT changes FROM activity_log WHERE action = 'settings_change' AND entity_id = 'alerts'"), '"followup_minutes":[15,30]'), '  (followup_minutes: 15 -> 30)');
    $r = $short->post('/config/alerts', ['_csrf' => $short->csrf, 'followup_minutes' => '3', 'warm_telegram' => 'auto']);
    T::ok($r['status'] === 422 && str_contains($r['body'], 'entre 5 y 120') && Alerts::get()['followup_minutes'] === 30, 'alertas con un valor invalido: 422, mensaje y no guarda');
    $r = $short->post('/config/alerts', ['followup_minutes' => '20', 'warm_telegram' => 'on']);
    T::ok($r['status'] === 303 && Alerts::get()['followup_minutes'] === 30, 'guardar alertas sin CSRF: no cambia');
    // el resto de guardados con la confirmacion vigente
    $r = $short->post('/config/costs/save', ['_csrf' => $short->csrf, 'source' => 'meta', 'period' => Fmt::et(Clock::time())->format('Y-m'), 'amount' => '321.50']);
    T::ok($r['status'] === 303 && (float) Db::value("SELECT amount FROM channel_costs WHERE source = 'meta'") === 321.5, 'guardar costo por HTTP');
    $r = $short->post('/config/costs/save', ['_csrf' => $short->csrf, 'source' => 'meta', 'period' => 'xx', 'amount' => '-1']);
    T::ok($r['status'] === 422 && str_contains($r['body'], 'Elige un mes válido') && str_contains($r['body'], 'Escribe el monto'), 'costo invalido por HTTP: 422 con los dos errores');
    $cid = (int) Db::value("SELECT id FROM channel_costs LIMIT 1");
    $r = $short->post('/config/costs/delete', ['_csrf' => $short->csrf, 'id' => (string) $cid]);
    T::ok($r['status'] === 303 && (int) Db::value('SELECT COUNT(*) FROM channel_costs') === 0, 'borrar costo por HTTP');
    $r = $short->post('/config/display', ['_csrf' => $short->csrf, 'timezone' => 'America/Denver', 'start' => '08:00', 'end' => '16:00', 'days' => ['1', '2', '3']]);
    Settings::clear();
    T::ok($r['status'] === 303 && Display::timezone() === 'America/Denver' && Display::hours()['end'] === '16:00', 'guardar horario por HTTP');
    $short->post('/config/display', ['_csrf' => $short->csrf, 'timezone' => 'America/New_York', 'start' => '09:00', 'end' => '18:00', 'days' => ['1', '2', '3', '4', '5']]);
    $pc = ScoringConfig::current();
    $form = ['_csrf' => $short->csrf, 'owner' => array_map('strval', array_intersect_key($pc['owner'], ScoringConfig::OWNER_POINTS) + ['experience_min_years' => $pc['owner']['experience_min_years']]), 'driver' => array_map('strval', array_intersect_key($pc['driver'], ScoringConfig::DRIVER_POINTS)), 'temp' => array_map('strval', $pc['temp'])];
    foreach (['owner' => ['authority_age', 'trucks'], 'driver' => ['experience']] as $g => $tks) {
        foreach ($tks as $tk) {
            foreach ($pc[$g][$tk] as $i => $t) {
                $form[$g][$tk][$i] = ['lt' => $t['lt'] === null ? '' : (string) $t['lt'], 'points' => (string) $t['points']];
            }
        }
    }
    $bad = $form;
    $bad['owner']['cdl_standard'] = '-3';
    $r = $short->post('/config/scoring', $bad);
    T::ok($r['status'] === 422 && str_contains($r['body'], 'aria-invalid="true"') && str_contains($r['body'], 'value="-3"'), 'puntuacion invalida por HTTP: 422, campo marcado y valor conservado');
    $good = $form;
    $good['owner']['cdl_standard'] = '22';
    $r = $short->post('/config/scoring/preview', $good);
    T::ok($r['status'] === 200 && str_contains($r['body'], 'VISTA PREVIA') && Scoring::settings()['scoring.owner']['cdl_standard'] === 20, 'vista previa por HTTP: muestra el efecto y no guarda');
    $r = $short->post('/config/scoring', $good);
    Settings::clear();
    T::ok($r['status'] === 303 && Scoring::settings()['scoring.owner']['cdl_standard'] === 22, 'guardar puntuacion por HTTP');
    $r = $short->post('/config/scoring/recalc', ['_csrf' => $short->csrf]);
    T::ok(!ScoringConfig::running(), 'recalcular sin marcar la confirmacion: no arranca');
    $r = $short->post('/config/scoring/recalc', ['_csrf' => $short->csrf, 'confirm' => '1']);
    T::ok(ScoringConfig::running() && (int) Db::value("SELECT COUNT(*) FROM jobs WHERE type = 'rescore_batch'") === 1, 'recalcular confirmado por HTTP: arranca el job');
    $r = $short->post('/config/scoring/reset', ['_csrf' => $short->csrf, 'group' => 'all']);
    Settings::clear();
    T::ok(Scoring::settings()['scoring.owner']['cdl_standard'] === 20, 'restablecer por HTTP vuelve al valor del documento');
    $r = $short->post('/config/provisional', ['_csrf' => $short->csrf, 'dispatched_equipment' => ['reefer'], 'hiring_states' => 'ZZ', 'driver_min_years' => '1']);
    T::ok($r['status'] === 422 && str_contains($r['body'], 'No reconozco'), 'provisionales invalidos por HTTP: 422');
    // la confirmacion caduca (REAUTH_SECONDS=2)
    sleep(3);
    $r = $short->post('/config/alerts', ['_csrf' => $short->csrf, 'hot_telegram' => '1', 'followup_minutes' => '45', 'warm_telegram' => 'auto', 'email' => '1']);
    T::ok($r['status'] === 303 && Alerts::get()['followup_minutes'] === 30, 'la confirmacion caduca (2 s en esta prueba): guardar despues se rechaza');
    // bloquear a mano
    Db::run('UPDATE users SET totp_last_step = NULL WHERE id = ?', [$admin['id']]);
    $short->post('/config/unlock', ['_csrf' => $short->csrf, 'tab' => 'alertas', 'password' => Panel::PASSWORD, 'code' => Totp::code(Panel::SECRET)]);
    $short->post('/config/lock', ['_csrf' => $short->csrf, 'tab' => 'alertas']);
    $short->post('/config/alerts', ['_csrf' => $short->csrf, 'followup_minutes' => '50', 'warm_telegram' => 'auto']);
    T::eq(Alerts::get()['followup_minutes'], 30, '«Bloquear ahora»: despues no se puede guardar');
    // integraciones por HTTP (no exigen confirmar)
    $r = $short->post('/config/integrations/test', ['_csrf' => $short->csrf, 'name' => 'smtp']);
    $pg = $short->get('/config?tab=integraciones');
    T::ok($r['status'] === 303 && str_contains($pg['body'], 'Prueba ·') && str_contains($pg['body'], 'mail.log'), 'probar SMTP por HTTP: muestra el resultado en la tarjeta');
    T::ok(!str_contains($short->get('/config?tab=integraciones')['body'], 'Prueba ·'), '  y el resultado se muestra una sola vez');
    T::eq($short->post('/config/integrations/test', ['_csrf' => $short->csrf, 'name' => 'cron'])['status'], 404, 'probar «cron» o una integracion inventada: 404');
    T::eq((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'integration_test'"), 1, '  la prueba queda en activity_log');
    $limited = 0;
    for ($i = 0; $i < 9; $i++) {
        $rr = $short->post('/config/integrations/test', ['_csrf' => $short->csrf, 'name' => 'smtp']);
        $limited += str_contains($short->get('/config?tab=integraciones')['body'], 'Demasiadas pruebas') ? 1 : 0;
    }
    T::ok($limited >= 1, 'probar integraciones: limite de uso por usuario (' . \Captacion\Controllers\ConfigController::TEST_LIMIT . '/min)');
    T::eq($short->get('/config/inventada')['status'], 404, 'ruta de configuracion inventada: 404');
    // bloqueo por intentos: 5 fallos y ni la contraseña correcta entra
    $ex = Panel::user('admin');
    $ep = $short->another();
    T::ok($ep->login($ex['email']), 'segundo administrador para la prueba de bloqueo');
    for ($i = 0; $i < 5; $i++) {
        $ep->post('/config/unlock', ['_csrf' => $ep->csrf, 'tab' => 'alertas', 'password' => 'incorrecta-' . $i, 'code' => '123456']);
    }
    Db::run('UPDATE users SET totp_last_step = NULL WHERE id = ?', [$ex['id']]);
    $ep->post('/config/unlock', ['_csrf' => $ep->csrf, 'tab' => 'alertas', 'password' => Panel::PASSWORD, 'code' => Totp::code(Panel::SECRET)]);
    T::ok(str_contains($ep->get('/config?tab=alertas')['body'], 'Demasiados intentos') && (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'reauth_blocked'") >= 1, 'confirmar tras 5 fallos: bloqueado 15 min (aunque ahora sea correcto) y registrado');
    Infra::stop('panel-cfg');
    $wipe();
    T::reset(true);
};
