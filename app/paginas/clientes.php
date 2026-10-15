<?php
declare(strict_types=1);

use Tukan\{Capacidades, Clientes, Contacto, Productos};

/** Todos los clientes de todos los programas, con quién está por vencer (PROTOCOLO_V2 §9.5 C). */
$estado = param('estado');
$f = [
    'inst' => param('i'), 'buscar' => mb_substr(param('q'), 0, 80), 'plan' => param('plan'), 'origen' => param('origen'),
    'estados' => isset(Clientes::ESTADOS[$estado]) ? [$estado] : [],
    'papelera' => $estado === 'papelera',
    'vence_en' => in_array(param('vence'), ['7', '15', '30'], true) ? (int) param('vence') : null,
    'sin_uso' => param('sin_uso') === '15' ? 15 : null,
    'orden' => in_array(param('orden'), ['vence', 'alta', 'nombre', 'actividad'], true) ? param('orden') : 'vence',
];
$r = clientes()->lista($f, ve_contacto());
$porPagina = 50;
$pagina = max(1, (int) param('pagina', '1'));
$paginas = max(1, (int) ceil($r['total'] / $porPagina));
$pagina = min($pagina, $paginas);
$insts = instancias()->lista(true);
$conLista = array_values(array_filter($insts, fn($i) => Capacidades::puede(capacidades()->datos($i['id']), 'clientes') || Capacidades::puede(capacidades()->datos($i['id']), 'cliente')));
$sinLista = array_values(array_filter($insts, fn($i) => !in_array($i['id'], array_column($conLista, 'id'), true)));
// Papelera (Anexo A): el filtro sale si algún programa sabe borrar clientes o si ya hay alguno borrado
$conPapelera = (bool) array_filter($conLista, fn($i) => Capacidades::puede(capacidades()->datos($i['id']), 'ejecutar', 'borrar_cliente'));
// Mensaje de WhatsApp ya escrito según el estado (plantillas de Ajustes)
$mensaje = function (array $c): ?string {
    $p = Contacto::plantillaPara($c);
    if ($p === null) return null;
    $ct = $c['contacto'] ?? [];
    $monto = $c['datos']['pagos']['ultimo_monto'] ?? null;
    return Contacto::plantilla(ajustes()->plantilla($p), [
        'nombre' => explode(' ', (string) ($ct['nombre'] ?? ''))[0], 'negocio' => $c['nombre'], 'programa' => $c['programa'],
        'vence' => fecha_dm($c['vence'], true), 'monto' => $monto !== null ? dinero((float) $monto) : 'el de su plan',
    ]);
};
vista('clientes', [
    'f' => $f, 'estado' => $estado, 'filas' => array_slice($r['filas'], ($pagina - 1) * $porPagina, $porPagina), 'total' => $r['total'],
    'contadores' => $r['contadores'], 'en_papelera' => $r['papelera'], 'ver_papelera' => $conPapelera || $r['papelera'] > 0, 'pagina' => $pagina, 'paginas' => $paginas, 'insts' => $conLista, 'sinLista' => $sinLista,
    'planes' => clientes()->planes(), 'recordados' => clientes()->ultimosRecordatorios(), 'mensaje' => $mensaje,
    'momento' => sincronizacion()->masViejo(array_column($conLista, 'id'), 'clientes'),
], 'Clientes · Panel Tukán');
