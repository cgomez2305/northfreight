<?php
/**
 * Lista de supresion (fase AM): numeros y correos a los que NO se debe volver a escribir aunque aun no sean lead (STOP por SMS de un desconocido) o ya no lo sean
 * (borrado a peticion del titular).
 *
 * DECISION: tabla propia `suppression` y no `dnc_numbers`, porque hace falta guardar tambien correos y porque un borrado a peticion no puede dejar el telefono ni el correo
 * en claro. Se guarda SOLO un HMAC-SHA256 del valor normalizado (telefono E.164, correo en minusculas) con una clave derivada de APP_KEY ("suppress|v1"): quien lea la base
 * no recupera el dato, pero el motor puede comprobar si un lead nuevo coincide. Si APP_KEY cambia, los hashes dejan de coincidir (documentado en docs/fase-am-datos.md).
 * `dnc_numbers` sigue siendo la lista de «no llamar» de los prospectos FMCSA y no se toca.
 *
 * Razones: sms_stop (el titular respondio STOP por SMS: solo bloquea SMS/WhatsApp) y erasure (borrado a peticion: bloquea TODO contacto). Una razon `erasure` nunca se rebaja a
 * `sms_stop`. START por SMS quita solo las de razon sms_stop.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Db;

final class Suppression
{
    public const SMS_STOP = 'sms_stop';
    public const ERASURE = 'erasure';
    /** Baja de un clic de los correos en lote a prospectos (fase AQ). Solo frena correos a prospectos; un lead que después da su consentimiento en el formulario no se ve afectado. */
    public const EMAIL_UNSUB = 'email_unsub';

    /** Valor normalizado o null si no hay nada que guardar. */
    public static function normalize(string $kind, ?string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }
        if ($kind === 'phone') {
            $p = Phone::normalize($v);
            return $p['ok'] ? (string) $p['e164'] : null;
        }
        $v = strtolower($v);
        return filter_var($v, FILTER_VALIDATE_EMAIL) !== false ? $v : null;
    }

    public static function hash(string $kind, string $normalized): string
    {
        return hash_hmac('sha256', $kind . '|' . $normalized, Crypto::key() . 'suppress|v1');
    }

    /** Anade (o endurece) una supresion. @return bool true si el valor era valido */
    public static function add(string $kind, ?string $value, string $reason, ?int $leadId = null, ?int $userId = null): bool
    {
        $n = self::normalize($kind, $value);
        if ($n === null || !in_array($reason, [self::SMS_STOP, self::ERASURE, self::EMAIL_UNSUB], true)) {
            return false;
        }
        Db::run(
            "INSERT INTO suppression (kind, value_hash, reason, source_lead_id, created_by) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE reason = IF(reason = 'erasure', 'erasure', VALUES(reason)), source_lead_id = COALESCE(source_lead_id, VALUES(source_lead_id))",
            [$kind, self::hash($kind, $n), $reason, $leadId, $userId]
        );
        return true;
    }

    /** START por SMS: quita el telefono si la razon era sms_stop. @return bool true si se quito algo */
    public static function removeSmsStop(?string $phone): bool
    {
        $n = self::normalize('phone', $phone);
        if ($n === null) {
            return false;
        }
        return Db::run("DELETE FROM suppression WHERE kind = 'phone' AND value_hash = ? AND reason = 'sms_stop'", [self::hash('phone', $n)])->rowCount() > 0;
    }

    /** @return ?string razon de la supresion (sms_stop|erasure) o null */
    public static function reason(string $kind, ?string $value): ?string
    {
        $n = self::normalize($kind, $value);
        if ($n === null) {
            return null;
        }
        $r = Db::value('SELECT reason FROM suppression WHERE kind = ? AND value_hash = ?', [$kind, self::hash($kind, $n)]);
        return is_string($r) ? $r : null;
    }

    /**
     * Efecto de la lista sobre un lead entrante.
     * @return array{reason:?string,flags:list<string>,drop:list<string>,erased:bool} drop = canales de consentimiento que NO se conceden
     */
    public static function check(?string $phone, ?string $email): array
    {
        $rp = self::reason('phone', $phone);
        $re = self::reason('email', $email);
        if ($rp === self::ERASURE || $re === self::ERASURE) {
            return ['reason' => self::ERASURE, 'flags' => ['stop'], 'drop' => ['call', 'sms', 'email', 'whatsapp'], 'erased' => true];
        }
        if ($rp === self::SMS_STOP) {
            return ['reason' => self::SMS_STOP, 'flags' => ['stop_sms', 'stop_whatsapp'], 'drop' => ['sms', 'whatsapp'], 'erased' => false];
        }
        return ['reason' => null, 'flags' => [], 'drop' => [], 'erased' => false];
    }

    /** ¿El telefono esta borrado a peticion? (filtro de los prospectos FMCSA y de la importacion). */
    public static function isErased(?string $phone, ?string $email = null): bool
    {
        return self::reason('phone', $phone) === self::ERASURE || self::reason('email', $email) === self::ERASURE;
    }

    /** ¿Hay algo en la lista para este telefono o correo (cualquier razon)? */
    public static function isListed(?string $phone, ?string $email = null): bool
    {
        return self::reason('phone', $phone) !== null || self::reason('email', $email) !== null;
    }

    /** Conjunto de telefonos E.164 borrados a peticion entre los dados (para filtrar listas grandes sin una consulta por telefono). @param list<string> $phones @return array<string,true> */
    public static function erasedPhones(array $phones): array
    {
        $hashes = [];
        foreach ($phones as $p) {
            $n = self::normalize('phone', $p);
            if ($n !== null) {
                $hashes[self::hash('phone', $n)] = $n;
            }
        }
        $out = [];
        foreach (array_chunk(array_keys($hashes), 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach (Db::all("SELECT value_hash FROM suppression WHERE kind = 'phone' AND reason = 'erasure' AND value_hash IN ($in)", $chunk) as $r) {
                $out[$hashes[(string) $r['value_hash']]] = true;
            }
        }
        return $out;
    }
}
