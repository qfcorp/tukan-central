# Inventario del súper panel de cada programa

Revisado en el código el **13 de octubre de 2026** (sin cambiar nada). Sirve para decidir qué pasa al **Panel Tukán
Central** y qué se queda en cada programa. El contrato nuevo está en [`PROTOCOLO_V2.md`](PROTOCOLO_V2.md).

Marcas:
- **→ Central**: el Panel Central lo muestra o lo hace (con el protocolo v2).
- **Se queda**: trabajo propio de ese programa; se llega con **Entrar** (SSO), ahora con enlace directo a la pantalla exacta.
- **Mixto**: el Central muestra y hace lo básico; lo detallado sigue en el programa.

---

## 1. TuVocalía (`/home/claude/tuvocalia`, PHP + SQLite)

### 1.1 Cómo se reconoce al súper usuario

- `usuarios.rol = 'super'`; `es_super()`. Todo pasa por `public/admin.php?p=<pagina>`.
- `area_pagina()` (`app/perfiles.php`) marca como **solo súper**: `ligas, planes, suscripciones, ajustes, vendedores,
  demos, modulos, negocio, pasarela, embudo`. Cada una además empieza con `if (!es_super()) ir('admin.php');`.
- El menú del súper (`app/menu.php`, grupo `super`): Negocio, Ligas y escuelas, Ventas (con el número de comprobantes
  por revisar `pagos_por_revisar()`), Vendedores, Módulos deportivos, Demos (`solicitudes_demo_nuevas()`), Embudo de
  demos, Soporte (`tickets_pendientes_super()`), Novedades, Ver portales, Usuarios, Ajustes, Cobros (pasarela) y, sin
  liga elegida, Bitácora de todo el sistema y Protección de datos de todo el sistema.
- La "cuenta" de un cliente es una fila de **`ligas`** (liga, escuela o federación). `ligas.es_demo = 1` es la
  **demostración del sistema** (compartida, se reinicia cada madrugada): no es un cliente y no se cuenta.

### 1.2 Páginas solo del súper usuario

| Página (`app/admin/…`) | Qué muestra | Acciones (función / tabla) | Destino |
|---|---|---|---|
| `negocio.php` (Negocio) | Ingreso mensual estimado (último pago ÷ meses), cobrado mes / 12 meses, barras de ingresos por mes, cuentas nuevas / empezaron a pagar / no renovaron por mes, **cuentas en riesgo** (sin ingresar o sin partidos en 15 días), vencen en 15 días, no renovaron (60 días), botones WhatsApp | Solo lectura (`pagos_suscripcion`, `ligas`, `usuarios.ultimo_ingreso`, `partidos`) | **→ Central** (Finanzas, Embudo, Clientes "sin uso", Vencimientos) |
| `ligas.php` (Ligas y escuelas) | Todas las cuentas con estado (`estado_suscripcion()`), plan, vence, módulos | `crear` → `crear_liga_completa()` (+ `correo_bienvenida()`, mensaje de WhatsApp con accesos, marca `solicitudes_demo.estado='creada'`, `anotar_referido()`); `suscripcion` (plan, vence, es_prueba, dominio, contacto, vendedor, directorio, federación: `UPDATE ligas`); `combo` → `enlazar_combo()`; `deportes` (módulos deportivos); `limites`; `admin_crear/admin_clave/admin_activo/admin_perfil/admin_eliminar` (usuarios de la cuenta); `guardar_liga()` (incluye `activa`); `eliminar`; `entrar` (trabajar dentro de la cuenta) | **Mixto**: lista, crear demo, plan, nueva clave del dueño, suspender → Central. Combo, módulos, límites, dominio, usuarios, eliminar → **se quedan** |
| `suscripciones.php` (Ventas) | Cobrado mes/año, conteo por estado, **comprobantes por revisar** (`html_comprobantes_por_revisar()`), formulario "Registrar pago", lista de cuentas con contacto y último ingreso, botón WhatsApp "Recordar", últimos 50 pagos | `aprobar_comprobante` → `aprobar_solicitud_pago()`; `rechazar_comprobante` → `rechazar_solicitud_pago()`; `pago` → `registrar_pago_suscripcion()` + `correo_pago_registrado()` (genera comisión del vendedor con `generar_comision()`, premia referidos, aviso de dominio gratis); `anular_pago` → `DELETE FROM pagos_suscripcion` (no cambia `ligas.vence`) | **→ Central** |
| `planes.php` | Planes con precios mensual/trimestral/semestral/anual, límites, módulos, # cuentas | `guardar` / `eliminar` en `planes` | **Mixto**: ver → Central; editar se queda (tiene módulos y folletos) |
| `demos.php` (Solicitudes de demo) | `solicitudes_demo` por estado (nueva, contactada, creada, descartada) con contacto, plan de interés, vendedor, deportes, mensaje | Cambiar estado / nota (`UPDATE solicitudes_demo`); "Crear su cuenta de demo" → `ligas.php?nueva=1&demo=ID`; WhatsApp `mensaje_contacto_demo()` | **→ Central** |
| `embudo.php` (Embudo de demos) | Solicitudes sin atender, demos activas con su uso (`uso_cuenta()`), demos vencidas (60 días), cuentas que pagaron (30 días), referidos | `alargar` (+7 días: `vence`, `es_prueba=1`, `demo_avisos=''`, bitácora); WhatsApp `mensaje_seguimiento_demo()`; "Pasar a pagado" | **→ Central** |
| `vendedores.php` | Vendedores, sus cuentas, comisiones (`comisiones`) y pagos | Crear/editar vendedor, acceso, pagar comisión | **Se queda** (las comisiones van a Finanzas como costo y las pendientes como recordatorio) |
| `pasarela.php` (Cobros con tarjeta) | Todo lo cobrado por Plux (`pagos_linea`): ganancia de gestión (`comision_tuvocalia`), suscripciones pagadas con tarjeta, comisiones de Plux, **por transferir a cada organización** | `liquidar` → `liquidaciones_org` | **Mixto**: cifras y "por transferir" → Central; liquidar se queda |
| `modulos.php` | Módulos deportivos: precios, requisitos, reglas, demos por deporte | Varios | **Se queda** |
| `ajustes.php` | Marca, venta (`dias_demo`, `dias_prueba`, `registro_abierto`, `dias_gracia`, `dias_gracia_demo`, `combo_descuento`, `ventas_whatsapp`, `ventas_datos_pago`, `referidos_meses`…), correo SMTP, Telegram, pasarela, demo del sistema | `guardar_ajuste()` | **Se queda** (el Central solo lee `dias_demo`/`dias_gracia` en `planes`) |
| `portales.php` (Ver portales) | Abrir cada portal como lo ve cada persona | — | **Se queda** |

