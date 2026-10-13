<div class="tarjeta">
  <h2><?= e($titulo) ?></h2>
  <p class="suave mt"><?= e($mensaje) ?></p>
  <p class="mt"><a class="btn" href="<?= e(url(usuario() ? 'inicio' : 'login')) ?>"><?= icono('volver') ?> Volver</a></p>
</div>
