<?php
/**
 * Plantillas iniciales de reglas (Q5b): 5 reglas de EJEMPLO repartidas en tres conjuntos, todas DESACTIVADAS y marcadas «ejemplo». Se instalan como BORRADOR (no se publican nunca solas).
 * Los puntos y las listas son ilustrativos y provisionales: no son una verdad del negocio; hay que ajustarlos a lo que NorthStar confirme antes de activarlas.
 */

declare(strict_types=1);

namespace Captacion\Services;

final class RuleTemplates
{
    /** @return list<array{kind:string,name:string,description:string,rules:list<array>}> */
    public static function all(): array
    {
        $r = static fn(string $id, string $name, array $when, array $then, ?array $except = null): array => ['id' => $id, 'name' => $name, 'enabled' => false, 'stop' => false, 'example' => true, 'when' => $when, 'except' => $except, 'then' => $then];
        $c = static fn(string $field, string $op, mixed $value = null): array => ['field' => $field, 'op' => $op, 'value' => $value];
        return [
            [
                'kind' => 'scoring', 'name' => 'Ejemplos de puntuación', 'description' => 'Reglas de ejemplo desactivadas. Los puntos y las listas son ilustrativos y provisionales: ajústalos antes de activarlas.',
                'rules' => [
                    $r('ej_fuente_baja', 'No puntuar fuentes de baja calidad', ['mode' => 'all', 'items' => [$c('source', 'in', ['indeed', 'import'])]],
                        [['type' => 'add_points', 'points' => -15, 'cap' => 0]], ['mode' => 'all', 'items' => [$c('has_usdot', 'is', true)]]),
                    $r('ej_flotas_grandes', 'Más puntos a flotas de 6 camiones o más', ['mode' => 'all', 'items' => [$c('trucks', 'gte', 6), ['mode' => 'any', 'items' => [$c('equipment', 'in', ['dry-van', 'reefer']), $c('profile', 'is', 'small_carrier')]]]],
                        [['type' => 'add_points', 'points' => 5, 'cap' => 95]]),
                ],
            ],
            [
                'kind' => 'alerts', 'name' => 'Ejemplos de alertas', 'description' => 'Regla de ejemplo desactivada. Respeta el horario de silencio, evita repetirse y tiene un tope por hora.',
                'rules' => [
                    $r('ej_owner_usdot', 'Avisar de un owner-operator con USDOT y 3 camiones o más', ['mode' => 'all', 'items' => [$c('profile', 'is', 'owner_operator'), $c('has_usdot', 'is', true), $c('trucks', 'gte', 3)]],
                        [['type' => 'telegram_alert', 'urgency' => 'normal', 'text' => 'Owner-operator con {camiones} camiones y USDOT: {nombre} ({estado}), puntuación {puntuacion}.']]),
                ],
            ],
            [
                'kind' => 'routing_tasks', 'name' => 'Ejemplos de tareas y etiquetas', 'description' => 'Reglas de ejemplo desactivadas. Las tareas y etiquetas se crean solo para leads nuevos o que cambian, no al recalcular en masa.',
                'rules' => [
                    $r('ej_llamar_calientes', 'Llamar en 2 horas a los calientes', ['mode' => 'all', 'items' => [$c('temperature', 'is', 'hot'), $c('stage', 'is', 'new')]],
                        [['type' => 'create_task', 'title' => 'Llamar a {nombre} ({perfil})', 'due_hours' => 2, 'priority' => 1]]),
                    $r('ej_fmcsa_revisar', 'Revisar a mano a quien traiga una bandera de FMCSA', ['mode' => 'any', 'items' => [$c('fmcsa_flag', 'in', ['authority_inactive', 'no_insurance'])]],
                        [['type' => 'review_queue'], ['type' => 'tag', 'tag' => 'revisar-fmcsa']]),
                ],
            ],
        ];
    }

    /** Crea los conjuntos de ejemplo como borrador. Los que ya existen (mismo nombre) se saltan. @return array{ok:bool,msg:string,created:int} */
    public static function install(int $userId): array
    {
        $existing = array_column(RuleSets::all(), 'name');
        $created = 0;
        foreach (self::all() as $t) {
            if (in_array($t['name'], $existing, true)) {
                continue;
            }
            $v = Rules::normalize($t['rules'], $t['kind']);
            if (!$v['ok']) {
                throw new \LogicException('La plantilla «' . $t['name'] . '» no valida: ' . json_encode($v['errors']));
            }
            $r = RuleSets::create($t['kind'], $t['name'], $t['description'], $userId);
            if (!$r['ok']) {
                return ['ok' => $created > 0, 'msg' => $r['msg'], 'created' => $created];
            }
            RuleSets::saveDraft((int) $r['id'], $v['rules'], 'Plantilla de ejemplo', $userId);
            $created++;
        }
        return ['ok' => true, 'created' => $created, 'msg' => $created > 0 ? 'Se crearon ' . $created . ' conjuntos de ejemplo como borrador, con las reglas desactivadas.' : 'Los conjuntos de ejemplo ya estaban creados.'];
    }
}
