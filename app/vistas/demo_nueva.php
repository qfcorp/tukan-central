<?php use Tukan\Productos; ?>
<p class="migas"><a href="<?= e(url('demos')) ?>">Demos</a> / Crear demo</p>
<?php if ($paso === 1): ?>
  <div class="cabeza"><div><h1>Crear demo</h1><p class="sub">1. ¿En qué programa?</p></div></div>
  <?php if (!$pueden): ?>
    <div class="tarjeta"><p class="suave">Ningún programa puede crear demos desde el panel todavía. Cuando actualices un programa al protocolo v2, aparecerá aquí.</p></div>
  <?php else: ?>
  <div class="rejilla tres">
    <?php foreach ($pueden as $i): $tp = Productos::tipo($i['producto']); ?>
      <a class="elegir-prog p-<?= e($i['producto']) ?>" href="<?= e(url('demo_nueva', ['i' => $i['id']])) ?>">
        <span class="ico" aria-hidden="true"><?= e($tp['letra']) ?></span>
        <span><strong><?= e($i['nombre']) ?></strong><small><?= e($tp['para']) ?></small></span><?= icono('chevron') ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php else: ?>
  <div class="cabeza p-<?= e($inst['producto']) ?>">
    <div class="prog-top"><?= ico_programa($inst['producto'], false) ?><div class="prog-tit"><h1>Demo en <?= e($inst['nombre']) ?></h1><span class="url">2. Datos del negocio<?= $sol ? ' · viene del pedido de demo' : '' ?></span></div></div>
  </div>
  <form method="post" action="<?= e(url('demo_nueva')) ?>" class="tarjeta form-demo" id="form-demo">
    <?php if ($error): ?><div class="aviso error mb" role="alert"><?= icono('error') ?><p><?= e($error) ?></p></div><?php endif; ?>
    <?= campo_csrf() ?><input type="hidden" name="i" value="<?= e($inst['id']) ?>"><input type="hidden" name="op" value="<?= e($op) ?>"><input type="hidden" name="sol" value="<?= e($solId) ?>">
    <div class="fila-campos"><?= campos_html($comunes, $valores, $errores, 'campo', 'd') ?></div>
    <?php if ($extra): ?>
      <h2 class="mt">Lo propio de <?= e($inst['nombre']) ?></h2>
      <div class="fila-campos"><?= campos_html($extra, $valoresExtra, $errores, 'extra', 'x') ?></div>
    <?php endif; ?>
    <label class="check mb"><input type="hidden" name="enviar_correo" value="0"><input type="checkbox" name="enviar_correo" value="1" <?= $enviarCorreo ? 'checked' : '' ?>> <span>Enviarle el correo de bienvenida con sus accesos</span></label>
    <div class="acciones-form">
      <button class="btn btn-primario btn-grande" type="submit"><?= icono('demo') ?> Crear demo</button>
      <a class="btn btn-grande" href="<?= e(url('demo_nueva')) ?>">Otro programa</a>
    </div>
    <p class="chico suave mt">La contraseña se mostrará una sola vez, para mandarla por WhatsApp. No se guarda en el panel.</p>
  </form>
<?php endif; ?>
