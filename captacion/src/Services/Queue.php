<?php
/**
 * Cola de trabajos en base de datos (doc §3). La procesa cron/process_jobs.php cada minuto.
 *
 * Estados: pending -> running -> done. Si el handler lanza una excepcion: reintento a los 1, 5 y 15 minutos (3 reintentos, 4 ejecuciones en total)
 * y luego failed (queda para revision en el panel). parked = registrado y NO ejecutado (sms_backup_alert, pendiente de Q4).
 * last_error se trunca a 480 caracteres y se pasa por Storage::redact() (nunca guarda tokens).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class Queue
{
    /** Espera (segundos) tras el fallo n-esimo: 1 min, 5 min, 15 min. */
    public const BACKOFF = [60, 300, 900];
    public const STALE_AFTER = 600;   // un job 'running' mas de 10 min = el proceso murio: vuelve a pending

    /**
     * Encola un job. $dedupeKey evita duplicados (INSERT IGNORE sobre la clave unica). Devuelve el id, o null si ya existia.
     * $status 'parked' lo registra sin ejecutarlo.
     */
    public static function push(string $type, array $payload = [], ?string $runAt = null, ?string $dedupeKey = null, string $status = 'pending', ?string $note = null): ?int
    {
        $st = Db::run(
            'INSERT IGNORE INTO jobs (type, payload, status, attempts, run_at, dedupe_key, last_error) VALUES (?, ?, ?, 0, ?, ?, ?)',
            [$type, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, $runAt ?? Clock::now(), $dedupeKey, $note]
        );
        return $st->rowCount() === 1 ? (int) Db::pdo()->lastInsertId() : null;
    }

    /**
     * Procesa jobs vencidos hasta $maxSeconds. $handlers: tipo => callable(array $payload, array $job): void.
     * @return array{processed:int,done:int,retried:int,failed:int,seconds:float}
     */
    public static function runBatch(array $handlers, int $maxSeconds = 50, int $batchSize = 20): array
    {
        $t0 = microtime(true);
        $stats = ['processed' => 0, 'done' => 0, 'retried' => 0, 'failed' => 0, 'seconds' => 0.0];
        self::recoverStale();

        while (microtime(true) - $t0 < $maxSeconds) {
            $rows = Db::all("SELECT id FROM jobs WHERE status = 'pending' AND run_at <= ? ORDER BY run_at, id LIMIT " . (int) $batchSize, [Clock::now()]);
            if (!$rows) {
                break;
            }
            foreach ($rows as $r) {
                if (microtime(true) - $t0 >= $maxSeconds) {
                    break 2;
                }
                $job = self::claim((int) $r['id']);
                if ($job === null) {
                    continue;   // otro proceso lo tomo
                }
                $stats['processed']++;
                $outcome = self::execute($job, $handlers);
                $stats[$outcome]++;
            }
        }
        $stats['seconds'] = round(microtime(true) - $t0, 2);
        try {
            Settings::set('cron.process_jobs', ['at' => Clock::now(), 'processed' => $stats['processed'], 'done' => $stats['done'], 'retried' => $stats['retried'], 'failed' => $stats['failed'], 'seconds' => $stats['seconds']]);
        } catch (\Throwable $t) {
            Storage::error('queue heartbeat failed', $t->getMessage());
        }
        return $stats;
    }

    /** Toma un job pending de forma atomica. Devuelve la fila (con attempts ya incrementado) o null. */
    private static function claim(int $id): ?array
    {
        $st = Db::run("UPDATE jobs SET status = 'running', attempts = attempts + 1, started_at = ? WHERE id = ? AND status = 'pending'", [Clock::now(), $id]);
        return $st->rowCount() === 1 ? Db::one('SELECT * FROM jobs WHERE id = ?', [$id]) : null;
    }

    /** @return 'done'|'retried'|'failed' */
    private static function execute(array $job, array $handlers): string
    {
        $id = (int) $job['id'];
        $attempt = (int) $job['attempts'];
        try {
            $handler = $handlers[$job['type']] ?? null;
            if (!is_callable($handler)) {
                throw new PermanentJobFailure('Tipo de job desconocido: ' . $job['type']);
            }
            $handler(Db::decode($job['payload']), $job);
            Db::run("UPDATE jobs SET status = 'done', finished_at = ?, last_error = NULL WHERE id = ?", [Clock::now(), $id]);
            return 'done';
        } catch (\Throwable $t) {
            $msg = mb_substr(Storage::redact($t instanceof \RuntimeException ? $t->getMessage() : get_class($t) . ': ' . $t->getMessage()), 0, 480);
            if ($t instanceof PermanentJobFailure || $attempt > count(self::BACKOFF)) {
                Db::run("UPDATE jobs SET status = 'failed', finished_at = ?, last_error = ? WHERE id = ?", [Clock::now(), $msg, $id]);
                Storage::error('job failed', '#' . $id . ' ' . $job['type'] . ' :: ' . $msg);
                return 'failed';
            }
            $wait = self::BACKOFF[$attempt - 1];
            Db::run("UPDATE jobs SET status = 'pending', run_at = ?, last_error = ? WHERE id = ?", [Clock::shift($wait), $msg, $id]);
            return 'retried';
        }
    }

    public static function recoverStale(): int
    {
        return Db::run("UPDATE jobs SET status = 'pending', last_error = 'Recuperado: el proceso anterior no termino' WHERE status = 'running' AND started_at < ?", [Clock::shift(-self::STALE_AFTER)])->rowCount();
    }

    /** Estado para el panel. */
    public static function stats(): array
    {
        $counts = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'parked' => 0];
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM jobs GROUP BY status') as $r) {
            $counts[(string) $r['status']] = (int) $r['n'];
        }
        $due = (int) Db::value("SELECT COUNT(*) FROM jobs WHERE status = 'pending' AND run_at <= ?", [Clock::now()]);
        return [
            'counts'      => $counts,
            'due'         => $due,
            'last_run'    => Settings::get('cron.process_jobs'),
            'recent_fail' => Db::all("SELECT id, type, attempts, last_error, finished_at FROM jobs WHERE status = 'failed' ORDER BY finished_at DESC, id DESC LIMIT 5"),
        ];
    }
}
