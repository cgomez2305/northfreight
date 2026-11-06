<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Services\Dashboard;
use Captacion\Services\DashboardActions;
use Captacion\Services\DashboardNarrative;
use Captacion\Services\Fmt;
use CaptacionTests\Infra;
use CaptacionTests\Panel;
use CaptacionTests\T;

/**
 * Q6: dashboard vivo. Variación con base mínima (nunca un porcentaje sobre 1 a 4 leads), anotación y promedio del gráfico, subtítulo-conclusión (todas las variantes, también base vacía y minúscula),
 * reglas de «Siguiente mejor acción» (cada regla, orden, empates, vacío), endpoint de refresco (método, sesión, CSRF, rol, límite, forma y ausencia de datos personales),
 * HTML completo sin JS, nada de estilos en línea ni en el JS, y el medidor de rayas accesible.
 */
return static function (array $ctx): void {
    T::reset(true);

    // ------------------------------------------------------------------ Variación con base mínima
    T::eq(Dashboard::deltaCount(9, 6)['text'], '+50.0%', 'deltaCount: con 6 leads antes (5 o más) sí hay porcentaje');
    T::eq(Dashboard::deltaCount(10, 4)['text'], '+6 leads', 'deltaCount: con 4 antes, la diferencia en leads (nunca «+150 %»)');
    T::eq(Dashboard::deltaCount(5, 1)['text'], '+4 leads', 'deltaCount: de 1 a 5 NO es «+400 %»: «+4 leads»');
    T::eq(Dashboard::deltaCount(2, 3)['text'], '−1 lead', 'deltaCount: baja de 1 con la unidad en singular');
    T::eq(Dashboard::deltaCount(3, 3)['dir'], 'flat', 'deltaCount: igual a la base minúscula = sin cambio');
    T::eq(Dashboard::deltaCount(7, 0)['text'], '+7 nuevos', 'deltaCount: sin base previa, «+7 nuevos»');
    T::eq(Dashboard::deltaCount(0, 0)['dir'], 'flat', 'deltaCount: 0 contra 0 = plana');
    T::eq(Dashboard::deltaCount(1, 4)['good'], false, 'deltaCount: bajar es malo para los leads');

    // ------------------------------------------------------------------ Anotación y promedio del gráfico
    $tips = array_map(static fn($i) => 'Semana ' . $i, range(1, 8));
    $a = Dashboard::annotate([1, 0, 0, 0, 0, 0, 0, 1], $tips);
    T::ok($a['note'] === null && $a['avg'] === null, 'annotate: con 2 leads repartidos en 2 semanas no hay anotación ni promedio');
    $a = Dashboard::annotate([2, 3, 9, 4, 2, 3, 2, 1], $tips);
    T::eq([$a['note']['i'], $a['note']['l1']], [2, 'Pico: 9 leads en total'], 'annotate: el pico se anota sobre su semana');
    T::eq(round((float) $a['avg'], 3), round((2 + 3 + 9 + 4 + 2 + 3 + 2) / 7, 3), 'annotate: el promedio excluye el periodo en curso (el último)');
    $a = Dashboard::annotate([2, 3, 4, 4, 2, 3, 2, 6], $tips);
    T::eq([$a['note']['i'], $a['note']['l1'], $a['note']['l2']], [7, '+4 leads en total', 'frente al periodo anterior'], 'annotate: si el periodo en curso ya es el máximo, «+N frente al anterior»');
    $a = Dashboard::annotate([0, 0, 0, 5, 0, 0, 6, 0], $tips);
    T::ok($a['avg'] === null, 'annotate: el promedio exige 4 periodos completos con datos');
    $a = Dashboard::annotate([3, 3, 3, 3, 3, 3, 3, 3], $tips);
    T::eq($a['note']['i'], 7, 'annotate: con empate en el máximo gana el más reciente');

    // ------------------------------------------------------------------ Subtítulo-conclusión
    $base = static fn(array $o = []): array => array_replace([
        'period' => 'week', 'any' => true, 'leads' => ['n' => 38, 'prev' => 30], 'hot' => ['n' => 3, 'oldest_secs' => 3600 * 5 + 120],
        'mix' => ['hot' => 9, 'warm' => 12, 'sources' => [['key' => 'meta', 'label' => 'Meta Ads', 'n' => 20, 'q' => 14], ['key' => 'web_careers', 'label' => 'Sitio · Careers', 'n' => 18, 'q' => 7]]],
    ], $o);
    $h = DashboardNarrative::headline($base());
    T::eq($h['text'], 'En los últimos 7 días llegaron 38 leads (+26.7% frente a los 7 días previos), 9 calientes. Meta Ads califica mejor (70% calificados). 3 calientes esperan contacto (el más antiguo, desde hace 5 h 2 min).', 'subtítulo: caso completo con variación, fuente que califica mejor y calientes esperando');
    T::eq(array_column(array_filter($h['segments'], static fn($s) => $s[1]), 0), ['38 leads', '+26.7%', '9 calientes', 'Meta Ads', '70% calificados', '3 calientes'], '  la negrita va solo en cifras y sustantivos clave');
    $h = DashboardNarrative::headline($base(['leads' => ['n' => 6, 'prev' => 2], 'mix' => ['hot' => 1, 'warm' => 2, 'sources' => [['key' => 'meta', 'label' => 'Meta Ads', 'n' => 6, 'q' => 3]]], 'hot' => ['n' => 0, 'oldest_secs' => null]]));
    T::ok(!str_contains($h['text'], '%') && str_contains($h['text'], 'Todos vienen de Meta Ads') && str_contains($h['text'], 'Ningún caliente espera contacto.'), 'subtítulo: base previa de 2 = sin porcentaje; una sola fuente; nadie esperando');
    $h = DashboardNarrative::headline($base(['period' => 'today', 'leads' => ['n' => 1, 'prev' => 0], 'mix' => ['hot' => 0, 'warm' => 1, 'sources' => [['key' => 'meta', 'label' => 'Meta Ads', 'n' => 1, 'q' => 1]]], 'hot' => ['n' => 1, 'oldest_secs' => 30]]));
    T::eq($h['text'], 'Hoy llegó 1 lead, 1 tibio y ninguno caliente. Vino de Meta Ads. 1 caliente espera contacto.', 'subtítulo: singulares y un solo lead (sin «+N nuevos» inventados)');
    $h = DashboardNarrative::headline($base(['leads' => ['n' => 4, 'prev' => 9], 'mix' => ['hot' => 0, 'warm' => 0, 'sources' => [['key' => 'meta', 'label' => 'Meta Ads', 'n' => 3, 'q' => 0], ['key' => 'indeed', 'label' => 'Indeed', 'n' => 1, 'q' => 0]]], 'hot' => ['n' => 0, 'oldest_secs' => null]]));
    T::ok(str_contains($h['text'], 'ninguno caliente ni tibio') && str_contains($h['text'], '−55.6%') && str_contains($h['text'], 'Meta Ads es la fuente principal (3 de 4)'), 'subtítulo: sin calientes ni tibios, con baja real sobre una base de 9 y la fuente principal');
    $h = DashboardNarrative::headline($base(['leads' => ['n' => 10, 'prev' => 10], 'mix' => ['hot' => 2, 'warm' => 2, 'sources' => [['key' => 'a', 'label' => 'A', 'n' => 5, 'q' => 2], ['key' => 'b', 'label' => 'B', 'n' => 5, 'q' => 2]]], 'hot' => ['n' => 0, 'oldest_secs' => null]]));
    T::ok(!str_contains($h['text'], 'califica mejor') && !str_contains($h['text'], 'fuente principal') && !str_contains($h['text'], 'frente a'), 'subtítulo: fuentes parejas y sin cambio = no se inventa una conclusión');
    $h = DashboardNarrative::headline($base(['leads' => ['n' => 0, 'prev' => 7], 'mix' => ['hot' => 0, 'warm' => 0, 'sources' => []], 'hot' => ['n' => 0, 'oldest_secs' => null]]));
    T::eq($h['text'], 'Aún no hay leads en los últimos 7 días; en los 7 días previos llegaron 7 leads. Ningún caliente espera contacto.', 'subtítulo: periodo sin leads con el conteo del anterior');
    $h = DashboardNarrative::headline($base(['any' => false, 'leads' => ['n' => 0, 'prev' => 0], 'mix' => ['hot' => 0, 'warm' => 0, 'sources' => []], 'hot' => ['n' => 0, 'oldest_secs' => null]]));
    T::eq($h['text'], 'Aún no hay leads. Cuando llegue el primero aparecerá aquí.', 'subtítulo: base vacía, sin cifras');
    $h = DashboardNarrative::headline($base(['period' => 'month', 'leads' => ['n' => 0, 'prev' => 0], 'mix' => ['hot' => 0, 'warm' => 0, 'sources' => []], 'hot' => ['n' => 2, 'oldest_secs' => 7200]]));
    T::eq($h['text'], 'Aún no hay leads en los últimos 30 días. 2 calientes esperan contacto (el más antiguo, desde hace 2 h).', 'subtítulo: sin leads en el periodo pero con calientes esperando (el estado actual manda)');

    // ------------------------------------------------------------------ Siguiente mejor acción
    T::eq(DashboardActions::rank([]), [], 'acciones: sin nada pendiente, lista vacía («Todo al día»)');
    $r = DashboardActions::rank(['hot' => 3, 'hot_secs' => 3600, 'overdue' => 5, 'overdue_secs' => 3 * 86400, 'failed' => 1, 'presented' => 2, 'followups' => 4, 'no_task' => 9, 'referrals' => 1, 'review' => 2]);
    T::eq(array_column($r, 'key'), ['hot', 'failed', 'overdue'], 'acciones: máximo 3, por impacto (calientes 88, fallidos 60, vencidas 60)');
    T::eq(array_column($r, 'impact'), [88, 60, 60], '  fórmulas: 60+6·3+10 · 55+5·1 · 30+4·5+10');
    T::eq($r[0]['href'], '/leads?t=hot&ct=none', '  el botón de los calientes abre la vista ya filtrada');
    T::eq(DashboardActions::rank(['overdue' => 2])[0]['href'], '/tareas?tab=overdue', '  las vencidas abren /tareas?tab=overdue');
    T::eq(DashboardActions::rank(['followups' => 1])[0]['href'], '/prospectos', '  los seguimientos abren /prospectos');
    $r = DashboardActions::rank(['hot' => 1, 'hot_secs' => 100]);
    T::eq([$r[0]['impact'], $r[0]['level']], [66, 'mid'], 'acciones: un caliente reciente = 66 (sin el +10 de espera larga), nivel medio');
    T::eq(DashboardActions::rank(['hot' => 50, 'hot_secs' => 99999])[0]['impact'], 100, 'acciones: el impacto tiene tope 100');
    $r = DashboardActions::rank(['presented' => 3, 'followups' => 5]);
    T::eq(array_column($r, 'key'), ['presented', 'followups'], 'acciones: empate de impacto (40 y 40): manda el orden fijo de las reglas');
    T::eq(array_column(DashboardActions::rank(['no_task' => 2, 'review' => 2, 'referrals' => 2]), 'key'), ['no_task', 'referrals', 'review'], 'acciones: orden de reglas menores (24, 24, 19: empate no_task/referrals por orden)');
    T::ok(count(DashboardActions::rank(['hot' => -5, 'overdue' => 0])) === 0, 'acciones: cuentas negativas o cero no generan nada');

    // ------------------------------------------------------------------ Datos reales: periodo y pulse
    $now = Clock::time();
    $mk = static function (array $o) use ($now): int {
        static $n = 0;
        $n++;
        return Db::insert('leads', $o + ['profile' => 'owner_operator', 'first_name' => 'Secreto' . $n, 'last_name' => 'Apellido' . $n, 'phone_e164' => '+1305555' . str_pad((string) (3000 + $n), 4, '0', STR_PAD_LEFT),
            'email' => "q6-$n@example.com", 'state' => 'FL', 'language' => 'en', 'score' => 50, 'temperature' => 'warm', 'pipeline' => 'A', 'stage' => 'new', 'source' => 'meta', 'attempt' => 1,
            'equipment_types' => ['dry-van'], 'flags' => [], 'created_at' => Clock::at($now - 3600), 'updated_at' => Clock::at($now - 3600)]);
    };
    $p0 = Dashboard::pulse('week', $now);
    T::eq([$p0['leads']['n'], $p0['hot']['n'], $p0['narrative']['text']], [0, 0, 'Aún no hay leads. Cuando llegue el primero aparecerá aquí.'], 'pulse con la base vacía: ceros y subtítulo sincero');
    $mk(['temperature' => 'hot', 'score' => 90, 'created_at' => Clock::at($now - 3500)]);
    $mk(['temperature' => 'warm']);
    $mk(['temperature' => 'cold', 'source' => 'indeed']);
    $p = Dashboard::pulse('week', $now);
    T::eq([$p['leads']['n'], $p['qualified']['n'], $p['hot']['n'], $p['hot']['sample']], [3, 2, 1, []], 'pulse: KPI reales y sin la muestra de leads calientes (nada personal)');
    T::ok(str_contains($p['narrative']['text'], '3 leads') && str_contains($p['narrative']['text'], '1 caliente'), '  subtítulo con las cifras reales');
    T::eq(array_column($p['actions'], 'key'), ['hot', 'no_task'], '  acciones reales: el caliente sin contactar y los leads abiertos sin tarea');
    T::eq($p['hot']['ticks'], 4, '  el dial de urgencia: 1 h de espera = 4 rayas de 15 min');
    $full = Dashboard::build('week', $now);
    T::eq([$full['leads']['n'], $full['actions'], $full['narrative']['text']], [$p['leads']['n'], $p['actions'], $p['narrative']['text']], 'build y pulse dan el mismo resumen y las mismas acciones');
    T::ok(Dashboard::build('today', $now)['sources']['list'][0]['delta'] === null, 'por fuente: con menos de 5 leads entre los dos periodos no hay variación');

    // ------------------------------------------------------------------ HTML sin JS y endpoint
    $s = Panel::start($ctx, 'panel37');
    $admin = Panel::user('admin');
    $viewerU = Panel::user('viewer');
    T::ok($s->login($admin['email']), 'login del administrador');
    $r = $s->get('/?p=week');
    $b = $r['body'];
    T::ok($r['status'] === 200 && str_contains($b, 'data-live="headline"') && str_contains($b, 'En los últimos 7 días llegaron') && str_contains($b, 'SIGUIENTE MEJOR ACCIÓN') && str_contains($b, 'Contacta a los calientes'), 'HTML del servidor: subtítulo, KPI y acciones ya vienen escritos (sin JS no falta nada)');
    T::ok(preg_match('/<p class="kpi__v" data-count="3"[^>]*><span class="sr">3<\/span><span class="kpi__n" aria-hidden="true">3<\/span><\/p>/', $b) === 1, '  el número protagonista: texto accesible final en .sr y lo pintado en aria-hidden (el conteo no cambia lo que lee el lector)');
    T::ok(str_contains($b, 'role="img" aria-label="Impacto '), '  el medidor de rayas lleva su texto accesible');
    T::ok(!preg_match('/\sstyle="/i', $b) && !preg_match('/\son[a-z]+="/i', $b), '  sin estilos ni handlers en línea');
    T::ok(str_contains($b, 'src="/assets/dashboard.js') && str_contains($b, 'data-live-v="'), '  carga dashboard.js (con nonce) y declara la versión de su hoja diferida');
    T::ok(str_contains($b, 'data-peek="') && str_contains($b, 'id="peek"'), '  las filas abren el vistazo rápido');
    $js = (string) file_get_contents($ctx['root'] . '/public/assets/dashboard.js');
    T::ok(!str_contains($js, "setAttribute('style'") && !str_contains($js, 'cssText') && !str_contains($js, 'eval(') && !preg_match('/\.innerHTML\s*=\s*[^;]*(location|document\.cookie)/', $js), 'dashboard.js: ni setAttribute(style), ni cssText, ni eval');
    T::ok(str_contains((string) file_get_contents($ctx['root'] . '/public/assets/dashlive.css'), 'prefers-reduced-motion') && str_contains($js, 'prefers-reduced-motion'), 'dashlive.css y dashboard.js respetan prefers-reduced-motion');

    $j = $s->json($s->ajax('/ajax/dashboard', ['p' => 'month']));
    T::ok(($j['ok'] ?? false) === true && $j['p'] === 'month' && isset($j['kpis'], $j['headline'], $j['actions'], $j['at'], $j['ts']), 'POST /ajax/dashboard: forma de la respuesta (kpis, headline, actions, at)');
    T::ok(str_contains($j['kpis'], 'data-k="leads"') && str_contains($j['kpis'], 'data-k="hot"') && str_contains($j['headline'], 'En los últimos 30 días'), '  los fragmentos son los mismos del servidor y siguen el periodo pedido');
    $raw = json_encode($j);
    T::ok(!str_contains((string) $raw, 'Secreto') && !str_contains((string) $raw, 'Apellido') && !str_contains((string) $raw, '+1305') && !str_contains((string) $raw, 'q6-'), '  SIN datos personales: ni nombres, ni teléfonos, ni correos de leads');
    T::eq($s->json($s->ajax('/ajax/dashboard', ['p' => 'zzz']))['p'], 'today', '  un periodo desconocido cae a «hoy»');
    $r = Infra::http('GET', $s->base . '/ajax/dashboard', ['jar' => $s->jar]);
    T::eq($r['status'], 405, '  GET: 405 (solo POST)');
    $r = $s->ajax('/ajax/dashboard', ['p' => 'week'], 'token-falso');
    T::eq($r['status'], 403, '  sin CSRF válido: 403');
    $anon = $s->another();
    T::eq($anon->ajax('/ajax/dashboard', ['p' => 'week'], 'x')['status'], 401, '  sin sesión: 401');
    $v = $s->another();
    T::ok($v->login($viewerU['email']), 'login del lector');
    $r = $v->ajax('/ajax/dashboard', ['p' => 'week']);
    T::eq([$r['status'], $v->json($r)['error'] ?? ''], [403, 'forbidden'], 'el lector recibe 403 en el endpoint del administrador (sin ningún dato)');
    $codes = [];
    for ($i = 0; $i < 30; $i++) {
        $codes[] = $s->ajax('/ajax/dashboard', ['p' => 'today'])['status'];
    }
    T::ok(in_array(429, $codes, true) && $codes[0] === 200, 'límite de uso: tras ~24 peticiones por minuto responde 429');

    $e = $s->get('/');
    T::ok(str_contains($e['body'], '/assets/dashboard.js') , 'el dashboard sigue cargando para el administrador tras el límite (otra ruta)');
    Infra::stop('panel37');
    T::reset(true);
};
