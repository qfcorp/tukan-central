<?php
declare(strict_types=1);

namespace Tukan;

use PDO;
use RuntimeException;

/**
 * Ingreso al panel: contraseña + verificación en 2 pasos (obligatoria), bloqueo por intentos,
 * códigos de respaldo, sesiones y permisos por rol.
 */
final class Acceso
{
    public const MAX_FALLOS = 5;
    public const BLOQUEO_SEG = 900;          // 15 minutos
    public const INACTIVA_SEG = 1800;        // 30 minutos sin usar
    public const MAXIMA_SEG = 43200;         // 12 horas como máximo
    public const CODIGOS_RESPALDO = 8;
    public const CLAVE_MINIMA = 10;

    public const ROLES = [
        'dueno'   => 'Dueño (todo)',
        'soporte' => 'Soporte (entra a los programas y atiende solicitudes; no ve contactos ni plata)',
    ];

    /**
     * Lo que puede hacer cada rol. El dueño puede todo (PROTOCOLO_V2 §9.3):
     *   ver (solicitudes, clientes sin contacto, vencimientos, demos, embudo), atender (atender_solicitud, responder_ticket)
     *   solo dueño: contacto (teléfono, correo, WhatsApp), demos (crear_demo, extender_prueba, nueva_clave_dueno, nota_interna),
     *   cobros (registrar_pago, comprobantes, anular_pago, suspender, reactivar, cambiar_plan, guardar_plan), finanzas,
     *   borrar_clientes (borrar_cliente y restaurar_cliente, Anexo A).
     *   Soporte también puede pausar (Anexo A): devolver el servicio (reactivar) lo decide el dueño.
     */
    private const PERMISOS_SOPORTE = ['ver', 'entrar', 'probar', 'actualizar', 'mi_cuenta', 'atender', 'pausar'];

    private ?Cifrado $cifrado;

    public function __construct(private PDO $db, ?Cifrado $cifrado = null)
    {
        $this->cifrado = $cifrado;
    }

    private function cifrado(): Cifrado
    {
        return $this->cifrado ??= new Cifrado();
    }

    /**
     * Permisos: ver, entrar, probar, actualizar, mi_cuenta (todos)
     * y programas (agregar/editar/eliminar/cambiar secreto), secretos, usuarios, auditoria, ajustes (solo dueño).
     */
    public static function puede(string $rol, string $accion): bool
    {
        if ($rol === 'dueno') return true;
        if ($rol === 'soporte') return in_array($accion, self::PERMISOS_SOPORTE, true);
        return false;
    }

    // ------------------------------------------------------------- bloqueo

    public static function claveEmail(string $email): string
    {
        return 'email:' . strtolower(trim($email));
    }

    public static function claveIp(string $ip): string
    {
        return 'ip:' . $ip;
    }

    /** Segundos que faltan para poder intentar otra vez (0 = no está bloqueado). Revisa correo e IP. */
    public function bloqueado(string $email, string $ip, ?int $ahora = null): int
    {
        $ahora ??= time();
        $falta = 0;
        foreach ([self::claveEmail($email), self::claveIp($ip)] as $clave) {
            $st = $this->db->prepare('SELECT momento FROM intentos_fallidos WHERE clave = ? AND momento > ? ORDER BY momento DESC LIMIT ' . self::MAX_FALLOS);
            $st->execute([$clave, $ahora - self::BLOQUEO_SEG]);
            $m = $st->fetchAll(PDO::FETCH_COLUMN);
            if (count($m) >= self::MAX_FALLOS) {
                // Bloqueado 15 minutos desde el último error
                $falta = max($falta, (int) $m[0] + self::BLOQUEO_SEG - $ahora);
            }
        }
        return max(0, $falta);
    }

