# IA de Tukán (`tukan_ia.php`): cómo la conecta cada programa

Una sola librería, **un solo archivo PHP sin composer** (`compartido/tukan_ia.php`), para el módulo extra
**«Asistente IA»** de los 12 programas Tukán y para la **Ayuda** («¿cómo hago…?») con el manual de cada programa.
Esta guía es para el agente que conecta UN programa. Regla de oro: **el gasto está controlado y el cliente nunca se entera**.

- Cada respuesta de la Ayuda se guarda como **pregunta frecuente (FAQ)**: si alguien pregunta lo mismo o algo parecido, se
  responde desde lo guardado **sin llamar a la IA** (costo 0).
- **Límite silencioso**: si el negocio llegó a su tope del mes, si el programa llegó al suyo o si Tukán pausó todo, la librería
  **no llama** a la API y devuelve `['ok' => false, 'motivo' => 'tope' | 'tope_programa' | 'pausa' | 'sin_clave']`. Nunca se
  muestra «límite», «cupo», «saldo» ni «no hay crédito»: la Ayuda sigue con FAQ + manual y las fotos dicen «puede hacerlo a mano».
- Dimitry ve el gasto en el **Panel Tukán Central → Más → IA** (acción v2 `ia_uso`, `docs/PROTOCOLO_V2.md` Anexo C).

## 1. El servidor (una sola vez, lo hace Dimitry desde el Panel)

```
cd /var/www/tukan-central && git pull && bash deploy/configurar_ia.sh
```

Pide la clave de Anthropic (no se ve al escribir), los modelos (`claude-haiku-5-5` rápido, `claude-sonnet-5-5` fuerte) y si
queda en pausa. Escribe **`/etc/tukan-ia.env`** (`root:www-data`, `640`) y hace una llamada de prueba: **«IA: FUNCIONA»** o el
error claro (clave inválida, sin saldo en la cuenta, sin conexión). Todos los programas leen ese archivo: **no copies la clave**
a cada programa. Otras órdenes:

| Comando | Qué hace |
|---|---|
| `bash deploy/configurar_ia.sh --pausar` | `TUKAN_IA_PAUSA=1`: corta TODO el gasto de todos los programas al instante |
| `bash deploy/configurar_ia.sh --reanudar` | la vuelve a encender |
| `bash deploy/configurar_ia.sh --tope tuevento 20` | tope del mes del programa en USD (`TUKAN_IA_TOPE_PROGRAMA_TUEVENTO=20`); `quitar` lo borra |
| `bash deploy/configurar_ia.sh --probar` | solo la llamada de prueba |
| `bash deploy/configurar_ia.sh --usar-clave-tuhospedaje` | reusa la `ANTHROPIC_API_KEY` de `/var/www/tuhospedaje/config/.env` (la lee con sudo, no la muestra). Sin la opción, si no hay clave, la ofrece: «usarla? [S/n]» |
| `sudo -u www-data php /var/www/tukan-central/compartido/tukan_ia.php probar` | prueba como el usuario de PHP |

> Si el programa corre con otro usuario (no `www-data`), ese usuario tiene que poder leer `/etc/tukan-ia.env` (agrégalo al
> grupo `www-data`); si no, para la librería no hay clave y todo queda en «sin IA» (la Ayuda sigue con FAQ + manual).

**Compatibilidad (TuHospedaje):** si no hay `TUKAN_IA_CLAVE`, la librería acepta `ANTHROPIC_API_KEY` del entorno o del `.env`
del programa: `tukan_ia_env_programa(__DIR__ . '/../config/.env');` (lee también `IA_MODELO_RAPIDO`, `IA_MODELO_FUERTE` y
`ANTHROPIC_API_BASE`). Lo de `/etc/tukan-ia.env` gana. La pausa global funciona igual con la clave del programa.

## 2. Copiar la librería y crear las tablas

Copia **sin cambiar nada** `compartido/tukan_ia.php` a la carpeta de código del programa (`src/`, `app/`, `lib/`…, fuera de la
carpeta pública). En la cabecera dice la versión (`TUKAN_IA_VERSION`): si sale una nueva, se vuelve a copiar.

```php
require_once __DIR__ . '/tukan_ia.php';
```

