<?php
declare(strict_types=1);

use Tukan\Acceso;

/**
 * Usuarios del panel (solo el dueño). Cada cambio pide el código de 2 pasos de quien lo hace.
 * Al usuario nuevo se le da una contraseña temporal; al entrar activa su 2FA y cambia la contraseña.
 */
$yo = usuario();
$error = null;
if (es_post()) {
    $accion = campo('accion');
    if (!acceso()->verificarCodigo($yo, campo('codigo'), ip_visitante())) {
        auditar('2fa_fallido', 'Al cambiar usuarios');
        $error = 'Tu código de 2 pasos no es correcto. Escribe el que aparece ahora en tu app (si acabas de usar uno, espera el siguiente).';
    } else {
        try {
            $temporal = null;
            if ($accion === 'crear') {
                if (acceso()->porEmail(campo('email'))) throw new RuntimeException('Ya existe un usuario con ese correo.');
                $temporal = substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 14);
                [$id] = acceso()->guardarUsuario(campo('nombre'), campo('email'), $temporal, campo('rol'), true);
                $nuevo = acceso()->usuario($id);
                auditar('usuario_creado', $nuevo['email'] . ' · rol ' . $nuevo['rol']);
                aviso('Usuario creado. Contraseña temporal: ' . $temporal . ' — pásasela por un medio seguro. Al entrar activará su verificación en 2 pasos y cambiará la contraseña.', 'info');
            } else {
                $otro = acceso()->usuario((int) campo('id')) ?? throw new RuntimeException('Ese usuario no existe.');
                switch ($accion) {
                    case 'rol':
                        acceso()->cambiarRol((int) $otro['id'], campo('rol'));
                        auditar('usuario_editado', $otro['email'] . ': rol ' . $otro['rol'] . ' → ' . campo('rol'));
                        aviso('Rol cambiado.');
                        break;
                    case 'activo':
                        $activar = campo('valor') === '1';
                        if (!$activar && (int) $otro['id'] === (int) $yo['id']) throw new RuntimeException('No puedes desactivarte a ti mismo.');
                        acceso()->activar((int) $otro['id'], $activar);
                        auditar('usuario_editado', $otro['email'] . ($activar ? ': activado' : ': desactivado (sesiones cerradas)'));
                        aviso($activar ? 'Usuario activado.' : 'Usuario desactivado. Se cerraron sus sesiones.');
                        break;
                    case 'quitar_2fa':
                        acceso()->quitarTotp((int) $otro['id']);
                        auditar('2fa_quitado', $otro['email'] . ' (desde Usuarios)');
                        aviso('Se quitó su verificación en 2 pasos: la activará otra vez al entrar.');
                        break;
                    case 'clave':
                        $temporal = substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 14);
                        acceso()->guardarUsuario($otro['nombre'], $otro['email'], $temporal, $otro['rol'], true);
                        acceso()->cerrarTodas((int) $otro['id'], 'contraseña nueva');
                        auditar('usuario_editado', $otro['email'] . ': contraseña temporal nueva');
                        aviso('Contraseña temporal nueva para ' . $otro['email'] . ': ' . $temporal, 'info');
                        break;
                    default:
                        throw new RuntimeException('Acción no válida.');
                }
            }
            redirigir('usuarios');
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}
vista('usuarios', ['lista' => acceso()->lista(), 'error' => $error, 'yo' => $yo, 'roles' => Acceso::ROLES], 'Usuarios · Panel Tukán');