### 1.3 Páginas compartidas en las que el súper ve "de todos"

| Página | Qué hace el súper | Destino |
|---|---|---|
| `soporte.php` | Ve todos los tickets (`soporte_tickets` + `soporte_mensajes`), responde (`responder_ticket()`), cambia estado y prioridad, captura adjunta; WhatsApp al cliente | **→ Central** (bandeja, responder texto, cambiar estado); conversación larga y adjuntos también en el programa |
| `datos.php` (LOPDP), sin liga elegida | Solicitudes de derechos de **todo** el sistema (`solicitudes_derechos`, plazo 15 días), autorizaciones (`consentimientos`), versión de la política | **Mixto**: las solicitudes **del sistema** (`liga_id IS NULL`, Tukán es el responsable) → Central; las de cada liga son de esa liga |
| `bitacora.php&todas=1` | Todos los cambios (`bitacora`) | **Se queda** (el Central tiene su propia auditoría y muestra las últimas acciones de cada cliente) |
| `novedades.php` | Escribe las novedades de cada versión (`novedades`) | **Se queda** |
| `usuarios.php` | Todos los usuarios | **Se queda** |
| `seguridad.php` | Su clave y 2FA | **Se queda** (con `TUKAN_SOLO_CENTRAL=1` el súper ya no entra con clave) |
| `inicio.php` | El súper sin liga elegida va directo a `ligas` | — |

### 1.4 Páginas del cliente (no son del súper)

`inicio/escuela_inicio, equipos, torneos, partido, finanzas, contabilidad, cobros, pagos_linea, pedidos (reprogramaciones,
descargos y apelaciones de los delegados), inscripciones, mi_plan, referidos, indicadores, cargas, mis_datos, config…`
→ **se quedan**. Ojo con los nombres: `finanzas` y `contabilidad` son **la plata de la liga**, no la de Tukán; `pedidos`
son pedidos de los delegados a su liga, no a Tukán.

### 1.5 De dónde salen las "solicitudes" en TuVocalía

