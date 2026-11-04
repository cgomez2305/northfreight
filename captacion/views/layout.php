<?php
use Captacion\Ui;
use Captacion\Middleware\SecurityHeaders;

$nonce = SecurityHeaders::nonce();
$assetV = static fn(string $f): string => (string) (@filemtime(CAPTACION_ROOT . '/public/assets/' . $f) ?: 1);
$shell = !empty($shell);
$brand = '<a class="brand" href="/" aria-label="NorthStar Captación, ir al dashboard"><img class="brand__mark" src="/assets/isotype.svg" width="30" height="30" alt=""><span class="brand__txt"><b>NORTH<i>STAR</i></b><small>CAPTACIÓN</small></span></a>';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="color-scheme" content="light">
<title><?= $e($title ?? 'Captación') ?> · NorthStar Captación</title>
<link rel="icon" href="/assets/isotype.svg" type="image/svg+xml">
<link rel="preload" href="/assets/fonts/barlow-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/fonts.css?v=<?= $e($assetV('fonts.css')) ?>">
<link rel="stylesheet" href="/assets/panel.css?v=<?= $e($assetV('panel.css')) ?>">
<script nonce="<?= $e($nonce) ?>">document.documentElement.classList.add('js')</script>
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
      <p class="side__group">Principal</p>
      <a class="nav-i<?= $active === 'dashboard' ? ' is-on' : '' ?>" href="/"<?= $active === 'dashboard' ? ' aria-current="page"' : '' ?>><?= Ui::icon('dashboard') ?>Dashboard</a>
      <a class="nav-i<?= $active === 'leads' ? ' is-on' : '' ?>" href="/leads"<?= $active === 'leads' ? ' aria-current="page"' : '' ?>><?= Ui::icon('users') ?>Leads</a>
      <p class="side__group">Atajos</p>
      <a class="nav-i" href="/leads?t=hot&amp;ct=none"><?= Ui::icon('zap') ?>Calientes pendientes<?php if ($hotBadge > 0): ?><span class="badge badge--hot"><?= $e($hotBadge) ?></span><?php endif; ?></a>
      <a class="nav-i" href="/leads?from=<?= $e(\Captacion\Services\Fmt::et(\Captacion\Clock::time())->format('Y-m-d')) ?>"><?= Ui::icon('inbox') ?>Llegaron hoy</a>
      <a class="nav-i" href="/leads?t=disqualified"><?= Ui::icon('flag') ?>Descartados</a>
    </nav>
    <div class="side__foot">
      <a class="sys" href="/#cola">
        <span class="sys__row"><i class="dot<?= !empty($sysOk) ? ' is-ok' : '' ?>"></i>Estado del sistema</span>
        <small>Cola, cron e integraciones</small>
      </a>
      <div class="dd" data-dd>
        <button class="user" type="button" aria-expanded="false" aria-haspopup="menu" aria-controls="dd-user">
          <?= Ui::avatar((string) $user['name'], 'md', 0) ?>
          <span class="user__txt"><b><?= $e($user['name']) ?></b><small><?= $e($user['role'] === 'admin' ? 'Administrador' : 'Lector') ?></small></span>
          <?= Ui::icon('chevrons') ?>
        </button>
        <div class="dd__panel dd__panel--up" id="dd-user" role="menu" hidden>
          <div class="dd__head"><b><?= $e($user['name']) ?></b><small><?= $e($user['email']) ?></small></div>
          <form method="post" action="/logout"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="dd__item" type="submit" role="menuitem"><?= Ui::icon('logout') ?>Salir</button></form>
        </div>
      </div>
    </div>
  </aside>
  <div class="scrim" data-nav-close></div>

  <div class="col">
    <header class="top">
      <button class="icon-btn top__burger" type="button" data-nav-open aria-controls="side" aria-expanded="false" aria-label="Abrir menú"><?= Ui::icon('menu') ?></button>
      <a class="top__logo" href="/" aria-label="Dashboard"><img src="/assets/isotype.svg" width="26" height="26" alt=""></a>
      <nav class="crumbs" aria-label="Migas de pan">
        <ol>
          <?php foreach ($crumbs as $i => [$text, $href]): $last = $i === count($crumbs) - 1; ?>
            <li<?= $last ? ' aria-current="page"' : '' ?>><?php if ($href !== null): ?><a href="<?= $e($href) ?>"><?= $e($text) ?></a><?php else: ?><?= $e($text) ?><?php endif; ?></li>
          <?php endforeach; ?>
        </ol>
      </nav>
      <div class="top__tools">
        <button class="search-btn" type="button" data-search aria-haspopup="dialog" aria-keyshortcuts="Control+K Meta+K /">
          <?= Ui::icon('search') ?><span class="search-btn__t">Buscar leads, teléfono, USDOT…</span><span class="kbds" aria-hidden="true"><kbd data-mod>Ctrl</kbd><kbd>K</kbd></span>
        </button>
        <span class="clock" title="Hora del este de EE. UU.">ET <time data-clock><?= $e(\Captacion\Services\Fmt::et(\Captacion\Clock::time())->format('H:i')) ?></time></span>
        <div class="dd" data-dd data-notes>
          <button class="icon-btn" type="button" aria-expanded="false" aria-controls="dd-notes" aria-haspopup="dialog" aria-label="Avisos<?= $unread > 0 ? ', ' . $unread . ' sin leer' : '' ?>">
            <?= Ui::icon('bell') ?><span class="icon-btn__n" data-unread<?= $unread > 0 ? '' : ' hidden' ?>><?= $e($unread) ?></span>
          </button>
          <div class="dd__panel dd__panel--notes" id="dd-notes" role="dialog" aria-label="Avisos" hidden>
            <div class="dd__bar"><span class="label">AVISOS</span><button class="link" type="button" data-notes-read<?= $unread > 0 ? '' : ' hidden' ?>>Marcar todo como leído</button></div>
            <?php if (!$bell): ?>
              <p class="dd__empty"><?= Ui::icon('check-circle') ?>Todo en orden. No hay avisos pendientes.</p>
            <?php else: ?>
              <ul class="notes">
                <?php foreach ($bell as $n): ?>
                  <li><a class="note<?= $n['read'] ? '' : ' is-new' ?>" href="<?= $e($n['href']) ?>" data-key="<?= $e($n['key']) ?>"><i class="dot dot--<?= $e($n['kind']) ?>"></i><span><b><?= $e($n['title']) ?></b><small><?= $e($n['meta']) ?></small></span></a></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
        <div class="dd" data-dd>
          <button class="icon-btn icon-btn--av" type="button" aria-expanded="false" aria-haspopup="menu" aria-controls="dd-av" aria-label="Cuenta de <?= $e($user['name']) ?>"><?= Ui::avatar((string) $user['name'], 'sm', 0) ?></button>
          <div class="dd__panel" id="dd-av" role="menu" hidden>
            <div class="dd__head"><b><?= $e($user['name']) ?></b><small><?= $e($user['email']) ?></small></div>
            <form method="post" action="/logout"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="dd__item" type="submit" role="menuitem"><?= Ui::icon('logout') ?>Salir</button></form>
          </div>
        </div>
      </div>
    </header>
    <main id="main" class="main" tabindex="-1">
      <?php if (!empty($flash)): ?><p class="flash flash--<?= $flash['ok'] ? 'ok' : 'bad' ?>" role="<?= $flash['ok'] ? 'status' : 'alert' ?>"><?= Ui::icon($flash['ok'] ? 'check-circle' : 'alert') ?><?= $e($flash['msg']) ?></p><?php endif; ?>
      <?= $content ?>
    </main>
  </div>
