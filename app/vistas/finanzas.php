<?php
use Tukan\Finanzas;

$t = $r['total']['total'];
$periodos = ['12' => 'Últimos 12 meses', '24' => '24 meses', 'anio' => 'Este año'];
// Gráfico (SVG del servidor, sin librerías): barras de ingresos (verde) y gastos (rojo), línea de ganancia
$W = 720; $H = 250; $izq = 56; $der = 12; $arriba = 14; $abajo = 34;
$vals = [];
foreach ($meses as $m) { $x = $r['total']['meses'][$m]; array_push($vals, $x['ingresos'], $x['gastos'], $x['ganancia']); }
$maxV = max(1.0, ...$vals);
$minV = min(0.0, ...$vals);
$paso = (function (float $rango) { $p = 10 ** floor(log10(max(1, $rango / 4))); foreach ([1, 2, 5, 10] as $k) if ($rango / ($p * $k) <= 5) return $p * $k; return $p * 10; })($maxV - $minV);
$maxV = ceil($maxV / $paso) * $paso;
$minV = floor($minV / $paso) * $paso;
$alto = $H - $arriba - $abajo;
$y = fn(float $v) => round($arriba + ($maxV - $v) / ($maxV - $minV ?: 1) * $alto, 1);
$n = count($meses);
$ancho = ($W - $izq - $der) / max(1, $n);
$barra = max(2, min(18, $ancho * 0.32));
$puntos = [];
?>
<div class="cabeza">
  <div>
    <h1>Finanzas</h1>
    <p class="sub">Lo que entra, lo que sale y lo que queda · <?= e(datos_de($momento)) ?></p>
  </div>
  <div class="acciones">
    <form method="post" action="<?= e(url('actualizar_datos')) ?>"><?= campo_csrf() ?><input type="hidden" name="que" value="finanzas"><input type="hidden" name="volver" value="<?= e(aqui()) ?>">
      <button class="btn" type="submit"><?= icono('refrescar') ?> Actualizar</button></form>
  </div>
</div>
<?php require __DIR__ . '/_finanzas_tabs.php'; ?>

<div class="chips" role="list" aria-label="Período">
  <?php foreach ($periodos as $k => $txt): ?><a role="listitem" class="chip<?= $periodo === (string) $k ? ' activo' : '' ?>" href="<?= e(url('finanzas', ['periodo' => (string) $k])) ?>"><?= e($txt) ?></a><?php endforeach; ?>
</div>

<section class="hoy finanzas-cifras" aria-label="Totales">
  <div class="kpi kpi-ing"><span class="kpi-t">Ingresos</span><span class="kpi-n" data-fin="ingresos"><?= e(dinero($t['ingresos'])) ?></span><span class="kpi-s"><?= e($periodos[$periodo]) ?></span></div>
  <div class="kpi kpi-gas"><span class="kpi-t">Gastos</span><span class="kpi-n" data-fin="gastos"><?= e(dinero($t['gastos'])) ?></span><span class="kpi-s">propios <?= e(dinero($t['gastos_propios'])) ?> + de los programas <?= e(dinero($t['costos'])) ?></span></div>
  <div class="kpi kpi-ganancia<?= $t['ganancia'] < 0 ? ' negativa' : '' ?>"><span class="kpi-t">Ganancia</span><span class="kpi-n" data-fin="ganancia"><?= e(dinero($t['ganancia'])) ?></span><span class="kpi-s">ingresos − gastos</span></div>
  <div class="kpi"><span class="kpi-t">Ingreso mensual recurrente</span><span class="kpi-n" data-fin="mrr"><?= e(dinero($r['mrr'])) ?></span><span class="kpi-s">lo que pagan al mes hoy</span></div>
</section>