| Solicitud | Formulario / origen | Tabla y estados | Aviso que ya existe |
|---|---|---|---|
| **Pedido de demo** | `index.php?v=solicitar` → `app/publico/solicitar.php` | `solicitudes_demo`: `nueva` → `contactada` → `creada` (con `liga_id`) / `descartada` | Telegram al súper (`tg_encolar`), correo (`correos_solicitud_demo()`), aviso al vendedor |
| **Registro nuevo** (prueba gratis automática, solo si `ajustes.registro_abierto=1`; en TuVocalía está apagado, en TuAcademia encendido) | `index.php?v=registro` → `app/publico/registro.php` → `crear_liga_completa(es_prueba=1)` | No hay tabla de solicitud: es la fila de `ligas`. Se reconoce porque deja `consentimientos.formulario='registro'` con su `liga_id` | Telegram "Nueva liga en prueba gratis" |
| **Comprobante de pago por aprobar** (contratar, renovar o **cambiar de plan**) | `admin.php?p=mi_plan`, `accion=comprobante` → `crear_solicitud_pago()` (foto privada en `data/fotos/…`) | `solicitudes_pago`: `pendiente` → `aprobada` (con `pago_id`) / `rechazada` (con `respuesta`). Solo uno pendiente por cuenta. El **cambio de plan** no tiene tabla propia: es un comprobante con `plan_id` distinto al de la cuenta (o con otros `deportes`) | `aviso_super()` + correo a los súper |
| **Pago automático** (informativo) | Tarjeta Plux en Mi plan (`confirmar_pago_linea()` → `registrar_pago_suscripcion(…,'tarjeta')`, `registrado_por='Pago en línea (…)'`); en TuAcademia también Google Play (`'google_play'`) | `pagos_suscripcion` | Telegram al cliente |
| **Soporte** (problema, pregunta, funcionalidad, pagos, otro) | `admin.php?p=soporte`, `accion=crear` → `crear_ticket()` | `soporte_tickets`: `nuevo`, `en_proceso`, `esperando`, `resuelto`, `cerrado`; `sin_leer_super`; prioridad `baja/normal/alta/urgente` | `aviso_super()` |
| **Derechos de datos (LOPDP)** del sistema | `index.php?v=derechos` (público) | `solicitudes_derechos` con `liga_id IS NULL`: `nueva`, `en_proceso`, `respondida`, `rechazada` | — |
| **Dominio gratis por registrar** | Pago de 12 meses o más en un plan con `planes.dominio_anual=1` | `pagos_suscripcion` + `ligas.dominio = ''` (+ evento de bitácora `ventas`) | Telegram "Dominio gratis" |
| **Seguimiento de demo** (día 5, día 7, vencida sin pagar) | Cron `avisos_demos()` | `ligas` (`es_prueba=1`, `vence`, `demo_avisos`) | Telegram con WhatsApp listo |
| **Renovación** (7, 5, 3, 1, 0 días y vencida ayer) | Cron `avisos_diarios()` | `ligas.vence` | Telegram al cliente y al súper, correo |
| **Comisión por pagar a un vendedor** | `generar_comision()` al registrar un pago | `comisiones.estado='pendiente'` | — |
| **Dinero por transferir a una organización** | Cobros con tarjeta de equipos y familias | `pagos_linea` (`destino='organizacion'`, `estado='pagado'`, `liquidacion_id IS NULL`) | — |

### 1.6 Qué se puede hacer con una cuenta y con qué función

| Necesidad | Hoy en TuVocalía |
|---|---|
| Crear demo | `crear_liga_completa()` con `vence = hoy + ajuste('dias_demo', 7)` y `es_prueba = 1` (desde `ligas.php`, con o sin `solicitud_demo`) |
| Alargar prueba | `embudo.php` `alargar` (1 a 60 días) |
| Registrar pago | `registrar_pago_suscripcion($l, $plan, $meses, $monto, $forma, $nota, $fecha, $por)` |
| Aprobar / rechazar comprobante | `aprobar_solicitud_pago()` / `rechazar_solicitud_pago()` |
| Anular pago | `DELETE FROM pagos_suscripcion` (no vuelve atrás `ligas.vence`; se niega si la comisión ya se pagó) |
| Cambiar plan | `UPDATE ligas SET plan_id` (dentro de `accion=suscripcion`, junto con otros campos) |
| Suspender | **No existe de verdad**: `ligas.activa = 0` solo la oculta del público; el panel de la cuenta sigue funcionando |
| Nueva clave del dueño | `admin_clave` (hay que elegir el usuario) |
| Notas internas | No hay campo en `ligas` (solo `solicitudes_demo.nota`) |

