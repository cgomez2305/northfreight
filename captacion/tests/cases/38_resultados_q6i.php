<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Services\Dashboard;
use Captacion\Services\ResultadosBoard;
use Captacion\Ui;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/**
 * Q6-I: /resultados (vista del lector de NorthStar) con las piezas del dashboard vivo. Narrativa con todas las variantes y bases vacías o minúsculas (sin porcentajes engañosos),
 * tarjetas, anotación y promedio del gráfico y nota del embudo; HTML completo sin JS; el lector NUNCA ve nada personal (ni en el HTML ni en el JSON del gráfico), no recibe acciones del administrador
 * y recibe 403 en el endpoint del dashboard; los JS compartidos no hacen ninguna petición; sin estilos en línea; la hoja diferida y las hojas de la pantalla.
 */
return static function (array $ctx): void {
    T::reset(true);
    Db::pdo()->exec('DELETE FROM channel_costs');

    // ------------------------------------------------------------------ Fábrica de agregados (la forma de Reports::forViewer)
    $mk = static function (array $o = []): array {
        $rec = $o['received'] ?? 46;
        $funnel = $o['funnel'] ?? [['label' => 'Nuevo', 'n' => $rec, 'of_prev' => null, 'of_total' => 100.0], ['label' => 'Contactado', 'n' => 36, 'of_prev' => 78.0, 'of_total' => 78.0],
            ['label' => 'Calificado', 'n' => 21, 'of_prev' => 58.0, 'of_total' => 46.0], ['label' => 'Firmado', 'n' => 2, 'of_prev' => 9.0, 'of_total' => 4.0]];
        $base = [
            'received' => $rec, 'qualified' => 21, 'presented' => 7, 'signed' => 2, 'signed_in_period' => 4, 'funnel' => $funnel,
            'loss' => ['total' => 9, 'rows' => [['label' => 'No contesta', 'n' => 4, 'pct' => 44.4], ['label' => 'Sin autoridad o seguro', 'n' => 1, 'pct' => 11.1]]],
            'trend' => ['unit' => 'day', 'labels' => ['1', '', '3', '', '5', '', '7', ''], 'tips' => ['lun 1 oct', 'mar 2 oct', 'mié 3 oct', 'jue 4 oct', 'vie 5 oct', 'sáb 6 oct', 'dom 7 oct', 'lun 8 oct'], 'received' => [1, 3, 2, 9, 4, 0, 2, 6], 'signed' => [0, 0, 1, 0, 0, 0, 0, 1]],
            'sign_time' => ['median_days' => null, 'n' => 0],
            'channels' => ['cost_months' => [], 'total' => ['cost' => 0.0, 'leads' => 0, 'signed' => 0, 'cpl' => null, 'cps' => null], 'rows' => [
                ['key' => 'meta', 'label' => 'Meta Ads', 'leads' => 20, 'qualified' => 14, 'presented' => 3, 'signed' => 1, 'cost' => null, 'cpl' => null, 'cps' => null],
                ['key' => 'web_careers', 'label' => 'Sitio · Careers', 'leads' => 18, 'qualified' => 7, 'presented' => 4, 'signed' => 1, 'cost' => null, 'cpl' => null, 'cps' => null]]],
        ];
        return array_replace($base, $o);
    };
    $range = ['key' => 'lastmonth', 'label' => 'Mes pasado', 'prev_label' => 'vs el mes anterior', 'to' => 1000];

    // ------------------------------------------------------------------ Subtítulo-conclusión
    $m = $mk();
    $h = ResultadosBoard::headline($m, ['received' => 32], $range);
    T::eq($h['text'], 'El mes pasado llegaron 46 leads (+43.8% frente al mes anterior). De ellos, 21 calificados, 7 presentados y 2 firmados. Meta Ads califica mejor (70% calificados). No contesta es el motivo de pérdida más frecuente (4 de 9).', 'subtítulo: caso completo con variación, embudo, canal que califica mejor y motivo de pérdida');
    T::eq(array_column(array_filter($h['segments'], static fn($s) => $s[1]), 0), ['46 leads', '+43.8%', '21 calificados', '7 presentados', '2 firmados', 'Meta Ads', '70% calificados', 'No contesta', '4 de 9'], '  la negrita va solo en cifras y sustantivos clave');
    $h = ResultadosBoard::headline($m, ['received' => 3], $range);
    T::ok(!str_contains($h['text'], '%)') && !str_contains($h['text'], 'frente al mes'), 'subtítulo: base previa de 3 leads = ninguna variación en porcentaje ni frase de comparación (la tarjeta dice el conteo)');
    $h = ResultadosBoard::headline($mk(['received' => 0, 'qualified' => 0, 'presented' => 0, 'signed' => 0]), ['received' => 7], $range);
    T::eq($h['text'], 'Aún no hay leads del mes pasado; en el periodo anterior llegaron 7 leads.', 'subtítulo: rango sin leads con el conteo del anterior');
    $h = ResultadosBoard::headline($mk(['received' => 0, 'qualified' => 0, 'presented' => 0, 'signed' => 0]), ['received' => 0], $range);
    T::eq($h['text'], 'Aún no hay leads del mes pasado. Cuando llegue el primero aparecerá aquí.', 'subtítulo: base vacía, sin cifras');
    $h = ResultadosBoard::headline($mk(['received' => 0, 'qualified' => 0, 'presented' => 0, 'signed' => 0]), ['received' => 1], array_replace($range, ['key' => '30d']));
    T::eq($h['text'], 'Aún no hay leads en los últimos 30 días; en el periodo anterior llegó 1 lead.', 'subtítulo: singular del periodo anterior');
    $one = $mk(['received' => 1, 'qualified' => 0, 'presented' => 0, 'signed' => 0, 'loss' => ['total' => 0, 'rows' => []], 'channels' => ['rows' => [['key' => 'meta', 'label' => 'Meta Ads', 'leads' => 1, 'qualified' => 0, 'presented' => 0, 'signed' => 0]], 'total' => ['cps' => null, 'signed' => 0]]]);
    $one['channels']['rows'] = [['key' => 'meta', 'label' => 'Meta Ads', 'leads' => 1, 'qualified' => 0, 'presented' => 0, 'signed' => 0, 'cost' => null, 'cpl' => null, 'cps' => null]];
    $one['loss'] = ['total' => 0, 'rows' => []];
    $h = ResultadosBoard::headline($one, ['received' => 0], $range);
    T::eq($h['text'], 'El mes pasado llegó 1 lead. Ninguno ha calificado todavía. Vino de Meta Ads.', 'subtítulo: un solo lead (singular, sin «+N nuevos» inventados ni «califica mejor»)');
    $few = $mk(['received' => 6, 'qualified' => 2, 'presented' => 0, 'signed' => 0, 'loss' => ['total' => 1, 'rows' => [['label' => 'No contesta', 'n' => 1, 'pct' => 100.0]]]]);
    $few['channels']['rows'] = [['key' => 'meta', 'label' => 'Meta Ads', 'leads' => 6, 'qualified' => 2, 'presented' => 0, 'signed' => 0, 'cost' => null, 'cpl' => null, 'cps' => null]];
    $h = ResultadosBoard::headline($few, ['received' => 2], $range);
    T::eq($h['text'], 'El mes pasado llegaron 6 leads. De ellos, 2 calificados, ninguno presentado y ninguno firmado. Todos vienen de Meta Ads. Se perdió 1 lead (No contesta).', 'subtítulo: ceros dichos con palabras, una sola fuente y una sola pérdida');
    $costly = $mk();
    $costly['channels']['total'] = ['cost' => 4800.0, 'leads' => 40, 'signed' => 4, 'cpl' => 120.0, 'cps' => 1200.0];
    $h = ResultadosBoard::headline($costly, ['received' => 32], $range);
    T::ok(str_contains($h['text'], 'El costo por firmado fue de $1,200.') && !str_contains($h['text'], 'motivo de pérdida'), 'subtítulo: con costo por firmado se dice ese y no el motivo de pérdida');
    $tie = $mk(['loss' => ['total' => 4, 'rows' => [['label' => 'A', 'n' => 2, 'pct' => 50.0], ['label' => 'B', 'n' => 2, 'pct' => 50.0]]]]);
    $tie['loss'] = ['total' => 4, 'rows' => [['label' => 'A', 'n' => 2, 'pct' => 50.0], ['label' => 'B', 'n' => 2, 'pct' => 50.0]]];
    T::ok(!str_contains(ResultadosBoard::headline($tie, ['received' => 32], $range)['text'], 'más frecuente'), 'subtítulo: con empate en el primer motivo de pérdida no se inventa un «más frecuente»');
    $even = $mk();
    $even['channels']['rows'] = [['key' => 'a', 'label' => 'A', 'leads' => 5, 'qualified' => 2, 'presented' => 0, 'signed' => 0], ['key' => 'b', 'label' => 'B', 'leads' => 5, 'qualified' => 2, 'presented' => 0, 'signed' => 0]];
    $t = ResultadosBoard::headline($even, ['received' => 46], $range)['text'];
    T::ok(!str_contains($t, 'califica mejor') && !str_contains($t, 'canal principal'), 'subtítulo: canales parejos = no se inventa una conclusión');
    $small = $mk();
    $small['channels']['rows'] = [['key' => 'a', 'label' => 'A', 'leads' => 4, 'qualified' => 4, 'presented' => 0, 'signed' => 0], ['key' => 'b', 'label' => 'B', 'leads' => 4, 'qualified' => 0, 'presented' => 0, 'signed' => 0]];
    T::ok(!str_contains(ResultadosBoard::headline($small, ['received' => 46], $range)['text'], 'califica mejor'), 'subtítulo: canales de menos de 5 leads no se comparan (100 % contra 0 % sobre 4 leads engaña)');
    $main = $mk(['received' => 10]);
    $main['channels']['rows'] = [['key' => 'a', 'label' => 'Indeed', 'leads' => 6, 'qualified' => 3, 'presented' => 0, 'signed' => 0], ['key' => 'b', 'label' => 'WhatsApp', 'leads' => 4, 'qualified' => 2, 'presented' => 0, 'signed' => 0]];
    T::ok(str_contains(ResultadosBoard::headline($main, ['received' => 9], $range)['text'], 'Indeed es el canal principal (6 de 10)'), 'subtítulo: el canal principal cuando tiene más de la mitad');
    $y = ResultadosBoard::headline($mk(), ['received' => 10], ['key' => 'custom', 'label' => 'x', 'prev_label' => 'vs los 30 días anteriores', 'to' => 1]);
    T::ok(str_starts_with($y['text'], 'En este rango llegaron 46 leads (+360% frente a los 30 días anteriores)'), 'subtítulo: rango desconocido y comparación con «los N días anteriores»');

    // ------------------------------------------------------------------ Tarjetas
    $k = ResultadosBoard::kpis($mk(['signed' => 2]), ['received' => 32, 'qualified' => 17, 'presented' => 8, 'signed' => 4, 'channels' => ['total' => ['cps' => null, 'signed' => 0]]], $range);
    T::eq(array_column($k, 'k'), ['received', 'qualified', 'presented', 'signed', 'cps'], 'tarjetas: las cinco de siempre, en el mismo orden');
    T::eq([$k[0]['delta']['text'], $k[1]['delta']['text'], $k[2]['delta']['text']], ['+43.8%', '+23.5%', '−12.5%'], '  variaciones con base previa de 5 o más: porcentaje');
    T::eq($k[3]['delta']['text'], '−2 firmados', '  firmados con base previa de 4: el conteo, nunca «−50 %»');
    T::eq([$k[0]['viz']['type'], $k[1]['viz']['type'], $k[2]['viz']['type'], $k[3]['viz']['type'], $k[4]['viz']], ['spark', 'ticks', 'ticks', 'spark', null], '  microvisualizaciones: microlínea, medidor, medidor, microlínea, ninguna');
    T::eq([$k[1]['viz']['on'], $k[1]['viz']['total'], $k[2]['viz']['on']], [13, 28, 4], '  el medidor de rayas es proporcional (21/46 de 28 = 13; 7/46 = 4)');
    T::eq([$k[1]['sub'], $k[2]['sub']], ['46% de los recibidos', '15% de los recibidos'], '  contexto en porcentaje con 5 o más recibidos');
    $k = ResultadosBoard::kpis($mk(['received' => 4, 'qualified' => 1, 'presented' => 0, 'signed' => 0]), ['received' => 0, 'channels' => ['total' => ['cps' => null, 'signed' => 0]]], $range);
    T::eq([$k[1]['sub'], $k[0]['delta']['text']], ['1 de 4 recibidos', '+4 nuevos'], 'tarjetas: con 4 recibidos, «1 de 4 recibidos» y no «25 %»; sin base previa, «+4 nuevos»');
    $k = ResultadosBoard::kpis($mk(['received' => 0, 'qualified' => 0, 'presented' => 0, 'signed' => 0, 'trend' => []]), ['received' => 0], $range);
    T::ok($k[1]['viz'] === null && $k[0]['delta']['dir'] === 'flat' && $k[1]['sub'] === 'Sin leads en el rango', 'tarjetas: con la base vacía no hay medidor y el contexto es sincero');
    $mc = $mk();
    $mc['channels']['total'] = ['cost' => 4800.0, 'leads' => 40, 'signed' => 4, 'cpl' => 120.0, 'cps' => 1200.0];
    $k = ResultadosBoard::kpis($mc, ['received' => 9, 'channels' => ['total' => ['cps' => 1500.0, 'signed' => 3]]], $range);
    T::eq([$k[4]['text'], $k[4]['count']['pre'], $k[4]['delta']['text']], ['$1,200', '$', 'Pocos firmados para comparar'], 'tarjetas: costo por firmado con conteo en dólares y sin variación si hay menos de 5 firmados');
    $mc['channels']['total']['signed'] = 6;
    $k = ResultadosBoard::kpis($mc, ['received' => 9, 'channels' => ['total' => ['cps' => 1500.0, 'signed' => 5]]], $range);
    T::eq([$k[4]['delta']['text'], $k[4]['delta']['good']], ['−20.0%', true], '  con 5 o más en ambos periodos sí hay variación (bajar el costo es bueno)');
    T::ok(str_contains(Ui::big('$1,200', 'kpi__v', ['v' => 1200, 'dec' => 0, 'suf' => '', 'pre' => '$']), 'data-pre="$"') && !str_contains(Ui::big('46', 'kpi__v', ['v' => 46, 'dec' => 0, 'suf' => '']), 'data-pre'), 'Ui::big: el prefijo del conteo solo va cuando existe');

    // ------------------------------------------------------------------ Anotación, promedio y nota del embudo
    $now = 5000;
    $c = ResultadosBoard::chartNote($mk(), 1000, $now);   // rango ya cerrado
    T::eq([$c['note']['i'], $c['note']['l1'], $c['note']['l2']], [3, 'Pico: 9 leads recibidos', 'jue 4 oct'], 'gráfico: la anotación es el pico real de los recibidos, sobre su día');
    T::eq(round((float) $c['avg'], 3), round(27 / 8, 3), '  rango cerrado: el promedio incluye todos los días');
    $c = ResultadosBoard::chartNote($mk(), 9000, $now);   // rango en curso
    T::eq(round((float) $c['avg'], 3), round(21 / 7, 3), '  rango en curso: el último día no entra en el promedio');
    T::eq([$c['prev'], $c['avgu']], ['frente al día anterior', ' al día'], '  frases del tooltip y del promedio por día');
    $m2 = $mk();
    $m2['trend'] = ['unit' => 'week', 'labels' => ['a', 'b', 'c', 'd'], 'tips' => ['Semana del 1 oct', 'Semana del 8 oct', 'Semana del 15 oct', 'Semana del 22 oct'], 'received' => [2, 3, 4, 9], 'signed' => [0, 0, 0, 0]];
    $c = ResultadosBoard::chartNote($m2, 9000, $now);
    T::eq([$c['note']['l1'], $c['note']['l2'], $c['prev']], ['+5 leads recibidos', 'frente a la semana anterior', 'frente a la semana anterior'], 'gráfico: si el periodo en curso ya es el máximo, «+N frente a la anterior»');
    $m2['trend']['received'] = [1, 0, 0, 1];
    $c = ResultadosBoard::chartNote($m2, 9000, $now);
    T::ok($c['note'] === null && $c['avg'] === null, 'gráfico: con 2 leads no hay anotación ni promedio');
    $m2['trend']['received'] = [];
    T::ok(ResultadosBoard::chartNote($m2, 9000, $now)['note'] === null, 'gráfico: serie vacía = sin anotación y sin error');
    $f = ResultadosBoard::funnelNote($mk());
    T::eq($f['text'], 'Mayor caída: de Calificado a Firmado (−19 leads).', 'embudo: la mayor caída entre etapas consecutivas, en conteos');
    T::ok(ResultadosBoard::funnelNote($mk(['received' => 0, 'funnel' => [['label' => 'Nuevo', 'n' => 0, 'of_prev' => null, 'of_total' => null]]])) === null, 'embudo: sin leads no hay nota');

    // ------------------------------------------------------------------ Integración: el lector ve la pantalla completa y NADA personal
    $now = Clock::time();
    $lead = static function (array $o) use ($now): int {
        static $n = 0;
        $n++;
        return Db::insert('leads', $o + ['profile' => 'owner_operator', 'first_name' => 'Zebulon' . $n, 'last_name' => 'Quixote' . $n, 'phone_e164' => '+1305555' . str_pad((string) (4000 + $n), 4, '0', STR_PAD_LEFT),
            'email' => "q6i-$n@example.com", 'usdot' => '98765' . $n, 'state' => 'FL', 'language' => 'en', 'score' => 70, 'temperature' => 'hot', 'pipeline' => 'A', 'stage' => 'new', 'source' => 'meta', 'attempt' => 1,
            'equipment_types' => ['dry-van'], 'flags' => [], 'created_at' => Clock::at($now - 86400 * 3), 'updated_at' => Clock::at($now - 86400 * 3)]);
    };
    $s = Panel::start($ctx, 'panel38');
    $admin = Panel::user('admin');
    $viewerU = Panel::user('viewer');
    $v = $s->another();
    T::ok($v->login($viewerU['email']), 'login del lector');
    $r = $v->get('/resultados?r=30d');
    $b = $r['body'];
    T::ok($r['status'] === 200 && str_contains($b, 'Aún no hay leads en los últimos 30 días. Cuando llegue el primero aparecerá aquí.') && str_contains($b, 'Aún no hay leads en este rango') && str_contains($b, 'Sin leads ni firmas en el rango') && str_contains($b, 'Sin leads, sin pérdidas que mostrar'),
        'base VACÍA: 200, subtítulo sincero y estados vacíos cuidados en el embudo, la tendencia, los canales y las pérdidas');
    T::ok(str_contains($b, 'data-k="received"') && str_contains($b, 'Sin leads en el rango') && !str_contains($b, 'NaN') && !str_contains($b, 'Infinity') && str_contains($b, '</html>'), '  con la base vacía las cinco tarjetas se dibujan (ceros, sin porcentajes ni NaN)');
    for ($i = 0; $i < 7; $i++) {
        $lead(['source' => $i < 5 ? 'meta' : 'indeed', 'stage' => $i === 0 ? 'signed' : 'new', 'signed_at' => $i === 0 ? Clock::at($now - 86400) : null, 'temperature' => $i % 2 ? 'cold' : 'hot']);
    }
    $r = $v->get('/resultados?r=30d');
    $b = $r['body'];
    T::ok($r['status'] === 200 && str_contains($b, 'llegaron <b>7 leads</b>') && str_contains($b, 'data-rs') && str_contains($b, 'data-live-v="') && str_contains($b, 'class="kpi"') && str_contains($b, 'role="img" aria-label="Calificados:') && str_contains($b, 'data-lc2'),
        'con datos: subtítulo, tarjetas con medidor de rayas con texto accesible y gráfico anotado');
    T::ok(preg_match('/<p class="kpi__v" data-count="7"[^>]*><span class="sr">7<\/span><span class="kpi__n" aria-hidden="true">7<\/span><\/p>/', $b) === 1, '  número protagonista: el texto accesible final va en .sr (el conteo no lo cambia)');
    T::ok(str_contains($b, 'RECIBIDOS') && str_contains($b, 'EMBUDO') && str_contains($b, '<table class="sr">') && str_contains($b, 'COSTO POR FIRMADO POR CANAL') && str_contains($b, 'MOTIVOS DE PÉRDIDA'), '  HTML completo sin JS: tarjetas, embudo, tabla accesible del gráfico, canales y pérdidas');
    $pii = ['Zebulon', 'Quixote', '98765', '+1305555', 'q6i-'];
    $hits = array_filter($pii, static fn($p) => str_contains($b, $p));
    T::ok(!$hits, 'el lector: NINGUNA cadena personal en el HTML (nombres, teléfonos, correos, USDOT)' . ($hits ? ' · aparece: ' . implode(', ', $hits) : ''));
    T::ok(preg_match('#/leads/\d+#', $b) !== 1 && !str_contains($b, 'data-peek') && !str_contains($b, 'id="peek"') && !str_contains($b, 'wa.me') && !str_contains($b, 'mailto:') && !str_contains($b, 'tel:'), '  sin enlaces a leads, vistazo, WhatsApp, correo ni teléfono');
    T::ok(!str_contains($b, 'SIGUIENTE MEJOR ACCIÓN') && !str_contains($b, 'data-dash') && !str_contains($b, '/ajax/dashboard') && !str_contains($b, 'data-live="actions"') && !str_contains($b, 'CALIENTES SIN CONTACTAR') && !str_contains($b, 'PRIMERA RESPUESTA'), '  sin «siguiente mejor acción», sin KPI de administrador y sin el marcado del refresco');
    preg_match_all('/data-chart="([^"]*)"/', $b, $cm);
    $okJson = count($cm[1]) === 1;
    foreach ($cm[1] as $raw) {
        $cfg = json_decode(html_entity_decode($raw), true);
        $okJson = $okJson && is_array($cfg) && count(array_diff(array_keys($cfg), ['labels', 'max', 'unit', 'dk', 'pv', 'series'])) === 0;
        foreach ($cfg['series'] ?? [] as $se) {
            $okJson = $okJson && count(array_diff(array_keys($se), ['n', 'c', 'v'])) === 0 && count(array_filter($se['v'], static fn($x) => !is_int($x))) === 0;
        }
        $okJson = $okJson && !preg_match('/Zebulon|Quixote|@|\+1/', (string) json_encode($cfg));
    }
    T::ok($okJson, '  el JSON del gráfico solo trae etiquetas de periodo y conteos enteros (ningún dato personal)');
    T::ok(!preg_match('/\sstyle="/i', $b) && !preg_match('/\son[a-z]+="/i', $b) && preg_match('/<script(?![^>]*\bnonce=")/i', $b) === 0, '  sin estilos ni handlers en línea; todos los scripts con nonce');
    preg_match_all('#<script nonce="[^"]+" src="/assets/([a-z0-9-]+)\.js\?v=#', $b, $sm);
    T::eq(array_values(array_diff($sm[1], ['panel'])), ['dashfx', 'resultados'], '  carga dashfx.js y resultados.js (en ese orden) y NO ux.js ni dashboard.js');
    preg_match_all('#<link rel="stylesheet" href="/assets/([a-z0-9-]+)\.css\?v=#', $b, $lm);
    T::eq($lm[1], ['fonts', 'panel', 'charts', 'viz', 'reportes'], '  hojas: fonts + panel + charts + viz + reportes (el movimiento va en la hoja diferida dashlive.css)');
    $size = static fn(string $f): int => (int) @filesize($ctx['root'] . '/public/assets/' . $f . '.css');
    $tot = array_sum(array_map($size, $lm[1]));
    T::ok($tot > 0 && $tot <= 45000, "  CSS de la primera pantalla: $tot bytes (≤ 45.000)");
    // porcentajes con base mínima en el embudo y las pérdidas (7 leads: ≥ 5; luego con 4)
    T::ok(str_contains($b, 'de la etapa anterior'), '  el embudo dice de dónde viene cada etapa');
    $r4 = $v->get('/resultados?r=lastmonth');
    T::ok($r4['status'] === 200 && !preg_match('/Zebulon|Quixote|98765|q6i-/', $r4['body']), 'otro rango (mes pasado): 200 y nada personal');

    // ------------------------------------------------------------------ El lector no puede usar los endpoints del administrador
    $r = $v->ajax('/ajax/dashboard', ['p' => 'week']);
    T::eq([$r['status'], $v->json($r)['error'] ?? ''], [403, 'forbidden'], 'el lector recibe 403 en /ajax/dashboard (sin ningún dato)');
    foreach (['/ajax/peek' => ['id' => 1], '/ajax/search' => ['q' => 'Zebulon'], '/ajax/timeline' => [], '/ajax/pipeline/cards' => []] as $path => $body) {
        $r = $v->ajax($path, $body);
        T::ok(in_array($r['status'], [403, 404, 405], true) && !preg_match('/Zebulon|Quixote|98765/', $r['body']), "el lector: POST $path -> {$r['status']} y sin datos personales");
    }
    $r = Infra::http('GET', $v->base . '/', ['jar' => $v->jar]);
    T::ok($r['status'] === 303 && Infra::header($r, 'location') === '/resultados', 'el lector que pide el dashboard del administrador (/) vuelve a /resultados');
    // el administrador ve la misma pantalla del cliente, también sin datos personales
    $a = $s->another();
    T::ok($a->login($admin['email']), 'login del administrador');
    $r = $a->get('/resultados?r=30d');
    T::ok($r['status'] === 200 && str_contains($r['body'], 'llegaron <b>7 leads</b>') && !preg_match('/Zebulon|Quixote|98765|q6i-/', $r['body']), 'el administrador ve /resultados idéntica a la del cliente (sin nombres)');

    // ------------------------------------------------------------------ Los JS compartidos no hacen red ni tocan estilos en línea
    $fx = (string) file_get_contents($ctx['root'] . '/public/assets/dashfx.js');
    $rs = (string) file_get_contents($ctx['root'] . '/public/assets/resultados.js');
    $db = (string) file_get_contents($ctx['root'] . '/public/assets/dashboard.js');
    foreach (['dashfx.js' => $fx, 'resultados.js' => $rs] as $name => $js) {
        T::ok(!preg_match('/fetch\s*\(|XMLHttpRequest|sendBeacon|WebSocket|NS\.api|\/ajax\/|\/internal\/|localStorage|document\.cookie/', $js), "$name: sin ninguna petición de red ni almacenamiento (el lector no tiene refresco ni endpoint)");
        T::ok(!str_contains($js, "setAttribute('style'") && !str_contains($js, 'cssText') && !str_contains($js, 'eval(') && !str_contains($js, 'document.write'), "$name: ni setAttribute(style), ni cssText, ni eval");
    }
    T::ok(str_contains($fx, 'prefers-reduced-motion') && str_contains($fx, 'NS.fx = {') && !str_contains($rs, 'innerHTML'), 'dashfx.js respeta prefers-reduced-motion y expone NS.fx; resultados.js no escribe HTML');
    T::ok(str_contains($db, 'NS.fx') && !str_contains($db, 'function countTo') && !str_contains($db, 'function screenLen'), 'dashboard.js reutiliza dashfx.js (el código visual no está duplicado)');
    T::ok(str_contains($db, "'dashfx'") === false && str_contains((string) file_get_contents($ctx['root'] . '/src/Controllers/DashboardController.php'), "'dashfx', 'dashboard'"), 'el dashboard del administrador carga dashfx.js antes que dashboard.js');
    $ad = $a->get('/');
    preg_match_all('#<script nonce="[^"]+" src="/assets/([a-z0-9-]+)\.js\?v=#', $ad['body'], $am);
    T::ok($ad['status'] === 200 && array_values(array_intersect($am[1], ['ux', 'peek', 'dashfx', 'dashboard'])) === ['ux', 'peek', 'dashfx', 'dashboard'], 'el dashboard del administrador sigue cargando ux, peek, dashfx y dashboard en orden');
    $css = (string) file_get_contents($ctx['root'] . '/public/assets/dashlive.css');
    T::ok(str_contains($css, 'prefers-reduced-motion'), 'dashlive.css (diferida, compartida) respeta prefers-reduced-motion');
    Infra::stop('panel38');
    T::reset(true);
};
