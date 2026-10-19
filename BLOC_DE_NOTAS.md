# Bloc de notas — Panel Tukán Central

## Qué es
Un solo panel (panel.tukanec.com) para que Dimitry entre una vez con 2FA, vea las cifras de todos los programas Tukán
y entre a cualquiera con un toque (SSO del Protocolo Tukán Central v1, `docs/PROTOCOLO.md`). Desde la v2
(`docs/PROTOCOLO_V2.md`) también atiende solicitudes, clientes, demos, cobros y finanzas de todos los programas sin
entrar a cada uno. El ERP QF no participa.

## Estado (13 oct 2026)
**Listo y probado en entorno simulado** (no instalado todavía en qfcorp).

- [x] Ingreso: contraseña + TOTP obligatorio (propio, RFC 6238), activación con QR en PHP puro, 8 códigos de respaldo.
- [x] Bloqueo 5 intentos / 15 min (correo e IP), CSRF + Origin, sesión segura, 30 min / 12 h, cabeceras (CSP, DENY, no-referrer, HSTS).
- [x] Secretos cifrados (XChaCha20-Poly1305, `TUKAN_CENTRAL_KEY`), auditoría de todo, roles dueño / soporte.
- [x] Programas: agregar (comando una vez), cambiar secreto, probar conexión (errores en palabras simples), editar, pausar, quitar.
- [x] Inicio: totales, "Para atender hoy", tarjetas con estado, versión, cifras extra, disponibilidad 7 días, Entrar / pestaña nueva.
- [x] Consultas en paralelo (curl_multi, 5 s), caché de 2 min en SQLite; app.js actualiza solo si está viejo.
- [x] `bin/revisar.php` (cron 15 min, reintento a los 10 s, historial, Telegram: caída / vuelta / negocios nuevos).
- [x] Mi cuenta, Usuarios, Auditoría con filtros, Ajustes (Telegram), Ayuda.
- [x] `bin/crear_admin.php` (`--quitar-2fa`, `--rol=`), `bin/quitar_2fa.php`, instalador y respaldo diario (guarda 14 + `.env`).
- [x] Pruebas: `tests/run_tests.php` (254), `tests/run_web.sh` (223, con programas de prueba y capturas Playwright
      que revisan errores de consola y que nada se salga a lo ancho) y `tests/integracion.sh` (65, con los conectores
      REALES de TuEvento y TuSalón: probar conexión, cifras, Entrar hasta su panel, token repetido, secreto cambiado,
      límite 429, programa apagado).
- [x] Errores de los conectores reales reconocidos por su texto (firma, ya se usó, otra instalación, versión,
      sin configurar, demasiados pedidos) y 400 / 429 explicados en palabras simples.
- [x] Instalador probado en un entorno de prueba (dos veces: conserva la llave y Telegram; crea 9 tablas; respaldo OK).

### Versión 2 (13 oct 2026, commit local, sin subir)
- [x] Base `user_version = 2`: capacidades, clientes, solicitudes, operaciones, demos, recordatorios, finanzas_mes, gastos,
      ingresos_extra, recurrentes, licencias y sincronizacion. Contactos cifrados.
- [x] Pedidos v2 firmados (cuerpo con hash, `X-Tukan-Token`), errores por `codigo` (y los v1 por texto), `datos` siempre `{}`,
      `cliente_id` arriba en `ejecutar`, reintento con el mismo cuerpo byte a byte.
- [x] Pantallas: Inicio nuevo, Solicitudes (bandeja única + Telegram), Clientes (+ Recordar por WhatsApp), ficha del
      cliente, Demos, Finanzas (gastos, fijos, otros ingresos, ganancia, Excel/CSV), Vencimientos, Embudo, Más.
