<?php
/**
 * Destino 2: webhook saliente firmado, pensado para n8n (que luego escribe en el Google Sheet de NorthStar). POST JSON versionado (schema northstar.handoff.v1).
 *
 * Firma: cabecera X-NS-Signature: sha256=<hex> = HMAC-SHA256(secreto, "<X-NS-Timestamp>.<cuerpo>"). Quien recibe debe recalcularla y rechazar marcas de tiempo viejas (> 5 min).
 * Otras cabeceras: X-NS-Timestamp (segundos Unix), X-NS-Delivery (id de la entrega), Idempotency-Key (handoff-<id>). Timeout 5 s. 2xx = entregado; cualquier otra cosa o error de red lanza
 * (la cola reintenta a 1, 5 y 15 min). No sigue redirecciones. El secreto vive cifrado en la base (settings) y nunca se muestra ni se registra.
 */

declare(strict_types=1);

namespace Captacion\Services\Handoff;

use Captacion\Clock;
use Captacion\Services\Handoff;
use Captacion\Services\HandoffCard;
use Captacion\Services\Http;
use Captacion\Services\Storage;

final class WebhookTarget implements HandoffTarget
{
    public const SCHEMA = 'northstar.handoff.v1';

    public function key(): string
    {
        return 'webhook';
    }

    public function enabled(): bool
    {
        $c = Handoff::config();
        return $c['webhook_on'] && $c['webhook_url'] !== '' && $c['webhook_secret'] !== '';
    }

    /** Cuerpo JSON de la entrega (separado para poder probarlo y documentarlo). */
    public static function body(array $card, array $handoff): string
    {
        return (string) json_encode([
            'schema'     => self::SCHEMA,
            'event'      => ($handoff['reason'] ?? '') === 'test' ? 'handoff.test' : 'lead.presented',
            'handoff_id' => (int) ($handoff['id'] ?? 0),
            'version'    => (int) ($handoff['version'] ?? 1),
            'sent_at'    => gmdate('c', Clock::time()),
            'lead'       => HandoffCard::payload($card),
            'confirm_urls' => $handoff['urls'] ?? ['signed' => '', 'rejected' => ''],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public function send(array $card, array $handoff): array
    {
        $c = Handoff::config();
        if (!$this->enabled()) {
            throw new \RuntimeException('Webhook sin configurar.');
        }
        $body = self::body($card, $handoff);
        $ts = (string) Clock::time();
        $r = Http::request('POST', $c['webhook_url'], $body, [
            'Content-Type: application/json', 'X-NS-Timestamp: ' . $ts, 'X-NS-Signature: ' . self::sign($c['webhook_secret'], $ts, $body),
            'X-NS-Delivery: ' . (int) ($handoff['id'] ?? 0), 'Idempotency-Key: handoff-' . (int) ($handoff['id'] ?? 0) . '-v' . (int) ($handoff['version'] ?? 1),
        ], 5, 3);
        if ($r['status'] === 0) {
            throw new \RuntimeException('El webhook no responde: ' . ($r['error'] !== '' ? $r['error'] : 'sin respuesta'));
        }
        if ($r['status'] < 200 || $r['status'] > 299) {
            throw new \RuntimeException('El webhook respondió HTTP ' . $r['status'] . '.');
        }
        return ['ok' => true, 'code' => $r['status'], 'msg' => 'Webhook entregado (HTTP ' . $r['status'] . ').'];
    }
}
