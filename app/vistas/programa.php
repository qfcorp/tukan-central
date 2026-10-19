<?php
use Tukan\{Auditoria, Instancias, Productos};

$tp = Productos::tipo($f['producto']);
$datos = $f['datos'];
$activo = (int) $f['activo'];
$on = $activo && $f['consultado'] !== null && (int) $f['en_linea'];
?>
<p class="migas"><a href="<?= e(url('programas')) ?>">Programas</a> / <?= e($f['nombre']) ?></p>
<div class="cabeza p-<?= e($f['producto']) ?>">
  <div class="prog-top">
    <span class="ico" aria-hidden="true"><?= e($tp['letra']) ?></span>
    <div class="prog-tit">
      <h1><?= e($f['nombre']) ?></h1>
      <span class="url"><?= e($tp['nombre']) ?> · <a href="<?= e($f['url']) ?>" rel="noopener noreferrer" target="_blank"><?= e(host($f['url'])) ?></a></span>
    </div>
  </div>
  <div class="acciones">
    <form method="post" action="<?= e(url('probar')) ?>"><?= campo_csrf() ?><input type="hidden" name="id" value="<?= e($f['id']) ?>">
      <button class="btn" type="submit"><?= icono('enchufe') ?> Probar conexión</button></form>
    <?php if ($activo): ?>
    <form method="post" action="<?= e(url('entrar')) ?>"><?= campo_csrf() ?><input type="hidden" name="id" value="<?= e($f['id']) ?>">
      <button class="btn btn-primario" type="submit">Entrar <?= icono('flecha') ?></button></form>
    <?php endif; ?>
  </div>
</div>

<div class="rejilla dos">
  <div class="tarjeta">
    <h2>Estado</h2>
    <dl class="datos">
      <div><dt>Conexión</dt><dd>
        <?php if (!$activo): ?><span class="estado pausa">Pausado</span>
        <?php elseif ($f['consultado'] === null): ?><span class="estado">Sin revisar</span> <span class="chico suave">Toca "Probar conexión".</span>
        <?php elseif ($on): ?><span class="estado on">En línea</span> <span class="chico suave"><?= (int) $f['ms'] ?> ms</span>
        <?php else: ?><span class="estado off">Sin conexión</span><?php endif; ?>
      </dd></div>
      <?php if ($activo && $f['consultado'] !== null && !$on): ?>
        <div><dt>Qué pasa</dt><dd><span class="fallo" id="error-conexion"><?= e($f['error_detalle']) ?></span></dd></div>
      <?php elseif ($on && $f['error_detalle']): ?>
        <div><dt>Ojo</dt><dd><span class="ojo"><?= e($f['error_detalle']) ?></span></dd></div>
      <?php endif; ?>
      <div><dt>Última revisión</dt><dd><?= $f['consultado'] ? e(fecha_hora((int) $f['consultado'])) . ' <span class="chico suave">(' . e(hace((int) $f['consultado'])) . ')</span>' : '—' ?></dd></div>
      <div><dt>Últimos datos</dt><dd><?= $f['datos_momento'] ? e(hace((int) $f['datos_momento'])) : '—' ?></dd></div>
      <div><dt>Versión</dt><dd class="mono"><?= e($datos['version'] ?? '—') ?></dd></div>
      <?php if (!empty($datos['cliente'])): ?><div><dt>Cliente</dt><dd><strong><?= e($datos['cliente']) ?></strong></dd></div><?php endif; ?>
      <div><dt>7 días</dt><dd>
        <div class="disp"><div class="barras" role="img" aria-label="Disponibilidad"><?php foreach ($disp['tramos'] as $tr): ?><i class="<?= e($tr['clase']) ?>" title="<?= e(fecha_hora($tr['desde'], false)) ?><?= $tr['pct'] === null ? '' : ' · ' . e((string) $tr['pct']) . ' %' ?>"></i><?php endforeach; ?></div>
        <small><?= $disp['pct'] === null ? '—' : e(str_replace('.', ',', (string) $disp['pct'])) . ' %' ?></small></div>
      </dd></div>
    </dl>
  </div>

  <div class="tarjeta">
    <h2>Cifras</h2>
    <?php if (!$datos): ?>
      <p class="suave">Todavía no hay cifras. Cuando la conexión esté bien, aparecerán aquí.</p>
    <?php else: ?>
      <dl class="datos">
        <?php if (is_array($datos['negocios'])): ?>
          <div><dt>Negocios</dt><dd><b><?= (int) $datos['negocios']['total'] ?></b> en total · <?= (int) $datos['negocios']['prueba'] ?> en prueba · <?= (int) $datos['negocios']['activos'] ?> activos · <?= (int) $datos['negocios']['por_vencer'] ?> por vencer · <?= (int) $datos['negocios']['vencidos'] ?> vencidos · <?= (int) $datos['negocios']['suspendidos'] ?> suspendidos</dd></div>
        <?php endif; ?>
        <?php if ($datos['nuevos_30d'] !== null): ?><div><dt>Nuevos (30 días)</dt><dd><?= (int) $datos['nuevos_30d'] ?></dd></div><?php endif; ?>
        <?php if ($datos['ingresos_mes'] !== null): ?><div><dt>Ingresos del mes</dt><dd><?= e(dinero($datos['ingresos_mes'])) ?> <span class="chico suave">(mes pasado <?= e(dinero($datos['ingresos_mes_anterior'])) ?>)</span></dd></div><?php endif; ?>
        <?php foreach ($datos['extra'] as $k => $v): ?><div><dt><?= e($k) ?></dt><dd><?= e(numero_corto($v)) ?></dd></div><?php endforeach; ?>
        <div><dt>Demos que vencen</dt><dd><?= count($datos['pruebas_vencen_7d']) ?: 'Ninguna' ?></dd></div>
        <div><dt>Pagos vencidos</dt><dd><?= count($datos['pagos_vencidos']) ?: 'Ninguno' ?></dd></div>
      </dl>
    <?php endif; ?>
  </div>
