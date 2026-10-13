<?php
declare(strict_types=1);

namespace Tukan;

/**
 * Error de un pedido v2 a un programa (docs/PROTOCOLO_V2.md §3.2).
 * $codigo es el del protocolo (firma, inst, tipo, version, vencido, futuro, nonce, sin_configurar, limite,
 * no_encontrado, invalido, no_permitido, conflicto, interno) o uno del panel cuando no hubo respuesta del
 * protocolo: dns, sin_conexion, tiempo, tls, no_conectado, redirige, formato, v1, secreto_ilegible.
 * getMessage() es el texto en palabras simples para mostrar a Dimitry.
 */
final class ErrorPrograma extends \RuntimeException
{
    /** Códigos que puede mandar un programa (nunca otro). */
    public const CODIGOS = ['firma', 'inst', 'tipo', 'version', 'vencido', 'futuro', 'nonce', 'sin_configurar', 'limite',
                            'no_encontrado', 'invalido', 'no_permitido', 'conflicto', 'interno'];

    /** Lo que le dice el Central a Dimitry (§3.2). Para invalido / no_permitido / conflicto se muestra el texto del programa. */
    public const TEXTOS = [
        'firma'          => 'Secreto incorrecto: toca «Cambiar secreto» y pega el comando nuevo en el servidor del programa.',
        'inst'           => 'El ID no coincide con el que tiene guardado el programa.',
        'tipo'           => 'Error del panel (pedido mal armado). Avísale al programador.',
        'version'        => 'El programa usa otra versión del protocolo. Actualiza ese programa.',
        'vencido'        => 'Reloj desfasado o red muy lenta. Intenta otra vez; si sigue, revisa la hora del servidor.',
        'futuro'         => 'Reloj desfasado: la hora de uno de los servidores está mal.',
        'nonce'          => 'El programa dice que el pedido se repitió. Intenta otra vez.',
        'sin_configurar' => 'Programa sin conectar: pega el comando en su servidor.',
        'limite'         => 'Demasiados pedidos seguidos: espera un minuto.',
        'no_encontrado'  => 'Ya no existe en el programa (quizás lo borraron). Actualiza la lista.',
        'invalido'       => 'El programa no aceptó los datos.',
        'no_permitido'   => 'El programa no permite eso.',
        'conflicto'      => 'Alguien ya lo atendió o cambió. Actualiza y revisa.',
        'interno'        => 'El programa tuvo un error interno. Intenta en un momento.',
        'v1'             => 'Este programa todavía usa la versión 1 del protocolo: actualízalo para ver esto desde el panel.',
    ];

    public function __construct(
        public readonly string $codigo,
        string $texto,
        public readonly int $http = 0,
        public readonly ?string $campo = null,
        public readonly array $detalle = [],
        public readonly string $textoPrograma = '',
    ) {
        parent::__construct($texto);
    }

    /** ¿El pedido pudo haber llegado y hecho algo? (no hubo respuesta: se puede "Revisar" con la misma operación) */
    public function sinRespuesta(): bool
    {
        // "interno": el programa borra la operación (§7.1 paso 3), así que reintentar con la misma clave es seguro
        return in_array($this->codigo, ['tiempo', 'sin_conexion', 'interno'], true);
    }

    /** ¿Es un programa que todavía habla v1? */
    public function esV1(): bool
    {
        return $this->codigo === 'v1';
    }

    /** Campo del formulario al que apunta el error ("datos.monto" -> "monto"). */
    public function campoFormulario(): ?string
    {
        if ($this->campo === null) return null;
        return preg_replace('/^datos\.(extra\.)?/', '', $this->campo);
    }

    /** Arma el error a partir de la respuesta HTTP (sirve también para las pruebas sin red). */
    public static function desdeRespuesta(int $http, string $cuerpo, int $errno = 0, string $error = '', ?int $desfase = null): self
    {
        if ($errno !== 0 || $http === 0) {
            [$c, $t] = Estado::clasificar($http, $cuerpo, $errno, $error);
            return new self($c, $t, $http);
        }
        $j = json_decode($cuerpo, true);
        if (is_array($j) && is_string($j['codigo'] ?? null) && in_array($j['codigo'], self::CODIGOS, true)) {
            $c = $j['codigo'];
            $suyo = is_string($j['error'] ?? null) ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $j['error']) ?? ''), 0, 300) : '';
            $campo = is_string($j['campo'] ?? null) && preg_match('/^[A-Za-z0-9_.\[\]-]{1,80}$/', $j['campo']) ? $j['campo'] : null;
            $detalle = is_array($j['detalle'] ?? null) ? $j['detalle'] : [];
            $texto = in_array($c, ['invalido', 'no_permitido', 'conflicto'], true) && $suyo !== '' ? $suyo : self::TEXTOS[$c];
            if (in_array($c, ['vencido', 'futuro'], true) && $desfase !== null && abs($desfase) > 30) $texto .= ' Diferencia: ' . Estado::segundos($desfase) . '.';
            return new self($c, $texto, $http, $campo, $detalle, $suyo);
        }
        // Sin "codigo": respuesta de un conector v1 o de algo que no es el conector
        if (in_array($http, [400, 405], true)) return new self('v1', self::TEXTOS['v1'], $http);
        if ($http === 404) return new self('no_conectado', Estado::ERRORES['no_conectado'], $http);
        if ($http === 429) return new self('limite', self::TEXTOS['limite'], $http);
        if ($http >= 300 && $http < 400) return new self('redirige', Estado::ERRORES['redirige'], $http);
        if ($http >= 500) return new self('interno', self::TEXTOS['interno'] . " (HTTP $http)", $http);
        if ($http === 200) return new self('formato', Estado::ERRORES['formato'], $http);
        [$c, $t] = Estado::clasificar($http, $cuerpo, 0, '', $desfase);
        return new self($c, $t, $http);
    }
}
