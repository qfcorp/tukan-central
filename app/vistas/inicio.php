<?php
use Tukan\{Estado, Productos, Solicitudes};

$t = $totales;
$nombre = explode(' ', usuario()['nombre'])[0];
$hora = (int) date('G');
$saludo = $hora < 12 ? 'Buenos días' : ($hora < 19 ? 'Buenas tardes' : 'Buenas noches');
$activas = array_filter($tablero, fn($f) => (int) $f['activo']);
$conDatos = $t['con_negocios'] > 0;
$var = $t['variacion'];
$etiquetas = ['prueba' => 'En prueba', 'activos' => 'Activos', 'por_vencer' => 'Por vencer', 'vencidos' => 'Vencidos', 'suspendidos' => 'Suspendidos'];
$hayV1 = (bool) array_filter($activas, fn($f) => ($caps[$f['id']]['protocolo'] ?? 1) !== 2);
$v1Pend = count($pendientes['pruebas']) + count($pendientes['pagos']);
$ganancia = round($cobrado - $gastosMes, 2);
$boton = function (array $x): string {
    return '<form method="post" action="' . e(url('entrar')) . '">' . campo_csrf()
        . '<input type="hidden" name="id" value="' . e($x['instancia']) . '">'
        . '<button class="btn btn-chico" type="submit">Entrar a ' . e($x['programa']) . '</button></form>';
};
?>
<div class="cabeza" id="tablero" data-viejo="<?= $viejo ? '1' : '0' ?>">
  <div>
    <h1><?= e($saludo) ?>, <?= e($nombre) ?></h1>
    <p class="sub" id="actualizado">
      <?php if (!$activas): ?>Todavía no hay programas conectados.
      <?php elseif ($ultima): ?>Cifras de <?= count($activas) ?> <?= count($activas) === 1 ? 'programa' : 'programas' ?> · actualizadas <?= e(hace($ultima)) ?>
      <?php else: ?>Toca "Actualizar" para traer las cifras de tus programas.<?php endif; ?>
    </p>
  </div>
  <?php if ($activas): ?>
  <div class="acciones">
    <form method="post" action="<?= e(url('refrescar')) ?>" data-refrescar>
      <?= campo_csrf() ?>
      <button class="btn" type="submit"><?= icono('refrescar') ?> Actualizar</button>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php if ($activas): ?>
<section class="hoy" aria-label="Lo de hoy">
  <a class="kpi kpi-alerta<?= $porAtender ? ' hay' : '' ?>" href="<?= e(url('solicitudes')) ?>">
    <span class="kpi-t"><?= icono('bandeja') ?> Por atender</span><span class="kpi-n" data-kpi="por_atender"><?= $porAtender ?></span>
    <span class="kpi-s"><?= $porAtender ? 'solicitudes de tus clientes' : 'Nada pendiente 👏' ?></span></a>
  <a class="kpi" href="<?= e(url('vencimientos')) ?>">
    <span class="kpi-t"><?= icono('calendario') ?> Vencen esta semana</span><span class="kpi-n" data-kpi="vencen"><?= $vencenSemana ?></span>
    <span class="kpi-s">pruebas y planes</span></a>
  <?php if (puede('finanzas')): ?>
  <a class="kpi" href="<?= e(url('finanzas')) ?>">
    <span class="kpi-t"><?= icono('dinero') ?> Cobrado en <?= e($nombreMes) ?></span><span class="kpi-n" data-kpi="cobrado"><?= e(dinero($cobrado)) ?></span>
    <span class="kpi-s">gastos: <?= e(dinero($gastosMes)) ?></span></a>
  <a class="kpi kpi-ganancia<?= $ganancia < 0 ? ' negativa' : '' ?>" href="<?= e(url('finanzas')) ?>">
    <span class="kpi-t"><?= icono('finanzas') ?> Ganancia del mes</span><span class="kpi-n" data-kpi="ganancia"><?= e(dinero($ganancia)) ?></span>
    <span class="kpi-s">cobrado − gastos</span></a>
  <?php else: ?>
  <a class="kpi" href="<?= e(url('clientes', ['estado' => 'prueba'])) ?>">
    <span class="kpi-t"><?= icono('demo') ?> En prueba</span><span class="kpi-n"><?= $conDatos ? numero_corto($t['prueba']) : '—' ?></span><span class="kpi-s">en todos los programas</span></a>
  <a class="kpi" href="<?= e(url('clientes')) ?>">
    <span class="kpi-t"><?= icono('clientes') ?> Clientes</span><span class="kpi-n"><?= $conDatos ? numero_corto($t['total']) : '—' ?></span><span class="kpi-s">en total</span></a>
  <?php endif; ?>
