<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Services\Fmt;
use Captacion\Services\LeadActions;
use Captacion\Services\RepView;
use Captacion\Services\ReportJobs;
use Captacion\Services\Reports;
use Captacion\Services\Settings;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/** Q3-C/D: reportes con datos CONOCIDOS (embudo, tiempos, costos por canal, pérdidas), consistencia con la base, exportación, informe mensual (instantánea, borrador, publicar) y crons semanal y mensual. */
return static function (array $ctx): void {
    T::reset(true);
    Db::pdo()->exec('DELETE FROM channel_costs');
    Db::pdo()->exec('DELETE FROM reports');
    Settings::set('mail', []);
    Settings::clear();
    $work = $ctx['work'];
    $smtpLog = "$work/smtp20.log";
    @unlink($smtpLog);
    $smtpPort = Infra::smtpMock('smtp20', $smtpLog);
    Fx::smtp($smtpPort);
    $mails = static fn(): array => Infra::readLog($smtpLog);
    $now = Clock::time();
    $M = ReportJobs::previousMonth($now);
    $mStart = (int) Clock::parse((string) Fmt::fromEtInput($M . '-01'));
    $day = (int) Clock::parse((string) Fmt::fromEtInput($M . '-05T10:00'));
    $ev = static function (int $lead, array $stages, string $pipeline = 'A'): void {
        $from = 'new';
        foreach ($stages as $to) {
            LeadActions::event($lead, 'stage_changed', ['from' => $from, 'to' => $to, 'pipeline' => $pipeline], null);
            $from = $to;
        }
    };
    $mk = static function (int $i, array $o) use ($day): int {
        $created = $day + $i * 3600;
        $fc = $o['fc'] ?? null;
        unset($o['fc'], $o['sd']);
        return Fx::lead($o + ['created_at' => Clock::at($created), 'first_contact_at' => $fc !== null ? Clock::at($created + $fc) : null, 'equipment_types' => ['dry-van'], 'state' => 'TX', 'landing_page' => null]);
    };
    $adm = Panel::user('admin');
    $aid = (int) $adm['id'];
    // 12 leads de la cohorte (ver el esquema de abajo) + 1 fuera de rango + 1 borrado
    $L = [];
    $L[1] = $mk(1, ['source' => 'meta', 'stage' => 'signed', 'temperature' => 'hot', 'fc' => 600, 'signed_at' => Clock::at($day + 3600 + 3 * 86400), 'handed_off_at' => Clock::at($day + 86400), 'landing_page' => 'https://northstarfreightsolutions.us/careers/owner-operators/?utm_source=x', 'equipment_types' => ['dry-van', 'reefer']]);
    $ev($L[1], ['contacted', 'qualified', 'call_booked', 'presented', 'signed']);
    $L[2] = $mk(2, ['source' => 'meta', 'stage' => 'signed', 'temperature' => 'warm', 'fc' => 1800, 'signed_at' => Clock::at($day + 7200 + 5 * 86400), 'handed_off_at' => Clock::at($day + 86400), 'landing_page' => '/careers/owner-operators/', 'equipment_types' => ['dry-van', 'reefer']]);
    $L[3] = $mk(3, ['source' => 'meta', 'stage' => 'presented', 'temperature' => 'hot', 'fc' => 3600, 'landing_page' => '/careers/owner-operators/']);
    $ev($L[3], ['contacted', 'qualified', 'presented']);
    $L[4] = $mk(4, ['source' => 'meta', 'stage' => 'lost', 'lost_reason' => 'price', 'temperature' => 'warm', 'fc' => 900]);
    $ev($L[4], ['contacted', 'qualified', 'lost']);
    $L[5] = $mk(5, ['source' => 'meta', 'stage' => 'new', 'temperature' => 'hot', 'landing_page' => '/careers/owner-operators/']);
    $L[6] = $mk(6, ['source' => 'meta', 'stage' => 'lost', 'lost_reason' => 'no_answer', 'temperature' => 'cold', 'fc' => 4000, 'landing_page' => '+cmd|calc']);
    $L[7] = $mk(7, ['source' => 'indeed', 'stage' => 'contacted', 'temperature' => 'cold', 'fc' => 7200, 'state' => 'FL']);
    $L[8] = $mk(8, ['source' => 'indeed', 'stage' => 'qualified', 'temperature' => 'warm', 'fc' => 1200, 'state' => 'FL']);
    $ev($L[8], ['contacted', 'qualified']);
    $L[9] = $mk(9, ['source' => 'indeed', 'stage' => 'lost', 'lost_reason' => 'price', 'temperature' => 'cold', 'state' => 'FL']);
    $L[10] = $mk(10, ['source' => 'indeed', 'stage' => 'signed', 'temperature' => 'hot', 'fc' => 300, 'signed_at' => Clock::at($day + 10 * 3600 + 10 * 86400), 'state' => 'FL']);
    $ev($L[10], ['contacted', 'qualified', 'call_booked', 'presented', 'signed']);
    $L[11] = $mk(11, ['source' => 'web_careers', 'profile' => 'company_driver', 'pipeline' => 'B', 'stage' => 'hired', 'temperature' => 'warm', 'fc' => 2400, 'signed_at' => Clock::at($day + 11 * 3600 + 7 * 86400), 'landing_page' => '/careers/cdl-a-drivers/']);
    $ev($L[11], ['contacted', 'prequalified', 'presented', 'hired'], 'B');
    $L[12] = $mk(12, ['source' => 'web_careers', 'profile' => 'company_driver', 'pipeline' => 'B', 'stage' => 'prequalified', 'temperature' => 'cold', 'fc' => 1500, 'landing_page' => '/careers/cdl-a-drivers/']);
    $ev($L[12], ['contacted', 'prequalified'], 'B');
    $L[13] = Fx::lead(['source' => 'meta', 'stage' => 'signed', 'created_at' => Clock::at($mStart - 20 * 86400), 'signed_at' => Clock::at($day + 86400), 'first_contact_at' => Clock::at($mStart - 20 * 86400 + 60)]);   // llego un mes antes, firmo en M
    $L[14] = $mk(14, ['source' => 'meta', 'stage' => 'signed', 'signed_at' => Clock::at($day + 3 * 86400), 'deleted_at' => Clock::now()]);   // borrado: no cuenta
    Db::insert('channel_costs', ['source' => 'meta', 'period' => $M, 'amount' => '600.00']);
    Db::insert('channel_costs', ['source' => 'indeed', 'period' => $M, 'amount' => '200.00']);

    $to = (int) Clock::parse((string) Fmt::fromEtInput(Fmt::et($mStart)->modify('first day of next month')->format('Y-m-d')));
    $m = Reports::compute($mStart, $to);
    $col = static fn(array $rows, string $k) => array_column($rows, null, 'key')[$k] ?? null;
    T::eq([$m['received'], $m['qualified'], $m['presented'], $m['signed'], $m['hotwarm']], [12, 8, 5, 4, 8], 'cohorte conocida: 12 recibidos, 8 calificados, 5 presentados, 4 firmados, 8 con puntuación alta (el lead borrado y el de otro mes no cuentan)');
    T::eq(array_column($m['funnel'], 'n'), [12, 10, 8, 5, 5, 4], 'embudo: 12 → 10 → 8 → 5 → 5 → 4 (por historial de etapas, contacto, entrega y firma)');
    T::eq(array_column($m['funnel'], 'of_prev'), [null, 83.3, 80.0, 62.5, 100.0, 80.0], '  conversión por etapa (sobre la etapa anterior)');
    T::eq(array_column($m['funnel'], 'of_total'), [100.0, 83.3, 66.7, 41.7, 41.7, 33.3], '  y sobre el total');
    T::eq($m['response'], ['median' => 1650, 'p90' => 4000, 'n' => 10], 'primera respuesta: mediana 1650 s (27,5 min) y p90 4000 s sobre 10 leads contactados');
    T::eq($m['sign_time'], ['median_days' => 6.0, 'n' => 4], 'tiempo hasta la firma: mediana 6,0 días (3, 5, 7 y 10)');
    T::eq([$m['loss']['total'], array_column($m['loss']['rows'], 'n', 'key')], [3, ['price' => 2, 'no_answer' => 1]], 'motivos de pérdida: precio 2, no contesta 1 (ranking)');
    T::eq([$m['loss']['rows'][0]['key'], $m['loss']['rows'][0]['pct']], ['price', 66.7], '  y el primero es precio con 66,7 %');
    T::eq($m['signed_in_period'], 5, 'firmados por FECHA DE FIRMA en el mes: 5 (los 4 de la cohorte + el que llegó el mes anterior)');
    $meta = $col($m['channels']['rows'], 'meta');
    $ind = $col($m['channels']['rows'], 'indeed');
    $web = $col($m['channels']['rows'], 'web_careers');
    T::eq([$meta['leads'], $meta['qualified'], $meta['presented'], $meta['signed']], [6, 4, 3, 2], 'canal Meta: 6 leads, 4 calificados, 3 presentados, 2 firmados');
    T::eq([$ind['leads'], $ind['qualified'], $ind['presented'], $ind['signed']], [4, 2, 1, 1], 'canal Indeed: 4, 2, 1, 1');
    T::eq([$web['leads'], $web['signed'], $web['cost'], $web['cpl'], $web['cps']], [2, 1, null, null, null], 'canal del sitio sin costo cargado: costos en null (no se inventa)');
    T::eq([$meta['cost'], $meta['cpl'], $meta['cps'], $ind['cpl'], $ind['cps']], [600.0, 100.0, 300.0, 50.0, 200.0], 'CPL y costo por firmado por canal: Meta 100 y 300; Indeed 50 y 200');
    T::eq([$m['channels']['cost_months'], $m['channels']['total']['cost'], $m['channels']['total']['leads'], $m['channels']['total']['signed'], $m['channels']['total']['cpl'], $m['channels']['total']['cps']], [[$M], 800.0, 10, 3, 80.0, 266.67], 'total con costo: $800 entre 10 leads (CPL 80) y 3 firmados (266,67)');
    // Consistencia con la base
    $sqlRec = (int) Db::value('SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL AND created_at >= ? AND created_at < ?', [Clock::at($mStart), Clock::at($to)]);
    $sqlSig = (int) Db::value("SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL AND created_at >= ? AND created_at < ? AND (signed_at IS NOT NULL OR stage IN ('signed','hired'))", [Clock::at($mStart), Clock::at($to)]);
    $sqlPer = (int) Db::value('SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL AND signed_at >= ? AND signed_at < ?', [Clock::at($mStart), Clock::at($to)]);
    T::eq([$m['received'], $m['signed'], $m['signed_in_period']], [$sqlRec, $sqlSig, $sqlPer], 'los totales cuadran con COUNT(*) directo sobre la base');
    T::eq([array_sum(array_column($m['channels']['rows'], 'leads')), array_sum(array_column($m['profiles'], 'leads')), array_sum(array_column($m['states'], 'leads')), array_sum(array_column($m['channels']['rows'], 'signed'))], [12, 12, 12, 4], 'la suma por canal, perfil y estado da el total (y los firmados por canal suman 4)');
    $prev = null;
    $mono = true;
    foreach ($m['funnel'] as $f) {
        $mono = $mono && ($prev === null || $f['n'] <= $prev);
        $prev = $f['n'];
    }
    T::ok($mono, 'el embudo nunca crece de una etapa a la siguiente');
    T::eq([array_sum($m['trend']['received']), array_sum($m['trend']['signed']), $m['trend']['unit']], [12, 5, 'day'], 'tendencia: 12 recibidos y 5 firmas repartidos por día');
    $eq = array_column($m['equipment'], 'leads', 'key');
    T::eq([$eq['dry-van'] ?? 0, $eq['reefer'] ?? 0], [12, 2], 'equipos: dry van 12 y reefer 2 (un lead puede tener varios)');
    T::eq(array_column($m['profiles'], 'leads', 'key'), ['owner_operator' => 10, 'company_driver' => 2], 'perfiles: 10 owner-operators y 2 drivers');
    T::eq(array_column($m['states'], 'leads', 'key'), ['TX' => 8, 'FL' => 4], 'estados: TX 8 y FL 4');
    $land = array_column($m['landing'], 'leads', 'key');
    T::eq([$land['/careers/owner-operators/'] ?? 0, $land['/careers/cdl-a-drivers/'] ?? 0, $land['(sin página de llegada)'] ?? 0], [4, 2, 5], 'páginas de llegada: se quita el dominio y el query; sin página se agrupa aparte');
    $viewer = Reports::forViewer($m);
    T::eq(array_keys($viewer), ['received', 'qualified', 'presented', 'signed', 'signed_in_period', 'funnel', 'loss', 'trend', 'sign_time', 'channels'], 'proyección para el lector: solo agregados (sin páginas de llegada, estados, equipos ni tiempos de respuesta)');
    T::ok(!str_contains((string) json_encode($viewer), 'owner-operators') && !str_contains((string) json_encode($viewer), '+cmd'), '  y no lleva rutas de páginas ni valores libres');

    // ------------------------------------------------------------------ Rangos y comparacion
    $r7 = Reports::resolve(['r' => '7d'], Reports::PRESETS, $now);
    T::ok($r7['key'] === '7d' && $r7['to'] === Fmt::dayStart($now, 1) && $r7['from'] === Fmt::dayStart($now, -6) && $r7['prev_to'] === $r7['from'] && $r7['prev_from'] === Fmt::dayStart($now, -13), 'rango 7 días: hoy incluido y comparación con los 7 anteriores');
    $rl = Reports::resolve(['r' => 'lastmonth'], Reports::PRESETS, $now);
    T::ok($rl['from'] === $mStart && $rl['to'] === Fmt::monthStart($now) && $rl['prev_to'] === $mStart && $rl['prev_from'] === Fmt::monthStart($mStart, -1), 'mes pasado: se compara con el mes calendario anterior');
    $rm = Reports::resolve(['r' => 'month'], Reports::PRESETS, $now);
    T::ok($rm['from'] === Fmt::monthStart($now) && $rm['prev_from'] === Fmt::monthStart($now, -1) && $rm['prev_to'] <= $rm['from'], 'este mes: se compara con el mismo tramo del mes anterior');
    $rc = Reports::resolve(['from' => '2026-10-05', 'to' => '2026-10-07'], Reports::PRESETS, $now);
    T::ok($rc['custom'] && $rc['key'] === 'custom' && $rc['to'] - $rc['from'] === 3 * 86400 && $rc['prev_to'] === $rc['from'] && $rc['prev_from'] === $rc['from'] - 3 * 86400, 'rango personalizado: ambos días incluidos y comparación con los 3 anteriores');
    T::ok(Reports::resolve(['from' => '2026-10-09', 'to' => '2026-10-01'], Reports::PRESETS, $now)['error'] !== null && Reports::resolve(['from' => 'no', 'to' => 'no'], Reports::PRESETS, $now)['error'] !== null && Reports::resolve(['from' => '2020-01-01', 'to' => '2026-10-01'], Reports::PRESETS, $now)['error'] !== null, 'fechas invertidas, ilegibles o de más de 400 días: error y se vuelve al rango anterior');
    T::ok(Reports::resolve(['r' => 'drop table'], Reports::PRESETS, $now)['key'] === '30d' && Reports::resolve(['r' => 'year', 'from' => '2026-01-01', 'to' => '2026-01-31'], Reports::VIEWER_PRESETS, $now)['custom'] === false, 'preset desconocido: 30 días; el lector no puede usar rango libre');
    // sin meses completos
    $m7 = Reports::compute($r7['from'], $r7['to']);
    T::eq($m7['channels']['cost_months'], [], 'un rango que no contiene ningún mes completo no usa costos');
    $kp = RepView::cpsText($m7);
    T::ok($kp[0] === 'Sin costos cargados' && str_contains($kp[1], 'ningún mes completo'), '  y lo dice («Sin costos cargados», sin inventar un número)');
    $mNo = Reports::compute($mStart, $to);
    Db::pdo()->exec('DELETE FROM channel_costs');
    $mNo = Reports::compute($mStart, $to);
    T::ok($mNo['channels']['total']['cps'] === null && $mNo['channels']['total']['cost'] == 0 && RepView::cpsText($mNo)[0] === 'Sin costos cargados' && $col($mNo['channels']['rows'], 'meta')['cps'] === null, 'sin costos cargados: ningún CPL ni costo por firmado');
    $csv = Reports::csv('canales', $mNo);
    T::ok(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, 'Sin costos cargados') && !str_contains($csv, '300.00'), 'el CSV de canales dice «Sin costos cargados» en vez de un número');
    Db::insert('channel_costs', ['source' => 'meta', 'period' => $M, 'amount' => '600.00']);
    Db::insert('channel_costs', ['source' => 'indeed', 'period' => $M, 'amount' => '200.00']);
    // dos meses: un rango de 2 meses completos suma los costos de ambos
    $twoFrom = (int) Clock::parse((string) Fmt::fromEtInput(Fmt::et($mStart)->modify('first day of last month')->format('Y-m-d')));
    $prevM = Fmt::et($twoFrom)->format('Y-m');
    Db::insert('channel_costs', ['source' => 'meta', 'period' => $prevM, 'amount' => '400.00']);
    $m2 = Reports::compute($twoFrom, $to);
    $meta2 = $col($m2['channels']['rows'], 'meta');
    T::ok($m2['channels']['cost_months'] === [$prevM, $M] && $meta2['cost'] === 1000.0 && $meta2['cost_leads'] === 7, 'dos meses completos: se suman los costos (600 + 400) y los leads de esos dos meses (6 + 1)');
    Db::run('DELETE FROM channel_costs WHERE period = ?', [$prevM]);
    // rendimiento y plan de consultas con muchos leads
    Db::pdo()->exec("INSERT INTO leads (profile, first_name, last_name, state, source, stage, temperature, pipeline, attempt, created_at) SELECT 'owner_operator', 'Mass', 'Lead', 'TX', 'meta', 'new', 'cold', 'A', 1, TIMESTAMPADD(MINUTE, seq, '2026-01-01 00:00:00') FROM seq_1_to_30000");
    Db::all('ANALYZE TABLE leads');
    $t0 = microtime(true);
    $big = Reports::compute((int) Clock::parse('2026-01-01 00:00:00'), (int) Clock::parse('2026-02-01 00:00:00'));
    $secs = microtime(true) - $t0;
    T::ok($big['received'] === 30000 - (int) Db::value("SELECT COUNT(*) FROM leads WHERE first_name = 'Mass' AND created_at >= '2026-02-01'") && $secs < 6.0, 'un mes con ' . $big['received'] . ' leads se calcula en ' . round($secs, 2) . ' s (< 6 s)');
    $plan = static fn(string $sql, array $p): string => (string) json_encode(Db::all('EXPLAIN ' . $sql, $p));
    T::ok(str_contains($plan('SELECT id FROM leads WHERE deleted_at IS NULL AND created_at >= ? AND created_at < ?', ['2026-01-05 00:00:00', '2026-01-06 00:00:00']), 'idx_leads_created'), 'EXPLAIN: la cohorte del rango usa leads(deleted_at, created_at)');
    T::ok(str_contains($plan('SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL AND signed_at >= ? AND signed_at < ?', ['2026-01-05 00:00:00', '2026-01-06 00:00:00']), 'idx_leads_signed'), 'EXPLAIN: los firmados por fecha usan leads(deleted_at, signed_at)');
    T::ok(str_contains($plan("SELECT lead_id, payload FROM lead_events WHERE type = 'stage_changed' AND lead_id IN (1,2,3)", []), 'idx_events_lead'), 'EXPLAIN: el historial de etapas usa lead_events(lead_id, created_at)');
    Db::pdo()->exec("DELETE FROM leads WHERE first_name = 'Mass'");

    // ------------------------------------------------------------------ HTTP: pagina, exportacion, permisos
    $panel = Panel::start($ctx, 'panel20', ['SMTP_HOST' => '127.0.0.1', 'SMTP_PORT' => (string) $smtpPort, 'SMTP_USER' => 'crm@example.com', 'SMTP_PASS' => 'pw-smtp-prueba', 'SMTP_SECURE' => 'none']);
    T::ok($panel->login($adm['email']), 'login del administrador');
    $pg = $panel->get('/reportes?r=lastmonth');
    T::ok($pg['status'] === 200 && str_contains($pg['body'], 'EMBUDO') && str_contains($pg['body'], 'Sitio · Careers') && str_contains($pg['body'], 'Meta Ads') && str_contains($pg['body'], '$100.00') && str_contains($pg['body'], '$300'), '/reportes?r=lastmonth: embudo, canales y costos de Meta (CPL $100 y costo por firmado $300)');
    T::ok(!preg_match('/\sstyle="/i', $pg['body']) && !preg_match('/\son\w+="/i', $pg['body']) && preg_match('/<script(?![^>]*\bnonce=")/i', $pg['body']) === 0 && str_contains($pg['body'], '/assets/reportes.css'), '  sin estilos ni handlers en línea, scripts con nonce');
    T::ok(str_contains($panel->get('/reportes')['body'], 'Sin costos cargados') || str_contains($panel->get('/reportes')['body'], 'ningún mes completo'), '/reportes (30 días): explica por qué no hay costos');
    $bad = $panel->get('/reportes?from=2026-10-09&to=2026-10-01');
    T::ok($bad['status'] === 200 && str_contains($bad['body'], 'no son válidas'), 'fechas inválidas: 200 con aviso');
    $ex = $panel->post('/reportes/export', ['_csrf' => $panel->csrf, 'table' => 'canales', 'r' => 'lastmonth']);
    T::ok($ex['status'] === 200 && str_contains(Infra::header($ex, 'content-type'), 'text/csv') && str_starts_with($ex['body'], "\xEF\xBB\xBF") && str_contains($ex['body'], 'Meta Ads,6,4,3,2,600.00,6,2,100.00,300.00'), 'exportar canales: CSV con BOM y la fila de Meta correcta');
    $ex = $panel->post('/reportes/export', ['_csrf' => $panel->csrf, 'table' => 'paginas', 'r' => 'lastmonth']);
    T::ok(str_contains($ex['body'], "'+cmd|calc") && !str_contains($ex['body'], ",+cmd"), 'exportar páginas: una celda que empieza por «+» sale con protección contra fórmulas');
    foreach (array_keys(Reports::TABLES) as $t) {
        $x = $panel->post('/reportes/export', ['_csrf' => $panel->csrf, 'table' => $t, 'r' => 'lastmonth']);
        T::ok($x['status'] === 200 && str_contains(Infra::header($x, 'content-disposition'), 'reporte-' . $t), "  CSV «{$t}»: 200 con nombre de archivo");
    }
    T::ok((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'report_export'") === 2 + count(Reports::TABLES), 'cada exportación queda en activity_log');
    T::eq($panel->post('/reportes/export', ['_csrf' => 'falso', 'table' => 'canales'])['status'], 303, 'exportar sin CSRF válido: no descarga');
    T::eq($panel->post('/reportes/export', ['_csrf' => $panel->csrf, 'table' => 'inventada'])['status'], 303, 'tabla inexistente: no descarga');
    $csvCust = $panel->post('/reportes/export', ['_csrf' => $panel->csrf, 'table' => 'embudo', 'from' => '2026-10-01', 'to' => '2026-10-31']);
    T::ok(str_contains(Infra::header($csvCust, 'content-disposition'), '2026-10-01_2026-10-31'), 'exportar con rango personalizado: el nombre lleva las fechas');
    $res = $panel->get('/resultados');
    T::eq($res['status'], 200, 'el administrador también ve /resultados (la vista del cliente)');

    // ------------------------------------------------------------------ Informe mensual
    Settings::set('mail', ['monthly_to' => ['cliente1@northstar.test', 'cliente2@northstar.test'], 'weekly_to' => 'carlos@example.com', 'enabled' => false, 'address' => '']);
    Settings::clear();
    $g = ReportJobs::generateMonthly($M, $aid, $now);
    $rep = Db::one("SELECT * FROM reports WHERE kind = 'monthly' AND period = ?", [$M]);
    T::ok($g['ok'] && $g['created'] && $rep['status'] === 'draft' && $rep['ident'] === 'INF-' . $M && $rep['published_at'] === null, 'generar el informe mensual: queda como borrador con identificador ' . $rep['ident']);
    $snap = json_decode((string) $rep['metrics'], true);
    T::ok($snap['cur']['received'] === 12 && $snap['cur']['signed'] === 4 && $snap['label'] === Reports::monthName($M) && isset($snap['prev']), '  la instantánea guarda las métricas del mes y las del mes anterior');
    $acts = json_decode((string) $rep['actions'], true);
    T::ok(count($acts) === 3 && !array_filter($acts, static fn($a) => trim((string) $a) === ''), 'trae tres acciones sugeridas (reglas simples, no vacías)');
    T::ok(str_contains($acts[0], 'caliente') && str_contains($acts[0], 'sin contactar'), '  la primera señala los leads calientes sin contactar (hay 3 en la base)');
    $g2 = ReportJobs::generateMonthly($M, $aid, $now);
    T::ok($g2['ok'] && !$g2['created'] && (int) $g2['id'] === (int) $rep['id'] && (int) Db::value("SELECT COUNT(*) FROM reports WHERE kind = 'monthly'") === 1, 'generar otra vez el mismo mes: idempotente (no duplica)');
    T::ok(!ReportJobs::generateMonthly(Fmt::et($now)->format('Y-m'), $aid, $now)['ok'] && !ReportJobs::generateMonthly('2026-13', $aid, $now)['ok'] && !ReportJobs::generateMonthly('2099-01', $aid, $now)['ok'], 'el mes en curso, uno futuro o uno ilegible no se generan');
    Db::update('leads', $L[5], ['stage' => 'contacted']);
    $snap2 = json_decode((string) Db::value('SELECT metrics FROM reports WHERE id = ?', [$rep['id']]), true);
    T::eq($snap2['cur']['funnel'], $snap['cur']['funnel'], 'es una instantánea: cambiar un lead después no altera el informe');
    // pagina
    $one = $panel->get('/informes/' . $rep['id']);
    T::ok($one['status'] === 200 && str_contains($one['body'], 'BORRADOR') && str_contains($one['body'], 'name="action[]"') && str_contains($one['body'], 'TENDENCIA FRENTE AL MES ANTERIOR') && str_contains($one['body'], 'data-print') && str_contains($one['body'], 'rep-print-h'), 'vista del informe (admin, borrador): acciones editables, tendencia vs mes anterior y botón de imprimir');
    $css = (string) file_get_contents($ctx['root'] . '/public/assets/reportes.css');
    T::ok(str_contains($css, '@media print') && str_contains($css, '@page') && str_contains($css, '.side,.top,.tabbar'), 'hoja de impresión: oculta barra lateral y superior, define la página A4');
    $r = $panel->post('/informes/' . $rep['id'] . '/actions', ['_csrf' => $panel->csrf, 'action' => ['Primera acción editada', 'Segunda <b>editada</b>', '', 'sobra']]);
    $acts = json_decode((string) Db::value('SELECT actions FROM reports WHERE id = ?', [$rep['id']]), true);
    T::ok($r['status'] === 303 && $acts === ['Primera acción editada', 'Segunda <b>editada</b>'], 'editar las acciones antes de publicar (vacías se descartan, máximo 3)');
    T::ok(str_contains($panel->get('/informes/' . $rep['id'])['body'], 'Segunda &lt;b&gt;editada&lt;/b&gt;'), '  y salen escapadas');
    T::eq($panel->post('/informes/' . $rep['id'] . '/actions', ['_csrf' => $panel->csrf, 'action' => ['', '', '']])['status'], 303, 'sin ninguna acción: no guarda');
    T::eq(json_decode((string) Db::value('SELECT actions FROM reports WHERE id = ?', [$rep['id']]), true), ['Primera acción editada', 'Segunda <b>editada</b>'], '  las acciones siguen como estaban');
    // publicar
    $before = count($mails());
    $r = $panel->post('/informes/' . $rep['id'] . '/publish', ['_csrf' => $panel->csrf]);
    $rep = Db::one('SELECT * FROM reports WHERE id = ?', [$rep['id']]);
    T::ok($r['status'] === 303 && $rep['status'] === 'published' && $rep['published_at'] !== null && $rep['sent_at'] !== null, 'publicar: queda publicado y se envía el enlace');
    $new = array_slice($mails(), $before);
    $tos = array_merge(...array_map(static fn($x) => $x['to'], $new));
    sort($tos);
    T::ok(count($new) === 2 && $tos === ['cliente1@northstar.test', 'cliente2@northstar.test'] && str_contains(quoted_printable_decode($new[0]['raw']), '/informes/' . $rep['id']), '  1 correo por destinatario con el enlace al informe');
    $body = quoted_printable_decode($new[0]['raw']);
    T::ok(str_contains($body, 'Recibidos: 12') && str_contains($body, 'Firmados: 4') && !str_contains($body, 'Zebulon'), '  y las cifras del correo son agregadas');
    $panel->post('/informes/' . $rep['id'] . '/publish', ['_csrf' => $panel->csrf]);
    T::eq(count($mails()), $before + 2, 'publicar otra vez no reenvía (idempotente)');
    T::eq($panel->post('/informes/' . $rep['id'] . '/actions', ['_csrf' => $panel->csrf, 'action' => ['Cambio tardío']])['status'], 303, 'un informe publicado ya no se edita');
    T::eq(json_decode((string) Db::value('SELECT actions FROM reports WHERE id = ?', [$rep['id']]), true), ['Primera acción editada', 'Segunda <b>editada</b>'], '  (las acciones no cambiaron)');
    $panel->post('/informes/' . $rep['id'] . '/resend', ['_csrf' => $panel->csrf]);
    T::eq(count($mails()), $before + 4, '«Reenviar el enlace» es explícito y sí vuelve a enviarlo');
    $panel->post('/informes/generate', ['_csrf' => $panel->csrf, 'period' => $M]);
    T::eq((int) Db::value("SELECT COUNT(*) FROM reports WHERE kind = 'monthly'"), 1, 'el botón «Generar ahora» sobre un mes existente no duplica');
    $panel->post('/informes/generate', ['_csrf' => $panel->csrf, 'period' => Fmt::et($now - 70 * 86400)->format('Y-m')]);
    T::eq((int) Db::value("SELECT COUNT(*) FROM reports WHERE kind = 'monthly'"), 2, '«Generar ahora» para un mes pasado crea su borrador');
    $lst = $panel->get('/informes');
    T::ok(str_contains($lst['body'], 'Borrador') && str_contains($lst['body'], 'Publicado') && str_contains($lst['body'], 'GENERAR UN INFORME'), 'lista de informes (admin): publicados y borradores');

    // ------------------------------------------------------------------ Crons: resumen semanal (A13) e informe mensual (A14), con zona horaria ET
    $et = static function (string $local): int {
        $d = DateTimeImmutable::createFromFormat('Y-m-d H:i', $local, new DateTimeZone('America/New_York'));
        return $d->getTimestamp();
    };
    Db::pdo()->exec('DELETE FROM reports');
    Db::pdo()->exec('DELETE FROM jobs');
    $wk = static fn(): int => (int) Db::value("SELECT COUNT(*) FROM reports WHERE kind = 'weekly'");
    // semana del 2 al 8 nov 2026 (lunes a domingo); el lunes 9 a las 8:00 ET sale el resumen
    $inWeek = static fn(int $i, array $o) => Fx::lead($o + ['created_at' => Clock::at($et('2026-11-03 10:00') + $i * 600), 'equipment_types' => ['dry-van']]);
    $w1 = $inWeek(1, ['source' => 'meta', 'stage' => 'new', 'temperature' => 'hot']);
    $w2 = $inWeek(2, ['source' => 'meta', 'stage' => 'signed', 'signed_at' => Clock::at($et('2026-11-05 12:00')), 'first_contact_at' => Clock::at($et('2026-11-03 10:20'))]);
    $w3 = $inWeek(3, ['source' => 'indeed', 'stage' => 'contacted', 'first_contact_at' => Clock::at($et('2026-11-03 11:00'))]);
    Db::insert('tasks', ['title' => 'Vencida de prueba', 'lead_id' => $w1, 'due_at' => Clock::at($et('2026-11-04 09:00')), 'priority' => 1, 'created_by' => $aid]);
    $b0 = count($mails());
    $t = ReportJobs::tick($et('2026-11-09 07:59'));
    T::ok($wk() === 0 && count($mails()) === $b0 && $t['weekly'] === 'nada que hacer', 'lunes 7:59 a. m. ET: todavía no hay resumen semanal');
    $t = ReportJobs::tick($et('2026-11-09 08:00'));
    $sent = array_slice($mails(), $b0);
    T::ok($wk() === 1 && count($sent) === 1 && $sent[0]['to'] === ['carlos@example.com'] && str_starts_with($t['weekly'], 'enviado 2026-W45'), 'lunes 8:00 a. m. ET: sale el resumen semanal (semana ISO 45) a Carlos');
    $txt = quoted_printable_decode($sent[0]['raw']);
    $hotNow = (int) Db::value("SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL AND temperature = 'hot' AND first_contact_at IS NULL AND stage = 'new'");
    T::ok(str_contains($txt, 'Leads recibidos: 3') && str_contains($txt, 'Firmados esta semana (por fecha de firma): 1') && str_contains($txt, "Calientes sin contactar AHORA: $hotNow") && str_contains($txt, 'Tareas vencidas AHORA: 1') && str_contains($txt, 'Mejor canal: Meta Ads (2 leads, 1 firmados)'), '  lleva leads de la semana, firmados, calientes sin contactar, tareas vencidas y mejor canal');
    T::ok(preg_match_all('/^[123]\. /m', $txt) === 3 && str_contains($txt, 'Peor canal: Indeed'), '  y tres acciones sugeridas numeradas');
    ReportJobs::tick($et('2026-11-09 08:30'));
    ReportJobs::tick($et('2026-11-10 23:00'));
    T::eq([$wk(), count($mails())], [1, $b0 + 1], 'repetir el cron el mismo lunes y el martes: no duplica ni reenvía (idempotente)');
    ReportJobs::tick($et('2026-11-11 00:30'));
    T::eq(count($mails()), $b0 + 1, 'el miércoles ya no se recupera');
    // cambio de hora (DST termina el domingo 1 nov 2026): lunes 2 nov 8:00 EST = 13:00 UTC; lunes 26 oct 8:00 EDT = 12:00 UTC
    $wm = static fn(): int => count(array_filter($mails(), static fn($x) => str_contains(quoted_printable_decode((string) $x['raw']), 'Resumen de la semana del')));
    $w0 = $wm();
    ReportJobs::tick($et('2026-11-02 07:59'));
    T::eq($wm(), $w0, 'lunes 2 nov 7:59 ET (tras el cambio de hora): no sale el resumen');
    ReportJobs::tick($et('2026-11-02 08:00'));
    T::eq([$wm(), $wk()], [$w0 + 1, 2], 'lunes 2 nov 8:00 ET (= 13:00 UTC, EST): sale');
    $w1c = $wm();
    ReportJobs::tick($et('2026-10-26 07:59'));
    T::eq($wm(), $w1c, 'lunes 26 oct 7:59 ET (EDT): no sale');
    ReportJobs::tick($et('2026-10-26 08:00'));
    T::eq([$wm(), $wk()], [$w1c + 1, 3], 'lunes 26 oct 8:00 ET (= 12:00 UTC, EDT): sale');
    T::eq(gmdate('H:i', $et('2026-10-26 08:00')) . ' / ' . gmdate('H:i', $et('2026-11-02 08:00')), '12:00 / 13:00', '  (las 8:00 ET son 12:00 UTC en verano y 13:00 UTC en invierno)');
    // recuperacion: el cron no corrio el lunes y corre el martes 14:00
    $b3 = $wm();
    $t = ReportJobs::tick($et('2026-11-17 14:00'));
    T::eq([$wm(), $wk()], [$b3 + 1, 4], 'si el cron no corrió el lunes, el martes lo recupera');
    // mensual: dia 1 desde la 1:00 a. m. ET
    Db::pdo()->exec("DELETE FROM reports WHERE kind = 'monthly'");
    $n0 = count($mails());
    $t = ReportJobs::tick($et('2026-12-01 00:30'));
    T::eq((int) Db::value("SELECT COUNT(*) FROM reports WHERE kind = 'monthly'"), 0, 'día 1 a las 0:30 ET: el informe mensual todavía no se genera');
    $t = ReportJobs::tick($et('2026-12-01 01:00'));
    $mon = Db::one("SELECT * FROM reports WHERE kind = 'monthly'");
    T::ok($mon !== null && $mon['period'] === '2026-11' && $mon['status'] === 'draft' && $mon['notified_at'] !== null && $t['monthly'] === 'generado 2026-11', 'día 1 a la 1:00 ET: se genera el informe de noviembre como borrador y se avisa a Carlos');
    $notice = array_slice($mails(), $n0);
    T::ok(count($notice) >= 1 && $notice[count($notice) - 1]['to'] === ['carlos@example.com'] && str_contains(quoted_printable_decode($notice[count($notice) - 1]['raw']), '/informes/' . $mon['id']), '  el aviso lleva el enlace al borrador');
    $n1 = count($mails());
    ReportJobs::tick($et('2026-12-01 09:00'));
    ReportJobs::tick($et('2026-12-03 12:00'));
    T::eq([(int) Db::value("SELECT COUNT(*) FROM reports WHERE kind = 'monthly'"), count($mails())], [1, $n1], 'repetir el cron los días siguientes: ni duplica el informe ni repite el aviso');
    Db::pdo()->exec("DELETE FROM reports WHERE kind = 'monthly'");
    ReportJobs::tick($et('2026-12-06 09:00'));
    T::eq((int) Db::value("SELECT COUNT(*) FROM reports WHERE kind = 'monthly'"), 0, 'después del día 5 no se genera solo (se usa «Generar ahora»)');
    // el script de cron por CLI (con flock y --now)
    $envFile = $work . '/cron20.env';
    file_put_contents($envFile, implode("\n", array_map(static fn($k, $v) => "$k=$v", array_keys($ctx['env']), $ctx['env'])) . "\nSMTP_HOST=127.0.0.1\nSMTP_PORT=$smtpPort\nSMTP_USER=crm@example.com\nSMTP_PASS=pw-smtp-prueba\nSMTP_SECURE=none\n");
    $c0 = count($mails());
    $out = Infra::cli([$ctx['root'] . '/cron/reports_cron.php', '--now=2026-12-07T09:00'], ['CAPTACION_ENV_FILE' => $envFile]);
    $out2 = Infra::cli([$ctx['root'] . '/cron/reports_cron.php', '--now=2026-12-07T09:30'], ['CAPTACION_ENV_FILE' => $envFile]);
    T::ok($out['code'] === 0 && str_contains($out['out'], 'enviado 2026-W49') && $out2['code'] === 0 && str_contains($out2['out'], 'ya enviado') && count($mails()) === $c0 + 1, 'cron/reports_cron.php por CLI: envía una vez y la segunda pasada no repite');
    T::ok(Settings::get('cron.reports') !== null || (int) Db::value("SELECT COUNT(*) FROM settings WHERE `key` = 'cron.reports'") === 1, '  y deja la marca de la última pasada');
    Infra::stop('panel20');
    Infra::stop('smtp20');
    Fx::noSmtp();
    Settings::set('mail', []);
    Clock::travel(0);
    T::reset(true);
};
