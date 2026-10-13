<div class="cabeza">
  <div>
    <h1>Programas</h1>
    <p class="sub">Cada programa instalado está conectado a este panel con su propio ID y su propio secreto.</p>
  </div>
  <?php if (puede('programas')): ?>
  <div class="acciones">
    <a class="btn btn-primario" href="<?= e(url('programa_nuevo')) ?>"><?= icono('mas') ?> Agregar programa</a>
  </div>
  <?php endif; ?>
</div>

<?php if (!$tablero): ?>
  <div class="tarjeta">
    <h2>Todavía no hay programas</h2>
    <p class="suave">Agrega el primero: elige qué programa es, escribe su dirección y el panel te dará un comando para pegar en su servidor. <a href="<?= e(url('ayuda')) ?>">Ver la ayuda paso a paso</a>.</p>
  </div>
<?php else: ?>
  <div class="tarjeta">
    <div class="tabla-env">
      <table class="tabla apilable">
        <thead><tr><th>Programa</th><th>ID</th><th>Estado</th><th>Versión</th><th>7 días</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($tablero as $f): $tp = \Tukan\Productos::tipo($f['producto']); $dp = $disp[$f['id']];
              $on = (int) $f['activo'] && $f['consultado'] !== null && (int) $f['en_linea']; ?>
          <tr class="p-<?= e($f['producto']) ?>">
            <td>
              <div class="prog-top">
                <span class="ico chico" aria-hidden="true"><?= e($tp['letra']) ?></span>
                <div class="prog-tit"><a href="<?= e(url('programa', ['id' => $f['id']])) ?>"><strong><?= e($f['nombre']) ?></strong></a>
                  <span class="url"><?= e($tp['nombre']) ?> · <?= e(host($f['url'])) ?></span></div>
              </div>
            </td>
            <td data-t="ID"><span class="mono"><?= e($f['id']) ?></span></td>
            <td data-t="Estado">
              <?php if (!(int) $f['activo']): ?><span class="etiqueta">Pausado</span>
              <?php elseif ($f['consultado'] === null): ?><span class="etiqueta">Sin revisar</span>
              <?php elseif ($on): ?><span class="etiqueta ok">En línea · <?= (int) $f['ms'] ?> ms</span>
              <?php else: ?><span class="etiqueta mal" title="<?= e($f['error_detalle']) ?>">Sin conexión</span><?php endif; ?>
            </td>
            <td data-t="Versión"><span class="mono"><?= e($f['datos']['version'] ?? '—') ?></span></td>
            <td data-t="7 días"><?= $dp['pct'] === null ? '—' : e(str_replace('.', ',', (string) $dp['pct'])) . ' %' ?></td>
            <td class="nowrap"><a class="btn btn-chico" href="<?= e(url('programa', ['id' => $f['id']])) ?>">Abrir ficha</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
