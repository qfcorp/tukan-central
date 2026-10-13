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
        <div><dt>Pruebas que vencen</dt><dd><?= count($datos['pruebas_vencen_7d']) ?: 'Ninguna' ?></dd></div>
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
      <?php foreach ($resumen as $t => $si): if ($unico && in_array($t, ['Crear demos', 'Alargar pruebas', 'Registrar pagos', 'Aprobar comprobantes', 'Finanzas (ingresos por mes)', 'Ver las solicitudes (bandeja)'], true)) continue; ?>
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

<details class="tarjeta seccion">
  <summary><b>Quitar este programa del panel</b></summary>
  <p class="suave mt">El programa sigue funcionando; solo deja de aparecer aquí y ya no se podrá entrar desde el panel. Para confirmar, escribe su ID: <b class="mono"><?= e($f['id']) ?></b></p>
  <form method="post" action="<?= e(url('eliminar')) ?>" class="mt">
    <?= campo_csrf() ?><input type="hidden" name="id" value="<?= e($f['id']) ?>">
    <div class="campo"><label for="confirmar">ID del programa</label><input id="confirmar" name="confirmar" type="text" autocomplete="off" required></div>
    <button class="btn btn-peligro" type="submit"><?= icono('basura') ?> Quitar del panel</button>
  </form>
</details>
<?php endif; ?>
