<?php
declare(strict_types=1);

use Tukan\{Auditoria, Bajas, Cloudflare, Operaciones};

/** Ficha de un programa: estado, cifras, historial y acciones. */
$id = param('id');
$fila = null;
foreach (monitor()->tablero() as $f) {
    if ($f['id'] === $id) $fila = $f;
}
if (!$fila) {
    http_response_code(404);
    aviso('Ese programa no existe.', 'error');
    redirigir('programas');
}
$registro = puede('auditoria') ? (new Auditoria(db()))->buscar(['instancia' => $id], 1, 12)['filas'] : [];
$cap = capacidades()->obtener($id);
$unico = \Tukan\Productos::tipo($fila['producto'])['unico'] || ($cap['datos']['unico'] ?? false);
// Borrar la instalación (solo el dueño): si hay un borrado en marcha, se pregunta al programa cómo va
$baja = null;
$bajaDisp = false;
if (puede('programas')) {
    $bajaDisp = Bajas::disponible($cap && $cap['protocolo'] === 2 ? $cap['datos'] : []);
    $baja = bajas()->obtener($id);
    if ($baja && in_array($baja['estado'], Bajas::ACTIVAS, true) && time() - (int) $baja['revisada'] >= 5) {
        $inst = instancias()->obtener($id);
        if ($inst) $baja = bajas()->revisar($inst, cliente_v2(), usuario_v2());
    }
}
// Zona de peligro (siempre visible para el dueño): borrar desde el panel, o los pasos a mano si no se puede
$on = (int) $fila['activo'] && $fila['consultado'] !== null && (int) $fila['en_linea'];
$hostBaja = Bajas::hostname($fila, is_string($cap['datos']['url_publica'] ?? null) ? (string) parse_url($cap['datos']['url_publica'], PHP_URL_HOST) : null);
$carpetaBaja = Bajas::carpeta($fila, null);
// Anexo B: catálogo de módulos (en vivo o la copia) y, en TuGuardería, los módulos de la instalación
$mCat = null;
$mCli = null;
if ($cap && $cap['protocolo'] === 2 && \Tukan\Modulos::tiene($cap['datos'])) {
    $instM = instancias()->obtener($id);
    if ($instM) $mCat = (new \Tukan\Modulos(db()))->catalogo($instM, cliente_v2(), usuario_v2(), (bool) $on);
    if ($unico && $instM) {
        try {
            $rc = cliente_v2()->pedir($instM, 'cliente', ['id' => 'unico'], usuario_v2());
            $mCli = \Tukan\Modulos::limpiarCliente($rc['cliente']['modulos'] ?? null);
        } catch (\Tukan\ErrorPrograma) {
            $mCli = null;
        }
    }
    if ($mCat && !$mCat['modulos']) $mCat = null;
}
vista('programa', [
    'mCat' => $mCat, 'mCli' => $mCli,
    'on' => $on, 'hostBaja' => $hostBaja, 'carpetaBaja' => $carpetaBaja,
    'comandoBorrar' => 'cd /home/qf/github/tuguarderia && git pull && sudo bash borrar_cliente.sh ' . ($carpetaBaja ?? 'CARPETA'),
    'baja' => $baja, 'bajaDisp' => $bajaDisp, 'urlPublica' => is_string($cap['datos']['url_publica'] ?? null) ? $cap['datos']['url_publica'] : null, 'cfListo' => Cloudflare::configurado(ajustes()), 'opBaja' => Operaciones::nuevaId(),
    'f' => $fila, 'cap' => $cap, 'unico' => $unico, 'licencia' => $unico && puede('finanzas') ? licencias()->obtener($id) : null,
    'disp' => monitor()->disponibilidad($id),
    'registro' => $registro,
], $fila['nombre'] . ' · Panel Tukán');
