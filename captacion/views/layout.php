<?php
use Captacion\Ui;
use Captacion\Middleware\SecurityHeaders;

$nonce = SecurityHeaders::nonce();
$assetV = static fn(string $f): string => (string) (@filemtime(CAPTACION_ROOT . '/public/assets/' . $f) ?: 1);
$shell = !empty($shell);
$brand = '<a class="brand" href="' . (!empty($isViewer) ? '/resultados' : '/') . '" aria-label="NorthStar Captación, ir al inicio"><img class="brand__mark" src="/assets/isotype.svg" width="30" height="30" alt=""><span class="brand__txt"><b>NORTH<i>STAR</i></b><small>CAPTACIÓN</small></span></a>';
?>
<!doctype html>
<html lang="es"<?= isset($prefs) ? ' data-theme-pref="' . $e($prefs['theme']) . '" data-side="' . $e($prefs['side']) . '" data-density="' . $e($prefs['density']) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="color-scheme" content="light dark">
<title><?= $e($title ?? 'Captación') ?> · NorthStar Captación</title>
<link rel="icon" href="/assets/isotype.svg" type="image/svg+xml">
<link rel="preload" href="/assets/fonts/barlow-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/fonts.css?v=<?= $e($assetV('fonts.css')) ?>">
<link rel="stylesheet" href="/assets/panel.css?v=<?= $e($assetV('panel.css')) ?>">
<?php foreach (($css ?? ($shell ? [] : ['auth'])) as $sheet): if (preg_match('/^[a-z0-9-]{2,24}$/', (string) $sheet) !== 1) { continue; } ?>
<link rel="stylesheet" href="/assets/<?= $e($sheet) ?>.css?v=<?= $e($assetV($sheet . '.css')) ?>">
<?php endforeach; ?>
<script nonce="<?= $e($nonce) ?>">(function(d,w){var h=d.documentElement,p=h.getAttribute('data-theme-pref'),mq=w.matchMedia?w.matchMedia('(prefers-color-scheme: dark)'):null;h.classList.add('js');if(!p){try{p=w.localStorage.getItem('ns-theme')}catch(e){}}if(p!=='light'&&p!=='dark')p='auto';function ap(){h.setAttribute('data-theme',p==='dark'||(p==='auto'&&mq&&mq.matches)?'dark':'light')}ap();if(mq&&mq.addEventListener)mq.addEventListener('change',function(){if(h.getAttribute('data-theme-pref')==='auto'||!h.hasAttribute('data-theme-pref'))ap()});var was;w.addEventListener('beforeprint',function(){was=h.getAttribute('data-theme');h.setAttribute('data-theme','light')});w.addEventListener('afterprint',function(){if(was)h.setAttribute('data-theme',was)})})(document,window)</script>
</head>
<?php if (!$shell): ?>
<body class="bare">
<?php require __DIR__ . '/_icons.php'; ?>
<?= $content ?>
</body>
</html>
<?php return; endif; ?>
<body class="app">
<?php require __DIR__ . '/_icons.php'; ?>
<a class="skip" href="#main">Saltar al contenido</a>
<div class="shell">
  <aside class="side" id="side" aria-label="Navegación principal">
    <div class="side__head">
      <?= $brand ?>
      <button class="icon-btn side__close" type="button" data-nav-close aria-label="Cerrar menú"><?= Ui::icon('x') ?></button>
    </div>
    <nav class="side__nav">
<?php
$ni = static function (string $href, string $icon, string $label, string $key, array $o = []) use ($e, $active): string {
    $on = $active === $key;
    $n = (int) ($o['badge'] ?? 0);
    return '<a class="nav-i' . ($on ? ' is-on' : '') . '" href="' . $e($href) . '" data-tip="' . $e($label) . '"' . ($on ? ' aria-current="page"' : '') . '>' . Ui::icon($icon) . '<span class="nav-i__t">' . $e($label) . '</span>'
        . ($n > 0 ? '<span class="badge badge--' . $e($o['cls'] ?? 'late') . '"' . (isset($o['title']) ? ' title="' . $e($o['title']) . '"' : '') . '>' . $e($n) . '<span class="sr"> ' . $e($o['sr'] ?? '') . '</span></span>' : '') . '</a>';
};
?>
<?php if (!empty($isViewer)): ?>
      <p class="side__group">NorthStar</p>
      <?= $ni('/resultados', 'bar-chart', 'Resultados', 'resultados') ?>
      <?= $ni('/informes', 'file', 'Informes', 'informes') ?>
