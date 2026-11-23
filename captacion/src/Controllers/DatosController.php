<?php
/**
 * Fase AM · datos. Acciones de la ficha del lead solo para administradores:
 *   POST /leads/{id}/anonimizar   anonimizacion a peticion (irreversible; reautenticacion 2FA reciente + escribir «ANONIMIZAR»)
 *   POST /leads/{id}/fusionar     fusion manual de duplicados (elige el principal; irreversible)
 *   GET  /leads/{id}/enviar       vista previa de una plantilla para este lead (?tpl=ID)
 *   POST /leads/{id}/enviar       envia el correo (channel=email) o abre WhatsApp con el texto resuelto (channel=whatsapp)
 * Siempre con sesion, CSRF, rol administrador y registro en activity_log (lo hacen los servicios).
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Models\Lead;
use Captacion\Services\Fmt;
use Captacion\Services\LeadErasure;
use Captacion\Services\LeadMerge;
use Captacion\Services\LeadSend;
use Captacion\Services\Reauth;
use Captacion\Services\Templates;

final class DatosController extends Responder
{
    public static function handle(int $id, string $action): never
    {
        $user = self::user();
        self::requireAdmin($user);
        match ($action) {
            'anonimizar' => self::anonymize($user, $id),
            'fusionar' => self::merge($user, $id),
            default => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' ? self::sendPost($user, $id) : self::sendPage($user, $id),
        };
    }

    private static function anonymize(array $user, int $id): never
    {
        $back = '/leads/' . $id . '#privacidad';
        self::requirePost($back);
        $lead = Lead::find($id);
        if ($lead === null) {
            self::notFound();
        }
        if (!LeadErasure::confirmOk($_POST['confirm'] ?? null)) {
            self::flash(false, 'Para anonimizar escribe ' . LeadErasure::CONFIRM_WORD . ' en el campo de confirmación.');
            self::redirect($back);
        }
        if (!Reauth::fresh()) {
            $r = Reauth::attempt($user, is_string($_POST['password'] ?? null) ? $_POST['password'] : '', is_string($_POST['code'] ?? null) ? $_POST['code'] : '', self::ip());
            if ($r['status'] === 'blocked') {
                self::flash(false, 'Demasiados intentos fallidos. Espera 15 minutos.');
                self::redirect($back);
            }
            if ($r['status'] !== 'ok') {
                self::flash(false, 'La contraseña o el código no son correctos. Usa un código nuevo de tu app (no el que usaste para entrar).');
                self::redirect($back);
            }
        }
        $r = LeadErasure::anonymize($id, (int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect('/leads/' . $id . ($r['ok'] ? '' : '#privacidad'));
    }

    private static function merge(array $user, int $id): never
    {
        $back = '/leads/' . $id . '#fusion';
        self::requirePost($back);
        $other = is_string($_POST['other'] ?? null) && ctype_digit($_POST['other']) && strlen($_POST['other']) < 12 ? (int) $_POST['other'] : 0;
        $primary = is_string($_POST['primary'] ?? null) && ctype_digit($_POST['primary']) && strlen($_POST['primary']) < 12 ? (int) $_POST['primary'] : 0;
        if (Lead::find($id) === null) {
            self::notFound();
        }
        if ($other <= 0 || !in_array($primary, [$id, $other], true)) {
            self::flash(false, 'Elige cuál de los dos leads será el principal.');
            self::redirect($back);
        }
        if (($_POST['confirm'] ?? '') !== '1') {
            self::flash(false, 'Marca la casilla para confirmar la fusión: no se puede deshacer.');
            self::redirect($back);
        }
        $r = LeadMerge::merge($primary, $primary === $id ? $other : $id, (int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect($r['ok'] ? '/leads/' . (int) $r['primary'] . '#linea' : $back);
    }

    private static function tplId(): int
    {
        $v = $_POST['tpl'] ?? ($_GET['tpl'] ?? '');
        return is_string($v) && ctype_digit($v) && strlen($v) < 12 ? (int) $v : 0;
    }

    private static function sendPage(array $user, int $id): never
    {
        $lead = Lead::find($id);
        if ($lead === null) {
            self::notFound();
        }
        $tpl = self::tplId() > 0 ? Templates::find(self::tplId()) : null;
        if ($tpl !== null && (int) $tpl['active'] !== 1) {
            $tpl = null;
        }
        $name = Fmt::name($lead);
        self::page('lead_send', $user, 'leads', [['Leads', '/leads'], [$name, '/leads/' . $id], ['Enviar plantilla', null]], [
            'title' => 'Enviar plantilla · ' . $name, 'css' => ['datos'], 'hideTabbar' => true, 'lead' => $lead, 'templates' => LeadSend::templates($lead), 'tpl' => $tpl,
            'preview' => $tpl !== null ? LeadSend::preview($lead, $tpl) : null,
            'emailBlock' => LeadSend::emailBlock($lead), 'capBlock' => LeadSend::capBlock($id), 'waBlock' => LeadSend::whatsappBlock($lead),
        ]);
    }

    private static function sendPost(array $user, int $id): never
    {
        $tpl = self::tplId();
        $back = '/leads/' . $id . '/enviar' . ($tpl > 0 ? '?tpl=' . $tpl : '');
        self::requirePost($back);
        if (Lead::find($id) === null) {
            self::notFound();
        }
        if ($tpl <= 0) {
            self::flash(false, 'Elige una plantilla.');
            self::redirect($back);
        }
        $channel = $_POST['channel'] ?? '';
        if ($channel === 'whatsapp') {
            $r = LeadSend::whatsapp($id, $tpl, (int) $user['id']);
            if ($r['ok'] && isset($r['url'])) {
                // Ya quedo anotado. NO se redirige a wa.me: la CSP del panel (form-action 'self') bloquea las redirecciones de un formulario a otro origen. El formulario abre una
                // pestaña nueva y esa pestaña recibe esta pagina, que salta a WhatsApp con un refresco de cabecera (y deja el enlace por si no salta).
                self::html('message', ['title' => 'Abriendo WhatsApp', 'heading' => 'Abriendo WhatsApp…', 'text' => 'Si no se abre solo, usa el botón. Puedes cerrar esta pestaña cuando termines.', 'linkHref' => $r['url'], 'linkText' => 'Abrir WhatsApp', 'refresh' => $r['url']]);
            }
            self::flash(false, $r['msg']);
            self::redirect($back);
        }
        if ($channel !== 'email') {
            self::flash(false, 'Elige el canal.');
            self::redirect($back);
        }
        $r = LeadSend::sendEmail($id, $tpl, (int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect($r['ok'] ? '/leads/' . $id . '#linea' : $back);
    }
}
