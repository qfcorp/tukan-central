<?php
use Tukan\{Clientes, Contacto, Historial, Operaciones, Solicitudes};

$nombre = $c['nombre'] ?? '';
$f = $c['fechas'] ?? [];
$dias = $f['dias_para_vencer'] ?? null;
$estado = $c['estado'] ?? 'activo';
$prod = $inst['producto'];
$mensajesNombres = ['bienvenida' => 'Bienvenida', 'recordar_prueba' => 'Recordar la prueba', 'recordar_renovacion' => 'Recordar la renovación', 'vencido' => 'Plan vencido'];
$volver = aqui();
$pap = is_array($c['papelera'] ?? null) ? $c['papelera'] : null;
$pau = is_array($c['pausa'] ?? null) ? $c['pausa'] : null;
?>
<p class="migas"><a href="<?= e(url('clientes')) ?>">Clientes</a> / <?= e($nombre) ?></p>
<div class="cabeza p-<?= e($prod) ?>">
  <div class="prog-top">
    <?= ico_programa($prod, false) ?>
    <div class="prog-tit">
      <h1><?= e($nombre) ?></h1>
      <span class="url"><?= e($inst['nombre']) ?><?= !empty($c['tipo_nombre']) ? ' · ' . e($c['tipo_nombre']) : '' ?><?= !empty($c['ciudad']) ? ' · ' . e($c['ciudad']) : '' ?></span>
    </div>
  </div>
  <div class="acciones"><?php if (puede('entrar')): ?><?= boton_entrar($inst['id'], $c['ir'] ?? null, 'Abrir en ' . e($inst['nombre']) . ' ' . icono('externo'), 'btn') ?><?php endif; ?></div>
</div>
<?php if ($problema): ?>
  <div class="aviso info mb"><?= icono('info') ?><p><?= e($inst['nombre']) ?> no respondió ahora (<?= e($problema->getMessage()) ?>). Se muestran los datos guardados<?= $fila ? ' ' . e(hace((int) $fila['visto'])) : '' ?>; los botones vuelven cuando el programa conteste.</p></div>
<?php endif; ?>

<?php if ($pap): $dp = !empty($pap['hasta']) ? dias_hasta($pap['hasta']) : null; ?>
  <div class="aviso error mb en-papelera" role="status"><?= icono('basura') ?><div>
    <p><strong>Está en la papelera</strong><?= !empty($pap['desde']) ? ' desde el ' . e(fecha_dm($pap['desde'], true)) : '' ?><?= !empty($pap['quien']) ? ' (lo borró ' . e((string) $pap['quien']) . ')' : '' ?>.
      <?php if (!empty($pap['hasta'])): ?>Se borra para siempre el <strong><?= e(fecha_dm($pap['hasta'], true)) ?></strong> (<?= e(falta($dp)) ?>); hasta entonces se puede restaurar tal como estaba.<?php else: ?>Se puede restaurar mientras siga en la papelera.<?php endif; ?></p>
    <?php if (!empty($pap['motivo'])): ?><p class="chico">Motivo: <?= e((string) $pap['motivo']) ?></p><?php endif; ?>
    <?php if (!empty($pap['copia']) && ve_contacto()): ?><p class="chico mono copia">Copia de sus datos: <?= e((string) $pap['copia']) ?></p><?php endif; ?>
  </div></div>
<?php endif; ?>

