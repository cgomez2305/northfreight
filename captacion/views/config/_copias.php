<?php
use Captacion\Services\Fmt;
use Captacion\Ui;

/** Variables: $backups, $health, $queued, $retentionDays, $dirName, $locked, $csrf */
$lvl = ['ok' => ['ok', 'check-circle'], 'warn' => ['warn', 'alert'], 'bad' => ['bad', 'alert']];
[$cls, $ic] = $lvl[$health['level']];
$size = static fn(?int $b): string => $b === null ? '—' : ($b >= 1048576 ? Fmt::num($b / 1048576, 1) . ' MB' : Fmt::num(max(1, $b / 1024), 0) . ' KB');
$stLabel = ['ok' => 'Correcta', 'failed' => 'Falló', 'running' => 'En curso'];
?>
<p class="cfg-intro">Copia diaria de TODA la base del CRM (leads, notas, tareas, consentimientos, configuración…), hecha por el servidor a las 3:30 a. m. de Nueva York, verificada y guardada <?= $e($retentionDays) ?> días fuera de la carpeta pública. Contiene datos personales: descárgala solo con confirmación de identidad y guárdala en un lugar privado.</p>

<section class="card cfg-card" aria-labelledby="bk-s">
  <header class="card__h"><h2 class="label" id="bk-s">ESTADO</h2><span class="stat stat--<?= $e($cls) ?>"><?= Ui::icon($ic) ?><?= $e($health['level'] === 'ok' ? 'Al día' : ($health['failed'] ? 'La última falló' : 'Requiere atención')) ?></span></header>
  <div class="card__b bk-s">
    <p><?= $e($health['msg']) ?></p>
    <form method="post" action="/config/backups/run" class="bk-run">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn btn--secondary" type="submit"<?= $queued ? ' disabled' : '' ?>><?= Ui::icon('database') ?><?= $queued ? 'Copia en cola…' : 'Hacer copia ahora' ?></button>
    </form>
    <p class="muted">La copia manual corre por la cola (cron de cada minuto) y aparece abajo en unos instantes. Sin <code>mysqldump</code>: el volcado es de PHP puro y se verifica (tablas, filas y que el .gz se descomprime) antes de darlo por bueno.</p>
    <p class="intg__h2"><b>Copia fuera de Hostinger (recomendado).</b> Si el servidor se pierde, las copias que están en él se pierden también. Descarga una copia cada semana o mes y guárdala en un disco o gestor de contraseñas/archivos cifrado tuyo. No la subas a servicios de terceros sin cifrarla. Cómo restaurar: README del CRM, §16.</p>
  </div>
</section>

<section class="card cfg-card" aria-labelledby="bk-l">
  <header class="card__h"><h2 class="label" id="bk-l">COPIAS</h2><span class="muted"><?= $e(count($backups)) ?> registro<?= count($backups) === 1 ? '' : 's' ?> · carpeta <code><?= $e($dirName) ?></code></span></header>
  <?php if (!$backups): ?>
    <div class="empty empty--sm"><?= Ui::icon('star', 'empty__star ic--star') ?><h3>Aún no hay copias</h3><p>La primera se hará esta noche a las 3:30 a. m. (hora de Nueva York) o ahora mismo con «Hacer copia ahora». Si el cron de copias no está creado, mira el README §11.</p></div>
  <?php else: ?>
    <div class="tw"><table class="tbl tbl--bk">
      <thead><tr><th scope="col">Fecha</th><th scope="col">Archivo</th><th scope="col" class="num-c">Tamaño</th><th scope="col" class="num-c">Tablas</th><th scope="col" class="num-c">Filas</th><th scope="col">Estado</th><th scope="col"><span class="sr">Acciones</span></th></tr></thead>
      <tbody>
        <?php foreach ($backups as $b): $bcls = $b['status'] === 'ok' ? 'ok' : ($b['status'] === 'failed' ? 'bad' : 'warn'); ?>
          <tr>
            <th scope="row" class="c-n"><?= $e(Fmt::dateTime((string) $b['started_at'])) ?><small><?= $e($b['trigger_type'] === 'cron' ? 'Automática' : 'Manual') ?></small></th>
            <td data-l="Archivo" class="mono bk-f"><?= $e($b['filename'] ?? '—') ?></td>
            <td class="num-c" data-l="Tamaño"><?= $e($size($b['size_bytes'] !== null ? (int) $b['size_bytes'] : null)) ?></td>
            <td class="num-c" data-l="Tablas"><?= $e($b['tables_count'] ?? '—') ?></td>
            <td class="num-c" data-l="Filas"><?= $e($b['rows_count'] !== null ? Fmt::num((int) $b['rows_count']) : '—') ?></td>
            <td data-l="Estado"><span class="stat stat--<?= $e($bcls) ?>"><?= $e($stLabel[$b['status']] ?? $b['status']) ?></span><?php if ($b['error']): ?><small class="bk-err"><?= $e(mb_substr((string) $b['error'], 0, 160)) ?></small><?php endif; ?></td>
            <td class="c-act">
              <?php if ($b['status'] === 'ok' && $b['exists']): ?>
                <form method="post" action="/config/backups/download"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="id" value="<?= $e($b['id']) ?>">
                  <button class="btn btn--secondary btn--sm" type="submit"<?= $locked ? ' disabled title="Confirma tu identidad arriba para descargar"' : '' ?>><?= Ui::icon('download') ?>Descargar</button></form>
              <?php elseif ($b['status'] === 'ok'): ?><span class="muted">Borrada del servidor</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>
