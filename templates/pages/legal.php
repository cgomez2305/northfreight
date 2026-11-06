<?php
/**
 * /privacy-policy/ y /terms/ (clave de ruta `doc`: privacy|terms). Maqueta: Privacy.html / Terms.html.
 * Hero largo (.t-h1-long) + índice lateral sticky con anclas reales + texto. Contenido en templates/pages/legal/{doc}.php.
 */
require_once NS_ROOT . '/templates/partials/kit.php';

$docKey = in_array($route['doc'] ?? '', ['privacy', 'terms'], true) ? $route['doc'] : 'privacy';
$doc = require NS_ROOT . '/templates/pages/legal/' . $docKey . '.php';
$email = (string) cfg('email');

/**
 * Texto del documento: ph() para huecos y tokens {email} (mailto), {phone} (WhatsApp con el número visible) y {address} (ADDRESS de config; sin ella, ph('Business address')).
 * Las partes separadas por " · " que quedan vacías (p. ej. la dirección en producción) se descartan, así la línea de contacto nunca muestra separadores huérfanos.
 */
$legalText = static function (string $s) use ($email): string {
    $addr = org_address();
    $addrText = $addr ? implode(', ', array_filter([$addr['street'] ?? '', trim(($addr['city'] ?? '') . (isset($addr['city'], $addr['region']) ? ', ' : '') . ($addr['region'] ?? '') . (isset($addr['postal']) ? ' ' . $addr['postal'] : '')), $addr['country'] ?? ''], static fn(string $x): bool => $x !== '')) : '';
    $pieces = [];
    foreach (explode(' · ', $s) as $part) {
        if (trim($part) === '{address}') {
            $html = $addrText !== '' ? e($addrText) : ph('Business address');
        } else {
            $html = '';
            foreach (preg_split('/(\{email\}|\{phone\})/', $part, -1, PREG_SPLIT_DELIM_CAPTURE) as $seg) {
                $html .= match ($seg) {
                    '{email}' => '<a href="mailto:' . e($email) . '">' . e($email) . '</a>',
                    '{phone}' => 'WhatsApp <a href="' . e(wa('default')) . '" rel="noopener">' . e((string) cfg('phone')) . '</a>',
                    default   => phtext($seg),
                };
            }
        }
        if (trim(strip_tags($html)) !== '') {
            $pieces[] = $html;
        }
    }
    return implode(' · ', $pieces);
};

// Secciones: en producción solo las que tienen texto (las pendientes del abogado salen como {{ph:…}} y quedan vacías: no se imprime ni su H2 ni su entrada del índice).
$sections = [];
foreach ($doc['sections'] as $sec) {
    $html = $legalText((string) $sec[2]);
    if (is_production() && trim(strip_tags($html)) === '') {
        continue;
    }
    $sections[] = [$sec[0], $sec[1], $html];
}
// Fecha: "Last updated <fecha real>". Sin fecha real no se muestra (producción); en staging queda el hueco marcado.
$updated = (string) ($doc['updated'] ?? '');
$updatedHtml = $updated !== '' && !has_ph($updated) ? $updated : (is_production() ? '' : $updated);

partial('hero', [
    'route'   => $route,
    'h1'      => $doc['h1'],
    'size'    => 'h1-long',
    'eyebrow' => $updatedHtml,
    'lead'    => $doc['lead'],
    'cls'     => 'co-hero co-legal-hero',
]);
?>
<section class="co-legal" aria-label="<?= e($route['h1']) ?> text">
  <div class="container co-legal__in">
    <aside class="co-legal__aside">
      <nav class="co-toc" aria-label="On this page">
        <span class="co-toc__label t-meta">Sections</span>
        <?php foreach ($sections as $s): ?>
        <a href="#<?= e($s[0]) ?>"><?= e($s[1]) ?></a>
        <?php endforeach; ?>
      </nav>
    </aside>
    <article class="co-prose">
      <?php if (!is_production()): ?><div class="co-notice"><strong>Draft for review.</strong> Final text to be approved by NorthStar&#39;s legal counsel before publishing.</div><?php endif; ?>
      <?php foreach ($sections as $s): $body = $s[2]; ?>
      <h2 class="t-prose-h2" id="<?= e($s[0]) ?>"><?= e($s[1]) ?></h2>
      <?php if (trim(strip_tags($body)) !== ''): ?><p><?= $body ?></p><?php endif; ?>
      <?php endforeach; ?>
      <p class="co-prose__more"><a href="<?= e(url($doc['other'][1])) ?>"><?= e($doc['other'][0]) ?> <span class="arrow" aria-hidden="true">→</span></a></p>
    </article>
  </div>
</section>