</section>

<section class="seccion" aria-labelledby="t-hoy">
  <h2 id="t-hoy">Para atender hoy <?php if ($porAtender): ?><span class="cuenta rojo"><?= $porAtender ?></span><?php endif; ?></h2>
  <div class="tarjeta">
    <?php if (!$urgentes): ?>
      <p class="vacio"><?= icono('ok') ?> No hay nada por atender. 👏</p>
    <?php else: ?>
      <ul class="lista-p urgentes">
        <?php foreach ($urgentes as $s): $volver = '/?r=inicio'; ?>
          <li class="p-<?= e($s['producto']) ?>">
            <span class="ico chico tipo-sol" aria-hidden="true"><?= Solicitudes::iconoTipo($s['tipo']) ?></span>
            <div class="dato"><a href="<?= e(url('solicitud', ['i' => $s['instancia_id'], 'id' => $s['id']])) ?>"><strong><?= e($s['quien']['organizacion'] ?? $s['quien']['nombre'] ?? $s['titulo']) ?></strong></a>
              <span><?= e($s['programa']) ?> · <?= e(Solicitudes::nombreTipo($s['tipo'])) ?><?= $s['resumen'] ? ' · ' . e(mb_strimwidth($s['resumen'], 0, 70, '…')) : '' ?> · <?= e(hace_iso($s['fecha'])) ?></span></div>
            <div class="acc-linea"><?= botones_acciones(array_values(array_filter($s['acciones'], fn($a) => $a['estilo'] === 'principal')), ['inst' => $s['instancia_id'], 'sol' => $s['id'], 'cliente_id' => $s['cliente_id'], 'volver' => $volver, 'max' => 1]) ?>
              <a class="btn btn-chico" href="<?= e(url('solicitud', ['i' => $s['instancia_id'], 'id' => $s['id']])) ?>">Ver</a></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($porAtender > count($urgentes)): ?><p class="mt"><a class="btn btn-chico" href="<?= e(url('solicitudes')) ?>">Ver las <?= $porAtender ?> solicitudes <?= icono('flecha') ?></a></p><?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<section class="resumen seccion" aria-labelledby="t-resumen">
  <div class="titulo">
    <h2 id="t-resumen">Todos tus negocios</h2>
    <p><?= $t['en_linea'] ?> en línea<?= $t['sin_conexion'] ? ' · ' . $t['sin_conexion'] . ' sin conexión' : '' ?></p>
  </div>
  <div class="cifras">
    <div class="cifra grande"><span class="t">Negocios en total</span><span class="n" data-total="negocios"><?= $conDatos ? numero_corto($t['total']) : '—' ?></span></div>
    <?php foreach ($etiquetas as $k => $txt): ?>
      <div class="cifra"><span class="n" data-total="<?= e($k) ?>"><?= $conDatos ? numero_corto($t[$k]) : '—' ?></span><span class="t"><i class="i-<?= e($k) ?>"></i><?= e($txt) ?></span></div>
    <?php endforeach; ?>
  </div>
  <div class="ingresos">
    <div class="cifra"><span class="n" data-total="nuevos_30d"><?= numero_corto($t['nuevos_30d']) ?></span><span class="t">Negocios nuevos en 30 días</span></div>
    <?php if (puede('finanzas')): ?>
    <div class="cifra">
      <span class="n"><span data-total="ingresos_mes"><?= $t['con_ingresos'] ? e(dinero($t['ingresos_mes'])) : '—' ?></span><?php if ($var !== null): ?><span class="delta<?= $var < 0 ? ' baja' : ($var == 0 ? ' igual' : '') ?>"><?= $var > 0 ? '▲' : ($var < 0 ? '▼' : '=') ?> <?= e(str_replace('.', ',', (string) abs($var))) ?> %</span><?php endif; ?></span>
      <span class="t">Ingresos de este mes · el mes pasado <?= $t['con_ingresos'] ? e(dinero($t['ingresos_mes_anterior'])) : '—' ?></span>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($hayV1 && $v1Pend): ?>
