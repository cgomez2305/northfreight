<?php
$temps = ['hot' => 'Caliente', 'warm' => 'Tibio', 'cold' => 'Frío', 'disqualified' => 'Descartado'];
?>
<header class="top">
  <p class="brand">NorthStar <span>Captación</span></p>
  <a class="link" href="/">Volver</a>
</header>
<main class="wrap">
  <h1><?= $e(trim($lead['first_name'] . ' ' . $lead['last_name'])) ?> <span class="chip chip--<?= $e($lead['temperature'] === 'disqualified' ? 'dq' : $lead['temperature']) ?>"><?= $e($temps[$lead['temperature']] ?? $lead['temperature']) ?></span></h1>
  <p class="muted">Vista mínima de solo lectura (la ficha completa es de la fase Q2).</p>
  <section class="card">
    <dl class="kv">
      <dt>Teléfono</dt><dd><?php if ($lead['phone_e164']): ?><a href="tel:<?= $e($lead['phone_e164']) ?>"><?= $e($lead['phone_e164']) ?></a><?php else: ?>-<?php endif; ?></dd>
      <dt>Correo</dt><dd><?= $e($lead['email'] ?? '-') ?></dd>
      <dt>Estado</dt><dd><?= $e($lead['state'] ?? '-') ?></dd>
      <dt>Perfil</dt><dd><?= $e($lead['profile']) ?></dd>
      <dt>Equipo</dt><dd><?= $e($lead['equipment_types'] ? implode(', ', $lead['equipment_types']) : '-') ?></dd>
      <dt>Puntuación</dt><dd><?= $e($lead['score']) ?>/100<?= $lead['disqualified_reason'] ? ' · motivo: ' . $e($lead['disqualified_reason']) : '' ?></dd>
      <dt>Fuente</dt><dd><?= $e($lead['source']) ?> · <?= $e($lead['landing_page'] ?? '-') ?></dd>
      <dt>Etapa</dt><dd><?= $e($lead['pipeline']) ?> / <?= $e($lead['stage']) ?> (intento <?= $e($lead['attempt']) ?>)</dd>
      <dt>Banderas</dt><dd><?= $e($lead['flags'] ? implode(', ', $lead['flags']) : '-') ?></dd>
    </dl>
  </section>
  <?php if (!empty($lead['score_breakdown']['items'])): ?>
  <section class="card">
    <h2>Desglose de la puntuación</h2>
    <table><tbody>
      <?php foreach ($lead['score_breakdown']['items'] as $it): ?>
        <tr><td><?= $e($it['label']) ?><?= !empty($it['note']) ? ' <span class="muted">(' . $e($it['note']) . ')</span>' : '' ?></td><td class="num"><?= $e($it['points']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  </section>
  <?php endif; ?>
  <section class="card">
    <h2>Historial</h2>
    <ul class="faillist">
      <?php foreach ($events as $ev): ?><li><code><?= $e($ev['type']) ?></code> · <?= $e($ev['created_at']) ?> UTC</li><?php endforeach; ?>
    </ul>
  </section>
</main>
