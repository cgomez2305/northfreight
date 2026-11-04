<?php
/**
 * Migraciones numeradas (db/migrations/NNN_nombre.sql) con tabla schema_migrations. Idempotente: solo aplica las que faltan.
 * Cada archivo se divide en sentencias (respeta comillas y comentarios --); DDL no es transaccional en MySQL, asi que una migracion
 * que falla a medias queda SIN registrar y hay que revisarla a mano (el mensaje dice cual fue).
 */

declare(strict_types=1);

namespace Captacion;

use PDO;
use RuntimeException;

final class Migrator
{
    /** @return list<string> versiones aplicadas en esta ejecucion */
    public static function run(PDO $pdo, string $dir): array
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(120) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $got = (int) $pdo->query("SELECT GET_LOCK('captacion_migrate', 10)")->fetchColumn();
        if ($got !== 1) {
            throw new RuntimeException('Otra migracion esta en curso (GET_LOCK).');
        }
        $applied = [];
        try {
            $done = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
            $files = glob(rtrim($dir, '/') . '/[0-9][0-9][0-9]_*.sql') ?: [];
            sort($files);
            foreach ($files as $file) {
                $version = basename($file, '.sql');
                if (in_array($version, $done, true)) {
                    continue;
                }
                foreach (self::split((string) file_get_contents($file)) as $i => $stmt) {
                    try {
                        $pdo->exec($stmt);
                    } catch (\Throwable $t) {
                        throw new RuntimeException('Migracion ' . $version . ', sentencia #' . ($i + 1) . ' fallo: ' . $t->getMessage() . ' (queda sin registrar; revisa el estado de la base antes de repetir)');
                    }
                }
                $st = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
                $st->execute([$version]);
                $applied[] = $version;
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('captacion_migrate')")->fetchAll();
        }
        return $applied;
    }

    /** @return list<string> */
    public static function split(string $sql): array
    {
        $out = [];
        $buf = '';
        $quote = '';
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if ($quote !== '') {
                $buf .= $c;
                if ($c === '\\' && $quote !== '`' && $i + 1 < $len) {
                    $buf .= $sql[++$i];
                } elseif ($c === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl;
                $buf .= "\n";
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buf .= $c;
                continue;
            }
            if ($c === ';') {
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}
