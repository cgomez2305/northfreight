<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Services\Catalog;
use Captacion\Services\Kanban;
use Captacion\Services\LeadActions;
use Captacion\Services\Queue;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/** Kanban (Q2b-A): columnas, paginacion por columna, filtros, pre-validaciones, transiciones validas e invalidas por la API, permisos por rol, CSRF y limite de uso. */
return static function (array $ctx): void {
    T::reset(true);
    $now = Clock::time();
    $mk = static function (array $o = []) use ($now): int {
        static $n = 0;
        $n++;
        $created = $o['created_at'] ?? Clock::at($now - 7200);
        unset($o['created_at']);
        $row = $o + [
            'profile' => 'owner_operator', 'first_name' => 'Kan' . $n, 'last_name' => 'Ban' . $n, 'phone_e164' => '+1305556' . str_pad((string) (1000 + $n), 4, '0', STR_PAD_LEFT), 'email' => "k$n@example.com",
            'state' => 'TX', 'language' => 'en', 'score' => 50, 'temperature' => 'warm', 'pipeline' => 'A', 'stage' => 'new', 'source' => 'web_careers', 'attempt' => 1,
            'equipment_types' => ['dry-van'], 'flags' => [],
        ];
        $row['created_at'] = $created;
        $row['updated_at'] = $created;
        if (($row['profile'] ?? '') === 'company_driver' && !isset($o['pipeline'])) {
            $row['pipeline'] = 'B';
        }
        return Db::insert('leads', $row);
    };
    $admin = Panel::user('admin');
    $uid = $admin['id'];

    // ------------------------------------------------------------------ Datos y paginacion por columna
    for ($i = 0; $i < 60; $i++) {
        $mk(['score' => 10 + ($i % 80), 'temperature' => $i % 3 === 0 ? 'hot' : 'warm', 'source' => $i % 2 === 0 ? 'meta' : 'web_careers']);
    }
    $contacted = $mk(['stage' => 'contacted', 'first_contact_at' => Clock::at($now - 3000), 'score' => 90, 'temperature' => 'hot']);
    $drv = $mk(['profile' => 'company_driver', 'pipeline' => 'B', 'stage' => 'prequalified', 'first_contact_at' => Clock::at($now - 3000)]);
    $lostLead = $mk(['stage' => 'lost', 'lost_reason' => 'price', 'lost_at' => Clock::at($now - 86400), 'first_contact_at' => Clock::at($now - 90000)]);
    $mk(['first_name' => 'Borrado', 'deleted_at' => Clock::now()]);

    $counts = Kanban::counts('A', []);
    T::eq([$counts['new'], $counts['contacted'], $counts['lost'], $counts['signed']], [60, 1, 1, 0], 'kanban: conteos por etapa del pipeline A (no cuenta borrados ni el pipeline B)');
    T::eq(array_keys($counts), array_keys(Catalog::STAGES['A']), '  una columna por etapa del doc §7, en orden');
    T::eq(array_keys(Kanban::counts('B', [])), array_keys(Catalog::STAGES['B']), '  pipeline B: sus propias etapas (Precalificado, Contratado, Descartado)');
    $p1 = Kanban::cards('A', 'new', [], 0, $counts['new']);
    T::eq([count($p1['cards']), $p1['total'], $p1['next']], [Kanban::PAGE, 60, 25], 'pagina 1 de la columna Nuevo: 25 tarjetas de 60, siguiente offset 25');
    $p2 = Kanban::cards('A', 'new', [], 25);
    $p3 = Kanban::cards('A', 'new', [], 50);
    T::eq([count($p2['cards']), $p2['next'], count($p3['cards']), $p3['next']], [25, 50, 10, null], 'paginas 2 y 3: 25 y 10 tarjetas; la ultima no tiene siguiente');
    $ids = array_merge(array_column($p1['cards'], 'id'), array_column($p2['cards'], 'id'), array_column($p3['cards'], 'id'));
    T::eq(count(array_unique($ids)), 60, 'las 3 paginas no repiten ni omiten tarjetas (60 distintas)');
    $scores = array_map('intval', array_column($p1['cards'], 'score'));
    $sorted = $scores;
    rsort($sorted);
    T::eq($scores, $sorted, 'las tarjetas van por puntuacion descendente');
    T::eq(Kanban::cards('A', 'inventada', [], 0)['cards'], [], 'una etapa que no existe devuelve vacio');
    $card = Kanban::cards('A', 'contacted', [])['cards'][0];
    T::ok($card['id'] === $contacted && array_key_exists('next_task', $card) && array_key_exists('red_flags', $card) && $card['in_stage_since'] !== null, 'la tarjeta trae tarea proxima, banderas rojas y antiguedad en la etapa');
    // filtros rapidos
    T::eq(Kanban::counts('A', ['t' => 'hot'])['new'], 20, 'filtro temperatura=caliente: 20 en Nuevo');
    T::eq(Kanban::counts('A', ['src' => 'meta'])['new'], 30, 'filtro fuente=meta: 30 en Nuevo');
    T::eq(Kanban::counts('A', ['q' => 'Zzzqqx'])['new'], 0, 'busqueda por texto sin coincidencia: 0 (no hay nadie con ese nombre)');
    T::ok(Kanban::counts('A', ['q' => 'Kan1'])['new'] >= 1, 'busqueda por texto (nombre): encuentra');
    T::eq(array_keys(Kanban::filters(['t' => 'hot', 'src' => 'meta', 'q' => 'x', 'pf' => 'owner_operator', 'st' => 'new', 'page' => '3'])), ['t', 'pf', 'src', 'q'], 'filtros del tablero (Q5a): los mismos de la bandeja (comparten vistas), salvo la etapa (son las columnas) y la paginacion');
    // tarea proxima y bandera roja
    Db::insert('tasks', ['title' => 'Llamar de nuevo', 'lead_id' => $contacted, 'due_at' => Clock::at($now + 3600), 'priority' => 2, 'created_at' => Clock::now()]);
    Db::insert('tasks', ['title' => 'Mas tarde', 'lead_id' => $contacted, 'due_at' => Clock::at($now + 86400), 'priority' => 2, 'created_at' => Clock::now()]);
    Db::insert('tasks', ['title' => 'Hecha', 'lead_id' => $contacted, 'due_at' => Clock::at($now - 600), 'priority' => 2, 'done_at' => Clock::now(), 'created_at' => Clock::now()]);
    Db::run('UPDATE leads SET flags = ? WHERE id = ?', [json_encode(['red:authority_inactive', 'fmcsa_pending']), $contacted]);
    $card = Kanban::cards('A', 'contacted', [])['cards'][0];
    T::eq([$card['next_task']['title'], $card['red_flags']], ['Llamar de nuevo', ['red:authority_inactive']], 'tarjeta: la tarea abierta mas proxima (no la hecha) y solo banderas rojas');

    // ------------------------------------------------------------------ Pre-validaciones (las reglas de fondo son las de LeadActions::setStage)
    $lead = fn(int $id) => \Captacion\Models\Lead::find($id);
    $nl = $lead($p1['cards'][0]['id']);
    T::eq(Kanban::needs($nl, 'lost', [])['needs'] ?? null, 'reason', 'needs: Perdido sin motivo pide el motivo');
    T::eq(Kanban::needs($nl, 'lost', ['reason' => 'other'])['needs'] ?? null, 'note', 'needs: motivo «Otro» sin nota pide la nota');
    T::eq(Kanban::needs($nl, 'lost', ['reason' => 'other', 'note' => 'no le interesa'])['needs'] ?? null, null, 'needs: con motivo y nota ya no pide nada');
    T::eq(Kanban::needs($nl, 'presented', [])['needs'] ?? null, 'override', 'needs: saltarse el orden (Nuevo -> Presentado) pide confirmar el override');
    T::eq(Kanban::needs($nl, 'presented', ['override' => true]), null, '  con override ya no pide nada');
    T::eq(Kanban::needs($nl, 'contacted', []), null, 'needs: Nuevo -> Contactado no pide nada');
    T::eq(Kanban::needs($nl, 'nope', []), null, 'needs: etapa inexistente la rechaza setStage, no la pre-validacion');

    // ------------------------------------------------------------------ HTTP: autorizacion, CSRF, transiciones y permisos
    $c = Panel::start($ctx, 'panel-kb');
    $anon = $c->another();
    T::eq($anon->get('/pipeline')['status'], 303, 'sin sesion: /pipeline redirige a /login');
    T::eq($anon->ajax('/ajax/pipeline/move', ['lead_id' => 1, 'to' => 'contacted'], 'x')['status'], 401, 'sin sesion: mover responde 401 JSON');
    T::eq($anon->ajax('/ajax/pipeline/cards', ['stage' => 'new'], 'x')['status'], 401, 'sin sesion: cargar tarjetas responde 401 JSON');
    T::ok($c->login($admin['email']), 'login del administrador');
    $viewerU = Panel::user('viewer');
    $v = $c->another();
    T::ok($v->login($viewerU['email']), 'login del lector (viewer)');

    $pg = $c->get('/pipeline');
    T::ok($pg['status'] === 200 && str_contains($pg['body'], 'Pipeline') && str_contains($pg['body'], 'data-board') && substr_count($pg['body'], 'class="kcard"') >= 25 + 1, 'pagina /pipeline: tablero con tarjetas');
    T::ok(preg_match('/id="col-lost"[^>]*>/', $pg['body']) === 1 && str_contains($pg['body'], 'kcol--lost is-collapsed') && substr_count($pg['body'], 'data-id="' . $lostLead . '"') === 0, 'la columna Perdido llega colapsada y sin tarjetas (se cargan al expandirla)');
    T::ok(substr_count($pg['body'], 'data-id="') === 25 + 1, 'solo se renderizan 25 tarjetas de Nuevo + 1 de Contactado (no cientos)');
    T::ok(!preg_match('/\sstyle="/i', $pg['body']) && !preg_match('/\son\w+="/i', $pg['body']) && preg_match('/<script(?![^>]*\bnonce=")/i', $pg['body']) === 0 && str_contains($pg['body'], '/assets/kanban.js') && str_contains($pg['body'], '/assets/kanban.css'), '/pipeline: sin estilos ni handlers en linea, scripts con nonce, hojas propias');
    T::ok(str_contains($pg['body'], 'data-move') && str_contains($pg['body'], 'data-write="1"'), 'administrador: tarjetas con «Mover a…»');
    $pv = $v->get('/pipeline');
    T::ok($pv['status'] === 403 && !str_contains($pv['body'], 'data-board') && !str_contains($pv['body'], 'kcard'), 'lector: el tablero responde 403 y no trae ninguna tarjeta (Q3: el lector solo ve resultados e informes)');
    T::eq($c->get('/pipeline?pl=B&t=%27%3Bdrop&src=%3Cscript%3E&q=%00x')['status'], 200, 'parametros hostiles: 200 (se normalizan)');
    T::ok(str_contains($c->get('/pipeline?pl=B')['body'], 'Precalificado') && str_contains($c->get('/pipeline?pl=B')['body'], 'Descartado'), 'pipeline B: columnas de company drivers');

    $move = static fn(array $b, ?Panel $who = null, ?string $tok = null) => ($who ?? $c)->ajax('/ajax/pipeline/move', $b, $tok);
    $target = $p1['cards'][0]['id'];
    T::eq($c->ajax('/ajax/pipeline/move', ['lead_id' => $target, 'to' => 'contacted'], 'incorrecto')['status'], 403, 'mover con CSRF incorrecto: 403');
    T::eq(Infra::http('GET', $c->base . '/ajax/pipeline/move', ['jar' => $c->jar])['status'], 405, 'mover con GET: 405');
    T::eq(Infra::http('POST', $c->base . '/ajax/pipeline/move', ['raw' => '{roto', 'jar' => $c->jar, 'headers' => ['X-CSRF-Token: ' . $c->csrf, 'Content-Type: application/json']])['status'], 400, 'mover con JSON roto: 400');
    T::eq($move(['lead_id' => $target, 'to' => 'contacted'], $v, $v->csrf)['status'], 403, 'lector: mover responde 403 (solo lectura)');
    T::eq(Db::value('SELECT stage FROM leads WHERE id = ?', [$target]), 'new', '  y el lead no cambio');
    T::eq($move(['lead_id' => 999999, 'to' => 'contacted'])['status'], 404, 'lead inexistente: 404');
    // valida: Nuevo -> Contactado fija first_contact_at, cancela A3, deja evento y activity_log
    Queue::push('telegram_alert_followup', ['lead_id' => $target], Clock::shift(900), 'telegram_followup:' . $target . ':1');
    $r = $move(['lead_id' => $target, 'to' => 'contacted']);
    $j = $c->json($r);
    T::ok($r['status'] === 200 && ($j['ok'] ?? false) && $j['from'] === 'new' && $j['to'] === 'contacted' && str_contains($j['card'], 'data-id="' . $target . '"') && str_contains($j['card'], 'data-stage="contacted"') && $j['counts']['new'] === 59 && $j['counts']['contacted'] === 2, 'mover Nuevo -> Contactado: 200 con la tarjeta nueva y los conteos al dia');
    $row = Db::one('SELECT stage, first_contact_at, stage_changed_at FROM leads WHERE id = ?', [$target]);
    T::ok($row['stage'] === 'contacted' && $row['first_contact_at'] !== null && $row['stage_changed_at'] !== null, '  fija first_contact_at y stage_changed_at');
    T::eq(Db::value("SELECT status FROM jobs WHERE dedupe_key = ?", ['telegram_followup:' . $target . ':1']), 'cancelled', '  cancela la segunda alerta A3');
    T::ok((int) Db::value("SELECT COUNT(*) FROM lead_events WHERE lead_id = ? AND type = 'stage_changed' AND user_id = ?", [$target, $uid]) === 1 && (int) Db::value("SELECT COUNT(*) FROM activity_log WHERE action = 'lead_stage' AND entity_id = ?", [(string) $target]) === 1, '  deja evento con usuario y registro en activity_log');
    // mismas reglas que la ficha
    $r = $move(['lead_id' => $target, 'to' => 'contacted']);
    T::ok($r['status'] === 422 && str_contains($c->json($r)['msg'], 'ya está'), 'mover a la misma etapa: 422 con el mensaje de LeadActions');
    $r = $move(['lead_id' => $target, 'to' => 'lost']);
    T::ok($r['status'] === 409 && $c->json($r)['needs'] === 'reason' && Db::value('SELECT stage FROM leads WHERE id = ?', [$target]) === 'contacted', 'Perdido sin motivo: 409 needs=reason y no cambia');
    $r = $move(['lead_id' => $target, 'to' => 'lost', 'reason' => 'inventado']);
    T::ok($r['status'] === 409 && Db::value('SELECT stage FROM leads WHERE id = ?', [$target]) === 'contacted', 'Perdido con motivo fuera de la lista: no pasa');
    $r = $move(['lead_id' => $target, 'to' => 'lost', 'reason' => 'other']);
    T::ok($r['status'] === 409 && $c->json($r)['needs'] === 'note', 'Perdido con «Otro» sin nota: 409 needs=note');
    $r = $move(['lead_id' => $target, 'to' => 'signed']);
    T::ok($r['status'] === 409 && $c->json($r)['needs'] === 'override' && Db::value('SELECT stage FROM leads WHERE id = ?', [$target]) === 'contacted', 'Firmado sin pasar por Presentado: 409 needs=override');
    $r = $move(['lead_id' => $target, 'to' => 'lost', 'reason' => 'price', 'note' => 'Quiere otra tarifa']);
    $j = $c->json($r);
    T::ok($r['status'] === 200 && Db::value('SELECT lost_reason FROM leads WHERE id = ?', [$target]) === 'price' && $j['counts']['lost'] === 2 && str_contains($j['card'], 'Motivo: Precio'), 'Perdido con motivo: 200, guarda lost_reason y la tarjeta muestra el motivo');
    $r = $move(['lead_id' => $target, 'to' => 'contacted']);
    T::ok($r['status'] === 422 && str_contains($c->json($r)['msg'], 'Reábrelo'), 'un lead perdido no se mueve: hay que reabrirlo (422)');
    T::ok(LeadActions::reopen($target, $uid)['ok'] && Db::value('SELECT stage_changed_at FROM leads WHERE id = ?', [$target]) !== null, 'reabrir desde la ficha sigue funcionando y actualiza stage_changed_at');
    // orden de Presentado / Firmado y salida de Firmado
    $q = $mk(['stage' => 'qualified', 'first_contact_at' => Clock::at($now - 3000)]);
    T::eq($move(['lead_id' => $q, 'to' => 'presented'])['status'], 200, 'Calificado -> Presentado: permitido (en orden)');
    T::eq($move(['lead_id' => $q, 'to' => 'signed'])['status'], 200, 'Presentado -> Firmado: permitido');
    T::ok(Db::value('SELECT handoff_status FROM leads WHERE id = ?', [$q]) === 'signed' && Db::value('SELECT signed_at FROM leads WHERE id = ?', [$q]) !== null, '  Firmado fija handoff_status y signed_at');
    $r = $move(['lead_id' => $q, 'to' => 'contacted']);
    T::ok($r['status'] === 409 && $c->json($r)['needs'] === 'override', 'sacar un lead de Firmado pide override');
    T::eq($move(['lead_id' => $q, 'to' => 'contacted', 'override' => true])['status'], 200, '  con override se permite');
    T::eq($move(['lead_id' => $q, 'to' => 'prequalified'])['status'], 422, 'una etapa de otro pipeline: 422 (no existe en este pipeline)');
    // pipeline B
    $r = $move(['lead_id' => $drv, 'to' => 'presented']);
    T::ok($r['status'] === 200 && $c->json($r)['counts']['presented'] === 1, 'pipeline B: Precalificado -> Presentado y los conteos son los del pipeline B');
    T::eq($move(['lead_id' => $drv, 'to' => 'lost', 'reason' => 'rejected'])['status'], 200, 'pipeline B: Descartado con motivo');
    // entradas hostiles
    T::eq($move(['lead_id' => 'abc', 'to' => ['x']])['status'], 404, 'lead_id no numerico: 404');
    T::eq($move(['lead_id' => $target, 'to' => str_repeat('x', 5000)], null)['status'], 413, 'cuerpo de mas de 4 KB: 413');
    T::ok($move(['lead_id' => $target, 'to' => 'contacted', 'reason' => ['x'], 'note' => ['y'], 'override' => 'yes'])['status'] !== 500, 'tipos raros en reason/note/override: no revienta');
    // cargar mas tarjetas
    $r = $c->ajax('/ajax/pipeline/cards', ['pl' => 'A', 'stage' => 'new', 'offset' => 25, 'qs' => '']);
    $j = $c->json($r);
    T::ok($r['status'] === 200 && $j['ok'] && $j['shown'] === 25 && $j['next'] === 50 && substr_count($j['html'], 'class="kcard"') === 25, 'cargar mas: 25 tarjetas desde el offset 25, con siguiente');
    $j = $c->json($c->ajax('/ajax/pipeline/cards', ['pl' => 'A', 'stage' => 'new', 'offset' => 50, 'qs' => 't=hot']));
    T::ok($j['ok'] && $j['total'] <= 20 && $j['next'] === null, 'cargar mas con filtros (t=hot): total filtrado y sin siguiente');
    $j = $c->json($c->ajax('/ajax/pipeline/cards', ['pl' => 'A', 'stage' => 'lost']));
    T::ok($j['ok'] && str_contains($j['html'], 'Motivo:'), 'la columna Perdido carga al pedirla y muestra el motivo');
    T::eq($c->ajax('/ajax/pipeline/cards', ['pl' => 'A', 'stage' => 'zzz'])['status'], 422, 'cargar una etapa inexistente: 422');
    T::eq($c->ajax('/ajax/pipeline/cards', ['pl' => 'A', 'stage' => 'new'], 'mal')['status'], 403, 'cargar tarjetas con CSRF incorrecto: 403');
    // limite de uso
    $limited = 0;
    for ($i = 0; $i < 125; $i++) {
        if ($move(['lead_id' => 999999, 'to' => 'contacted'])['status'] === 429) {
            $limited++;
        }
    }
    T::ok($limited >= 1, 'mover: limite de uso por usuario (429 pasado el tope de ' . \Captacion\Controllers\PipelineController::MOVE_LIMIT . '/min)');
    // EXPLAIN: las consultas del kanban usan indice
    $ex = Db::all("EXPLAIN SELECT l.id FROM leads l WHERE l.deleted_at IS NULL AND l.pipeline = 'A' AND l.stage = 'new' ORDER BY l.score DESC, l.id DESC LIMIT 26");
    T::ok(($ex[0]['key'] ?? '') === 'idx_leads_kanban', 'EXPLAIN de la pagina de una columna: usa idx_leads_kanban (' . ($ex[0]['key'] ?? 'ninguno') . ')');
    Infra::stop('panel-kb');
};
