<?php
/**
 * Informes automaticos (doc §8 A13 y A14, §12) y su ciclo de vida.
 *
 *  - Resumen semanal (A13): lunes 8:00 a. m. ET por correo a Carlos, de la semana ANTERIOR (lunes a domingo). Se guarda en `reports` (kind weekly, periodo 'AAAA-Www') para no duplicarse.
 *  - Informe mensual (A14): el dia 1 de cada mes (desde la 1:00 a. m. ET, con recuperacion hasta el dia 5) se genera el del mes anterior como BORRADOR: instantanea de metricas
 *    (JSON), comparacion con el mes anterior y tres acciones sugeridas por reglas simples. Carlos las edita y pulsa «Publicar»: solo entonces lo ve el lector y se envia el enlace
 *    a los destinatarios configurados. Idempotente: (kind, periodo) es unico y cada envio deja su marca (notified_at / sent_at).
 *  - cron/reports_cron.php llama a tick() cada hora; la zona es SIEMPRE America/New_York (no la de visualizacion).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class ReportJobs
{
    public const TZ = 'America/New_York';

    private static function et(int $ts): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone(self::TZ));
    }

    private static function etInput(string $ymd): int
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ymd . ' 00:00:00', new \DateTimeZone(self::TZ));
        return $d === false ? 0 : $d->getTimestamp();
    }

    // --- Acciones sugeridas (reglas simples y honestas) --------------------------------------------------------------------------------

    /** Hasta 3 acciones calculadas con datos reales; si no hay nada que senalar, acciones generales de mantenimiento. @return list<string> */
    public static function suggest(array $cur, ?array $prev, int $now): array
    {
        $out = [];
        $hot = Db::one("SELECT COUNT(*) AS n, MIN(created_at) AS oldest FROM leads WHERE deleted_at IS NULL AND temperature = 'hot' AND first_contact_at IS NULL AND stage = 'new'");
        if ((int) $hot['n'] > 0) {
            $out[] = 'Contactar ' . (int) $hot['n'] . ' lead' . ((int) $hot['n'] === 1 ? '' : 's') . ' caliente' . ((int) $hot['n'] === 1 ? '' : 's') . ' sin contactar (el más antiguo espera ' . Fmt::dur(Fmt::secondsBetween((string) $hot['oldest'], Clock::at($now))) . ').';
        }
        $late = (int) Db::value('SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL AND done_at IS NULL AND due_at IS NOT NULL AND due_at < ?', [Clock::at($now)]);
        if ($late > 0) {
            $out[] = 'Cerrar las ' . $late . ' tarea' . ($late === 1 ? '' : 's') . ' vencida' . ($late === 1 ? '' : 's') . '.';
        }
        $stale = (int) Db::value("SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL AND stage = 'presented' AND handed_off_at IS NOT NULL AND handed_off_at < ?", [Clock::at($now - 7 * 86400)]);
        if ($stale > 0) {
            $out[] = 'Pedir a NorthStar respuesta sobre ' . $stale . ' lead' . ($stale === 1 ? '' : 's') . ' presentado' . ($stale === 1 ? '' : 's') . ' hace más de 7 días.';
        }
        if ($cur['received'] > 0 && $cur['channels']['cost_months'] && $cur['channels']['total']['cost'] <= 0) {
            $out[] = 'Cargar los costos de ' . implode(', ', array_map([Reports::class, 'monthName'], $cur['channels']['cost_months'])) . ' en Configuración > Costos por canal para calcular el costo por firmado.';
        }
        $rows = array_values(array_filter($cur['channels']['rows'], static fn($r) => $r['leads'] > 0));
        if ($rows) {
            usort($rows, static fn($a, $b) => [$b['signed'], $b['leads']] <=> [$a['signed'], $a['leads']]);
            if ($rows[0]['signed'] > 0) {
                $out[] = 'Reforzar ' . $rows[0]['label'] . ': ' . $rows[0]['signed'] . ' firmado' . ($rows[0]['signed'] === 1 ? '' : 's') . ' de ' . $rows[0]['leads'] . ' lead' . ($rows[0]['leads'] === 1 ? '' : 's') . ' recibidos.';
            }
            foreach (array_reverse($rows) as $r) {
                if ($r['leads'] >= 5 && $r['signed'] === 0) {
                    $out[] = 'Revisar ' . $r['label'] . ': ' . $r['leads'] . ' leads y ningún firmado.';
                    break;
                }
            }
        }
        if ($cur['response']['median'] !== null && $cur['response']['median'] > 900) {
            $out[] = 'Bajar el tiempo de primera respuesta (mediana de ' . Fmt::dur($cur['response']['median']) . '): contactar los leads calientes antes de 15 minutos.';
        }
        if ($prev !== null && $prev['received'] >= 10 && $cur['received'] < $prev['received'] * 0.7) {
            $out[] = 'Revisar por qué bajó la captación: ' . $cur['received'] . ' leads frente a ' . $prev['received'] . ' del periodo anterior.';
        }
        $out[] = 'Mantener el seguimiento diario de los leads calientes y la revisión de tareas vencidas.';
        $out[] = 'Revisar semanalmente los leads presentados que siguen sin respuesta de NorthStar.';
        $out[] = 'Cargar cada mes los costos por canal para seguir el costo por firmado.';
        return array_slice(array_values(array_unique($out)), 0, 3);
    }

    // --- Informe mensual ----------------------------------------------------------------------------------------------------------------------

    /** Mes anterior a $now (hora ET) como 'AAAA-MM'. */
    public static function previousMonth(int $now): string
    {
        return self::et($now)->modify('first day of last month')->format('Y-m');
    }

    public static function findMonthly(string $period): ?array
    {
        return Db::one("SELECT * FROM reports WHERE kind = 'monthly' AND period = ?", [$period]);
    }

    /**
     * Genera (una sola vez) el informe mensual de $period. @return array{ok:bool,msg:string,id:?int,created:bool}
     */
    public static function generateMonthly(string $period, ?int $userId, ?int $now = null): array
    {
        $now ??= Clock::time();
        if (preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $period) !== 1) {
            return ['ok' => false, 'msg' => 'Elige un mes válido (AAAA-MM).', 'id' => null, 'created' => false];
        }
        if ($period >= self::et($now)->format('Y-m')) {
            return ['ok' => false, 'msg' => 'Ese mes todavía no termina: solo se generan informes de meses pasados.', 'id' => null, 'created' => false];
        }
        $have = self::findMonthly($period);
        if ($have !== null) {
            return ['ok' => true, 'msg' => 'El informe de ' . Reports::monthName($period) . ' ya existe (' . $have['ident'] . ').', 'id' => (int) $have['id'], 'created' => false];
        }
        $from = self::etInput($period . '-01');
        $to = self::etInput(self::et($from)->modify('first day of next month')->format('Y-m-d'));
        $pfrom = self::etInput(self::et($from)->modify('first day of last month')->format('Y-m-d'));
        $cur = Reports::compute($from, $to);
        $prev = Reports::compute($pfrom, $from);
        $prevPeriod = self::et($pfrom)->format('Y-m');
        $metrics = ['version' => 1, 'period' => $period, 'label' => Reports::monthName($period), 'prev_period' => $prevPeriod, 'prev_label' => Reports::monthName($prevPeriod), 'cur' => $cur, 'prev' => $prev, 'generated_at' => Clock::at($now)];
        $ident = 'INF-' . str_replace('-', '-', $period);
        $st = Db::run(
            "INSERT IGNORE INTO reports (kind, period, ident, metrics, actions, status, generated_by, created_at) VALUES ('monthly', ?, ?, ?, ?, 'draft', ?, ?)",
            [$period, $ident, json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), json_encode(self::suggest($cur, $prev, $now), JSON_UNESCAPED_UNICODE), $userId, Clock::at($now)]
        );
        $row = self::findMonthly($period);
        if ($st->rowCount() !== 1 || $row === null) {
            return ['ok' => true, 'msg' => 'El informe ya existía.', 'id' => $row !== null ? (int) $row['id'] : null, 'created' => false];
        }
        ActivityLog::record('report_generate', $userId, 'report', (int) $row['id'], ['kind' => 'monthly', 'period' => $period]);
        return ['ok' => true, 'msg' => 'Informe de ' . Reports::monthName($period) . ' generado como borrador (' . $ident . ').', 'id' => (int) $row['id'], 'created' => true];
    }

    /** @param list<string> $actions @return array{ok:bool,msg:string} */
    public static function saveActions(int $id, array $actions, int $userId): array
    {
        $r = Db::one('SELECT id, status, kind FROM reports WHERE id = ?', [$id]);
        if ($r === null || $r['kind'] !== 'monthly') {
            return ['ok' => false, 'msg' => 'Ese informe no existe.'];
        }
        if ($r['status'] !== 'draft') {
            return ['ok' => false, 'msg' => 'Un informe publicado ya no se edita.'];
        }
        $clean = [];
        foreach ($actions as $a) {
            $a = trim((string) preg_replace('/[^\P{C}]/u', '', (string) preg_replace('/\s+/', ' ', is_string($a) ? $a : '')));
            if ($a !== '') {
                $clean[] = mb_substr($a, 0, 220);
            }
        }
        $clean = array_slice($clean, 0, 3);
        if (count($clean) < 1) {
            return ['ok' => false, 'msg' => 'Escribe al menos una acción para el mes siguiente.'];
        }
        Db::update('reports', $id, ['actions' => json_encode($clean, JSON_UNESCAPED_UNICODE)]);
        ActivityLog::record('report_actions', $userId, 'report', $id, ['n' => count($clean)]);
        return ['ok' => true, 'msg' => 'Acciones guardadas.'];
    }

    /** Publica (una vez) y envia el enlace a los destinatarios. @return array{ok:bool,msg:string} */
    public static function publish(int $id, int $userId): array
    {
        $r = Db::one('SELECT * FROM reports WHERE id = ?', [$id]);
        if ($r === null || $r['kind'] !== 'monthly') {
            return ['ok' => false, 'msg' => 'Ese informe no existe.'];
        }
        if ($r['status'] === 'published') {
            return ['ok' => true, 'msg' => 'Ese informe ya estaba publicado.'];
        }
        if (Db::run("UPDATE reports SET status = 'published', published_at = ?, published_by = ? WHERE id = ? AND status = 'draft'", [Clock::now(), $userId, $id])->rowCount() !== 1) {
            return ['ok' => true, 'msg' => 'Ese informe ya estaba publicado.'];
        }
        ActivityLog::record('report_publish', $userId, 'report', $id, ['period' => $r['period']]);
        $sent = self::sendPublished($id, $userId, false);
        return ['ok' => true, 'msg' => 'Informe publicado: el lector ya lo ve. ' . $sent['msg']];
    }

    /** Correo con el enlace al informe publicado. Sin $force no repite el envio. @return array{ok:bool,msg:string,sent:int} */
    public static function sendPublished(int $id, ?int $userId, bool $force): array
    {
        $r = Db::one("SELECT * FROM reports WHERE id = ? AND kind = 'monthly' AND status = 'published'", [$id]);
        if ($r === null) {
            return ['ok' => false, 'msg' => 'El informe no está publicado.', 'sent' => 0];
        }
        if ($r['sent_at'] !== null && !$force) {
            return ['ok' => true, 'msg' => 'El enlace ya se había enviado.', 'sent' => 0];
        }
        $to = Sequences::mailSettings()['monthly_to'];
        if (!$to) {
            return ['ok' => true, 'msg' => 'No hay destinatarios configurados para el enlace (Configuración > Correo).', 'sent' => 0];
        }
        $m = json_decode((string) $r['metrics'], true);
        $c = $m['cur'] ?? [];
        $link = Url::base() . '/informes/' . $id;
        $subject = 'Informe de captación de ' . ($m['label'] ?? $r['period']) . ' · NorthStar';
        $text = "Ya está disponible el informe de captación de " . ($m['label'] ?? $r['period']) . ".\n\nRecibidos: " . (int) ($c['received'] ?? 0) . "\nCalificados: " . (int) ($c['qualified'] ?? 0) . "\nPresentados: " . (int) ($c['presented'] ?? 0) . "\nFirmados: " . (int) ($c['signed'] ?? 0)
            . "\n\nÁbrelo (necesitas tu cuenta de lector): " . $link . "\n";
        $n = 0;
        foreach ($to as $addr) {
            try {
                Mailer::deliver(['to' => $addr, 'subject' => $subject, 'text' => $text, 'from_name' => 'NorthStar Captación', 'reply_to' => Mailer::to()]);
                $n++;
            } catch (\Throwable $t) {
                Storage::error('report email failed', $t->getMessage());
            }
        }
        if ($n > 0) {
            Db::update('reports', $id, ['sent_at' => Clock::now()]);
            ActivityLog::record('report_send', $userId, 'report', $id, ['recipients' => $n]);
        }
        return ['ok' => $n > 0, 'msg' => $n > 0 ? 'Enlace enviado a ' . $n . ' destinatario' . ($n === 1 ? '' : 's') . '.' : 'No se pudo enviar el enlace (revisa el SMTP).', 'sent' => $n];
    }

    /** Aviso a Carlos de que hay un borrador listo. Una sola vez (notified_at). */
    private static function notifyDraft(array $r): bool
    {
        if ($r['notified_at'] !== null) {
            return false;
        }
        $to = self::adminTo();
        if ($to === '') {
            return false;
        }
        $link = Url::base() . '/informes/' . (int) $r['id'];
        $m = json_decode((string) $r['metrics'], true);
        try {
            Mailer::deliver(['to' => $to, 'subject' => '[Captación] Informe de ' . ($m['label'] ?? $r['period']) . ' listo para revisar', 'from_name' => 'NorthStar captacion',
                'text' => "Se generó el informe mensual de " . ($m['label'] ?? $r['period']) . " (" . $r['ident'] . ") como borrador.\n\nRevisa las tres acciones del mes siguiente, edítalas si quieres y pulsa «Publicar» para que NorthStar lo vea:\n" . $link . "\n"]);
        } catch (\Throwable $t) {
            Storage::error('report draft notice failed', $t->getMessage());
            return false;
        }
        Db::update('reports', (int) $r['id'], ['notified_at' => Clock::now()]);
        return true;
    }

    public static function adminTo(): string
    {
        $w = Sequences::mailSettings()['weekly_to'];
        return $w !== '' ? $w : Mailer::to();
    }

    // --- Resumen semanal ----------------------------------------------------------------------------------------------------------------------

    /** 'AAAA-Www' de la semana ISO que empieza el lunes $weekStart. */
    public static function weekKey(int $weekStart): string
    {
        return self::et($weekStart)->format('o-\WW');
    }

    /** Construye y guarda (una vez) el resumen de la semana que empieza en $weekStart. @return ?array fila de reports (null si ya existia) */
    public static function buildWeekly(int $weekStart, int $now): ?array
    {
        $key = self::weekKey($weekStart);
        if (Db::one("SELECT id FROM reports WHERE kind = 'weekly' AND period = ?", [$key]) !== null) {
            return null;
        }
        $to = self::et($weekStart)->modify('+7 days')->setTime(0, 0, 0)->getTimestamp();
        $pfrom = self::et($weekStart)->modify('-7 days')->setTime(0, 0, 0)->getTimestamp();
        $cur = Reports::compute($weekStart, $to);
        $prev = Reports::compute($pfrom, $weekStart);
        $hot = Db::one("SELECT COUNT(*) AS n, MIN(created_at) AS oldest FROM leads WHERE deleted_at IS NULL AND temperature = 'hot' AND first_contact_at IS NULL AND stage = 'new'");
        $late = (int) Db::value('SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL AND done_at IS NULL AND due_at IS NOT NULL AND due_at < ?', [Clock::at($now)]);
        $rows = array_values(array_filter($cur['channels']['rows'], static fn($r) => $r['leads'] > 0));
        $best = $worst = null;
        if ($rows) {
            usort($rows, static fn($a, $b) => [$b['signed'], $b['leads']] <=> [$a['signed'], $a['leads']]);
            $best = ['label' => $rows[0]['label'], 'leads' => $rows[0]['leads'], 'signed' => $rows[0]['signed']];
            $w = $rows[count($rows) - 1];
            $worst = count($rows) > 1 ? ['label' => $w['label'], 'leads' => $w['leads'], 'signed' => $w['signed']] : null;
        }
        $metrics = [
            'version' => 1, 'week' => $key, 'from' => gmdate('c', $weekStart), 'to' => gmdate('c', $to), 'received' => $cur['received'], 'prev_received' => $prev['received'], 'hotwarm' => $cur['hotwarm'],
            'signed' => $cur['signed'], 'signed_in_period' => $cur['signed_in_period'], 'presented' => $cur['presented'], 'hot_waiting' => (int) $hot['n'], 'hot_oldest' => $hot['oldest'], 'overdue_tasks' => $late,
            'best_channel' => $best, 'worst_channel' => $worst, 'actions' => self::suggest($cur, $prev, $now),
        ];
        Db::run("INSERT IGNORE INTO reports (kind, period, ident, metrics, actions, status, created_at) VALUES ('weekly', ?, ?, ?, ?, 'draft', ?)", [$key, 'RES-' . $key, json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), json_encode($metrics['actions'], JSON_UNESCAPED_UNICODE), Clock::at($now)]);
        return Db::one("SELECT * FROM reports WHERE kind = 'weekly' AND period = ?", [$key]);
    }

    /** Cuerpo del correo semanal. */
    public static function weeklyText(array $m): string
    {
        $a = Fmt::et((int) strtotime((string) $m['from']));
        $b = Fmt::et((int) strtotime((string) $m['to']) - 1);
        $out = 'Resumen de la semana del ' . Fmt::dayLabel($a) . ' al ' . Fmt::dayLabel($b) . " (hora ET)\n" . str_repeat('=', 44) . "\n\n"
            . 'Leads recibidos: ' . $m['received'] . ' (semana anterior: ' . $m['prev_received'] . ")\n"
            . 'Con puntuación alta (calientes y tibios): ' . $m['hotwarm'] . "\n"
            . 'Presentados a NorthStar: ' . $m['presented'] . "\n"
            . 'Firmados esta semana (por fecha de firma): ' . $m['signed_in_period'] . "\n\n"
            . 'Calientes sin contactar AHORA: ' . $m['hot_waiting'] . ($m['hot_waiting'] > 0 && $m['hot_oldest'] ? ' (el más antiguo espera ' . Fmt::dur(Fmt::secondsBetween((string) $m['hot_oldest'], Clock::now())) . ')' : '') . "\n"
            . 'Tareas vencidas AHORA: ' . $m['overdue_tasks'] . "\n\n"
            . 'Mejor canal: ' . ($m['best_channel'] ? $m['best_channel']['label'] . ' (' . $m['best_channel']['leads'] . ' leads, ' . $m['best_channel']['signed'] . ' firmados)' : 'sin leads esta semana') . "\n"
            . 'Peor canal: ' . ($m['worst_channel'] ? $m['worst_channel']['label'] . ' (' . $m['worst_channel']['leads'] . ' leads, ' . $m['worst_channel']['signed'] . ' firmados)' : 'no hay otro canal con leads para comparar') . "\n\n"
            . "Tres acciones sugeridas (reglas simples sobre tus datos):\n";
        foreach ($m['actions'] as $i => $x) {
            $out .= ($i + 1) . '. ' . $x . "\n";
        }
        return $out . "\nReportes completos: " . Url::base() . "/reportes\n";
    }

    // --- Cron ------------------------------------------------------------------------------------------------------------------------------------

    /**
     * Decide que toca ahora (hora ET). Idempotente: se puede llamar cada hora o cada minuto.
     * @return array{weekly:string,monthly:string,notified:string}
     */
    public static function tick(?int $now = null): array
    {
        $now ??= Clock::time();
        $out = ['weekly' => 'nada que hacer', 'monthly' => 'nada que hacer', 'notified' => '—'];
        $et = self::et($now);
        // A13: lunes desde las 8:00 hasta el martes 23:59 (recuperacion si el cron no corrio a las 8)
        $monday = $et->modify('-' . ((int) $et->format('N') - 1) . ' days')->setTime(8, 0, 0);
        $until = $monday->modify('+2 days')->setTime(0, 0, 0);
        if ($now >= $monday->getTimestamp() && $now < $until->getTimestamp()) {
            $weekStart = $monday->modify('-7 days')->setTime(0, 0, 0)->getTimestamp();
            $existing = Db::one("SELECT * FROM reports WHERE kind = 'weekly' AND period = ?", [self::weekKey($weekStart)]);
            if ($existing === null || $existing['notified_at'] === null) {
                $row = $existing ?? self::buildWeekly($weekStart, $now);
                $to = self::adminTo();
                if ($row !== null && $to !== '') {
                    $m = json_decode((string) $row['metrics'], true);
                    try {
                        Mailer::deliver(['to' => $to, 'subject' => '[Captación] Resumen semanal · ' . Fmt::dayLabel(Fmt::et($weekStart)) . ' al ' . Fmt::dayLabel(Fmt::et($weekStart)->modify('+6 days')), 'text' => self::weeklyText($m), 'from_name' => 'NorthStar captacion']);
                        Db::update('reports', (int) $row['id'], ['notified_at' => Clock::now()]);
                        ActivityLog::record('report_weekly_sent', null, 'report', (int) $row['id'], ['period' => $row['period']]);
                        $out['weekly'] = 'enviado ' . $row['period'];
                    } catch (\Throwable $t) {
                        Storage::error('weekly summary failed', $t->getMessage());
                        $out['weekly'] = 'falló el envío (se reintenta en la próxima pasada)';
                    }
                } else {
                    $out['weekly'] = $row === null ? 'ya existía' : 'sin destinatario (MAIL_TO)';
                }
            } else {
                $out['weekly'] = 'ya enviado ' . $existing['period'];
            }
        }
        // A14: del dia 1 al 5, desde la 1:00 a. m. ET, el informe del mes anterior
        if ((int) $et->format('j') <= 5 && ((int) $et->format('j') > 1 || (int) $et->format('G') >= 1)) {
            $prev = self::previousMonth($now);
            $g = self::generateMonthly($prev, null, $now);
            $out['monthly'] = $g['created'] ? 'generado ' . $prev : 'ya existía ' . $prev;
            $row = self::findMonthly($prev);
            if ($row !== null && $row['status'] === 'draft' && self::notifyDraft($row)) {
                $out['notified'] = 'borrador avisado a Carlos';
            }
        }
        return $out;
    }
}
