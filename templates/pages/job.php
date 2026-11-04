<?php
/**
 * Vacante de company driver (ruta generada desde app/data/jobs.php: $route['job'] = slug). JSON-LD JobPosting en job_schema() (helpers.php). Disponibles: $route, $cfg y helpers.
 * Solo imprime los campos que la vacante trae; `pay` no se inventa. La declaración EEO va aquí (y en el paso de company drivers del formulario): TEXTO PENDIENTE DE REVISIÓN LEGAL.
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$slug = (string) ($route['job'] ?? '');
$j = jobs_all()[$slug] ?? [];
$loc = is_array($j['location'] ?? null) ? $j['location'] : [];
$where = trim(($loc['city'] ?? '') . (isset($loc['city'], $loc['region']) ? ', ' : '') . ($loc['region'] ?? ''));
$pay = is_array($j['pay'] ?? null) ? $j['pay'] : [];
$payText = '';
if ((isset($pay['min']) || isset($pay['max'])) && in_array($pay['unit'] ?? '', ['HOUR', 'WEEK', 'MONTH', 'YEAR'], true)) {
    $cur = ($pay['currency'] ?? 'USD') === 'USD' ? '$' : '';
    $range = isset($pay['min'], $pay['max']) && $pay['min'] !== $pay['max'] ? $cur . $pay['min'] . ' to ' . $cur . $pay['max'] : $cur . ($pay['min'] ?? $pay['max']);
    $payText = $range . ' per ' . strtolower((string) $pay['unit']);
}
$facts = array_values(array_filter([
    $where !== '' ? ['Location', $where] : null,
    !empty($j['equipment']) ? ['Equipment', (string) $j['equipment']] : null,
    !empty($j['employment_type']) ? ['Type', ucwords(strtolower(str_replace('_', ' ', (string) $j['employment_type'])))] : null,
    $payText !== '' ? ['Pay', $payText] : null,
]));
$aside = $facts ? '<dl class="cr-facts">' . implode('', array_map(static fn(array $f): string => '<div><dt class="t-meta">' . e($f[0]) . '</dt><dd>' . e($f[1]) . '</dd></div>', $facts)) . '</dl>' : '';
$applyHref = url('/careers/apply/') . '?type=company-driver&job=' . rawurlencode($slug);

partial('hero', [
    'route'   => $route,
    'cls'     => 'cr-hero',
    'eyebrow' => 'Company driver',
    'h1'      => (string) ($j['title'] ?? $route['h1']),
    'lead'    => (string) ($j['summary'] ?? ''),
    'ctas'    => [['label' => 'Apply for this job', 'href' => $applyHref]],
    'aside'   => $aside,
    'stack'   => $aside === '',
]);
?>
<section class="section pre-cta" aria-label="Job details">
  <div class="container container--narrow stack cr-job">
    <?php foreach (['responsibilities' => 'What you will do', 'requirements' => 'What you need', 'benefits' => 'What we offer'] as $k => $title):
        $list = array_values(array_filter(array_map('strval', (array) ($j[$k] ?? [])))); if (!$list) { continue; } ?>
    <div class="cr-job__block">
      <h2 class="t-h2-s"><?= e($title) ?></h2>
      <ul class="cr-job__list"><?php foreach ($list as $li): ?><li><?= e($li) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endforeach; ?>
    <p class="cr-eeo"><?= e('NorthStar Freight Solutions LLC is an equal opportunity employer. We consider all qualified applicants without regard to race, color, religion, sex, sexual orientation, gender identity, national origin, age, disability, veteran status or any other protected status.') ?></p>
    <p><a class="btn btn--primary" href="<?= e($applyHref) ?>"><span class="btn__t">Apply for this job</span></a></p>
  </div>
</section>
