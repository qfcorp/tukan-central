<?php
declare(strict_types=1);

use Tukan\{Capacidades, Clientes, ErrorPrograma, Formulario, Operaciones, Solicitudes};

/**
 * Formulario de un botón (acciones_posibles) de una solicitud o de un cliente.
 * Con JavaScript se abre en una hoja (celular) o ventana (escritorio) con ?parcial=1; sin JavaScript es una página.
 * El operacion_id se crea aquí, al abrir el formulario (dos toques = la misma operación).
 */
$inst = instancias()->obtener(param('i'));
$accion = param('a');
$volver = volver_valido(param('volver'), '/?r=solicitudes');
$parcial = param('parcial') === '1';
$falla = function (string $texto) use ($parcial, $volver): never {
    if ($parcial) {
        http_response_code(409);
        echo '<div class="hoja-cabeza"><div><h2>No disponible</h2></div><button type="button" class="btn btn-icono btn-chico" data-cerrar aria-label="Cerrar">✕</button></div><p class="aviso error">' . e($texto) . '</p>';
        exit;
    }
    aviso($texto, 'error');
    header('Location: ' . $volver, true, 303);
    exit;
};
if (!$inst || !(int) $inst['activo']) $falla('Ese programa no existe o está pausado.');
if (in_array($accion, Operaciones::DE_LA_FICHA, true)) $falla('Borrar la instalación se hace desde la ficha del programa (zona de peligro).');
if (in_array($accion, Operaciones::DE_MODULOS, true)) $falla('Los módulos se cambian en la pestaña «Módulos» del cliente (o en el Catálogo del programa).');
if (!puede_operacion($inst['id'], $accion)) {
    auditar('permiso_negado', "Pidió el formulario $accion", $inst['id']);
    $falla('Tu usuario no tiene permiso para esto (o el programa no lo permite).');
}
$n = (int) param('n', '-1');
// Con n: el botón número n de la lista; sin n (botones de la lista de Clientes): el primero con esa acción
$porNombre = function (array $lista) use ($n, $accion): ?array {
    if ($n >= 0) return $lista[$n] ?? null;
    foreach ($lista as $x) if (($x['accion'] ?? null) === $accion) return $x;
    return null;
};
$a = null;
$ctx = ['volver' => $volver, 'cliente_id' => param('cliente') ?: null, 'nombre' => ''];
if (param('sol') !== '') {
    $fila = solicitudes()->obtener($inst['id'], param('sol'));
    if ($fila) {
        $s = solicitudes()->completa($fila, ve_contacto());
        $a = $porNombre($s['acciones']);
        $ctx['cliente_id'] = $s['cliente_id'];
        $ctx['nombre'] = (string) ($s['quien']['organizacion'] ?? $s['quien']['nombre'] ?? $s['titulo']);
    }
} elseif ($ctx['cliente_id'] !== null && Clientes::idValido($ctx['cliente_id'])) {
    try {
        $r = cliente_v2()->pedir($inst, 'cliente', ['id' => $ctx['cliente_id']], usuario_v2());
    } catch (ErrorPrograma $e) {
        $falla($inst['nombre'] . ': ' . $e->getMessage());
    }
    $ctx['nombre'] = (string) ($r['cliente']['nombre'] ?? '');
    if ($accion === 'anular_pago') {
        foreach (is_array($r['cliente']['pagos_detalle'] ?? null) ? $r['cliente']['pagos_detalle'] : [] as $p) {
            if (is_array($p) && (string) ($p['id'] ?? '') === param('pago') && ($p['puede_anular'] ?? false) === true && !($p['anulado'] ?? false)) {
                $a = Formulario::limpiarAccion(['accion' => 'anular_pago', 'etiqueta' => 'Anular pago de ' . dinero((float) ($p['monto'] ?? 0)) . ' (' . fecha_dm((string) ($p['fecha'] ?? ''), true) . ')', 'estilo' => 'peligro',
                    'confirmar' => '¿Anular este pago? La fecha de vencimiento se vuelve a calcular.', 'datos' => ['cliente_id' => $ctx['cliente_id'], 'pago_id' => (string) $p['id']],
                    'campos' => [['campo' => 'motivo', 'etiqueta' => 'Motivo', 'tipo' => 'texto', 'requerido' => true, 'min' => 5, 'max' => 200]]]);
            }
        }
    } else {
        $a = $porNombre(Formulario::limpiarAcciones($r['cliente']['acciones_posibles'] ?? []));
    }
}
if (!$a || $a['accion'] !== $accion) $falla('Ese botón ya no está disponible (quizás alguien ya lo atendió). Actualiza la lista.');
// Pausar pide tipo + motivo; borrar pide el nombre exacto + motivo (aunque el programa no los mande)
$a = Operaciones::completarCampos($a, $ctx['nombre']);
// Pausar / Borrar cliente desde una solicitud de ese cliente (de_sol): si es "la demo terminó sin pagar" o "venció sin
// renovar", el motivo viene sugerido (y el tipo "Falta de pago"); al borrar, la solicitud queda atendida (ejecutar.php)
$valores = [];
if (param('sol') === '' && param('de_sol') !== '' && in_array($accion, ['pausar', 'borrar_cliente'], true)) {
    $fs = solicitudes()->obtener($inst['id'], param('de_sol'));
    if ($fs && (string) $fs['cliente_id'] === (string) $ctx['cliente_id']) {
        $sd = solicitudes()->completa($fs, ve_contacto());
        $ctx['solicitud'] = (string) $fs['solicitud_id'];
        $ctx['solicitud_titulo'] = (string) $sd['titulo'];
        $motivo = Solicitudes::motivoSinPagar($sd);
        if ($motivo !== null) {
            $valores['motivo'] = $motivo;
            if ($accion === 'pausar') $valores['tipo'] = 'falta_pago';
        }
    }
}
$datos = ['inst' => $inst, 'a' => $a, 'ctx' => $ctx, 'valores' => $valores, 'errores' => [], 'error' => null, 'op' => Operaciones::nuevaId()];
if ($parcial) {
    extract($datos);
    require RAIZ . '/app/vistas/_form_accion.php';
    exit;
}
vista('accion', $datos, $a['etiqueta'] . ' · Panel Tukán');
