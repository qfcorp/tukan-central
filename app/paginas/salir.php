<?php
declare(strict_types=1);

auditar('salir');
acceso()->cerrarSesion((string) $_SESSION['ref'], 'salio');
$_SESSION = [];
session_regenerate_id(true);
aviso('Saliste del panel. ¡Hasta pronto!', 'info');
redirigir('login');
