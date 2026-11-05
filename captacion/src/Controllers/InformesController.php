<?php
/**
 * /informes: informes mensuales (vista web de solo lectura, imprimible a PDF desde el navegador). El lector de NorthStar ve SOLO los publicados y SOLO agregados; el administrador ve
 * ademas los borradores, los resumenes semanales guardados y puede generar un mes pasado, editar las tres acciones, publicar y reenviar el enlace.
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Db;
use Captacion\Services\ActivityLog;
use Captacion\Services\ReportJobs;
use Captacion\Services\Reports;

final class InformesController extends Responder
{
    public static function route(?int $id, ?string $action): never
    {
        $user = self::user(true);
        $admin = ($user['role'] ?? '') === 'admin';
        if ($id === null && $action === null) {
            self::listing($user, $admin);
        }
        if ($id === null && $action === 'generate') {
            self::generate($user, $admin);
        }
        if ($id === null) {
            self::notFound();
        }
        if ($action === null) {
            self::show($user, $admin, $id);
        }
        if (!$admin) {
            self::notFound();
        }
        match ($action) {
            'actions' => self::actions($user, $id),
            'publish' => self::publish($user, $id),
            'resend'  => self::resend($user, $id),
            default   => self::notFound(),
        };
    }

    private static function listing(array $user, bool $admin): never
    {
        $monthly = Db::all("SELECT id, period, ident, status, published_at, sent_at, notified_at, created_at FROM reports WHERE kind = 'monthly'" . ($admin ? '' : " AND status = 'published'") . ' ORDER BY period DESC LIMIT 60');
        $weekly = $admin ? Db::all("SELECT id, period, ident, notified_at, created_at FROM reports WHERE kind = 'weekly' ORDER BY period DESC LIMIT 12") : [];
        self::page('informes', $user, 'informes', [['Informes', null]], [
            'title' => 'Informes', 'css' => ['reportes'], 'monthly' => $monthly, 'weekly' => $weekly, 'isAdminView' => $admin,
            'genDefault' => ReportJobs::previousMonth(\Captacion\Clock::time()),
        ]);
    }

    private static function show(array $user, bool $admin, int $id): never
    {
        $r = Db::one("SELECT * FROM reports WHERE id = ? AND kind = 'monthly'" . ($admin ? '' : " AND status = 'published'"), [$id]);
        if ($r === null) {
            self::notFound();   // para el lector un borrador no existe
        }
        $metrics = json_decode((string) $r['metrics'], true);
        if (!is_array($metrics)) {
            self::notFound();
        }
        $cur = $admin ? $metrics['cur'] : Reports::forViewer($metrics['cur']);
        $prev = $admin ? $metrics['prev'] : Reports::forViewer($metrics['prev']);
        self::page('informe', $user, 'informes', [['Informes', '/informes'], [$metrics['label'] ?? (string) $r['period'], null]], [
            'title' => 'Informe de ' . ($metrics['label'] ?? $r['period']), 'css' => ['charts', 'reportes'], 'report' => $r, 'meta' => $metrics, 'm' => $cur, 'p' => $prev,
            'actions' => json_decode((string) $r['actions'], true) ?: [], 'isAdminView' => $admin, 'recipients' => \Captacion\Services\Sequences::mailSettings()['monthly_to'],
        ]);
    }

    private static function generate(array $user, bool $admin): never
    {
        if (!$admin) {
            self::notFound();
        }
        self::requirePost('/informes');
        $period = is_string($_POST['period'] ?? null) ? trim($_POST['period']) : '';
        $g = ReportJobs::generateMonthly($period, (int) $user['id']);
        self::flash($g['ok'], $g['msg']);
        self::redirect($g['id'] !== null ? '/informes/' . $g['id'] : '/informes');
    }

    private static function actions(array $user, int $id): never
    {
        self::requirePost('/informes/' . $id);
        $in = is_array($_POST['action'] ?? null) ? array_slice($_POST['action'], 0, 3) : [];
        $r = ReportJobs::saveActions($id, $in, (int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect('/informes/' . $id . '#acciones');
    }

    private static function publish(array $user, int $id): never
    {
        self::requirePost('/informes/' . $id);
        $r = ReportJobs::publish($id, (int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect('/informes/' . $id);
    }

    private static function resend(array $user, int $id): never
    {
        self::requirePost('/informes/' . $id);
        $r = ReportJobs::sendPublished($id, (int) $user['id'], true);
        self::flash($r['ok'], $r['msg']);
        self::redirect('/informes/' . $id);
    }
}
