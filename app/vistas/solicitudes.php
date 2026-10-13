<?php
use Tukan\Solicitudes;

$tabs = ['pendiente' => 'Por atender', 'en_proceso' => 'En proceso', 'atendidas' => 'Atendidas'];
?>
<div class="cabeza">
  <div>
    <h1>Solicitudes</h1>
    <p class="sub">Lo que piden tus clientes en todos los programas · <?= e(datos_de($momento)) ?></p>
  </div>
  <div class="acciones">
    <form method="post" action="<?= e(url('actualizar_datos')) ?>"><?= campo_csrf() ?><input type="hidden" name="que" value="solicitudes"><input type="hidden" name="volver" value="<?= e(aqui()) ?>">
      <button class="btn" type="submit"><?= icono('refrescar') ?> Actualizar</button></form>
  </div>
</div>

<nav class="pestanas" aria-label="Estado">
  <?php foreach ($tabs as $k => $t): ?>
    <a href="<?= e(url_aqui(['t' => $k], ['chip'])) ?>"<?= $pestana === $k ? ' aria-current="page"' : '' ?>><?= e($t) ?> <span class="n<?= $k === 'pendiente' && $pestanas[$k] ? ' rojo' : '' ?>"><?= $pestanas[$k] ?></span></a>
  <?php endforeach; ?>
</nav>

<div class="chips" role="list" aria-label="Filtrar por tipo">
  <?php foreach (Solicitudes::FILTROS as $k => [$t]): if ($k !== 'todo' && !$chips[$k] && $chip !== $k) continue; ?>
    <a role="listitem" class="chip<?= $chip === $k ? ' activo' : '' ?>" href="<?= e(url_aqui(['chip' => $k])) ?>"><?= e($t) ?> <b><?= $chips[$k] ?></b></a>
  <?php endforeach; ?>
</div>

<form method="get" action="/" class="buscador">
  <input type="hidden" name="r" value="solicitudes"><input type="hidden" name="t" value="<?= e($pestana) ?>"><input type="hidden" name="chip" value="<?= e($chip) ?>">
  <label class="oculto" for="q">Buscar</label>
  <input id="q" type="search" name="q" value="<?= e($f['buscar']) ?>" placeholder="<?= ve_contacto() ? 'Buscar por nombre o teléfono' : 'Buscar por nombre' ?>">
  <label class="oculto" for="fi">Programa</label>
  <select id="fi" name="i"><option value="">Todos los programas</option>
    <?php foreach ($insts as $i): ?><option value="<?= e($i['id']) ?>" <?= $f['inst'] === $i['id'] ? 'selected' : '' ?>><?= e($i['nombre']) ?></option><?php endforeach; ?></select>
  <button class="btn btn-oscuro" type="submit"><?= icono('buscar') ?><span class="oculto-cel">Buscar</span></button>
</form>

<?php if (!$filas): ?>
  <div class="tarjeta vacia">
    <p class="vacio grande"><?= icono('ok') ?> <?= $pestana === 'pendiente' && $f['buscar'] === '' && $chip === 'todo' ? 'No hay nada por atender. 👏' : 'No hay solicitudes con estos filtros.' ?></p>
    <?php if (!$insts): ?><p class="suave mt">Todavía ningún programa manda sus solicitudes. Cuando actualices un programa al protocolo v2, aparecerán aquí.</p><?php endif; ?>
  </div>
<?php else: ?>
  <div class="lista-sol">
    <?php foreach ($filas as $s): require __DIR__ . '/_tarjeta_solicitud.php'; endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($sinV2): ?>
  <p class="chico suave mt"><?= icono('info') ?> <?= e(implode(', ', array_column($sinV2, 'nombre'))) ?> todavía no <?= count($sinV2) === 1 ? 'manda' : 'mandan' ?> sus solicitudes (actualiza <?= count($sinV2) === 1 ? 'ese programa' : 'esos programas' ?> al protocolo v2).</p>
<?php endif; ?>
