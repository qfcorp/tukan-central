<?php
$campoCodigo = function (string $id): string {
    return '<div class="campo"><label for="' . $id . '">Tu código de 2 pasos</label>'
        . '<input id="' . $id . '" name="codigo" type="text" class="codigo-input" inputmode="numeric" autocomplete="one-time-code" maxlength="9" placeholder="000000" required></div>';
};
?>
<div class="cabeza">
  <div>
    <h1>Usuarios del panel</h1>
    <p class="sub">Quiénes pueden entrar a este panel. Todos usan verificación en 2 pasos.</p>
  </div>
</div>
<?php if ($error): ?><div class="aviso error mb" role="alert"><?= icono('error') ?><p><?= e($error) ?></p></div><?php endif; ?>

<div class="rejilla dos">
  <div class="tarjeta">
    <h2>Usuarios</h2>
    <ul class="lista-p">
      <?php foreach ($lista as $x): ?>
        <li>
          <span class="avatar" aria-hidden="true"><?= e(iniciales($x['nombre'])) ?></span>
          <div class="dato">
            <strong><?= e($x['nombre']) ?><?= (int) $x['id'] === (int) $yo['id'] ? ' (tú)' : '' ?></strong>
            <span><?= e($x['email']) ?></span><br>
            <span class="etiqueta <?= $x['rol'] === 'dueno' ? 'alerta' : '' ?>"><?= $x['rol'] === 'dueno' ? 'Dueño' : 'Soporte' ?></span>
            <?php if (!(int) $x['activo']): ?><span class="etiqueta mal">Desactivado</span><?php endif; ?>
            <?php if (!(int) $x['con_2fa']): ?><span class="etiqueta">2FA pendiente</span><?php else: ?><span class="etiqueta ok">2FA activo</span><?php endif; ?>
            <?php if ((int) $x['cambiar_clave']): ?><span class="etiqueta">Clave temporal</span><?php endif; ?>
            <br><span class="chico">Último ingreso: <?= $x['ultimo_ingreso'] ? e(hace((int) $x['ultimo_ingreso'])) : 'nunca' ?></span>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div>
    <form method="post" action="<?= e(url('usuarios')) ?>" class="tarjeta">
      <h2>Agregar usuario</h2>
      <?= campo_csrf() ?><input type="hidden" name="accion" value="crear">
      <div class="campo"><label for="u-nombre">Nombre</label><input id="u-nombre" name="nombre" type="text" required maxlength="80"></div>
      <div class="campo"><label for="u-email">Correo</label><input id="u-email" name="email" type="email" required autocapitalize="none"></div>
      <div class="campo"><label for="u-rol">Rol</label>
        <select id="u-rol" name="rol"><?php foreach ($roles as $k => $v): ?><option value="<?= e($k) ?>" <?= $k === 'soporte' ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
        <span class="ayuda">Soporte puede entrar a los programas, pero no agregar ni borrar programas, ni ver secretos, usuarios o auditoría.</span></div>
      <?= $campoCodigo('u-codigo') ?>
      <button class="btn btn-primario" type="submit"><?= icono('mas') ?> Crear con contraseña temporal</button>
    </form>

    <form method="post" action="<?= e(url('usuarios')) ?>" class="tarjeta">
      <h2>Cambiar un usuario</h2>
      <?= campo_csrf() ?>
      <div class="campo"><label for="c-id">Usuario</label>
        <select id="c-id" name="id" required><?php foreach ($lista as $x): ?><option value="<?= (int) $x['id'] ?>"><?= e($x['nombre'] . ' · ' . $x['email']) ?></option><?php endforeach; ?></select></div>
      <div class="campo"><label for="c-accion">Qué hacer</label>
        <select id="c-accion" name="accion" required>
          <option value="rol">Cambiar el rol</option>
          <option value="activo">Activar o desactivar</option>
          <option value="clave">Darle una contraseña temporal nueva</option>
          <option value="quitar_2fa">Quitar su 2FA (perdió el celular)</option>
        </select></div>
      <div class="fila-campos">
        <div class="campo"><label for="c-rol">Rol nuevo</label><select id="c-rol" name="rol"><?php foreach ($roles as $k => $v): ?><option value="<?= e($k) ?>"><?= e($k === 'dueno' ? 'Dueño' : 'Soporte') ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label for="c-valor">Activo</label><select id="c-valor" name="valor"><option value="1">Activar</option><option value="0">Desactivar</option></select></div>
      </div>
      <?= $campoCodigo('c-codigo') ?>
      <button class="btn btn-oscuro" type="submit">Aplicar</button>
    </form>
  </div>
</div>
