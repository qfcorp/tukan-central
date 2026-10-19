<?php
declare(strict_types=1);

use Tukan\Finanzas;

/** Finanzas: cuánto entra, cuánto sale y cuánto queda, por mes y por programa (PROTOCOLO_V2 §9.5 G). */
if (es_post() && campo('accion') === 'repartir') {
    $v = campo('repartir') === '1' ? '1' : '0';
    if (ajustes()->valor('repartir_gastos_generales') !== $v) {
        ajustes()->guardar('repartir_gastos_generales', $v);
        auditar('ajustes', "repartir_gastos_generales=$v");
    }
    header('Location: ' . volver_valido(campo('volver'), '/?r=finanzas'), true, 303);
    exit;
}
$periodo = in_array(param('periodo'), ['12', '24', 'anio'], true) ? param('periodo') : '12';
$meses = Finanzas::meses($periodo);
finanzas()->generarFijos(date('Y-m'));
$repartir = ajustes()->si('repartir_gastos_generales');
$r = finanzas()->resumen($meses, $repartir);
$prog = param('prog');
$detalle = $prog !== '' && isset($r['programas'][$prog]) ? finanzas()->detallePrograma($prog, $meses) : null;
$insts = array_column(instancias()->lista(true), 'id');
vista('finanzas', [
    'r' => $r, 'periodo' => $periodo, 'meses' => $meses, 'repartir' => $repartir, 'prog' => $prog, 'detalle' => $detalle,
    'momento' => sincronizacion()->masViejo($insts, 'finanzas'),
    // Anexo B: ingreso estimado al mes por cada módulo extra (de los clientes que lo tienen encendido aparte de su plan)
    'porModulo' => (new \Tukan\Modulos(db()))->ingresoPorModulo(clientes()),
    'sinFinanzas' => array_values(array_filter(instancias()->lista(true), fn($i) => !isset($r['programas'][$i['id']]) && !\Tukan\Productos::tipo($i['producto'])['unico'])),
], 'Finanzas · Panel Tukán');
