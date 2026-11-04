<?php
/** Acceso a la tabla leads: hidratacion de las columnas JSON y consultas basicas (la bandeja completa es de Q2). */

declare(strict_types=1);

namespace Captacion\Models;

use Captacion\Db;

final class Lead
{
    public const JSON_COLUMNS = ['equipment_types', 'endorsements', 'score_breakdown', 'utm', 'fmcsa_data', 'flags'];

    /** Fila con las columnas JSON ya decodificadas a array. */
    public static function hydrate(array $row): array
    {
        foreach (self::JSON_COLUMNS as $c) {
            if (array_key_exists($c, $row)) {
                $row[$c] = Db::decode($row[$c]);
            }
        }
        return $row;
    }

    public static function find(int $id, bool $lock = false): ?array
    {
        $row = Db::one('SELECT * FROM leads WHERE id = ? AND deleted_at IS NULL' . ($lock ? ' FOR UPDATE' : ''), [$id]);
        return $row === null ? null : self::hydrate($row);
    }

    /** @return list<array> los ultimos leads (sin los borrados), mas nuevo primero. */
    public static function latest(int $limit = 20): array
    {
        return Db::all('SELECT id, first_name, last_name, profile, state, temperature, score, source, stage, created_at FROM leads WHERE deleted_at IS NULL ORDER BY id DESC LIMIT ' . (int) $limit);
    }

    public static function count(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL');
    }
}
