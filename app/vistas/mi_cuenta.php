<?php
use Tukan\Auditoria;

$u = usuario();
$campoCodigo = function (string $id): string {
    return '<div class="campo"><label for="' . $id . '">Código de 2 pasos</label>'
        . '<input id="' . $id . '" name="codigo" type="text" class="codigo-input" inputmode="numeric" autocomplete="one-time-code" maxlength="9" placeholder="000000" required></div>';
};
?>
<div class="cabeza">
  <div>
    <h1>Mi cuenta</h1>
    <p class="sub"><?= e($u['nombre']) ?> · <?= e($u['email']) ?> · <?= $u['rol'] === 'dueno' ? 'Dueño' : 'Soporte' ?></p>
  </div>
</div>
<?php if ($error): ?><div class="aviso error mb" role="alert"><?= icono('error') ?><p><?= e($error) ?></p></div><?php endif; ?>

<div class="rejilla dos">
  <form method="post" action="<?= e(url('mi_cuenta')) ?>" class="tarjeta<?= (int) $u['cambiar_clave'] ? ' marco-alerta' : '' ?>" id="form-clave">
    <h2>Cambiar contraseña</h2>
    <?php if ((int) $u['cambiar_clave']): ?><p class="suave mb">Tienes una contraseña temporal. Elige una tuya para continuar.</p><?php endif; ?>
    <?= campo_csrf() ?><input type="hidden" name="accion" value="clave">
    <div class="campo"><label for="actual">Contraseña actual</label><input id="actual" name="actual" type="password" autocomplete="current-password" required></div>
    <div class="campo"><label for="nueva">Contraseña nueva</label><input id="nueva" name="nueva" type="password" autocomplete="new-password" minlength="10" required><span class="ayuda">Mínimo 10 caracteres. Una frase larga es más segura y fácil de recordar.</span></div>
    <div class="campo"><label for="repetir">Repite la nueva</label><input id="repetir" name="repetir" type="password" autocomplete="new-password" minlength="10" required></div>
    <?= $campoCodigo('codigo-clave') ?>
    <button class="btn btn-primario" type="submit"><?= icono('candado') ?> Cambiar contraseña</button>
  </form>

  <div>
    <form method="post" action="<?= e(url('mi_cuenta')) ?>" class="tarjeta" data-confirmar="¿Generar 8 códigos nuevos? Los anteriores dejan de servir.">
      <h2>Códigos de respaldo</h2>
      <p class="suave mb">Te quedan <b><?= (int) $restantes ?></b> de 8. Sirven para entrar si pierdes el celular. Si generas nuevos, los anteriores dejan de servir.</p>
      <?= campo_csrf() ?><input type="hidden" name="accion" value="codigos">
      <?= $campoCodigo('codigo-respaldo') ?>
      <button class="btn" type="submit"><?= icono('refrescar') ?> Generar códigos nuevos</button>
    </form>

    <div class="tarjeta">
      <h2>Sesiones</h2>
      <ul class="lista-p">
        <?php foreach (array_slice($sesiones, 0, 8) as $s): $esta = $s['id'] === ($_SESSION['ref'] ?? ''); ?>
          <li><div class="dato">
            <strong><?= e(navegador($s['agente'])) ?><?= $esta ? ' · esta sesión' : '' ?></strong>
            <span class="mono"><?= e($s['ip']) ?></span> <span>· desde <?= e(fecha_hora((int) $s['creada'])) ?> · <?= $s['cerrada'] ? 'cerrada (' . e($s['motivo']) . ')' : 'abierta, usada ' . e(hace((int) $s['ultima'])) ?></span>
          </div><?php if (!$s['cerrada'] && !$esta): ?><span class="etiqueta alerta">Abierta</span><?php elseif ($esta): ?><span class="etiqueta ok">Tú</span><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
      <form method="post" action="<?= e(url('mi_cuenta')) ?>" class="mt"><?= campo_csrf() ?><input type="hidden" name="accion" value="cerrar_otras">
        <button class="btn btn-chico" type="submit"><?= icono('salir') ?> Cerrar las demás sesiones</button></form>
    </div>
  </div>
</div>

<div class="tarjeta seccion">
  <h2>Últimos ingresos y avisos de seguridad</h2>
  <?php if (!$ingresos): ?><p class="suave">Nada todavía.</p><?php else: ?>
  <div class="tabla-env"><table class="tabla apilable">
    <thead><tr><th>Cuándo</th><th>Qué</th><th>Desde</th></tr></thead>
    <tbody><?php foreach ($ingresos as $r): ?>
      <tr><td data-t="Cuándo" class="nowrap"><?= e(fecha_hora((int) $r['momento'])) ?></td><td data-t="Qué"><?= e(Auditoria::texto($r['accion'])) ?><?= $r['detalle'] !== '' ? ' · <span class="suave">' . e($r['detalle']) . '</span>' : '' ?></td><td data-t="Desde"><span class="mono"><?= e($r['ip']) ?></span> · <?= e(navegador($r['agente'])) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>
