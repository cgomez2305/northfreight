<?php
/**
 * /config (solo administradores): Puntuacion, Alertas, Costos por canal, Integraciones, Horario y zona y Copias de seguridad.
 * Cada accion: sesion + rol admin + CSRF + validacion + activity_log. Los cambios que modifican el comportamiento (pesos, alertas, costos, horario, copias) exigen
 * una confirmacion reciente de contraseña + 2FA (Services\Reauth); sin ella los formularios se ven bloqueados y el servidor rechaza el POST.
 */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Services\ActivityLog;
use Captacion\Services\Alerts;
use Captacion\Services\Backup;
use Captacion\Services\Costs;
use Captacion\Services\Display;
use Captacion\Services\Integrations;
use Captacion\Services\RateLimiter;
use Captacion\Services\Reauth;
use Captacion\Services\Scoring;
use Captacion\Services\ScoringConfig;
use Captacion\Services\Settings;

final class ConfigController extends Responder
{
    public const TABS = [
        'puntuacion' => 'Puntuación', 'alertas' => 'Alertas', 'costos' => 'Costos por canal', 'integraciones' => 'Integraciones', 'horario' => 'Horario y zona', 'copias' => 'Copias de seguridad',
    ];
    /** Pestañas cuyos formularios exigen confirmacion reciente. */
    public const LOCKED_TABS = ['puntuacion', 'alertas', 'costos', 'horario', 'copias'];
    public const TEST_LIMIT = 6;   // pruebas de integracion por minuto y usuario

    public static function route(?string $a, ?string $b): never
    {
        $user = self::user();
        self::requireAdmin($user);
        if ($a === null) {
            self::index($user);
        }
        $key = $a . ($b !== null ? '/' . $b : '');
        match ($key) {
            'unlock'              => self::unlock($user),
            'lock'                => self::lock($user),
            'scoring'             => self::scoringSave($user, false),
            'scoring/preview'     => self::scoringSave($user, true),
            'scoring/reset'       => self::scoringReset($user),
            'scoring/recalc'      => self::scoringRecalc($user),
            'provisional'         => self::provisionalSave($user),
            'alerts'              => self::alertsSave($user),
            'costs/save'          => self::costSave($user),
            'costs/delete'        => self::costDelete($user),
            'display'             => self::displaySave($user),
            'integrations/test'   => self::integrationTest($user),
            'backups/run'         => self::backupRun($user),
            'backups/download'    => self::backupDownload($user),
            default               => self::notFound(),
        };
        self::notFound();
    }

    private static function tabOf(mixed $v): string
    {
        return is_string($v) && isset(self::TABS[$v]) ? $v : 'puntuacion';
    }

    private static function back(string $tab, string $anchor = ''): string
    {
        return '/config' . ($tab === 'puntuacion' ? '' : '?tab=' . $tab) . $anchor;
    }

    /** POST con CSRF y, si $sensitive, con confirmacion reciente. Devuelve la URL de vuelta. */
    private static function guardPost(string $tab, bool $sensitive = true, string $anchor = ''): string
    {
        $back = self::back($tab, $anchor);
        self::requirePost($back);
        if ($sensitive && !Reauth::fresh()) {
            self::flash(false, 'Confirma tu identidad (contraseña y código) para guardar cambios.');
            self::redirect($back);
        }
        return $back;
    }

    // --- Pagina ---------------------------------------------------------------------------------------------------------

    /** @param array $over variables que sustituyen a las de la pestaña (formularios con errores, vista previa…) */
    private static function index(array $user, array $over = [], int $status = 200, ?string $forceTab = null): never
    {
        $tab = $forceTab ?? self::tabOf($_GET['tab'] ?? null);
        $now = Clock::time();
        $vars = [
            'title' => 'Configuración', 'css' => ['config', 'cfg-' . $tab], 'js' => ['config'], 'tab' => $tab, 'tabs' => self::TABS, 'locked' => in_array($tab, self::LOCKED_TABS, true) && !Reauth::fresh(),
            'needsUnlock' => in_array($tab, self::LOCKED_TABS, true), 'reauthLeft' => Reauth::remaining(), 'reauthTtl' => Reauth::ttl(), 'errors' => [], 'now' => $now,
        ];
        $vars += match ($tab) {
            'puntuacion'    => [
                'form' => ScoringConfig::current(), 'preview' => null, 'recalc' => ScoringConfig::state(), 'recalcRunning' => ScoringConfig::running(), 'prov' => Scoring::settings(),
                'provPending' => ScoringConfig::provisionalList(), 'history' => ScoringConfig::history(), 'defaults' => Scoring::DEFAULTS, 'provErrors' => [], 'provForm' => null,
                'pending' => (int) Db::value('SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL') <= 20000 ? ScoringConfig::preview(Scoring::settings()) : null,
            ],
            'alertas'       => ['alerts' => Alerts::get(), 'warmOn' => Alerts::warmTelegram(), 'fmcsaKey' => Alerts::fmcsaKey(), 'tgOk' => \Captacion\Services\Telegram::configured(), 'envMail' => (string) \Captacion\Env::get('MAIL_TO', '')],
            'costos'        => self::costsVars(),
            'integraciones' => ['cards' => Integrations::status(), 'testResult' => self::takeTestResult(), 'testUsdot' => Integrations::TEST_USDOT],
            'horario'       => ['zones' => Display::ZONES, 'tz' => Display::timezone(), 'hours' => Display::hours()],
            'copias'        => Backup::pageVars(),
        };
        self::page('config', $user, 'config', [['Configuración', '/config'], [self::TABS[$tab], null]], $over + $vars, $status);
    }

