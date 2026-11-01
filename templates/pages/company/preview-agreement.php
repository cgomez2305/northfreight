<?php
/** Vista previa del agreement dibujada con HTML/CSS (Doc Agreement de la maqueta). Decorativa: role="img" con etiqueta. */
$sections = [['1. SERVICES', 'co-w-70'], ['2. CARRIER RIGHTS', 'co-w-85'], ['3. DISPATCH FEE', 'co-w-55'], ['4. PAYMENT TERMS', 'co-w-64'], ['5. TERMINATION', 'co-w-40']];
?>
<div class="co-paper" role="img" aria-label="Preview of the dispatch service agreement template: services, carrier rights, dispatch fee, payment terms, termination and two signature boxes">
  <div class="co-paper__head">
    <span class="co-paper__title head">Dispatch service agreement</span>
    <span class="co-paper__meta mono">TEMPLATE<br>REVIEW BEFORE SIGNING</span>
  </div>
  <?php foreach ($sections as $s): ?>
  <div class="co-paper__sec <?= e($s[1]) ?>"><span class="co-paper__lab mono"><?= e($s[0]) ?></span><span class="co-paper__line"></span><span class="co-paper__line"></span></div>
  <?php endforeach; ?>
  <div class="co-paper__grid2 co-paper__grid2--lg">
    <div class="co-paper__box"><span class="co-paper__lab mono">CARRIER · SIGN</span><span class="co-paper__line"></span></div>
    <div class="co-paper__box"><span class="co-paper__lab mono">DISPATCHER · SIGN</span><span class="co-paper__line"></span></div>
  </div>
</div>
