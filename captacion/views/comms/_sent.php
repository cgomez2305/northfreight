<?php
use Captacion\Services\Fmt;
use Captacion\Ui;

/** Variables: $rows */
$st = ['sent' => ['Enviado', 'warm'], 'logged' => ['Solo registrado (sin SMTP)', 'plain'], 'failed' => ['Falló', 'hot']];
?>
<section class="card card--table" aria-labelledby="sn-t">
  <header class="card__h"><h2 class="label" id="sn-t">Últimos correos a leads</h2><span class="muted"><?= $e(count($rows)) ?></span></header>
  <?php if (!$rows): ?>
    <div class="empty empty--sm"><?= Ui::icon('star', 'empty__star ic--star') ?><h3>Aún no se ha enviado ningún correo</h3><p>Los correos de las secuencias aparecerán aquí con su estado. «Solo registrado» significa que no hay SMTP y nadie lo recibió.</p></div>
  <?php else: ?>
    <div class="tw"><table class="tbl tbl--cm"><thead><tr><th scope="col">Lead</th><th scope="col">Plantilla</th><th scope="col">Asunto</th><th scope="col">Estado</th><th scope="col">Fecha</th></tr></thead><tbody>
      <?php foreach ($rows as $r): [$lbl, $cls] = $st[$r['status']] ?? [(string) $r['status'], 'plain']; ?>
        <tr><th scope="row" class="c-n"><a href="/leads/<?= $e($r['lead_id']) ?>"><?= $e(Fmt::name($r)) ?></a></th><td class="mono" data-l="Plantilla"><?= $e($r['template_key']) ?></td><td data-l="Asunto"><?= $e($r['subject']) ?></td>
          <td data-l="Estado"><span class="chip chip--<?= $e($cls) ?>"><i class="dot"></i><?= $e($lbl) ?></span></td><td class="c-when" data-l="Fecha"><?= $e(Fmt::dateTime($r['created_at'])) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>
