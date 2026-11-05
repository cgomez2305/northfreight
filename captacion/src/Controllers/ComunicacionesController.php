<?php
/**
 * /comunicaciones (solo administradores): plantillas de correo EN/ES (CRUD, vista previa y envio de prueba), inscripciones a secuencias (quien esta en que paso; pausar, reanudar,
 * sacar) y registro de los correos enviados. Las secuencias corren en la cola (job sequence_step); las reglas de envio estan en Services\Sequences.
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Db;
use Captacion\Services\ActivityLog;
use Captacion\Services\Mailer;
use Captacion\Services\RateLimiter;
use Captacion\Services\Sequences;
use Captacion\Services\Templates;
use Captacion\Services\Url;

final class ComunicacionesController extends Responder
{
    public const TABS = ['plantillas' => 'Plantillas', 'inscripciones' => 'Inscripciones', 'envios' => 'Correos enviados'];

    public static function route(?string $tab, ?string $id, ?string $action): never
    {
        $user = self::user();
        self::requireAdmin($user);
        $tab ??= 'plantillas';
        if (!isset(self::TABS[$tab])) {
            self::notFound();
        }
        if ($tab === 'plantillas') {
            if ($id === null) {
                $action === null ? self::templates($user) : self::notFound();
            }
            if ($id === 'new') {
                if ($action !== null) {
                    self::notFound();
                }
                strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' ? self::create($user) : self::editor($user, null);
            }
            if ($action === null) {
                self::editor($user, (int) $id);
            }
            self::templateAction($user, (int) $id, $action);
        }
        if ($tab === 'inscripciones') {
            if ($id === null && $action === null) {
                self::enrollments($user);
            }
            if ($id !== null && $action !== null && ctype_digit($id)) {
                self::enrollmentAction($user, (int) $id, $action);
            }
            self::notFound();
        }
        if ($id !== null || $action !== null) {
            self::notFound();
        }
        self::sent($user);
    }

    private static function shell(array $user, string $tab, array $vars, int $status = 200): never
    {
        self::page('comunicaciones', $user, 'comunicaciones', [['Comunicaciones', '/comunicaciones'], [self::TABS[$tab], null]], $vars + [
            'title' => 'Comunicaciones', 'css' => ['comms'], 'tab' => $tab, 'tabs' => self::TABS, 'warnings' => Sequences::warnings(), 'canDeliver' => Mailer::canDeliver(),
        ], $status);
    }

    // --- Plantillas ---------------------------------------------------------------------------------------------------------------------

    private static function templates(array $user): never
    {
        $rows = Templates::all();
        foreach ($rows as &$r) {
            $r['pending'] = Templates::render($r, Templates::sampleLead((string) $r['lang']), (string) $r['lang'], Url::base() . '/u/EJEMPLO')['unresolved'];
        }
        unset($r);
        self::shell($user, 'plantillas', ['view' => 'list', 'templates' => $rows]);
    }

    private static function editor(array $user, ?int $id, array $over = [], int $status = 200): never
    {
        $t = $id !== null ? Templates::find($id) : null;
        if ($id !== null && $t === null) {
            self::notFound();
        }
        $form = $over['form'] ?? ($t ?? ['id' => 0, 'tkey' => '', 'lang' => 'en', 'name' => '', 'subject' => '', 'body' => '', 'kind' => 'custom', 'active' => 1]);
        $lang = in_array($form['lang'] ?? '', ['en', 'es'], true) ? (string) $form['lang'] : 'en';
        $prev = Templates::render(['subject' => (string) ($form['subject'] ?? ''), 'body' => (string) ($form['body'] ?? '')], Templates::sampleLead($lang), $lang, Url::base() . '/u/EJEMPLO');
        self::shell($user, 'plantillas', ['view' => 'edit', 'tpl' => $t, 'form' => $form, 'errors' => $over['errors'] ?? [], 'preview' => $prev, 'creating' => $t === null, 'userEmail' => (string) $user['email']], $status);
    }

    private static function templateAction(array $user, int $id, string $action): never
    {
        $back = '/comunicaciones/plantillas/' . $id;
        $t = Templates::find($id);
        if ($t === null) {
            self::notFound();
        }
        self::requirePost($back);
        $uid = (int) $user['id'];
        switch ($action) {
            case 'save':
                $v = Templates::validate($_POST + ['lang' => $t['lang']], false);
                if (!$v['ok']) {
                    self::flash(false, 'Revisa los campos marcados. No se guardó nada.');
                    self::editor($user, $id, ['form' => ['id' => $id, 'tkey' => $t['tkey'], 'kind' => $t['kind']] + $v['values'] + ['lang' => $t['lang']], 'errors' => $v['errors']], 422);
                }
                Templates::update($id, $v['values'], $uid);
                self::flash(true, 'Plantilla guardada.');
                self::redirect($back);
                // no break
            case 'delete':
                $r = Templates::delete($id, $uid);
                self::flash($r['ok'], $r['msg']);
                self::redirect($r['ok'] ? '/comunicaciones' : $back);
                // no break
            case 'restore':
                $r = Templates::restore($id, $uid);
                self::flash($r['ok'], $r['msg']);
                self::redirect($back);
                // no break
            case 'test':
                self::testSend($user, $t, $back);
            default:
                self::notFound();
        }
    }

    public static function create(array $user): never
    {
        self::requirePost('/comunicaciones/plantillas/new');
        $v = Templates::validate($_POST, true);
        if (!$v['ok']) {
            self::flash(false, 'Revisa los campos marcados. No se creó nada.');
            self::editor($user, null, ['form' => ['id' => 0, 'kind' => 'custom'] + $v['values'], 'errors' => $v['errors']], 422);
        }
        $id = Templates::create($v['values'], (int) $user['id']);
        self::flash(true, 'Plantilla creada.');
        self::redirect('/comunicaciones/plantillas/' . $id);
    }

    private static function testSend(array $user, array $t, string $back): never
    {
        $key = 'u' . (int) $user['id'];
        if (RateLimiter::tooMany('tpl_test', $key, 6, 60)) {
            self::flash(false, 'Demasiadas pruebas seguidas. Espera un minuto.');
            self::redirect($back);
        }
        RateLimiter::hit('tpl_test', $key, 60);
        $to = is_string($_POST['to'] ?? null) ? trim($_POST['to']) : '';
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            self::flash(false, 'Escribe un correo válido para la prueba.');
            self::redirect($back);
        }
        $lang = (string) $t['lang'];
        $r = Templates::render($t, Templates::sampleLead($lang), $lang, Url::base() . '/u/PRUEBA');
        try {
            $res = Mailer::deliver(['to' => $to, 'subject' => '[PRUEBA] ' . $r['subject'], 'text' => "*** PRUEBA: no es un envío real ***\n\n" . $r['text'], 'html' => $r['html'], 'from_name' => 'NorthStar captacion']);
        } catch (\Throwable $e) {
            self::flash(false, 'El SMTP rechazó la prueba: ' . mb_substr(\Captacion\Services\Storage::redact($e->getMessage()), 0, 160));
            self::redirect($back);
        }
        ActivityLog::record('template_test', (int) $user['id'], 'template', (int) $t['id'], ['logged' => $res !== 'sent']);
        self::flash(true, $res === 'sent' ? 'Prueba enviada a ' . $to . '.' . ($r['unresolved'] ? ' Ojo: la plantilla tiene texto pendiente de confirmar y NO se enviaría a un lead.' : '') : 'Sin SMTP: la prueba quedó registrada en storage/mail.log (no se envió a nadie).');
        self::redirect($back);
    }

    // --- Inscripciones ------------------------------------------------------------------------------------------------------------------

    private static function enrollments(array $user): never
    {
        $st = is_string($_GET['st'] ?? null) ? $_GET['st'] : '';
        $sq = is_string($_GET['sq'] ?? null) ? $_GET['sq'] : '';
        $counts = [];
        foreach (Db::all('SELECT sequence_key, status, COUNT(*) AS n FROM sequence_enrollments GROUP BY sequence_key, status') as $r) {
            $counts[$r['sequence_key']][$r['status']] = (int) $r['n'];
        }
        self::shell($user, 'inscripciones', ['view' => 'enroll', 'rows' => Sequences::listEnrollments($st, $sq), 'counts' => $counts, 'st' => $st, 'sq' => $sq,
            'caps' => Sequences::mailSettings(), 'sentHour' => (int) Db::value("SELECT COUNT(*) FROM messages WHERE channel = 'email' AND direction = 'out' AND sequence_key IS NOT NULL AND status IN ('sent','logged') AND created_at > ?", [\Captacion\Clock::shift(-3600)])]);
    }

    private static function enrollmentAction(array $user, int $id, string $action): never
    {
        if (!in_array($action, ['pause', 'resume', 'remove'], true)) {
            self::notFound();
        }
        self::requirePost('/comunicaciones/inscripciones');
        $r = Sequences::manage($id, $action, (int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect('/comunicaciones/inscripciones');
    }

    // --- Correos enviados ---------------------------------------------------------------------------------------------------------------

    private static function sent(array $user): never
    {
        $rows = Db::all(
            "SELECT m.id, m.lead_id, m.template_key, m.subject, m.sequence_key, m.status, m.created_at, l.first_name, l.last_name FROM messages m JOIN leads l ON l.id = m.lead_id
              WHERE m.channel = 'email' AND m.direction = 'out' ORDER BY m.id DESC LIMIT 100"
        );
        self::shell($user, 'envios', ['view' => 'sent', 'rows' => $rows]);
    }
}
