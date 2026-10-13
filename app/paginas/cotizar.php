<?php
declare(strict_types=1);

use Tukan\{Capacidades, Clientes, ErrorPrograma, Formulario};

/** Valor sugerido al cambiar plan o tiempo en "Registrar pago" (PROTOCOLO_V2 §5.6). Responde JSON para app.js. */
$inst = instancias()->obtener(param('i'));
$cid = param('cliente');
if (!$inst || !Clientes::idValido($cid) || !Capacidades::puede(capacidades()->datos($inst['id']), 'cotizar')) responder_json(['ok' => false], 404);
$cuerpo = ['cliente_id' => $cid, 'plan' => param('plan')];
if (param('meses') !== '' && preg_match('/^\d{1,2}$/', param('meses'))) $cuerpo['meses'] = (int) param('meses');
else $cuerpo['periodo'] = param('periodo', 'mensual');
try {
    $r = cliente_v2()->pedir($inst, 'cotizar', $cuerpo, usuario_v2());
} catch (ErrorPrograma $e) {
    responder_json(['ok' => false, 'error' => $e->getMessage()], 200);
}
$monto = is_int($r['monto'] ?? null) || is_float($r['monto'] ?? null) ? round((float) $r['monto'], 2) : null;
$hasta = is_string($r['hasta'] ?? null) ? substr($r['hasta'], 0, 10) : null;
responder_json(['ok' => true, 'monto' => $monto, 'monto_texto' => $monto !== null ? number_format($monto, 2, ',', '') : '', 'monto_bonito' => $monto !== null ? dinero($monto) : '',
                'hasta' => $hasta, 'hasta_texto' => $hasta ? fecha_dm($hasta, true) : '', 'detalle' => is_string($r['detalle'] ?? null) ? mb_substr($r['detalle'], 0, 120) : '']);
