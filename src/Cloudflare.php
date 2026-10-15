<?php
declare(strict_types=1);

namespace Tukan;

/**
 * Cloudflare (opcional): al borrar una instalación, quita su dirección del túnel (public hostname) y su registro DNS.
 * El token se guarda CIFRADO en Ajustes (con la cuenta y el túnel). Permisos del token: Cloudflare Tunnel Edit y DNS Edit
 * (y Zone Read para encontrar la zona). Solo toca la dirección EXACTA que se le pide:
 *  1. lee la configuración del túnel, 2. quita solo las reglas con ese hostname, 3. la vuelve a guardar, 4. la lee otra vez
 *     para verificar, 5. borra el CNAME de esa dirección solo si apunta a ESTE túnel (<tunel>.cfargotunnel.com) y verifica.
 * La dirección de la API se puede cambiar con CLOUDFLARE_API_BASE (las pruebas usan un servidor de mentira).
 */
final class Cloudflare
{
    public const API = 'https://api.cloudflare.com/client/v4';

    /** @var callable|null fn(string $metodo, string $url, array $cabeceras, ?string $cuerpo): array{http:int,cuerpo:string,errno:int,error:string} */
    private $transporte;
    private string $base;

    public function __construct(private string $token, private string $cuenta, private string $tunel, ?callable $transporte = null, ?string $base = null)
    {
        $this->transporte = $transporte;
        $this->base = rtrim($base ?? ((string) getenv('CLOUDFLARE_API_BASE') ?: self::API), '/');
    }

    public static function cuentaValida(string $c): bool
    {
        return (bool) preg_match('/^[0-9a-f]{32}$/', $c);
    }

