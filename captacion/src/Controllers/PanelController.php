<?php
/** Panel minimo (fase Q1): saludo, estado de la cola, ultimos 20 leads y ficha de solo lectura. El panel completo es de Q2. */

declare(strict_types=1);

namespace Captacion\Controllers;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;
use Captacion\Models\User;
use Captacion\Services\Mailer;
use Captacion\Services\Queue;
use Captacion\Services\Session;
use Captacion\Services\Telegram;

final class PanelController extends Responder
{
    private static function user(): array
    {
        Session::start();
        $uid = Session::userId();
        $user = $uid !== null ? User::find($uid) : null;
        if ($user === null || (int) $user['is_active'] !== 1) {
            if ($uid !== null) {
                Session::destroy();   // la cuenta ya no existe o se desactivo: fuera
            }
            self::redirect('/login');
        }
        return $user;
    }

    public static function dashboard(): never
    {
        $user = self::user();
        $queue = Queue::stats();
        $last = $queue['last_run'];
        $warnings = [];
        if (!Telegram::configured()) {
            $warnings[] = 'Telegram sin configurar: faltan TELEGRAM_BOT_TOKEN o TELEGRAM_CHAT_ID. Las alertas de leads calientes fallarán hasta que se añadan en config/.env.';
        }
        $ago = 'nunca';
        if (is_array($last) && isset($last['at'])) {
            $secs = max(0, Clock::time() - (int) Clock::parse((string) $last['at']));
            $ago = $secs < 90 ? 'hace ' . $secs . ' s' : 'hace ' . intdiv($secs, 60) . ' min';
            if ($secs > 300) {
                $warnings[] = 'El cron de la cola no corre desde hace más de 5 minutos. Revisa la línea de cron en hPanel.';
            }
        } else {
            $warnings[] = 'El cron de la cola todavía no se ha ejecutado nunca. Crea la tarea de cron de process_jobs.php (ver README).';
        }
        if ($queue['counts']['failed'] > 0) {
            $warnings[] = 'Hay ' . $queue['counts']['failed'] . ' trabajo(s) fallido(s) para revisar.';
        }
        if (!Mailer::smtpConfigured()) {
            $warnings[] = 'SMTP sin configurar: los avisos por correo se guardan en storage/mail.log en vez de enviarse.';
        }
        $notice = $_SESSION['notice'] ?? null;
        unset($_SESSION['notice']);
        self::html('panel', [
            'title' => 'Panel', 'user' => $user, 'csrf' => Session::csrf(), 'queue' => $queue, 'lastAgo' => $ago, 'warnings' => $warnings,
            'leads' => Lead::latest(20), 'total' => Lead::count(), 'notice' => $notice,
        ]);
    }

    public static function lead(int $id): never
    {
        self::user();
        $lead = Lead::find($id);
        if ($lead === null) {
            self::notFound();
        }
        $events = Db::all('SELECT type, created_at FROM lead_events WHERE lead_id = ? ORDER BY id DESC LIMIT 100', [$id]);
        self::html('lead', ['title' => 'Lead #' . $id, 'lead' => $lead, 'events' => $events]);
    }
}
