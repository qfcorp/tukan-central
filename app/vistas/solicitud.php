<?php
use Tukan\{Operaciones, Solicitudes};

$q = $s['quien'];
$quien = $q['organizacion'] ?? $q['nombre'] ?? $s['titulo'];
$cerrada = in_array($s['estado_local'], ['atendida', 'descartada', 'cerrada'], true);
$etq = ['plan' => 'Plan', 'meses' => 'Meses', 'forma_pago' => 'Forma de pago', 'nota' => 'Nota', 'enviado_por' => 'Enviado por', 'cambia_plan' => 'Cambia de plan',
        'plan_actual' => 'Plan actual', 'subtipo' => 'Tipo', 'asunto' => 'Asunto', 'tipo' => 'Tipo de cuenta', 'ciudad' => 'Ciudad', 'tamano' => 'Tamaño',
        'plan_interes' => 'Plan de interés', 'mensaje' => 'Mensaje', 'etapa' => 'Etapa', 'derecho' => 'Derecho', 'respuesta' => 'Respuesta', 'dominio' => 'Dominio'];
$valor = function ($v) {
    if (is_bool($v)) return $v ? 'Sí' : 'No';
    if (is_array($v)) return isset($v['nombre']) ? (string) $v['nombre'] : implode(', ', array_map(fn($x) => is_scalar($x) ? (string) $x : '…', $v));
    return (string) $v;
};
$mensajes = is_array($s['detalle']['mensajes'] ?? null) ? $s['detalle']['mensajes'] : [];
$verAdj = ve_contacto() || $s['tipo'] === 'soporte';
?>
<p class="migas"><a href="<?= e(url('solicitudes')) ?>">Solicitudes</a> / <?= e(Solicitudes::nombreTipo($s['tipo'])) ?></p>
<div class="cabeza p-<?= e($s['producto']) ?>">
  <div class="prog-top">
    <span class="tipo-sol grande" aria-hidden="true"><?= Solicitudes::iconoTipo($s['tipo']) ?></span>
    <div class="prog-tit">
      <h1><?= e($s['titulo']) ?></h1>
      <span class="url"><span class="prog-chip"><?= e($s['programa']) ?></span> <?= e(hace_iso($s['fecha'])) ?> · <?= e(fecha_hora((int) strtotime($s['fecha']))) ?></span>
    </div>
  </div>
  <div class="acciones">
    <?php if ($cerrada): ?><span class="etiqueta ok grande">Ya no está pendiente</span>
    <?php elseif ($s['estado_local'] === 'en_proceso'): ?><span class="etiqueta alerta grande">En proceso</span>
    <?php else: ?><span class="etiqueta mal grande">Por atender</span><?php endif; ?>
  </div>
</div>
<?php if ($problema): ?>
  <div class="aviso info mb"><?= icono('info') ?><p><?= e($inst['nombre']) ?> no respondió ahora (<?= e($problema->getMessage()) ?>). Se muestran los datos guardados <?= e(hace((int) $s['ultima_vez'])) ?>.</p></div>
<?php endif; ?>

