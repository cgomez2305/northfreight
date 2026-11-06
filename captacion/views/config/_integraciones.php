<?php
use Captacion\Services\Fmt;
use Captacion\Ui;

$lvl = ['ok' => ['ok', 'check-circle'], 'warn' => ['warn', 'alert'], 'bad' => ['bad', 'alert'], 'off' => ['off', 'minus']];
?>
<p class="cfg-intro">Estado en vivo de lo que usa el CRM. «Probar» hace un uso real (envía un mensaje, consulta un USDOT…). Nunca se muestran llaves ni contraseñas, solo si están configuradas y sus últimos 4 caracteres.</p>
<div class="intg">
  <?php foreach ($cards as $c): [$cls, $ic] = $lvl[$c['level']]; $res = $testResult !== null && $testResult['name'] === $c['key'] ? $testResult : null; ?>
    <section class="card intg__c" id="int-<?= $e($c['key']) ?>" aria-labelledby="ih-<?= $e($c['key']) ?>">
      <header class="intg__h"><h2 class="intg__t" id="ih-<?= $e($c['key']) ?>"><?= $e($c['title']) ?></h2><span class="stat stat--<?= $e($cls) ?>"><?= Ui::icon($ic) ?><?= $e($c['headline']) ?></span></header>
      <?php if (!empty($c['webhook'])): ?>
        <div class="intg__u2"><span class="intg__k">URL del webhook</span><span class="rlink"><input class="mono" type="text" readonly value="<?= $e($c['url']) ?>" aria-label="URL del webhook de <?= $e($c['title']) ?>"><button class="btn btn--secondary btn--sm" type="button" data-copy="<?= $e($c['url']) ?>"><?= Ui::icon('link') ?>Copiar</button></span></div>
      <?php endif; ?>
      <dl class="intg__d"><?php foreach ($c['details'] as [$k, $v]): ?><div><dt><?= $e($k) ?></dt><dd><?= $e($v) ?></dd></div><?php endforeach; ?></dl>
      <?php if ($c['last'] !== ''): ?><p class="intg__l"><?= Ui::icon('clock') ?><?= $e($c['last']) ?></p><?php endif; ?>
      <?php if ($c['error'] !== ''): ?><p class="intg__x"><?= Ui::icon('alert') ?>Último error: <?= $e($c['error']) ?></p><?php endif; ?>
      <?php if ($c['help'] !== ''): ?><p class="intg__h2"><?= $e($c['help']) ?></p><?php endif; ?>
      <?php if ($res !== null): ?><p class="flash flash--<?= $res['level'] === 'ok' ? 'ok' : ($res['level'] === 'warn' ? 'warn' : 'bad') ?> intg__r" role="status"><?= Ui::icon($res['level'] === 'ok' ? 'check-circle' : 'alert') ?><span><b>Prueba · <?= $e(Fmt::dateTime($res['at'], false)) ?>:</b> <?= $e($res['msg']) ?></span></p><?php endif; ?>
      <?php if (!empty($c['link'])): ?><p class="intg__lnk"><a href="<?= $e($c['link']) ?>">Ajustes de este canal<?= Ui::icon('arrow-right') ?></a></p><?php endif; ?>
      <?php if ($c['testable']): ?>
        <form class="intg__f" method="post" action="/config/integrations/test">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="name" value="<?= $e($c['key']) ?>">
          <?php if ($c['key'] === 'fmcsa'): ?><label class="field intg__u"><span>USDOT de prueba</span><input type="text" name="usdot" value="<?= $e($testUsdot) ?>" inputmode="numeric" maxlength="8" pattern="[0-9]{1,8}" autocomplete="off"></label><?php endif; ?>
          <button class="btn btn--secondary btn--sm" type="submit"><?= Ui::icon($c['key'] === 'telegram' ? 'send' : 'play') ?>Probar</button>
        </form>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
</div>