<section class="estado-cliente e-<?= e($estado) ?><?= $pau ? ' pausado' : '' ?>" aria-label="Estado">
  <div class="ec-principal">
    <span class="ec-estado"><?= e($pau ? 'Pausado' : (Clientes::ESTADOS[$estado] ?? $estado)) ?><?= !empty($c['en_gracia']) ? ' · en gracia' : '' ?></span>
    <span class="ec-texto"><?= e($c['estado_texto'] ?? '') ?></span>
    <?php if ($pau): ?>
      <p class="ec-pausa"><strong><?= e((string) $pau['tipo_nombre']) ?></strong><?= !empty($pau['motivo']) ? ': ' . e((string) $pau['motivo']) : '' ?>
        <span class="chico suave"><?= !empty($pau['desde']) ? 'desde ' . e(fecha_dm(substr((string) $pau['desde'], 0, 10), true)) : '' ?><?= !empty($pau['quien']) ? ' · ' . e(Contacto::enmascararTexto((string) $pau['quien'])) : '' ?></span>
        <?php if (in_array($pau['tipo'], ['revision', 'abuso'], true)): ?><span class="chico suave">El cliente ve un texto general (no este motivo).</span><?php endif; ?></p>
    <?php endif; ?>
  </div>
  <dl class="ec-datos">
    <div><dt>Plan</dt><dd><?= e($c['plan']['nombre'] ?? '—') ?></dd></div>
    <div><dt>Vence</dt><dd><?= $f['vence'] ?? null ? e(fecha_dm($f['vence'], true)) . ' <small>' . e(falta($dias)) . '</small>' : 'No vence' ?></dd></div>
    <div><dt>Cliente desde</dt><dd><?= e(fecha_dm($f['alta'] ?? null, true)) ?></dd></div>
    <div><dt>Vino por</dt><dd><?= e(Clientes::ORIGENES[$c['origen'] ?? 'desconocido'] ?? '—') ?></dd></div>
    <?php if (puede('finanzas')): ?>
    <div><dt>Ha pagado</dt><dd><?= e(dinero($c['pagos']['total'] ?? 0)) ?> <small><?= (int) ($c['pagos']['cantidad'] ?? 0) ?> pagos</small></dd></div>
    <div><dt>Vale al mes</dt><dd><?= e(dinero($c['mensual_estimado'] ?? 0)) ?></dd></div>
    <?php endif; ?>
    <div><dt>Último uso</dt><dd><?= e(hace_iso($c['ultima_actividad'] ?? null)) ?></dd></div>
  </dl>
</section>

<?php if ($acciones): ?>
<div class="tarjeta seccion">
  <h2>¿Qué hacemos?</h2>
  <div class="sol-acc grande"><?= botones_acciones($acciones, ['inst' => $inst['id'], 'cliente_id' => $c['id'], 'nombre' => $nombre, 'volver' => $volver]) ?></div>
</div>
<?php endif; ?>