</div>

<?php $resumen = \Tukan\Capacidades::resumen($cap && $cap['protocolo'] === 2 ? $cap['datos'] : []); $esV2 = $cap && $cap['protocolo'] === 2; ?>
<div class="tarjeta seccion" id="capacidades">
  <h2>Qué puede hacer desde el panel</h2>
  <?php if (!$cap): ?>
    <p class="suave">Todavía no se sabe: toca <b>Probar conexión</b>.</p>
  <?php else: ?>
    <p class="chico suave mb"><?= $esV2 ? 'Protocolo v2' . (!empty($cap['datos']['version']) ? ' · versión ' . e($cap['datos']['version']) : '') . ' · revisado ' . e(hace($cap['consultado'])) : 'Este programa todavía usa el protocolo v1: solo cifras y Entrar.' ?></p>
    <ul class="capacidades">
      <?php foreach ($resumen as $t => $si): if ($unico && in_array($t, ['Crear demos', 'Alargar demos', 'Registrar pagos', 'Aprobar comprobantes', 'Finanzas (ingresos por mes)', 'Borrar clientes (papelera)'], true)) continue; ?>
        <li class="<?= $si ? 'si' : 'no' ?>"><?= icono($si ? 'check' : 'cruz') ?> <?= e($t) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if (in_array(false, $resumen, true) && !$unico): ?><p class="chico suave mt">Lo que sale con ✖: actualiza <?= e($f['nombre']) ?> para tener esto.</p><?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($unico && puede('finanzas')): $lc = $licencia['contacto'] ?? []; $hasta = $licencia['activo_hasta'] ?? null; $dl = $hasta ? dias_hasta($hasta) : null; ?>
