<?php
/**
 * Fase AU: otras entradas de prospectos además de la búsqueda diaria de autoridades nuevas (ProspectImport):
 *  - addByUsdot: un carrier que el equipo encontró por su cuenta (un grupo de Facebook, un foro, una parada de camiones, alguien que lo recomendó). Se escribe el
 *    USDOT y se completa solo con el censo público de FMCSA (Socrata) y, si la tiene, la última autoridad de carga concedida.
 *  - importCsv: listas en CSV (exportadas de otra herramienta o compradas). Columnas reconocidas por su encabezado; el USDOT es obligatorio.
 * Mismos filtros de seguridad que la corrida diaria: nunca entra un teléfono de «No llamar más», borrado a petición o que ya es lead, ni un USDOT repetido.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;

final class ProspectSources
{
    public const MAX_CSV_ROWS = 2000;
    public const MAX_CSV_BYTES = 2_000_000;
    /** encabezado normalizado => columna */
    private const HEADERS = [
        'usdot' => 'usdot', 'dot' => 'usdot', 'dot_number' => 'usdot', 'usdot_number' => 'usdot', 'us_dot' => 'usdot',
        'mc' => 'mc', 'mc_number' => 'mc', 'docket' => 'mc', 'docket_number' => 'mc',
        'name' => 'name', 'legal_name' => 'name', 'company' => 'name', 'company_name' => 'name', 'carrier' => 'name', 'razon_social' => 'name',
        'dba' => 'dba', 'dba_name' => 'dba',
        'phone' => 'phone', 'telephone' => 'phone', 'phone_number' => 'phone', 'telefono' => 'phone',
        'email' => 'email', 'email_address' => 'email', 'correo' => 'email',
        'state' => 'state', 'phy_state' => 'state', 'estado' => 'state',
        'city' => 'city', 'phy_city' => 'city', 'ciudad' => 'city',
        'fleet' => 'fleet', 'power_units' => 'fleet', 'trucks' => 'fleet', 'flota' => 'fleet',
        'authority_date' => 'granted', 'granted' => 'granted', 'authority_granted' => 'granted', 'fecha_autoridad' => 'granted',
    ];

    private static function ok(string $msg, array $extra = []): array
    {
        return ['ok' => true, 'msg' => $msg] + $extra;
    }

    private static function fail(string $msg, array $extra = []): array
    {
        return ['ok' => false, 'msg' => $msg] + $extra;
    }

    /** Motivo para NO dejar entrar un teléfono (o null). */
    private static function phoneBlock(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        if (Db::value('SELECT 1 FROM dnc_numbers WHERE phone_e164 = ?', [$phone]) !== null || Suppression::isErased($phone)) {
            return 'Ese teléfono está en «No llamar más» o se borró a petición del titular: no se vuelve a listar.';
        }
        if (Db::value('SELECT id FROM leads WHERE deleted_at IS NULL AND phone_e164 = ? LIMIT 1', [$phone]) !== null) {
            return 'Ese teléfono ya es de un lead.';
        }
        return null;
    }

    /** @return array{ok:bool,msg:string,id?:int} */
    public static function addByUsdot(mixed $raw, mixed $origin, int $userId, ?SocrataClient $client = null): array
    {
        $usdot = preg_replace('/\D/', '', is_string($raw) ? $raw : '') ?? '';
        if (strlen($usdot) < 1 || strlen($usdot) > 8) {
            return self::fail('Escribe un número USDOT válido (solo dígitos, hasta 8).');
        }
        $usdot = ltrim($usdot, '0') ?: '0';
        if (($id = Db::value('SELECT id FROM prospects_fmcsa WHERE usdot = ?', [$usdot])) !== null) {
            return self::fail('Ese USDOT ya está en la lista.', ['id' => (int) $id]);
        }
        if (($lead = Db::value('SELECT id FROM leads WHERE deleted_at IS NULL AND usdot = ? LIMIT 1', [$usdot])) !== null) {
            return self::fail('Ese USDOT ya es el lead #' . (int) $lead . '.');
        }
        $client ??= new SocrataClient();
        try {
            $cr = $client->query(ProspectImport::CENSUS_DATASET, ProspectImport::censusParams([$usdot]))[0] ?? null;
            $g = $client->query(ProspectImport::GRANTS_DATASET, ['$select' => 'docket_number,usdot_number,status_change_date', '$where' => "usdot_number='" . $usdot . "' AND op_auth_type='" . ProspectImport::AUTH_TYPE . "'", '$order' => 'status_change_date DESC', '$limit' => '1'])[0] ?? null;
        } catch (SocrataUnavailable $t) {
            return self::fail('No se pudo consultar FMCSA ahora mismo (' . mb_substr($t->getMessage(), 0, 120) . '). Inténtalo en unos minutos.');
        }
        if ($cr === null) {
            return self::fail('Ese USDOT no aparece en el censo público de FMCSA.');
        }
        if (strtoupper((string) ($cr['status_code'] ?? '')) === 'I') {
            return self::fail('Ese USDOT figura como inactivo en FMCSA.');
        }
        $p = Phone::normalize((string) ($cr['phone'] ?? ''));
        $phone = $p['ok'] ? $p['e164'] : null;
        if (($why = self::phoneBlock($phone)) !== null) {
            return self::fail($why);
        }
        $date = is_array($g) && preg_match('/^\d{8}/', (string) ($g['status_change_date'] ?? '')) === 1 ? substr((string) $g['status_change_date'], 0, 8) : '';
        $row = ProspectImport::buildRow(['usdot' => $usdot, 'docket' => is_array($g) ? (string) ($g['docket_number'] ?? '') : '', 'date' => $date !== '' ? $date : '00000000'], $cr, $phone, Prospects::etDay(Clock::time()));
        if ($date === '') {
            $row['authority_granted_at'] = null;
        }
        $row['source'] = 'manual';
        $row['state'] = isset(UsStates::NAMES[(string) $row['state']]) ? $row['state'] : null;
        return self::insert($row, $origin, $userId, 'Añadido: ' . Fmt::company((string) ($row['legal_name'] ?? 'USDOT ' . $usdot)) . ($phone === null ? ' (sin teléfono en FMCSA)' : '') . '.');
    }

    private static function insert(array $row, mixed $origin, int $userId, string $msg): array
    {
        $origin = mb_substr(trim((string) preg_replace('/\s+/u', ' ', mb_scrub(is_string($origin) ? $origin : ''))), 0, 200);
        try {
            $id = Db::insert('prospects_fmcsa', $row);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return self::fail('Ese USDOT ya está en la lista.');
            }
            throw $e;
        }
        Db::insert('prospect_calls', ['prospect_id' => $id, 'user_id' => $userId, 'result' => 'added', 'note' => $origin !== '' ? 'Origen: ' . $origin : null, 'attempt_no' => 0, 'created_at' => Clock::now()]);
        ActivityLog::record('prospect_add', $userId, 'prospect', $id, ['source' => $row['source'], 'has_origin' => $origin !== '']);
        ProspectAutoMail::queue((int) $id, $userId, 'intro');   // fase AW (los CSV no: listas de origen desconocido dañan la reputación del dominio)
        return self::ok($msg, ['id' => $id]);
    }

    /**
     * Importa un CSV de prospectos. @return array{ok:bool,msg:string,counts?:array<string,int>}
     */
    public static function importCsv(string $path, mixed $origin, int $userId): array
    {
        if (!is_file($path) || filesize($path) === 0) {
            return self::fail('El archivo está vacío.');
        }
        if (filesize($path) > self::MAX_CSV_BYTES) {
            return self::fail('El archivo pasa de 2 MB.');
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return self::fail('No se pudo leer el archivo.');
        }
        $first = (string) fgets($fh);
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        rewind($fh);
        $head = fgetcsv($fh, 0, $delim, '"', '');
        if (!is_array($head)) {
            fclose($fh);
            return self::fail('El archivo no tiene encabezados.');
        }
        $map = [];
        foreach ($head as $i => $h) {
            $k = strtolower(trim((string) preg_replace('/[^A-Za-z0-9_]+/', '_', (string) preg_replace('/^\xEF\xBB\xBF/', '', trim((string) $h))), '_'));
            if (isset(self::HEADERS[$k]) && !in_array(self::HEADERS[$k], $map, true)) {
                $map[$i] = self::HEADERS[$k];
            }
        }
        if (!in_array('usdot', $map, true)) {
            fclose($fh);
            return self::fail('Falta la columna del USDOT (encabezado usdot, dot o dot_number).');
        }
        $c = ['added' => 0, 'known' => 0, 'invalid' => 0, 'blocked' => 0, 'too_many' => 0];
        $today = Prospects::etDay(Clock::time());
        $n = 0;
        while (($cols = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            if ($cols === [null] || implode('', array_map('strval', $cols)) === '') {
                continue;
            }
            if (++$n > self::MAX_CSV_ROWS) {
                $c['too_many']++;
                continue;
            }
            $v = [];
            foreach ($map as $i => $k) {
                $v[$k] = trim((string) ($cols[$i] ?? ''));
            }
            $usdot = ltrim((string) preg_replace('/\D/', '', $v['usdot'] ?? ''), '0');
            if ($usdot === '' || strlen($usdot) > 8) {
                $c['invalid']++;
                continue;
            }
            if (Db::value('SELECT 1 FROM prospects_fmcsa WHERE usdot = ?', [$usdot]) !== null || Db::value('SELECT 1 FROM leads WHERE deleted_at IS NULL AND usdot = ? LIMIT 1', [$usdot]) !== null) {
                $c['known']++;
                continue;
            }
            $ph = Phone::normalize($v['phone'] ?? '');
            $phone = $ph['ok'] ? $ph['e164'] : null;
            if (self::phoneBlock($phone) !== null || ($phone !== null && Db::value('SELECT 1 FROM prospects_fmcsa WHERE phone = ?', [$phone]) !== null)) {
                $c['blocked']++;
                continue;
            }
            $email = strtolower($v['email'] ?? '');
            $email = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 190 ? $email : null;
            $state = strtoupper(substr($v['state'] ?? '', 0, 2));
            $granted = null;
            if (($g = trim($v['granted'] ?? '')) !== '' && ($ts = strtotime($g)) !== false) {
                $granted = gmdate('Y-m-d', $ts);
            }
            $fleet = isset($v['fleet']) && ctype_digit($v['fleet']) ? min(65535, (int) $v['fleet']) : null;
            $clean = static fn(string $s, int $max): ?string => ($s = mb_substr(trim((string) preg_replace('/\s+/u', ' ', mb_scrub($s))), 0, $max)) !== '' ? $s : null;
            $mc = $clean((string) preg_replace('/[^A-Za-z0-9-]/', '', $v['mc'] ?? ''), 20);
            $row = [
                'usdot' => $usdot, 'mc_number' => $mc !== null && ctype_digit($mc) ? 'MC-' . $mc : $mc, 'legal_name' => $clean($v['name'] ?? '', 190), 'dba' => $clean($v['dba'] ?? '', 190), 'phone' => $phone, 'email' => $email,
                'state' => isset(UsStates::NAMES[$state]) ? $state : null, 'city' => $clean($v['city'] ?? '', 80), 'authority_granted_at' => $granted, 'fleet_size' => $fleet,
                'completeness' => ($phone !== null ? 2 : 0) + ($email !== null ? 1 : 0) + 1, 'source' => 'csv', 'status' => 'to_call', 'attempts' => 0, 'listed_on' => $today, 'created_at' => Clock::now(), 'updated_at' => Clock::now(),
            ];
            try {
                Db::insert('prospects_fmcsa', $row);
                $c['added']++;
            } catch (\PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
                $c['known']++;
            }
        }
        fclose($fh);
        ActivityLog::record('prospect_import', $userId, 'prospect', null, $c + ['has_origin' => trim((string) $origin) !== '']);
        $parts = [];
        foreach (['known' => 'ya estaban', 'blocked' => 'con teléfono bloqueado o repetido', 'invalid' => 'sin USDOT válido', 'too_many' => 'por encima del tope de ' . self::MAX_CSV_ROWS] as $k => $l) {
            if ($c[$k] > 0) {
                $parts[] = $c[$k] . ' ' . $l;
            }
        }
        return self::ok('Importados ' . $c['added'] . ' prospectos.' . ($parts ? ' Omitidos: ' . implode(', ', $parts) . '.' : ''), ['counts' => $c]);
    }
}
