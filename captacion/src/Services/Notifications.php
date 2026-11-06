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
        // Q4b: webhooks entrantes configurados que fallan seguido o rechazan la firma (posible clave mal puesta)
        foreach (Webhook::SOURCES as $src) {
            if (!Webhook::configured($src)) {
                continue;
            }
            $l = Integrations::last($src);
            $streak = (int) ($l['streak'] ?? 0);
            if ($streak >= 3) {
                $items[] = ['key' => 'webhook_err:' . $src . ':' . $streak, 'kind' => 'bad', 'title' => 'El webhook de ' . Webhook::TITLES[$src] . ' falla (' . $streak . ' errores seguidos)',
                    'meta' => mb_substr((string) ($l['err'] ?? ''), 0, 90), 'href' => '/config?tab=integraciones#int-' . $src];
            }
            $sig = Integrations::sigFails($src);
            if ($sig >= 5) {
                $items[] = ['key' => 'webhook_sig:' . $src . ':' . $sig, 'kind' => 'warn', 'title' => 'Firma rechazada ' . $sig . ' veces en ' . Webhook::TITLES[$src],
                    'meta' => 'Posible clave mal puesta en config/.env (o alguien probando el endpoint)', 'href' => '/config?tab=integraciones#int-' . $src];
            }
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
        // Q3: entregas a NorthStar sin destino o fallidas
        $waiting = (int) Db::value("SELECT COUNT(*) FROM handoffs WHERE confirm_status IS NULL AND status = 'no_target'");
        if ($waiting > 0) {
            $items[] = ['key' => 'handoff_waiting:' . $waiting . ':' . (int) Db::value("SELECT MAX(id) FROM handoffs WHERE confirm_status IS NULL AND status = 'no_target'"), 'kind' => 'warn',
                'title' => $waiting === 1 ? '1 lead presentado sin destino de entrega' : $waiting . ' leads presentados sin destino de entrega', 'meta' => 'Destino de entrega sin configurar', 'href' => '/config?tab=entrega'];
        }
        $hf = (int) Db::value("SELECT COUNT(*) FROM handoffs WHERE confirm_status IS NULL AND status IN ('failed','partial') AND EXISTS (SELECT 1 FROM handoff_deliveries d WHERE d.handoff_id = handoffs.id AND d.status = 'failed')");
        if ($hf > 0) {
            $items[] = ['key' => 'handoff_failed:' . $hf . ':' . (int) Db::value("SELECT MAX(id) FROM handoffs WHERE status IN ('failed','partial')"), 'kind' => 'bad',
                'title' => $hf === 1 ? '1 entrega a NorthStar falló' : $hf . ' entregas a NorthStar fallaron', 'meta' => 'Revisa el destino y usa «Reenviar entrega» en la ficha', 'href' => '/config?tab=entrega'];
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
        // Q4a: prospectos con seguimiento de hoy, cron de FMCSA, bonos por aprobar y vacantes por vencer
        $due = Prospects::followupsDue(Clock::time());
        if ($due['n'] > 0) {
            $items[] = ['key' => 'prospects_due:' . $due['n'] . ':' . $due['max_id'], 'kind' => 'warn', 'title' => $due['n'] === 1 ? '1 prospecto con seguimiento para hoy' : $due['n'] . ' prospectos con seguimiento para hoy',
                'meta' => 'Reintentos y llamadas que prometiste volver a hacer', 'href' => '/prospectos'];
        }
        $pcfg = ProspectConfig::get();
        if ($pcfg['enabled']) {
            $pcron = Settings::get('cron.prospects');
            $pat = is_array($pcron) && isset($pcron['at']) ? Clock::parse((string) $pcron['at']) : null;
            $prun = ProspectImport::state();
            if ($pat === null || Clock::time() - $pat > 10800) {
                $items[] = ['key' => 'fmcsa_cron:' . ($pat === null ? 'never' : 'late'), 'kind' => 'warn', 'title' => $pat === null ? 'El cron de prospectos FMCSA nunca se ha ejecutado' : 'El cron de prospectos FMCSA no corre',
                    'meta' => $pat === null ? 'Crea la tarea de cron de fmcsa_daily.php (cada hora)' : 'Última pasada ' . Fmt::ago((string) $pcron['at']), 'href' => '/config?tab=integraciones'];
            } elseif (is_array($prun) && !empty($prun['error'])) {
                $items[] = ['key' => 'fmcsa_error:' . substr(md5((string) $prun['error']), 0, 8), 'kind' => 'bad', 'title' => 'La búsqueda de prospectos FMCSA falló', 'meta' => mb_substr((string) $prun['error'], 0, 90), 'href' => '/config?tab=integraciones'];
            }
        }
        $bonus = Referrals::pendingApproval();
        if ($bonus > 0) {
            $items[] = ['key' => 'bonus_pending:' . $bonus . ':' . (int) Db::value("SELECT MAX(id) FROM referrals WHERE bonus_status = 'pending'"), 'kind' => 'hot', 'title' => $bonus === 1 ? '1 bono de referido por aprobar' : $bonus . ' bonos de referido por aprobar',
                'meta' => 'Un referido firmó', 'href' => '/referidos?f=pending'];
        }
        $soon = (int) Db::value("SELECT COUNT(*) FROM job_postings WHERE deleted_at IS NULL AND status = 'active' AND valid_through >= ? AND valid_through <= ?", [JobPostings::today(), (new \DateTimeImmutable(JobPostings::today()))->modify('+7 days')->format('Y-m-d')]);
        if ($soon > 0) {
            $items[] = ['key' => 'jobs_expiring:' . $soon . ':' . JobPostings::today(), 'kind' => 'warn', 'title' => $soon === 1 ? '1 vacante vence esta semana' : $soon . ' vacantes vencen esta semana', 'meta' => 'Renueva la fecha o déjala vencer', 'href' => '/vacantes'];
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
