<?php
use Tukan\{Finanzas, Ia};

$cmd = fn(string $op) => 'cd ' . $dir . ' && bash deploy/configurar_ia.sh' . ($op !== '' ? " $op" : '');
?>
<div class="cabeza">
  <div>
    <h1>IA</h1>
    <p class="sub">Lo que gasta la inteligencia artificial de cada programa en <?= e(Finanzas::nombreMes($mes)) ?>.</p>
  </div>
  <form method="get" action="/" class="acciones"><input type="hidden" name="r" value="ia">
    <label class="oculto" for="ia-mes">Mes</label>
    <select id="ia-mes" name="mes" data-enviar-al-cambiar>
      <?php foreach ($meses as $m): ?><option value="<?= e($m) ?>" <?= $m === $mes ? 'selected' : '' ?>><?= e(ucfirst(Finanzas::nombreMes($m))) ?></option><?php endforeach; ?>
    </select>
    <noscript><button class="btn" type="submit">Ver</button></noscript>
    <a class="btn" href="<?= e(url('ia', ['mes' => $mes, 'act' => '1'])) ?>"><?= icono('refrescar') ?> Actualizar</a>
  </form>
</div>

<?php if ($estado !== null): ?>
  <?php if (!$legible): ?>
    <div class="aviso error mb" id="ia-estado"><?= icono('error') ?><p>El panel no puede leer <span class="mono"><?= e($estado['archivo']) ?></span>. Debe quedar <span class="mono">root:www-data</span> con permisos <span class="mono">640</span>: vuelve a correr <span class="mono">configurar_ia.sh</span>.</p></div>
  <?php elseif (!$estado['configurada']): ?>
    <div class="aviso alerta mb" id="ia-estado"><?= icono('info') ?><p><b>La IA no está configurada en este servidor.</b> Mientras tanto la Ayuda de los programas responde con las preguntas guardadas y el manual, y las fotos se hacen a mano. Para activarla, en el servidor: <span class="mono"><?= e($cmd('')) ?></span></p></div>
  <?php elseif ($estado['pausada']): ?>
    <div class="aviso alerta mb" id="ia-estado"><?= icono('pausa') ?><p><b>La IA está en pausa para todos los programas:</b> no se gasta nada. Los clientes no ven ningún aviso: la Ayuda sigue con las preguntas guardadas y el manual.</p></div>
  <?php else: ?>
    <div class="aviso mb" id="ia-estado"><?= icono('ok') ?><p><b>La IA está encendida.</b> Modelo rápido <span class="mono"><?= e($estado['modelo_rapido']) ?></span> · fuerte <span class="mono"><?= e($estado['modelo_fuerte']) ?></span><?= $estado['topes_programa'] ? ' · topes por programa: ' . e(implode(', ', array_map(fn($k, $v) => "$k " . Ia::dinero((float) $v), array_keys($estado['topes_programa']), $estado['topes_programa']))) : '' ?>.</p></div>
  <?php endif; ?>
<?php endif; ?>

<section class="hoy ia-cifras" aria-label="Totales del mes">
  <div class="kpi kpi-gas"><span class="kpi-t">Gasto del mes</span><span class="kpi-n" data-ia="costo"><?= e(Ia::dinero($tot['costo'])) ?></span><span class="kpi-s">lo que Tukán paga a Anthropic</span></div>
  <div class="kpi"><span class="kpi-t">Llamadas a la IA</span><span class="kpi-n" data-ia="llamadas"><?= e(numero_corto($tot['llamadas'])) ?></span><span class="kpi-s"><?= $tot['fallidas'] ? e($tot['fallidas'] . ' no salieron') : 'todas salieron bien' ?></span></div>
  <div class="kpi kpi-ganancia"><span class="kpi-t">Respondidas sin gastar</span><span class="kpi-n" data-ia="ahorradas"><?= e(numero_corto($tot['ahorradas'])) ?></span><span class="kpi-s"><?= $tot['pct_ahorro'] !== null ? e($tot['pct_ahorro'] . ' % desde preguntas guardadas o el manual') : 'desde preguntas guardadas o el manual' ?></span></div>
  <div class="kpi"><span class="kpi-t">Programas que reportan</span><span class="kpi-n"><?= $tot['programas'] ?> <small>de <?= count($filas) ?></small></span><span class="kpi-s"><?= $tot['pausados'] ? e($tot['pausados'] . ' en pausa') : 'con la acción ia_uso' ?></span></div>
</section>