<?php $pb = $cerrada ? ['html' => '', 'destacar' => false] : botones_pausa_borrar_sol($s); ?>
<?php if (!$cerrada && ($s['acciones'] || $pb['html'] !== '')): ?>
<div class="tarjeta acciones-grandes">
  <h2>¿Qué hacemos?</h2>
  <div class="sol-acc grande"><?= botones_acciones($s['acciones'], ['inst' => $s['instancia_id'], 'sol' => $s['id'], 'cliente_id' => $s['cliente_id'], 'nombre' => $quien, 'volver' => aqui()]) ?><?= $pb['destacar'] ? $pb['html'] : '' ?></div>
  <?php if ($pb['html'] !== '' && $pb['destacar']): ?>
    <p class="chico suave mt"><?= icono('info') ?> ¿No va a pagar? <b>Pausar</b> le corta el acceso con el motivo a la vista; <b>Borrar cliente</b> lo manda 30 días a la papelera (se puede restaurar) y esta solicitud queda atendida.</p>
  <?php elseif ($pb['html'] !== ''): ?>
    <div class="sol-acc-2" role="group" aria-label="Más acciones con el cliente"><span class="chico suave">Con el cliente:</span><?= $pb['html'] ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="rejilla dos seccion">
  <div class="tarjeta">
    <h2>Quién</h2>
    <dl class="datos">
      <?php if ($q['organizacion'] ?? null): ?><div><dt>Negocio</dt><dd><strong><?= e($q['organizacion']) ?></strong></dd></div><?php endif; ?>
      <?php if ($q['nombre'] ?? null): ?><div><dt>Contacto</dt><dd><?= e($q['nombre']) ?></dd></div><?php endif; ?>
      <?php if ($q['telefono'] ?? null): ?><div><dt>Teléfono</dt><dd class="mono"><?= e($q['telefono']) ?></dd></div><?php endif; ?>
      <?php if ($q['email'] ?? null): ?><div><dt>Correo</dt><dd><?= e($q['email']) ?></dd></div><?php endif; ?>
      <?php if ($cliente): ?><div><dt>Cliente</dt><dd><a href="<?= e(url('cliente', ['i' => $s['instancia_id'], 'id' => $cliente['cliente_id']])) ?>"><?= e($cliente['nombre']) ?></a> <?= etiqueta_estado($cliente['estado']) ?></dd></div><?php endif; ?>
    </dl>
    <?php if (ve_contacto()): ?>
      <div class="acciones-form mt">
        <?php if ($q['telefono'] ?? null): ?><a class="btn btn-chico" href="tel:<?= e(preg_replace('/[^\d+]/', '', $q['telefono'])) ?>"><?= icono('telefono') ?> Llamar</a><?php endif; ?>
        <?= boton_whatsapp($q['whatsapp'] ?? null, 'Hola ' . explode(' ', (string) ($q['nombre'] ?? ''))[0] . ', le saluda Tukán Software.') ?>
        <?php if ($q['email'] ?? null): ?><a class="btn btn-chico" href="mailto:<?= e($q['email']) ?>"><?= icono('correo') ?> Correo</a><?php endif; ?>
      </div>
    <?php elseif ($q['telefono'] ?? $q['email'] ?? null): ?>
      <p class="chico suave mt">El teléfono y el correo completos solo los ve el dueño del panel.</p>
    <?php endif; ?>
  </div>
  <div class="tarjeta">
    <h2>Detalle</h2>
    <?php if ($s['resumen']): ?><p class="mb"><?= e($s['resumen']) ?></p><?php endif; ?>
    <dl class="datos">
      <?php if ($s['monto'] !== null): ?><div><dt>Valor</dt><dd><strong><?= e(dinero($s['monto'])) ?></strong></dd></div><?php endif; ?>
      <?php if ($s['vence_el']): ?><div><dt>Responder antes del</dt><dd><?= e(fecha_dm($s['vence_el'], true)) ?></dd></div><?php endif; ?>
      <?php foreach ($s['detalle'] as $k => $v): if ($k === 'mensajes' || $v === null || $v === '' || $v === []) continue; ?>
        <div><dt><?= e($etq[$k] ?? ucfirst(str_replace('_', ' ', (string) $k))) ?></dt><dd><?= nl2br(e($valor($v))) ?></dd></div>
      <?php endforeach; ?>
      <div><dt>Estado en el programa</dt><dd><?= e($s['estado_producto'] ?? $s['estado']) ?></dd></div>
    </dl>
  </div>
</div>

<?php if ($s['adjuntos']): ?>
<div class="tarjeta seccion">
  <h2>Archivos</h2>
  <?php if (!$verAdj): ?><p class="suave">Los comprobantes solo los ve el dueño del panel.</p><?php else: ?>
  <div class="adjuntos">
    <?php foreach ($s['adjuntos'] as $a): $urlAdj = url("adjunto", ["i" => $s["instancia_id"], "sol" => $s["id"], "adj" => $a["id"]]); ?>
      <?php if (str_starts_with($a['tipo_mime'], 'image/')): ?>
        <a href="<?= e($urlAdj) ?>" target="_blank" rel="noopener"><img src="<?= e($urlAdj) ?>" alt="<?= e($a['nombre']) ?>" loading="lazy"></a>
      <?php else: ?>
        <a class="btn" href="<?= e($urlAdj) ?>" target="_blank" rel="noopener"><?= icono('adjunto') ?> <?= e($a['nombre']) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($mensajes): ?>
<div class="tarjeta seccion">
  <h2>Conversación</h2>
  <ol class="chat">
    <?php foreach ($mensajes as $m): if (!is_array($m)) continue; $nuestro = ($m['de'] ?? '') !== 'cliente'; ?>
      <li class="<?= $nuestro ? 'nuestro' : 'suyo' ?>"><p><?= nl2br(e((string) ($m['texto'] ?? ''))) ?></p><small><?= e((string) ($m['autor'] ?? '')) ?> · <?= e(isset($m['momento']) ? fecha_hora((int) strtotime((string) $m['momento'])) : '') ?></small></li>
    <?php endforeach; ?>
  </ol>
</div>
<?php endif; ?>

<?php if ($historial): ?>
<div class="tarjeta seccion">
  <h2>Lo que se hizo desde el panel</h2>
  <ul class="lista-p">
    <?php foreach ($historial as $h): ?>
      <li><div class="dato"><strong><?= e(Operaciones::nombre($h['accion'])) ?></strong>
        <span><?= e(fecha_hora((int) $h['creada'])) ?> · <?= e($h['quien'] ?? '—') ?> · <?= $h['estado'] === 'ok' ? 'Listo' : ($h['estado'] === 'sin_respuesta' ? 'Sin respuesta del programa' : 'No se hizo') ?><?= $h['mensaje'] ? ': ' . e($h['mensaje']) : '' ?></span></div></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<p class="mt"><?= boton_entrar($s['instancia_id'], $s['ir'], 'Ver en ' . e($s['programa']) . ' ' . icono('externo'), 'btn') ?></p>
