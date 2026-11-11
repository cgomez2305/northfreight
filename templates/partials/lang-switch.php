<?php
/**
 * Selector de idioma (fase AE): enlace a la misma página en el otro idioma (o a la home del otro idioma si la página no tiene traducción).
 * Params: route(array|null), variant('nav'|'menu'|'foot'). El texto es solo la sigla (EN / ES); el enlace lleva lang y hreflang del destino y un nombre accesible en ese idioma.
 */
$route = $route ?? null;
$variant = in_array($variant ?? 'nav', ['nav', 'menu', 'foot'], true) ? ($variant ?? 'nav') : 'nav';
$sw = ns_switch_target($route);
$cur = $sw['lang'] === 'es' ? 'en' : 'es';   // idioma de la página actual
?>
<a data-i18n-skip class="lang-switch lang-switch--<?= e($variant) ?>" href="<?= e($sw['href']) ?>" hreflang="<?= e($sw['hreflang']) ?>" lang="<?= e($sw['lang']) ?>" aria-label="<?= e($sw['lang'] === 'es' ? 'Ver esta página en español' : 'View this page in English') ?>">
<?php if ($variant === 'nav'): ?><?= ns_icon('globe', 18, 'lang-switch__globe') ?><span class="ls-opt<?= $cur === 'en' ? ' is-on' : '' ?>" aria-hidden="true">EN</span><span class="ls-opt<?= $cur === 'es' ? ' is-on' : '' ?>" aria-hidden="true">ES</span><?php else: ?><span aria-hidden="true"><?= e($sw['label']) ?></span><span class="lang-switch__name"><?= e($sw['name']) ?></span><?php endif; ?>
</a>
