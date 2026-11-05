<?php
/**
 * Estado y pruebas de las integraciones (Configuracion > Integraciones): Telegram, FMCSA, SMTP y cron. NUNCA muestra secretos: solo «configurado / sin configurar»
 * y los ultimos 4 caracteres. El resultado real de cada uso queda en settings['integration.<nombre>'] = {ok_at, err_at, err} (lo escriben Telegram::send,
 * Fmcsa::lookup y Mailer::send); el boton «Probar» hace un uso de verdad y devuelve el mensaje.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Env;

final class Integrations
{
    /** USDOT de la consulta de prueba de FMCSA (una transportista grande y conocida); se puede escribir otro en la pantalla. */
    public const TEST_USDOT = '53467';

    public static function record(string $name, bool $ok, string $msg = ''): void
    {
        try {
            $key = 'integration.' . $name;
            $cur = Settings::get($key, []);
            $cur = is_array($cur) ? $cur : [];
            $cur['last'] = $ok ? 'ok' : 'err';
            if ($ok) {
                $cur['ok_at'] = Clock::now();
            } else {
                $cur['err_at'] = Clock::now();
                $cur['err'] = mb_substr(Storage::redact($msg), 0, 200);
            }
            Settings::set($key, $cur);
        } catch (\Throwable) {
            // el estado es un extra: nunca rompe el envio real
        }
    }

    public static function last(string $name): array
    {
        $v = Settings::get('integration.' . $name, []);
        return is_array($v) ? $v : [];
    }

    /** "•••• 4f2a" (ultimos 4 caracteres) o null si no hay valor. */
    public static function mask(?string $v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : '•••• ' . substr($v, -4);
    }

    private static function lastLine(array $last): array
    {
        $ok = isset($last['ok_at']) ? (int) Clock::parse((string) $last['ok_at']) : 0;
        $err = isset($last['err_at']) ? (int) Clock::parse((string) $last['err_at']) : 0;
        return [$ok, $err];
    }

    /**
     * Tarjetas de estado. Cada una: key, title, level ('ok'|'warn'|'bad'|'off'), headline, details [[etiqueta, valor]], last (texto de la ultima actividad), testable (bool).
     * @return list<array>
     */
    public static function status(): array
    {
        $now = Clock::time();
        $cards = [];

        // Telegram
        $tg = Telegram::configured();
        [$ok, $err] = self::lastLine(self::last('telegram'));
        $l = self::last('telegram');
        $bad = ($l['last'] ?? '') === 'err';
        $cards[] = [
            'key' => 'telegram', 'title' => 'Telegram', 'testable' => true,
            'level' => !$tg ? 'off' : ($bad ? 'bad' : 'ok'),
            'headline' => !$tg ? 'Sin configurar' : ($bad ? 'El último envío falló' : 'Configurado'),
            'details' => [['Token del bot', self::mask(Env::get('TELEGRAM_BOT_TOKEN')) ?? 'sin configurar'], ['Chat', self::mask(Env::get('TELEGRAM_CHAT_ID')) ?? 'sin configurar']],
            'last' => $ok > 0 ? 'Último envío correcto ' . Fmt::ago((string) $l['ok_at']) : 'Sin envíos todavía',
            'error' => $bad ? (string) ($l['err'] ?? '') : '',
            'help' => $tg ? '' : 'Añade TELEGRAM_BOT_TOKEN y TELEGRAM_CHAT_ID en config/.env (pasos en el README §11, punto 7).',
        ];

        // FMCSA
        $key = Alerts::fmcsaKey();
        $l = self::last('fmcsa');
        $bad = ($l['last'] ?? '') === 'err';
        [$ok, $err] = self::lastLine($l);
        $cards[] = [
            'key' => 'fmcsa', 'title' => 'FMCSA', 'testable' => true,
            'level' => !$key ? 'warn' : ($bad ? 'bad' : 'ok'),
            'headline' => !$key ? 'Sin llave (modo degradado)' : ($bad ? 'La última consulta falló' : 'Configurado'),
            'details' => [['Llave (webKey)', self::mask(Env::get('FMCSA_WEBKEY')) ?? 'sin configurar'], ['Servicio', Env::get('FMCSA_API_BASE') !== null ? 'personalizado (FMCSA_API_BASE)' : 'mobile.fmcsa.dot.gov']],
            'last' => $ok > 0 ? 'Última consulta correcta ' . Fmt::ago((string) $l['ok_at']) : 'Sin consultas todavía',
            'error' => $bad ? (string) ($l['err'] ?? '') : '',
            'help' => $key ? '' : 'Sin la llave, los leads con USDOT quedan «FMCSA pendiente» y un lead muy bueno suma 65 a 70 puntos y casi nunca llega a caliente. Registra una webKey gratis y ponla en FMCSA_WEBKEY.',
        ];

        // SMTP
        $smtp = Mailer::smtpConfigured();
        $l = self::last('smtp');
        $bad = ($l['last'] ?? '') === 'err';
        [$ok, $err] = self::lastLine($l);
        $to = Alerts::mailTo();
        $cards[] = [
            'key' => 'smtp', 'title' => 'Correo (SMTP)', 'testable' => true,
            'level' => !$smtp ? 'warn' : ($bad ? 'bad' : 'ok'),
            'headline' => !$smtp ? 'Sin SMTP: los avisos solo se registran' : ($bad ? 'El último envío falló' : 'Configurado'),
            'details' => [['Servidor', $smtp ? (string) Env::get('SMTP_HOST') . ':' . Env::int('SMTP_PORT', 465) : 'sin configurar'], ['Usuario', self::mask(Env::get('SMTP_USER')) ?? 'sin configurar'], ['Destinatario', $to !== '' ? $to : 'sin configurar (MAIL_TO)']],
            'last' => $ok > 0 ? 'Último envío correcto ' . Fmt::ago((string) $l['ok_at']) : 'Sin envíos todavía',
            'error' => $bad ? (string) ($l['err'] ?? '') : '',
            'help' => $smtp ? '' : 'Sin SMTP el correo queda en storage/mail.log. Configura SMTP_HOST, SMTP_USER y SMTP_PASS en config/.env.',
        ];

        // Cron de la cola
        $cron = Settings::get('cron.process_jobs');
        $at = is_array($cron) && isset($cron['at']) ? Clock::parse((string) $cron['at']) : null;
        $late = $at !== null && $now - $at > Notifications::CRON_STALE;
        $cards[] = [
            'key' => 'cron', 'title' => 'Cron de la cola', 'testable' => false,
            'level' => $at === null ? 'warn' : ($late ? 'bad' : 'ok'),
            'headline' => $at === null ? 'Nunca se ha ejecutado' : ($late ? 'Lleva más de 3 minutos sin correr' : 'Corriendo'),
            'details' => [['Última pasada', $at === null ? '—' : Fmt::dateTime((string) $cron['at']) . ' · ' . Fmt::ago((string) $cron['at'])], ['Trabajos de esa pasada', is_array($cron) ? (int) ($cron['processed'] ?? 0) . ' procesados, ' . (int) ($cron['failed'] ?? 0) . ' fallidos' : '—']],
            'last' => '',
            'error' => '',
            'help' => $at === null || $late ? 'Revisa la tarea de cron de cron/process_jobs.php (cada minuto) y storage/error.log.' : '',
        ];
        // Copia diaria de la base
        $bk = Backup::health($now);
        $bs = Settings::get('cron.backup');
        $cards[] = [
            'key' => 'backup', 'title' => 'Copia diaria', 'testable' => false,
            'level' => $bk['level'], 'headline' => $bk['level'] === 'ok' ? 'Al día' : ($bk['failed'] ? 'La última falló' : 'Requiere atención'),
            'details' => [['Última copia buena', $bk['last_ok'] !== null ? Fmt::dateTime((string) $bk['last_ok']) . ' · ' . Fmt::ago((string) $bk['last_ok']) : '—'], ['Programada', 'cada día 3:30 a. m. (Nueva York)']],
            'last' => '', 'error' => $bk['failed'] ? (string) (is_array($bs) ? ($bs['error'] ?? '') : '') : '',
            'help' => $bk['level'] === 'ok' ? '' : 'Revisa la pestaña «Copias de seguridad» y la tarea de cron de cron/backup_db.php (cada hora a los :30).',
        ];
        return $cards;
    }

    /**
     * «Probar»: un uso real de la integracion. @return array{ok:bool,level:string,msg:string}  level: ok | warn | bad
     */
    public static function test(string $name, ?string $usdot = null): array
    {
        try {
            return match ($name) {
                'telegram' => self::testTelegram(),
                'fmcsa'    => self::testFmcsa($usdot),
                'smtp'     => self::testSmtp(),
                default    => ['ok' => false, 'level' => 'bad', 'msg' => 'Integración desconocida.'],
            };
        } catch (\Throwable $t) {
            return ['ok' => false, 'level' => 'bad', 'msg' => mb_substr(Storage::redact($t->getMessage()), 0, 240)];
        }
    }

    private static function testTelegram(): array
    {
        if (!Telegram::configured()) {
            return ['ok' => false, 'level' => 'warn', 'msg' => 'Telegram no está configurado: faltan TELEGRAM_BOT_TOKEN o TELEGRAM_CHAT_ID en config/.env.'];
        }
        Telegram::send('<b>Prueba de NorthStar Captación</b>' . "\n" . 'Si lees esto, las alertas de Telegram funcionan. ' . gmdate('H:i') . ' UTC');
        return ['ok' => true, 'level' => 'ok', 'msg' => 'Mensaje de prueba enviado a Telegram. Revisa tu teléfono.'];
    }

    private static function testFmcsa(?string $usdot): array
    {
        $usdot = preg_match('/^\d{1,8}$/', (string) $usdot) === 1 ? (string) $usdot : self::TEST_USDOT;
        $client = JobHandlers::$fmcsa ?? new Fmcsa();
        if (!$client->configured()) {
            return ['ok' => false, 'level' => 'warn', 'msg' => 'Sin llave de FMCSA (FMCSA_WEBKEY): no se puede consultar. Los leads con USDOT quedan «FMCSA pendiente» hasta que la agregues.'];
        }
        try {
            $r = $client->lookup($usdot);
        } catch (FmcsaUnavailable $e) {
            return ['ok' => false, 'level' => 'bad', 'msg' => 'FMCSA no respondió bien: ' . mb_substr(Storage::redact($e->getMessage()), 0, 200)];
        }
        if (!($r['found'] ?? false)) {
            return ['ok' => true, 'level' => 'warn', 'msg' => 'FMCSA respondió, pero el USDOT ' . $usdot . ' no existe. Prueba con otro.'];
        }
        $age = $r['authority_age_months'] ?? null;
        return ['ok' => true, 'level' => 'ok', 'msg' => 'USDOT ' . $usdot . ': ' . ($r['legal_name'] ?? 'sin nombre') . ' · autoridad ' . ($r['authority_status'] ?? '?') . ' · seguro ' . (($r['insured'] ?? null) === true ? 'registrado' : (($r['insured'] ?? null) === false ? 'sin registrar' : '?'))
            . ($age !== null ? ' · ' . $age . ' meses de antigüedad' : '') . '.'];
    }

    private static function testSmtp(): array
    {
        $to = Alerts::mailTo();
        if ($to === '') {
            return ['ok' => false, 'level' => 'warn', 'msg' => 'No hay destinatario: configura un correo en Alertas o MAIL_TO en config/.env.'];
        }
        $r = Mailer::send('[Captacion] Prueba de correo', "Prueba de NorthStar Captacion.\nSi lees esto, el correo de avisos funciona.\n");
        if ($r === 'sent') {
            return ['ok' => true, 'level' => 'ok', 'msg' => 'Correo de prueba enviado a ' . $to . '.'];
        }
        return ['ok' => true, 'level' => 'warn', 'msg' => 'No hay SMTP (o PHPMailer): la prueba quedó registrada en storage/mail.log, no se envió ningún correo.'];
    }
}
