<?php
/**
 * Q6: «Siguiente mejor accion». Hasta 3 acciones priorizadas por una puntuacion de impacto (0 a 100) calculada con reglas FIJAS sobre datos reales; no hay IA ni numeros inventados.
 * Cada regla tiene su formula y la tabla completa esta en captacion/README.md §24. Orden: impacto descendente; con empate, el orden de la lista de reglas. Con 0 acciones el panel dice «Todo al día».
 *
 *   hot       leads calientes en «Nuevo» sin primer contacto   60 + 6·min(n,5) + 10 si el mas antiguo espera mas de 15 min   → /leads?t=hot&ct=none
 *   failed    trabajos fallidos en la cola                     55 + 5·min(n,5)                                              → /#cola
 *   overdue   tareas vencidas                                  30 + 4·min(n,10) + 10 si la mas antigua tiene mas de 2 dias  → /tareas?tab=overdue
 *   presented presentados a NorthStar sin respuesta            25 + 5·min(n,5)                                              → /leads?hs=wait
 *   followups seguimientos de prospectos de hoy o vencidos     25 + 3·min(n,8)                                              → /prospectos
 *   no_task   leads abiertos sin ninguna tarea                 20 + 2·min(n,10)                                             → /leads?nt=1&op=1
 *   referrals bonos de referidos por aprobar                   20 + 2·min(n,5)                                              → /referidos
 *   review    leads en la cola de revision de las reglas       15 + 2·min(n,10)                                             → /leads?fl=review_queue
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Clock;
use Captacion\Db;
use Captacion\Ui;

final class DashboardActions
{
    public const MAX = 3;

    /** @return list<array{key:string,title:string,detail:string,n:int,impact:int,href:string,cta:string,level:string}> */
    public static function compute(int $now, array $hot): array
    {
        return self::rank(self::inputs($now, $hot));
    }

    /** Cuentas que alimentan las reglas (una consulta pequena por regla). @return array<string,mixed> */
    public static function inputs(int $now, array $hot): array
    {
        $overdue = (int) Db::value('SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL AND done_at IS NULL AND due_at IS NOT NULL AND due_at < ?', [Clock::at($now)]);
        $oldest = $overdue > 0 ? Db::value('SELECT MIN(due_at) FROM tasks WHERE deleted_at IS NULL AND done_at IS NULL AND due_at IS NOT NULL AND due_at < ?', [Clock::at($now)]) : null;
        return [
            'hot' => (int) $hot['n'], 'hot_secs' => $hot['oldest_secs'],
            'failed' => (int) Db::value("SELECT COUNT(*) FROM jobs WHERE status = 'failed'"),
            'overdue' => $overdue, 'overdue_secs' => $oldest !== null ? Fmt::secondsBetween((string) $oldest, Clock::at($now)) : null,
            'presented' => LeadQuery::count(['hs' => 'wait']),
            'followups' => Prospects::followupsDue($now)['n'],
            'no_task' => LeadQuery::count(['nt' => '1', 'op' => '1']),
            'referrals' => Referrals::pendingApproval(),
            'review' => LeadQuery::count(['fl' => 'review_queue']),
        ];
    }

    /**
     * Pura: de las cuentas a la lista final (la que prueban los tests, sin base de datos).
     * @param array<string,mixed> $in
     * @return list<array{key:string,title:string,detail:string,n:int,impact:int,href:string,cta:string,level:string}>
     */
    public static function rank(array $in): array
    {
        $c = static fn(string $k): int => max(0, (int) ($in[$k] ?? 0));
        $cap = static fn(int $v): int => max(0, min(100, $v));
        $list = [];
        $push = static function (string $key, int $order, int $n, int $impact, string $title, string $detail, string $href, string $cta) use (&$list, $cap): void {
            if ($n > 0) {
                $impact = $cap($impact);
                $list[] = ['key' => $key, 'title' => $title, 'detail' => $detail, 'n' => $n, 'impact' => $impact, 'href' => $href, 'cta' => $cta, 'level' => $impact >= 70 ? 'high' : ($impact >= 40 ? 'mid' : 'low'), 'o' => $order];
            }
        };
        $pl = static fn(int $n, string $one, string $many): string => Fmt::num($n) . ' ' . ($n === 1 ? $one : $many);

        $n = $c('hot');
        $secs = $in['hot_secs'] ?? null;
        $push('hot', 0, $n, 60 + 6 * min($n, 5) + ($secs !== null && $secs > 900 ? 10 : 0), 'Contacta a los calientes',
            $pl($n, 'espera contacto', 'esperan contacto') . ($secs !== null && $secs >= 60 ? ' · el más antiguo, desde hace ' . Fmt::dur((int) $secs) : ''), '/leads' . Ui::qs(['t' => 'hot', 'ct' => 'none']), 'Abrir la vista');
        $n = $c('failed');
        $push('failed', 1, $n, 55 + 5 * min($n, 5), 'Revisa los trabajos fallidos', $pl($n, 'trabajo fallido', 'trabajos fallidos') . ' en la cola', '/#cola', 'Ver el estado');
        $n = $c('overdue');
        $secs = $in['overdue_secs'] ?? null;
        $push('overdue', 2, $n, 30 + 4 * min($n, 10) + ($secs !== null && $secs > 2 * 86400 ? 10 : 0), 'Pon al día las tareas vencidas',
            $pl($n, 'vencida', 'vencidas') . ($secs !== null && $secs >= 3600 ? ' · la más antigua, desde hace ' . Fmt::dur((int) $secs) : ''), '/tareas?tab=overdue', 'Ver tareas vencidas');
        $n = $c('presented');
        $push('presented', 3, $n, 25 + 5 * min($n, 5), 'Pregunta a NorthStar por los presentados', $pl($n, 'presentado sin respuesta', 'presentados sin respuesta'), '/leads' . Ui::qs(['hs' => 'wait']), 'Abrir la vista');
        $n = $c('followups');
        $push('followups', 4, $n, 25 + 3 * min($n, 8), 'Retoma los prospectos sin gestionar', $pl($n, 'seguimiento', 'seguimientos') . ' para hoy o ya vencidos', '/prospectos', 'Abrir prospectos');
        $n = $c('no_task');
        $push('no_task', 5, $n, 20 + 2 * min($n, 10), 'Dale seguimiento a los leads sin tarea', $pl($n, 'lead abierto', 'leads abiertos') . ' sin ninguna tarea', '/leads' . Ui::qs(['nt' => '1', 'op' => '1']), 'Abrir la vista');
        $n = $c('referrals');
        $push('referrals', 6, $n, 20 + 2 * min($n, 5), 'Resuelve los bonos de referidos', $pl($n, 'bono', 'bonos') . ' por aprobar', '/referidos', 'Abrir referidos');
        $n = $c('review');
        $push('review', 7, $n, 15 + 2 * min($n, 10), 'Revisa la cola de las reglas', $pl($n, 'lead por revisar', 'leads por revisar'), '/leads' . Ui::qs(['fl' => 'review_queue']), 'Abrir la vista');

        usort($list, static fn($a, $b) => [$b['impact'], $a['o']] <=> [$a['impact'], $b['o']]);
        return array_map(static function (array $a): array {
            unset($a['o']);
            return $a;
        }, array_slice($list, 0, self::MAX));
    }
}
