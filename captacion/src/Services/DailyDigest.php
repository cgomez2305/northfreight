<?php
/**
 * Resumen diario (fase AM). A las 8:00 de la zona de visualizacion (Configuracion > Horario y zona), dentro de cron/reports_cron.php (que ya corre cada hora), un correo a los
 * destinatarios de alertas (Alerts::mailTo) y un mensaje de Telegram si esta configurado, con: leads de ayer (total, calientes y tibios), leads sin contactar y con el SLA vencido,
 * tareas vencidas y de hoy, citas de hoy y las «Siguiente mejor accion» del dashboard (DashboardActions). Solo conteos y nombres, y SOLO para administradores: el lector de NorthStar
 * nunca lo recibe (no es una pantalla y los destinatarios son los de las alertas del CRM).
 *
 * Interruptor: settings alerts.daily_digest (Configuracion > Alertas), ENCENDIDO por defecto. Idempotente por dia: settings `cron.digest` guarda la fecha local ya enviada, asi que
 * correr el cron varias veces el mismo dia (o recuperar tras una caida, hasta las 23:59) no manda dos. Si no hay ningun canal (ni correo de avisos ni Telegram) o todos fallan,
 * no se marca como enviado y se reintenta en la siguiente pasada del cron.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class DailyDigest
{
    public const HOUR = 8;
    public const NAMES = 5;

    /** @return array{sent:bool,reason:string,channels:list<string>} */
    public static function tick(?int $now = null): array
    {
        $now ??= Clock::time();
        if (!Alerts::get()['daily_digest']) {
            return ['sent' => false, 'reason' => 'off', 'channels' => []];
        }
        $local = Fmt::et($now);
        $today = $local->format('Y-m-d');
        if ((int) $local->format('G') < self::HOUR) {
            return ['sent' => false, 'reason' => 'early', 'channels' => []];
        }
        $last = Settings::get('cron.digest');
        if (is_array($last) && ($last['date'] ?? '') === $today) {
            return ['sent' => false, 'reason' => 'already', 'channels' => []];
        }
        $d = self::compute($now);
        $channels = [];
        $to = Alerts::mailTo();
        if ($to !== '') {
            try {
                Mailer::deliver(['to' => $to, 'subject' => $d['subject'], 'text' => $d['text'], 'from_name' => 'NorthStar captacion']);
                $channels[] = 'email';
            } catch (\Throwable $t) {
                Storage::error('daily digest email failed', get_class($t) . ': ' . $t->getMessage());
            }
        }
        if (Telegram::configured()) {
            try {
                Telegram::send($d['telegram']);
                $channels[] = 'telegram';
            } catch (\Throwable $t) {
                Storage::error('daily digest telegram failed', get_class($t) . ': ' . $t->getMessage());
            }
        }
        if (!$channels) {
            return ['sent' => false, 'reason' => $to === '' && !Telegram::configured() ? 'no_channel' : 'failed', 'channels' => []];
        }
        Settings::set('cron.digest', ['date' => $today, 'at' => Clock::at($now), 'channels' => $channels]);
        ActivityLog::record('daily_digest', null, 'system', null, ['date' => $today, 'channels' => $channels]);
        return ['sent' => true, 'reason' => 'ok', 'channels' => $channels];
    }

    /**
     * Contenido del resumen (puro salvo las consultas): conteos y nombres.
     * @return array{subject:string,text:string,telegram:string,data:array<string,mixed>}
     */
    public static function compute(int $now): array
    {
        $y0 = Fmt::dayStart($now, -1);
        $t0 = Fmt::dayStart($now, 0);
        $t1 = Fmt::dayStart($now, 1);
        $rows = Db::all('SELECT temperature, COUNT(*) AS n FROM leads WHERE deleted_at IS NULL AND created_at >= ? AND created_at < ? GROUP BY temperature', [Clock::at($y0), Clock::at($t0)]);
        $by = array_fill_keys(['hot', 'warm', 'cold', 'disqualified'], 0);
        foreach ($rows as $r) {
            $by[(string) $r['temperature']] = (int) $r['n'];
        }
        $received = array_sum($by);
        $uncontacted = LeadQuery::count(['ct' => 'none']);
        [$slaSql, $slaP] = Sla::overdueSql('l', $now);
        $slaLate = (int) Db::value("SELECT COUNT(*) FROM leads l WHERE $slaSql", $slaP);
        $slaNames = [];
        foreach (Db::all("SELECT l.id, l.first_name, l.last_name FROM leads l WHERE $slaSql ORDER BY " . Sla::startSql('l') . ' ASC, l.id ASC LIMIT ' . self::NAMES, $slaP) as $l) {
            $slaNames[] = Fmt::name($l);
        }
        $overdue = (int) Db::value('SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL AND done_at IS NULL AND due_at IS NOT NULL AND due_at < ?', [Clock::at($now)]);
        $todayTasks = (int) Db::value('SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL AND done_at IS NULL AND due_at IS NOT NULL AND due_at >= ? AND due_at < ?', [Clock::at($now), Clock::at($t1)]);
        $appts = Db::all("SELECT a.starts_at, l.first_name, l.last_name FROM appointments a JOIN leads l ON l.id = a.lead_id WHERE a.deleted_at IS NULL AND a.status <> 'cancelled' AND a.starts_at >= ? AND a.starts_at < ? ORDER BY a.starts_at ASC LIMIT 20", [Clock::at($t0), Clock::at($t1)]);
        $hot = Db::one("SELECT COUNT(*) AS n, MIN(created_at) AS oldest FROM leads WHERE deleted_at IS NULL AND temperature = 'hot' AND first_contact_at IS NULL AND stage = 'new'");
        $actions = DashboardActions::compute($now, ['n' => (int) ($hot['n'] ?? 0), 'oldest_secs' => ($hot['oldest'] ?? null) !== null ? Fmt::secondsBetween((string) $hot['oldest'], Clock::at($now)) : null]);

        $day = Fmt::weekdayLong((int) Fmt::et($now)->format('N')) . ' ' . Fmt::dayLabel(Fmt::et($now));
        $lines = [
            'Leads de ayer: ' . $received . ' (' . $by['hot'] . ' calientes, ' . $by['warm'] . ' tibios)',
            'Sin contactar: ' . $uncontacted . ' · con el plazo de primer contacto vencido: ' . $slaLate . ($slaNames ? ' (' . implode(', ', $slaNames) . ($slaLate > count($slaNames) ? ' y más' : '') . ')' : ''),
            'Tareas vencidas: ' . $overdue . ' · para hoy: ' . $todayTasks,
            'Citas de hoy: ' . count($appts) . ($appts ? ' (' . implode(', ', array_map(static fn($a) => Fmt::et((string) $a['starts_at'])->format('H:i') . ' ' . Fmt::name($a), array_slice($appts, 0, 8))) . ')' : ''),
        ];
        $acts = array_map(static fn($a) => $a['title'] . ': ' . $a['detail'], $actions);
        $text = "Resumen de captación · $day\n\n" . implode("\n", $lines) . "\n\nSiguiente mejor acción:\n" . ($acts ? '- ' . implode("\n- ", $acts) : '- Todo al día.') . "\n\nPanel: " . Url::base() . "/\n";
        $tg = '<b>' . self::e('Resumen de captación · ' . $day) . "</b>\n" . implode("\n", array_map([self::class, 'e'], $lines)) . "\n<b>Siguiente mejor acción</b>\n" . ($acts ? implode("\n", array_map(static fn($a) => '• ' . self::e($a), $acts)) : self::e('Todo al día.'))
            . "\n" . '<a href="' . self::e(Url::base() . '/') . '">Abrir el panel</a>';
        return ['subject' => '[Captacion] Resumen del día · ' . $day, 'text' => $text, 'telegram' => $tg,
            'data' => ['received' => $received, 'hot' => $by['hot'], 'warm' => $by['warm'], 'uncontacted' => $uncontacted, 'sla_late' => $slaLate, 'overdue' => $overdue, 'today_tasks' => $todayTasks, 'appointments' => count($appts), 'actions' => count($actions)]];
    }

    private static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
