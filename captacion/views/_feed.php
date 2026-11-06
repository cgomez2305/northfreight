<?php
use Captacion\Services\Fmt;
use Captacion\Ui;

/** Grupos de días de la cronología global (Q5c-D). Variable: $days (LeadFeed::page()['days']). Cada tarjeta es compacta y abre el vistazo del lead al hacer clic (data-peek). */
foreach ($days as $g): ?>
<section class="feed__g" data-day="<?= $e($g['key']) ?>" aria-label="<?= $e($g['label']) ?>">
  <h3 class="feed__d"><span><?= $e($g['label']) ?></span><small><?= $e(count($g['items'])) ?></small></h3>
  <ol class="feed__l">
    <?php foreach ($g['items'] as $it): ?>
    <li class="fe fe--<?= $e($it['tone']) ?>" data-peek="<?= $e($it['lead_id']) ?>">
      <span class="fe__ic" aria-hidden="true"><?= Ui::icon($it['icon']) ?></span>
      <div class="fe__b">
        <p class="fe__t"><?= $e($it['title']) ?></p>
        <p class="fe__w"><a class="fe__lead" href="/leads/<?= $e($it['lead_id']) ?>" data-peek-link><?= $e($it['lead']) ?></a><span class="mono"><?= $e($it['code']) ?></span><?php if ($it['by'] !== null): ?><span>por <?= $e($it['by']) ?></span><?php endif; ?><a class="fe__only" href="/cronologia?ld=<?= $e($it['lead_id']) ?>">Solo este lead</a></p>
        <?php if ($it['detail'] !== ''): ?><p class="fe__x"><?= $e(mb_strlen($it['detail']) > 150 ? mb_substr($it['detail'], 0, 149) . '…' : $it['detail']) ?></p><?php endif; ?>
      </div>
      <time class="fe__h mono" datetime="<?= $e(str_replace(' ', 'T', $it['raw_at']) . 'Z') ?>" title="<?= $e(Fmt::dateTime($it['raw_at'])) ?>"><?= $e(Fmt::et($it['raw_at'])->format('H:i')) ?></time>
    </li>
    <?php endforeach; ?>
  </ol>
</section>
<?php endforeach; ?>
