<?php
use Tukan\Productos;

$tp = Productos::tipo($inst['producto']);
?>
<p class="migas"><a href="<?= e(url('programas')) ?>">Programas</a> / <a href="<?= e(url('programa', ['id' => $inst['id']])) ?>"><?= e($inst['nombre']) ?></a> / Conectar</p>
<div class="cabeza p-<?= e($inst['producto']) ?>">
  <div class="prog-top">
    <span class="ico" aria-hidden="true"><?= e($tp['letra']) ?></span>
    <div class="prog-tit">
      <h1><?= $nuevo ? 'Último paso: conecta ' : 'Secreto nuevo para ' ?><?= e($inst['nombre']) ?></h1>
      <span class="url"><?= e(host($inst['url'])) ?> · ID <span class="mono"><?= e($inst['id']) ?></span></span>
    </div>
  </div>
</div>

<div class="tarjeta marco-alerta">
  <div class="aviso alerta mb"><?= icono('llave') ?><div><p><b>Este comando tiene el secreto y se muestra una sola vez.</b> Cópialo ahora. Si cierras esta página sin copiarlo, toca "Cambiar secreto" para generar otro.</p>
    <?php if (!$nuevo): ?><p>El secreto anterior ya no sirve: hasta que pegues este comando, el panel no podrá conectarse con <?= e($inst['nombre']) ?>.</p><?php endif; ?></div></div>
  <ol class="pasos">
    <li><div>
      <p><b>Copia el comando</b></p>
      <div class="comando" id="comando"><span class="prompt" aria-hidden="true">$ </span><span data-texto><?= e($comando) ?></span></div>
      <div class="acciones-form">
        <button type="button" class="btn btn-oscuro" data-copiar="#comando [data-texto]"><?= icono('copiar') ?> Copiar comando</button>
        <span class="copiado" aria-live="polite"></span>
      </div>
    </div></li>
    <li><div>
      <p><b>Abre tu app de SSH</b> (por ejemplo Termius) y entra al servidor donde vive <?= e($tp['nombre']) ?>.</p>
    </div></li>
    <li><div>
      <p><b>Pega el comando y toca Enter.</b> Si te pide la contraseña del servidor (sudo), escríbela. Al terminar dirá que quedó conectado.</p>
    </div></li>
    <li><div>
      <p><b>Vuelve aquí y prueba la conexión.</b></p>
      <form method="post" action="<?= e(url('probar')) ?>" class="mt"><?= campo_csrf() ?><input type="hidden" name="id" value="<?= e($inst['id']) ?>">
        <button class="btn btn-primario btn-grande" type="submit"><?= icono('enchufe') ?> Probar conexión</button></form>
    </div></li>
  </ol>
</div>
<p class="chico suave mt">¿Dudas? Mira la <a href="<?= e(url('ayuda')) ?>#<?= e($inst['producto']) ?>">ayuda de <?= e($tp['nombre']) ?></a>.</p>
