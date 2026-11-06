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
use Captacion\Services\Handoff;
use Captacion\Services\Integrations;
use Captacion\Services\MetaLeads;
use Captacion\Services\Appointments;
use Captacion\Services\SmsInbound;
use Captacion\Services\N8nBridge;
use Captacion\Services\Webhook;
use Captacion\Services\Catalog;
use Captacion\Services\LeadPayload;
use Captacion\Services\Invites;
use Captacion\Services\Mailer;
use Captacion\Services\ProspectConfig;
use Captacion\Services\ProspectImport;
use Captacion\Services\RateLimiter;
use Captacion\Services\Reauth;
use Captacion\Services\Scoring;
use Captacion\Services\ScoringConfig;
use Captacion\Services\Sequences;
use Captacion\Services\Settings;

final class ConfigController extends Responder
{
    public const TABS = [
        'puntuacion' => 'Puntuación', 'alertas' => 'Alertas', 'costos' => 'Costos por canal', 'integraciones' => 'Integraciones', 'horario' => 'Horario y zona', 'copias' => 'Copias de seguridad',
        'entrega' => 'Entrega a NorthStar', 'correo' => 'Correo y secuencias', 'usuarios' => 'Usuarios', 'prospectos' => 'Prospectos', 'canales' => 'Canales externos',
    ];
    /** Pestañas cuyos formularios exigen confirmacion reciente. */
    public const LOCKED_TABS = ['puntuacion', 'alertas', 'costos', 'horario', 'copias', 'entrega', 'correo', 'usuarios', 'prospectos', 'canales'];
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
            'handoff/save'        => self::handoffSave($user),
            'handoff/test'        => self::handoffTest($user),
            'handoff/retry'       => self::handoffRetry($user),
            'mail/save'           => self::mailSave($user),
            'users/invite'        => self::usersInvite($user),
            'users/relink'        => self::usersAction($user, 'relink'),
            'users/reset'         => self::usersAction($user, 'reset'),
            'users/deactivate'    => self::usersAction($user, 'deactivate'),
            'users/activate'      => self::usersAction($user, 'activate'),
            'prospects/save'      => self::prospectsSave($user),
            'prospects/window'    => self::prospectsWindow($user),
            'canales/meta'        => self::canalesSave($user, 'meta'),
            'canales/calcom'      => self::canalesSave($user, 'calcom'),
            'canales/twilio'      => self::canalesSave($user, 'twilio'),
            'canales/n8n'         => self::canalesSave($user, 'n8n'),
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
            'entrega'       => ['hand' => Handoff::config(), 'handView' => Handoff::overview(), 'handTargets' => Handoff::targets(), 'once' => self::takeOnce()],
            'correo'        => ['mail' => Sequences::mailSettings(), 'mailWarn' => Sequences::warnings(), 'canDeliver' => Mailer::canDeliver(), 'smtpSet' => Mailer::smtpConfigured(), 'mailFrom' => Mailer::fromAddress()],
            'usuarios'      => ['viewers' => Invites::viewers(), 'once' => self::takeOnce(), 'inviteForm' => ['name' => '', 'email' => '']],
            'canales'       => ['meta' => MetaLeads::config(), 'metaTargets' => MetaLeads::TARGETS, 'metaDefaults' => MetaLeads::DEFAULT_MAP, 'cal' => Appointments::config(), 'tw' => SmsInbound::config(), 'n8n' => N8nBridge::config(),
                'steps' => N8nBridge::STEP_LABELS, 'profiles' => Catalog::PROFILES, 'urls' => ['meta' => Webhook::url('meta'), 'calcom' => Webhook::url('calcom'), 'twilio' => Webhook::url('twilio'), 'n8n' => Webhook::url('n8n')]],
            'prospectos'    => [
                'pc' => ProspectConfig::get(), 'pRun' => ProspectImport::state(), 'pComplete' => ProspectImport::completeUntil(), 'pCron' => Settings::get('cron.prospects'),
                'pSocrata' => Integrations::last('socrata'), 'pToken' => (new \Captacion\Services\SocrataClient())->hasToken(), 'pForm' => null,
            ],
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