<div class="tarjeta seccion grafico">
  <div class="grafico-cab"><h2>Mes por mes</h2><span class="leyenda"><i class="verde"></i>Ingresos <i class="rojo"></i>Gastos <i class="linea"></i>Ganancia</span></div>
  <svg viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Ingresos, gastos y ganancia por mes" class="svg-finanzas">
    <?php for ($v = $minV; $v <= $maxV + 0.001; $v += $paso): ?>
      <line x1="<?= $izq ?>" x2="<?= $W - $der ?>" y1="<?= $y($v) ?>" y2="<?= $y($v) ?>" class="<?= abs($v) < 0.001 ? 'eje' : 'guia' ?>"/>
      <text x="<?= $izq - 6 ?>" y="<?= $y($v) + 4 ?>" text-anchor="end" class="txt">$<?= e(number_format($v, 0, ',', '.')) ?></text>
    <?php endfor; ?>
    <?php foreach ($meses as $k => $m): $x = $r['total']['meses'][$m]; $cx = $izq + $ancho * ($k + 0.5); $puntos[] = round($cx, 1) . ',' . $y($x['ganancia']); ?>
      <rect x="<?= round($cx - $barra - 1, 1) ?>" y="<?= $y(max(0, $x['ingresos'])) ?>" width="<?= round($barra, 1) ?>" height="<?= max(0, round($y(0) - $y(max(0, $x['ingresos'])), 1)) ?>" class="b-ing" rx="2"><title><?= e(Finanzas::nombreMes($m)) ?>: ingresos <?= e(dinero($x['ingresos'])) ?></title></rect>
      <rect x="<?= round($cx + 1, 1) ?>" y="<?= $y(max(0, $x['gastos'])) ?>" width="<?= round($barra, 1) ?>" height="<?= max(0, round($y(0) - $y(max(0, $x['gastos'])), 1)) ?>" class="b-gas" rx="2"><title><?= e(Finanzas::nombreMes($m)) ?>: gastos <?= e(dinero($x['gastos'])) ?></title></rect>
      <?php if ($n <= 12 || $k % 2 === 0): ?><text x="<?= round($cx, 1) ?>" y="<?= $H - 12 ?>" text-anchor="middle" class="txt"><?= e(Finanzas::nombreMes($m, true)) ?></text><?php endif; ?>
    <?php endforeach; ?>
    <polyline points="<?= e(implode(' ', $puntos)) ?>" class="l-gan"/>
    <?php foreach ($meses as $k => $m): [$px, $py] = explode(',', $puntos[$k]); ?><circle cx="<?= $px ?>" cy="<?= $py ?>" r="3.5" class="p-gan"><title><?= e(Finanzas::nombreMes($m)) ?>: ganancia <?= e(dinero($r['total']['meses'][$m]['ganancia'])) ?></title></circle><?php endforeach; ?>
  </svg>
</div>

