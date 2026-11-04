<?php
/** Paso 3 del motor: busca un lead existente por telefono E.164 y, si no, por correo (sin borrados). Prefiere el mas reciente. */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;

final class Dedupe
{
    public const REOPEN_AFTER_DAYS = 90;

    public static function find(?string $phoneE164, ?string $email): ?array
    {
        if ($phoneE164 !== null && $phoneE164 !== '') {
            $row = Db::one('SELECT * FROM leads WHERE phone_e164 = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$phoneE164]);
            if ($row !== null) {
                return Lead::hydrate($row);
            }
        }
        if ($email !== null && $email !== '') {
            $row = Db::one('SELECT * FROM leads WHERE email = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$email]);
            if ($row !== null) {
                return Lead::hydrate($row);
            }
        }
        return null;
    }

    /** Ya entregado/cerrado como firmado (o contratado). */
    public static function isSigned(array $lead): bool
    {
        return ($lead['signed_at'] ?? null) !== null || ($lead['handoff_status'] ?? null) === 'signed' || in_array($lead['stage'] ?? '', ['signed', 'hired'], true);
    }

    /** Perdido hace mas de 90 dias. */
    public static function isReopenable(array $lead): bool
    {
        if (($lead['stage'] ?? '') !== 'lost' || ($lead['lost_at'] ?? null) === null) {
            return false;
        }
        $ts = Clock::parse((string) $lead['lost_at']);
        return $ts !== null && $ts <= Clock::time() - self::REOPEN_AFTER_DAYS * 86400;
    }
}
