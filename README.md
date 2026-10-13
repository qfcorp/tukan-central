# Panel Tukán Central

Un solo panel para el dueño de **Tukán Software** (Dimitry): entra **una vez** (con verificación en 2 pasos),
ve las cifras de **todos** sus programas en una pantalla y entra a cualquiera con un toque.

Programas que se conectan: **TuEvento, TuSalón, TuVocalía, TuAcademia, TuGuardería** (una instalación por cliente:
tuguarderia, dogssy, …) y los que vengan (veterinaria, TuPass…). El ERP QF **no** se conecta.

El contrato con los programas está en [`docs/PROTOCOLO.md`](docs/PROTOCOLO.md) (Protocolo Tukán Central v1).

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
(agrega `--quitar-2fa` para borrar también la verificación).

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
- Roles: **dueño** (todo) y **soporte** (ve el panel, entra a los programas y prueba conexiones; no agrega/edita/borra
  programas, no ve comandos ni secretos, usuarios, auditoría ni ajustes).

---

## 6. Estructura

```
app/bootstrap.php        configuración, sesión segura, funciones de ayuda
app/paginas/*.php        una página por ruta (/?r=...)
app/vistas/*.php         HTML
public/index.php         rutas y permisos   ·  public/assets/ (app.css, app.js)
src/                     Totp, Qr, Cifrado, Token (protocolo), Productos (tipos y comandos), Instancias,
                         Estado (revisión del JSON), Monitor (consultas en paralelo, caché, historial, Telegram),
                         Acceso (ingreso, 2FA, bloqueo, sesiones, roles), Auditoria, Ajustes, Telegram, Db
bin/                     crear_admin.php, quitar_2fa.php, revisar.php (cron)
deploy/                  instalar.sh, respaldo.sh
datos/                   central.sqlite y sesiones (no va a GitHub)
tests/                   run_tests.php (lógica), run_web.sh (punta a punta), mock/ (programa de prueba), capturas.py
```

## 7. Pruebas

```
php tests/run_tests.php       # lógica: TOTP (vectores RFC 6238), QR (leído con OpenCV), token byte a byte, cifrado,
                              # bloqueo, roles, comandos, JSON de estado
bash tests/run_web.sh         # punta a punta con programas de prueba que cumplen el protocolo + capturas (Playwright)
SIN_CAPTURAS=1 bash tests/run_web.sh
```