<div class="tarjeta seccion">
  <div class="grafico-cab">
    <h2>Por programa</h2>
    <form method="post" action="<?= e(url('finanzas')) ?>" class="interruptor"><?= campo_csrf() ?><input type="hidden" name="accion" value="repartir"><input type="hidden" name="volver" value="<?= e(aqui()) ?>">
      <input type="hidden" name="repartir" value="<?= $repartir ? '0' : '1' ?>">
      <button type="submit" class="check-boton" aria-pressed="<?= $repartir ? 'true' : 'false' ?>"><span class="caja"><?= $repartir ? icono('check') : '' ?></span> Repartir gastos generales según lo que gana cada programa</button></form>
  </div>
  <div class="tabla-env tabla-ancha">
  <table class="tabla fin-tabla" id="tabla-finanzas">
    <thead><tr><th>Programa</th><?php foreach ($meses as $m): ?><th class="num"><?= e(Finanzas::nombreMes($m, true)) ?></th><?php endforeach; ?><th class="num">Total</th></tr></thead>
    <?php foreach ($r['programas'] as $p): ?>
      <tbody class="p-<?= e($p['producto']) ?>" data-programa="<?= e($p['id']) ?>">
        <tr class="fila-prog"><th colspan="<?= count($meses) + 2 ?>"><a href="<?= e(url('finanzas', ['periodo' => $periodo, 'prog' => $p['id']])) ?>#detalle"><?= ico_programa($p['producto']) ?> <?= e($p['nombre']) ?></a><?= $p['informa'] ? '' : ' <span class="chico suave">(no informa ingresos)</span>' ?></th></tr>
        <?php foreach (['ingresos' => 'Ingresos', 'otros' => 'Otros ingresos', 'extra' => 'Ingresos extra', 'costos' => 'Costos del programa', 'gastos' => 'Gastos asignados', 'parte_general' => 'Parte de gastos generales', 'ganancia' => 'Ganancia'] as $k => $txt):
              if (in_array($k, ['otros', 'extra', 'parte_general'], true) && abs($p['total'][$k]) < 0.005) continue; ?>
          <tr class="<?= $k === 'ganancia' ? 'fila-gan' : '' ?>"><td><?= e($txt) ?></td>
            <?php foreach ($meses as $m): $v = $p['meses'][$m][$k]; ?><td class="num<?= $k === 'ganancia' && $v < 0 ? ' neg' : '' ?>"><?= abs($v) < 0.005 ? '—' : e(dinero(in_array($k, ['costos', 'gastos', 'parte_general'], true) ? -$v : $v)) ?></td><?php endforeach; ?>
            <td class="num"><b><?= e(dinero(in_array($k, ['costos', 'gastos', 'parte_general'], true) ? -$p['total'][$k] : $p['total'][$k])) ?></b></td></tr>
        <?php endforeach; ?>
      </tbody>
    <?php endforeach; ?>
    <tbody class="general">
      <tr class="fila-prog"><th colspan="<?= count($meses) + 2 ?>">Gastos generales <span class="chico suave">(sin programa)</span></th></tr>
      <tr><td>Gastos<?= $repartir ? ' (repartidos)' : '' ?></td><?php foreach ($meses as $m): $g = $r['general']['meses'][$m]; ?><td class="num"><?= $g['gastos'] < 0.005 ? '—' : e(dinero(-$g['gastos'])) ?></td><?php endforeach; ?><td class="num"><b><?= e(dinero(-$r['general']['total']['gastos'])) ?></b></td></tr>
      <?php if ($r['general']['total']['extra'] > 0): ?><tr><td>Ingresos extra</td><?php foreach ($meses as $m): ?><td class="num"><?= $r['general']['meses'][$m]['extra'] < 0.005 ? '—' : e(dinero($r['general']['meses'][$m]['extra'])) ?></td><?php endforeach; ?><td class="num"><b><?= e(dinero($r['general']['total']['extra'])) ?></b></td></tr><?php endif; ?>
    </tbody>
    <tfoot>
      <tr><th>Ingresos</th><?php foreach ($meses as $m): ?><td class="num"><?= e(dinero($r['total']['meses'][$m]['ingresos'])) ?></td><?php endforeach; ?><td class="num"><b><?= e(dinero($t['ingresos'])) ?></b></td></tr>
      <tr><th>Gastos</th><?php foreach ($meses as $m): ?><td class="num"><?= e(dinero(-$r['total']['meses'][$m]['gastos'])) ?></td><?php endforeach; ?><td class="num"><b><?= e(dinero(-$t['gastos'])) ?></b></td></tr>
      <tr class="fila-gan total"><th>Ganancia</th><?php foreach ($meses as $m): $v = $r['total']['meses'][$m]['ganancia']; ?><td class="num<?= $v < 0 ? ' neg' : '' ?>" data-mes="<?= e($m) ?>"><?= e(dinero($v)) ?></td><?php endforeach; ?><td class="num"><b data-total-ganancia><?= e(dinero($t['ganancia'])) ?></b></td></tr>
    </tfoot>
  </table>
  </div>
  <?php if ($sinFinanzas): ?><p class="chico suave mt"><?= e(implode(', ', array_column($sinFinanzas, 'nombre'))) ?>: sin ingresos por mes todavía (actualiza el programa al protocolo v2). En Inicio se ven sus ingresos del mes.</p><?php endif; ?>
