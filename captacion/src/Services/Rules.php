<?php
/**
 * Reglas CUANDO / ENTONCES / EXCEPTO (Q5b): catalogo CERRADO de condiciones, operadores y acciones, y la validacion/normalizacion en servidor.
 * No es un lenguaje: nada de eval ni expresiones libres. Todo lo que entra (formulario del editor o JSON) pasa por normalize(), que solo deja salir lo que esta en las listas blancas.
 *
 * Forma normalizada de una regla:
 *   id (a-z0-9_, 1 a 24) · name (1 a 80) · enabled · stop (detener al coincidir, dentro de su conjunto) · example (plantilla de ejemplo)
 *   when   {mode: all|any, items: [condicion | grupo]}      condicion = {field, op, value}; grupo = {mode, items: [condicion]} (UN solo nivel de grupos)
 *   except {mode, items} | null                              si coincide, la regla NO actua
 *   then   [accion]                                          segun el tipo del conjunto (KIND_ACTIONS)
 * El orden de la lista es la prioridad: se evalua de arriba abajo, en una sola pasada (no hay ciclos posibles: una regla nunca activa a otra, y una condicion sobre la
 * puntuacion solo ve lo que sumaron las reglas anteriores).
 *
 * Las rutas de error son 'rules.<n>.when.items.<j>.value' (las mismas que usa el formulario, para pintar el mensaje junto al campo).
 */

declare(strict_types=1);

namespace Captacion\Services;

final class Rules
{
    public const VERSION = 1;
    public const KINDS = ['scoring' => 'Puntuación', 'alerts' => 'Alertas', 'routing_tasks' => 'Tareas y etiquetas'];
    public const KIND_HELP = [
        'scoring'       => 'Suman o restan puntos a la puntuación base del documento, o suben la temperatura mínima.',
        'alerts'        => 'Avisan por Telegram cuando un lead cumple una condición.',
        'routing_tasks' => 'Crean tareas, ponen etiquetas o mandan el lead a la cola de revisión.',
    ];
    public const KIND_ACTIONS = ['scoring' => ['add_points', 'min_temperature'], 'alerts' => ['telegram_alert'], 'routing_tasks' => ['create_task', 'tag', 'review_queue']];
    public const ACTIONS = [
        'add_points' => 'Sumar o restar puntos', 'min_temperature' => 'Fijar temperatura mínima', 'telegram_alert' => 'Avisar por Telegram',
        'create_task' => 'Crear una tarea', 'tag' => 'Poner una etiqueta', 'review_queue' => 'Enviar a la cola de revisión',
    ];

    public const MAX_SETS = 12;
    public const MAX_RULES = 40;
    public const MAX_ITEMS = 10;
    public const MAX_GROUP_ITEMS = 8;
    public const MAX_ACTIONS = 4;
    public const MAX_TAGS = 3;
    public const MAX_LIST = 30;
    public const MAX_POINTS = 30;
    public const MAX_ADJUST = 40;
    public const MAX_JSON = 60000;
    public const MAX_TEXT = 200;

    /** Variables permitidas en los textos de alertas y tareas ({nombre}...). Nada de telefono, correo ni datos sensibles. */
    public const VARS = [
        'nombre' => 'Nombre', 'perfil' => 'Perfil', 'estado' => 'Estado', 'equipo' => 'Equipo', 'camiones' => 'Camiones', 'anos' => 'Años de experiencia',
        'puntuacion' => 'Puntuación', 'temperatura' => 'Temperatura', 'fuente' => 'Fuente', 'etapa' => 'Etapa',
    ];

    public const FMCSA_FLAGS = ['authority_inactive' => 'Autoridad inactiva', 'no_insurance' => 'Sin seguro registrado', 'usdot_not_found' => 'USDOT no encontrado', 'fmcsa_pending' => 'FMCSA pendiente'];
    public const CONSENT = ['call' => 'Llamada', 'sms' => 'SMS', 'email' => 'Correo'];
    /** @var array<string,array{0:int|float,1:int|float,2:string}> min, max, unidad */
    public const NUM = ['years' => [0, 80, 'años'], 'trucks' => [0, 9999, 'camiones'], 'authority_age' => [0, 600, 'meses'], 'hours_since' => [0, 8760, 'h'], 'score' => [0, 100, 'puntos']];