    public function registrarFallo(string $email, string $ip, ?int $ahora = null): void
    {
        $ahora ??= time();
        $st = $this->db->prepare('INSERT INTO intentos_fallidos (clave, momento) VALUES (?, ?)');
        if (trim($email) !== '') $st->execute([self::claveEmail($email), $ahora]);
        $st->execute([self::claveIp($ip), $ahora]);
        $this->db->prepare('DELETE FROM intentos_fallidos WHERE momento < ?')->execute([$ahora - 86400]);
    }

    public function limpiarFallos(string $email, string $ip): void
    {
        $this->db->prepare('DELETE FROM intentos_fallidos WHERE clave IN (?, ?)')->execute([self::claveEmail($email), self::claveIp($ip)]);
    }

    // ------------------------------------------------------------- usuarios

    public function usuario(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM usuarios WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function porEmail(string $email): ?array
    {
        $st = $this->db->prepare('SELECT * FROM usuarios WHERE email = ?');
        $st->execute([strtolower(trim($email))]);
        return $st->fetch() ?: null;
    }

    public function lista(): array
    {
        return $this->db->query('SELECT id, nombre, email, rol, activo, totp_cif IS NOT NULL AS con_2fa, cambiar_clave, creado, ultimo_ingreso FROM usuarios ORDER BY activo DESC, nombre')->fetchAll();
    }

    public static function errorClave(string $clave): ?string
    {
        if (strlen($clave) < self::CLAVE_MINIMA) return 'La contraseña debe tener al menos ' . self::CLAVE_MINIMA . ' caracteres.';
        if (strlen($clave) > 200) return 'La contraseña es demasiado larga.';
        return null;
    }

    /** Crea un usuario o, si el correo ya existe, le cambia nombre/contraseña/rol. Devuelve [id, era_nuevo]. */
    public function guardarUsuario(string $nombre, string $email, string $clave, string $rol = 'dueno', bool $cambiarClave = false, ?int $ahora = null): array
    {
        $ahora ??= time();
        $email = strtolower(trim($email));
        $nombre = trim($nombre);
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 80) throw new RuntimeException('Escribe el nombre (2 a 80 letras).');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo no es válido.');
        if (!isset(self::ROLES[$rol])) throw new RuntimeException('Rol no válido.');
        if (($e = self::errorClave($clave)) !== null) throw new RuntimeException($e);
        $hash = password_hash($clave, PASSWORD_DEFAULT);
        $u = $this->porEmail($email);
        if ($u) {
            $this->db->prepare('UPDATE usuarios SET nombre = ?, clave_hash = ?, rol = ?, activo = 1, cambiar_clave = ?, actualizado = ? WHERE id = ?')
                ->execute([$nombre, $hash, $rol, (int) $cambiarClave, $ahora, $u['id']]);
            return [(int) $u['id'], false];
        }
        $this->db->prepare('INSERT INTO usuarios (nombre, email, clave_hash, rol, cambiar_clave, creado, actualizado) VALUES (?,?,?,?,?,?,?)')
            ->execute([$nombre, $email, $hash, $rol, (int) $cambiarClave, $ahora, $ahora]);
        return [(int) $this->db->lastInsertId(), true];
    }

    public function cambiarRol(int $id, string $rol): void
    {
        if (!isset(self::ROLES[$rol])) throw new RuntimeException('Rol no válido.');
        if ($rol !== 'dueno') $this->exigirOtroDueno($id);
        $this->db->prepare('UPDATE usuarios SET rol = ?, actualizado = ? WHERE id = ?')->execute([$rol, time(), $id]);
    }

    public function activar(int $id, bool $activo): void
    {
        if (!$activo) {
            $this->exigirOtroDueno($id);
            $this->cerrarTodas($id, 'usuario desactivado');
        }
        $this->db->prepare('UPDATE usuarios SET activo = ?, actualizado = ? WHERE id = ?')->execute([(int) $activo, time(), $id]);
    }

