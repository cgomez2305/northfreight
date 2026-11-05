<?php
/** GET / : dashboard (doc §9.1 y §12) con datos reales + estado del sistema (cola, cron, integraciones). */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Clock;
use Captacion\Env;
use Captacion\Services\Dashboard;
use Captacion\Services\Fmt;
use Captacion\Services\Mailer;
use Captacion\Services\Queue;
use Captacion\Services\Session;
use Captacion\Services\Telegram;

final class DashboardController extends Responder
{
    public static function index(): never
    {
        $user = self::user();
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
            'title' => 'Dashboard', 'css' => ['charts', 'dashboard'], 'd' => $data, 'period' => $period, 'recent' => Dashboard::recent($rq, $rp), 'rq' => $rq,
            'greeting' => ($hour < 12 ? 'Buenos días' : ($hour < 19 ? 'Buenas tardes' : 'Buenas noches')) . ', ' . $first,
            'nowEt' => Fmt::et(Clock::time()),
            'queue' => $queue, 'cronAgo' => $cronAgo, 'cronLate' => $cronLate,
            'sys' => ['telegram' => Telegram::configured(), 'smtp' => Mailer::smtpConfigured(), 'fmcsa' => (Env::get('FMCSA_WEBKEY') ?? '') !== ''],
            'csrf' => Session::csrf(),
        ]);
    }
}