<div class="rejilla dos seccion" id="licencia">
  <form method="post" action="<?= e(url('licencias')) ?>" class="tarjeta">
    <h2>Licencia</h2>
    <p class="mb"><?php if (!$licencia): ?><span class="etiqueta">Sin datos todavía</span>
      <?php elseif ($hasta === null): ?><span class="etiqueta">Sin fecha</span>
      <?php else: ?><?= etiqueta_estado($dl < 0 ? 'vencido' : ($dl <= 7 ? 'por_vencer' : 'activo'), ($dl < 0 ? 'Vencida el ' : 'Pagada hasta el ') . fecha_dm($hasta, true)) ?><?php endif; ?>
      <?php if ($licencia): ?> · <?= e(dinero((float) $licencia['valor_mensual'])) ?> al mes<?php endif; ?></p>
    <?= campo_csrf() ?><input type="hidden" name="i" value="<?= e($f['id']) ?>"><input type="hidden" name="accion" value="guardar">
    <div class="fila-campos">
      <div class="campo"><label for="l-valor">Valor al mes ($)</label><input id="l-valor" name="valor_mensual" inputmode="decimal" value="<?= e(number_format((float) ($licencia['valor_mensual'] ?? 0), 2, ',', '')) ?>"></div>
      <div class="campo"><label for="l-hasta">Pagada hasta</label><input id="l-hasta" type="date" name="activo_hasta" value="<?= e((string) $hasta) ?>"></div>
      <div class="campo"><label for="l-cn">Contacto</label><input id="l-cn" name="contacto_nombre" value="<?= e((string) ($lc['nombre'] ?? '')) ?>" maxlength="100"></div>
      <div class="campo"><label for="l-ct">Celular</label><input id="l-ct" type="tel" name="contacto_telefono" value="<?= e((string) ($lc['telefono'] ?? '')) ?>" maxlength="30"></div>
    </div>
    <div class="campo"><label for="l-ce">Correo</label><input id="l-ce" type="email" name="contacto_email" value="<?= e((string) ($lc['email'] ?? '')) ?>" maxlength="150"></div>
    <div class="campo"><label for="l-n">Notas</label><textarea id="l-n" name="notas" maxlength="1000"><?= e((string) ($licencia['notas'] ?? '')) ?></textarea></div>
    <?php if ($mCat && $mCli): ?><p class="chico suave mb">Con módulos: el valor al mes se pone solo al guardar los módulos (abajo).</p><?php endif; ?>
    <button class="btn" type="submit">Guardar licencia</button>
  </form>
  <form method="post" action="<?= e(url('licencias')) ?>" class="tarjeta" id="pago-licencia">
    <h2>Registrar pago de licencia</h2>
    <p class="chico suave mb">Se anota como ingreso extra de <?= e($f['nombre']) ?> y la fecha "pagada hasta" se mueve sola.</p>
    <?= campo_csrf() ?><input type="hidden" name="i" value="<?= e($f['id']) ?>"><input type="hidden" name="accion" value="pago">
    <div class="fila-campos">
      <div class="campo"><label for="p-monto">Valor recibido ($)</label><input id="p-monto" name="monto" inputmode="decimal" value="<?= e(number_format((float) ($licencia['valor_mensual'] ?? 0), 2, ',', '')) ?>" required></div>
      <div class="campo"><label for="p-meses">Meses</label><select id="p-meses" name="meses"><?php foreach ([1, 3, 6, 12] as $m): ?><option value="<?= $m ?>"><?= $m ?> <?= $m === 1 ? 'mes' : 'meses' ?></option><?php endforeach; ?></select></div>
      <div class="campo"><label for="p-fecha">Fecha del pago</label><input id="p-fecha" type="date" name="fecha" value="<?= e(date('Y-m-d')) ?>" required></div>
      <div class="campo"><label for="p-met">Forma de pago</label><select id="p-met" name="metodo"><?php foreach (\Tukan\Finanzas::METODOS as $t): ?><option><?= e($t) ?></option><?php endforeach; ?></select></div>
    </div>
    <button class="btn btn-primario" type="submit"><?= icono('dinero') ?> Registrar pago</button>
  </form>
