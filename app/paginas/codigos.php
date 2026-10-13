<?php
declare(strict_types=1);

/** Códigos de respaldo: se muestran UNA sola vez. */
$codigos = $_SESSION['codigos_mostrar'] ?? null;
if (es_post()) {
    unset($_SESSION['codigos_mostrar']);
    redirigir((int) usuario()['cambiar_clave'] ? 'mi_cuenta' : 'inicio');
}
if (!$codigos) {
    aviso('Los códigos de respaldo solo se muestran una vez. Si los perdiste, genera unos nuevos aquí.', 'info');
    redirigir('mi_cuenta');
}
vista('codigos', ['codigos' => $codigos], 'Códigos de respaldo · Panel Tukán');
