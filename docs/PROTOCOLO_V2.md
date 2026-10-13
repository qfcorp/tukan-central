# Protocolo Tukán Central v2

Amplía el [Protocolo v1](PROTOCOLO.md) para que el **Panel Tukán Central** reciba las **solicitudes** de todos los
programas, vea a **todos los clientes** con sus vencimientos, **cree demos**, **registre pagos** y calcule **cuánto se
gana y cuánto se gasta**. Lo que hoy hace el súper panel de TuVocalía, pero para todos los programas y desde un solo
lugar. El inventario de lo que existe en cada programa está en [`INVENTARIO_SUPERPANEL.md`](INVENTARIO_SUPERPANEL.md).

Contenido:
1. Principios y compatibilidad con v1
2. Transporte y token v2
3. Validación y errores (`codigo`)
4. Tipos comunes (fechas, dinero, cliente, estado, formularios)
5. Acciones de lectura: `capacidades`, `estado`, `sso` (con enlace directo), `clientes`, `cliente`, `cotizar`, `planes`,
   `solicitudes`, `solicitud`, `adjunto`, `finanzas`
6. Acción de escritura: `ejecutar` (catálogo de operaciones)
7. Idempotencia y auditoría dentro del programa
8. Programas de una sola empresa (TuGuardería)
9. Lo que debe agregar el Panel Central (pantalla por pantalla)
10. Tabla por programa: qué existe y qué hay que construir
11. Pruebas obligatorias de cada conector v2
12. Orden de trabajo sugerido
13. Código de referencia (PHP)

---

## 1. Principios y compatibilidad con v1

1. **Mismo archivo** `/tukan_central.php`, **mismo secreto**, **mismo formato de token**
   (`b64url(payload_json) . "." . b64url(HMAC_SHA256(b64url(payload_json), SECRETO))`), misma tabla de nonces.
2. **v1 no cambia**: `accion=sso` y `accion=estado` siguen funcionando igual con tokens `v:1` por GET. Un Central nuevo
   habla v1 con los programas que no se han actualizado; un programa nuevo sigue contestando al Central viejo.
3. **Una sola pantalla genérica en el Central** para todos los programas: el programa dice qué puede hacer
   (`capacidades`), entrega listas con la misma forma (`clientes`, `solicitudes`, `finanzas`) y dice, por cada elemento,
   qué botones mostrar (`acciones_posibles`) con los campos de cada formulario. El Central **no** tiene código especial por
   programa.
4. **El programa manda**: las reglas de negocio (cómo se calcula el vencimiento, el precio, la comisión del vendedor, el
   correo al cliente) se ejecutan **en el programa**, con sus mismas funciones. El Central solo pide y muestra.
5. **Se actualiza programa por programa**: cada conector declara sus acciones en `capacidades`; lo que falta no se muestra.
6. Todo lo que cambia algo es **POST**, con el cuerpo **firmado** (hash del JSON en el token), **nonce de un solo uso**,
   **vida corta** y una **clave de operación** para poder reintentar sin duplicar (pagos dos veces, días dos veces).

---

## 2. Transporte y token v2

### 2.1 Pedido

Todas las acciones v2 (todas menos `sso` y `estado`) se piden así:

```
POST /tukan_central.php?accion=<accion> HTTP/1.1
Host: tuvocalia.com
Content-Type: application/json; charset=utf-8
Accept: application/json
User-Agent: TukanCentral/2
X-Tukan-Token: <token v2>

{"pagina":1,"por_pagina":50}
```

- El cuerpo es **siempre** un objeto JSON en UTF-8 (como mínimo `{}`), de **64 KiB como máximo**, profundidad ≤ 8.
- `accion` va en la dirección (para enrutar) **y** en el token (`tipo`); deben coincidir.
- El token va en la cabecera `X-Tukan-Token` (no en la dirección: así no queda en los registros de nginx).
  En PHP: `$_SERVER['HTTP_X_TUKAN_TOKEN']`.
- El Central espera como máximo **5 s** en lecturas, **15 s** en `ejecutar` y **20 s** en `adjunto` y `finanzas`.
  Respuestas de hasta **2 MiB** (8 MiB en `adjunto`). No sigue redirecciones.
- Máximo **4 pedidos a la vez** a una misma instancia.

### 2.2 Payload del token v2

```json
{"v":2,"tipo":"ejecutar","inst":"tuvocalia","email":"admin@tukanec.com","nombre":"Dimitry","rol":"dueno",
 "iat":1760371200,"exp":1760371230,"nonce":"9f2c4e0a7b1d4c3e8a6f5b2d1c0e9a8b","bh":"y1Q9oV8aB0cD1eF2gH3iJ4kL5mN6oP7qR8sT9uV0wXy"}
```

| Campo | Regla |
|---|---|
| `v` | `2` |
| `tipo` | igual a `accion` (`capacidades`, `clientes`, `cliente`, `cotizar`, `planes`, `solicitudes`, `solicitud`, `adjunto`, `finanzas`, `ejecutar`) |
| `inst` | `TUKAN_CENTRAL_ID` de la instancia |
| `email`, `nombre` | usuario del Central que hace el pedido (para la auditoría del programa). El cron usa `revision@panel.tukanec.com` |
| `rol` | `dueno` o `soporte` (informativo; los permisos los controla el Central) |
| `iat`, `exp` | enteros (segundos Unix). Lecturas: `exp - iat ≤ 60`. **`ejecutar`: `exp - iat ≤ 30`** |
| `nonce` | 32 hex en minúsculas, nuevo en cada pedido (también en los reintentos) |
| `bh` | **hash del cuerpo**: `b64url(SHA256(bytes exactos del cuerpo))`, 43 caracteres. Obligatorio en v2 |

El orden de las claves no importa (la firma se calcula sobre el texto tal como viaja).

### 2.3 Respuesta correcta

`200 application/json; charset=utf-8`, `Cache-Control: no-store`, siempre con:

```json
{"ok":true,"protocolo":2,"accion":"clientes","producto":"tuvocalia","generado":"2026-10-13T12:00:00-05:00", "…": "campos de la acción"}
```

### 2.4 Cambios mínimos a v1 (compatibles)

- **`estado`**: sigue igual (GET, token `v:1`, `"protocolo": 1`). Un conector v2 **agrega** (el Central viejo los ignora):
  `"protocolo_max": 2`, `"solicitudes_abiertas": 3` y `"solicitudes_huella": "a1b2c3d4e5f60718"` (16 hex: primeros 16 de
  `sha256` de los `id` + `estado` de las solicitudes abiertas, ordenados). Con la huella, el cron sabe si debe pedir
  `solicitudes`.
- **`sso`**: sigue con token `v:1`, y acepta un campo **opcional** `ir` (enlace directo, §5.3). Los conectores v1 lo
  ignoran (no revisan campos extra) y abren el inicio, que es lo que pasa hoy.
- **Errores de v1**: un conector v2 agrega `codigo` también a los errores de `estado` (y cabecera `X-Tukan-Codigo` en las
  páginas de error de `sso`).

---

## 3. Validación y errores

### 3.1 Orden de validación (v2)

`sso` y `estado` siguen el orden de v1 (`PROTOCOLO.md` §3), solo que sus errores ahora traen `codigo`. Las acciones v2:

| Paso | Revisión | Si falla → `codigo` |
|---|---|---|
| 1 | Conector configurado (ID y secreto ≥ 32) | `sin_configurar` (404) |
| 2 | Límite por IP: **120 pedidos por minuto** en total y **30 rechazados (401) por minuto** | `limite` (429, `Retry-After: 60`) |
| 3 | Método `POST`, `Content-Type` JSON, cuerpo ≤ 64 KiB | `invalido` (405 / 415 / 413) |
| 4 | Cabecera `X-Tukan-Token` con dos partes y **firma correcta** (`hash_equals`); payload legible | `firma` |
| 5 | `v == 2` | `version` |
| 6 | `tipo == accion` | `tipo` |
| 7 | `inst == TUKAN_CENTRAL_ID` | `inst` |
| 8 | `iat`/`exp` enteros, `exp ≥ iat`, `exp - iat ≤ 60` (30 en `ejecutar`) | `invalido` |
| 9 | `ahora ≤ exp + 30` | `vencido` |
| 10 | `iat ≤ ahora + 30` | `futuro` |
| 11 | `bh` igual a `b64url(sha256(php://input))` | `firma` (con `"detalle":{"parte":"cuerpo"}`) |
| 12 | `nonce` 32 hex **no usado** (se guarda con su `exp`, como en v1) | `nonce` |
| 13 | `email` válido | `invalido` |
| 14 | Cuerpo JSON válido y con los campos de la acción | `invalido` (400 si no es JSON; 422 si un campo está mal, con `campo`) |
| 15 | La acción (o la operación de `ejecutar`) existe en este programa | `no_permitido` (403, `"detalle":{"motivo":"no_disponible"}`) |

El nonce se guarda **después** de comprobar firma, tiempo y cuerpo (como en v1: un token roto no "gasta" su nonce).

### 3.2 Formato de error (todas las acciones, también `estado`)

```json
{"ok":false,"protocolo":2,"codigo":"invalido","error":"Escriba el monto con números, por ejemplo 39,00.","campo":"datos.monto","detalle":{}}
```

- `codigo`: uno de la tabla de abajo (nunca otro). `error`: texto en español sencillo, para mostrar tal cual.
- `campo` (opcional): ruta del campo que está mal (`datos.monto`, `pagina`).
- `detalle` (opcional): objeto con datos extra (`{"motivo":"ya_atendida","estado":"aprobada"}`).

| `codigo` | HTTP | Cuándo | Qué le dice el Central a Dimitry |
|---|---|---|---|
| `firma` | 401 | Firma mala, token ilegible o cuerpo cambiado | "Secreto incorrecto: toca «Cambiar secreto» y pega el comando nuevo en el servidor del programa." |
| `inst` | 401 | El token es para otra instancia | "El ID no coincide con el que tiene guardado el programa." |
| `tipo` | 401 | `tipo` ≠ `accion` | "Error del panel (pedido mal armado). Avísale al programador." |
| `version` | 401 | Versión de token no soportada | "El programa usa otra versión del protocolo. Actualiza ese programa." |
| `vencido` | 401 | Token viejo | "Reloj desfasado o red muy lenta. Intenta otra vez; si sigue, revisa la hora del servidor." |
| `futuro` | 401 | Token con fecha futura | "Reloj desfasado: la hora de uno de los servidores está mal." |
| `nonce` | 401 | Token repetido | "El programa dice que el pedido se repitió. Intenta otra vez." |
| `sin_configurar` | 404 | Conector apagado | "Programa sin conectar: pega el comando en su servidor." |
| `limite` | 429 | Demasiados pedidos | "Demasiados pedidos seguidos: espera un minuto." |
| `no_encontrado` | 404 | El cliente, pago, solicitud o adjunto no existe | "Ya no existe en el programa (quizás lo borraron). Actualiza la lista." |
| `invalido` | 400/422 (datos), 401 (fechas o correo del token), 405/413/415 (método, tamaño, tipo) | Datos mal | Se muestra `error` del programa junto al campo |
| `no_permitido` | 403 | Acción que no aplica (ej. alargar la prueba de un cliente que ya paga) o no disponible | Se muestra `error` |
| `conflicto` | 409 | Ya atendida por otro, o `operacion_id` repetido con otros datos, o en curso | Se muestra `error` y se recarga el elemento |
| `interno` | 500 | Excepción en el programa (el detalle va al `error_log`, nunca al JSON) | "El programa tuvo un error interno. Intenta en un momento." |

Un 404 **sin** JSON o sin `codigo` (conector v1 apagado, URL equivocada o acción desconocida en TuVocalía v1) se sigue
tratando como hoy (`no_conectado`). Un 400 "Acción no válida." de un conector v1 (TuEvento/TuSalón) en `capacidades`
significa "programa v1".

En `sso` los errores siguen siendo una página HTML en español con enlace al Central, ahora con la cabecera
`X-Tukan-Codigo: <codigo>`.

---

## 4. Tipos comunes

### 4.1 Formatos

| Tipo | Formato |
|---|---|
| fecha | `"2026-10-13"` (día en hora de Ecuador, `America/Guayaquil`) |
| momento | ISO 8601 con zona: `"2026-10-13T09:12:00-05:00"` |
| mes | `"2026-10"` |
| dinero | número JSON con 2 decimales como máximo, en **USD** (`95.0`, `39.5`). Nunca texto |
| id | texto `^[A-Za-z0-9_.:-]{1,80}$`. Los ids numéricos del programa viajan como texto (`"123"`) |
| texto | texto plano (sin HTML); el Central siempre lo escapa |
| teléfono | `telefono`: como lo escribió el cliente; `whatsapp`: solo dígitos con código de país (`593991234567`) o `null` |

Cualquier campo que el programa no tenga va en `null` (o lista vacía). Campos desconocidos se ignoran.

### 4.2 Estado del cliente (las mismas 5 categorías que suman el `negocios` de v1)

En v1, `negocios` tiene 6 cifras: `total` y 5 estados. En v2 cada cliente lleva **uno** de esos 5 estados, con **una
sola regla para todos los programas** (hoy TuEvento/TuSalón usan `por_vencer` para "ya venció, en gracia"; eso se corrige):

| `estado` | Regla (día de hoy en Ecuador) | Contador v1 |
|---|---|---|
| `prueba` | En prueba o demo y todavía no termina (incluye "termina hoy") | `negocios.prueba` |
| `por_vencer` | Pagado y vence en **0 a 7 días** | `negocios.por_vencer` |
| `activo` | Pagado y vence en más de 7 días, **o** sin fecha de vencimiento (cortesía, propio) | `negocios.activos` |
| `vencido` | Ya pasó la fecha (pagado o prueba), **incluidos** los días de gracia | `negocios.vencidos` |
| `suspendido` | Suspendido o cancelado por Tukán | `negocios.suspendidos` |

Además cada cliente trae:
- `estado_detalle`: el estado propio del programa (`gracia`, `prueba_vencida`, `sin_limite`, `cancelado`…), con su
  nombre en `capacidades.estados_detalle`.
- `en_gracia`: `true` si venció pero todavía puede trabajar.
- `bloqueado`: `true` si ya no puede trabajar (TuVocalía: solo lectura; TuEvento/TuSalón: pantalla de prueba terminada).

**Regla de coherencia**: al pasar un programa a v2, su `estado` v1 debe contar con estas mismas reglas, para que
`clientes.contadores` y `estado.negocios` den lo mismo. (TuVocalía y TuAcademia ya cuentan así; TuEvento y TuSalón deben
pasar "en gracia" de `por_vencer` a `vencidos` y contar en `por_vencer` a los activos que vencen en ≤ 7 días.)

Las demos **del sistema** (TuVocalía `ligas.es_demo = 1`) **no** son clientes: no salen en ninguna lista ni suma.

### 4.3 Cliente (resumen)

