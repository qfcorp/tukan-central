<?php
$m = $mes;
$estados = ['prueba' => 'En prueba', 'pago' => 'Pagó', 'sin_pagar' => 'Terminó sin pagar'];
?>
<div class="cabeza">
  <div>
    <h1>Demos</h1>
    <p class="sub">Las cuentas de prueba de todos tus programas y cuántas se vuelven clientes.</p>
  </div>
  <?php if (puede('demos') && $pueden): ?>
  <div class="acciones"><a class="btn btn-primario btn-grande" href="<?= e(url('demo_nueva')) ?>"><?= icono('mas') ?> Crear demo</a></div>
  <?php endif; ?>
</div>

<section class="hoy demos-cifras" aria-label="Este mes">
  <div class="kpi"><span class="kpi-t">Demos este mes</span><span class="kpi-n" data-kpi="demos_mes"><?= $m['demos'] ?></span><span class="kpi-s">creadas o registradas</span></div>
  <div class="kpi"><span class="kpi-t">Pagaron</span><span class="kpi-n" data-kpi="demos_pagaron"><?= $m['pagaron'] ?><?php if ($m['porcentaje'] !== null): ?> <small>(<?= $m['porcentaje'] ?> %)</small><?php endif; ?></span><span class="kpi-s">de las de este mes</span></div>
  <div class="kpi"><span class="kpi-t">Tardan en pagar</span><span class="kpi-n"><?= $promedio_dias === null ? '—' : e(str_replace('.', ',', (string) $promedio_dias)) . ' <small>días</small>' ?></span><span class="kpi-s">en promedio</span></div>
</section>

<?php if ($por_programa): ?>
<div class="tarjeta seccion">
  <h2>Por programa</h2>
  <ul class="lista-p">
    <?php foreach ($por_programa as $i => $p): ?>
      <li class="p-<?= e($p['producto']) ?>"><?= ico_programa($p['producto']) ?><div class="dato"><strong><?= e($p['programa']) ?></strong>
        <span>Este mes: <?= $p['demos'] ?> demos · <?= $p['pagaron'] ?> pagaron<?= $p['porcentaje'] !== null ? ' (' . $p['porcentaje'] . ' %)' : '' ?> · <?= $p['total'] ?> en total</span></div>
        <?php if (puede('demos') && in_array($i, array_column($pueden, 'id'), true)): ?><a class="btn btn-chico" href="<?= e(url('demo_nueva', ['i' => $i])) ?>">Crear demo</a><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="seccion">
  <div class="chips" role="list">
    <a role="listitem" class="chip<?= $ver === '' ? ' activo' : '' ?>" href="<?= e(url_aqui([], ['ver'])) ?>">Todas <b><?= count($filas) ?></b></a>
    <?php foreach ($estados as $k => $t): ?>
      <a role="listitem" class="chip<?= $ver === $k ? ' activo' : '' ?>" href="<?= e(url_aqui(['ver' => $k])) ?>"><?= e($t) ?> <b><?= count(array_filter($filas, fn($f) => $f['estado_demo'] === $k)) ?></b></a>
    <?php endforeach; ?>
  </div>
  <div class="tarjeta mt">
    <h2>Historial</h2>
    <?php if (!$lista): ?><p class="vacio"><?= icono('info') ?> Todavía no hay demos.</p><?php else: ?>
    <div class="tabla-env">
    <table class="tabla apilable" id="tabla-demos">
      <thead><tr><th>Demo</th><th>Creada</th><th>Cómo va</th><th>De dónde</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($lista as $f): ?>
        <tr class="p-<?= e((string) $f['producto']) ?>">
          <td><div class="prog-top"><?= $f['producto'] ? ico_programa($f['producto']) : '' ?><div class="prog-tit"><strong><?= e($f['nombre']) ?></strong><span class="url"><?= e($f['programa'] ?? nombre_programa($f['instancia_id'])) ?></span></div></div></td>
          <td data-t="Creada" class="nowrap"><?= e(fecha_dm($f['alta'], true)) ?></td>
          <td data-t="Cómo va"><?php
            if ($f['estado_demo'] === 'pago') echo '<span class="etiqueta ok">Pagó ✔ (' . e(fecha_dm($f['primer_pago'])) . ($f['monto_primer_pago'] !== null && puede('finanzas') ? ', ' . e(dinero((float) $f['monto_primer_pago'])) : '') . ')</span>';
            elseif ($f['estado_demo'] === 'prueba') echo '<span class="etiqueta e-prueba">En prueba (' . e(falta($f['dias'])) . ')</span>';
            elseif ($f['estado_demo'] === 'sin_pagar') echo '<span class="etiqueta mal">Terminó sin pagar</span>';
            else echo $f['estado'] ? etiqueta_estado($f['estado']) : '<span class="etiqueta">Ya no está en el programa</span>';
          ?></td>
          <td data-t="De dónde"><?= e(['central' => 'Panel Tukán' . ($f['creada_por'] ? ' (' . explode(' ', $f['creada_por'])[0] . ')' : ''), 'demo_pedida' => 'Pidió una demo', 'registro' => 'Se registró solo'][$f['origen']] ?? $f['origen']) ?></td>
          <td><?php if ($f['estado'] !== null): ?><a class="btn btn-chico" href="<?= e(url('cliente', ['i' => $f['instancia_id'], 'id' => $f['cliente_id']])) ?>">Ficha</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>