Dos tablas en la base del programa. Con PDO basta `tukan_ia_crear_tablas($pdo);` (detecta PostgreSQL, MySQL o SQLite) en la
migración / instalador. El SQL, por si se prefiere a mano (`tukan_ia_sql('pgsql' | 'mysql' | 'sqlite')` lo devuelve):

**PostgreSQL**

```sql
CREATE TABLE IF NOT EXISTS tukan_ia_faq (
  id BIGSERIAL PRIMARY KEY,
  pregunta VARCHAR(500) NOT NULL,
  pregunta_normalizada VARCHAR(500) NOT NULL,
  respuesta TEXT NOT NULL,
  usos INTEGER NOT NULL DEFAULT 0,
  origen VARCHAR(12) NOT NULL DEFAULT 'automatica',   -- 'automatica' (la escribió la IA) o 'manual' (una persona)
  aprobada SMALLINT NOT NULL DEFAULT 0,                -- 1 = revisada por el súper usuario
  fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS tukan_ia_faq_norm ON tukan_ia_faq (pregunta_normalizada);

CREATE TABLE IF NOT EXISTS tukan_ia_gasto (
  negocio_id VARCHAR(64) NOT NULL,                    -- el id del negocio (cliente) en este programa
  mes CHAR(7) NOT NULL,                               -- '2026-10'
  costo_usd DECIMAL(12,6) NOT NULL DEFAULT 0,
  llamadas INTEGER NOT NULL DEFAULT 0,                -- llamadas a la IA que salieron bien
  fallidas INTEGER NOT NULL DEFAULT 0,
  tokens_entrada BIGINT NOT NULL DEFAULT 0,
  tokens_salida BIGINT NOT NULL DEFAULT 0,
  ahorradas INTEGER NOT NULL DEFAULT 0,               -- respuestas desde una FAQ o el manual, sin llamar a la IA
  ayudas_nuevas INTEGER NOT NULL DEFAULT 0,           -- respuestas NUEVAS de la ayuda hechas con IA
  actualizado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (negocio_id, mes)
);
```

**MySQL / MariaDB**

```sql
CREATE TABLE IF NOT EXISTS tukan_ia_faq (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pregunta VARCHAR(500) NOT NULL,
  pregunta_normalizada VARCHAR(500) NOT NULL,
  respuesta MEDIUMTEXT NOT NULL,
  usos INTEGER NOT NULL DEFAULT 0,
  origen VARCHAR(12) NOT NULL DEFAULT 'automatica',   -- 'automatica' (la escribió la IA) o 'manual' (una persona)
  aprobada SMALLINT NOT NULL DEFAULT 0,                -- 1 = revisada por el súper usuario
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX tukan_ia_faq_norm ON tukan_ia_faq (pregunta_normalizada(191));

CREATE TABLE IF NOT EXISTS tukan_ia_gasto (
  negocio_id VARCHAR(64) NOT NULL,                    -- el id del negocio (cliente) en este programa
  mes CHAR(7) NOT NULL,                               -- '2026-10'
  costo_usd DECIMAL(12,6) NOT NULL DEFAULT 0,
  llamadas INTEGER NOT NULL DEFAULT 0,                -- llamadas a la IA que salieron bien
  fallidas INTEGER NOT NULL DEFAULT 0,
  tokens_entrada BIGINT NOT NULL DEFAULT 0,
  tokens_salida BIGINT NOT NULL DEFAULT 0,
  ahorradas INTEGER NOT NULL DEFAULT 0,               -- respuestas desde una FAQ o el manual, sin llamar a la IA
  ayudas_nuevas INTEGER NOT NULL DEFAULT 0,           -- respuestas NUEVAS de la ayuda hechas con IA
  actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (negocio_id, mes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- `tukan_ia_faq` es **del programa** (las mismas FAQ para todos sus negocios: cada respuesta sirve para todos).
- `tukan_ia_gasto` tiene una fila por negocio y mes. En TuGuardería (una sola empresa) `negocio_id = 'unico'`.
- Si el programa ya usa prefijos (`tuevento_…`), las clases aceptan otro nombre de tabla (último parámetro).

## 3. El guardián por negocio (gasto y tope)

El programa le pasa a cada llamada un **guardián** que sabe cuánto lleva gastado **ese** negocio este mes y cuánto puede gastar.

```php
$tope = $negocio['ia_tope_usd'] ?? null;               // lo guarda el programa (ver 3.1); null = sin tope propio
$g = new TukanIaGuardianPdo($pdo, (string) $negocio['id'], $tope !== null ? (float) $tope : null);
```

O con funciones, si el programa ya tiene su propia tabla:

```php
$g = ['gasto' => fn(): float => …, 'tope' => fn(): ?float => …, 'anotar' => function (array $uso): void { … },
      'gasto_programa' => fn(): ?float => …, 'ayudas_nuevas' => fn(): int => …, 'ahorro' => function (string $funcion): void { … }];
