<?php
/** Diferencia legible entre dos versiones. Variables: $diff (RuleSets::diff) */
$lab = ['added' => 'Añadida', 'removed' => 'Quitada', 'changed' => 'Cambió', 'moved' => 'Movida'];
?>
<ul class="rdiff">
  <?php foreach ($diff as $d): ?>
  <li class="rdiff__i rdiff__i--<?= $e($d['type']) ?>"><span class="rdiff__t"><b><?= $e($lab[$d['type']] ?? $d['type']) ?></b> «<?= $e($d['name']) ?>»</span>
    <?php foreach ($d['changes'] as $c): ?><p class="rdiff__c"><?php if ($d['type'] === 'added'): ?><ins><?= $e($c['to']) ?></ins><?php elseif ($d['type'] === 'removed'): ?><del><?= $e($c['from']) ?></del><?php else: ?><span class="rdiff__f"><?= $e($c['field']) ?></span><del><?= $e($c['from'] !== '' ? $c['from'] : '(vacío)') ?></del><ins><?= $e($c['to'] !== '' ? $c['to'] : '(vacío)') ?></ins><?php endif; ?></p><?php endforeach; ?></li>
  <?php endforeach; ?>
</ul>
