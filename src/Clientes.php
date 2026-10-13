<?php
declare(strict_types=1);

namespace Tukan;

use PDO;

/**
 * Copia local de los clientes de todos los programas (PROTOCOLO_V2 §4.3, §5.4 y §9.7):
 * se refresca desde cada programa y las pantallas se dibujan con lo guardado (rápido aunque un programa esté caído).
 * El contacto del dueño se guarda cifrado y solo se descifra para el rol dueño.
 */
final class Clientes
{
    public const ESTADOS = ['prueba' => 'En prueba', 'activo' => 'Al día', 'por_vencer' => 'Por vencer', 'vencido' => 'Vencido', 'suspendido' => 'Suspendido'];
    public const ORIGENES = ['registro' => 'Se registró solo', 'demo_pedida' => 'Pidió una demo', 'super' => 'Lo creó el súper', 'central' => 'Creado desde el Panel Tukán',
                             'google_play' => 'Desde Google Play', 'desconocido' => 'No se sabe'];
    public const BORRAR_DESPUES = 7 * 86400;

    private ?Cifrado $cifrado;

    public function __construct(private PDO $db, ?Cifrado $cifrado = null)
    {
        $this->cifrado = $cifrado;
    }

    private function cifrado(): Cifrado
    {
        return $this->cifrado ??= new Cifrado();
    }