---

## 2. TuAcademia (`/home/claude/tuacademia`)

Copia de TuVocalía v38 con `PRODUCTO = 'academia'`. Todo lo de la sección 1 vale igual, con estas diferencias:

- Toda cuenta nueva es **academia** (`ligas.tipo='escuela'`); ligas, campeonatos, árbitros, federaciones y entradas
  están apagados.
- **Registro abierto** (prueba gratis de 7 días automática): los **registros nuevos** son la fuente principal de
  solicitudes (`consentimientos.formulario='registro'`).
- Página extra `google_play.php` y `app/play.php`: pagos de suscripción con `forma_pago='google_play'` (apagado por ahora).
  `registrar_pago_suscripcion()` acepta un `$hasta_fijo` (la fecha la pone Google).
- `formas_pago()` agrega `deuna` y `payphone`; `nombre_forma_pago()` agrega "Google Play".
- Planes provisionales Básico $19 / Completo $29 (semestre paga 5, año paga 10).
- Páginas extra del cliente: `mensajes`, `particulares`, `recitales` → **se quedan**.
- Mismo conector (`app/tukan_central.php` detecta `tuacademia` por `app/academia.php`).

---

## 3. TuEvento (`/home/claude/tuevento`) y TuSalón (`/home/claude/tusalon`) — "Panel Tukán"

PHP + PostgreSQL, misma arquitectura. Súper usuario en la tabla **`superadmins`** (no en `usuarios`), sesión
`$_SESSION['admin_id']`. La cuenta del cliente es **`negocios`** (TuEvento) o **`salones`** (TuSalón). Toda la lógica
está en `src/PanelTukan.php` y `src/Planes.php`; las páginas en `app/paginas/admin*.php` (rutas `/?r=admin…`).

| Página | Qué muestra | Acciones (función / tabla) | Destino |
|---|---|---|---|
| `admin` (Resumen) | `PanelTukan::resumen()`: total, por estado (7 estados), por plan, ingreso mensual recurrente (`ingresoMensual()`), cobrado mes/año (`cobradoMes()`), pruebas que terminan en 3 días, planes que vencen en 15 días, **sin pagar**, últimos registrados, últimas acciones (`registro_admin`) — con botones WhatsApp | Solo lectura | **→ Central** |
| `admin_negocios` / `admin_salones` | Lista con filtro por estado, plan (solo TuEvento) y búsqueda (`negocios()` / `salones()`) | — | **→ Central** (Clientes) |
| `admin_negocio&id=` / `admin_salon&id=` | Ficha: datos, dueño, uso (espacios/eventos o peluqueros/citas), pagos, registro | `pago` → `PanelTukan::registrarPago()` / `Planes::suscribir()`; `anular_pago` → `Planes::anularPago()` (sí recalcula `activo_hasta`); `plan` → `cambiarPlan()`; `estado` → `cambiarEstado()` (`prueba`, `activo`, `suspendido`, `cancelado`); `prueba` → `extenderPrueba()` (1–90 días); `notas` → `guardarNotas()` (`notas_admin`); `clave` → `nuevaClaveDueno()`. Todo queda en `registro_admin` con `registrar_admin()` | **→ Central** (todas) |
| `admin_nuevo` | Crear cliente con prueba gratis | `Altas::crearNegocioConDueno()` (con datos de ejemplo) / `Altas::crearSalonConDueno()` (5 servicios y horario); clave temporal que se muestra una vez | **→ Central** (crear demo) |
| `admin_planes` | Planes (`planes`: `codigo`, `precio_mensual`, `max_espacios`/`max_usuarios`) y ajustes (`dias_prueba`, semestral/anual paga-recibe, `dias_gracia`, `whatsapp_ventas`) | `Planes::guardarPlan()`, `Planes::guardarAjustes()` | **Mixto**: ver → Central; editar puede pasar al Central (es simple) |
| `admin_ver` / `admin_volver` | Entrar al negocio **como su dueño** (soporte, solo por POST) | `registro_admin` | **Se queda** (el Central abre la ficha con enlace directo y desde ahí se toca "Entrar como el dueño") |

