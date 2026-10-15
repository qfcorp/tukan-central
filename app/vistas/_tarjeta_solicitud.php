<?php
/** Tarjeta de una solicitud en la bandeja. Variable: $s (Solicitudes::completa) */
use Tukan\Solicitudes;

$q = $s['quien'];
$quien = $q['organizacion'] ?? $q['nombre'] ?? null;
$img = null;
foreach ($s['adjuntos'] as $a) if (str_starts_with($a['tipo_mime'], 'image/')) { $img = $a; break; }
$verAdj = $img && (ve_contacto() || $s['tipo'] === 'soporte');
$cerrada = in_array($s['estado_local'], ['atendida', 'descartada', 'cerrada'], true);
$detalleUrl = url('solicitud', ['i' => $s['instancia_id'], 'id' => $s['id']]);
?>
<article class="sol p-<?= e($s['producto']) ?> pr-<?= e($s['prioridad']) ?><?= $cerrada ? ' cerrada' : '' ?>" data-sol="<?= e($s['instancia_id'] . '|' . $s['id']) ?>">
  <div class="sol-top">
    <span class="tipo-sol" aria-hidden="true"><?= Solicitudes::iconoTipo($s['tipo']) ?></span>
    <div class="sol-tit">
      <h3><a href="<?= e($detalleUrl) ?>"><?= e($s['tipo'] === 'comprobante' && !empty($s['detalle']['cambia_plan']) ? 'Comprobante y cambio de plan' : $s['titulo']) ?></a></h3>
      <span class="sol-meta"><span class="prog-chip"><?= e($s['programa']) ?></span> <?= e(hace_iso($s['fecha'])) ?><?= $s['prioridad'] === 'alta' ? ' · <b class="urgente">Urgente</b>' : '' ?><?= $s['vence_el'] ? ' · responder antes del ' . e(fecha_dm($s['vence_el'])) : '' ?></span>
    </div>
    <?php if ($cerrada): ?><span class="etiqueta<?= $s['estado_local'] === 'descartada' ? '' : ' ok' ?>"><?= $s['estado_local'] === 'descartada' ? 'Descartada' : 'Atendida' ?></span>
    <?php elseif ($s['estado_local'] === 'en_proceso'): ?><span class="etiqueta alerta">En proceso</span><?php endif; ?>
  </div>
  <div class="sol-cuerpo">
    <div class="sol-texto">
      <?php if ($quien): ?><p class="sol-quien"><strong><?= e($quien) ?></strong><?php if (($q['organizacion'] ?? null) && ($q['nombre'] ?? null)): ?> · <?= e($q['nombre']) ?><?php endif; ?><?php if ($q['telefono'] ?? null): ?> · <span class="mono"><?= e($q['telefono']) ?></span><?php endif; ?></p><?php endif; ?>
      <?php if ($s['resumen']): ?><p class="sol-resumen"><?= e($s['resumen']) ?></p><?php endif; ?>
    </div>
    <?php if ($verAdj): ?>
      <a class="miniatura" href="<?= e($detalleUrl) ?>"><img src="<?= e(url('adjunto', ['i' => $s['instancia_id'], 'sol' => $s['id'], 'adj' => $img['id']])) ?>" alt="<?= e($img['nombre']) ?>" loading="lazy" width="96" height="64"></a>
    <?php elseif ($s['adjuntos']): ?><span class="chico suave"><?= icono('adjunto') ?> <?= count($s['adjuntos']) ?></span><?php endif; ?>
  </div>
  <?php if (!$cerrada): $pb = botones_pausa_borrar_sol($s); ?>
  <div class="sol-acc">
    <?= botones_acciones($s['acciones'], ['inst' => $s['instancia_id'], 'sol' => $s['id'], 'cliente_id' => $s['cliente_id'], 'nombre' => $quien, 'volver' => aqui()]) ?>
    <?php if ($pb['destacar'] && $pb['html'] !== ''): ?><?= $pb['html'] ?><?php endif; ?>
    <a class="btn btn-chico btn-link-btn" href="<?= e($detalleUrl) ?>">Ver detalle</a>
  </div>
  <?php if (!$pb['destacar'] && $pb['html'] !== ''): ?><div class="sol-acc-2" role="group" aria-label="Más acciones con el cliente"><?= $pb['html'] ?></div><?php endif; ?>
  <?php endif; ?>
</article>
