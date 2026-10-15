<?php
/**
 * Formulario de una de acciones_posibles (§4.4). Variables: $inst, $a, $ctx (cliente_id, nombre, volver), $valores, $errores, $error, $op
 */
use Tukan\{Operaciones};

$fijos = json_encode($a['datos'], JSON_UNESCAPED_UNICODE);
$def = json_encode($a['campos'], JSON_UNESCAPED_UNICODE);
$meta = ['etiqueta' => $a['etiqueta'], 'estilo' => $a['estilo'], 'confirmar' => $a['confirmar']];
// Borrar: el nombre exacto que hay que escribir va firmado (el panel lo revisa antes de mandar)
if ($a['accion'] === 'borrar_cliente' && (string) ($ctx['nombre'] ?? '') !== '') $meta['nombre_exacto'] = (string) $ctx['nombre'];
// Abierto desde una solicitud de ese cliente: al borrar, el panel la marca atendida
if (!empty($ctx['solicitud'])) $meta['solicitud'] = (string) $ctx['solicitud'];
$meta = json_encode($meta, JSON_UNESCAPED_UNICODE);
$cid = (string) ($a['datos']['cliente_id'] ?? $ctx['cliente_id'] ?? '');
$cotiza = (bool) array_filter($a['campos'], fn($c) => $c['cotizar']);
$dosPasos = in_array($a['accion'], Operaciones::PIDEN_2FA, true);
$peligro = $a['estilo'] === 'peligro';
$nombre = (string) ($ctx['nombre'] ?? '');
$confirmar = $a['confirmar'];
if ($a['accion'] === 'registrar_pago') $confirmar = '¿Registrar este pago de ' . ($nombre ?: 'este cliente') . '?';
?>
<form method="post" action="<?= e(url('ejecutar')) ?>" class="form-accion<?= $peligro ? ' es-peligro' : '' ?>" id="form-accion"
      <?= $confirmar ? 'data-confirmar="' . e($confirmar) . '"' : '' ?><?= $peligro ? ' data-dos-veces' : '' ?>
      <?= $cotiza && $cid !== '' ? 'data-cotizar-url="' . e(url('cotizar', ['i' => $inst['id'], 'cliente' => $cid])) . '" data-nombre="' . e($nombre) . '"' : '' ?>>
  <div class="hoja-cabeza">
    <div><h2><?= e($a['etiqueta']) ?></h2><?php if ($nombre !== ''): ?><p class="sub"><?= e($nombre) ?> · <?= e($inst['nombre']) ?></p><?php endif; ?></div>
    <button type="button" class="btn btn-icono btn-chico cerrar-hoja" data-cerrar aria-label="Cerrar"><?= icono('cruz') ?></button>
  </div>
  <?php if (!empty($ctx['solicitud']) && $a['accion'] === 'borrar_cliente'): ?>
    <p class="aviso info mb desde-sol"><?= icono('bandeja') ?><span>Al borrarlo, la solicitud<?= !empty($ctx['solicitud_titulo']) ? ' «' . e($ctx['solicitud_titulo']) . '»' : '' ?> queda <b>atendida</b>.</span></p>
  <?php endif; ?>
  <?php if ($error): ?><div class="aviso error mb" role="alert"><?= icono('error') ?><p><?= e($error) ?></p></div><?php endif; ?>
  <?= campo_csrf() ?>
  <input type="hidden" name="i" value="<?= e($inst['id']) ?>"><input type="hidden" name="accion" value="<?= e($a['accion']) ?>">
  <input type="hidden" name="op" value="<?= e($op) ?>"><input type="hidden" name="cliente_id" value="<?= e($cid) ?>">
  <input type="hidden" name="fijos" value="<?= e($fijos) ?>"><input type="hidden" name="def" value="<?= e($def) ?>"><input type="hidden" name="meta" value="<?= e($meta) ?>">
  <input type="hidden" name="nombre" value="<?= e($nombre) ?>"><input type="hidden" name="volver" value="<?= e($ctx['volver']) ?>">
  <input type="hidden" name="firma" value="<?= e(firma_form($inst['id'], $a['accion'], $cid, $fijos, $def, $meta)) ?>">
  <?= campos_html($a['campos'], $valores, $errores) ?>
  <?php if ($cotiza): ?><p class="cotizado" data-cotizado aria-live="polite"></p><?php endif; ?>
  <?php if ($dosPasos): ?>
    <div class="campo"><label for="codigo2">Tu código de 2 pasos</label><input id="codigo2" name="codigo" type="text" inputmode="numeric" autocomplete="one-time-code" required maxlength="9" class="corto">
      <span class="ayuda">Esto es delicado: escribe el código de tu app para confirmar.</span><?php if (isset($errores['_codigo'])): ?><span class="error-campo"><?= e($errores['_codigo']) ?></span><?php endif; ?></div>
  <?php endif; ?>
  <?php if ($peligro): ?>
    <label class="check mb seguro"><input type="checkbox" name="seguro" value="1" required> <span><?= e($a['confirmar'] ?? '¿Seguro?') ?> Sí, estoy seguro.</span></label>
  <?php elseif ($a['confirmar'] && $a['accion'] !== 'registrar_pago'): ?>
    <p class="chico suave mb"><?= e($a['confirmar']) ?></p>
  <?php endif; ?>
  <div class="acciones-form">
    <button class="btn <?= $peligro ? 'btn-peligro' : 'btn-primario' ?> btn-grande" type="submit"><?= e($a['etiqueta']) ?></button>
    <a class="btn btn-grande" href="<?= e($ctx['volver']) ?>" data-cerrar>Cancelar</a>
  </div>
</form>
