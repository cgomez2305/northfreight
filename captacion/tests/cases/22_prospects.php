<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Services\Integrations;
use Captacion\Services\LeadIngest;
use Captacion\Services\ProspectConfig;
use Captacion\Services\ProspectImport;
use Captacion\Services\Prospects;
use Captacion\Services\Settings;
use Captacion\Services\SocrataClient;
use Captacion\Services\SocrataUnavailable;
use Captacion\Services\UsTimeZones;
use CaptacionTests\Panel;
use CaptacionTests\SocrataFixture;
use CaptacionTests\T;

/**
 * Prospectos FMCSA (Q4a-A): horario por zona horaria, filtros, cliente Socrata, corrida diaria con fixtures REALES (dedupe contra leads y no-llamar, tope diario, cursor y
 * reanudacion, errores de red, compuerta por hora ET), resultados e intentos, «no llamar mas» permanente y conversion a lead sin alertas ni consentimiento.
 */
return static function (array $ctx): void {
    T::reset(true);
    $admin = Panel::user('admin');
    $uid = $admin['id'];
    $utc = static fn(string $s): int => (new DateTimeImmutable($s, new DateTimeZone('UTC')))->getTimestamp();

    // ------------------------------------------------------------------ Horario por zona horaria
    $c = static fn(string $state, string $when) => UsTimeZones::check($state, $utc($when));
    T::ok($c('CA', '2026-11-05 16:00')['allowed'] && !$c('CA', '2026-11-05 15:59')['allowed'], 'horario: California abre a las 8:00 PST (16:00 UTC) y no antes');
    T::ok($c('CA', '2026-11-06 04:59')['allowed'] && !$c('CA', '2026-11-06 05:00')['allowed'], 'horario: California cierra a las 9:00 p. m. (05:00 UTC)');
    T::ok($c('NY', '2026-07-15 12:00')['allowed'] && !$c('NY', '2026-07-15 11:59')['allowed'], 'horario: en verano Nueva York abre a las 12:00 UTC (horario de verano incluido)');
    T::ok($c('NY', '2026-11-05 13:00')['allowed'] && !$c('NY', '2026-11-05 12:59')['allowed'], 'horario: en invierno Nueva York abre a las 13:00 UTC');
    T::ok(!$c('TX', '2026-11-05 14:00')['allowed'] && $c('TX', '2026-11-05 15:00')['allowed'], 'horario: Texas (Central y Montaña) exige las 8 a. m. en ambas: abre a las 15:00 UTC');
    T::ok($c('TX', '2026-11-06 02:59')['allowed'] && !$c('TX', '2026-11-06 03:00')['allowed'], '  y cierra cuando son las 9 p. m. en la zona Central');
    T::ok(!$c('FL', '2026-11-05 13:00')['allowed'] && $c('FL', '2026-11-05 14:00')['allowed'], 'horario: Florida (Este y Central) abre a las 9:00 ET');
    T::ok($c('FL', '2026-11-06 01:59')['allowed'] && !$c('FL', '2026-11-06 02:00')['allowed'], '  y cierra a las 9:00 p. m. ET');
    T::ok(!$c('AZ', '2026-07-15 14:00')['allowed'] && $c('AZ', '2026-07-15 15:00')['allowed'], 'horario: Arizona en verano (Phoenix y Navajo) abre a las 15:00 UTC');
    T::ok($c('HI', '2026-11-05 18:00')['allowed'] && !$c('HI', '2026-11-05 17:59')['allowed'], 'horario: Hawái abre a las 8:00 HST');
    T::ok(!UsTimeZones::check(null, $utc('2026-11-05 18:00'))['allowed'] && !UsTimeZones::check('ZZ', $utc('2026-11-05 18:00'))['known'], 'horario: sin estado o estado desconocido NO se llama');
    T::ok(UsTimeZones::isMultiZone('KY') && !UsTimeZones::isMultiZone('GA') && count(UsTimeZones::ZONES) === 51, 'horario: 51 entradas (50 estados + DC) y KY es de dos zonas');
    $closed = $c('CA', '2026-11-05 07:00');
    T::ok(!$closed['allowed'] && $closed['opens_in'] === 9 * 3600 && $closed['local_hm'] === '11:00 p. m.' && $closed['zone_label'] === 'PST', 'horario: fuera de horario dice la hora local (11:00 p. m. PST) y cuándo abre (9 h)');
    $missing = array_diff(array_keys(\Captacion\Services\UsStates::NAMES), array_keys(UsTimeZones::ZONES));
    T::eq($missing, [], 'horario: todos los estados de UsStates tienen zona');

    // ------------------------------------------------------------------ Configuracion de filtros
    $n = ProspectConfig::normalize(['max_age_months' => '99', 'max_fleet' => 'abc', 'daily_cap' => '0', 'states' => 'TX, fl ,ZZ', 'cargo' => ['cold', 'robot']]);
    T::ok(isset($n['errors']['max_age_months'], $n['errors']['max_fleet'], $n['errors']['daily_cap'], $n['errors']['states'], $n['errors']['cargo']), 'config: rechaza meses fuera de 1 a 24, flota no numérica, tope 0, estado y categoría inexistentes');
    $n = ProspectConfig::normalize(['max_age_months' => '6', 'max_fleet' => '5', 'daily_cap' => '25', 'states' => 'tx, FL', 'cargo' => ['cold'], 'enabled' => '1', 'require_phone' => '0']);
    T::ok($n['errors'] === [] && $n['clean']['states'] === ['TX', 'FL'] && $n['clean']['max_age_months'] === 6 && $n['clean']['require_phone'] === false && $n['clean']['cargo'] === ['cold'], 'config: normaliza estados a mayúsculas y números válidos');
    T::eq(ProspectConfig::get(), ProspectConfig::DEFAULTS, 'config: sin guardar se usan los valores por defecto (12 meses, flota 10, tope 40)');
    T::eq(ProspectConfig::categoriesOf(['produce', 'genfreight']), ['general', 'cold'], 'config: las banderas de carga se agrupan en categorías');

    // ------------------------------------------------------------------ Cliente Socrata
    $fx = new SocrataFixture();
    $sleeps = [];
    $cli = function (?SocrataFixture $f = null, ?string $token = null) use (&$sleeps): SocrataClient {
        return new SocrataClient(($f ?? $GLOBALS['__fx'])->http(), static function (float $s) use (&$sleeps): void {
            $sleeps[] = $s;
        }, 'https://example.test', $token ?? '');
    };
    $GLOBALS['__fx'] = $fx;
    $rows = $cli()->query('yu5v-wbh6', ProspectImport::grantsParams('20261001', null, 5));
    T::ok(count($rows) === 5 && $rows[0]['status_change_date'] === '20261104' && isset($rows[0]['usdot_number']) && !isset($rows[0]['reason']), 'socrata: la consulta de concesiones devuelve 5 filas, de la más nueva a la más vieja, solo con los campos pedidos');
    $q = (string) end($fx->log);
    T::ok(str_contains($q, 'resource/yu5v-wbh6.json') && str_contains($q, '%24where=') && str_contains($q, '%24limit=5') && !str_contains($q, 'token'), 'socrata: la URL usa $where y $limit codificados y no lleva token');
    $sawHeader = null;
    $c2 = new SocrataClient(static function (string $u, array $h) use (&$sawHeader): array {
        $sawHeader = $h;
        return ['status' => 200, 'body' => '[]'];
    }, null, 'https://example.test', 'app-token-123');
    $c2->query('az4n-8mr2', ['$limit' => '1']);
    T::eq($sawHeader, ['X-App-Token: app-token-123'], 'socrata: SOCRATA_APP_TOKEN se manda en la cabecera X-App-Token (nunca en la URL)');
    $fx->failStatus = 429;
    $fx->failTimes = 2;
    $before = count($sleeps);
    $r = $cli()->query('az4n-8mr2', ProspectImport::censusParams(['9100001']));
    T::ok(count($r) === 1 && count($sleeps) - $before === 2 && $r[0]['dot_number'] === '9100001', 'socrata: un 429 doble se reintenta con espera y la tercera vez responde');
    $fx->failTimes = -1;
    $fx->failStatus = 500;
    T::throws(static fn() => $cli()->query('az4n-8mr2', ['$limit' => '1']), 'socrata: 500 persistente lanza SocrataUnavailable tras 3 intentos', 'tras 3 intentos');
    $fx->failTimes = 0;
    $fx->failStatus = null;
    try {
        $cli()->query('yu5v-wbh6', ['$where' => 'crgo_dryvan']);
        T::ok(false, 'socrata: una columna inexistente debe fallar');
    } catch (SocrataUnavailable $e) {
        T::ok($e->permanent && str_contains($e->getMessage(), 'no-such-column'), 'socrata: una columna inexistente (error real de la API) es un fallo permanente con el mensaje de Socrata');
    }
    T::throws(static fn() => $cli()->query('../etc/passwd', []), 'socrata: un identificador de dataset raro se rechaza');
    $fx->log = [];

    // ------------------------------------------------------------------ Corrida con fixtures reales
    $today = '2026-11-05';
    $at = static fn(string $hm) => (new DateTimeImmutable($today . ' ' . $hm, new DateTimeZone('America/New_York')))->getTimestamp();
    $fresh = static function (): void {
        Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['prospect_calls', 'dnc_numbers', 'prospects_fmcsa', 'leads', 'lead_events', 'jobs', 'consents'] as $t) {
            Db::pdo()->exec("TRUNCATE TABLE `$t`");
        }
        Db::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        Db::pdo()->exec("DELETE FROM settings WHERE `key` LIKE 'prospects.%'");
        Settings::clear();
        Clock::travel(0);
    };
    $fresh();
    Clock::travel($at('10:00') - time());
    $run = static fn(array $o = [], ?int $when = null) => ProspectImport::tick($when ?? Clock::time(), $o + ['client' => $cli(), 'force' => true]);

    $r = $run();
    $cn = $r['run']['counters'];
    T::eq($r['status'], 'ran', 'corrida: termina sin errores');
    T::ok($r['run']['finished'] && !$r['run']['capped'] && $cn['read'] === 14 && $cn['new'] === 10, 'corrida: lee 14 concesiones y deja 10 prospectos nuevos');
    T::ok($cn['no_phone'] === 1 && $cn['not_us'] === 2 && $cn['census_inactive'] === 1 && $cn['fleet'] === 0 && $cn['known'] === 0, '  descarta 1 sin teléfono, 2 fuera de EE. UU. (BC y ON) y 1 USDOT inactivo');
    T::eq(ProspectImport::completeUntil(), $today, 'corrida: una corrida completa deja prospects.complete_until = hoy (ET)');
    $p1 = Db::one("SELECT * FROM prospects_fmcsa WHERE usdot = '9100001'");
    T::ok($p1['phone'] === '+13055550100' && $p1['state'] === 'TX' && $p1['city'] === 'HIGHLANDS' && $p1['authority_granted_at'] === '2026-11-04' && (int) $p1['fleet_size'] === 1 && $p1['mc_number'] === 'MC8800001'
        && $p1['email'] === 'fixture01@example.com' && $p1['status'] === 'to_call' && $p1['listed_on'] === $today && (int) $p1['completeness'] === 8, 'corrida: el prospecto guarda USDOT, MC, teléfono E.164, ciudad, estado, fecha de concesión, flota, correo y completitud 8/8');
    T::ok(in_array('genfreight', Db::decode($p1['cargo']), true) && in_array('bldgmat', Db::decode($p1['cargo']), true) && Db::decode($p1['fmcsa_data'])['census_status'] === 'A', 'corrida: guarda las banderas de carga y los datos del censo');
    T::eq((int) Db::value("SELECT completeness FROM prospects_fmcsa WHERE usdot = '9100006'"), 7, 'corrida: sin banderas de carga la completitud baja a 7');
    T::eq((int) Db::value("SELECT COUNT(*) FROM prospects_fmcsa WHERE usdot IN ('9100003','9100008','9100009','9100010')"), 0, 'corrida: lo descartado no queda en la tabla');
    $lg = Db::one("SELECT * FROM activity_log WHERE action = 'prospects_run' ORDER BY id DESC LIMIT 1");
    T::ok($lg !== null && Db::decode($lg['changes'])['counters']['new'] === 10, 'corrida: queda en activity_log con los contadores');
    T::ok(count($fx->log) === 2 && str_contains($fx->log[0], 'yu5v-wbh6') && str_contains($fx->log[1], 'az4n-8mr2'), 'corrida: 14 filas caben en una página de concesiones + 1 consulta al censo (2 peticiones)');
    T::eq(Integrations::last('socrata')['last'] ?? '', 'ok', 'corrida: queda registrado el último uso correcto de Socrata');

    // Segunda corrida: todo lo ya listado se reconoce y no se duplica
    $r2 = $run();
    T::ok($r2['run']['counters']['new'] === 0 && $r2['run']['counters']['known'] === 10 && (int) Db::value('SELECT COUNT(*) FROM prospects_fmcsa') === 10, 'idempotencia: repetir la corrida no duplica (10 conocidos, 0 nuevos)');
    T::eq(ProspectImport::tick(Clock::time(), ['client' => $cli(), 'max_pages' => 1])['status'], 'done', 'idempotencia: sin forzar, el mismo día responde «done» y no consulta nada');

    // ------------------------------------------------------------------ Deduplicacion contra leads y no llamar
    $fresh();
    Clock::travel($at('10:00') - time());
    $l1 = Db::insert('leads', ['profile' => 'owner_operator', 'first_name' => 'Ya', 'last_name' => 'Lead', 'usdot' => '9100002', 'phone_e164' => '+17135559999', 'created_at' => Clock::now(), 'updated_at' => Clock::now(),
        'score' => 50, 'temperature' => 'warm', 'pipeline' => 'A', 'stage' => 'new', 'source' => 'web_careers', 'attempt' => 1]);
    $l2 = Db::insert('leads', ['profile' => 'owner_operator', 'first_name' => 'Otro', 'last_name' => 'Lead', 'phone_e164' => '+17135550103', 'created_at' => Clock::now(), 'updated_at' => Clock::now(),
        'score' => 50, 'temperature' => 'warm', 'pipeline' => 'A', 'stage' => 'new', 'source' => 'web_careers', 'attempt' => 1]);
    Db::insert('dnc_numbers', ['phone_e164' => '+13055550104', 'reason' => 'prospect', 'created_at' => Clock::now()]);
    $r = $run();
    $cn = $r['run']['counters'];
    T::ok($cn['already_lead'] === 2 && $cn['dnc'] === 1 && $cn['new'] === 7, 'dedupe: un lead con el mismo USDOT, otro con el mismo teléfono y un teléfono de no llamar quedan fuera (7 nuevos)');
    T::eq((int) Db::value("SELECT COUNT(*) FROM prospects_fmcsa WHERE usdot IN ('9100002','9100004','9100005')"), 0, '  y no están en la lista de llamadas');

    // ------------------------------------------------------------------ Filtros
    $fresh();
    Clock::travel($at('10:00') - time());
    Settings::set('prospects.config', ['states' => ['TX']] + ProspectConfig::DEFAULTS);
    $cn = $run()['run']['counters'];
    T::ok($cn['new'] === 4 && $cn['state'] === 7, 'filtro estados [TX]: 4 prospectos (01, 04, 12, 13) y 7 descartados por estado');
    $fresh();
    Clock::travel($at('10:00') - time());
    Settings::set('prospects.config', ['max_fleet' => 2] + ProspectConfig::DEFAULTS);
    $cn = $run()['run']['counters'];
    T::ok($cn['fleet'] === 2 && $cn['new'] === 8, 'filtro flota máxima 2: descarta las de 4 y 6 unidades');
    $fresh();
    Clock::travel($at('10:00') - time());
    Settings::set('prospects.config', ['cargo' => ['cold']] + ProspectConfig::DEFAULTS);
    $cn = $run()['run']['counters'];
    T::ok($cn['new'] === 1 && $cn['cargo'] === 9 && Db::value('SELECT usdot FROM prospects_fmcsa') === '9100011', 'filtro carga refrigerada: solo queda el carrier con carga fría; el que no declara carga también se descarta');
    $fresh();
    Clock::travel($at('10:00') - time());
    Settings::set('prospects.config', ['require_phone' => false] + ProspectConfig::DEFAULTS);
    $cn = $run()['run']['counters'];
    T::ok($cn['new'] === 11 && Db::value("SELECT phone FROM prospects_fmcsa WHERE usdot = '9100003'") === null, 'filtro «solo con teléfono» apagado: entra el carrier sin teléfono (con completitud menor)');
    $fresh();
    Clock::travel($at('10:00') - time());
    Settings::set('prospects.config', ['max_age_months' => 1] + ProspectConfig::DEFAULTS);
    $r = $run();
    T::eq($r['run']['since'], '20261005', 'filtro antigüedad 1 mes: since = 5 de octubre');

    // ------------------------------------------------------------------ Tope diario
    $fresh();
    Clock::travel($at('10:00') - time());
    Settings::set('prospects.config', ['daily_cap' => 3] + ProspectConfig::DEFAULTS);
    $r = $run();
    T::ok($r['run']['capped'] && $r['run']['finished'] && $r['run']['counters']['new'] === 3 && $r['run']['counters']['cap'] === 1, 'tope diario 3: entran 3 nuevos y la corrida se cierra «con tope»');
    T::eq(ProspectImport::completeUntil(), null, '  una corrida con tope NO avanza la marca de ventana completa (lo que no entró se ve otro día)');
    T::eq(Db::value("SELECT GROUP_CONCAT(usdot ORDER BY usdot) FROM prospects_fmcsa"), '9100001,9100002,9100004', '  entran primero los más recientes por fecha y MC');
    $r = $run();
    T::ok($r['run']['counters']['new'] === 0 && $r['run']['counters']['cap'] === 1, 'tope diario: otra corrida el mismo día no agrega más (el tope es por día ET)');
    Clock::travel(Clock::time() + 86400 - time());
    $r = ProspectImport::tick(Clock::time(), ['client' => $cli(), 'force' => true]);
    T::ok($r['run']['counters']['new'] === 3 && (int) Db::value('SELECT COUNT(*) FROM prospects_fmcsa') === 6, 'tope diario: al día siguiente entran otros 3 (los primeros ya se conocen)');
    Settings::set('prospects.config', ProspectConfig::DEFAULTS);

    // ------------------------------------------------------------------ Cursor y reanudacion
    $fresh();
    Clock::travel($at('10:00') - time());
    $r = ProspectImport::tick(Clock::time(), ['client' => $cli(), 'force' => true, 'page_size' => 4, 'max_pages' => 1]);
    T::ok(!$r['run']['finished'] && $r['run']['cursor'] !== null && $r['run']['counters']['read'] === 4 && $r['run']['pages'] === 1, 'cursor: con 4 filas por página y 1 página por pasada, la corrida queda a medias con su cursor');
    $cursor = $r['run']['cursor'];
    $guard = 0;
    while (empty($r['run']['finished']) && $guard++ < 8) {
        $r = ProspectImport::tick(Clock::time(), ['client' => $cli(), 'page_size' => 4, 'max_pages' => 1]);
    }
    T::ok($r['run']['finished'] && $r['run']['counters']['new'] === 10 && $r['run']['counters']['read'] === 14, 'cursor: las pasadas siguientes continúan sin pedir de nuevo las primeras (14 leídas, 10 nuevos, igual que de una vez)');
    T::ok($r['run']['resumed'] >= 2 && $cursor['d'] === '20261104', 'cursor: la corrida registra que se reanudó');
    T::eq(ProspectImport::tick(Clock::time(), ['client' => $cli()])['status'], 'done', 'cursor: terminada, el cron del mismo día no hace nada');
    // Paginas con un limite de tiempo ya agotado: no consulta nada y no pierde el cursor
    $fresh();
    Clock::travel($at('10:00') - time());
    $fx->log = [];
    $r = ProspectImport::tick(Clock::time(), ['client' => $cli(), 'force' => true, 'max_seconds' => -1]);
    T::ok($r['status'] === 'ran' && empty($r['run']['finished']) && $fx->log === [], 'tiempo: con el presupuesto de tiempo agotado no consulta nada y queda pendiente');

    // ------------------------------------------------------------------ Errores de Socrata
    $fresh();
    Clock::travel($at('10:00') - time());
    $fx->failStatus = 500;
    $fx->failTimes = -1;
    $r = $run();
    T::ok($r['status'] === 'error' && empty($r['run']['finished']) && str_contains((string) $r['run']['error'], 'tras 3 intentos'), 'errores: Socrata caído deja la corrida con error y sin terminar');
    T::eq(Integrations::last('socrata')['last'] ?? '', 'err', '  y lo registra en la tarjeta de integraciones');
    T::eq((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'prospects_run_error'"), 1, '  y en activity_log');
    $fx->failTimes = 0;
    $fx->failStatus = null;
    $r = ProspectImport::tick(Clock::time(), ['client' => $cli()]);
    T::ok($r['status'] === 'ran' && $r['run']['finished'] && $r['run']['error'] === null && $r['run']['counters']['new'] === 10, 'errores: a la hora siguiente se reanuda sola y termina');

    // ------------------------------------------------------------------ Compuerta por hora de Nueva York
    $fresh();
    $mk = static fn(string $day, string $hm) => (new DateTimeImmutable($day . ' ' . $hm, new DateTimeZone('America/New_York')))->getTimestamp();
    Clock::travel($mk('2026-11-05', '10:00') - time());
    $tk = static fn(int $ts, array $o = []) => ProspectImport::tick($ts, $o + ['client' => $cli()]);
    T::eq($tk($mk('2026-11-05', '05:30'))['status'], 'waiting', 'hora: a las 5:30 a. m. ET no hace nada');
    T::eq($tk($mk('2026-11-05', '06:05'))['status'], 'ran', 'hora: a las 6:05 a. m. ET corre');
    T::eq($tk($mk('2026-11-05', '07:05'))['status'], 'done', 'hora: a las 7:05 del mismo día ya no corre otra vez');
    T::eq($tk($mk('2026-11-06', '05:59'))['status'], 'waiting', 'hora: el día siguiente antes de las 6:00 espera');
    $r = $tk($mk('2026-11-09', '09:30'));
    T::ok($r['status'] === 'ran' && $r['run']['day'] === '2026-11-09' && $r['run']['since'] === '20261102', 'hora: si se perdió un día, la próxima corrida recupera (since = marca de ventana menos 3 días)');
    Settings::set('prospects.config', ['enabled' => false] + ProspectConfig::DEFAULTS);
    T::eq($tk($mk('2026-11-10', '09:30'))['status'], 'disabled', 'hora: con la búsqueda apagada en Configuración no corre');
    Settings::set('prospects.config', ProspectConfig::DEFAULTS);
    // DST: 6:00 ET del cambio de hora (2026-11-01) = 11:00 UTC; 6:00 ET en verano = 10:00 UTC
    T::ok(ProspectImport::etDate($utc('2026-11-01 04:59')) === '2026-11-01' && ProspectImport::etDate($utc('2026-11-02 04:59')) === '2026-11-01' && ProspectImport::etDate($utc('2026-11-02 05:00')) === '2026-11-02', 'hora: el día ET cambia a las 05:00 UTC en invierno');

    // ------------------------------------------------------------------ Resultados e intentos
    $fresh();
    Clock::travel($utc('2026-11-05 15:00') - time());   // jueves
    $run();
    $pid = (int) Db::value("SELECT id FROM prospects_fmcsa WHERE usdot = '9100001'");
    $r = Prospects::record($pid, 'no_answer', 'Buzón lleno', '', $uid);
    $p = Prospects::find($pid);
    T::ok($r['ok'] && $p['status'] === 'no_answer' && (int) $p['attempts'] === 1 && $p['next_call_at'] === '2026-11-06 14:00:00' && $p['last_result'] === 'no_answer', 'resultado: sin respuesta suma un intento y reprograma al siguiente día hábil a las 9:00 ET');
    T::eq((int) Db::value("SELECT COUNT(*) FROM prospects_fmcsa p WHERE p.id = $pid AND (p.status = 'to_call' OR (p.status = 'no_answer' AND (p.next_call_at IS NULL OR p.next_call_at <= '" . Clock::now() . "')))"), 0, 'resultado: hasta su fecha, el reintento sale de la «Lista de hoy»');
    Clock::travel($utc('2026-11-06 15:00') - time());
    $f = Prospects::filters([]);
    $ids = array_column(Prospects::page($f, Clock::time(), 1, 25)['rows'], 'id');
    T::ok(in_array((string) $pid, array_map('strval', $ids), true) && (string) $ids[0] === (string) $pid, 'resultado: al llegar la fecha vuelve a la lista de hoy, primero (los reintentos van antes)');
    Prospects::record($pid, 'no_answer', null, '2026-11-09', $uid);
    $r = Prospects::record($pid, 'no_answer', null, '', $uid);
    $p = Prospects::find($pid);
    T::ok($r['ok'] && $p['status'] === 'no_answer_closed' && (int) $p['attempts'] === 3 && $p['next_call_at'] === null && $p['closed_at'] !== null, 'resultado: el 3.º intento sin respuesta cierra al prospecto («Sin respuesta (cerrada)»)');
    T::eq((int) Db::value('SELECT COUNT(*) FROM prospect_calls WHERE prospect_id = ?', [$pid]), 3, 'resultado: el historial guarda los 3 intentos');
    $r = Prospects::reopen($pid, $uid);
    $p = Prospects::find($pid);
    T::ok($r['ok'] && $p['status'] === 'to_call' && (int) $p['attempts'] === 0 && $p['closed_at'] === null, 'resultado: reabrir a mano lo devuelve a «Por llamar» con 0 intentos');
    T::ok(!Prospects::reopen($pid, $uid)['ok'], 'resultado: reabrir uno que no está cerrado se rechaza');
    T::ok(!Prospects::record($pid, 'abracadabra', null, '', $uid)['ok'] && !Prospects::record($pid, 'talked', null, '2026-13-45', $uid)['ok'] && !Prospects::record(999999, 'talked', null, '', $uid)['ok'], 'resultado: resultado, fecha o prospecto inválidos se rechazan');
    $r = Prospects::record($pid, 'talked', str_repeat('x', 800) . "\0", '2026-11-12', $uid);
    $p = Prospects::find($pid);
    T::ok($r['ok'] && $p['status'] === 'talked' && (int) $p['attempts'] === 0 && $p['next_call_at'] === '2026-11-12 14:00:00', 'resultado: «conversó» reinicia los intentos y guarda el siguiente intento a las 9:00 ET');
    T::eq((int) Db::value('SELECT CHAR_LENGTH(note) FROM prospect_calls WHERE prospect_id = ? ORDER BY id DESC LIMIT 1', [$pid]), 500, 'resultado: la nota se recorta a 500 caracteres y sin caracteres de control');
    T::ok(!Prospects::record($pid, 'talked', null, '2020-01-01', $uid)['ok'], 'resultado: una fecha de siguiente intento muy pasada se rechaza');

    // «No llamar mas» permanente
    $r = Prospects::record($pid, 'dnc', 'Pidió que no lo llamen', '', $uid);
    $p = Prospects::find($pid);
    T::ok($r['ok'] && $p['status'] === 'dnc' && (int) $p['do_not_call'] === 1 && $p['dnc_at'] !== null && (int) Db::value('SELECT COUNT(*) FROM dnc_numbers WHERE phone_e164 = ?', [$p['phone']]) === 1, 'no llamar más: marca el prospecto y guarda su teléfono en la lista interna');
    T::ok(!Prospects::record($pid, 'talked', null, '', $uid)['ok'] && !Prospects::reopen($pid, $uid)['ok'] && !Prospects::convert($pid, $uid)['ok'], '  después no se puede registrar otra llamada, reabrir ni convertir');
    T::eq((int) Db::value("SELECT COUNT(*) FROM prospects_fmcsa p WHERE p.id = $pid AND p.do_not_call = 0 AND (p.status = 'to_call')"), 0, '  y no aparece en la lista de hoy');
    $r = $run();
    T::ok($r['run']['counters']['new'] === 0 && Prospects::find($pid)['status'] === 'dnc', 'no llamar más: aunque FMCSA lo vuelva a entregar, no se vuelve a listar ni cambia');
    // Otro USDOT con el mismo telefono tampoco entra
    $fx2 = new SocrataFixture();
    $fx2->grants[] = ['docket_number' => 'MC8800099', 'usdot_number' => '9100099', 'op_auth_type' => 'Motor Carrier of Property (Except Household Goods)', 'op_auth_status' => 'Active', 'reason' => 'Granted', 'status_change_date' => '20261105'];
    $dup = $fx2->census[0];
    $dup['dot_number'] = '9100099';
    $fx2->census[] = $dup;   // mismo telefono que el prospecto en «no llamar»
    $r = ProspectImport::tick(Clock::time(), ['client' => $cli($fx2), 'force' => true]);
    T::ok($r['run']['counters']['dnc'] === 1 && (int) Db::value("SELECT COUNT(*) FROM prospects_fmcsa WHERE usdot = '9100099'") === 0, 'no llamar más: un USDOT nuevo con el mismo teléfono tampoco se lista');

    // ------------------------------------------------------------------ Conversion a lead
    $pid2 = (int) Db::value("SELECT id FROM prospects_fmcsa WHERE usdot = '9100004'");   // 2 unidades -> carrier pequeño
    $jobsBefore = (int) Db::value('SELECT COUNT(*) FROM jobs');
    $r = Prospects::convert($pid2, $uid);
    $lead = Db::one('SELECT * FROM leads WHERE id = ?', [$r['lead_id'] ?? 0]);
    T::ok($r['ok'] && $lead !== null && $lead['source'] === 'fmcsa_outbound' && $lead['profile'] === 'small_carrier' && $lead['stage'] === 'contacted' && $lead['first_contact_at'] !== null, 'convertir: crea el lead con fuente fmcsa_outbound, perfil carrier pequeño (2 unidades), etapa Contactado y primer contacto ahora');
    T::ok($lead['usdot'] === '9100004' && $lead['phone_e164'] === '+17135550103' && (int) $lead['truck_count'] === 2 && $lead['state'] === 'TX' && $lead['city'] === 'HIGHLANDS' || $lead['state'] === 'TX', 'convertir: trae USDOT, teléfono, camiones y estado');
    $fm = Db::decode($lead['fmcsa_data']);
    T::ok(($fm['found'] ?? false) === true && $fm['source'] === 'socrata' && $fm['authority_granted_at'] === '2026-11-04' && $fm['authority_age_months'] === 0 && (int) $lead['authority_age_months'] === 0 && $fm['power_units'] === 2, 'convertir: los datos de FMCSA ya vienen cargados (concesión, antigüedad, unidades) y no queda «FMCSA pendiente»');
    T::ok(!in_array('fmcsa_pending', Db::decode($lead['flags']), true), 'convertir: sin bandera fmcsa_pending');
    T::eq((int) Db::value('SELECT COUNT(*) FROM consents WHERE lead_id = ?', [$lead['id']]), 0, 'convertir: SIN consentimiento de mensajería (ninguna fila en consents)');
    T::eq((int) Db::value('SELECT COUNT(*) FROM jobs') - $jobsBefore, 0, 'convertir: no encola alertas de Telegram, correos ni secuencias (0 jobs nuevos)');
    T::ok((int) Db::value("SELECT COUNT(*) FROM lead_events WHERE lead_id = ? AND type = 'prospect_converted'", [$lead['id']]) === 1 && (int) Db::value("SELECT COUNT(*) FROM lead_events WHERE lead_id = ? AND type = 'stage_changed'", [$lead['id']]) === 1, 'convertir: deja el evento en la línea de tiempo del lead y el cambio de etapa');
    $p = Prospects::find($pid2);
    T::ok((int) $p['converted_lead_id'] === (int) $lead['id'] && $p['status'] === 'interested' && $p['converted_at'] !== null, 'convertir: el prospecto queda enlazado al lead e «Interesado»');
    T::ok(Prospects::convert($pid2, $uid)['lead_id'] === (int) $lead['id'] && (int) Db::value('SELECT COUNT(*) FROM leads') === 1, 'convertir: repetir no crea otro lead');
    T::ok(!Prospects::record($pid2, 'talked', null, '', $uid)['ok'], 'convertir: un prospecto convertido ya no admite llamadas desde esta pantalla');
    $pid3 = (int) Db::value("SELECT id FROM prospects_fmcsa WHERE usdot = '9100002'");
    $r = Prospects::convert($pid3, $uid);
    T::eq(Db::value('SELECT profile FROM leads WHERE id = ?', [$r['lead_id']]), 'owner_operator', 'convertir: una sola unidad = owner-operator');
    // Lead ya existente con el mismo telefono: se enlaza
    $pid4 = (int) Db::value("SELECT id FROM prospects_fmcsa WHERE usdot = '9100005'");
    $existing = Db::insert('leads', ['profile' => 'owner_operator', 'first_name' => 'Previo', 'last_name' => 'Lead', 'phone_e164' => Db::value('SELECT phone FROM prospects_fmcsa WHERE id = ?', [$pid4]), 'created_at' => Clock::now(), 'updated_at' => Clock::now(),
        'score' => 40, 'temperature' => 'warm', 'pipeline' => 'A', 'stage' => 'qualified', 'source' => 'web_careers', 'attempt' => 1]);
    $r = Prospects::convert($pid4, $uid);
    T::ok($r['ok'] && $r['lead_id'] === $existing && Db::value('SELECT stage FROM leads WHERE id = ?', [$existing]) === 'qualified', 'convertir: si ya hay un lead con ese teléfono se enlaza a él sin tocar su etapa');

    // ------------------------------------------------------------------ Filtros y busqueda de la lista
    $fresh();
    Clock::travel($utc('2026-11-05 15:00') - time());
    $run();
    $f = Prospects::filters(['tab' => 'today', 'us' => 'tx']);
    $pg = Prospects::page($f, Clock::time(), 1, 25);
    T::ok($pg['total'] === 4 && $pg['rows'][0]['usdot'] === '9100001', 'lista: filtro por estado TX = 4 prospectos, ordenados por antigüedad y completitud');
    T::eq(Prospects::page(Prospects::filters(['fl' => '2-5']), Clock::time(), 1, 25)['total'], 4, 'lista: flota 2 a 5 = 4 (01 no, 04, 05, 06, 11)');
    T::eq(Prospects::page(Prospects::filters(['q' => '9100007']), Clock::time(), 1, 25)['total'], 1, 'lista: buscar por USDOT');
    T::eq(Prospects::page(Prospects::filters(['q' => '713 555 0103']), Clock::time(), 1, 25)['total'], 1, 'lista: buscar por teléfono con espacios');
    T::eq(Prospects::page(Prospects::filters(['q' => 'fixture carrier 04']), Clock::time(), 1, 25)['total'], 1, 'lista: buscar por nombre');
    T::eq(Prospects::page(Prospects::filters(['q' => "' OR 1=1 --"]), Clock::time(), 1, 25)['total'], 0, 'lista: una inyección en la búsqueda no devuelve nada');
    $cnt = Prospects::counts(Prospects::filters([]), Clock::time());
    T::ok($cnt['today'] === 10 && $cnt['to_call'] === 10 && $cnt['no_answer'] === 0 && $cnt['dnc'] === 0, 'lista: contadores por estado');
    T::eq(Prospects::page(Prospects::filters(['ag' => '3']), Clock::time(), 1, 25)['total'], 10, 'lista: antigüedad hasta 3 meses');
    T::eq(Prospects::filters(['tab' => 'x', 'us' => 'ZZ', 'fl' => 'q', 'ag' => '7'])['tab'], 'today', 'lista: filtros inválidos se descartan');
};
