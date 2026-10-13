<?php
declare(strict_types=1);

namespace Tukan;

use PDO;
use RuntimeException;

/**
 * Licencias de los programas de una sola empresa (TuGuardería, PROTOCOLO_V2 §8 y §9.5 I): lo comercial lo lleva
 * el Central (valor al mes, pagada hasta, contacto cifrado). Pagar la licencia crea un ingreso extra y mueve la fecha.
 */
final class Licencias
{
    private ?Cifrado $cifrado;

    public function __construct(private PDO $db, ?Cifrado $cifrado = null)
    {
        $this->cifrado = $cifrado;
    }

    private function cifrado(): Cifrado
    {
        return $this->cifrado ??= new Cifrado();
    }

    public function obtener(string $inst): ?array
    {
        $st = $this->db->prepare('SELECT * FROM licencias WHERE instancia_id = ?');
        $st->execute([$inst]);
        $f = $st->fetch();
        if (!$f) return null;
        $f['contacto'] = null;
        if ($f['contacto_cif']) {
            try {
                $f['contacto'] = json_decode($this->cifrado()->descifrar($f['contacto_cif'], "licencia:$inst"), true);
            } catch (\Throwable) {
            }
        }
        return $f;
    }

    public function todas(): array
    {
        return $this->db->query('SELECT l.*, i.nombre AS programa, i.producto FROM licencias l JOIN instancias i ON i.id = l.instancia_id AND i.activo = 1')->fetchAll();
    }

    public function guardar(string $inst, float $valor, ?string $hasta, array $contacto, string $notas, ?int $ahora = null): void
    {
        if ($valor < 0 || $valor > 100000) throw new RuntimeException('El valor al mes no es válido.');
        if ($hasta !== null && $hasta !== '' && !\DateTimeImmutable::createFromFormat('!Y-m-d', $hasta)) throw new RuntimeException('La fecha "pagada hasta" no es válida.');
        $contacto = array_map(fn($v) => mb_substr(trim((string) $v), 0, 150), array_intersect_key($contacto, array_flip(['nombre', 'telefono', 'email'])));
        $cif = array_filter($contacto) ? $this->cifrado()->cifrar(json_encode($contacto, JSON_UNESCAPED_UNICODE), "licencia:$inst") : null;
        $this->db->prepare('INSERT INTO licencias (instancia_id, valor_mensual, activo_hasta, contacto_cif, notas, actualizado) VALUES (?,?,?,?,?,?)
                            ON CONFLICT (instancia_id) DO UPDATE SET valor_mensual = excluded.valor_mensual, activo_hasta = excluded.activo_hasta,
                              contacto_cif = excluded.contacto_cif, notas = excluded.notas, actualizado = excluded.actualizado')
            ->execute([$inst, round($valor, 2), $hasta ?: null, $cif, mb_substr($notas, 0, 1000), $ahora ?? time()]);
        $this->actualizarCopia($inst);
    }

    /** Fecha nueva al pagar N meses: desde la fecha actual si aún no vence, o desde hoy. */
    public static function nuevaFecha(?string $hasta, int $meses, string $hoy): string
    {
        $base = $hasta !== null && $hasta >= $hoy ? $hasta : $hoy;
        return (new \DateTimeImmutable($base))->modify("+$meses months")->format('Y-m-d');
    }

    /** Registra el pago de la licencia: un ingreso extra del programa y la fecha "pagada hasta" se mueve. */
    public function registrarPago(array $inst, float $monto, int $meses, string $fecha, string $metodo, ?int $uid, ?string $hoy = null): array
    {
        $hoy ??= date('Y-m-d');
        if ($meses < 1 || $meses > 36) throw new RuntimeException('Elige de 1 a 36 meses.');
        if ($monto < 0) throw new RuntimeException('El valor no es válido.');
        $lic = $this->obtener($inst['id']);
        if (!$lic) {
            $this->guardar($inst['id'], $meses > 0 ? round($monto / $meses, 2) : 0, null, [], '');
            $lic = $this->obtener($inst['id']);
        }
        $nueva = self::nuevaFecha($lic['activo_hasta'], $meses, $hoy);
        $fin = new Finanzas($this->db);
        $id = $fin->guardarIngresoExtra([
            'fecha' => $fecha, 'concepto' => 'Licencia ' . $inst['nombre'] . " ($meses " . ($meses === 1 ? 'mes' : 'meses') . ')',
            'monto' => $monto, 'instancia_id' => $inst['id'], 'metodo' => $metodo,
        ], $uid);
        $this->db->prepare('UPDATE licencias SET activo_hasta = ?, actualizado = ? WHERE instancia_id = ?')->execute([$nueva, time(), $inst['id']]);
        $this->actualizarCopia($inst['id']);
        return ['ingreso_id' => $id, 'activo_hasta' => $nueva];
    }

    /** Pone la licencia en el "cliente" único del programa (estado, vence, plan y lo que vale al mes). */
    public function aplicar(string $inst, array $c, ?string $hoy = null): array
    {
        $hoy ??= date('Y-m-d');
        $lic = $this->obtener($inst);
        $hasta = $lic['activo_hasta'] ?? null;
        $dias = $hasta ? (int) (new \DateTimeImmutable($hoy))->diff(new \DateTimeImmutable($hasta))->format('%r%a') : null;
        $c['plan'] = ['codigo' => 'licencia', 'nombre' => 'Licencia'];
        $c['fechas']['activo_hasta'] = $hasta;
        $c['fechas']['vence'] = $hasta;
        $c['fechas']['dias_para_vencer'] = $dias;
        $c['estado'] = $dias === null ? 'activo' : ($dias < 0 ? 'vencido' : ($dias <= 7 ? 'por_vencer' : 'activo'));
        $c['estado_texto'] = $hasta ? ($dias < 0 ? 'Licencia vencida el ' . $hasta : 'Licencia pagada hasta el ' . $hasta) : 'Licencia sin fecha (anótala en Programas)';
        $c['mensual_estimado'] = (float) ($lic['valor_mensual'] ?? 0);
        $st = $this->db->prepare('SELECT coalesce(sum(monto), 0), count(*), min(fecha), max(fecha) FROM ingresos_extra WHERE instancia_id = ? AND anulado = 0');
        $st->execute([$inst]);
        [$total, $n, $primero, $ultimo] = $st->fetch(PDO::FETCH_NUM);
        $c['pagos'] = ['total' => round((float) $total, 2), 'cantidad' => (int) $n, 'primer_pago' => $primero, 'ultimo_pago' => $ultimo, 'ultimo_monto' => null];
        if (empty($c['dueno']['telefono']) && !empty($lic['contacto'])) {
            $c['dueno'] = ['nombre' => $lic['contacto']['nombre'] ?? null, 'telefono' => $lic['contacto']['telefono'] ?? null,
                           'whatsapp' => Contacto::numeroWhatsapp($lic['contacto']['telefono'] ?? null), 'email' => $lic['contacto']['email'] ?? null];
        }
        return $c;
    }

    /** Vuelve a calcular el cliente único guardado (cuando cambia la licencia). */
    public function actualizarCopia(string $inst): void
    {
        $cl = new Clientes($this->db, $this->cifrado);
        foreach ($cl->todas($inst) as $f) {
            $c = $f['datos'];
            $c['dueno'] = $cl->contacto($f) ?? ['nombre' => null, 'telefono' => null, 'whatsapp' => null, 'email' => null];
            $cl->guardar($inst, $this->aplicar($inst, $c), (int) $f['visto']);
        }
    }
}
