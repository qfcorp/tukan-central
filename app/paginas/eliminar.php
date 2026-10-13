<?php
declare(strict_types=1);

/** Eliminar un programa del panel (no toca el programa; solo deja de aparecer aquí). Se confirma escribiendo el ID. */
$inst = instancias()->obtener(campo('id'));
if (!$inst) redirigir('programas');
if (campo('confirmar') !== $inst['id']) {
    aviso('Para eliminarlo, escribe el ID exacto del programa (' . $inst['id'] . ').', 'error');
    redirigir('programa', ['id' => $inst['id']]);
}
instancias()->eliminar($inst['id']);
auditar('instancia_eliminada', $inst['nombre'] . ' · ' . $inst['url'], $inst['id']);
aviso($inst['nombre'] . ' se quitó del panel. El programa sigue funcionando; para apagar su conector, borra TUKAN_CENTRAL_SECRET en su servidor.', 'info');
redirigir('programas');
