<?php
declare(strict_types=1);

use Tukan\{Finanzas, Formulario};

/**
 * Gastos (y, con $esGasto = false, Ingresos extra) de un mes (PROTOCOLO_V2 §9.5 G y §9.6):
 * agregar (con "se repite cada mes"), anular, y los fijos: pausar, reanudar, cambiar el valor, terminar.
 * Variable: $esGasto
 */
$ruta = $esGasto ? 'gastos' : 'ingresos_extra';
$tabla = $esGasto ? 'gastos' : 'ingresos_extra';
$mes = Finanzas::mesValido(param('mes')) ? param('mes') : (Finanzas::mesValido(campo('mes')) ? campo('mes') : date('Y-m'));
$error = null;
$valores = [];
if (es_post()) {
    $accion = campo('accion');
    $uid = (int) usuario()['id'];
    try {
        if ($accion === 'agregar') {
            $d = ['fecha' => campo('fecha'), 'monto' => campo('monto'), 'instancia_id' => campo('instancia_id'), 'metodo' => campo('metodo'),
                  'recurrente' => campo('recurrente') === '1', 'dia' => campo('dia')];
            if ($esGasto) $d += ['categoria' => campo('categoria'), 'descripcion' => campo('descripcion'), 'referencia' => campo('referencia')];
            else $d += ['concepto' => campo('concepto')];
            $id = $esGasto ? finanzas()->guardarGasto($d, $uid) : finanzas()->guardarIngresoExtra($d, $uid);
            $txt = ($esGasto ? campo('descripcion') : campo('concepto')) . ' · ' . dinero(Formulario::numero(campo('monto')) ?? 0) . ($d['recurrente'] ? ' · fijo cada mes' : '');
            auditar($esGasto ? 'gasto' : 'ingreso_extra', "Agregó #$id: $txt", campo('instancia_id') ?: null);
            aviso(($esGasto ? 'Gasto agregado' : 'Ingreso agregado') . ($d['recurrente'] ? '. Se repetirá cada mes (lo ves en "Fijos").' : '.'));
            header('Location: ' . url($ruta, ['mes' => substr($d['fecha'], 0, 7)]), true, 303);
            exit;
        }
        if ($accion === 'anular') {
            $f = finanzas()->anular($tabla, (int) campo('id'));
            auditar($esGasto ? 'gasto' : 'ingreso_extra', 'Anuló #' . $f['id'] . ': ' . ($f['descripcion'] ?? $f['concepto']) . ' · ' . dinero((float) $f['monto']), $f['instancia_id']);
            aviso('Anulado. Ya no cuenta en las finanzas.');
        } elseif ($accion === 'fijo') {
            $que = campo('que');
            $r = finanzas()->recurrente((int) campo('id'));
            finanzas()->cambiarRecurrente((int) campo('id'), $que, $que === 'monto' ? Formulario::numero(campo('monto')) : null);
            auditar($esGasto ? 'gasto' : 'ingreso_extra', 'Fijo #' . (int) campo('id') . ' (' . ($r['descripcion'] ?? '') . "): $que" . ($que === 'monto' ? ' ' . campo('monto') : ''));
            aviso(['pausar' => 'Pausado: este mes ya no se crea.', 'reanudar' => 'Reanudado.', 'terminar' => 'Terminado: ya no se crea en los próximos meses.', 'monto' => 'Valor cambiado desde este mes.'][$que] ?? 'Listo.');
        }
        header('Location: ' . url($ruta, ['mes' => $mes]), true, 303);
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
        $valores = $_POST;
        http_response_code(422);
    }
}
finanzas()->generarFijos(date('Y-m'));
$lista = $esGasto ? finanzas()->gastos($mes) : finanzas()->ingresosExtra($mes);
$primero = new DateTimeImmutable("$mes-01");
vista('movimientos', [
    'esGasto' => $esGasto, 'ruta' => $ruta, 'mes' => $mes, 'lista' => $lista, 'error' => $error, 'valores' => $valores,
    'total' => round(array_sum(array_map(fn($g) => $g['anulado'] ? 0 : (float) $g['monto'], $lista)), 2),
    'costos' => $esGasto ? finanzas()->costosInformados($mes) : [],
    'fijos' => finanzas()->recurrentes($esGasto ? 'gasto' : 'ingreso'),
    'insts' => instancias()->lista(true),
    'anterior' => $primero->modify('-1 month')->format('Y-m'), 'siguiente' => $primero->modify('+1 month')->format('Y-m'),
], ($esGasto ? 'Gastos' : 'Ingresos extra') . ' · Panel Tukán');