</div>
<?php endif; ?>

<?php if ($unico && $mCat && $mCli): ?>
<section class="seccion" id="modulos-licencia">
  <h2>Módulos de la instalación <span class="cuenta"><?= count($mCli['activos']) ?> de <?= count($mCat['modulos']) ?></span></h2>
  <p class="suave mb">Lo que tiene encendido <?= e($f['nombre']) ?>. El total al mes pasa a ser el valor de la licencia.</p>
  <?php $mInst = ['id' => $f['id'], 'nombre' => $f['nombre'], 'producto' => $f['producto']]; $mCid = 'unico';
        $mVolver = url('programa', ['id' => $f['id']]); $mPuede = puede_operacion($f['id'], 'cambiar_modulos');
        $mFlags = $cap['datos']['modulos'] ?? []; $mNombre = $f['nombre'];
        require __DIR__ . '/_modulos.php'; ?>
</section>
<?php endif; ?>

<?php if ($mCat): ?>
<section class="tarjeta seccion" id="catalogo">
  <h2>Catálogo de módulos y planes</h2>
  <p class="suave mb"><?= count($mCat['modulos']) ?> módulos y <?= count($mCat['planes']) ?> planes. Aquí se ponen los precios y qué trae cada plan.</p>
  <ul class="lista-p">
    <?php foreach ($mCat['planes'] as $p): ?><li><div class="dato"><strong><?= e($p['nombre']) ?> · <?= e(dinero($p['precio_mensual'])) ?> al mes</strong><span><?= $p['todos'] ? 'Todos los módulos' : e(\Tukan\Modulos::nombres($mCat, $p['modulos']) ?: 'Solo la base') ?></span></div></li><?php endforeach; ?>
  </ul>
  <a class="btn mt" href="<?= e(url('catalogo', ['i' => $f['id']])) ?>"><?= icono('editar') ?> <?= puede_operacion($f['id'], 'guardar_modulos') ? 'Editar precios y planes' : 'Ver el catálogo' ?></a>
</section>
<?php endif; ?>

<?php if (puede('programas')): ?>
<div class="rejilla dos seccion">
  <div class="tarjeta">
    <h2>Datos de la conexión</h2>
    <dl class="datos">
      <div><dt>ID</dt><dd class="mono"><?= e($f['id']) ?></dd></div>
      <div><dt>Dirección</dt><dd class="mono"><?= e($f['url']) ?></dd></div>
      <?php if ($f['carpeta']): ?><div><dt>Carpeta</dt><dd class="mono"><?= e($f['carpeta']) ?></dd></div><?php endif; ?>
      <div><dt>Comando</dt><dd class="mono chico"><?= e(Instancias::plantilla($f)) ?></dd></div>
      <div><dt>Secreto</dt><dd>Guardado cifrado · cambiado <?= e(fecha_hora((int) $f['secreto_cambiado'])) ?></dd></div>
      <?php if ($f['notas'] !== ''): ?><div><dt>Notas</dt><dd><?= nl2br(e($f['notas'])) ?></dd></div><?php endif; ?>
    </dl>
    <div class="acciones-form mt">
      <a class="btn" href="<?= e(url('programa_editar', ['id' => $f['id']])) ?>"><?= icono('editar') ?> Editar</a>
      <form method="post" action="<?= e(url('rotar')) ?>" data-confirmar="¿Cambiar el secreto de <?= e($f['nombre']) ?>? El secreto actual deja de servir de inmediato y tendrás que pegar el comando nuevo en su servidor.">
        <?= campo_csrf() ?><input type="hidden" name="id" value="<?= e($f['id']) ?>">
        <button class="btn" type="submit"><?= icono('llave') ?> Cambiar secreto</button>
      </form>
    </div>
  </div>
  <div class="tarjeta">
    <h2>Actividad reciente</h2>
    <?php if (!$registro): ?><p class="suave">Sin movimientos todavía.</p><?php else: ?>
      <ul class="lista-p">
        <?php foreach ($registro as $r): ?>
          <li><div class="dato"><strong><?= e(Auditoria::texto($r['accion'])) ?></strong><span><?= e($r['email'] ?? 'Sistema') ?> · <?= e(fecha_hora((int) $r['momento'])) ?><?= $r['detalle'] !== '' ? ' · ' . e($r['detalle']) : '' ?></span></div></li>
        <?php endforeach; ?>
      </ul>
      <p class="chico mt"><a href="<?= e(url('auditoria', ['instancia' => $f['id']])) ?>">Ver todo en Auditoría</a></p>
    <?php endif; ?>
  </div>
