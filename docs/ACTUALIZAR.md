# Actualizar los programas de un servidor (comando `actualizar` y botón del panel)

Para que Dimitry **apruebe** cada actualización con una palabra (`actualizar`) o un botón (Más → Actualizaciones),
sin pegar comandos largos. **Nada se publica solo**: no hay horario ni detección automática de commits. El servidor
solo actúa cuando el dueño lo pide.

## Instalar (una sola vez por servidor, con el usuario normal `qf`)

```
bash /var/www/tukan-central/deploy/instalar.sh && bash /var/www/tukan-central/deploy/instalar_actualizador.sh
```

El primero actualiza el panel (como siempre) y el segundo deja:

| Qué | Dónde | Dueño y permisos |
|---|---|---|
| Comando `actualizar` | `/usr/local/bin/actualizar` | root 755 (copia, no enlace al repo) |
| Servicio que atiende los pedidos | `/usr/local/sbin/tukan-actualizar-pedidos` + `tukan-actualizar.path` / `.service` | root 700 |
| Ayudante y clase del pedido | `/usr/local/lib/tukan-actualizar/{ayudante.php,Pedido.php}` | root 644 |
| Llave para firmar pedidos | `/etc/tukan-actualizar/llave` | root:www-data 640 |
| Usuario que corre los instaladores | `/etc/tukan-actualizar/actualizador.conf` (`USUARIO=qf`) | root 644 |
| Programas extra o cambiados | `/etc/tukan-actualizar/programas.conf` | root 644 |
| Cola de pedidos del panel | `/var/lib/tukan-actualizar/pedidos` | root:www-data 770 |
| Pedidos tomados / ids usados | `/var/lib/tukan-actualizar/{tomados,usados}` | root 700 |
| Estado para el panel | `/var/lib/tukan-actualizar/estado` (`versiones.json`, `ultima.json`, `pedido-ID.json/.log`) | qf:www-data 2750 |
| Candado (flock) | `/var/lib/tukan-actualizar/trabajo/actualizar.lock` | qf 700 |
| Respaldos de las bases | `/var/backups/tukan-actualizar/PROGRAMA/` (últimos 10) | qf 700, archivos 600 |
| Logs | `/var/log/tukan-actualizar/` (`FECHA_PROGRAMA.log`, `actualizar.log`, `pedidos.log`) | qf:adm 2750 |

Al final prueba el camino completo del panel (un pedido firmado de solo revisar) y muestra un resumen.
Si se cambia `deploy/actualizar*.sh` o `src/Pedido.php`, hay que correr `instalar_actualizador.sh` otra vez (las copias de
root no se cambian solas, a propósito: ni el panel ni el repo pueden cambiar código que corre como root).

## Uso en la consola

```
actualizar              # muestra qué tiene versión nueva en GitHub y pregunta «Actualizar todos? [S/n]»
actualizar todo         # todos los que tengan algo nuevo, sin preguntar
actualizar tuevento     # solo ese (o varios: actualizar tuevento tusalon)
actualizar estado       # versión de cada programa y si hay algo nuevo (no cambia nada)
```

Sin sudo delante: el comando pide la contraseña de sudo **una vez** (y la mantiene viva mientras corre).
Como root se niega.

Por cada programa con algo nuevo, en orden (el panel siempre al final, porque su instalador reinicia PHP):

1. **Respaldo** de su base: lee `config/.env` (`*_DB_NAME/_USER/_PASS/_HOST/_PORT`). PostgreSQL → `pg_dump -Fc` con el
   usuario de la app; MySQL/MariaDB (`*_DB_DRIVER=mysql` o puerto 3306) → `mysqldump | gzip`; el panel (SQLite) →
   `sqlite3 .dump | gzip`; TuGuardería → `sudo mysqldump` de cada instalación. **Si el respaldo falla, no se actualiza.**
2. Pasa el código a la versión nueva (`git merge --ff-only`) y corre **su instalador** con el log en `/var/log/tukan-actualizar/`.
   Mientras corre, el `git pull` del instalador se cambia por nada: se instala **exactamente** la versión aprobada
   (si alguien sube algo mientras tanto, no entra) y volver atrás funciona.
   Google Play queda como estaba (`GOOGLE_PLAY=si/no` según `GOOGLE_PLAY_ACTIVO=1` o `app/android.txt`): no hay preguntas.
