<?php
/** Mini-framework de pruebas (sin PHPUnit): aserciones, secciones, contadores y limpieza de datos. */

declare(strict_types=1);

namespace CaptacionTests;

use Captacion\Db;

final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    /** @var list<string> */
    public static array $failures = [];
    private static string $section = '';

    public static function section(string $name): void
    {
        self::$section = $name;
        fwrite(STDOUT, "\n== $name\n");
    }

    public static function ok(bool $cond, string $msg): bool
    {
        if ($cond) {
            self::$pass++;
            fwrite(STDOUT, "  ok   $msg\n");
        } else {
            self::$fail++;
            self::$failures[] = self::$section . ': ' . $msg;
            fwrite(STDOUT, "  FAIL $msg\n");
        }
        return $cond;
    }

    public static function eq(mixed $actual, mixed $expected, string $msg): bool
    {
        $same = $actual === $expected;
        $ok = self::ok($same, $msg);
        if (!$same) {
            fwrite(STDOUT, '       esperado: ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n       obtenido: " . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n");
        }
        return $ok;
    }

    public static function throws(callable $fn, string $msg, ?string $contains = null): bool
    {
        try {
            $fn();
        } catch (\Throwable $t) {
            return self::ok($contains === null || str_contains($t->getMessage(), $contains), $msg . ($contains !== null && !str_contains($t->getMessage(), $contains) ? ' (mensaje: ' . $t->getMessage() . ')' : ''));
        }
        return self::ok(false, $msg . ' (no lanzo excepcion)');
    }

    /** Vacia todas las tablas de datos (no schema_migrations ni settings) para que cada bloque empiece limpio. */
    public static function reset(bool $withUsers = false): void
    {
        $pdo = Db::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['lead_events', 'consents', 'leads', 'jobs', 'activity_log', 'login_attempts', 'recovery_codes', 'lead_notes', 'notification_reads', 'tasks', 'appointments', 'messages', 'sequence_enrollments', 'referrals', 'referral_codes', 'prospects_fmcsa'] as $t) {
            $pdo->exec("TRUNCATE TABLE `$t`");
        }
        if ($withUsers) {
            $pdo->exec('TRUNCATE TABLE users');
        }
        $pdo->exec("DELETE FROM settings WHERE `key` LIKE 'cron.%'");
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        \Captacion\Services\Settings::clear();
        \Captacion\Clock::travel(0);
        $dir = \Captacion\Services\Storage::dir('ratelimit');
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            @unlink($f);
        }
    }

    public static function summary(): int
    {
        fwrite(STDOUT, "\n" . str_repeat('=', 60) . "\n");
        fwrite(STDOUT, 'Pruebas: ' . (self::$pass + self::$fail) . ' | ok: ' . self::$pass . ' | fallos: ' . self::$fail . "\n");
        foreach (self::$failures as $f) {
            fwrite(STDOUT, "  - $f\n");
        }
        return self::$fail === 0 ? 0 : 1;
    }
}
