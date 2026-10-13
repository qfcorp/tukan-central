<?php use Tukan\{Contacto, Solicitudes}; ?>
<div class="cabeza">
  <div>
    <h1>Ajustes</h1>
    <p class="sub">Avisos por Telegram, mensajes de WhatsApp y finanzas.</p>
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
      <div><dt>Protocolo</dt><dd>Tukán Central v2 (habla v1 con los programas que no se han actualizado)</dd></div>
      <div><dt>Revisión automática</dt><dd>Cada 15 minutos (bin/revisar.php)</dd></div>
    </dl>
  </div>
</div>

<div class="rejilla dos seccion">
  <form method="post" action="<?= e(url('ajustes')) ?>" class="tarjeta" id="ajustes-solicitudes">
    <h2>Avisos de solicitudes</h2>
    <?= campo_csrf() ?><input type="hidden" name="accion" value="solicitudes">
    <label class="check mb"><input type="checkbox" name="avisar_solicitudes" value="1" <?= ajustes()->si('avisar_solicitudes') ? 'checked' : '' ?>> <span>Avisar por Telegram cada <b>solicitud nueva</b> (el teléfono va escondido)</span></label>
    <fieldset class="tipos-aviso"><legend>De qué tipo</legend>
      <?php foreach (Solicitudes::TIPOS as $k => [$t, $ico]): ?>
        <label class="check"><input type="checkbox" name="tipos[]" value="<?= e($k) ?>" <?= in_array($k, $tipos, true) ? 'checked' : '' ?>> <span><?= $ico ?> <?= e($t) ?></span></label>
      <?php endforeach; ?>
    </fieldset>
    <div class="fila-campos mt">
      <div class="campo"><label for="s-desde">Silencio desde</label><input id="s-desde" type="time" name="silencio_desde" value="<?= e(ajustes()->valor('silencio_desde')) ?>"></div>
      <div class="campo"><label for="s-hasta">hasta</label><input id="s-hasta" type="time" name="silencio_hasta" value="<?= e(ajustes()->valor('silencio_hasta')) ?>"></div>
    </div>
    <p class="chico suave mb">Las que lleguen en la noche salen juntas al terminar el silencio.</p>
    <label class="check mb"><input type="checkbox" name="resumen_diario" value="1" <?= ajustes()->si('resumen_diario') ? 'checked' : '' ?>> <span><b>Resumen de cada mañana</b> (07:30): por atender, pruebas que terminan, planes que vencen, cobrado y gastos del mes</span></label>
    <button class="btn btn-primario" type="submit">Guardar</button>
  </form>
  <form method="post" action="<?= e(url('ajustes')) ?>" class="tarjeta" id="ajustes-whatsapp">
    <h2>Mensajes de WhatsApp</h2>
    <p class="chico suave mb">Se usan solo si el programa no manda su propio mensaje. Puedes usar <span class="mono">{nombre} {negocio} {programa} {vence} {monto}</span>. Déjalo vacío para el texto de fábrica.</p>
    <?= campo_csrf() ?><input type="hidden" name="accion" value="whatsapp">
    <?php foreach (Contacto::PLANTILLAS as $k => $def): ?>
      <div class="campo"><label for="w-<?= e($k) ?>"><?= e(Contacto::NOMBRES_PLANTILLAS[$k]) ?></label><textarea id="w-<?= e($k) ?>" name="<?= e($k) ?>" maxlength="1000" placeholder="<?= e($def) ?>"><?= e(ajustes()->valor($k)) ?></textarea></div>
    <?php endforeach; ?>
    <button class="btn btn-primario" type="submit">Guardar mensajes</button>
  </form>
</div>

<form method="post" action="<?= e(url('ajustes')) ?>" class="tarjeta seccion">
  <h2>Finanzas</h2>
  <?= campo_csrf() ?><input type="hidden" name="accion" value="finanzas">
  <label class="check mb"><input type="checkbox" name="repartir_gastos_generales" value="1" <?= ajustes()->si('repartir_gastos_generales') ? 'checked' : '' ?>> <span><b>Repartir los gastos generales</b> (los que no son de un programa) según lo que gana cada programa en el mes</span></label>
  <button class="btn btn-primario" type="submit">Guardar</button>
</form>
