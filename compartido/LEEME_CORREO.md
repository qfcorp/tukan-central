# Correo de Tukán (`tukan_correo.php`): cómo lo usa cada programa

Una sola librería, **un solo archivo PHP sin composer**, para mandar correos desde **info@tukansoftware.com**
(Panel Tukán Central y los 12 programas del servidor qfcorp). Esta guía es para agregar **«Enviar por correo»** en la
pantalla **«Demo lista»** de cada programa (igual que en el Panel).

## 1. Configurar el servidor (una sola vez, ya lo hace el Panel)

En qfcorp, con el usuario normal:

```
cd /var/www/tukan-central && git pull && bash deploy/configurar_correo.sh
```

Pregunta servidor (`mail.qfproducciones.com`, el HestiaCP), puerto (`587`), seguridad (`tls`), usuario
(`info@tukansoftware.com`) y la clave del buzón (no se ve al escribir). Escribe **`/etc/tukan-correo.env`**
(`root:www-data`, `640`) y manda un correo de prueba. Si el certificado no coincide con el nombre, probar
`mail.tukansoftware.com`. Todos los programas del servidor leen ese mismo archivo: **no** copies la clave a cada programa.

Probar desde la consola (como el usuario de PHP, para comprobar los permisos):

```
sudo -u www-data php /var/www/tukan-central/compartido/tukan_correo.php probar tu@correo.com
```

> Si el programa corre con otro usuario (no `www-data`), ese usuario tiene que poder leer `/etc/tukan-correo.env`
> (agrégalo al grupo `www-data`), o el programa no podrá mandar correos.

## 2. Copiar la librería al programa

Copia **sin cambiar nada** `compartido/tukan_correo.php` del repo `qfcorp/tukan-central` a la carpeta de código del
programa: `src/tukan_correo.php` (o `app/tukan_correo.php`, `lib/`… donde estén sus otros archivos PHP; fuera de la
carpeta pública si se puede). En la cabecera dice la versión: si sale una versión nueva, se vuelve a copiar.

```php
require_once __DIR__ . '/tukan_correo.php';
```

Funciones (globales, prefijo `tukan_correo_`; se puede incluir dos veces sin problema):

| Función | Qué hace |
|---|---|
| `tukan_correo_listo(): bool` | ¿Está configurado? (servidor, remitente y clave) |
| `tukan_correo_enviar(string $para, string $asunto, string $html, string $texto, ?string &$error = null, array $opciones = []): bool` | Manda HTML + texto (multipart/alternative, UTF-8). `false` y `$error` en palabras sencillas si no se pudo. `$opciones`: `responder_a` (Reply-To), `nombre_para` |
| `tukan_correo_demo(array $d): array` | El correo **«Su demo de {Programa} está lista»** con la marca Tukán. `$d`: `programa`, `enlace`, `usuario`, `clave`, `hasta` (`Y-m-d`), `contacto`, `negocio`. Devuelve `['asunto', 'html', 'texto']` |
| `tukan_correo_valido(string $correo): bool` | Correo válido y sin saltos de línea (anti-inyección) |
| `tukan_correo_estado(): array` | Servidor, puerto, usuario, remitente y `listo` (**sin** la clave), para mostrar |
| `tukan_correo_fecha('2026-10-30')` | «viernes 30 de octubre de 2026» |

Lo que la librería ya cuida (no hay que repetirlo): STARTTLS (587) o SSL (465) con el certificado verificado,
AUTH LOGIN (o PLAIN), timeout corto (10 s), asunto y nombres con `=?UTF-8?B?…?=`, saltos de línea fuera de las
cabeceras, correo destino validado, y **la clave del buzón nunca sale** en `$error` ni en ningún registro.

## 3. «Demo lista»: texto y botón

En la pantalla donde el programa muestra el usuario y la contraseña de una demo recién creada (la que ya tiene
**Enviar por WhatsApp**), agrega debajo:

- Título: **Enviar por correo**
- Campo **Correo del cliente** (`type="email"`), ya lleno con el correo de la demo.
- Ayuda bajo el campo: *Le llega «Su demo de {Programa} está lista» con sus datos de entrada, desde info@tukansoftware.com.*
- Botón **Enviar por correo** (el mismo estilo del botón principal del programa).
- Si `tukan_correo_listo()` es `false`: en vez del formulario, un botón desactivado **Configure el correo de Tukán** y
  debajo: *En el servidor, una sola vez:* `cd /var/www/tukan-central && git pull && bash deploy/configurar_correo.sh`
- Al enviar bien: **Correo enviado a {correo} desde info@tukansoftware.com. Que revise también la carpeta de spam.**
- Si falla: el `$error` en rojo y el formulario otra vez.

Reglas de la casa:

1. **POST con CSRF** (el token CSRF que ya use el programa) y solo para quien puede crear demos.
2. **La contraseña se ve una sola vez y no se guarda** (ni en la base, ni en la sesión, ni en los registros). El envío se
   hace desde esa misma pantalla: la contraseña viaja en el POST dentro de un **dato sellado** (cifrado o firmado con la
   llave del programa, que vence en 30 minutos) o con un **token temporal en la sesión que se borra al enviar**. Nunca un
   campo oculto con la contraseña en claro que se pueda cambiar desde el navegador.
3. Dejar constancia en la bitácora / auditoría del programa: **«Envió la demo por correo a {correo}»**, **sin** la contraseña.
4. **Nunca** «gratis» ni precios en el correo (la plantilla `tukan_correo_demo` ya lo cumple: no la cambies).

Ejemplo mínimo (adaptar a cómo el programa arma sus pantallas):

```php
require_once __DIR__ . '/tukan_correo.php';

// En la pantalla «Demo lista» (solo aquí se conoce la clave):
$sello = mi_cifrar(json_encode(['usuario' => $usuario, 'clave' => $clave, 'enlace' => $urlPanel,
                                'hasta' => $fin, 'contacto' => $nombre, 'negocio' => $negocio, 'exp' => time() + 1800]));
// <form method="post" action="?r=demo_correo"> csrf + <input type="hidden" name="sello" value="$sello">
//   + <input type="email" name="para" value="$correoDeLaDemo"> + <button>Enviar por correo</button>

// Al recibir el POST (después de revisar CSRF y permisos):
$d = json_decode(mi_descifrar($_POST['sello']), true);
if (!$d || $d['exp'] < time()) { /* «Este envío ya caducó» */ }
$para = trim((string) ($_POST['para'] ?? ''));
if (!tukan_correo_valido($para)) { /* «Escribe un correo válido» */ }
$m = tukan_correo_demo(['programa' => 'TuSalón'] + $d);
if (tukan_correo_enviar($para, $m['asunto'], $m['html'], $m['texto'], $error, ['nombre_para' => $d['contacto']])) {
    bitacora("Envió la demo por correo a $para");          // sin la clave
} else {
    // mostrar $error (no trae la clave)
}
```

El Panel Tukán Central lo hace así: `src/CorreoDemo.php` (sello cifrado con XChaCha20-Poly1305, atado al usuario, 30 min,
y un número de un solo uso en la sesión), `app/paginas/demo_correo.php` (el POST) y `app/vistas/resultado.php` (la pantalla).

## 4. Probar

- `php tukan_correo.php probar tu@correo.com` (con `sudo -u www-data` en el servidor).
- En las pruebas del programa se puede usar el SMTP de mentira del Panel: `tests/smtp_falso.py` (sin librerías:
  `--modo starttls|ssl|plano`, guarda cada correo en `--dir`) y apuntar la librería a otro archivo con
  `TUKAN_CORREO_ENV=/ruta/prueba.env`. Con un certificado propio: `TUKAN_SMTP_CA=/ruta/cert.pem`.
  `TUKAN_SMTP_SEGURIDAD=ninguna` (sin TLS) **solo** funciona con un servidor en `127.0.0.1`/`localhost`.
- Ver `tests/run_tests_correo.php` del Panel: cabeceras, UTF-8, multipart, clave equivocada, anti-inyección, timeout.
