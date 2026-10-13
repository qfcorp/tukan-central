<?php
declare(strict_types=1);

use Tukan\{Acceso, Auditoria};

/** Mi cuenta: contraseña (pide la actual + código), códigos de respaldo, sesiones y últimos ingresos. */
$u = usuario();
$error = null;
if (es_post()) {
    $accion = campo('accion');
    if ($accion === 'cerrar_otras') {
        $n = acceso()->cerrarTodas((int) $u['id'], 'cerrada por el usuario', (string) $_SESSION['ref']);
        auditar('sesiones_cerradas', "$n sesiones");
        aviso($n ? "Listo: se cerraron $n " . ($n === 1 ? 'sesión' : 'sesiones') . '.' : 'No había otras sesiones abiertas.');
        redirigir('mi_cuenta');
    }
    if (!acceso()->verificarCodigo($u, campo('codigo'), ip_visitante())) {
        auditar('2fa_fallido', $accion === 'clave' ? 'Al cambiar la contraseña' : 'Al generar códigos de respaldo');
        $falta = acceso()->bloqueado($u['email'], ip_visitante());
        $error = $falta > 0 ? Acceso::textoBloqueo($falta) : 'El código de 2 pasos no es correcto. Si acabas de usar uno, espera a que la app muestre el siguiente.';
    } elseif ($accion === 'clave') {
        $nueva = (string) ($_POST['nueva'] ?? '');
        if (!password_verify((string) ($_POST['actual'] ?? ''), $u['clave_hash'])) {
            acceso()->registrarFallo($u['email'], ip_visitante());
            auditar('login_fallido', 'Contraseña actual equivocada al cambiarla');
            $error = 'La contraseña actual no es correcta.';
        } elseif ($nueva !== (string) ($_POST['repetir'] ?? '')) {
            $error = 'La contraseña nueva no coincide al repetirla.';
        } elseif (password_verify($nueva, $u['clave_hash'])) {
            $error = 'La contraseña nueva debe ser distinta a la actual.';
        } elseif (($e = Acceso::errorClave($nueva)) !== null) {
            $error = $e;
        } else {
            acceso()->cambiarClave((int) $u['id'], $nueva);
            $n = acceso()->cerrarTodas((int) $u['id'], 'contraseña cambiada', (string) $_SESSION['ref']);
            session_regenerate_id(true);
            auditar('clave_cambiada', $n ? "Se cerraron $n sesiones" : '');
            aviso('Contraseña cambiada.' . ($n ? ' Por seguridad se cerraron tus otras sesiones.' : ''));
            redirigir((int) $u['cambiar_clave'] ? 'inicio' : 'mi_cuenta');
        }
    } elseif ($accion === 'codigos') {
        $_SESSION['codigos_mostrar'] = acceso()->nuevosCodigosRespaldo((int) $u['id']);
        auditar('codigos_nuevos');
        redirigir('codigos');
    } else {
        $error = 'Acción no válida.';
    }
}

$ingresos = (new Auditoria(db()))->buscar(['usuario' => $u['email'], 'grupo' => 'acceso'], 1, 12)['filas'];
vista('mi_cuenta', [
    'error' => $error,
    'sesiones' => acceso()->sesiones((int) $u['id']),
    'restantes' => acceso()->codigosRestantes((int) $u['id']),
    'ingresos' => $ingresos,
], 'Mi cuenta · Panel Tukán');