3. Prueba que la página responda **200** (hasta 40 s; usa el dominio del `.env` como Host).
4. Si algo falla (instalador con error, página sin 200, el código no quedó en la versión): `git reset --hard` al commit
   anterior, corre el instalador otra vez, vuelve a probar y lo dice claro: **VOLVIO ATRAS** o **SIGUE FALLANDO: revisa YA**.
   La base **no** se restaura sola (podría borrar lo que los clientes guardaron): el mensaje dice dónde quedó el respaldo.

Al final: tabla de resumen, `ultima.json`, `versiones.json` y aviso por Telegram si el panel lo tiene configurado
(`TELEGRAM_BOT_TOKEN` y `TELEGRAM_CHAT_ID` de `/var/www/tukan-central/config/.env`; el token va por la entrada de curl,
no en la línea de comandos).

Programas que conoce (solo usa los que existen en el servidor):

| id | Carpeta | Instalador | Prueba |
|---|---|---|---|
| tuevento, tuplanner, tusalon, turemolque | `/var/www/ID` | `bash /var/www/ID/deploy/instalar.sh` | `http://127.0.0.1/?r=login` con su dominio |
| tuspa (8108), tugroomer (8109), tuhospedaje (8104) | `/var/www/ID` | igual | `http://127.0.0.1:PUERTO/?r=login` |
| erp-productoras | `/var/www/erp-productoras` | igual | `http://127.0.0.1:8106/login.php` |
| tuguarderia | `/home/qf/github/tuguarderia` | `sudo bash instalar.sh DOMINIO CARPETA` por cada instalación | `http://127.0.0.1:PUERTO/login.php` |
| tukan-central | `/var/www/tukan-central` | `bash deploy/instalar.sh` | `http://127.0.0.1/?r=login` con `panel.tukanec.com` |

Las instalaciones de TuGuardería (tuguarderia, dogssy, …) se encuentran solas: son los sitios de nginx cuya primera línea
dice `# CARPETA - generado por instalar.sh (TuGuardería…` (de ahí salen el dominio y el puerto).

## Botón del panel (Más → Actualizaciones)

1. **Buscar novedades**: el panel deja un pedido firmado de tipo *revisar*; el servidor hace `git fetch` (no cambia nada)
   y escribe `versiones.json`. La pantalla se recarga sola cada 5 s mientras corre.
2. Cada programa muestra la versión instalada, la de GitHub y los **títulos de los commits nuevos**.
3. **Actualizar** (por programa) o **Actualizar todo**: piden el **código de 2 pasos** de la app. Solo el rol dueño.
4. El panel deja un pedido firmado con **el commit exacto** que se ve en pantalla. Si GitHub cambió después, ese programa
   no se toca («busca novedades y aprueba otra vez»).
5. El resultado (y lo que hizo el servidor) se ve en la misma pantalla; los últimos pedidos quedan en una tabla.

Auditoría: `actualizacion_revisar`, `actualizacion_pedida`, `2fa_fallido` + `actualizacion_rechazada` (código malo) y
`actualizacion_resultado` (una vez por pedido, cuando el panel ve el final).

### Cómo viaja un pedido

```
Panel (www-data) --pedido firmado--> /var/lib/tukan-actualizar/pedidos/MOMENTO-ID.json
   systemd tukan-actualizar.path (solo cuando aparece un archivo) -> tukan-actualizar.service (root)
      1. saca el archivo de la cola (a tomados/, solo root)
      2. ayudante.php validar: HMAC-SHA256 con la llave, versión 1, vence a los 10 min, id de un solo uso (usados/),
         programas con nombre válido y commits de 40 hex. Nada de comandos ni rutas.
      3. revisar    -> runuser -u qf -- actualizar estado
         actualizar -> sudo temporal (abajo) + runuser -u qf -- actualizar PROG@COMMIT ... --pedido ID
      4. estado/pedido-ID.json + .log para el panel; pedidos.log
```

Un pedido con firma mala o cambiado a mano se rechaza y solo se anota en `pedidos.log` (no se escribe nada a nombre de
ningún id). Uno vencido o del futuro se rechaza y se avisa al panel. Uno repetido se rechaza sin tocar el resultado del original.

## Sudo: la decisión y el riesgo