<div class="rejilla dos seccion">
  <div class="tarjeta">
    <h2>Dueño</h2>
    <?php if (!ve_contacto()): ?>
      <p class="suave">El contacto del dueño solo lo ve el dueño del panel.</p>
      <?php if (!empty($c['dueno']['nombre'])): ?><p class="mt"><strong><?= e($c['dueno']['nombre']) ?></strong></p><?php endif; ?>
    <?php elseif (!$contacto || !array_filter($contacto)): ?>
      <p class="suave">El programa no mandó el contacto del dueño.</p>
    <?php else: ?>
      <dl class="datos">
        <?php if ($contacto['nombre'] ?? null): ?><div><dt>Nombre</dt><dd><strong><?= e($contacto['nombre']) ?></strong></dd></div><?php endif; ?>
        <?php if ($contacto['telefono'] ?? null): ?><div><dt>Teléfono</dt><dd class="mono"><?= e($contacto['telefono']) ?></dd></div><?php endif; ?>
        <?php if ($contacto['email'] ?? null): ?><div><dt>Correo</dt><dd><?= e($contacto['email']) ?></dd></div><?php endif; ?>
      </dl>
      <div class="acciones-form mt">
        <?php if ($contacto['telefono'] ?? null): ?><a class="btn btn-chico" href="tel:<?= e(preg_replace('/[^\d+]/', '', $contacto['telefono'])) ?>"><?= icono('telefono') ?> Llamar</a><?php endif; ?>
        <?= boton_whatsapp($contacto['whatsapp'] ?? null, 'Hola ' . explode(' ', (string) ($contacto['nombre'] ?? ''))[0] . ', le saluda Tukán Software.') ?>
        <?php if ($contacto['email'] ?? null): ?><a class="btn btn-chico" href="mailto:<?= e($contacto['email']) ?>"><?= icono('correo') ?> Correo</a><?php endif; ?>
      </div>
      <?php if ($mensajes && ($contacto['whatsapp'] ?? null) && $fila): ?>
        <h3 class="mt">Mensajes listos (los escribe <?= e($inst['nombre']) ?>)</h3>
        <?php if ($recordado): ?><p class="recordado mt"><?= icono('check') ?> Recordado <?= e(hace($recordado['momento'])) ?><?= $recordado['quien'] ? ' por ' . e(explode(' ', $recordado['quien'])[0]) : '' ?></p><?php endif; ?>
        <div class="mensajes-listos">
          <?php foreach ($mensajes as $k => $txt): ?>
            <form method="post" action="<?= e(url('recordar')) ?>" target="_blank"><?= campo_csrf() ?>
              <input type="hidden" name="i" value="<?= e($inst['id']) ?>"><input type="hidden" name="id" value="<?= e($c['id']) ?>">
              <input type="hidden" name="msg" value="<?= e($txt) ?>"><input type="hidden" name="tipo" value="<?= e((string) $k) ?>">
              <button class="mensaje-listo" type="submit"><span class="ml-t"><?= icono('whatsapp') ?> <?= e($mensajesNombres[$k] ?? ucfirst(str_replace('_', ' ', (string) $k))) ?></span><span class="ml-x"><?= e(mb_strimwidth($txt, 0, 140, '…')) ?></span></button>
            </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="tarjeta">
    <h2>Datos</h2>
    <dl class="datos">
      <?php foreach ($c['uso'] ?? [] as $k => $v): ?><div><dt><?= e($k) ?></dt><dd><?= e(numero_corto($v)) ?></dd></div><?php endforeach; ?>
      <?php if (!empty($det['usuarios'])): ?><div><dt>Usuarios</dt><dd><?= (int) $det['usuarios'] ?></dd></div><?php endif; ?>
      <?php if (!empty($c['vendedor'])): ?><div><dt>Vendedor</dt><dd><?= e($c['vendedor']) ?></dd></div><?php endif; ?>
      <?php if (!empty($det['recomendado_por']['nombre'])): ?><div><dt>Recomendado por</dt><dd><?= e((string) $det['recomendado_por']['nombre']) ?></dd></div><?php endif; ?>
      <?php if (is_string($det['enlace_publico'] ?? null) && preg_match('#^https?://#', $det['enlace_publico'])): ?><div><dt>Página pública</dt><dd><a href="<?= e($det['enlace_publico']) ?>" target="_blank" rel="noopener noreferrer"><?= e(host($det['enlace_publico'])) ?></a></dd></div><?php endif; ?>
      <?php if (is_string($det['dominio'] ?? null) && $det['dominio'] !== ''): ?><div><dt>Dominio</dt><dd class="mono"><?= e($det['dominio']) ?></dd></div><?php endif; ?>
      <?php if (!empty($c['estado_detalle'])): ?><div><dt>Estado en el programa</dt><dd><?= e($cap['estados_detalle'][$c['estado_detalle']] ?? $c['estado_detalle']) ?></dd></div><?php endif; ?>
    </dl>
    <?php if (is_string($det['notas_admin'] ?? null) && trim($det['notas_admin']) !== ''): ?>
      <h3 class="mt">Nota interna</h3><p class="nota-interna"><?= nl2br(e($det['notas_admin'])) ?></p>
    <?php endif; ?>
  </div>
</div>

<?php if ($solicitudes): ?>
<div class="tarjeta seccion">
  <h2>Solicitudes abiertas <span class="cuenta rojo"><?= count($solicitudes) ?></span></h2>
  <ul class="lista-p">
    <?php foreach ($solicitudes as $s): ?>
      <li><span class="tipo-sol chico" aria-hidden="true"><?= Solicitudes::iconoTipo($s['tipo']) ?></span><div class="dato"><a href="<?= e(url('solicitud', ['i' => $s['instancia_id'], 'id' => $s['id']])) ?>"><strong><?= e($s['titulo']) ?></strong></a><span><?= e((string) $s['resumen']) ?> · <?= e(hace_iso($s['fecha'])) ?></span></div></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if ($pagos && puede('finanzas')): $anula = puede_operacion($inst['id'], 'anular_pago'); ?>
