<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Services\Crypto;
use Captacion\Services\LeadIngest;
use Captacion\Services\Queue;
use Captacion\Services\Totp;
use CaptacionTests\Infra;
use CaptacionTests\T;

return static function (array $ctx): void {
    $work = $ctx['work'];
    $docroot = $ctx['root'] . '/public';
    $mkEnv = static function (string $name, array $over, array $drop = []) use ($ctx, $work): string {
        $vars = array_diff_key(array_merge($ctx['env'], $over), array_flip($drop));
        $file = "$work/$name.env";
        file_put_contents($file, implode("\n", array_map(static fn($k, $v) => "$k=$v", array_keys($vars), $vars)) . "\n");
        return $file;
    };
    $count = static fn(string $t, string $where = '1=1') => (int) Db::value("SELECT COUNT(*) FROM $t WHERE $where");
    $fresh = static fn(): string => "$work/jar-" . bin2hex(random_bytes(3)) . '.txt';
    $csrfOf = static fn(string $html) => preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
    $cookieOf = static function (string $jar): string {
        foreach (is_file($jar) ? file($jar, FILE_IGNORE_NEW_LINES) : [] as $l) {
            $l = preg_replace('/^#HttpOnly_/', '', $l);
            $p = explode("\t", (string) $l);
            if (($p[5] ?? '') === 'NSCRM') {
                return $p[6] ?? '';
            }
        }
        return '';
    };

    T::reset(true);

    // ---------------------------------------------------------------- setup.php sin SETUP_TOKEN: 404
    $noTok = $mkEnv('panel-notoken', ['SETUP_TOKEN' => ''], ['SETUP_TOKEN']);
    $portN = Infra::phpServer('panel-notoken', $docroot, ['CAPTACION_ENV_FILE' => $noTok]);
    $r = Infra::http('GET', "http://127.0.0.1:$portN/setup.php");
    T::eq($r['status'], 404, 'setup.php sin SETUP_TOKEN en el .env: 404 (desactivado)');
    Infra::stop('panel-notoken');

    // ---------------------------------------------------------------- panel principal
    $envA = $mkEnv('panel-a', []);
    $port = Infra::phpServer('panel-a', $docroot, ['CAPTACION_ENV_FILE' => $envA]);
    $B = "http://127.0.0.1:$port";
    $jar = "$work/jar-a.txt";
    @unlink($jar);
    $get = static fn(string $path, ?string $j = null, array $h = []) => Infra::http('GET', $B . $path, ['jar' => $j ?? $jar, 'headers' => $h]);
    $post = static fn(string $path, array $form, ?string $j = null, array $h = []) => Infra::http('POST', $B . $path, ['form' => $form, 'jar' => $j ?? $jar, 'headers' => $h]);

    // ---------------------------------------------------------------- setup (primer uso)
    T::eq($count('users'), 0, 'sin usuarios al empezar (no hay cuentas por defecto)');
    $r = $get('/setup.php');
    T::ok($r['status'] === 200 && str_contains($r['body'], 'Crear el administrador'), 'setup.php con users vacia: muestra el formulario');
    $token = $ctx['env']['SETUP_TOKEN'];
    $csrf = $csrfOf($r['body']);
    $fill = ['_csrf' => $csrf, 'action' => 'create', 'setup_token' => $token, 'name' => 'Carlos Test', 'email' => 'Carlos@Example.com', 'password' => 'una-clave-larga-123', 'password2' => 'una-clave-larga-123'];
    T::eq($post('/setup.php', ['_csrf' => 'mal'] + $fill)['status'], 403, 'setup con CSRF invalido: 403');
    T::eq($post('/setup.php', ['setup_token' => 'x' . $token] + $fill)['status'], 403, 'setup con token incorrecto: 403');
    T::eq($post('/setup.php', ['password' => 'corta', 'password2' => 'corta'] + $fill)['status'], 422, 'setup con contrasena de menos de 12 caracteres: 422');
    T::eq($post('/setup.php', ['password2' => 'otra-clave-larga-123'] + $fill)['status'], 422, 'setup con contrasenas distintas: 422');
    T::eq($post('/setup.php', ['email' => 'no-es-correo'] + $fill)['status'], 422, 'setup con correo invalido: 422');
    T::eq($count('users'), 0, 'ninguno de esos intentos crea la cuenta');
    $r = $get('/setup.php?setup_token=' . $token . '&email=a@b.com');
    T::ok(!str_contains($r['body'], 'Activa la verificación') && $count('users') === 0, 'el token en la URL no hace nada (solo vale el campo del formulario)');
    $r = $post('/setup.php', $fill);
    T::ok($r['status'] === 200 && str_contains($r['body'], 'Activa la verificación en dos pasos'), 'setup paso 1 correcto: muestra la clave TOTP y los codigos de recuperacion');
    T::eq($count('users'), 0, 'la cuenta NO existe todavia (se activa solo al confirmar el codigo)');
    preg_match('/class="secret"[^>]*><code>([^<]+)<\/code>/', $r['body'], $m);
    $secret = str_replace(' ', '', $m[1] ?? '');
    preg_match_all('/<li><code>([a-z0-9]{5}-[a-z0-9]{5})<\/code><\/li>/', $r['body'], $mc);
    $codes = $mc[1];
    T::ok(strlen($secret) === 32 && preg_match('/^[A-Z2-7]+$/', $secret) === 1, 'clave TOTP en base32 (32 caracteres) mostrada en grupos de 4');
    T::eq(count($codes), 10, 'se muestran 10 codigos de recuperacion');
    T::ok(str_contains($r['body'], 'otpauth://totp/') && str_contains($r['body'], rawurlencode('carlos@example.com')), 'enlace otpauth:// para el movil (correo en minusculas)');
    T::ok(!str_contains($r['body'], '<img') && !str_contains($r['body'], '<canvas'), 'sin QR (solo texto y enlace)');
    // Confirmar con codigo incorrecto
    $csrf2 = $csrfOf($r['body']);
    $bad = Totp::codeForStep(Totp::base32Decode($secret), Totp::stepAt(time()) + 10);
    $r2 = $post('/setup.php', ['_csrf' => $csrf2, 'action' => 'confirm', 'code' => $bad]);
    T::ok($r2['status'] === 422 && str_contains($r2['body'], 'no es correcto') && $count('users') === 0, 'confirmar con un codigo incorrecto: 422 y la cuenta sigue sin crearse');
    T::ok(str_contains($r2['body'], $codes[0]), '  mientras no se confirma, la pantalla conserva la clave y los codigos');
    // Confirmar bien
    $good = Totp::code($secret);
    $r3 = $post('/setup.php', ['_csrf' => $csrf2, 'action' => 'confirm', 'code' => $good]);
    T::ok($r3['status'] === 200 && str_contains($r3['body'], 'Cuenta creada') && !str_contains($r3['body'], $codes[0]) && !str_contains($r3['body'], $secret), 'confirmar con un codigo valido crea la cuenta; la pantalla final ya NO repite clave ni codigos');
    $u = Db::one('SELECT * FROM users');
    T::ok($count('users') === 1 && (int) $u['is_active'] === 1 && $u['role'] === 'admin' && $u['email'] === 'carlos@example.com' && $u['totp_confirmed_at'] !== null, 'usuario admin activo, correo normalizado, 2FA confirmado');
    T::ok(str_starts_with((string) $u['totp_secret'], 'v1:') && !str_contains((string) $u['totp_secret'], $secret) && Crypto::decrypt((string) $u['totp_secret']) === $secret, 'secreto TOTP guardado CIFRADO (sodium) y descifrable con APP_KEY');
    T::ok(str_starts_with((string) $u['password_hash'], defined('PASSWORD_ARGON2ID') ? '$argon2id$' : '$2y$12$') && !str_contains((string) $u['password_hash'], 'una-clave'), 'contrasena con hash ' . (defined('PASSWORD_ARGON2ID') ? 'Argon2id' : 'bcrypt coste 12'));
    $rc = Db::all('SELECT code_hash, used_at FROM recovery_codes');
    $plainIn = false;
    foreach ($rc as $row) {
        foreach ($codes as $c) {
            $plainIn = $plainIn || str_contains((string) $row['code_hash'], str_replace('-', '', $c));
        }
    }
    T::ok(count($rc) === 10 && !$plainIn && strlen((string) $rc[0]['code_hash']) === 64, '10 codigos de recuperacion guardados solo como hash (HMAC-SHA256)');
    T::eq($count('activity_log', "action = 'setup_admin'"), 1, 'activity_log: setup_admin');
    // Bloqueo posterior
    T::eq($get('/setup.php')['status'], 404, 'despues del alta: GET setup.php -> 404 para siempre');
    T::eq($post('/setup.php', $fill)['status'], 404, 'despues del alta: POST setup.php -> 404 (aunque se presente el token)');
    $setupAfter = $count('users');
    T::eq($setupAfter, 1, 'no se pudo crear un segundo usuario');

    // ---------------------------------------------------------------- login
    $clear = static fn() => Db::run('DELETE FROM login_attempts');
    $adminPw = 'una-clave-larga-123';
    $code = static fn(int $stepOffset = 0) => Totp::codeForStep(Totp::base32Decode($secret), Totp::stepAt(time()) + $stepOffset);
    $login = static function (string $j, string $email, string $pw) use ($get, $post, $csrfOf): array {
        $g = $get('/login', $j);
        return $post('/login', ['_csrf' => $csrfOf($g['body']), 'email' => $email, 'password' => $pw], $j);
    };

    $r = $get('/');
    T::ok($r['status'] === 303 && Infra::header($r, 'location') === '/login', 'sin sesion, / redirige a /login');
    $r = $get('/login', $fresh());
    $sc = Infra::header($r, 'set-cookie');
    T::ok(str_contains($sc, 'NSCRM=') && stripos($sc, 'HttpOnly') !== false && stripos($sc, 'SameSite=Lax') !== false, 'cookie de sesion NSCRM: HttpOnly y SameSite=Lax');
    T::eq($post('/login', ['email' => 'carlos@example.com', 'password' => $adminPw])['status'], 403, 'login sin token CSRF: 403');
    T::eq($post('/login', ['_csrf' => 'falso', 'email' => 'carlos@example.com', 'password' => $adminPw])['status'], 403, 'login con CSRF falso: 403');
    $j1 = $fresh();
    $r = $login($j1, 'carlos@example.com', 'contrasena-mala');
    T::ok($r['status'] === 401 && str_contains($r['body'], 'Correo o contraseña incorrectos'), 'contrasena incorrecta: 401');
    $j2 = $fresh();
    $r2 = $login($j2, 'nadie@example.com', 'contrasena-mala');
    T::ok($r2['status'] === 401 && str_contains($r2['body'], 'Correo o contraseña incorrectos'), 'correo inexistente: 401 con el MISMO mensaje (no revela cuentas)');
    $j3 = $fresh();
    $r3 = $login($j3, "' OR 1=1 --", 'x');
    T::eq($r3['status'], 401, "inyeccion SQL en el correo: 401");
    T::ok($count('activity_log', "action = 'login_failed'") === 3, 'activity_log: 3 fallos de login registrados');
    $clear();

    // Contrasena correcta -> 2FA pendiente (aun sin sesion)
    $jar1 = $fresh();
    $g = $get('/login', $jar1);
    $sid0 = $cookieOf($jar1);
    $r = $post('/login', ['_csrf' => $csrfOf($g['body']), 'email' => 'CARLOS@example.com', 'password' => $adminPw], $jar1);
    $sid1 = $cookieOf($jar1);
    T::ok($r['status'] === 303 && Infra::header($r, 'location') === '/login/2fa', 'contrasena correcta: 303 a /login/2fa');
    T::ok($sid0 !== '' && $sid1 !== '' && $sid0 !== $sid1, 'el ID de sesion se regenera al pasar la contrasena');
    $r = $get('/', $jar1);
    T::ok($r['status'] === 303 && Infra::header($r, 'location') === '/login', 'con solo la contrasena todavia no hay acceso al panel');
    // 2FA incorrecto
    $g2 = $get('/login/2fa', $jar1);
    $csrfT = $csrfOf($g2['body']);
    $r = $post('/login/2fa', ['_csrf' => $csrfT, 'code' => '000000'], $jar1);
    T::ok($r['status'] === 401 && str_contains($r['body'], 'Código incorrecto'), '2FA con codigo incorrecto: 401');
    T::eq($post('/login/2fa', ['code' => $code()], $jar1)['status'], 403, '2FA sin CSRF: 403');
    // 2FA correcto (paso -1 de la ventana)
    $r = $post('/login/2fa', ['_csrf' => $csrfT, 'code' => $code(-1)], $jar1);
    $sid2 = $cookieOf($jar1);
    T::ok($r['status'] === 303 && Infra::header($r, 'location') === '/', '2FA con el codigo del paso anterior (ventana -1): entra');
    T::ok($sid2 !== $sid1 && $sid2 !== '', 'el ID de sesion se regenera otra vez al completar el login');
    $r = $get('/', $jar1);
    T::ok($r['status'] === 200 && str_contains($r['body'], 'Hola, Carlos Test') && str_contains($r['body'], 'Cola de trabajos') && str_contains($r['body'], 'Últimos 20 leads') && str_contains($r['body'], 'Salir'), 'panel: saludo, cola, ultimos 20 leads y salir');
    T::ok(str_contains($r['body'], 'nunca') && str_contains($r['body'], 'Telegram sin configurar'), 'panel: avisa "cron nunca ejecutado" y "Telegram sin configurar"');
    T::ok(Db::value('SELECT last_login_at FROM users') !== null && $count('activity_log', "action = 'login_ok'") === 1, 'last_login_at y activity_log login_ok');

    // Cabeceras de seguridad y sin inline
    $h = static fn(string $n) => Infra::header($r, $n);
    $csp = $h('content-security-policy');
    T::ok(str_contains($csp, "default-src 'none'") && str_contains($csp, "frame-ancestors 'none'") && str_contains($csp, "base-uri 'none'") && !str_contains($csp, 'unsafe-inline') && !str_contains($csp, 'unsafe-eval'), 'CSP estricta (default-src none, sin unsafe-inline/eval, frame-ancestors none)');
    T::ok(!preg_match("/style-src[^;]*'unsafe-inline'/", $csp) && str_contains($csp, "script-src 'self' 'nonce-"), 'CSP: scripts solo con nonce; estilos solo del propio origen');
    T::ok($h('x-frame-options') === 'DENY' && $h('referrer-policy') === 'no-referrer' && str_contains($h('x-robots-tag'), 'noindex') && $h('x-content-type-options') === 'nosniff' && str_contains($h('cache-control'), 'no-store'), 'X-Frame-Options DENY, Referrer-Policy, X-Robots-Tag noindex, nosniff, no-store');
    T::ok(!str_contains($r['body'], '<script') && !preg_match('/\sstyle="/i', $r['body']) && !preg_match('/\son\w+="/i', $r['body']), 'HTML sin <script>, sin style= ni handlers on*= (compatible con la CSP)');
    T::ok(str_contains($r['body'], 'name="robots" content="noindex'), 'meta robots noindex');
    $rb = $get('/robots.txt');
    T::ok($rb['status'] === 200 && str_contains($rb['body'], 'Disallow: /'), 'robots.txt: Disallow /');
    T::eq($get('/assets/panel.css')['status'], 200, 'assets/panel.css se sirve');
    T::eq(Infra::http('PUT', "$B/")['status'], 405, 'metodo PUT: 405');
    T::eq($get('/no-existe')['status'], 404, 'ruta inexistente: 404');

    // Escapado: un nombre con HTML no se ejecuta
    LeadIngest::handle(['profile' => 'owner-operator', 'name' => '<script>alert(1)</script> "x"', 'phone' => '3052345611', 'email' => 'xss@example.com', 'cdl_standard' => 'yes', 'english' => 'yes', 'years' => '3', 'state' => 'FL'], 'web_careers', ['quiet' => true]);
    $xssId = (int) Db::value('SELECT MAX(id) FROM leads');
    $r = $get('/', $jar1);
    T::ok(!str_contains($r['body'], '<script>alert(1)') && str_contains($r['body'], '&lt;script&gt;alert(1)&lt;/script&gt;'), 'escapado: el nombre con <script> sale como texto en la tabla de leads');
    $r = $get("/leads/$xssId", $jar1);
    T::ok($r['status'] === 200 && !str_contains($r['body'], '<script>alert(1)') && str_contains($r['body'], 'Desglose de la puntuación'), 'ficha minima del lead: escapada y con el desglose de la puntuacion');
    T::eq($get("/leads/$xssId'", $jar1)['status'], 404, 'ficha con id mal formado: 404');
    T::eq($get('/leads/99999', $jar1)['status'], 404, 'ficha de un lead inexistente: 404');
    T::ok($get("/leads/$xssId", $fresh())['status'] === 303, 'ficha sin sesion: redirige a /login');

    // Estado de la cola en el panel
    Queue::push('t_a', []);
    Queue::push('t_b', [], Clock::shift(3600));
    $f = Queue::push('t_c', []);
    Db::update('jobs', $f, ['status' => 'failed', 'last_error' => 'error de prueba visible', 'finished_at' => Clock::now(), 'attempts' => 4]);
    Queue::push('sms_backup_alert', [], null, 'x', 'parked', 'TODO');
    Queue::runBatch(['t_a' => static function (): void {}], 5);   // deja la marca de "ultima ejecucion"
    $r = $get('/', $jar1);
    T::ok(str_contains($r['body'], 'error de prueba visible') && str_contains($r['body'], 'Fallidos') && str_contains($r['body'], 'hace ') && str_contains($r['body'], 'trabajo(s) fallido(s)'), 'panel: muestra fallidos con su error y la ultima ejecucion del cron');
    T::ok(preg_match('/Pendientes<\/dt><dd>1<\/dd>/', $r['body']) === 1 && preg_match('/Registrados sin ejecutar<\/dt><dd>1<\/dd>/', $r['body']) === 1, 'panel: contadores de cola (1 pendiente, 1 parked)');

    // Logout con CSRF
    $r = $post('/logout', [], $jar1);
    T::ok($r['status'] === 303 && $get('/', $jar1)['status'] === 200, 'logout sin token CSRF: no cierra la sesion');
    $csrfP = $csrfOf($get('/', $jar1)['body']);
    $r = $post('/logout', ['_csrf' => $csrfP], $jar1);
    T::ok($r['status'] === 303 && Infra::header($r, 'location') === '/login' && $get('/', $jar1)['status'] === 303, 'logout con CSRF: cierra la sesion');
    T::eq($get('/logout', $jar1)['status'], 303, 'GET /logout no hace nada');
    T::eq($count('activity_log', "action = 'logout'"), 1, 'activity_log: logout');

    // ---------------------------------------------------------------- 2FA: ventana, reutilizacion y recuperacion
    $clear();
    $loginFull = static function (string $j, string $c) use ($login, $post, $get, $csrfOf, $adminPw): array {
        $login($j, 'carlos@example.com', $adminPw);
        $g = $get('/login/2fa', $j);
        return $post('/login/2fa', ['_csrf' => $csrfOf($g['body']), 'code' => $c], $j);
    };
    Db::run('UPDATE users SET totp_last_step = NULL');
    $r = $loginFull($fresh(), $code(1));
    T::eq($r['status'], 303, '2FA con el codigo del paso siguiente (ventana +1): entra');
    Db::run('UPDATE users SET totp_last_step = NULL');
    $r = $loginFull($fresh(), $code(-2));
    T::eq($r['status'], 401, '2FA con un codigo de hace 2 pasos: rechazado');
    $r = $loginFull($fresh(), $code(2));
    T::eq($r['status'], 401, '2FA con un codigo de 2 pasos por delante: rechazado');
    Db::run('UPDATE users SET totp_last_step = NULL');
    $c0 = $code(0);
    $r = $loginFull($fresh(), $c0);
    T::eq($r['status'], 303, '2FA con el codigo actual: entra');
    $r = $loginFull($fresh(), $c0);
    T::eq($r['status'], 401, '2FA: el mismo codigo no se puede reutilizar (anti-replay)');
    $clear();

    // Codigos de recuperacion: un solo uso, tolerantes a formato
    $jr = $fresh();
    $r = $loginFull($jr, strtoupper(str_replace('-', ' ', $codes[0])));
    T::eq($r['status'], 303, 'codigo de recuperacion (en mayusculas y con espacio en vez de guion): entra');
    $pan = $get('/', $jr);
    T::ok(str_contains($pan['body'], 'código de recuperación') && str_contains($pan['body'], 'Te quedan 9'), '  el panel avisa que se uso un codigo y cuantos quedan (9)');
    T::eq($count('recovery_codes', 'used_at IS NOT NULL'), 1, '  el codigo queda marcado como usado');
    T::eq($count('activity_log', "action = 'recovery_code_used'"), 1, '  activity_log: recovery_code_used');
    $r = $loginFull($fresh(), $codes[0]);
    T::eq($r['status'], 401, 'el mismo codigo de recuperacion NO sirve dos veces');
    $r = $loginFull($fresh(), $codes[1]);
    T::eq($r['status'], 303, 'otro codigo de recuperacion distinto si sirve');
    $r = $loginFull($fresh(), 'zzzzz-zzzzz');
    T::eq($r['status'], 401, 'codigo de recuperacion inventado: rechazado');
    $clear();

    // ---------------------------------------------------------------- limite de intentos
    $badLogin = static function () use ($login, $fresh): int {
        return $login($fresh(), 'carlos@example.com', 'mala-contrasena-x')['status'];
    };
    $res = [];
    for ($i = 0; $i < 6; $i++) {
        $res[] = $badLogin();
    }
    T::eq($res, [401, 401, 401, 401, 401, 429], 'limite: 5 intentos fallidos, el 6.o recibe 429');
    $r = $login($fresh(), 'carlos@example.com', $adminPw);
    T::ok($r['status'] === 429 && str_contains($r['body'], 'Demasiados intentos') && Infra::header($r, 'retry-after') !== '', 'bloqueado: ni la contrasena correcta entra (429 con Retry-After)');
    T::ok($count('activity_log', "action = 'login_blocked'") >= 2, 'activity_log: login_blocked registrado');
    Db::run('UPDATE login_attempts SET created_at = ?', [Clock::shift(-16 * 60)]);
    $r = $login($fresh(), 'carlos@example.com', $adminPw);
    T::eq($r['status'], 303, 'pasados 15 minutos el bloqueo se levanta');
    $clear();
    // Por correo (desde "otras IP")
    for ($i = 0; $i < 5; $i++) {
        Db::insert('login_attempts', ['email' => 'carlos@example.com', 'ip' => '10.1.1.' . $i, 'kind' => 'password', 'success' => 0, 'created_at' => Clock::now()]);
    }
    $r = $login($fresh(), 'carlos@example.com', $adminPw);
    T::eq($r['status'], 429, 'limite por correo: 5 fallos desde otras IP bloquean esa cuenta tambien desde una IP limpia');
    $r = $login($fresh(), 'otro@example.com', 'x');
    T::eq($r['status'], 401, '  otro correo desde la IP limpia no esta bloqueado');
    $clear();
    // 2FA tambien cuenta
    $jb = $fresh();
    $login($jb, 'carlos@example.com', $adminPw);
    $csrfB = $csrfOf($get('/login/2fa', $jb)['body']);
    $st = [];
    for ($i = 0; $i < 6; $i++) {
        $st[] = $post('/login/2fa', ['_csrf' => $csrfB, 'code' => '12345' . $i], $jb)['status'];
    }
    T::ok(array_slice($st, 0, 4) === [401, 401, 401, 401] && end($st) === 429, 'limite: los fallos de 2FA cuentan (4 de 401 y luego 429): ' . implode(',', $st));
    T::eq($get('/', $jb)['status'], 303, '  y la sesion pendiente se descarta al bloquear');
    $clear();

    // ---------------------------------------------------------------- sesion caducada
    Infra::stop('panel-a');
    $envS = $mkEnv('panel-short', []);
    $portS = Infra::phpServer('panel-short', $docroot, ['CAPTACION_ENV_FILE' => $envS, 'SESSION_IDLE_SECONDS' => '2']);
    $BS = "http://127.0.0.1:$portS";
    $B = $BS;
    $js = $fresh();
    Db::run('UPDATE users SET totp_last_step = NULL');
    $r = $loginFull($js, $code(0));
    T::ok($r['status'] === 303 && $get('/', $js)['status'] === 200, 'sesion con idle=2 s: entra');
    sleep(1);
    T::eq($get('/', $js)['status'], 200, '  a 1 s de inactividad sigue valida (y la actividad renueva el plazo)');
    sleep(1);
    usleep(900000);
    T::eq($get('/', $js)['status'], 200, '  la actividad renueva: 1,9 s despues sigue valida');
    sleep(3);
    $r = $get('/', $js);
    T::ok($r['status'] === 303 && Infra::header($r, 'location') === '/login', 'sesion caducada por inactividad: redirige a /login');
    $r = $get('/login', $js);
    T::ok($r['status'] === 200 && str_contains($r['body'], 'caducó por inactividad'), '  y el login explica que caduco');
    Infra::stop('panel-short');

    // ---------------------------------------------------------------- produccion: cookie Secure, HSTS
    $envP = $mkEnv('panel-prod', ['APP_ENV' => 'production']);
    $portP = Infra::phpServer('panel-prod', $docroot, ['CAPTACION_ENV_FILE' => $envP]);
    $r = Infra::http('GET', "http://127.0.0.1:$portP/login", ['headers' => ['X-Forwarded-Proto: https']]);
    $sc = Infra::header($r, 'set-cookie');
    T::ok(stripos($sc, 'Secure') !== false && stripos($sc, 'HttpOnly') !== false && stripos($sc, 'SameSite=Lax') !== false, 'APP_ENV=production: cookie NSCRM con Secure; HttpOnly; SameSite=Lax');
    T::ok(str_contains(Infra::header($r, 'strict-transport-security'), 'max-age=31536000'), 'HSTS cuando la conexion es https');
    Infra::stop('panel-prod');

    // .htaccess: sin Apache aqui; se comprueban las reglas clave por texto (ver README: se prueba en el servidor)
    $ht = (string) file_get_contents($ctx['root'] . '/public/.htaccess');
    T::ok(str_contains($ht, 'RewriteEngine On') && str_contains($ht, 'RewriteRule ^ index.php [L]') && str_contains($ht, 'HTTP_AUTHORIZATION') && str_contains($ht, 'Require all denied'), 'public/.htaccess: reescritura propia, Authorization hacia PHP y bloqueo de archivos sensibles (texto; Apache no esta disponible aqui)');
    $cfgHt = (string) file_get_contents($ctx['root'] . '/config/.htaccess');
    T::ok(str_contains($cfgHt, 'Require all denied') && str_contains((string) file_get_contents($ctx['root'] . '/storage/.htaccess'), 'Require all denied'), 'config/.htaccess y storage/.htaccess: Require all denied');
};
