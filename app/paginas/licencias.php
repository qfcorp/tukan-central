<?php
declare(strict_types=1);

use Tukan\Formulario;

/** Licencia de un programa de una sola empresa (TuGuardería): datos y "Registrar pago de licencia" (§9.5 I). */
$inst = instancias()->obtener(campo('i'));
if (!$inst) {
    aviso('Ese programa no existe.', 'error');
    redirigir('programas');
}
try {
    if (campo('accion') === 'pago') {
        $monto = Formulario::numero(campo('monto'));
        if ($monto === null) throw new RuntimeException('Escribe el valor recibido con números.');
        $fecha = campo('fecha') ?: date('Y-m-d');
        $r = licencias()->registrarPago($inst, $monto, (int) campo('meses'), $fecha, campo('metodo'), (int) usuario()['id']);
        auditar('licencia', 'Pago de licencia ' . dinero($monto) . ' por ' . (int) campo('meses') . ' meses · pagada hasta ' . $r['activo_hasta'], $inst['id']);
        aviso('Pago registrado: la licencia queda pagada hasta el ' . fecha_dm($r['activo_hasta'], true) . '. Se anotó como ingreso extra.');
    } else {
        $valor = Formulario::numero(campo('valor_mensual')) ?? 0.0;
        licencias()->guardar($inst['id'], $valor, campo('activo_hasta') ?: null,
            ['nombre' => campo('contacto_nombre'), 'telefono' => campo('contacto_telefono'), 'email' => campo('contacto_email')], campo('notas'));
        auditar('licencia', 'Datos de la licencia: ' . dinero($valor) . ' al mes' . (campo('activo_hasta') ? ', pagada hasta ' . campo('activo_hasta') : ''), $inst['id']);
        aviso('Licencia guardada.');
    }
} catch (RuntimeException $e) {
    aviso($e->getMessage(), 'error');
}
redirigir('programa', ['id' => $inst['id']]);
