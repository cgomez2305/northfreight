<?php
/**
 * /prospectos/correos (solo administradores, fase AQ): correos en lote a prospectos FMCSA por segmentos. Lista de campañas, borrador con vista previa y conteo
 * de destinatarios, prueba al correo del administrador, lanzar, pausar, reanudar, cancelar y ajustes de envío. Reglas y límites en Services\ProspectMail.
 * Cada acción: sesión + rol admin en el servidor + CSRF + validación + activity_log. Ningún dato personal en la URL (solo ids y el segmento).
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Clock;
use Captacion\Services\Mailer;
use Captacion\Services\ProspectMail;
use Captacion\Services\Templates;

final class ProspectMailController extends Responder
{
    private const BASE = '/prospectos/correos';

    public static function route(?string $a, ?string $b): never
    {
        $user = self::user();
        self::requireAdmin($user);
        $post = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
        if ($a === null && $b === null) {
            self::index($user);
        }
        if ($a === 'ajustes' && $b === null) {
            self::requirePost(self::BASE);
            $r = ProspectMail::saveSettings($_POST, (int) $user['id']);
            self::flash($r['ok'], $r['msg']);
            self::redirect(self::BASE);
        }
        if ($a === 'nueva' && $b === null) {
            $post ? self::save($user, null) : self::form($user, null);
        }
        if ($a !== null && ctype_digit($a)) {
            $id = (int) $a;
            if ($b === null) {
                self::show($user, $id);
            }
            if ($b === 'guardar') {
                self::save($user, $id);
            }
            if (in_array($b, ['prueba', 'lanzar', 'pausar', 'reanudar', 'cancelar', 'borrar'], true)) {
                self::act($user, $id, (string) $b);
            }
        }
        self::notFound();
    }

    private static function index(array $user): never
    {
        self::page('pmail/list', $user, 'pmail', [['Prospectos', '/prospectos'], ['Correos en lote', null]], [
            'title' => 'Correos a prospectos', 'css' => ['comms', 'pmail'], 'campaigns' => ProspectMail::list(), 'settings' => ProspectMail::settings(), 'blockers' => ProspectMail::blockers(),
            'inbox' => \Captacion\Services\ProspectReplies::state(), 'inboxReady' => \Captacion\Services\ProspectReplies::configured(),
        ]);
    }

    /** Formulario de borrador. $form/$errors vienen de un envío con errores o del botón «Contar destinatarios» (sin guardar). */
    private static function form(array $user, ?array $c, ?array $form = null, array $errors = [], int $status = 200): never
    {
        $now = Clock::time();
        if ($form === null) {
            if ($c !== null) {
                $form = ['name' => $c['name'], 'subject' => $c['subject'], 'body' => $c['body'], 'segment' => $c['segment'], 'lang' => ($c['lang'] ?? 'en') === 'es' ? 'es' : 'en', 'followup_days' => (int) ($c['followup_days'] ?? 0),
                    'followup_subject' => (string) ($c['followup_subject'] ?? ''), 'followup_body' => (string) ($c['followup_body'] ?? '')];
            } else {
                $st = is_string($_GET['inicio'] ?? null) && isset(ProspectMail::STARTERS[$_GET['inicio']]) ? ProspectMail::STARTERS[$_GET['inicio']] : null;
                $form = ['name' => $st !== null ? $st['label'] : '', 'subject' => $st['subject'] ?? '', 'body' => $st['body'] ?? '', 'segment' => ProspectMail::segment($st['segment'] ?? $_GET), 'lang' => ($st['lang'] ?? 'en') === 'es' ? 'es' : 'en', 'followup_days' => 0, 'followup_subject' => '', 'followup_body' => ''];
            }
        }
        $aud = ProspectMail::audience($form['segment'], $now);
        $sampleP = ProspectMail::sample($form['segment'], $now);
        $preview = ProspectMail::render(['subject' => $form['subject'], 'body' => $form['body'], 'lang' => $form['lang'] ?? 'en'], $sampleP, '(enlace de baja de cada prospecto)');
        $crumb = $c !== null ? $c['name'] : 'Nueva campaña';
        self::page('pmail/form', $user, 'pmail', [['Prospectos', '/prospectos'], ['Correos en lote', self::BASE], [$crumb, null]], [
            'title' => $crumb, 'css' => ['comms', 'pmail'], 'js' => ['pmail'], 'c' => $c, 'form' => $form, 'errors' => $errors, 'aud' => $aud, 'preview' => $preview,
            'sampleName' => (string) ($sampleP['legal_name'] ?? ''), 'blockers' => $c !== null ? ProspectMail::launchBlockers($c, $aud) : ProspectMail::blockers(),
            'userEmail' => (string) $user['email'], 'settings' => ProspectMail::settings(), 'hasAddress' => Templates::address() !== '', 'canDeliver' => Mailer::canDeliver(),
        ], $status);
    }

    private static function save(array $user, ?int $id): never
    {
        $back = $id === null ? self::BASE . '/nueva' : self::BASE . '/' . $id;
        self::requirePost($back);
        $c = $id !== null ? ProspectMail::find($id) : null;
        if ($id !== null && $c === null) {
            self::notFound();
        }
        $v = ProspectMail::validate($_POST);
        $form = $v['values'];
        if (($_POST['do'] ?? '') === 'count') {   // «Contar destinatarios»: recalcula con el segmento elegido sin guardar
            self::form($user, $c, $form);
        }
        if (!$v['ok']) {
            self::form($user, $c, $form, $v['errors'], 422);
        }
        if ($id === null) {
            $nid = ProspectMail::create($form, (int) $user['id']);
            self::flash(true, 'Borrador creado. Envíate una prueba antes de lanzarlo.');
            self::redirect(self::BASE . '/' . $nid);
        }
        $r = ProspectMail::update($id, $form, (int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect($back);
    }

    private static function show(array $user, int $id): never
    {
        $c = ProspectMail::find($id);
        if ($c === null) {
            self::notFound();
        }
        if ($c['status'] === 'draft') {
            self::form($user, $c);
        }
        $st = is_string($_GET['ver'] ?? null) ? $_GET['ver'] : '';
        $st = isset(ProspectMail::R_STATUS[$st]) || in_array($st, ['opened', 'clicked', 'replied'], true) ? $st : '';
        self::page('pmail/show', $user, 'pmail', [['Prospectos', '/prospectos'], ['Correos en lote', self::BASE], [$c['name'], null]], [
            'title' => $c['name'], 'css' => ['comms', 'pmail'], 'c' => $c, 'stats' => ProspectMail::stats($id), 'ver' => $st,
            'list' => ProspectMail::recipients($id, $st, max(1, (int) ($_GET['page'] ?? 1))), 'settings' => ProspectMail::settings(),
        ]);
    }

    private static function act(array $user, int $id, string $action): never
    {
        $back = self::BASE . '/' . $id;
        self::requirePost($back);
        $uid = (int) $user['id'];
        $r = match ($action) {
            'prueba'   => ProspectMail::sendTest($id, (string) $user['email'], $uid),
            'lanzar'   => ProspectMail::launch($id, $uid),
            'pausar'   => ProspectMail::pause($id, $uid),
            'reanudar' => ProspectMail::resume($id, $uid),
            'cancelar' => ProspectMail::cancel($id, $uid),
            'borrar'   => ProspectMail::delete($id, $uid),
        };
        self::flash($r['ok'], $r['msg']);
        self::redirect($action === 'borrar' && $r['ok'] ? self::BASE : $back);
    }
}
