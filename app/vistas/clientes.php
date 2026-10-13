<?php
use Tukan\{Clientes, Contacto};

$hayFiltro = $f['buscar'] !== '' || $f['inst'] !== '' || $f['plan'] !== '' || $f['vence_en'] || $f['sin_uso'] || $f['origen'] !== '';
?>
<div class="cabeza">
  <div>
    <h1>Clientes</h1>
    <p class="sub">De todos tus programas · <?= e(datos_de($momento)) ?></p>
  </div>
  <div class="acciones">
    <form method="post" action="<?= e(url('actualizar_datos')) ?>"><?= campo_csrf() ?><input type="hidden" name="que" value="clientes"><input type="hidden" name="volver" value="<?= e(aqui()) ?>">
      <button class="btn" type="submit"><?= icono('refrescar') ?> Actualizar</button></form>
  </div>
</div>

<form method="get" action="/" class="tarjeta filtros-clientes">
  <input type="hidden" name="r" value="clientes"><?php if ($estado !== ''): ?><input type="hidden" name="estado" value="<?= e($estado) ?>"><?php endif; ?>
  <div class="busqueda"><label class="oculto" for="q">Buscar</label>
    <input id="q" type="search" name="q" value="<?= e($f['buscar']) ?>" placeholder="<?= ve_contacto() ? 'Nombre, teléfono o correo' : 'Nombre del negocio' ?>">
    <button class="btn btn-oscuro" type="submit"><?= icono('buscar') ?><span class="oculto-cel">Buscar</span></button></div>
  <details class="filtros-mas"<?= ($f['inst'] !== '' || $f['plan'] !== '' || $f['vence_en'] || $f['sin_uso'] || $f['origen'] !== '') ? ' open' : '' ?>>
    <summary><?= icono('filtro') ?> Filtros: programa, plan, vencimiento, sin uso</summary>
    <div class="filtros-grid">
  <div class="campo"><label for="fi">Programa</label><select id="fi" name="i"><option value="">Todos</option>
    <?php foreach ($insts as $i): ?><option value="<?= e($i['id']) ?>" <?= $f['inst'] === $i['id'] ? 'selected' : '' ?>><?= e($i['nombre']) ?></option><?php endforeach; ?></select></div>
  <div class="campo"><label for="fp">Plan</label><select id="fp" name="plan"><option value="">Todos</option>
    <?php foreach ($planes as $p): ?><option value="<?= e($p) ?>" <?= $f['plan'] === $p ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?></select></div>
  <div class="campo"><label for="fo">De dónde vino</label><select id="fo" name="origen"><option value="">Todos</option>
    <?php foreach (Clientes::ORIGENES as $k => $t): ?><option value="<?= e($k) ?>" <?= $f['origen'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
  <fieldset class="campo grupo-chips"><legend>Se vence en</legend>
    <?php foreach (['' => 'Cuando sea', '7' => '7 días', '15' => '15 días', '30' => '30 días'] as $k => $t): ?>
      <label class="chip-radio"><input type="radio" name="vence" value="<?= e($k) ?>" <?= (string) ($f['vence_en'] ?? '') === $k ? 'checked' : '' ?>><span><?= e($t) ?></span></label>
    <?php endforeach; ?></fieldset>
  <label class="check sin-uso"><input type="checkbox" name="sin_uso" value="15" <?= $f['sin_uso'] ? 'checked' : '' ?>> <span>Sin usar el programa hace 15 días <small class="suave">(en riesgo de irse)</small></span></label>
  <div class="acciones-form"><button class="btn btn-oscuro" type="submit"><?= icono('filtro') ?> Filtrar</button><?php if ($hayFiltro || $estado !== ''): ?><a class="btn" href="<?= e(url('clientes')) ?>">Limpiar</a><?php endif; ?></div>
    </div>
  </details>
</form>

<div class="chips mt" role="list" aria-label="Estado">
  <a role="listitem" class="chip<?= $estado === '' ? ' activo' : '' ?>" href="<?= e(url_aqui([], ['estado'])) ?>">Todos <b><?= array_sum($contadores) ?></b></a>
  <?php foreach (Clientes::ESTADOS as $k => $t): ?>
    <a role="listitem" class="chip c-<?= e($k) ?><?= $estado === $k ? ' activo' : '' ?>" href="<?= e(url_aqui(['estado' => $k])) ?>"><?= e($t) ?> <b><?= $contadores[$k] ?></b></a>
  <?php endforeach; ?>
</div>

<?php if (!$filas): ?>
  <div class="tarjeta vacia"><p class="vacio grande"><?= icono('info') ?> <?= $insts ? 'No hay clientes con estos filtros.' : 'Todavía ningún programa manda su lista de clientes.' ?></p></div>
<?php else: ?>
<div class="tarjeta lista-clientes">
  <p class="chico suave mb"><?= $total ?> <?= $total === 1 ? 'cliente' : 'clientes' ?> · los que vencen primero arriba</p>
  <div class="tabla-env">
  <table class="tabla apilable clientes" id="tabla-clientes">
    <thead><tr><th>Cliente</th><th>Estado</th><th>Plan</th><th>Vence</th><th>Último uso</th><th>Pagado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($filas as $c): $rec = $recordados[$c['instancia_id'] . '|' . $c['cliente_id']] ?? null; $ct = $c['contacto'] ?? [];
          $msg = $mensaje($c); $d = $c['datos']; $dias = $c['vence'] ? dias_hasta($c['vence']) : null; ?>
      <tr class="p-<?= e($c['producto']) ?>" data-cliente="<?= e($c['instancia_id'] . '|' . $c['cliente_id']) ?>">
        <td class="c-nombre"><div class="prog-top"><?= ico_programa($c['producto']) ?><div class="prog-tit">
          <a href="<?= e(url('cliente', ['i' => $c['instancia_id'], 'id' => $c['cliente_id']])) ?>"><strong><?= e($c['nombre']) ?></strong></a>
          <span class="url"><?= e($c['programa']) ?><?= !empty($d['ciudad']) ? ' · ' . e($d['ciudad']) : '' ?></span>
          <?php if ($rec): ?><span class="recordado"><?= icono('check') ?> Recordado <?= e(hace($rec['momento'])) ?><?= $rec['quien'] ? ' por ' . e(explode(' ', $rec['quien'])[0]) : '' ?></span><?php endif; ?>
        </div></div></td>
        <td data-t="Estado"><?= etiqueta_estado($c['estado']) ?><?= !empty($d['en_gracia']) ? ' <span class="chico suave">en gracia</span>' : '' ?></td>
        <td data-t="Plan"><?= e($c['plan'] ?? '—') ?></td>
        <td data-t="Vence" class="nowrap<?= $dias !== null && $dias <= 7 && $c['estado'] !== 'suspendido' ? ' urgente-suave' : '' ?>"><?= $c['vence'] ? e(fecha_dm($c['vence'])) . ' · ' . e(falta($dias)) : 'No vence' ?></td>
        <td data-t="Último uso"><?= e(hace_iso($c['ultima_actividad'])) ?></td>
        <td data-t="Pagado"><?= e(dinero((float) $c['total_pagado'])) ?></td>
        <td class="c-acc">
          <?php if ($msg && ve_contacto() && !empty($ct['whatsapp'])): ?>
            <form method="post" action="<?= e(url('recordar')) ?>" target="_blank" class="enlinea"><?= campo_csrf() ?>
              <input type="hidden" name="i" value="<?= e($c['instancia_id']) ?>"><input type="hidden" name="id" value="<?= e($c['cliente_id']) ?>">
              <button class="btn btn-chico btn-wa" type="submit"><?= icono('whatsapp') ?> Recordar</button></form>
          <?php endif; ?>
          <a class="btn btn-chico" href="<?= e(url('cliente', ['i' => $c['instancia_id'], 'id' => $c['cliente_id']])) ?>">Ficha</a>
          <?php if (puede('entrar')): ?><?= boton_entrar($c['instancia_id'], $d['ir'] ?? null, 'Entrar') ?><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($paginas > 1): ?>
  <div class="paginas">
    <?= $pagina > 1 ? '<a class="btn btn-chico" href="' . e(url_aqui(['pagina' => $pagina - 1])) . '">← Anteriores</a>' : '<span></span>' ?>
    <span>Página <?= $pagina ?> de <?= $paginas ?></span>
    <?= $pagina < $paginas ? '<a class="btn btn-chico" href="' . e(url('clientes', array_diff_key($_GET, ['r' => 1]) + ['pagina' => $pagina + 1])) . '">Siguientes →</a>' : '<span></span>' ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($sinLista): ?>
  <p class="chico suave mt"><?= icono('info') ?> <?= e(implode(', ', array_column($sinLista, 'nombre'))) ?> todavía no <?= count($sinLista) === 1 ? 'manda' : 'mandan' ?> su lista de clientes (solo cifras en Inicio). Actualiza <?= count($sinLista) === 1 ? 'ese programa' : 'esos programas' ?> al protocolo v2.</p>
<?php endif; ?>
