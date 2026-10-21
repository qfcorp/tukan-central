<?php
declare(strict_types=1);

use Tukan\Actualizaciones;

/**
 * Más → Actualizaciones (solo el dueño, permiso "actualizaciones").
 *   GET                    versiones de cada programa de este servidor, lo nuevo en GitHub y el resultado de la última corrida
 *   POST accion=revisar    pide al servidor que busque novedades en GitHub (git fetch: no cambia nada)
 *   POST accion=actualizar programa=ID o todo=1, con el código de 2 pasos -> pedido firmado para el servicio tukan-actualizar
 * Lo que pasa queda en la Auditoría (pedido, código equivocado y resultado).
 */
$u = usuario();
$act = actualizaciones();
$anotar = function (string $id, string $estado, string $resumen, array $objetivos, string $tipo): void {
    if ($tipo === 'revisar' && $estado === 'listo') return;   // buscar novedades no cambia nada: basta con el pedido
    auditar('actualizacion_resultado', Actualizaciones::textoEstado($estado) . ': ' . $resumen . ' (pedido ' . substr($id, 0, 8) . ')');
};
$volver = fn(array $p = []): never => redirigir('actualizaciones', $p);

if (es_post()) {
    $accion = campo('accion');
    if (!$act->instalado()) {
        aviso('El actualizador todavía no está instalado en este servidor. Pega el comando de abajo una sola vez.', 'error');
        $volver();
    }
    $act->sincronizar($anotar);
    if ($act->enCurso()) {
        aviso('Ya hay un pedido en curso. Espera a que termine (esta pantalla se actualiza sola).', 'info');
        $volver();
    }
    if ($accion === 'revisar') {
        try {
            $id = $act->pedir('revisar', [], usuario_v2());
            auditar('actualizacion_revisar', 'Pidió buscar versiones nuevas (pedido ' . substr($id, 0, 8) . ')');
            aviso('Buscando versiones nuevas en GitHub… (no se cambia nada)', 'info');
            $volver(['p' => $id]);
        } catch (RuntimeException $e) {
            aviso($e->getMessage(), 'error');
            $volver();
        }
    }
    if ($accion === 'actualizar') {
        $v = $act->versiones();
        $disponibles = $act->conNovedad($v);
        $todo = campo('todo') === '1';
        $prog = campo('programa');
        $objetivos = $todo ? $disponibles : (isset($disponibles[$prog]) ? [$prog => $disponibles[$prog]] : []);
        if (!$objetivos) {
            aviso($todo ? 'No hay versiones nuevas para actualizar.' : 'Ese programa ya está al día o no está en este servidor. Toca «Buscar novedades».', 'info');
            $volver();
        }
        // El dueño aprueba ESTA versión exacta (la que ve en pantalla): si el formulario trae otra, se pide revisar otra vez
        if (!$todo && campo('commit') !== '' && campo('commit') !== $objetivos[$prog]) {
            aviso('La versión cambió mientras mirabas. Revisa la lista otra vez y vuelve a aprobar.', 'info');
            $volver();
        }
        $nombres = implode(', ', array_map(fn($p) => ($v['programas'][$p]['nombre'] ?? $p) . ' → ' . substr($objetivos[$p], 0, 7), array_keys($objetivos)));
        if (!acceso()->verificarCodigo($u, campo('codigo'), ip_visitante())) {
            auditar('2fa_fallido', 'Al actualizar: ' . $nombres);
            auditar('actualizacion_rechazada', 'Código de 2 pasos equivocado. No se pidió nada: ' . $nombres);
            aviso('El código de 2 pasos no es correcto. Escribe el que aparece ahora en tu app. No se actualizó nada.', 'error');
            $volver();
        }
        try {
            $id = $act->pedir('actualizar', $objetivos, usuario_v2());
        } catch (RuntimeException $e) {
            aviso($e->getMessage(), 'error');
            $volver();
        }
        auditar('actualizacion_pedida', $nombres . ' (pedido ' . substr($id, 0, 8) . ')');
        aviso('Pedido enviado al servidor: ' . $nombres . '. Se respalda cada base, se instala y se prueba; si algo falla vuelve solo a la versión anterior.', 'ok');
        $volver(['p' => $id]);
    }
    $volver();
}

$act->sincronizar($anotar);
$instalado = $act->instalado();
$v = $act->versiones();
$curso = $act->enCurso();
$historial = $act->historial(10);
$verId = param('p');
$ver = $verId !== '' ? $act->obtener($verId) : ($curso ?: ($historial[0] ?? null));
$resultado = $ver ? $act->resultado($ver['id']) : null;
$log = $ver ? $act->log($ver['id']) : '';
if ($curso) header('Refresh: 5');   // mientras corre, la pantalla se vuelve a cargar sola
vista('actualizaciones', [
    'instalado' => $instalado, 'v' => $v, 'novedad' => $act->conNovedad($v), 'curso' => $curso, 'historial' => $historial,
    'ver' => $ver, 'resultado' => $resultado, 'log' => $log, 'ultima' => $act->ultima(),
    'comando' => 'bash ' . RAIZ . '/deploy/instalar.sh && bash ' . RAIZ . '/deploy/instalar_actualizador.sh',
], 'Actualizaciones · Panel Tukán');
