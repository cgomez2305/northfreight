<?php
/** Simula la API SODA de data.transportation.gov con las fixtures reales (tests/fixtures/socrata): entiende solo las consultas que arma ProspectImport. */

declare(strict_types=1);

namespace CaptacionTests;

final class SocrataFixture
{
    /** @var list<array> filas de concesiones (authhist) */
    public array $grants;
    /** @var list<array> filas del censo */
    public array $census;
    /** @var list<string> registro de peticiones: "dataset ?query" */
    public array $log = [];
    /** @var int|null si se pone, responde ese estado (429, 500…) en lugar de datos */
    public ?int $failStatus = null;
    /** Cuantas peticiones fallan antes de responder bien (-1 = todas). */
    public int $failTimes = 0;

    public function __construct(?string $dir = null)
    {
        $dir ??= dirname(__DIR__) . '/fixtures/socrata';
        $this->grants = json_decode((string) file_get_contents($dir . '/authhist_granted.json'), true);
        $this->census = json_decode((string) file_get_contents($dir . '/census.json'), true);
    }

    /** @return array{status:int,body:string,retry_after?:int} */
    public function respond(string $url): array
    {
        $parts = parse_url($url);
        $this->log[] = ltrim((string) ($parts['path'] ?? ''), '/') . '?' . ($parts['query'] ?? '');
        if ($this->failTimes !== 0) {   // > 0: falla esa cantidad de veces; -1: siempre
            if ($this->failTimes > 0) {
                $this->failTimes--;
            }
            return ['status' => $this->failStatus ?? 500, 'body' => '{"error":true}', 'retry_after' => 1];
        }
        parse_str((string) ($parts['query'] ?? ''), $q);
        $path = (string) ($parts['path'] ?? '');
        if (preg_match('#/resource/([a-z0-9-]+)\.json$#', $path, $m) !== 1) {
            return ['status' => 404, 'body' => '{}'];
        }
        $where = (string) ($q['$where'] ?? '');
        $limit = (int) ($q['$limit'] ?? 1000);
        if ($m[1] === 'yu5v-wbh6') {
            if (str_contains($where, 'crgo_dryvan')) {
                return ['status' => 400, 'body' => (string) file_get_contents(dirname(__DIR__) . '/fixtures/socrata/error_no_such_column.json')];
            }
            preg_match("/status_change_date>='(\d{8})'/", $where, $s);
            $since = $s[1] ?? '00000000';
            $rows = array_values(array_filter($this->grants, static fn($r) => ($r['op_auth_status'] ?? '') === 'Active' && strtoupper((string) ($r['reason'] ?? '')) === 'GRANTED'
                && ($r['op_auth_type'] ?? '') === 'Motor Carrier of Property (Except Household Goods)' && $r['status_change_date'] >= $since));
            if (preg_match("/status_change_date<'(\d{8})' OR \(status_change_date='(\d{8})' AND docket_number>'([^']*)'\)/", $where, $c) === 1) {
                $rows = array_values(array_filter($rows, static fn($r) => $r['status_change_date'] < $c[1] || ($r['status_change_date'] === $c[2] && strcmp($r['docket_number'], $c[3]) > 0)));
            }
            usort($rows, static fn($a, $b) => [$b['status_change_date'], $a['docket_number']] <=> [$a['status_change_date'], $b['docket_number']]);
            $rows = array_slice($rows, 0, $limit);
            return ['status' => 200, 'body' => json_encode(array_map(static fn($r) => array_intersect_key($r, array_flip(['docket_number', 'usdot_number', 'status_change_date'])), $rows))];
        }
        if ($m[1] === 'az4n-8mr2') {
            preg_match('/dot_number in \(([^)]*)\)/', $where, $ids);
            $want = array_map(static fn($x) => trim($x, "' "), explode(',', $ids[1] ?? ''));
            $rows = array_values(array_filter($this->census, static fn($r) => in_array($r['dot_number'], $want, true)));
            return ['status' => 200, 'body' => json_encode(array_slice($rows, 0, $limit))];
        }
        return ['status' => 404, 'body' => '{}'];
    }

    /** Cliente inyectable para SocrataClient. */
    public function http(): callable
    {
        return fn(string $url, array $headers): array => $this->respond($url);
    }
}
