<?php
/**
 * SMS entrantes de Twilio (A7, A15; POST /api/webhooks/sms.php). La firma ya se verifico (Webhook::twilioValid) antes de llegar aqui.
 *
 *  - STOP, STOPALL, UNSUBSCRIBE, CANCEL, END, QUIT (palabra sola, sin importar mayusculas ni signos): revoca el consentimiento de SMS y de WhatsApp (A15). Respuesta TwiML vacia
 *    (Twilio manda su propia confirmacion de baja si el Opt-Out avanzado esta activo).
 *  - START, YES, UNSTOP: restablece SOLO el SMS y solo si ese lead ya habia dado consentimiento de SMS antes.
 *  - HELP, INFO: responde con el texto de ayuda (Configuracion > Canales externos) en TwiML.
 *  - Cualquier otra respuesta: la guarda, pausa las secuencias del lead (A7), anota el evento y avisa a Carlos por Telegram.
 *  - Con `OptOutType` (lo manda Twilio cuando gestiona el STOP por su cuenta) se usa ese valor aunque el texto sea otro.
 *  - `MessageStatus` (devolucion de estado de entrega, sin Body): actualiza messages.status del MessageSid.
 *
 * FORMATO (Twilio, documentado): application/x-www-form-urlencoded con MessageSid, From, To, Body, NumMedia, OptOutType (opcional); estado: MessageSid, MessageStatus, ErrorCode.
 * Firma: X-Twilio-Signature = base64(HMAC-SHA1(TWILIO_TOKEN, URL + parametros ordenados)); ver Webhook::twilioValid(). NO probado contra Twilio real.
 * Prueba propia («Probar» en Integraciones): Body NSTEST-PING desde +15005550006, sin efectos.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class SmsInbound
{
    public const STOP = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'];
    public const START = ['START', 'YES', 'UNSTOP'];
    public const HELP = ['HELP', 'INFO'];
    public const TEST_FROM = '+15005550006';
    public const TEST_BODY = 'NSTEST-PING';
    public const DEFAULT_HELP = 'NorthStar Freight Solutions: dispatch support for owner-operators. Reply STOP to opt out. Msg & data rates may apply. Questions: +1 863-651-3322.';

    public static function config(): array
    {
        $c = Settings::get('twilio', []);
        $c = is_array($c) ? $c : [];
        $h = is_string($c['help_text'] ?? null) ? trim(Inbound::clean($c['help_text'], 320)) : '';
        return ['help_text' => $h !== '' ? $h : self::DEFAULT_HELP, 'help_custom' => $h !== ''];
    }

    /** @return ?string 'stop' | 'start' | 'help' | null (respuesta normal) */
    public static function keyword(string $body, string $optOutType = ''): ?string
    {
        $o = strtoupper(trim($optOutType));
        if ($o !== '') {
            $m = ['STOP' => 'stop', 'START' => 'start', 'HELP' => 'help'][$o] ?? null;
            if ($m !== null) {
                return $m;
            }
        }
        $w = strtoupper(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $body)));
        if (in_array($w, self::STOP, true)) {
            return 'stop';
        }
        if (in_array($w, self::START, true)) {
            return 'start';
        }
        return in_array($w, self::HELP, true) ? 'help' : null;
    }

    public static function twiml(?string $message = null): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . ($message === null || $message === '' ? '<Response></Response>' : '<Response><Message>' . htmlspecialchars($message, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Message></Response>');
    }

    /**
     * @param array<string,string> $p parametros del POST
     * @return array{twiml:string,status:string,summary:string,lead_id:?int}
     */
    public static function handle(array $p): array
    {
        $res = static fn(string $status, string $summary, ?int $lead = null, ?string $msg = null): array => ['twiml' => self::twiml($msg), 'status' => $status, 'summary' => $summary, 'lead_id' => $lead];
        $sid = preg_match('/^[A-Za-z0-9]{10,64}$/', (string) ($p['MessageSid'] ?? ($p['SmsSid'] ?? ''))) === 1 ? (string) ($p['MessageSid'] ?? $p['SmsSid']) : '';
        if ($sid === '') {
            return $res('ignored', 'sin MessageSid');
        }
        $from = Phone::normalize((string) ($p['From'] ?? ''));
        $body = Inbound::clean($p['Body'] ?? '', 1600);

        // Estado de entrega de un mensaje saliente
        if (isset($p['MessageStatus']) && !isset($p['Body'])) {
            $st = preg_replace('/[^a-z_]/', '', strtolower((string) $p['MessageStatus']));
            $id = Webhook::claim('twilio', Webhook::key('st', $sid, (string) $st));
            if ($id === null) {
                return $res('ignored', 'estado repetido');
            }
            $n = Db::run('UPDATE messages SET status = ? WHERE provider_id = ?', [substr((string) $st, 0, 20), $sid])->rowCount();
            Webhook::finish($id, $n > 0 ? 'processed' : 'ignored', null, 'estado ' . $st . ($n > 0 ? '' : ' (mensaje desconocido)'));
            return $res('processed', 'estado de entrega');
        }

        if ($from['ok'] && $from['e164'] === self::TEST_FROM && $body === self::TEST_BODY) {
            return $res('processed', 'prueba (sin efectos)');
        }
        $evId = Webhook::claim('twilio', Webhook::key('in', $sid));
        if ($evId === null) {
            return $res('ignored', 'mensaje repetido');
        }
        $kw = self::keyword($body, (string) ($p['OptOutType'] ?? ''));
        $help = $kw === 'help' ? self::config()['help_text'] : null;
        if (!$from['ok']) {
            Webhook::finish($evId, 'ignored', null, 'remitente sin telefono valido');
            return $res('ignored', 'remitente invalido', null, $help);
        }
        $ids = array_map(static fn($r) => (int) $r['id'], Db::all('SELECT id FROM leads WHERE phone_e164 = ? AND deleted_at IS NULL ORDER BY id DESC', [$from['e164']]));
        if ($ids === []) {
            Webhook::finish($evId, 'ignored', null, ($kw ?? 'respuesta') . ' de un número que no es un lead');
            return $res('ignored', 'número desconocido', null, $help);
        }
        $lead = $ids[0];
        if ($kw !== null) {
            foreach ($ids as $i) {
                Db::insert('messages', ['lead_id' => $i, 'channel' => 'sms', 'direction' => 'in', 'body' => mb_substr($body, 0, 1000), 'provider_id' => $sid, 'status' => 'received', 'created_at' => Clock::now()]);
            }
        }
        if ($kw === 'stop') {
            $r = Consent::revokeChannels($from['e164'], ['sms', 'whatsapp', 'whatsapp_inbound'], 'stop_sms');
            Webhook::finish($evId, 'processed', $lead, 'STOP: ' . $r['revoked'] . ' consentimientos revocados');
            return $res('processed', 'stop', $lead);
        }
        if ($kw === 'start') {
            $n = Consent::restoreSms($from['e164'], $body);
            Webhook::finish($evId, 'processed', $lead, $n > 0 ? 'START: SMS restablecido' : 'START sin consentimiento previo (no se restablece nada)');
            return $res('processed', 'start', $lead);
        }
        if ($kw === 'help') {
            Webhook::finish($evId, 'processed', $lead, 'HELP');
            return $res('processed', 'help', $lead, $help);
        }
        foreach ($ids as $i) {
            Inbound::record($i, 'sms', $body, null, $sid, 'twilio');
            Inbound::replied($i, 'sms', $body);
        }
        Webhook::finish($evId, 'processed', $lead, 'respuesta: secuencias en pausa y aviso a Carlos');
        return $res('processed', 'reply', $lead);
    }
}
