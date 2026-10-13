<div class="tarjeta">
  <h2>Entrar</h2>
  <p class="intro">Escribe tu correo y tu contraseña. Después te pediremos el código de tu app de seguridad.</p>
  <?php if ($error): ?><div class="aviso error mb" role="alert"><?= icono('error') ?><p><?= e($error) ?></p></div><?php endif; ?>
  <form method="post" action="<?= e(url('login')) ?>" novalidate>
    <?= campo_csrf() ?>
    <div class="campo">
      <label for="email">Correo</label>
      <input id="email" name="email" type="email" autocomplete="username" inputmode="email" required value="<?= e($email) ?>" autofocus>
    </div>
    <div class="campo">
      <label for="clave">Contraseña</label>
      <input id="clave" name="clave" type="password" autocomplete="current-password" required>
    </div>
    <button class="btn btn-primario btn-grande btn-ancho" type="submit">Continuar <?= icono('flecha') ?></button>
  </form>
</div>