```json
{
  "id": "123",
  "nombre": "Liga Norte",
  "tipo": "liga",
  "tipo_nombre": "Liga",
  "ciudad": "Quito",
  "plan": {"codigo": "5", "nombre": "Pro"},
  "estado": "prueba",
  "estado_detalle": "prueba",
  "estado_texto": "Demo hasta el 2026-10-20 (faltan 7 días)",
  "en_gracia": false,
  "bloqueado": false,
  "fechas": {
    "alta": "2026-10-13",
    "prueba_hasta": "2026-10-20",
    "activo_hasta": null,
    "vence": "2026-10-20",
    "dias_para_vencer": 7
  },
  "dueno": {"nombre": "Juan Pérez", "telefono": "0991234567", "whatsapp": "593991234567", "email": "juan@ejemplo.com"},
  "ultima_actividad": "2026-10-12T18:22:00-05:00",
  "pagos": {"total": 0.0, "cantidad": 0, "primer_pago": null, "ultimo_pago": null, "ultimo_monto": null},
  "mensual_estimado": 0.0,
  "origen": "demo_pedida",
  "vendedor": null,
  "uso": {"Equipos": 4, "Campeonatos": 1},
  "ir": "/admin.php?p=ligas&editar=123",
  "ir_trabajar": "/admin.php?p=ligas&entrar=123",
  "actualizado": "2026-10-13T11:58:00-05:00"
}
```

| Campo | Regla |
|---|---|
| `tipo` | texto libre del programa (`liga`, `escuela`, `federacion`, `academia`, `salon`, `quinta`…). `null` si no aplica |
| `fechas.vence` | `prueba_hasta` si está en prueba, si no `activo_hasta`. `null` si no vence |
| `fechas.dias_para_vencer` | días desde hoy hasta `vence` (negativo si ya pasó); `null` si no vence |
| `dueno` | contacto del dueño o administrador principal. **Datos personales**: el Central solo los muestra al rol `dueno` (§9.3) |
| `ultima_actividad` | lo más reciente entre: último ingreso de cualquier usuario de la cuenta y último cambio de sus datos (lo que el programa pueda saber) |
| `pagos` | solo pagos de suscripción **no anulados** |
| `mensual_estimado` | lo que vale al mes lo pagado y vigente (último pago ÷ meses que cubre); `0` si no está pagando |
| `origen` | `registro` (se registró solo), `demo_pedida` (vino de un pedido de demo), `super` (lo creó el súper en el programa), `central` (lo creó el Central), `google_play`, `desconocido` |
| `uso` | hasta 4 pares texto → número para "cuánto lo usa" |
| `ir` | ruta (relativa, empieza con `/`) de la **ficha de ese cliente en el súper panel del programa** (para `sso` con `ir`) |
| `ir_trabajar` | opcional: ruta para trabajar **dentro** de la cuenta del cliente |

### 4.4 Formularios genéricos: `acciones_posibles` y `campos`

Cada cliente (en `cliente`) y cada solicitud trae los botones que tiene sentido mostrar:

```json
{"accion": "registrar_pago", "etiqueta": "Registrar pago", "estilo": "principal",
 "confirmar": "¿Registrar el pago de Liga Norte?",
 "datos": {"cliente_id": "123"},
 "campos": [
   {"campo": "plan", "etiqueta": "Plan", "tipo": "opcion", "requerido": true, "valor": "5", "opciones": [{"valor": "5", "etiqueta": "Pro"}, {"valor": "6", "etiqueta": "Torneo"}]},
   {"campo": "periodo", "etiqueta": "Tiempo", "tipo": "opcion", "requerido": true, "valor": "mensual", "opciones": [{"valor": "mensual", "etiqueta": "1 mes"}, {"valor": "semestral", "etiqueta": "6 meses (1 gratis)"}]},
   {"campo": "monto", "etiqueta": "Valor recibido ($)", "tipo": "dinero", "requerido": true, "valor": 19.0, "min": 0, "cotizar": true},
   {"campo": "metodo", "etiqueta": "Forma de pago", "tipo": "opcion", "requerido": true, "valor": "transferencia", "opciones": [{"valor": "transferencia", "etiqueta": "Transferencia"}]},
   {"campo": "fecha", "etiqueta": "Fecha del pago", "tipo": "fecha", "requerido": true, "valor": "2026-10-13"},
   {"campo": "nota", "etiqueta": "Nota", "tipo": "texto", "requerido": false, "max": 200, "ayuda": "N° de transferencia, factura…"}
 ]}
```

| Propiedad | Regla |
|---|---|
| `accion` | una operación de `ejecutar` (§6), **o** dos acciones locales del Central: `whatsapp` (abre `https://wa.me/<whatsapp>?text=<texto>`, con `"whatsapp"` y `"texto"`) y `abrir` (SSO con `"ir"`) |
| `estilo` | `principal`, `normal` o `peligro` (rojo, pide confirmar dos veces) |
| `confirmar` | texto de confirmación o `null` |
| `datos` | valores fijos que el Central agrega a `datos` sin mostrarlos (`cliente_id`, `solicitud_id`) |
| `campos[].tipo` | `texto`, `texto_largo`, `numero`, `dinero`, `fecha`, `dias`, `opcion`, `si_no`, `telefono`, `email` |
| `campos[].cotizar` | `true`: al cambiar plan o período, el Central llama `cotizar` y pone el valor sugerido |
| otros | `requerido`, `valor` (por defecto), `min`, `max` (número o largo), `opciones`, `ayuda` |

El Central dibuja el formulario con esto, manda `ejecutar` con `{"accion": …, "datos": {…datos fijos, …campos}}` y
muestra `mensaje` de la respuesta. Así una operación nueva en un programa aparece sola en el Central.

---

## 5. Acciones de lectura

### 5.1 `capacidades`

Cuerpo `{}`. El Central la pide al agregar o probar un programa, una vez al día y cuando cambia `version` en `estado`.

```json
{
  "ok": true, "protocolo": 2, "revision": 1, "accion": "capacidades",
  "producto": "tuvocalia", "nombre": "TuVocalía", "version": "75df6b3", "generado": "2026-10-13T12:00:00-05:00",
  "unico": false, "moneda": "USD", "zona_horaria": "America/Guayaquil",
  "url_publica": "https://tuvocalia.com",
  "acciones": ["capacidades", "estado", "sso", "clientes", "cliente", "cotizar", "planes", "solicitudes", "solicitud", "adjunto", "finanzas", "ejecutar"],
  "sso_ir": true,
  "rutas_sso": ["/admin.php"],
  "ejecutar": ["crear_demo", "extender_prueba", "registrar_pago", "anular_pago", "aprobar_comprobante", "rechazar_comprobante",
               "suspender", "reactivar", "cambiar_plan", "nueva_clave_dueno", "nota_interna", "atender_solicitud", "responder_ticket"],
  "tipos_solicitud": ["registro_nuevo", "demo_pedida", "comprobante", "soporte", "derechos_datos", "pago_automatico",
                      "seguimiento_prueba", "renovacion", "dominio_gratis", "comision_vendedor", "liquidacion_pendiente"],
  "estados_detalle": {"prueba": "Demo", "activa": "Activa", "sin_limite": "Sin vencimiento", "gracia": "En gracia",
                      "vencida": "Vencida", "prueba_vencida": "Demo terminada", "suspendida": "Suspendida"},
  "periodos": [{"periodo": "mensual", "meses": 1}, {"periodo": "trimestral", "meses": 3}, {"periodo": "semestral", "meses": 6}, {"periodo": "anual", "meses": 12}],
  "meses_libres": true,
  "metodos_pago": [{"metodo": "transferencia", "nombre": "Transferencia"}, {"metodo": "deposito", "nombre": "Depósito"},
                   {"metodo": "efectivo", "nombre": "Efectivo"}, {"metodo": "tarjeta", "nombre": "Tarjeta / Plux"}, {"metodo": "otro", "nombre": "Otro"}],
  "planes_editar": false,
  "filtros_clientes": ["buscar", "estados", "plan", "tipo", "vence_desde", "vence_hasta", "alta_desde", "alta_hasta", "sin_uso_dias", "origen"],
  "formularios": {
    "crear_demo": [
      {"campo": "tipo", "etiqueta": "Tipo de cuenta", "tipo": "opcion", "requerido": true, "valor": "liga",
       "opciones": [{"valor": "liga", "etiqueta": "Liga (campeonatos)"}, {"valor": "escuela", "etiqueta": "Escuela deportiva"}]},
      {"campo": "ciudad", "etiqueta": "Ciudad", "tipo": "texto", "requerido": false, "max": 80}
    ]
  },
  "limites": {"por_pagina_max": 200, "meses_finanzas_max": 24, "dias_prueba_max": 90}
}
```

- `ejecutar`: operaciones disponibles (§6). `tipos_solicitud`: tipos que este programa puede emitir (§5.8).
- `meses_libres: true`: `registrar_pago` acepta `meses` (1 a 36) además de `periodo` (TuVocalía).
- `formularios.crear_demo`: **solo los campos propios** del programa; los comunes (§6.1) los pone siempre el Central.
- `rutas_sso`: prefijos permitidos para `ir`.
- `revision`: sube cuando se agregan cosas al v2 sin romper nada.

### 5.2 `estado`

Igual que v1 (§2.4).

### 5.3 `sso` con enlace directo

Token `v:1` (igual que hoy) con un campo opcional:

```json
{"v":1,"tipo":"sso","inst":"tuvocalia","email":"admin@tukanec.com","nombre":"Dimitry","iat":1760371200,"exp":1760371260,"nonce":"…","ir":"/admin.php?p=ligas&editar=123"}
```

