<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Services\ActivityLog;
use Captacion\Services\ActivityView;
use Captacion\Services\Fmt;
use Captacion\Services\Settings;
use Captacion\Services\Totp;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/** Registro de actividad (Q2b-D): agente resumido, filtros, paginacion, descripcion, CSV con proteccion contra formulas, retencion y permisos por rol. */
return static function (array $ctx): void {
    T::reset(true);
    Db::pdo()->exec("DELETE FROM settings WHERE `key` = 'activity.retention_days'");
    Settings::clear();
    // ------------------------------------------------------------------ Agente e IP resumidos
    $chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
    T::eq(ActivityLog::agent($chrome), 'Chrome 130 · Windows', 'agente: Chrome en Windows');
    T::eq(ActivityLog::agent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/129.0.2792.52'), 'Edge 129 · Windows', 'agente: Edge (no se confunde con Chrome)');
    T::eq(ActivityLog::agent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'), 'Safari 17 · iOS', 'agente: Safari en iPhone');
    T::eq(ActivityLog::agent('Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0'), 'Firefox 131 · Linux', 'agente: Firefox en Linux');
    T::eq(ActivityLog::agent('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36'), 'Chrome 129 · Android', 'agente: Chrome en Android');
    T::eq([ActivityLog::agent(''), ActivityLog::agent('bot-raro/1.0')], [null, null], 'agente: vacio o desconocido = null (no se guarda el texto completo)');
    T::ok(mb_strlen((string) ActivityLog::agent(str_repeat('A', 5000) . ' Chrome/130 Windows')) <= 80, 'agente: nunca pasa de 80 caracteres');
    T::eq([ActivityLog::shortIp('203.0.113.9'), ActivityLog::shortIp('2001:db8:85a3:8d3:1319:8a2e:370:7348'), ActivityLog::shortIp(null)], ['203.0.113.9', '2001:db8:85a3:8d3::', '—'], 'IP: IPv4 completa, IPv6 resumida a 4 grupos, vacio = «—»');

    // ------------------------------------------------------------------ Datos conocidos
    $a = Panel::user('admin');
    $b = Panel::user('admin');
    $now = Clock::time();
    $ins = static function (string $action, ?int $uid, array $changes = [], ?string $etype = null, mixed $eid = null, ?int $ts = null, ?string $ip = '203.0.113.5', ?string $agent = 'Chrome 130 · Windows') use ($now): int {
        return Db::insert('activity_log', ['user_id' => $uid, 'action' => $action, 'entity_type' => $etype, 'entity_id' => $eid === null ? null : (string) $eid, 'changes' => $changes ?: null, 'ip' => $ip, 'agent' => $agent, 'created_at' => Clock::at($ts ?? $now - 60)]);
    };
    Db::pdo()->exec('TRUNCATE TABLE activity_log');
    $ins('login_ok', $a['id'], ['via' => 'totp'], 'user', $a['id']);
    $ins('login_failed', null, ['email' => 'intruso@example.com'], 'user', null, $now - 120, '198.51.100.7', 'Firefox 131 · Linux');
    $ins('leads_export', $a['id'], ['rows' => 40, 'scope' => 'filters'], 'lead');
    $ins('settings_change', $b['id'], ['diff' => ['followup_minutes' => [15, 30], 'warm_telegram' => ['auto', 'off']]], 'settings', 'alerts');
    $ins('lead_stage', $a['id'], ['from' => 'new', 'to' => 'lost', 'reason' => 'price'], 'lead', 77);
    $ins('leads_bulk_stage', $b['id'], ['stage' => 'contacted', 'changed' => 3, 'skipped' => 1], 'lead');
    $ins('note_delete', $a['id'], ['note_id' => 5], 'lead', 77);
    $ins('cost_delete', $b['id'], ['source' => 'meta', 'period' => '2026-10', 'before' => '380.00', 'after' => null], 'channel_cost', 4);
    $ins('login_ok', $a['id'], ['via' => 'recovery'], 'user', $a['id'], $now - 3 * 86400);
    $ins('task_add', $a['id'], ['task_id' => 9], 'lead', 12, $now - 10 * 86400);
    $ins('settings_change', $a['id'], ['diff' => ['hot_min' => [70, 75]]], 'settings', 'scoring.temperature', $now - 40 * 86400);
    T::eq(ActivityView::query([], 1)['total'], 11, 'registro: 11 filas sembradas');
    // filtros
    $count = static fn(array $f) => ActivityView::query(ActivityView::filters($f), 1)['total'];
    T::eq($count(['ty' => 'login']), 3, 'filtro por tipo «Accesos»: 3 (2 login_ok + 1 fallo)');
    T::eq($count(['ty' => 'config']), 2, 'filtro «Cambios de configuración»: 2');
    T::eq($count(['ty' => 'export']), 1, 'filtro «Exportaciones»: 1');
    T::eq($count(['ty' => 'stage']), 2, 'filtro «Cambios de etapa»: 2 (individual y masivo)');
    T::eq($count(['ty' => 'delete']), 2, 'filtro «Borrados»: 2');
    T::eq($count(['us' => (string) $b['id']]), 3, 'filtro por usuario: 3 de B');
    T::eq($count(['from' => Fmt::et($now - 4 * 86400)->format('Y-m-d'), 'to' => Fmt::et($now)->format('Y-m-d')]), 9, 'filtro por fechas (ultimos 4 dias): 9');
    T::eq($count(['from' => Fmt::et($now - 11 * 86400)->format('Y-m-d'), 'to' => Fmt::et($now - 9 * 86400)->format('Y-m-d')]), 1, 'filtro por rango estrecho: 1');
    T::eq($count(['q' => 'intruso']), 1, 'busqueda por texto dentro del detalle (correo): 1');
    T::eq($count(['q' => '198.51.100']), 1, 'busqueda por IP: 1');
    T::eq($count(['q' => 'Firefox']), 1, 'busqueda por agente: 1');
    T::eq($count(['q' => $b['email']]), 3, 'busqueda por correo del usuario: 3');
    T::eq($count(['q' => 'followup_minutes']), 1, 'busqueda por un campo del cambio: 1');
    T::eq($count(['q' => '%']), 0, 'busqueda de un «%» literal: el comodin esta escapado (0, no todo)');
    T::eq($count(['ty' => 'config', 'us' => (string) $a['id']]), 1, 'filtros combinados: configuracion + usuario A = 1');
    T::eq(ActivityView::filters(['ty' => 'inventado', 'us' => 'x', 'from' => 'mañana', 'q' => "  \0  "]), [], 'filtros hostiles: se descartan los invalidos');
    // paginacion
    for ($i = 0; $i < 120; $i++) {
        $ins('task_done', $a['id'], ['task_id' => $i], 'lead', 1, $now - 30 - $i);
    }
    $p1 = ActivityView::query([], 1);
    $p3 = ActivityView::query([], 3);
    $p9 = ActivityView::query([], 99);
    T::eq([count($p1['rows']), $p1['total'], $p1['pages'], count($p3['rows']), $p9['page']], [50, 131, 3, 31, 3], 'paginacion: 131 filas = 50 + 50 + 31; una pagina fuera de rango cae en la ultima');
    $ids = array_column($p1['rows'], 'id');
    T::ok($ids === array_values(array_reverse(array_values(array_reverse($ids)))) && $ids[0] > $ids[49], 'orden: mas reciente primero');
    // descripciones
    $rows = ActivityView::query(['q' => 'followup_minutes'], 1)['rows'];
    T::ok(str_contains(ActivityView::describe($rows[0]), 'followup_minutes: 15 → 30') && str_contains(ActivityView::describe($rows[0]), 'warm_telegram: auto → off'), 'detalle de un cambio de configuracion: ruta, valor anterior → nuevo');
    $st = ActivityView::query(['ty' => 'stage'], 1)['rows'];
    $desc = array_map(static fn($r) => ActivityView::describe($r), $st);
    T::ok(in_array('Lead #77: Nuevo → Perdido (Precio)', $desc, true) && in_array('3 cambiados, 1 omitidos → Contactado', $desc, true), 'detalle de etapas: «Lead #77: Nuevo → Perdido (Precio)» y el masivo');
    $lg = ActivityView::query(['ty' => 'login'], 1)['rows'];
    $d2 = array_map(static fn($r) => ActivityView::describe($r), $lg);
    T::ok(in_array('Con código de recuperación', $d2, true) && in_array('Correo: intruso@example.com', $d2, true), 'detalle de accesos: via recuperacion y correo del intento fallido');
    T::eq(ActivityView::label('inventada_x'), 'Inventada x', 'etiqueta de una accion desconocida: legible, no revienta');
    // CSV
    $uEvil = Panel::user('viewer');
    Db::run("UPDATE users SET name = '=cmd|calc' WHERE id = ?", [$uEvil['id']]);
    $ins('login_ok', $uEvil['id'], ['via' => 'totp'], 'user', $uEvil['id']);
    $csv = ActivityView::csv(ActivityView::query(['us' => (string) $uEvil['id']], 1)['rows']);
    T::ok(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, "'=cmd|calc") && !preg_match('/(^|,)=cmd/', $csv) && str_contains($csv, 'Chrome 130 · Windows') && str_contains($csv, 'Accesos y seguridad'), 'CSV: BOM, nombre con formula neutralizado, agente y tipo');
    $csvAll = ActivityView::csv(ActivityView::query([], 1, 1000)['rows']);
    T::eq(substr_count($csvAll, "\r\n"), 133, 'CSV completo: cabecera + 132 filas');
    // retencion
    T::eq(ActivityView::retentionDays(), 365, 'retencion por defecto: 365 dias');
    foreach (['10', '3651', 'abc', '', '1e3', '-90'] as $bad) {
        T::ok(!ActivityView::setRetention($bad, $a['id'])['ok'], "retencion: rechaza «{$bad}»");
    }
    T::ok(ActivityView::setRetention('30', $a['id'])['ok'] && ActivityView::retentionDays() === 30, 'retencion: acepta 30 y la guarda');
    T::eq((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'activity_retention'"), 1, '  y queda en el registro');
    $before = (int) Db::value('SELECT COUNT(*) FROM activity_log');
    $n = ActivityView::purge();
    T::ok($n === 1 && (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE created_at < ?", [Clock::shift(-30 * 86400)]) === 0, 'purga: borra solo lo mas viejo que 30 dias (la fila de hace 40 dias)');
    T::ok((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'activity_purge'") === 1 && (int) Db::value('SELECT COUNT(*) FROM activity_log') === $before - 1 + 1, '  y deja una fila «activity_purge» que cuenta lo borrado');
    T::eq(ActivityView::purge(), 0, 'purga sin nada viejo: 0 y no deja fila');
    Settings::set('activity.retention_days', 365);

    // ------------------------------------------------------------------ HTTP
    $s = Panel::start($ctx, 'panel-act', ['REAUTH_SECONDS' => '600']);
    $anon = $s->another();
    T::eq($anon->get('/actividad')['status'], 303, 'sin sesion: /actividad redirige a /login');
    T::eq($anon->post('/actividad/export', ['qs' => ''])['status'], 303, 'sin sesion: exportar redirige a /login');
    T::ok($s->login($a['email']), 'login del administrador');
    $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15';
    $viewer = Panel::user('viewer');
    $v = $s->another();
    $g = $v->get('/login');
    $v->post('/login', ['_csrf' => $v->csrfOf($g['body']), 'email' => $viewer['email'], 'password' => Panel::PASSWORD], ['User-Agent: ' . $ua]);
    $g = $v->get('/login/2fa');
    $v->post('/login/2fa', ['_csrf' => $v->csrfOf($g['body']), 'code' => Totp::code(Panel::SECRET)], ['User-Agent: ' . $ua]);
    $v->csrf = $v->csrfOf($v->get('/')['body']);
    T::eq(Db::value("SELECT agent FROM activity_log WHERE action = 'login_ok' AND user_id = ? ORDER BY id DESC LIMIT 1", [$viewer['id']]), 'Safari 17 · macOS', 'el login por HTTP guarda el agente resumido (no el user agent completo)');
    T::eq($v->get('/actividad')['status'], 403, 'lector: /actividad responde 403');
    T::eq($v->post('/actividad/export', ['_csrf' => $v->csrf, 'qs' => ''])['status'], 403, 'lector: exportar responde 403');
    T::eq($v->post('/actividad/retention', ['_csrf' => $v->csrf, 'days' => '60'])['status'], 403, 'lector: cambiar la retencion responde 403');
    T::ok(!str_contains($v->get('/leads')['body'], 'href="/actividad"'), 'el lector no ve «Actividad» en la barra lateral');
    $ins('note_add', $a['id'], ['x' => '<script>alert(1)</script>'], 'lead', '<img src=x onerror=1>');
    $pg = $s->get('/actividad');
    T::ok($pg['status'] === 200 && str_contains($pg['body'], 'Inicio de sesión') && str_contains($pg['body'], 'Chrome 130 · Windows') && str_contains($pg['body'], 'href="/actividad?page=2"'), '/actividad: tabla con acciones legibles, agente y paginacion');
    T::ok(!preg_match('/\sstyle="/i', $pg['body']) && !preg_match('/\son\w+="/i', $pg['body']) && preg_match('/<script(?![^>]*\bnonce=")/i', $pg['body']) === 0, '  sin estilos ni handlers en linea, scripts con nonce');
    T::ok(!str_contains($pg['body'], '<script>alert(1)</script>') && !str_contains($pg['body'], '<img src=x'), '  los detalles con HTML salen escapados');
    $pf = $s->get('/actividad?ty=config&us=' . $b['id'] . '&q=alerts');
    T::ok($pf['status'] === 200 && str_contains($pf['body'], 'followup_minutes') && !str_contains($pf['body'], 'Inicio de sesión') && str_contains($pf['body'], 'fchip'), 'filtros por URL: muestra solo lo filtrado y los chips activos');
    T::ok(str_contains($s->get('/actividad?q=zzzz-nada')['body'], 'Nada coincide con esos filtros'), 'estado vacio con filtros: «Nada coincide con esos filtros»');
    T::eq($s->get('/actividad?ty=%27%3B&page=-5&from=%00&us=9999999999999')['status'], 200, 'parametros hostiles: 200');
    T::eq($s->get('/actividad/inventada')['status'], 404, '/actividad/inventada: 404');
    // exportar
    $r = $s->post('/actividad/export', ['qs' => 'ty=login']);
    T::ok(!str_contains(Infra::header($r, 'content-type'), 'text/csv'), 'exportar sin CSRF: no entrega CSV');
    $r = $s->post('/actividad/export', ['_csrf' => $s->csrf, 'qs' => 'ty=login']);
    T::ok($r['status'] === 200 && str_contains(Infra::header($r, 'content-type'), 'text/csv') && str_contains(Infra::header($r, 'content-disposition'), 'attachment') && str_starts_with($r['body'], "\xEF\xBB\xBF") && str_contains($r['body'], 'Inicio de sesión') && !str_contains($r['body'], 'Cambio de etapa'), 'exportar con CSRF: CSV adjunto con los filtros aplicados');
    T::eq((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'activity_export'"), 1, '  la exportacion queda en el registro');
    // retencion con reautenticacion
    $r = $s->post('/actividad/retention', ['_csrf' => $s->csrf, 'days' => '90']);
    T::ok($r['status'] === 303 && ActivityView::retentionDays() === 365 && str_contains($s->get('/actividad')['body'], 'Confirmar para editar'), 'cambiar la retencion SIN confirmar identidad: se rechaza y no cambia');
    Db::run('UPDATE users SET totp_last_step = NULL WHERE id = ?', [$a['id']]);
    $r = $s->post('/config/unlock', ['_csrf' => $s->csrf, 'tab' => 'actividad', 'password' => Panel::PASSWORD, 'code' => Totp::code(Panel::SECRET)]);
    T::ok($r['status'] === 303 && Infra::header($r, 'location') === '/actividad#retencion', 'confirmar identidad desde /actividad: vuelve a /actividad');
    $s->post('/actividad/retention', ['_csrf' => $s->csrf, 'days' => '90']);
    Settings::clear();
    T::eq(ActivityView::retentionDays(), 90, 'con la confirmacion vigente: la retencion cambia a 90');
    $r = $s->post('/actividad/retention', ['_csrf' => $s->csrf, 'days' => '5']);
    Settings::clear();
    T::ok(ActivityView::retentionDays() === 90 && str_contains($s->get('/actividad')['body'], 'entre 30 y 3650'), 'retencion invalida por HTTP: mensaje y no cambia');
    $ex = Db::all("EXPLAIN SELECT a.id FROM activity_log a WHERE a.action IN ('login_ok','login_failed') ORDER BY a.id DESC LIMIT 50");
    T::ok(($ex[0]['key'] ?? null) !== null, 'EXPLAIN del filtro por tipo: usa indice (' . ($ex[0]['key'] ?? 'ninguno') . ')');
    Infra::stop('panel-act');
    Db::pdo()->exec("DELETE FROM settings WHERE `key` = 'activity.retention_days'");
    Settings::clear();
    T::reset(true);
};