<?php foreach ($filas as $f): $inst = $f['inst']; $u = $f['uso']; $dd = $u['datos'] ?? null; ?>
  <section class="tarjeta ia-prog mt" id="ia-<?= e($inst['id']) ?>" aria-label="<?= e($inst['nombre']) ?>">
    <div class="ia-cab">
      <h2><?= e($inst['nombre']) ?></h2>
      <?php if ($dd): ?>
        <?php if ($dd['pausada']): ?><span class="estado pausa">En pausa</span>
        <?php elseif ($dd['configurada'] === false): ?><span class="estado pausa">Sin configurar</span>
        <?php elseif ($dd['tope_programa'] !== null && $dd['totales']['costo'] >= $dd['tope_programa']): ?><span class="estado off">Llegó a su tope</span>
        <?php else: ?><span class="estado on">Encendida</span><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php if (!$f['reporta']): ?>
      <p class="vacio ia-no-reporta"><?= icono('info') ?> Este programa aún no reporta IA.</p>
    <?php elseif (!$dd): ?>
      <div class="aviso error"><?= icono('error') ?><p>No se pudo leer el uso de IA: <?= e((string) ($u['error'] ?? 'sin respuesta')) ?></p></div>
    <?php else: ?>
      <?php if ($u['error']): ?><div class="aviso info mb"><?= icono('info') ?><p>No contestó ahora (<?= e(rtrim((string) $u['error'], '. ')) ?>). <?= e(datos_de($u['de_copia'])) ?>.</p></div><?php endif; ?>
      <p class="ia-linea"><b><?= e(Ia::dinero($dd['totales']['costo'])) ?></b><?= $dd['tope_programa'] !== null ? ' de ' . e(Ia::dinero($dd['tope_programa'])) . ' de tope' : '' ?>
        · <?= e(numero_corto($dd['totales']['llamadas'])) ?> llamadas · <?= e(numero_corto($dd['totales']['ahorradas'])) ?> sin gastar
        <?= $dd['totales']['ayudas_nuevas'] ? '· ' . e(numero_corto($dd['totales']['ayudas_nuevas'])) . ' respuestas nuevas de la Ayuda' : '' ?>
        <?= $dd['faq'] ? '· ' . e($dd['faq']['total']) . ' preguntas guardadas' . ($dd['faq']['sin_aprobar'] ? ' (' . e($dd['faq']['sin_aprobar']) . ' por revisar)' : '') : '' ?></p>
      <?php if ($dd['tope_programa'] !== null && $dd['tope_programa'] > 0): $pct = min(100, (int) round($dd['totales']['costo'] / $dd['tope_programa'] * 100)); ?>
        <div class="ia-barra" role="img" aria-label="<?= $pct ?> % del tope usado"><span class="ia-barra-<?= $pct >= 100 ? 'llena' : ($pct >= 80 ? 'alta' : 'ok') ?> ia-p<?= (int) (round($pct / 5) * 5) ?>"></span></div>
      <?php endif; ?>
      <?php if ($dd['negocios']): ?>
        <div class="tabla-env"><table class="tabla ia-tabla">
          <thead><tr><th>Negocio</th><th>Gasto</th><th>Llamadas</th><th>Sin gastar</th><th>Tope</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($dd['negocios'], 0, 15) as $n): ?>
            <tr>
              <td><a href="<?= e(url('cliente', ['i' => $inst['id'], 'id' => $n['cliente_id']])) ?>"><?= e($n['nombre']) ?></a><?= $n['modulo'] ? ' <span class="chip-mini">Asistente IA</span>' : '' ?></td>
              <td><?= e(Ia::dinero($n['costo'])) ?></td>
              <td><?= e(numero_corto($n['llamadas'])) ?></td>
              <td><?= e(numero_corto($n['ahorradas'])) ?></td>
              <td><?= $n['tope'] !== null ? e(Ia::dinero($n['tope'])) . ($n['costo'] >= $n['tope'] ? ' <span class="estado off">al tope</span>' : '') : '<span class="suave">sin tope</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php if (count($dd['negocios']) > 15): ?><p class="chico suave mt">Y <?= count($dd['negocios']) - 15 ?> negocios más con menos gasto.</p><?php endif; ?>
      <?php else: ?>
        <p class="vacio"><?= icono('ok') ?> Ningún negocio usó la IA este mes.</p>
      <?php endif; ?>
    <?php endif; ?>
  </section>
<?php endforeach; ?>

<section class="tarjeta mt" id="pausar-ia" aria-labelledby="pausar-ia-t">
  <h2 id="pausar-ia-t"><?= icono('pausa') ?> Pausar toda la IA</h2>
  <p>Corta <b>al instante</b> el gasto de IA de <b>todos</b> los programas. A los clientes no se les dice nada: la Ayuda sigue respondiendo con las preguntas guardadas y el manual, y en las fotos les sale «puede hacerlo a mano».</p>
  <p class="chico suave">El panel no puede cambiar archivos del servidor (<span class="mono">/etc/tukan-ia.env</span> es de root). Copia el comando y pégalo en qfcorp con tu app de terminal:</p>
  <details class="ia-pausar mt">
    <summary class="btn btn-pausar"><?= icono('pausa') ?> Pausar toda la IA</summary>
    <div class="comando mt" id="cmd-pausar"><span class="prompt" aria-hidden="true">$ </span><span data-texto><?= e($cmd('--pausar')) ?></span></div>
    <div class="acciones-form"><button type="button" class="btn btn-oscuro" data-copiar="#cmd-pausar [data-texto]"><?= icono('copiar') ?> Copiar comando</button><span class="copiado" aria-live="polite"></span></div>
  </details>
  <ul class="ia-comandos mt">
    <li><b>Volver a encenderla:</b> <span class="mono"><?= e($cmd('--reanudar')) ?></span></li>
    <li><b>Tope del mes de un programa</b> (en dólares; al llegar deja de gastar en silencio): <span class="mono"><?= e($cmd('--tope tuevento 20')) ?></span> · sin tope: <span class="mono">--tope tuevento quitar</span></li>
    <li><b>Cambiar la clave o los modelos:</b> <span class="mono"><?= e($cmd('')) ?></span> (hace una prueba y dice «IA: FUNCIONA»)</li>
    <li><b>Tope de cada negocio:</b> lo guarda cada programa. Si el programa lo permite, sale como botón en la ficha del cliente (operación <span class="mono">ia_tope</span>).</li>
  </ul>
</section>