    /** No se puede quedar el panel sin ningún dueño activo. */
    private function exigirOtroDueno(int $id): void
    {
        $st = $this->db->prepare("SELECT count(*) FROM usuarios WHERE rol = 'dueno' AND activo = 1 AND id <> ?");
        $st->execute([$id]);
        if ((int) $st->fetchColumn() === 0) throw new RuntimeException('Debe quedar al menos un dueño activo.');
    }

    public function cambiarClave(int $id, string $nueva): void
    {
        if (($e = self::errorClave($nueva)) !== null) throw new RuntimeException($e);
        $this->db->prepare('UPDATE usuarios SET clave_hash = ?, cambiar_clave = 0, actualizado = ? WHERE id = ?')
            ->execute([password_hash($nueva, PASSWORD_DEFAULT), time(), $id]);
    }

    // ------------------------------------------------------------- paso 1: contraseña

    /**
     * Revisa correo y contraseña. Devuelve ['ok' => true, 'usuario' => ...] o ['ok' => false, 'error' => texto, 'bloqueo' => seg].
     * Siempre tarda lo mismo exista o no el correo (no da pistas de qué correos existen).
     */
    public function verificarClave(string $email, string $clave, string $ip, ?int $ahora = null): array
    {
        $ahora ??= time();
        $falta = $this->bloqueado($email, $ip, $ahora);
        if ($falta > 0) return ['ok' => false, 'bloqueo' => $falta, 'error' => self::textoBloqueo($falta)];
        $u = $this->porEmail($email);
        $hash = $u['clave_hash'] ?? '$2y$10$8Jxi8fSf0f.MnmiSInt3leXXeRF8ty5KxoTWiukMdmRnCeOsYdvf2';
        $bien = password_verify($clave, $hash);
        if (!$u || !$bien || !(int) $u['activo']) {
            $this->registrarFallo($email, $ip, $ahora);
            $falta = $this->bloqueado($email, $ip, $ahora);
            return ['ok' => false, 'bloqueo' => $falta, 'error' => $falta > 0 ? self::textoBloqueo($falta) : 'El correo o la contraseña no son correctos.'];
        }
        if (password_needs_rehash($u['clave_hash'], PASSWORD_DEFAULT)) {
            $this->db->prepare('UPDATE usuarios SET clave_hash = ? WHERE id = ?')->execute([password_hash($clave, PASSWORD_DEFAULT), $u['id']]);
        }
        return ['ok' => true, 'usuario' => $u];
    }

    public static function textoBloqueo(int $seg): string
    {
        $min = max(1, (int) ceil($seg / 60));
        return "Demasiados intentos equivocados. Por seguridad espera $min " . ($min === 1 ? 'minuto' : 'minutos') . ' y vuelve a intentar.';
    }

    // ------------------------------------------------------------- paso 2: código

    public function secretoTotp(array $u): ?string
    {
        if (empty($u['totp_cif'])) return null;
        return $this->cifrado()->descifrar($u['totp_cif'], 'totp:' . $u['id']);
    }

    /**
     * Revisa el código de 6 dígitos (o un código de respaldo). Devuelve 'totp', 'respaldo' o null.
     * Los errores cuentan para el bloqueo igual que la contraseña.
     */
    public function verificarCodigo(array $u, string $codigo, string $ip, ?int $ahora = null): ?string
    {
        $ahora ??= time();
        if ($this->bloqueado($u['email'], $ip, $ahora) > 0) return null;
        $tipo = null;
        $secreto = $this->secretoTotp($u);
        $limpio = preg_replace('/\s+/', '', $codigo) ?? '';
        if ($secreto !== null && preg_match('/^\d{6}$/', $limpio)) {
            $paso = Totp::verificar($secreto, $limpio, $ahora, (int) $u['totp_ultimo_paso']);
            if ($paso !== null) {
                // Se guarda el paso usado: el mismo código no sirve dos veces
                $st = $this->db->prepare('UPDATE usuarios SET totp_ultimo_paso = ? WHERE id = ? AND totp_ultimo_paso < ?');
                $st->execute([$paso, $u['id'], $paso]);
                if ($st->rowCount() === 1) $tipo = 'totp';
            }
        } elseif ($this->usarCodigoRespaldo((int) $u['id'], $limpio, $ahora)) {
            $tipo = 'respaldo';
        }
        if ($tipo === null) $this->registrarFallo($u['email'], $ip, $ahora);
        return $tipo;
    }