<section class="seccion" aria-labelledby="t-v1">
  <h2 id="t-v1">De los programas que todavía no se actualizan <span class="cuenta"><?= $v1Pend ?></span></h2>
  <div class="pendientes">
    <div class="tarjeta">
      <h3>Pruebas gratis que vencen esta semana</h3>
      <?php if (!$pendientes['pruebas']): ?>
        <p class="vacio"><?= icono('ok') ?> Ninguna prueba vence en los próximos 7 días.</p>
      <?php else: ?>
        <ul class="lista-p">
          <?php foreach (array_slice($pendientes['pruebas'], 0, 6) as $x): $dias = dias_hasta($x['vence']); ?>
            <li class="p-<?= e($x['producto']) ?>">
              <span class="ico chico" aria-hidden="true"><?= e(Productos::tipo($x['producto'])['letra']) ?></span>
              <div class="dato"><strong><?= e($x['nombre']) ?></strong>
                <span><?= e($x['programa']) ?> · <span class="<?= $dias !== null && $dias <= 1 ? 'urgente' : '' ?>">vence <?= e(fecha_corta($x['vence'])) ?><?= $dias === null ? '' : ($dias <= 0 ? ' (hoy)' : ($dias === 1 ? ' (mañana)' : " (en $dias días)")) ?></span></span></div>
              <?= $boton($x) ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <div class="tarjeta">
      <h3>Pagos vencidos</h3>
      <?php if (!$pendientes['pagos']): ?>
        <p class="vacio"><?= icono('ok') ?> Nadie tiene pagos vencidos.</p>
      <?php else: ?>
        <ul class="lista-p">
          <?php foreach (array_slice($pendientes['pagos'], 0, 6) as $x): $dias = dias_hasta($x['desde']); ?>
            <li class="p-<?= e($x['producto']) ?>">
              <span class="ico chico" aria-hidden="true"><?= e(Productos::tipo($x['producto'])['letra']) ?></span>
              <div class="dato"><strong><?= e($x['nombre']) ?></strong>
                <span><?= e($x['programa']) ?> · <span class="urgente">vencido desde <?= e(fecha_corta($x['desde'])) ?></span><?= $dias !== null && $dias < 0 ? ' (' . -$dias . ' días)' : '' ?></span></div>
              <?= $boton($x) ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>
<?php endif; ?>

<section class="seccion" aria-labelledby="t-prog">
  <h2 id="t-prog">Tus programas <span class="cuenta"><?= count($tablero) ?></span></h2>
  <div class="rejilla tres">
    <?php foreach ($tablero as $f): $dp = $disp[$f['id']]; $v2 = ($caps[$f['id']]['protocolo'] ?? 1) === 2;
          $lineaV2 = $v2 ? ['solicitudes' => (int) ($abiertasPorInst[$f['id']] ?? 0), 'vencen' => (int) ($vencenPorInst[$f['id']] ?? 0)] : null;
          require __DIR__ . '/_tarjeta_programa.php'; endforeach; ?>
    <?php if (puede('programas')): ?>
      <a class="agregar" href="<?= e(url('programa_nuevo')) ?>">
        <span class="mas"><?= icono('mas') ?></span>
        <strong>Agregar programa</strong>
        <span class="chico">Conecta TuEvento, TuSalón, TuVocalía, TuAcademia o una TuGuardería</span>
      </a>
    <?php endif; ?>
  </div>
</section>
