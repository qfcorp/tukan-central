<?php
use Tukan\{Estado, Productos};

$t = $totales;
$nombre = explode(' ', usuario()['nombre'])[0];
$hora = (int) date('G');
$saludo = $hora < 12 ? 'Buenos días' : ($hora < 19 ? 'Buenas tardes' : 'Buenas noches');
$activas = array_filter($tablero, fn($f) => (int) $f['activo']);
$conDatos = $t['con_negocios'] > 0;
$var = $t['variacion'];
$etiquetas = ['prueba' => 'En prueba', 'activos' => 'Activos', 'por_vencer' => 'Por vencer', 'vencidos' => 'Vencidos', 'suspendidos' => 'Suspendidos'];
$hoyN = count($pendientes['pruebas']) + count($pendientes['pagos']);
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
<section class="resumen" aria-labelledby="t-resumen">
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
    <div class="cifra">
      <span class="n"><span data-total="ingresos_mes"><?= $t['con_ingresos'] ? e(dinero($t['ingresos_mes'])) : '—' ?></span><?php if ($var !== null): ?><span class="delta<?= $var < 0 ? ' baja' : ($var == 0 ? ' igual' : '') ?>"><?= $var > 0 ? '▲' : ($var < 0 ? '▼' : '=') ?> <?= e(str_replace('.', ',', (string) abs($var))) ?> %</span><?php endif; ?></span>
      <span class="t">Ingresos de este mes · el mes pasado <?= $t['con_ingresos'] ? e(dinero($t['ingresos_mes_anterior'])) : '—' ?></span>
    </div>
  </div>
</section>

<section class="seccion" aria-labelledby="t-hoy">
  <h2 id="t-hoy">Para atender hoy <?php if ($hoyN): ?><span class="cuenta"><?= $hoyN ?></span><?php endif; ?></h2>
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
        <?php if (count($pendientes['pruebas']) > 6): ?>
          <details class="mas-items"><summary>Ver <?= count($pendientes['pruebas']) - 6 ?> más</summary>
            <ul class="lista-p"><?php foreach (array_slice($pendientes['pruebas'], 6) as $x): ?>
              <li class="p-<?= e($x['producto']) ?>"><span class="ico chico" aria-hidden="true"><?= e(Productos::tipo($x['producto'])['letra']) ?></span><div class="dato"><strong><?= e($x['nombre']) ?></strong><span><?= e($x['programa']) ?> · vence <?= e(fecha_corta($x['vence'])) ?></span></div><?= $boton($x) ?></li>
            <?php endforeach; ?></ul></details>
        <?php endif; ?>
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
        <?php if (count($pendientes['pagos']) > 6): ?>
          <details class="mas-items"><summary>Ver <?= count($pendientes['pagos']) - 6 ?> más</summary>
            <ul class="lista-p"><?php foreach (array_slice($pendientes['pagos'], 6) as $x): ?>
              <li class="p-<?= e($x['producto']) ?>"><span class="ico chico" aria-hidden="true"><?= e(Productos::tipo($x['producto'])['letra']) ?></span><div class="dato"><strong><?= e($x['nombre']) ?></strong><span><?= e($x['programa']) ?> · desde <?= e(fecha_corta($x['desde'])) ?></span></div><?= $boton($x) ?></li>
            <?php endforeach; ?></ul></details>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="seccion" aria-labelledby="t-prog">
  <h2 id="t-prog">Tus programas <span class="cuenta"><?= count($tablero) ?></span></h2>
  <div class="rejilla tres">
    <?php foreach ($tablero as $f): $dp = $disp[$f['id']]; require __DIR__ . '/_tarjeta_programa.php'; endforeach; ?>
    <?php if (puede('programas')): ?>
      <a class="agregar" href="<?= e(url('programa_nuevo')) ?>">
        <span class="mas"><?= icono('mas') ?></span>
        <strong>Agregar programa</strong>
        <span class="chico">Conecta TuEvento, TuSalón, TuVocalía, TuAcademia o una TuGuardería</span>
      </a>
    <?php endif; ?>
  </div>
</section>
