<?php
/**
 * ¿Se le puede escribir a este lead por este canal AHORA? Una sola respuesta para n8n (POST /internal/leads/{id}/consent-check) y para el CRM.
 *
 *  - email, sms, call: consentimiento vigente en `consents` (sin revocar) y sin bandera STOP (Consent::isGranted).
 *  - whatsapp: dos bases posibles.
 *      1) opt_in: hay un consentimiento de canal «whatsapp» vigente (p. ej. un formulario de Meta cuyo aviso lo cubre, o una futura version del texto del sitio que lo incluya).
 *         CONSENT_TEXT_V1 del sitio NO cubre WhatsApp, asi que un lead del sitio NO pasa por aqui.
 *      2) inbound_window: el lead escribio primero y han pasado menos de 24 h desde su ultimo mensaje de WhatsApp (ventana de servicio de WhatsApp). Es la base para RESPONDER,
 *         nunca consentimiento de marketing; un STOP la cierra.
 *    free_text_allowed = ventana abierta (texto libre); fuera de la ventana solo plantillas aprobadas Y solo con opt_in.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class Messaging
{
    public const CHANNELS = ['whatsapp', 'sms', 'email', 'call'];
    public const WINDOW = 86400;

    /**
     * @return array{allowed:bool,channel:string,basis:?string,reason:?string,free_text_allowed:bool,window_expires_at:?string}
     */
    public static function consentCheck(int $leadId, string $channel): array
    {
        $channel = strtolower($channel);
        $r = ['allowed' => false, 'channel' => $channel, 'basis' => null, 'reason' => null, 'free_text_allowed' => false, 'window_expires_at' => null];
        $row = Db::one('SELECT flags, wa_last_inbound_at FROM leads WHERE id = ? AND deleted_at IS NULL', [$leadId]);
        if ($row === null) {
            return ['reason' => 'lead_not_found'] + $r;
        }
        if (!in_array($channel, self::CHANNELS, true)) {
            return ['reason' => 'unknown_channel'] + $r;
        }
        $flags = Db::decode($row['flags']);
        if ($channel !== 'whatsapp') {
            if (in_array('stop', $flags, true) || in_array('stop_' . $channel, $flags, true)) {
                return ['reason' => 'stop'] + $r;
            }
            $ok = Consent::isGranted($leadId, $channel);
            return ['allowed' => $ok, 'basis' => $ok ? 'opt_in' : null, 'reason' => $ok ? null : 'no_consent'] + $r;
        }
        // WhatsApp
        if (in_array('stop', $flags, true) || in_array('stop_whatsapp', $flags, true)) {
            return ['reason' => 'stop'] + $r;
        }
        $lastIn = $row['wa_last_inbound_at'] !== null ? Clock::parse((string) $row['wa_last_inbound_at']) : null;
        $open = $lastIn !== null && Clock::time() < $lastIn + self::WINDOW;
        $r['window_expires_at'] = $lastIn !== null ? Clock::at($lastIn + self::WINDOW) : null;
        $r['free_text_allowed'] = $open && !in_array('stop_whatsapp_inbound', $flags, true);
        if (Consent::isGranted($leadId, 'whatsapp')) {
            return ['allowed' => true, 'basis' => 'opt_in', 'reason' => null] + $r;
        }
        if ($r['free_text_allowed'] && Consent::isGranted($leadId, 'whatsapp_inbound')) {
            return ['allowed' => true, 'basis' => 'inbound_window', 'reason' => null] + $r;
        }
        $r['free_text_allowed'] = false;
        return ['reason' => $lastIn !== null ? 'window_closed' : 'no_whatsapp_consent'] + $r;
    }
}
