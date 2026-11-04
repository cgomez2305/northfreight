<?php
/**
 * Alertas a Carlos por Telegram (A2/A3). Bot API: POST {TELEGRAM_API_BASE}/bot<TOKEN>/sendMessage (JSON, parse_mode HTML).
 * Variables: TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID, TELEGRAM_API_BASE (solo pruebas; por defecto https://api.telegram.org).
 * El token NUNCA se registra ni aparece en mensajes de error (Storage::redact + mensajes sin URL).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Env;

final class Telegram
{
    public static function configured(): bool
    {
        return (Env::get('TELEGRAM_BOT_TOKEN') ?? '') !== '' && (Env::get('TELEGRAM_CHAT_ID') ?? '') !== '';
    }

    /** @throws \RuntimeException sin configurar o si Telegram rechaza/no responde (el job se reintenta). Deja el resultado en Integrations (estado de la pantalla de Configuracion). */
    public static function send(string $htmlText): void
    {
        try {
            self::doSend($htmlText);
            Integrations::record('telegram', true);
        } catch (\RuntimeException $e) {
            if (self::configured()) {
                Integrations::record('telegram', false, $e->getMessage());
            }
            throw $e;
        }
    }

    private static function doSend(string $htmlText): void
    {
        if (!self::configured()) {
            throw new \RuntimeException('Telegram sin configurar: faltan TELEGRAM_BOT_TOKEN o TELEGRAM_CHAT_ID en config/.env');
        }
        $base = rtrim(Env::get('TELEGRAM_API_BASE', 'https://api.telegram.org') ?? 'https://api.telegram.org', '/');
        $url = $base . '/bot' . Env::get('TELEGRAM_BOT_TOKEN') . '/sendMessage';
        $payload = json_encode([
            'chat_id'                  => Env::get('TELEGRAM_CHAT_ID'),
            'text'                     => $htmlText,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $r = Http::request('POST', $url, (string) $payload, ['Content-Type: application/json'], 8, 3);
        if ($r['status'] === 0) {
            throw new \RuntimeException('Telegram no responde: ' . ($r['error'] !== '' ? $r['error'] : 'sin respuesta'));
        }
        $json = json_decode($r['body'], true);
        if ($r['status'] !== 200 || !is_array($json) || empty($json['ok'])) {
            $desc = is_array($json) && isset($json['description']) ? Storage::redact((string) $json['description']) : 'respuesta inesperada';
            throw new \RuntimeException('Telegram rechazo el mensaje (HTTP ' . $r['status'] . '): ' . mb_substr($desc, 0, 200));
        }
    }

    private static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Texto de la alerta de un lead (HTML de Telegram). $kind: 'hot' | 'warm' | 'followup' | 'signed_reentry'; $minutes = espera de la segunda alerta.
     * Lleva: nombre, perfil, estado, equipo, puntuacion, fuente, pagina de llegada, enlace tel: y enlace al lead en el panel.
     */
    public static function leadText(array $lead, string $kind, ?int $minutes = null): string
    {
        $profiles = ['owner_operator' => 'Owner-operator', 'small_carrier' => 'Carrier pequeno', 'company_driver' => 'Company driver'];
        $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
        $phone = (string) ($lead['phone_e164'] ?? '');
        $equipment = self::equipment($lead);
        $head = match ($kind) {
            'followup'       => 'SIN CONTACTO tras ' . ($minutes ?? 15) . ' min: lead caliente',
            'warm'           => 'Lead TIBIO',
            'signed_reentry' => 'Un lead ya FIRMADO volvio a escribir',
            default          => 'Lead CALIENTE',
        };
        $lines = [
            '<b>' . self::e($head) . '</b>',
            '<b>' . self::e($name !== '' ? $name : 'Sin nombre') . '</b> (' . self::e($profiles[$lead['profile'] ?? ''] ?? '?') . ')',
            'Estado: ' . self::e($lead['state'] ?? '-') . ' | Equipo: ' . self::e($equipment !== '' ? $equipment : '-'),
            'Puntuacion: ' . self::e($lead['score'] ?? 0) . '/100',
            'Fuente: ' . self::e($lead['source'] ?? '-') . ' | Llego por: ' . self::e($lead['landing_page'] ?? '-'),
            ...(self::redFlags($lead) !== '' ? ['<b>Banderas rojas:</b> ' . self::e(self::redFlags($lead))] : []),
            $phone !== '' ? 'Llamar: <a href="tel:' . self::e($phone) . '">' . self::e($phone) . '</a>' : 'Sin telefono valido',
            'Panel: <a href="' . self::e(Url::lead((int) ($lead['id'] ?? 0))) . '">abrir lead #' . (int) ($lead['id'] ?? 0) . '</a>',
        ];
        return implode("\n", $lines);
    }

    /** Banderas rojas de FMCSA y similares (red:*), para que Carlos las vea antes de llamar. */
    private static function redFlags(array $lead): string
    {
        $flags = $lead['flags'] ?? [];
        if (is_string($flags)) {
            $flags = json_decode($flags, true) ?: [];
        }
        $red = array_map(static fn($f) => substr((string) $f, 4), array_filter((array) $flags, static fn($f) => str_starts_with((string) $f, 'red:')));
        return implode(', ', $red);
    }

    /** Equipo del lead como texto (equipment_types ya decodificado o JSON). */
    private static function equipment(array $lead): string
    {
        $e = $lead['equipment_types'] ?? [];
        if (is_string($e)) {
            $e = json_decode($e, true) ?: [];
        }
        return implode(', ', array_map('strval', (array) $e));
    }
}
