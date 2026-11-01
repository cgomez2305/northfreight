<?php
/**
 * Cabecera de sección: eyebrow + H2 (+ lead). Marcas en textos: {hl}…{/hl}, {br}, {{ph:X}}.
 * Params: title(string, requerido), eyebrow(string|null), lead(string|null), tag('h2' defecto|'h1'|'h3'),
 *         size('h2' defecto|'h2-s'), theme('light' defecto|'dark'), split(bool: lead a la derecha en escritorio),
 *         swoosh(bool), id(string|null para aria-labelledby/ancla), cls(string).
 */
require_once __DIR__ . '/kit.php';
$tag = in_array($tag ?? 'h2', ['h1', 'h2', 'h3'], true) ? ($tag ?? 'h2') : 'h2';
$size = ($size ?? 'h2') === 'h2-s' ? 't-h2-s' : 't-h2';
$dark = ($theme ?? 'light') === 'dark';
$split = !empty($split) && !empty($lead);
$cls = 'section-head' . ($dark ? ' section-head--dark' : '') . ($split ? ' section-head--split' : '') . (!empty($cls) ? ' ' . $cls : '');
?>
<div class="<?= e($cls) ?>">
  <?php if ($split): ?><div class="section-head__main"><?php endif; ?>
  <?php if (!empty($eyebrow)): ?><span class="t-eyebrow"><?= ns_rich($eyebrow) ?></span><?php endif; ?>
  <<?= $tag ?> class="<?= $size ?>"<?= !empty($id) ? ' id="' . e($id) . '"' : '' ?>><?= ns_rich($title) ?></<?= $tag ?>>
  <?php if (!empty($swoosh)): ?><?= ns_swoosh(160, 12) ?><?php endif; ?>
  <?php if ($split): ?></div><p class="section-head__lead t-body"><?= ns_rich($lead) ?></p>
  <?php elseif (!empty($lead)): ?><p class="t-body c-slate"><?= ns_rich($lead) ?></p><?php endif; ?>
</div>
