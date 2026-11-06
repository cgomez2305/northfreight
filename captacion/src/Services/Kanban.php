<?php
/**
 * Kanban de leads (/pipeline), doc §9.2. Una columna por etapa del pipeline (doc §7). Solo se leen las columnas visibles, 25 tarjetas por pagina de columna
 * ("Ver mas"); el conteo de cada columna es el real (con los filtros rapidos aplicados). El cambio de etapa NO tiene logica propia: usa LeadActions::setStage
 * (mismas validaciones, transaccion, evento y activity_log que la ficha). Esta clase solo prepara los datos de las tarjetas y las pre-validaciones que piden datos.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Db;

final class Kanban
{
    public const PAGE = 25;

    /** Filtros del tablero: los mismos de la bandeja (comparten vistas y filtros), salvo `st` (la etapa son las columnas). @return array<string,string> */
    public static function filters(array $in): array
    {
        return array_diff_key(LeadQuery::filters($in), ['st' => 1]);
    }

    public static function pipeline(mixed $v): string
    {
        return $v === 'B' ? 'B' : 'A';
    }

    /** Etapas de un pipeline en orden: [etapa => etiqueta]; la ultima ('lost') es la salida. */
    public static function stages(string $pipeline): array
    {
        return Catalog::STAGES[$pipeline] ?? Catalog::STAGES['A'];
    }

    /** @return array<string,int> leads por etapa del pipeline (con los filtros rapidos) */
    public static function counts(string $pipeline, array $f): array
    {
        [$where, $p] = LeadQuery::where($f);
        $out = array_fill_keys(array_keys(self::stages($pipeline)), 0);
        foreach (Db::all("SELECT l.stage, COUNT(*) AS n FROM leads l WHERE $where AND l.pipeline = ? GROUP BY l.stage", [...$p, $pipeline]) as $r) {
            if (isset($out[$r['stage']])) {
                $out[$r['stage']] = (int) $r['n'];
            }
        }
        return $out;
    }

    /** Leads abiertos por pipeline (numeritos de las pestañas), sin filtros. @return array<string,int> */
    public static function pipelineTotals(): array
    {
        $out = ['A' => 0, 'B' => 0];
        foreach (Db::all("SELECT pipeline, COUNT(*) AS n FROM leads WHERE deleted_at IS NULL AND stage <> 'lost' GROUP BY pipeline") as $r) {
            $out[$r['pipeline']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Una pagina de tarjetas de una columna.
     * @return array{cards:list<array>,total:int,next:?int}
     */
    public static function cards(string $pipeline, string $stage, array $f, int $offset = 0, ?int $total = null): array
    {
        if (!isset(self::stages($pipeline)[$stage])) {
            return ['cards' => [], 'total' => 0, 'next' => null];
        }
        [$where, $p] = LeadQuery::where($f);
        $base = "$where AND l.pipeline = ? AND l.stage = ?";
        $params = [...$p, $pipeline, $stage];
        $total ??= (int) Db::value("SELECT COUNT(*) FROM leads l WHERE $base", $params);
        $order = $stage === 'lost' ? 'l.lost_at DESC, l.id DESC' : 'l.score DESC, l.id DESC';
        $rows = Db::all(
            "SELECT l.id, l.first_name, l.last_name, l.profile, l.pipeline, l.stage, l.state, l.equipment_types, l.truck_count, l.score, l.temperature, l.flags, l.lost_reason, l.lost_at,
                    l.source, l.created_at, l.language, COALESCE(l.stage_changed_at, l.created_at) AS in_stage_since
               FROM leads l WHERE $base ORDER BY $order LIMIT " . (self::PAGE + 1) . ' OFFSET ' . max(0, $offset),
            $params
        );
        $hasMore = count($rows) > self::PAGE;
        $rows = array_slice($rows, 0, self::PAGE);
        return ['cards' => self::decorate($rows), 'total' => $total, 'next' => $hasMore ? $offset + self::PAGE : null];
    }

    /** Una sola tarjeta (tras un cambio de etapa). */
    public static function card(int $leadId): ?array
    {
        $rows = Db::all(
            "SELECT l.id, l.first_name, l.last_name, l.profile, l.pipeline, l.stage, l.state, l.equipment_types, l.truck_count, l.score, l.temperature, l.flags, l.lost_reason, l.lost_at,
                    l.source, l.created_at, COALESCE(l.stage_changed_at, l.created_at) AS in_stage_since
               FROM leads l WHERE l.id = ? AND l.deleted_at IS NULL",
            [$leadId]
        );
        return $rows ? self::decorate($rows)[0] : null;
    }

    /** Añade a cada fila: equipo/flags decodificados, banderas rojas y la tarea abierta mas proxima. @param list<array> $rows */
    private static function decorate(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $next = [];
        foreach (Db::all("SELECT lead_id, title, due_at FROM tasks WHERE lead_id IN ($in) AND done_at IS NULL AND deleted_at IS NULL ORDER BY due_at IS NULL, due_at ASC, id ASC", $ids) as $t) {
            $next[(int) $t['lead_id']] ??= $t;
        }
        foreach ($rows as &$r) {
            $r['equipment_types'] = Db::decode($r['equipment_types']);
            $flags = Db::decode($r['flags']);
            $r['red_flags'] = array_values(array_filter($flags, static fn($f) => str_starts_with((string) $f, 'red:')));
            $r['next_task'] = $next[(int) $r['id']] ?? null;
        }
        return $rows;
    }

    /**
     * Pre-validacion de un movimiento: devuelve null si se puede intentar, o ['needs' => 'reason'|'note'|'override', 'msg' => ...] si falta un dato que la
     * interfaz debe pedir. Las reglas de fondo siguen siendo las de LeadActions::setStage (que vuelve a validar todo dentro de la transaccion).
     * @param array{reason?:string,note?:string,override?:bool} $o
     */
    public static function needs(array $lead, string $to, array $o): ?array
    {
        $stages = self::stages((string) $lead['pipeline']);
        if (!isset($stages[$to]) || $to === $lead['stage'] || $lead['stage'] === 'lost') {
            return null;   // setStage responde con el mensaje exacto
        }
        if ($to === 'lost') {
            $reason = (string) ($o['reason'] ?? '');
            if (!isset(Catalog::LOSS_REASONS[$reason])) {
                return ['needs' => 'reason', 'msg' => 'Elige un motivo para marcar «' . $stages['lost'] . '».'];
            }
            if ($reason === 'other' && mb_strlen(trim((string) ($o['note'] ?? ''))) < 3) {
                return ['needs' => 'note', 'msg' => 'Con el motivo «Otro» escribe una nota que lo explique.'];
            }
        }
        if (empty($o['override']) && LeadActions::needsOverride($lead, $to)) {
            return ['needs' => 'override', 'msg' => in_array($lead['stage'], ['signed', 'hired'], true)
                ? 'Sacar un lead de «' . $stages[$lead['stage']] . '» necesita confirmar que quieres saltarte el orden.'
                : '«' . $stages[$to] . '» solo se alcanza en orden (desde la etapa anterior). Confirma el salto si es lo que quieres.'];
        }
        return null;
    }

    /** Avance en el embudo (sin la salida): [posicion 1..n, n]; [0, n] si esta fuera del embudo (perdido). @return array{0:int,1:int} */
    public static function progress(string $pipeline, string $stage): array
    {
        $flow = array_keys(array_diff_key(self::stages($pipeline), ['lost' => 1]));
        $i = array_search($stage, $flow, true);
        return [$i === false ? 0 : $i + 1, count($flow)];
    }

    /** Texto corto de antiguedad en la etapa: "hoy", "3 d", "5 h". */
    public static function age(string $since): string
    {
        $s = max(0, \Captacion\Clock::time() - (int) \Captacion\Clock::parse($since));
        return match (true) {
            $s < 3600     => max(1, intdiv($s, 60)) . ' min',
            $s < 86400    => intdiv($s, 3600) . ' h',
            $s < 86400 * 60 => intdiv($s, 86400) . ' d',
            default       => intdiv($s, 86400 * 30) . ' meses',
        };
    }
}
