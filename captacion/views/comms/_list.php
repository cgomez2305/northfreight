<?php
use Captacion\Services\Fmt;
use Captacion\Services\Templates;
use Captacion\Ui;

/** Variables: $templates (con pending) */
?>
<section class="card card--table" aria-labelledby="t-l">
  <header class="card__h"><h2 class="label" id="t-l">PLANTILLAS</h2><span class="muted"><?= $e(count($templates)) ?></span></header>
  <div class="tw"><table class="tbl tbl--cm">
    <thead><tr><th scope="col">Nombre</th><th scope="col">Clave</th><th scope="col">Idioma</th><th scope="col">Estado</th><th scope="col">Editada</th><th scope="col"><span class="sr">Abrir</span></th></tr></thead>
    <tbody>
      <?php foreach ($templates as $t): ?>
        <tr><th scope="row" class="c-n"><a href="/comunicaciones/plantillas/<?= $e($t['id']) ?>"><?= $e($t['name']) ?></a><?= $t['kind'] === 'system' ? ' <span class="chip chip--plain">De fábrica</span>' : '' ?></th>
          <td class="mono" data-l="Clave"><?= $e($t['tkey']) ?></td><td data-l="Idioma"><?= $e(Templates::LANGS[$t['lang']] ?? $t['lang']) ?></td>
          <td data-l="Estado"><?php if ($t['pending']): ?><span class="chip chip--hot"><i class="dot"></i>Texto pendiente de confirmar</span><?php elseif ((int) $t['active'] !== 1): ?><span class="chip chip--plain"><i class="dot"></i>Desactivada</span><?php else: ?><span class="chip chip--warm"><i class="dot"></i>Lista para enviar</span><?php endif; ?></td>
          <td class="c-when" data-l="Editada"><?= $e(Fmt::ago($t['updated_at'])) ?></td><td class="c-act"><a class="btn btn--sm btn--secondary" href="/comunicaciones/plantillas/<?= $e($t['id']) ?>">Editar</a></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="foot-note cm-note">Una plantilla con texto <code>[[CONFIRMAR: …]]</code> sin resolver <b>no se envía</b> a ningún lead: la secuencia espera y avisa. Reemplaza el marcador por texto real y aprobado antes de encender las secuencias.</p>
</section>
