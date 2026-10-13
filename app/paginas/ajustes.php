<?php
declare(strict_types=1);

use Tukan\{Ajustes, Telegram};

/** Ajustes del panel: avisos de Telegram. */
if (es_post()) {
    if (campo('accion') === 'probar_telegram') {
        $ok = (new Telegram())->enviar('Panel Tukán · Mensaje de prueba de ' . usuario()['nombre'] . '. Si lees esto, los avisos funcionan. ✅');
        auditar('ajustes', 'Mensaje de prueba de Telegram: ' . ($ok ? 'enviado' : 'falló'));
        aviso($ok ? 'Mensaje de prueba enviado. Revisa tu Telegram.' : 'No se pudo enviar. Revisa TELEGRAM_BOT_TOKEN y TELEGRAM_CHAT_ID en config/.env.', $ok ? 'ok' : 'error');
    } else {
        $cambios = [];
        foreach (array_keys(Ajustes::PREDETERMINADOS) as $k) {
            $nuevo = isset($_POST[$k]) ? '1' : '0';
            if (ajustes()->valor($k) !== $nuevo) {
                ajustes()->guardar($k, $nuevo);
                $cambios[] = "$k=" . $nuevo;
            }
        }
        if ($cambios) auditar('ajustes', implode(', ', $cambios));
        aviso($cambios ? 'Ajustes guardados.' : 'No había nada que cambiar.', $cambios ? 'ok' : 'info');
    }
    redirigir('ajustes');
}
vista('ajustes', ['telegram' => Telegram::configurado()], 'Ajustes · Panel Tukán');
