<?php
use Tukan\Finanzas;

$hoy = date('Y-m-d');
$diasMes = (int) $primero->format('t');
$inicio = (int) $primero->format('N');   // 1 = lunes
$semanas = [];
for ($d = 1; $d <= $diasMes; $d++) {
    $f = $primero->modify('+' . ($d - 1) . ' days');
    $semanas[(int) ceil(($d + $inicio - 1) / 7)][] = $f->format('Y-m-d');
}
$nombreDia = ['', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];
?>
<div class="cabeza">
  <div>
    <h1>Vencimientos</h1>
    <p class="sub">Qué día termina cada demo y vence cada plan.</p>
  </div>
  <form method="get" action="/" class="acciones"><input type="hidden" name="r" value="vencimientos"><input type="hidden" name="mes" value="<?= e($mes) ?>">
    <label class="oculto" for="fi">Programa</label>
    <select id="fi" name="i" data-enviar-al-cambiar><option value="">Todos los programas</option>
      <?php foreach ($insts as $i): ?><option value="<?= e($i['id']) ?>" <?= $inst === $i['id'] ? 'selected' : '' ?>><?= e($i['nombre']) ?></option><?php endforeach; ?></select>
    <noscript><button class="btn" type="submit">Ver</button></noscript>
  </form>
</div>

<div class="franjas">
  <a class="franja roja" href="<?= e(url('clientes', ['estado' => 'vencido'] + ($inst !== '' ? ['i' => $inst] : []))) ?>"><?= icono('error') ?> Ya vencieron sin renovar: <b><?= count($vencidos) ?></b> <?= icono('chevron') ?></a>
  <a class="franja" href="<?= e(url('clientes', ['vence' => '7'] + ($inst !== '' ? ['i' => $inst] : []))) ?>"><?= icono('calendario') ?> Esta semana: <b><?= $semana['n'] ?> <?= $semana['n'] === 1 ? 'vence' : 'vencen' ?></b><?php if (puede('finanzas')): ?> · <b><?= e(dinero($semana['monto'])) ?></b> al mes en juego<?php endif; ?> <?= icono('chevron') ?></a>
</div>

<div class="cal-nav">
  <a class="btn btn-chico btn-icono" href="<?= e(url_aqui(['mes' => $anterior])) ?>" aria-label="Mes anterior"><?= icono('atras') ?></a>
  <h2><?= e(ucfirst(Finanzas::nombreMes($mes))) ?></h2>
  <a class="btn btn-chico btn-icono" href="<?= e(url_aqui(['mes' => $siguiente])) ?>" aria-label="Mes siguiente"><?= icono('chevron') ?></a>
  <?php if ($mes !== date('Y-m')): ?><a class="btn btn-chico" href="<?= e(url_aqui([], ['mes'])) ?>">Hoy</a><?php endif; ?>
  <span class="leyenda"><i class="amarillo"></i>demo termina <i class="verde"></i>plan vence <i class="rojo"></i>ya venció</span>
</div>

<div class="calendario tarjeta" role="table" aria-label="Calendario de <?= e(Finanzas::nombreMes($mes)) ?>">
  <div class="cal-fila cal-cab" role="row"><?php for ($k = 1; $k <= 7; $k++): ?><span role="columnheader"><?= $nombreDia[$k] ?></span><?php endfor; ?></div>
  <?php foreach ($semanas as $n => $diasSem): ?>
    <div class="cal-fila" role="row">
      <?php if ($n === 1) for ($k = 1; $k < $inicio; $k++): ?><span class="cal-dia vacio" role="cell"></span><?php endfor; ?>
      <?php foreach ($diasSem as $f): $items = $dias[$f] ?? []; ?>
        <div class="cal-dia<?= $f === $hoy ? ' hoy' : '' ?><?= $items ? ' con' : '' ?>" role="cell">
          <span class="cal-num"><?= (int) substr($f, 8) ?></span>
          <?php foreach (array_slice($items, 0, 4) as $c): ?>
            <a class="cal-item <?= e($c['color']) ?>" href="<?= e(url('cliente', ['i' => $c['instancia_id'], 'id' => $c['cliente_id']])) ?>" title="<?= e($c['nombre'] . ' · ' . $c['programa']) ?>"><?= e($c['nombre']) ?></a>
          <?php endforeach; ?>
          <?php if (count($items) > 4): ?><span class="cal-mas">+<?= count($items) - 4 ?> más</span><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="cal-lista">
  <?php $alguno = false; foreach ($semanas as $n => $diasSem): $conItems = array_filter($diasSem, fn($f) => !empty($dias[$f])); if (!$conItems) continue; $alguno = true; ?>
    <section class="tarjeta semana">
      <h3>Semana del <?= e(fecha_dm($diasSem[0])) ?> al <?= e(fecha_dm(end($diasSem))) ?></h3>
      <ul class="lista-p">
        <?php foreach ($conItems as $f): foreach ($dias[$f] as $c): ?>
          <li class="p-<?= e($c['producto']) ?>">
            <span class="punto <?= e($c['color']) ?>" aria-hidden="true"></span>
            <div class="dato"><a href="<?= e(url('cliente', ['i' => $c['instancia_id'], 'id' => $c['cliente_id']])) ?>"><strong><?= e($c['nombre']) ?></strong></a>
              <span><?= e(fecha_corta($f)) ?> · <?= e($c['programa']) ?> · <?= e($c['color'] === 'amarillo' ? 'termina la demo' : ($c['color'] === 'rojo' ? 'ya venció' : 'vence el plan')) ?></span></div>
            <?php if (ve_contacto() && $c['contacto_cif']): ?>
              <form method="post" action="<?= e(url('recordar')) ?>" target="_blank"><?= campo_csrf() ?><input type="hidden" name="i" value="<?= e($c['instancia_id']) ?>"><input type="hidden" name="id" value="<?= e($c['cliente_id']) ?>">
                <button class="btn btn-chico btn-wa btn-icono" type="submit" aria-label="Recordar a <?= e($c['nombre']) ?> por WhatsApp"><?= icono('whatsapp') ?></button></form>
            <?php endif; ?>
          </li>
        <?php endforeach; endforeach; ?>
      </ul>
    </section>
  <?php endforeach; ?>
  <?php if (!$alguno): ?><div class="tarjeta"><p class="vacio"><?= icono('ok') ?> Nada vence en <?= e(Finanzas::nombreMes($mes)) ?>.</p></div><?php endif; ?>
</div>