    public static function idValido(mixed $id): bool
    {
        return is_string($id) && (bool) preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $id);
    }

    private static function texto(mixed $v, int $max): ?string
    {
        if (!is_string($v) && !is_int($v) && !is_float($v)) return null;
        $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $v) ?? '');
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private static function fecha(mixed $v): ?string
    {
        return is_string($v) && \DateTimeImmutable::createFromFormat('!Y-m-d', substr($v, 0, 10)) ? substr($v, 0, 10) : null;
    }

    private static function dinero(mixed $v): float
    {
        return is_int($v) || is_float($v) ? round((float) $v, 2) : (is_string($v) && is_numeric($v) ? round((float) $v, 2) : 0.0);
    }

    /** Revisa un cliente (§4.3). null si no tiene lo mínimo (id y nombre). */
    public static function limpiar(mixed $c, ?string $hoy = null): ?array
    {
        if (!is_array($c)) return null;
        $id = is_int($c['id'] ?? null) ? (string) $c['id'] : ($c['id'] ?? null);
        $nombre = self::texto($c['nombre'] ?? null, 120);
        if (!self::idValido($id) || $nombre === null) return null;
        $f = is_array($c['fechas'] ?? null) ? $c['fechas'] : [];
        $vence = self::fecha($f['vence'] ?? null);
        $hoy ??= date('Y-m-d');
        $dias = $vence !== null ? (int) (new \DateTimeImmutable($hoy))->diff(new \DateTimeImmutable($vence))->format('%r%a') : null;
        $d = is_array($c['dueno'] ?? null) ? $c['dueno'] : [];
        $p = is_array($c['pagos'] ?? null) ? $c['pagos'] : [];
        $uso = [];
        if (is_array($c['uso'] ?? null)) {
            foreach ($c['uso'] as $k => $v) {
                if (count($uso) >= 4) break;
                if (($k = self::texto((string) $k, 40)) !== null && (is_int($v) || is_float($v))) $uso[$k] = $v;
            }
        }
        $plan = is_array($c['plan'] ?? null) ? ['codigo' => self::texto($c['plan']['codigo'] ?? null, 40), 'nombre' => self::texto($c['plan']['nombre'] ?? null, 60)] : null;
        $vend = $c['vendedor'] ?? null;
        $vend = is_array($vend) ? self::texto($vend['nombre'] ?? null, 80) : self::texto($vend, 80);
        return [
            'id' => $id,
            'nombre' => $nombre,
            'tipo' => self::texto($c['tipo'] ?? null, 40),
            'tipo_nombre' => self::texto($c['tipo_nombre'] ?? null, 60),
            'ciudad' => self::texto($c['ciudad'] ?? null, 80),
            'plan' => $plan && ($plan['codigo'] !== null || $plan['nombre'] !== null) ? $plan : null,
            'estado' => isset(self::ESTADOS[$c['estado'] ?? '']) ? $c['estado'] : 'activo',
            'estado_detalle' => self::texto($c['estado_detalle'] ?? null, 30),
            'estado_texto' => self::texto($c['estado_texto'] ?? null, 160),
            'en_gracia' => ($c['en_gracia'] ?? false) === true,
            'bloqueado' => ($c['bloqueado'] ?? false) === true,
            'fechas' => [
                'alta' => self::fecha($f['alta'] ?? null),
                'prueba_hasta' => self::fecha($f['prueba_hasta'] ?? null),
                'activo_hasta' => self::fecha($f['activo_hasta'] ?? null),
                'vence' => $vence,
                'dias_para_vencer' => $dias,
            ],
            'dueno' => [
                'nombre' => self::texto($d['nombre'] ?? null, 100),
                'telefono' => self::texto($d['telefono'] ?? null, 40),
                'whatsapp' => Contacto::numeroWhatsapp(is_string($d['whatsapp'] ?? null) ? $d['whatsapp'] : null),
                'email' => is_string($d['email'] ?? null) && filter_var($d['email'], FILTER_VALIDATE_EMAIL) ? mb_substr($d['email'], 0, 150) : null,
            ],
            'ultima_actividad' => self::texto($c['ultima_actividad'] ?? null, 40),
            'pagos' => [
                'total' => self::dinero($p['total'] ?? 0),
                'cantidad' => is_int($p['cantidad'] ?? null) ? max(0, $p['cantidad']) : 0,
                'primer_pago' => self::fecha($p['primer_pago'] ?? null),
                'ultimo_pago' => self::fecha($p['ultimo_pago'] ?? null),
                'ultimo_monto' => isset($p['ultimo_monto']) && (is_int($p['ultimo_monto']) || is_float($p['ultimo_monto'])) ? round((float) $p['ultimo_monto'], 2) : null,
            ],
            'mensual_estimado' => self::dinero($c['mensual_estimado'] ?? 0),
            'origen' => isset(self::ORIGENES[$c['origen'] ?? '']) ? $c['origen'] : 'desconocido',
            'vendedor' => $vend,
            'uso' => $uso,
            'ir' => Formulario::rutaValida($c['ir'] ?? null) ? $c['ir'] : null,
            'ir_trabajar' => Formulario::rutaValida($c['ir_trabajar'] ?? null) ? $c['ir_trabajar'] : null,
            'actualizado' => self::texto($c['actualizado'] ?? null, 40),
        ];
    }

    /** Guarda (o actualiza) un cliente ya revisado. */
    public function guardar(string $inst, array $c, ?int $ahora = null): void
    {
        $ahora ??= time();
        $contacto = $c['dueno'];
        $resto = $c;
        unset($resto['dueno']);
        $hayContacto = array_filter($contacto, fn($v) => $v !== null) !== [];
        $cif = $hayContacto ? $this->cifrado()->cifrar(json_encode($contacto, JSON_UNESCAPED_UNICODE), "contacto:$inst:{$c['id']}") : null;
        $this->db->prepare('INSERT INTO clientes (instancia_id, cliente_id, nombre, estado, estado_texto, plan, alta, vence, dias_para_vencer, ultima_actividad,
                              total_pagado, primer_pago, mensual_estimado, origen, contacto_cif, datos, visto)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                            ON CONFLICT (instancia_id, cliente_id) DO UPDATE SET nombre = excluded.nombre, estado = excluded.estado,
                              estado_texto = excluded.estado_texto, plan = excluded.plan, alta = excluded.alta, vence = excluded.vence,
                              dias_para_vencer = excluded.dias_para_vencer, ultima_actividad = excluded.ultima_actividad, total_pagado = excluded.total_pagado,
                              primer_pago = excluded.primer_pago, mensual_estimado = excluded.mensual_estimado, origen = excluded.origen,
                              contacto_cif = excluded.contacto_cif, datos = excluded.datos, visto = excluded.visto')
            ->execute([$inst, $c['id'], $c['nombre'], $c['estado'], $c['estado_texto'], $c['plan']['nombre'] ?? $c['plan']['codigo'] ?? null,
                       $c['fechas']['alta'], $c['fechas']['vence'], $c['fechas']['dias_para_vencer'], $c['ultima_actividad'],
                       $c['pagos']['total'], $c['pagos']['primer_pago'], $c['mensual_estimado'], $c['origen'], $cif,
                       json_encode($resto, JSON_UNESCAPED_UNICODE), $ahora]);
    }

    /**
     * Trae los clientes de un programa (todas las páginas; o solo $ids) y actualiza la copia.
     * @return array{total:int,contadores:array}
     */
    public function refrescar(array $inst, ClienteV2 $cv, array $cap, ?array $usuario = null, ?array $ids = null, ?int $ahora = null): array
    {
        $ahora ??= time();
        $sync = new Sincronizacion($this->db);
        $porPagina = min(200, (int) ($cap['limites']['por_pagina_max'] ?? 50));
        $pagina = 1;
        $total = 0;
        $contadores = [];
        do {
            $cuerpo = ['pagina' => $pagina, 'por_pagina' => $porPagina, 'orden' => 'vence'];
            if ($ids !== null) $cuerpo['ids'] = array_values($ids);
            $r = $cv->pedir($inst, 'clientes', $cuerpo, $usuario);
            $lista = is_array($r['clientes'] ?? null) && array_is_list($r['clientes']) ? $r['clientes'] : [];
            $this->db->beginTransaction();
            foreach ($lista as $c) {
                if (($l = self::limpiar($c)) !== null) {
                    $this->guardar($inst['id'], $l, $ahora);
                    $total++;
                }
            }
            $this->db->commit();
            if (is_array($r['contadores'] ?? null)) $contadores = array_map('intval', array_intersect_key($r['contadores'], array_flip(['total', 'prueba', 'activo', 'por_vencer', 'vencido', 'suspendido'])));
            $paginas = is_int($r['paginas'] ?? null) ? $r['paginas'] : 1;
            $pagina++;
        } while ($pagina <= min($paginas, 100) && $lista !== []);
        if ($ids === null) {
            $sync->guardar($inst['id'], 'clientes', $ahora, null, $contadores);
            // Los que el programa ya no manda se borran de la copia a los 7 días
            $this->db->prepare('DELETE FROM clientes WHERE instancia_id = ? AND visto < ?')->execute([$inst['id'], $ahora - self::BORRAR_DESPUES]);
        }
        return ['total' => $total, 'contadores' => $contadores];
    }

    /** Programas de una sola empresa (TuGuardería): un solo "cliente", con la licencia que lleva el Central. */
    public function refrescarUnico(array $inst, ClienteV2 $cv, ?array $usuario = null, ?int $ahora = null): ?array
    {
        $ahora ??= time();
        $r = $cv->pedir($inst, 'cliente', ['id' => 'unico'], $usuario);
        $c = self::limpiar($r['cliente'] ?? null);
        if ($c === null) return null;
        $c = (new Licencias($this->db, $this->cifrado))->aplicar($inst['id'], $c);
        $this->guardar($inst['id'], $c, $ahora);
        (new Sincronizacion($this->db))->guardar($inst['id'], 'clientes', $ahora, null, ['total' => 1]);
        return $c;
    }

    // ------------------------------------------------------------- lectura

    public function contacto(array $fila): ?array
    {
        if (empty($fila['contacto_cif'])) return null;
        try {
            return json_decode($this->cifrado()->descifrar($fila['contacto_cif'], "contacto:{$fila['instancia_id']}:{$fila['cliente_id']}"), true) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function obtener(string $inst, string $id): ?array
    {
        $st = $this->db->prepare('SELECT c.*, i.nombre AS programa, i.producto FROM clientes c JOIN instancias i ON i.id = c.instancia_id WHERE c.instancia_id = ? AND c.cliente_id = ?');
        $st->execute([$inst, $id]);
        $f = $st->fetch();
        if (!$f) return null;
        $f['datos'] = json_decode($f['datos'], true) ?: [];
        return $f;
    }

    /** Filas vigentes (de programas activos y que vinieron en la última lista completa). */
    public function todas(?string $inst = null): array
    {
        $sql = 'SELECT c.*, i.nombre AS programa, i.producto FROM clientes c JOIN instancias i ON i.id = c.instancia_id AND i.activo = 1
                LEFT JOIN sincronizacion s ON s.instancia_id = c.instancia_id AND s.que = \'clientes\'
                WHERE (s.momento IS NULL OR c.visto >= s.momento)' . ($inst !== null ? ' AND c.instancia_id = ?' : '');
        $st = $this->db->prepare($sql);
        $st->execute($inst !== null ? [$inst] : []);
        $filas = $st->fetchAll();
        foreach ($filas as &$f) $f['datos'] = json_decode($f['datos'], true) ?: [];
        return $filas;
    }

    private static function sinTildes(string $t): string
    {
        return mb_strtolower(strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n']));
    }

    /**
     * Lista con filtros: inst, estado, plan, buscar, vence_en (días), sin_uso (días), origen, orden.
     * $conContacto: el rol dueño también busca por teléfono y correo y recibe el contacto descifrado.
     * @return array{filas: array, contadores: array, total: int}
     */
    public function lista(array $f, bool $conContacto, ?string $hoy = null): array
    {
        $hoy ??= date('Y-m-d');
        $filas = $this->todas(($f['inst'] ?? '') !== '' ? $f['inst'] : null);
        $q = self::sinTildes(trim((string) ($f['buscar'] ?? '')));
        $qDig = preg_replace('/\D+/', '', $q) ?? '';
        $salida = [];
        $contadores = array_fill_keys(array_keys(self::ESTADOS), 0);
        foreach ($filas as $r) {
            $r['contacto'] = $conContacto ? $this->contacto($r) : null;
            if (($f['plan'] ?? '') !== '' && (string) $r['plan'] !== $f['plan']) continue;
            if (($f['origen'] ?? '') !== '' && $r['origen'] !== $f['origen']) continue;
            if (!empty($f['vence_en'])) {
                $hasta = date('Y-m-d', strtotime("$hoy +" . (int) $f['vence_en'] . ' days'));
                if ($r['vence'] === null || $r['vence'] < $hoy || $r['vence'] > $hasta || $r['estado'] === 'suspendido') continue;
            }
            if (!empty($f['sin_uso'])) {
                $limite = strtotime("$hoy -" . (int) $f['sin_uso'] . ' days');
                $ua = $r['ultima_actividad'] ? strtotime($r['ultima_actividad']) : false;
                if ($ua !== false && $ua >= $limite) continue;
            }
            if ($q !== '') {
                $donde = self::sinTildes($r['nombre'] . ' ' . ($r['datos']['ciudad'] ?? '') . ' ' . ($r['programa'] ?? ''));
                $ok = str_contains($donde, $q);
                if (!$ok && $r['contacto']) {
                    $ok = str_contains(self::sinTildes((string) ($r['contacto']['nombre'] ?? '') . ' ' . ($r['contacto']['email'] ?? '')), $q)
                       || (strlen($qDig) >= 4 && (str_contains(preg_replace('/\D+/', '', (string) ($r['contacto']['telefono'] ?? '')) ?? '', $qDig)
                                                  || str_contains((string) ($r['contacto']['whatsapp'] ?? ''), $qDig)));
                }
                if (!$ok) continue;
            }
            $contadores[$r['estado']]++;
            $estados = $f['estados'] ?? [];
            if ($estados && !in_array($r['estado'], $estados, true)) continue;
            $salida[] = $r;
        }
        $orden = $f['orden'] ?? 'vence';
        usort($salida, function ($a, $b) use ($orden) {
            return match ($orden) {
                'nombre' => strcasecmp($a['nombre'], $b['nombre']),
                'alta' => strcmp((string) $b['alta'], (string) $a['alta']),
                'actividad' => strcmp((string) $a['ultima_actividad'], (string) $b['ultima_actividad']),
                default => [$a['vence'] === null, $a['vence'], $a['nombre']] <=> [$b['vence'] === null, $b['vence'], $b['nombre']],
            };
        });
        return ['filas' => $salida, 'contadores' => $contadores, 'total' => count($salida)];
    }

    /** Planes que hay en la copia (para el filtro). */
    public function planes(): array
    {
        return $this->db->query("SELECT DISTINCT plan FROM clientes WHERE plan IS NOT NULL AND plan <> '' ORDER BY plan")->fetchAll(PDO::FETCH_COLUMN);
    }

    // ------------------------------------------------------------- recordatorios ("ya le escribí")

    public function recordar(string $inst, string $id, string $tipo, ?int $uid, ?int $ahora = null): void
    {
        $this->db->prepare('INSERT INTO recordatorios (instancia_id, cliente_id, tipo, usuario_id, momento) VALUES (?,?,?,?,?)')
            ->execute([$inst, $id, $tipo, $uid, $ahora ?? time()]);
    }

    /** @return array<string, array{momento:int, quien:?string, tipo:string}> clave "inst|cliente" */
    public function ultimosRecordatorios(int $dias = 30): array
    {
        $st = $this->db->prepare('SELECT r.instancia_id, r.cliente_id, r.tipo, r.momento, u.nombre FROM recordatorios r LEFT JOIN usuarios u ON u.id = r.usuario_id
                                  WHERE r.momento > ? ORDER BY r.momento');
        $st->execute([time() - $dias * 86400]);
        $r = [];
        foreach ($st->fetchAll() as $f) $r[$f['instancia_id'] . '|' . $f['cliente_id']] = ['momento' => (int) $f['momento'], 'quien' => $f['nombre'], 'tipo' => $f['tipo']];
        return $r;
    }

    // ------------------------------------------------------------- demos creadas desde el Central

    /**
     * Las demos creadas desde el Central que pagaron por primera vez (se llenan solas al refrescar).
     * @return list<array{nombre:string,monto:?float,instancia_id:string,primer_pago:string}>
     */
    public function vincularDemos(string $inst): array
    {
        $st = $this->db->prepare('SELECT d.id, d.nombre, c.primer_pago, c.datos FROM demos d JOIN clientes c ON c.instancia_id = d.instancia_id AND c.cliente_id = d.cliente_id
                                  WHERE d.instancia_id = ? AND d.primer_pago IS NULL AND c.primer_pago IS NOT NULL');
        $st->execute([$inst]);
        $pagaron = [];
        foreach ($st->fetchAll() as $f) {
            $d = json_decode($f['datos'], true) ?: [];
            $monto = isset($d['pagos']['ultimo_monto']) ? (float) $d['pagos']['ultimo_monto'] : null;   // con un solo pago, el último es el primero
            $this->db->prepare('UPDATE demos SET primer_pago = ?, monto_primer_pago = ? WHERE id = ?')->execute([$f['primer_pago'], $monto, $f['id']]);
            $pagaron[] = ['nombre' => $f['nombre'], 'monto' => $monto, 'instancia_id' => $inst, 'primer_pago' => $f['primer_pago']];
        }
        return $pagaron;
    }
}