<?php else: ?>
      <p class="side__group">Principal</p>
      <button class="nav-i nav-i--btn" type="button" data-inbox data-tip="Bandeja" aria-haspopup="dialog"><?= Ui::icon('inbox') ?><span class="nav-i__t">Bandeja</span><span class="badge badge--late" data-inbox-n<?= ($unread + $overdueBadge) > 0 ? '' : ' hidden' ?>><?= $e($unread + $overdueBadge) ?></span></button>
      <?= $ni('/', 'dashboard', 'Dashboard', 'dashboard') ?>
      <?= $ni('/leads', 'users', 'Leads', 'leads') ?>
      <?= $ni('/pipeline', 'columns', 'Pipeline', 'pipeline') ?>
      <?= $ni('/tareas', 'list-check', 'Tareas', 'tareas', ['badge' => $overdueBadge, 'title' => 'Tareas vencidas', 'sr' => 'vencidas']) ?>
      <p class="side__group">Vistas</p>
      <?php foreach ($navViews as $v): $vOn = ($_GET['v'] ?? '') === $v['id'] && in_array($active, ['leads', 'pipeline'], true); ?>
        <a class="nav-i nav-i--view<?= $vOn ? ' is-on' : '' ?>" href="<?= $e($v['href']) ?>" data-tip="<?= $e($v['name']) ?>"<?= $vOn ? ' aria-current="page"' : '' ?>><?= Ui::icon($v['icon']) ?><span class="nav-i__t"><?= $e($v['name']) ?></span><span class="nav-i__n" data-view-count="<?= $e($v['id']) ?>"><?= $e($v['count']) ?></span></a>
      <?php endforeach; ?>
      <?php if ($isAdmin): ?>
      <p class="side__group">Captación saliente</p>
      <?= $ni('/prospectos', 'phone', 'Prospectos', 'prospectos', ['badge' => (int) ($followBadge ?? 0), 'title' => 'Seguimientos de hoy', 'sr' => 'seguimientos de hoy']) ?>
      <?= $ni('/referidos', 'user-plus', 'Referidos', 'referidos', ['badge' => (int) ($bonusBadge ?? 0), 'cls' => 'hot', 'title' => 'Bonos por aprobar', 'sr' => 'bonos por aprobar']) ?>
      <?= $ni('/vacantes', 'file', 'Vacantes', 'vacantes') ?>
      <p class="side__group">Reportes y correo</p>
      <?= $ni('/reportes', 'bar-chart', 'Reportes', 'reportes') ?>
      <?= $ni('/informes', 'file', 'Informes', 'informes') ?>
      <?= $ni('/comunicaciones', 'mail', 'Comunicaciones', 'comunicaciones') ?>
      <p class="side__group">Administración</p>
      <?= $ni('/config', 'gear', 'Configuración', 'config') ?>
      <?= $ni('/actividad', 'activity', 'Actividad', 'actividad') ?>
      <?php endif; ?>
