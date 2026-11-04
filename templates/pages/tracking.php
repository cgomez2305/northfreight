<?php
/**
 * /load-tracking/ — maqueta Tracking.html.
 * Orden: hero (texto + "Desk log" de ejemplo, sin foto) · What we log · Evidencia (foto ancha con tarjeta solapada, patrón B) · CTA final (lo imprime el layout).
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$log = [
    ['06:12', 'Rate confirmation received & filed', ''],
    ['08:05', 'ETA sent to broker', ''],
    ['09:40', 'Driver checked in at shipper, photo attached', ''],
    ['11:55', 'Delay logged with timestamps for detention claim', ' log__row--on'],
    ['—', 'POD collected at delivery', ' sb-tr-end'],
];
$logs = ['Check calls & ETA updates', 'Arrival & departure times', 'Delays & detention clock', 'Incident photos & notes', 'BOL & POD'];

ob_start(); ?>
<div class="data-card sb-tr-card">
  <div class="data-card__head">
    <span class="data-card__label t-meta">Desk log · Example</span>
    <span class="data-card__status"><span class="data-card__dot pulse" aria-hidden="true"></span>LOAD #<?= ph('ID') ?> · IN TRANSIT</span>
  </div>
  <ol class="log">
    <?php foreach ($log as [$time, $text, $mod]): ?>
    <li class="log__row<?= $mod ?>"><span class="log__time"><?= e($time) ?></span><span class="log__text"><?= e($text) ?></span></li>
    <?php endforeach; ?>
  </ol>
</div>
<?php $aside = ob_get_clean();

partial('hero', [
    'route' => $route,
    'cls'   => 'sb-tr-hero',
    'h1'    => 'Load tracking and check calls {hl}for your trucks{/hl}',
    'lead'  => 'Every trip followed from pickup to POD. ETAs, arrivals and departures logged, incidents documented and brokers kept informed, so you are not on the phone.',
    'ctas'  => [['label' => 'Talk to a dispatcher', 'href' => wa($route['wa']), 'wa' => true, 'arrow' => true]],
    'aside' => $aside,
]);

// Resumen "In short" (SEO/GEO): editable aquí. Solo información que ya está en el copy de esta página; sin cifras, tarifas ni promesas nuevas.
partial('summary', [
    'text'   => 'Truck load tracking means following each trip from pickup to POD: ETAs, arrivals and departures logged, incidents documented and brokers kept informed. NorthStar\'s desk handles the check calls so you are not on the phone.',
    'points' => [
        'Check calls and ETA updates',
        'Arrival and departure times, delays and the detention clock',
        'Incident photos and notes, BOL and POD',
    ],
]);
?>

<section class="section" aria-labelledby="tr-log">
  <div class="container stack">
    <?php partial('section-head', ['eyebrow' => 'On every load', 'title' => 'What our load tracking logs', 'id' => 'tr-log']); ?>
    <ul class="g5 gap-12 sb-cells">
      <?php foreach ($logs as $i => $t): ?>
      <li><?php partial('card', ['size' => 'tile', 'variant' => $i === 4 ? 'dark' : 'light', 'code' => 'LOG · ' . sprintf('%02d', $i + 1), 'title' => $t, 'cls' => 'sb-tile']); ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<?php partial('photo-card', [
    'id'      => 'tr-proof',
    'eyebrow' => 'Proof, not promises',
    'title'   => 'Incidents are handled with evidence',
    'lead'    => 'When a broker questions a late arrival or detention, the record is already there: time, place and photos. Your claim starts with proof, not with a phone call.',
    'image'   => ['base' => 'nf-highway-side-mirror-night', 'alt' => 'Night highway with trucks seen in a truck’s side mirror', 'pos' => '70% 50%'],
    'link'    => route_is_live('/trucking-back-office/') ? ['label' => 'How our back office uses this evidence', 'href' => url('/trucking-back-office/')] : null,
]); ?>
