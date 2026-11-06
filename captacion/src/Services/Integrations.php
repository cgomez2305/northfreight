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

    /** Resultado de un webhook ENTRANTE: ok | err | sig (firma rechazada). Lleva la racha de errores y las firmas malas recientes (avisos de la campana). */
    public static function webhook(string $name, string $outcome, string $msg = ''): void
    {
        try {
            $key = 'integration.' . $name;
            $cur = Settings::get($key, []);
            $cur = is_array($cur) ? $cur : [];
            $now = Clock::now();
            $cur['msg'] = mb_substr(Storage::redact($msg), 0, 160);
            if ($outcome === 'ok') {
                $cur['last'] = 'ok';
                $cur['ok_at'] = $now;
                $cur['streak'] = 0;
            } elseif ($outcome === 'err') {
                $cur['last'] = 'err';
                $cur['err_at'] = $now;
                $cur['err'] = mb_substr(Storage::redact($msg), 0, 200);
                $cur['streak'] = (int) ($cur['streak'] ?? 0) + 1;
            } else {
                $cur['last'] = 'sig';
                $cur['sig_at'] = $now;
                $sig = array_values(array_filter((array) ($cur['sig'] ?? []), static fn($t) => is_int($t) && $t > Clock::time() - 86400));
                $sig[] = Clock::time();
                $cur['sig'] = array_slice($sig, -20);
            }
            Settings::set($key, $cur);
        } catch (\Throwable) {
            // el estado es un extra: nunca rompe el webhook
        }
    }

    /** Firmas rechazadas en la ultima hora. */
    public static function sigFails(string $name): int
    {
        $l = self::last($name);
        return count(array_filter((array) ($l['sig'] ?? []), static fn($t) => is_int($t) && $t > Clock::time() - 3600));
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
        // Informes automaticos (A13/A14)
        $rc = Settings::get('cron.reports');
        $rat = is_array($rc) && isset($rc['at']) ? Clock::parse((string) $rc['at']) : null;
        $rlate = $rat !== null && $now - $rat > 7200;
        $cards[] = [
            'key' => 'reports', 'title' => 'Informes automáticos', 'testable' => false,
            'level' => $rat === null ? 'warn' : ($rlate ? 'bad' : 'ok'),
            'headline' => $rat === null ? 'Nunca se ha ejecutado' : ($rlate ? 'Lleva más de 2 horas sin correr' : 'Corriendo'),
            'details' => [['Última pasada', $rat === null ? '—' : Fmt::dateTime((string) $rc['at']) . ' · ' . Fmt::ago((string) $rc['at'])], ['Programado', 'cada hora; resumen los lunes 8:00 a. m. e informe el día 1 (hora de Nueva York)']],
            'last' => '', 'error' => '',
            'help' => $rat === null || $rlate ? 'Agrega la tarea de cron de cron/reports_cron.php (cada hora) en hPanel. Pasos en el README §18.' : '',
        ];
        // Prospectos FMCSA (cron diario) y datos publicos de Socrata
        $pc = Settings::get('cron.prospects');
        $pat = is_array($pc) && isset($pc['at']) ? Clock::parse((string) $pc['at']) : null;
        $pcfg = ProspectConfig::get();
        $plate = $pcfg['enabled'] && ($pat === null || $now - $pat > 10800);
        $run = ProspectImport::state();
        $cards[] = [
            'key' => 'prospects', 'title' => 'Prospectos FMCSA (cron)', 'testable' => false,
            'level' => !$pcfg['enabled'] ? 'off' : ($plate ? 'warn' : (is_array($run) && !empty($run['error']) ? 'bad' : 'ok')),
            'headline' => !$pcfg['enabled'] ? 'Búsqueda apagada' : ($pat === null ? 'Nunca se ha ejecutado' : ($plate ? 'Lleva más de 3 horas sin correr' : 'Corriendo')),
            'details' => [['Última pasada', $pat === null ? '—' : Fmt::dateTime((string) $pc['at']) . ' · ' . Fmt::ago((string) $pc['at'])], ['Última corrida', is_array($run) ? ($run['day'] ?? '—') . ' · ' . (!empty($run['finished']) ? 'terminada' : 'a medias') . ' · ' . (int) ($run['counters']['new'] ?? 0) . ' nuevos' : '—'], ['Programado', 'cada hora; corre una vez al día desde las 6:00 a. m. (Nueva York)']],
            'last' => '', 'error' => is_array($run) && !empty($run['error']) ? (string) $run['error'] : '',
            'help' => $pcfg['enabled'] && ($pat === null || $plate) ? 'Agrega la tarea de cron de cron/fmcsa_daily.php (cada hora, minuto 20) en hPanel. Pasos en el README §19.' : '',
        ];
        $so = self::last('socrata');
        $sbad = ($so['last'] ?? '') === 'err';
        [$sok] = self::lastLine($so);
        $cards[] = [
            'key' => 'socrata', 'title' => 'Datos públicos de FMCSA (Socrata)', 'testable' => true,
            'level' => $sbad ? 'bad' : ($sok > 0 ? 'ok' : 'off'), 'headline' => $sbad ? 'La última consulta falló' : ($sok > 0 ? 'Responde' : 'Sin consultas todavía'),
            'details' => [['Token (opcional)', self::mask(Env::get('SOCRATA_APP_TOKEN')) ?? 'sin token: límite compartido'], ['Servicio', Env::get('SOCRATA_API_BASE') !== null ? 'personalizado (SOCRATA_API_BASE)' : 'data.transportation.gov'], ['Datasets', ProspectImport::GRANTS_DATASET . ' y ' . ProspectImport::CENSUS_DATASET]],
            'last' => $sok > 0 ? 'Última consulta correcta ' . Fmt::ago((string) $so['ok_at']) : 'Sin consultas todavía', 'error' => $sbad ? (string) ($so['err'] ?? '') : '',
            'help' => 'Es de solo lectura y no necesita llave. Con SOCRATA_APP_TOKEN (gratis en data.transportation.gov) sube el límite de consultas.',
        ];
        // Canales externos (Q4b): webhooks entrantes
        foreach (Webhook::SOURCES as $src) {
            $cards[] = self::webhookCard($src);
        }
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

    private static function webhookCard(string $src): array
    {
        $l = self::last($src);
        $missing = Webhook::missing($src);
        $conf = $missing === [];
        $streak = (int) ($l['streak'] ?? 0);
        $sig = self::sigFails($src);
        $ev = Webhook::recent($src);
        $extra = [];
        $help = '';
        if ($src === 'meta' && $conf && (Env::get('META_PAGE_TOKEN') ?? '') === '') {
            $extra[] = ['Token de página', 'falta META_PAGE_TOKEN: no se pueden traer los leads'];
            $help = 'Falta META_PAGE_TOKEN en config/.env: el webhook responde 503 y Meta reintenta; no se pierde nada, pero ningún lead entra hasta que lo pongas.';
        } elseif ($src === 'meta') {
            $extra[] = ['Token de página', (Env::get('META_PAGE_TOKEN') ?? '') !== '' ? 'configurado' : 'sin configurar'];
        }
        if ($src === 'twilio') {
            $extra[] = ['Cuenta (SID)', (Env::get('TWILIO_SID') ?? '') !== '' ? 'configurado' : 'sin configurar (solo hará falta para enviar)'];
        }
        $level = !$conf ? 'off' : ($streak >= 3 ? 'bad' : ($sig >= 3 || ($l['last'] ?? '') === 'err' || ($l['last'] ?? '') === 'sig' ? 'warn' : 'ok'));
        $headline = !$conf ? 'Sin configurar' : ($streak >= 3 ? $streak . ' errores seguidos' : ($sig >= 3 ? 'Firma rechazada ' . $sig . ' veces en la última hora' : (($l['last'] ?? '') === 'err' ? 'El último evento falló' : (isset($l['ok_at']) ? 'Recibiendo' : 'Listo, sin eventos todavía'))));
        $details = [['Variables', $conf ? 'completas' : 'faltan: ' . implode(', ', $missing)]];
        $details = array_merge($details, $extra, [['Eventos (7 días)', $ev['processed'] . ' procesados · ' . $ev['ignored'] . ' ignorados · ' . $ev['error'] . ' con error']]);
        if (!$conf) {
            $help = 'Pon ' . implode(' y ', $missing) . ' en config/.env y pega la URL del webhook en ' . Webhook::TITLES[$src] . '. Sin eso el endpoint responde 503 y no hace nada. Guía: captacion/docs/setup-guide.md.';
        } elseif ($sig >= 3) {
            $help = 'La firma no coincide: casi seguro la clave del .env no es la que tiene ' . Webhook::TITLES[$src] . '. Revísala y pulsa «Probar».';
        }
        $lastTxt = isset($l['ok_at']) ? 'Último evento correcto ' . Fmt::ago((string) $l['ok_at']) . (($l['last'] ?? '') === 'ok' && ($l['msg'] ?? '') !== '' ? ' (' . $l['msg'] . ')' : '') : 'Sin eventos todavía';
        return [
            'key' => $src, 'title' => Webhook::TITLES[$src], 'testable' => $conf, 'webhook' => true, 'url' => Webhook::url($src), 'level' => $level, 'headline' => $headline,
            'details' => $details, 'last' => $lastTxt, 'error' => ($l['last'] ?? '') === 'err' ? (string) ($l['err'] ?? '') : (($l['last'] ?? '') === 'sig' ? 'Firma rechazada ' . Fmt::ago((string) ($l['sig_at'] ?? Clock::now())) : ''),
            'help' => $help, 'link' => '/config?tab=canales',
        ];
    }

    /**
     * «Probar» de un webhook: construye un evento de PRUEBA, lo firma con el secreto del .env y lo envia por HTTP al PROPIO endpoint (SELF_BASE_URL o APP_URL). No toca ningun servicio
     * externo ni crea datos: cada endpoint reconoce su evento de prueba (PING, leadgen nstest_…, NSTEST-PING, ping) y responde sin efectos.
     * @return array{ok:bool,level:string,msg:string}
     */
    public static function probe(string $src): array
    {
        if (!Webhook::configured($src)) {
            return ['ok' => false, 'level' => 'warn', 'msg' => Webhook::TITLES[$src] . ' no está configurado: faltan ' . implode(', ', Webhook::missing($src)) . ' en config/.env.'];
        }
        $base = rtrim((string) (Env::get('SELF_BASE_URL') ?? Url::base()), '/');
        $url = $base . Webhook::PATHS[$src];
        $secret = Webhook::secret($src);
        $t0 = microtime(true);
        $steps = [];
        if ($src === 'meta') {
            $ch = 'nstest' . bin2hex(random_bytes(4));
            $r = Http::request('GET', $url . '?hub.mode=subscribe&hub.verify_token=' . rawurlencode((string) Env::get('META_VERIFY_TOKEN')) . '&hub.challenge=' . $ch, null, [], 8, 3);
            $steps[] = ['Verificación (GET)', $r['status'] === 200 && trim($r['body']) === $ch, $r];
            $body = (string) json_encode(['object' => 'page', 'entry' => [['id' => '0', 'time' => time(), 'changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => MetaLeads::TEST_PREFIX . bin2hex(random_bytes(6)), 'form_id' => '0', 'page_id' => '0', 'created_time' => time()]]]]]]);
            $r = Http::request('POST', $url, $body, ['Content-Type: application/json', 'X-Hub-Signature-256: sha256=' . Webhook::hmacHex('sha256', $secret, $body)], 8, 3);
            $steps[] = ['Evento firmado (POST)', $r['status'] === 200, $r];
        } elseif ($src === 'calcom') {
            $body = (string) json_encode(['triggerEvent' => 'PING', 'createdAt' => gmdate('c'), 'payload' => ['note' => 'prueba de NorthStar Captación']]);
            $r = Http::request('POST', $url, $body, ['Content-Type: application/json', 'X-Cal-Signature-256: ' . Webhook::hmacHex('sha256', $secret, $body)], 8, 3);
            $steps[] = ['PING firmado', $r['status'] === 200, $r];
        } elseif ($src === 'twilio') {
            $params = ['AccountSid' => 'ACnstest', 'Body' => SmsInbound::TEST_BODY, 'From' => SmsInbound::TEST_FROM, 'MessageSid' => 'SMnstest' . bin2hex(random_bytes(8)), 'To' => '+10000000000'];
            ksort($params, SORT_STRING);
            $data = Webhook::url('twilio');
            foreach ($params as $k => $v) {
                $data .= $k . $v;
            }
            $r = Http::request('POST', $url, http_build_query($params), ['Content-Type: application/x-www-form-urlencoded', 'X-Twilio-Signature: ' . base64_encode(hash_hmac('sha1', $data, $secret, true))], 8, 3);
            $steps[] = ['SMS de prueba firmado', $r['status'] === 200 && str_contains($r['body'], '<Response'), $r];
        } else {
            $body = (string) json_encode(['event' => 'ping']);
            $ts = (string) time();
            $r = Http::request('POST', $url, $body, ['Content-Type: application/json', 'X-NS-Timestamp: ' . $ts, 'X-NS-Signature: sha256=' . Webhook::hmacHex('sha256', $secret, $ts . '.' . $body)], 8, 3);
            $steps[] = ['ping firmado', $r['status'] === 200, $r];
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $bad = array_values(array_filter($steps, static fn($x) => !$x[1]));
        if ($bad === []) {
            return ['ok' => true, 'level' => 'ok', 'msg' => 'Firma aceptada: ' . implode(' y ', array_map(static fn($x) => $x[0], $steps)) . ' respondieron bien en ' . $ms . ' ms. Es un evento de prueba: no creó ningún lead ni dato.'];
        }
        $r = $bad[0][2];
        $why = match (true) {
            $r['status'] === 0 => 'no se pudo llegar a ' . $url . ($r['error'] !== '' ? ' (' . $r['error'] . ')' : '') . '. Revisa APP_URL (o SELF_BASE_URL) en config/.env.',
            $r['status'] === 401, $r['status'] === 403 => 'el endpoint rechazó la firma (HTTP ' . $r['status'] . '): la clave del .env no coincide con la que usa el endpoint. Si acabas de cambiarla, ¿se guardó el .env?',
            $r['status'] === 503 => 'el endpoint responde 503 (sin configurar): ' . mb_substr(trim((string) $r['body']), 0, 120),
            $r['status'] === 429 => 'el endpoint limitó las peticiones (429): espera unos minutos.',
            default => 'HTTP ' . $r['status'] . ' inesperado: ' . mb_substr(Storage::redact(trim((string) $r['body'])), 0, 120),
        };
        return ['ok' => false, 'level' => 'bad', 'msg' => $bad[0][0] . ' falló: ' . $why];
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
                'socrata'  => self::testSocrata(),
                'meta', 'calcom', 'twilio', 'n8n' => self::probe($name),
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

    private static function testSocrata(): array
    {
        $c = new SocrataClient();
        try {
            $rows = $c->query(ProspectImport::GRANTS_DATASET, ProspectImport::grantsParams(gmdate('Ymd', Clock::time() - 30 * 86400), null, 1));
        } catch (SocrataUnavailable $e) {
            self::record('socrata', false, $e->getMessage());
            return ['ok' => false, 'level' => 'bad', 'msg' => 'Socrata no respondió bien: ' . mb_substr(Storage::redact($e->getMessage()), 0, 200)];
        }
        self::record('socrata', true);
        $d = (string) ($rows[0]['status_change_date'] ?? '');
        return ['ok' => true, 'level' => 'ok', 'msg' => 'Socrata respondió' . ($d !== '' ? '. Última concesión vista: ' . substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2) : ' sin filas en los últimos 30 días') . ($c->hasToken() ? ' (con token)' : ' (sin token: límite compartido)') . '.'];
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