<div class="tarjeta seccion">
  <h2>Pagos</h2>
  <div class="tabla-env">
  <table class="tabla apilable" id="tabla-pagos">
    <thead><tr><th>Fecha</th><th>Valor</th><th>Plan y tiempo</th><th>Cubre</th><th>Forma</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pagos as $p): if (!is_array($p)) continue; $anulado = ($p['anulado'] ?? false) === true; ?>
      <tr class="<?= $anulado ? 'anulado' : '' ?>">
        <td data-t="Fecha" class="nowrap"><?= e(fecha_dm((string) ($p['fecha'] ?? ''), true)) ?></td>
        <td data-t="Valor"><strong><?= e(dinero((float) ($p['monto'] ?? 0))) ?></strong><?= $anulado ? ' <span class="etiqueta mal">Anulado</span>' : '' ?></td>
        <td data-t="Plan"><?= e((string) ($p['plan']['nombre'] ?? '')) ?> · <?= (int) ($p['meses'] ?? 0) ?> <?= (int) ($p['meses'] ?? 0) === 1 ? 'mes' : 'meses' ?></td>
        <td data-t="Cubre" class="nowrap"><?= e(fecha_dm((string) ($p['desde'] ?? ''))) ?> → <?= e(fecha_dm((string) ($p['hasta'] ?? ''), true)) ?></td>
        <td data-t="Forma"><?= e((string) ($p['metodo_nombre'] ?? $p['metodo'] ?? '')) ?><?= !empty($p['nota']) ? '<br><small class="suave">' . e((string) $p['nota']) . '</small>' : '' ?></td>
        <td><?php if ($anula && !$anulado && ($p['puede_anular'] ?? false) === true): ?>
          <a class="btn btn-chico btn-peligro" data-hoja href="<?= e(url('accion', ['i' => $inst['id'], 'a' => 'anular_pago', 'cliente' => $c['id'], 'pago' => (string) $p['id'], 'volver' => $volver])) ?>">Anular</a>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php if ($historial): ?>
<div class="tarjeta seccion">
  <h2>Pausas y borrados</h2>
  <ul class="lista-p historial-pb">
    <?php foreach ($historial as $h): ?>
      <li class="h-<?= e($h['accion']) ?>"><div class="dato"><strong><?= e(Historial::titulo($h)) ?></strong>
        <span><?= e(fecha_hora($h['momento'])) ?> · <?= e($h['quien'] ?? '—') ?></span>
        <?php if ($h['motivo']): ?><span class="h-motivo">«<?= e($h['motivo']) ?>»</span><?php endif; ?>
        <?php if (!empty($h['datos']['borrar_definitivo_el'])): ?><span>Se borra para siempre el <?= e(fecha_dm($h['datos']['borrar_definitivo_el'], true)) ?></span><?php endif; ?>
        <?php if (!empty($h['datos']['copia']) && ve_contacto()): ?><span class="mono copia">Copia: <?= e($h['datos']['copia']) ?></span><?php endif; ?>
      </div></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if ($registro || $operaciones): ?>
<div class="rejilla dos seccion">
  <?php if ($registro): ?>
  <div class="tarjeta">
    <h2>Últimas acciones (en <?= e($inst['nombre']) ?>)</h2>
    <ul class="lista-p">
      <?php foreach ($registro as $x): if (!is_array($x)) continue; ?>
        <li><div class="dato"><strong><?= e((string) ($x['texto'] ?? '')) ?></strong><span><?= e(isset($x['momento']) ? fecha_hora((int) strtotime((string) $x['momento'])) : '') ?> · <?= e(Contacto::enmascararTexto((string) ($x['quien'] ?? ''))) ?></span></div></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
  <?php if ($operaciones): ?>
  <div class="tarjeta">
    <h2>Lo que se hizo desde el panel</h2>
    <ul class="lista-p">
      <?php foreach ($operaciones as $o): ?>
        <li><div class="dato"><strong><?= e(Operaciones::nombre($o['accion'])) ?></strong><span><?= e(fecha_hora((int) $o['creada'])) ?> · <?= e($o['quien'] ?? '—') ?> · <?= $o['estado'] === 'ok' ? 'Listo' : ($o['estado'] === 'sin_respuesta' ? 'Sin respuesta' : 'No se hizo') ?><?= $o['mensaje'] ? ': ' . e($o['mensaje']) : '' ?></span></div></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