El conector, **después** de iniciar la sesión del súper usuario:
- Si `ir` es texto de ≤ 300 caracteres, empieza con `/` pero no con `//`, no tiene `\`, ni caracteres de control, cumple
  `^/[A-Za-z0-9._~!$&'()*+,;=:@%/?#-]*$` **y** empieza con uno de sus `rutas_sso` → redirige a `ir`.
- Si no → redirige al inicio del súper panel (como v1) y anota en su registro "ir no válido".
- Anota en su registro: "Entró desde el Panel Tukán Central → <ir>".

El Central solo manda `ir` si la instancia tiene `sso_ir: true`.

### 5.4 `clientes`

Pedido:

```json
{"pagina": 1, "por_pagina": 50, "buscar": "norte", "estados": ["prueba", "por_vencer"], "plan": "", "tipo": "",
 "vence_desde": "2026-10-13", "vence_hasta": "2026-11-12", "alta_desde": "", "alta_hasta": "", "sin_uso_dias": null,
 "origen": "", "ids": [], "orden": "vence"}
```

| Campo | Regla |
|---|---|
| `pagina` | ≥ 1 (por defecto 1). `por_pagina`: 1 a `limites.por_pagina_max` (por defecto 50) |
| `buscar` | ≤ 80 caracteres; busca en nombre, contacto, correo, teléfono, dirección corta, ciudad, RUC (sin distinguir mayúsculas ni tildes si el motor lo permite) |
| `estados` | lista de estados de §4.2 (vacía = todos) |
| `vence_desde`/`vence_hasta` | filtran por `fechas.vence` (incluidos) |
| `sin_uso_dias` | solo clientes con `ultima_actividad` más vieja que N días (o nunca) |
| `ids` | solo esos clientes (para refrescar unos pocos) |
| `orden` | `vence` (los que vencen primero; sin fecha al final), `alta` (más nuevos primero), `nombre`, `actividad` (menos activos primero) |

Respuesta:

```json
{"ok": true, "protocolo": 2, "accion": "clientes", "producto": "tuvocalia", "generado": "…",
 "total": 12, "pagina": 1, "por_pagina": 50, "paginas": 1,
 "contadores": {"total": 12, "prueba": 4, "activo": 6, "por_vencer": 1, "vencido": 1, "suspendido": 0},
 "clientes": [ { "…": "Cliente (§4.3)" } ]}
```

`contadores` cuenta **todos** los clientes (sin filtros) y debe dar las mismas cifras que `estado.negocios` (allí las
claves van en plural: `activos`, `vencidos`, `suspendidos`; aquí en singular, igual que `estado`).

### 5.5 `cliente`

Pedido `{"id": "123"}`. Respuesta `{"ok": true, …, "cliente": {…}}` con el resumen de §4.3 más:

```json
{
  "notas_admin": "Pidió factura con RUC 1790012345001",
  "usuarios": 3,
  "recomendado_por": {"id": "88", "nombre": "Escuela Sur"},
  "combo_con": null,
  "dominio": "",
  "enlace_publico": "https://tuvocalia.com/liga-norte",
  "pagos_detalle": [
    {"id": "88", "fecha": "2026-09-01", "monto": 95.0, "plan": {"codigo": "5", "nombre": "Pro"}, "periodo": "semestral", "meses": 6,
     "desde": "2026-09-01", "hasta": "2027-03-01", "metodo": "transferencia", "metodo_nombre": "Transferencia",
     "nota": "comprobante #45", "registrado_por": "Dimitry", "origen": "comprobante", "anulado": false, "puede_anular": true}
  ],
  "solicitudes": [ { "…": "Solicitudes abiertas de este cliente (§5.8)" } ],
  "registro": [ {"momento": "2026-10-13T09:15:00-05:00", "quien": "Panel Tukán Central <admin@tukanec.com>", "texto": "Alargó la demo hasta el 2026-10-27"} ],
  "mensajes_sugeridos": {
    "bienvenida": "Hola Juan, le saluda Tukán Software de TuVocalía…",
    "recordar_prueba": "Hola Juan, ¿cómo le va con la demo de Liga Norte? Le quedan 2 día(s)…",
    "recordar_renovacion": null,
    "vencido": null
  },
  "acciones_posibles": [ { "…": "§4.4" } ]
}
```

- `pagos_detalle`: todos (máximo 200, el más nuevo primero), **incluidos** los anulados (`anulado: true`).
- `registro`: últimas 20 acciones del súper usuario o del Central sobre este cliente.
- `mensajes_sugeridos`: textos de WhatsApp **del programa** (con su precio de renovación, datos de pago, etc.); `null` si
  no aplica. El Central los prefiere a sus plantillas.

### 5.6 `cotizar`

Pedido `{"cliente_id": "123", "plan": "5", "periodo": "semestral"}` (o `"meses": 2` si `meses_libres`).

```json
{"ok": true, …, "monto": 95.0, "meses": 6, "desde": "2026-10-21", "hasta": "2027-04-20",
 "detalle": "Pro semestral (paga 5, recibe 6)", "incluye_dominio": false, "descuentos": [{"nombre": "Combo liga + escuela", "porcentaje": 30}]}
```

`desde`/`hasta` = vigencia que quedaría con la misma regla de `registrar_pago` (se suma desde el vencimiento actual si
aún no vence, o desde hoy).

### 5.7 `planes`

Pedido `{}`.

```json
{"ok": true, …, "editable": false,
 "planes": [
   {"codigo": "5", "nombre": "Pro", "activo": true, "para": "Ligas y escuelas", "descripcion": "…",
    "precios": [{"periodo": "mensual", "meses": 1, "precio": 19.0, "meses_gratis": 0},
                {"periodo": "semestral", "meses": 6, "precio": 95.0, "meses_gratis": 1},
                {"periodo": "anual", "meses": 12, "precio": 190.0, "meses_gratis": 2, "incluye_dominio": true}],
    "limites": {"Torneos a la vez": "3", "Equipos": "Ilimitados"}, "clientes": 12}
 ],
 "ajustes": {"dias_prueba": 7, "dias_demo": 7, "dias_gracia": 3, "dias_gracia_demo": 0, "registro_abierto": false}}
```

Si `planes_editar` es `true`, existe la operación `guardar_plan` (§6.15).

### 5.8 `solicitudes`

Pedido:

```json
{"estado": "abiertas", "tipos": [], "cliente_id": null, "desde": "2026-09-01", "pagina": 1, "por_pagina": 100}
```

- `estado`: `abiertas` (por defecto: `pendiente` y `en_proceso`), `cerradas` (`atendida`, `descartada`) o `todas`.
- `desde`: solo las de esa fecha en adelante (para `cerradas`/`todas`; por defecto 60 días atrás).

Respuesta:

```json
{"ok": true, …, "total": 4, "pagina": 1, "paginas": 1,
 "contadores": {"comprobante": 1, "demo_pedida": 2, "soporte": 1},
 "solicitudes": [
  {
    "id": "comprobante:45",
    "tipo": "comprobante",
    "categoria": "pedido",
    "titulo": "Comprobante de pago por revisar",
    "fecha": "2026-10-13T09:12:00-05:00",
    "actualizado": "2026-10-13T09:12:00-05:00",
    "cliente_id": "123",
    "quien": {"nombre": "Juan Pérez", "organizacion": "Liga Norte", "telefono": "0991234567", "whatsapp": "593991234567", "email": "juan@ejemplo.com"},
    "resumen": "Plan Pro · 6 meses · $95,00 · Transferencia",
    "monto": 95.0,
    "estado": "pendiente",
    "estado_producto": "pendiente",
    "prioridad": "alta",
    "vence_el": null,
    "detalle": {"plan": {"codigo": "5", "nombre": "Pro"}, "meses": 6, "forma_pago": "transferencia", "nota": "Pichincha 12345",
                "enviado_por": "Juan Pérez <juan@ejemplo.com>", "cambia_plan": false, "plan_actual": {"codigo": "5", "nombre": "Pro"}},
    "adjuntos": [{"id": "1", "nombre": "comprobante_45.jpg", "tipo_mime": "image/jpeg"}],
    "acciones_posibles": [
      {"accion": "aprobar_comprobante", "etiqueta": "Aprobar y renovar", "estilo": "principal",
       "confirmar": "¿Aprobar y renovar Liga Norte por 6 meses?", "datos": {"solicitud_id": "comprobante:45"},
       "campos": [{"campo": "monto", "etiqueta": "Valor recibido ($)", "tipo": "dinero", "requerido": true, "valor": 95.0, "min": 0},
                  {"campo": "nota", "etiqueta": "Nota", "tipo": "texto", "requerido": false, "max": 300}]},
      {"accion": "rechazar_comprobante", "etiqueta": "Rechazar", "estilo": "peligro", "confirmar": null, "datos": {"solicitud_id": "comprobante:45"},
       "campos": [{"campo": "motivo", "etiqueta": "Motivo (lo verá el cliente)", "tipo": "texto", "requerido": true, "min": 5, "max": 300}]},
      {"accion": "whatsapp", "etiqueta": "WhatsApp", "whatsapp": "593991234567", "texto": "Hola Juan, recibimos su comprobante…"},
      {"accion": "abrir", "etiqueta": "Ver en TuVocalía", "ir": "/admin.php?p=suscripciones#comprobantes"}
    ],
    "ir": "/admin.php?p=suscripciones#comprobantes"
  }
 ]}
```

| Campo | Regla |
|---|---|
| `id` | `"<tipo>:<referencia>"`, **estable** (el mismo elemento siempre tiene el mismo id; una etapa nueva de un recordatorio tiene id nuevo) |
| `categoria` | `pedido` (alguien pidió algo), `recordatorio` (lo genera el calendario o una regla), `aviso` (informativo) |
| `estado` | genérico: `pendiente`, `en_proceso`, `atendida`, `descartada`. `estado_producto`: el estado propio |
| `prioridad` | `alta`, `normal`, `baja` |
| `vence_el` | fecha límite (ej. LOPDP: 15 días) o `null` |
| `detalle` | campos propios del tipo (tabla de abajo) |
| `adjuntos` | se bajan con `adjunto` |

**Catálogo de tipos** (sacado del código de los programas; ver §10 para cada uno):

| `tipo` | Categoría | Qué es | Abierta mientras | `acciones_posibles` típicas |
|---|---|---|---|---|
| `registro_nuevo` | pedido | Un negocio **se registró solo** (prueba gratis automática) en los últimos 30 días | No se atendió y tiene ≤ 30 días | WhatsApp de bienvenida, `extender_prueba`, `registrar_pago`, `atender_solicitud` |
| `demo_pedida` | pedido | Alguien **pidió una demo** o contacto por el formulario | Estado del programa `nueva` (pendiente) o `contactada` (en_proceso) | WhatsApp, `crear_demo` (con los datos del pedido), `atender_solicitud` (contactada / descartada) |
| `comprobante` | pedido | Un cliente **subió el comprobante** para contratar, renovar o cambiar de plan | Comprobante `pendiente` | `aprobar_comprobante`, `rechazar_comprobante`, ver foto, WhatsApp. Si `detalle.cambia_plan` es `true` el título es "Comprobante y cambio de plan" |
| `cambio_plan` | pedido | Pedido de cambio de plan **sin pago** (ningún programa lo tiene hoy; reservado) | Pendiente | `cambiar_plan`, `atender_solicitud` |
| `soporte` | pedido | Ticket: `detalle.subtipo` = `problema`, `pregunta`, `funcionalidad`, `pagos`, `otro` | Sin leer por Tukán, o `nuevo`/`en_proceso` | `responder_ticket`, `atender_solicitud` (resuelto / cerrado), abrir |
| `derechos_datos` | pedido | Solicitud LOPDP dirigida a **Tukán** (no a un cliente) | `nueva`/`en_proceso`. `vence_el` = creada + 15 días | `atender_solicitud` (con `respuesta`), abrir |
| `pago_automatico` | aviso | Entró un pago **solo** (tarjeta, Google Play) en los últimos 30 días | No se marcó "visto" | `atender_solicitud` ("Visto") |
| `seguimiento_prueba` | recordatorio | Prueba con `detalle.etapa` = `faltan_2`, `vence_hoy` o `terminada` (terminó sin pagar, ≤ 60 días) | No se atendió esa etapa | WhatsApp (`mensajes_sugeridos`), `extender_prueba` (+7), `registrar_pago`, `atender_solicitud` |
| `renovacion` | recordatorio | Cliente que paga con `detalle.etapa` = `vence_pronto` (≤ 5 días) o `vencida` (≤ 30 días sin renovar) | No se atendió esa etapa y no ha pagado | WhatsApp, `registrar_pago`, `suspender` (solo `vencida`), `atender_solicitud` |
| `dominio_gratis` | recordatorio | Pagó 1 año en un plan con dominio gratis y todavía no tiene dominio | Sin dominio y no atendida | `atender_solicitud` (campo `dominio`), abrir |
| `comision_vendedor` | recordatorio | Tukán le debe comisión a un vendedor | Comisión `pendiente` | abrir (Vendedores), `atender_solicitud` |
| `liquidacion_pendiente` | recordatorio | Tukán tiene que transferir a un cliente lo que cobró con tarjeta por él | Hay pagos sin liquidar | abrir (Cobros con tarjeta), `atender_solicitud` |

Los recordatorios se arman **al momento** (no hay tabla de recordatorios): el programa calcula cuáles corresponden hoy y
les quita los que ya se atendieron (tabla `tukan_central_atenciones`, §7.2).

### 5.9 `solicitud`

Pedido `{"id": "soporte:9"}`. Respuesta `{"ok": true, …, "solicitud": {…}}`: la misma forma que en la lista, con
`detalle` completo. En `soporte`: `detalle.mensajes` = toda la conversación
(`[{"de": "cliente", "autor": "Juan", "momento": "…", "texto": "…", "adjunto_id": "31"}]`).

### 5.10 `adjunto`

Pedido `{"solicitud_id": "comprobante:45", "adjunto_id": "1"}`.

```json
{"ok": true, …, "nombre": "comprobante_45.jpg", "tipo_mime": "image/jpeg", "tamano": 183422, "base64": "/9j/4AAQSk…"}
```

Solo `image/jpeg`, `image/png`, `image/webp` y `application/pdf`, de hasta 6 MiB. El programa revisa que el adjunto sea
de **esa** solicitud. El Central nunca lo guarda: lo pasa al navegador con su propia ruta (`/?r=adjunto`), con
`Content-Type` del archivo, `X-Content-Type-Options: nosniff`, `Content-Disposition: inline` y `Cache-Control: private, no-store`.

### 5.11 `finanzas`

Pedido `{"desde": "2025-11", "hasta": "2026-10"}` (por defecto los últimos 12 meses; máximo 24 meses).

```json
{"ok": true, …, "moneda": "USD",
 "criterio": "Pagos de suscripción no anulados, por fecha de pago. Las demos del sistema no cuentan.",
 "mrr": 312.5,
 "meses": [
  {"mes": "2026-10", "ingresos": 414.0, "pagos": 6, "clientes_que_pagaron": 5, "nuevos_pagadores": 2, "devengado": 298.3,
   "por_plan": [{"plan": "5", "nombre": "Pro", "monto": 300.0, "pagos": 4}, {"plan": "6", "nombre": "Torneo", "monto": 114.0, "pagos": 2}],
   "por_periodo": [{"periodo": "mensual", "meses": 1, "monto": 114.0, "pagos": 3}, {"periodo": "semestral", "meses": 6, "monto": 300.0, "pagos": 3}],
   "por_metodo": [{"metodo": "transferencia", "nombre": "Transferencia", "monto": 395.0, "pagos": 5}, {"metodo": "tarjeta", "nombre": "Tarjeta / Plux", "monto": 19.0, "pagos": 1}],
   "otros_ingresos": [{"concepto": "gestion_cobros", "nombre": "Gestión de cobros con tarjeta (1 %)", "monto": 4.1}],
   "costos": [{"concepto": "comision_pasarela", "categoria": "comisiones_pasarela", "nombre": "Comisión de Plux en suscripciones", "monto": 0.87, "estimado": false},
              {"concepto": "comision_vendedores", "categoria": "sueldos_servicios", "nombre": "Comisiones de vendedores", "monto": 20.0, "estimado": false}]}
 ],
 "totales": {"ingresos": 2810.0, "otros_ingresos": 31.4, "costos": 140.2, "pagos": 41}}
```

| Campo | Regla |
|---|---|
| `meses` | **todos** los meses del rango, también los que están en 0, en orden |
| `ingresos` | suma de pagos de suscripción **por fecha de pago** (caja) |
| `devengado` | (opcional) lo que corresponde a ese mes repartiendo cada pago entre los meses que cubre |
| `nuevos_pagadores` | clientes cuyo **primer** pago cayó en ese mes |
| `otros_ingresos` | otra plata que gana Tukán en el programa (ej. TuVocalía: 1 % de gestión de cobros con tarjeta) |
| `costos` | costos que el programa **conoce** (comisión de la pasarela en suscripciones, comisiones de vendedores, comisión estimada de Google Play). `categoria` es una de las categorías de gastos del Central (§9.6). `estimado: true` si es un cálculo |
| `mrr` | ingreso mensual recurrente de hoy (suma de `mensual_estimado` de los clientes que pagan) |

---

## 6. `ejecutar`

### 6.1 Pedido y respuesta

```json
{"operacion_id": "4b7d2f0e9c1a4e8b9d3f6a2c5e7b1d0f", "accion": "registrar_pago", "cliente_id": "123",
 "datos": {"plan": "5", "periodo": "semestral", "monto": 95.0, "metodo": "transferencia", "fecha": "2026-10-13", "nota": "Pichincha 12345"}}
```

| Campo | Regla |
|---|---|
| `operacion_id` | 32 hex. El Central lo crea **al abrir el formulario** (dos toques = la misma operación) y lo reusa en los reintentos |
| `accion` | una de `capacidades.ejecutar` |
| `cliente_id` | obligatorio en las operaciones sobre un cliente; en las de solicitudes sale de `datos.solicitud_id` |
| `datos` | campos de la operación (abajo) |

Respuesta correcta:

```json
{"ok": true, "protocolo": 2, "accion": "ejecutar", "producto": "tuvocalia", "generado": "…",
 "operacion": "registrar_pago", "operacion_id": "4b7d2f0e9c1a4e8b9d3f6a2c5e7b1d0f", "repetida": false, "sin_cambios": false,
 "mensaje": "Pago registrado. Liga Norte queda activa hasta el 2027-04-13.",
 "resultado": {"pago_id": "89", "activo_hasta": "2027-04-13", "monto": 95.0, "comision": {"vendedor": "Carlos", "monto": 19.0}},
 "cliente": { "…": "Cliente actualizado (§4.3)" },
 "solicitud": null}
```

- `repetida: true`: la operación ya se había hecho; se devuelve el mismo resultado guardado (§7.1).
- `sin_cambios: true`: no había nada que cambiar (ej. suspender a alguien ya suspendido). No es error.
- `cliente` / `solicitud`: cómo quedaron, para que el Central actualice su copia sin otro pedido.

**Campos comunes de crear demo** (siempre los pone el Central):

| Campo | Regla |
|---|---|
| `nombre` | nombre del negocio, 3–120 |
| `contacto_nombre` | 2–80 |
| `email` | correo válido (será el usuario). Si ya existe en el programa → `invalido` con `campo: "datos.email"` |
| `telefono` | 9–30 caracteres con al menos 9 dígitos |
| `plan` | código de `planes` (o `null`: el plan por defecto del programa) |
| `dias` | 1 a `limites.dias_prueba_max`; por defecto el ajuste del programa |
| `solicitud_id` | opcional: `demo_pedida:<id>`. El pedido queda como "demo creada" y la cuenta toma su vendedor y su referido |
| `enviar_correo` | `true`/`false`: correo de bienvenida del programa con los accesos |
| `extra` | objeto con los campos de `capacidades.formularios.crear_demo` |

### 6.2 Catálogo de operaciones

| # | `accion` | `datos` | Reglas | `resultado` |
|---|---|---|---|---|
| 6.3 | `crear_demo` | comunes de §6.1 | Crea la cuenta **en prueba** con su dueño, con las mismas funciones que usa el súper panel (datos de ejemplo incluidos). `origen = central` | `cliente_id`, `acceso` (`url_panel`, `usuario`, `clave`), `enlace_publico`, `prueba_hasta`, `mensaje_whatsapp`, `whatsapp_url`, `correo_enviado` |
| 6.4 | `extender_prueba` | `dias` (1–90) | Solo si `estado` es `prueba` o (`vencido` sin pagos nunca). Suma desde la fecha de fin si aún no terminó, o desde hoy. Reinicia los avisos de la demo | `prueba_hasta` |
| 6.5 | `registrar_pago` | `plan`, `periodo` (o `meses` si `meses_libres`), `monto` (≥ 0; si falta, el de `cotizar`), `metodo` (de `metodos_pago`), `fecha` (≤ hoy y ≥ hoy − 366 días), `inicio` (opcional), `nota` (≤ 200), `dominio` (opcional, solo con dominio incluido) | Usa la lógica de suscripción del programa (vigencia, comisión, referidos, correo de pago) | `pago_id`, `activo_hasta`, `monto`, `comision` |
| 6.6 | `anular_pago` | `pago_id`, `motivo` (5–200) | El pago debe ser de ese cliente y no estar anulado. Recalcula la vigencia. Se niega si la comisión del vendedor ya se pagó | `activo_hasta` |
| 6.7 | `aprobar_comprobante` | `solicitud_id`, `monto` (opcional: el del comprobante), `nota` | El comprobante debe estar `pendiente` (si no → `conflicto`, `detalle.estado`) | `pago_id`, `activo_hasta` |
| 6.8 | `rechazar_comprobante` | `solicitud_id`, `motivo` (5–300, lo ve el cliente) | Igual | — |
| 6.9 | `suspender` | `motivo` (5–200) | El cliente deja de poder trabajar y su página pública se oculta. Los datos se guardan | `estado` |
| 6.10 | `reactivar` | — | Quita la suspensión; queda en el estado que le corresponda por fechas | `estado` |
| 6.11 | `cambiar_plan` | `plan` | Sin cobrar (para cortesías o corrección). El plan debe existir y ser para ese tipo de cuenta | `plan` |
| 6.12 | `nueva_clave_dueno` | — | Contraseña temporal nueva para el dueño (la anterior deja de servir) | `usuario`, `clave`, `mensaje_whatsapp` |
| 6.13 | `nota_interna` | `texto` (≤ 4000; vacío borra) | Notas de Tukán sobre el cliente (el cliente no las ve) | — |
| 6.14 | `atender_solicitud` | `solicitud_id`, `resultado` (`en_proceso`, `atendida`, `descartada`), `nota` (≤ 300), `respuesta` (LOPDP, ≥ 5), `dominio` (dominio gratis) | Marca la solicitud y guarda el resultado **en el programa** (§7.2) | `solicitud` |
| 6.15 | `guardar_plan` | `plan`, `nombre`, `precios`, `descripcion`, límites del programa | Solo si `planes_editar` | `plan` |
| 6.16 | `responder_ticket` | `solicitud_id` (`soporte:<id>`), `texto` (1–6000), `estado` (`en_proceso`, `esperando`, `resuelto`, `cerrado`) | Igual que responder desde el programa (avisa al cliente) | `solicitud` |
| 6.17 | `cancelar` | `motivo` | Solo programas con estado "cancelado" (TuEvento/TuSalón) | `estado` |

**Claves de un solo uso**: `acceso.clave` (6.3) y `clave` (6.12) se devuelven **una sola vez**. El programa no las guarda
en `tukan_central_operaciones`; si la operación se repite, viene `"clave": null, "clave_oculta": true` (para otra, usar
`nueva_clave_dueno`). El Central **no** las guarda ni las escribe en su auditoría: solo las muestra en pantalla.

Ejemplo de `crear_demo` (TuVocalía):

```json
{"operacion_id": "c1d2…", "accion": "crear_demo",
 "datos": {"nombre": "Liga Norte", "contacto_nombre": "Juan Pérez", "email": "juan@ejemplo.com", "telefono": "0991234567",
           "plan": "5", "dias": 7, "solicitud_id": "demo_pedida:12", "enviar_correo": true,
           "extra": {"tipo": "liga", "ciudad": "Quito"}}}
```

```json
{"ok": true, …, "operacion": "crear_demo", "repetida": false,
 "mensaje": "Demo creada: Liga Norte, hasta el 2026-10-20.",
 "resultado": {"cliente_id": "124", "prueba_hasta": "2026-10-20",
   "acceso": {"url_panel": "https://tuvocalia.com/admin.php", "usuario": "juan@ejemplo.com", "clave": "k8Ff2pQx7m"},
   "enlace_publico": "https://tuvocalia.com/liga-norte",
   "mensaje_whatsapp": "¡Hola Juan! Su liga *Liga Norte* ya está lista en TuVocalía.\n\n🌐 Página pública: https://tuvocalia.com/liga-norte\n🔐 Panel: https://tuvocalia.com/admin.php\n👤 Usuario: juan@ejemplo.com\n🔑 Contraseña: k8Ff2pQx7m\n\nLe recomendamos cambiar la contraseña al ingresar (Mi cuenta).",
   "whatsapp_url": "https://wa.me/593991234567?text=%C2%A1Hola%20Juan…",
   "correo_enviado": true},
 "cliente": { "…": "…" }}
```

---

## 7. Idempotencia y auditoría dentro del programa

### 7.1 Tabla `tukan_central_operaciones` (todos los programas con `ejecutar`)

```sql
CREATE TABLE IF NOT EXISTS tukan_central_operaciones (
    operacion_id  CHAR(32) PRIMARY KEY,
    accion        VARCHAR(40) NOT NULL,
    cliente_id    VARCHAR(80),
    cuerpo_hash   CHAR(43) NOT NULL,          -- el mismo valor que "bh"
    estado        VARCHAR(10) NOT NULL,       -- en_curso | ok | error
    http          INT,
    respuesta     TEXT,                        -- JSON de la respuesta SIN claves
    email         VARCHAR(120) NOT NULL,
    creado        TIMESTAMP NOT NULL,          -- SQLite: TEXT 'Y-m-d H:i:s'
    terminado     TIMESTAMP
);
```

Al recibir `ejecutar` (después de validar el token):
1. Buscar `operacion_id`.
   - No existe → insertar con `estado='en_curso'` y seguir.
   - Existe, `cuerpo_hash` distinto → `409 conflicto` ("Esa operación ya se usó con otros datos").
   - Existe, `en_curso` → `409 conflicto` con `"detalle":{"motivo":"en_curso"}` (el Central espera 3 s y reintenta).
   - Existe, `ok` o `error` → devolver la respuesta guardada con `repetida: true` (mismo HTTP).
2. Hacer la operación **en una transacción** (SQLite: `BEGIN IMMEDIATE`; PostgreSQL: transacción normal + `SELECT … FOR UPDATE`
   de la fila del cliente). Guardar la respuesta (sin claves) y `estado='ok'` en la misma transacción.
3. Si falla por validación (`invalido`, `no_permitido`, `conflicto` de negocio, `no_encontrado`) → guardar `estado='error'`
   con la respuesta. Si falla por excepción (`interno`) → **borrar** la fila (se puede reintentar).
4. Borrar filas de más de 90 días.

Además, las operaciones son idempotentes por naturaleza cuando se puede: suspender a un suspendido, reactivar a un activo,
cambiar al mismo plan o atender una solicitud ya atendida con el mismo resultado → `ok` con `sin_cambios: true`.

### 7.2 Tabla `tukan_central_atenciones` (solicitudes que no tienen tabla propia)

```sql
CREATE TABLE IF NOT EXISTS tukan_central_atenciones (
    solicitud_id  VARCHAR(80) PRIMARY KEY,     -- "seguimiento_prueba:123:faltan_2"
    tipo          VARCHAR(30) NOT NULL,
    estado        VARCHAR(12) NOT NULL,        -- en_proceso | atendida | descartada
    nota          VARCHAR(300),
    email         VARCHAR(120) NOT NULL,
    nombre        VARCHAR(100),
    momento       TIMESTAMP NOT NULL
);
```

Se usa para `registro_nuevo`, `pago_automatico`, `seguimiento_prueba`, `renovacion`, `dominio_gratis`,
`comision_vendedor` y `liquidacion_pendiente`. Los tipos con tabla propia (`demo_pedida`, `comprobante`, `soporte`,
`derechos_datos`) guardan el resultado **en su propia tabla** con los estados de siempre (§10).

### 7.3 Auditoría

Cada `ejecutar` correcto deja **una línea** en el registro propio del programa con quién (el `nombre <email>` del token),
qué, sobre qué cliente y el `operacion_id`:
- TuVocalía / TuAcademia: `contexto_auditoria(['usuario' => 'Panel Tukán Central · Dimitry <email>', 'rol' => 'super', 'origen' => 'tukan_central', 'liga_id' => …])`
  y `bitacora_evento('tukan_central', 'Pago registrado: Pro 6 meses $95,00 (op 4b7d2f0e)', $liga_id, $pago_id)`. La
  auditoría automática de `q()` ya guarda el antes/después de cada fila.
- TuEvento / TuSalón: `registro_admin` con `admin_id` = el súper usuario de ese correo (se busca o se crea igual que en el
  SSO, `creado_por_central = true`), `negocio_id`/`salon_id` y `accion = 'Panel Tukán Central · Pago registrado: …'`.
- TuGuardería: `bitacora`.

Las lecturas no se auditan una por una (las cifras ya se piden cada 15 minutos).

---

## 8. Programas de una sola empresa (TuGuardería)

Cada instalación es **un solo cliente** de Tukán, y la plata del programa es **de la guardería**, no de Tukán.

| Acción | ¿Aplica? | Cómo |
|---|---|---|
| `capacidades` | Sí | `"unico": true`, `acciones: ["capacidades","estado","sso","cliente","ejecutar"]`, `ejecutar: ["nueva_clave_dueno"]`, `tipos_solicitud: []` |
| `estado` | Sí | Igual que hoy (`negocios: null`, `cliente`, `extra`) |
| `sso` con `ir` | Sí | `rutas_sso: ["/"]` (ej. `/usuarios.php`) |
| `cliente` | Sí | `id: "unico"`: `nombre` = `nombre_negocio()`, `dueno` = administrador principal (`usuarios` rol `admin` más antiguo y activo, sin `creado_por_central`: nombre, `celular`, `correo`), `ultima_actividad`, `uso` (mascotas activas, con plan vigente, usuarios). Sin plan, pagos ni fechas (`null`) |
| `clientes`, `cotizar`, `planes`, `finanzas`, `solicitudes`, `adjunto` | **No** | Su `solicitudes` y sus `pagos` son de los dueños de perros; no se mandan |
| `ejecutar` | Solo `nueva_clave_dueno` (opcional) | Clave temporal para el administrador principal (`debe_cambiar_clave = 1`) |
| `suspender` / `reactivar` | Opcional, **por construir** | Bandera en `ajustes` (`licencia_suspendida`) que muestra "Servicio suspendido, comuníquese con Tukán" a todo el personal; el portal de dueños sigue |

Lo comercial de TuGuardería (cuánto paga por la licencia, hasta cuándo está pagada) lo lleva **el Central** (§9.2,
tabla `licencias`), y así sale igual en Clientes, Vencimientos y Finanzas.

---

## 9. Lo que debe agregar el Panel Central

### 9.1 Cambios internos

- `src/Token.php`: `TIPOS` agrega los tipos v2; nuevo `Token::crearV2(string $tipo, string $inst, string $email, string $nombre, string $rol, string $cuerpo, string $secreto, int $vida)`
  que agrega `bh` y usa `v:2` (vida 60 o 30). `Token::payload()` acepta `ir` para `sso`.
- `src/ClienteV2.php` (nuevo): `pedir(array $inst, string $accion, array $cuerpo, ?array $usuario): array` → arma el JSON
  (`JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES`), el token, hace el POST (curl, tiempos de §2.1) y devuelve los datos o
  lanza `ErrorPrograma(codigo, texto, http, campo, detalle)`.
- `src/Estado.php`: `clasificar()` usa primero el `codigo` del JSON (tabla de §3.2 → `secreto`, `id`, `reloj`, `repetido`,
  `version`, `sin_configurar`, `limite`, `peticion`, `error_programa` y los nuevos `no_encontrado`, `invalido`,
  `no_permitido`, `conflicto`); solo si no hay `codigo` adivina por palabras (conectores v1).
- `src/Capacidades.php` (nuevo): guarda y lee las capacidades de cada instancia; `soporta($inst, 'ejecutar', 'crear_demo')`.
- `src/Solicitudes.php`, `src/Clientes.php`, `src/Operaciones.php`, `src/Finanzas.php`, `src/Exportar.php` (nuevos).
- `public/index.php`: rutas nuevas `solicitudes`, `solicitud`, `adjunto`, `clientes`, `cliente`, `vencimientos`, `demos`,
  `demo_nueva`, `ejecutar` (solo POST), `finanzas`, `gastos`, `gasto`, `ingresos_extra`, `licencias`, `embudo`, `exportar`.
- `deploy/instalar.sh`: agrega `php8.3-zip` (para el Excel) y la tarea diaria de finanzas.
- `tests/mock/tukan_central.php`: el programa de prueba implementa todo el v2 (para `run_web.sh`).

### 9.2 Base de datos (migración a `user_version = 2`)

```sql
CREATE TABLE capacidades (instancia_id TEXT PRIMARY KEY REFERENCES instancias(id) ON DELETE CASCADE,
    protocolo INTEGER NOT NULL, datos TEXT NOT NULL, consultado INTEGER NOT NULL);

CREATE TABLE clientes (                       -- copia de lo que dicen los programas (se refresca)
    instancia_id TEXT NOT NULL REFERENCES instancias(id) ON DELETE CASCADE,
    cliente_id TEXT NOT NULL,
    nombre TEXT NOT NULL, estado TEXT NOT NULL, estado_texto TEXT, plan TEXT,
    alta TEXT, vence TEXT, dias_para_vencer INTEGER, ultima_actividad TEXT,
    total_pagado REAL NOT NULL DEFAULT 0, primer_pago TEXT, mensual_estimado REAL NOT NULL DEFAULT 0,
    origen TEXT, contacto_cif TEXT,             -- {"nombre","telefono","whatsapp","email"} cifrado con Cifrado
    datos TEXT NOT NULL,                        -- el resto del JSON (sin contacto)
    visto INTEGER NOT NULL,                     -- última vez que vino en la lista
    PRIMARY KEY (instancia_id, cliente_id));
CREATE INDEX clientes_vence ON clientes (vence);

CREATE TABLE solicitudes (
    instancia_id TEXT NOT NULL REFERENCES instancias(id) ON DELETE CASCADE,
    solicitud_id TEXT NOT NULL, tipo TEXT NOT NULL, categoria TEXT NOT NULL, estado TEXT NOT NULL, prioridad TEXT,
    cliente_id TEXT, fecha TEXT NOT NULL, titulo TEXT, resumen TEXT, monto REAL,
    quien_cif TEXT, datos TEXT NOT NULL,
    primera_vez INTEGER NOT NULL, ultima_vez INTEGER NOT NULL, avisada INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (instancia_id, solicitud_id));

CREATE TABLE operaciones (
    operacion_id TEXT PRIMARY KEY, instancia_id TEXT NOT NULL, accion TEXT NOT NULL, cliente_id TEXT, solicitud_id TEXT,
    usuario_id INTEGER, estado TEXT NOT NULL,   -- enviada | ok | error | sin_respuesta
    http INTEGER, codigo TEXT, mensaje TEXT, resumen TEXT, creada INTEGER NOT NULL, respondida INTEGER);

CREATE TABLE demos (
    id INTEGER PRIMARY KEY, instancia_id TEXT NOT NULL, cliente_id TEXT NOT NULL, nombre TEXT NOT NULL, contacto TEXT,
    plan TEXT, prueba_hasta TEXT, solicitud_id TEXT, creada_por INTEGER, creada INTEGER NOT NULL,
    primer_pago TEXT, monto_primer_pago REAL);   -- se llenan solos al refrescar clientes

CREATE TABLE recordatorios (                   -- "ya le escribí por WhatsApp"
    id INTEGER PRIMARY KEY, instancia_id TEXT NOT NULL, cliente_id TEXT NOT NULL, tipo TEXT NOT NULL,
    usuario_id INTEGER, momento INTEGER NOT NULL);

CREATE TABLE finanzas_mes (instancia_id TEXT NOT NULL, mes TEXT NOT NULL, datos TEXT NOT NULL, consultado INTEGER NOT NULL,
    PRIMARY KEY (instancia_id, mes));

CREATE TABLE gastos (
    id INTEGER PRIMARY KEY, fecha TEXT NOT NULL, mes TEXT NOT NULL, categoria TEXT NOT NULL, descripcion TEXT NOT NULL,
    monto REAL NOT NULL CHECK (monto >= 0), producto TEXT, instancia_id TEXT, recurrente_id INTEGER,
    metodo TEXT, referencia TEXT, creado_por INTEGER, creado INTEGER NOT NULL, anulado INTEGER NOT NULL DEFAULT 0);
CREATE INDEX gastos_mes ON gastos (mes);
CREATE UNIQUE INDEX gastos_recurrente_mes ON gastos (recurrente_id, mes) WHERE recurrente_id IS NOT NULL;

CREATE TABLE ingresos_extra (                  -- licencias de TuGuardería, instalaciones, servicios
    id INTEGER PRIMARY KEY, fecha TEXT NOT NULL, mes TEXT NOT NULL, concepto TEXT NOT NULL, monto REAL NOT NULL CHECK (monto >= 0),
    producto TEXT, instancia_id TEXT, recurrente_id INTEGER, metodo TEXT, creado_por INTEGER, creado INTEGER NOT NULL,
    anulado INTEGER NOT NULL DEFAULT 0);
CREATE UNIQUE INDEX ingresos_recurrente_mes ON ingresos_extra (recurrente_id, mes) WHERE recurrente_id IS NOT NULL;

CREATE TABLE recurrentes (                     -- gastos e ingresos fijos de cada mes
    id INTEGER PRIMARY KEY, tipo TEXT NOT NULL CHECK (tipo IN ('gasto','ingreso')), categoria TEXT, descripcion TEXT NOT NULL,
    monto REAL NOT NULL, dia INTEGER NOT NULL CHECK (dia BETWEEN 1 AND 28), producto TEXT, instancia_id TEXT,
    desde_mes TEXT NOT NULL, hasta_mes TEXT, activo INTEGER NOT NULL DEFAULT 1, creado INTEGER NOT NULL);

CREATE TABLE licencias (                       -- programas de una sola empresa (TuGuardería)
    instancia_id TEXT PRIMARY KEY REFERENCES instancias(id) ON DELETE CASCADE,
    valor_mensual REAL NOT NULL DEFAULT 0, activo_hasta TEXT, contacto_cif TEXT, notas TEXT, actualizado INTEGER NOT NULL);
```

- Ajustes nuevos (`ajustes`): `avisar_solicitudes` (1), `avisar_tipos` (lista), `silencio_desde` (`22:00`),
  `silencio_hasta` (`07:00`), `resumen_diario` (1, a las 07:30), `repartir_gastos_generales` (0),
  plantillas de WhatsApp `wa_recordar_prueba`, `wa_recordar_renovacion`, `wa_vencido`, `wa_bienvenida`.
- Respaldo diario: igual que hoy (son tablas del mismo archivo).

### 9.3 Permisos (roles de hoy: dueño y soporte)

| Permiso nuevo | Dueño | Soporte |
|---|---|---|
| Ver solicitudes y clientes (sin contacto) | ✔ | ✔ |
| Ver contacto (teléfono, correo) y botón WhatsApp | ✔ | ✖ (ve `09•••••67`, `j•••@ejemplo.com`) |
| `atender_solicitud`, `responder_ticket` | ✔ | ✔ |
| `extender_prueba`, `crear_demo`, `nueva_clave_dueno`, `nota_interna` | ✔ | ✖ |
| `registrar_pago`, `aprobar/rechazar_comprobante`, `anular_pago`, `suspender`, `reactivar`, `cambiar_plan`, `guardar_plan` | ✔ | ✖ |
| Finanzas, gastos, ingresos extra, exportar | ✔ | ✖ |

`anular_pago` y `guardar_plan` piden otra vez el **código de 2 pasos** (son raros y delicados). Todo `ejecutar` queda en
la auditoría del Central (`auditoria`, acción `ejecutar`, con el resumen, nunca con claves).

### 9.4 Revisión automática (cron cada 15 minutos, `bin/revisar.php`)

1. `estado` de todos (como hoy).
2. Si cambió la `version` o pasó un día → `capacidades`.
3. Si `solicitudes_huella` cambió (o el programa no la manda y es v2) → `solicitudes` (`abiertas`). Compara con la tabla
   `solicitudes`: las nuevas se insertan (`primera_vez`), las que ya no vienen se marcan cerradas.
4. **Aviso por Telegram** de cada solicitud **nueva** de tipo `pedido` (y de los tipos marcados en Ajustes), fuera del
   horario de silencio (las de la noche salen juntas a las 07:00). Si hay más de 5 nuevas a la vez, un solo mensaje con
   el resumen. Ejemplos:
   - `📝 TuVocalía · Pedido de demo: Liga Norte (Quito). Juan Pérez · 099•••4567. Abrir: https://panel.tukanec.com/?r=solicitud&i=tuvocalia&id=demo_pedida:12`
   - `💵 TuAcademia · Comprobante por revisar: Academia Ritmo · Completo 6 meses · $145,00. Abrir: …`
   - `🆕 TuEvento · Se registró solo: Quinta Los Álamos (prueba hasta el 20/10). Abrir: …`
   - `🎫 TuSalón · Ticket urgente: …`
   (El enlace lleva al Central, que pide entrar con 2 pasos si no hay sesión; el número va enmascarado.)
5. `clientes` de cada programa **cada hora** (todas las páginas), y además enseguida después de cualquier `ejecutar` en
   ese programa (`ids` del cliente) o si `estado.negocios` no coincide con los contadores guardados.
   Al refrescar: llena `demos.primer_pago` cuando una demo creada desde el Central paga por primera vez y avisa por
   Telegram `🎉 ¡La demo de Liga Norte pagó! $19,00 (TuVocalía)`.
6. Una vez al día (03:10): `finanzas` de los últimos 24 meses de cada programa → `finanzas_mes`; y crea los gastos e
   ingresos **fijos** del mes (`recurrentes` → `gastos` / `ingresos_extra`, una sola vez por mes gracias al índice único).
7. A las 07:30, si `resumen_diario`: `☀️ Buenos días. Hoy: 3 solicitudes por atender, 2 pruebas terminan, 1 plan vence. Cobrado en octubre: $1.240 · Gastos: $310.`

### 9.5 Pantallas (para Dimitry, en palabras sencillas)

Menú nuevo (escritorio arriba; celular abajo con 4 botones + "Más"): **Inicio · Solicitudes (número rojo) · Clientes ·
Finanzas · Más** (Vencimientos, Demos, Embudo, Programas, Auditoría, Usuarios, Ajustes, Ayuda).

**A. Inicio (cambia)**
- Arriba, cuatro cifras grandes: *Por atender* (solicitudes abiertas), *Vencen esta semana*, *Cobrado este mes*,
  *Ganancia del mes* (cobrado − gastos).
- "Para atender hoy": las 5 solicitudes más urgentes (comprobantes y pedidos primero), cada una con su botón principal.
- Las tarjetas de cada programa siguen como hoy; abajo de cada una: "3 solicitudes · 2 vencen esta semana".
- Si un programa todavía es v1: etiqueta gris "Solo cifras (actualiza el programa para ver solicitudes y clientes)".

**B. Solicitudes (bandeja única)**
- Para qué: ver **todo lo que piden** los clientes de todos los programas, en un solo lugar, y atenderlo con un toque.
- Arriba, pestañas con número: *Por atender* · *En proceso* · *Atendidas*. Debajo, chips para filtrar: *Todo*,
  *Pagos por aprobar*, *Pedidos de demo*, *Registros nuevos*, *Soporte*, *Recordatorios*; y un selector de programa.
  Buscador por nombre o teléfono.
- Cada solicitud es una tarjeta: icono del tipo, color del programa, "hace 2 horas", **quién** y su negocio, el resumen
  ("Plan Pro · 6 meses · $95"), la miniatura del comprobante si hay, y **botones grandes** (los de `acciones_posibles`):
  *Aprobar y renovar*, *Rechazar*, *Crear demo*, *WhatsApp*, *Responder*, *Marcar atendida*, *Ver en el programa*.
- Al tocar un botón con campos, se abre una hoja abajo (celular) o una ventana (escritorio) con el formulario ya lleno.
  Al confirmar: "Listo: Liga Norte queda activa hasta el 13/04/2027" y la tarjeta pasa a *Atendidas*.
- Si algo falla, el mensaje del programa aparece en rojo junto al campo; si el programa no contesta: "TuVocalía no
  respondió. Toca *Revisar* para ver si el pago se registró" (reenvía la misma operación, sin duplicar).
- Tocar la tarjeta abre el **detalle**: todos los datos, la foto en grande, la conversación del ticket y el historial.
- Vacío: "No hay nada por atender. 👏".

**C. Clientes**
- Para qué: ver **a todos los clientes de todos los programas** y saber quién está por vencer.
- Buscador grande ("nombre, teléfono o correo"). Filtros: programa, estado (chips con número: *En prueba 4 · Al día 6 ·
  Por vencer 1 · Vencidos 1 · Suspendidos 0*), plan, y **"Se vence en: 7 días · 15 días · 30 días"**, y *"Sin usar el
  programa hace 15 días"* (los que están en riesgo de irse).
- Lista (tabla en escritorio, tarjetas en celular): nombre con el color del programa, estado, plan, **vence** ("20/10 ·
  faltan 7 días"), último uso, total pagado, y botones: **📲 Recordar por WhatsApp** (mensaje ya escrito según su estado:
  prueba por terminar, plan por vencer o vencido), **Ficha**, **Entrar** (abre el programa en la ficha de ese cliente).
- Si ya se le recordó: "Recordado hace 2 días por Dimitry" (para no escribir dos veces).
- Orden por defecto: los que vencen primero.

**D. Ficha del cliente**
- Encabezado: nombre, programa, estado en grande con su color, plan, vence, desde cuándo es cliente, de dónde vino
  ("Pidió una demo", "Se registró solo").
- Contacto del dueño (solo el rol dueño) con botones *Llamar*, *WhatsApp*, *Correo*.
- Botones (los que el programa permita): **Registrar pago**, **Alargar prueba**, **Cambiar plan**, **Suspender** /
  **Reactivar**, **Nueva clave del dueño**, **Nota interna**, **Abrir en el programa**.
- **Registrar pago**: plan, tiempo (1 mes, 6 meses…), valor (se llena solo con `cotizar`; se puede cambiar), forma de
  pago, fecha, nota. Antes de guardar: "Vas a registrar **$95,00** de **Liga Norte**. Quedará activa hasta el
  **13/04/2027**. ¿Confirmas?".
- Historial de pagos (con *Anular* en rojo, que pide el código de 2 pasos), solicitudes abiertas de este cliente y las
  últimas acciones ("13/10 09:15 · Dimitry desde el Panel Tukán: alargó la demo hasta el 27/10").
- Nueva clave: se muestra **una vez**, con botón "Enviar por WhatsApp".

**E. Vencimientos (calendario)**
- Para qué: ver de un vistazo **qué día vence cada prueba y cada plan**.
- Calendario del mes (en el celular, lista por semanas). Cada día muestra los nombres: amarillo = prueba que termina,
  verde = plan que vence, rojo = ya venció. Arriba una franja: "**Ya vencieron sin renovar: 3**" (tocar → lista).
- Al lado (abajo en el celular): "Esta semana: 5 vencen · $230 al mes en juego" (suma de `mensual_estimado`).
- Filtro por programa. Tocar un nombre abre su ficha; cada uno tiene su botón WhatsApp.
- TuGuardería y otros de una sola empresa salen con la fecha de su licencia (tabla `licencias`).

**F. Demos**
- **Crear demo** (botón grande): 1) elegir el programa (solo los que pueden crear demos); 2) llenar: nombre del negocio,
  nombre del contacto, celular, correo, plan, días de prueba (ya puesto: 7), los campos propios del programa (ej.
  TuVocalía: liga o escuela) y "Enviarle el correo de bienvenida"; 3) resultado: "✅ Demo lista" con los accesos, botones
  **Enviar por WhatsApp**, **Copiar** y **Entrar a la demo**. La clave se ve solo aquí.
