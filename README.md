# Panel Tukán Central

Un solo panel para el dueño de **Tukán Software** (Dimitry): entra **una vez** (con verificación en 2 pasos),
ve las cifras de **todos** sus programas en una pantalla y entra a cualquiera con un toque.

Programas que se conectan: **TuEvento, TuSalón, TuVocalía, TuAcademia, TuGuardería** (una instalación por cliente:
tuguarderia, dogssy, …) y los que vengan (veterinaria, TuPass…). El ERP QF **no** se conecta.

El contrato con los programas está en [`docs/PROTOCOLO.md`](docs/PROTOCOLO.md) (v1: cifras y Entrar) y
[`docs/PROTOCOLO_V2.md`](docs/PROTOCOLO_V2.md) (v2: solicitudes, clientes, demos, cobros y finanzas; ver la sección 8).
Los programas que todavía hablan v1 siguen funcionando igual (solo cifras y Entrar).

- Dirección: **https://panel.tukanec.com**
- PHP 8.3 sin framework + **SQLite** (`datos/central.sqlite`, fuera de la carpeta pública). Sin librerías de JavaScript.

---

## 1. Instalar (servidor qfcorp)

Una sola línea, con tu usuario normal (no root):

```
curl -fsSL https://raw.githubusercontent.com/qfcorp/tukan-central/main/deploy/instalar.sh -o ~/instalar_central.sh && bash ~/instalar_central.sh
```

Se puede correr otra vez para **actualizar**: no borra la base ni cambia la llave.
Valores por defecto (se pueden cambiar delante del comando, ej. `DOMINIO=otro.tukanec.com bash ~/instalar_central.sh`):
`APP=/var/www/tukan-central`, `DOMINIO=panel.tukanec.com`, `REPO=https://github.com/qfcorp/tukan-central.git`.

Qué hace: instala PHP (sqlite3, curl, mbstring), baja el código, crea `config/.env` con una llave nueva
`TUKAN_CENTRAL_KEY` (la conserva si ya existe), crea la base, configura nginx (puerto 80, `server_name panel.tukanec.com`),
programa la revisión cada 15 minutos y el respaldo diario (3:40, guarda 14) y al final muestra un resumen.

### Crear tu usuario

```
sudo -u www-data php /var/www/tukan-central/bin/crear_admin.php
```

Pide nombre, correo y contraseña. Al entrar por primera vez, el panel te pide activar la verificación en 2 pasos:
ten a mano tu celular con Google Authenticator, Microsoft Authenticator, Authy o 2FAS.

### Ruta en Cloudflare

Cloudflare Zero Trust → Networks → Tunnels → tu túnel → **Public Hostname** → Add:

| Campo | Valor |
|---|---|
| Subdomain / Domain | `panel` / `tukanec.com` |
| Type | `HTTP` |
| URL | `localhost:80` |
| Additional settings → HTTP Settings → HTTP Host Header | `panel.tukanec.com` |

---

## 2. Conectar cada programa

1. En el panel: **Programas → Agregar programa**. Elige el tipo, escribe un ID corto (ej. `tuevento`, `dogssy`) y la dirección (`https://…`).
2. El panel muestra **una sola vez** el comando con el secreto. Cópialo.
3. Entra por SSH al servidor donde vive ese programa, pega el comando y Enter.
4. Vuelve al panel y toca **Probar conexión**.

Comandos (el panel los arma solo; `ID`, `SECRETO`, `CARPETA` y la URL del panel se reemplazan):

| Programa | Comando |
|---|---|
| TuEvento | `sudo bash /var/www/tuevento/deploy/conectar_central.sh ID SECRETO https://panel.tukanec.com` |
| TuSalón | `sudo bash /var/www/tusalon/deploy/conectar_central.sh ID SECRETO https://panel.tukanec.com` |
| TuVocalía (VPS) | `sudo bash /var/www/ligas/vps/conectar_central.sh ID SECRETO https://panel.tukanec.com` |
| TuAcademia (VPS) | `sudo bash /var/www/tuacademia/vps/conectar_central.sh ID SECRETO https://panel.tukanec.com` |
| TuGuardería | `sudo bash /home/qf/github/tuguarderia/conectar_central.sh CARPETA ID SECRETO https://panel.tukanec.com` |

Las plantillas viven en **un solo lugar**: `src/Productos.php` (`Productos::TIPOS`). Para un programa nuevo se agrega
una entrada (nombre, color, letra, para quién es, comando). Cada programa también puede tener su comando propio
(Editar → Avanzado).

Si un secreto se pierde o se filtra: ficha del programa → **Cambiar secreto** (el anterior deja de servir al instante)
y se pega el comando nuevo.

---

## 3. Emergencias

**Perdí el celular** → en la pantalla del código escribe uno de tus **códigos de respaldo** (los 8 que se mostraron al
activar). Luego, en Mi cuenta, genera códigos nuevos.

