<?php
declare(strict_types=1);

use Tukan\Instancias;

/** "Cambiar secreto": el secreto anterior deja de servir y se muestra el comando nuevo una vez. */
$inst = instancias()->obtener(campo('id'));
if (!$inst) redirigir('programas');
try {
    $secreto = instancias()->rotar($inst['id']);
    $_SESSION['comando'] = ['id' => $inst['id'], 'comando' => Instancias::comando($inst, $secreto, url_central()), 'nuevo' => false];
    auditar('secreto_cambiado', $inst['nombre'], $inst['id']);
    redirigir('programa_comando', ['id' => $inst['id']]);
} catch (InvalidArgumentException $e) {
    // El comando no se pudo armar (ej. falta la carpeta): el secreto ya cambió, hay que corregir y rotar otra vez
    auditar('secreto_cambiado', $inst['nombre'] . ' (sin comando: ' . $e->getMessage() . ')', $inst['id']);
    aviso('Se cambió el secreto, pero no se pudo armar el comando: ' . $e->getMessage() . ' Corrige el programa y vuelve a tocar "Cambiar secreto".', 'error');
    redirigir('programa_editar', ['id' => $inst['id']]);
}