- Desde un *Pedido de demo* el formulario viene lleno.
- **Historial**: todas las demos (las creadas desde el Central y las de los programas con origen demo o registro), con
  su estado: *En prueba (faltan 3 días)*, *Pagó ✔ (20/10, $19)*, *Terminó sin pagar*. Arriba: "Este mes: 8 demos ·
  3 pagaron (**38 %**) · tardan 5 días en promedio en pagar", y lo mismo por programa.

**G. Finanzas**
- Para qué: saber **cuánto entra, cuánto sale y cuánto queda**, por mes y por programa.
- Arriba: selector *Últimos 12 meses / 24 meses / este año*. Cuatro cifras: *Ingresos*, *Gastos*, *Ganancia*,
  *Ingreso mensual recurrente*.
- Barras por mes (ingresos verde, gastos rojo, línea de ganancia), dibujadas con CSS/SVG del servidor (sin librerías).
- **Tabla por programa** (filas) y mes (columnas): ingresos, costos del programa (comisiones que informa el programa),
  gastos asignados, **ganancia**. Fila "Gastos generales" (los sin programa) y fila **Total**. Interruptor
  "Repartir gastos generales según lo que gana cada programa" (proporcional a los ingresos del mes).
- Detalle de ingresos de un programa: por plan, por tiempo (mensual, semestral…) y por forma de pago.
- **Gastos**: lista del mes con *+ Agregar gasto*: fecha, categoría (**Servidores/VPS · Dominios · Google Play/Apple ·
  Publicidad · Comisiones de pasarela · Sueldos/servicios · Otros**), descripción, valor, programa (opcional: "General"
  por defecto) y casilla **"Se repite cada mes"** (con el día). Los fijos se ven en *Gastos fijos* (pausar, cambiar,
  terminar). Los costos que informa cada programa salen aparte con la etiqueta "(lo informa TuVocalía)" para no
  anotarlos dos veces.
