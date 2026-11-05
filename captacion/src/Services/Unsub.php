<?php
/**
 * Baja de correo de un clic (CAN-SPAM, A15 para el canal email). El enlace /u/{token} va en el pie de cada correo y en la cabecera List-Unsubscribe.
 * Token: base64url("u.<leadId>") + HMAC-SHA256 (43 caracteres, sin separador) con APP_KEY. Sin caducidad (la baja debe funcionar mientras exista el correo) y sin datos personales en la URL.
 * Efecto (idempotente): revoca el consentimiento de correo (consents.revoked_at, bandera stop_email), detiene TODAS las secuencias del lead y lo registra
 * en lead_events y activity_log.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Db;
use Captacion\Models\Lead;

final class Unsub
{
    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function token(int $leadId): string
    {
        $p = self::b64('u.' . $leadId);
        return $p . self::b64(hash_hmac('sha256', 'unsub|' . $p, Crypto::key(), true));
    }

    public static function url(int $leadId): string
    {
        return Url::base() . '/u/' . self::token($leadId);
    }

    /** @return ?int id del lead si el token es autentico */
    public static function verify(string $token): ?int
    {
        if (preg_match('/^([A-Za-z0-9_-]{4,40})([A-Za-z0-9_-]{43})$/', $token, $m) !== 1) {
            return null;
        }
        try {
            $ok = hash_equals(self::b64(hash_hmac('sha256', 'unsub|' . $m[1], Crypto::key(), true)), $m[2]);
        } catch (\Throwable) {
            return null;
        }
        $raw = base64_decode(strtr($m[1], '-_', '+/'), true);
        return $ok && $raw !== false && preg_match('/^u\.(\d{1,12})$/', $raw, $p) === 1 ? (int) $p[1] : null;
    }

    /** Aplica la baja. @return array{ok:bool,lang:string,first_name:string,already:bool} */
    public static function apply(int $leadId, string $via): array
    {
        $lead = Lead::find($leadId);
        if ($lead === null) {
            return ['ok' => false, 'lang' => 'en', 'first_name' => '', 'already' => false];
        }
        $already = in_array('stop_email', (array) $lead['flags'], true) || in_array('stop', (array) $lead['flags'], true);
        $n = $already ? 0 : Consent::revoke($leadId, 'email', $via);
        $stopped = Sequences::stopAll($leadId, 'unsubscribed', null);
        if (!$already || $n > 0 || $stopped > 0) {
            ActivityLog::record('unsubscribe', null, 'lead', $leadId, ['via' => $via, 'consents' => $n, 'sequences' => $stopped]);
        }
        return ['ok' => true, 'lang' => (string) $lead['language'], 'first_name' => (string) $lead['first_name'], 'already' => $already && $n === 0];
    }
}
