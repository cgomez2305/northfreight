<?php
$temps = ['hot' => ['Caliente', 'hot'], 'warm' => ['Tibio', 'warm'], 'cold' => ['Frío', 'cold'], 'disqualified' => ['Descartado', 'dq']];
$profiles = ['owner_operator' => 'Owner-operator', 'small_carrier' => 'Carrier pequeño', 'company_driver' => 'Company driver'];
$q = $queue['counts'];
$last = $queue['last_run'];
?>
<header class="top">
  <p class="brand">NorthStar <span>Captación</span></p>
  <form method="post" action="/logout"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="link" type="submit">Salir</button></form>
</header>
<main class="wrap">
  <h1>Hola, <?= $e($user['name']) ?></h1>
  <?php if (!empty($notice)): ?><p class="notice" role="status"><?= $e($notice) ?></p><?php endif; ?>

  <?php foreach ($warnings as $w): ?><p class="alert" role="alert"><?= $e($w) ?></p><?php endforeach; ?>

  <section class="card" aria-labelledby="h-queue">
    <h2 id="h-queue">Cola de trabajos</h2>
    <dl class="stats">
      <div><dt>Pendientes</dt><dd><?= $e($q['pending']) ?></dd></div>
      <div><dt>En curso</dt><dd><?= $e($q['running']) ?></dd></div>
      <div class="<?= $q['failed'] > 0 ? 'stats__bad' : '' ?>"><dt>Fallidos</dt><dd><?= $e($q['failed']) ?></dd></div>
      <div><dt>Registrados sin ejecutar</dt><dd><?= $e($q['parked']) ?></dd></div>
      <div><dt>Hechos</dt><dd><?= $e($q['done']) ?></dd></div>
    </dl>
    <p class="muted">Última ejecución del cron:
      <?php if ($last): ?><strong><?= $e($last['at']) ?> UTC</strong> (<?= $e($lastAgo) ?>) · <?= $e($last['processed']) ?> procesados, <?= $e($last['failed']) ?> fallidos
      <?php else: ?><strong>nunca</strong><?php endif; ?></p>
    <?php if ($queue['recent_fail']): ?>
    <h3>Últimos fallidos</h3>
    <ul class="faillist">
      <?php foreach ($queue['recent_fail'] as $f): ?><li><code><?= $e($f['type']) ?></code> #<?= $e($f['id']) ?> · <?= $e($f['attempts']) ?> intentos · <?= $e($f['last_error']) ?></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>

  <section class="card" aria-labelledby="h-leads">
    <h2 id="h-leads">Últimos 20 leads <span class="muted">(<?= $e($total) ?> en total)</span></h2>
    <?php if (!$leads): ?>
      <p class="muted">Todavía no hay leads.</p>
    <?php else: ?>
    <div class="tablewrap">
      <table>
        <thead><tr><th>Fecha (UTC)</th><th>Nombre</th><th>Perfil</th><th>Estado</th><th>Temp.</th><th>Pts</th><th>Fuente</th></tr></thead>
        <tbody>
        <?php foreach ($leads as $l): $t = $temps[$l['temperature']] ?? [$l['temperature'], 'cold']; ?>
          <tr>
            <td><?= $e($l['created_at']) ?></td>
            <td><a href="/leads/<?= $e($l['id']) ?>"><?= $e(trim($l['first_name'] . ' ' . $l['last_name'])) ?></a></td>
            <td><?= $e($profiles[$l['profile']] ?? $l['profile']) ?></td>
            <td><?= $e($l['state'] ?? '-') ?></td>
            <td><span class="chip chip--<?= $e($t[1]) ?>"><?= $e($t[0]) ?></span></td>
            <td><?= $e($l['score']) ?></td>
            <td><?= $e($l['source']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>
</main>
