<?php
declare(strict_types=1);

use Tukan\{Capacidades, Finanzas, Monitor};

/**
 * Inicio: lo urgente arriba (solicitudes por atender, lo que vence esta semana, cobrado y ganancia del mes),
 * luego las cifras de todos los programas y sus tarjetas. Se dibuja con lo guardado (rápido aunque algo esté caído).
 */
$tablero = monitor()->tablero();
$activas = array_values(array_filter($tablero, fn($f) => (int) $f['activo']));
$disp = [];
foreach ($tablero as $f) $disp[$f['id']] = monitor()->disponibilidad($f['id']);
$caps = capacidades()->todas();
$esV2 = fn(string $id) => ($caps[$id]['protocolo'] ?? 1) === 2;

// Lo que vence esta semana (copia de clientes de los v2 + las pruebas que informa el "estado" de los v1)
$hoy = date('Y-m-d');
$semana = date('Y-m-d', strtotime('+7 days'));
$vencenSemana = 0;
$vencenPorInst = [];
$conClientes = [];
foreach (clientes()->todas() as $c) {
    $conClientes[$c['instancia_id']] = true;
    if ($c['vence'] !== null && $c['vence'] >= $hoy && $c['vence'] <= $semana && $c['estado'] !== 'suspendido') {
        $vencenSemana++;
        $vencenPorInst[$c['instancia_id']] = ($vencenPorInst[$c['instancia_id']] ?? 0) + 1;
    }
}
$pendV1 = Monitor::pendientes(array_values(array_filter($tablero, fn($f) => !isset($conClientes[$f['id']]))));
$vencenSemana += count($pendV1['pruebas']);

// Plata del mes: lo que informa "finanzas" (v2) + los ingresos del "estado" de los que no tienen finanzas
$mes = date('Y-m');
$fin = finanzas()->resumen([$mes], ajustes()->si('repartir_gastos_generales'));
$cobrado = $fin['total']['meses'][$mes]['ingresos'];
$gastos = $fin['total']['meses'][$mes]['gastos'];
foreach ($activas as $f) {
    if (!Capacidades::puede($caps[$f['id']]['datos'] ?? [], 'finanzas') && $f['datos'] && Monitor::ingresosSonDeTukan($f) && $f['datos']['ingresos_mes'] !== null) $cobrado += (float) $f['datos']['ingresos_mes'];
}

$bandeja = solicitudes()->lista(['pestana' => 'pendiente'], ve_contacto());
$consultas = array_filter(array_column($activas, 'consultado'));
vista('inicio', [
    'tablero'    => $tablero,
    'totales'    => Monitor::totales($tablero),
    'pendientes' => $pendV1,
    'disp'       => $disp,
    'viejo'      => Monitor::hayViejos($tablero),
    'ultima'     => $consultas ? min($consultas) : null,
    'caps'       => $caps,
    'porAtender' => $bandeja['pestanas']['pendiente'],
    'urgentes'   => array_slice($bandeja['filas'], 0, 5),
    'vencenSemana' => $vencenSemana,
    'vencenPorInst' => $vencenPorInst,
    'abiertasPorInst' => solicitudes()->porPrograma(),
    'cobrado'    => round($cobrado, 2),
    'gastosMes'  => $gastos,
    'nombreMes'  => explode(' ', Finanzas::nombreMes($mes))[0],
], 'Inicio · Panel Tukán');
