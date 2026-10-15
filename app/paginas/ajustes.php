<?php
declare(strict_types=1);

use Tukan\{Ajustes, Cifrado, Cloudflare, Contacto, Sincronizador, Solicitudes, Telegram};

/** Ajustes del panel: avisos de Telegram (caídas y solicitudes), mensajes de WhatsApp, finanzas y Cloudflare (opcional). */
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
    // Cloudflare (opcional): token cifrado + cuenta + túnel, para quitar la dirección de una instalación borrada
    if (in_array($accion, ['cloudflare', 'probar_cloudflare', 'borrar_cloudflare'], true)) {
        $ir = function (): never { header('Location: ' . url('ajustes') . '#cloudflare', true, 303); exit; };
        if ($accion === 'borrar_cloudflare') {
            Cloudflare::borrarConfig(ajustes());
            auditar('ajustes', 'Cloudflare: se borró el token guardado');
            aviso('Se borró el token de Cloudflare. Las direcciones se quitarán a mano.', 'ok');
            $ir();
        }
        if ($accion === 'probar_cloudflare') {
            try {
                $cf = Cloudflare::desdeAjustes(ajustes());
                if ($cf === null) throw new RuntimeException('Primero guarda el token, la cuenta y el túnel.');
                $nombre = $cf->probar();
                auditar('ajustes', "Cloudflare: prueba bien (túnel $nombre)");
                aviso("Cloudflare responde bien: túnel «{$nombre}».", 'ok');
            } catch (Throwable $e) {
                auditar('ajustes', 'Cloudflare: la prueba falló');
                aviso('Cloudflare: ' . $e->getMessage(), 'error');
            }
            $ir();
        }
        $token = preg_replace('/\s+/', '', (string) ($_POST['cf_token'] ?? '')) ?? '';
        $cuenta = strtolower(campo('cf_cuenta'));
        $tunel = strtolower(campo('cf_tunel'));
        $hay = ajustes()->valor('cf_token_cif') !== '';
        $mal = [];
        if ($token === '' && !$hay) $mal[] = 'falta el token';
        if ($token !== '' && !Cloudflare::tokenValido($token)) $mal[] = 'el token no parece de Cloudflare';
        if (!Cloudflare::cuentaValida($cuenta)) $mal[] = 'el ID de la cuenta debe tener 32 letras y números';
        if (!Cloudflare::tunelValido($tunel)) $mal[] = 'el ID del túnel debe ser como 1a2b3c4d-1111-2222-3333-444455556666';
        if ($mal) {
            aviso('Cloudflare no se guardó: ' . implode(', ', $mal) . '.', 'error');
            $ir();
        }
        Cloudflare::guardar(ajustes(), new Cifrado(), $token, $cuenta, $tunel);
        auditar('ajustes', 'Cloudflare: ' . ($token !== '' ? 'token guardado (cifrado), ' : '') . "cuenta $cuenta, túnel $tunel");
        aviso('Cloudflare guardado. Toca «Probar» para revisar el token.', 'ok');
        $ir();
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
vista('ajustes', ['telegram' => Telegram::configurado(), 'tipos' => sincronizador()->tiposAviso(), 'cloudflare' => Cloudflare::configurado(ajustes()),
                  'cfToken' => ajustes()->valor('cf_token_cif') !== ''], 'Ajustes · Panel Tukán');