```

`$uso` trae `funcion`, `modelo`, `tokens_entrada`, `tokens_salida`, `costo` (USD), `ok` y `ayuda_nueva`. Si leer el gasto
falla, la librería **no gasta**. Si hay tope de programa en `/etc/tukan-ia.env` y el guardián no sabe el gasto del programa
(`gasto_programa`), **no gasta** (`TukanIaGuardianPdo` ya lo sabe: suma la tabla).

### 3.1 Topes por negocio (sugeridos)

| Negocio | Tope del mes (`topeMes`) | Respuestas NUEVAS de la Ayuda con IA (`max_nuevas_mes`) | Foto / audio |
|---|---|---|---|
| **Sin** el módulo `asistente_ia` | `0.50` | **30** | no (se hace a mano) |
| **Con** el módulo `asistente_ia` | el que se cotizó (por defecto `3.00`) | `null` (sin tope de cantidad; vale el tope en USD) | sí |

Guárdalo como ajuste del negocio (columna `ia_tope_usd` o tabla de ajustes). Para que Dimitry lo cambie desde el Panel, ofrece
la operación `ejecutar ia_tope` (`datos: {"tope_usd": número | null}`) en `cliente.acciones_posibles` con un campo `tope_usd`
tipo `dinero` (Anexo C.2): el Panel lo muestra con su formulario genérico.

## 4. Llamar a la IA (funciones del módulo «Asistente IA»)

```php
$r = tukan_ia_llamar([
    'sistema' => 'Lees fotos de facturas de Ecuador. Devuelve ruc, fecha (AAAA-MM-DD) y total.',
    'texto' => 'Lee esta factura.',
    'imagenes' => [['bytes' => file_get_contents($_FILES['foto']['tmp_name'])]],   // o ['base64' => …]
    'modelo' => 'fuerte',            // 'rapido' (por defecto) | 'fuerte'
    'json' => true,                  // pide SOLO JSON y lo lee; si no sirve, reintenta una vez
    'validar' => fn(array $j) => isset($j['total']) && is_numeric($j['total']) ? $j : null,
    'max_tokens' => 400,
], ['guardian' => $g, 'programa' => 'tuevento', 'funcion' => 'foto_factura']);

