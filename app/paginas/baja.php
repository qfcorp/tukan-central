<?php
declare(strict_types=1);

use Tukan\{Bajas, ErrorPrograma, Operaciones};

/**
 * Borrar una instalación (TuGuardería) desde la ficha del programa. Solo el dueño (permiso "programas").
 *   programar  → pide escribir el ID y el código de 2 pasos; manda dar_de_baja (el programa lo hace en unos minutos)
 *   cancelar   → cancelar_baja (mientras no empiece)
 *   revisar    → pregunta al programa cómo va
 *   cloudflare → vuelve a intentar quitar su dirección de Cloudflare
 *   cloudflare_manual → conector antiguo o sin conexión (se borra a mano con borrar_cliente.sh): quita solo su dirección
 *   quitar     → ya borrada: la quita también del panel (la auditoría se conserva)
 */
$u = usuario();
$inst = instancias()->obtener(campo('id'));
if (!$inst) redirigir('programas');
$volver = function () use ($inst): never {
    header('Location: ' . url('programa', ['id' => $inst['id']]) . '#baja', true, 303);
    exit;
};
$accion = campo('accion');
$b = bajas()->obtener($inst['id']);
$activa = $b && in_array($b['estado'], Bajas::ACTIVAS, true);

if ($accion === 'programar') {
    if (!Bajas::disponible(capacidades()->datos($inst['id']))) {
        aviso($inst['nombre'] . ' todavía no se puede borrar desde el panel: actualízalo (git pull e instalar.sh) y toca «Probar conexión».', 'error');
        $volver();
    }
    if ($activa) {
        aviso('Ya hay un borrado ' . ($b['estado'] === 'en_curso' ? 'en curso' : 'programado') . ' para ' . $inst['nombre'] . '.', 'info');
        $volver();
    }
    $errores = [];
    if (campo('confirmar') !== $inst['id']) $errores[] = 'Para borrar, escribe el ID exacto del programa (' . $inst['id'] . ').';
    if (!Operaciones::idValida(campo('op'))) $errores[] = 'El formulario no es válido. Vuelve a abrir la página.';
    if (!$errores && !acceso()->verificarCodigo($u, campo('codigo'), ip_visitante())) {
        auditar('2fa_fallido', 'Al borrar la instalación de ' . $inst['nombre'], $inst['id']);
        $errores[] = 'El código de 2 pasos no es correcto. Escribe el que aparece ahora en tu app.';
    }
    if ($errores) {
        auditar('baja_rechazada', 'No se programó: ' . implode(' ', $errores), $inst['id']);
        aviso(implode(' ', $errores) . ' No se borró nada.', 'error');
        $volver();
    }
    $carpeta = Bajas::carpeta($inst, null);
    if ($carpeta === null) {
        try {
            $r = cliente_v2()->pedir($inst, 'cliente', ['id' => 'unico'], usuario_v2());
            $carpeta = Bajas::carpeta($inst, is_array($r['cliente']['baja'] ?? null) ? $r['cliente']['baja'] : null);
        } catch (ErrorPrograma $e) {
            aviso($inst['nombre'] . ' no respondió: ' . $e->getMessage() . ' No se borró nada.', 'error');
            $volver();
        }
    }
    if ($carpeta === null) {
        aviso('No se sabe la carpeta de esta instalación en el servidor: escríbela en Editar → Carpeta. No se borró nada.', 'error');
        $volver();
    }
    $res = bajas()->programar($inst, operaciones(), campo('op'), $carpeta, usuario_v2());
    aviso($res['ok'] ? $res['mensaje'] : $inst['nombre'] . ': ' . $res['mensaje'] . ($res['sin_respuesta'] ? ' Toca «Revisar ahora» o intenta otra vez.' : ' No se borró nada.'), $res['ok'] ? 'ok' : 'error');
    if ($res['sin_respuesta']) bajas()->revisar($inst, cliente_v2(), usuario_v2());
    $volver();
}

if ($accion === 'cancelar') {
    if (!$activa) {
        aviso('No hay ningún borrado programado.', 'info');
        $volver();
    }
    $op = Operaciones::idValida(campo('op')) ? campo('op') : Operaciones::nuevaId();
    $res = bajas()->cancelar($inst, operaciones(), $op, usuario_v2());
    aviso($res['ok'] ? $res['mensaje'] : 'No se pudo cancelar: ' . $res['mensaje'], $res['ok'] ? 'ok' : 'error');
    $volver();
}

if ($accion === 'revisar') {
    $b = bajas()->revisar($inst, cliente_v2(), usuario_v2());
    if ($b && $b['mensaje']) aviso($b['mensaje'], $b['estado'] === 'error' ? 'error' : 'info');
    $volver();
}

if ($accion === 'cloudflare') {
    if (($b['estado'] ?? null) !== 'borrada') {
        aviso('Primero tiene que terminar el borrado de la instalación.', 'info');
        $volver();
    }
    $r = bajas()->limpiarCloudflare($inst);
    if ($r === null) aviso('Cloudflare no está configurado (Ajustes → Cloudflare). Quita la dirección a mano.', 'info');
    else aviso(implode(' ', $r['mensajes']), $r['ok'] ? 'ok' : 'error');
    $volver();
}

if ($accion === 'cloudflare_manual') {
    // Conector antiguo o sin conexión: el dueño borra a mano en el servidor y aquí se quita solo su dirección de Cloudflare
    $on = false;
    foreach (monitor()->tablero() as $t) if ($t['id'] === $inst['id']) $on = (int) $t['activo'] && $t['consultado'] !== null && (int) $t['en_linea'];
    if ($on && Bajas::disponible(capacidades()->datos($inst['id']))) {
        aviso($inst['nombre'] . ' responde y sabe borrarse: usa «Borrar esta instalación» (Cloudflare se limpia solo al terminar).', 'info');
        $volver();
    }
    $host = Bajas::hostname($inst, is_string(capacidades()->datos($inst['id'])['url_publica'] ?? null) ? (string) parse_url(capacidades()->datos($inst['id'])['url_publica'], PHP_URL_HOST) : null);
    $r = bajas()->soloCloudflare($inst, $host);
    if ($r === null) aviso('Cloudflare no está configurado (Ajustes → Cloudflare). Quita la dirección a mano.', 'info');
    else aviso('Cloudflare: ' . implode(' ', $r['mensajes']), $r['ok'] ? 'ok' : 'error');
    $volver();
}

if ($accion === 'quitar') {
    if (($b['estado'] ?? null) !== 'borrada') {
        aviso('Solo se puede quitar del panel desde aquí cuando el borrado terminó. Para quitarlo sin borrar, usa «Quitar este programa del panel».', 'info');
        $volver();
    }
    $cf = $b['cloudflare'];
    instancias()->eliminar($inst['id']);
    bajas()->quitar($inst['id']);
    auditar('instancia_eliminada', $inst['nombre'] . ' · ' . $inst['url'] . ' · después de borrar su instalación (la auditoría se conserva)', $inst['id']);
    $falta = !is_array($cf) || !($cf['ok'] ?? false);
    aviso($inst['nombre'] . ' se quitó del panel. Su historial sigue en Auditoría.'
        . ($falta ? ' No olvides quitar ' . ($b['hostname'] ?: 'su dirección') . ' en Cloudflare (túnel y DNS).' : ''), 'ok');
    redirigir('programas');
}

$volver();
