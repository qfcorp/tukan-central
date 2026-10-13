<div class="cabeza">
  <div>
    <h1>Ajustes</h1>
    <p class="sub">Avisos por Telegram y datos del panel.</p>
  </div>
</div>
<div class="rejilla dos">
  <form method="post" action="<?= e(url('ajustes')) ?>" class="tarjeta">
    <h2>Avisos por Telegram</h2>
    <?php if (!$telegram): ?>
      <div class="aviso info mb"><?= icono('info') ?><p>Telegram no está configurado. Para recibir avisos, agrega <span class="mono">TELEGRAM_BOT_TOKEN</span> y <span class="mono">TELEGRAM_CHAT_ID</span> en <span class="mono">config/.env</span> (explicado en el README).</p></div>
    <?php else: ?>
      <p class="suave mb">El panel revisa tus programas cada 15 minutos y te avisa:</p>
    <?php endif; ?>
    <?= campo_csrf() ?><input type="hidden" name="accion" value="guardar">
    <label class="check mb"><input type="checkbox" name="avisar_caidas" value="1" <?= ajustes()->si('avisar_caidas') ? 'checked' : '' ?>> <span>Cuando un programa se queda <b>sin conexión</b> y cuando vuelve</span></label>
    <label class="check mb"><input type="checkbox" name="avisar_negocios" value="1" <?= ajustes()->si('avisar_negocios') ? 'checked' : '' ?>> <span>Cuando se registra un <b>negocio nuevo</b></span></label>
    <button class="btn btn-primario" type="submit">Guardar</button>
  </form>
  <div class="tarjeta">
    <h2>Probar Telegram</h2>
    <p class="suave mb">Manda un mensaje de prueba al chat configurado.</p>
    <form method="post" action="<?= e(url('ajustes')) ?>"><?= campo_csrf() ?><input type="hidden" name="accion" value="probar_telegram">
      <button class="btn" type="submit" <?= $telegram ? '' : 'disabled' ?>>Enviar mensaje de prueba</button></form>
    <h2 class="mt">Este panel</h2>
    <dl class="datos">
      <div><dt>Dirección</dt><dd class="mono"><?= e(url_central()) ?></dd></div>
      <div><dt>Protocolo</dt><dd>Tukán Central v1</dd></div>
      <div><dt>Revisión automática</dt><dd>Cada 15 minutos (bin/revisar.php)</dd></div>
    </dl>
  </div>
</div>