<?php endif; ?>
    </nav>
    <div class="side__foot">
      <?php if (empty($isViewer)): ?><a class="sys" href="/#cola" data-tip="Estado del sistema">
        <span class="sys__row"><i class="dot<?= !empty($sysOk) ? ' is-ok' : '' ?>"></i><span class="sys__t">Estado del sistema</span></span>
        <small>Cola, cron e integraciones</small>
      </a><?php endif; ?>
      <?php if (empty($isViewer)): ?><button class="side__fold" type="button" data-side-toggle aria-pressed="<?= ($prefs['side'] ?? 'open') === 'collapsed' ? 'true' : 'false' ?>" data-tip="Contraer barra lateral"><?= Ui::icon('panel-left') ?><span class="nav-i__t">Contraer barra lateral</span></button><?php endif; ?>
      <div class="dd" data-dd>
        <button class="user" type="button" aria-expanded="false" aria-haspopup="menu" aria-controls="dd-user">
          <?= Ui::avatar((string) $user['name'], 'md', 0) ?>
          <span class="user__txt"><b><?= $e($user['name']) ?></b><small><?= $e($user['role'] === 'admin' ? 'Administrador' : 'Lector · NorthStar') ?></small></span>
          <?= Ui::icon('chevrons') ?>
        </button>
        <div class="dd__panel dd__panel--up" id="dd-user" role="menu" hidden>
          <?php require __DIR__ . '/_usermenu.php'; ?>
        </div>
      </div>
    </div>
  </aside>
  <div class="scrim" data-nav-close></div>

  <div class="col">
    <header class="top">
      <button class="icon-btn top__burger" type="button" data-nav-open aria-controls="side" aria-expanded="false" aria-label="Abrir menú"><?= Ui::icon('menu') ?></button>
      <a class="top__logo" href="<?= !empty($isViewer) ? '/resultados' : '/' ?>" aria-label="Inicio"><img src="/assets/isotype.svg" width="26" height="26" alt=""></a>
      <nav class="crumbs" aria-label="Migas de pan">
        <ol>
          <?php foreach ($crumbs as $i => [$text, $href]): $last = $i === count($crumbs) - 1; ?>
            <li<?= $last ? ' aria-current="page"' : '' ?>><?php if ($href !== null): ?><a href="<?= $e($href) ?>"><?= $e($text) ?></a><?php else: ?><?= $e($text) ?><?php endif; ?></li>
          <?php endforeach; ?>
        </ol>
      </nav>
      <div class="top__tools">
        <?php if (empty($isViewer)): ?><button class="search-btn" type="button" data-search aria-haspopup="dialog" aria-keyshortcuts="Control+K Meta+K /">
          <?= Ui::icon('search') ?><span class="search-btn__t">Buscar o ejecutar…</span><span class="kbds" aria-hidden="true"><kbd>/</kbd><kbd data-mod>Ctrl</kbd><kbd>K</kbd></span>
        </button><?php endif; ?>
        <span class="clock" title="Hora en <?= $e(\Captacion\Services\Display::timezone()) ?>"><?= $e(\Captacion\Services\Fmt::zone()) ?> <time data-clock data-tz="<?= $e(\Captacion\Services\Display::timezone()) ?>"><?= $e(\Captacion\Services\Fmt::et(\Captacion\Clock::time())->format('H:i')) ?></time></span>
        <button class="icon-btn thm" type="button" data-theme-cycle aria-label="Cambiar tema de color"><?= Ui::icon('sun', 'thm__sun') ?><?= Ui::icon('moon', 'thm__moon') ?><?= Ui::icon('monitor', 'thm__auto') ?></button>
        <?php if (empty($isViewer)): ?><div class="dd" data-dd data-notes>
          <button class="icon-btn" type="button" aria-expanded="false" aria-controls="dd-notes" aria-haspopup="dialog" aria-label="Avisos<?= $unread > 0 ? ', ' . $unread . ' sin leer' : '' ?>">
            <?= Ui::icon('bell') ?><span class="icon-btn__n" data-unread<?= $unread > 0 ? '' : ' hidden' ?>><?= $e($unread) ?></span>
          </button>
          <div class="dd__panel dd__panel--notes" id="dd-notes" role="dialog" aria-label="Bandeja de avisos" hidden>
            <div class="dd__bar"><span class="label">BANDEJA</span><button class="link" type="button" data-notes-read<?= $unread > 0 ? '' : ' hidden' ?>>Marcar todo como leído</button></div>
            <?php
            $grp = static fn(array $n): string => str_starts_with($n['href'], '/tareas') ? 'tareas' : (preg_match('#^/(leads|referidos|prospectos)#', $n['href']) === 1 ? 'leads' : 'sistema');
            ?>
            <div class="notes__f" role="group" aria-label="Filtrar avisos"><?php foreach (['all' => 'Todos', 'unread' => 'Sin leer', 'leads' => 'Leads', 'tareas' => 'Tareas', 'sistema' => 'Sistema'] as $fk => $fl): ?><button class="seg__i<?= $fk === 'all' ? ' is-on' : '' ?>" type="button" data-nf="<?= $e($fk) ?>" aria-pressed="<?= $fk === 'all' ? 'true' : 'false' ?>"><?= $e($fl) ?></button><?php endforeach; ?></div>
            <p class="dd__empty"<?= $bell ? ' hidden' : '' ?> data-notes-empty><?= Ui::icon('check-circle') ?>Todo en orden. No hay avisos pendientes.</p>
            <?php if ($bell): ?>
              <ul class="notes">
                <?php foreach ($bell as $n): ?>
                  <li class="note-li<?= $n['read'] ? '' : ' is-new' ?>" data-g="<?= $e($grp($n)) ?>" data-read="<?= $n['read'] ? '1' : '0' ?>"><a class="note<?= $n['read'] ? '' : ' is-new' ?>" href="<?= $e($n['href']) ?>" data-key="<?= $e($n['key']) ?>"><i class="dot dot--<?= $e($n['kind']) ?>"></i><span><b><?= $e($n['title']) ?></b><small><?= $e($n['meta']) ?></small></span></a><button class="icon-btn icon-btn--sm note__t" type="button" data-note-toggle="<?= $e($n['key']) ?>" aria-label="<?= $n['read'] ? 'Ya leído' : 'Marcar como leído' ?>: <?= $e($n['title']) ?>"<?= $n['read'] ? ' hidden' : '' ?>><?= Ui::icon('check') ?></button></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <p class="dd__empty" hidden data-notes-none>Ningún aviso coincide con este filtro.</p>
            <a class="dd__foot" href="/tareas?tab=overdue"><?= Ui::icon('list-check') ?>Tareas vencidas<?php if ($overdueBadge > 0): ?><b class="badge badge--late"><?= $e($overdueBadge) ?></b><?php endif; ?></a>
          </div>
        </div><?php endif; ?>
        <div class="dd" data-dd>
          <button class="icon-btn icon-btn--av" type="button" aria-expanded="false" aria-haspopup="menu" aria-controls="dd-av" aria-label="Cuenta de <?= $e($user['name']) ?>"><?= Ui::avatar((string) $user['name'], 'sm', 0) ?></button>
          <div class="dd__panel" id="dd-av" role="menu" hidden>
            <?php require __DIR__ . '/_usermenu.php'; ?>
          </div>
        </div>
      </div>
    </header>
    <main id="main" class="main" tabindex="-1">
      <?php if (!empty($flash)): ?><div class="flash flash--<?= $flash['ok'] ? 'ok' : 'bad' ?>" role="<?= $flash['ok'] ? 'status' : 'alert' ?>" data-flash><?= Ui::icon($flash['ok'] ? 'check-circle' : 'alert') ?><span class="flash__t"><?= $e($flash['msg']) ?></span><?php if (!empty($flash['undo'])): ?>
        <form class="flash__u" method="post" action="/undo"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="token" value="<?= $e($flash['undo']) ?>"><input type="hidden" name="back" value="<?= $e(preg_match('#^/[A-Za-z0-9_/\-]{0,60}(\?[A-Za-z0-9_%=&.\-+]{0,800})?$#', (string) ($_SERVER['REQUEST_URI'] ?? '/')) === 1 ? $_SERVER['REQUEST_URI'] : '/') ?>"><button class="link" type="submit" data-undo-form><?= Ui::icon('undo') ?>Deshacer</button></form><?php endif; ?></div><?php endif; ?>
      <?= $content ?>
    </main>
  </div>