if ($r['ok']) {
    $datos = $r['json'];             // ya validado · $r['costo'], $r['tokens_entrada'], $r['tokens_salida'], $r['modelo']
} else {
    $aviso = tukan_ia_mensaje_neutro('foto', url('gasto_nuevo'));   // SIEMPRE este mensaje, sea cual sea $r['motivo']
    error_log('IA: ' . $r['motivo'] . ' ' . $r['error']);           // $r['error'] nunca trae la clave
}
```

| Función | Qué hace |
|---|---|
| `tukan_ia_llamar(array $pedido, array $opciones = []): array` | La llamada (texto, imágenes JPG/PNG/WEBP/GIF o PDF hasta 5 MB, JSON validado). Reintenta 429 / 529 / 5xx / red con espera (2 veces, respeta `retry-after`). Anota el gasto con el guardián |
| `tukan_ia_mensaje_neutro(string $tipo = 'foto', string $aMano = ''): string` | El mensaje para el cliente (`foto`, `audio`, `texto`) |
| `tukan_ia_sin_gasto(array $r): bool` | `true` si no se llamó por tope, pausa o falta de clave |
| `tukan_ia_activa(): bool` · `tukan_ia_estado(): array` | ¿Hay clave y no está en pausa? · estado para mostrar (**sin** la clave) |
| `tukan_ia_costo(string $modelo, int $entrada, int $salida): float` | USD estimados (tabla por millón: Haiku 5.5 $0,10 / $0,50, más de 100 mil de entrada $0,50 / $2,50; Sonnet 5.5 $2 / $10; cambiar con `TUKAN_IA_PRECIOS`) |
| `tukan_ia_datos(string $etiqueta, string $texto): string` | Texto de otra persona envuelto como DATO (`<correo>…</correo>`), para que la IA no siga instrucciones de adentro |
| `tukan_ia_leer_json(string $texto): ?array` | Saca el JSON de una respuesta |

`$r['motivo']` cuando `ok` es `false`: `tope`, `tope_programa`, `pausa`, `sin_clave` (no se llamó, costo 0), `clave` (401/403),
`saldo` (la cuenta de Anthropic sin créditos), `ocupado` (429/529 después de reintentar), `red`, `pedido` (400/404),
`respuesta` (JSON que no sirvió dos veces), `datos` (foto mala o vacía). **Al cliente siempre el mismo mensaje neutro.**

**Audio:** la API de Anthropic no recibe audio. Hasta tener transcripción, las pantallas de audio usan
`tukan_ia_mensaje_neutro('audio', …)` y la carga a mano.

### 4.1 Mensajes neutros exactos (copiar tal cual)

| Dónde | Texto |
|---|---|
| Foto (leer documento, factura, cédula…) | «En este momento no pudimos procesar la foto automáticamente; puede hacerlo a mano aquí: {enlace}.» |
| Audio | «En este momento no pudimos procesar el audio automáticamente; puede hacerlo a mano aquí: {enlace}.» |
| Texto (resumir, redactar…) | «En este momento no pudimos procesar el texto automáticamente; puede hacerlo a mano aquí: {enlace}.» |
| Sin enlace | «… puede hacerlo a mano en esta misma pantalla.» |
| Ayuda sin respuesta en el manual | «No encontramos eso en el manual de {Programa}. Por favor, escríbanos por WhatsApp 099 640 8397 y le ayudamos.» (lo pone la librería) |

**Prohibido** en todo lo que ve el cliente: «límite», «cupo», «saldo», «crédito», «se acabó», «pasó el tope», «IA apagada».
Lo que ya dice otra cosa (por ejemplo TuHospedaje: «Ya se usó todo el cupo») se cambia por estos textos.

## 5. La pantalla «Ayuda» (todos los planes)

1. **El texto del manual.** De `docs/MANUAL.md` tal cual, o del manual en HTML convertido:
   ```php
   $manual = is_file($md = __DIR__ . '/../docs/MANUAL.md') ? file_get_contents($md)
           : tukan_ia_html_a_texto(file_get_contents(__DIR__ . '/../public/manual.html'));
   ```
   Con encabezados `#`, `##`, `###` la librería parte el manual en secciones y manda a la IA solo las 3 a 5 que más coinciden.
2. **El cuadro de pregunta.** En el menú del negocio: **Ayuda** (ícono de pregunta). Título «¿En qué le ayudamos?»,
   un campo «Escriba su pregunta» (máximo 500 letras, ejemplo en gris: «¿Cómo registro un pago?») y el botón **Preguntar**.
   POST con CSRF; máximo 10 preguntas por minuto por usuario.
   ```php
   $faq = new TukanIaFaqPdo($pdo);
   $a = tukan_ia_ayuda($pregunta, $manual, $faq, [
       'guardian' => $g, 'programa' => 'tuevento', 'nombre' => 'TuEvento',
       'max_nuevas_mes' => $tieneModulo ? null : 30,   // sin el módulo: 30 respuestas nuevas al mes (cada una queda para todos)
   ]);
   echo nl2br(e($a['respuesta']));   // la respuesta puede traer **negritas** y pasos 1. 2. 3.
   ```
   `$a`: `respuesta`, `fuente` (`faq` | `ia` | `manual` | `vacia`), `faq_id`, `costo`, `secciones`, `similitud`.
   No muestres la `fuente` al cliente. Debajo de la respuesta: «¿Le sirvió? Si no, escríbanos por WhatsApp 099 640 8397».
3. **Orden que sigue la librería**: normaliza (minúsculas, sin tildes, signos ni palabras vacías, verbos iguales: «hago»,
   «creo», «emito» = crear) → FAQ exacta → FAQ parecida (palabras en común, Dice ≥ 0,75, sin servicios externos) → IA con el
   modelo rápido y las secciones del manual (y se guarda como FAQ `automatica`, sin aprobar) → sin poder gastar: las secciones
   del manual que más coinciden (primeras líneas) + el WhatsApp.