    private static function costsVars(): array
    {
        $edit = null;
        if (isset($_GET['edit']) && ctype_digit((string) $_GET['edit'])) {
            $edit = Db::one('SELECT id, source, period, amount FROM channel_costs WHERE id = ?', [(int) $_GET['edit']]);
        }
        $nowEt = \Captacion\Services\Fmt::et(Clock::time());
        return ['costTable' => Costs::table(), 'sources' => Costs::sources(), 'costForm' => $edit !== null ? ['source' => $edit['source'], 'period' => $edit['period'], 'amount' => number_format((float) $edit['amount'], 2, '.', ''), 'id' => (int) $edit['id']] : ['source' => '', 'period' => $nowEt->format('Y-m'), 'amount' => '', 'id' => 0],
            'maxPeriod' => $nowEt->modify('+1 month')->format('Y-m'), 'month' => Costs::currentMonth(Clock::time())];
    }

    private static function takeTestResult(): ?array
    {
        $r = $_SESSION['test_result'] ?? null;
        unset($_SESSION['test_result']);
        return is_array($r) ? $r : null;
    }

    // --- Confirmacion de identidad ----------------------------------------------------------------------------------------

    private static function unlock(array $user): never
    {
        $tab = self::tabOf($_POST['tab'] ?? null);
        $back = ($_POST['tab'] ?? '') === 'actividad' ? '/actividad#retencion' : self::back($tab);
        self::requirePost($back);
        $r = Reauth::attempt($user, is_string($_POST['password'] ?? null) ? $_POST['password'] : '', is_string($_POST['code'] ?? null) ? $_POST['code'] : '', self::ip());
        if ($r['status'] === 'blocked') {
            self::flash(false, 'Demasiados intentos fallidos. Espera 15 minutos.');
        } elseif ($r['status'] === 'invalid') {
            self::flash(false, 'La contraseña o el código no son correctos. Usa un código nuevo de tu app (no el que usaste para entrar).');
        } else {
            self::flash(true, 'Identidad confirmada: puedes editar durante ' . intdiv(Reauth::ttl(), 60) . ' minutos.');
        }
        self::redirect($back);
    }

    private static function lock(array $user): never
    {
        $back = self::back(self::tabOf($_POST['tab'] ?? null));
        self::requirePost($back);
        Reauth::forget();
        self::flash(true, 'Edición bloqueada.');
        self::redirect($back);
    }

    // --- Puntuacion -------------------------------------------------------------------------------------------------------

    private static function scoringSave(array $user, bool $previewOnly): never
    {
        $back = self::guardPost('puntuacion', !$previewOnly);
        $r = ScoringConfig::fromPost($_POST);
        if ($r['errors']) {
            self::flash(false, 'Revisa los campos marcados: ' . count($r['errors']) . ' con error. No se guardó nada.');
            self::index($user, ['form' => $r['form'], 'errors' => $r['errors']], 422, 'puntuacion');
        }
        $settings = ScoringConfig::toSettings($r['form']);
        if ($previewOnly) {
            self::flash(true, 'Vista previa calculada con los valores del formulario (todavía no se guardó nada).');
            self::index($user, ['form' => $r['form'], 'preview' => ScoringConfig::preview(array_replace(Scoring::settings(), $settings))], 200, 'puntuacion');
        }
        $n = ScoringConfig::save($settings, (int) $user['id']);
        self::flash(true, $n > 0 ? 'Puntuación guardada. Los leads nuevos y los que cambien ya usan estos valores; usa «Recalcular» para los existentes.' : 'No había cambios que guardar.');
        self::redirect($back . ($n > 0 ? '#recalcular' : ''));
    }