**Perdí el celular y los códigos** → en el servidor:

```
sudo -u www-data php /var/www/tukan-central/bin/quitar_2fa.php tu@correo.com
```

Al entrar, el panel te pedirá activar la verificación otra vez con el celular nuevo. Queda anotado en la auditoría.

**Olvidé la contraseña** → `sudo -u www-data php /var/www/tukan-central/bin/crear_admin.php` con tu mismo correo
(agrega `--quitar-2fa` o `--reset-2fa` para borrar también la verificación).

**Bloqueado por intentos** → después de 5 errores se bloquea 15 minutos (por correo y por IP). Espera o, si es urgente,
`quitar_2fa.php` también limpia los intentos de ese correo.

**Se perdió config/.env** → la llave está en el respaldo: `/var/backups/tukan-central/env_*.txt`. Sin ella los secretos
guardados no se pueden leer (habría que Cambiar secreto en cada programa y quitar el 2FA a cada usuario).

---

## 4. Telegram (opcional)

En `/var/www/tukan-central/config/.env` agrega:

```
TELEGRAM_BOT_TOKEN=123456:ABC...
TELEGRAM_CHAT_ID=123456789
```

El panel avisa cuando un programa se queda sin conexión (después de 2 fallos seguidos), cuando vuelve y cuando hay
negocios nuevos. Se prueba en **Ajustes → Enviar mensaje de prueba**. El instalador conserva estas líneas.

---

## 5. Seguridad (resumen)

- Contraseña (`password_hash`) + **TOTP obligatorio** (RFC 6238, 30 s, 6 dígitos, ±1 paso; un código no sirve dos veces).
  QR generado en PHP puro (`src/Qr.php`). 8 códigos de respaldo de un solo uso, guardados con hash.
- Bloqueo: 5 intentos fallidos (contraseña o código) → 15 minutos, por correo y por IP.
- CSRF en todos los formularios + revisión de `Origin`; galleta `HttpOnly`, `SameSite=Lax`, `Secure` y prefijo `__Host-`
  detrás de Cloudflare (`X-Forwarded-Proto` / `CF-Visitor`); id de sesión nuevo al entrar; 30 min sin uso / 12 h máximo.
