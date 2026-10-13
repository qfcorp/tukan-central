<?php $ancha = true; ?>
<div class="tarjeta">
  <h2>Protege tu cuenta</h2>
  <p class="intro">Este panel abre todos tus programas, por eso pide un <b>segundo paso</b>: además de la contraseña, un código de 6 dígitos que cambia cada 30 segundos en tu celular. Se configura una sola vez.</p>
  <?php if ($error): ?><div class="aviso error mb" role="alert"><?= icono('error') ?><p><?= e($error) ?></p></div><?php endif; ?>
  <ol class="pasos">
    <li><div>
      <p><b>Instala una app de autenticación</b> en tu celular (gratis):</p>
      <div class="apps"><span>Google Authenticator</span><span>Microsoft Authenticator</span><span>Authy</span><span>2FAS</span></div>
    </div></li>
    <li><div>
      <p><b>En la app toca "+" y luego "Escanear código QR"</b>. Apunta la cámara a este código:</p>
      <div class="qr-caja">
        <?= $qr ?>
        <p class="chico suave">¿No puedes escanear? Elige "Ingresar clave de configuración" y escribe:</p>
        <div class="clave-manual" id="clave-manual" data-clave><?= e($clave) ?></div>
        <p class="chico suave">Cuenta: <b><?= e($pre['email']) ?></b> · Tipo: <b>basada en el tiempo</b></p>
      </div>
    </div></li>
    <li><div>
      <p><b>Escribe el código de 6 dígitos</b> que aparece ahora en la app para confirmar:</p>
      <form method="post" action="<?= e(url('activar_2fa')) ?>" class="mt">
        <?= campo_csrf() ?>
        <div class="campo">
          <label for="codigo" class="oculto">Código de 6 dígitos</label>
          <input id="codigo" name="codigo" type="text" class="codigo-input" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="000000" required data-auto-enviar>
        </div>
        <button class="btn btn-primario btn-grande btn-ancho" type="submit"><?= icono('escudo') ?> Activar y entrar</button>
      </form>
    </div></li>
  </ol>
</div>
