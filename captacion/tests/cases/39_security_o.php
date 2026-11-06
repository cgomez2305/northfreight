<?php
declare(strict_types=1);

use Captacion\Db;
use Captacion\Services\Session;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/**
 * Fase O (endurecimiento). Pruebas de regresion de seguridad:
 *  - MATRIZ de rutas por rol (anonimo, lector, administrador): cada `case` de public/index.php debe tener al menos una ruta de muestra en esta prueba, y el anonimo y el lector no reciben nada donde no deben.
 *  - Cabeceras (CSP sin inline, marcos, no-store, noindex, HSTS tras proxy HTTPS), cookie de sesion, fijacion de sesion, caducidad absoluta, IDOR de vistas guardadas, redireccion abierta,
 *    limites de intentos de los enlaces publicos y setup.php cerrado tras el alta.
 */
return static function (array $ctx): void {
    T::reset(true);
    $admin = Panel::user('admin');
    $admin2 = Panel::user('admin');
    $panel = Panel::start($ctx, 'panel39');
    T::ok($panel->login($admin['email']), 'login del administrador');
    $anon = $panel->another();

    // ------------------------------------------------------------------ Cobertura: toda ruta de public/index.php tiene muestra
    $indexSrc = (string) file_get_contents($ctx['root'] . '/public/index.php');
    preg_match_all('/case \$path === \'([^\']+)\'/', $indexSrc, $m1);
    preg_match_all('/case preg_match\(\'(#[^\']+#)\', \$path/', $indexSrc, $m2);
    $token40 = str_repeat('a', 40);
    $hex64 = str_repeat('b', 64);
    $tok20 = str_repeat('c', 24);
    $samples = [
        '/', '/leads', '/leads/bulk', '/tabla', '/tabla/export', '/tabla/cols', '/calendario', '/calendario/move', '/ajax/calendar/move', '/cronologia', '/ajax/timeline', '/leads/export', '/ajax/dashboard', '/ajax/search',
        '/ajax/notifications/read', '/ajax/prefs', '/ajax/peek', '/ajax/lead/act', '/ajax/lead/create', '/ajax/undo', '/ajax/counters', '/ajax/rules/panel', '/ajax/rules/row', '/ajax/rules/preview', '/ajax/rules/simulate',
        '/ajax/rules/simstatus', '/undo', '/vistas/new', '/vistas/1', '/vistas/1/rename', '/vistas/1/pin', '/vistas/1/unpin', '/vistas/1/delete', '/vistas/1/update', '/pipeline', '/ajax/pipeline/cards', '/ajax/pipeline/card',
        '/ajax/pipeline/move', '/tareas', '/tareas/new', '/tareas/1/done', '/tareas/1/delete', '/config', '/config/alerts', '/config/users/invite', '/config/rules/save', '/actividad', '/actividad/export', '/prospectos',
        '/prospectos/export', '/prospectos/1', '/prospectos/1/result', '/prospectos/1/convert', '/referidos', '/referidos/new', '/referidos/confirm', '/referidos/1', '/referidos/1/approve', '/vacantes', '/vacantes/new',
        '/vacantes/1', '/vacantes/1/pause', '/resultados', '/reportes', '/reportes/export', '/informes', '/informes/1', '/informes/1/publish', '/informes/generate', '/comunicaciones', '/comunicaciones/plantillas',
        '/comunicaciones/plantillas/1', '/comunicaciones/plantillas/new', '/comunicaciones/plantillas/1/save', '/comunicaciones/envios', '/c/' . $token40, '/invite/' . $hex64, '/u/' . $tok20, '/login', '/login/2fa', '/logout',
        '/leads/1', '/leads/1/stage', '/leads/1/note', '/leads/1/task',
    ];
    $uncovered = [];
    foreach ($m1[1] as $p) {
        if (!in_array($p, $samples, true)) {
            $uncovered[] = $p;
        }
    }
    foreach ($m2[1] as $rx) {
        $hit = false;
        foreach ($samples as $s) {
            if (@preg_match($rx, $s) === 1) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            $uncovered[] = $rx;
        }
    }
    T::ok(count($m1[1]) + count($m2[1]) > 40 && !$uncovered, 'cada ruta de public/index.php (' . (count($m1[1]) + count($m2[1])) . ') tiene una ruta de muestra en la matriz' . ($uncovered ? ': sin muestra ' . implode(', ', $uncovered) : ''));

    // ------------------------------------------------------------------ Matriz: anonimo
    $publicAnon = ['/login', '/login/2fa', '/logout', '/c/' . $token40, '/invite/' . $hex64, '/u/' . $tok20];
    $badA = [];
    $badAP = [];
    foreach ($samples as $path) {
        $r = $anon->get($path);
        $loc = Infra::header($r, 'location');
        $denied = in_array($r['status'], [401, 403, 404, 405], true) || ($r['status'] === 303 && in_array($loc, ['/login', '/'], true)) || ($r['status'] === 302 && $loc === '/login');
        if (in_array($path, $publicAnon, true)) {
            $denied = $r['status'] < 500;   // publicas: solo se exige que no fallen
        }
        if (!$denied) {
            $badA[] = "GET $path -> {$r['status']}";
        }
        if (!in_array($path, $publicAnon, true)) {
            $rp = $anon->post($path, ['_csrf' => 'falso', 'id' => '1']);
            if (!in_array($rp['status'], [303, 401, 403, 404, 405], true) || ($rp['status'] === 303 && Infra::header($rp, 'location') !== '/login')) {
                $badAP[] = "POST $path -> {$rp['status']}";
            }
            $rj = Infra::http('POST', $panel->base . $path, ['json' => ['x' => 1], 'headers' => ['X-CSRF-Token: falso']]);
            if (!in_array($rj['status'], [303, 401, 403, 404, 405], true)) {
                $badAP[] = "POST(json) $path -> {$rj['status']}";
            }
        }
    }
    T::ok(!$badA, 'anónimo, ' . count($samples) . ' rutas por GET: ninguna entrega contenido del panel' . ($badA ? ': ' . implode('; ', $badA) : ''));
    T::ok(!$badAP, 'anónimo, POST sin sesión (CSRF falso y JSON): ninguna escribe ni responde con datos' . ($badAP ? ': ' . implode('; ', $badAP) : ''));
    $nobody = $anon->get('/leads?q=a');
    T::ok(!str_contains($nobody['body'], 'Bandeja') && !str_contains($nobody['body'], 'data-csrf'), 'anónimo: la bandeja no se filtra ni a medias');

    // ------------------------------------------------------------------ Matriz: lector (cuenta viewer real, con 2FA)
    $vUser = Panel::user('viewer');
    $viewer = $panel->another();
    T::ok($viewer->login($vUser['email']), 'login del lector');
    $viewerOk = ['/resultados', '/informes', '/informes/1', '/login', '/logout', '/c/' . $token40, '/invite/' . $hex64, '/u/' . $tok20];
    $badV = [];
    $badVP = [];
    foreach ($samples as $path) {
        $r = $viewer->get($path);
        $loc = Infra::header($r, 'location');
        $denied = in_array($r['status'], [403, 404, 405, 401], true) || ($r['status'] === 303 && in_array($loc, ['/login', '/resultados', '/'], true));
        if (in_array($path, $viewerOk, true)) {
            $denied = $r['status'] < 500;
        }
        if ($path === '/') {
            $denied = $r['status'] === 303 && $loc === '/resultados' || $r['status'] === 403;
        }
        if (!$denied) {
            $badV[] = "GET $path -> {$r['status']}";
        }
        if (!in_array($path, $viewerOk, true)) {
            $rp = $viewer->post($path, ['_csrf' => $viewer->csrf, 'id' => '1']);
            if (!in_array($rp['status'], [403, 404, 405, 401], true) && !($rp['status'] === 303 && in_array(Infra::header($rp, 'location'), ['/resultados', '/login'], true))) {
                $badVP[] = "POST $path -> {$rp['status']}";
            }
            $rj = $viewer->ajax($path, ['x' => 1]);
            if (!in_array($rj['status'], [403, 404, 405, 401, 303], true)) {
                $badVP[] = "POST(json) $path -> {$rj['status']}";
            }
        }
    }
    T::ok(!$badV, 'lector, ' . count($samples) . ' rutas por GET: solo /resultados e /informes entregan contenido' . ($badV ? ': ' . implode('; ', $badV) : ''));
    T::ok(!$badVP, 'lector, POST con CSRF válido (formulario y JSON): ninguna escribe' . ($badVP ? ': ' . implode('; ', $badVP) : ''));

    // ------------------------------------------------------------------ Administrador: las rutas de muestra existen y responden sin error de servidor
    $bad5 = [];
    foreach ($samples as $path) {
        if (in_array($path, ['/logout', '/leads/export', '/tabla/export', '/actividad/export', '/reportes/export', '/prospectos/export'], true)) {
            continue;
        }
        $r = $panel->get($path);
        if ($r['status'] >= 500) {
            $bad5[] = "GET $path -> {$r['status']}";
        }
    }
    T::ok(!$bad5, 'administrador: ninguna ruta de muestra da error 5xx por GET' . ($bad5 ? ': ' . implode('; ', $bad5) : ''));

    // ------------------------------------------------------------------ Cabeceras
    $h = $panel->get('/leads');
    $csp = Infra::header($h, 'content-security-policy');
    T::ok($csp !== '' && !str_contains($csp, "'unsafe-inline'") && !str_contains($csp, "'unsafe-eval'") && str_contains($csp, "frame-ancestors 'none'") && str_contains($csp, "default-src 'none'") && str_contains($csp, "base-uri 'none'"), 'CSP del panel: default-src none, sin unsafe-inline ni unsafe-eval, sin marcos');
    T::ok(Infra::header($h, 'x-frame-options') === 'DENY' && str_contains(Infra::header($h, 'cache-control'), 'no-store') && str_contains(Infra::header($h, 'x-robots-tag'), 'noindex') && Infra::header($h, 'x-content-type-options') === 'nosniff'
        && Infra::header($h, 'referrer-policy') === 'no-referrer' && Infra::header($h, 'permissions-policy') !== '', 'cabeceras del panel: X-Frame-Options DENY, no-store, noindex, nosniff, Referrer-Policy no-referrer, Permissions-Policy');
    T::ok(Infra::header($h, 'strict-transport-security') === '', 'sin HSTS por http plano (solo tras HTTPS)');
    $hs = $panel->get('/login', ['X-Forwarded-Proto: https']);
    T::ok(str_contains(Infra::header($hs, 'strict-transport-security'), 'max-age=31536000'), 'HSTS de un año cuando la petición llega por HTTPS (proxy)');
    $nf = $anon->get('/no-existe-xyz');
    T::ok($nf['status'] === 404 && Infra::header($nf, 'content-security-policy') !== '' && str_contains(Infra::header($nf, 'x-robots-tag'), 'noindex'), '404 del panel: también con CSP y noindex');
    $js = Infra::http('GET', $panel->base . '/ajax/counters', ['jar' => $panel->jar]);
    T::ok($js['status'] < 500 && str_contains(Infra::header($js, 'cache-control'), 'no-store') && Infra::header($js, 'content-security-policy') !== '', 'respuestas JSON: no-store y CSP');
    $setup = Infra::http('GET', $panel->base . '/setup.php');
    T::eq($setup['status'], 404, 'setup.php con el administrador ya creado: 404 (cerrado para siempre)');
    T::eq(Infra::http('POST', $panel->base . '/setup.php', ['form' => ['setup_token' => str_repeat('s', 64), 'name' => 'X', 'email' => 'x@example.com', 'password' => 'una-clave-larga-123', 'password2' => 'una-clave-larga-123']])['status'], 404, '  también el POST');

    // ------------------------------------------------------------------ Cookie de sesion y fijacion
    $fresh = $panel->another();
    $g = $fresh->get('/login');
    $cookieLine = implode(' | ', (array) ($g['headers']['set-cookie'] ?? []));
    T::ok(str_contains($cookieLine, 'NSCRM=') && stripos($cookieLine, 'HttpOnly') !== false && stripos($cookieLine, 'SameSite=Lax') !== false, 'cookie de sesión: HttpOnly y SameSite=Lax' . ($cookieLine === '' ? ' (sin Set-Cookie)' : ''));
    preg_match('/NSCRM=([^;]+)/', $cookieLine, $cm);
    $preId = $cm[1] ?? '';
    $csrf0 = $fresh->csrfOf($g['body']);
    $fresh->post('/login', ['_csrf' => $csrf0, 'email' => $admin2['email'], 'password' => Panel::PASSWORD]);
    Db::run('UPDATE users SET totp_last_step = NULL WHERE id = ?', [$admin2['id']]);
    $g2 = $fresh->get('/login/2fa');
    $r2 = $fresh->post('/login/2fa', ['_csrf' => $fresh->csrfOf($g2['body']), 'code' => \Captacion\Services\Totp::code(Panel::SECRET)]);
    $postCookie = implode(' | ', (array) ($r2['headers']['set-cookie'] ?? []));
    preg_match('/NSCRM=([^;]+)/', $postCookie, $cm2);
    $postId = $cm2[1] ?? '';
    T::ok($r2['status'] === 303 && $preId !== '' && $postId !== '' && $postId !== $preId, 'fijación de sesión: el ID cambia al iniciar sesión (antes y después son distintos)');
    $old = Infra::http('GET', $panel->base . '/leads', ['headers' => ['Cookie: NSCRM=' . $preId]]);
    T::ok($old['status'] === 303 && Infra::header($old, 'location') === '/login', 'el ID anterior al login no da acceso');
    $pend = Infra::http('GET', $panel->base . '/leads', ['headers' => ['Cookie: NSCRM=' . $preId]]);
    T::ok(!str_contains($pend['body'], 'Bandeja'), 'y no entrega la bandeja');
    // ID inventado
    $fake = Infra::http('GET', $panel->base . '/leads', ['headers' => ['Cookie: NSCRM=' . str_repeat('x', 48)]]);
    T::ok($fake['status'] === 303 && Infra::header($fake, 'location') === '/login', 'un ID de sesión inventado se ignora (strict mode)');
    // logout invalida
    $keep = $postId;
    $csrfLogout = $fresh->csrfOf($fresh->get('/')['body']);
    $fresh->post('/logout', ['_csrf' => $csrfLogout]);
    $after = Infra::http('GET', $panel->base . '/leads', ['headers' => ['Cookie: NSCRM=' . $keep]]);
    T::ok($after['status'] === 303 && Infra::header($after, 'location') === '/login', 'tras cerrar sesión, la cookie anterior ya no sirve');
    $getLogout = $panel->get('/logout');
    T::ok($getLogout['status'] === 303 && $panel->get('/')['status'] === 200, 'GET /logout no cierra la sesión (solo POST con CSRF)');

    // ------------------------------------------------------------------ Caducidad absoluta de sesion (aunque haya actividad)
    $abs = $panel->another();
    Db::run('UPDATE users SET totp_last_step = NULL WHERE id = ?', [$admin2['id']]);
    T::ok($abs->login($admin2['email']), 'segunda sesión de administrador');
    $jarTxt = (string) file_get_contents($abs->jar);
    preg_match('/NSCRM\s+(\S+)/', $jarTxt, $jm);
    $sid = urldecode($jm[1] ?? '');
    $file = $ctx['work'] . '/storage/sessions/sess_' . $sid;
    T::ok($sid !== '' && is_file($file), 'el archivo de sesión existe (storage/sessions)');
    $raw = is_file($file) ? (string) file_get_contents($file) : '';
    T::ok(str_contains($raw, 'login_at|i:'), 'la sesión guarda login_at');
    file_put_contents($file, (string) preg_replace('/login_at\|i:\d+;/', 'login_at|i:' . (time() - Session::absolute() - 60) . ';', $raw));
    $ex = $abs->get('/leads');
    T::ok($ex['status'] === 303 && Infra::header($ex, 'location') === '/login', 'una sesión más vieja que SESSION_ABSOLUTE_SECONDS caduca aunque esté activa');
    T::eq(Session::absolute(), 86400, 'la vida máxima por defecto es de 24 horas');

    // ------------------------------------------------------------------ IDOR: vistas guardadas de otro usuario
    $mine = $panel->post('/vistas/new', ['_csrf' => $panel->csrf, 'name' => 'Vista de A', 'screen' => 'leads', 'qs' => 't=hot']);
    $vid = (int) Db::value("SELECT id FROM saved_views WHERE name = 'Vista de A' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
    T::ok($mine['status'] === 303 && $vid > 0, 'el administrador A crea una vista guardada');
    $other = $panel->another();
    Db::run('UPDATE users SET totp_last_step = NULL WHERE id = ?', [$admin2['id']]);
    T::ok($other->login($admin2['email']), 'administrador B');
    $before = Db::one('SELECT name, pinned, deleted_at FROM saved_views WHERE id = ?', [$vid]);
    foreach (['rename' => ['name' => 'Robada'], 'pin' => [], 'delete' => [], 'update' => ['screen' => 'leads', 'qs' => 't=cold']] as $act => $body) {
        $other->post("/vistas/$vid/$act", ['_csrf' => $other->csrf, 'back' => '/leads'] + $body);
    }
    $afterV = Db::one('SELECT name, pinned, deleted_at, filters FROM saved_views WHERE id = ?', [$vid]);
    T::ok($afterV !== null && $afterV['name'] === 'Vista de A' && $afterV['deleted_at'] === null && (int) $afterV['pinned'] === (int) $before['pinned'] && !str_contains((string) $afterV['filters'], 'cold'), 'IDOR: B no puede renombrar, fijar, actualizar ni borrar la vista de A');
    $lst = $other->get('/leads');
    T::ok(!str_contains($lst['body'], 'Vista de A'), 'y la vista de A no aparece en la barra lateral de B');

    // ------------------------------------------------------------------ Redireccion abierta
    $ro = $panel->post('/vistas/new', ['_csrf' => $panel->csrf, 'name' => 'X redir', 'screen' => 'leads', 'qs' => '', 'back' => '//evil']);
    $rl = Infra::header($ro, 'location');
    $ro2 = $panel->post('/vistas/' . $vid . '/pin', ['_csrf' => $panel->csrf, 'back' => '//evil/x']);
    T::ok(!str_starts_with(Infra::header($ro2, 'location'), '//') && !str_starts_with($rl, '//') && !str_contains(Infra::header($ro2, 'location') . $rl, 'evil'), 'el parámetro back nunca produce una ruta relativa al protocolo (//host)');
    $ro3 = $panel->post('/vistas/' . $vid . '/pin', ['_csrf' => $panel->csrf, 'back' => 'https://evil.example/']);
    T::ok(!str_contains(Infra::header($ro3, 'location'), 'evil'), 'ni una URL absoluta');

    // ------------------------------------------------------------------ Enlaces publicos: limite de intentos por IP
    $codes = [];
    for ($i = 0; $i < 64; $i++) {
        $codes[] = $anon->get('/c/' . bin2hex(random_bytes(20)))['status'];
    }
    T::ok(in_array(429, $codes, true) && !array_filter($codes, static fn($c) => $c >= 500), '/c/{token}: adivinar tokens se frena con 429 (' . count(array_filter($codes, static fn($c) => $c === 429)) . ' bloqueadas de 64)');
    $codes = [];
    for ($i = 0; $i < 44; $i++) {
        $codes[] = $anon->get('/invite/' . bin2hex(random_bytes(32)))['status'];
    }
    T::ok(in_array(429, $codes, true) && !array_filter($codes, static fn($c) => $c >= 500), '/invite/{token}: también 429 tras 40 intentos/hora');

    // ------------------------------------------------------------------ Login: enumeracion y fuerza bruta
    $e1 = $anon->another();
    $g = $e1->get('/login');
    $r1 = $e1->post('/login', ['_csrf' => $e1->csrfOf($g['body']), 'email' => 'nadie-existe@example.com', 'password' => 'x']);
    $e2 = $anon->another();
    $g = $e2->get('/login');
    $r2 = $e2->post('/login', ['_csrf' => $e2->csrfOf($g['body']), 'email' => $admin['email'], 'password' => 'mala-clave-larga']);
    T::ok($r1['status'] === 401 && $r2['status'] === 401 && strip_tags($r1['body']) === strip_tags($r2['body']) || (preg_match('/Correo o contraseña incorrectos/', $r1['body']) === 1 && preg_match('/Correo o contraseña incorrectos/', $r2['body']) === 1), 'usuario inexistente y contraseña mala: mismo mensaje (sin enumeración)');
    $blocked = 0;
    $bf = $anon->another();
    for ($i = 0; $i < 8; $i++) {
        $g = $bf->get('/login');
        $r = $bf->post('/login', ['_csrf' => $bf->csrfOf($g['body']), 'email' => 'fuerza-bruta-' . $i . '@example.com', 'password' => 'x' . $i]);
        if ($r['status'] === 429) {
            $blocked++;
        }
    }
    T::ok($blocked >= 1, 'fuerza bruta de login desde una IP: bloqueada con 429 tras cinco fallos (' . $blocked . ' bloqueos)');
    Infra::stop('panel39');
    T::reset(true);
};
