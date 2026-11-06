<?php
use Captacion\Ui;
use Captacion\Services\Fmt;
use Captacion\Services\JobPostings;

/** Variables: $jobs, $pub, $siteUrl, $today, $siteFile, $csrf */
$chip = ['active' => 'ok', 'paused' => 'warm', 'draft' => 'plain', 'closed' => 'dq'];
$day = static fn(?string $d): string => $d === null ? '—' : (new DateTimeImmutable($d))->format('j M Y');
$nActive = count(array_filter($jobs, static fn($j) => $j['status'] === 'active' && !JobPostings::expired($j)));
?>
<div class="page-head">
  <div>
    <h1 class="h1">Vacantes</h1>
    <p class="sub">Las vacantes de company drivers que se publican en el sitio, con su página y su <code>JobPosting</code> para Google. Solo vacantes reales.</p>
  </div>
  <div class="page-head__a">
    <a class="btn btn--primary" href="/vacantes/new"><span class="btn__t">Nueva vacante</span></a>
  </div>
</div>

<p class="pstat vstat" role="status">
  <span class="dot<?= $nActive > 0 ? ' is-ok' : ' dot--warn' ?>"></span>
  <span><?= $nActive > 0 ? $e($nActive) . ($nActive === 1 ? ' vacante publicada' : ' vacantes publicadas') . ' en el sitio' : 'No hay vacantes publicadas: <code>/careers/cdl-a-drivers/</code> responde 404 y el formulario no ofrece «company driver»' ?>.
  <?php if ($pub): ?>Último envío al sitio <?= $e(Fmt::ago((string) $pub['at'])) ?> (<?= $e((int) $pub['count']) ?> en <code><?= $e($siteFile) ?></code>).<?php endif; ?></span>
</p>
<p class="vnote muted">El sitio guarda el HTML en caché hasta 5 minutos: un cambio puede tardar ese tiempo en verse. Al vencer su fecha, una vacante deja de existir sola (404) y sale del sitemap.</p>

<?php if (!$jobs): ?>
<section class="card empty empty--lg">
  <svg class="empty__star empty__star--lg" aria-hidden="true"><use href="#i-star"/></svg>
  <h2 class="h2">Todavía no hay vacantes</h2>
  <p>Mientras no haya ninguna publicada, el hub de Careers dice con honestidad que no hay puestos abiertos. No se inventan vacantes: cada una crea una página pública.</p>
  <ol class="steps">
    <li><span class="steps__n">1</span><div><b>Escribe la vacante</b><p>Título, ubicación, resumen, requisitos y, solo si está confirmado, el pago. Queda como borrador.</p></div></li>
    <li><span class="steps__n">2</span><div><b>Publícala</b><p>El panel escribe <code><?= $e($siteFile) ?></code> y el sitio crea <code>/careers/cdl-a-drivers/&lt;slug&gt;/</code>, su sitemap y su schema.</p></div></li>
    <li><span class="steps__n">3</span><div><b>Pausa o cierra</b><p>La página desaparece (404) y sale del sitemap.</p></div></li>
  </ol>
  <a class="btn btn--secondary" href="/vacantes/new"><span class="btn__t">Crear la primera vacante</span></a>
</section>
<?php else: ?>
<section class="card">
  <ul class="vrows">
    <?php foreach ($jobs as $j): $exp = JobPostings::expired($j); $st = $exp ? 'Vencida' : (JobPostings::STATUSES[$j['status']] ?? $j['status']); ?>
    <li class="vrow">
      <div class="vrow__w">
        <a class="vrow__t" href="/vacantes/<?= $e($j['id']) ?>"><?= $e($j['title']) ?></a>
        <small class="mono"><?= $e('/careers/cdl-a-drivers/' . $j['slug'] . '/') ?></small>
        <small><?= $e($j['location'] ?? '—') ?> · <?= $e(JobPostings::TYPES[$j['employment_type']] ?? '') ?> · publicación <?= $e($day($j['posted_on'])) ?> · vence <?= $e($day($j['valid_through'])) ?></small>
      </div>
      <div class="vrow__s"><span class="chip chip--<?= $exp ? 'bad' : $e($chip[$j['status']] ?? 'plain') ?>"><?php if ($j['status'] !== 'draft'): ?><i class="dot"></i><?php endif; ?><?= $e($st) ?></span></div>
      <div class="vrow__a">
        <a class="btn btn--secondary btn--sm" href="/vacantes/<?= $e($j['id']) ?>"><?= Ui::icon('pencil') ?>Editar</a>
        <?php if ($j['status'] === 'active' && !$exp): ?><a class="btn btn--ghost btn--sm" href="<?= $e($siteUrl . '/careers/cdl-a-drivers/' . $j['slug'] . '/') ?>" rel="noopener" target="_blank">Ver en el sitio</a><?php endif; ?>
        <?php foreach ([['publish', 'Publicar', in_array($j['status'], ['draft', 'paused'], true)], ['pause', 'Pausar', $j['status'] === 'active'], ['close', 'Cerrar', $j['status'] !== 'closed' && $j['status'] !== 'draft'], ['reopen', 'Reabrir', $j['status'] === 'closed']] as [$op, $label, $show]): if (!$show) { continue; } ?>
          <form method="post" action="/vacantes/<?= $e($j['id']) ?>/status"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="op" value="<?= $e($op) ?>"><input type="hidden" name="from" value="list">
            <button class="btn btn--<?= $op === 'publish' ? 'secondary' : 'ghost' ?> btn--sm" type="submit"<?= in_array($op, ['pause', 'close'], true) ? ' data-confirm="' . $e($op === 'pause' ? '¿Pausar «' . $j['title'] . '»? Su página en el sitio dejará de existir (404) hasta que la publiques otra vez.' : '¿Cerrar «' . $j['title'] . '»? Su página en el sitio dejará de existir (404).') . '"' : '' ?>><?= $e($label) ?></button></form>
        <?php endforeach; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
