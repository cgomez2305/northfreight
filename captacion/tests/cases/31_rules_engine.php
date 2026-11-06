<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Models\Lead;
use Captacion\Services\Alerts;
use Captacion\Services\JobHandlers;
use Captacion\Services\LeadIngest;
use Captacion\Services\Queue;
use Captacion\Services\RuleEffects;
use Captacion\Services\RuleEngine;
use Captacion\Services\RulePhrase;
use Captacion\Services\RuleRunner;
use Captacion\Services\Rules;
use Captacion\Services\RuleSets;
use Captacion\Services\RuleSim;
use Captacion\Services\RuleTemplates;
use Captacion\Services\Scoring;
use Captacion\Services\Settings;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\T;

/**
 * Q5b-A/B: modelo de reglas CUANDO / ENTONCES / EXCEPTO. Validación (catálogo cerrado, rangos, topes), frases, evaluación pura (tablas, grupos Y/O, excepciones, orden, «detener al coincidir»,
 * topes de puntos), equivalencia sin reglas con la puntuación del documento, aplicación en el motor con reloj simulado (puntos, temperatura mínima, alerta, tarea, etiqueta, cola de revisión),
 * versiones (borrador, publicar atómico, historial, diferencia, revertir, retirar), vista previa y simulación SIN escritura, recálculo por la cola y auditoría.
 */
