<?php
/**
 * Resultado de una operación que no se puede mostrar con un aviso:
 *   tipo = clave: contraseña nueva o demo creada (la clave se ve SOLO aquí; no se guarda)
 *     $correo (opcional, solo demos): «Enviar por correo» (ver correo_demo_preparar y app/paginas/demo_correo.php)
 *   tipo = sin_respuesta: el programa no contestó → "Revisar" reenvía la misma operación
 */
use Tukan\Contacto;

$prog = $inst['nombre'];
?>
<?php if ($tipo === 'sin_respuesta'): ?>
  <div class="tarjeta marco-alerta form-sola">
    <h1 class="h2"><?= icono('reloj') ?> <?= e($prog) ?> no respondió</h1>
    <p class="mt"><?= e($mensaje) ?></p>
    <p class="mt">Puede que <?= $accion === 'registrar_pago' ? 'el pago sí se haya registrado' : 'sí se haya hecho' ?>. Toca <b>Revisar</b>: se manda la <b>misma</b> operación, así que no se hace dos veces.</p>
    <form method="post" action="<?= e($accionUrl ?? url('ejecutar')) ?>" class="mt">
      <?php foreach ($post as $k => $v): if ($k === 'csrf' || $k === 'codigo') continue; ?>
        <?php if (is_array($v)): foreach ($v as $k2 => $v2): if (is_string($v2)): ?><input type="hidden" name="<?= e($k) ?>[<?= e((string) $k2) ?>]" value="<?= e($v2) ?>"><?php endif; endforeach; ?>
        <?php elseif (is_string($v)): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endif; ?>
      <?php endforeach; ?>
      <?= campo_csrf() ?>
      <?php if (in_array($accion, \Tukan\Operaciones::PIDEN_2FA, true)): ?>
        <div class="campo"><label for="codigo2">Tu código de 2 pasos</label><input id="codigo2" name="codigo" type="text" inputmode="numeric" required class="corto"></div>
      <?php endif; ?>
      <div class="acciones-form"><button class="btn btn-primario btn-grande" type="submit"><?= icono('refrescar') ?> Revisar</button>
        <a class="btn btn-grande" href="<?= e($volver) ?>">Volver</a></div>
    </form>
  </div>
<?php else:
  $acceso = $resultado['acceso'] ?? null;
  $usuario = $acceso['usuario'] ?? $resultado['usuario'] ?? null;
  $clave = $acceso['clave'] ?? $resultado['clave'] ?? null;
  $texto = is_string($resultado['mensaje_whatsapp'] ?? null) ? $resultado['mensaje_whatsapp'] : null;
  $cliente = is_array($respuesta['cliente'] ?? null) ? $respuesta['cliente'] : [];
  $wa = Contacto::esUrlWhatsapp($resultado['whatsapp_url'] ?? null) ? $resultado['whatsapp_url'] : (($n = Contacto::numeroWhatsapp($cliente['dueno']['whatsapp'] ?? $cliente['dueno']['telefono'] ?? null)) && $texto ? Contacto::urlWhatsapp($n, $texto) : null);
  $esDemo = isset($resultado['cliente_id']) || isset($resultado['prueba_hasta']);
  $cid = (string) ($resultado['cliente_id'] ?? $cliente['id'] ?? '');
