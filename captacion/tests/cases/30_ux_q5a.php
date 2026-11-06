<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Models\Lead;
use Captacion\Services\Commands;
use Captacion\Services\Consent;
use Captacion\Services\LeadActions;
use Captacion\Services\LeadQuery;
use Captacion\Services\Prefs;
use Captacion\Services\SavedViews;
use Captacion\Services\Tasks;
use Captacion\Services\Undo;
use CaptacionTests\Fx;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/**
 * Q5a: experiencia del panel. Preferencias por usuario (tema, barra lateral, densidad, columnas), vistas guardadas (CRUD, aislamiento, contadores), vistazo rápido, acciones rápidas,
 * alta manual por el motor, «Deshacer» de cada acción reversible, paleta de comandos por rol, bandeja de avisos y la prueba negativa del lector.
 */
return static function (array $ctx): void {
    T::reset(true);
    $admin = Panel::user('admin');
    $other = Panel::user('admin');
    $viewerU = Panel::user('viewer');
    $uid = (int) $admin['id'];
    $oid = (int) $other['id'];
    $now = Clock::time();

    // ------------------------------------------------------------------ Preferencias (servicio)
    T::eq(Prefs::all($uid), Prefs::DEFAULTS, 'preferencias: sin nada guardado salen los valores por defecto');
    T::ok(Prefs::set($uid, 'theme', 'dark') && Prefs::get($uid, 'theme') === 'dark', 'preferencias: el tema oscuro se guarda y se lee');
    T::eq(Prefs::get($oid, 'theme'), 'auto', '  y es por usuario: el otro usuario sigue en automático');
    T::ok(!Prefs::set($uid, 'theme', 'rojo') && !Prefs::set($uid, 'inventada', 'x') && !Prefs::set($uid, 'density', ['x']) && Prefs::get($uid, 'theme') === 'dark', 'preferencias: valores y claves inválidos se rechazan sin tocar lo guardado');
    T::ok(Prefs::set($uid, 'cols', 'when,prof,inventada,stage') && Prefs::columns($uid) === ['prof', 'stage', 'when'], 'columnas: se normalizan al orden canónico y se descartan las desconocidas');
    T::ok(!Prefs::set($uid, 'cols', '') && !Prefs::set($uid, 'cols', 'solo,inventadas'), '  y no se puede dejar la lista sin ninguna columna válida');
    Prefs::touchRecent($uid, 5);
    Prefs::touchRecent($uid, 7);
    Prefs::touchRecent($uid, 5);
    T::eq(Prefs::recent($uid), [5, 7], 'recientes: el último abierto va primero y no se repite');
    Prefs::set($uid, 'theme', 'auto');
    Prefs::set($uid, 'cols', Prefs::DEFAULTS['cols']);

    // ------------------------------------------------------------------ Datos
    $mk = static function (array $o = []): int {
        static $n = 0;
        $n++;
        return Db::insert('leads', $o + ['profile' => 'owner_operator', 'first_name' => 'Ux' . $n, 'last_name' => 'Prueba' . $n, 'phone_e164' => '+1305559' . str_pad((string) (100 + $n), 4, '0', STR_PAD_LEFT), 'email' => "ux$n@example.com",
            'state' => 'TX', 'language' => 'en', 'score' => 55, 'temperature' => 'warm', 'pipeline' => 'A', 'stage' => 'new', 'source' => 'web_careers', 'attempt' => 1, 'equipment_types' => ['dry-van'], 'truck_count' => 2,
            'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
    };
    $hot = $mk(['temperature' => 'hot', 'score' => 90, 'first_name' => 'Caliente']);
    $warm1 = $mk(['first_name' => 'Tibio', 'state' => 'TX']);
    $warm2 = $mk(['first_name' => 'Otro', 'state' => 'FL']);
    $presented = $mk(['stage' => 'qualified', 'first_contact_at' => Clock::at($now - 3000), 'first_name' => 'Presenta']);
    Db::insert('tasks', ['title' => 'Llamar mañana', 'lead_id' => $warm1, 'due_at' => Clock::at($now + 7200), 'priority' => 2, 'created_at' => Clock::now()]);
    Db::insert('tasks', ['title' => 'Vencida', 'lead_id' => $hot, 'due_at' => Clock::at($now - 7200), 'priority' => 1, 'created_at' => Clock::now()]);

    // ------------------------------------------------------------------ Filtros nuevos y código corto
    T::eq(LeadQuery::filters(['nt' => '1', 'op' => '1', 'hs' => 'wait', 'zz' => '1']), ['nt' => '1', 'op' => '1', 'hs' => 'wait'], 'filtros nuevos: sin tarea, abiertos y presentados sin respuesta (lo demás se descarta)');
    $noTask = array_column(Db::all('SELECT l.id FROM leads l WHERE ' . LeadQuery::where(['nt' => '1'])[0], LeadQuery::where(['nt' => '1'])[1]), 'id');
    T::ok(!in_array((string) $warm1, array_map('strval', $noTask), true) && !in_array((string) $hot, array_map('strval', $noTask), true) && in_array((string) $warm2, array_map('strval', $noTask), true), 'filtro «sin tarea»: excluye a los que tienen una tarea abierta');
    T::eq(LeadQuery::count(['t' => 'hot', 'ct' => 'none']), 1, 'contador de una vista: calientes sin contactar = 1');
    T::eq(\Captacion\Services\GlobalSearch::run(\Captacion\Services\Fmt::code($warm1))['items'][0]['id'] ?? 0, $warm1, 'búsqueda por código corto (LD-0001): encuentra el lead');

    // ------------------------------------------------------------------ Vistas guardadas (servicio)
    $sys = array_column(SavedViews::system(), 'id');
    T::eq($sys, ['sys-hot_pending', 'sys-today', 'sys-no_task', 'sys-discarded', 'sys-presented_wait', 'sys-review'], 'vistas del sistema: Calientes pendientes, Llegaron hoy, Sin tarea, Descartados, Presentados sin respuesta y (Q5b) Por revisar');
    $r = SavedViews::create($uid, 'Owner-operators de TX', ['pf' => 'owner_operator', 'us' => 'tx', 'zz' => 'basura', 'q' => 'zzzqq'], 'score', 'asc', null);
    T::ok($r['ok'] && is_int($r['id']), 'vistas: guardar filtros y orden con un nombre');
    $vid = (int) $r['id'];
    $mine = SavedViews::mine($uid);
    T::ok($mine[0]['filters'] === ['pf' => 'owner_operator', 'us' => 'TX', 'q' => 'zzzqq'] && $mine[0]['sort'] === 'score' && $mine[0]['dir'] === 'asc', '  se guardan solo los filtros válidos (normalizados) y el orden');
    T::ok(!SavedViews::create($uid, 'owner-operators de tx', ['t' => 'hot'], null, null, null)['ok'], 'vistas: el nombre repetido (sin importar mayúsculas) se rechaza');
    T::ok(!SavedViews::create($uid, '', ['t' => 'hot'], null, null, null)['ok'] && !SavedViews::create($uid, str_repeat('x', 61), ['t' => 'hot'], null, null, null)['ok'], 'vistas: nombre vacío o de más de 60 caracteres se rechaza');
    T::ok(!SavedViews::create($uid, 'Sin filtros', [], null, null, null)['ok'], 'vistas: sin filtros ni orden no se guarda');
    T::eq(SavedViews::mine($oid), [], 'vistas: el otro usuario no ve las mías');
    T::ok(SavedViews::find($oid, (string) $vid) === null && !SavedViews::rename($oid, $vid, 'robada')['ok'] && !SavedViews::pin($oid, $vid, true)['ok'] && !SavedViews::delete($oid, $vid)['ok'] && !SavedViews::update($oid, $vid, ['t' => 'hot'], null, null, null)['ok'], 'vistas: otro usuario no puede leer, renombrar, fijar, actualizar ni borrar la mía (aislamiento)');
    T::ok(SavedViews::rename($uid, $vid, 'OO de Texas')['ok'] && SavedViews::mine($uid)[0]['name'] === 'OO de Texas', 'vistas: renombrar');
    $cnt0 = array_column(SavedViews::sidebar($uid), 'count', 'id');
    T::ok(!isset($cnt0[(string) $vid]) && isset($cnt0['sys-hot_pending']) && !isset($cnt0['sys-no_task']), 'barra lateral: solo las vistas fijadas (las del sistema marcadas y las mías fijadas)');
    T::ok(SavedViews::pin($uid, $vid, true)['ok'], 'vistas: fijar en la barra lateral');
    $cnt = array_column(SavedViews::sidebar($uid), 'count', 'id');
    T::eq($cnt[(string) $vid] ?? null, 0, 'contador en vivo de la vista fijada: 0 (nadie coincide con el texto)');
    T::ok(SavedViews::update($uid, $vid, ['pf' => 'owner_operator', 'us' => 'TX'], 'score', 'asc', null)['ok'], 'vistas: actualizar filtros');
    $mk(['state' => 'TX', 'first_name' => 'NuevoTX']);
    T::eq(array_column(SavedViews::sidebar($uid), 'count', 'id')[(string) $vid] ?? null, 4, 'contador en vivo: sube al entrar un lead que coincide (TX owner-operators)');
    $act = SavedViews::active($uid, ['v' => (string) $vid], ['pf' => 'owner_operator', 'us' => 'TX']);
    T::ok($act !== null && $act['dirty'] === false && SavedViews::active($uid, ['v' => (string) $vid], ['pf' => 'owner_operator'])['dirty'] === true, 'vista activa: se detecta sin cambios y modificada');
    T::ok(SavedViews::active($oid, ['v' => (string) $vid], []) === null && SavedViews::active($uid, ['v' => '999999'], []) === null && SavedViews::active($uid, ['v' => "x'; DROP"], []) === null, '  y otra cuenta, un id inexistente o basura en `v` no activan nada');
    $href = SavedViews::href(SavedViews::find($uid, (string) $vid), 'leads');
    T::ok(str_starts_with($href, '/leads?') && str_contains($href, 'pf=owner_operator') && str_contains($href, 'v=' . $vid) && str_contains($href, 'sort=score'), 'enlace de la vista: filtros, orden e identificador, sin datos personales');
    $today = SavedViews::find($uid, 'sys-today');
    T::ok(($today['filters']['from'] ?? '') === \Captacion\Services\Fmt::et($now)->format('Y-m-d'), 'vista «Llegaron hoy»: la fecha de hoy (hora ET) se calcula al abrirla');
    for ($i = 0; $i < SavedViews::MAX - 1; $i++) {
        SavedViews::create($uid, 'V' . $i, ['t' => 'hot'], null, null, null);
    }
    T::ok(!SavedViews::create($uid, 'Una de más', ['t' => 'cold'], null, null, null)['ok'], 'vistas: tope de ' . SavedViews::MAX . ' por usuario');
    T::ok(SavedViews::delete($uid, $vid)['ok'] && SavedViews::find($uid, (string) $vid) === null && (int) Db::value('SELECT COUNT(*) FROM saved_views WHERE id = ? AND deleted_at IS NOT NULL', [$vid]) === 1, 'vistas: borrar es lógico y desaparece de la barra lateral');
    Db::pdo()->exec('DELETE FROM saved_views');

    // ------------------------------------------------------------------ Deshacer (servicio)
    $c1 = $mk(['stage' => 'new']);
    T::ok(LeadActions::setStage($c1, 'contacted', ['note' => 'x'], $uid)['ok'] && Db::value('SELECT first_contact_at FROM leads WHERE id = ?', [$c1]) !== null, 'deshacer: (preparación) Nuevo -> Contactado fija el primer contacto');
    $tok = Undo::stage($uid, $c1, 'new', 'contacted');
    T::ok($tok !== null && Undo::verify($tok, $uid) !== null, 'deshacer: el cambio de etapa emite un token firmado');
    T::ok(Undo::verify($tok, $oid) === null && Undo::verify($tok . 'x', $uid) === null && Undo::verify(substr($tok, 0, -3) . 'AAA', $uid) === null && Undo::verify('basura', $uid) === null, '  y no vale para otro usuario ni manipulado');
    $r = Undo::apply($tok, $uid);
    $l = Lead::find($c1);
    T::ok($r['ok'] && $l['stage'] === 'new' && $l['first_contact_at'] === null, '  al aplicarlo vuelve a Nuevo y quita el primer contacto que ese cambio fijó');
    T::ok((int) Db::value("SELECT COUNT(*) FROM lead_events WHERE lead_id = ? AND type = 'stage_changed' AND payload LIKE '%\"undo\":true%'", [$c1]) === 1 && (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'lead_stage_undo' AND entity_id = ?", [$c1]) === 1, '  deja evento en el historial y en el registro de actividad');
    T::ok(!Undo::apply($tok, $uid)['ok'], '  y repetirlo no hace nada (el lead ya no está en esa etapa)');
    // otro cambio posterior invalida el deshacer
    LeadActions::setStage($c1, 'contacted', [], $uid);
    $tok2 = Undo::stage($uid, $c1, 'new', 'contacted');
    LeadActions::setStage($c1, 'qualified', [], $uid);
    T::ok(!Undo::apply($tok2, $uid)['ok'] && Lead::find($c1)['stage'] === 'qualified', 'deshacer: si el lead ya cambió de etapa, no se deshace (con las mismas validaciones)');
    // perdido: borra motivo
    $c2 = $mk();
    LeadActions::setStage($c2, 'lost', ['reason' => 'price', 'note' => 'x'], $uid);
    $r = Undo::apply((string) Undo::stage($uid, $c2, 'new', 'lost'), $uid);
    $l = Lead::find($c2);
    T::ok($r['ok'] && $l['stage'] === 'new' && $l['lost_reason'] === null && $l['lost_at'] === null, 'deshacer: Perdido vuelve a la etapa anterior sin motivo ni fecha de pérdida');
    // presentado / firmado: sin deshacer
    T::ok(Undo::stage($uid, $c2, 'qualified', 'presented') === null && Undo::stage($uid, $c2, 'presented', 'contacted') === null && Undo::stage($uid, $c2, 'presented', 'signed') === null && Undo::stage($uid, $c2, 'lost', 'new') === null, 'deshacer: no se ofrece cuando hay entrega a NorthStar de por medio ni desde Perdido');
    T::ok(!LeadActions::undoStage($c2, 'qualified', 'presented', $uid)['ok'], '  y el servicio también lo rechaza si alguien lo fuerza');
    // masivo
    $b1 = $mk();
    $b2 = $mk();
    $b3 = $mk(['stage' => 'contacted', 'first_contact_at' => Clock::now()]);
    $bulk = LeadActions::bulkStage([$b1, $b2, $b3], 'contacted', [], $uid);
    $btok = Undo::bulk($uid, $bulk['changes']);
    T::ok($bulk['changed'] === 2 && count($bulk['changes']) === 2 && $btok !== null, 'deshacer masivo: el cambio masivo devuelve la lista de cambios reales (2; el que ya estaba en la etapa se omitió)');
    LeadActions::setStage($b2, 'qualified', [], $uid);   // uno cambia después
    $r = Undo::apply($btok, $uid);
    T::ok($r['ok'] && Lead::find($b1)['stage'] === 'new' && Lead::find($b2)['stage'] === 'qualified' && Lead::find($b3)['stage'] === 'contacted' && str_contains($r['msg'], '1 cambio deshecho') && str_contains($r['msg'], '1 no se pudieron'), 'deshacer masivo: revierte los que siguen igual y avisa de los que ya cambiaron');
    // tarea completada
    $tk = (int) Db::insert('tasks', ['title' => 'Hacer algo', 'lead_id' => $b1, 'due_at' => Clock::at($now + 60), 'priority' => 2, 'created_at' => Clock::now()]);
    LeadActions::completeTask($b1, $tk, $uid);
    T::ok(Undo::apply(Undo::task($uid, $tk), $uid)['ok'] && Db::value('SELECT done_at FROM tasks WHERE id = ?', [$tk]) === null && !Undo::apply(Undo::task($uid, $tk), $uid)['ok'], 'deshacer: completar una tarea la reabre (y dos veces no)');
    // nota borrada (borrado lógico)
    LeadActions::addNote($b1, 'Nota importante', $uid);
    $nid = (int) Db::value('SELECT id FROM lead_notes WHERE lead_id = ? ORDER BY id DESC LIMIT 1', [$b1]);
    LeadActions::deleteNote($b1, $nid, $uid);
    T::ok(Db::value('SELECT deleted_at FROM lead_notes WHERE id = ?', [$nid]) !== null && Undo::apply(Undo::note($uid, $b1, $nid), $uid)['ok'] && Db::value('SELECT deleted_at FROM lead_notes WHERE id = ?', [$nid]) === null, 'deshacer: borrar una nota (borrado lógico) la restaura con el mismo texto');
    // caducidad
    $old = Undo::task($uid, $tk);
    Clock::travel(Undo::TTL + 5);
    T::ok(Undo::verify($old, $uid) === null && !Undo::apply($old, $uid)['ok'], 'deshacer: el token caduca a los 10 minutos');
    Clock::travel(0);

    // ------------------------------------------------------------------ Paleta de comandos y catálogo por rol
    $cmd = Commands::forUser(['role' => 'admin'], [['name' => 'Calientes pendientes', 'href' => '/leads?t=hot']]);
    $screens = array_column($cmd['screens'], 0);
    T::ok(in_array('Configuración', $screens, true) && in_array('Pipeline · Tablero', $screens, true) && $cmd['views'][0][0] === 'Calientes pendientes' && in_array('Nuevo lead', array_column($cmd['actions'], 1), true) && in_array('Marcar respondió', array_column($cmd['leadActions'], 1), true), 'paleta: el administrador recibe pantallas, vistas, acciones y acciones sobre un lead');
    T::eq(Commands::forUser(['role' => 'viewer'], []), [], 'paleta: el lector no recibe nada');

    // ------------------------------------------------------------------ HTTP
    $s = Panel::start($ctx, 'panel30');
    $anon = $s->another();
    T::ok($s->login($admin['email']), 'login del administrador');
    $o = $s->another();
    T::ok($o->login($other['email']), 'login del segundo administrador');
    $v = $s->another();
    T::ok($v->login($viewerU['email']), 'login del lector');
    Db::insert('tasks', ['title' => 'Tarea del vistazo', 'lead_id' => $warm1, 'due_at' => Clock::at($now + 9000), 'priority' => 2, 'created_at' => Clock::now()]);
    for ($i = 0; $i < 8; $i++) {
        LeadActions::addNote($warm1, 'Nota ' . $i, $uid);
        LeadActions::logCall($warm1, 'no_answer', null, '', $uid);
    }

    // --- tema por usuario, sin parpadeo (atributo en <html> desde el servidor + script con nonce)
    $page = $s->get('/');
    T::ok(preg_match('/<html lang="es" data-theme-pref="auto" data-side="open" data-density="comfortable">/', $page['body']) === 1 && str_contains($page['body'], 'name="color-scheme" content="light dark"') && preg_match('/<script nonce="[^"]+">\(function\(d,w\)\{var h=d\.documentElement/', $page['body']) === 1, 'tema: <html> trae la preferencia del usuario y un script con nonce que fija data-theme antes de pintar');
    T::eq($s->ajax('/ajax/prefs', ['key' => 'theme', 'value' => 'dark'])['status'], 200, 'tema: guardar «oscuro» por /ajax/prefs');
    $page = $s->get('/leads');
    T::ok(str_contains($page['body'], 'data-theme-pref="dark"') && Prefs::get($uid, 'theme') === 'dark' && Prefs::get($oid, 'theme') === 'auto', '  la preferencia persiste en la base, se aplica al recargar y no afecta al otro usuario');
    T::ok(str_contains($o->get('/')['body'], 'data-theme-pref="auto"'), '  el otro usuario sigue en automático');
    T::ok(preg_match('/data-theme-set="light"[^>]*aria-pressed="false".*data-theme-set="dark"[^>]*aria-pressed="true"/s', $page['body']) === 1 && str_contains($page['body'], 'data-theme-cycle'), 'tema: el menú del usuario y la barra superior traen el interruptor (claro / oscuro / auto)');
    T::eq($s->ajax('/ajax/prefs', ['key' => 'theme', 'value' => 'rojo'])['status'], 422, 'prefs: valor inválido 422');
    T::eq($s->ajax('/ajax/prefs', ['key' => 'recent', 'value' => '1,2'])['status'], 422, 'prefs: clave que no es una preferencia (recent) 422');
    T::eq($s->ajax('/ajax/prefs', ['key' => 'theme', 'value' => 'light'], 'falso')['status'], 403, 'prefs: sin CSRF 403');
    T::eq($anon->ajax('/ajax/prefs', ['key' => 'theme', 'value' => 'light'], 'x')['status'], 401, 'prefs: sin sesión 401');
    T::eq($s->ajax('/ajax/prefs', ['key' => 'density', 'value' => 'compact'])['status'], 200, 'densidad compacta guardada');
    T::eq($s->ajax('/ajax/prefs', ['key' => 'cols', 'value' => 'prof,when,task'])['status'], 200, 'columnas de la lista guardadas');
    T::eq($s->ajax('/ajax/prefs', ['key' => 'side', 'value' => 'collapsed'])['status'], 200, 'barra lateral contraída guardada');
    $page = $s->get('/leads');
    T::ok(str_contains($page['body'], 'data-side="collapsed"') && str_contains($page['body'], 'data-density="compact"') && preg_match('/data-hide="[^"]*\bst\b[^"]*\beq\b/', $page['body']) === 1 && !preg_match('/data-hide="[^"]*\btask\b/', $page['body']) && str_contains($page['body'], 'Próxima tarea'), 'la lista aplica la densidad, la barra lateral contraída y las columnas elegidas (ocultas: estado, equipo, etc.; visible: próxima tarea)');
    T::ok(str_contains($s->get('/pipeline')['body'], 'kb kb--compact'), 'el tablero aplica la densidad compacta');
    $s->ajax('/ajax/prefs', ['key' => 'theme', 'value' => 'auto']);
    $s->ajax('/ajax/prefs', ['key' => 'density', 'value' => 'comfortable']);
    $s->ajax('/ajax/prefs', ['key' => 'side', 'value' => 'open']);
    $s->ajax('/ajax/prefs', ['key' => 'cols', 'value' => Prefs::DEFAULTS['cols']]);
    // lector: solo el tema
    T::eq($v->ajax('/ajax/prefs', ['key' => 'theme', 'value' => 'dark'])['status'], 200, 'lector: SÍ puede cambiar el tema');
    T::ok(str_contains($v->get('/resultados')['body'], 'data-theme-pref="dark"'), '  y se le aplica');
    T::eq($v->ajax('/ajax/prefs', ['key' => 'density', 'value' => 'compact'])['status'], 403, 'lector: densidad/columnas/barra lateral 403');
    $vh = $v->get('/resultados')['body'];
    T::ok(!str_contains($vh, 'id="search"') && !str_contains($vh, 'id="keys"') && !str_contains($vh, 'data-cmds') && !str_contains($vh, 'ux.js') && !str_contains($vh, 'data-inbox') && !str_contains($vh, 'data-side-toggle') && !str_contains($vh, 'Vistas</p>') && !str_contains($vh, 'data-keys') && str_contains($vh, 'data-theme-set="dark"'), 'lector: sin paleta, atajos, bandeja, vistas ni ux.js; solo el selector de tema');
    // páginas sin sesión: tema automático (o el último elegido en este navegador)
    $lg = Infra::http('GET', $s->base . '/login', []);
    T::ok(!str_contains($lg['body'], '<html lang="es" data-theme-pref') && str_contains($lg['body'], "localStorage.getItem('ns-theme')") && str_contains($lg['body'], 'beforeprint'), 'login: sin preferencia de servidor, el script usa la del navegador o el modo automático; los informes impresos vuelven a claro (beforeprint)');

    // --- vistas guardadas por HTTP
    $post = static fn(Panel $p, string $path, array $f) => $p->post($path, $f + ['_csrf' => $p->csrf]);
    $r = $post($s, '/vistas/new', ['name' => 'Calientes de TX', 'qs' => 't=hot&us=tx&zz=1&sort=score&dir=asc', 'screen' => 'leads', 'back' => '/leads?t=hot&us=tx']);
    $vv = Db::one('SELECT * FROM saved_views WHERE user_id = ?', [$uid]);
    T::ok($r['status'] === 303 && $vv !== null && $vv['name'] === 'Calientes de TX' && json_decode((string) $vv['filters'], true) === ['t' => 'hot', 'us' => 'TX'] && $vv['sort'] === 'score' && str_contains(Infra::header($r, 'location'), 'v=' . $vv['id']), 'vistas (HTTP): guardar desde los filtros actuales y abrirla');
    $vvid = (int) $vv['id'];
    $pg = $s->get('/leads?t=hot&us=TX&v=' . $vvid);
    T::ok(str_contains($pg['body'], 'Calientes de TX') && str_contains($pg['body'], 'class="dd__panel vmenu"') && str_contains($pg['body'], 'Mis vistas') && str_contains($pg['body'], 'data-view-rename="' . $vvid . '"'), 'la barra de vistas lista la vista guardada (con renombrar, fijar y borrar)');
    T::ok(preg_match('/<a class="vtab is-on" href="[^"]*"[^>]*aria-current="page"><svg[^>]*><use href="#i-list"\/><\/svg>Lista<\/a>/', $pg['body']) === 1 && str_contains($pg['body'], 'href="/pipeline?t=hot&amp;us=TX&amp;v=' . $vvid . '"'), 'pestañas Lista / Tablero: comparten los mismos filtros (la del tablero conserva t, us y v)');
    T::ok(str_contains($pg['body'], 'data-view-count="' . $vvid . '"') === false, 'una vista sin fijar no sale en la barra lateral');
    T::eq($post($s, '/vistas/' . $vvid . '/pin', ['qs' => '', 'screen' => 'leads', 'back' => '/leads'])['status'], 303, 'fijar la vista (formulario)');
    T::ok(str_contains($s->get('/')['body'], 'data-view-count="' . $vvid . '"') && str_contains($s->get('/')['body'], 'data-view-count="sys-hot_pending"'), '  aparece en la barra lateral bajo «Vistas» con su contador, junto a las del sistema');
    T::ok(!str_contains($o->get('/')['body'], 'data-view-count="' . $vvid . '"'), '  y el otro usuario no la ve');
    T::eq($post($o, '/vistas/' . $vvid . '/rename', ['name' => 'robada', 'qs' => '', 'screen' => 'leads', 'back' => '/leads'])['status'], 303, 'otro usuario intenta renombrarla: responde pero no cambia nada');
    T::eq(Db::value('SELECT name FROM saved_views WHERE id = ?', [$vvid]), 'Calientes de TX', '  la vista sigue intacta (aislamiento por usuario)');
    T::ok($post($o, '/vistas/' . $vvid . '/delete', ['qs' => '', 'screen' => 'leads', 'back' => '/leads'])['status'] === 303 && Db::value('SELECT deleted_at FROM saved_views WHERE id = ?', [$vvid]) === null, '  y tampoco puede borrarla');
    T::eq($s->post('/vistas/new', ['name' => 'x', 'qs' => 't=hot', 'screen' => 'leads', 'back' => '/leads', '_csrf' => 'falso'])['status'], 303, 'sin CSRF válido: no se crea (vuelve con aviso)');
    T::eq((int) Db::value('SELECT COUNT(*) FROM saved_views WHERE user_id = ?', [$uid]), 1, '  (sigue habiendo una sola vista)');
    T::eq($v->post('/vistas/new', ['name' => 'x', 'qs' => 't=hot', 'screen' => 'leads', 'back' => '/leads', '_csrf' => $v->csrf])['status'], 403, 'lector: /vistas responde 403');
    T::eq($anon->post('/vistas/new', ['name' => 'x', '_csrf' => 'x'])['status'], 303, 'sin sesión: /vistas redirige a /login');
    $r = $post($s, '/vistas/' . $vvid . '/rename', ['name' => 'Calientes TX', 'qs' => '', 'screen' => 'leads', 'back' => '/leads?v=' . $vvid]);
    T::eq(Db::value('SELECT name FROM saved_views WHERE id = ?', [$vvid]), 'Calientes TX', 'renombrar (formulario)');
    $post($s, '/vistas/' . $vvid . '/update', ['qs' => 't=hot&us=FL', 'screen' => 'leads', 'back' => '/leads']);
    T::eq(json_decode((string) Db::value('SELECT filters FROM saved_views WHERE id = ?', [$vvid]), true), ['t' => 'hot', 'us' => 'FL'], 'guardar cambios de una vista (formulario)');
    $post($s, '/vistas/' . $vvid . '/delete', ['qs' => '', 'screen' => 'leads', 'back' => '/leads?v=' . $vvid]);
    T::ok(Db::value('SELECT deleted_at FROM saved_views WHERE id = ?', [$vvid]) !== null && !str_contains($s->get('/')['body'], 'data-view-count="' . $vvid . '"'), 'borrar la vista (formulario): sale de la barra lateral');
    T::ok(str_contains($s->get('/leads?nt=1&op=1')['body'], 'Sin tarea abierta') && str_contains($s->get('/pipeline?hs=wait')['body'], 'Presentados sin respuesta'), 'chips de filtros activos con los filtros nuevos, en la lista y en el tablero');
    T::eq($s->get('/leads?v=' . "x'%20OR%201=1")['status'], 200, 'parámetro v hostil: 200 y sin efecto');

    // --- contadores y bandeja de avisos
    $j = $s->json($s->ajax('/ajax/counters', []));
    T::ok(($j['ok'] ?? false) && isset($j['views']['sys-hot_pending']) && $j['views']['sys-hot_pending'] === 1 && is_int($j['inbox']) && is_int($j['unread']), 'contadores en vivo: vistas fijadas y bandeja (avisos sin leer + tareas vencidas)');
    T::eq($v->ajax('/ajax/counters', [])['status'], 403, 'lector: contadores 403');
    T::eq($anon->ajax('/ajax/counters', [], 'x')['status'], 401, 'sin sesión: contadores 401');
    $pgx = $s->get('/');
    preg_match_all('/data-key="([^"]+)"/', $pgx['body'], $km);
    T::ok(count($km[1]) >= 1 && str_contains($pgx['body'], 'data-nf="unread"') && str_contains($pgx['body'], 'data-nf="leads"') && str_contains($pgx['body'], 'data-note-toggle') && str_contains($pgx['body'], 'data-inbox'), 'bandeja: lista completa con filtro por tipo, marcar uno y botón «Bandeja» en la barra lateral');
    $key = $km[1][0];
    T::eq($s->json($s->ajax('/ajax/notifications/read', ['keys' => [$key]]))['marked'] ?? 0, 1, 'bandeja: marcar un aviso como leído');
    T::ok(str_contains($s->get('/')['body'], 'data-read="1"') && !str_contains($o->get('/')['body'], 'data-read="1"'), '  queda leído solo para ese usuario');

    // --- vistazo rápido
    $pk = $s->ajax('/ajax/peek', ['id' => $warm1]);
    $pj = $s->json($pk);
    T::ok($pk['status'] === 200 && $pj['ok'] && $pj['code'] === \Captacion\Services\Fmt::code($warm1) && $pj['stage'] === 'new' && isset($pj['stages']['qualified']) && str_contains($pj['html'], 'Tibio Prueba') && str_contains($pj['html'], '+1 (305) 559'), 'vistazo: nombre, código, etapa y datos clave del lead correcto');
    T::ok(substr_count($pj['html'], 'class="tick"') === 2 && str_contains($pj['html'], 'Llamar mañana') && str_contains($pj['html'], 'Tarea del vistazo'), '  próximas tareas (las abiertas)');
    T::eq(substr_count($pj['html'], '<ol class="pk__tl">') === 1 ? substr_count($pj['html'], 'class="dot dot--') : -1, 5, '  y solo las últimas 5 actividades');
    T::ok(str_contains($pj['html'], 'href="/leads/' . $warm1 . '"') && str_contains($pj['html'], 'Abrir ficha completa') && str_contains($pj['html'], 'data-pk-move') && str_contains($pj['html'], 'tel:+1305'), '  con «Abrir ficha completa», cambio de etapa y llamar');
    T::ok(!preg_match('/\sstyle="/i', $pj['html']) && !preg_match('/\son\w+="/i', $pj['html']), '  sin estilos ni handlers en línea');
    $pjLost = $s->json($s->ajax('/ajax/peek', ['id' => $c2]));
    T::ok($pjLost['ok'], 'vistazo de un lead normal funciona');
    T::eq($s->ajax('/ajax/peek', ['id' => 999999])['status'], 404, 'vistazo de un lead inexistente: 404');
    T::eq($s->ajax('/ajax/peek', ['id' => $warm1], 'falso')['status'], 403, 'vistazo sin CSRF: 403');
    T::eq(Infra::http('GET', $s->base . '/ajax/peek', ['jar' => $s->jar])['status'], 405, 'vistazo con GET: 405 (nada va en la URL)');
    $vr = $v->ajax('/ajax/peek', ['id' => $warm1]);
    T::ok($vr['status'] === 403 && !str_contains($vr['body'], 'Tibio') && !str_contains($vr['body'], '555') && !str_contains($vr['body'], 'example.com'), 'lector: el vistazo responde 403 y no filtra ningún dato del lead (prueba negativa)');
    T::eq($anon->ajax('/ajax/peek', ['id' => $warm1], 'x')['status'], 401, 'sin sesión: vistazo 401');
    T::ok(in_array($warm1, Prefs::recent($uid), true), 'el vistazo (y la ficha) se anotan en «recientes» de la paleta');
    $pg = $s->get('/leads?peek=' . $warm1);
    T::ok(str_contains($pg['body'], 'id="peek"') && str_contains($pg['body'], 'data-peek-id="' . $warm1 . '"') && str_contains($pg['body'], 'data-peek="' . $warm1 . '"') && str_contains($pg['body'], 'aria-modal="false"') && str_contains($pg['body'], 'aria-labelledby="pk-title"'), 'la lista trae el panel del vistazo y abre ?peek=ID al recargar; filas con data-peek y enlace a la ficha (sin JS sigue siendo un enlace)');
    T::ok(str_contains($pg['body'], 'href="/leads/' . $warm1 . '"') && str_contains($s->get('/pipeline')['body'], 'id="peek"'), '  también en el tablero');

    // --- acciones rápidas
    $act = static fn(array $b, ?Panel $who = null) => ($who ?? $s)->ajax('/ajax/lead/act', ['id' => $warm1] + $b);
    $r = $act(['act' => 'note', 'body' => 'Nota desde el vistazo']);
    T::ok($r['status'] === 200 && Db::value("SELECT COUNT(*) FROM lead_notes WHERE lead_id = ? AND body = 'Nota desde el vistazo' AND user_id = ?", [$warm1, $uid]) == 1 && Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'note_add' AND entity_id = ?", [$warm1]) > 0, 'acción: nueva nota (mismo servicio, evento y registro que la ficha)');
    T::eq($act(['act' => 'note', 'body' => '   '])['status'], 422, 'acción: nota vacía 422');
    $r = $act(['act' => 'task', 'title' => 'Pedir seguro', 'due' => '2031-01-05T10:00']);
    T::ok($r['status'] === 200 && Db::value("SELECT COUNT(*) FROM tasks WHERE lead_id = ? AND title = 'Pedir seguro'", [$warm1]) == 1, 'acción: nueva tarea con fecha (hora ET)');
    T::eq($act(['act' => 'task', 'title' => ''])['status'], 422, 'acción: tarea sin título 422');
    $tid = (int) Db::value("SELECT id FROM tasks WHERE lead_id = ? AND title = 'Pedir seguro'", [$warm1]);
    $r = $act(['act' => 'task_done', 'task_id' => $tid]);
    $rj = $s->json($r);
    T::ok($r['status'] === 200 && !empty($rj['undo']) && Db::value('SELECT done_at FROM tasks WHERE id = ?', [$tid]) !== null, 'acción: completar una tarea devuelve el token de «Deshacer»');
    $u = $s->json($s->ajax('/ajax/undo', ['token' => $rj['undo']]));
    T::ok(($u['ok'] ?? false) && Db::value('SELECT done_at FROM tasks WHERE id = ?', [$tid]) === null, '  y /ajax/undo la reabre');
    T::eq($o->ajax('/ajax/undo', ['token' => $rj['undo']])['status'], 422, '  el token de otro usuario no sirve (422)');
    $r = $act(['act' => 'call', 'outcome' => 'answered', 'minutes' => 4, 'note' => 'Habló']);
    T::ok($r['status'] === 200 && Db::value("SELECT status FROM messages WHERE lead_id = ? AND channel = 'call' ORDER BY id DESC LIMIT 1", [$warm1]) === 'answered' && Lead::find($warm1)['stage'] === 'contacted', 'acción: llamada contestada (primer contacto y Nuevo -> Contactado, como en la ficha)');
    T::eq($act(['act' => 'call', 'outcome' => 'inventado'])['status'], 422, 'acción: resultado de llamada inválido 422');
    T::eq($act(['act' => 'responded'])['status'], 200, 'acción: «Marcar respondió»');
    T::eq($act(['act' => 'volar'])['status'], 422, 'acción desconocida 422');
    T::eq($s->ajax('/ajax/lead/act', ['id' => 999999, 'act' => 'note', 'body' => 'x'])['status'], 404, 'acción sobre un lead inexistente 404');
    foreach (['note' => ['body' => 'x'], 'task' => ['title' => 'x'], 'call' => ['outcome' => 'answered'], 'responded' => [], 'task_done' => ['task_id' => $tk]] as $a => $extra) {
        $vr = $act(['act' => $a] + $extra, $v);
        T::ok($vr['status'] === 403, "lector: acción {$a} responde 403");
    }
    T::eq($act(['act' => 'note', 'body' => 'x'], $anon)['status'], 401, 'sin sesión: acciones 401');
    $before = (int) Db::value('SELECT COUNT(*) FROM lead_notes');
    $act(['act' => 'note', 'body' => 'sin csrf'], null);
    $bad = Infra::http('POST', $s->base . '/ajax/lead/act', ['json' => ['id' => $warm1, 'act' => 'note', 'body' => 'sin csrf'], 'jar' => $s->jar, 'headers' => ['X-CSRF-Token: falso']]);
    T::ok($bad['status'] === 403 && (int) Db::value('SELECT COUNT(*) FROM lead_notes') === $before + 1, 'acciones: sin CSRF válido 403 y no se escribe nada');

    // --- cambio de etapa desde el vistazo / paleta (mismo endpoint del tablero) y deshacer
    $mv = $s->json($s->ajax('/ajax/pipeline/move', ['lead_id' => $warm2, 'to' => 'contacted']));
    T::ok(($mv['ok'] ?? false) && !empty($mv['undo']) && $mv['from'] === 'new', 'mover a Contactado: devuelve el token de «Deshacer»');
    $u = $s->json($s->ajax('/ajax/undo', ['token' => $mv['undo']]));
    T::ok(($u['ok'] ?? false) && Lead::find($warm2)['stage'] === 'new' && Lead::find($warm2)['first_contact_at'] === null && $u['lead_ids'] === [$warm2], '  deshacer devuelve el lead a Nuevo y el tablero recibe los ids a refrescar');
    $card = $s->json($s->ajax('/ajax/pipeline/card', ['lead_id' => $warm2, 'qs' => '']));
    T::ok(($card['ok'] ?? false) && $card['to'] === 'new' && str_contains($card['card'], 'data-id="' . $warm2 . '"') && isset($card['counts']['new']), 'tablero: /ajax/pipeline/card devuelve la tarjeta actual y los conteos (para refrescar tras deshacer)');
    $mv = $s->json($s->ajax('/ajax/pipeline/move', ['lead_id' => $presented, 'to' => 'presented']));
    T::ok(($mv['ok'] ?? false) && ($mv['undo'] ?? null) === null, 'mover a Presentado: no ofrece deshacer (genera entrega a NorthStar)');
    T::eq($v->ajax('/ajax/pipeline/card', ['lead_id' => $warm2])['status'], 403, 'lector: /ajax/pipeline/card 403');
    $hs = LeadQuery::count(['hs' => 'wait']);
    T::eq($hs, 1, 'vista «Presentados sin respuesta»: cuenta el presentado pendiente');

    // --- deshacer desde los formularios de la ficha (flash + formulario sin JS)
    $c3 = $mk();
    $r = $s->post('/leads/' . $c3 . '/stage', ['_csrf' => $s->csrf, 'stage' => 'contacted']);
    $page = $s->get('/leads/' . $c3);
    T::ok($r['status'] === 303 && str_contains($page['body'], 'data-flash') && str_contains($page['body'], 'action="/undo"') && str_contains($page['body'], 'name="token"') && str_contains($page['body'], 'Deshacer'), 'ficha: tras cambiar la etapa el aviso trae «Deshacer» (formulario que funciona sin JS)');
    preg_match('/name="token" value="([^"]+)"/', $page['body'], $tm);
    $r = $s->post('/undo', ['_csrf' => $s->csrf, 'token' => $tm[1] ?? 'x', 'back' => '/leads/' . $c3]);
    T::ok($r['status'] === 303 && Lead::find($c3)['stage'] === 'new' && Infra::header($r, 'location') === '/leads/' . $c3, 'POST /undo: deshace y vuelve a la ficha');
    T::ok(!str_contains($s->get('/leads/' . $c3)['body'], 'name="token"') || str_contains($s->get('/leads/' . $c3)['body'], 'Cambio deshecho'), '  el aviso de «Deshacer» no se repite al recargar');
    $s->post('/leads/' . $c3 . '/note', ['_csrf' => $s->csrf, 'body' => 'Para borrar']);
    $nid2 = (int) Db::value('SELECT id FROM lead_notes WHERE lead_id = ? ORDER BY id DESC LIMIT 1', [$c3]);
    $s->post('/leads/' . $c3 . '/note-delete', ['_csrf' => $s->csrf, 'note_id' => $nid2]);
    $page = $s->get('/leads/' . $c3);
    preg_match('/name="token" value="([^"]+)"/', $page['body'], $tm);
    T::ok(Db::value('SELECT deleted_at FROM lead_notes WHERE id = ?', [$nid2]) !== null && isset($tm[1]), 'ficha: borrar una nota muestra «Deshacer»');
    $s->post('/undo', ['_csrf' => $s->csrf, 'token' => $tm[1], 'back' => '/leads/' . $c3]);
    T::ok(Db::value('SELECT deleted_at FROM lead_notes WHERE id = ?', [$nid2]) === null, '  y la nota vuelve');
    // tareas (página)
    $tk2 = (int) Db::insert('tasks', ['title' => 'Tarea de la página', 'lead_id' => $c3, 'due_at' => Clock::at($now + 600), 'priority' => 2, 'created_at' => Clock::now()]);
    $s->post('/tareas/' . $tk2 . '/done', ['_csrf' => $s->csrf, 'back' => '/tareas']);
    $page = $s->get('/tareas');
    preg_match('/name="token" value="([^"]+)"/', $page['body'], $tm);
    T::ok(isset($tm[1]), 'tareas: completar una tarea muestra «Deshacer»');
    $s->post('/undo', ['_csrf' => $s->csrf, 'token' => $tm[1] ?? 'x', 'back' => '/tareas']);
    T::ok(Db::value('SELECT done_at FROM tasks WHERE id = ?', [$tk2]) === null, '  y la tarea se reabre');
    // masivo (página)
    $m1 = $mk();
    $m2 = $mk();
    $r = $s->post('/leads/bulk', ['_csrf' => $s->csrf, 'ids' => [$m1, $m2], 'do' => 'stage', 'stage' => 'contacted', 'back' => '/leads']);
    $page = $s->get('/leads');
    preg_match('/name="token" value="([^"]+)"/', $page['body'], $tm);
    T::ok(isset($tm[1]), 'masivo: el cambio de etapa de varios leads muestra «Deshacer»');
    $s->post('/undo', ['_csrf' => $s->csrf, 'token' => $tm[1] ?? 'x', 'back' => '/leads']);
    T::ok(Lead::find($m1)['stage'] === 'new' && Lead::find($m2)['stage'] === 'new', '  y vuelven los dos');
    T::eq($v->post('/undo', ['_csrf' => $v->csrf, 'token' => 'x'])['status'], 403, 'lector: /undo 403');
    $evil = $s->post('/undo', ['_csrf' => $s->csrf, 'token' => 'x', 'back' => 'https://evil.example/']);
    T::ok($evil['status'] === 303 && Infra::header($evil, 'location') === '/', 'POST /undo: `back` solo admite rutas internas (sin redirección abierta)');

    // --- alta manual rápida
    $cr = static fn(array $b, ?Panel $who = null) => ($who ?? $s)->ajax('/ajax/lead/create', $b);
    $r = $cr(['name' => 'Manual Pérez', 'phone' => '(305) 555-7788', 'profile' => 'owner_operator']);
    $cj = $s->json($r);
    $new = Lead::find((int) ($cj['lead_id'] ?? 0));
    T::ok($r['status'] === 200 && ($cj['created'] ?? false) && $new !== null && $new['source'] === 'manual' && $new['phone_e164'] === '+13055557788' && $new['first_name'] === 'Manual' && $new['stage'] === 'new' && str_contains($cj['url'], '/leads/'), 'alta rápida: crea el lead por el motor (fuente manual, teléfono normalizado)');
    T::ok((int) Db::value('SELECT COUNT(*) FROM consents WHERE lead_id = ?', [$new['id']]) === 0 && !Consent::isGranted((int) $new['id'], 'sms') && !Consent::isGranted((int) $new['id'], 'email'), '  sin consentimiento de mensajería');
    T::ok((int) $new['score'] >= 0 && $new['temperature'] !== '' && (int) Db::value("SELECT COUNT(*) FROM lead_events WHERE lead_id = ? AND type = 'created'", [$new['id']]) === 1 && (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'lead_create_manual'") === 1, '  puntuado, con su evento «created» y registro de actividad');
    $dup = $s->json($cr(['name' => 'Manual Pérez', 'phone' => '305-555-7788', 'profile' => 'owner_operator']));
    T::ok(($dup['ok'] ?? false) && ($dup['created'] ?? true) === false && $dup['lead_id'] === $new['id'] && (int) Db::value("SELECT COUNT(*) FROM leads WHERE phone_e164 = '+13055557788'") === 1, 'alta rápida: un teléfono repetido no duplica (dedupe del motor) y abre el existente');
    T::eq($cr(['name' => '', 'phone' => '3055550000', 'profile' => 'owner_operator'])['status'], 422, 'alta rápida: sin nombre 422');
    T::eq($cr(['name' => 'X', 'phone' => '', 'profile' => 'owner_operator'])['status'], 422, 'alta rápida: sin teléfono 422');
    T::eq($cr(['name' => 'X', 'phone' => '3055550000', 'profile' => 'camionero'])['status'], 422, 'alta rápida: perfil inválido 422');
    T::eq($cr(['name' => 'X', 'phone' => '123', 'profile' => 'owner_operator'])['status'], 422, 'alta rápida: teléfono inválido 422');
    T::eq($cr(['name' => 'Ana', 'phone' => '3055559999', 'profile' => 'company_driver']) !== null ? Lead::find((int) $s->json($cr(['name' => 'Beto', 'phone' => '3055559998', 'profile' => 'company_driver']))['lead_id'])['pipeline'] : '', 'B', 'alta rápida: un company driver entra al pipeline B');
    T::eq($cr(['name' => 'X', 'phone' => '3055550001', 'profile' => 'owner_operator'], $v)['status'], 403, 'lector: alta rápida 403');
    T::eq($cr(['name' => 'X', 'phone' => '3055550001', 'profile' => 'owner_operator'], $anon)['status'], 401, 'sin sesión: alta rápida 401');
    T::ok(!Db::value("SELECT COUNT(*) FROM leads WHERE phone_e164 = '+13055550001'"), '  y no se creó nada');

    // --- paleta y búsqueda global
    $page = $s->get('/');
    preg_match('/id="search"[^>]*data-cmds="([^"]*)"/', $page['body'], $cm);
    $cj = json_decode(html_entity_decode($cm[1] ?? '{}', ENT_QUOTES), true) ?: [];
    T::ok(in_array('Configuración', array_column($cj['screens'] ?? [], 0), true) && count($cj['views'] ?? []) >= 5 && in_array('Nuevo lead', array_column($cj['actions'] ?? [], 1), true) && str_contains($page['body'], 'id="keys"') && str_contains($page['body'], 'id="mv-dlg"') && str_contains($page['body'], 'id="qc-dlg"') && str_contains($page['body'], 'id="toasts"'), 'paleta: el administrador recibe el catálogo en la página, y la hoja de atajos, el diálogo de mover, el de alta y el contenedor de avisos');
    T::ok(str_contains($page['body'], 'data-keys') && str_contains($page['body'], '<kbd>g</kbd> <kbd>d</kbd>') && str_contains($page['body'], '<kbd>j</kbd> <kbd>/</kbd> <kbd>k</kbd>') && str_contains($page['body'], 'Con un lead abierto'), 'hoja de atajos: g d / g l / g p / g t, j / k, Enter, n, c, m, Esc y ?');
    $rs = $s->json($s->ajax('/ajax/search', ['q' => '']));
    T::ok(($rs['ok'] ?? false) && count($rs['items']) >= 1 && count($rs['items']) <= 5, 'búsqueda vacía: devuelve los leads recientes del usuario (máximo 5)');
    T::eq($o->json($o->ajax('/ajax/search', ['q' => '']))['items'] ?? null, [], '  y el otro usuario no ve los míos (sus recientes están vacíos)');
    T::eq($v->ajax('/ajax/search', ['q' => ''])['status'], 403, 'lector: la búsqueda responde 403');
    T::ok(count($s->json($s->ajax('/ajax/search', ['q' => \Captacion\Services\Fmt::code($warm1)]))['items']) === 1, 'búsqueda por código LD-xxxx desde la paleta');

    // --- tarjetas nuevas del tablero
    Db::run('UPDATE leads SET flags = ? WHERE id = ?', [json_encode(['red:authority_inactive']), $hot]);
    $pg = $s->get('/pipeline');
    T::ok(str_contains($pg['body'], 'class="kc__code mono">' . \Captacion\Services\Fmt::code($hot)) && str_contains($pg['body'], 'class="sbar sbar--hot"') && str_contains($pg['body'], 'class="prog"') && str_contains($pg['body'], 'kc__task is-late') && str_contains($pg['body'], 'class="tag tag--bad"') && str_contains($pg['body'], 'class="tag tag--p-owner_operator"'), 'tarjeta: código corto, etiquetas, barra de puntuación, avance, tarea vencida en rojo y bandera FMCSA');
    T::ok(str_contains($pg['body'], 'data-new-lead') && str_contains($pg['body'], 'kcol__add') && substr_count($pg['body'], 'kcol__add') === 1, 'columna Nuevo con «+» para crear un lead; el botón «Nuevo lead» del encabezado');
    T::ok(!preg_match('/\sstyle="/i', $pg['body']) && !preg_match('/\son\w+="/i', $pg['body']) && preg_match('/<script(?![^>]*\bnonce=")/i', $pg['body']) === 0, 'tablero: sin estilos ni handlers en línea y scripts con nonce');
    T::ok(str_contains($pg['body'], '/assets/ux.js') && str_contains($pg['body'], '/assets/peek.js') && str_contains($pg['body'], '/assets/views.js') && str_contains($pg['body'], '/assets/kanban.js'), 'tablero: carga ux.js, peek.js, views.js y kanban.js');

    Infra::stop('panel30');
    T::reset(true);
};
