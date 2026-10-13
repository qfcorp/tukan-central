# Bloc de notas — Panel Tukán Central

## Qué es
Un solo panel (panel.tukanec.com) para que Dimitry entre una vez con 2FA, vea las cifras de todos los programas Tukán
y entre a cualquiera con un toque (SSO del Protocolo Tukán Central v1, `docs/PROTOCOLO.md`). El ERP QF no participa.

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
- [x] Pruebas: `tests/run_tests.php` (238) y `tests/run_web.sh` (222, con programas de prueba y capturas Playwright).

## Pendiente
1. Crear el repositorio `qfcorp/tukan-central` en GitHub (público, sin `config/.env` ni `datos/`) y subir.
2. Instalar en qfcorp con la línea del README y agregar la ruta en Cloudflare (panel.tukanec.com → HTTP → localhost:80, Host header).
3. `crear_admin.php` para Dimitry y activar 2FA. Opcional: Telegram en `config/.env`.
4. Confirmar con los agentes de cada producto las rutas de `conectar_central.sh` (están en `src/Productos.php`; se cambian ahí).
5. Conectar cada programa (TuEvento, TuSalón, TuVocalía, TuAcademia y cada TuGuardería) y probar conexión.
6. Colores elegidos para TuEvento (#8E3B52, vino como su tema) y TuAcademia (#3B6FE0): confirmar con la marca.

## Notas sobre el protocolo (para la versión 2; NO se cambió el contrato)
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
