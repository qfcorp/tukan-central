<?php
declare(strict_types=1);

/**
 * Ayudante de "actualizar" y del servicio tukan-actualizar (consola, sin tildes en lo que imprime).
 * Lo instala deploy/instalar_actualizador.sh en /usr/local/lib/tukan-actualizar/ayudante.php (copia de root) junto con Pedido.php.
 *
 *   php ayudante.php validar PEDIDO LLAVE USADOS
 *        Revisa un pedido del panel. Si sirve, imprime "OK<TAB>id<TAB>tipo<TAB>usuario" y una linea "programa<TAB>commit"
 *        por cada objetivo, y anota el id en USADOS (no se puede usar dos veces). Si no sirve: "NO<TAB>id-o-guion<TAB>motivo"
 *        (id solo si la firma era buena) y sale con 1.
 *   php ayudante.php versiones SALIDA            < registros (P/C, ver actualizar.sh)      -> versiones.json para el panel
 *   php ayudante.php resultado SALIDA ORIGEN PEDIDO USUARIO INICIO FIN [ESTADO] < resultados TSV
 *        -> JSON con el resultado de una corrida (ultima.json o pedido-ID.json)
 *   php ayudante.php pedido SALIDA ID TIPO ESTADO MOTIVO [USUARIO]   -> pedido-ID.json sin resultados (en_curso / rechazado)
 */

$p = __DIR__ . '/Pedido.php';
require is_file($p) ? $p : dirname(__DIR__) . '/src/Pedido.php';

use Tukan\Pedido;

function escribir(string $salida, array $datos): void
{
    $tmp = $salida . '.tmp' . getmypid();
    if (file_put_contents($tmp, json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n") === false) {
        fwrite(STDERR, "ERROR: no se pudo escribir $salida\n");
        exit(2);
    }
    @chmod($tmp, 0640);
    if (!rename($tmp, $salida)) {
        @unlink($tmp);
        fwrite(STDERR, "ERROR: no se pudo escribir $salida\n");
        exit(2);
    }
}

function limpio(string $t, int $max = 300): string
{
    $t = (string) preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $t);
    if (!mb_check_encoding($t, 'UTF-8')) $t = mb_convert_encoding($t, 'UTF-8', 'ISO-8859-1');
    return mb_substr(trim($t), 0, $max);
}

/** Resultados TSV de actualizar.sh: programa, estado, antes, despues, mensaje */
function leer_resultados(): array
{
    $r = [];
    while (($l = fgets(STDIN)) !== false) {
        $c = explode("\t", rtrim($l, "\r\n"));
        if (count($c) < 4 || !Pedido::programaValido($c[0])) continue;
        $r[] = ['programa' => $c[0], 'estado' => limpio($c[1], 30), 'antes' => limpio($c[2], 40), 'despues' => limpio($c[3], 40), 'mensaje' => limpio($c[4] ?? '', 600)];
    }
    return $r;
}

$cmd = $argv[1] ?? '';

if ($cmd === 'validar') {
    [$archivo, $llaveArchivo, $usados] = [$argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''];
    $no = function (string $id, string $motivo): never {
        echo "NO\t$id\t$motivo\n";
        exit(1);
    };
    $contenido = is_file($archivo) && filesize($archivo) <= Pedido::MAX_BYTES ? (string) file_get_contents($archivo) : null;
    if ($contenido === null) $no('-', 'pedido ilegible o demasiado grande');
    try {
        $llave = Pedido::leerLlave($llaveArchivo);
    } catch (Throwable $e) {
        $no('-', 'el servidor no tiene la llave del actualizador (corre instalar_actualizador.sh)');
    }
    try {
        $d = Pedido::verificar($contenido, $llave);
    } catch (Throwable $e) {
        $m = $e->getMessage();
        $id = '-';
        if (str_starts_with($m, 'valido: ')) {   // firma buena: se puede anotar el rechazo con su id
            $j = json_decode($contenido, true);
            $dd = json_decode((string) base64_decode(strtr((string) ($j['datos'] ?? ''), '-_', '+/'), true), true);
            $id = is_array($dd) && is_string($dd['id'] ?? null) && Pedido::idValida($dd['id']) ? $dd['id'] : '-';
            $m = substr($m, 8);
        }
        $no($id, $m);
    }
    // De un solo uso: el id se anota antes de hacer nada
    if (!is_dir($usados) && !@mkdir($usados, 0700, true)) $no('-', "no se puede anotar el pedido en $usados");
    $f = @fopen("$usados/{$d['id']}", 'x');
    if ($f === false) $no('-', 'pedido repetido (ya se uso)');
    fwrite($f, (string) time());
    fclose($f);
    echo "OK\t{$d['id']}\t{$d['tipo']}\t" . str_replace("\t", ' ', $d['usuario']) . "\n";
    foreach ($d['objetivos'] as $prog => $commit) echo "$prog\t$commit\n";
    exit(0);
}