- **Ingresos extra**: lo que se cobra fuera de los programas (licencias de TuGuardería, instalaciones, capacitaciones),
  también con "se repite cada mes".
- **Descargar Excel** (`.xlsx` con hojas *Resumen*, *Por programa*, *Ingresos*, *Gastos*, *Ingresos extra*) y
  **Descargar CSV** (UTF-8 con BOM, separado por `;`, decimales con coma, para Excel en español). Se puede reutilizar
  `crear_xlsx_libro()` de TuVocalía (`app/excel.php`, usa `ZipArchive`).

**H. Embudo**
- Para qué: ver **cuántos interesados se vuelven clientes que pagan**.
- Por mes (y por programa): *Pedidos de demo y registros* → *Demos/pruebas creadas* → *Empezaron a pagar* → *Siguen
  pagando hoy*, con el porcentaje de cada paso y barras.
- Debajo: "Pruebas que terminaron sin pagar (últimos 60 días)" con WhatsApp y *Alargar prueba*; y "Clientes en riesgo"
  (sin usar el programa hace 15 días, de los que pagan).
- Datos: `clientes` (alta, origen, primer pago, estado) y `solicitudes` (`demo_pedida`, `registro_nuevo`).

**I. Programas (ficha, cambia)**
- Nueva sección "Qué puede hacer desde el panel": lista con ✔/✖ (solicitudes, clientes, crear demos, registrar pagos,
  finanzas…). Si falta algo: "Actualiza TuEvento para tener esto".