</div>

<?php if (empty($hideTabbar)): ?>
<nav class="tabbar" aria-label="Acceso rápido">
<?php if (!empty($isViewer)): ?>
  <a class="tab<?= $active === 'resultados' ? ' is-on' : '' ?>" href="/resultados"><?= Ui::icon('bar-chart') ?><span>Resultados</span></a>
  <a class="tab<?= $active === 'informes' ? ' is-on' : '' ?>" href="/informes"><?= Ui::icon('file') ?><span>Informes</span></a>
<?php else: ?>
  <a class="tab<?= $active === 'dashboard' ? ' is-on' : '' ?>" href="/"><?= Ui::icon('dashboard') ?><span>Inicio</span></a>
  <a class="tab<?= $active === 'leads' ? ' is-on' : '' ?>" href="/leads"><?= Ui::icon('users') ?><span>Leads</span></a>
  <a class="tab<?= $active === 'pipeline' ? ' is-on' : '' ?>" href="/pipeline"><?= Ui::icon('columns') ?><span>Pipeline</span></a>
  <a class="tab<?= $active === 'tareas' ? ' is-on' : '' ?>" href="/tareas"><?= Ui::icon('list-check') ?><span>Tareas</span><?php if ($overdueBadge > 0): ?><i class="tab__n"><?= $e($overdueBadge) ?></i><?php endif; ?></a>