</div>

<?php
  $be = $baja['estado'] ?? null;
  $host = $baja['hostname'] ?? $hostBaja;
  $copia = $baja['copia_en'] ?? '/var/backups/borrados/';
  $enMarcha = in_array($be, ['programada', 'en_curso', 'borrada'], true);
  $remoto = $bajaDisp && $on;
  $guarderia = $f['producto'] === 'tuguarderia';
  // "Quitar del panel" (la página eliminar: se confirma escribiendo el ID)
  $formQuitar = function (string $boton = 'Quitar del panel') use ($f): string {
      return '<form method="post" action="' . e(url('eliminar')) . '" class="mt form-quitar" data-confirmar="¿Quitar ' . e($f['nombre']) . ' del panel? Su historial sigue en Auditoría.">'
          . campo_csrf() . '<input type="hidden" name="id" value="' . e($f['id']) . '">'
          . '<div class="campo"><label for="confirmar">Para confirmar, escribe su ID: <b class="mono">' . e($f['id']) . '</b></label>'
          . '<input id="confirmar" name="confirmar" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" required></div>'
          . '<button class="btn btn-peligro" type="submit">' . icono('basura') . ' ' . e($boton) . '</button></form>';
  };
?>
<section class="tarjeta seccion zona-peligro" id="baja"<?= in_array($be, \Tukan\Bajas::ACTIVAS, true) ? ' data-recargar="10"' : '' ?>>
<?php if ($enMarcha): ?>
  <h2><?= icono('basura') ?> Borrar esta instalación</h2>
  <?php if ($be === 'programada'): $desde = (int) $baja['borrar_desde']; ?>
    <p class="mb"><span class="estado-baja programada" id="baja-estado">Borrado programado</span></p>
    <p>Se borra <?= $desde > time() ? 'desde las <b>' . e(date('H:i', $desde)) . '</b> (faltan ' . e((string) max(1, (int) ceil(($desde - time()) / 60))) . ' min)' : '<b>en menos de 1 minuto</b>' ?>.
      La tarea del servidor revisa cada minuto y antes de borrar guarda una copia final en <span class="mono"><?= e($copia) ?></span>.</p>
    <?php if ($baja['mensaje']): ?><p class="chico suave mt"><?= e($baja['mensaje']) ?></p><?php endif; ?>
    <div class="acciones-form mt">
      <form method="post" action="<?= e(url('baja')) ?>"><?= campo_csrf() ?><input type="hidden" name="id" value="<?= e($f['id']) ?>"><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="op" value="<?= e($opBaja) ?>">
        <button class="btn btn-primario" type="submit">Cancelar el borrado</button></form>
      <form method="post" action="<?= e(url('baja')) ?>"><?= campo_csrf() ?><input type="hidden" name="id" value="<?= e($f['id']) ?>"><input type="hidden" name="accion" value="revisar">
        <button class="btn" type="submit">Revisar ahora</button></form>
    </div>
  <?php elseif ($be === 'en_curso'): ?>
    <p class="mb"><span class="estado-baja en_curso" id="baja-estado">Borrado en curso</span></p>
    <p>El servidor está guardando la copia final (<span class="mono"><?= e($copia) ?></span>) y borrando. Ya no se puede cancelar.</p>
    <?php if ($baja['mensaje']): ?><p class="chico suave mt"><?= e($baja['mensaje']) ?></p><?php endif; ?>
    <form method="post" action="<?= e(url('baja')) ?>" class="mt"><?= campo_csrf() ?><input type="hidden" name="id" value="<?= e($f['id']) ?>"><input type="hidden" name="accion" value="revisar">
      <button class="btn" type="submit">Revisar ahora</button></form>
  <?php elseif ($be === 'borrada'): $cfr = $baja['cloudflare']; ?>
    <p class="mb"><span class="estado-baja borrada" id="baja-estado">Borrado terminado</span></p>
    <p>La instalación <span class="mono"><?= e((string) $baja['carpeta']) ?></span> ya no responde<?= $baja['terminada'] ? ' (' . e(fecha_hora((int) $baja['terminada'])) . ')' : '' ?>.
      La copia final quedó en <span class="mono"><?= e($copia) ?></span> del servidor del cliente.</p>
    <?php if (is_array($cfr)): ?>
      <div class="aviso <?= $cfr['ok'] ? '' : 'error' ?> mt" id="baja-cloudflare"><?= icono($cfr['ok'] ? 'check' : 'info') ?><div><p><b>Cloudflare:</b></p><?php foreach ($cfr['mensajes'] as $m): ?><p><?= e($m) ?></p><?php endforeach; ?></div></div>
    <?php endif; ?>
    <?php if (!is_array($cfr) || !$cfr['ok']): ?>
      <div class="aviso alerta mt" id="baja-recordatorio"><?= icono('info') ?><div><p><b>Falta quitar <?= e($host ?: 'su dirección') ?> en Cloudflare.</b></p>
        <p>Zero Trust → Networks → Tunnels → tu túnel → Public Hostname → borra <span class="mono"><?= e($host ?: '…') ?></span>. Luego, en el dominio → DNS, borra su registro CNAME.</p>
        <?php if (!$cfListo): ?><p>O guarda un token de Cloudflare en <a href="<?= e(url('ajustes')) ?>#cloudflare">Ajustes</a> y el panel lo hace solo.</p><?php endif; ?></div></div>
      <?php if ($cfListo): ?>
        <form method="post" action="<?= e(url('baja')) ?>" class="mt"><?= campo_csrf() ?><input type="hidden" name="id" value="<?= e($f['id']) ?>"><input type="hidden" name="accion" value="cloudflare">
          <button class="btn" type="submit">Quitar de Cloudflare otra vez</button></form>
      <?php endif; ?>
    <?php endif; ?>
    <form method="post" action="<?= e(url('baja')) ?>" class="mt" data-confirmar="¿Quitar <?= e($f['nombre']) ?> del panel? Su historial sigue en Auditoría.">
      <?= campo_csrf() ?><input type="hidden" name="id" value="<?= e($f['id']) ?>"><input type="hidden" name="accion" value="quitar">
      <button class="btn btn-peligro" type="submit">Quitar también del panel</button>
      <p class="chico suave mt">La auditoría de <?= e($f['nombre']) ?> se conserva.</p>
    </form>
  <?php endif; ?>
