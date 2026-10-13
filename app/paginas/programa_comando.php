<?php
declare(strict_types=1);

/** Muestra UNA vez el comando con el secreto. Si se recarga la página, ya no aparece. */
$inst = instancias()->obtener(param('id'));
if (!$inst) redirigir('programas');
$c = $_SESSION['comando'] ?? null;
unset($_SESSION['comando']);
if (!$c || $c['id'] !== $inst['id']) {
    aviso('Por seguridad, el comando con el secreto se muestra una sola vez. Si no lo copiaste, toca "Cambiar secreto" para generar otro.', 'info');
    redirigir('programa', ['id' => $inst['id']]);
}
vista('programa_comando', ['inst' => $inst, 'comando' => $c['comando'], 'nuevo' => $c['nuevo']], 'Conectar ' . $inst['nombre'] . ' · Panel Tukán');
