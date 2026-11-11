<?php
/**
 * Cabecera de sección: H2 (+ lead). Sin etiqueta («eyebrow») encima: si el título se entiende solo, la etiqueta sobra (fase AF). Marcas en textos: {hl}…{/hl}, {br}, {{ph:X}}.
 * Params: title(string, requerido), lead(string|null), tag('h2' defecto|'h1'|'h3'),
 *         size('h2' defecto|'h2-s'), theme('light' defecto|'dark'), split(bool: lead a la derecha en escritorio),
 *         id(string|null para aria-labelledby/ancla), cls(string),
 *         count(int|null: variante "índice" en escritorio, cuenta "(05)" en columna estrecha a la izquierda y el H2 en la ancha; en móvil se apila.
 *               La cuenta es decorativa (aria-hidden), va fuera del H2 y SOLO se pasa con un número real de la propia página, p. ej. count($items). No combina con split).
 */
require_once __DIR__ . '/kit.php';
$tag = in_array($tag ?? 'h2', ['h1', 'h2', 'h3'], true) ? ($tag ?? 'h2') : 'h2';
$size = ($size ?? 'h2') === 'h2-s' ? 't-h2-s' : 't-h2';
$dark = ($theme ?? 'light') === 'dark';
$count = (int) ($count ?? 0);
$idx = $count > 0 && $count < 100 && empty($split);
$split = !empty($split) && !empty($lead);
$cls = 'section-head' . ($dark ? ' section-head--dark' : '') . ($split ? ' section-head--split' : '') . ($idx ? ' section-head--idx' : '') . (!empty($cls) ? ' ' . $cls : '');
?>
<div class="<?= e($cls) ?>">
  <?php if ($idx): ?>
  <div class="section-head__side">
    <span class="section-head__count" aria-hidden="true">(<?= e(sprintf('%02d', $count)) ?>)</span>
  </div>
  <div class="section-head__body">
    <<?= $tag ?> class="<?= $size ?>"<?= !empty($id) ? ' id="' . e($id) . '"' : '' ?>><?= ns_rich($title) ?></<?= $tag ?>>
    <?php if (!empty($lead)): ?><p class="t-body c-slate"><?= ns_rich($lead) ?></p><?php endif; ?>
  </div>
</div>
<?php return; endif; ?>
  <?php if ($split): ?><div class="section-head__main"><?php endif; ?>
  <<?= $tag ?> class="<?= $size ?>"<?= !empty($id) ? ' id="' . e($id) . '"' : '' ?>><?= ns_rich($title) ?></<?= $tag ?>>
  <?php if ($split): ?></div><p class="section-head__lead t-body"><?= ns_rich($lead) ?></p>
  <?php elseif (!empty($lead)): ?><p class="t-body c-slate"><?= ns_rich($lead) ?></p><?php endif; ?>
</div>