<?php elseif ($remoto): ?>
  <h2><?= icono('basura') ?> Borrar esta instalación</h2>
  <?php if ($be === 'error'): ?><div class="aviso error mb" id="baja-error"><?= icono('info') ?><p>El último borrado no se hizo: <?= e((string) $baja['mensaje']) ?></p></div><?php endif; ?>
  <?php if ($be === 'cancelada'): ?><div class="aviso info mb"><?= icono('info') ?><p><?= e((string) ($baja['mensaje'] ?: 'El último borrado se canceló.')) ?></p></div><?php endif; ?>
      <p>Borra <b>todo</b> lo de este cliente en su servidor: el programa, la base de datos, las fotos y los respaldos automáticos.
        Antes se guarda una <b>copia final completa</b> en <span class="mono"><?= e($copia) ?></span> (se puede recuperar). Después de pedirlo tienes unos minutos para cancelar.</p>
      <p class="chico suave mt"><?= $cfListo ? 'Al terminar, el panel quita ' . e($host ?: 'su dirección') . ' de Cloudflare (túnel y DNS).' : 'Al terminar te recordaremos quitar ' . e($host ?: 'su dirección') . ' en Cloudflare (o configúralo en Ajustes y se hace solo).' ?></p>
      <form method="post" action="<?= e(url('baja')) ?>" class="mt" id="form-baja" data-confirmar="¿Borrar la instalación de <?= e($f['nombre']) ?>? Se guarda una copia final y luego se borra todo." data-dos-veces>
        <?= campo_csrf() ?><input type="hidden" name="id" value="<?= e($f['id']) ?>"><input type="hidden" name="accion" value="programar"><input type="hidden" name="op" value="<?= e($opBaja) ?>">
        <div class="fila-campos">
          <div class="campo"><label for="baja-id">Escribe el ID: <b class="mono"><?= e($f['id']) ?></b></label><input id="baja-id" name="confirmar" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" required></div>
          <div class="campo"><label for="baja-codigo">Código de 2 pasos (de tu app)</label><input id="baja-codigo" name="codigo" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required></div>
        </div>
        <button class="btn btn-peligro" type="submit"><?= icono('basura') ?> Borrar esta instalación</button>
      </form>
  <details class="quitar-solo mt">
    <summary>Solo quitarlo del panel (sin borrar nada en su servidor)</summary>
    <p class="chico suave mt">El programa sigue funcionando; solo deja de aparecer aquí y ya no se podrá entrar desde el panel.</p>
    <?= $formQuitar() ?>
  </details>
