<?php
declare(strict_types=1);

use Tukan\Contacto;

/**
 * "Recordar por WhatsApp": anota que se le escribió (para no escribir dos veces) y abre WhatsApp con el mensaje
 * ya escrito según su estado. Solo el rol dueño (ve teléfonos). El mensaje: el del programa si lo manda
 * (mensajes_sugeridos, campo "msg"), si no la plantilla de Ajustes.
 */
$c = clientes()->obtener(campo('i'), campo('id'));
$ct = $c ? clientes()->contacto($c) : null;
if (!$c || empty($ct['whatsapp'])) {
    aviso('Ese cliente no tiene WhatsApp guardado.', 'error');
    redirigir('clientes');
}
$tipo = Contacto::plantillaPara($c + ['total_pagado' => $c['total_pagado']]) ?? 'wa_bienvenida';
$msg = campo('msg');
if ($msg === '' || mb_strlen($msg) > 3000) {
    $monto = $c['datos']['pagos']['ultimo_monto'] ?? null;
    $msg = Contacto::plantilla(ajustes()->plantilla($tipo), [
        'nombre' => explode(' ', (string) ($ct['nombre'] ?? ''))[0], 'negocio' => $c['nombre'], 'programa' => $c['programa'],
        'vence' => fecha_dm($c['vence'], true), 'monto' => $monto !== null ? dinero((float) $monto) : 'el de su plan',
    ]);
}
clientes()->recordar($c['instancia_id'], $c['cliente_id'], campo('tipo') !== '' ? mb_substr(campo('tipo'), 0, 30) : $tipo, (int) usuario()['id']);
auditar('recordatorio', $c['nombre'] . ' (' . $c['programa'] . ') · ' . (Contacto::NOMBRES_PLANTILLAS[$tipo] ?? $tipo), $c['instancia_id']);
header('Location: ' . Contacto::urlWhatsapp($ct['whatsapp'], $msg), true, 303);
exit;