    /** Activa 2FA si el código coincide con el secreto nuevo. Devuelve los códigos de respaldo (para mostrarlos UNA vez) o null. */
    public function activarTotp(int $id, string $secreto, string $codigo, ?int $ahora = null): ?array
    {
        $ahora ??= time();
        $paso = Totp::verificar($secreto, $codigo, $ahora);
        if ($paso === null) return null;
        $this->db->prepare('UPDATE usuarios SET totp_cif = ?, totp_ultimo_paso = ?, actualizado = ? WHERE id = ?')
            ->execute([$this->cifrado()->cifrar($secreto, 'totp:' . $id), $paso, $ahora, $id]);
        return $this->nuevosCodigosRespaldo($id);
    }

    /** Quita 2FA (el usuario tendrá que activarlo otra vez al entrar). */
    public function quitarTotp(int $id): void
    {
        $this->db->prepare('UPDATE usuarios SET totp_cif = NULL, totp_ultimo_paso = 0, actualizado = ? WHERE id = ?')->execute([time(), $id]);
        $this->db->prepare('DELETE FROM codigos_respaldo WHERE usuario_id = ?')->execute([$id]);
        $this->cerrarTodas($id, '2FA quitado');
    }

    /** 8 códigos de un solo uso, tipo "k7m2-p9xq". Se guardan cifrados con password_hash; los anteriores dejan de servir. */
    public function nuevosCodigosRespaldo(int $id): array
    {
        $abc = 'abcdefghjkmnpqrstuvwxyz23456789';   // sin letras que se confunden (0/o, 1/l/i)
        $codigos = [];
        while (count($codigos) < self::CODIGOS_RESPALDO) {
            $c = '';
            for ($i = 0; $i < 8; $i++) $c .= $abc[random_int(0, strlen($abc) - 1)];
            $codigos[substr($c, 0, 4) . '-' . substr($c, 4)] = true;
        }
        $codigos = array_keys($codigos);
        $this->db->beginTransaction();
        $this->db->prepare('DELETE FROM codigos_respaldo WHERE usuario_id = ?')->execute([$id]);
        $st = $this->db->prepare('INSERT INTO codigos_respaldo (usuario_id, hash) VALUES (?, ?)');
        foreach ($codigos as $c) $st->execute([$id, password_hash($c, PASSWORD_DEFAULT)]);
        $this->db->commit();
        return $codigos;
    }

    public function codigosRestantes(int $id): int
    {
        $st = $this->db->prepare('SELECT count(*) FROM codigos_respaldo WHERE usuario_id = ? AND usado IS NULL');
        $st->execute([$id]);
        return (int) $st->fetchColumn();
    }

