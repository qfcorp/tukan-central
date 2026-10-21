<?php
use Tukan\Actualizaciones;

/** Más → Actualizaciones. Variables: $instalado, $v, $novedad, $curso, $historial, $ver, $resultado, $log, $ultima, $comando */
$clase = fn(string $e) => match ($e) { 'listo', 'ok', 'al_dia' => 'on', 'fallo', 'grave', 'revertido', 'rechazado', 'vencido', 'error', 'sin_respaldo' => 'off', default => 'pausa' };
$progs = $v['programas'] ?? [];
?>
<div class="cabeza">
  <div>
    <h1>Actualizaciones</h1>
    <p class="sub">Versiones de los programas de este servidor<?= !empty($v['servidor']) ? ' (' . e($v['servidor']) . ')' : '' ?>. Nada se publica hasta que tú lo apruebas.</p>
  </div>
  <?php if ($instalado): ?>
    <form method="post" action="<?= e(url('actualizaciones')) ?>" class="acciones"><?= campo_csrf() ?><input type="hidden" name="accion" value="revisar">
      <button class="btn" type="submit"<?= $curso ? ' disabled' : '' ?>><?= icono('refrescar') ?> Buscar novedades</button>
    </form>
  <?php endif; ?>
</div>

<?php if (!$instalado): ?>
  <div class="tarjeta marco-alerta" id="act-instalar">
    <div class="aviso alerta mb"><?= icono('info') ?><p><b>Falta instalar el actualizador en este servidor (una sola vez).</b> Entra por SSH con tu usuario normal y pega este comando. Sudo te pedirá tu contraseña.</p></div>
    <div class="comando" id="cmd-actualizador"><span class="prompt" aria-hidden="true">$ </span><span data-texto><?= e($comando) ?></span></div>
    <div class="acciones-form">
      <button type="button" class="btn btn-oscuro" data-copiar="#cmd-actualizador [data-texto]"><?= icono('copiar') ?> Copiar comando</button>
      <span class="copiado" aria-live="polite"></span>
    </div>
    <p class="chico suave mt">Deja el comando <span class="mono">actualizar</span> en el servidor y el servicio que atiende este botón. No actualiza nada por su cuenta.</p>
  </div>
<?php endif; ?>

<?php if ($curso): ?>
  <div class="aviso info mb" id="act-curso"><?= icono('refrescar') ?><p><b><?= $curso['tipo'] === 'revisar' ? 'Buscando novedades en GitHub…' : 'Actualizando…' ?></b>
    <?= e(Actualizaciones::textoEstado($curso['estado'])) ?>. Esta pantalla se vuelve a cargar sola cada 5 segundos.</p></div>
<?php endif; ?>

<?php if ($ver && ($resultado || $log !== '' || in_array($ver['estado'], Actualizaciones::FINALES, true))): ?>
  <section class="tarjeta mb" id="act-resultado" aria-label="Resultado">
    <div class="ia-cab">
      <h2><?= $ver['tipo'] === 'revisar' ? 'Búsqueda de novedades' : 'Actualización' ?> del <?= e(fecha_hora((int) $ver['creado'])) ?></h2>
      <span class="estado <?= $clase($ver['estado']) ?>"><?= e(ucfirst(Actualizaciones::textoEstado($ver['estado']))) ?></span>
    </div>
    <?php if ($ver['resumen']): ?><p class="mt"><?= e($ver['resumen']) ?></p><?php endif; ?>
    <?php foreach ($resultado['programas'] ?? [] as $p): if (in_array($p['estado'], ['ok', 'al_dia'], true)) continue; ?>
      <div class="aviso error mt"><?= icono('error') ?><p><b><?= e(Actualizaciones::nombre($p['programa'], $p['programa'])) ?>:</b> <?= e($p['mensaje']) ?></p></div>
    <?php endforeach; ?>
    <?php if ($log !== ''): ?>
      <details class="mt"<?= $curso ? ' open' : '' ?>><summary>Ver lo que hizo el servidor</summary>
        <div class="comando log-servidor mt" id="act-log"><?= e($log) ?></div>
      </details>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($instalado && !$v): ?>
  <div class="vacio tarjeta"><p>Todavía no hay versiones guardadas. Toca <b>Buscar novedades</b>.</p></div>
<?php endif; ?>