- [x] Formularios genéricos con lo que manda cada programa (`acciones_posibles`); programas v1 siguen igual.
- [x] Pruebas: `run_tests.php` 511, `run_web.sh` 470 (programa de prueba v2 completo y uno solo v1; 46 capturas),
      `integracion.sh` 202 con TuEvento, TuSalón y TuVocalía reales (demo + entrar con la clave, alargar, cobrar y
      reintentar, finanzas, suspender/reactivar, registro nuevo atendido).

### Borrar una instalación desde el panel (15 oct 2026)
- [x] Ficha del programa → **Borrar esta instalación** (TuGuardería con `dar_de_baja`): solo el dueño, con el ID escrito y
      el código de 2 pasos. Estados programado → en curso → terminado (se recarga sola), cancelar, quitar también del panel
      (la auditoría se conserva). Programas sin `dar_de_baja` siguen como antes.
- [x] Cloudflare opcional (Ajustes, token cifrado + cuenta + túnel): al terminar quita solo ese hostname del túnel y su
      CNAME, y verifica. Sin token: recordatorio con los pasos. Ayuda explica cómo crear el token.
- [x] `src/Bajas.php`, `src/Cloudflare.php`, `app/paginas/baja.php`; tabla `bajas` se crea sola (sigue `user_version = 2`).
- [x] Pruebas: `run_tests.php` 581 (Cloudflare contra un servidor de mentira), `run_web.sh` 540 (programas de prueba que se
      pueden borrar, 2FA, soporte sin permiso, ID mal escrito, estados, Cloudflare de mentira).

### Pausar y borrar clientes (15 oct 2026, Anexo A)
- [x] Clientes: Pausar / Reactivar y Borrar en cada fila, filtro **Papelera** con Restaurar. Pausar pide tipo + motivo
      (soporte también puede); Borrar pide el nombre exacto + motivo + código de 2 pasos (solo el dueño).
- [x] Ficha: pausa con tipo y motivo, aviso de papelera (se borra para siempre el…, copia) e historial *Pausas y borrados*.
- [x] TuGuardería: Pausar toda la instalación; Borrar lleva a *Borrar esta instalación*.
- [x] `src/Historial.php` (tabla `historial_clientes`, se crea sola); papelera en la copia local; programa de prueba con
      las operaciones nuevas (`MOCK_SIN_PAUSA=1` para uno sin ellas). TuPlanner: comando revisado contra su script.
- [x] Pruebas: `run_tests.php` 637, `run_web.sh` 621 (capturas 24–29: lista, papelera, fichas y las hojas de Pausar y Borrar).
- [ ] Al actualizar cada programa con el Anexo A: **Probar conexión** en el panel (para que vea los botones).

### Módulos (18 oct 2026, Anexo B) y «nada se da gratis»
- [x] Ficha del cliente con pestaña **Módulos**: interruptores, precio de cada uno, plan base, total al mes en vivo y precio
      acordado; *Revisar y guardar* muestra qué se enciende / apaga y el precio nuevo antes de mandar `cambiar_modulos`.
      Solo el dueño; soporte la ve.
- [x] TuGuardería: los interruptores en la ficha del programa; el total pasa a ser el valor de la licencia.
- [x] Catálogo de módulos y planes del programa (`guardar_modulos`, con código de 2 pasos).
- [x] Solicitudes `cambio_modulos` con Aprobar (resumen) y Rechazar; chip Módulos; Telegram.
- [x] Clientes: columna Al mes y filtro «Con módulos extra». Finanzas: Ingreso por módulo. Demos: plan y módulos.
- [x] Sin «prueba gratis»: pedidos de demo (`demo_pedida`, `demo`, `alta`) con Crear demo; en pantalla dice «demo».
- [x] Pruebas: `run_tests.php` 693 (41 nuevas de módulos, con el programa de prueba en el 8267), `run_web.sh` 781
      (97 nuevas; capturas 37–42: pestaña Módulos, resumen, catálogo, aprobar, ficha del programa y demo).
      `tests/mock/` sabe `modulos`, `cambiar_modulos`, `guardar_modulos` y `cambio_modulos` (`MOCK_SIN_MODULOS=1`: sin Anexo B).
      La parte de Telegram de `run_web.sh` apaga el horario de silencio (de noche fallaba).
