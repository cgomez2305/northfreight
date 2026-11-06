<?php
/**
 * Mensajes ENTRANTES de un lead (SMS de Twilio, WhatsApp/SMS/correo que reporta n8n, primer mensaje de WhatsApp en /api/lead.php).
 *
 *  - record(): guarda el mensaje (messages, direccion 'in'), marca leads.last_inbound_at (y wa_last_inbound_at si es WhatsApp: abre la ventana de 24 h) y, en el PRIMER
 *    mensaje de WhatsApp, deja un consentimiento de canal «whatsapp_inbound» con el texto y la hora de ese mensaje. Ese registro SOLO permite responder dentro de la ventana:
 *    NO es consentimiento de marketing (Consent::isGranted($id, 'whatsapp') sigue en false).
 *  - replied(): A7. Pausa las secuencias del lead, anota «el lead respondio» y avisa a Carlos por Telegram (job telegram_notice).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class Inbound
{
    public const WA_INBOUND_VERSION = 'wa-inbound-1';

    public static function clean(mixed $v, int $max): string
    {
        $s = is_string($v) ? mb_scrub($v) : '';
        $s = (string) preg_replace('/[^\P{C}\n\t]/u', '', $s);
        return mb_substr(trim($s), 0, $max);
    }

    /** Hora del mensaje: la dada si es razonable (hasta 48 h atras, hasta 5 min en el futuro); si no, ahora. */
    public static function when(mixed $at): int
    {
        $t = is_string($at) && $at !== '' ? strtotime($at) : (is_int($at) ? $at : false);
        return $t !== false && $t >= Clock::time() - 172800 && $t <= Clock::time() + 300 ? (int) $t : Clock::time();
    }

    public static function record(int $leadId, string $channel, string $text, ?int $atTs = null, ?string $providerId = null, string $via = 'webhook'): void
    {
        $at = Clock::at($atTs ?? Clock::time());
        $text = self::clean($text, 1000);
        Db::transaction(static function () use ($leadId, $channel, $text, $at, $providerId, $via): void {
            Db::insert('messages', ['lead_id' => $leadId, 'channel' => $channel, 'direction' => 'in', 'body' => $text !== '' ? $text : null, 'provider_id' => $providerId !== null ? substr($providerId, 0, 80) : null, 'status' => 'received', 'created_at' => $at]);
            Db::run('UPDATE leads SET last_inbound_at = GREATEST(COALESCE(last_inbound_at, ?), ?)' . ($channel === 'whatsapp' ? ', wa_last_inbound_at = GREATEST(COALESCE(wa_last_inbound_at, ?), ?)' : '') . ' WHERE id = ?',
                $channel === 'whatsapp' ? [$at, $at, $at, $at, $leadId] : [$at, $at, $leadId]);
            if ($channel === 'whatsapp' && (int) Db::value("SELECT COUNT(*) FROM consents WHERE lead_id = ? AND channel = 'whatsapp_inbound'", [$leadId]) === 0) {
                Db::insert('consents', ['lead_id' => $leadId, 'channel' => 'whatsapp_inbound', 'consent_text' => 'El lead escribio primero por WhatsApp. Primer mensaje: «' . ($text !== '' ? $text : '(sin texto)') . '». Solo permite responder dentro de la ventana de 24 h; no es consentimiento de marketing.',
                    'consent_version' => self::WA_INBOUND_VERSION, 'granted_at' => $at]);
            }
            LeadIngest::event($leadId, 'inbound_message', ['channel' => $channel, 'via' => $via]);
        });
    }

    /** A7: el lead respondio por cualquier canal. @return int secuencias pausadas */
    public static function replied(int $leadId, string $channel, string $snippet = ''): int
    {
        $paused = Sequences::pauseForLead($leadId, 'responded', null);
        LeadIngest::event($leadId, 'lead_replied', ['channel' => $channel, 'paused' => $paused]);
        Queue::push('telegram_notice', ['lead_id' => $leadId, 'kind' => 'reply', 'channel' => $channel, 'snippet' => self::clean($snippet, 140)], Clock::now());
        return $paused;
    }
}