return static function (array $ctx): void {
    T::reset(true);
    $admin = \CaptacionTests\Panel::user('admin');
    $uid = (int) $admin['id'];
    $now = Clock::time();
    Env::set('TELEGRAM_BOT_TOKEN', null);
    Env::set('TELEGRAM_CHAT_ID', null);
    Env::set('FMCSA_WEBKEY', null);
    $cond = static fn(string $f, string $op, mixed $v = null): array => ['field' => $f, 'op' => $op, 'value' => $v];
    $rule = static fn(array $o = []): array => $o + ['name' => 'Regla de prueba', 'enabled' => true, 'when' => ['mode' => 'all', 'items' => [['field' => 'profile', 'op' => 'is', 'value' => 'owner_operator']]], 'then' => [['type' => 'add_points', 'points' => 5]]];
    $norm = static fn(mixed $rules, string $kind = 'scoring'): array => Rules::normalize($rules, $kind);
    $errs = static fn(array $rules, string $kind = 'scoring'): array => array_keys(Rules::normalize($rules, $kind)['errors']);

    // ------------------------------------------------------------------ Validación: forma, ids, límites
    $r = $norm([$rule(['id' => 'abc_1'])]);
    T::ok($r['ok'] && $r['count'] === 1 && $r['rules'][0]['id'] === 'abc_1' && $r['rules'][0]['enabled'] === true && $r['rules'][0]['stop'] === false && $r['rules'][0]['except'] === null, 'validación: una regla válida se normaliza (id, activa, sin detener, sin excepción)');
    $r = $norm([$rule(['id' => 'dup']), $rule(['id' => 'dup'])]);
    T::ok($r['ok'] && $r['rules'][0]['id'] === 'dup' && $r['rules'][1]['id'] !== 'dup' && preg_match('/^[a-z0-9_]{1,24}$/', $r['rules'][1]['id']) === 1, '  un id repetido (regla duplicada) recibe uno nuevo');
    T::ok(preg_match('/^r[0-9a-f]{6}$/', $norm([$rule(['id' => 'MAL ID!'])])['rules'][0]['id']) === 1, '  un id con caracteres ajenos se reemplaza');
    T::eq($norm([$rule(['enabled' => '0', 'stop' => 'false', 'example' => ''])])['rules'][0]['enabled'], false, '  «0» y «false» llegan como falso (checkbox del formulario)');
    T::ok($norm([$rule(['enabled' => '1', 'stop' => 'on'])])['rules'][0]['stop'] === true, '  «1» y «on» llegan como verdadero');
    T::eq($errs([$rule(['name' => ''])]), ['rules.0.name'], 'validación: la regla sin nombre da error en su campo');
    T::eq($errs([$rule(['when' => ['mode' => 'all', 'items' => []]])]), ['rules.0.when.items'], '  sin condiciones: error');
    T::eq($errs([$rule(['then' => []])]), ['rules.0.then'], '  sin acciones: error');
    T::ok(in_array('rules', $errs(array_fill(0, Rules::MAX_RULES + 1, $rule())), true), 'límite: más de ' . Rules::MAX_RULES . ' reglas por conjunto da error');
    $many = array_fill(0, Rules::MAX_ITEMS + 1, $cond('profile', 'is', 'owner_operator'));
    T::ok(in_array('rules.0.when.items', $errs([$rule(['when' => ['mode' => 'all', 'items' => $many]])]), true), 'límite: más de ' . Rules::MAX_ITEMS . ' condiciones en un bloque da error');
    $big = [$rule(['name' => str_repeat('x', 80), 'when' => ['mode' => 'all', 'items' => [$cond('campaign', 'in', array_fill(0, 30, str_repeat('a', 60)))]]])];
    $bigMany = array_fill(0, 40, $big[0]);
    T::ok(isset(Rules::normalize($bigMany, 'scoring')['errors']['rules']) && str_contains(Rules::normalize($bigMany, 'scoring')['errors']['rules'], 'grande'), 'límite: un conjunto de más de 60 KB se rechaza');
    T::ok(!Rules::normalize([], 'otro')['ok'], 'validación: un tipo de conjunto desconocido se rechaza');
    T::eq($norm('no es un arreglo')['rules'], [], '  y un valor que no es lista no rompe nada');
    $grp = ['mode' => 'any', 'items' => [$cond('state', 'in', 'tx, fl'), $cond('trucks', 'gte', 3)]];
    $r = $norm([$rule(['when' => ['mode' => 'all', 'items' => [$cond('profile', 'is', 'owner_operator'), $grp]]])]);
    T::ok($r['ok'] && $r['rules'][0]['when']['items'][1]['items'][0]['value'] === ['TX', 'FL'], 'grupos: un nivel de grupos válido (y la lista de estados se normaliza a mayúsculas)');
    $nest = ['mode' => 'all', 'items' => [$cond('profile', 'is', 'owner_operator'), ['mode' => 'any', 'items' => [$cond('state', 'is', 'TX'), ['mode' => 'all', 'items' => [$cond('state', 'is', 'FL')]]]]]];
    T::ok(in_array('rules.0.when.items.1.items.1', $errs([$rule(['when' => $nest])]), true), 'grupos: un grupo dentro de un grupo se rechaza (un solo nivel)');
    T::ok(in_array('rules.0.when.items.0.items', $errs([$rule(['when' => ['mode' => 'all', 'items' => [['mode' => 'all', 'items' => []]]]])]), true), 'grupos: un grupo vacío da error');

    // ------------------------------------------------------------------ Validación: cada condición y operador
    // [campo, operador, valor, ¿válido?]
    $table = [
        ['profile', 'is', 'owner_operator', true], ['profile', 'is', 'camionero', false], ['profile', 'in', ['owner_operator', 'small_carrier'], true], ['profile', 'in', [], false], ['profile', 'not_in', 'company_driver', true],
        ['profile', 'exists', null, true], ['profile', 'gt', 3, false], ['profile', 'contains', 'x', false],
        ['state', 'is', 'tx', true], ['state', 'is', 'ZZ', false], ['state', 'in', 'TX, TN, FL', true], ['state', 'in', 'TX, QQ', false], ['state', 'not_in', ['FL'], true], ['state', 'not_exists', null, true],
        ['equipment', 'in', ['dry-van', 'reefer'], true], ['equipment', 'all', ['dry-van'], true], ['equipment', 'not_in', ['flatbed'], true], ['equipment', 'in', ['camion-volador'], false], ['equipment', 'is', 'dry-van', false], ['equipment', 'exists', null, true],
        ['years', 'gte', 2, true], ['years', 'gt', '2.5', true], ['years', 'between', [1, 5], true], ['years', 'between', [5, 1], false], ['years', 'between', [1], false], ['years', 'gt', 'mucho', false], ['years', 'gt', 81, false], ['years', 'gt', -1, false], ['years', 'in', [1], false],
        ['trucks', 'is', 3, true], ['trucks', 'lt', 10000, false], ['trucks', 'is_not', 0, true], ['trucks', 'exists', null, true],
        ['source', 'is', 'meta', true], ['source', 'in', ['meta', 'indeed'], true], ['source', 'is', 'tiktok', false],
        ['campaign', 'contains', 'Verano', true], ['campaign', 'is', 'x', true], ['campaign', 'in', 'a, b', true], ['campaign', 'contains', '<b>', false], ['campaign', 'contains', '', false], ['campaign', 'between', [1, 2], false],
        ['language', 'is', 'es', true], ['language', 'is', 'fr', false],
        ['has_usdot', 'is', true, true], ['has_usdot', 'is', 'no', true], ['has_usdot', 'is', 'quizas', false], ['has_usdot', 'in', ['yes'], false],
        ['fmcsa_flag', 'in', ['authority_inactive', 'no_insurance'], true], ['fmcsa_flag', 'in', ['otra'], false], ['fmcsa_flag', 'not_exists', null, true],
        ['authority_age', 'lt', 6, true], ['authority_age', 'gt', 601, false],
        ['consent', 'in', ['sms'], true], ['consent', 'in', ['whatsapp'], false], ['consent', 'not_in', ['call', 'email'], true],
        ['hours_since', 'gt', 2, true], ['hours_since', 'gt', '1.5', true], ['hours_since', 'gt', 9000, false],
        ['stage', 'is', 'new', true], ['stage', 'in', ['new', 'contacted'], true], ['stage', 'is', 'hired', true], ['stage', 'is', 'inventada', false],
        ['score', 'gte', 70, true], ['score', 'gt', 101, false], ['temperature', 'is', 'hot', true], ['temperature', 'in', ['hot', 'warm'], true], ['temperature', 'is', 'ardiente', false],
        ['landing', 'contains', '/careers', true], ['landing', 'is', '/careers/owner-operators/', true], ['landing', 'exists', null, true],
        ['inventado', 'is', 'x', false], ['', 'is', 'x', false],
    ];
    $bad = [];
    foreach ($table as [$f, $op, $v, $ok]) {
        $res = Rules::normalize([$rule(['when' => ['mode' => 'all', 'items' => [$cond($f, $op, $v)]]])], 'scoring');
        if ($res['ok'] !== $ok) {
            $bad[] = "$f $op " . json_encode($v) . ' esperaba ' . ($ok ? 'válido' : 'error') . ' y dio ' . json_encode($res['errors']);
        }
    }
    T::eq($bad, [], 'validación: ' . count($table) . ' combinaciones campo, operador y valor (cada tipo, rangos, listas cerradas)');
    T::eq(count(Rules::FIELDS), 17, 'catálogo: 17 condiciones');
    $opsSeen = [];
    foreach (Rules::OPS_BY_TYPE as $ops) {
        $opsSeen = array_merge($opsSeen, $ops);
    }
    T::eq(array_values(array_unique($opsSeen)), ['is', 'is_not', 'in', 'not_in', 'exists', 'not_exists', 'all', 'gt', 'gte', 'lt', 'lte', 'between', 'contains', 'not_contains'], 'catálogo: los 14 operadores (es, no es, en lista, existe, mayor, menor, entre…)');
    $okVals = $norm([$rule(['when' => ['mode' => 'all', 'items' => [$cond('campaign', 'contains', '  Verano  '), $cond('years', 'gt', '2.50'), $cond('equipment', 'in', 'DRY-VAN; reefer, dry-van')]]])])['rules'][0]['when']['items'];
    T::eq([$okVals[0]['value'], $okVals[1]['value'], $okVals[2]['value']], ['verano', 2.5, ['dry-van', 'reefer']], 'normalización: texto en minúsculas sin espacios sobrantes, decimales y listas sin repetidos');
    T::ok(in_array('rules.0.when.items.0.value', $errs([$rule(['when' => ['mode' => 'all', 'items' => [$cond('profile', 'is', 'zzz')]]])]), true), 'errores: la ruta del error apunta al campo exacto del formulario');

    // ------------------------------------------------------------------ Validación: acciones
    $act = static fn(array $a, string $kind = 'scoring'): array => Rules::normalize([$rule(['then' => [$a]])], $kind);
    $actTable = [
        ['scoring', ['type' => 'add_points', 'points' => 10], true], ['scoring', ['type' => 'add_points', 'points' => -15, 'cap' => 0], true], ['scoring', ['type' => 'add_points', 'points' => 30], true], ['scoring', ['type' => 'add_points', 'points' => 31], false],
        ['scoring', ['type' => 'add_points', 'points' => -31], false], ['scoring', ['type' => 'add_points', 'points' => 0], false], ['scoring', ['type' => 'add_points', 'points' => 'diez'], false], ['scoring', ['type' => 'add_points', 'points' => 5, 'cap' => 101], false],
        ['scoring', ['type' => 'add_points', 'points' => 5, 'cap' => 'x'], false], ['scoring', ['type' => 'add_points', 'points' => '+7', 'cap' => '90'], true],
        ['scoring', ['type' => 'min_temperature', 'level' => 'hot'], true], ['scoring', ['type' => 'min_temperature', 'level' => 'warm'], true], ['scoring', ['type' => 'min_temperature', 'level' => 'cold'], false],
        ['alerts', ['type' => 'telegram_alert', 'urgency' => 'urgent', 'text' => 'Lead {nombre} de {estado}'], true], ['alerts', ['type' => 'telegram_alert', 'text' => ''], false],
        ['alerts', ['type' => 'telegram_alert', 'text' => 'Llama al {telefono}'], false], ['alerts', ['type' => 'telegram_alert', 'text' => 'Correo {email}'], false], ['alerts', ['type' => 'telegram_alert', 'text' => '<b>hola</b>'], false],
        ['alerts', ['type' => 'telegram_alert', 'text' => 'Abre {nombre'], false], ['alerts', ['type' => 'telegram_alert', 'text' => 'Cierra }'], false], ['alerts', ['type' => 'telegram_alert', 'text' => str_repeat('a', 201)], false],
        ['alerts', ['type' => 'telegram_alert', 'text' => '{perfil} {equipo} {camiones} {anos} {puntuacion} {temperatura} {fuente} {etapa}'], true],
        ['routing_tasks', ['type' => 'create_task', 'title' => 'Llamar a {nombre}', 'due_hours' => 2], true], ['routing_tasks', ['type' => 'create_task', 'title' => 'Llamar', 'due_hours' => 0], false], ['routing_tasks', ['type' => 'create_task', 'title' => 'Llamar', 'due_hours' => 721], false],
        ['routing_tasks', ['type' => 'create_task', 'title' => '', 'due_hours' => 2], false], ['routing_tasks', ['type' => 'create_task', 'title' => 'x {clave}', 'due_hours' => 2], false], ['routing_tasks', ['type' => 'create_task', 'title' => str_repeat('a', 121), 'due_hours' => 2], false],
        ['routing_tasks', ['type' => 'tag', 'tag' => 'Revisar FMCSA'], true], ['routing_tasks', ['type' => 'tag', 'tag' => '!!!'], false], ['routing_tasks', ['type' => 'tag', 'tag' => ''], false], ['routing_tasks', ['type' => 'tag', 'tag' => str_repeat('a', 25)], false],
        ['routing_tasks', ['type' => 'review_queue'], true],
        ['alerts', ['type' => 'add_points', 'points' => 5], false], ['scoring', ['type' => 'telegram_alert', 'text' => 'x'], false], ['scoring', ['type' => 'review_queue'], false], ['routing_tasks', ['type' => 'min_temperature', 'level' => 'hot'], false],
        ['scoring', ['type' => 'borrar_leads'], false], ['scoring', [], false],
    ];
    $bad = [];
    foreach ($actTable as [$kind, $a, $ok]) {
        $res = $act($a, $kind);
        if ($res['ok'] !== $ok) {
            $bad[] = $kind . ' ' . json_encode($a, JSON_UNESCAPED_UNICODE) . ' esperaba ' . ($ok ? 'válida' : 'error') . ' y dio ' . json_encode($res['errors']);
        }
    }
    T::eq($bad, [], 'validación: ' . count($actTable) . ' acciones (rangos, topes, variables permitidas, tipo del conjunto)');
    T::eq(array_keys(Rules::ACTIONS), ['add_points', 'min_temperature', 'telegram_alert', 'create_task', 'tag', 'review_queue'], 'catálogo: 6 acciones (puntos, temperatura mínima, Telegram, tarea, etiqueta, cola de revisión)');
    T::eq($act(['type' => 'tag', 'tag' => 'Revisar FMCSA'], 'routing_tasks')['rules'][0]['then'][0]['tag'], 'revisar-fmcsa', 'normalización: la etiqueta se vuelve minúsculas con guiones');
    T::ok(in_array('rules.0.then.1.type', $errs([$rule(['then' => [['type' => 'add_points', 'points' => 5], ['type' => 'add_points', 'points' => 6]]])]), true), 'validación: la misma acción dos veces en una regla da error');
    T::ok(in_array('rules.0.then', $errs([$rule(['then' => array_fill(0, 5, ['type' => 'tag', 'tag' => 'a'])])], 'routing_tasks'), true), 'límite: más de 4 acciones por regla da error');
    T::eq(Rules::render('Hola {nombre} {inexistente}', ['nombre' => "Ana\n<script>"]), 'Hola Ana script {inexistente}', 'render: solo variables de la lista blanca, sin saltos de línea ni signos < >');
    T::eq(Rules::render('{camiones}', []), '', '  una variable sin dato queda vacía');

    // ------------------------------------------------------------------ Frases legibles
    $ph = $norm([$rule(['when' => ['mode' => 'all', 'items' => [$cond('profile', 'is', 'owner_operator'), $cond('state', 'in', ['TX', 'TN', 'FL', 'GA', 'AL', 'MS', 'LA', 'OK']), ['mode' => 'any', 'items' => [$cond('trucks', 'gte', 3), $cond('years', 'between', [2, 5])]]]],
        'except' => ['mode' => 'any', 'items' => [$cond('has_usdot', 'is', false), $cond('source', 'is', 'import')]], 'then' => [['type' => 'add_points', 'points' => -10, 'cap' => 20], ['type' => 'x']]])])['rules'][0];
    T::eq(RulePhrase::when($ph), 'Cuando el perfil es Owner-operator y el estado está en TX, TN, FL, GA, AL, MS y 2 más y (los camiones es al menos 3 o los años de experiencia está entre 2 años y 5 años)', 'frase: CUANDO con lista larga y un grupo entre paréntesis');
    T::eq(RulePhrase::except($ph), 'Excepto cuando no tiene USDOT o la fuente es Importación', 'frase: EXCEPTO');
    T::eq(RulePhrase::action(['type' => 'add_points', 'points' => -10, 'cap' => 20]), 'restar 10 puntos (sin bajar de 20)', 'frase: restar con tope');
    T::eq(RulePhrase::action(['type' => 'add_points', 'points' => 1]), 'sumar 1 punto', '  sumar un punto');
    T::eq([RulePhrase::action(['type' => 'min_temperature', 'level' => 'hot']), RulePhrase::action(['type' => 'review_queue']), RulePhrase::action(['type' => 'tag', 'tag' => 'a'])], ['dejar la temperatura al menos en Caliente', 'enviar el lead a la cola de revisión', 'poner la etiqueta «a»'], '  y las demás acciones');
    T::eq(RulePhrase::cond($cond('equipment', 'not_in', ['flatbed'])), 'el equipo no incluye ninguno de Flatbed', 'frase: operador de varios valores en español claro');
    T::eq(RulePhrase::cond($cond('campaign', 'exists')), 'la campaña tiene dato', 'frase: existe');

    // ------------------------------------------------------------------ Evaluación pura: condiciones (tabla de hechos)
    $facts = ['profile' => 'owner_operator', 'state' => 'TX', 'equipment' => ['dry-van', 'reefer'], 'years' => 4, 'trucks' => 3, 'source' => 'meta', 'campaign' => 'verano 2026', 'language' => 'en', 'has_usdot' => true,
        'fmcsa_flag' => ['no_insurance'], 'authority_age' => 8, 'consent' => ['sms', 'call'], 'hours_since' => 1.5, 'stage' => 'new', 'landing' => '/careers/owner-operators/', 'score' => 60, 'temperature' => 'warm'];
    $none = ['profile' => 'owner_operator', 'state' => null, 'equipment' => [], 'years' => null, 'trucks' => null, 'source' => 'meta', 'campaign' => null, 'language' => 'en', 'has_usdot' => false, 'fmcsa_flag' => [], 'authority_age' => null,
        'consent' => [], 'hours_since' => 0.0, 'stage' => 'new', 'landing' => null, 'score' => 0, 'temperature' => 'cold'];
    $evalTable = [
        [$cond('profile', 'is', 'owner_operator'), $facts, true], [$cond('profile', 'is', 'company_driver'), $facts, false], [$cond('profile', 'is_not', 'company_driver'), $facts, true], [$cond('profile', 'in', ['small_carrier', 'owner_operator']), $facts, true],
        [$cond('profile', 'not_in', ['owner_operator']), $facts, false],
        [$cond('state', 'in', ['TX', 'FL']), $facts, true], [$cond('state', 'in', ['TX', 'FL']), $none, false], [$cond('state', 'not_in', ['TX']), $none, true], [$cond('state', 'is_not', 'TX'), $none, true], [$cond('state', 'exists'), $none, false], [$cond('state', 'not_exists'), $none, true],
        [$cond('equipment', 'in', ['reefer', 'flatbed']), $facts, true], [$cond('equipment', 'all', ['reefer', 'flatbed']), $facts, false], [$cond('equipment', 'all', ['reefer', 'dry-van']), $facts, true], [$cond('equipment', 'not_in', ['flatbed']), $facts, true],
        [$cond('equipment', 'not_in', ['reefer']), $facts, false], [$cond('equipment', 'exists'), $none, false], [$cond('equipment', 'not_exists'), $none, true],
        [$cond('years', 'gt', 4), $facts, false], [$cond('years', 'gte', 4), $facts, true], [$cond('years', 'lt', 5), $facts, true], [$cond('years', 'lte', 3), $facts, false], [$cond('years', 'between', [4, 4]), $facts, true], [$cond('years', 'between', [5, 9]), $facts, false],
        [$cond('years', 'is', 4), $facts, true], [$cond('years', 'is_not', 4), $facts, false], [$cond('years', 'gt', 0), $none, false], [$cond('years', 'is_not', 4), $none, true], [$cond('years', 'not_exists'), $none, true], [$cond('years', 'exists'), $facts, true],
        [$cond('trucks', 'gte', 3), $facts, true], [$cond('trucks', 'lt', 3), $facts, false],
        [$cond('source', 'in', ['meta', 'indeed']), $facts, true], [$cond('source', 'is', 'indeed'), $facts, false],
        [$cond('campaign', 'contains', 'verano'), $facts, true], [$cond('campaign', 'not_contains', 'verano'), $facts, false], [$cond('campaign', 'not_contains', 'verano'), $none, true], [$cond('campaign', 'is', 'verano 2026'), $facts, true],
        [$cond('campaign', 'in', ['otoño', 'verano 2026']), $facts, true], [$cond('campaign', 'contains', 'x'), $none, false], [$cond('campaign', 'exists'), $none, false], [$cond('campaign', 'not_exists'), $none, true],
        [$cond('language', 'is', 'en'), $facts, true], [$cond('has_usdot', 'is', true), $facts, true], [$cond('has_usdot', 'is', true), $none, false], [$cond('has_usdot', 'is', false), $none, true],
        [$cond('fmcsa_flag', 'in', ['no_insurance', 'authority_inactive']), $facts, true], [$cond('fmcsa_flag', 'in', ['authority_inactive']), $facts, false], [$cond('fmcsa_flag', 'not_exists'), $none, true], [$cond('fmcsa_flag', 'exists'), $facts, true],
        [$cond('authority_age', 'lt', 12), $facts, true], [$cond('authority_age', 'lt', 12), $none, false],
        [$cond('consent', 'in', ['sms']), $facts, true], [$cond('consent', 'in', ['email']), $facts, false], [$cond('consent', 'not_in', ['email']), $facts, true], [$cond('consent', 'all', ['sms', 'call']), $facts, true], [$cond('consent', 'all', ['sms', 'email']), $facts, false],
        [$cond('hours_since', 'gt', 1), $facts, true], [$cond('hours_since', 'gt', 2), $facts, false], [$cond('hours_since', 'between', [1, 2]), $facts, true],
        [$cond('stage', 'is', 'new'), $facts, true], [$cond('stage', 'in', ['contacted', 'qualified']), $facts, false],
        [$cond('score', 'gte', 60), $facts, true], [$cond('score', 'gt', 60), $facts, false], [$cond('temperature', 'is', 'warm'), $facts, true], [$cond('temperature', 'in', ['hot', 'warm']), $facts, true],
        [$cond('landing', 'contains', '/careers'), $facts, true], [$cond('landing', 'is', '/careers/owner-operators/'), $facts, true], [$cond('landing', 'exists'), $facts, true], [$cond('landing', 'not_contains', '/careers'), $facts, false],
    ];
    $bad = [];
    foreach ($evalTable as $i => [$c, $f, $want]) {
        if (RuleEngine::cond($c, $f) !== $want) {
            $bad[] = "#$i " . json_encode($c) . ' esperaba ' . json_encode($want);
        }
    }
    T::eq($bad, [], 'evaluación: ' . count($evalTable) . ' casos de tabla (cada tipo y operador, con datos ausentes)');
    // Grupos Y/O y excepciones
    $A = $cond('profile', 'is', 'owner_operator');
    $B = $cond('state', 'is', 'FL');
    $C = $cond('trucks', 'gte', 3);
    $blk = static fn(string $mode, array ...$items): array => ['mode' => $mode, 'items' => $items];
    T::ok(RuleEngine::block($blk('all', $A, $C), $facts) && !RuleEngine::block($blk('all', $A, $B), $facts) && RuleEngine::block($blk('any', $B, $C), $facts) && !RuleEngine::block($blk('any', $B, $cond('years', 'gt', 9)), $facts), 'evaluación: bloques Y (todas) y O (alguna)');
    T::ok(RuleEngine::block($blk('all', $A, $blk('any', $B, $C)), $facts) && !RuleEngine::block($blk('all', $A, $blk('any', $B, $cond('years', 'gt', 9))), $facts) && RuleEngine::block($blk('any', $B, $blk('all', $A, $C)), $facts), 'evaluación: un grupo dentro de un bloque Y u O');
    T::ok(!RuleEngine::block(['mode' => 'all', 'items' => []], $facts) && !RuleEngine::block(null, $facts), 'evaluación: un bloque vacío o ausente nunca coincide');
    $exRule = ['id' => 'a', 'name' => 'a', 'enabled' => true, 'when' => $blk('all', $A), 'except' => $blk('any', $B, $cond('has_usdot', 'is', true)), 'then' => [['type' => 'tag', 'tag' => 'x']]];
    T::eq([RuleEngine::matches($exRule, $facts), RuleEngine::matches($exRule, ['has_usdot' => false] + $facts), RuleEngine::matches($exRule, ['profile' => 'company_driver'] + $facts)], ['excepted', 'match', 'no_match'], 'evaluación: EXCEPTO anula la regla aunque CUANDO coincida');

    // ------------------------------------------------------------------ Evaluación pura: orden, detener, topes
    $thr = ['hot_min' => 70, 'warm_min' => 40];
    $set = static fn(array $rules, string $kind = 'scoring', int $id = 1): array => ['set_id' => $id, 'version_id' => $id * 10, 'name' => 'S' . $id, 'kind' => $kind, 'version' => 1, 'rules' => $rules];
    $pts = static fn(string $id, int $p, ?int $cap = null, array $o = []): array => $o + ['id' => $id, 'name' => $id, 'enabled' => true, 'stop' => false, 'when' => $blk('all', $cond('profile', 'is', 'owner_operator')), 'except' => null, 'then' => [['type' => 'add_points', 'points' => $p, 'cap' => $cap]]];
    $run = static fn(array $sets, array $f, int $score = 50, string $temp = 'warm') => RuleEngine::run($sets, $f, ['score' => $score, 'temperature' => $temp], $thr);
    $o = $run([$set([$pts('a', 10), $pts('b', 15)])], $facts);
    T::eq([$o['score'], $o['temperature'], $o['adjust'], count($o['applied'])], [75, 'hot', 25, 2], 'motor: dos reglas suman en orden (50 + 10 + 15 = 75, caliente)');
    $o = $run([$set([$pts('a', 10, null, ['stop' => true]), $pts('b', 15)])], $facts);
    T::eq([$o['score'], count($o['applied']), $o['trace'][1]['result']], [60, 1, 'stopped'], 'motor: «detener al coincidir» corta el resto del conjunto');
    $o = $run([$set([$pts('a', 10, null, ['stop' => true])]), $set([$pts('b', 15)], 'scoring', 2)], $facts);
    T::eq($o['score'], 75, '  pero no corta los demás conjuntos');
    $o = $run([$set([$pts('a', 10, null, ['enabled' => false]), $pts('b', 5)])], $facts);
    T::eq([$o['score'], $o['trace'][0]['result']], [55, 'disabled'], 'motor: una regla desactivada no actúa');
    $o = $run([$set([$pts('a', 30), $pts('b', 30), $pts('c', 30)])], $facts, 50);
    T::eq([$o['score'], $o['adjust']], [90, 40], 'tope: el ajuste TOTAL de las reglas no pasa de +40 (50 + 40 = 90)');
    $o = $run([$set([$pts('a', -30), $pts('b', -30)])], $facts, 80, 'hot');
    T::eq([$o['score'], $o['adjust'], $o['temperature']], [40, -40, 'warm'], 'tope: ni de -40 (80 - 40 = 40, tibio)');
    $o = $run([$set([$pts('a', 30), $pts('b', 25)])], $facts, 95, 'hot');
    T::eq([$o['score'], $o['temperature']], [100, 'hot'], 'tope: la puntuación final nunca pasa de 100');
    $o = $run([$set([$pts('a', -30), $pts('b', -30)])], $facts, 10, 'cold');
    T::eq($o['score'], 0, 'tope: ni baja de 0');
    $o = $run([$set([$pts('a', 20, 55)])], $facts, 50);
    T::eq([$o['score'], $o['applied'][0]['effects'][0]['capped']], [55, true], 'tope de la regla: sumar sin pasar de 55 (50 + 20 se queda en 55)');
    $o = $run([$set([$pts('a', 20, 40)])], $facts, 50);
    T::eq($o['score'], 50, '  si ya está por encima del tope no suma ni resta');
    $o = $run([$set([$pts('a', -20, 45)])], $facts, 50);
    T::eq($o['score'], 45, 'tope de la regla: restar sin bajar de 45');
    $o = $run([$set([$pts('a', 10), $pts('b', 5, null, ['when' => $blk('all', $cond('score', 'gte', 60))]), $pts('c', 5, null, ['when' => $blk('all', $cond('score', 'gte', 70))])])], $facts, 50);
    T::eq([$o['score'], count($o['applied'])], [65, 2], 'sin ciclos: una condición sobre la puntuación solo ve lo que sumaron las reglas anteriores (una sola pasada)');
    $selfRef = $pts('a', 10, null, ['when' => $blk('all', $cond('score', 'gte', 50))]);
    $o1 = $run([$set([$selfRef])], $facts, 50);
    $o2 = $run([$set([$selfRef])], $facts, 50);
    T::ok($o1['score'] === 60 && $o1 === $o2, '  una regla que mira la puntuación y la cambia se evalúa una vez; el resultado es determinista');
    $o = $run([$set([['id' => 't', 'name' => 't', 'enabled' => true, 'stop' => false, 'when' => $blk('all', $A), 'except' => null, 'then' => [['type' => 'min_temperature', 'level' => 'hot']]]])], $facts, 50, 'warm');
    T::eq([$o['score'], $o['temperature'], $o['applied'][0]['effects'][0]['raised']], [50, 'hot', true], 'temperatura mínima: sube la temperatura sin cambiar la puntuación');
    $o = $run([$set([['id' => 't', 'name' => 't', 'enabled' => true, 'stop' => false, 'when' => $blk('all', $A), 'except' => null, 'then' => [['type' => 'min_temperature', 'level' => 'warm']]]])], $facts, 80, 'hot');
    T::eq($o['temperature'], 'hot', '  y nunca la baja');
    $o = $run([$set([$pts('a', -30), ['id' => 't', 'name' => 't', 'enabled' => true, 'stop' => false, 'when' => $blk('all', $A), 'except' => null, 'then' => [['type' => 'min_temperature', 'level' => 'hot']]]])], $facts, 80, 'hot');
    T::eq($o['temperature'], 'hot', '  la temperatura mínima manda aunque los puntos bajen');
    $o = $run([$set([$pts('a', 25)])], $facts, 0, 'disqualified');
    T::eq([$o['score'], $o['temperature'], $o['applied'][0]['effects']], [0, 'disqualified', []], 'descartados: las reglas no les cambian ni la puntuación ni la temperatura');
    // Alertas y tareas ven la puntuación final; el orden de conjuntos es puntuación, alertas, tareas aunque se den al revés
    $alert = ['id' => 'al', 'name' => 'al', 'enabled' => true, 'stop' => false, 'when' => $blk('all', $cond('temperature', 'is', 'hot')), 'except' => null, 'then' => [['type' => 'telegram_alert', 'urgency' => 'urgent', 'text' => 'x']]];
    $o = $run([$set([$alert], 'alerts', 1), $set([$pts('a', 25)], 'scoring', 2)], $facts, 50);
    T::eq([count($o['alerts']), $o['alerts'][0]['urgency'], $o['score']], [1, 'urgent', 75], 'orden: las alertas ven la puntuación y la temperatura FINALES aunque su conjunto vaya antes');
    $o = $run([$set([$alert], 'alerts', 1)], $facts, 50);
    T::eq(count($o['alerts']), 0, '  y sin esa puntuación no se disparan');
    $o = $run([$set([['id' => 'x', 'name' => 'x', 'enabled' => true, 'stop' => false, 'when' => $blk('all', $A), 'except' => null, 'then' => [['type' => 'tag', 'tag' => 'a'], ['type' => 'tag', 'tag' => 'b'], ['type' => 'review_queue']]],
        ['id' => 'y', 'name' => 'y', 'enabled' => true, 'stop' => false, 'when' => $blk('all', $A), 'except' => null, 'then' => [['type' => 'tag', 'tag' => 'a'], ['type' => 'create_task', 'title' => 't', 'due_hours' => 2, 'priority' => 1]]]], 'routing_tasks')], $facts);
    T::eq([$o['tags'], $o['review'], count($o['tasks'])], [['a', 'b'], true, 1], 'efectos: etiquetas sin repetir, cola de revisión y tarea');
    $json = json_encode(RuleEngine::run([$set([$pts('a', 10)])], $facts, ['score' => 50, 'temperature' => 'warm'], $thr));
    T::eq(json_encode(RuleEngine::run([$set([$pts('a', 10)])], $facts, ['score' => 50, 'temperature' => 'warm'], $thr)), $json, 'determinismo: mismos datos, mismo resultado (el motor no lee hora ni base)');

    // ------------------------------------------------------------------ Hechos de un lead y reloj inyectable
    $fl = ['profile' => 'owner_operator', 'state' => 'tx', 'equipment_types' => ['Dry-Van'], 'years_experience' => 3, 'truck_count' => 2, 'source' => 'meta', 'utm' => ['utm_campaign' => 'Verano'], 'language' => 'en', 'usdot' => '123',
        'flags' => ['red:no_insurance', 'fmcsa_pending', 'stop_sms'], 'authority_age_months' => 5, 'stage' => 'new', 'landing_page' => '/Careers/', 'created_at' => Clock::at($now - 7200)];
    $f = RuleRunner::facts($fl, $now, ['call']);
    T::eq([$f['state'], $f['equipment'], $f['campaign'], $f['has_usdot'], $f['fmcsa_flag'], $f['hours_since'], $f['landing'], $f['consent'], $f['authority_age']], ['TX', ['dry-van'], 'verano', true, ['no_insurance', 'fmcsa_pending'], 2.0, '/careers/', ['call'], 5], 'hechos: estado en mayúsculas, equipo y campaña en minúsculas, banderas de FMCSA, horas desde la llegada con el reloj inyectado');
    T::eq(RuleRunner::facts($fl, $now + 3600)['hours_since'], 3.0, '  otra hora inyectada da otro valor (sin hora oculta)');
    $L1 = Fx::lead(['flags' => ['stop_sms']]);
    Fx::consent($L1, ['call', 'sms', 'email']);
    $cm = RuleRunner::consentMap([Lead::find($L1)]);
    T::eq($cm[$L1], ['call', 'email'], 'consentimiento: una bandera stop_sms lo quita solo de ese canal');

    // ------------------------------------------------------------------ Equivalencia sin reglas
    mt_srand(51);
    $mk = static function (int $i): array {
        $eq = ['dry-van', 'reefer', 'flatbed', 'amazon-relay', 'other'];
        $pro = ['owner_operator', 'small_carrier', 'company_driver'];
        $p = $pro[mt_rand(0, 2)];
        return [
            'profile' => $p, 'first_name' => mt_rand(0, 9) ? 'N' . $i : '', 'last_name' => 'A', 'phone_e164' => mt_rand(0, 9) ? '+1305555' . str_pad((string) $i, 4, '0', STR_PAD_LEFT) : null, 'email' => mt_rand(0, 5) ? "e$i@example.com" : null,
            'state' => mt_rand(0, 4) ? ['TX', 'FL', 'GA', 'TN'][mt_rand(0, 3)] : null, 'language' => mt_rand(0, 3) ? 'en' : 'es', 'cdl_type' => [null, 'standard', 'standard', 'unsure', 'non_domiciled'][mt_rand(0, 4)],
            'cdl_class' => mt_rand(0, 4) ? 'A' : 'B', 'english_level' => [null, 'yes', 'yes', 'basic', 'no'][mt_rand(0, 4)], 'years_experience' => mt_rand(0, 4) ? mt_rand(0, 12) : null,
            'equipment_types' => array_slice($eq, mt_rand(0, 3), mt_rand(0, 2)), 'truck_count' => mt_rand(0, 3) ? mt_rand(0, 12) : null, 'authority_status' => mt_rand(0, 1) ? 'active' : null,
            'authority_age_months' => mt_rand(0, 2) ? mt_rand(0, 60) : null, 'referral_code' => mt_rand(0, 6) === 0 ? 'REF1' : null, 'endorsements' => mt_rand(0, 5) === 0 ? ['hazmat'] : [],
            'fmcsa_data' => mt_rand(0, 2) ? ['found' => true, 'authority_status' => ['active', 'inactive'][mt_rand(0, 1)], 'insured' => (bool) mt_rand(0, 1)] : [], 'flags' => [], 'source' => 'web_careers', 'stage' => 'new', 'created_at' => Clock::now(),
        ];
    };
    $leads = array_map($mk, range(1, 400));
    $sameAs = static function (array $leads, ?array $sets = null): int {
        $diff = 0;
        foreach ($leads as $l) {
            if (RuleRunner::score($l, null, null, $sets) !== Scoring::score($l)) {
                $diff++;
            }
        }
        return $diff;
    };
    T::eq($sameAs($leads), 0, 'EQUIVALENCIA: sin reglas publicadas, RuleRunner::score() devuelve EXACTAMENTE lo mismo que Scoring::score() en 400 leads aleatorios (idéntico arreglo)');
    $noMatch = [$set([$pts('x', 20, null, ['when' => $blk('all', $cond('state', 'is', 'AK'))])])];
    T::eq($sameAs($leads, $noMatch), 0, '  con reglas publicadas que no coinciden con ningún lead, también idéntico');
    $dq = array_filter($leads, static fn($l) => Scoring::score($l)['temperature'] === 'disqualified');
    $all = [$set([$pts('x', 20, null, ['when' => $blk('all', $cond('profile', 'exists'))])])];
    $diffDq = 0;
    foreach ($dq as $l) {
        $diffDq += RuleRunner::score($l, null, null, $all)['score'] !== 0 ? 1 : 0;
    }
    T::ok(count($dq) > 10 && $diffDq === 0, '  y los ' . count($dq) . ' descartados siguen en 0 aunque una regla coincida con todos');
    // Equivalencia sobre los datos de demostración (bin/seed-demo.php, ~120 leads con eventos): recalcular sin reglas no cambia NADA
    T::reset(true);
    $seedEnv = $ctx['work'] . '/seed31.env';
    file_put_contents($seedEnv, implode("\n", array_map(static fn($k, $v) => "$k=$v", array_keys($ctx['env']), $ctx['env'])) . "\n");
    $sd = Infra::cli([$ctx['root'] . '/bin/seed-demo.php', '--reset'], ['CAPTACION_ENV_FILE' => $seedEnv]);
    $seeded = (int) Db::value('SELECT COUNT(*) FROM leads');
    T::ok($sd['code'] === 0 && $seeded >= 100, 'datos de demostración: seed-demo sembró ' . $seeded . ' leads');
    $eventsBefore = (int) Db::value("SELECT COUNT(*) FROM lead_events WHERE type = 'scored'");
    // Resultado ESPERADO con las funciones originales (Scoring::score + Flags::derive), sin pasar por RuleRunner
    $expected = [];
    $changedExpected = 0;
    foreach (Db::all('SELECT * FROM leads ORDER BY id') as $row) {
        $hl = Lead::hydrate($row);
        $sc = Scoring::score($hl);
        $expected[(int) $row['id']] = ['score' => $sc['score'], 'temperature' => $sc['temperature'], 'breakdown' => json_decode((string) json_encode($sc['breakdown']), true), 'flags' => \Captacion\Services\Flags::derive($hl['flags'], $hl), 'dq' => $sc['disqualified_reason']];
        $changedExpected += ((int) $row['score'] !== $sc['score'] || $row['temperature'] !== $sc['temperature']) ? 1 : 0;
    }
    $actual = static function () use ($expected): int {
        $bad = 0;
        foreach (Db::all('SELECT id, score, temperature, score_breakdown, flags, disqualified_reason FROM leads ORDER BY id') as $r) {
            $e = $expected[(int) $r['id']];
            $got = ['score' => (int) $r['score'], 'temperature' => $r['temperature'], 'breakdown' => json_decode((string) $r['score_breakdown'], true), 'flags' => Db::decode($r['flags']), 'dq' => $r['disqualified_reason']];
            sort($e['flags']);
            sort($got['flags']);
            $bad += $got === $e ? 0 : 1;
        }
        return $bad;
    };
    $diffs = 0;
    foreach (Db::all('SELECT * FROM leads ORDER BY id') as $row) {
        $hl = Lead::hydrate($row);
        $diffs += RuleRunner::score($hl) === Scoring::score($hl) ? 0 : 1;
    }
    T::eq($diffs, 0, 'EQUIVALENCIA sobre el seed demo: RuleRunner::score() es idéntico a Scoring::score() en los ' . $seeded . ' leads');
    $jobsBefore = (int) Db::value('SELECT COUNT(*) FROM jobs');
    foreach (Db::all('SELECT id FROM leads ORDER BY id') as $row) {
        LeadIngest::rescore((int) $row['id'], 'equivalencia', false);
    }
    $scoredAfter = (int) Db::value("SELECT COUNT(*) FROM lead_events WHERE type = 'scored'");
    T::eq($actual(), 0, 'EQUIVALENCIA: recalcular los ' . $seeded . ' leads del seed SIN reglas deja exactamente lo que dan Scoring::score y Flags::derive (puntuación, temperatura, desglose, banderas y motivo de descarte)');
    T::eq([$scoredAfter - $eventsBefore, (int) Db::value('SELECT COUNT(*) FROM jobs') - $jobsBefore, (int) Db::value('SELECT COUNT(*) FROM rule_applications')], [$changedExpected, 0, 0], '  con un evento «scored» solo por cada lead cuya puntuación o temperatura cambió (' . $changedExpected . '), sin jobs y sin registros de reglas');
    $sNoMatch = RuleSets::create('scoring', 'Sin coincidencias', '', $uid);
    RuleSets::saveDraft($sNoMatch['id'], Rules::normalize([$rule(['id' => 'nada', 'when' => ['mode' => 'all', 'items' => [$cond('state', 'is', 'AK'), $cond('years', 'gt', 79)]]])], 'scoring')['rules'], null, $uid);
    RuleSets::publish($sNoMatch['id'], $uid);
    foreach (Db::all('SELECT id FROM leads ORDER BY id') as $row) {
        LeadIngest::rescore((int) $row['id'], 'equivalencia', false);
    }
    T::ok($actual() === 0 && (int) Db::value("SELECT COUNT(*) FROM lead_events WHERE type = 'scored'") === $scoredAfter && (int) Db::value('SELECT COUNT(*) FROM rule_applications') === 0, '  y con una regla publicada que no coincide con nadie, también idéntico y sin eventos nuevos');
    Settings::set('rules.enabled', false);
    $allRule = RuleSets::create('scoring', 'Todos', '', $uid);
    RuleSets::saveDraft($allRule['id'], Rules::normalize([$rule(['id' => 'todos', 'when' => ['mode' => 'all', 'items' => [$cond('profile', 'exists')]]])], 'scoring')['rules'], null, $uid);
    RuleSets::publish($allRule['id'], $uid);
    foreach (Db::all('SELECT id FROM leads ORDER BY id') as $row) {
        LeadIngest::rescore((int) $row['id'], 'equivalencia', false);
    }
    T::ok($actual() === 0, '  y con el interruptor general apagado aunque haya una regla que coincide con todos, también idéntico');
    Settings::set('rules.enabled', true);
    $dis = Db::value("SELECT COUNT(*) FROM leads WHERE temperature = 'disqualified'");
    T::ok($dis > 0, '  (el seed incluye ' . $dis . ' descartados, que las reglas no tocan)');
    RuleSets::retireAll($uid);
    Db::pdo()->exec('DELETE FROM rule_versions');
    Db::pdo()->exec('DELETE FROM rule_sets');
    RuleSets::clearCache();
    T::reset(true);
    $uid = (int) \CaptacionTests\Panel::user('admin')['id'];

    // Interruptor general
    $setIds = [];
    $s1 = RuleSets::create('scoring', 'Conjunto de prueba', '', $uid);
    $setIds[] = $s1['id'];
    $valid = $norm([$pts('x', 10)])['rules'];
    RuleSets::saveDraft($s1['id'], $valid, null, $uid);
    RuleSets::publish($s1['id'], $uid);
    $any = $leads[0];
    $any['profile'] = 'owner_operator';
    T::ok(RuleRunner::score($any)['score'] !== Scoring::score($any)['score'] || Scoring::score($any)['temperature'] === 'disqualified', 'interruptor: con un conjunto publicado las reglas se aplican');
    Settings::set('rules.enabled', false);
    T::eq($sameAs($leads), 0, '  con el interruptor general apagado (rules.enabled = false) vuelve EXACTAMENTE a la puntuación del documento');
    Settings::set('rules.enabled', true);
    $r = RuleSets::retireAll($uid);
    T::ok($r['n'] === 1 && $sameAs($leads) === 0 && RuleRunner::sets() === [], '  «Restablecer»: retirar todo lo publicado deja la puntuación idéntica a la del documento');
    Db::pdo()->exec('DELETE FROM rule_versions');
    Db::pdo()->exec('DELETE FROM rule_sets');
    RuleSets::clearCache();
    Settings::clear();
};