?>
  <div class="tarjeta marco-ok form-sola resultado">
    <h1 class="h2"><?= icono('ok') ?> <?= $esDemo ? 'Demo lista' : 'Contraseña nueva lista' ?></h1>
    <p class="mt"><?= e($mensaje) ?></p>
    <?php if ($clave === null && !empty($resultado['clave_oculta']) && ($correo['estado'] ?? null) !== 'usado'): ?>
      <div class="aviso info mt"><?= icono('info') ?><p>Esta operación ya se había hecho y la contraseña solo se muestra una vez. Si hace falta otra, usa <b>Nueva clave del dueño</b> en la ficha del cliente.</p></div>
    <?php endif; ?>
    <dl class="datos accesos mt">
      <?php if (!empty($acceso['url_panel'])): ?><div><dt>Panel</dt><dd class="mono"><?= e($acceso['url_panel']) ?></dd></div><?php endif; ?>
      <?php if (!empty($resultado['enlace_publico'])): ?><div><dt>Página pública</dt><dd class="mono"><?= e($resultado['enlace_publico']) ?></dd></div><?php endif; ?>
      <?php if ($usuario): ?><div><dt>Usuario</dt><dd class="mono"><?= e($usuario) ?></dd></div><?php endif; ?>
      <?php if ($clave): ?><div><dt>Contraseña</dt><dd class="mono clave-una-vez" data-clave-una-vez><?= e($clave) ?></dd></div><?php endif; ?>
      <?php if (!empty($resultado['prueba_hasta'])): ?><div><dt>Prueba hasta</dt><dd><?= e(fecha_dm($resultado['prueba_hasta'], true)) ?></dd></div><?php endif; ?>
      <?php if (isset($resultado['correo_enviado'])): ?><div><dt>Correo de bienvenida</dt><dd><?= $resultado['correo_enviado'] ? 'Enviado ✔' : 'No se envió' ?></dd></div><?php endif; ?>
    </dl>
    <?php if ($clave): ?><p class="chico suave mt"><?= icono('candado') ?> La contraseña se ve solo ahora: no se guarda en el panel. Mándala ya.</p><?php endif; ?>
    <?php if ($texto && $clave): ?>
      <h2 class="mt">Mensaje para WhatsApp</h2>
      <pre class="mensaje-wa" id="mensaje-wa"><?= e($texto) ?></pre>
    <?php endif; ?>
    <?php if (!empty($correo)): $ce = $correo['estado'] ?? null; ?>
      <section class="correo-demo mt" id="correo-demo" aria-labelledby="correo-demo-t">
        <h2 id="correo-demo-t"><?= icono('correo') ?> Enviar por correo</h2>
        <?php if ($ce === 'enviado'): ?>
          <div class="aviso mt" role="status"><?= icono('ok') ?><p>Correo enviado a <b><?= e($correo['para']) ?></b> desde info@tukansoftware.com. Que revise también la carpeta de spam.</p></div>
        <?php elseif ($ce === 'usado'): ?>
          <div class="aviso info mt" role="status"><?= icono('info') ?><p>Este correo ya se envió. La contraseña solo se muestra una vez: si hace falta otra, usa <b>Nueva clave del dueño</b> en la ficha del cliente.</p></div>
        <?php elseif (!$correo['listo']): ?>
          <div class="acciones-form mt"><button type="button" class="btn btn-grande" disabled><?= icono('correo') ?> Configure el correo de Tukán</button></div>
          <p class="chico suave mt">El panel todavía no puede mandar correos desde info@tukansoftware.com. En el servidor, una sola vez:</p>
          <pre class="mono"><?= e(\Tukan\CorreoDemo::comandoConfigurar()) ?></pre>
        <?php elseif ($clave): ?>
          <?php if (!empty($correo['error'])): ?><div class="aviso error mt" role="alert"><?= icono('error') ?><p><?= e($correo['error']) ?></p></div><?php endif; ?>
          <form method="post" action="<?= e(url('demo_correo')) ?>" class="mt" id="form-correo-demo">
            <?= campo_csrf() ?><input type="hidden" name="sello" value="<?= e($correo['sello']) ?>">
            <div class="campo"><label for="correo-para">Correo del cliente</label>
              <input id="correo-para" name="para" type="email" required maxlength="254" autocomplete="off" value="<?= e($correo['para']) ?>">
              <span class="ayuda">Le llega «Su demo de <?= e(\Tukan\Productos::nombre((string) $inst['producto'])) ?> está lista» con sus datos de entrada, desde info@tukansoftware.com.</span></div>
            <div class="acciones-form"><button class="btn btn-primario btn-grande" type="submit"><?= icono('correo') ?> Enviar por correo</button></div>
          </form>
        <?php endif; ?>
      </section>
    <?php endif; ?>
    <div class="acciones-form mt">
      <?php if ($wa && ve_contacto() && $clave): ?><a class="btn btn-wa btn-grande" href="<?= e($wa) ?>" target="_blank" rel="noopener noreferrer"><?= icono('whatsapp') ?> Enviar por WhatsApp</a><?php endif; ?>
      <?php if ($texto && $clave): ?><button type="button" class="btn btn-grande" data-copiar="#mensaje-wa"><?= icono('copiar') ?> Copiar</button><span class="copiado" aria-live="polite"></span><?php endif; ?>
      <?php if ($esDemo && $cid !== ''): ?><?= boton_entrar($inst['id'], $cliente['ir_trabajar'] ?? $cliente['ir'] ?? null, 'Entrar a la demo ' . icono('externo'), 'btn btn-grande', true) ?>
        <a class="btn btn-grande" href="<?= e(url('cliente', ['i' => $inst['id'], 'id' => $cid])) ?>">Ver ficha</a><?php endif; ?>
      <a class="btn btn-grande" href="<?= e($volver) ?>">Listo</a>
    </div>
  </div>
<?php endif; ?>