- TuGuardería: sección **Licencia**: valor al mes, pagada hasta, contacto, *Registrar pago de licencia* (crea un ingreso
  extra y mueve la fecha).

**J. Ajustes (cambia)**
- Avisos por Telegram: qué tipos de solicitudes avisar, horario de silencio, resumen diario.
- Mensajes de WhatsApp (plantillas con `{nombre}`, `{negocio}`, `{programa}`, `{vence}`, `{monto}`), que se usan solo
  si el programa no manda su propio mensaje.
- Repartir gastos generales: sí/no.

### 9.6 Categorías de gastos

`servidores` (Servidores/VPS), `dominios` (Dominios), `tiendas_apps` (Google Play/Apple), `publicidad` (Publicidad),
`comisiones_pasarela` (Comisiones de pasarela), `sueldos_servicios` (Sueldos/servicios), `otros` (Otros).
Los `costos[].categoria` de `finanzas` usan estas mismas claves.

**Ganancia** de un programa en un mes = `ingresos` + `otros_ingresos` (de `finanzas`) + ingresos extra asignados −
`costos` informados − gastos asignados (− su parte de los gastos generales si se reparten). **Ganancia total** = suma de
todos los ingresos − todos los costos y gastos.

### 9.7 Copia local y datos personales

- El Central guarda la última copia de clientes, solicitudes y finanzas para que las pantallas abran rápido aunque un
  programa esté caído ("Datos de hace 40 minutos"). Botón *Actualizar* pide de nuevo.
- Teléfonos y correos se guardan **cifrados** (`contacto_cif`, `quien_cif`, con `Cifrado`) y se descifran solo al mostrarlos
  al rol dueño. Nunca van a los registros ni a Telegram completos.
- Los clientes que el programa ya no manda se borran de la copia a los 7 días.

---

## 10. Tabla por programa: qué existe y qué hay que construir

Leyenda: **Existe** = ya está en el código (usar tal cual); **Construir** = falta.

### 10.1 TuVocalía y TuAcademia (`app/tukan_central.php`, mismo archivo para las dos)

| v2 | Cómo se arma con lo que existe | Construir |
|---|---|---|
| Cliente | `ligas` (`COALESCE(es_demo,0)=0`) `LEFT JOIN planes`. `id`=`ligas.id`; `tipo`=`ligas.tipo` (`federacion` si `es_federacion`); `estado` con `estado_suscripcion()` + `activa` **exactamente como `tc_estado()`**: `activa=0`→`suspendido`; `prueba`→`prueba`; `activa` con `dias ≤ 7`→`por_vencer`, si no `activo`; `sin_limite`→`activo`; `gracia`→`vencido` (`en_gracia`); `vencida`→`vencido` (`estado_detalle` `prueba_vencida` si `es_prueba`). `prueba_hasta`/`activo_hasta` = `vence` según `es_prueba`. `dueno` = `contacto_nombre/telefono/email` (si faltan: el usuario `rol='liga'`, `perfil='admin'` más antiguo); `whatsapp` = `telefono_wa()`. `ultima_actividad` = `MAX(usuarios.ultimo_ingreso)` de la cuenta. `pagos` de `pagos_suscripcion`. `mensual_estimado` como `negocio.php`. `uso` de `uso_cuenta()` (equipos/campeonatos o alumnos/grupos). `vendedor` de `vendedores`. `ir` = `/admin.php?p=ligas&editar={id}`, `ir_trabajar` = `/admin.php?p=ligas&entrar={id}` | Columna `ligas.origen TEXT NOT NULL DEFAULT ''` (llenarla en `crear_liga_completa()`: `registro`, `demo_pedida`, `super`, `central`). Para las viejas: `registro` si hay `consentimientos.formulario='registro'` con ese `liga_id`; `demo_pedida` si alguna `solicitudes_demo.liga_id` es ella; si no `super` |
| `clientes` | Consulta de arriba; filtro de estado y orden en PHP (son pocas filas) y luego paginar | Función `tc2_clientes()` |
| `cliente` | + `pagos_suscripcion` (con `planes`), `referidos`, `combo_de()`, `url_liga()`, últimas 20 filas de `bitacora` con `liga_id` y `operacion='evento'`. `mensajes_sugeridos`: `mensaje_seguimiento_demo()`, texto de "Recordar" de `suscripciones.php` y de `negocio.php` con `texto_valor_renovacion()` | `ligas.notas_admin TEXT NOT NULL DEFAULT ''` |
| `cotizar` | `opciones_precio($pl, $liga)` (combo y módulos deportivos); para meses libres, el mismo cálculo del formulario de Ventas (precio del período, o mensual × meses); vigencia con la regla de `registrar_pago_suscripcion()` | Extraer esa regla a `vigencia_pago($l, $meses)` |
| `planes` | `planes` + `opciones_precio()` + `textos_limites_plan()` + `ajuste()` de `dias_prueba`, `dias_demo`, `dias_gracia`, `dias_gracia_demo` y `registro_abierto`. `planes_editar: false` | — |
| `solicitudes` `demo_pedida` | `solicitudes_demo` (`nueva`→`pendiente`, `contactada`→`en_proceso`, `creada`→`atendida`, `descartada`→`descartada`). `quien` = contacto/telefono/email/organizacion; `detalle`: tipo, ciudad, tamaño, plan de interés, deportes, mensaje, vendedor, nota. WhatsApp: `mensaje_contacto_demo()`. `crear_demo` con todos los campos llenos | — |
| `solicitudes` `registro_nuevo` | `ligas` con `consentimientos.formulario='registro'` (o `origen='registro'`) y `creado` ≤ 30 días | Atención en `tukan_central_atenciones` |
| `solicitudes` `comprobante` | `solicitudes_pago` (`pendiente`/`aprobada`/`rechazada`) `JOIN ligas`, `planes`. `cambia_plan` = `plan_id ≠ ligas.plan_id` o `deportes` distintos de `deportes_cobrados()`. Adjunto `archivo` (como `servir_comprobante()`) | — |
| `solicitudes` `soporte` | `soporte_tickets` (`nuevo`→`pendiente`; `en_proceso`/`esperando`→`en_proceso`; `resuelto`/`cerrado`→`atendida`; con `sin_leer_super=1` siempre `pendiente`). `subtipo`=`tipo`; prioridad `urgente`/`alta`→`alta`. Mensajes de `soporte_mensajes`, adjuntos `fotos/…` | — |
| `solicitudes` `derechos_datos` | `solicitudes_derechos` con `liga_id IS NULL` (`nueva`→`pendiente`, `en_proceso`, `respondida`→`atendida`, `rechazada`→`descartada`); `vence_el` = `creado` + 15 días | — |
| `solicitudes` `pago_automatico` | `pagos_suscripcion` de 30 días con `registrado_por LIKE 'Pago en línea%'` o `forma_pago='google_play'` | Atención |
| `solicitudes` `seguimiento_prueba` | Mismas reglas que `avisos_demos()` (`dias` = 2 → `faltan_2`, 0 → `vence_hoy`) y de `embudo.php` (vencidas ≤ 60 días → `terminada`). WhatsApp `mensaje_seguimiento_demo()` | Atención |
| `solicitudes` `renovacion` | `ligas` pagadas: `dias` 0–5 → `vence_pronto`; `gracia`/`vencida` ≤ 30 días → `vencida`. Texto como `avisos_diarios()` | Atención |
| `solicitudes` `dominio_gratis` | `pagos_suscripcion` `meses ≥ 12` `JOIN planes dominio_anual=1` con `ligas.dominio=''` | Atención (campo `dominio` se guarda en la nota; activarlo sigue en Ligas → Editar porque necesita `dominio.sh`) |
| `solicitudes` `comision_vendedor` | `comisiones.estado='pendiente'` `JOIN vendedores` | Atención |
| `solicitudes` `liquidacion_pendiente` | Igual que `pasarela.php`: `pagos_linea destino='organizacion' estado='pagado' liquidacion_id IS NULL`, por organización; id `liquidacion_pendiente:{liga_id}:{MAX(id)}` | Atención |
| `finanzas` | `pagos_suscripcion` `JOIN ligas` (`es_demo=0`) por `substr(fecha,1,7)`, `plan_id`, `meses` (1→mensual, 3→trimestral, 6→semestral, 12→anual, otro→"N meses"), `forma_pago`. `devengado`: repartir `monto` entre `desde`–`hasta`. `otros_ingresos.gestion_cobros` = `SUM(pagos_linea.comision_tuvocalia)` (`destino='organizacion'`, `estado='pagado'`, por `pagado_en`). `costos`: `comision_pasarela` = `SUM(comision_pasarela)` de `pagos_linea destino='tuvocalia'`; `comision_vendedores` = `SUM(comisiones.comision)` por `creado`; TuAcademia: `google_play` estimado 15 % de los pagos `forma_pago='google_play'` (`estimado: true`) | `tc2_finanzas()` |
| `crear_demo` | `crear_liga_completa()` con `vence = hoy + dias` (por defecto `ajuste('dias_demo', 7)`), `es_prueba=1`, `crear_campeonato=true`, clave al azar de 10 caracteres (como `ligas.php`), `tipo`/`ciudad`/`modalidad`/`deportes` de `extra` (TuAcademia: siempre `escuela`). Con `solicitud_id`: mismo `UPDATE solicitudes_demo … 'creada'` y `anotar_referido()` que `ligas.php`; `vendedor_id` del pedido. `correo_bienvenida()` si `enviar_correo`. `mensaje_whatsapp` = el mismo texto de `ligas.php?creada=` | Sacar a una función `crear_demo_cuenta(array $d, string $por): array` que usen `ligas.php` y el conector; `origen` |
| `extender_prueba` | `embudo.php` `alargar` (`vence`, `es_prueba=1`, `demo_avisos=''`, bitácora) | Función compartida; permitir 1–90; **negar** si la cuenta ya pagó (hoy `embudo` pondría `es_prueba=1` a una pagada) |
| `registrar_pago` | `registrar_pago_suscripcion()` + `correo_pago_registrado()` (igual que `suscripciones.php`). `meses` de `periodo` o directo; `metodo` ∈ `formas_pago()`; `por` = "Panel Tukán Central (Dimitry)" | Función `venta_registrar_pago()` que use también `suscripciones.php` |
| `anular_pago` | `suscripciones.php` `anular_pago` (niega si la comisión está pagada) | Al borrar, si `hasta` del pago = `ligas.vence`, volver `vence` al `MAX(hasta)` de los pagos que quedan (o dejarla y avisar en `mensaje` si no quedan pagos) |
| `aprobar_comprobante` / `rechazar_comprobante` | `aprobar_solicitud_pago()` / `rechazar_solicitud_pago()` (ya niegan si no está pendiente → devolver `conflicto`) | — |
| `suspender` / `reactivar` | `ligas.activa` (ya cuenta como suspendido en `estado`) | **Suspensión real**: `ligas.suspendida_motivo TEXT NOT NULL DEFAULT ''`; `liga_puede_editar()` y `exigir_suscripcion()` devuelven "Cuenta suspendida. Comuníquese con …" si `activa=0`; aviso arriba en el panel de la cuenta. Reactivar = `activa=1`, motivo vacío |
| `cambiar_plan` | `UPDATE ligas SET plan_id` (parte de `accion=suscripcion` en `ligas.php`) | Función propia con validación de `tipo_cuenta` y bitácora `ventas` |
| `nueva_clave_dueno` | `ligas.php` `admin_clave` | Elegir el usuario (`rol='liga'`, `perfil='admin'`, primero el del `contacto_email`); clave de 10 caracteres |
| `nota_interna` | — | `ligas.notas_admin` |
| `atender_solicitud` | `demo_pedida`: `UPDATE solicitudes_demo SET estado, nota, atendido_por, atendido_en` (como `demos.php`; `en_proceso`→`contactada`, `descartada`→`descartada`, `atendida`→`contactada` si no se creó demo). `soporte`: `UPDATE soporte_tickets SET estado` (`atendida`→`resuelto`, `descartada`→`cerrado`, `en_proceso`→`en_proceso`, `sin_leer_super=0`). `derechos_datos`: lo mismo que `datos.php` `responder` (`respuesta` obligatoria). `comprobante`: no (usar aprobar/rechazar → `no_permitido`). Resto: `tukan_central_atenciones` | — |
| `responder_ticket` | `responder_ticket($t, 'soporte', $texto, '', $estado)` | Poner `contexto_auditoria`/`usuario()` del Central (la función usa `usuario()` para el autor) |
| `sso` con `ir` | `tc_sso()` | Después de `ir('admin.php')` → `ir($ir)` si pasa la regla; `rutas_sso: ["/admin.php"]` |
| `adjunto` | `solicitudes_pago.archivo`, `soporte_mensajes.adjunto` (en `RAIZ/data/`) | Leer, revisar ruta (sin `..`), `base64_encode` |
| Tablas v2 | `tukan_nonces` (existe) | `tukan_central_operaciones`, `tukan_central_atenciones` (crear solas como `tc_preparar()`) |
| Límite | `demasiados_intentos('tukan_central', 30)` | 120 en total y 30 rechazados por minuto |

### 10.2 TuEvento (`src/TukanCentral.php`, `src/PanelTukan.php`, `src/Planes.php`)

