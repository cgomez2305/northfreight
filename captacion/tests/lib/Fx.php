<?php
/** Datos de prueba compartidos: leads, consentimientos, ejecucion de la cola y entorno de correo simulado. */

declare(strict_types=1);

namespace CaptacionTests;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Services\Consent;
use Captacion\Services\JobHandlers;
use Captacion\Services\Queue;
use Captacion\Services\Settings;

final class Fx
{
    private static int $n = 0;

    public static function lead(array $o = []): int
    {
        self::$n++;
        $n = self::$n;
        return Db::insert('leads', $o + [
            'profile' => 'owner_operator', 'first_name' => 'Fx' . $n, 'last_name' => 'Prueba' . $n, 'phone_e164' => '+1305556' . str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'email' => "fx$n@example.com", 'state' => 'TX',
            'language' => 'en', 'score' => 55, 'temperature' => 'warm', 'pipeline' => 'A', 'stage' => 'new', 'source' => 'web_careers', 'attempt' => 1, 'equipment_types' => ['dry-van'],
            'truck_count' => 2, 'created_at' => Clock::now(), 'updated_at' => Clock::now(),
        ]);
    }

    public static function consent(int $leadId, ?array $channels = null, string $text = 'Texto exacto del consentimiento v1'): void
    {
        Consent::record($leadId, ['given' => true, 'text' => $text, 'version' => 'v1', 'ip' => '203.0.113.9', 'user_agent' => 'PruebaUA'], $channels ?? ['call', 'sms', 'email']);
    }

    /** Procesa la cola (pasada corta). */
    public static function runJobs(int $max = 5): array
    {
        return Queue::runBatch(JobHandlers::all(), $max, 50);
    }

    /** Apunta el correo del CRM al SMTP falso. */
    public static function smtp(int $port): void
    {
        foreach (['SMTP_HOST' => '127.0.0.1', 'SMTP_PORT' => (string) $port, 'SMTP_USER' => 'crm@example.com', 'SMTP_PASS' => 'pw-smtp-prueba', 'SMTP_SECURE' => 'none'] as $k => $v) {
            Env::set($k, $v);
        }
    }

    public static function noSmtp(): void
    {
        foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'SMTP_SECURE'] as $k) {
            Env::set($k, null);
        }
    }

    public static function setting(string $key, mixed $value): void
    {
        Settings::set($key, $value);
    }

    /** Hace falta para que Handoff::open() / Sequences vean cambios de reloj: avanza el reloj de pruebas. */
    public static function travel(int $seconds): void
    {
        Clock::travel($seconds);
    }
}