4. **La AYUDA es para todos los planes**, con o sin el módulo. Las funciones de **foto y audio** necesitan el módulo
   `asistente_ia` encendido (si no, ni se muestran los botones).

## 6. La pantalla «Preguntas frecuentes» del súper usuario

En el panel del súper (no del negocio): **Ayuda → Preguntas frecuentes**. Lista con filtro *Por revisar* (las automáticas sin
aprobar primero), columnas Pregunta, Respuesta (recortada), Usos, Origen (Automática / Escrita a mano), y por fila
**Aprobar**, **Editar** (pregunta y respuesta; al guardar queda aprobada y «manual») y **Borrar** (con confirmación).
Arriba **Agregar pregunta** (queda aprobada). Todo con CSRF y en la auditoría del programa.

```php
$faq->listar('sin_aprobar');           // '' | 'sin_aprobar' | 'automatica' | 'manual'
$faq->aprobar($id);  $faq->editar($id, $pregunta, $respuesta);  $faq->borrar($id);  $faq->agregar($pregunta, $respuesta);
$faq->resumen();                       // total, automaticas, manuales, sin_aprobar, usos
```

Una FAQ mala (por ejemplo «No lo encuentro en el manual…») se **edita** con la respuesta buena: desde ahí sale para todos.

## 7. El módulo «Asistente IA» en el catálogo (Anexo B)

Agregar al catálogo de módulos del programa:

| Campo | Valor |
|---|---|
| `id` | `asistente_ia` |
| `nombre` | Asistente IA |
| `icono` | `chispa` (o el que use el programa para «inteligente») |
| `descripcion` | «Lee fotos de documentos y llena los datos por usted, y responde las dudas al instante.» |
| `precio_mensual` | `0` en el catálogo: **por cotización**. El precio que se acuerde va como `precio_acordado` del cliente |
| `grupo` | Extras |

- **Sin precio visible para el cliente**: en «Mi plan» el módulo sale como «Pídalo y le cotizamos» (crea la solicitud
  `cambio_modulos` de siempre), nunca con un precio ni con «gratis».
- No está en ningún plan base. Apagarlo no borra nada (las FAQ siguen sirviendo a todos).
- Con el módulo encendido sube el tope del negocio (3.1).

## 8. `ia_uso` en el conector v2 (`tukan_central.php`)

1. Agregar `'ia_uso'` a `capacidades.acciones`.
2. La acción (solo lectura, cuerpo `{"mes": "2026-10"}`; otro campo → `422 invalido`):
   ```php
   case 'ia_uso':
       $mes = $datos['mes'] ?? date('Y-m');
       if (!is_string($mes) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) fallo('invalido', 'Mes no válido.', 422, 'mes');
       $nombres = function (array $ids) use ($pdo): array {   // id => nombre, tope y si tiene el módulo
           // SELECT id, nombre, ia_tope_usd FROM negocios WHERE id IN (…) + modulo_activo($id, 'asistente_ia')
       };
       return $base + tukan_ia_uso_pdo($pdo, $mes, $nombres, 'tukan_ia_gasto', new TukanIaFaqPdo($pdo), 'tuevento');
   ```
3. Pruebas del conector: `ia_uso` sin datos (listas vacías), con dos negocios (orden por gasto), mes inválido (`422`), campo
   desconocido (`422`) y que la respuesta **no** trae la clave. Después, en el Panel: **Probar conexión** y abrir **Más → IA**.

## 9. Pruebas que debe tener cada programa

- Ayuda: pregunta nueva (IA de mentira), la misma pregunta otra vez (FAQ, sin llamar), una parecida, con `TUKAN_IA_PAUSA=1` y con
  el negocio en su tope (solo manual), y que **ninguna** respuesta diga «límite», «cupo» ni «saldo».
- Foto: con IA, con el negocio en su tope y en pausa (mensaje neutro + enlace a mano), foto que no es imagen.
- Pantalla de FAQ del súper: aprobar, editar, borrar, agregar; el negocio no puede entrar.
- Para probar sin gastar: `tests/anthropic_falso.py` del Panel (`python3 anthropic_falso.py 8256 registro.jsonl`, clave
  `sk-ant-prueba-buena-0123456789`) y `TUKAN_IA_URL=http://127.0.0.1:8256` + `TUKAN_IA_ESPERA_MS=0` en un `TUKAN_IA_ENV` de prueba.
