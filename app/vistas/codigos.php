<div class="cabeza">
  <div>
    <h1>Tus códigos de respaldo</h1>
    <p class="sub">Si un día pierdes el celular, con uno de estos códigos puedes entrar en lugar del código de la app.</p>
  </div>
</div>
<div class="tarjeta marco-alerta">
  <div class="aviso alerta mb"><?= icono('error') ?><div><p><b>Se muestran una sola vez.</b> Guárdalos ahora: cópialos en un lugar seguro (por ejemplo, una nota con candado o un papel en tu caja fuerte). No los mandes por WhatsApp.</p><p>Cada código sirve una sola vez.</p></div></div>
  <ul class="codigos-r" id="codigos-respaldo">
    <?php foreach ($codigos as $c): ?><li><?= e($c) ?></li><?php endforeach; ?>
  </ul>
  <div class="acciones-form mt no-imprimir">
    <button type="button" class="btn" data-copiar="#codigos-respaldo"><?= icono('copiar') ?> Copiar los 8</button>
    <button type="button" class="btn" data-imprimir><?= icono('imprimir') ?> Imprimir</button>
    <span class="copiado" aria-live="polite"></span>
  </div>
</div>
<form method="post" action="<?= e(url('codigos')) ?>" class="mt no-imprimir">
  <?= campo_csrf() ?>
  <button class="btn btn-primario btn-grande" type="submit"><?= icono('ok') ?> Ya los guardé, continuar</button>
</form>