<?php endif; ?>
  <button class="tab" type="button" data-nav-open aria-controls="side"><?= Ui::icon('menu') ?><span>Menú</span></button>
</nav>
<?php endif; ?>

<?php if (empty($isViewer)): ?><dialog class="dlg dlg--search" id="search" aria-label="Buscar y ejecutar comandos" data-cmds="<?= $e(json_encode($cmds ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
  <div class="srch" role="search">
    <div class="srch__bar"><?= Ui::icon('search') ?>
      <input id="search-q" type="text" enterkeyhint="go" role="combobox" aria-expanded="false" aria-controls="search-list" aria-autocomplete="list" placeholder="Busca un lead o escribe una acción…" autocomplete="off" spellcheck="false" maxlength="100" aria-label="Buscar leads o ejecutar una acción">
      <button class="kbd-esc" type="button" data-close aria-label="Cerrar"><kbd>Esc</kbd></button>
    </div>
    <ul class="srch__list" id="search-list" role="listbox" aria-label="Resultados"></ul>
    <p class="srch__msg" id="search-msg" role="status"></p>
    <p class="srch__foot"><span><kbd>↑</kbd><kbd>↓</kbd> moverse</span><span><kbd>Enter</kbd> ejecutar</span><span><kbd>?</kbd> atajos</span></p>
  </div>
</dialog>
<dialog class="dlg dlg--keys" id="keys" aria-labelledby="keys-t">
  <div class="dlg__h"><h2 class="dlg__t" id="keys-t">Atajos de teclado</h2><button class="icon-btn icon-btn--sm" type="button" data-close aria-label="Cerrar"><?= Ui::icon('x') ?></button></div>
  <p class="dlg__m">Funcionan fuera de los campos de texto. Todo lo que hacen se puede hacer también con el ratón.</p>
  <div class="keys">
    <?php foreach ([
        'General' => [['/', 'Buscar leads'], ['Ctrl/⌘ K', 'Paleta de comandos'], ['?', 'Mostrar esta hoja'], ['Esc', 'Cerrar lo que esté abierto']],
        'Ir a' => [['g d', 'Dashboard'], ['g l', 'Leads'], ['g p', 'Pipeline'], ['g t', 'Tareas']],
        'Listas y tablero' => [['j / k', 'Siguiente / anterior'], ['Enter', 'Abrir el vistazo rápido'], ['Doble clic', 'Abrir la ficha completa']],
        'Con un lead abierto' => [['n', 'Añadir nota'], ['c', 'Registrar llamada'], ['m', 'Mover de etapa']],
    ] as $grp => $rows): ?>
      <section><h3 class="label"><?= $e(strtoupper($grp)) ?></h3><dl><?php foreach ($rows as [$k, $d]): ?><div><dt><?php foreach (explode(' ', $k) as $i => $part): ?><?= $i ? ' ' : '' ?><kbd><?= $e($part) ?></kbd><?php endforeach; ?></dt><dd><?= $e($d) ?></dd></div><?php endforeach; ?></dl></section>
    <?php endforeach; ?>
  </div>