- [ ] Al actualizar cada programa con el Anexo B: **Probar conexión** en el panel (para que vea los módulos).

## Pendiente
10. En el servidor de cada TuGuardería, una vez: `git pull` y `instalar.sh` de cada cliente (crea `/var/lib/tukan_bajas`
    y la tarea `/etc/cron.d/tukan_bajas`). Luego **Probar conexión** en el panel. Opcional: token de Cloudflare en Ajustes.
1. Crear el repositorio `qfcorp/tukan-central` en GitHub (público, sin `config/.env` ni `datos/`) y subir.
2. Instalar en qfcorp con la línea del README y agregar la ruta en Cloudflare (panel.tukanec.com → HTTP → localhost:80, Host header).
3. `crear_admin.php` para Dimitry y activar 2FA. Opcional: Telegram en `config/.env`.
4. ~~Confirmar las rutas de `conectar_central.sh`~~ (confirmadas el 13 oct; están en `src/Productos.php`).
5. Conectar cada programa (TuEvento, TuSalón, TuVocalía, TuAcademia y cada TuGuardería) y probar conexión.
6. TuAcademia y TuGuardería no están en `tests/integracion.sh` (TuAcademia usa otro reinicio con simulador; TuGuardería
   usa MySQL): se prueban con el programa de prueba (`tests/mock/`) y al conectarlos de verdad. TuVocalía sí está.
8. Al instalar la v2: correr `bin/revisar.php` una vez a mano después de conectar cada programa (trae capacidades,
   clientes, solicitudes y 24 meses de finanzas) y revisar Ajustes → Avisos de solicitudes.
9. Probar en el celular de Dimitry la hoja de abajo (formularios) y el botón de WhatsApp con números reales.
7. Colores elegidos para TuEvento (#8E3B52, vino como su tema) y TuAcademia (#3B6FE0): confirmar con la marca.

## Notas sobre el protocolo v1 (resueltas en la v2: `codigo` en los errores, solicitudes con quién y qué)
- Los errores 401 no tienen un código fijo, solo texto libre: el panel adivina el motivo por palabras (firma, inst, vencido,
  nonce) y por la cabecera `Date` del programa (reloj desfasado). Sugerencia v2: `{"ok":false,"codigo":"firma|inst|tipo|tiempo|nonce|email","error":"..."}`.
- `estado` no dice **qué** negocios son nuevos: el aviso de Telegram solo puede decir "N negocios nuevos en X".
- El 404 significa "conector apagado" pero también puede ser una URL equivocada: el mensaje del panel cubre los dos casos.
- El token SSO viaja en la URL (`?t=`): puede quedar en los logs de acceso de nginx del programa. Es de un solo uso y dura
  60 s (+30 s de tolerancia), así que el riesgo es bajo; los programas deberían redirigir de inmediato (lo pide §4).
- Con `Referrer-Policy: no-referrer` los navegadores mandan `Origin: null` en los formularios: el panel lo acepta y rechaza
  solo orígenes de otro sitio (la protección principal es el token CSRF + SameSite=Lax).

## Decisiones
- Sin Google Fonts (privacidad y CSP estricta): usa "Space Grotesk"/"DM Sans" si están instaladas, si no las del sistema.
- La revisión automática usa el correo `revision@panel.tukanec.com` en el token de estado.
- Las sesiones de PHP se guardan en `datos/sesiones` (Ubuntu borra las de /var/lib/php a los 24 min).
- Los comandos de consola (bin/, deploy/) escriben sin tildes ni ñ (la app de SSH del celular las daña); las pruebas lo revisan.
- v2: lo que el panel decidió donde la especificación no lo decía está en `docs/PROTOCOLO_V2.md`, al final
  ("Notas de implementación — Panel Central").
