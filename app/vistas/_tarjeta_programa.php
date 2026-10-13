<?php
/**
 * Tarjeta de un programa. Variables: $f (fila del tablero), $dp (disponibilidad)
 */
use Tukan\{Estado, Productos};

$tp = Productos::tipo($f["producto"]);
$d = $f['datos'];
$activo = (int) $f['activo'];
$consultado = $f['consultado'] !== null;
$enLinea = $activo && $consultado && (int) $f['en_linea'];
$viejo = $d && (!$enLinea || time() - (int) $f['datos_momento'] > 600);
// Cifras a mostrar: negocios (si el programa tiene) + los "extra"
$kv = [];
if ($d) {
    if (is_array($d['negocios'])) {
        $kv['Negocios'] = $d['negocios']['total'];
        $kv['En prueba'] = $d['negocios']['prueba'];
        $kv['Activos'] = $d['negocios']['activos'];
    }
    foreach ($d['extra'] as $k => $v) $kv[$k] = $v;
}
$kv = array_slice($kv, 0, 6, true);
?>
<article class="prog p-<?= e($f['producto']) ?><?= $activo ? '' : ' apagado' ?>" data-inst="<?= e($f['id']) ?>">
  <div class="prog-cuerpo">
    <div class="prog-top">
      <span class="ico" aria-hidden="true"><?= e($tp["letra"]) ?></span>
      <div class="prog-tit">
        <h3><a href="<?= e(url('programa', ['id' => $f['id']])) ?>"><?= e($f['nombre']) ?></a></h3>
        <span class="url"><?= e(host($f['url'])) ?></span>
      </div>
      <?php if (!$activo): ?>
        <span class="estado pausa">Pausado</span>
      <?php elseif (!$consultado): ?>
        <span class="estado">Sin revisar</span>
      <?php elseif ($enLinea): ?>
        <span class="estado on">En línea</span>
      <?php else: ?>
        <span class="estado off">Sin conexión</span>
      <?php endif; ?>
    </div>
    <div class="prog-meta">
      <span><?= e($tp["nombre"]) ?></span>
      <?php if ($d && $d['version']): ?><span class="mono">v<?= e($d['version']) ?></span><?php endif; ?>
      <?php if ($enLinea): ?><span><?= icono('rayo') ?><?= (int) $f['ms'] ?> ms</span><?php endif; ?>
      <?php if ($consultado): ?><span><?= icono('reloj') ?><?= e(hace((int) $f['consultado'])) ?></span><?php endif; ?>
    </div>
    <?php if ($d && $d['cliente']): ?>
      <div class="cliente">Cliente: <strong><?= e($d['cliente']) ?></strong></div>
    <?php endif; ?>
    <?php if ($activo && $consultado && !$enLinea): ?>
      <div class="fallo"><?= e(mb_strimwidth((string) $f['error_detalle'], 0, 150, '…')) ?></div>
    <?php elseif ($enLinea && $f['error_detalle']): ?>
      <div class="ojo"><?= e($f['error_detalle']) ?></div>
    <?php endif; ?>
    <?php if ($kv): ?>
      <dl class="kv<?= $viejo ? ' viejo' : '' ?>">
        <?php foreach ($kv as $k => $v): ?><div><dt title="<?= e($k) ?>"><?= e($k) ?></dt><dd><?= e(numero_corto($v)) ?></dd></div><?php endforeach; ?>
      </dl>
      <?php if ($viejo): ?><p class="chico suave">Últimos datos conocidos: <?= e(hace((int) $f['datos_momento'])) ?>.</p><?php endif; ?>
    <?php elseif ($activo && !$d): ?>
      <p class="chico suave"><?= $consultado ? 'Todavía no hay cifras de este programa.' : 'Toca "Actualizar" para traer sus cifras.' ?></p>
    <?php endif; ?>
    <?php if (!empty($lineaV2)): ?>
      <p class="linea-v2"><a href="<?= e(url('solicitudes', ['i' => $f['id']])) ?>"><?= $lineaV2['solicitudes'] ?> <?= $lineaV2['solicitudes'] === 1 ? 'solicitud' : 'solicitudes' ?></a> ·
        <a href="<?= e(url('clientes', ['i' => $f['id'], 'vence' => '7'])) ?>"><?= $lineaV2['vencen'] ?> <?= $lineaV2['vencen'] === 1 ? 'vence' : 'vencen' ?> esta semana</a></p>
    <?php elseif ($activo && isset($caps[$f['id']]) && $caps[$f['id']]['protocolo'] === 1): ?>
      <p class="etiqueta solo-v1">Solo cifras (actualiza el programa para ver solicitudes y clientes)</p>
    <?php endif; ?>
    <div class="disp" title="Disponibilidad de los últimos 7 días">
      <div class="barras" role="img" aria-label="Disponibilidad 7 días: <?= $dp['pct'] === null ? 'sin datos' : e(str_replace('.', ',', (string) $dp['pct'])) . ' %' ?>">
        <?php foreach ($dp['tramos'] as $tr): ?><i class="<?= e($tr['clase']) ?>"></i><?php endforeach; ?>
      </div>
      <small>7 días · <?= $dp['pct'] === null ? '—' : e(str_replace('.', ',', (string) $dp['pct'])) . ' %' ?></small>
    </div>
    <div class="prog-acc">
      <?php if ($activo && puede('entrar')): ?>
        <form method="post" action="<?= e(url('entrar')) ?>">
          <?= campo_csrf() ?><input type="hidden" name="id" value="<?= e($f['id']) ?>">
          <button class="btn btn-primario btn-grande btn-entrar" type="submit">Entrar a <?= e($f['nombre']) ?> <?= icono('flecha') ?></button>
        </form>
        <form method="post" action="<?= e(url('entrar')) ?>" target="_blank" rel="noopener noreferrer">
          <?= campo_csrf() ?><input type="hidden" name="id" value="<?= e($f['id']) ?>"><input type="hidden" name="nueva" value="1">
          <button class="btn btn-grande btn-icono" type="submit" title="Abrir en pestaña nueva" aria-label="Abrir <?= e($f['nombre']) ?> en pestaña nueva"><?= icono('externo') ?></button>
        </form>
      <?php else: ?>
        <a class="btn btn-ancho" href="<?= e(url('programa', ['id' => $f['id']])) ?>">Ver detalles</a>
      <?php endif; ?>
    </div>
  </div>
</article>