</dialog>
<dialog class="dlg" id="mv-dlg" aria-labelledby="mv-t">
  <form method="dialog" data-mv-form>
    <div class="dlg__h"><h2 class="dlg__t" id="mv-t">Mover lead</h2><button class="icon-btn icon-btn--sm" type="button" data-close aria-label="Cerrar"><?= Ui::icon('x') ?></button></div>
    <p class="dlg__m" data-mv-msg></p>
    <label class="field" data-mv-pick hidden><span>Mover a</span><select name="to"></select></label>
    <label class="field" data-mv-reason hidden><span>Motivo</span>
      <select name="reason"><option value="">Elige un motivo…</option><?php foreach (\Captacion\Services\Catalog::LOSS_REASONS as $k => $l): ?><option value="<?= $e($k) ?>"><?= $e($l) ?></option><?php endforeach; ?></select></label>
    <label class="field" data-mv-note hidden><span>Nota <small data-mv-note-hint>(opcional)</small></span><textarea name="note" maxlength="500" rows="3"></textarea></label>
    <label class="check" data-mv-override hidden><input type="checkbox" name="override" value="1"><span>Sí, quiero saltarme el orden de las etapas.</span></label>
    <div class="dlg__a"><button class="btn btn--ghost" type="button" data-close>Cancelar</button><button class="btn btn--primary" type="submit" value="go"><span class="btn__t">Mover</span><span class="btn__cell"><?= Ui::icon('arrow-up-right') ?></span></button></div>
  </form>
</dialog>
<dialog class="dlg" id="qc-dlg" aria-labelledby="qc-t">
  <form method="dialog" data-qc-form novalidate>
    <div class="dlg__h"><h2 class="dlg__t" id="qc-t">Nuevo lead</h2><button class="icon-btn icon-btn--sm" type="button" data-close aria-label="Cerrar"><?= Ui::icon('x') ?></button></div>
    <p class="dlg__m">Alta manual: pasa por el mismo motor que los demás (validación, duplicados y puntuación). No registra consentimiento de mensajería.</p>
    <label class="field"><span>Nombre</span><input type="text" name="name" maxlength="200" autocomplete="off" required></label>
    <label class="field"><span>Teléfono</span><input type="tel" name="phone" maxlength="40" inputmode="tel" autocomplete="off" required></label>
    <label class="field"><span>Perfil</span><select name="profile" required><option value="">Elige el perfil…</option><?php foreach (\Captacion\Services\Catalog::PROFILES as $k => $l): ?><option value="<?= $e($k) ?>"><?= $e($l) ?></option><?php endforeach; ?></select></label>
    <p class="field__err" role="alert" data-qc-err hidden></p>
    <div class="dlg__a"><button class="btn btn--ghost" type="button" data-close>Cancelar</button><button class="btn btn--primary" type="submit" value="go"><span class="btn__t">Crear lead</span><span class="btn__cell"><?= Ui::icon('plus') ?></span></button></div>
  </form>
</dialog><?php endif; ?>
<div class="toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
<dialog class="dlg dlg--confirm" id="confirm" aria-labelledby="confirm-t">
  <form method="dialog">
    <h2 id="confirm-t" class="dlg__t">¿Seguro?</h2>
    <p id="confirm-m" class="dlg__m"></p>
    <div class="dlg__a"><button class="btn btn--ghost" value="no">Cancelar</button><button class="btn btn--danger" value="yes" id="confirm-y">Confirmar</button></div>
  </form>
</dialog>
<script nonce="<?= $e($nonce) ?>" src="/assets/panel.js?v=<?= $e($assetV('panel.js')) ?>" defer data-csrf="<?= $e($csrf) ?>"></script>
<?php if (empty($isViewer)): ?><script nonce="<?= $e($nonce) ?>" src="/assets/ux.js?v=<?= $e($assetV('ux.js')) ?>" defer data-csrf="<?= $e($csrf) ?>" data-css-v="<?= $e($assetV('ux.css')) ?>"></script><?php endif; ?>
<?php foreach (($js ?? []) as $script): if (preg_match('/^[a-z0-9-]{2,24}$/', (string) $script) !== 1) { continue; } ?>
<script nonce="<?= $e($nonce) ?>" src="/assets/<?= $e($script) ?>.js?v=<?= $e($assetV($script . '.js')) ?>" defer data-csrf="<?= $e($csrf) ?>"></script>
<?php endforeach; ?>
</body>
</html>
