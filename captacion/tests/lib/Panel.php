<?php
/** Cliente HTTP del panel para las pruebas: servidor php -S con su propio .env, alta de usuarios, login con contrasena + 2FA y peticiones con CSRF. */

declare(strict_types=1);

namespace CaptacionTests;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Services\Auth;
use Captacion\Services\Crypto;
use Captacion\Services\Totp;

final class Panel
{
    public const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
    public const PASSWORD = 'pw-q2b-larga-123';

    public string $base = '';
    public string $jar = '';
    public string $csrf = '';

    /** Arranca php -S sobre public/ con un .env propio (la base de pruebas + $extra). */
    public static function start(array $ctx, string $name, array $extra = []): self
    {
        $envFile = $ctx['work'] . "/$name.env";
        $vars = $extra + $ctx['env'];
        file_put_contents($envFile, implode("\n", array_map(static fn($k, $v) => "$k=$v", array_keys($vars), $vars)) . "\n");
        $port = Infra::phpServer($name, $ctx['root'] . '/public', ['CAPTACION_ENV_FILE' => $envFile, 'NO_PROXY' => '127.0.0.1,localhost']);
        $c = new self();
        $c->base = "http://127.0.0.1:$port";
        $c->jar = $ctx['work'] . "/jar-$name-" . bin2hex(random_bytes(3)) . '.txt';
        return $c;
    }

    /** Otra sesion (otro cookie jar) contra el mismo servidor. */
    public function another(): self
    {
        $c = new self();
        $c->base = $this->base;
        $c->jar = $this->jar . '.' . bin2hex(random_bytes(3));
        return $c;
    }

    public static function user(string $role = 'admin', ?string $email = null): array
    {
        $email ??= $role . '-' . bin2hex(random_bytes(3)) . '@example.com';
        $id = (int) Db::insert('users', ['name' => ucfirst($role) . ' Prueba', 'email' => $email, 'password_hash' => Auth::hashPassword(self::PASSWORD), 'password_algo' => Auth::algo(),
            'totp_secret' => Crypto::encrypt(self::SECRET), 'totp_confirmed_at' => Clock::now(), 'role' => $role, 'is_active' => 1]);
        return ['id' => $id, 'email' => $email];
    }

    public function csrfOf(string $html): string
    {
        return preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : (preg_match('/data-csrf="([^"]+)"/', $html, $m) === 1 ? $m[1] : '');
    }

    public function get(string $p, array $h = []): array
    {
        return Infra::http('GET', $this->base . $p, ['jar' => $this->jar, 'headers' => $h]);
    }

    public function post(string $p, array $f, array $h = []): array
    {
        return Infra::http('POST', $this->base . $p, ['form' => $f, 'jar' => $this->jar, 'headers' => $h]);
    }

    /** POST JSON con el token CSRF de la sesion (o el dado). */
    public function ajax(string $p, mixed $body, ?string $tok = null): array
    {
        return Infra::http('POST', $this->base . $p, ['json' => $body, 'jar' => $this->jar, 'headers' => ['X-CSRF-Token: ' . ($tok ?? $this->csrf)]]);
    }

    /** Inicia sesion (contrasena + TOTP); deja $this->csrf listo. */
    public function login(string $email): bool
    {
        $g = $this->get('/login');
        $this->post('/login', ['_csrf' => $this->csrfOf($g['body']), 'email' => $email, 'password' => self::PASSWORD]);
        $g = $this->get('/login/2fa');
        $r = $this->post('/login/2fa', ['_csrf' => $this->csrfOf($g['body']), 'code' => Totp::code(self::SECRET)]);
        if ($r['status'] !== 303) {
            return false;
        }
        $this->csrf = $this->csrfOf($this->get('/')['body']);
        return $this->csrf !== '';
    }

    public function json(array $r): array
    {
        return json_decode($r['body'], true) ?: [];
    }
}
