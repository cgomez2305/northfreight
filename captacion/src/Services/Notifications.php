<?php
/**
 * Avisos de la campana: se calculan al vuelo (no hay tabla de avisos) y cada uno lleva una clave que incluye su estado ('failed_jobs:3'). "Marcar como leido"
 * guarda esa clave en notification_reads por usuario: si el estado cambia (otro trabajo fallido, otro lead caliente) la clave es otra y el aviso vuelve a salir.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;

final class Notifications
{
    /** Segundos sin pasada del cron de la cola para avisar (el cron corre cada minuto). */
    public const CRON_STALE = 180;

    /** @return list<array{key:string,kind:string,title:string,meta:string,href:string,read:bool}> ordenados por importancia */
    public static function all(int $userId): array
    {
        $items = [];
        $hotN = (int) Db::value("SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL AND temperature = 'hot' AND first_contact_at IS NULL AND stage = 'new'");
        if ($hotN > 0) {
            $old = Db::one("SELECT id, created_at FROM leads WHERE deleted_at IS NULL AND temperature = 'hot' AND first_contact_at IS NULL AND stage = 'new' ORDER BY created_at ASC, id ASC LIMIT 1");
            $maxId = (int) Db::value("SELECT MAX(id) FROM leads WHERE deleted_at IS NULL AND temperature = 'hot' AND first_contact_at IS NULL AND stage = 'new'");
            $items[] = [
                'key' => 'hot_uncontacted:' . $hotN . ':' . $maxId, 'kind' => 'hot',
                'title' => $hotN === 1 ? '1 lead caliente sin contactar' : $hotN . ' leads calientes sin contactar',
                'meta' => 'El más antiguo espera ' . Fmt::dur(Fmt::secondsBetween((string) $old['created_at'], Clock::now())),
                'href' => '/leads?t=hot&ct=none',
            ];
        }
        $failed = (int) Db::value("SELECT COUNT(*) FROM jobs WHERE status = 'failed'");
        if ($failed > 0) {
            $last = Db::one("SELECT id, type, last_error FROM jobs WHERE status = 'failed' ORDER BY id DESC LIMIT 1");
            $items[] = [
                'key' => 'failed_jobs:' . $failed . ':' . (int) $last['id'], 'kind' => 'bad',
                'title' => $failed === 1 ? '1 trabajo fallido en la cola' : $failed . ' trabajos fallidos en la cola',
                'meta' => $last['type'] . ': ' . mb_substr((string) $last['last_error'], 0, 80),
                'href' => '/#cola',
            ];
        }
        $over = Tasks::overdueCount();
        if ($over > 0) {
            $oldest = Db::one('SELECT id, due_at FROM tasks WHERE deleted_at IS NULL AND done_at IS NULL AND due_at IS NOT NULL AND due_at < ? ORDER BY due_at ASC LIMIT 1', [Clock::now()]);
            $maxId = (int) Db::value('SELECT MAX(id) FROM tasks WHERE deleted_at IS NULL AND done_at IS NULL AND due_at IS NOT NULL AND due_at < ?', [Clock::now()]);
            $items[] = [
                'key' => 'tasks_overdue:' . $over . ':' . $maxId, 'kind' => 'warn',
                'title' => $over === 1 ? '1 tarea vencida' : $over . ' tareas vencidas',
                'meta' => 'La más antigua venció ' . Fmt::ago((string) $oldest['due_at']),
                'href' => '/tareas?tab=overdue',
            ];
        }
        $bk = Backup::health();
        if ($bk['level'] !== 'ok') {
            $bst = Settings::get('cron.backup');
            $items[] = [
                'key' => 'backup:' . ($bk['failed'] ? 'failed' : ($bk['last_ok'] === null ? 'none' : 'stale')) . ':' . (is_array($bst) ? (string) ($bst['at'] ?? '') : ''), 'kind' => $bk['level'] === 'bad' ? 'bad' : 'warn',
                'title' => $bk['failed'] ? 'La última copia de seguridad falló' : ($bk['last_ok'] === null ? 'Aún no hay copias de seguridad' : 'La última copia de seguridad es antigua'),
                'meta' => $bk['msg'], 'href' => '/config?tab=copias',
            ];
        }
        if (!Telegram::configured()) {
            $items[] = ['key' => 'telegram_unconfigured', 'kind' => 'warn', 'title' => 'Telegram sin configurar', 'meta' => 'Las alertas de leads calientes no llegarán a tu teléfono', 'href' => '/#cola'];
        }
        $pending = (int) Db::value("SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL AND flags LIKE '%\"fmcsa_pending\"%'");
        $noKey = (Env::get('FMCSA_WEBKEY') ?? '') === '';
        if ($pending > 0 || $noKey) {
            $items[] = [
                'key' => 'fmcsa_pending:' . $pending . ':' . ($noKey ? 'nokey' : 'key'), 'kind' => 'warn',
                'title' => $pending > 0 ? 'FMCSA pendiente en ' . $pending . ($pending === 1 ? ' lead' : ' leads') : 'FMCSA sin llave configurada',
                'meta' => $noKey ? 'Falta FMCSA_WEBKEY en config/.env' : 'Se reintenta desde la cola',
                'href' => $pending > 0 ? '/leads?fl=fmcsa_pending' : '/#cola',
            ];
        }
        $cron = Settings::get('cron.process_jobs');
        $at = is_array($cron) && isset($cron['at']) ? Clock::parse((string) $cron['at']) : null;
        if ($at === null || Clock::time() - $at > self::CRON_STALE) {
            $items[] = [
                'key' => 'cron_stale:' . ($at === null ? 'never' : 'late'), 'kind' => 'warn', 'title' => $at === null ? 'El cron de la cola nunca se ha ejecutado' : 'El cron de la cola no corre',
                'meta' => $at === null ? 'Crea la tarea de cron de process_jobs.php' : 'Última pasada ' . Fmt::ago((string) $cron['at']), 'href' => '/#cola',
            ];
        }
        $read = array_column(Db::all('SELECT notif_key FROM notification_reads WHERE user_id = ?', [$userId]), 'notif_key');
        $read = array_flip($read);
        foreach ($items as &$i) {
            $i['read'] = isset($read[$i['key']]);
        }
        return $items;
    }

    public static function unread(array $items): int
    {
        return count(array_filter($items, static fn($i) => !$i['read']));
    }

    /** Marca como leidos los avisos vigentes (o solo las claves dadas, si coinciden con alguno vigente). */
    public static function markRead(int $userId, ?array $keys = null): int
    {
        $n = 0;
        foreach (self::all($userId) as $i) {
            if ($i['read'] || ($keys !== null && !in_array($i['key'], $keys, true))) {
                continue;
            }
            Db::run('INSERT IGNORE INTO notification_reads (user_id, notif_key, read_at) VALUES (?, ?, ?)', [$userId, $i['key'], Clock::now()]);
            $n++;
        }
        if (random_int(1, 20) === 1) {
            Db::run('DELETE FROM notification_reads WHERE read_at < ?', [Clock::shift(-90 * 86400)]);
        }
        return $n;
    }
}
