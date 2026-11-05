<?php
/**
 * /prospectos (solo administradores): lista de llamadas de hoy con autoridades nuevas de FMCSA, resultado con un clic, conversion a lead, reabrir y exportar CSV.
 * Las llamadas son manuales. Cada accion: sesion + rol admin en el servidor + CSRF + validacion + activity_log. Ningun dato personal viaja en la URL (solo ids y filtros).
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Clock;
use Captacion\Services\ActivityLog;
use Captacion\Services\Csv;
use Captacion\Services\ProspectConfig;
use Captacion\Services\ProspectImport;
use Captacion\Services\Prospects;
use Captacion\Services\Settings;

final class ProspectosController extends Responder
{
    public static function route(?string $a, ?string $b): never
    {
        $user = self::user();
        self::requireAdmin($user);
        if ($a === null) {
            self::index($user);
        }
        if ($a === 'export' && $b === null) {
            self::export($user);
        }
        if (ctype_digit($a) && in_array($b, ['result', 'convert', 'reopen'], true)) {
            self::act($user, (int) $a, (string) $b);
        }
        self::notFound();
    }

    private static function index(array $user): never
    {
        $now = Clock::time();
        $f = Prospects::filters($_GET);
        $per = (int) ($_GET['per'] ?? 25);
        $per = in_array($per, Prospects::PER, true) ? $per : 25;
        $page = Prospects::page($f, $now, max(1, (int) ($_GET['page'] ?? 1)), $per);
        $cfg = ProspectConfig::get();
        $run = ProspectImport::state();
        $cron = Settings::get('cron.prospects');
        self::page('prospectos', $user, 'prospectos', [['Prospectos', null]], [
            'title' => 'Prospectos', 'css' => ['prospectos'], 'js' => ['prospectos'], 'f' => $f, 'per' => $per, 'list' => $page, 'counts' => Prospects::counts($f, $now),
            'history' => Prospects::historyFor(array_column($page['rows'], 'id')), 'now' => $now, 'summary' => Prospects::summary($now), 'cfg' => $cfg, 'run' => $run,
            'cron' => is_array($cron) ? $cron : null, 'totalAll' => (int) \Captacion\Db::value('SELECT COUNT(*) FROM prospects_fmcsa WHERE deleted_at IS NULL'),
            'back' => '/prospectos' . self::qs($f, $per, $page['page']),
        ]);
    }

    /** Query string de los filtros vigentes (sin valores vacios ni por defecto). */
    public static function qs(array $f, int $per = 25, int $page = 1): string
    {
        $q = array_filter([
            'tab' => $f['tab'] !== 'today' ? $f['tab'] : null, 'q' => $f['q'] ?: null, 'us' => $f['us'] ?: null, 'fl' => $f['fl'] ?: null, 'ag' => $f['ag'] ?: null, 'ph' => $f['ph'] ?: null,
            'per' => $per !== 25 ? $per : null, 'page' => $page > 1 ? $page : null,
        ], static fn($v) => $v !== null);
        return $q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '';
    }

    /** Vuelta a la lista con los mismos filtros (solo rutas internas /prospectos...). */
    private static function backUrl(): string
    {
        $b = is_string($_POST['back'] ?? null) ? $_POST['back'] : '/prospectos';
        return preg_match('#^/prospectos(\?[A-Za-z0-9_%=&.\-+~]{0,200})?$#', $b) === 1 ? $b : '/prospectos';
    }

    private static function act(array $user, int $id, string $action): never
    {
        $back = self::backUrl();
        self::requirePost($back);
        $uid = (int) $user['id'];
        $r = match ($action) {
            'result'  => Prospects::record($id, is_string($_POST['result'] ?? null) ? $_POST['result'] : '', $_POST['note'] ?? '', $_POST['next'] ?? '', $uid),
            'convert' => Prospects::convert($id, $uid),
            'reopen'  => Prospects::reopen($id, $uid),
        };
        self::flash($r['ok'], $r['msg']);
        self::redirect($back);
    }

    private static function export(array $user): never
    {
        self::requirePost('/prospectos');
        parse_str(is_string($_POST['qs'] ?? null) ? mb_substr($_POST['qs'], 0, 500) : '', $q);
        $f = Prospects::filters(is_array($q) ? $q : []);
        $now = Clock::time();
        $rows = Prospects::exportRows($f, $now);
        ActivityLog::record('prospects_export', (int) $user['id'], 'prospect', null, ['rows' => count($rows), 'tab' => $f['tab']]);
        self::download('prospectos-' . gmdate('Ymd-His', $now) . '.csv', 'text/csv; charset=utf-8', Csv::prospects($rows, $now));
    }
}