<?php if ($progs): ?>
  <p class="chico suave mb">Revisado <?= e(hace((int) $v['revisado'])) ?> (<?= e(fecha_hora((int) $v['revisado'])) ?>).</p>
  <?php if (count($novedad) > 1): ?>
    <form method="post" action="<?= e(url('actualizaciones')) ?>" class="tarjeta mb" id="form-todo" data-confirmar="¿Actualizar <?= count($novedad) ?> programas? Se respalda cada base antes y, si algo falla, vuelve solo a la versión anterior.">
      <?= campo_csrf() ?><input type="hidden" name="accion" value="actualizar"><input type="hidden" name="todo" value="1">
      <h2>Actualizar todo (<?= count($novedad) ?> programas)</h2>
      <p class="chico suave"><?= e(implode(', ', array_map(fn($id) => $progs[$id]['nombre'], array_keys($novedad)))) ?>. El panel va al final.</p>
      <div class="fila-campos mt">
        <div class="campo"><label for="todo-codigo">Código de 2 pasos (de tu app)</label><input id="todo-codigo" name="codigo" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required></div>
      </div>
      <button class="btn btn-primario btn-grande" type="submit"<?= $curso ? ' disabled' : '' ?>><?= icono('refrescar') ?> Actualizar todo</button>
    </form>
  <?php endif; ?>

  <?php foreach ($progs as $id => $p): $nuevo = isset($novedad[$id]); ?>
    <section class="tarjeta mt" id="act-<?= e($id) ?>" aria-label="<?= e($p['nombre']) ?>">
      <div class="ia-cab">
        <h2><?= e($p['nombre']) ?></h2>
        <?php if ($nuevo): ?><span class="estado pausa"><?= (int) $p['nuevos'] ?> <?= (int) $p['nuevos'] === 1 ? 'cambio nuevo' : 'cambios nuevos' ?></span>
        <?php elseif ($p['estado'] === 'al_dia'): ?><span class="estado on">Al día</span>
        <?php else: ?><span class="estado off">No se pudo revisar</span><?php endif; ?>
      </div>
      <dl class="kv mt">
        <div><dt>Instalada</dt><dd class="mono"><?= e($p['instalada']['corto'] ?? '—') ?></dd></div>
        <div><dt>En GitHub</dt><dd class="mono"><?= e($nuevo ? $p['disponible']['corto'] : ($p['instalada']['corto'] ?? '—')) ?></dd></div>
        <div><dt><?= $p['instancias'] ? 'Instalaciones' : 'Fecha instalada' ?></dt><dd><?= $p['instancias'] ? count($p['instancias']) : e(fecha_dm($p['instalada']['fecha'] ?? null)) ?></dd></div>
      </dl>
      <?php if ($p['instancias']): ?><p class="chico suave mt">Instalaciones: <?= e(implode(', ', $p['instancias'])) ?></p><?php endif; ?>
      <?php if (!empty($p['instalada']['titulo'])): ?><p class="chico suave mt">Ahora: <?= e($p['instalada']['titulo']) ?></p><?php endif; ?>
      <?php if ($p['mensaje'] && !$nuevo): ?><div class="aviso error mt"><?= icono('error') ?><p><?= e($p['mensaje']) ?></p></div><?php endif; ?>
      <?php if ($nuevo): ?>
        <h3 class="mt">Lo nuevo</h3>
        <ul class="lista-cambios">
          <?php foreach ($p['cambios'] as $c): ?><li><span class="mono"><?= e($c['corto']) ?></span> <?= e($c['titulo']) ?></li><?php endforeach; ?>
          <?php if ($p['nuevos'] > count($p['cambios'])): ?><li class="suave">… y <?= $p['nuevos'] - count($p['cambios']) ?> más</li><?php endif; ?>
        </ul>
        <form method="post" action="<?= e(url('actualizaciones')) ?>" class="mt" data-confirmar="¿Actualizar <?= e($p['nombre']) ?> a la versión <?= e($p['disponible']['corto']) ?>?">
          <?= campo_csrf() ?><input type="hidden" name="accion" value="actualizar"><input type="hidden" name="programa" value="<?= e($id) ?>"><input type="hidden" name="commit" value="<?= e($novedad[$id]) ?>">
          <div class="fila-campos">
            <div class="campo"><label for="cod-<?= e($id) ?>">Código de 2 pasos (de tu app)</label><input id="cod-<?= e($id) ?>" name="codigo" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required></div>
          </div>
          <button class="btn btn-primario" type="submit"<?= $curso ? ' disabled' : '' ?>><?= icono('refrescar') ?> Actualizar <?= e($p['nombre']) ?></button>
        </form>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
<?php endif; ?>

<?php if ($ultima && ($ultima['origen'] ?? '') === 'consola'): ?>
  <p class="chico suave mt" id="act-consola">Última actualización desde la consola: <?= e(fecha_hora((int) ($ultima['fin'] ?? 0))) ?> — <?= e(Actualizaciones::resumen($ultima)) ?></p>
<?php endif; ?>

<?php if ($historial): ?>
  <section class="tarjeta mt" aria-label="Historial">
    <h2>Últimos pedidos</h2>
    <div class="tabla-env"><table class="tabla">
      <thead><tr><th>Cuándo</th><th>Qué</th><th>Resultado</th></tr></thead>
      <tbody>
      <?php foreach ($historial as $h): $obj = json_decode((string) $h['objetivos'], true) ?: []; ?>
        <tr>
          <td class="nowrap"><a href="<?= e(url('actualizaciones', ['p' => $h['id']])) ?>"><?= e(fecha_hora((int) $h['creado'], false)) ?></a></td>
          <td><?= $h['tipo'] === 'revisar' ? 'Buscar novedades' : e(implode(', ', array_map(fn($x) => Actualizaciones::nombre($x, $x), array_keys($obj)))) ?></td>
          <td><span class="estado <?= $clase($h['estado']) ?>"><?= e(ucfirst(Actualizaciones::textoEstado($h['estado']))) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
<?php endif; ?>
<p class="chico suave mt">También desde el servidor: escribe <span class="mono">actualizar</span> por SSH (sin sudo). Respaldos en <span class="mono">/var/backups/tukan-actualizar</span>, logs en <span class="mono">/var/log/tukan-actualizar</span>.</p>
