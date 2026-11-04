<?php
/** Conexion PDO unica (MySQL 8 / MariaDB 10.11). Zona horaria de sesion UTC, errores como excepciones, sentencias preparadas nativas. */

declare(strict_types=1);

namespace Captacion;

use PDO;
use PDOStatement;
use RuntimeException;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect();
        }
        return self::$pdo;
    }

    public static function setPdo(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function close(): void
    {
        self::$pdo = null;
    }

    public static function dsn(?string $dbName = null): string
    {
        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $name = $dbName ?? Env::get('DB_NAME', '');
        return 'mysql:host=' . $host . ';port=' . $port . ($name !== '' ? ';dbname=' . $name : '') . ';charset=utf8mb4';
    }

    public static function connect(?string $dbName = null, ?int $timeout = null): PDO
    {
        $name = $dbName ?? Env::get('DB_NAME', '');
        $user = Env::get('DB_USER');
        if ($user === null || ($dbName === null && $name === '')) {
            throw new RuntimeException('Base de datos sin configurar (DB_NAME / DB_USER).');
        }
        $pdo = new PDO(self::dsn($name), $user, Env::get('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => $timeout ?? Env::int('DB_TIMEOUT', 3),
        ]);
        $pdo->exec("SET NAMES utf8mb4, time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }

    /** Ejecuta una sentencia preparada. Los enteros PHP se enlazan como enteros (LIMIT, ids). */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $i = 1;
        foreach ($params as $k => $v) {
            $name = is_int($k) ? $i++ : (str_starts_with((string) $k, ':') ? $k : ':' . $k);
            $type = match (true) {
                is_int($v)  => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_INT,
                $v === null => PDO::PARAM_NULL,
                default     => PDO::PARAM_STR,
            };
            $st->bindValue($name, is_bool($v) ? (int) $v : $v, $type);
        }
        $st->execute();
        return $st;
    }

    /** @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** INSERT con un array columna => valor. Devuelve el id insertado. Los arrays se guardan como JSON. */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::run($sql, array_map([self::class, 'encode'], array_values($data)));
        return (int) self::pdo()->lastInsertId();
    }

    /** UPDATE por id. Devuelve filas afectadas. */
    public static function update(string $table, int $id, array $data): int
    {
        if (!$data) {
            return 0;
        }
        $sets = implode(', ', array_map(static fn($c) => '`' . $c . '` = ?', array_keys($data)));
        $st = self::run('UPDATE `' . $table . '` SET ' . $sets . ' WHERE id = ?', [...array_map([self::class, 'encode'], array_values($data)), $id]);
        return $st->rowCount();
    }

    public static function encode(mixed $v): mixed
    {
        return is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : $v;
    }

    /** Decodifica una columna JSON (string o null) a array. */
    public static function decode(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (!is_string($v) || $v === '') {
            return [];
        }
        $d = json_decode($v, true);
        return is_array($d) ? $d : [];
    }

    /** Ejecuta $fn dentro de una transaccion (reentrante: si ya hay una, solo la ejecuta). */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }
    }

    public static function lock(string $name, int $timeout = 5): bool
    {
        return (int) self::value('SELECT GET_LOCK(?, ?)', [substr($name, 0, 60), $timeout]) === 1;
    }

    public static function unlock(string $name): void
    {
        self::run('SELECT RELEASE_LOCK(?)', [substr($name, 0, 60)])->fetchAll();
    }
}
