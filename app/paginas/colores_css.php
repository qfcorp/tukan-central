<?php
declare(strict_types=1);

// Colores de cada tipo de programa (salen de Productos::TIPOS): sin estilos en línea.
header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo \Tukan\Productos::css();
