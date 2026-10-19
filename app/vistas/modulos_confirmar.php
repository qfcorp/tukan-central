<?php
/** Resumen antes de cambiar los módulos de un cliente (o de aprobar un pedido cambio_modulos). */
use Tukan\Modulos;

$mods = $cat['modulos'];
$hayCambios = $sim['encender'] || $sim['apagar'] || $cambiaPlan || $cambiaAcordado;
$antes = (float) ($actual['mensual'] ?? 0);
$despues = (float) $sim['mensual'];
?>
<p class="migas"><?= $sol ? '<a href="' . e(url('solicitudes')) . '">Solicitudes</a>' : '<a href="' . e($volver) . '">' . e($nombreCliente) . '</a>' ?> / <?= $sol ? 'Aprobar módulos' : 'Revisar módulos' ?></p>
<div class="cabeza p-<?= e($inst['producto']) ?>">
  <div class="prog-top"><?= ico_programa($inst['producto'], false) ?><div class="prog-tit">
    <h1><?= $sol ? 'Aprobar el pedido de módulos' : 'Revisa antes de guardar' ?></h1>
    <span class="url"><?= e($nombreCliente) ?> · <?= e($inst['nombre']) ?></span></div></div>
</div>

<form method="post" action="<?= e(url('modulos')) ?>" class="tarjeta form-sola" id="confirmar-modulos" data-confirmar="¿Guardar los módulos de <?= e($nombreCliente) ?>? Valor mensual: <?= e(dinero($despues)) ?>.">
  <?= campo_csrf() ?>
  <input type="hidden" name="paso" value="guardar"><input type="hidden" name="i" value="<?= e($inst['id']) ?>"><input type="hidden" name="cliente" value="<?= e($cid) ?>">
  <input type="hidden" name="sol" value="<?= e($solId) ?>"><input type="hidden" name="op" value="<?= e($op) ?>"><input type="hidden" name="carga" value="<?= e($carga) ?>">
  <input type="hidden" name="firma" value="<?= e($firma) ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>"><input type="hidden" name="nombre" value="<?= e($nombreCliente) ?>">

  <?php if ($sol): ?>
    <p class="mb">Lo pidió <strong><?= e((string) ($sol['quien']['nombre'] ?? $sol['detalle']['pedido_por'] ?? 'el cliente')) ?></strong> <?= e(hace_iso($sol['fecha'] ?? null)) ?> desde «Mi plan».
      <?php if (!empty($sol['detalle']['nota'])): ?><br><span class="suave">«<?= e((string) $sol['detalle']['nota']) ?>»</span><?php endif; ?></p>
  <?php endif; ?>

  <?php if ($sim['problemas']): ?>
    <div class="aviso error mb" role="alert"><?= icono('error') ?><div><?php foreach ($sim['problemas'] as $p): ?><p><?= e($p) ?></p><?php endforeach; ?></div></div>
  <?php endif; ?>

  <?php if (!$hayCambios): ?>
    <div class="aviso info mb"><?= icono('info') ?><p>No hay cambios: los módulos ya están así.<?= $sol ? ' Al aprobar, el pedido queda atendido.' : '' ?></p></div>
  <?php else: ?>
    <h2>Qué cambia</h2>
    <ul class="cambios" id="lista-cambios">
      <?php if ($cambiaPlan): ?><li><span>Plan base: <?= e($actual['plan']['nombre'] ?? $actual['plan']['codigo'] ?? '—') ?> → <strong><?= e($cat['planes'][$plan]['nombre'] ?? $plan) ?></strong></span><span><?= e(dinero((float) ($cat['planes'][$plan]['precio_mensual'] ?? 0))) ?></span></li><?php endif; ?>
      <?php foreach ($sim['encender'] as $id): ?>
        <li class="enciende"><span>✔ Se enciende <strong><?= e($mods[$id]['nombre'] ?? $id) ?></strong><?= in_array($id, $sim['por_dependencia'], true) ? ' <small>(lo necesita otro módulo)</small>' : '' ?></span>
          <span><?= in_array($id, $sim['extra'], true) ? '+' . e(dinero($mods[$id]['precio_mensual'] ?? 0)) : 'En el plan' ?></span></li>
      <?php endforeach; ?>
      <?php foreach ($sim['apagar'] as $id): ?>
        <li class="apaga"><span>✖ Se apaga <strong><?= e($mods[$id]['nombre'] ?? $id) ?></strong> <small>(sus datos se guardan)</small></span>
          <span><?= in_array($id, $sim['quitados'], true) ? '−' . e(dinero($mods[$id]['precio_mensual'] ?? 0)) : '−' . e(dinero($mods[$id]['precio_mensual'] ?? 0)) ?></span></li>
      <?php endforeach; ?>
      <?php if ($cambiaAcordado): ?><li><span>Precio acordado</span><span><?= $acordado === null ? 'Sin precio acordado' : e(dinero($acordado)) ?></span></li><?php endif; ?>
    </ul>
  <?php endif; ?>

  <div class="precio-nuevo" id="precio-nuevo">
    <span>Valor mensual: <?= e(dinero($antes)) ?> →</span> <strong><?= e(dinero($despues)) ?></strong>
    <span class="chico suave"><?= $acordado !== null ? 'precio acordado (calculado: ' . e(dinero($sim['calculado'])) . ')' : 'plan ' . e(dinero($sim['precio_plan'])) . ' + extras ' . e(dinero($sim['precio_extra'])) . ' − quitados ' . e(dinero($sim['descuento'])) ?></span>
  </div>
  <?php if ($cid === 'unico'): ?><p class="chico suave mb">Es un programa de una sola empresa: este valor pasa a ser el de su licencia (Programas → Licencia).</p><?php endif; ?>
  <p class="chico suave mb">Quedan encendidos <?= count($sim['activos']) ?> de <?= count($mods) ?> módulos. Lo que se apaga se esconde, pero no se borra: si se vuelve a encender, todo sigue como estaba. <?= e($inst['nombre']) ?> confirma el precio final.</p>

  <div class="campo"><label for="motivo">Nota <span class="opcional">(opcional<?= $sol ? ', la ve el cliente en «Mi plan»' : '' ?>)</span></label><input type="text" id="motivo" name="motivo" maxlength="300" value="<?= e($motivo) ?>"></div>
  <div class="acciones-form">
    <button class="btn btn-primario btn-grande" type="submit"<?= $sim['problemas'] ? ' disabled' : '' ?>><?= icono('check') ?> <?= $sol ? 'Aprobar y encender' : 'Sí, guardar' ?></button>
    <a class="btn btn-grande" href="<?= e($volver) ?>"><?= $sol ? 'Volver' : 'Cambiar algo' ?></a>
  </div>
</form>