    private static function scoringReset(array $user): never
    {
        $back = self::guardPost('puntuacion');
        $g = is_string($_POST['group'] ?? null) ? $_POST['group'] : '';
        $r = ScoringConfig::reset($g, (int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect($back);
    }

    private static function scoringRecalc(array $user): never
    {
        $back = self::guardPost('puntuacion', true, '#recalcular');
        if (($_POST['confirm'] ?? '') !== '1') {
            self::flash(false, 'Marca la casilla de confirmación para recalcular.');
            self::redirect($back);
        }
        $r = ScoringConfig::startRecalc((int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect($back);
    }

    private static function provisionalSave(array $user): never
    {
        $back = self::guardPost('puntuacion', true, '#provisionales');
        $v = ScoringConfig::validateProvisional($_POST);
        if (!$v['ok']) {
            self::flash(false, 'Revisa los ajustes provisionales: ' . implode(' ', $v['errors']));
            self::index($user, ['provErrors' => $v['errors'], 'provForm' => $_POST], 422, 'puntuacion');
        }
        $n = ScoringConfig::saveProvisional($v['values'], (int) $user['id']);
        self::flash(true, $n > 0 ? 'Ajustes provisionales guardados.' : 'No había cambios que guardar.');
        self::redirect($back);
    }

    // --- Alertas ----------------------------------------------------------------------------------------------------------

    private static function alertsSave(array $user): never
    {
        $back = self::guardPost('alertas');
        $v = Alerts::validate($_POST);
        if (!$v['ok']) {
            self::flash(false, 'Revisa los campos marcados. No se guardó nada.');
            self::index($user, ['alerts' => $v['values'], 'errors' => $v['errors']], 422, 'alertas');
        }
        $before = Alerts::get();
        Settings::set('alerts', $v['values']);
        $changed = ActivityLog::settingsChange((int) $user['id'], 'alerts', $before, Alerts::get());
        self::flash(true, $changed ? 'Alertas guardadas. Se aplican a partir de ahora.' : 'No había cambios que guardar.');
        self::redirect($back);
    }

    // --- Costos -----------------------------------------------------------------------------------------------------------

    private static function costSave(array $user): never
    {
        $back = self::guardPost('costos');
        $v = Costs::validate($_POST);
        if (!$v['ok']) {
            self::flash(false, 'Revisa los campos marcados. No se guardó nada.');
            self::index($user, ['costForm' => $v['values'] + ['id' => 0], 'errors' => $v['errors']], 422, 'costos');
        }
        $r = Costs::save($v['values'], (int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect($back);
    }

    private static function costDelete(array $user): never
    {
        $back = self::guardPost('costos');
        $r = Costs::delete((int) ($_POST['id'] ?? 0), (int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect($back);
    }

    // --- Horario y zona ---------------------------------------------------------------------------------------------------

    private static function displaySave(array $user): never
    {
        $back = self::guardPost('horario');
        $v = Display::validate($_POST);
        if (!$v['ok']) {
            self::flash(false, $v['msg']);
            self::redirect($back);
        }
        $before = ['timezone' => Display::timezone(), 'hours' => Display::hours()];
        Settings::set('display.timezone', $v['tz']);
        Settings::set('display.hours', $v['hours']);
        $changed = ActivityLog::settingsChange((int) $user['id'], 'display', $before, ['timezone' => $v['tz'], 'hours' => $v['hours']]);
        self::flash(true, $changed ? 'Horario y zona guardados.' : 'No había cambios que guardar.');
        self::redirect($back);
    }

    // --- Integraciones ----------------------------------------------------------------------------------------------------

    private static function integrationTest(array $user): never
    {
        $name = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
        $back = self::guardPost('integraciones', false, '#int-' . preg_replace('/[^a-z]/', '', $name));
        if (!in_array($name, ['telegram', 'fmcsa', 'smtp'], true)) {
            self::notFound();
        }
        $key = 'u' . (int) $user['id'];
        if (RateLimiter::tooMany('config_test', $key, self::TEST_LIMIT, 60)) {
            self::flash(false, 'Demasiadas pruebas seguidas. Espera un minuto.');
            self::redirect($back);
        }
        RateLimiter::hit('config_test', $key, 60);
        $res = Integrations::test($name, is_string($_POST['usdot'] ?? null) ? $_POST['usdot'] : null);
        ActivityLog::record('integration_test', (int) $user['id'], 'integration', $name, ['ok' => $res['ok'], 'level' => $res['level']]);
        $_SESSION['test_result'] = ['name' => $name] + $res + ['at' => Clock::now()];
        self::redirect($back);
    }

    // --- Copias de seguridad ----------------------------------------------------------------------------------------------

    /** «Hacer copia ahora»: la hace el cron de la cola (no exige confirmacion: no entrega datos). */
    private static function backupRun(array $user): never
    {
        $back = self::guardPost('copias', false);
        $r = Backup::request((int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect($back);
    }

    /** Descarga: solo admin y con confirmacion reciente (contiene todos los datos personales de los leads). */
    private static function backupDownload(array $user): never
    {
        $back = self::guardPost('copias', true);
        Backup::download((int) ($_POST['id'] ?? 0), (int) $user['id'], $back);
    }
}