    /** campo => [etiqueta del sujeto en la frase, tipo, texto corto del selector] */
    public const FIELDS = [
        'profile'       => ['el perfil', 'enum', 'Perfil'],
        'state'         => ['el estado', 'enum', 'Estado de EE. UU.'],
        'equipment'     => ['el equipo', 'multi', 'Equipo'],
        'years'         => ['los años de experiencia', 'number', 'Años de experiencia'],
        'trucks'        => ['los camiones', 'number', 'Camiones'],
        'source'        => ['la fuente', 'enum', 'Fuente'],
        'campaign'      => ['la campaña', 'text', 'Campaña (utm)'],
        'language'      => ['el idioma', 'enum', 'Idioma'],
        'has_usdot'     => ['el USDOT', 'bool', 'Tiene USDOT'],
        'fmcsa_flag'    => ['las banderas de FMCSA', 'multi', 'Banderas de FMCSA'],
        'authority_age' => ['la antigüedad de la autoridad', 'number', 'Antigüedad de la autoridad'],
        'consent'       => ['el consentimiento', 'multi', 'Consentimiento vigente'],
        'hours_since'   => ['las horas desde la llegada', 'number', 'Horas desde la llegada'],
        'stage'         => ['la etapa', 'enum', 'Etapa'],
        'score'         => ['la puntuación actual', 'number', 'Puntuación actual'],
        'temperature'   => ['la temperatura', 'enum', 'Temperatura'],
        'landing'       => ['la página de llegada', 'text', 'Página de llegada'],
    ];

    public const OPS = [
        'is' => 'es', 'is_not' => 'no es', 'in' => 'está en', 'not_in' => 'no está en', 'all' => 'incluye todos', 'gt' => 'es mayor que', 'gte' => 'es al menos', 'lt' => 'es menor que',
        'lte' => 'es como máximo', 'between' => 'está entre', 'exists' => 'tiene dato', 'not_exists' => 'está vacío', 'contains' => 'contiene', 'not_contains' => 'no contiene',
    ];
    /** Etiquetas de los operadores de listas de varios valores (equipo, banderas, consentimiento): «incluye alguno de». */
    public const OPS_MULTI = ['in' => 'incluye alguno de', 'not_in' => 'no incluye ninguno de', 'all' => 'incluye todos', 'exists' => 'tiene alguno', 'not_exists' => 'no tiene ninguno'];
    public const OPS_BY_TYPE = [
        'enum'   => ['is', 'is_not', 'in', 'not_in', 'exists', 'not_exists'],
        'bool'   => ['is'],
        'multi'  => ['in', 'all', 'not_in', 'exists', 'not_exists'],
        'number' => ['is', 'is_not', 'gt', 'gte', 'lt', 'lte', 'between', 'exists', 'not_exists'],
        'text'   => ['is', 'is_not', 'in', 'contains', 'not_contains', 'exists', 'not_exists'],
    ];

    public static function fieldType(string $field): ?string
    {
        return self::FIELDS[$field][1] ?? null;
    }

    /** Etiqueta del operador para ese campo. */
    public static function opLabel(string $field, string $op): string
    {
        if (self::fieldType($field) === 'multi' && isset(self::OPS_MULTI[$op])) {
            return self::OPS_MULTI[$op];
        }
        if ($field === 'has_usdot') {
            return 'es';
        }
        return self::OPS[$op] ?? $op;
    }