    /** Secreto o enlace que se muestra UNA vez (queda en la sesion hasta la siguiente pantalla de configuracion). */
    private static function takeOnce(): ?array
    {
        $r = $_SESSION['cfg_once'] ?? null;
        unset($_SESSION['cfg_once']);
        return is_array($r) ? $r : null;
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

    // --- Prospectos FMCSA ---------------------------------------------------------------------------------------------------

    private static function prospectsSave(array $user): never
    {
        $back = self::guardPost('prospectos');
        $in = [
            'enabled' => isset($_POST['enabled']) ? '1' : '0', 'require_phone' => isset($_POST['require_phone']) ? '1' : '0', 'max_age_months' => $_POST['max_age_months'] ?? '', 'max_fleet' => $_POST['max_fleet'] ?? '',
            'daily_cap' => $_POST['daily_cap'] ?? '', 'states' => is_array($_POST['states'] ?? null) ? array_slice($_POST['states'], 0, 60) : [], 'cargo' => is_array($_POST['cargo'] ?? null) ? array_slice($_POST['cargo'], 0, 10) : [],
        ];
        $v = ProspectConfig::normalize($in);
        if ($v['errors'] || count($v['clean']) < 7) {
            self::flash(false, $v['errors'] ? 'Revisa los campos marcados.' : 'Faltan datos del formulario.');
            self::index($user, ['errors' => $v['errors'], 'pForm' => $in], 422, 'prospectos');
        }
        $before = ProspectConfig::get();
        $after = $v['clean'] + $before;
        Settings::set('prospects.config', $after);
        $changed = ActivityLog::settingsChange((int) $user['id'], 'prospects', $before, $after);
        $filters = ['max_age_months', 'states', 'max_fleet', 'require_phone', 'cargo'];
        $widened = $changed && array_filter($filters, static fn($k) => $before[$k] !== $after[$k]);
        if ($widened) {
            ProspectImport::resetWindow();   // la proxima corrida vuelve a evaluar toda la ventana con los filtros nuevos
        }
        self::flash(true, !$changed ? 'No había cambios que guardar.' : 'Filtros guardados.' . ($widened ? ' La próxima corrida vuelve a revisar toda la ventana con los filtros nuevos.' : ''));
        self::redirect($back);
    }

    private static function prospectsWindow(array $user): never
    {
        $back = self::guardPost('prospectos');
        ProspectImport::resetWindow();
        ActivityLog::record('prospects_window_reset', (int) $user['id'], 'prospects', null, []);
        self::flash(true, 'Listo: la próxima corrida revisará de nuevo toda la ventana de antigüedad (lo que ya está en la lista no se duplica).');
        self::redirect($back);
    }

    // --- Integraciones ----------------------------------------------------------------------------------------------------

    private static function integrationTest(array $user): never
    {
        $name = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
        $back = self::guardPost('integraciones', false, '#int-' . preg_replace('/[^a-z]/', '', $name));
        if (!in_array($name, ['telegram', 'fmcsa', 'smtp', 'socrata', 'meta', 'calcom', 'twilio', 'n8n'], true)) {
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

    // --- Canales externos (Meta, Cal.com, Twilio, n8n) ---------------------------------------------------------------------------------

    private static function canalesSave(array $user, string $which): never
    {
        $back = self::guardPost('canales', true, '#cn-' . $which);
        $in = $_POST;
        $str = static fn(string $k, int $max): string => is_string($in[$k] ?? null) ? mb_substr(trim((string) preg_replace('/[^\P{C}\n\t]/u', '', $in[$k])), 0, $max) : '';
        $before = Settings::get($which === 'twilio' ? 'twilio' : $which, []);
        if ($which === 'meta') {
            $map = [];
            $from = is_array($in['map_from'] ?? null) ? $in['map_from'] : [];
            $to = is_array($in['map_to'] ?? null) ? $in['map_to'] : [];
            foreach ($from as $i => $f) {
                $f = MetaLeads::fieldKey(is_string($f) ? $f : '');
                $t = is_string($to[$i] ?? null) ? $to[$i] : '';
                if ($f !== '' && isset(MetaLeads::TARGETS[$t]) && count($map) < 40) {
                    $map[] = ['from' => $f, 'to' => $t];
                }
            }
            $profile = in_array($in['default_profile'] ?? '', LeadPayload::PROFILES, true) ? (string) $in['default_profile'] : 'owner_operator';
            $ch = array_values(array_intersect(['call', 'sms', 'email', 'whatsapp'], array_map('strval', is_array($in['consent_channels'] ?? null) ? $in['consent_channels'] : [])));
            $new = ['default_profile' => $profile, 'map' => $map, 'consent_text' => $str('consent_text', 4000), 'consent_channels' => $ch, 'disclaimer_key' => MetaLeads::fieldKey($str('disclaimer_key', 120))];
            if ($new['consent_text'] !== '' && $ch === []) {
                self::flash(false, 'Con un texto de consentimiento hay que marcar al menos un canal que cubra.');
                self::redirect($back);
            }
            Settings::set('meta', $new);
        } elseif ($which === 'calcom') {
            $url = $str('booking_url', 200);
            if ($url !== '' && preg_match('#^https://[^\s<>"\']{4,200}$#', $url) !== 1) {
                self::flash(false, 'El enlace de reserva debe empezar por https://.');
                self::redirect($back);
            }
            Settings::set('calcom', ['default_profile' => in_array($in['default_profile'] ?? '', LeadPayload::PROFILES, true) ? (string) $in['default_profile'] : 'owner_operator', 'booking_url' => $url]);
        } elseif ($which === 'twilio') {
            Settings::set('twilio', ['help_text' => $str('help_text', 320)]);
        } else {
            $steps = array_values(array_intersect(array_keys(N8nBridge::STEP_LABELS), array_map('strval', is_array($in['wa_steps'] ?? null) ? $in['wa_steps'] : [])));
            Settings::set('n8n', ['wa_steps' => $steps, 'sms_enabled' => !empty($in['sms_enabled'])]);
        }
        ActivityLog::settingsChange((int) $user['id'], 'canales_' . $which, is_array($before) ? $before : [], (array) Settings::get($which, []));
        self::flash(true, 'Ajustes guardados.');
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

    // --- Entrega a NorthStar (Q3-A) ----------------------------------------------------------------------------------------

    private static function handoffSave(array $user): never
    {
        $back = self::guardPost('entrega');
        $v = Handoff::validate($_POST);
        $mode = is_string($_POST['secret_mode'] ?? null) ? $_POST['secret_mode'] : 'keep';
        $secret = null;
        $once = null;
        if ($mode === 'generate') {
            $secret = Handoff::generateSecret();
            $once = ['kind' => 'secret', 'value' => $secret];
        } elseif ($mode === 'set') {
            $secret = is_string($_POST['webhook_secret'] ?? null) ? trim($_POST['webhook_secret']) : '';
            if ($secret === '') {
                $v['errors']['webhook_secret'] = 'Escribe el secreto o elige «Generar uno nuevo».';
                $v['ok'] = false;
            }
        } elseif ($mode === 'clear') {
            $secret = '';
        }
        if (!$v['ok']) {
            self::flash(false, 'Revisa los campos marcados. No se guardó nada.');
            self::index($user, ['hand' => ['email' => $v['values']['email'], 'webhook_url' => $v['values']['webhook_url'], 'webhook_on' => $v['values']['webhook_on'], 'webhook_secret' => '', 'secret_set' => Handoff::config()['secret_set']], 'errors' => $v['errors']], 422, 'entrega');
        }
        $r = Handoff::saveConfig($v['values'], $secret, (int) $user['id']);
        if (!$r['ok']) {
            self::flash(false, $r['msg']);
            self::redirect($back);
        }
        if ($once !== null) {
            $_SESSION['cfg_once'] = $once;
        }
        // lo que estaba esperando un destino se pone en cola ahora que lo hay
        $extra = Handoff::configured() ? Handoff::retryPending((int) $user['id']) : null;
        self::flash(true, $r['msg'] . ($extra !== null && $extra['ok'] && !str_starts_with($extra['msg'], 'No había') ? ' ' . $extra['msg'] : ''));
        self::redirect($back);
    }

    private static function handoffTest(array $user): never
    {
        $back = self::guardPost('entrega', true, '#prueba');
        if (!Handoff::configured()) {
            self::flash(false, 'No hay ningún destino activo: guarda un correo o un webhook primero.');
            self::redirect($back);
        }
        $key = 'u' . (int) $user['id'];
        if (RateLimiter::tooMany('handoff_test', $key, 4, 60)) {
            self::flash(false, 'Demasiadas pruebas seguidas. Espera un minuto.');
            self::redirect($back);
        }
        RateLimiter::hit('handoff_test', $key, 60);
        $res = Handoff::sendTest((int) $user['id']);
        $ok = count(array_filter($res['results'], static fn($r) => $r['ok']));
        self::flash($ok === count($res['results']), 'Prueba de entrega: ' . $ok . ' de ' . count($res['results']) . ' destino' . (count($res['results']) === 1 ? '' : 's') . ' respondió bien.');
        self::redirect($back);
    }

    private static function handoffRetry(array $user): never
    {
        $back = self::guardPost('entrega');
        $r = Handoff::retryPending((int) $user['id']);
        self::flash($r['ok'], $r['msg']);
        self::redirect($back);
    }

    // --- Correo y secuencias (Q3-E/D) ----------------------------------------------------------------------------------------

    private static function mailSave(array $user): never
    {
        $back = self::guardPost('correo');
        $err = [];
        $line = static fn($k, $max) => mb_substr(trim((string) preg_replace('/[^\P{C}\n]/u', '', is_string($_POST[$k] ?? null) ? $_POST[$k] : '')), 0, $max);
        $address = trim((string) preg_replace('/\n{2,}/', "\n", str_replace("\r", '', $line('address', 300))));
        $enabled = ($_POST['enabled'] ?? '') === '1';
        if ($address !== '' && mb_strlen($address) < 12) {
            $err['address'] = 'Escribe la dirección completa (calle, ciudad, estado y código postal).';
        }
        if (preg_match('/<|>/', $address) === 1) {
            $err['address'] = 'Solo texto, sin etiquetas.';
        }
        if ($enabled && $address === '') {
            $err['address'] = 'Para encender las secuencias hace falta la dirección física del remitente (CAN-SPAM).';
        }
        $fromName = $line('from_name', 80) ?: 'NorthStar Freight Solutions';
        $replyTo = $line('reply_to', 190);
        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $err['reply_to'] = 'Ese correo no es válido.';
        }
        $weekly = $line('weekly_to', 190);
        if ($weekly !== '' && !filter_var($weekly, FILTER_VALIDATE_EMAIL)) {
            $err['weekly_to'] = 'Ese correo no es válido.';
        }
        $monthly = [];
        foreach (preg_split('/[\s,;]+/', is_string($_POST['monthly_to'] ?? null) ? $_POST['monthly_to'] : '') ?: [] as $m) {
            $m = strtolower(trim($m));
            if ($m === '') {
                continue;
            }
            if (!filter_var($m, FILTER_VALIDATE_EMAIL) || strlen($m) > 190) {
                $err['monthly_to'] = 'Hay un correo que no es válido: ' . mb_substr($m, 0, 60);
                break;
            }
            $monthly[] = $m;
        }
        if (count($monthly) > 10) {
            $err['monthly_to'] = 'Máximo 10 destinatarios.';
        }
        $capHour = is_string($_POST['cap_hour'] ?? null) && ctype_digit($_POST['cap_hour']) ? (int) $_POST['cap_hour'] : 0;
        $capLead = is_string($_POST['cap_lead'] ?? null) && ctype_digit($_POST['cap_lead']) ? (int) $_POST['cap_lead'] : 0;
        if ($capHour < 1 || $capHour > 500) {
            $err['cap_hour'] = 'Entre 1 y 500 correos por hora.';
        }
        if ($capLead < 1 || $capLead > 5) {
            $err['cap_lead'] = 'Entre 1 y 5 correos por lead cada 24 horas.';
        }
        $rates = $line('rates_source', 200);
        $values = ['enabled' => $enabled, 'address' => $address, 'from_name' => $fromName, 'reply_to' => $replyTo, 'weekly_to' => $weekly, 'monthly_to' => array_values(array_unique($monthly)), 'cap_hour' => $capHour ?: 20, 'cap_lead' => $capLead ?: 1, 'rates_source' => $rates];
        if ($err) {
            self::flash(false, 'Revisa los campos marcados. No se guardó nada.');
            self::index($user, ['mail' => $values, 'errors' => $err], 422, 'correo');
        }
        $before = Sequences::mailSettings();
        Settings::set('mail', $values);
        $changed = ActivityLog::settingsChange((int) $user['id'], 'mail', $before, Sequences::mailSettings());
        self::flash(true, $changed ? 'Correo y secuencias guardados.' : 'No había cambios que guardar.');
        self::redirect($back);
    }

    // --- Usuarios lectores (Q3-B) --------------------------------------------------------------------------------------------

    private static function usersInvite(array $user): never
    {
        $back = self::guardPost('usuarios');
        $name = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $r = Invites::invite($name, $email, (int) $user['id']);
        if (!$r['ok']) {
            self::flash(false, 'Revisa los campos marcados. No se creó ninguna cuenta.');
            self::index($user, ['inviteForm' => ['name' => mb_substr($name, 0, 120), 'email' => mb_substr($email, 0, 190)], 'errors' => $r['errors']], 422, 'usuarios');
        }
        $_SESSION['cfg_once'] = ['kind' => 'link', 'value' => $r['link'], 'name' => mb_substr(trim($name), 0, 120), 'ttl' => '48 horas'];
        self::flash(true, 'Cuenta de lector creada. Copia el enlace de invitación ahora: no se vuelve a mostrar.');
        self::redirect($back);
    }

    private static function usersAction(array $user, string $action): never
    {
        $back = self::guardPost('usuarios');
        $id = is_string($_POST['id'] ?? null) && ctype_digit($_POST['id']) ? (int) $_POST['id'] : 0;
        $uid = (int) $user['id'];
        $r = match ($action) {
            'relink'     => Invites::relink($id, $uid),
            'reset'      => Invites::reset2fa($id, $uid),
            'deactivate' => Invites::setActive($id, false, $uid) + ['link' => null],
            default      => Invites::setActive($id, true, $uid) + ['link' => null],
        };
        if (!empty($r['link'])) {
            $who = \Captacion\Models\User::find($id);
            $_SESSION['cfg_once'] = ['kind' => 'link', 'value' => $r['link'], 'name' => (string) ($who['name'] ?? ''), 'ttl' => '48 horas'];
        }
        self::flash($r['ok'], $r['msg']);
        self::redirect($back);
    }
}