if ($cmd === 'versiones') {
    $salida = $argv[2] ?? '';
    $progs = [];
    while (($l = fgets(STDIN)) !== false) {
        $c = explode("\t", rtrim($l, "\r\n"));
        if (($c[0] ?? '') === 'P' && count($c) >= 15 && Pedido::programaValido($c[1])) {
            $inst = fn(int $i) => preg_match('/^[0-9a-f]{40}$/', $c[$i]) ? ['commit' => $c[$i], 'corto' => substr($c[$i], 0, 7), 'fecha' => limpio($c[$i + 1], 40), 'titulo' => limpio($c[$i + 2])] : null;
            $progs[$c[1]] = [
                'id' => $c[1], 'nombre' => limpio($c[2], 80), 'tipo' => limpio($c[3], 20), 'carpeta' => limpio($c[4], 200),
                'estado' => limpio($c[5], 20), 'mensaje' => limpio($c[6], 400),
                'instancias' => array_values(array_filter(explode(',', $c[7]), fn($x) => preg_match('/^[a-z][a-z0-9_]{1,30}$/', $x))),
                'instalada' => $inst(8), 'disponible' => $inst(11), 'nuevos' => (int) $c[14], 'cambios' => [],
            ];
        } elseif (($c[0] ?? '') === 'C' && count($c) >= 4 && isset($progs[$c[1]]) && count($progs[$c[1]]['cambios']) < 30) {
            $progs[$c[1]]['cambios'][] = ['corto' => limpio($c[2], 12), 'titulo' => limpio($c[3])];
        }
    }
    escribir($salida, ['revisado' => time(), 'servidor' => limpio((string) gethostname(), 80), 'programas' => array_values($progs)]);
    exit(0);
}

if ($cmd === 'resultado') {
    [$salida, $origen, $pedido, $usuario, $inicio, $fin] = array_pad(array_slice($argv, 2, 6), 6, '');
    $res = leer_resultados();
    $estado = $argv[8] ?? '';
    if ($estado === '') {
        $malos = array_filter($res, fn($x) => !in_array($x['estado'], ['ok', 'al_dia'], true));
        $estado = $malos ? 'fallo' : 'listo';
    }
    escribir($salida, [
        'id' => Pedido::idValida($pedido) ? $pedido : null, 'origen' => $origen === 'panel' ? 'panel' : 'consola', 'usuario' => limpio($usuario, 200),
        'estado' => $estado, 'inicio' => (int) $inicio, 'fin' => (int) $fin, 'programas' => $res,
    ]);
    exit(0);
}

if ($cmd === 'pedido') {
    [$salida, $id, $tipo, $estado, $motivo, $usuario] = array_pad(array_slice($argv, 2, 6), 6, '');
    if (!Pedido::idValida($id)) exit(2);
    escribir($salida, ['id' => $id, 'tipo' => limpio($tipo, 20), 'estado' => limpio($estado, 20), 'motivo' => limpio($motivo, 400),
                       'usuario' => limpio($usuario, 200), 'inicio' => time(), 'fin' => in_array($estado, ['en_curso'], true) ? 0 : time(), 'programas' => []]);
    exit(0);
}

fwrite(STDERR, "Uso: php ayudante.php validar|versiones|resultado|pedido ...\n");
exit(2);