</div>

<?php if (empty($hideTabbar)): ?>
<nav class="tabbar" aria-label="Acceso rápido">
  <a class="tab<?= $active === 'dashboard' ? ' is-on' : '' ?>" href="/"><?= Ui::icon('dashboard') ?><span>Inicio</span></a>
  <a class="tab<?= $active === 'leads' ? ' is-on' : '' ?>" href="/leads"><?= Ui::icon('users') ?><span>Leads</span></a>
  <button class="tab" type="button" data-search><?= Ui::icon('search') ?><span>Buscar</span></button>
  <button class="tab" type="button" data-nav-open aria-controls="side"><?= Ui::icon('menu') ?><span>Menú</span></button>
</nav>
<?php endif; ?>

<dialog class="dlg dlg--search" id="search" aria-label="Buscar leads">
  <div class="srch" role="search">
    <div class="srch__bar"><?= Ui::icon('search') ?>
      <input id="search-q" type="text" enterkeyhint="search" role="combobox" aria-expanded="false" aria-controls="search-list" aria-autocomplete="list" placeholder="Nombre, teléfono, correo o USDOT" autocomplete="off" spellcheck="false" maxlength="100" aria-label="Buscar leads">
      <button class="kbd-esc" type="button" data-close aria-label="Cerrar búsqueda"><kbd>Esc</kbd></button>
    </div>
    <ul class="srch__list" id="search-list" role="listbox" aria-label="Resultados"></ul>
    <p class="srch__msg" id="search-msg" role="status">Escribe al menos 2 caracteres.</p>
    <p class="srch__foot"><span><kbd>↑</kbd><kbd>↓</kbd> moverse</span><span><kbd>Enter</kbd> abrir</span><span><kbd>/</kbd> buscar desde cualquier página</span></p>
  </div>
</dialog>
<dialog class="dlg dlg--confirm" id="confirm" aria-labelledby="confirm-t">
  <form method="dialog">
    <h2 id="confirm-t" class="dlg__t">¿Seguro?</h2>
    <p id="confirm-m" class="dlg__m"></p>
    <div class="dlg__a"><button class="btn btn--ghost" value="no">Cancelar</button><button class="btn btn--danger" value="yes" id="confirm-y">Confirmar</button></div>
  </form>
</dialog>
<script nonce="<?= $e($nonce) ?>" src="/assets/panel.js?v=<?= $e($assetV('panel.js')) ?>" defer data-csrf="<?= $e($csrf) ?>"></script>
</body>
</html>