- CSP sin scripts ni estilos en línea, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer` (el token SSO nunca sale por Referer), HSTS.
- Secretos de los programas y de 2FA **cifrados** (XChaCha20-Poly1305) con `TUKAN_CENTRAL_KEY`.
- IP real: `CF-Connecting-IP`, solo si la conexión viene del túnel (127.0.0.1).
- **Auditoría** de todo: ingresos, fallos, bloqueos, 2FA, cada "Entrar a X", cambios de programas y secretos, usuarios, ajustes.
- Roles: **dueño** (todo) y **soporte** (ve el panel, entra a los programas, prueba conexiones, actualiza datos y marca
  solicitudes como atendidas; no agrega/edita/borra programas, no ve comandos ni secretos, usuarios, auditoría ni ajustes,
  y **no** ve teléfonos/correos, comprobantes, plata, demos ni cobros — ver la tabla de la sección 8).
- Datos de contacto de los clientes y de las solicitudes: **cifrados** en la copia local (solo los ve el dueño; en
  Telegram el teléfono va enmascarado: `099•••4567`). Las contraseñas temporales de las demos se muestran **una vez** y
  nunca se guardan (ni en la base, ni en la auditoría, ni en la sesión).

---

## 6. Estructura

```
app/bootstrap.php        configuración, sesión segura, funciones de ayuda
app/paginas/*.php        una página por ruta (/?r=...)
app/vistas/*.php         HTML
public/index.php         rutas y permisos   ·  public/assets/ (app.css, app.js)
src/                     Totp, Qr, Cifrado, Token (protocolo v1 y v2), Productos (tipos y comandos), Instancias,
                         Estado (revisión del JSON), Monitor (consultas en paralelo, caché, historial, Telegram),
                         Acceso (ingreso, 2FA, bloqueo, sesiones, roles), Auditoria, Ajustes, Telegram, Db,
                         v2: ClienteV2 (pedidos firmados), ErrorPrograma, Capacidades, Formulario, Clientes,
                         Solicitudes, Operaciones (ejecutar idempotente), Contacto, Finanzas, Licencias,
                         Sincronizador (cron), Analisis (embudo, vencimientos), Xlsx, Exportar
bin/                     crear_admin.php, quitar_2fa.php, revisar.php (cron)
deploy/                  instalar.sh, respaldo.sh
datos/                   central.sqlite y sesiones (no va a GitHub)
tests/                   run_tests.php (+ run_tests_v2.php), run_web.sh (punta a punta), integracion.sh
                         (conectores reales), mock/ (programa de prueba v1 y v2), form.php, capturas.py
```

## 7. Pruebas

```
php tests/run_tests.php       # lógica: TOTP (vectores RFC 6238), QR (leído con OpenCV), token byte a byte, cifrado,
                              # bloqueo, roles, comandos, JSON de estado, errores reales de los conectores
bash tests/run_web.sh         # punta a punta con programas de prueba que cumplen el protocolo + capturas (Playwright)
SIN_CAPTURAS=1 bash tests/run_web.sh
bash tests/integracion.sh     # con los conectores REALES de TuEvento y TuSalón (php -S + PostgreSQL de prueba) y
                              # TuVocalía (SQLite, con su pruebas/tukan_central/reiniciar.sh, que solo toca data/ de su repo):
                              # agregar, probar conexión, cifras en Inicio, Entrar hasta su panel de super usuario,
                              # token repetido, secreto cambiado, límite 429, programa apagado y, con v2: capacidades,
                              # clientes, crear demo y entrar con la clave, alargar prueba, cobro + reintento idempotente,
                              # finanzas (ganancia = ingresos − gastos), suspender/reactivar y registro nuevo en Solicitudes.
                              # Si falta un repo o su usuario de PostgreSQL, ese programa se omite.
```

`run_web.sh` levanta programas de prueba v2 completos (`tests/mock/tukan_central.php`) y uno que solo habla v1
(`MOCK_V1=400` o `404`). Capturas a 390 px y en escritorio (Chromium de `/opt/pw-browsers`), sin errores de consola y
sin nada que se salga a lo ancho.

Puertos que usan las pruebas: 8260-8269 (`run_web.sh`) y 8240-8249 (`integracion.sh`: 8240 panel, 8241 TuEvento,
8242 TuSalón, 8243 TuVocalía).

---

## 8. Versión 2: solicitudes, clientes, demos, cobros y finanzas

El panel pregunta a cada programa qué sabe hacer (`capacidades`, una vez al día o cuando cambia su versión) y dibuja las
pantallas con eso: los botones y formularios **los manda el programa** (`acciones_posibles` y `formularios`), así un
programa nuevo funciona sin tocar el panel. Si un programa contesta 400/404 a `capacidades`, queda como **v1**.

**Menú:** Inicio · Solicitudes (con el número por atender) · Clientes · Demos · Finanzas · Programas · Más
(Vencimientos, Embudo, Auditoría, Usuarios, Ajustes, Ayuda).

| Pantalla | Qué hace |
|---|---|
| Inicio | Por atender, vencen esta semana, cobrado y ganancia del mes; "Para atender hoy" con un botón por solicitud |
| Solicitudes | Una sola bandeja de todos los programas (pestañas y filtros); detalle, comprobantes, aprobar / rechazar, marcar atendida |
| Clientes | Todos los clientes de todos los programas, filtros (estado, vence en, sin uso, origen, buscar) y **Recordar por WhatsApp** |
| Ficha del cliente | Estado, pagos, acciones del programa: alargar prueba, registrar pago (con cotización), anular, suspender / reactivar, cambiar plan, nueva clave, Entrar como el dueño |
| Demos | Crear una demo en cualquier programa que lo permita (la clave se muestra una vez, con el mensaje de WhatsApp listo) e historial |
| Finanzas | Ingresos por programa y mes, gastos (categorías de §9.6), gastos fijos de cada mes, otros ingresos, ganancia por mes y programa; descarga en Excel (.xlsx) y CSV |
| Vencimientos / Embudo | Calendario de lo que vence; interesados → demos → pagaron |

**Cómo funcionan los formularios:** cada botón viene del programa con su `accion`, `datos` fijos y `campos`. El panel
los muestra (en una hoja abajo en el celular, o en una página sin JavaScript), firma los datos ocultos con la sesión
(no se pueden cambiar en el navegador), crea un `operacion_id` al abrir el formulario y manda el pedido firmado
(`X-Tukan-Token` con el hash del cuerpo). Si el programa no responde, se ve **Revisar**: reintentar manda **el mismo
cuerpo, byte a byte** (se guarda cifrado por `operacion_id`), así nunca se cobra dos veces. Los errores del programa salen
junto al campo, en palabras sencillas. Todo queda en la auditoría.

**Permisos:** soporte puede ver, entrar, probar, actualizar y marcar atendida; solo el **dueño** ve contactos y
comprobantes, crea demos, cobra / anula / suspende / cambia planes, y ve Finanzas. Anular un pago y guardar un plan piden
el código de 2FA.

**Revisión automática** (`bin/revisar.php`, cada 15 min): estado de todos; solicitudes nuevas cuando cambia su huella
(aviso a Telegram con el teléfono enmascarado, respetando el horario de silencio; más de 5 → un solo resumen); clientes
cada hora; finanzas una vez al día (desde las 03:10); resumen diario por Telegram (07:30–12:00) y los gastos fijos del
mes. No hace falta otra tarea de cron ni la extensión zip de PHP (el Excel se arma en PHP puro).
