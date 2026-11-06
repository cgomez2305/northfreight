<?php
/** GET / : dashboard (doc §9.1 y §12) con datos reales + estado del sistema (cola, cron, integraciones). */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\QueryTimeout;
use Captacion\Services\Dashboard;
use Captacion\Services\Fmt;
use Captacion\Services\Mailer;
use Captacion\Services\Queue;
use Captacion\Services\RateLimiter;
use Captacion\Services\Session;
use Captacion\Services\Telegram;
use Captacion\View;

final class DashboardController extends Responder
{
    public const PULSE_LIMIT = 24;   // refrescos por minuto y usuario (el navegador pide 1 por minuto y uno por cada cambio de periodo)

    public static function index(): never
    {
        $user = self::user(true);
        if (($user['role'] ?? '') === 'viewer') {
            self::redirect('/resultados');   // el lector de NorthStar solo ve resultados e informes
        }
        $period = is_string($_GET['p'] ?? null) && isset(Dashboard::PERIODS[$_GET['p']]) ? $_GET['p'] : 'today';
        $rq = is_string($_GET['rq'] ?? null) ? mb_substr(trim($_GET['rq']), 0, 100) : '';
        $rp = max(1, min(100000, (int) ($_GET['rp'] ?? 1)));
        $data = Dashboard::build($period);
        $queue = Queue::stats();
        $last = $queue['last_run'];
        $cronAgo = null;
        $cronLate = true;
        if (is_array($last) && isset($last['at'])) {
            $cronAgo = Fmt::ago((string) $last['at']);
            $cronLate = Clock::time() - (int) Clock::parse((string) $last['at']) > 300;
        }
        $hour = (int) Fmt::et(Clock::time())->format('G');
        $first = trim(explode(' ', (string) $user['name'])[0]);
        self::page('dashboard', $user, 'dashboard', [['Dashboard', null], ['Resumen', null]], [
            'title' => 'Dashboard', 'css' => ['charts', 'dashboard'], 'js' => ['peek', 'dashboard'], 'peekId' => (int) ($_GET['peek'] ?? 0), 'liveV' => (string) (@filemtime(CAPTACION_ROOT . '/public/assets/dashlive.css') ?: 1), 'd' => $data, 'period' => $period, 'recent' => Dashboard::recent($rq, $rp), 'rq' => $rq,
            'greeting' => ($hour < 12 ? 'Buenos días' : ($hour < 19 ? 'Buenas tardes' : 'Buenas noches')) . ', ' . $first,
            'nowEt' => Fmt::et(Clock::time()),
            'queue' => $queue, 'cronAgo' => $cronAgo, 'cronLate' => $cronLate,
            'sys' => ['telegram' => Telegram::configured(), 'smtp' => Mailer::smtpConfigured(), 'fmcsa' => (Env::get('FMCSA_WEBKEY') ?? '') !== ''],
            'csrf' => Session::csrf(),
        ]);
    }

    /**
     * POST /ajax/dashboard {p}: refresco ligero (Q6). Solo administradores (el lector recibe 403), sesion con 2FA, CSRF en X-CSRF-Token, limite de uso por usuario y tope de 4 s de consulta.
     * Devuelve SOLO agregados ya dibujados (KPI, subtitulo y siguientes acciones como fragmentos HTML escapados): ningun nombre, telefono ni id de lead, y nada personal en la URL.
     */
    public static function pulse(): never
    {
        [$user, $body] = self::jsonPost(512);
        if (($user['role'] ?? '') !== 'admin') {
            self::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $key = 'u' . (int) $user['id'];
        if (RateLimiter::tooMany('dash_pulse', $key, self::PULSE_LIMIT, 60)) {
            self::json(['ok' => false, 'error' => 'rate_limited', 'msg' => 'Demasiadas peticiones seguidas. Espera un momento.'], 429, ['Retry-After: 20']);
        }
        RateLimiter::hit('dash_pulse', $key, 60);
        $period = is_string($body['p'] ?? null) && isset(Dashboard::PERIODS[$body['p']]) ? $body['p'] : 'today';
        try {
            $d = Db::withTimeout(4, static fn() => Dashboard::pulse($period));
        } catch (QueryTimeout) {
            self::json(['ok' => false, 'error' => 'timeout', 'msg' => 'El dashboard tardó demasiado en actualizarse.'], 503);
        }
        $narr = $d['narrative'];
        $actions = $d['actions'];
        self::json([
            'ok' => true, 'p' => $period, 'at' => $d['updated_at'], 'ts' => Fmt::et($d['updated_at'])->format('H:i') . ' ' . Fmt::zone(),
            'kpis' => View::render('_dash_kpis', ['d' => $d, 'e' => [View::class, 'e']], false),
            'headline' => View::render('_dash_narr', ['narr' => $narr], false),
            'actions' => View::render('_dash_actions', ['actions' => $actions], false),
        ]);
    }
}