    public static function tunelValido(string $t): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $t);
    }

    public static function tokenValido(string $t): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{30,200}$/', $t);
    }

    public static function hostValido(string $h): bool
    {
        return strlen($h) <= 253 && (bool) preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $h);
    }

    public static function configurado(Ajustes $a): bool
    {
        return $a->valor('cf_token_cif') !== '' && self::cuentaValida($a->valor('cf_cuenta')) && self::tunelValido($a->valor('cf_tunel'));
    }

    /** Con lo guardado en Ajustes, o null si no está configurado. */
    public static function desdeAjustes(Ajustes $a, ?Cifrado $cif = null, ?callable $transporte = null): ?self
    {
        if (!self::configurado($a)) return null;
        $token = ($cif ?? new Cifrado())->descifrar($a->valor('cf_token_cif'), 'cloudflare:token');
        return new self($token, $a->valor('cf_cuenta'), $a->valor('cf_tunel'), $transporte);
    }

    public static function guardar(Ajustes $a, Cifrado $cif, string $token, string $cuenta, string $tunel): void
    {
        if ($token !== '') $a->guardar('cf_token_cif', $cif->cifrar($token, 'cloudflare:token'));
        $a->guardar('cf_cuenta', $cuenta);
        $a->guardar('cf_tunel', $tunel);
    }

    public static function borrarConfig(Ajustes $a): void
    {
        foreach (['cf_token_cif', 'cf_cuenta', 'cf_tunel'] as $k) $a->guardar($k, '');
    }

    /** Pide a la API. Devuelve "result" o lanza \RuntimeException con un texto simple. */
    private function pedir(string $metodo, string $ruta, ?array $cuerpo = null): mixed
    {
        $json = $cuerpo === null ? null : json_encode($cuerpo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $cab = ['Authorization: Bearer ' . $this->token, 'Accept: application/json'];
        if ($json !== null) $cab[] = 'Content-Type: application/json';
        $r = ($this->transporte ?? [self::class, 'curl'])($metodo, $this->base . $ruta, $cab, $json);
        if ($r['errno'] !== 0 || $r['http'] === 0) throw new \RuntimeException('Cloudflare no respondió (' . ($r['error'] ?: 'sin conexión') . ').');
        $d = json_decode($r['cuerpo'], true);
        if (!is_array($d)) throw new \RuntimeException("Cloudflare respondió algo que no se entiende (HTTP {$r['http']}).");
        if (($d['success'] ?? false) !== true) {
            $m = is_array($d['errors'][0] ?? null) ? (string) ($d['errors'][0]['message'] ?? '') : '';
            $txt = match (true) {
                in_array($r['http'], [401, 403], true) => 'El token de Cloudflare no sirve o no tiene permiso (necesita Cloudflare Tunnel Edit y DNS Edit).',
                $r['http'] === 404 => 'Cloudflare no encontró la cuenta o el túnel guardados en Ajustes.',
                default => 'Cloudflare no aceptó el cambio.',
            };
            throw new \RuntimeException($txt . ($m !== '' ? ' (' . mb_substr(preg_replace('/[\x00-\x1F]+/', ' ', $m) ?? '', 0, 150) . ')' : ''));
        }
        return $d['result'] ?? null;
    }

    /** Prueba el token: lee el túnel. Devuelve su nombre. */
    public function probar(): string
    {
        $t = $this->pedir('GET', "/accounts/{$this->cuenta}/cfd_tunnel/{$this->tunel}");
        return is_array($t) && is_string($t['name'] ?? null) ? mb_substr($t['name'], 0, 80) : $this->tunel;
    }

    private function leerConfig(): array
    {
        $r = $this->pedir('GET', "/accounts/{$this->cuenta}/cfd_tunnel/{$this->tunel}/configurations");
        $config = is_array($r) ? ($r['config'] ?? null) : null;
        if (!is_array($config) || !is_array($config['ingress'] ?? null) || !array_is_list($config['ingress']) || $config['ingress'] === []) {
            throw new \RuntimeException('El túnel no tiene rutas que se puedan editar desde la nube (¿es un túnel administrado con archivo local?). Quita la dirección a mano.');
        }
        return $config;
    }

    private static function hosts(array $ingress): array
    {
        return array_map(fn($r) => is_array($r) && is_string($r['hostname'] ?? null) ? strtolower($r['hostname']) : null, $ingress);
    }

    /**
     * Quita la dirección del túnel y su CNAME. Nunca lanza: devuelve el resultado de cada parte.
     * @return array{ok:bool, tunel:string, dns:string, mensajes:list<string>}
     */
    public function quitarHostname(string $host): array
    {
        $host = strtolower(trim($host));
        $res = ['ok' => false, 'tunel' => 'error', 'dns' => 'error', 'mensajes' => []];
        if (!self::hostValido($host)) {
            $res['mensajes'][] = "La dirección «{$host}» no es válida: no se tocó nada en Cloudflare.";
            return $res;
        }
        // 1-4. Túnel
        try {
            $config = $this->leerConfig();
            $antes = $config['ingress'];
            $nuevas = array_values(array_filter($antes, fn($r) => !(is_array($r) && is_string($r['hostname'] ?? null) && strtolower($r['hostname']) === $host)));
            $quitadas = count($antes) - count($nuevas);
            if ($quitadas === 0) {
                $res['tunel'] = 'no_estaba';
                $res['mensajes'][] = "$host ya no estaba en el túnel.";
            } else {
                $ultima = end($nuevas);
                if ($nuevas === [] || !is_array($ultima) || isset($ultima['hostname'])) throw new \RuntimeException('La configuración del túnel quedaría sin la regla final: no se tocó.');
                $config['ingress'] = $nuevas;
                $this->pedir('PUT', "/accounts/{$this->cuenta}/cfd_tunnel/{$this->tunel}/configurations", ['config' => $config]);
                $ver = $this->leerConfig()['ingress'];
                if (in_array($host, self::hosts($ver), true) || count($ver) !== count($nuevas)) {
                    throw new \RuntimeException('Se guardó, pero al revisar el túnel la dirección sigue ahí o cambió otra cosa. Revísalo a mano.');
                }
                $res['tunel'] = 'quitado';
                $res['mensajes'][] = "Se quitó $host del túnel (" . ($quitadas === 1 ? '1 ruta' : "$quitadas rutas") . ').';
            }
        } catch (\Throwable $e) {
            $res['tunel'] = 'error';
            $res['mensajes'][] = 'Túnel: ' . $e->getMessage();
        }
        // 5. DNS
        try {
            $zona = $this->zona($host);
            if ($zona === null) {
                $res['dns'] = 'no_estaba';
                $res['mensajes'][] = "No se encontró la zona de $host en Cloudflare: no hay DNS que borrar.";
            } else {
                $recs = $this->registros($zona, $host);
                $destino = strtolower($this->tunel) . '.cfargotunnel.com';
                $mios = array_filter($recs, fn($r) => strtolower(rtrim((string) ($r['content'] ?? ''), '.')) === $destino);
                if ($recs === []) {
                    $res['dns'] = 'no_estaba';
                    $res['mensajes'][] = "$host no tenía registro DNS.";
                } elseif ($mios === []) {
                    $res['dns'] = 'otro_destino';
                    $res['mensajes'][] = "El DNS de $host apunta a otro lado (no a este túnel): no se tocó.";
                } else {
                    foreach ($mios as $r) {
                        if (!is_string($r['id'] ?? null) || !preg_match('/^[0-9a-f]{32}$/', $r['id'])) throw new \RuntimeException('Registro DNS con un id raro: no se tocó.');
                        $this->pedir('DELETE', "/zones/$zona/dns_records/{$r['id']}");
                    }
                    $quedan = array_filter($this->registros($zona, $host), fn($r) => strtolower(rtrim((string) ($r['content'] ?? ''), '.')) === $destino);
                    if ($quedan !== []) throw new \RuntimeException('Se pidió borrar el DNS pero sigue ahí. Revísalo a mano.');
                    $res['dns'] = 'quitado';
                    $res['mensajes'][] = "Se borró el DNS de $host.";
                }
            }
        } catch (\Throwable $e) {
            $res['dns'] = 'error';
            $res['mensajes'][] = 'DNS: ' . $e->getMessage();
        }
        $res['ok'] = $res['tunel'] !== 'error' && $res['dns'] !== 'error';
        return $res;
    }

    /** Id de la zona de la dirección (prueba "b.c.com", luego "c.com"), o null. */
    private function zona(string $host): ?string
    {
        $partes = explode('.', $host);
        for ($i = 1; $i <= count($partes) - 2; $i++) {
            $nombre = implode('.', array_slice($partes, $i));
            $r = $this->pedir('GET', '/zones?' . http_build_query(['name' => $nombre, 'per_page' => 5]));
            foreach (is_array($r) ? $r : [] as $z) {
                if (is_array($z) && ($z['name'] ?? null) === $nombre && is_string($z['id'] ?? null) && preg_match('/^[0-9a-f]{32}$/', $z['id'])) return $z['id'];
            }
        }
        return null;
    }

    private function registros(string $zona, string $host): array
    {
        $r = $this->pedir('GET', "/zones/$zona/dns_records?" . http_build_query(['type' => 'CNAME', 'name' => $host, 'per_page' => 20]));
        return array_values(array_filter(is_array($r) ? $r : [], fn($x) => is_array($x) && strtolower((string) ($x['name'] ?? '')) === $host));
    }

    /** Transporte real (curl). */
    public static function curl(string $metodo, string $url, array $cabeceras, ?string $cuerpo): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $cabeceras,
            CURLOPT_USERAGENT => 'TukanCentral/2',
            CURLOPT_NOPROXY => 'localhost,127.0.0.1,::1',
        ]);
        if ($cuerpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
        $out = curl_exec($ch);
        $errno = curl_errno($ch);
        $r = ['http' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'cuerpo' => is_string($out) ? substr($out, 0, 2 * 1024 * 1024) : '',
              'errno' => $errno, 'error' => $errno ? (curl_strerror($errno) ?: '') : ''];
        curl_close($ch);
        return $r;
    }
}
