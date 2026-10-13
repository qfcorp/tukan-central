<?php
declare(strict_types=1);

use Tukan\{Instancias, Productos};

/** "Agregar programa": genera el secreto y muestra UNA vez el comando para el servidor del programa. */
$error = null;
$d = ['producto' => param('tipo', ''), 'id' => '', 'nombre' => '', 'url' => '', 'carpeta' => '', 'notas' => '', 'plantilla' => '', 'activo' => '1'];
if (es_post()) {
    $d = array_merge($d, array_map(fn($v) => is_string($v) ? trim($v) : '', array_intersect_key($_POST, $d)));
    $d['activo'] = '1';
    try {
        $secreto = instancias()->crear($d);
        $inst = instancias()->obtener(strtolower($d['id']));
        $_SESSION['comando'] = ['id' => $inst['id'], 'comando' => Instancias::comando($inst, $secreto, url_central()), 'nuevo' => true];
        auditar('instancia_agregada', Productos::nombre($inst['producto']) . ' · ' . $inst['nombre'] . ' · ' . $inst['url'], $inst['id']);
        redirigir('programa_comando', ['id' => $inst['id']]);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (InvalidArgumentException $e) {
        aviso('El programa se guardó, pero no se pudo armar el comando: ' . $e->getMessage() . ' Revisa TUKAN_CENTRAL_URL en config/.env y toca "Cambiar secreto".', 'error');
        redirigir('programas');
    }
}
vista('programa_form', ['d' => $d, 'error' => $error, 'nuevo' => true], 'Agregar programa · Panel Tukán');
