<?php
declare(strict_types=1);

/** "Probar conexión": consulta el estado de un programa y explica el resultado en palabras simples. */
$u = usuario();
$inst = instancias()->obtener(campo('id'));
if (!$inst) {
    aviso('Ese programa no existe.', 'error');
    redirigir('programas');
}
$r = monitor()->consultar([$inst], $u['email'], $u['nombre'])[$inst['id']];
monitor()->guardar($inst, $r);
if ($r['en_linea']) {
    $d = $r['datos'];
    $txt = 'Conexión correcta: ' . ($d['nombre'] ?: $inst['nombre']) . ($d['version'] ? ' versión ' . $d['version'] : '') . " respondió en {$r['ms']} ms.";
    // Protocolo v2: qué puede hacer desde el panel (y trae lo que haga falta)
    sincronizador()->programa($inst, $d, false, ['capacidades'], usuario_v2());
    $cap = capacidades()->obtener($inst['id']);
    if ($cap) $txt .= $cap['protocolo'] === 2 ? ' Habla el protocolo v2: ya se ven sus solicitudes y clientes.' : ' Usa el protocolo v1 (solo cifras y Entrar).';
    if ($r['aviso']) $txt .= ' Ojo: ' . $r['aviso'];
    aviso($txt, $r['aviso'] ? 'info' : 'ok');
    auditar('probar', "OK en {$r['ms']} ms", $inst['id']);
} else {
    aviso($r['error_detalle'], 'error');
    auditar('probar', 'Falló: ' . strtok((string) $r['error_detalle'], ':.') . ($r['http'] ? " (HTTP {$r['http']})" : ''), $inst['id']);
}
redirigir('programa', ['id' => $inst['id']]);