</div>

<?php if ($detalle): $pp = $r['programas'][$prog]; ?>
<div class="tarjeta seccion" id="detalle">
  <h2>Ingresos de <?= e($pp['nombre']) ?> · <?= e($periodos[$periodo]) ?></h2>
  <div class="rejilla tres mt">
    <?php foreach (['por_plan' => 'Por plan', 'por_periodo' => 'Por tiempo', 'por_metodo' => 'Por forma de pago'] as $g => $txt): ?>
      <div><h3><?= e($txt) ?></h3>
        <?php if (!$detalle[$g]): ?><p class="suave chico">Sin pagos.</p><?php else: ?>
        <ul class="barras-h"><?php $mx = max(array_column($detalle[$g], 'monto') ?: [1]); foreach ($detalle[$g] as $x): ?>
          <li><span class="bh-t"><?= e($x['nombre']) ?> <small><?= (int) $x['pagos'] ?> pagos</small></span><progress max="<?= e((string) max(0.01, $mx)) ?>" value="<?= e((string) $x['monto']) ?>"></progress><b><?= e(dinero($x['monto'])) ?></b></li>
        <?php endforeach; ?></ul>
        <?php endif; ?></div>
    <?php endforeach; ?>
  </div>
  <?php if ($detalle['otros_ingresos'] || $detalle['costos']): ?>
  <dl class="datos mt">
    <?php foreach ($detalle['otros_ingresos'] as $x): ?><div><dt><?= e($x['nombre']) ?></dt><dd><?= e(dinero($x['monto'])) ?></dd></div><?php endforeach; ?>
    <?php foreach ($detalle['costos'] as $x): ?><div><dt><?= e($x['nombre']) ?> <small>(lo informa <?= e($pp['nombre']) ?>)</small></dt><dd class="neg"><?= e(dinero(-$x['monto'])) ?></dd></div><?php endforeach; ?>
  </dl>
  <?php endif; ?>
  <p class="mt"><a class="btn btn-chico" href="<?= e(url('finanzas', ['periodo' => $periodo])) ?>">Cerrar detalle</a></p>
</div>
<?php endif; ?>

<?php if ($porModulo): $mxm = max(array_column($porModulo, 'monto') ?: [1]); ?>
<div class="tarjeta seccion" id="por-modulo">
  <h2>Ingreso por módulo <span class="cuenta"><?= e(dinero(array_sum(array_column($porModulo, 'monto')))) ?> al mes</span></h2>
  <p class="chico suave mb">Lo que suman al mes los módulos <b>extra</b> (encendidos aparte del plan), con el precio del catálogo de cada programa. Así se ve qué módulos venden más.</p>
  <ul class="barras-h">
    <?php foreach ($porModulo as $x): ?>
      <li><span class="bh-t"><?= e($x['nombre']) ?> <small><?= e($x['programa']) ?> · <?= (int) $x['clientes'] ?> <?= $x['clientes'] === 1 ? 'cliente' : 'clientes' ?></small></span><progress max="<?= e((string) max(0.01, $mxm)) ?>" value="<?= e((string) $x['monto']) ?>"></progress><b><?= e(dinero($x['monto'])) ?></b></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="acciones-form seccion">
  <a class="btn btn-oscuro" href="<?= e(url('exportar', ['f' => 'xlsx', 'periodo' => $periodo])) ?>"><?= icono('descargar') ?> Descargar Excel</a>
  <a class="btn" href="<?= e(url('exportar', ['f' => 'csv', 'periodo' => $periodo])) ?>"><?= icono('descargar') ?> Descargar CSV</a>
  <a class="btn" href="<?= e(url('gastos')) ?>"><?= icono('mas') ?> Agregar gasto</a>
</div>
