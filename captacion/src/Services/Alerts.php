<?php
/**
 * Alertas de leads (Configuracion > Alertas). Una sola fuente para el motor (LeadIngest::enqueueFor), los handlers de la cola y la pantalla.
 *
 * settings['alerts'] (se completa con DEFAULTS):
 *   hot_telegram      A2: Telegram inmediato al llegar un lead caliente (urgente: ignora el horario de silencio)
 *   followup_telegram A3: segunda alerta si nadie contacto al lead tras followup_minutes (urgente)
 *   followup_minutes  5 a 120 (15 por defecto)
 *   warm_telegram     'auto' | 'on' | 'off'. NUEVO en Q2b: Telegram tambien para leads TIBIOS. 'auto' = activado mientras no haya FMCSA_WEBKEY (sin la llave de FMCSA
 *                     un lead muy bueno suma 65 a 70 puntos y casi nunca llega a caliente (70), asi que los mejores quedarian sin alerta por Telegram) y apagado cuando ya la haya
 *   email             avisos por correo (leads tibios y frios, reingresos y, si Telegram esta apagado, los calientes)
 *   mail_to           destinatario del correo; vacio = MAIL_TO del .env
 *   quiet             {enabled, start 'HH:MM', end 'HH:MM'} horario de silencio en la zona de visualizacion: las alertas NO urgentes (Telegram de tibios y correos) se
 *                     retrasan hasta que termina. Las urgentes (A2, A3 y firmado que vuelve a escribir) nunca se retrasan.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Env;

final class Alerts
{
    public const DEFAULTS = [
        'hot_telegram' => true, 'followup_telegram' => true, 'followup_minutes' => 15, 'warm_telegram' => 'auto', 'email' => true, 'mail_to' => '',
        'quiet' => ['enabled' => false, 'start' => '22:00', 'end' => '07:00'],
    ];
    public const WARM_MODES = ['auto' => 'Automático (recomendado)', 'on' => 'Siempre', 'off' => 'Nunca'];

    private static function time(mixed $v, string $default): string
    {
        return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1 ? $v : $default;
    }

    /** Ajustes vigentes, completos y normalizados. */
    public static function get(): array
    {
        try {
            $s = Settings::get('alerts', []);
        } catch (\Throwable) {
            $s = [];
        }
        $s = is_array($s) ? $s : [];
        $d = self::DEFAULTS;
        $q = is_array($s['quiet'] ?? null) ? $s['quiet'] : [];
        $mins = (int) ($s['followup_minutes'] ?? $d['followup_minutes']);
        return [
            'hot_telegram'      => (bool) ($s['hot_telegram'] ?? $d['hot_telegram']),
            'followup_telegram' => (bool) ($s['followup_telegram'] ?? $d['followup_telegram']),
            'followup_minutes'  => $mins >= 5 && $mins <= 120 ? $mins : $d['followup_minutes'],
            'warm_telegram'     => isset(self::WARM_MODES[$s['warm_telegram'] ?? '']) ? $s['warm_telegram'] : $d['warm_telegram'],
            'email'             => (bool) ($s['email'] ?? $d['email']),
            'mail_to'           => is_string($s['mail_to'] ?? null) && filter_var($s['mail_to'], FILTER_VALIDATE_EMAIL) ? $s['mail_to'] : '',
            'quiet'             => ['enabled' => (bool) ($q['enabled'] ?? false), 'start' => self::time($q['start'] ?? null, '22:00'), 'end' => self::time($q['end'] ?? null, '07:00')],
        ];
    }

    public static function followupMinutes(): int
    {
        return self::get()['followup_minutes'];
    }

    public static function fmcsaKey(): bool
    {
        return (Env::get('FMCSA_WEBKEY') ?? '') !== '';
    }

    /** ¿Se avisa por Telegram de los leads tibios? Con 'auto': si y solo si no hay llave de FMCSA. */
    public static function warmTelegram(?array $cfg = null): bool
    {
        $m = ($cfg ?? self::get())['warm_telegram'];
        return $m === 'on' || ($m === 'auto' && !self::fmcsaKey());
    }

    /** Destinatario del correo de aviso: el de Configuracion o, si esta vacio, MAIL_TO del .env. */
    public static function mailTo(): string
    {
        $to = self::get()['mail_to'];
        if ($to !== '') {
            return $to;
        }
        $env = trim((string) Env::get('MAIL_TO', ''));
        return filter_var($env, FILTER_VALIDATE_EMAIL) ? $env : '';
    }

    /**
     * Si $ts cae dentro del horario de silencio, el instante (timestamp) en que termina; si no, null. El horario puede cruzar la medianoche (22:00 a 07:00).
     */
    public static function quietUntil(int $ts, ?array $cfg = null): ?int
    {
        $q = ($cfg ?? self::get())['quiet'];
        if (!$q['enabled'] || $q['start'] === $q['end']) {
            return null;
        }
        $d = Fmt::et($ts);
        $hm = $d->format('H:i');
        $crosses = strcmp($q['start'], $q['end']) > 0;
        $inside = $crosses ? ($hm >= $q['start'] || $hm < $q['end']) : ($hm >= $q['start'] && $hm < $q['end']);
        if (!$inside) {
            return null;
        }
        [$h, $m] = array_map('intval', explode(':', $q['end']));
        $end = $d->setTime($h, $m, 0);
        if ($end->getTimestamp() <= $ts) {
            $end = $end->modify('+1 day')->setTime($h, $m, 0);
        }
        return $end->getTimestamp();
    }

    /** Fecha UTC en que debe correr una alerta NO urgente creada ahora. */
    public static function nonUrgentRunAt(?int $now = null): string
    {
        $now ??= Clock::time();
        $until = self::quietUntil($now);
        return Clock::at($until ?? $now);
    }

    /** @return array{ok:bool,errors:array<string,string>,values:array} valida el formulario de Alertas */
    public static function validate(array $in): array
    {
        $err = [];
        $mins = $in['followup_minutes'] ?? '';
        if (!is_string($mins) || preg_match('/^\d{1,3}$/', trim($mins)) !== 1 || (int) $mins < 5 || (int) $mins > 120) {
            $err['followup_minutes'] = 'Escribe un número de minutos entre 5 y 120.';
        }
        $warm = $in['warm_telegram'] ?? '';
        if (!is_string($warm) || !isset(self::WARM_MODES[$warm])) {
            $err['warm_telegram'] = 'Elige una opción de la lista.';
        }
        $mailTo = is_string($in['mail_to'] ?? null) ? trim($in['mail_to']) : '';
        if ($mailTo !== '' && (!filter_var($mailTo, FILTER_VALIDATE_EMAIL) || strlen($mailTo) > 190)) {
            $err['mail_to'] = 'Ese correo no es válido. Déjalo vacío para usar MAIL_TO del servidor.';
        }
        $qOn = ($in['quiet_enabled'] ?? '') === '1';
        $start = is_string($in['quiet_start'] ?? null) ? $in['quiet_start'] : '';
        $end = is_string($in['quiet_end'] ?? null) ? $in['quiet_end'] : '';
        $re = '/^([01]\d|2[0-3]):[0-5]\d$/';
        if ($qOn) {
            if (preg_match($re, $start) !== 1 || preg_match($re, $end) !== 1) {
                $err['quiet'] = 'Escribe las horas de silencio con el formato HH:MM.';
            } elseif ($start === $end) {
                $err['quiet'] = 'El inicio y el fin del silencio no pueden ser iguales.';
            }
        }
        $values = [
            'hot_telegram' => ($in['hot_telegram'] ?? '') === '1', 'followup_telegram' => ($in['followup_telegram'] ?? '') === '1',
            'followup_minutes' => is_string($mins) && ctype_digit(trim($mins)) ? (int) $mins : 15, 'warm_telegram' => is_string($warm) && isset(self::WARM_MODES[$warm]) ? $warm : 'auto',
            'email' => ($in['email'] ?? '') === '1', 'mail_to' => $mailTo,
            'quiet' => ['enabled' => $qOn, 'start' => preg_match($re, $start) === 1 ? $start : self::get()['quiet']['start'], 'end' => preg_match($re, $end) === 1 ? $end : self::get()['quiet']['end']],
        ];
        return ['ok' => !$err, 'errors' => $err, 'values' => $values];
    }
}