Estados nativos (`Planes::estadoCuenta()`): `prueba`, `prueba_vencida`, `activo`, `por_vencer` (= **ya venció, en días de
gracia**), `vencido`, `suspendido`, `cancelado`. Un cliente que no está "al día" (`alDia()`) solo ve
`prueba_terminada` (no puede trabajar).

Métodos de pago: `transferencia, deuna, payphone, plux, efectivo, tarjeta, cortesia` (+ `google_play` automático en
TuSalón, tabla `google_play_compras`, `GooglePlay::aplicar()`). Períodos: `mensual`, `semestral` (paga 5 recibe 6),
`anual` (paga 10 recibe 12 + dominio, `suscripciones.incluye_dominio`).

### De dónde salen las "solicitudes" en TuEvento / TuSalón

| Solicitud | Origen | Cómo se reconoce |
|---|---|---|
| **Registro nuevo** | `/?r=registro` (prueba gratis automática, `Altas`) | Fila nueva en `negocios`/`salones` **sin** `registro_admin` "…creado desde el panel" (no hay columna de origen) |
| **Prueba por terminar / terminada sin pagar** | Fechas | `estadoCuenta()` = `prueba` con `prueba_hasta` ≤ 3 días, o `prueba_vencida` |
| **Renovación / vencido** | Fechas | `activo_hasta` ≤ 15 días, `por_vencer` (gracia), `vencido` |
| **Dominio gratis por registrar** | Pago anual | `suscripciones.incluye_dominio = true AND dominio IS NULL AND NOT anulada` |
| **Pago automático** (TuSalón) | Google Play | `suscripciones.metodo_pago='google_play'` |
| Pedido de demo, comprobante, soporte, cambio de plan | **No existen.** La pantalla `prueba_terminada` solo abre WhatsApp de ventas | — |

`consultas_web` (TuEvento) y `solicitudes` (TuSalón: reservas de clientes) son **del negocio**, no de Tukán → se quedan.

---

## 4. TuGuardería (`/home/claude/tuguarderia`, MariaDB, una instalación por cliente)

- No hay súper usuario, ni planes, ni suscripciones, ni demos: el "súper" es el administrador principal de esa guardería
  (`usuarios.rol='admin'`; el SSO crea uno con `creado_por_central=1`).
- Su `estado` manda `negocios: null`, `cliente: nombre_negocio()` y cifras en `extra` (mascotas, planes vigentes,
  asistencia, hotel, peluquería, dueños, usuarios, por cobrar).
- Sus `pagos`, `cargos`, `gastos` y `solicitudes` (pedidos de los dueños de perros) son **de la guardería**, no de Tukán →
  **se quedan**. Lo que la guardería le paga a Tukán (licencia) **no está en el programa**: lo lleva el Central.
- Lo que sí aplica: `capacidades`, SSO con enlace directo y, opcional, `nueva_clave_dueno` (clave del administrador).

---

## 5. Hallazgos que el v2 debe corregir o tener en cuenta

1. **"Por vencer" no significa lo mismo**: en TuVocalía es "pagada, vence en ≤ 7 días"; en TuEvento/TuSalón el v1 manda
   como `por_vencer` a los que **ya vencieron y están en gracia**. El v2 fija una sola regla (ver PROTOCOLO_V2 §4.2)
   y pide alinear también el `estado` v1 de TuEvento y TuSalón.
2. TuVocalía no tiene **suspensión real** (`activa=0` solo oculta la página pública). Hay que construirla.
3. TuVocalía `anular_pago` borra el pago pero **no** devuelve la fecha de vencimiento.
4. TuEvento/TuSalón `Planes::suscribir()` no recibe la **fecha del pago** (`pagado_en` siempre es hoy).
5. TuVocalía alarga demos hasta 60 días; TuEvento/TuSalón hasta 90.
6. Nadie guarda **de dónde vino** cada cliente (registro, pedido de demo, súper, central) ni **notas internas** (TuVocalía).
7. TuSalón `cambiarEstado()` no avisa si el salón no existe (no revisa `rowCount`); `nuevaClaveDueno()` no marca clave temporal.
8. Los conectores v1 contestan la acción desconocida distinto: TuVocalía/TuAcademia **404** (igual que "apagado"),
   TuEvento/TuSalón **400**. El Central debe tratar los dos como "programa v1" si `estado` funciona.
9. Los avisos de Telegram de cada programa al súper (TuVocalía `aviso_super()`) se repetirían con los del Central:
   cuando el Central avise, se apagan en el programa (basta con no conectar el Telegram del súper allí).