**Lo que hacen los instaladores con sudo** (revisados uno por uno): `apt-get install`, `chown -R` y `chmod -R` sobre
`/var/www/…`, `mkdir`, `tee` a `/etc/nginx/sites-available/…`, `ln -sf`, `nginx -t`, `systemctl reload/restart`
(nginx, php-fpm, postgresql), `sudo -u postgres psql/createdb/dropdb`, `crontab`, `sudo -u www-data php …`,
`sudo APP=… …/deploy/respaldo.sh` (un script del repo como root) y TuGuardería entero con `sudo bash instalar.sh`.

**No se puede limitar bien con una lista en sudoers**: `tee` a cualquier archivo, `chown -R`, `apt-get install` o
`bash script-del-repo` ya equivalen a root (los repos son del usuario `qf`, que puede cambiarlos). Una lista sería
seguridad de mentira y además se rompería con el primer comando nuevo de un instalador.

**Lo que se hizo** (lo más limitado que funciona):

- **Ningún sudo permanente nuevo.** `qf` sigue escribiendo su contraseña en la consola, como hoy.
- El servicio corre como **root**, pero solo cuando hay un pedido **válido**, y corre los instaladores **como `qf`**
  (`runuser -u qf`, entorno limpio), nunca como root.
- Solo **durante** un pedido aprobado crea `/etc/sudoers.d/zz-tukan-actualizar-temporal`
  (`qf ALL=(ALL:ALL) NOPASSWD: ALL`, revisado con `visudo -cf`) y lo **borra al terminar** (también al empezar, por si una
  corrida se cortó, y `ExecStopPost` de systemd). Un pedido de solo *revisar* no lo crea.
- `www-data` (el panel) **no tiene sudo** y no puede correr nada: solo deja un archivo en la cola. Las copias de root del
  comando y del servicio no las puede cambiar ni el panel ni el repo.

**Riesgos que quedan** (y por qué se aceptan):

1. Mientras corre un pedido aprobado (minutos), cualquier proceso que ya corra como `qf` podría usar sudo sin contraseña.
   Hoy `qf` ya es administrador (está en el grupo sudo): quien controle `qf` puede robar la contraseña igual. La ventana
   es corta y solo se abre cuando el dueño aprobó con su código de 2 pasos.
2. La llave de los pedidos la puede leer `www-data`, o sea **cualquier programa PHP del servidor** (todos corren como
   www-data). Un atacante que logre correr código en uno de ellos podría firmar un pedido. Lo máximo que logra es
   **actualizar un programa a la versión que ya está en GitHub** (la que subió Dimitry): no puede elegir código, comandos
   ni rutas. Además cada actualización avisa por Telegram y queda en `pedidos.log`. Si pasa algo raro:
   `sudo rm /etc/tukan-actualizar/llave && bash /var/www/tukan-central/deploy/instalar_actualizador.sh` (llave nueva).
3. Quien tenga acceso de escritura a los repos de GitHub (`qfcorp/...`) decide qué se instala, como hoy con `git pull`.
4. La base no se restaura sola si una actualización falla después de una migración: el respaldo queda en
   `/var/backups/tukan-actualizar/PROGRAMA/` (PostgreSQL: `pg_restore`; MySQL/SQLite: `gunzip -c ARCHIVO | mysql BASE`).
5. Si el reloj del servidor se atrasa mucho, los pedidos salen «vencidos» o «del futuro» (se rechazan, no se instala nada).

**Apagarlo** (si alguna vez hace falta): `sudo systemctl disable --now tukan-actualizar.path` (el botón deja de funcionar;
el comando `actualizar` en la consola sigue funcionando).

## Pruebas

`tests/run_actualizar.sh` (lo corre también `tests/run_web.sh`): repos git locales (un origin *bare* y un clon por
programa), instaladores falsos, páginas falsas (`php -S`), `sudo`, `pg_dump`, `mysqldump` y `sqlite3` falsos y un
Telegram falso. Rutas por variables `TUKAN_ACT_*`. Cubre: detectar lo nuevo y no hacer nada sin cambios, respaldo
(y guardar 10), instalador con `git pull` omitido, página 200 con el Host correcto, volver atrás (página rota,
instalador con error, sigue fallando), no actualizar sin respaldo, candado, TuGuardería con 2 instalaciones,
MySQL y SQLite, versión fijada, pedido válido del panel con sudo temporal que se quita, y pedidos falsos, cambiados,
vencidos, repetidos o con otra versión. `tests/run_tests_actualizar.php` prueba la firma y la parte del panel;
`tests/run_web.sh` §40 la pantalla con 2FA, la cola y la auditoría.
