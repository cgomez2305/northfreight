<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Env;
use Captacion\Models\Lead;
use Captacion\Services\Commands;
use Captacion\Services\LeadIngest;
use Captacion\Services\LeadQuery;
use Captacion\Services\RateLimiter;
use Captacion\Services\RuleForm;
use Captacion\Services\RuleRunner;
use Captacion\Services\Rules;
use Captacion\Services\RuleSets;
use Captacion\Services\RuleTemplates;
use Captacion\Services\SavedViews;
use Captacion\Services\ScoringConfig;
use Captacion\Services\Settings;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/**
 * Q5b-C/E/F por HTTP: la pestaña Reglas y su editor (formularios sin JS, piezas del servidor, vista previa y simulación en JSON), reautenticación en lo sensible, CSRF, autorización por rol
 * en CADA ruta nueva (anónimo y lector), escapado, CSP sin estilos en línea, auditoría, paleta, desglose en la ficha y el vistazo, vista «Por revisar» y recálculo por la cola.
 */
return static function (array $ctx): void {
    T::reset(true);
    foreach (['TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID', 'FMCSA_WEBKEY', 'TELEGRAM_API_BASE'] as $k) {
        Env::set($k, null);
    }
    Db::pdo()->exec("DELETE FROM settings WHERE `key` IN ('alerts','rules.enabled','rules.alert_cap','scoring.recalc')");
    Settings::clear();
    RuleSets::clearCache();
    $admin = Panel::user('admin');
    $viewerU = Panel::user('viewer');
    $aid = (int) $admin['id'];
    $s = Panel::start($ctx, 'panel34');
    T::ok($s->login($admin['email']), 'login del administrador');
    $v = $s->another();
    T::ok($v->login($viewerU['email']), 'login del lector');
    $anon = $s->another();
    $post = static fn(Panel $p, string $path, array $f = []) => $p->post($path, $f + ['_csrf' => $p->csrf]);
    $loc = static fn(array $r): string => Infra::header($r, 'location');
    $nSets = static fn(): int => (int) Db::value('SELECT COUNT(*) FROM rule_sets WHERE deleted_at IS NULL');
    $cond = static fn(string $f, string $op, mixed $val = null): array => ['field' => $f, 'op' => $op, 'value' => $val];

    // ------------------------------------------------------------------ Pestaña vacía
    $r = $s->get('/config?tab=reglas');
    T::ok($r['status'] === 200 && str_contains($r['body'], 'Todavía no hay reglas') && str_contains($r['body'], 'Empezar con ejemplos') && str_contains($r['body'], 'Se usa la puntuación del documento') && str_contains($r['body'], 'href="/config?tab=reglas"'), 'pestaña Reglas: estado vacío cuidado (la puntuación sigue siendo la del documento)');
    T::ok(str_contains($r['body'], 'cfg-reglas.css') && str_contains($r['body'], 'rules.js') && !str_contains($r['body'], 'rules.css'), '  carga su hoja y su JS; la hoja del editor se pide al abrirlo');
    T::ok(!str_contains($r['body'], 'style="') && !preg_match('/\son[a-z]+="/i', $r['body']) && preg_match_all('/<script(?![^>]*nonce=)[^>]*>/', $r['body']) === 0, '  CSP: sin estilos ni manejadores en línea y todos los scripts con nonce');
    T::ok(str_contains($r['body'], 'Edición bloqueada'), '  y pide confirmar la identidad antes de editar');

    // ------------------------------------------------------------------ Autorización: anónimo y lector en CADA ruta nueva
    $forms = ['newset' => ['kind' => 'scoring', 'name' => 'Intruso'], 'starter' => [], 'save' => ['set' => '1', 'name' => 'X'], 'publish' => ['set' => '1'], 'revert' => ['set' => '1', 'version' => '1'], 'unpublish' => ['set' => '1'],
        'discard' => ['set' => '1'], 'delete' => ['set' => '1'], 'switch' => ['on' => '0'], 'reset' => [], 'apply' => ['confirm' => '1'], 'simcsv' => ['sim' => '1']];
    $jsons = ['panel' => ['id' => 1], 'row' => ['set' => 1, 'kind' => 'cond', 'np' => 'rules[a][when][items][c1]'], 'preview' => ['set' => 1, 'form' => '', 'impact' => true], 'simulate' => ['set' => 1, 'form' => ''], 'simstatus' => ['id' => 1]];
    $bad = [];
    foreach ($forms as $name => $f) {
        $a = Infra::http('POST', $s->base . "/config/rules/$name", ['form' => $f + ['_csrf' => $anon->csrf], 'jar' => $anon->jar]);
        if (!($a['status'] === 303 && str_contains($loc($a), '/login'))) {
            $bad[] = "anónimo $name -> {$a['status']}";
        }
        $b = $post($v, "/config/rules/$name", $f);
        if ($b['status'] !== 403) {
            $bad[] = "lector $name -> {$b['status']}";
        }
    }
    foreach ($jsons as $name => $body) {
        $a = Infra::http('POST', $s->base . "/ajax/rules/$name", ['json' => $body]);
        if ($a['status'] !== 401) {
            $bad[] = "anónimo ajax $name -> {$a['status']}";
        }
        $b = $v->ajax("/ajax/rules/$name", $body);
        if ($b['status'] !== 403 || !str_contains($b['body'], 'forbidden')) {
            $bad[] = "lector ajax $name -> {$b['status']}";
        }
        $c = Infra::http('GET', $s->base . "/ajax/rules/$name", ['jar' => $s->jar]);
        if ($c['status'] !== 405) {
            $bad[] = "GET ajax $name -> {$c['status']}";
        }
        $d = $s->ajax("/ajax/rules/$name", $body, 'token-falso');
        if ($d['status'] !== 403 || !str_contains($d['body'], 'csrf')) {
            $bad[] = "CSRF ajax $name -> {$d['status']}";
        }
    }
    T::ok(!$bad && $nSets() === 0, 'autorización: las 12 rutas de formulario y las 5 JSON rechazan al anónimo (login / 401), al lector (403) y a un token CSRF falso, y no se crea nada' . ($bad ? ': ' . implode('; ', $bad) : ''));
    $r = $v->get('/config?tab=reglas');
    T::ok($r['status'] === 403 && !str_contains($r['body'], 'Reglas legibles'), 'lector: la pestaña Reglas responde 403 sin contenido');
    // Sin CSRF y sin confirmar identidad
    $r = $s->post('/config/rules/newset', ['kind' => 'scoring', 'name' => 'Sin token']);
    T::ok($r['status'] === 303 && $nSets() === 0, 'sin token CSRF: no se crea el conjunto');

    // ------------------------------------------------------------------ Crear conjunto y editar (formularios)
    $r = $post($s, '/config/rules/newset', ['kind' => 'scoring', 'name' => 'Mis puntos', 'description' => 'Ajustes de prueba']);
    $sid = (int) Db::value('SELECT id FROM rule_sets');
    T::ok($r['status'] === 303 && $nSets() === 1 && str_contains($loc($r), "set=$sid"), 'crear un conjunto no exige reautenticar y lleva al editor');
    $r = $post($s, '/config/rules/newset', ['kind' => 'scoring', 'name' => '']);
    T::ok($nSets() === 1 && str_contains($s->get('/config?tab=reglas')['body'], 'Ponle un nombre'), '  un nombre vacío da un mensaje claro');
    $page = $s->get("/config?tab=reglas&set=$sid");
    T::ok($page['status'] === 200 && str_contains($page['body'], 'data-rp-form') && str_contains($page['body'], 'rules.css') && str_contains($page['body'], 'Mis puntos') && str_contains($page['body'], 'rp-nojs'), 'editor: abierto con ?set=ID va en la propia página (sin JS queda en línea) con su hoja');
    T::ok(str_contains($page['body'], 'Este conjunto todavía no tiene reglas') && str_contains($page['body'], 'Publicar v1') && str_contains($page['body'], 'disabled title="Confirma tu identidad para publicar"') && str_contains($page['body'], 'CONFIRMA TU IDENTIDAD PARA PUBLICAR'), '  vacío, con «Publicar» bloqueado y su formulario de confirmación dentro del editor');
    // Estructurales sin JS
    $form = static fn(array $rules, array $o = []): array => $o + ['set' => (string) $sid, 'name' => 'Mis puntos', 'description' => '', 'note' => '', 'rules' => $rules];
    $ops = static function (array $rules, string $op) use ($s, $post, $form): array {
        return $post($s, '/config/rules/save', $form($rules) + ['op' => $op]);
    };
    $r = $ops([], 'add_rule');
    preg_match_all('/data-rule data-key="([^"]+)"/', $r['body'], $mm);
    T::ok($r['status'] === 200 && count($mm[1]) === 1 && str_contains($r['body'], 'Añadir condición') && str_contains($r['body'], 'ENTONCES') && str_contains($r['body'], 'EXCEPTO') && Db::value('SELECT COUNT(*) FROM rule_versions') == 0, 'sin JS: «Añadir regla» recarga con una regla nueva y NO guarda nada');
    $k1 = $mm[1][0];
    // Guardar con errores: 422 con el mensaje junto al campo
    $r = $post($s, '/config/rules/save', $form([$k1 => ['id' => '', 'name' => '', 'enabled' => '1', 'when' => ['mode' => 'all', 'items' => ['c1' => ['field' => 'profile', 'op' => 'is', 'value' => '']]], 'then' => ['a1' => ['type' => 'add_points', 'points' => '99']]]]) + ['op' => 'save']);
    T::ok($r['status'] === 422 && str_contains($r['body'], 'Ponle un nombre a la regla.') && str_contains($r['body'], 'Elige un valor.') && str_contains($r['body'], 'entre -30 y 30') && Db::value('SELECT COUNT(*) FROM rule_versions') == 0, 'guardar con errores: 422, cada mensaje junto a su campo, y no se guarda nada');
    T::ok(str_contains($r['body'], 'value="99"') && str_contains($r['body'], 'aria-invalid="true"'), '  y conserva lo escrito y marca los campos');
    // Guardar bien
    $good = ['r_tx' => ['id' => 'r_tx', 'name' => 'Texas suma', 'enabled' => '1', 'stop' => '0', 'when' => ['mode' => 'all', 'items' => ['c1' => $cond('state', 'in', 'tx, fl')]], 'then' => ['a1' => ['type' => 'add_points', 'points' => '10', 'cap' => '']]],
        'r_fl' => ['id' => 'r_fl', 'name' => 'Flotas', 'enabled' => '1', 'when' => ['mode' => 'any', 'items' => ['c1' => $cond('trucks', 'gte', '5'), 'g1' => ['mode' => 'all', 'items' => ['c1' => $cond('years', 'between', ['2', '6']), $cond('has_usdot', 'is', 'yes')]]]], 'except' => ['mode' => 'all', 'items' => ['c1' => $cond('source', 'in', ['indeed'])]],
            'then' => ['a1' => ['type' => 'add_points', 'points' => '5', 'cap' => '90']]]];
    $r = $ops($good, 'save');
    $d = RuleSets::draft($sid);
    T::ok($r['status'] === 303 && $d !== null && $d['version'] === 1 && count($d['rules']) === 2 && $d['rules'][0]['when']['items'][0]['value'] === ['TX', 'FL'] && $d['rules'][1]['when']['items'][1]['items'][0]['value'] === [2, 6], 'guardar borrador: normaliza y guarda (estados en mayúsculas, rangos como números, grupo)');
    $page = $s->get("/config?tab=reglas&set=$sid");
    T::ok(str_contains($page['body'], 'Cuando el estado está en TX, FL') && str_contains($page['body'], 'Entonces sumar 10 puntos') && str_contains($page['body'], 'Excepto cuando la fuente está en Indeed') && str_contains($page['body'], 'Borrador v1'), 'el editor muestra la frase de cada regla en español y el estado del borrador');
    // Operaciones estructurales
    $cur = RuleForm::fromRules($d['rules']);
    $keys = array_keys($cur);
    $r = $ops($cur, 'down:' . $keys[0]);
    preg_match_all('/data-rule data-key="([^"]+)"/', $r['body'], $mm);
    T::eq($mm[1], [$keys[1], $keys[0]], 'sin JS: «bajar» cambia la prioridad (orden de las reglas)');
    $r = $ops($cur, 'dup_rule:' . $keys[0]);
    preg_match_all('/data-rule data-key="([^"]+)"/', $r['body'], $mm);
    T::ok(count($mm[1]) === 3 && str_contains($r['body'], 'Texas suma (copia)'), '  «duplicar» agrega una copia debajo con otro id y el nombre «(copia)»');
    $r = $ops($cur, 'del_rule:' . $keys[1]);
    preg_match_all('/data-rule data-key="([^"]+)"/', $r['body'], $mm);
    T::eq($mm[1], [$keys[0]], '  «borrar» quita la regla');
    $r = $ops($cur, 'add_cond:rules.' . $keys[0] . '.when');
    T::eq(substr_count($r['body'], 'data-cond'), substr_count($s->get("/config?tab=reglas&set=$sid")['body'], 'data-cond') + 1, '  «añadir condición» agrega una fila');
    $r = $ops($cur, 'add_group:rules.' . $keys[0] . '.when');
    T::ok(str_contains($r['body'], 'data-group'), '  «añadir grupo» agrega un grupo con una condición');
    $r = $ops($cur, 'add_except:' . $keys[0]);
    T::ok(substr_count($r['body'], 'Se anula si se cumplen') === 2, '  «añadir excepción» abre el bloque EXCEPTO');
    $r = $ops($cur, 'add_act:' . $keys[0]);
    T::ok(count(RuleForm::apply($cur, 'scoring', 'add_act:' . $keys[0])[$keys[0]]['then']) === 2, '  «añadir acción» agrega una (el tipo distinto de las ya usadas)');
    T::ok(RuleForm::apply($cur, 'scoring', 'del_item:rules.' . $keys[1] . '.when.items.g1') !== $cur && !isset(RuleForm::apply($cur, 'scoring', 'del_item:rules.' . $keys[1] . '.when.items.g1')[$keys[1]]['when']['items']['g1']), '  y quitar un grupo');
    T::eq(RuleForm::apply($cur, 'scoring', 'del_item:rules.zz.when.items.c1;DROP'), $cur, '  una operación con una ruta malformada no cambia nada');
    // Escapado y claves inyectadas
    $evil = ['"><script>alert(1)</script>' => ['name' => 'x'], 'ok1' => ['id' => 'ok1', 'name' => '"><img src=x onerror=alert(1)>', 'when' => ['mode' => 'all', 'items' => ['c1' => $cond('campaign', 'contains', '<b>x</b>')]], 'then' => ['a1' => ['type' => 'tag', 'tag' => 'a']]]];
    $r = $post($s, '/config/rules/save', $form($evil) + ['op' => 'save']);
    T::ok($r['status'] === 422 && !str_contains($r['body'], '<script>alert(1)') && !str_contains($r['body'], '<img src=x') && str_contains($r['body'], '&lt;img src=x onerror=alert(1)&gt;') && !str_contains($r['body'], 'data-key="&quot;&gt;&lt;script'), 'escapado: nombres con HTML se imprimen escapados y una clave de regla con HTML se descarta');
    // Guardar con datos válidos y tipo de conjunto
    $r = $post($s, '/config/rules/save', $form([['id' => 'x', 'name' => 'Alerta', 'enabled' => '1', 'when' => ['mode' => 'all', 'items' => [$cond('profile', 'is', 'owner_operator')]], 'then' => [['type' => 'telegram_alert', 'text' => 'Hola']]]]) + ['op' => 'save']);
    T::ok($r['status'] === 422 && str_contains($r['body'], 'no se puede usar en un conjunto de «Puntuación»'), 'una acción de otro tipo de conjunto (alerta en puntuación) se rechaza en el servidor');
    $r = $post($s, '/config/rules/save', $form($good, ['name' => '']) + ['op' => 'save']);
    T::ok($r['status'] === 422 && str_contains($r['body'], 'Ponle un nombre al conjunto.'), 'el nombre del conjunto vacío se rechaza');

    // ------------------------------------------------------------------ Reautenticación en lo que cambia el comportamiento
    $draftV = RuleSets::draft($sid)['version'];
    $sens = ['publish' => ['set' => (string) $sid], 'unpublish' => ['set' => (string) $sid], 'revert' => ['set' => (string) $sid, 'version' => '1'], 'delete' => ['set' => (string) $sid], 'switch' => ['on' => '0'], 'reset' => [], 'apply' => ['confirm' => '1']];
    $before = [$nSets(), Db::value('SELECT COUNT(*) FROM rule_versions WHERE pub_slot = 1'), Settings::get('rules.enabled', true)];
    $bad = [];
    foreach ($sens as $name => $f) {
        $a = $post($s, "/config/rules/$name", $f);
        if ($a['status'] !== 303) {
            $bad[] = "$name -> {$a['status']}";
        }
    }
    Settings::clear();
    T::ok(!$bad && [$nSets(), Db::value('SELECT COUNT(*) FROM rule_versions WHERE pub_slot = 1'), Settings::get('rules.enabled', true)] === $before, 'sin confirmar identidad: publicar, retirar, revertir, borrar, apagar, restablecer y aplicar no cambian nada' . ($bad ? ': ' . implode('; ', $bad) : ''));
    T::eq([(int) Db::value('SELECT COUNT(*) FROM rule_versions WHERE pub_slot = 1'), $nSets()], [0, 1], '  (sigue sin haber nada publicado y el conjunto sigue ahí)');
    T::ok($s->unlock($aid, 'reglas'), 'confirmar identidad (contraseña + código)');
    $r = $post($s, '/config/rules/publish', ['set' => (string) $sid, 'name' => 'Mis puntos', 'description' => '', 'note' => 'Primera', 'rules' => $good]);
    T::ok($r['status'] === 303 && RuleSets::published($sid)['version'] === 1 && RuleSets::published($sid)['note'] === 'Primera' && RuleSets::draft($sid) === null, 'publicar con identidad vigente: guarda el formulario como borrador y lo publica (v1)');
    T::ok(str_contains($s->get("/config?tab=reglas&set=$sid")['body'], 'Publicada') && str_contains($s->get('/config?tab=reglas')['body'], '1 conjunto publicado en uso'), '  y la lista y el editor lo muestran');
    // Revertir y retirar
    $good2 = $good;
    $good2['r_tx']['then']['a1']['points'] = '14';
    $post($s, '/config/rules/save', $form($good2, ['note' => 'Segunda']) + ['op' => 'save']);
    $post($s, '/config/rules/publish', $form($good2));
    $v1 = (int) Db::value('SELECT id FROM rule_versions WHERE set_id = ? AND version = 1', [$sid]);
    T::eq(RuleSets::published($sid)['version'], 2, 'v2 publicada');
    $page = $s->get("/config?tab=reglas&set=$sid");
    T::ok(str_contains($page['body'], 'Revertir a v1') && str_contains($page['body'], 'Ver cambios respecto a la versión anterior') && str_contains($page['body'], 'sumar 14 puntos') && str_contains($page['body'], 'sumar 10 puntos'), 'historial: lista v1 y v2, la diferencia legible (sumar 10 → sumar 14) y el botón Revertir');
    $r = $post($s, '/config/rules/revert', ['set' => (string) $sid, 'version' => (string) $v1]);
    T::ok($r['status'] === 303 && RuleSets::published($sid)['version'] === 3 && RuleSets::published($sid)['reverted_from'] === 1 && RuleSets::published($sid)['rules'][0]['then'][0]['points'] === 10, 'revertir: v1 vuelve como v3 publicada y se conserva el historial');
    T::ok(count(RuleSets::history($sid)) === 3, '  (3 versiones en la base)');
    // Interruptor general y restablecer
    $r = $post($s, '/config/rules/switch', ['on' => '0']);
    Settings::clear();
    T::ok($r['status'] === 303 && !RuleRunner::enabled() && RuleRunner::sets() === [] && str_contains($s->get('/config?tab=reglas')['body'], 'Las reglas están apagadas'), 'interruptor general: apagado, el motor ignora lo publicado y la pantalla lo dice');
    $post($s, '/config/rules/switch', ['on' => '1']);
    Settings::clear();
    T::ok(RuleRunner::enabled() && count(RuleRunner::sets()) === 1, '  y encendido vuelve a aplicarlo');
    $log = array_column(Db::all("SELECT action FROM activity_log WHERE action LIKE 'rules_%' OR (action = 'settings_change' AND entity_id = 'rules.enabled')"), 'action');
    T::ok(in_array('rules_publish', $log, true) && in_array('rules_revert', $log, true) && in_array('settings_change', $log, true) && in_array('rules_draft_save', $log, true) && in_array('rules_set_create', $log, true), 'auditoría: publicar, revertir, guardar borrador, crear y el interruptor quedan en activity_log');

    // ------------------------------------------------------------------ Efecto en leads y recálculo por la cola
    $ld = Fx::lead(['state' => 'TX', 'first_name' => 'Texas', 'score' => 40, 'temperature' => 'warm', 'cdl_type' => 'standard', 'english_level' => 'yes', 'years_experience' => 3, 'truck_count' => 2, 'usdot' => '123']);
    $before = Db::value('SELECT score FROM leads WHERE id = ?', [$ld]);
    $r = $post($s, '/config/rules/apply', []);
    T::ok($r['status'] === 303 && Db::value('SELECT score FROM leads WHERE id = ?', [$ld]) === $before, 'aplicar a existentes sin marcar la confirmación: no recalcula');
    $r = $post($s, '/config/rules/apply', ['confirm' => '1']);
    $g = 0;
    Settings::clear();
    while (ScoringConfig::running() && $g++ < 8) {
        Fx::runJobs(5);
        Settings::clear();
    }
    $after = (int) Db::value('SELECT score FROM leads WHERE id = ?', [$ld]);
    $bd = Db::decode(Db::value('SELECT score_breakdown FROM leads WHERE id = ?', [$ld]));
    T::ok($r['status'] === 303 && $after === min(100, $bd['score_base'] + 15) && $bd['rules_adjust'] === 15 && $after !== (int) $before && $bd['rules'][0]['rule_id'] === 'r_tx' && $bd['rules'][1]['rule_id'] === 'r_fl' && ScoringConfig::state()['status'] === 'done', 'aplicar a existentes (cola, en lotes): el lead recibe los puntos de la versión publicada y queda el recuento antes y después');
    $page = $s->get('/config?tab=reglas');
    T::ok(str_contains($page['body'], 'Último recálculo terminado') && str_contains($page['body'], 'Recuento antes y después') && str_contains($page['body'], 'Calientes'), '  la pestaña muestra el resultado del recálculo');
    // Ficha y vistazo con las reglas que influyeron
    $fich = $s->get("/leads/$ld");
    T::ok(str_contains($fich['body'], 'REGLAS QUE INFLUYERON') && str_contains($fich['body'], 'Texas suma') && str_contains($fich['body'], '+10') && str_contains($fich['body'], 'Base del documento'), 'ficha: el desglose muestra las reglas que influyeron, su efecto y la base del documento');
    $pk = $s->ajax('/ajax/peek', ['id' => $ld]);
    T::ok(str_contains($pk['body'], 'REGLAS QUE INFLUYERON') && str_contains($pk['body'], 'Texas suma'), 'vistazo: también');
    $plain = Fx::lead(['state' => 'GA', 'first_name' => 'Sinreglas']);
    LeadIngest::rescore($plain);
    T::ok(!str_contains($s->get("/leads/$plain")['body'], 'REGLAS QUE INFLUYERON'), '  y un lead al que no actúan reglas no muestra la sección');
    // Por revisar
    $rv = Fx::lead(['first_name' => 'Revisa']);
    Db::update('leads', $rv, ['flags' => ['review_queue', 'tag:revisar-fmcsa']]);
    T::eq(LeadQuery::filters(['fl' => 'review_queue']), ['fl' => 'review_queue'], 'vista «Por revisar»: el filtro fl=review_queue es válido');
    T::eq(LeadQuery::filters(['fl' => 'tag:revisar-fmcsa', 'zz' => 'x']), ['fl' => 'tag:revisar-fmcsa'], '  y fl=tag:etiqueta (lista blanca)');
    T::eq(LeadQuery::filters(['fl' => 'tag:<script>']), [], '  una etiqueta con caracteres raros se descarta');
    T::ok(in_array('sys-review', array_column(SavedViews::system(), 'id'), true), '  existe como vista del sistema');
    $revView = array_values(array_filter(SavedViews::system(), static fn($x) => $x['id'] === 'sys-review'))[0];
    $lst = $s->get(SavedViews::href($revView));
    T::ok(str_contains($lst['body'], 'data-peek="' . $rv . '"') && !str_contains($lst['body'], 'data-peek="' . $plain . '"') && str_contains($lst['body'], 'Por revisar'), '  y lista solo a los marcados');
    $lst2 = $s->get('/leads?fl=tag:revisar-fmcsa');
    T::ok(str_contains($lst2['body'], 'data-peek="' . $rv . '"') && !str_contains($lst2['body'], 'data-peek="' . $plain . '"') && str_contains($lst2['body'], 'Etiqueta: revisar-fmcsa'), '  y por etiqueta');

    // ------------------------------------------------------------------ JSON: piezas, vista previa y simulación
    $pj = $s->ajax('/ajax/rules/panel', ['id' => $sid]);
    $pjj = $s->json($pj);
    T::ok($pj['status'] === 200 && ($pjj['ok'] ?? false) && str_contains($pjj['html'], 'data-rp-form') && str_contains($pjj['html'], 'Texas suma') && !str_contains($pjj['html'], '<script'), 'JSON panel: devuelve el editor ya escapado del conjunto');
    T::eq($s->ajax('/ajax/rules/panel', ['id' => 999999])['status'], 404, '  un conjunto que no existe da 404');
    foreach (['cond' => ['kind' => 'cond', 'np' => 'rules[n9][when][items][c5]', 'field' => 'trucks', 'op' => 'between'], 'group' => ['kind' => 'group', 'np' => 'rules[n9][when][items][g2]'], 'act' => ['kind' => 'act', 'np' => 'rules[n9][then][a2]', 'type' => 'min_temperature'],
        'except' => ['kind' => 'except', 'np' => 'rules[n9][except]'], 'rule' => ['kind' => 'rule', 'np' => 'rules[n9]']] as $k => $b) {
        $rr = $s->ajax('/ajax/rules/row', $b + ['set' => $sid]);
        $h = $s->json($rr)['html'] ?? '';
        $okRow = $rr['status'] === 200 && str_contains($h, 'rules[n9]') && !str_contains($h, '<script');
        T::ok($okRow, "JSON row ($k): devuelve la pieza con los nombres de campo pedidos");
    }
    $h = $s->json($s->ajax('/ajax/rules/row', ['set' => $sid, 'kind' => 'cond', 'np' => 'rules[n9][when][items][c5]', 'field' => 'trucks', 'op' => 'between']))['html'];
    T::ok(str_contains($h, 'name="rules[n9][when][items][c5][value][0]"') && str_contains($h, 'name="rules[n9][when][items][c5][value][1]"') && str_contains($h, '>camiones<'), '  una condición «entre» trae sus dos campos y la unidad');
    $h = $s->json($s->ajax('/ajax/rules/row', ['set' => $sid, 'kind' => 'act', 'np' => 'rules[n9][then][a2]', 'type' => 'telegram_alert']))['html'];
    T::ok(!str_contains($h, 'value="telegram_alert"') && str_contains($h, 'name="rules[n9][then][a2][points]"'), '  una acción de alerta en un conjunto de puntuación no se ofrece: el tipo se corrige al primero válido (puntos)');
    $bad = [];
    foreach (['rules[x"onfocus="alert(1)]', 'rules[x][when]<img>', '../etc/passwd', 'rules', 'rules[a][b][c][d][e][f]', "rules[a]\n", ''] as $np) {
        $rr = $s->ajax('/ajax/rules/row', ['set' => $sid, 'kind' => 'cond', 'np' => $np]);
        if ($rr['status'] !== 422) {
            $bad[] = "np «" . substr($np, 0, 20) . "» -> {$rr['status']}";
        }
    }
    T::ok(!$bad, 'JSON row: un prefijo de campo que no cumple la lista blanca se rechaza (422), sin imprimirlo' . ($bad ? ': ' . implode('; ', $bad) : ''));
    T::eq($s->ajax('/ajax/rules/row', ['set' => $sid, 'kind' => 'borrar', 'np' => 'rules[a]'])['status'], 422, '  y un tipo de pieza desconocido también');
    // Vista previa
    $qs = http_build_query($form($good2));
    $pv = $s->json($s->ajax('/ajax/rules/preview', ['set' => $sid, 'form' => $qs, 'impact' => true, 'scope' => 'last:100']));
    T::ok(($pv['ok'] ?? false) && $pv['valid'] === true && str_contains($pv['phrases']['r_tx']['when'], 'el estado está en TX, FL') && str_contains($pv['html'], 'leads cambiarían') && str_contains($pv['html'], 'MUESTRAS') && str_contains($pv['html'], '<svg'), 'JSON preview: frases, validación e impacto con muestras y mini-gráfico SVG');
    $bad = $good2;
    $bad['r_tx']['when']['items']['c1']['value'] = 'ZZ';
    $pv = $s->json($s->ajax('/ajax/rules/preview', ['set' => $sid, 'form' => http_build_query($form($bad)), 'impact' => true]));
    T::ok($pv['valid'] === false && isset($pv['errors']['rules.r_tx.when.items.c1.value']) && !isset($pv['phrases']['r_tx']) && str_contains($pv['html'], 'Corrige los campos'), 'JSON preview: errores por ruta de campo, sin frase de la regla inválida y sin impacto');
    $big = $s->ajax('/ajax/rules/preview', ['set' => $sid, 'form' => str_repeat('a=b&', 60000)]);
    T::ok(in_array($big['status'], [200, 413], true) && ($big['status'] === 413 || ($s->json($big)['valid'] ?? true) !== null), 'JSON preview: un cuerpo enorme se rechaza (413) o se ignora sin romper');
    $x = $s->ajax('/ajax/rules/preview', ['set' => $sid, 'form' => 'x']);
    T::ok($x['status'] === 200, '  y un formulario vacío no rompe');
    // Tope de uso
    foreach (glob(\Captacion\Services\Storage::dir('ratelimit') . '/*.json') ?: [] as $f) {
        @unlink($f);
    }
    for ($i = 0; $i < 90; $i++) {
        RateLimiter::hit('rules_prev', 'u' . $aid, 60);
    }
    $lim = $s->ajax('/ajax/rules/preview', ['set' => $sid, 'form' => $qs, 'impact' => false]);
    T::ok($lim['status'] === 429 && str_contains($lim['body'], 'rate_limited'), 'límite de uso: más de 90 vistas previas por minuto responden 429');
    foreach (glob(\Captacion\Services\Storage::dir('ratelimit') . '/*.json') ?: [] as $f) {
        @unlink($f);
    }
    // Simulación por JSON (guarda el borrador y la asocia)
    for ($i = 0; $i < 60; $i++) {
        Fx::lead(['state' => 'TX', 'first_name' => 'Sim' . $i, 'truck_count' => $i % 8]);
    }
    $sim = $s->ajax('/ajax/rules/simulate', ['set' => $sid, 'form' => http_build_query($form($good2, ['note' => 'Para simular'])), 'scope' => 'last:500']);
    $sj = $s->json($sim);
    $dr = RuleSets::draft($sid);
    T::ok($sim['status'] === 200 && $sj['ok'] && $sj['status'] === 'done' && $dr !== null && $dr['note'] === 'Para simular' && (int) Db::value('SELECT version_id FROM rule_simulations WHERE id = ?', [$sj['id']]) === (int) $dr['id'], 'JSON simulate: guarda el borrador, corre la simulación y la deja asociada a esa versión');
    T::ok(str_contains($sj['html'], 'Terminada') && str_contains($sj['html'], 'Descargar CSV') && str_contains($sj['html'], 'cambiarían'), '  y devuelve el resultado');
    $st = $s->json($s->ajax('/ajax/rules/simstatus', ['id' => $sj['id']]));
    T::ok($st['ok'] && $st['status'] === 'done' && $st['percent'] === 100, 'JSON simstatus: estado y avance');
    T::eq($s->ajax('/ajax/rules/simstatus', ['id' => 999999])['status'], 404, '  una simulación que no existe da 404');
    $inv = $s->ajax('/ajax/rules/simulate', ['set' => $sid, 'form' => http_build_query($form($bad)), 'scope' => 'last:500']);
    T::ok($inv['status'] === 422 && isset($s->json($inv)['errors']), 'JSON simulate: con errores de validación no simula (422)');
    $rg = $s->ajax('/ajax/rules/simulate', ['set' => $sid, 'form' => http_build_query($form($good2)), 'scope' => 'range', 'from' => '2026-01-05', 'to' => '2026-01-01']);
    T::ok($rg['status'] === 422, '  ni con un rango de fechas invertido')   ;
    // CSV
    $csv = $post($s, '/config/rules/simcsv', ['sim' => (string) $sj['id'], 'set' => (string) $sid]);
    T::ok($csv['status'] === 200 && str_contains(Infra::header($csv, 'content-type'), 'text/csv') && str_contains(Infra::header($csv, 'content-disposition'), 'simulacion-reglas-' . $sj['id'] . '.csv') && str_starts_with($csv['body'], "\xEF\xBB\xBF") && str_contains($csv['body'], 'LD-'), 'CSV: descarga con nombre, BOM y filas');
    T::ok(!str_contains($csv['body'], '@example.com') && !str_contains($csv['body'], '+1305'), '  sin correos ni teléfonos');
    T::eq((int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'rules_sim_export'"), 1, '  y queda registrado en la actividad');
    $nope = $post($s, '/config/rules/simcsv', ['sim' => '999999', 'set' => (string) $sid]);
    T::ok($nope['status'] === 303 && !str_contains($nope['body'], 'LD-'), 'CSV: una simulación inexistente no entrega nada');
    // La simulación no tocó los leads
    T::eq((int) Db::value('SELECT score FROM leads WHERE first_name = ?', ['Sim3']), 55, 'simulación por HTTP: las puntuaciones guardadas de los leads no cambiaron');

    // ------------------------------------------------------------------ Paleta, plantillas, eliminación
    $home = $s->get('/');
    preg_match('/data-cmds=\'([^\']*)\'|data-cmds="([^"]*)"/', $home['body'], $mc);
    $cm = html_entity_decode($mc[1] !== '' ? $mc[1] : ($mc[2] ?? ''), ENT_QUOTES);
    T::ok(str_contains($cm, 'Ir a Reglas') && str_contains($cm, 'Nueva regla') && str_contains($cm, 'Simular reglas') && str_contains($cm, 'rules_go'), 'paleta: «Ir a Reglas», «Nueva regla» y «Simular reglas» para el administrador');
    T::eq(Commands::forUser(['role' => 'viewer'], []), [], '  y el lector no recibe nada');
    $acts = array_column(Commands::forUser(['role' => 'admin'], [])['actions'], 1);
    T::ok(in_array('Ir a Reglas', $acts, true) && in_array('Nueva regla', $acts, true) && in_array('Simular reglas', $acts, true), '  (catálogo armado en el servidor por rol)');
    $nv = $s->get('/config?tab=reglas&nueva=1');
    T::ok($nv['status'] === 200 && str_contains($nv['body'], 'data-focus="new"') && str_contains($nv['body'], 'data-rp-form') && substr_count($nv['body'], 'data-rule data-key') === 3, 'paleta «Nueva regla»: abre el conjunto con una regla en blanco al final');
    $sm = $s->get('/config?tab=reglas&simular=1');
    T::ok(str_contains($sm['body'], 'data-focus="sim"') && str_contains($sm['body'], 'data-sim-go'), 'paleta «Simular»: abre el editor enfocado en la simulación');
    $r = $post($s, '/config/rules/starter', []);
    T::ok($r['status'] === 303 && $nSets() === 4, 'plantillas: «Añadir ejemplos» crea los 3 conjuntos de ejemplo');
    $lst = $s->get('/config?tab=reglas');
    T::ok(substr_count($lst['body'], 'class="rl-card') >= 4 && str_contains($lst['body'], 'Ejemplos de puntuación') && str_contains($lst['body'], 'Borrador') && str_contains($lst['body'], 'Publicada'), 'lista: tarjetas con estado (Borrador, Publicada), versión y fecha');
    $ex = (int) Db::value("SELECT id FROM rule_sets WHERE name = 'Ejemplos de alertas'");
    $ep = $s->get("/config?tab=reglas&set=$ex");
    T::ok(str_contains($ep['body'], 'rule__ex') && str_contains($ep['body'], 'Owner-operator con {camiones} camiones') && !preg_match('/data-on\s+checked/', $ep['body']), 'plantilla: la regla de ejemplo sale marcada «ejemplo» y desactivada');
    $r = $post($s, '/config/rules/delete', ['set' => (string) $ex]);
    T::ok($r['status'] === 303 && $nSets() === 3 && Db::value('SELECT COUNT(*) FROM rule_sets WHERE id = ?', [$ex]) == 1, 'borrar un conjunto: borrado lógico con identidad vigente');
    $r = $post($s, '/config/rules/reset', []);
    Settings::clear();
    RuleSets::clearCache();
    T::ok($r['status'] === 303 && RuleSets::engineSets() === [], 'restablecer a la puntuación del documento: retira todo lo publicado');
    $ul = Infra::http('POST', $s->base . '/config/unlock', ['form' => ['_csrf' => $s->csrf, 'tab' => 'reglas', 'set' => (string) $sid, 'password' => 'mal', 'code' => '000000'], 'jar' => $s->jar]);
    T::ok($ul['status'] === 303 && str_contains($loc($ul), 'tab=reglas&set=' . $sid), 'confirmar identidad desde el editor vuelve al mismo conjunto');
    // Recursos estáticos
    foreach (['rules.css' => 'text/css', 'rules.js' => 'javascript', 'cfg-reglas.css' => 'text/css'] as $f => $mime) {
        $a = Infra::http('GET', $s->base . '/assets/' . $f);
        T::ok($a['status'] === 200 && str_contains(Infra::header($a, 'content-type'), $mime) && !str_contains($a['body'], 'style="') && !preg_match('/#[0-9a-fA-F]{3,8}\b/', (string) preg_replace('/\/\*.*?\*\//s', '', $f === 'rules.js' ? '' : $a['body'])), "recurso $f: se sirve y, si es CSS, no lleva colores fijos (todo son variables)");
    }
    T::ok(filesize(CAPTACION_ROOT . '/public/assets/rules.css') < 16000 && filesize(CAPTACION_ROOT . '/public/assets/rules.js') < 24000, 'tamaños: rules.css < 16 KB y rules.js < 24 KB sin comprimir (hojas diferidas; la primera pantalla de la pestaña sigue bajo 45 KB)');
};
