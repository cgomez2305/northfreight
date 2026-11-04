<?php
/**
 * Enriquecimiento con la API QCMobile de FMCSA (doc §5.4).
 *
 *   GET {BASE}/carriers/{usdot}?webKey=KEY            -> content.carrier {...}
 *   GET {BASE}/carriers/{usdot}/authority?webKey=KEY  -> content[] {carrierAuthority: {...}}   (fecha de concesion; opcional: si falla no se pierde el resto)
 *
 * !!! NO PROBADO CONTRA LA API REAL (aqui no hay webKey). Los nombres de campo de abajo salen de la documentacion publica/respuestas conocidas de QCMobile y estan
 * !!! concentrados en las constantes F_* / AUTH_DATE_KEYS para ajustarlos cuando se pruebe con una llave real. Los tests usan fixtures (tests/fixtures/fmcsa/*.json).
 *
 * Lo que se guarda en leads.fmcsa_data (normalizado):
 *   found, usdot, legal_name, dba, state, allowed_to_operate (bool), authority_status ('active'|'inactive'|'none'|'unknown'), authority_granted_at (Y-m-d|null),
 *   authority_age_months, insured (bool|null), bipd_on_file (miles de USD), power_units, drivers, driver_oos_rate, vehicle_oos_rate, safety_rating, red_flags[], checked_at
 * Banderas rojas: 'authority_inactive' (no autorizado a operar o autoridad inactiva) y 'no_insurance' (seguro BIPD registrado = 0).
 *
 * Cliente HTTP inyectable: callable(string $url): array{status:int, body:string}; puede lanzar. Sin inyectar usa Services\Http (timeout 5 s).
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Env;

final class Fmcsa
{
    public const DEFAULT_BASE = 'https://mobile.fmcsa.dot.gov/qc/services';

    // --- Campos de la respuesta (AJUSTAR con llave real) ---
    public const F_ALLOWED      = 'allowedToOperate';        // "Y" | "N"
    public const F_COMMON       = 'commonAuthorityStatus';   // "A" activa | "I" inactiva | "N" ninguna
    public const F_CONTRACT     = 'contractAuthorityStatus';
    public const F_LEGAL_NAME   = 'legalName';
    public const F_DBA          = 'dbaName';
    public const F_STATE        = 'phyState';
    public const F_BIPD_FILE    = 'bipdInsuranceOnFile';     // miles de USD registrados
    public const F_UNITS        = 'totalPowerUnits';
    public const F_DRIVERS      = 'totalDrivers';
    public const F_DRIVER_OOS   = 'driverOosRate';           // porcentaje
    public const F_VEHICLE_OOS  = 'vehicleOosRate';
    public const F_SAFETY       = 'safetyRating';
    public const AUTH_DATE_KEYS = ['authorityGrantDate', 'commonAuthorityGrantDate', 'grantDate', 'commonAuthorityDate'];

    /** @var callable|null */
    private $http;

    public function __construct(?callable $http = null, private ?string $webKey = null, private ?string $base = null)
    {
        $this->http = $http;
        $this->webKey ??= Env::get('FMCSA_WEBKEY');
        $this->base ??= rtrim(Env::get('FMCSA_API_BASE', self::DEFAULT_BASE) ?? self::DEFAULT_BASE, '/');
    }

    public function configured(): bool
    {
        return $this->webKey !== null && $this->webKey !== '';
    }

    /**
     * @return array normalizado (ver arriba); ['found' => false, ...] si el USDOT no existe.
     * @throws FmcsaUnavailable sin llave, llave rechazada, 429/5xx o fallo de red (reintentable)
     */
    public function lookup(string $usdot): array
    {
        if (!$this->configured()) {
            throw new FmcsaUnavailable('FMCSA_WEBKEY sin configurar');
        }
        if (preg_match('/^\d{1,8}$/', $usdot) !== 1) {
            return ['found' => false, 'usdot' => $usdot, 'checked_at' => Clock::now(), 'red_flags' => []];
        }
        $carrier = $this->get('/carriers/' . $usdot);
        if ($carrier === null) {
            return ['found' => false, 'usdot' => $usdot, 'checked_at' => Clock::now(), 'red_flags' => []];
        }
        $authority = null;
        try {
            $authority = $this->get('/carriers/' . $usdot . '/authority');
        } catch (FmcsaUnavailable) {
            $authority = null;   // la fecha de concesion es un extra: sin ella igual puntuamos lo demas
        }
        return self::normalize($usdot, $carrier, $authority);
    }

    /** GET y decodificacion. null = no existe (404 o contenido vacio). */
    private function get(string $path): ?array
    {
        $url = $this->base . $path . '?webKey=' . rawurlencode((string) $this->webKey);
        try {
            $r = $this->http !== null ? ($this->http)($url) : Http::request('GET', $url, null, [], 5, 3);
        } catch (\Throwable $t) {
            throw new FmcsaUnavailable('FMCSA no responde: ' . Storage::redact($t->getMessage()));
        }
        $status = (int) ($r['status'] ?? 0);
        if (isset($r['error']) && $r['error'] !== '' && $status === 0) {
            throw new FmcsaUnavailable('FMCSA no responde: ' . $r['error']);
        }
        if ($status === 404) {
            return null;
        }
        if ($status === 401 || $status === 403) {
            throw new FmcsaUnavailable('FMCSA rechazo la webKey (HTTP ' . $status . ')');
        }
        if ($status !== 200) {
            throw new FmcsaUnavailable('FMCSA respondio HTTP ' . $status);
        }
        $json = json_decode((string) ($r['body'] ?? ''), true);
        if (!is_array($json)) {
            throw new FmcsaUnavailable('FMCSA devolvio una respuesta que no es JSON');
        }
        $content = $json['content'] ?? null;
        return ($content === null || $content === [] || $content === '') ? null : $json;
    }

    /** Normaliza las respuestas crudas de QCMobile. Publico y sin red: lo usan los tests con fixtures. */
    public static function normalize(string $usdot, array $carrierJson, ?array $authorityJson = null): array
    {
        $content = $carrierJson['content'] ?? [];
        $c = $content['carrier'] ?? (isset($content[0]['carrier']) ? $content[0]['carrier'] : $content);
        $c = is_array($c) ? $c : [];

        $auths = [];
        foreach ((array) ($authorityJson['content'] ?? []) as $row) {
            if (is_array($row)) {
                $auths[] = $row['carrierAuthority'] ?? $row;
            }
        }
        $statuses = array_map('strtoupper', array_filter([(string) ($c[self::F_COMMON] ?? ''), (string) ($c[self::F_CONTRACT] ?? '')]));
        foreach ($auths as $a) {
            foreach ([self::F_COMMON, self::F_CONTRACT] as $k) {
                if (isset($a[$k]) && $a[$k] !== '') {
                    $statuses[] = strtoupper((string) $a[$k]);
                }
            }
        }
        $allowed = strtoupper((string) ($c[self::F_ALLOWED] ?? ''));
        if ($allowed === 'N') {
            $authorityStatus = 'inactive';
        } elseif (in_array('A', $statuses, true)) {
            $authorityStatus = $allowed === 'Y' || $allowed === '' ? 'active' : 'inactive';
        } elseif (in_array('I', $statuses, true)) {
            $authorityStatus = 'inactive';
        } elseif ($statuses && count(array_unique($statuses)) === 1 && $statuses[0] === 'N') {
            $authorityStatus = 'none';
        } else {
            $authorityStatus = 'unknown';
        }

        $granted = null;
        foreach ($auths as $a) {
            foreach (self::AUTH_DATE_KEYS as $k) {
                $d = isset($a[$k]) ? self::parseDate($a[$k]) : null;
                if ($d !== null && ($granted === null || $d < $granted)) {
                    $granted = $d;   // la mas antigua
                }
            }
        }
        $ageMonths = null;
        if ($granted !== null) {
            $g = new \DateTimeImmutable($granted . ' 00:00:00 UTC');
            $n = new \DateTimeImmutable('@' . Clock::time());
            $ageMonths = $g > $n ? 0 : ((int) $n->format('Y') - (int) $g->format('Y')) * 12 + ((int) $n->format('n') - (int) $g->format('n')) - ((int) $n->format('j') < (int) $g->format('j') ? 1 : 0);
        }

        $bipd = isset($c[self::F_BIPD_FILE]) && is_numeric($c[self::F_BIPD_FILE]) ? (int) $c[self::F_BIPD_FILE] : null;
        $insured = $bipd === null ? null : $bipd > 0;

        $flags = [];
        if ($authorityStatus === 'inactive') {
            $flags[] = 'authority_inactive';
        }
        if ($insured === false) {
            $flags[] = 'no_insurance';
        }
        $num = static fn(mixed $v): ?float => is_numeric($v) ? (float) $v : null;

        return [
            'found'                => true,
            'usdot'                => $usdot,
            'legal_name'           => isset($c[self::F_LEGAL_NAME]) ? (string) $c[self::F_LEGAL_NAME] : null,
            'dba'                  => isset($c[self::F_DBA]) && $c[self::F_DBA] !== '' ? (string) $c[self::F_DBA] : null,
            'state'                => isset($c[self::F_STATE]) ? (string) $c[self::F_STATE] : null,
            'allowed_to_operate'   => $allowed === '' ? null : $allowed === 'Y',
            'authority_status'     => $authorityStatus,
            'authority_granted_at' => $granted,
            'authority_age_months' => $ageMonths,
            'insured'              => $insured,
            'bipd_on_file'         => $bipd,
            'power_units'          => isset($c[self::F_UNITS]) && is_numeric($c[self::F_UNITS]) ? (int) $c[self::F_UNITS] : null,
            'drivers'              => isset($c[self::F_DRIVERS]) && is_numeric($c[self::F_DRIVERS]) ? (int) $c[self::F_DRIVERS] : null,
            'driver_oos_rate'      => $num($c[self::F_DRIVER_OOS] ?? null),
            'vehicle_oos_rate'     => $num($c[self::F_VEHICLE_OOS] ?? null),
            'safety_rating'        => isset($c[self::F_SAFETY]) && $c[self::F_SAFETY] !== '' ? (string) $c[self::F_SAFETY] : null,
            'red_flags'            => $flags,
            'checked_at'           => Clock::now(),
        ];
    }

    /** Fecha 'Y-m-d' desde "2024-05-12", "05/12/2024", "2024-05-12T00:00:00" o epoch en milisegundos. */
    public static function parseDate(mixed $v): ?string
    {
        if (is_int($v) || (is_string($v) && preg_match('/^\d{10,13}$/', $v) === 1)) {
            $n = (int) $v;
            return gmdate('Y-m-d', $n > 99999999999 ? intdiv($n, 1000) : $n);
        }
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $v, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $v, $m) === 1) {
            return checkdate((int) $m[1], (int) $m[2], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]) : null;
        }
        return null;
    }
}