    private function usarCodigoRespaldo(int $id, string $codigo, int $ahora): bool
    {
        $codigo = strtolower(str_replace(' ', '', $codigo));
        if (strlen($codigo) === 8) $codigo = substr($codigo, 0, 4) . '-' . substr($codigo, 4);
        if (!preg_match('/^[a-z0-9]{4}-[a-z0-9]{4}$/', $codigo)) return false;
        $st = $this->db->prepare('SELECT id, hash FROM codigos_respaldo WHERE usuario_id = ? AND usado IS NULL');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $f) {
            if (password_verify($codigo, $f['hash'])) {
                $up = $this->db->prepare('UPDATE codigos_respaldo SET usado = ? WHERE id = ? AND usado IS NULL');
                $up->execute([$ahora, $f['id']]);
                return $up->rowCount() === 1;
            }
        }
        return false;
    }

    // ------------------------------------------------------------- sesiones

    public function abrirSesion(int $uid, string $ip, string $agente, ?int $ahora = null): string
    {
        $ahora ??= time();
        $ref = bin2hex(random_bytes(16));
        $this->db->prepare('INSERT INTO sesiones (id, usuario_id, ip, agente, creada, ultima) VALUES (?,?,?,?,?,?)')
            ->execute([$ref, $uid, substr($ip, 0, 45), mb_substr($agente, 0, 200), $ahora, $ahora]);
        $this->db->prepare('UPDATE usuarios SET ultimo_ingreso = ? WHERE id = ?')->execute([$ahora, $uid]);
        $this->db->prepare('DELETE FROM sesiones WHERE creada < ?')->execute([$ahora - 90 * 86400]);
        return $ref;
    }

    /** ¿Por qué se venció la sesión? null = sigue vigente. */
    public static function motivoVencida(int $creada, int $ultima, int $ahora, int $inactiva = self::INACTIVA_SEG, int $maxima = self::MAXIMA_SEG): ?string
    {
        if ($ahora - $creada > $maxima) return 'maxima';
        if ($ahora - $ultima > $inactiva) return 'inactiva';
        return null;
    }

    /**
     * Revisa la sesión guardada y la marca como usada. Devuelve null si está bien, o el motivo de cierre
     * ('cerrada', 'inactiva', 'maxima', 'usuario').
     */
    public function revisarSesion(string $ref, int $uid, ?int $ahora = null, bool $tocar = true): ?string
    {
        $ahora ??= time();
        $st = $this->db->prepare('SELECT s.*, u.activo FROM sesiones s JOIN usuarios u ON u.id = s.usuario_id WHERE s.id = ? AND s.usuario_id = ?');
        $st->execute([$ref, $uid]);
        $s = $st->fetch();
        if (!$s || $s['cerrada'] !== null) return 'cerrada';
        if (!(int) $s['activo']) {
            $this->cerrarSesion($ref, 'usuario desactivado');
            return 'usuario';
        }
        $inactiva = (int) (getenv('TUKAN_SESION_INACTIVA') ?: self::INACTIVA_SEG);
        $maxima = (int) (getenv('TUKAN_SESION_MAXIMA') ?: self::MAXIMA_SEG);
        $motivo = self::motivoVencida((int) $s['creada'], (int) $s['ultima'], $ahora, $inactiva, $maxima);
        if ($motivo !== null) {
            $this->cerrarSesion($ref, $motivo);
            return $motivo;
        }
        if ($tocar && $ahora > (int) $s['ultima']) {
            $this->db->prepare('UPDATE sesiones SET ultima = ? WHERE id = ?')->execute([$ahora, $ref]);
        }
        return null;
    }

    public function cerrarSesion(string $ref, string $motivo = 'salio'): void
    {
        $this->db->prepare('UPDATE sesiones SET cerrada = ?, motivo = ? WHERE id = ? AND cerrada IS NULL')->execute([time(), $motivo, $ref]);
    }

    public function cerrarTodas(int $uid, string $motivo, ?string $menos = null): int
    {
        $st = $this->db->prepare('UPDATE sesiones SET cerrada = ?, motivo = ? WHERE usuario_id = ? AND cerrada IS NULL AND id <> ?');
        $st->execute([time(), $motivo, $uid, $menos ?? '']);
        return $st->rowCount();
    }

    public function sesiones(int $uid, int $limite = 15): array
    {
        $st = $this->db->prepare('SELECT * FROM sesiones WHERE usuario_id = ? ORDER BY creada DESC LIMIT ' . (int) $limite);
        $st->execute([$uid]);
        return $st->fetchAll();
    }
}