| v2 | Cómo se arma con lo que existe | Construir |
|---|---|---|
| Cliente | `PanelTukan::negocios()` / `negocio()` (`SQL_NEGOCIOS` ya trae dueño, `ultima_actividad`, uso) + `Planes::estadoCuenta()`. Estado v2: `prueba`→`prueba`; `prueba_vencida`→`vencido`; `activo`→`por_vencer` si `activo_hasta` ≤ hoy+7, si no `activo` (sin fecha → `activo`); `por_vencer` (gracia)→`vencido` con `en_gracia`; `vencido`→`vencido`; `suspendido`→`suspendido`; `cancelado`→`suspendido` (`estado_detalle: cancelado`). `bloqueado` = `!alDia()`. `dueno`: `u.nombre`, `n.whatsapp ?: n.telefono ?: u.telefono`, `u.email`; `whatsapp` con `WhatsApp::normalizarTelefono()`. `pagos` de `suscripciones` (`NOT anulada`). `mensual_estimado` = `monto / meses_recibidos` del pago vigente. `uso`: espacios, eventos (30 días), vendidos. `ir` = `/?r=admin_negocio&id={id}` | `negocios.origen VARCHAR(12)` (en `Altas`: `registro` desde `registro.php`, `super` desde `admin_nuevo`, `central`). Viejos: `super` si hay `registro_admin` "Negocio creado desde el panel%", si no `registro` |
| `estado` v1 | `TukanCentral::estado()` | **Alinear** con §4.2: gracia a `vencidos`, activos ≤ 7 días a `por_vencer` |
| `clientes` | `negocios($filtro, $buscar, null, $plan)` y luego filtro/orden/paginado en PHP | — |
| `cliente` | `negocio()`, `pagos($id)`, `registro($id, 20)`, `notas_admin`. `mensajes_sugeridos`: los textos de `app/vistas/admin.php` (prueba por terminar, plan por vencer, sin pagar) | — |
| `cotizar` | `Planes::cotizar($plan, $periodo)` + `inicioSugerido()` | — |
| `planes` | `Planes::lista()`, `periodos()`, `ajustes()` (`dias_prueba`, `dias_gracia`). `planes_editar: true` | — |
| `solicitudes` | `registro_nuevo` (negocios ≤ 30 días con origen `registro`), `seguimiento_prueba` (`prueba` ≤ 2 días → `faltan_2`, hoy → `vence_hoy`; `prueba_vencida` ≤ 60 días → `terminada`), `renovacion`, `dominio_gratis` (`suscripciones.incluye_dominio AND dominio IS NULL AND NOT anulada`) | `tukan_central_atenciones`. **Opcional** (para tener `comprobante` y `soporte` como TuVocalía): subir comprobante desde `prueba_terminada` y un formulario de ayuda |
| `finanzas` | `suscripciones` `NOT anulada` por `to_char(pagado_en,'YYYY-MM')`, `plan_codigo`, `periodo`, `metodo_pago`; `devengado` con `inicio`–`fin`; `mrr` = `ingresoMensual()` | — |
| `crear_demo` | `Altas::crearNegocioConDueno()` (prueba con `dias_prueba`, datos de ejemplo) con `Usuarios::claveTemporal()` y `clave_temporal = true` (como `admin_nuevo.php`). Si `dias` ≠ `dias_prueba`: `UPDATE negocios SET prueba_hasta = CURRENT_DATE + dias`. `extra`: `ciudad`. `enlace_publico` = `url_completa('sitio', ['s' => slug])`; `url_panel` = `url_completa('login')` | Correo de bienvenida (no existe; `correo_enviado: false` hasta que exista); `origen` |
| `extender_prueba` | `PanelTukan::extenderPrueba()` (1–90) | Negar si tiene pagos y está al día |
| `registrar_pago` | `PanelTukan::registrarPago()` → `Planes::suscribir()` (`plan`, `periodo` mensual/semestral/anual, `inicio` = `inicioSugerido()` si no viene, `metodo` ∈ `PanelTukan::METODOS`, `monto`, `dominio`) | `Planes::suscribir()` debe recibir la **fecha del pago** (`pagado_en`) |
| `anular_pago` | `Planes::anularPago()` (ya recalcula `activo_hasta`) | Revisar que el pago sea del negocio (como `admin_negocio.php`) |
| `suspender` / `reactivar` / `cancelar` | `PanelTukan::cambiarEstado()` (`suspendido`, `cancelado`; reactivar → `activo` si `activo_hasta` existe, si no `prueba`) | Guardar el motivo en `notas_admin` o en `registro_admin` |
| `cambiar_plan` | `PanelTukan::cambiarPlan()` | — |
| `nueva_clave_dueno` | `PanelTukan::nuevaClaveDueno()` | — |
| `nota_interna` | `PanelTukan::guardarNotas()` | — |
| `guardar_plan` | `Planes::guardarPlan()` (+ `guardarAjustes()` si vienen ajustes) | — |
| `atender_solicitud` | — | `tukan_central_atenciones` |
| `sso` con `ir` | `public/tukan_central.php` | `redirigir()` a `ir` si empieza con `/?r=admin`; `rutas_sso: ["/?r=admin"]` |
| Auditoría | `registrar_admin()` necesita `superadmin()` en sesión | Versión que reciba el `admin_id` del súper del token (`TukanCentral::superUsuario()`) |
| Tablas v2 | `tukan_central_nonces`, `tukan_central_pedidos` (existen, migración 095) | Migración `096_tukan_central_v2.sql`: `tukan_central_operaciones`, `tukan_central_atenciones`, `negocios.origen` |

### 10.3 TuSalón (igual que TuEvento, con estas diferencias)

| Tema | TuSalón |
|---|---|
| Tabla del cliente | `salones` (no `negocios`), `suscripciones.salon_id`, `registro_admin.salon_id`; métodos `PanelTukan::salones()` / `salon()` |
| Contacto | `salones.telefono` y el usuario dueño (`u.email`; no hay `whatsapp` ni `email` en `salones`) |
| Uso | peluqueros, citas (30 días), clientes |
| Planes | `basica` ($25), `completa` ($35); `Planes::cotizar()` igual |
| Pagos automáticos | Google Play: `suscripciones.metodo_pago='google_play'` (`GooglePlay::aplicar()`) → `pago_automatico` y costo `tiendas_apps` estimado 15 % (`GooglePlay::precioSugerido()` usa 0,15) |
| `registrar_pago` | La lógica está en `app/paginas/admin_salon.php` (no en `PanelTukan`): **pasarla** a `PanelTukan::registrarPago()` como en TuEvento |
| `cambiarEstado()` | **Revisar** `rowCount()` (hoy no avisa si el salón no existe) |
| `nuevaClaveDueno()` | No marca clave temporal (no existe la columna): aceptable; el mensaje dice "cámbiala al entrar" |
| `crear_demo` | `Altas::crearSalonConDueno()` (sin ciudad; 5 servicios de ejemplo y horario) |
| Enlace público | `/<slug>` (la dirección pública del salón) |
| `ir` | `/?r=admin_salon&id={id}`; `rutas_sso: ["/?r=admin"]` |
| Migración | `005_tukan_central_v2.sql` |

### 10.4 TuGuardería (`app/central.php`)

| v2 | Existe | Construir |
|---|---|---|
| `capacidades` | — | Respuesta fija de §8 |
| `sso` con `ir` | `tc_admin_para()` + redirección | Aceptar `ir` (`rutas_sso: ["/"]`) |
| `cliente` (`id: "unico"`) | `nombre_negocio()`, `usuarios` (`rol='admin'`, `celular`, `correo`), cifras de `tc_estado()` | Función `tc2_cliente()` |
| `nueva_clave_dueno` | `usuarios.debe_cambiar_clave` | Operación + `tukan_central_operaciones` (MariaDB) |
| Suspender licencia | — | Opcional (§8) |

---

## 11. Pruebas obligatorias de cada conector v2

Además de las de v1 (`PROTOCOLO.md` §6):

1. `capacidades` devuelve JSON válido y solo acciones que de verdad funcionan.
2. Token v2 **sin** `bh`, con `bh` de otro cuerpo, o con el cuerpo cambiado un byte → `401 firma`.
3. GET a una acción v2 → `405 invalido`. Cuerpo que no es JSON → `400 invalido`.
4. `ejecutar` con `exp - iat = 31` → `invalido`; token repetido → `nonce`; `v:1` en una acción v2 → `version`.
5. Todos los errores traen `codigo` de la lista y `error` en español; ningún `interno` muestra detalles técnicos.
6. `clientes.contadores` = `estado.negocios` (mismos números) con clientes en los 5 estados, incluido uno en gracia y uno
   que vence en 7 días exactos.
7. `ejecutar registrar_pago` dos veces con el mismo `operacion_id` (tokens distintos) → un solo pago; la segunda con
   `repetida: true`. Mismo `operacion_id` con otro monto → `409 conflicto`.
8. `crear_demo` repetido no crea dos cuentas y la segunda respuesta trae `clave: null`.
9. `aprobar_comprobante` de uno ya aprobado → `409 conflicto`; `extender_prueba` de un cliente que paga → `403 no_permitido`.
10. `atender_solicitud` de un recordatorio → ya no sale en `abiertas`; la siguiente etapa sí sale.
11. `adjunto` de otra solicitud o con `..` → `404 no_encontrado`.
12. `sso` con `ir` válido llega a esa pantalla; con `ir` = `//malo.com`, `https://…` o fuera de `rutas_sso` → inicio.
13. Cada `ejecutar` deja su línea en la bitácora / `registro_admin` con el correo del Central.
14. Límite: 121 pedidos en un minuto → `429 limite` con `Retry-After`.
15. Un Central v1 sigue funcionando con el conector v2 (estado y SSO).

En el Central: `tests/run_tests.php` (token v2 y `bh` byte a byte, `clasificar()` con cada `codigo`, idempotencia del
cliente HTTP, cálculo de ganancia y de gastos fijos), `tests/run_web.sh` (pantallas nuevas con el programa de prueba,
sin errores de consola y sin desbordar a 390 px) y `tests/integracion.sh` (conectores reales de TuEvento y TuSalón).

---

## 12. Orden de trabajo sugerido

1. **Central base v2**: `Token::crearV2`, `ClienteV2`, `codigo` en `clasificar()`, `capacidades`, migración de base,
   programa de prueba v2.
2. **Solo lectura** en un programa (TuEvento es el más simple): `capacidades`, `clientes`, `cliente`, `planes`,
   `finanzas`, `sso` con `ir`, alinear `estado`. En el Central: **Clientes**, **Ficha**, **Vencimientos**, **Finanzas con
   gastos** (los gastos no dependen de los programas).
3. **Solicitudes** (lectura) + Telegram del cron + `atender_solicitud`.
4. **Escritura**: `registrar_pago`, `extender_prueba`, `crear_demo`, `aprobar/rechazar_comprobante`; pantallas Demos y Embudo.
5. TuSalón, luego TuVocalía y TuAcademia (más tipos de solicitud), luego TuGuardería.
6. El resto: `suspender` real en TuVocalía, `anular_pago`, `nueva_clave_dueno`, `guardar_plan`, `responder_ticket`.

---

## 13. Código de referencia (PHP)

### 13.1 Central: firmar y pedir

```php
function tc2_token(string $tipo, string $inst, string $email, string $nombre, string $rol, string $cuerpo, string $secreto): string
{
    $ahora = time();
    $p = ['v' => 2, 'tipo' => $tipo, 'inst' => $inst, 'email' => $email, 'nombre' => $nombre, 'rol' => $rol,
          'iat' => $ahora, 'exp' => $ahora + ($tipo === 'ejecutar' ? 30 : 60), 'nonce' => bin2hex(random_bytes(16)),
          'bh' => tc_b64(hash('sha256', $cuerpo, true))];
    return tc_firmar($p, $secreto);   // igual que v1: b64(json) . "." . b64(hmac)
}

$cuerpo = json_encode(['pagina' => 1, 'por_pagina' => 50], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$ch = curl_init($url . '/tukan_central.php?accion=clientes');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 5,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8', 'Accept: application/json',
                           'X-Tukan-Token: ' . tc2_token('clientes', $inst, $email, $nombre, 'dueno', $cuerpo, $secreto)]]);
```

El cuerpo que se firma es **exactamente** el que se manda (no volver a codificar el JSON después de firmar).

### 13.2 Programa: validar un pedido v2

```php
/** Devuelve [payload, datos del cuerpo] o lanza TcError(codigo, mensaje, http). */
function tc2_validar(string $accion): array
{
    $cfg = tc_config();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new TcError('invalido', 'Use POST.', 405);
    $cuerpo = (string) file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($cuerpo) > 65536) throw new TcError('invalido', 'El pedido es demasiado grande.', 413);
    $token = (string) ($_SERVER['HTTP_X_TUKAN_TOKEN'] ?? '');
    $partes = explode('.', $token);
    if (count($partes) !== 2 || !hash_equals(tc_b64(hash_hmac('sha256', $partes[0], $cfg['secreto'], true)), $partes[1])) {
        throw new TcError('firma', 'La firma del pedido no es correcta.', 401);
    }
    $p = json_decode((string) tc_unb64($partes[0]), true);
    if (!is_array($p)) throw new TcError('firma', 'El pedido no es válido.', 401);
    if (($p['v'] ?? null) !== 2) throw new TcError('version', 'Versión de protocolo no soportada.', 401);
    if (($p['tipo'] ?? null) !== $accion) throw new TcError('tipo', 'El pedido no es para esta acción.', 401);
    if (!is_string($p['inst'] ?? null) || !hash_equals($cfg['id'], $p['inst'])) throw new TcError('inst', 'El pedido es de otra instalación.', 401);
    $iat = $p['iat'] ?? null; $exp = $p['exp'] ?? null; $ahora = time();
    $max = $accion === 'ejecutar' ? 30 : 60;
    if (!is_int($iat) || !is_int($exp) || $exp < $iat || $exp - $iat > $max) throw new TcError('invalido', 'El pedido dura más de lo permitido.', 401);
    if ($ahora > $exp + 30) throw new TcError('vencido', 'El pedido venció. Intente otra vez desde el Panel Tukán.', 401);
    if ($iat > $ahora + 30) throw new TcError('futuro', 'El pedido tiene fecha futura (revise la hora del servidor).', 401);
    if (!is_string($p['bh'] ?? null) || !hash_equals(tc_b64(hash('sha256', $cuerpo, true)), $p['bh'])) {
        throw new TcError('firma', 'El contenido del pedido cambió en el camino.', 401, ['parte' => 'cuerpo']);
    }
    tc_usar_nonce($p['nonce'] ?? null, $exp);          // la misma función de v1; lanza TcError('nonce', …, 401)
    if (!filter_var($p['email'] ?? '', FILTER_VALIDATE_EMAIL)) throw new TcError('invalido', 'Correo inválido en el pedido.', 401);
    $datos = json_decode($cuerpo, true, 8);
    if (!is_array($datos) || ($datos !== [] && array_is_list($datos))) throw new TcError('invalido', 'El contenido no es un objeto JSON.', 400);
    return [$p, $datos];
}
```

---

## Notas de implementación — TuGuardería

(Agregado por el conector de TuGuardería, `app/central.php`. No cambia nada de arriba.)

1. **Dónde va `cliente_id` en `ejecutar`** (§4.4 vs §6.1): §6.1 lo pide arriba (`{"operacion_id", "accion", "cliente_id", "datos"}`),
   pero §4.4 dice que el Central manda los `datos` fijos del botón (`{"cliente_id": "unico"}`) **dentro** de `datos`.
   TuGuardería acepta los dos: usa `cliente_id` de arriba y, si no viene, `datos.cliente_id`. Si no está en ninguno →
   `422 invalido` con `campo: "cliente_id"`. Sugerencia para el Central: mandarlo siempre arriba.
2. **Límites**: `sso` y `estado` siguen con el límite de v1 (30 por minuto por IP); las acciones v2 tienen su propio
   contador (120 por minuto y 30 rechazados por minuto por IP, tabla `tukan_central_limite_v2`).
3. **`cliente`** (§8 "sin plan, pagos ni fechas"): `plan: null`, `pagos: null`, `mensual_estimado: null`, y `fechas` es un
   objeto con todas sus claves en `null` (así `fechas.vence` se puede leer sin revisar antes). `estado: "activo"`,
   `estado_detalle: "licencia"`. `dueno` es `null` si no hay administrador principal activo.
