<div class="cabeza"><div><h1>Más</h1><p class="sub">Todo el panel en un solo lugar.</p></div></div>
<nav class="lista-mas tarjeta" aria-label="Más opciones">
  <?php foreach ($items as [$r, $t, $i, $d]): ?>
    <a href="<?= e(url($r)) ?>"><span class="ico-mas"><?= icono($i) ?></span><span><strong><?= e($t) ?></strong><small><?= e($d) ?></small></span><?= icono('chevron') ?></a>
  <?php endforeach; ?>
</nav>
