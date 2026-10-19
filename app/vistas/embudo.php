<?php
use Tukan\{Contacto, Finanzas};

$pasos = [['interesados', 'Pedidos de demo y registros', null], ['creadas', 'Demos creadas', 'pct_creadas'], ['pagaron', 'Empezaron a pagar', 'pct_pagaron'], ['siguen', 'Siguen pagando hoy', 'pct_siguen']];
$max = max(1, $total['interesados']);
?>
<div class="cabeza">
  <div>
    <h1>Embudo</h1>
    <p class="sub">De cada 10 interesados, ¿cuántos terminan pagando? Últimos 6 meses.</p>
  </div>
  <form method="get" action="/" class="acciones"><input type="hidden" name="r" value="embudo">
    <label class="oculto" for="fi">Programa</label>
    <select id="fi" name="i" data-enviar-al-cambiar><option value="">Todos los programas</option>
      <?php foreach ($insts as $i): ?><option value="<?= e($i['id']) ?>" <?= $inst === $i['id'] ? 'selected' : '' ?>><?= e($i['nombre']) ?></option><?php endforeach; ?></select>
    <noscript><button class="btn" type="submit">Ver</button></noscript>
  </form>
</div>

<div class="tarjeta embudo-total">
  <h2>Los 6 meses juntos</h2>
  <ol class="embudo">
    <?php foreach ($pasos as [$k, $t, $p]): ?>
      <li><span class="emb-t"><?= e($t) ?></span>
        <span class="emb-barra"><progress max="<?= $max ?>" value="<?= (int) $total[$k] ?>"><?= (int) $total[$k] ?></progress></span>
        <span class="emb-n" data-embudo="<?= e($k) ?>"><?= (int) $total[$k] ?><?php if ($p && $total[$p] !== null): ?> <small><?= $total[$p] ?> %</small><?php endif; ?></span></li>
    <?php endforeach; ?>
  </ol>
</div>

<div class="tarjeta seccion">
  <h2>Mes por mes</h2>
  <div class="tabla-env">
  <table class="tabla" id="tabla-embudo">
    <thead><tr><th>Mes</th><th>Interesados</th><th>Demos</th><th>Pagaron</th><th>Siguen</th></tr></thead>
    <tbody>
    <?php foreach (array_reverse($meses, true) as $m => $x): ?>
      <tr><td class="nowrap"><?= e(ucfirst(Finanzas::nombreMes($m))) ?></td><td><?= $x['interesados'] ?></td>
        <td><?= $x['creadas'] ?><?= $x['pct_creadas'] !== null ? ' <small class="suave">' . $x['pct_creadas'] . ' %</small>' : '' ?></td>
        <td><?= $x['pagaron'] ?><?= $x['pct_pagaron'] !== null ? ' <small class="suave">' . $x['pct_pagaron'] . ' %</small>' : '' ?></td>
        <td><?= $x['siguen'] ?><?= $x['pct_siguen'] !== null ? ' <small class="suave">' . $x['pct_siguen'] . ' %</small>' : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="rejilla dos seccion">
  <div class="tarjeta">
    <h2>Demos que terminaron sin pagar <span class="cuenta"><?= count($sin_pagar) ?></span></h2>
    <p class="chico suave mb">Últimos 60 días. Una llamada a tiempo hace la diferencia.</p>
    <?php if (!$sin_pagar): ?><p class="vacio"><?= icono('ok') ?> Ninguna.</p><?php else: ?>
    <ul class="lista-p">
      <?php foreach (array_slice($sin_pagar, 0, 30) as $c): ?>
        <li class="p-<?= e($c['producto']) ?>"><?= ico_programa($c['producto']) ?>
          <div class="dato"><a href="<?= e(url('cliente', ['i' => $c['instancia_id'], 'id' => $c['cliente_id']])) ?>"><strong><?= e($c['nombre']) ?></strong></a><span><?= e($c['programa']) ?> · terminó el <?= e(fecha_dm($c['vence'])) ?></span></div>
          <div class="acc-linea">
            <?php if (ve_contacto() && $c['contacto_cif']): ?><form method="post" action="<?= e(url('recordar')) ?>" target="_blank"><?= campo_csrf() ?><input type="hidden" name="i" value="<?= e($c['instancia_id']) ?>"><input type="hidden" name="id" value="<?= e($c['cliente_id']) ?>">
              <button class="btn btn-chico btn-wa" type="submit"><?= icono('whatsapp') ?> WhatsApp</button></form><?php endif; ?>
            <?php if (puede_operacion($c['instancia_id'], 'extender_prueba')): ?><a class="btn btn-chico" href="<?= e(url('cliente', ['i' => $c['instancia_id'], 'id' => $c['cliente_id']])) ?>">Alargar prueba</a><?php endif; ?>
          </div></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
  <div class="tarjeta">
    <h2>Clientes en riesgo <span class="cuenta"><?= count($en_riesgo) ?></span></h2>
    <p class="chico suave mb">Pagan, pero no usan el programa hace 15 días o más.</p>
    <?php if (!$en_riesgo): ?><p class="vacio"><?= icono('ok') ?> Nadie en riesgo.</p><?php else: ?>
    <ul class="lista-p">
      <?php foreach (array_slice($en_riesgo, 0, 30) as $c): ?>
        <li class="p-<?= e($c['producto']) ?>"><?= ico_programa($c['producto']) ?>
          <div class="dato"><a href="<?= e(url('cliente', ['i' => $c['instancia_id'], 'id' => $c['cliente_id']])) ?>"><strong><?= e($c['nombre']) ?></strong></a><span><?= e($c['programa']) ?> · último uso <?= e(hace_iso($c['ultima_actividad'])) ?></span></div>
          <?php if (ve_contacto() && $c['contacto_cif']): ?><form method="post" action="<?= e(url('recordar')) ?>" target="_blank"><?= campo_csrf() ?><input type="hidden" name="i" value="<?= e($c['instancia_id']) ?>"><input type="hidden" name="id" value="<?= e($c['cliente_id']) ?>">
            <input type="hidden" name="tipo" value="riesgo"><input type="hidden" name="msg" value="<?= e('Hola, le saluda Tukán Software. Notamos que hace días no usa ' . $c['programa'] . ' en ' . $c['nombre'] . '. ¿Todo bien? ¿Le ayudamos con algo?') ?>">
            <button class="btn btn-chico btn-wa" type="submit"><?= icono('whatsapp') ?> WhatsApp</button></form><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>