4. **`solicitudes_huella`** sin solicitudes = primeros 16 hex de `sha256("")` (`e3b0c44298fc1c14`).
5. **Tabla `tukan_central_operaciones`** en MariaDB usa `DATETIME` en `creado`/`terminado` (no `TIMESTAMP`, para evitar
   los valores por defecto automáticos de MariaDB).

---

## Notas de implementación — TuEvento/TuSalón

(Agregado por los conectores v2 de TuEvento y TuSalón, `src/TukanCentralV2.php`. No cambia nada de arriba.)

1. **Nombres de acción**: una acción que no está en el protocolo (`borrar`, …) → `400 invalido` "Acción no válida."
   (como v1, ahora con `codigo`); una del protocolo que el programa no tiene (`adjunto`: no hay comprobantes ni tickets)
   pasa por toda la validación y termina en `403 no_permitido` con `detalle.motivo = "no_disponible"`.
2. **Límite**: un solo contador por IP para v1 y v2 (`tukan_central_pedidos` + columna `rechazados`): 120 pedidos y
   30 rechazados (401) por minuto, también para `sso` y `estado`.
3. **`cliente_id` en `ejecutar`**: se acepta arriba o en `datos.cliente_id` (como TuGuardería, nota 1).
4. **Ids de recordatorios con la fecha de la etapa**: `seguimiento_prueba:{id}:{etapa}:{prueba_hasta}` y
   `renovacion:{id}:{etapa}:{activo_hasta}`, para que una prueba alargada o un plan renovado que vuelve a la misma etapa
   tenga id nuevo (§5.8). Además `extender_prueba` borra las atenciones de `seguimiento_prueba:{id}:*`.
5. **`solicitudes`**: `contadores` cuenta por `tipo` el resultado filtrado (antes de paginar); `desde` filtra por
   `actualizado` en `cerradas` y `todas`. Huella = primeros 16 hex de `sha256` de las líneas `id|estado` ordenadas y
   unidas con `\n` (sin solicitudes: `sha256("")`, igual que TuGuardería).
6. **`atender_solicitud`**: mismo resultado → `sin_cambios`; `en_proceso` puede pasar a cualquiera; una ya `atendida`
   o `descartada` con otro resultado → `409 conflicto` (`detalle.motivo = "ya_atendida"`). En `dominio_gratis`, `dominio`
   se guarda en `suscripciones.dominio` del pago anual (eso cierra la solicitud). TuSalón: `pago_automatico` (Google
   Play) es `aviso`, prioridad `baja`, con botón "Visto" (`datos` fijos `{"solicitud_id", "resultado": "atendida"}`).
7. **`crear_demo`**: no hay pedidos de demo → `solicitud_id` con valor → `422` (`campo: "datos.solicitud_id"`).
   `correo_enviado` siempre `false` (no hay correo de bienvenida). TuEvento: `extra.ciudad`, plan por defecto `quinta`,
   clave temporal (`clave_temporal = true`). TuSalón: sin campos propios (`formularios.crear_demo: []`), plan por
   defecto `completa`, clave normal de 10 caracteres (no existe "clave temporal"; el mensaje dice que la cambie).
8. **`registrar_pago`**: `fecha` es opcional (hoy); `meses` → `422` (`meses_libres: false`); `nota` va solo en
   `registro_admin` (no hay columna). `monto` debe ser número JSON. **`anular_pago`**: ya anulado → `409 conflicto`; si
   no le quedan pagos y estaba activo, el cliente vuelve a `prueba` (antes quedaba "al día sin vencimiento") y lo dice
   el `mensaje`.
9. **`guardar_plan`**: `datos` = `plan`, `nombre`, `precio_mensual` (o `precios[]` con el `mensual`), `descripcion`,
   límites (`max_espacios`/`max_usuarios` en TuEvento, `max_profesionales` en TuSalón; `null` = ilimitado; si no viene,
   se conserva) y `ajustes` opcional (`dias_prueba`, `dias_gracia`, …). Su formulario va en `capacidades.formularios.guardar_plan`.
10. **Cliente**: `tipo: null`; `ir_trabajar: null` ("Entrar como el dueño" es solo por POST desde la ficha); TuSalón
    `ciudad: null` y teléfono = `salones.telefono`. **Enlace público de TuSalón**: `/?r=reservar&s=<slug>` (§10.3 dice
    `/<slug>`, pero esa dirección no existe; es la que muestra la ficha del panel de Tukán).
11. **`estado` v1**: los contadores siguen la regla de §4.2; la lista `pagos_vencidos` no cambia (vencidos sin gracia y
    pruebas terminadas, "Sin pagar" del panel).

---

## Notas de implementación — TuVocalía/TuAcademia

Conector v2 en `app/tukan_central.php` (el mismo archivo en los dos repositorios; distingue por `PRODUCTO` y `app/academia.php`) y
funciones compartidas en `app/cuentas_super.php`. Lo que el texto de arriba dejaba abierto se resolvió así:

1. **Acción desconocida** (y pedido sin `accion`): `403 no_permitido` con `detalle.motivo = "no_disponible"` (paso 15), ya no 404.
   Sin configurar sigue siendo 404, ahora con JSON `sin_configurar` (en `sso`: texto plano con `X-Tukan-Codigo: sin_configurar`).
2. **Límite** (§3.1 paso 2) vale para **todas** las acciones, también `sso` y `estado`: 120 pedidos/minuto/IP y 30 rechazados (401)/minuto/IP
   (tabla `intentos`, tipos `tukan_central` y `tukan_central_mal`). Pasa a `429 limite` con `Retry-After: 60` (el v1 contestaba 401
   «Demasiados pedidos…»; el texto se conserva para los Centrales v1 que clasifican por palabras).
3. **Errores de `estado`**: JSON de §3.2 (`"protocolo": 2` y `codigo`); la respuesta correcta de `estado` sigue con `"protocolo": 1`.
4. **Ids de los recordatorios** llevan la fecha de vencimiento: `seguimiento_prueba:<cliente>:<etapa>:<AAAAMMDD>` y
   `renovacion:<cliente>:<etapa>:<AAAAMMDD>`. Así, al alargar la demo o al renovar, la etapa nueva tiene id nuevo («reinicia los avisos»)
   y una atención vieja no la esconde. `registro_nuevo:<cliente>`, `pago_automatico:<pago>`, `dominio_gratis:<pago>`,
   `comision_vendedor:<comisión>`, `liquidacion_pendiente:<cliente>:<MAX(id)>`.
5. **Pagos anulados**: el programa **borra** el pago (no tiene marca de anulado), así que `pagos_detalle` nunca trae `anulado: true` y
   `finanzas` cuenta las filas que existen. La comisión **pendiente** del vendedor se borra con el pago (clave foránea en cascada); si la
   comisión ya está **pagada** → `403 no_permitido` (`motivo: comision_pagada`). Si el pago era el que dejó `ligas.vence`, la fecha vuelve
   al `MAX(hasta)` de los pagos que quedan; si no queda ninguno, no se toca y `mensaje` lo dice (`resultado.sin_pagos: true`).
   Lo mismo pasa al eliminar un pago en Ventas.
6. **`crear_demo`**: `origen = "demo_pedida"` cuando viene con `solicitud_id`, si no `"central"`. `contacto_nombre` pide **3** letras como
   mínimo (regla del programa, no 2). Los campos propios (`tipo`, `ciudad`; TuAcademia: `ciudad`, `disciplina`) se aceptan en `extra`
   o sueltos en `datos` (el formulario del pedido de demo los trae sueltos). TuAcademia siempre crea academia (`tipo: escuela`).
   Con pedido de demo ya «creada» → `409 conflicto`. En la respuesta guardada la clave se cambia por un aviso también dentro de
   `mensaje_whatsapp` y `whatsapp_url` queda en `null` (además de `acceso.clave: null, clave_oculta: true`). Igual en `nueva_clave_dueno`.
7. **`atender_solicitud` de un `demo_pedida` con `atendida`** → estado del programa `contactada` (como dice §10.1), o sea que sigue
   **abierta** (`en_proceso`); se cierra con `crear_demo` o con `descartada`. Si ya tenía su demo, `atendida` → `sin_cambios`, y
   `descartada` → `409`.
8. **Soporte**: `cerrado` y `resuelto` → `atendida` (aunque se haya marcado `descartada` desde el Central, que guarda `cerrado`).
9. **Suspendido**: `extender_prueba` → `403 no_permitido` (`motivo: suspendido`). `registrar_pago` **sí** se permite (la cuenta sigue
   suspendida y el `mensaje` lo avisa). Los recordatorios `seguimiento_prueba`/`renovacion` no salen para cuentas suspendidas.
   Suspensión real: `liga_puede_editar()` y `exigir_suscripcion()` niegan con «Cuenta suspendida. Comuníquese con …»; aviso arriba del panel.
10. **`registrar_pago`**: `meses` (1–36) se acepta siempre, `periodo` tiene prioridad si vienen los dos; `inicio` (opcional) fija el inicio de la
    vigencia (también en `cotizar`); `dominio` se guarda en `ligas.dominio` (activarlo en el servidor sigue siendo Ligas → Editar).
    `metodo` es una clave de `formas_pago()` (no se acepta `google_play` a mano).
11. **Idempotencia**: toda la operación, la fila de `tukan_central_operaciones` (`en_curso`) y la respuesta (`ok`) van en **una** transacción
    `BEGIN IMMEDIATE`; otro pedido con el mismo `operacion_id` espera (busy_timeout 5 s) y luego ve `ok` → `repetida`. Si no logra el
    permiso de escritura en 5 s → `409 conflicto` con `motivo: en_curso`. Un error de validación (incluidos los `RuntimeException` del
    programa, que se devuelven como `422 invalido`) deshace todo y se guarda como `error`; una excepción interna no deja fila.
    Para que las funciones del programa (que abren su propia transacción) funcionen dentro, `db()` usa un PDO con transacciones anidadas
    (SAVEPOINT).
12. **`finanzas`**: solo se mandan los `costos`/`otros_ingresos` mayores que 0. El costo `google_play` (15 %, `estimado: true`) sale en
    cualquier instalación que tenga pagos `forma_pago = google_play`. `por_periodo.periodo` para meses que no son 1/3/6/12 es `"N meses"`.
    En modo academia todo se filtra a las academias.
13. **`cliente.actualizado`** = último cambio de la fila en la bitácora (o el alta); `ultima_actividad` = último ingreso de un usuario de la cuenta.
    `tipo` puede ser `liga`, `escuela` o `federacion` (filtro `tipo` igual). Filtro extra aceptado: `ids`.
14. **`planes`** trae además `detalle` (los textos de límites del programa). En modo academia no salen los planes solo de ligas.

---

## Notas de implementación — Panel Central

(Agregado por el Panel Central v2. No cambia el contrato de arriba; dice qué decidió el panel donde el texto no lo
decía y lo que agregó por su cuenta.)

1. **Tablas extra** (además de §9.2): `sincronizacion(instancia_id, que, momento, huella, datos)` guarda cuándo se trajo
   cada parte (capacidades, solicitudes, clientes, finanzas) y con qué huella, para que el cron no pida de más; y
   `operaciones.cuerpo_cif` guarda **cifrado** el cuerpo exacto que se mandó con cada `operacion_id`, para que un
   reintento sea byte a byte el mismo (el `exp`/`nonce` del token sí son nuevos). Al terminar (`ok`/`error`) los datos
   que traen claves se limpian (`resultado` sin `clave`, nunca se guarda una contraseña).
2. **`operacion_id`**: se crea al **abrir** el formulario. "Revisar" (sin respuesta, `en_curso`) reenvía el mismo; un
   formulario **corregido** después de un `422` lleva uno nuevo (el programa ya guardó el `error` con el anterior).
   `en_curso` (409) se reintenta dos veces con 3 s de espera antes de mostrar "Revisar".
3. **`cliente_id`** va arriba en `ejecutar` y también dentro de `datos` cuando el programa lo puso en los `datos` fijos de
   la acción (el panel no los toca). `datos` vacío sale como `{}`.
4. **Errores**: primero el `codigo`; sin `codigo`, 400/405 a `capacidades` → el programa es **v1**, y 404 con el estado en
   línea → v1 también (404 sin estado → "no conectado"). Acción desconocida: TuEvento/TuSalón contestan `400 invalido` y
   TuVocalía/TuAcademia `403 no_permitido` (`no_disponible`); el panel muestra el texto del programa en los dos casos.
5. **Solicitudes cerradas fuera del panel**: la que deja de venir en la lista de abiertas queda en la copia local con
   estado `cerrada` (se ve en "Atendidas"); no se borra hasta 90 días. La primera sincronización de un programa **no**
   avisa a Telegram (serían avisos viejos).
6. **`avisar_tipos`** (Ajustes → Avisos): por defecto `registro_nuevo, demo_pedida, comprobante, cambio_plan, soporte,
   derechos_datos`. Un tipo que el panel no conoce y viene con `categoria = "pedido"` **siempre** avisa (un programa nuevo
   no queda mudo); los `recordatorio` y `aviso` solo salen en el resumen diario.
7. **Permisos de operaciones** (§9.3): una operación que el panel no conoce se trata como **cobros** (solo el dueño).
   `anular_pago` y `guardar_plan` piden además el código de 2FA. `atender_solicitud` lo puede hacer soporte.
8. **Máscaras**: en pantalla para soporte, teléfono `09•••••67` (2 + 5 + 2) y correo `j•••@dominio`; en Telegram
   `099•••4567`. El texto libre de las solicitudes también se enmascara (números de 9 o más cifras y correos).
9. **Recordatorios por WhatsApp** (Clientes → Recordar): se anotan con la **fecha y hora del panel** (America/Guayaquil) y
   quién lo hizo; el programa no se entera (es un enlace `wa.me`), por eso no hay operación.
10. **MRR** (Finanzas) = suma de lo que informan los programas para el mes + las **licencias vigentes** de los
    programas de una sola empresa (TuGuardería), pasadas a mensual.
11. **Embudo**: "interesados" = cuentas nuevas en el período + pedidos de demo abiertos o descartados; "demos" = demos
    creadas (desde el panel o por pedido); "pagaron" = demos con al menos un pago.
12. **Gastos generales** (sin programa): por defecto van aparte; con "Repartir" se reparten entre los programas en
    proporción a sus ingresos del mes. Los `costos` que informan los programas (comisiones, Google Play) se muestran
    aparte en Gastos ("lo informa X") y **sí** se restan en la ganancia de ese programa: no hay que anotarlos a mano.
13. **Descargas**: el Excel (.xlsx) se arma en PHP puro (no necesita `php8.3-zip`) con hojas Resumen, Por programa,
    Ingresos, Gastos e Ingresos extra; el CSV es **una lista plana de movimientos** (una fila por ingreso o gasto),
    separada por `;`, coma decimal, con BOM, y las celdas que empiezan con `= + - @` llevan un apóstrofo delante.
14. **Finanzas** se piden una vez al día (desde las 03:10) y como máximo 24 meses; "Actualizar" en la pantalla las pide
    al momento. Los gastos fijos del mes se crean solo para el **mes actual** (no se rellenan meses pasados).
