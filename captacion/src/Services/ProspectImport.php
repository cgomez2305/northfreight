<?php
/**
 * Corrida diaria de prospectos FMCSA (A10, doc §7): autoridades NUEVAS de transportistas de carga, de la API publica de data.transportation.gov (Socrata).
 *
 * FUENTE (probada contra la API real el 2026-11-05; ver README §19):
 *   1. Autoridades concedidas: dataset `yu5v-wbh6` «Motus AuthHist - All With History». Filas con op_auth_type = «Motor Carrier of Property (Except Household Goods)»,
 *      op_auth_status = Active y reason = Granted (en mayusculas, GRANTED, las anteriores a la migracion a Motus del 2026-05-28). Campos: docket_number (MC), usdot_number,
 *      status_change_date (AAAAMMDD = fecha de concesion). El «All With History» legacy `9mw4-x3tu` y el censo viejo `6eyk-hxee` ya no se actualizan (desde 2026-05-14).
 *   2. Datos del carrier: dataset `az4n-8mr2` «Company Census File» (dot_number): nombre legal, DBA, telefono, correo, ciudad, estado, unidades motrices, conductores,
 *      status_code y las banderas de carga crgo_*. NO trae: tipo de equipo (dry van, reefer…), fecha de concesion (por eso se usa el dataset 1) ni nombre de contacto.
 *   Cambiar de dataset: constantes GRANTS_DATASET y CENSUS_DATASET (y los campos F_*). Los dos son de SOLO LECTURA; con SOCRATA_APP_TOKEN el limite compartido sube.
 *
 * FLUJO (tick): el cron corre cada hora; decide por la hora de Nueva York. Una corrida al dia desde las 6:00 a. m. ET (si el cron fallo, la siguiente hora disponible la
 * recupera), idempotente, con lotes por cursor (fecha, docket): si se acaba el tiempo (FMCSA_MAX_SECONDS, 40 s) o la memoria, guarda el cursor y la siguiente hora continua.
 * Una corrida COMPLETA (sin tope diario ni errores) deja `prospects.complete_until`; la siguiente solo mira desde 3 dias antes de esa fecha.
 * Filtros (ProspectConfig) y descartes contados: known, already_lead, dnc, no_census, census_inactive, not_us, state, fleet, no_phone, dup_phone, cargo, cap.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;

final class ProspectImport
{
    public const GRANTS_DATASET = 'yu5v-wbh6';
    public const CENSUS_DATASET = 'az4n-8mr2';
    public const AUTH_TYPE = 'Motor Carrier of Property (Except Household Goods)';
    public const RUN_KEY = 'prospects.run';
    public const COMPLETE_KEY = 'prospects.complete_until';
    public const START_HOUR_ET = 6;
    public const OVERLAP_DAYS = 3;
    public const REASONS = [
        'known' => 'Ya estaba en la lista', 'already_lead' => 'Ya es un lead', 'dnc' => 'En la lista de no llamar', 'no_census' => 'Sin ficha en el censo', 'census_inactive' => 'USDOT inactivo',
        'not_us' => 'Fuera de EE. UU.', 'state' => 'Estado fuera del filtro', 'fleet' => 'Flota mayor al máximo', 'no_phone' => 'Sin teléfono válido', 'dup_phone' => 'Teléfono repetido',
        'cargo' => 'Carga fuera del filtro', 'cap' => 'Tope diario alcanzado',
    ];
    private const CENSUS_FIELDS = ['dot_number', 'legal_name', 'dba_name', 'phone', 'email_address', 'phy_city', 'phy_state', 'phy_country', 'add_date', 'status_code', 'carrier_operation',
        'power_units', 'truck_units', 'total_drivers', 'classdef', 'hm_ind'];

    public static function etDate(int $ts): string
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('America/New_York'))->format('Y-m-d');
    }

    /** Parametros SoQL de la consulta de concesiones (publico: lo usan las pruebas y el servidor simulado). */
    public static function grantsParams(string $since, ?array $cursor, int $limit): array
    {
        $esc = static fn(string $s): string => str_replace("'", "''", $s);
        $where = "op_auth_type='" . self::AUTH_TYPE . "' AND op_auth_status='Active' AND upper(reason)='GRANTED' AND status_change_date>='" . $esc($since) . "'";
        if ($cursor !== null) {
            $where .= " AND (status_change_date<'" . $esc($cursor['d']) . "' OR (status_change_date='" . $esc($cursor['d']) . "' AND docket_number>'" . $esc($cursor['k']) . "'))";
        }
        return ['$select' => 'docket_number,usdot_number,status_change_date', '$where' => $where, '$order' => 'status_change_date DESC,docket_number ASC', '$limit' => (string) $limit];
    }

    public static function censusParams(array $usdots): array
    {
        $in = implode(',', array_map(static fn($u) => "'" . preg_replace('/\D/', '', (string) $u) . "'", $usdots));
        $cargo = [];
        foreach (ProspectConfig::CARGO as [, $keys]) {
            foreach ($keys as $k) {
                $cargo[] = 'crgo_' . $k;
            }
        }
        return ['$select' => implode(',', array_merge(self::CENSUS_FIELDS, $cargo)), '$where' => 'dot_number in (' . $in . ')', '$limit' => (string) max(1, count($usdots))];
    }

    /** Estado de la corrida actual o la ultima. */
    public static function state(): ?array
    {
        $s = Settings::get(self::RUN_KEY);
        return is_array($s) ? $s : null;
    }

    public static function completeUntil(): ?string
    {
        $v = Settings::get(self::COMPLETE_KEY);
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
    }

    /** La proxima corrida vuelve a mirar toda la ventana (tras cambiar los filtros). */
    public static function resetWindow(): void
    {
        Settings::set(self::COMPLETE_KEY, null);
    }

    /**
     * Lo que llama el cron cada hora. Devuelve ['status' => disabled|waiting|done|ran|error|locked, 'run' => estado].
     * @param array{force?:bool,max_seconds?:int,max_pages?:int,page_size?:int,client?:SocrataClient} $o
     */
    public static function tick(?int $now = null, array $o = []): array
    {
        $now ??= Clock::time();
        $cfg = ProspectConfig::get();
        if (!$cfg['enabled'] && empty($o['force'])) {
            return ['status' => 'disabled', 'run' => self::state()];
        }
        $today = self::etDate($now);
        $hour = (int) (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('America/New_York'))->format('G');
        $run = self::state();
        $open = $run !== null && empty($run['finished']);
        if ($open && ($run['day'] ?? '') !== $today) {
            $open = $hour >= self::START_HOUR_ET || !empty($o['force']) ? false : true;   // una corrida a medias de ayer sigue hasta las 6:00; despues empieza la de hoy
        }
        if (!$open) {
            if ($run !== null && ($run['day'] ?? '') === $today && !empty($run['finished']) && empty($o['force'])) {
                return ['status' => 'done', 'run' => $run];
            }
            if ($hour < self::START_HOUR_ET && empty($o['force'])) {
                return ['status' => 'waiting', 'run' => $run];
            }
            $run = self::newRun($now, $cfg, $today);
        }
        return self::work($run, $cfg, $now, $today, $o);
    }

    private static function newRun(int $now, array $cfg, string $today): array
    {
        $start = (new \DateTimeImmutable($today . ' 00:00:00', new \DateTimeZone('America/New_York')))->modify('-' . (int) $cfg['max_age_months'] . ' months');
        $since = $start->format('Ymd');
        $cu = self::completeUntil();
        if ($cu !== null) {
            $back = (new \DateTimeImmutable($cu . ' 00:00:00', new \DateTimeZone('America/New_York')))->modify('-' . self::OVERLAP_DAYS . ' days')->format('Ymd');
            $since = max($since, $back);
        }
        return ['day' => $today, 'since' => $since, 'cursor' => null, 'finished' => false, 'capped' => false, 'started_at' => Clock::at($now), 'updated_at' => Clock::at($now), 'pages' => 0, 'requests' => 0,
            'counters' => ['read' => 0, 'new' => 0] + array_fill_keys(array_keys(self::REASONS), 0), 'error' => null, 'resumed' => 0];
    }

    private static function memoryOk(): bool
    {
        $lim = (string) ini_get('memory_limit');
        if ($lim === '-1' || $lim === '') {
            return true;
        }
        $bytes = (int) $lim;
        $u = strtolower(substr($lim, -1));
        $bytes *= $u === 'g' ? 1073741824 : ($u === 'm' ? 1048576 : ($u === 'k' ? 1024 : 1));
        return $bytes <= 0 || memory_get_usage(true) < $bytes * 0.6;
    }

    private static function work(array $run, array $cfg, int $now, string $today, array $o): array
    {
        $client = $o['client'] ?? new SocrataClient();
        $maxSeconds = (int) ($o['max_seconds'] ?? Env::int('FMCSA_MAX_SECONDS', 40));
        $pageSize = max(2, min(500, (int) ($o['page_size'] ?? Env::int('FMCSA_PAGE_SIZE', 200))));
        $maxPages = (int) ($o['max_pages'] ?? 0);   // 0 = sin limite (solo lo usan las pruebas para forzar una reanudacion)
        $t0 = microtime(true);
        $pagesThisCall = 0;
        $c = &$run['counters'];
        $run['resumed'] = (int) ($run['resumed'] ?? 0) + (($run['pages'] ?? 0) > 0 ? 1 : 0);
        $run['error'] = null;
        $reqStart = $client->requests;
        $status = 'ran';
        try {
            while (true) {
                if (microtime(true) - $t0 > $maxSeconds || !self::memoryOk() || ($maxPages > 0 && $pagesThisCall >= $maxPages)) {
                    break;   // sigue en la proxima hora desde el cursor
                }
                $pagesThisCall++;
                $rows = $client->query(self::GRANTS_DATASET, self::grantsParams((string) $run['since'], $run['cursor'], $pageSize));
                $run['pages']++;
                if (!$rows) {
                    $run['finished'] = true;
                    break;
                }
                $last = $rows[count($rows) - 1];
                $stop = self::processPage($rows, $client, $cfg, $today, $c);
                $run['cursor'] = ['d' => (string) ($last['status_change_date'] ?? ''), 'k' => (string) ($last['docket_number'] ?? '')];
                $run['updated_at'] = Clock::now();
                if ($stop) {
                    $run['finished'] = true;
                    $run['capped'] = true;
                    break;
                }
                if (count($rows) < $pageSize) {
                    $run['finished'] = true;
                    break;
                }
                usleep(150000);
            }
        } catch (SocrataUnavailable $e) {
            $status = 'error';
            $run['error'] = mb_substr(Storage::redact($e->getMessage()), 0, 240);
            Integrations::record('socrata', false, $e->getMessage());
        }
        unset($c);
        $run['requests'] = (int) ($run['requests'] ?? 0) + ($client->requests - $reqStart);
        $run['updated_at'] = Clock::at($now);
        if ($status === 'ran') {
            Integrations::record('socrata', true);
        }
        if (!empty($run['finished'])) {
            $run['finished_at'] = Clock::now();
            if (!$run['capped'] && $run['error'] === null) {
                Settings::set(self::COMPLETE_KEY, $today);
            }
        }
        Settings::set(self::RUN_KEY, $run);
        ActivityLog::record($status === 'error' ? 'prospects_run_error' : 'prospects_run', null, 'prospects', $today, [
            'finished' => !empty($run['finished']), 'capped' => $run['capped'], 'pages' => $run['pages'], 'counters' => $run['counters'], 'error' => $run['error'],
        ]);
        return ['status' => $status, 'run' => $run];
    }

    /**
     * Evalua e inserta una pagina de concesiones. @return bool true si se alcanzo el tope diario (la corrida termina)
     * @param list<array<string,mixed>> $rows
     */
    private static function processPage(array $rows, SocrataClient $client, array $cfg, string $today, array &$c): bool
    {
        // Una fila por USDOT (la concesion mas antigua de la pagina, que es la ultima al ir de nuevo a viejo).
        $byDot = [];
        foreach ($rows as $r) {
            $c['read']++;
            $dot = ltrim(preg_replace('/\D/', '', (string) ($r['usdot_number'] ?? '')) ?? '', '0');
            $d = (string) ($r['status_change_date'] ?? '');
            if ($dot === '' || strlen($dot) > 8 || preg_match('/^\d{8}$/', $d) !== 1) {
                continue;
            }
            $byDot[$dot] = ['usdot' => $dot, 'docket' => substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($r['docket_number'] ?? '')) ?? '', 0, 20), 'date' => $d];
        }
        if (!$byDot) {
            return false;
        }
        $dots = array_keys($byDot);
        $in = implode(',', array_fill(0, count($dots), '?'));
        $known = array_flip(array_column(Db::all("SELECT usdot FROM prospects_fmcsa WHERE usdot IN ($in)", $dots), 'usdot'));
        $isLead = array_flip(array_column(Db::all("SELECT usdot FROM leads WHERE deleted_at IS NULL AND usdot IN ($in)", $dots), 'usdot'));
        $fresh = [];
        foreach ($byDot as $dot => $g) {
            if (isset($known[$dot])) {
                $c['known']++;
            } elseif (isset($isLead[$dot])) {
                $c['already_lead']++;
            } else {
                $fresh[$dot] = $g;
            }
        }
        if (!$fresh) {
            return false;
        }
        $census = [];
        foreach (array_chunk(array_keys($fresh), 100) as $chunk) {
            foreach ($client->query(self::CENSUS_DATASET, self::censusParams($chunk)) as $row) {
                $census[ltrim((string) ($row['dot_number'] ?? ''), '0')] = $row;
            }
        }
        // Telefonos ya conocidos (leads, no llamar, otros prospectos) en un solo viaje cada uno
        $cand = [];
        foreach ($fresh as $dot => $g) {
            $cr = $census[$dot] ?? null;
            $p = $cr !== null ? Phone::normalize((string) ($cr['phone'] ?? '')) : ['ok' => false, 'e164' => null];
            $cand[$dot] = $p['ok'] && !$p['intl'] ? $p['e164'] : null;
        }
        $phones = array_values(array_unique(array_filter($cand)));
        $leadPhone = $dncPhone = $prospPhone = [];
        if ($phones) {
            $pin = implode(',', array_fill(0, count($phones), '?'));
            $leadPhone = array_flip(array_column(Db::all("SELECT phone_e164 FROM leads WHERE deleted_at IS NULL AND phone_e164 IN ($pin)", $phones), 'phone_e164'));
            $dncPhone = array_flip(array_column(Db::all("SELECT phone_e164 FROM dnc_numbers WHERE phone_e164 IN ($pin)", $phones), 'phone_e164'));
            $prospPhone = array_flip(array_column(Db::all("SELECT phone FROM prospects_fmcsa WHERE phone IN ($pin)", $phones), 'phone'));
        }
        $listedToday = (int) Db::value('SELECT COUNT(*) FROM prospects_fmcsa WHERE listed_on = ?', [$today]);
        foreach ($fresh as $dot => $g) {
            if ($listedToday >= (int) $cfg['daily_cap']) {
                $c['cap']++;
                return true;
            }
            $cr = $census[$dot] ?? null;
            $phone = $cand[$dot];
            $reason = self::reject($cr, $phone, $cfg, $leadPhone, $dncPhone, $prospPhone);
            if ($reason !== null) {
                $c[$reason]++;
                continue;
            }
            $row = self::buildRow($g, $cr, $phone, $today);
            try {
                Db::insert('prospects_fmcsa', $row);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() === '23000') {
                    $c['known']++;   // otra corrida lo metio entre la consulta y el alta
                    continue;
                }
                throw $e;
            }
            $prospPhone[$phone ?? ''] = true;
            $listedToday++;
            $c['new']++;
        }
        return false;
    }

    /** Motivo de descarte (clave de REASONS) o null si pasa todos los filtros. */
    public static function reject(?array $cr, ?string $phone, array $cfg, array $leadPhone, array $dncPhone, array $prospPhone): ?string
    {
        if ($cr === null) {
            return 'no_census';
        }
        if (strtoupper((string) ($cr['status_code'] ?? '')) === 'I') {
            return 'census_inactive';
        }
        $state = strtoupper(trim((string) ($cr['phy_state'] ?? '')));
        if (strtoupper((string) ($cr['phy_country'] ?? 'US')) !== 'US' || !isset(UsStates::NAMES[$state])) {
            return 'not_us';
        }
        if ($cfg['states'] && !in_array($state, $cfg['states'], true)) {
            return 'state';
        }
        $units = isset($cr['power_units']) && is_numeric($cr['power_units']) ? (int) $cr['power_units'] : null;
        if ($units !== null && $units > (int) $cfg['max_fleet']) {
            return 'fleet';
        }
        if ($phone === null && $cfg['require_phone']) {
            return 'no_phone';
        }
        if ($phone !== null) {
            if (isset($dncPhone[$phone])) {
                return 'dnc';
            }
            if (isset($leadPhone[$phone])) {
                return 'already_lead';
            }
            if (isset($prospPhone[$phone])) {
                return 'dup_phone';
            }
        }
        if ($cfg['cargo']) {
            $cats = ProspectConfig::categoriesOf(self::cargoFlags($cr));
            if (!array_intersect($cats, $cfg['cargo'])) {
                return 'cargo';
            }
        }
        return null;
    }

    /** @return list<string> banderas de carga declaradas por el carrier, sin el prefijo crgo_ */
    public static function cargoFlags(array $cr): array
    {
        $out = [];
        foreach (ProspectConfig::CARGO as [, $keys]) {
            foreach ($keys as $k) {
                if (strtoupper((string) ($cr['crgo_' . $k] ?? '')) === 'X') {
                    $out[] = $k;
                }
            }
        }
        return $out;
    }

    private static function buildRow(array $g, array $cr, ?string $phone, string $today): array
    {
        $clean = static fn(mixed $v, int $max): ?string => ($s = mb_substr(trim((string) preg_replace('/\s+/u', ' ', mb_scrub((string) $v))), 0, $max)) !== '' ? $s : null;
        $email = strtolower(trim((string) ($cr['email_address'] ?? '')));
        $email = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 190 ? $email : null;
        $units = isset($cr['power_units']) && is_numeric($cr['power_units']) ? max(0, min(65535, (int) $cr['power_units'])) : null;
        $drivers = isset($cr['total_drivers']) && is_numeric($cr['total_drivers']) ? max(0, min(65535, (int) $cr['total_drivers'])) : null;
        $cargo = self::cargoFlags($cr);
        $city = $clean($cr['phy_city'] ?? '', 80);
        $date = $g['date'];
        $granted = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
        $score = ($phone !== null ? 2 : 0) + ($email !== null ? 1 : 0) + ($city !== null ? 1 : 0) + 1 + ($units !== null && $units > 0 ? 1 : 0) + ($cargo ? 1 : 0) + ($g['docket'] !== '' ? 1 : 0);
        $added = (string) ($cr['add_date'] ?? '');
        return [
            'usdot' => $g['usdot'], 'mc_number' => $g['docket'] !== '' ? $g['docket'] : null, 'legal_name' => $clean($cr['legal_name'] ?? '', 190), 'dba' => $clean($cr['dba_name'] ?? '', 190),
            'phone' => $phone, 'email' => $email, 'state' => strtoupper(trim((string) $cr['phy_state'])), 'city' => $city, 'authority_granted_at' => $granted,
            'fleet_size' => $units, 'drivers' => $drivers, 'cargo' => $cargo ?: null, 'completeness' => $score,
            'fmcsa_data' => array_filter([
                'census_status' => $clean($cr['status_code'] ?? '', 2), 'census_added_at' => preg_match('/^\d{8}$/', $added) === 1 ? substr($added, 0, 4) . '-' . substr($added, 4, 2) . '-' . substr($added, 6, 2) : null,
                'carrier_operation' => $clean($cr['carrier_operation'] ?? '', 2), 'classdef' => $clean($cr['classdef'] ?? '', 120), 'hazmat' => $clean($cr['hm_ind'] ?? '', 2),
                'truck_units' => isset($cr['truck_units']) && is_numeric($cr['truck_units']) ? (int) $cr['truck_units'] : null, 'grants_dataset' => self::GRANTS_DATASET, 'census_dataset' => self::CENSUS_DATASET,
            ], static fn($v) => $v !== null),
            'source' => 'socrata', 'status' => 'to_call', 'attempts' => 0, 'listed_on' => $today, 'created_at' => Clock::now(), 'updated_at' => Clock::now(),
        ];
    }
}
