<div class="tarjeta">
  <h2>Código de seguridad</h2>
  <p class="intro">Hola, <?= e(explode(' ', $pre['nombre'])[0]) ?>. Abre tu app de autenticación y escribe el código de 6 dígitos de <b>Tukán Central</b>.</p>
  <?php if ($error): ?><div class="aviso error mb" role="alert"><?= icono('error') ?><p><?= e($error) ?></p></div><?php endif; ?>
  <form method="post" action="<?= e(url('verificar')) ?>">
    <?= campo_csrf() ?>
    <div class="campo">
      <label for="codigo">Código</label>
      <input id="codigo" name="codigo" type="text" class="codigo-input" inputmode="numeric" autocomplete="one-time-code" maxlength="9" placeholder="000000" required autofocus data-auto-enviar>
    </div>
    <button class="btn btn-primario btn-grande btn-ancho" type="submit">Entrar al panel <?= icono('flecha') ?></button>
  </form>
  <details class="avanzado mt">
    <summary>¿Perdiste tu celular?</summary>
    <p class="suave chico mb">Escribe en el mismo cuadro uno de tus <b>códigos de respaldo</b> (por ejemplo <span class="mono">k7m2-p9xq</span>). Cada uno sirve una sola vez.</p>
    <p class="suave chico mb">Si tampoco los tienes, desde el servidor se puede quitar la verificación: está explicado en el README, sección "Emergencia".</p>
  </details>
  <p class="chico"><a href="<?= e(url('login')) ?>">← Entrar con otro correo</a></p>
</div>
