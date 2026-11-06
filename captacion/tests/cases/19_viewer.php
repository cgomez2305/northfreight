<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Services\Invites;
use Captacion\Services\ReportJobs;
use Captacion\Services\Settings;
use Captacion\Services\Totp;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/** Q3-B: lector de NorthStar. Invitacion de un solo uso, 2FA, y prueba NEGATIVA: el lector pide cada ruta del panel y de la API y no recibe ningun dato personal. */
return static function (array $ctx): void {
    T::reset(true);
    Settings::set('mail', []);
    Settings::clear();
    $admin = Panel::user('admin');
    $aid = (int) $admin['id'];
    $panel = Panel::start($ctx, 'panel19');
    T::ok($panel->login($admin['email']), 'login del administrador');
    $anon = $panel->another();

    // ------------------------------------------------------------------ Invitacion
    $r = $panel->post('/config/users/invite', ['_csrf' => $panel->csrf, 'name' => 'Ana NorthStar', 'email' => 'ana@northstar.test']);
    T::ok(Db::value("SELECT COUNT(*) FROM users WHERE email = 'ana@northstar.test'") == 0, 'invitar sin confirmar identidad: no crea la cuenta');
    T::ok($panel->unlock($aid, 'usuarios'), 'confirmar identidad (contraseña + código)');
    $r = $panel->post('/config/users/invite', ['_csrf' => $panel->csrf, 'name' => '', 'email' => 'no-es-correo']);
    T::ok($r['status'] === 422 && str_contains($r['body'], 'Escribe el nombre') && str_contains($r['body'], 'no es válido'), 'invitar con datos inválidos: 422 y mensajes');
    $r = $panel->post('/config/users/invite', ['_csrf' => $panel->csrf, 'name' => 'Ana NorthStar', 'email' => 'ana@northstar.test']);
    $pg = $panel->get('/config?tab=usuarios');
    preg_match('#<code>(http://panel\.test/invite/([a-f0-9]{64}))</code>#', $pg['body'], $m);
    T::ok($r['status'] === 303 && isset($m[2]), 'invitar: la cuenta se crea y el enlace de invitación se muestra UNA vez');
    $token = $m[2] ?? str_repeat('0', 64);
    $ana = Db::one("SELECT * FROM users WHERE email = 'ana@northstar.test'");
    T::ok($ana !== null && $ana['role'] === 'viewer' && (int) $ana['is_active'] === 1 && $ana['totp_confirmed_at'] === null && $ana['totp_secret'] === null, 'la cuenta nace como lector, sin 2FA y sin poder entrar');
    T::ok(Db::value('SELECT token_hash FROM user_invites WHERE user_id = ?', [$ana['id']]) === hash('sha256', $token) && !str_contains((string) Db::value('SELECT GROUP_CONCAT(token_hash) FROM user_invites'), $token), 'en la base solo queda el SHA-256 del token');
    T::ok(abs((int) Clock::parse((string) Db::value('SELECT expires_at FROM user_invites WHERE user_id = ?', [$ana['id']])) - (Clock::time() + 172800)) < 5, 'la invitación vale 48 horas');
    T::ok(!str_contains($panel->get('/config?tab=usuarios')['body'], $token), 'el enlace no vuelve a mostrarse');
    $r = $panel->post('/config/users/invite', ['_csrf' => $panel->csrf, 'name' => 'Otra', 'email' => 'ANA@northstar.test']);
    T::ok($r['status'] === 422 && str_contains($r['body'], 'Ya existe una cuenta'), 'correo repetido: se rechaza');
    // no puede entrar antes de activar
    $g = $anon->get('/login');
    $r = $anon->post('/login', ['_csrf' => $anon->csrfOf($g['body']), 'email' => 'ana@northstar.test', 'password' => Panel::PASSWORD]);
    T::eq($r['status'], 401, 'antes de activar, ni con la contraseña correcta puede entrar');
    // activacion
    $g = $anon->get('/invite/' . $token);
    T::ok($g['status'] === 200 && str_contains($g['body'], 'Ana NorthStar') && str_contains($g['body'], 'ana@northstar.test') && str_contains(Infra::header($g, 'x-robots-tag'), 'noindex'), 'GET /invite/{token}: formulario con el nombre, noindex');
    $csrf = $anon->csrfOf($g['body']);
    $r = $anon->post('/invite/' . $token, ['_csrf' => $csrf, 'action' => 'create', 'password' => 'corta', 'password2' => 'corta']);
    T::ok($r['status'] === 422 && str_contains($r['body'], 'al menos 12'), 'contraseña corta: 422');
    $r = $anon->post('/invite/' . $token, ['_csrf' => $csrf, 'action' => 'create', 'password' => 'una-clave-larga-123', 'password2' => 'otra-clave-larga-123']);
    T::ok($r['status'] === 422 && str_contains($r['body'], 'no coinciden'), 'contraseñas distintas: 422');
    $r = $anon->post('/invite/' . $token, ['_csrf' => 'falso', 'action' => 'create', 'password' => 'una-clave-larga-123', 'password2' => 'una-clave-larga-123']);
    T::eq($r['status'], 403, 'sin CSRF válido: 403');
    $r = $anon->post('/invite/' . $token, ['_csrf' => $csrf, 'action' => 'create', 'password' => 'una-clave-larga-123', 'password2' => 'una-clave-larga-123']);
    preg_match('#<p class="secret"[^>]*><code>([^<]+)</code>#', $r['body'], $sm);
    $secret = isset($sm[1]) ? str_replace(' ', '', $sm[1]) : '';
    T::ok($r['status'] === 200 && strlen($secret) >= 16 && substr_count($r['body'], '<li><code>') === 10, 'paso 2: clave TOTP y 10 códigos de recuperación');
    T::eq(Db::value('SELECT totp_confirmed_at FROM users WHERE id = ?', [$ana['id']]), null, '  la cuenta sigue inactiva hasta confirmar un código');
    $r = $anon->post('/invite/' . $token, ['_csrf' => $csrf, 'action' => 'confirm', 'code' => '000000']);
    T::ok($r['status'] === 422 && str_contains($r['body'], 'no es correcto') && Db::value('SELECT totp_confirmed_at FROM users WHERE id = ?', [$ana['id']]) === null, 'código TOTP incorrecto: no activa');
    $r = $anon->post('/invite/' . $token, ['_csrf' => $csrf, 'action' => 'confirm', 'code' => Totp::code($secret)]);
    $ana = Db::one('SELECT * FROM users WHERE id = ?', [$ana['id']]);
    T::ok($r['status'] === 200 && str_contains($r['body'], 'Cuenta activada') && $ana['totp_confirmed_at'] !== null && $ana['totp_secret'] !== null && !str_contains((string) $ana['totp_secret'], $secret), 'código correcto: cuenta activa y secreto TOTP cifrado');
    T::eq([(int) Db::value('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ?', [$ana['id']]), (int) Db::value('SELECT used_at IS NOT NULL FROM user_invites WHERE user_id = ?', [$ana['id']])], [10, 1], '  10 códigos guardados (solo hash) e invitación consumida');
    T::eq($anon->get('/invite/' . $token)['status'], 404, 'enlace reutilizado: 404');
    T::eq($anon->post('/invite/' . $token, ['_csrf' => $csrf, 'action' => 'confirm', 'code' => Totp::code($secret)])['status'], 404, '  también el POST');
    T::ok((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action IN ('user_invite','invite_accepted')") === 2, 'activity_log: user_invite e invite_accepted');
    T::eq($anon->get('/invite/' . str_repeat('a', 64))['status'], 404, 'token inventado: 404');
    T::eq($anon->get('/invite/corto')['status'], 404, 'token con formato inválido: 404');
    // invitacion caducada
    $b = Invites::invite('Beto Caduca', 'beto@northstar.test', $aid);
    $tkB = substr((string) $b['link'], -64);
    Db::run('UPDATE user_invites SET expires_at = ? WHERE user_id = ?', [Clock::shift(-10), $b['user_id']]);
    T::eq($anon->get('/invite/' . $tkB)['status'], 404, 'invitación caducada: 404');
    $rl = Invites::relink((int) $b['user_id'], $aid);
    $tkB2 = substr((string) $rl['link'], -64);
    T::ok($rl['ok'] && $tkB2 !== $tkB && Invites::find($tkB2) !== null && Invites::find($tkB) === null, 'enlace nuevo: invalida el anterior y vale');
    T::ok(!Invites::relink((int) $ana['id'], $aid)['ok'], 'no se emite enlace nuevo a una cuenta ya activada');
    T::ok(!Invites::setActive($aid, false, $aid)['ok'] && !Invites::reset2fa($aid, $aid)['ok'], 'un administrador no se desactiva ni se reinicia desde aquí');

    // ------------------------------------------------------------------ Datos con nombres distintivos para la prueba negativa
    $pii = ['Zebulon', 'Quixote', '3055557777', '305-555-7777', 'zebulon.quixote@example.com', '9876543', 'Marigold', 'Tuxedo', '/secret-landing-page/'];
    $lA = Fx::lead(['first_name' => 'Zebulon', 'last_name' => 'Quixote', 'phone_e164' => '+13055557777', 'email' => 'zebulon.quixote@example.com', 'usdot' => '9876543', 'stage' => 'signed', 'signed_at' => Clock::shift(-3 * 86400),
        'first_contact_at' => Clock::shift(-4 * 86400), 'handoff_status' => 'signed', 'landing_page' => '/secret-landing-page/?x=1', 'created_at' => Clock::shift(-5 * 86400), 'temperature' => 'hot']);
    $lB = Fx::lead(['first_name' => 'Marigold', 'last_name' => 'Tuxedo', 'stage' => 'lost', 'lost_reason' => 'price', 'created_at' => Clock::shift(-6 * 86400)]);
    Db::insert('lead_notes', ['lead_id' => $lA, 'body' => 'Nota privada de Zebulon', 'user_id' => $aid]);
    Db::insert('tasks', ['title' => 'Llamar a Quixote', 'lead_id' => $lA, 'priority' => 1, 'created_by' => $aid]);
    Db::insert('channel_costs', ['source' => 'web_careers', 'period' => ReportJobs::previousMonth(Clock::time()), 'amount' => '500.00']);
    $gen = ReportJobs::generateMonthly(ReportJobs::previousMonth(Clock::time()), $aid);
    $repId = (int) $gen['id'];
    T::ok($gen['ok'] && $gen['created'] && $repId > 0, 'se genera un informe mensual (borrador) para la prueba');
    $actions = ['Acción privada que menciona Zebulon Quixote', 'Segunda acción', 'Tercera acción'];
    ReportJobs::saveActions($repId, array_slice($actions, 1), $aid);   // 2 acciones; las del lector no deben contener PII que el admin escriba: se prueba mas abajo
    $draftId = $repId;
    $gen2 = ReportJobs::generateMonthly(ReportJobs::previousMonth(Clock::time() - 32 * 86400), $aid);
    $pubId = (int) $gen2['id'];
    ReportJobs::saveActions($pubId, ['Reforzar la captación de Meta', 'Cargar costos del mes', 'Revisar tareas vencidas'], $aid);
    ReportJobs::publish($pubId, $aid);

    // ------------------------------------------------------------------ El lector
    $viewer = $anon->another();
    $g = $viewer->get('/login');
    $viewer->post('/login', ['_csrf' => $viewer->csrfOf($g['body']), 'email' => 'ana@northstar.test', 'password' => 'una-clave-larga-123']);
    Db::run('UPDATE users SET totp_last_step = NULL WHERE id = ?', [$ana['id']]);
    $g = $viewer->get('/login/2fa');
    $r = $viewer->post('/login/2fa', ['_csrf' => $viewer->csrfOf($g['body']), 'code' => Totp::code($secret)]);
    T::ok($r['status'] === 303 && Infra::header($r, 'location') === '/resultados', 'el lector entra con contraseña + 2FA y aterriza en /resultados');
    $res = $viewer->get('/resultados');
    $viewer->csrf = $viewer->csrfOf($res['body']);
    T::ok($res['status'] === 200 && str_contains($res['body'], 'Resultados de captación') && $viewer->csrf !== '', '/resultados: 200 con el dashboard agregado');
    $leakCheck = static function (string $body, string $where) use ($pii, $lA, $lB): void {
        $hits = array_filter($pii, static fn($p) => str_contains($body, $p));
        T::ok(!$hits, "$where: ninguna cadena personal en la respuesta" . ($hits ? ' (aparece: ' . implode(', ', $hits) . ')' : ''));
        T::ok(preg_match('#/leads/\d+#', $body) !== 1 && !str_contains($body, 'wa.me') && !str_contains($body, 'mailto:') && !str_contains($body, 'tel:'), "$where: sin enlaces a leads, WhatsApp, correo ni teléfono");
    };
    $leakCheck($res['body'], '/resultados');
    foreach (['?r=90d', '?r=lastmonth', '?r=year', '?r=inventado', '?from=2020-01-01&to=2026-01-01'] as $q) {
        $x = $viewer->get('/resultados' . $q);
        T::ok($x['status'] === 200 && !preg_match('/Zebulon|Quixote|9876543/', $x['body']), "/resultados$q: 200 y sin datos personales");
    }
    T::ok(!str_contains($viewer->get('/resultados?from=2020-01-01&to=2026-01-01')['body'], 'name="from"'), 'el lector no tiene rango personalizado (solo presets)');
    T::ok(!str_contains($res['body'], '/reportes/export') && !str_contains($res['body'], 'CSV') && !str_contains($res['body'], '/leads/export'), 'el lector no tiene exportaciones');
    $inf = $viewer->get('/informes');
    $leakCheck($inf['body'], '/informes');
    T::ok($inf['status'] === 200 && str_contains($inf['body'], 'href="/informes/' . $pubId . '"') && !str_contains($inf['body'], 'href="/informes/' . $draftId . '"') && !str_contains($inf['body'], 'RESÚMENES SEMANALES') && !str_contains($inf['body'], 'GENERAR UN INFORME'), '/informes: solo los publicados, sin borradores, sin resúmenes semanales ni generar');
    $one = $viewer->get('/informes/' . $pubId);
    $leakCheck($one['body'], '/informes/{publicado}');
    T::ok($one['status'] === 200 && str_contains($one['body'], 'Reforzar la captación de Meta') && !str_contains($one['body'], 'name="action[]"') && !str_contains($one['body'], '/publish'), 'informe publicado: lo ve con sus tres acciones, sin controles de edición');
    T::eq($viewer->get('/informes/' . $draftId)['status'], 404, 'un borrador no existe para el lector (404)');
    $weekly = Db::insert('reports', ['kind' => 'weekly', 'period' => '2026-W01', 'ident' => 'RES-2026-W01', 'metrics' => '{"x":1}', 'status' => 'draft']);
    T::eq($viewer->get('/informes/' . $weekly)['status'], 404, 'un resumen semanal no existe para el lector (404)');
    T::eq($viewer->get('/informes/99999')['status'], 404, 'informe inexistente: 404');

    // ------------------------------------------------------------------ Prueba negativa: CADA ruta del panel y de la API
    $counts = static fn(): array => [
        (int) Db::value('SELECT COUNT(*) FROM leads'), (int) Db::value('SELECT COUNT(*) FROM tasks'), (int) Db::value('SELECT COUNT(*) FROM lead_notes'), (int) Db::value('SELECT COUNT(*) FROM users'),
        (int) Db::value('SELECT COUNT(*) FROM email_templates'), (int) Db::value("SELECT COUNT(*) FROM reports WHERE status = 'published'"), (int) Db::value('SELECT COUNT(*) FROM jobs'), (int) Db::value('SELECT COUNT(*) FROM sequence_enrollments'),
        Db::value("SELECT stage FROM leads WHERE id = $lA"), (int) Db::value('SELECT COUNT(*) FROM activity_log WHERE action IN (\'leads_export\',\'report_export\')'),
    ];
    $before = $counts();
    $csrf = $viewer->csrf;
    $getRoutes = ['/leads', "/leads/$lA", "/leads/$lB", '/leads?q=Zebulon', '/leads?t=hot&ct=none', '/pipeline', '/pipeline?pl=B', '/tareas', '/tareas?tab=overdue', '/config', '/config?tab=alertas', '/config?tab=costos', '/config?tab=integraciones', '/config?tab=horario', '/config?tab=copias',
        '/config?tab=entrega', '/config?tab=correo', '/config?tab=usuarios', '/actividad', '/actividad?q=Zebulon', '/reportes', '/reportes?r=90d', '/comunicaciones', '/comunicaciones/plantillas', '/comunicaciones/plantillas/1', '/comunicaciones/plantillas/new', '/comunicaciones/inscripciones',
        '/comunicaciones/envios', '/comunicaciones/reactivacion', '/config?tab=canales', '/internal/ping', '/internal/sequences/due', '/api/webhooks/meta.php', '/api/webhooks/calcom.php', '/api/webhooks/sms.php', '/api/webhooks/n8n.php', '/ajax/search', '/vistas/new', '/undo', '/ajax/notifications/read', '/ajax/pipeline/cards', '/ajax/pipeline/move', "/informes/$draftId", '/informes/generate', "/informes/$pubId/publish", "/informes/$pubId/actions", '/setup.php', '/api/lead.php', '/config/backups/download',
        '/leads/export', '/leads/bulk', '/reportes/export'];
    $bad = [];
    foreach ($getRoutes as $path) {
        $r = $viewer->get($path);
        $loc = Infra::header($r, 'location');
        $okStatus = in_array($r['status'], [403, 404, 405, 401, 503], true) || ($r['status'] === 303 && in_array($loc, ['/login', '/resultados'], true));
        $leak = array_filter($pii, static fn($p) => str_contains($r['body'], $p)) || preg_match('#/leads/\d+#', $r['body']) === 1;
        if (!$okStatus || $leak) {
            $bad[] = "GET $path -> {$r['status']}" . ($leak ? ' (FUGA)' : '');
        }
    }
    T::ok(!$bad, 'lector, ' . count($getRoutes) . ' rutas por GET: todas 403/404/405 (o redirigen a /resultados) y ninguna filtra datos' . ($bad ? ': ' . implode('; ', $bad) : ''));
    $postRoutes = ['/leads/bulk' => ['ids' => [$lA], 'do' => 'export'], '/leads/export' => ['scope' => 'period', 'period' => 'month'], "/leads/$lA/stage" => ['stage' => 'contacted'], "/leads/$lA/note" => ['body' => 'x'], "/leads/$lA/task" => ['title' => 'x'], "/leads/$lA/call" => ['outcome' => 'answered'],
        "/leads/$lA/revoke" => [], "/leads/$lA/reopen" => [], "/leads/$lA/handoff-signed" => [], "/leads/$lA/handoff-rejected" => ['reason' => 'price'], "/leads/$lA/handoff-resend" => [], "/leads/$lA/seq-pause" => ['enrollment_id' => 1], "/leads/$lA/responded" => [],
        '/tareas/new' => ['title' => 'x'], '/tareas/1/done' => [], '/tareas/1/delete' => [], '/config/alerts' => ['hot_telegram' => '1'], '/config/unlock' => ['password' => 'x', 'code' => '1'], '/config/handoff/save' => ['email' => 'x@x.com'], '/config/handoff/test' => [],
        '/config/mail/save' => ['enabled' => '1'], '/config/users/invite' => ['name' => 'Intruso', 'email' => 'intruso@example.com'], '/config/users/deactivate' => ['id' => $aid], '/config/users/reset' => ['id' => $aid], '/config/backups/download' => ['id' => 1], '/config/backups/run' => [],
        '/actividad/export' => [], '/reportes/export' => ['table' => 'canales'], '/informes/generate' => ['period' => ReportJobs::previousMonth(Clock::time() - 90 * 86400)], "/informes/$draftId/publish" => [], "/informes/$draftId/actions" => ['action' => ['x']], "/informes/$pubId/resend" => [],
        '/comunicaciones/plantillas/new' => ['name' => 'Intruso', 'lang' => 'en', 'subject' => 'abc', 'body' => str_repeat('x', 30)], '/comunicaciones/plantillas/1/save' => ['subject' => 'x'], '/comunicaciones/plantillas/1/test' => ['to' => 'a@b.com'], '/comunicaciones/inscripciones/1/pause' => [], '/comunicaciones/reactivacion' => ['enabled' => '1', 'min_days' => '60'], '/config/canales/meta' => ['consent_text' => 'x'], '/config/canales/n8n' => ['wa_steps' => ['a1_welcome']], '/config/integrations/test' => ['name' => 'meta']];
    $badP = [];
    foreach ($postRoutes as $path => $body) {
        $r = $viewer->post($path, ['_csrf' => $csrf] + $body);
        $isCsv = str_contains(Infra::header($r, 'content-type'), 'csv');
        $leak = array_filter($pii, static fn($p) => str_contains($r['body'], $p));
        if (!in_array($r['status'], [403, 404, 405, 401], true) || $isCsv || $leak) {
            $badP[] = "POST $path -> {$r['status']}" . ($isCsv ? ' (CSV)' : '') . ($leak ? ' (FUGA)' : '');
        }
    }
    T::ok(!$badP, 'lector, ' . count($postRoutes) . ' rutas por POST con CSRF válido: todas rechazadas, sin CSV ni datos' . ($badP ? ': ' . implode('; ', $badP) : ''));
    foreach (['/ajax/search' => ['q' => 'Zebulon'], '/ajax/notifications/read' => [], '/ajax/pipeline/move' => ['lead_id' => $lA, 'to' => 'contacted'], '/ajax/pipeline/cards' => ['stage' => 'new'], '/ajax/pipeline/card' => ['lead_id' => $lA], '/ajax/peek' => ['id' => $lA], '/ajax/lead/act' => ['id' => $lA, 'act' => 'note', 'body' => 'x'], '/ajax/lead/create' => ['name' => 'X', 'phone' => '3055551234', 'profile' => 'owner_operator'], '/ajax/undo' => ['token' => 'x'], '/ajax/counters' => []] as $path => $body) {
        $r = $viewer->ajax($path, $body);
        T::ok($r['status'] === 403 && str_contains($r['body'], 'forbidden') && !str_contains($r['body'], 'Zebulon'), "lector: $path (JSON) responde 403 forbidden");
    }
    T::eq($counts(), $before, 'tras todo eso no cambió nada en la base (leads, tareas, notas, usuarios, plantillas, informes, jobs, inscripciones, etapa, exportaciones)');
    $api = Infra::http('POST', $panel->base . '/api/lead.php', ['json' => ['name' => 'X', 'profile' => 'owner_operator'], 'jar' => $viewer->jar]);
    T::ok(in_array($api['status'], [401, 503], true) && !str_contains($api['body'], 'lead_id'), 'API /api/lead.php con la cookie del lector: sin Bearer no entra (' . $api['status'] . ')');
    $cookieOnly = Infra::http('GET', $panel->base . '/leads/' . $lA, ['jar' => $viewer->jar]);
    T::ok($cookieOnly['status'] === 403 && !str_contains($cookieOnly['body'], 'Zebulon'), 'la ficha del lead por URL directa: 403 sin nombre');
    $pwd = $viewer->get('/resultados');
    T::ok(!str_contains($pwd['body'], 'data-search') && !str_contains($pwd['body'], 'dd-notes') && !str_contains($pwd['body'], 'Atajos') && !str_contains($pwd['body'], 'Administración') && !str_contains($pwd['body'], 'Estado del sistema'), 'el shell del lector no trae buscador, campana, atajos, administración ni estado del sistema');

    // ------------------------------------------------------------------ Gestion de usuarios (administrador)
    $users = $panel->get('/config?tab=usuarios');
    T::ok(str_contains($users['body'], 'Ana NorthStar') && str_contains($users['body'], 'Activa') && str_contains($users['body'], 'Beto Caduca') && str_contains($users['body'], 'Invitación pendiente') && str_contains($users['body'], 'Reiniciar 2FA'), 'Usuarios: lista con estado, último acceso y acciones');
    T::ok($ana['last_login_at'] === null && Db::value('SELECT last_login_at FROM users WHERE id = ?', [$ana['id']]) !== null, 'el último acceso se registra al entrar');
    $r = $panel->post('/config/users/deactivate', ['_csrf' => $panel->csrf, 'id' => (string) $ana['id']]);
    T::ok($r['status'] === 303 && Db::value('SELECT is_active FROM users WHERE id = ?', [$ana['id']]) == 0, 'desactivar: la cuenta queda inactiva');
    T::eq($viewer->get('/resultados')['status'], 303, '  y su sesión abierta deja de valer (redirige a /login)');
    $v2 = $anon->another();
    $g = $v2->get('/login');
    $r = $v2->post('/login', ['_csrf' => $v2->csrfOf($g['body']), 'email' => 'ana@northstar.test', 'password' => 'una-clave-larga-123']);
    T::eq($r['status'], 401, '  y no puede volver a entrar');
    $panel->post('/config/users/activate', ['_csrf' => $panel->csrf, 'id' => (string) $ana['id']]);
    T::ok(Db::value('SELECT is_active FROM users WHERE id = ?', [$ana['id']]) == 1, 'activar: vuelve a estar activa');
    $viewer2 = $anon->another();
    $g = $viewer2->get('/login');
    $viewer2->post('/login', ['_csrf' => $viewer2->csrfOf($g['body']), 'email' => 'ana@northstar.test', 'password' => 'una-clave-larga-123']);
    Db::run('UPDATE users SET totp_last_step = NULL WHERE id = ?', [$ana['id']]);
    $g = $viewer2->get('/login/2fa');
    $viewer2->post('/login/2fa', ['_csrf' => $viewer2->csrfOf($g['body']), 'code' => Totp::code($secret)]);
    T::eq($viewer2->get('/resultados')['status'], 200, 'reactivada, vuelve a entrar');
    $r = $panel->post('/config/users/reset', ['_csrf' => $panel->csrf, 'id' => (string) $ana['id']]);
    $after = Db::one('SELECT * FROM users WHERE id = ?', [$ana['id']]);
    T::ok($r['status'] === 303 && $after['totp_confirmed_at'] === null && $after['totp_secret'] === null && (int) Db::value('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ?', [$ana['id']]) === 0, 'reiniciar 2FA: borra secreto y códigos');
    T::eq($viewer2->get('/resultados')['status'], 303, '  y su sesión abierta deja de valer');
    preg_match('#<code>http://panel\.test/invite/([a-f0-9]{64})</code>#', $panel->get('/config?tab=usuarios')['body'], $m2);
    T::ok(isset($m2[1]) && Invites::find($m2[1]) !== null, '  y deja un enlace nuevo de un solo uso para repetir el alta');
    T::ok((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action IN ('user_deactivate','user_activate','user_reset_2fa','user_relink')") === 4, 'activity_log: desactivar, activar, reiniciar 2FA y enlace nuevo');
    // otro administrador sin confirmacion reciente
    $adm2 = Panel::user('admin');
    $p2 = $panel->another();
    T::ok($p2->login($adm2['email']), 'segundo administrador');
    $r = $p2->post('/config/users/deactivate', ['_csrf' => $p2->csrf, 'id' => (string) $ana['id']]);
    T::ok(Db::value('SELECT is_active FROM users WHERE id = ?', [$ana['id']]) == 1, 'sin confirmación reciente de identidad no se desactiva a nadie');
    $r = $p2->post('/config/users/deactivate', ['_csrf' => 'falso', 'id' => (string) $ana['id']]);
    T::ok(Db::value('SELECT is_active FROM users WHERE id = ?', [$ana['id']]) == 1, 'ni sin CSRF');
    Infra::stop('panel19');
    T::reset(true);
};