    /** @return array<string,string> opciones de un campo de lista cerrada ([] si el campo no tiene lista) */
    public static function options(string $field): array
    {
        return match ($field) {
            'profile'     => Catalog::PROFILES,
            'state'       => UsStates::NAMES,
            'equipment'   => Catalog::EQUIPMENT,
            'source'      => Catalog::SOURCES,
            'language'    => Catalog::LANGS,
            'fmcsa_flag'  => self::FMCSA_FLAGS,
            'consent'     => self::CONSENT,
            'stage'       => array_replace(Catalog::STAGES['A'], Catalog::STAGES['B']),
            'temperature' => Catalog::TEMPS,
            'has_usdot'   => ['yes' => 'Sí', 'no' => 'No'],
            default       => [],
        };
    }

    /** Campos que puede usar un conjunto: todos, salvo que un conjunto de puntuacion ya ve la puntuacion en marcha (se permite y se explica). */
    public static function fieldsFor(string $kind): array
    {
        return array_keys(self::FIELDS);
    }

    // --- Lectura tolerante -------------------------------------------------------------------------------------------------

    private static function bool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        return is_scalar($v) && !in_array(strtolower(trim((string) $v)), ['', '0', 'false', 'off', 'no'], true);
    }

    private static function str(mixed $v, int $max): string
    {
        $s = is_scalar($v) ? (string) $v : '';
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
        return mb_substr(trim($s), 0, $max);
    }

    /** @return list<string> */
    private static function list(mixed $v): array
    {
        if (is_string($v)) {
            $v = preg_split('/[,;\n]+/', $v) ?: [];
        }
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $x) {
            if (is_scalar($x)) {
                $t = self::str($x, 80);
                if ($t !== '') {
                    $out[] = $t;
                }
            }
        }
        return $out;
    }

    public static function slug(string $s): string
    {
        $s = mb_strtolower(self::str($s, 60));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    }

    // --- Validacion ----------------------------------------------------------------------------------------------------------

    /**
     * @param mixed $raw lista de reglas (o ['rules' => lista]) tal como llega del formulario o de un JSON
     * @return array{ok:bool,errors:array<string,string>,rules:list<array>,count:int}
     */
    public static function normalize(mixed $raw, string $kind): array
    {
        $err = [];
        if (!isset(self::KINDS[$kind])) {
            return ['ok' => false, 'errors' => ['rules' => 'Tipo de conjunto no válido.'], 'rules' => [], 'count' => 0];
        }
        if (is_array($raw) && isset($raw['rules']) && is_array($raw['rules'])) {
            $raw = $raw['rules'];
        }
        if (!is_array($raw)) {
            $raw = [];
        }
        if (strlen((string) json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)) > self::MAX_JSON) {
            return ['ok' => false, 'errors' => ['rules' => 'El conjunto es demasiado grande (máximo ' . intdiv(self::MAX_JSON, 1000) . ' KB).'], 'rules' => [], 'count' => 0];
        }
        if (count($raw) > self::MAX_RULES) {
            $err['rules'] = 'Máximo ' . self::MAX_RULES . ' reglas por conjunto.';
            $raw = array_slice($raw, 0, self::MAX_RULES, true);
        }
        $rules = [];
        $ids = [];
        foreach ($raw as $key => $r) {
            $k = (string) $key;
            $rule = self::rule(is_array($r) ? $r : [], $kind, "rules.$k", $err, $ids);
            $rules[] = $rule;
        }
        return ['ok' => !$err, 'errors' => $err, 'rules' => $rules, 'count' => count($rules)];
    }

    private static function newId(array $ids): string
    {
        do {
            $id = 'r' . bin2hex(random_bytes(3));
        } while (isset($ids[$id]));
        return $id;
    }

    private static function rule(array $r, string $kind, string $p, array &$err, array &$ids): array
    {
        $id = strtolower(self::str($r['id'] ?? '', 24));
        if (preg_match('/^[a-z0-9_]{1,24}$/', $id) !== 1 || isset($ids[$id])) {
            $id = self::newId($ids);   // un id repetido (regla duplicada) o ajeno recibe uno nuevo: el id solo sirve para comparar versiones
        }
        $ids[$id] = true;
        $name = self::str($r['name'] ?? '', 80);
        if ($name === '') {
            $err["$p.name"] = 'Ponle un nombre a la regla.';
        }
        $when = self::block($r['when'] ?? null, "$p.when", $err, true);
        $except = self::block($r['except'] ?? null, "$p.except", $err, false);
        $then = self::actions($r['then'] ?? null, $kind, "$p.then", $err);
        return [
            'id' => $id, 'name' => $name, 'enabled' => self::bool($r['enabled'] ?? false), 'stop' => self::bool($r['stop'] ?? false), 'example' => self::bool($r['example'] ?? false),
            'when' => $when ?? ['mode' => 'all', 'items' => []], 'except' => $except, 'then' => $then,
        ];
    }

    /** @return array{mode:string,items:list<array>}|null */
    private static function block(mixed $b, string $p, array &$err, bool $required): ?array
    {
        $b = is_array($b) ? $b : [];
        $items = is_array($b['items'] ?? null) ? $b['items'] : [];
        if (!$items) {
            if ($required) {
                $err["$p.items"] = 'Añade al menos una condición.';
                return ['mode' => 'all', 'items' => []];
            }
            return null;
        }
        $mode = ($b['mode'] ?? 'all') === 'any' ? 'any' : 'all';
        if (count($items) > self::MAX_ITEMS) {
            $err["$p.items"] = 'Máximo ' . self::MAX_ITEMS . ' condiciones o grupos por bloque.';
            $items = array_slice($items, 0, self::MAX_ITEMS, true);
        }
        $out = [];
        foreach ($items as $j => $it) {
            $q = "$p.items.$j";
            if (is_array($it) && array_key_exists('items', $it)) {
                $out[] = self::group($it, $q, $err);
            } else {
                $out[] = self::cond(is_array($it) ? $it : [], $q, $err);
            }
        }
        return ['mode' => $mode, 'items' => $out];
    }

    /** @return array{mode:string,items:list<array>} */
    private static function group(array $g, string $p, array &$err): array
    {
        $items = is_array($g['items'] ?? null) ? $g['items'] : [];
        if (!$items) {
            $err["$p.items"] = 'Un grupo necesita al menos una condición.';
        }
        if (count($items) > self::MAX_GROUP_ITEMS) {
            $err["$p.items"] = 'Máximo ' . self::MAX_GROUP_ITEMS . ' condiciones por grupo.';
            $items = array_slice($items, 0, self::MAX_GROUP_ITEMS, true);
        }
        $out = [];
        foreach ($items as $k => $c) {
            if (is_array($c) && array_key_exists('items', $c)) {
                $err["$p.items.$k"] = 'Solo se permite un nivel de grupos.';
                continue;
            }
            $out[] = self::cond(is_array($c) ? $c : [], "$p.items.$k", $err);
        }
        return ['mode' => ($g['mode'] ?? 'all') === 'any' ? 'any' : 'all', 'items' => $out];
    }

    /** @return array{field:string,op:string,value:mixed} */
    private static function cond(array $c, string $p, array &$err): array
    {
        $field = self::str($c['field'] ?? '', 20);
        if (!isset(self::FIELDS[$field])) {
            $err["$p.field"] = 'Elige qué dato comparar.';
            return ['field' => '', 'op' => 'is', 'value' => null];
        }
        $type = self::FIELDS[$field][1];
        $op = self::str($c['op'] ?? '', 14);
        if (!in_array($op, self::OPS_BY_TYPE[$type], true)) {
            $err["$p.op"] = 'Ese operador no se puede usar con este dato.';
            return ['field' => $field, 'op' => self::OPS_BY_TYPE[$type][0], 'value' => null];
        }
        return ['field' => $field, 'op' => $op, 'value' => self::value($field, $type, $op, $c['value'] ?? null, "$p.value", $err)];
    }

    private static function value(string $field, string $type, string $op, mixed $v, string $p, array &$err): mixed
    {
        if ($op === 'exists' || $op === 'not_exists') {
            return null;
        }
        if ($type === 'bool') {
            $s = is_bool($v) ? ($v ? 'yes' : 'no') : strtolower(self::str($v, 5));
            if (in_array($s, ['yes', '1', 'true', 'si', 'sí'], true)) {
                return true;
            }
            if (in_array($s, ['no', '0', 'false'], true)) {
                return false;
            }
            $err[$p] = 'Elige Sí o No.';
            return null;
        }
        if ($type === 'number') {
            return self::numberValue($field, $op, $v, $p, $err);
        }
        if ($type === 'text') {
            if ($op === 'in') {
                $list = self::list($v);
                if (!$list || count($list) > self::MAX_LIST) {
                    $err[$p] = 'Escribe de 1 a ' . self::MAX_LIST . ' valores separados por comas.';
                    return [];
                }
                foreach ($list as $t) {
                    if (preg_match('/[<>]/', $t) === 1) {
                        $err[$p] = 'No se permiten los signos < y >.';
                    }
                }
                return array_values(array_unique(array_map('mb_strtolower', array_map(static fn($t) => mb_substr($t, 0, 60), $list))));
            }
            $s = self::str($v, 60);
            if ($s === '') {
                $err[$p] = 'Escribe un texto.';
            } elseif (preg_match('/[<>]/', $s) === 1) {
                $err[$p] = 'No se permiten los signos < y >.';
            }
            return mb_strtolower($s);
        }
        // enum y multi: lista cerrada
        $opts = self::options($field);
        $norm = static fn(string $x): string => $field === 'state' ? strtoupper($x) : strtolower($x);
        if (in_array($op, ['in', 'not_in', 'all'], true)) {
            $list = array_values(array_unique(array_map($norm, self::list($v))));
            if (!$list) {
                $err[$p] = 'Elige al menos un valor.';
                return [];
            }
            if (count($list) > self::MAX_LIST) {
                $err[$p] = 'Máximo ' . self::MAX_LIST . ' valores.';
                $list = array_slice($list, 0, self::MAX_LIST);
            }
            foreach ($list as $x) {
                if (!isset($opts[$x])) {
                    $err[$p] = 'No reconozco «' . mb_substr($x, 0, 20) . '».' . ($field === 'state' ? ' Usa abreviaturas de 2 letras (TX, FL).' : '');
                    break;
                }
            }
            return $list;
        }
        $s = $norm(self::str($v, 40));
        if (!isset($opts[$s])) {
            $err[$p] = $s === '' ? 'Elige un valor.' : 'No reconozco «' . mb_substr($s, 0, 20) . '».';
            return null;
        }
        return $s;
    }

    private static function numberValue(string $field, string $op, mixed $v, string $p, array &$err): mixed
    {
        [$min, $max] = self::NUM[$field];
        $one = static function (mixed $x) use ($min, $max, $p, &$err): int|float|null {
            $s = is_scalar($x) ? trim((string) $x) : '';
            if ($s === '' || preg_match('/^\d{1,5}(\.\d{1,2})?$/', $s) !== 1) {
                $err[$p] = $s === '' ? 'Escribe un número.' : 'Solo números positivos (con hasta 2 decimales).';
                return null;
            }
            $n = str_contains($s, '.') ? (float) $s : (int) $s;
            if ($n < $min || $n > $max) {
                $err[$p] = 'Debe estar entre ' . $min . ' y ' . $max . '.';
                return null;
            }
            return $n;
        };
        if ($op === 'between') {
            $a = is_array($v) ? array_values($v) : [];
            $lo = $one($a[0] ?? null);
            $hi = $one($a[1] ?? null);
            if ($lo !== null && $hi !== null && $lo > $hi) {
                $err[$p] = 'El primer número no puede ser mayor que el segundo.';
            }
            return [$lo, $hi];
        }
        return $one(is_array($v) ? ($v[0] ?? null) : $v);
    }

    /** Valida el texto de una alerta o tarea: solo variables de la lista blanca, sin HTML ni caracteres de control. */
    public static function checkTemplate(string $t, string $p, array &$err, int $max): void
    {
        if ($t === '') {
            $err[$p] = 'Escribe el texto.';
            return;
        }
        if (preg_match('/[<>]/', $t) === 1) {
            $err[$p] = 'No se permiten los signos < y > (sin HTML).';
            return;
        }
        $rest = preg_replace_callback('/\{([a-z_]*)\}/', static function (array $m) use ($p, &$err): string {
            if (!isset(self::VARS[$m[1]])) {
                $err[$p] = 'La variable {' . $m[1] . '} no existe. Usa: ' . implode(', ', array_map(static fn($k) => '{' . $k . '}', array_keys(self::VARS))) . '.';
            }
            return '';
        }, $t) ?? '';
        if (!isset($err[$p]) && preg_match('/[{}]/', $rest) === 1) {
            $err[$p] = 'Hay una llave { o } sin cerrar. Las variables se escriben así: {nombre}.';
        }
    }

    /** @return list<array> */
    private static function actions(mixed $a, string $kind, string $p, array &$err): array
    {
        $a = is_array($a) ? $a : [];
        if (!$a) {
            $err[$p] = 'Añade al menos una acción.';
            return [];
        }
        if (count($a) > self::MAX_ACTIONS) {
            $err[$p] = 'Máximo ' . self::MAX_ACTIONS . ' acciones por regla.';
            $a = array_slice($a, 0, self::MAX_ACTIONS, true);
        }
        $out = [];
        $seen = [];
        foreach ($a as $i => $x) {
            $q = "$p.$i";
            $x = is_array($x) ? $x : [];
            $type = self::str($x['type'] ?? '', 20);
            if (!isset(self::ACTIONS[$type])) {
                $err["$q.type"] = 'Elige una acción.';
                continue;
            }
            if (!in_array($type, self::KIND_ACTIONS[$kind], true)) {
                $err["$q.type"] = 'Esta acción no se puede usar en un conjunto de «' . self::KINDS[$kind] . '».';
                continue;
            }
            $seen[$type] = ($seen[$type] ?? 0) + 1;
            if ($seen[$type] > ($type === 'tag' ? self::MAX_TAGS : 1)) {
                $err["$q.type"] = $type === 'tag' ? 'Máximo ' . self::MAX_TAGS . ' etiquetas por regla.' : 'Esta acción ya está en la regla.';
                continue;
            }
            $out[] = self::action($type, $x, $q, $err);
        }
        return $out;
    }

    private static function action(string $type, array $x, string $q, array &$err): array
    {
        switch ($type) {
            case 'add_points':
                $raw = is_scalar($x['points'] ?? null) ? trim((string) $x['points']) : '';
                $pts = preg_match('/^[+-]?\d{1,3}$/', $raw) === 1 ? (int) $raw : null;
                if ($pts === null || $pts === 0 || abs($pts) > self::MAX_POINTS) {
                    $err["$q.points"] = 'Escribe un número de puntos entre -' . self::MAX_POINTS . ' y ' . self::MAX_POINTS . ' (distinto de 0).';
                }
                $capRaw = is_scalar($x['cap'] ?? null) ? trim((string) $x['cap']) : '';
                $cap = null;
                if ($capRaw !== '') {
                    if (preg_match('/^\d{1,3}$/', $capRaw) !== 1 || (int) $capRaw > 100) {
                        $err["$q.cap"] = 'El tope debe ser un número entre 0 y 100.';
                    } else {
                        $cap = (int) $capRaw;
                    }
                }
                return ['type' => $type, 'points' => $pts ?? 0, 'cap' => $cap];
            case 'min_temperature':
                $lv = self::str($x['level'] ?? '', 6);
                if (!in_array($lv, ['warm', 'hot'], true)) {
                    $err["$q.level"] = 'Elige Tibio o Caliente.';
                    $lv = 'warm';
                }
                return ['type' => $type, 'level' => $lv];
            case 'telegram_alert':
                $text = self::str($x['text'] ?? '', self::MAX_TEXT + 1);
                if (mb_strlen($text) > self::MAX_TEXT) {
                    $err["$q.text"] = 'Máximo ' . self::MAX_TEXT . ' caracteres.';
                    $text = mb_substr($text, 0, self::MAX_TEXT);
                } else {
                    self::checkTemplate($text, "$q.text", $err, self::MAX_TEXT);
                }
                $urg = ($x['urgency'] ?? 'normal') === 'urgent' ? 'urgent' : 'normal';
                return ['type' => $type, 'urgency' => $urg, 'text' => $text];
            case 'create_task':
                $title = self::str($x['title'] ?? '', 121);
                if (mb_strlen($title) > 120) {
                    $err["$q.title"] = 'Máximo 120 caracteres.';
                    $title = mb_substr($title, 0, 120);
                } else {
                    self::checkTemplate($title, "$q.title", $err, 120);
                }
                $hRaw = is_scalar($x['due_hours'] ?? null) ? trim((string) $x['due_hours']) : '';
                $h = preg_match('/^\d{1,3}$/', $hRaw) === 1 ? (int) $hRaw : 0;
                if ($h < 1 || $h > 720) {
                    $err["$q.due_hours"] = 'Escribe de 1 a 720 horas.';
                    $h = max(1, min(720, $h));
                }
                $pr = (int) ($x['priority'] ?? 2);
                return ['type' => $type, 'title' => $title, 'due_hours' => $h, 'priority' => isset(Catalog::PRIORITIES[$pr]) ? $pr : 2];
            case 'tag':
                $tag = self::slug((string) ($x['tag'] ?? ''));
                if ($tag === '' || strlen($tag) > 24) {
                    $err["$q.tag"] = 'Escribe una etiqueta de 1 a 24 letras o números (sin espacios; se usan guiones).';
                    $tag = substr($tag, 0, 24);
                }
                return ['type' => $type, 'tag' => $tag];
            default:
                return ['type' => 'review_queue'];
        }
    }

    // --- Plantillas de texto ---------------------------------------------------------------------------------------------------

    /** Reemplaza {variables} por sus valores (ya limpios). Una variable ausente queda vacia; lo que no esta en VARS se deja tal cual (la validacion ya lo impidio). */
    public static function render(string $template, array $vars): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', static function (array $m) use ($vars): string {
            if (!isset(self::VARS[$m[1]])) {
                return $m[0];
            }
            $v = $vars[$m[1]] ?? '';
            $s = is_scalar($v) ? (string) $v : '';
            $s = preg_replace('/[\x00-\x1F\x7F<>]+/u', ' ', $s) ?? '';
            return mb_substr(trim($s), 0, 80);
        }, $template) ?? '';
    }

    /** Una regla vacia para el boton «Añadir regla» del editor. */
    public static function blank(string $kind): array
    {
        $then = match ($kind) {
            'alerts'        => [['type' => 'telegram_alert', 'urgency' => 'normal', 'text' => '']],
            'routing_tasks' => [['type' => 'review_queue']],
            default         => [['type' => 'add_points', 'points' => 5, 'cap' => null]],
        };
        return ['id' => '', 'name' => '', 'enabled' => false, 'stop' => false, 'example' => false,
            'when' => ['mode' => 'all', 'items' => [['field' => 'profile', 'op' => 'is', 'value' => 'owner_operator']]], 'except' => null, 'then' => $then];
    }

    /** Cuenta de reglas activas y de reglas en total de una lista normalizada. @return array{total:int,enabled:int} */
    public static function counts(array $rules): array
    {
        return ['total' => count($rules), 'enabled' => count(array_filter($rules, static fn($r) => !empty($r['enabled'])))];
    }
}
