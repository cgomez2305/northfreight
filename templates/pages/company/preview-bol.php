<?php
/** Vista previa del BOL dibujada con HTML/CSS (Doc BOL de la maqueta). Decorativa: role="img" con etiqueta. */
$cell = static function (string $label, string $extra = ''): void { ?>
<div class="co-paper__box"><span class="co-paper__lab mono"><?= e($label) ?></span><span class="co-paper__line"></span><?= $extra ?></div>
<?php };
?>
<div class="co-paper" role="img" aria-label="Preview of the straight bill of lading template: shipper, consignee, carrier, freight table and three signature boxes">
  <div class="co-paper__head">
    <span class="co-paper__title head">Straight bill of lading</span>
    <span class="co-paper__meta mono">NOT NEGOTIABLE<br>PAGE 1 OF 1</span>
  </div>
  <div class="co-paper__grid2">
    <?php $cell('SHIP FROM', '<span class="co-paper__line co-paper__line--68"></span>'); ?>
    <?php $cell('SHIP TO', '<span class="co-paper__line co-paper__line--68"></span>'); ?>
    <?php $cell('CARRIER · MC#'); ?>
    <?php $cell('BOL # · PRO #'); ?>
  </div>
  <div class="co-paper__table">
    <div class="co-paper__cols mono"><span>QTY</span><span>DESCRIPTION</span><span>WEIGHT</span><span>CLASS</span></div>
    <div class="co-paper__row"><span></span><span class="co-wd-80"></span><span></span><span></span></div>
    <div class="co-paper__row"><span></span><span class="co-wd-62"></span><span></span><span></span></div>
    <div class="co-paper__row"><span></span><span class="co-wd-70"></span><span></span><span></span></div>
  </div>
  <div class="co-paper__grid3">
    <?php $cell('SHIPPER SIGN'); ?>
    <?php $cell('DRIVER SIGN'); ?>
    <?php $cell('RECEIVER SIGN'); ?>
  </div>
</div>
