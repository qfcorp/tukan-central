<?php
declare(strict_types=1);

use Tukan\{Ajustes, Contacto, Sincronizador, Solicitudes, Telegram};

/** Ajustes del panel: avisos de Telegram (caídas y solicitudes), mensajes de WhatsApp y finanzas. */
if (es_post()) {
    $accion = campo('accion');
    $cambios = [];
    $poner = function (string $k, string $v) use (&$cambios): void {
        if (ajustes()->valor($k) !== $v) {
            ajustes()->guardar($k, $v);
            $cambios[] = str_starts_with($k, 'wa_') ? "$k (texto)" : "$k=$v";
        }
    };
    if ($accion === 'probar_telegram') {
        $ok = (new Telegram())->enviar('Panel Tukán · Mensaje de prueba de ' . usuario()['nombre'] . '. Si lees esto, los avisos funcionan. ✅');
        auditar('ajustes', 'Mensaje de prueba de Telegram: ' . ($ok ? 'enviado' : 'falló'));
        aviso($ok ? 'Mensaje de prueba enviado. Revisa tu Telegram.' : 'No se pudo enviar. Revisa TELEGRAM_BOT_TOKEN y TELEGRAM_CHAT_ID en config/.env.', $ok ? 'ok' : 'error');
        redirigir('ajustes');
    }
    if ($accion === 'guardar') {
        foreach (['avisar_caidas', 'avisar_negocios'] as $k) $poner($k, isset($_POST[$k]) ? '1' : '0');
    } elseif ($accion === 'solicitudes') {
        $poner('avisar_solicitudes', isset($_POST['avisar_solicitudes']) ? '1' : '0');
        $poner('resumen_diario', isset($_POST['resumen_diario']) ? '1' : '0');
        $tipos = array_values(array_intersect(array_keys(Solicitudes::TIPOS), is_array($_POST['tipos'] ?? null) ? $_POST['tipos'] : []));
        $poner('avisar_tipos', $tipos === Sincronizador::TIPOS_AVISO_PREDETERMINADOS ? '' : (implode(',', $tipos) ?: 'ninguno'));
        foreach (['silencio_desde', 'silencio_hasta'] as $k) {
            $v = campo($k);
            if ($v === '' || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) $poner($k, $v);
        }
    } elseif ($accion === 'whatsapp') {
        foreach (array_keys(Contacto::PLANTILLAS) as $k) {
            $v = mb_substr(trim(campo($k)), 0, 1000);
            $poner($k, $v === Contacto::PLANTILLAS[$k] ? '' : $v);
        }
    } elseif ($accion === 'finanzas') {
        $poner('repartir_gastos_generales', isset($_POST['repartir_gastos_generales']) ? '1' : '0');
    }
    if ($cambios) auditar('ajustes', implode(', ', $cambios));
    aviso($cambios ? 'Ajustes guardados.' : 'No había nada que cambiar.', $cambios ? 'ok' : 'info');
    redirigir('ajustes');
}
vista('ajustes', ['telegram' => Telegram::configurado(), 'tipos' => sincronizador()->tiposAviso()], 'Ajustes · Panel Tukán');
