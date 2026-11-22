<?php
/**
 * Envio MANUAL de una plantilla desde la ficha del lead (fase AM): un correo uno a uno o un WhatsApp abierto con el texto ya resuelto.
 *
 * CORREO. Reglas (todas se comprueban otra vez al enviar):
 *  - Consentimiento de correo vigente (Consent::isGranted) O el lead escribio primero (leads.last_inbound_at). Nunca con bandera STOP/STOP de correo, con el correo en la lista
 *    de supresion ni con el lead anonimizado. DECISION: se acepta «escribio primero» porque es una conversacion uno a uno iniciada por el lead; las secuencias automaticas SI exigen
 *    consentimiento (Sequences::runStep).
 *  - Direccion fisica configurada (Configuracion > Correo): el pie CAN-SPAM la lleva siempre.
 *  - Sin marcadores [[CONFIRMAR…]] ni variables sin resolver, plantilla activa.
 *  - Topes de Configuracion > Correo: `cap_hour` (global) y `cap_lead` (por lead cada 24 h), contando TODOS los correos salientes (secuencias y manuales).
 *  - Sin SMTP no se envia nada ni se anota como enviado: se explica en pantalla (el correo no se «registra» en mail.log para esta accion).
 *  - Si sale: messages (con el usuario), evento template_sent y activity_log. No cuenta como primer contacto.
 *
 * WHATSAPP. Sin API: se abre wa.me con el texto resuelto (sin pie de baja) en una pestaña nueva y se anota «WhatsApp abierto con plantilla X» (el panel no puede saber si se envio).
 * Se bloquea con STOP, STOP de WhatsApp, lista de supresion o lead anonimizado.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;

final class LeadSend
{
    public const WA_MAX_CHARS = 1500;

    /** Plantillas activas; primero las del idioma del lead. @return list<array> */
    public static function templates(array $lead): array
    {
        $lang = in_array($lead['language'] ?? 'en', ['en', 'es'], true) ? (string) $lead['language'] : 'en';
        $all = array_values(array_filter(Templates::all(), static fn($t) => (int) $t['active'] === 1));
        usort($all, static fn($a, $b) => [$a['lang'] === $lang ? 0 : 1, $a['name']] <=> [$b['lang'] === $lang ? 0 : 1, $b['name']]);
        return $all;
    }

    /** Opciones ligeras para el selector de la ficha (sin sembrar plantillas: eso lo hace la pagina de envio). @return list<array{id:int,name:string,lang:string}> */
    public static function options(array $lead): array
    {
        $lang = in_array($lead['language'] ?? 'en', ['en', 'es'], true) ? (string) $lead['language'] : 'en';
        return Db::all('SELECT id, name, lang FROM email_templates WHERE active = 1 ORDER BY (lang = ?) DESC, name', [$lang]);
    }

    /** Motivo por el que NO se puede escribir un correo a este lead (o null). */
    public static function emailBlock(array $lead): ?string
    {
        $id = (int) $lead['id'];
        $flags = (array) ($lead['flags'] ?? []);
        if (($lead['anonymized_at'] ?? null) !== null) {
            return 'El lead está anonimizado: no tiene datos de contacto.';
        }
        if (empty($lead['email'])) {
            return 'El lead no tiene correo.';
        }
        if (in_array('stop', $flags, true) || in_array('stop_email', $flags, true)) {
            return 'El lead pidió no recibir correos (STOP o baja).';
        }
        if (Suppression::reason('email', (string) $lead['email']) !== null) {
            return 'Ese correo está en la lista de supresión.';
        }
        if (!Consent::isGranted($id, 'email') && empty($lead['last_inbound_at'])) {
            return 'El lead no dio consentimiento de correo y no ha escrito primero.';
        }
        if (Templates::address() === '') {
            return 'Falta la dirección física del remitente (Configuración > Correo): sin ella no se envía ningún correo.';
        }
        if (!Mailer::canDeliver()) {
            return 'No hay SMTP configurado: el correo no se enviaría a nadie, así que no se registra como enviado.';
        }
        return null;
    }

    /** Topes de envio (hora global y por lead cada 24 h). @return ?string motivo */
    public static function capBlock(int $leadId): ?string
    {
        $cfg = Sequences::mailSettings();
        $hour = (int) Db::value("SELECT COUNT(*) FROM messages WHERE channel = 'email' AND direction = 'out' AND status IN ('sent','logged') AND created_at > ?", [Clock::shift(-3600)]);
        if ($hour >= $cfg['cap_hour']) {
            return 'Tope de envíos por hora alcanzado (' . $cfg['cap_hour'] . '). Se ajusta en Configuración > Correo.';
        }
        $lead = (int) Db::value("SELECT COUNT(*) FROM messages WHERE lead_id = ? AND channel = 'email' AND direction = 'out' AND status IN ('sent','logged') AND created_at > ?", [$leadId, Clock::shift(-86400)]);
        if ($lead >= $cfg['cap_lead']) {
            return 'A este lead ya se le enviaron ' . $lead . ' correo' . ($lead === 1 ? '' : 's') . ' en las últimas 24 h (tope por lead: ' . $cfg['cap_lead'] . ', Configuración > Correo).';
        }
        return null;
    }

    public static function whatsappBlock(array $lead): ?string
    {
        $flags = (array) ($lead['flags'] ?? []);
        if (($lead['anonymized_at'] ?? null) !== null) {
            return 'El lead está anonimizado: no tiene datos de contacto.';
        }
        if (Fmt::waNumber($lead['phone_e164'] ?? null) === null) {
            return 'El lead no tiene un teléfono válido.';
        }
        if (in_array('stop', $flags, true) || in_array('stop_whatsapp', $flags, true) || in_array('stop_sms', $flags, true)) {
            return 'El lead pidió no recibir mensajes (STOP).';
        }
        if (Suppression::reason('phone', (string) $lead['phone_e164']) !== null) {
            return 'Ese teléfono está en la lista de supresión.';
        }
        return null;
    }

    /**
     * Vista previa: con las variables resueltas SIN efectos (el enlace de referido de la plantilla A12 se crea solo al enviar de verdad).
     * @return array{subject:string,body:string,whatsapp:string,unresolved:list<string>}
     */
    public static function preview(array $lead, array $tpl): array
    {
        $pv = $tpl;
        $pv['subject'] = str_replace('{link_referido}', '[el enlace de referido se crea al enviar]', (string) $tpl['subject']);
        $pv['body'] = str_replace('{link_referido}', '[el enlace de referido se crea al enviar]', (string) $tpl['body']);
        $lang = (string) $tpl['lang'];
        $r = Templates::render($pv, $lead, $lang, Unsub::url((int) $lead['id']));
        $wa = Templates::interpolate((string) $pv['body'], Templates::vars($lead, $lang, Unsub::url((int) $lead['id'])));
        return ['subject' => $r['subject'], 'body' => $r['text'], 'whatsapp' => trim($wa), 'unresolved' => $r['unresolved']];
    }

    /** @return array{ok:bool,msg:string} */
    public static function sendEmail(int $leadId, int $templateId, int $userId): array
    {
        $lockName = 'lead_send_' . $leadId;
        if (!Db::lock($lockName, 3)) {
            return ['ok' => false, 'msg' => 'Ya hay un envío en curso para este lead.'];
        }
        try {
            $lead = Lead::find($leadId);
            $tpl = Templates::find($templateId);
            if ($lead === null) {
                return ['ok' => false, 'msg' => 'Ese lead no existe.'];
            }
            if ($tpl === null || (int) $tpl['active'] !== 1) {
                return ['ok' => false, 'msg' => 'Esa plantilla no existe o está desactivada.'];
            }
            $why = self::emailBlock($lead) ?? self::capBlock($leadId);
            if ($why !== null) {
                return ['ok' => false, 'msg' => 'No se envió. ' . $why];
            }
            $unsub = Unsub::url($leadId);
            $r = Templates::render($tpl, $lead, (string) $tpl['lang'], $unsub);
            if ($r['unresolved']) {
                return ['ok' => false, 'msg' => 'No se envió: la plantilla tiene texto pendiente de confirmar (' . implode(' ', array_map(static fn($m) => mb_substr($m, 0, 40), array_slice($r['unresolved'], 0, 2))) . ').'];
            }
            $cfg = Sequences::mailSettings();
            try {
                $status = Mailer::deliver([
                    'to' => (string) $lead['email'], 'subject' => $r['subject'], 'text' => $r['text'], 'html' => $r['html'], 'from_name' => $cfg['from_name'],
                    'reply_to' => $cfg['reply_to'] !== '' ? $cfg['reply_to'] : Mailer::to(),
                    'headers' => ['List-Unsubscribe' => '<' . $unsub . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
                ]);
            } catch (\Throwable $t) {
                return ['ok' => false, 'msg' => 'No se envió: el servidor de correo rechazó el mensaje (' . mb_substr(Storage::redact($t->getMessage()), 0, 160) . ').'];
            }
            if ($status !== 'sent') {
                return ['ok' => false, 'msg' => 'No se envió: el correo quedó solo registrado en el servidor porque no hay SMTP disponible.'];
            }
            Db::transaction(static function () use ($leadId, $tpl, $r, $userId): void {
                Db::insert('messages', ['lead_id' => $leadId, 'channel' => 'email', 'direction' => 'out', 'body' => mb_substr($r['text'], 0, 4000), 'template_key' => (string) $tpl['tkey'],
                    'subject' => mb_substr($r['subject'], 0, 190), 'status' => 'sent', 'user_id' => $userId, 'created_at' => Clock::now()]);
                LeadActions::event($leadId, 'template_sent', ['tkey' => (string) $tpl['tkey'], 'lang' => (string) $tpl['lang'], 'channel' => 'email', 'status' => 'enviado'], $userId);
                ActivityLog::record('lead_template_sent', $userId, 'lead', $leadId, ['tkey' => (string) $tpl['tkey'], 'lang' => (string) $tpl['lang'], 'channel' => 'email']);
            });
            return ['ok' => true, 'msg' => 'Correo enviado con la plantilla «' . $tpl['name'] . '».'];
        } finally {
            Db::unlock($lockName);
        }
    }

    /** Registra que se abre WhatsApp con una plantilla y devuelve la URL de wa.me. @return array{ok:bool,msg:string,url?:string} */
    public static function whatsapp(int $leadId, int $templateId, int $userId): array
    {
        $lead = Lead::find($leadId);
        $tpl = Templates::find($templateId);
        if ($lead === null) {
            return ['ok' => false, 'msg' => 'Ese lead no existe.'];
        }
        if ($tpl === null || (int) $tpl['active'] !== 1) {
            return ['ok' => false, 'msg' => 'Esa plantilla no existe o está desactivada.'];
        }
        $why = self::whatsappBlock($lead);
        if ($why !== null) {
            return ['ok' => false, 'msg' => 'No se abrió WhatsApp. ' . $why];
        }
        $lang = (string) $tpl['lang'];
        $body = (string) $tpl['body'];
        $vars = Templates::vars($lead, $lang, Unsub::url($leadId));
        if (str_contains($body, '{link_referido}')) {
            $vars['{link_referido}'] = Referrals::linkForLead($lead);
        }
        $text = trim(Templates::interpolate($body, $vars));
        $unres = Templates::unresolved($text);
        if ($unres) {
            return ['ok' => false, 'msg' => 'No se abrió WhatsApp: la plantilla tiene texto pendiente de confirmar (' . implode(' ', array_map(static fn($m) => mb_substr($m, 0, 40), array_slice($unres, 0, 2))) . ').'];
        }
        if (mb_strlen($text) > self::WA_MAX_CHARS) {
            return ['ok' => false, 'msg' => 'El texto es demasiado largo para abrirlo en WhatsApp (máximo ' . self::WA_MAX_CHARS . ' caracteres).'];
        }
        $num = (string) Fmt::waNumber($lead['phone_e164']);
        Db::transaction(static function () use ($leadId, $tpl, $userId): void {
            LeadActions::event($leadId, 'whatsapp_opened', ['tkey' => (string) $tpl['tkey'], 'lang' => (string) $tpl['lang'], 'channel' => 'whatsapp'], $userId);
            ActivityLog::record('lead_whatsapp_opened', $userId, 'lead', $leadId, ['tkey' => (string) $tpl['tkey'], 'lang' => (string) $tpl['lang']]);
        });
        return ['ok' => true, 'msg' => 'WhatsApp abierto con la plantilla «' . $tpl['name'] . '».', 'url' => 'https://wa.me/' . $num . '?text=' . rawurlencode($text)];
    }
}
