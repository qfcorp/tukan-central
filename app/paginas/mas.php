<?php
declare(strict_types=1);

/** "Más" (celular): todo lo que no cabe en los 4 botones de abajo. */
$items = [
    ['vencimientos', 'Vencimientos', 'calendario', 'Qué día vence cada demo y cada plan'],
    ['demos', 'Demos', 'demo', 'Crear demos y ver cuántas pagan'],
    ['embudo', 'Embudo', 'embudo', 'Cuántos interesados se vuelven clientes'],
    ['programas', 'Programas', 'programas', 'Tus programas conectados y qué puede hacer cada uno'],
];
if (puede('finanzas')) array_splice($items, 2, 0, [['gastos', 'Gastos', 'dinero', 'Gastos de Tukán, fijos y del mes'], ['ia', 'IA', 'rayo', 'Lo que gasta la inteligencia artificial de cada programa']]);
if (puede('auditoria')) $items[] = ['auditoria', 'Auditoría', 'actividad', 'Todo lo que pasó en el panel'];
if (puede('usuarios')) $items[] = ['usuarios', 'Usuarios', 'usuarios', 'Quién más entra al panel'];
if (puede('ajustes')) $items[] = ['ajustes', 'Ajustes', 'ajustes', 'Avisos de Telegram, mensajes de WhatsApp'];
$items[] = ['ayuda', 'Ayuda', 'ayuda', 'Cómo conectar y qué hacer si algo falla'];
$items[] = ['mi_cuenta', 'Mi cuenta', 'cuenta', 'Contraseña, código de 2 pasos y sesiones'];
vista('mas', ['items' => $items], 'Más · Panel Tukán');