<?php elseif ($guarderia): $cfm = ($be === 'manual' && is_array($baja['cloudflare'] ?? null)) ? $baja['cloudflare'] : null; ?>
  <h2><?= icono('basura') ?> Borrar esta instalación</h2>
  <div class="aviso alerta mb" id="baja-manual"><?= icono('info') ?><div>
    <p><b>Este programa no responde o tiene un conector antiguo. Para borrarlo:</b></p>
    <p class="chico"><?= !$on ? 'Ahora mismo: ' . e($f['consultado'] === null ? 'todavía sin revisar' : rtrim(mb_strimwidth((string) ($f['error_detalle'] ?: 'sin conexión'), 0, 140, '…'), '. ')) . '.' : 'Su conector no sabe borrarse desde el panel (le falta «dar_de_baja»).' ?>
      <?= $bajaDisp ? 'Cuando vuelva a responder, podrás borrarlo desde aquí con un toque.' : '' ?></p>
  </div></div>
  <?php if ($be === 'error'): ?><div class="aviso error mb" id="baja-error"><?= icono('info') ?><p>El último borrado desde el panel no se hizo: <?= e((string) $baja['mensaje']) ?></p></div><?php endif; ?>
  <ol class="pasos pasos-baja">
    <li><div>
      <p><b>Copia este comando y pégalo en su servidor</b> (por SSH, con tu app de terminal).</p>
      <div class="comando" id="comando-borrar"><span class="prompt" aria-hidden="true">$ </span><span data-texto><?= e($comandoBorrar) ?></span></div>
      <div class="acciones-form">
        <button type="button" class="btn btn-oscuro" data-copiar="#comando-borrar [data-texto]"><?= icono('copiar') ?> Copiar comando</button>
        <span class="copiado" aria-live="polite"></span>
      </div>
      <?php if ($carpetaBaja === null): ?>
        <p class="chico error-campo mt">No se sabe la carpeta de esta instalación: cambia <span class="mono">CARPETA</span> por la suya, o escríbela en <a href="<?= e(url('programa_editar', ['id' => $f['id']])) ?>">Editar → Carpeta</a>.</p>
      <?php endif; ?>
      <p class="chico suave mt">Te pide escribir otra vez la carpeta para confirmar. Antes de borrar guarda una <b>copia final completa</b> en <span class="mono"><?= e($copia) ?></span> (si falla la copia, no borra nada).</p>
    </div></li>
    <li><div>
      <p><b>Quita su dirección en Cloudflare</b><?= $host ? ': <span class="mono">' . e($host) . '</span>' : '' ?>.</p>
      <?php if ($cfm): ?>
        <div class="aviso <?= $cfm['ok'] ? '' : 'error' ?> mt" id="baja-cloudflare"><?= icono($cfm['ok'] ? 'check' : 'info') ?><div><p><b>Cloudflare:</b></p><?php foreach ($cfm['mensajes'] as $m): ?><p><?= e($m) ?></p><?php endforeach; ?></div></div>
      <?php endif; ?>
      <?php if ($cfListo): ?>
        <?php if (!$cfm || !$cfm['ok']): ?>
        <p class="chico suave">El panel lo hace solo con el token guardado en Ajustes: quita del túnel solo esa dirección y su registro DNS.</p>
        <form method="post" action="<?= e(url('baja')) ?>" class="mt" data-confirmar="¿Quitar <?= e($host ?: 'su dirección') ?> de Cloudflare? La página de <?= e($f['nombre']) ?> deja de abrirse desde internet.">
          <?= campo_csrf() ?><input type="hidden" name="id" value="<?= e($f['id']) ?>"><input type="hidden" name="accion" value="cloudflare_manual">
          <button class="btn" type="submit"><?= icono('nube') ?> <?= $cfm ? 'Intentar otra vez en Cloudflare' : 'Quitar solo de Cloudflare' ?></button></form>
        <?php endif; ?>
      <?php else: ?>
        <p class="chico suave">Zero Trust → Networks → Tunnels → tu túnel → Public Hostname → borra <span class="mono"><?= e($host ?: '…') ?></span>. Luego, en el dominio → DNS, borra su registro CNAME.
          O guarda un token de Cloudflare en <a href="<?= e(url('ajustes')) ?>#cloudflare">Ajustes</a> y el panel lo hace con un toque.</p>
      <?php endif; ?>
    </div></li>
    <li><div>
      <p><b>Quítalo del panel.</b> Su historial sigue en la Auditoría.</p>
      <?= $formQuitar() ?>
    </div></li>
  </ol>
<?php else: ?>
  <h2><?= icono('basura') ?> Quitar este programa del panel</h2>
  <?php if (!$on): ?>
    <p><?= $f['consultado'] === null ? 'Este programa todavía no se ha revisado.' : 'Este programa no responde' . ($f['error_detalle'] ? ' (' . e(rtrim(mb_strimwidth((string) $f['error_detalle'], 0, 120, '…'), '. ')) . ')' : '') . '.' ?>
      Desde aquí solo se puede <b>quitar del panel</b>: deja de aparecer y ya no se podrá entrar desde aquí. <b>No borra nada en su servidor</b>; si ya no se usa, apágalo allá y quita su dirección en Cloudflare.</p>
  <?php else: ?>
    <p><?= e($tp['nombre']) ?> atiende a varios clientes: para borrar <b>uno</b>, usa <a href="<?= e(url('clientes', ['i' => $f['id']])) ?>">Clientes</a> → Borrar (va 30 días a la papelera).
      Quitarlo del panel solo deja de mostrarlo aquí: el programa sigue funcionando.</p>
  <?php endif; ?>
  <?= $formQuitar() ?>
<?php endif; ?>
</section>
<?php endif; ?>
