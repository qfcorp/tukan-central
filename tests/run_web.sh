#!/usr/bin/env bash
# Pruebas de punta a punta del Panel Tukan Central (entorno simulado, sin internet).
# Levanta el panel con "php -S" y varios PROGRAMAS DE PRUEBA (tests/mock/tukan_central.php) que cumplen el protocolo.
# Uso: bash tests/run_web.sh          (SIN_CAPTURAS=1 para no sacar las capturas con Playwright)
set -u
cd "$(dirname "$0")/.." || exit 1
TMP=$(mktemp -d)
export TZ=America/Guayaquil
PC=8260                      # panel central (las pruebas usan solo los puertos 8260-8269)
BASE="http://127.0.0.1:$PC"
TG=8261                      # Telegram de mentira
# Programas de prueba: 8262 TuEvento (v2) · 8263 TuSalón (v1, contesta 400) · 8264 Dogssy (TuGuardería v2, una sola empresa)
#                     8265 TuVocalía (v2 completo) · 8266 TuAcademia (v1 que contesta 404)
#                     8267 Guau y 8269 Miau (TuGuardería que se puede borrar desde el panel) · 8268 Cloudflare de mentira
export TUKAN_CENTRAL_DATOS="$TMP/datos"
TUKAN_CENTRAL_KEY=$(php -r "echo bin2hex(random_bytes(32));"); export TUKAN_CENTRAL_KEY
export TUKAN_CENTRAL_URL="$BASE"
export TUKAN_CENTRAL_ENV=/no/existe
export TELEGRAM_BOT_TOKEN=prueba123 TELEGRAM_CHAT_ID=999 TELEGRAM_API_BASE="http://127.0.0.1:$TG"
export TUKAN_REVISAR_ESPERA=0
# Borrar instalaciones: Cloudflare DE MENTIRA (tests/mock/cloudflare.php en 8268) y sin esperar entre revisiones
CF=8268
export CLOUDFLARE_API_BASE="http://127.0.0.1:$CF/client/v4" TUKAN_BAJAS_CONFIRMAR_SEG=0
OK=0; FALLAS=0
PIDS=()

limpiar() { for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null; done; pkill -f "php -S 127.0.0.1:826[0-9]" 2>/dev/null; [ "${GUARDAR_TMP:-0}" = "1" ] && echo "Archivos de la prueba en $TMP" || rm -rf "$TMP"; }
trap limpiar EXIT

check() { if [ "$2" == "$3" ]; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — esperado [$2], salio [$3]"; fi; }
contiene() { if grep -q -- "$3" "$2"; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — no aparece [$3]"; fi; }
no_contiene() { if grep -q -- "$3" "$2"; then FALLAS=$((FALLAS+1)); echo "  FALLA $1 — aparece [$3]"; else OK=$((OK+1)); echo "  OK    $1"; fi; }
titulo() { echo; echo "== $1"; }
sql() { php -r '$d=new PDO("sqlite:".$argv[1]); foreach($d->query($argv[2])->fetchAll(PDO::FETCH_NUM) as $f) echo implode("|",$f),"\n";' "$TUKAN_CENTRAL_DATOS/central.sqlite" "$1"; }
audit_n() { sql "SELECT count(*) FROM auditoria WHERE accion='$1'"; }

# --- peticiones (JAR = galleta del navegador; IP = CF-Connecting-IP simulada) ---
JAR="$TMP/jar_dueno"; IP="198.51.100.10"
get() { curl -s -b "$JAR" -c "$JAR" -H "CF-Connecting-IP: $IP" -o "$TMP/pag.html" -w "%{http_code}" "$BASE/?$1"; }
csrf() { grep -o 'name="csrf" content="[^"]*"' "$TMP/pag.html" | head -1 | sed 's/.*content="//;s/"$//'; }
# post "ruta" datos...  (toma el token de la última página abierta) -> "codigo destino"
post() {
  local ruta=$1; shift
  local t; t=$(csrf); [ -z "$t" ] && { get "r=login" > /dev/null; t=$(csrf); }
  curl -s -b "$JAR" -c "$JAR" -H "CF-Connecting-IP: $IP" -o "$TMP/post.html" -w "%{http_code} %{redirect_url}" \
       --data-urlencode "csrf=$t" "$@" "$BASE/?r=$ruta"
}
destino() { echo "$1" | cut -d' ' -f2- | sed "s#^$BASE##"; }
# Código TOTP sin repetir paso (el panel no acepta dos veces el mismo código): codigo SECRETO ARCHIVO_ULTIMO
codigo() {
  local s=$1 f=$2 ahora ult paso
  ult=$(cat "$f" 2>/dev/null || echo 0)
  while :; do
    ahora=$(( $(date +%s) / 30 ))
    paso=$(( ult + 1 > ahora - 1 ? ult + 1 : ahora - 1 ))
    [ "$paso" -le $((ahora + 1)) ] && break
    sleep 2
  done
  echo "$paso" > "$f"
  php tests/totp.php "$s" "$paso"
}
aviso() { grep -o 'class="aviso[^"]*"><svg[^>]*>.*</svg><p>[^<]*' "$TMP/pag.html" | sed 's/.*<p>//' | head -3; }

# --- programas de prueba ---
declare -A MPID
mock() { # mock NOMBRE PUERTO ID SECRETO PRODUCTO [VAR=valor ...]
  local n=$1 puerto=$2 id=$3 sec=$4 prod=$5; shift 5
  [ -n "${MPID[$n]:-}" ] && { kill "${MPID[$n]}" 2>/dev/null; wait "${MPID[$n]}" 2>/dev/null; }
  mkdir -p "$TMP/mock_$n"
  env -i PATH="$PATH" TZ="$TZ" TUKAN_CENTRAL_ID="$id" TUKAN_CENTRAL_SECRET="$sec" TUKAN_CENTRAL_URL="$BASE" \
      MOCK_DIR="$TMP/mock_$n" MOCK_PRODUCTO="$prod" MOCK_LIMITE=1000 "$@" \
      php -S "127.0.0.1:$puerto" tests/mock/router.php > "$TMP/mock_$n.log" 2>&1 &
  MPID[$n]=$!; PIDS+=($!)
  for _ in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$puerto/" && break; sleep 0.1; done
}
parar() { kill "${MPID[$1]}" 2>/dev/null; wait "${MPID[$1]}" 2>/dev/null; MPID[$1]=""; }
probar() { # probar ID -> deja el aviso en $TMP/pag.html
  get "r=programa&id=$1" > /dev/null; post probar --data-urlencode "id=$1" > /dev/null; get "r=programa&id=$1" > /dev/null
}

# --- panel central y Telegram de mentira ---
mkdir -p "$TMP/mock_telegram"
env -i PATH="$PATH" MOCK_DIR="$TMP/mock_telegram" php -S "127.0.0.1:$TG" tests/mock/router.php > "$TMP/telegram.log" 2>&1 &
PIDS+=($!)
php -S "127.0.0.1:$PC" -t public > "$TMP/central.log" 2>&1 &
PIDS+=($!)
for _ in $(seq 1 30); do curl -s -o /dev/null "$BASE/?r=login" && break; sleep 0.1; done

# =====================================================================
titulo "1. Cabeceras de seguridad y galleta de sesion"
curl -s -D "$TMP/cab.txt" -o /dev/null "$BASE/?r=login"
contiene "CSP sin scripts en linea" "$TMP/cab.txt" "script-src 'self';"
contiene "CSP frame-ancestors none" "$TMP/cab.txt" "frame-ancestors 'none'"
contiene "X-Frame-Options DENY" "$TMP/cab.txt" "X-Frame-Options: DENY"
contiene "Referrer-Policy no-referrer" "$TMP/cab.txt" "Referrer-Policy: no-referrer"
contiene "No se guarda en cache" "$TMP/cab.txt" "Cache-Control: no-store"
contiene "Galleta HttpOnly + SameSite=Lax" "$TMP/cab.txt" "HttpOnly; SameSite=Lax"
no_contiene "Sin https: galleta sin Secure" "$TMP/cab.txt" "secure"
no_contiene "Sin X-Powered-By" "$TMP/cab.txt" "X-Powered-By"
curl -s -D "$TMP/cab2.txt" -o /dev/null -H "X-Forwarded-Proto: https" "$BASE/?r=login"
contiene "Detras de Cloudflare (X-Forwarded-Proto): galleta Secure" "$TMP/cab2.txt" "secure; HttpOnly"
contiene "Nombre __Host- con https" "$TMP/cab2.txt" "__Host-tukan_central"
contiene "HSTS con https" "$TMP/cab2.txt" "Strict-Transport-Security"
curl -s -D "$TMP/cab3.txt" -o /dev/null -H 'CF-Visitor: {"scheme":"https"}' "$BASE/?r=login"
contiene "CF-Visitor https: galleta Secure" "$TMP/cab3.txt" "secure; HttpOnly"
check "Sin sesion: Inicio manda a login" "303 /?r=login" "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/?r=inicio" | sed "s#$BASE##")"
check "Ruta desconocida no rompe" "303" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/?r=../../etc/passwd")"
check "datos/ no es publico" "404" "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/../datos/central.sqlite")"

# =====================================================================
titulo "2. Crear el dueno (bin/crear_admin.php) y primer ingreso con 2FA obligatorio"
SALIDA=$(TUKAN_ADMIN_NOMBRE="Dimitry Fierro" TUKAN_ADMIN_EMAIL="dimitryfierro@gmail.com" TUKAN_ADMIN_CLAVE="claveSegura2026" php bin/crear_admin.php)
check "crear_admin: mensaje" "1" "$(echo "$SALIDA" | grep -c 'usuario creado para dimitryfierro@gmail.com')"
check "crear_admin: mensajes sin tildes" "0" "$(echo "$SALIDA" | grep -c '[áéíóúñ]')"
get "r=login" > /dev/null
contiene "Pagina de ingreso" "$TMP/pag.html" "Panel Tukán Central"
COOKIE_ANTES=$(grep tukan_central "$JAR" | awk '{print $7}')
R=$(post login --data-urlencode "email=DimitryFierro@gmail.com" --data-urlencode "clave=claveSegura2026")
check "Contrasena buena -> activar 2FA (primera vez)" "303 /?r=activar_2fa" "$(echo "$R" | sed "s#$BASE##")"
COOKIE_DESPUES=$(grep tukan_central "$JAR" | awk '{print $7}')
[ "$COOKIE_ANTES" != "$COOKIE_DESPUES" ] && check "Id de sesion nuevo al poner la contrasena" 1 1 || check "Id de sesion nuevo al poner la contrasena" distinto igual
check "Sin el 2FA no entra a Inicio" "303 /?r=login" "$(get 'r=inicio' > /dev/null; curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$BASE/?r=inicio" | sed "s#$BASE##")"
check "Pagina de activacion" "200" "$(get 'r=activar_2fa')"
contiene "Muestra el QR (SVG)" "$TMP/pag.html" '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0'
contiene "Muestra la clave manual" "$TMP/pag.html" 'data-clave'
SECRETO_D=$(grep -o 'data-clave>[^<]*' "$TMP/pag.html" | sed 's/data-clave>//;s/ //g')
check "Clave de 32 letras base32" "32" "${#SECRETO_D}"
R=$(post activar_2fa --data-urlencode "codigo=000000")
check "Codigo malo al activar -> no activa" "401" "${R%% *}"
COOKIE_ANTES=$(grep tukan_central "$JAR" | awk '{print $7}')
R=$(post activar_2fa --data-urlencode "codigo=$(codigo "$SECRETO_D" "$TMP/paso_d")")
check "Codigo bueno -> codigos de respaldo" "303 /?r=codigos" "$(echo "$R" | sed "s#$BASE##")"
COOKIE_DESPUES=$(grep tukan_central "$JAR" | awk '{print $7}')
[ "$COOKIE_ANTES" != "$COOKIE_DESPUES" ] && check "Id de sesion nuevo al completar el ingreso" 1 1 || check "Id de sesion nuevo al completar el ingreso" distinto igual
get "r=codigos" > /dev/null
RESPALDO=$(grep -o '<li>[a-z0-9]\{4\}-[a-z0-9]\{4\}</li>' "$TMP/pag.html" | sed 's/<[^>]*>//g')
check "8 codigos de respaldo" "8" "$(echo "$RESPALDO" | grep -c .)"
R=$(post codigos)
get "r=codigos" > /dev/null
check "Los codigos se muestran una sola vez" "0" "$(grep -c '<li>[a-z0-9]\{4\}-' "$TMP/pag.html")"
check "Ya entra a Inicio" "200" "$(get 'r=inicio')"
contiene "Saludo" "$TMP/pag.html" "Dimitry"
check "Auditoria: 2FA activado" "1" "$(audit_n 2fa_activado)"
check "Auditoria: ingreso ok con IP de Cloudflare" "198.51.100.10" "$(sql "SELECT ip FROM auditoria WHERE accion='login_ok' ORDER BY id DESC LIMIT 1")"
check "2FA guardado cifrado (no en claro)" "0" "$(sql "SELECT count(*) FROM usuarios WHERE totp_cif LIKE '%$SECRETO_D%'")"

# =====================================================================
titulo "3. Agregar programas y comando para el servidor (se muestra UNA vez)"
get "r=programa_nuevo" > /dev/null
contiene "Formulario con los tipos" "$TMP/pag.html" 'value="tuguarderia"'
R=$(post programa_nuevo --data-urlencode "producto=tuevento" --data-urlencode "id=tuevento" --data-urlencode "nombre=TuEvento" --data-urlencode "url=http://127.0.0.1:8262")
check "Agregar TuEvento -> pagina del comando" "303 /?r=programa_comando&id=tuevento" "$(echo "$R" | sed "s#$BASE##")"
get "r=programa_comando&id=tuevento" > /dev/null
CMD_TE=$(grep -o 'data-texto>[^<]*' "$TMP/pag.html" | sed 's/data-texto>//')
SEC_TE=$(echo "$CMD_TE" | awk '{print $5}')
check "Comando exacto de TuEvento" "sudo bash /var/www/tuevento/deploy/conectar_central.sh tuevento $SEC_TE $BASE" "$CMD_TE"
check "Secreto de 64 hex" "64" "$(echo -n "$SEC_TE" | grep -cE '^[0-9a-f]{64}$' | sed 's/1/64/')"
check "Comando sin tildes ni n" "0" "$(echo "$CMD_TE" | LC_ALL=C grep -c '[^ -~]')"
check "Recargar: el comando ya no aparece" "303" "$(get 'r=programa_comando&id=tuevento')"
check "Secreto cifrado en la base" "0" "$(sql "SELECT count(*) FROM instancias WHERE secreto_cif LIKE '%$SEC_TE%'")"
check "Auditoria: programa agregado" "1" "$(audit_n instancia_agregada)"
get "r=programa_nuevo" > /dev/null
R=$(post programa_nuevo --data-urlencode "producto=tuevento" --data-urlencode "id=Mal ID" --data-urlencode "url=http://127.0.0.1:8262")
check "ID invalido -> error en el formulario" "200" "${R%% *}"
contiene "Mensaje del ID" "$TMP/post.html" "El ID solo puede tener"
get "r=programa_nuevo" > /dev/null
R=$(post programa_nuevo --data-urlencode "producto=tusalon" --data-urlencode "id=tusalon" --data-urlencode "url=http://tusalon.tukanec.com")
contiene "http:// (sin candado) no se acepta" "$TMP/post.html" "https://"

agregar() { # agregar TIPO ID NOMBRE URL [CARPETA] -> deja CMD y SEC
  get "r=programa_nuevo" > /dev/null
  post programa_nuevo --data-urlencode "producto=$1" --data-urlencode "id=$2" --data-urlencode "nombre=$3" --data-urlencode "url=$4" --data-urlencode "carpeta=${5:-}" > /dev/null
  get "r=programa_comando&id=$2" > /dev/null
  CMD=$(grep -o 'data-texto>[^<]*' "$TMP/pag.html" | sed 's/data-texto>//')
}
agregar tusalon tusalon "TuSalón" "http://127.0.0.1:8263"
check "Comando exacto de TuSalon" "sudo bash /var/www/tusalon/deploy/conectar_central.sh tusalon" "$(echo "$CMD" | awk '{print $1,$2,$3,$4}')"
SEC_TS=$(echo "$CMD" | awk '{print $5}')
agregar tuguarderia dogssy "TuGuardería Dogssy" "http://127.0.0.1:8264" ""
check "Comando exacto de TuGuarderia (CARPETA ID SECRETO URL)" "sudo bash /home/qf/github/tuguarderia/conectar_central.sh dogssy dogssy" "$(echo "$CMD" | awk '{print $1,$2,$3,$4,$5}')"
SEC_DG=$(echo "$CMD" | awk '{print $6}')
check "TuGuarderia: URL del panel al final" "$BASE" "$(echo "$CMD" | awk '{print $7}')"
agregar tuvocalia tuvocalia "TuVocalía" "http://127.0.0.1:8265"
check "Comando exacto de TuVocalia" "sudo bash /var/www/ligas/vps/conectar_central.sh tuvocalia" "$(echo "$CMD" | awk '{print $1,$2,$3,$4}')"
SEC_TV=$(echo "$CMD" | awk '{print $5}')
agregar tuacademia tuacademia "TuAcademia" "http://127.0.0.1:8266"
check "Comando exacto de TuAcademia" "sudo bash /var/www/tuacademia/vps/conectar_central.sh tuacademia" "$(echo "$CMD" | awk '{print $1,$2,$3,$4}')"
curl -s -D "$TMP/cab4.txt" -o /dev/null -b "$JAR" "$BASE/?r=inicio"
contiene "CSP form-action permite ir a los programas" "$TMP/cab4.txt" "form-action 'self' http://127.0.0.1:8262"
curl -s -D "$TMP/cab5.txt" -o /dev/null "$BASE/?r=login"
no_contiene "Sin sesion, la CSP no revela las direcciones de los programas" "$TMP/cab5.txt" "127.0.0.1:8262"

# =====================================================================
titulo "4. Probar conexion: cada problema explicado en palabras simples"
probar tuevento
contiene "Programa apagado -> sin conexion" "$TMP/pag.html" "Sin conexión: el servidor del programa no contesta"
mock tuevento 8262 "" "" tuevento MOCK_V1=400
probar tuevento
contiene "Responde sin conector (404, v1) -> programa sin conectar" "$TMP/pag.html" "Programa sin conectar"
mock tuevento 8262 "" "" tuevento
probar tuevento
contiene "Conector v2 sin configurar (404 + codigo sin_configurar)" "$TMP/pag.html" "apagado o sin configurar"
mock tuevento 8262 tuevento "$SEC_TE" tuevento
probar tuevento
contiene "Con el secreto -> conexion correcta" "$TMP/pag.html" "Conexión correcta: TuEvento versión 75df6b3"
contiene "Probar conexion: habla el protocolo v2" "$TMP/pag.html" "Habla el protocolo v2"
contiene "Estado en linea en la ficha" "$TMP/pag.html" 'estado on'
check "Auditoria: probar conexion" "1" "$(sql "SELECT count(*) FROM auditoria WHERE accion='probar' AND detalle LIKE 'OK%'")"
mock tusalon 8263 tusalon "$(printf 'f%.0s' $(seq 1 64))" tusalon MOCK_V1=400
probar tusalon
contiene "Secreto equivocado -> secreto incorrecto" "$TMP/pag.html" "Secreto incorrecto"
mock tusalon 8263 otro-id "$SEC_TS" tusalon MOCK_V1=400
probar tusalon
contiene "ID distinto en el programa -> el ID no coincide" "$TMP/pag.html" "El ID no coincide"
mock tusalon 8263 tusalon "$SEC_TS" tusalon MOCK_V1=400
probar tusalon
contiene "TuSalon conectado" "$TMP/pag.html" "Conexión correcta"
contiene "TuSalon: programa v1 (solo cifras y Entrar)" "$TMP/pag.html" "Usa el protocolo v1"
mock tuvocalia 8265 tuvocalia "$SEC_TV" tuvocalia MOCK_DESFASE=300
probar tuvocalia
contiene "Reloj del programa corrido 5 min -> reloj desfasado (codigo vencido)" "$TMP/pag.html" "Reloj desfasado"
mock tuvocalia 8265 tuvocalia "$SEC_TV" tuvocalia MOCK_ROTO=1
probar tuvocalia
contiene "JSON danado -> formato del protocolo" "$TMP/pag.html" "no tienen el formato del Protocolo"
mock tuvocalia 8265 tuvocalia "$SEC_TV" tuvocalia MOCK_LIMITE=1
curl -s -o /dev/null "http://127.0.0.1:8265/tukan_central.php?accion=estado&t=x"
probar tuvocalia
contiene "Limite de pedidos (429)" "$TMP/pag.html" "Demasiados pedidos"
mock tuvocalia 8265 tuvocalia "$SEC_TV" tuvocalia
mock dogssy 8264 dogssy "$SEC_DG" tuguarderia MOCK_CLIENTE=Dogssy
probar dogssy
contiene "TuGuarderia conectada" "$TMP/pag.html" "Conexión correcta"

# =====================================================================
titulo "5. Inicio: cifras de todos los programas"
get "r=inicio" > /dev/null
R=$(post refrescar)
check "Actualizar -> vuelve a Inicio" "303 /?r=inicio" "$(echo "$R" | sed "s#$BASE##")"
get "r=inicio" > /dev/null
contiene "Aviso: cifras actualizadas" "$TMP/pag.html" "Cifras actualizadas"
contiene "Nota: un programa no respondio (TuAcademia sin mock)" "$TMP/pag.html" "1 programa no respondió"
# TuEvento y TuVocalía (v2): 9 clientes cada uno (2 en prueba, 3 activos, 1 por vencer, 2 vencidos, 1 suspendido) · TuSalón (v1): 23
check "Total de negocios 9+23+9 = 41" "41" "$(grep -o 'data-total="negocios">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"
check "En prueba 2+7+2 = 11" "11" "$(grep -o 'data-total="prueba">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"
check "Activos 3+14+3 = 20" "20" "$(grep -o 'data-total="activos">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"
check "Vencidos 2+1+2 = 5" "5" "$(grep -o 'data-total="vencidos">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"
check "Nuevos 30 dias 3+5+3 = 11" "11" "$(grep -o 'data-total="nuevos_30d">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"
ING=$(sql "SELECT printf('%.2f', sum(json_extract(datos, '$.ingresos_mes'))) FROM estados WHERE en_linea = 1")
check "Ingresos del mes = suma de lo que informa cada programa ($ING)" "$(php -r 'echo "$".number_format((float)$argv[1],2,",",".");' "$ING")" "$(grep -o 'data-total="ingresos_mes">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"
contiene "Para atender hoy: el comprobante de TuVocalia" "$TMP/pag.html" "Liga Barrial Chillogallo"
contiene "Para atender hoy: boton principal (Aprobar y renovar)" "$TMP/pag.html" "Aprobar y renovar"
contiene "Programa v1: sus pruebas siguen saliendo (TuSalon)" "$TMP/pag.html" "Barbería El Bigote"
contiene "Programa v1: pago vencido" "$TMP/pag.html" "Peluquería Glamour"
contiene "Tarjeta v1: etiqueta Solo cifras" "$TMP/pag.html" "Solo cifras (actualiza el programa"
contiene "Tarjeta v2: solicitudes y vencimientos" "$TMP/pag.html" 'vencen esta semana</a>'
contiene "Boton Entrar a <programa> en pendientes" "$TMP/pag.html" "Entrar a TuSalón</button>"
contiene "Tarjeta TuGuarderia: cliente" "$TMP/pag.html" "Cliente: <strong>Dogssy</strong>"
contiene "Tarjeta TuGuarderia: extras" "$TMP/pag.html" "Mascotas activas"
contiene "Version en la tarjeta" "$TMP/pag.html" "v75df6b3"
contiene "Tiempo de respuesta" "$TMP/pag.html" " ms</span>"
contiene "Historial de 7 dias" "$TMP/pag.html" 'class="barras"'
contiene "TuAcademia sin conexion" "$TMP/pag.html" "estado off"
contiene "Cifras recientes: no pide actualizar solo" "$TMP/pag.html" 'data-viejo="0"'
sql "UPDATE estados SET consultado = consultado - 300" > /dev/null
get "r=inicio" > /dev/null
contiene "Cifras de mas de 2 min: app.js las pide de nuevo" "$TMP/pag.html" 'data-viejo="1"'
T=$(get "r=inicio" > /dev/null; curl -s -b "$JAR" -H "X-CSRF: $(csrf)" -H "Origin: $BASE" -X POST -o "$TMP/json.txt" -w '%{http_code}' "$BASE/?r=refrescar")
check "Actualizar desde app.js (JSON + cabecera X-CSRF)" "200" "$T"
contiene "Respuesta JSON" "$TMP/json.txt" '"hecho":true'

# =====================================================================
titulo "6. Entrar a un programa (SSO de un solo uso)"
get "r=inicio" > /dev/null
R=$(post entrar --data-urlencode "id=tuevento")
check "Entrar -> 302 al programa" "302" "${R%% *}"
SSO=$(echo "$R" | cut -d' ' -f2-)
check "Va a tukan_central.php?accion=sso&t=" "http://127.0.0.1:8262/tukan_central.php?accion=sso&t=" "${SSO%%t=*}t="
TOKEN=${SSO##*t=}
PAYLOAD=$(php -r 'echo base64_decode(strtr(explode(".", $argv[1])[0], "-_", "+/"));' "$TOKEN")
check "Token: email del admin" "dimitryfierro@gmail.com" "$(echo "$PAYLOAD" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["email"];')"
check "Token: nombre" "Dimitry Fierro" "$(echo "$PAYLOAD" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["nombre"];')"
check "Token: exp - iat = 60" "60" "$(echo "$PAYLOAD" | php -r '$p=json_decode(stream_get_contents(STDIN), true); echo $p["exp"]-$p["iat"];')"
check "Token: orden de claves del protocolo" "v,tipo,inst,email,nombre,iat,exp,nonce" "$(echo "$PAYLOAD" | php -r 'echo implode(",", array_keys(json_decode(stream_get_contents(STDIN), true)));')"
R2=$(curl -s -L -c "$TMP/jar_prog" -b "$TMP/jar_prog" -o "$TMP/prog.html" -w '%{http_code} %{url_effective}' "$SSO")
check "El programa acepta el token y abre su panel" "200 http://127.0.0.1:8262/admin" "$R2"
contiene "Entro con el email del admin" "$TMP/prog.html" "dimitryfierro@gmail.com / Dimitry Fierro"
check "El programa registro el ingreso" "1" "$(grep -c 'dimitryfierro@gmail.com|Dimitry Fierro' "$TMP/mock_tuevento/sso.log")"
check "El mismo enlace otra vez -> rechazado (401)" "401" "$(curl -s -o "$TMP/prog2.html" -w '%{http_code}' "$SSO")"
contiene "Motivo: nonce ya usado" "$TMP/mock_tuevento/rechazos.log" "sso|nonce ya usado"
contiene "Pagina de error con enlace al panel" "$TMP/prog2.html" "Volver al Panel Tukán"
check "Auditoria: entro a TuEvento" "1" "$(sql "SELECT count(*) FROM auditoria WHERE accion='entrar' AND instancia_id='tuevento'")"
R=$(post entrar --data-urlencode "id=tuevento" --data-urlencode "nueva=1")
check "Entrar en pestana nueva tambien redirige" "302" "${R%% *}"
check "Auditoria: pestana nueva" "1" "$(sql "SELECT count(*) FROM auditoria WHERE accion='entrar' AND detalle LIKE '%pestaña nueva%'")"
FALSO=$(php -r '$b=fn($s)=>rtrim(strtr(base64_encode($s),"+/","-_"),"="); $p=$b(json_encode(["v"=>1,"tipo"=>"sso","inst"=>"tuevento","email"=>"x@x.ec","nombre"=>"X","iat"=>time(),"exp"=>time()+60,"nonce"=>bin2hex(random_bytes(16))])); echo $p.".".$b(hash_hmac("sha256",$p,str_repeat("0",64),true));')
check "Token firmado con otro secreto -> 401" "401" "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8262/tukan_central.php?accion=sso&t=$FALSO")"
get "r=inicio" > /dev/null
R=$(post entrar --data-urlencode "id=no-existe")
check "Programa que no existe -> vuelve a Inicio" "303 /?r=inicio" "$(echo "$R" | sed "s#$BASE##")"
check "Entrar por GET no hace nada" "303" "$(get 'r=entrar&id=tuevento')"

# =====================================================================
titulo "7. Programa caido o lento: Inicio sigue rapido con los ultimos datos"
parar dogssy
get "r=inicio" > /dev/null; post refrescar > /dev/null
get "r=inicio" > /dev/null
contiene "Dogssy sin conexion" "$TMP/pag.html" "Sin conexión: el servidor del programa no contesta"
contiene "Muestra los ultimos datos conocidos" "$TMP/pag.html" "Cliente: <strong>Dogssy</strong>"
sql "UPDATE estados SET datos_momento = datos_momento - 1500 WHERE instancia_id='dogssy'" > /dev/null
get "r=inicio" > /dev/null
contiene "Dice hace cuanto son esos datos" "$TMP/pag.html" "Últimos datos conocidos: hace 25 min"
check "Los totales conservan el ultimo dato" "41" "$(grep -o 'data-total="negocios">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"
mock tuvocalia 8265 tuvocalia "$SEC_TV" tuvocalia MOCK_LENTO=8
get "r=inicio" > /dev/null
INI=$(date +%s%N); post refrescar > /dev/null; FIN=$(date +%s%N)
MS=$(( (FIN - INI) / 1000000 ))
[ "$MS" -ge 4500 ] && [ "$MS" -lt 7500 ] && check "Consulta corta a los 5 s (tardo ${MS} ms, en paralelo)" 1 1 || check "Consulta corta a los 5 s" "4500-7500 ms" "$MS ms"
get "r=inicio" > /dev/null
contiene "Programa lento -> no respondio a tiempo" "$TMP/pag.html" "no respondió a tiempo"
T=$(curl -s -b "$JAR" -o /dev/null -w '%{time_total}' "$BASE/?r=inicio")
check "Inicio se dibuja en menos de 1 s aunque haya caidos ($T s)" "1" "$(php -r "echo $T < 1 ? 1 : 0;")"
mock tuvocalia 8265 tuvocalia "$SEC_TV" tuvocalia

# =====================================================================
titulo "8. Revision automatica (bin/revisar.php) y avisos por Telegram"
SALIDA=$(php bin/revisar.php)
check "revisar.php resume sin tildes" "0" "$(echo "$SALIDA" | grep -c '[áéíóúñ]')"
echo "    ($SALIDA)"
contiene "revisar.php: cuenta los caidos" <(echo "$SALIDA") "sin conexion (dogssy: sin_conexion; tuacademia: sin_conexion)"
contiene "Telegram: aviso de caida de Dogssy" "$TMP/mock_telegram/telegram.log" "TuGuardería Dogssy está sin conexión"
N1=$(grep -c "Dogssy está sin conexión" "$TMP/mock_telegram/telegram.log")
php bin/revisar.php > /dev/null
check "No repite el aviso de caida" "$N1" "$(grep -c "Dogssy está sin conexión" "$TMP/mock_telegram/telegram.log")"
mock dogssy 8264 dogssy "$SEC_DG" tuguarderia
echo 10 > "$TMP/mock_tuevento/total"
php bin/revisar.php > /dev/null
contiene "Telegram: Dogssy volvio" "$TMP/mock_telegram/telegram.log" "TuGuardería Dogssy volvió a estar en línea"
contiene "Telegram: negocio nuevo en TuEvento" "$TMP/mock_telegram/telegram.log" "Un negocio nuevo en TuEvento. Ahora son 10"
check "Historial de disponibilidad guardado" "1" "$(sql "SELECT count(*) > 0 FROM historial WHERE instancia_id='tuevento'")"
check "El programa recibe tokens de estado (no de sso)" "0" "$(grep -c 'estado|tipo incorrecto' "$TMP/mock_tuevento/rechazos.log" 2>/dev/null || true)"
get "r=ajustes" > /dev/null
post ajustes --data-urlencode "accion=guardar" --data-urlencode "avisar_caidas=1" > /dev/null
check "Auditoria: ajustes cambiados (avisos de negocios apagados)" "1" "$(sql "SELECT count(*) FROM auditoria WHERE accion='ajustes' AND detalle LIKE '%avisar_negocios=0%'")"
echo 11 > "$TMP/mock_tuevento/total"
php bin/revisar.php > /dev/null
no_contiene "Con el aviso apagado no avisa negocios" "$TMP/mock_telegram/telegram.log" "Ahora son 11"
rm -f "$TMP/mock_tuevento/total"

# =====================================================================
titulo "9. Cambiar secreto"
get "r=programa&id=tusalon" > /dev/null
R=$(post rotar --data-urlencode "id=tusalon")
check "Cambiar secreto -> comando nuevo" "303 /?r=programa_comando&id=tusalon" "$(echo "$R" | sed "s#$BASE##")"
get "r=programa_comando&id=tusalon" > /dev/null
SEC_TS2=$(grep -o 'data-texto>[^<]*' "$TMP/pag.html" | sed 's/data-texto>//' | awk '{print $5}')
[ -n "$SEC_TS2" ] && [ "$SEC_TS2" != "$SEC_TS" ] && check "Secreto distinto" 1 1 || check "Secreto distinto" nuevo "$SEC_TS2"
probar tusalon
contiene "El secreto viejo ya no sirve" "$TMP/pag.html" "Secreto incorrecto"
mock tusalon 8263 tusalon "$SEC_TS2" tusalon MOCK_V1=400
probar tusalon
contiene "Con el comando nuevo vuelve a conectar" "$TMP/pag.html" "Conexión correcta"
check "Auditoria: secreto cambiado" "1" "$(audit_n secreto_cambiado)"

# =====================================================================
titulo "10. Editar y quitar programas"
get "r=programa_editar&id=tuacademia" > /dev/null
R=$(post "programa_editar&id=tuacademia" --data-urlencode "producto=tuacademia" --data-urlencode "nombre=TuAcademia Norte" --data-urlencode "url=http://127.0.0.1:8266" --data-urlencode "notas=VPS")
check "Editar (sin marcar Activo = pausa)" "303 /?r=programa&id=tuacademia" "$(echo "$R" | sed "s#$BASE##")"
check "Quedo pausado y con nombre nuevo" "0|TuAcademia Norte" "$(sql "SELECT activo, nombre FROM instancias WHERE id='tuacademia'")"
contiene "Auditoria: que cambio" <(sql "SELECT detalle FROM auditoria WHERE accion='instancia_editada'") "nombre, notas, activo"
get "r=inicio" > /dev/null
contiene "Pausado en Inicio" "$TMP/pag.html" "estado pausa"
get "r=programa&id=tuacademia" > /dev/null
R=$(post eliminar --data-urlencode "id=tuacademia" --data-urlencode "confirmar=otro")
check "Quitar sin escribir el ID: no se quita" "1" "$(sql "SELECT count(*) FROM instancias WHERE id='tuacademia'")"
get "r=programa&id=tuacademia" > /dev/null
R=$(post eliminar --data-urlencode "id=tuacademia" --data-urlencode "confirmar=tuacademia")
check "Quitar escribiendo el ID" "0" "$(sql "SELECT count(*) FROM instancias WHERE id='tuacademia'")"
check "Auditoria: programa eliminado" "1" "$(audit_n instancia_eliminada)"

# =====================================================================
titulo "11. CSRF: formularios sin token o de otro sitio se rechazan"
check "POST sin token -> 400" "400" "$(curl -s -b "$JAR" -o "$TMP/x.html" -w '%{http_code}' --data 'id=tuevento' "$BASE/?r=entrar")"
contiene "Mensaje: la pagina caduco" "$TMP/x.html" "La página caducó"
check "POST con token falso -> 400" "400" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' --data 'id=tuevento&csrf=abc123' "$BASE/?r=entrar")"
get "r=inicio" > /dev/null; T=$(csrf)
check "Token bueno pero desde otro sitio (Origin) -> 400" "400" "$(curl -s -b "$JAR" -H 'Origin: https://malo.example' -o /dev/null -w '%{http_code}' --data "id=tuevento&csrf=$T" "$BASE/?r=entrar")"
check "Token bueno desde este sitio -> 302" "302" "$(curl -s -b "$JAR" -H "Origin: $BASE" -o /dev/null -w '%{http_code}' --data "id=tuevento&csrf=$T" "$BASE/?r=entrar")"
check "Cambiar secreto sin token -> no cambia" "400" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' --data 'id=tuevento' "$BASE/?r=rotar")"
check "Auditoria: CSRF rechazados" "4" "$(audit_n csrf_rechazado)"

# =====================================================================
titulo "12. Bloqueo despues de 5 intentos (por correo y por IP)"
TUKAN_ADMIN_NOMBRE="Victima" TUKAN_ADMIN_EMAIL="victima@tukan.ec" TUKAN_ADMIN_CLAVE="claveVictima2026" php bin/crear_admin.php > /dev/null
JAR_D="$JAR"; JAR="$TMP/jar_atacante"; IP="203.0.113.9"
get "r=login" > /dev/null
for i in 1 2 3 4; do R=$(post login --data-urlencode "email=victima@tukan.ec" --data-urlencode "clave=mala$i"); done
check "4.o intento: todavia 401" "401" "${R%% *}"
R=$(post login --data-urlencode "email=victima@tukan.ec" --data-urlencode "clave=mala5")
check "5.o intento: bloqueado (429)" "429" "${R%% *}"
contiene "Mensaje de espera" "$TMP/post.html" "Demasiados intentos equivocados"
R=$(post login --data-urlencode "email=victima@tukan.ec" --data-urlencode "clave=claveVictima2026")
check "Ni con la contrasena buena (15 min)" "429" "${R%% *}"
IP="203.0.113.77"; rm -f "$JAR"; get "r=login" > /dev/null
R=$(post login --data-urlencode "email=victima@tukan.ec" --data-urlencode "clave=claveVictima2026")
check "Bloqueo por correo: tambien desde otra IP" "429" "${R%% *}"
IP="203.0.113.9"; rm -f "$JAR"; get "r=login" > /dev/null
R=$(post login --data-urlencode "email=dimitryfierro@gmail.com" --data-urlencode "clave=claveSegura2026")
check "Bloqueo por IP: tambien con otro correo" "429" "${R%% *}"
check "Auditoria: intentos fallidos" "4" "$(audit_n login_fallido)"
check "Auditoria: bloqueos" "1" "$(sql "SELECT count(*) >= 3 FROM auditoria WHERE accion='login_bloqueado'")"
check "Auditoria: IP real del atacante (CF-Connecting-IP)" "203.0.113.9" "$(sql "SELECT ip FROM auditoria WHERE accion='login_fallido' ORDER BY id LIMIT 1")"
# 2FA equivocado tambien cuenta
IP="192.0.2.50"; rm -f "$JAR"; get "r=login" > /dev/null
post login --data-urlencode "email=dimitryfierro@gmail.com" --data-urlencode "clave=claveSegura2026" > /dev/null
get "r=verificar" > /dev/null
for i in 1 2 3 4; do R=$(post verificar --data-urlencode "codigo=00000$i"); done
check "Codigo de 2 pasos malo: 401" "401" "${R%% *}"
R=$(post verificar --data-urlencode "codigo=000009")
check "5 codigos malos: bloqueado" "429" "${R%% *}"
check "Auditoria: 2FA fallido" "1" "$(sql "SELECT count(*) >= 5 FROM auditoria WHERE accion='2fa_fallido'")"
sql "UPDATE intentos_fallidos SET momento = momento - 901" > /dev/null   # pasan 15 minutos
IP="203.0.113.9"; rm -f "$JAR"; get "r=login" > /dev/null
R=$(post login --data-urlencode "email=victima@tukan.ec" --data-urlencode "clave=claveVictima2026")
check "A los 15 minutos se desbloquea" "303 /?r=activar_2fa" "$(echo "$R" | sed "s#$BASE##")"
# IP falsa en CF-Connecting-IP desde fuera del tunel: se ignora
check "CF-Connecting-IP solo se cree si viene del tunel (127.0.0.1)" "127.0.0.1" "$(TUKAN_PROXIES_CONFIABLES=10.9.9.9 php -r '$_SERVER["REMOTE_ADDR"]="127.0.0.1"; $_SERVER["HTTP_CF_CONNECTING_IP"]="1.1.1.1"; require "app/bootstrap.php"; echo ip_visitante();')"

# =====================================================================
titulo "13. Codigo de respaldo y quitar 2FA en emergencia"
JAR="$TMP/jar_respaldo"; IP="192.0.2.60"; get "r=login" > /dev/null
post login --data-urlencode "email=dimitryfierro@gmail.com" --data-urlencode "clave=claveSegura2026" > /dev/null
check "Pide el codigo" "200" "$(get 'r=verificar')"
CR=$(echo "$RESPALDO" | head -1)
R=$(post verificar --data-urlencode "codigo=$CR")
check "Entra con un codigo de respaldo" "303 /?r=inicio" "$(echo "$R" | sed "s#$BASE##")"
get "r=inicio" > /dev/null
contiene "Avisa cuantos quedan" "$TMP/pag.html" "Te quedan 7"
check "Auditoria: ingreso con respaldo" "1" "$(audit_n 2fa_respaldo)"
SALIDA=$(php bin/quitar_2fa.php victima@tukan.ec)
check "quitar_2fa.php: mensaje sin tildes" "1" "$(echo "$SALIDA" | grep -c 'se quito la verificacion en 2 pasos de victima@tukan.ec')"
check "quitar_2fa.php: anotado" "1" "$(sql "SELECT count(*) FROM auditoria WHERE accion='2fa_quitado' AND detalle LIKE 'victima%'")"
check "quitar_2fa.php: correo que no existe" "1" "$(php bin/quitar_2fa.php nadie@x.ec > /dev/null 2>&1; echo $?)"
SALIDA=$(TUKAN_ADMIN_NOMBRE="Victima" TUKAN_ADMIN_EMAIL="victima@tukan.ec" TUKAN_ADMIN_CLAVE="claveNueva20261" php bin/crear_admin.php --quitar-2fa)
check "crear_admin: cambia contrasena de uno que ya existe" "1" "$(echo "$SALIDA" | grep -c 'contrasena cambiada para victima@tukan.ec')"

# =====================================================================
titulo "14. Rol soporte: entra a los programas pero no cambia nada"
JAR="$JAR_D"; IP="198.51.100.10"
get "r=usuarios" > /dev/null
R=$(post usuarios --data-urlencode "accion=crear" --data-urlencode "nombre=Socio Soporte" --data-urlencode "email=soporte@tukan.ec" --data-urlencode "rol=soporte" --data-urlencode "codigo=123456")
contiene "Crear usuario pide el codigo de 2 pasos" "$TMP/post.html" "código de 2 pasos no es correcto"
R=$(post usuarios --data-urlencode "accion=crear" --data-urlencode "nombre=Socio Soporte" --data-urlencode "email=soporte@tukan.ec" --data-urlencode "rol=soporte" --data-urlencode "codigo=$(codigo "$SECRETO_D" "$TMP/paso_d")")
get "r=usuarios" > /dev/null
TEMPORAL=$(grep -o 'Contraseña temporal: [^ ]*' "$TMP/pag.html" | head -1 | awk '{print $3}')
check "Usuario creado con contrasena temporal" "14" "${#TEMPORAL}"
check "Auditoria: usuario creado" "1" "$(audit_n usuario_creado)"
JAR="$TMP/jar_soporte"; IP="198.51.100.20"; get "r=login" > /dev/null
R=$(post login --data-urlencode "email=soporte@tukan.ec" --data-urlencode "clave=$TEMPORAL")
check "Soporte: primero activa su 2FA" "303 /?r=activar_2fa" "$(echo "$R" | sed "s#$BASE##")"
get "r=activar_2fa" > /dev/null
SECRETO_S=$(grep -o 'data-clave>[^<]*' "$TMP/pag.html" | sed 's/data-clave>//;s/ //g')
post activar_2fa --data-urlencode "codigo=$(codigo "$SECRETO_S" "$TMP/paso_s")" > /dev/null
get "r=codigos" > /dev/null; R=$(post codigos)
check "Con clave temporal: lo manda a cambiarla" "303 /?r=mi_cuenta" "$(echo "$R" | sed "s#$BASE##")"
check "No puede ir a Inicio sin cambiarla" "303" "$(get 'r=inicio')"
get "r=mi_cuenta" > /dev/null
R=$(post mi_cuenta --data-urlencode "accion=clave" --data-urlencode "actual=$TEMPORAL" --data-urlencode "nueva=claveSoporte2026" --data-urlencode "repetir=claveSoporte2026" --data-urlencode "codigo=$(codigo "$SECRETO_S" "$TMP/paso_s")")
check "Cambia la contrasena (actual + codigo)" "303 /?r=inicio" "$(echo "$R" | sed "s#$BASE##")"
check "Auditoria: contrasena cambiada" "1" "$(audit_n clave_cambiada)"
check "Soporte ve Inicio" "200" "$(get 'r=inicio')"
contiene "Soporte ve las tarjetas" "$TMP/pag.html" "Entrar a TuEvento"
no_contiene "Soporte no ve Agregar programa" "$TMP/pag.html" "programa_nuevo"
no_contiene "Soporte no ve Auditoria en el menu" "$TMP/pag.html" "r=auditoria"
R=$(post entrar --data-urlencode "id=tuevento")
check "Soporte SI puede entrar a un programa" "302" "${R%% *}"
check "Soporte: Agregar programa -> 403" "403" "$(get 'r=programa_nuevo')"
check "Soporte: Editar -> 403" "403" "$(get 'r=programa_editar&id=tuevento')"
check "Soporte: Usuarios -> 403" "403" "$(get 'r=usuarios')"
check "Soporte: Auditoria -> 403" "403" "$(get 'r=auditoria')"
check "Soporte: Ajustes -> 403" "403" "$(get 'r=ajustes')"
get "r=programa&id=tuevento" > /dev/null
no_contiene "Soporte: la ficha no muestra el comando ni el boton de secreto" "$TMP/pag.html" "Cambiar secreto"
R=$(post rotar --data-urlencode "id=tuevento")
check "Soporte: Cambiar secreto -> 403" "403" "${R%% *}"
get "r=programa&id=tuevento" > /dev/null
R=$(post eliminar --data-urlencode "id=tuevento" --data-urlencode "confirmar=tuevento")
check "Soporte: Eliminar -> 403 y sigue ahi" "403|1" "${R%% *}|$(sql "SELECT count(*) FROM instancias WHERE id='tuevento'")"
get "r=programa&id=tuevento" > /dev/null
R=$(post probar --data-urlencode "id=tuevento")
check "Soporte SI puede probar la conexion" "303" "${R%% *}"
check "Auditoria: permisos negados" "1" "$(sql "SELECT count(*) >= 7 FROM auditoria WHERE accion='permiso_negado' AND email='soporte@tukan.ec'")"

# =====================================================================
titulo "15. Mi cuenta, sesiones y salir"
get "r=mi_cuenta" > /dev/null
contiene "Lista de sesiones" "$TMP/pag.html" "esta sesión"
contiene "Ultimos ingresos" "$TMP/pag.html" "Entró al panel"
R=$(post mi_cuenta --data-urlencode "accion=clave" --data-urlencode "actual=mala" --data-urlencode "nueva=otraClave2026x" --data-urlencode "repetir=otraClave2026x" --data-urlencode "codigo=$(codigo "$SECRETO_S" "$TMP/paso_s")")
contiene "Contrasena actual equivocada -> no cambia" "$TMP/post.html" "La contraseña actual no es correcta"
post salir > /dev/null
check "Despues de salir no entra" "303" "$(get 'r=inicio')"
check "Auditoria: salio" "1" "$(audit_n salir)"
check "Sesion cerrada en la base" "1" "$(sql "SELECT count(*) > 0 FROM sesiones WHERE motivo='salio'")"
JAR="$JAR_D"; IP="198.51.100.10"
get "r=mi_cuenta" > /dev/null
R=$(post mi_cuenta --data-urlencode "accion=cerrar_otras")
check "Cerrar las demas sesiones" "1" "$(audit_n sesiones_cerradas)"
check "Mi sesion sigue abierta" "200" "$(get 'r=inicio')"
check "La sesion de respaldo quedo cerrada" "303" "$(curl -s -b "$TMP/jar_respaldo" -o /dev/null -w '%{http_code}' "$BASE/?r=inicio")"
sql "UPDATE sesiones SET ultima = ultima - 1900 WHERE cerrada IS NULL" > /dev/null
check "31 minutos sin usar -> se cierra" "303" "$(get 'r=inicio')"
check "Auditoria: sesion vencida" "1" "$(sql "SELECT count(*) >= 1 FROM auditoria WHERE accion='sesion_vencida'")"

# =====================================================================
titulo "16. Auditoria con filtros"
JAR="$TMP/jar_audit"; IP="198.51.100.11"; get "r=login" > /dev/null
post login --data-urlencode "email=dimitryfierro@gmail.com" --data-urlencode "clave=claveSegura2026" > /dev/null
get "r=verificar" > /dev/null
post verificar --data-urlencode "codigo=$(codigo "$SECRETO_D" "$TMP/paso_d")" > /dev/null
check "Auditoria abre" "200" "$(get 'r=auditoria')"
contiene "Tabla" "$TMP/pag.html" 'id="tabla-auditoria"'
get "r=auditoria&accion=entrar" > /dev/null
check "Filtro por accion: solo entradas" "0" "$(grep -o 'data-accion="[^"]*"' "$TMP/pag.html" | grep -vc 'data-accion="entrar"')"
get "r=auditoria&instancia=tusalon" > /dev/null
contiene "Filtro por programa" "$TMP/pag.html" "Cambió el secreto de un programa"
get "r=auditoria&grupo=fallos" > /dev/null
contiene "Filtro fallos: bloqueos" "$TMP/pag.html" "Intento bloqueado"
get "r=auditoria&q=203.0.113.9" > /dev/null
contiene "Busqueda por IP" "$TMP/pag.html" "203.0.113.9"
check "Cada 'Entrar' quedo anotado" "1" "$(sql "SELECT count(*) >= 4 FROM auditoria WHERE accion='entrar'")"

# =====================================================================
titulo "17. Paginas sin errores"
for r in inicio programas "programa&id=tuevento" "programa&id=dogssy" programa_nuevo "programa_editar&id=dogssy" auditoria usuarios mi_cuenta ayuda ajustes; do
  check "Abre $r" "200" "$(get "r=$r")"
done
get "r=ayuda" > /dev/null
contiene "Ayuda: comando de TuGuarderia con CARPETA" "$TMP/pag.html" "conectar_central.sh CARPETA ID SECRETO"
contiene "Ayuda: emergencia 2FA" "$TMP/pag.html" "quitar_2fa.php"
check "Hoja de colores por producto" "200" "$(curl -s -o "$TMP/c.css" -w '%{http_code}' "$BASE/?r=colores_css")"
contiene "Color TuEvento" "$TMP/c.css" ".p-tuevento{--c:#8E3B52}"
if grep -qiE "fatal|warning|deprecated|notice" "$TMP/central.log"; then
  FALLAS=$((FALLAS+1)); echo "  FALLA Avisos de PHP en el servidor:"; grep -iE "fatal|warning|deprecated|notice" "$TMP/central.log" | sort | uniq -c | head -20
else OK=$((OK+1)); echo "  OK    Sin avisos de PHP en el servidor"; fi

# =====================================================================
# PROTOCOLO v2 (docs/PROTOCOLO_V2.md): bandeja, clientes, acciones, demos, finanzas, permisos, cron
# =====================================================================
# post_form PAGINA SELECTOR [campo=valor ...] -> manda el formulario como el navegador (deja la respuesta en $TMP/post.html)
post_form() {
  local html=$1 sel=$2; shift 2
  local args=() accion="" kv
  while IFS= read -r -d '' kv; do
    if [ -z "$accion" ] && [[ "$kv" == ACTION=* ]]; then accion=${kv#ACTION=}; continue; fi
    args+=(--data-urlencode "$kv")
  done < <(php tests/form.php "$html" "$sel" "$@")
  curl -s -b "$JAR" -c "$JAR" -H "CF-Connecting-IP: $IP" -o "$TMP/post.html" -w "%{http_code} %{redirect_url}" "${args[@]}" "$BASE$accion"
}
mockdb() { php -r '$d=json_decode(file_get_contents($argv[1]),true); echo eval("return ".$argv[2].";");' "$TMP/mock_$1/db.json" "$2"; }
tg() { cat "$TMP/mock_telegram/telegram.log" 2>/dev/null; }
pagos_de() { mockdb tuvocalia "count(array_filter(\$d['clientes']['$1']['pagos'], fn(\$p) => !\$p['anulado']))"; }
# abrir_accion INST CLIENTE ACCION -> abre (como sin JavaScript) el formulario de ese botón de la ficha; deja $TMP/pag.html
abrir_accion() {
  get "r=cliente&i=$1&id=$2" > /dev/null
  local h; h=$(grep -o "href=\"/?r=accion&amp;i=$1&amp;a=$3&amp;n=[0-9]*[^\"]*\"" "$TMP/pag.html" | head -1 | sed 's/^href="\/?//;s/"$//;s/&amp;/\&/g')
  get "$h" > /dev/null
}

titulo "18. Capacidades: v2, v1 (400 y 404) y una sola empresa"
agregar tuacademia tuacademia "TuAcademia" "http://127.0.0.1:8266"
mock tuacademia 8266 tuacademia "$(echo "$CMD" | awk '{print $5}')" tuacademia MOCK_V1=404
probar tuacademia
contiene "Programa v1 que contesta 404 -> protocolo v1" "$TMP/pag.html" "Usa el protocolo v1"
check "Capacidades guardadas: TuVocalía v2, TuSalón v1, TuAcademia v1, Dogssy v2" "dogssy|2 tuacademia|1 tuevento|2 tusalon|1 tuvocalia|2" "$(sql "SELECT instancia_id || '|' || protocolo FROM capacidades ORDER BY instancia_id" | tr '\n' ' ' | sed 's/ $//')"
get "r=programa&id=tuvocalia" > /dev/null
contiene "Ficha: Qué puede hacer desde el panel" "$TMP/pag.html" "Qué puede hacer desde el panel"
check "Ficha TuVocalía: todo con ✔ (12, con pausar y borrar clientes)" "12" "$(grep -c '<li class="si">' "$TMP/pag.html")"
get "r=programa&id=tuevento" > /dev/null
contiene "Ficha TuEvento: sin aprobar comprobantes (no lo declara)" "$TMP/pag.html" '<li class="no"><svg[^>]*>.*</svg> Aprobar comprobantes'
contiene "Ficha TuEvento: actualiza para tenerlo" "$TMP/pag.html" "actualiza TuEvento para tener esto"
get "r=programa&id=tusalon" > /dev/null
contiene "Ficha TuSalón (v1): solo cifras y Entrar" "$TMP/pag.html" "todavía usa el protocolo v1"
php bin/revisar.php > /dev/null
check "Copia de clientes: 9 de TuVocalía, 9 de TuEvento, 1 de Dogssy" "dogssy|1 tuevento|9 tuvocalia|9" "$(sql "SELECT instancia_id || '|' || count(*) FROM clientes GROUP BY instancia_id ORDER BY 1" | tr '\n' ' ' | sed 's/ $//')"
check "Copia de clientes = contadores del programa (estado.negocios)" "9" "$(sql "SELECT json_extract(datos, '$.total') FROM sincronizacion WHERE instancia_id='tuvocalia' AND que='clientes'")"
check "Contacto de los clientes cifrado (ni un teléfono en claro en la base)" "0" "$(cat "$TUKAN_CENTRAL_DATOS"/central.sqlite* | grep -ac '0991234562')"
check "Correos de los clientes cifrados" "0" "$(cat "$TUKAN_CENTRAL_DATOS"/central.sqlite* | grep -ac 'carlos3@ejemplo.com')"
check "Finanzas: 24 meses de TuVocalía guardados" "24" "$(sql "SELECT count(*) FROM finanzas_mes WHERE instancia_id='tuvocalia'")"
check "TuSalón (v1) no tiene copia de clientes ni solicitudes" "0|0" "$(sql "SELECT (SELECT count(*) FROM clientes WHERE instancia_id='tusalon') || '|' || (SELECT count(*) FROM solicitudes WHERE instancia_id='tusalon')")"
check "Lo viejo no se avisa por Telegram (primera vez)" "0" "$(tg | grep -c 'Pedido de demo')"

titulo "19. Revisión automática: solicitudes nuevas por Telegram (teléfono escondido)"
echo 1 > "$TMP/mock_tuvocalia/agregar_demos"
SALIDA=$(php bin/revisar.php)
contiene "revisar.php: trae las solicitudes porque cambió la huella" <(echo "$SALIDA") "tuvocalia: solicitudes"
check "revisar.php: v2 sin tildes" "0" "$(echo "$SALIDA" | grep -c '[áéíóúñ]')"
contiene "Telegram: pedido de demo nuevo" "$TMP/mock_telegram/telegram.log" "📝 TuVocalía · Pedido de demo: Club Nuevo"
contiene "Telegram: ciudad y contacto" "$TMP/mock_telegram/telegram.log" "(Loja). Marta Ruiz · 098•••1122."
contiene "Telegram: enlace a la solicitud en el panel" "$TMP/mock_telegram/telegram.log" "Abrir: $BASE/?r=solicitud&i=tuvocalia&id=demo_pedida%3A"
no_contiene "Telegram: nunca el teléfono completo" "$TMP/mock_telegram/telegram.log" "0987001122"
no_contiene "Telegram: nunca el correo" "$TMP/mock_telegram/telegram.log" "@club.ec"
N1=$(tg | grep -c 'Pedido de demo: Club Nuevo')
php bin/revisar.php > /dev/null
check "No se repite el aviso" "$N1" "$(tg | grep -c 'Pedido de demo: Club Nuevo')"
get "r=ajustes" > /dev/null
post_form "$TMP/pag.html" "#ajustes-solicitudes" "silencio_desde=00:00" "silencio_hasta=23:59" > /dev/null
echo 1 > "$TMP/mock_tuvocalia/agregar_demos"
php bin/revisar.php > /dev/null
check "Horario de silencio: no avisa" "$N1" "$(tg | grep -c 'Pedido de demo: Club Nuevo')"
get "r=ajustes" > /dev/null
post_form "$TMP/pag.html" "#ajustes-solicitudes" "silencio_desde=" "silencio_hasta=" > /dev/null
check "Auditoria: ajustes de avisos" "1" "$(sql "SELECT count(*) > 0 FROM auditoria WHERE accion='ajustes' AND detalle LIKE '%silencio_desde%'")"
php bin/revisar.php > /dev/null
check "Al terminar el silencio sale la que estaba guardada" "$((N1 + 1))" "$(tg | grep -c 'Pedido de demo: Club Nuevo')"
echo 6 > "$TMP/mock_tuvocalia/agregar_demos"
php bin/revisar.php > /dev/null
contiene "Más de 5 nuevas: un solo mensaje con el resumen" "$TMP/mock_telegram/telegram.log" "📬 6 solicitudes nuevas: 6 × Pedido de demo"
check "...y no 6 mensajes" "$((N1 + 1))" "$(tg | grep -c 'Pedido de demo: Club Nuevo')"
php -r 'require "app/bootstrap.php"; sincronizador()->resumenDiario(strtotime("today 07:35"));'
contiene "Resumen de la mañana (07:30)" "$TMP/mock_telegram/telegram.log" "☀️ Buenos días. Hoy: "
contiene "Resumen: cobrado y gastos del mes" "$TMP/mock_telegram/telegram.log" "Cobrado en"
N2=$(tg | grep -c 'Buenos días')
php -r 'require "app/bootstrap.php"; sincronizador()->resumenDiario(strtotime("today 08:00"));'
check "Resumen: una sola vez al día" "$N2" "$(tg | grep -c 'Buenos días')"

titulo "20. Solicitudes: bandeja única con filtros, detalle y archivos"
check "Abre la bandeja" "200" "$(get 'r=solicitudes')"
TOT=$(sql "SELECT count(*) FROM solicitudes WHERE estado='pendiente'")
check "Pestaña Por atender con número ($TOT)" "$TOT" "$(grep -o 'Por atender <span class="n[^"]*">[0-9]*' "$TMP/pag.html" | sed 's/.*>//')"
contiene "Número rojo en el menú" "$TMP/pag.html" "aria-label=\"$TOT por atender\""
contiene "Tarjeta del comprobante (TuVocalía)" "$TMP/pag.html" "Comprobante de pago por revisar"
contiene "Registro nuevo de TuEvento (otro programa, misma bandeja)" "$TMP/pag.html" "Hacienda La Ruta"
contiene "Dueño: ve el teléfono" "$TMP/pag.html" "0991234562"
contiene "Botón WhatsApp con el mensaje del programa" "$TMP/pag.html" 'href="https://wa.me/593991234562?text=Hola%20Carlos%2C%20recibimos'
contiene "Miniatura del comprobante" "$TMP/pag.html" 'r=adjunto&amp;i=tuvocalia&amp;sol=comprobante%3A45&amp;adj=1'
get "r=solicitudes&chip=pagos" > /dev/null
check "Chip Pagos por aprobar: solo el comprobante" "1" "$(grep -c 'class="sol p-' "$TMP/pag.html")"
get "r=solicitudes&chip=recordatorios" > /dev/null
no_contiene "Chip Recordatorios: sin comprobantes" "$TMP/pag.html" "Comprobante de pago por revisar"
get "r=solicitudes&q=0987654321" > /dev/null
contiene "Buscar por teléfono" "$TMP/pag.html" "Liga Deportiva Calderón"
get "r=solicitudes&i=tuevento" > /dev/null
no_contiene "Filtro por programa" "$TMP/pag.html" "TuVocalía</span>"
check "Detalle del ticket" "200" "$(get 'r=solicitud&i=tuvocalia&id=soporte:9')"
contiene "Detalle: conversación del ticket" "$TMP/pag.html" "al subir el logo sale un error"
check "Archivo del comprobante: 200 imagen" "200 image/png" "$(curl -s -b "$JAR" -o "$TMP/adj.png" -w '%{http_code} %{content_type}' "$BASE/?r=adjunto&i=tuvocalia&sol=comprobante:45&adj=1")"
curl -s -b "$JAR" -D "$TMP/adj.txt" -o /dev/null "$BASE/?r=adjunto&i=tuvocalia&sol=comprobante:45&adj=1"
contiene "Archivo: nosniff" "$TMP/adj.txt" "X-Content-Type-Options: nosniff"
contiene "Archivo: no se guarda en caché" "$TMP/adj.txt" "Cache-Control: private, no-store"
check "Archivo de otra solicitud -> 404" "404" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/?r=adjunto&i=tuvocalia&sol=comprobante:45&adj=31")"
check "El panel no guarda archivos" "0" "$(find "$TUKAN_CENTRAL_DATOS" -name '*.png' | wc -l)"

titulo "21. Aprobar un comprobante: un toque, sin duplicar al repetir"
P3=$(pagos_de 3)
get "r=accion&i=tuvocalia&a=aprobar_comprobante&n=0&sol=comprobante:45&volver=%2F%3Fr%3Dsolicitudes" > /dev/null
contiene "Formulario con el valor del comprobante" "$TMP/pag.html" 'value="95,00"'
cp "$TMP/pag.html" "$TMP/form_aprobar.html"
R=$(post_form "$TMP/form_aprobar.html" "#form-accion")
check "Aprobar -> vuelve a la bandeja" "303 /?r=solicitudes" "${R%% *} $(destino "$R")"
get "r=solicitudes" > /dev/null
contiene "Aviso con el mensaje del programa" "$TMP/pag.html" "Listo: Liga Barrial Chillogallo queda activa hasta el"
check "Un pago nuevo en el programa" "$((P3 + 1))" "$(pagos_de 3)"
check "El comprobante pasa a Atendidas" "atendida" "$(sql "SELECT estado FROM solicitudes WHERE solicitud_id='comprobante:45'")"
R=$(post_form "$TMP/form_aprobar.html" "#form-accion")
get "r=solicitudes" > /dev/null
contiene "Repetir el mismo formulario: ya estaba hecho" "$TMP/pag.html" "Ya estaba hecho"
check "...y NO hay otro pago" "$((P3 + 1))" "$(pagos_de 3)"
contiene "El programa lo marcó como repetida" "$TMP/mock_tuvocalia/ejecutar.log" "|aprobar_comprobante|repetida"
check "Auditoría del panel: ejecutar con el resumen" "1" "$(sql "SELECT count(*) > 0 FROM auditoria WHERE accion='ejecutar' AND detalle LIKE 'Aprobar comprobante%listo%'")"
contiene "Bitácora del programa con el correo del panel" "$TMP/mock_tuvocalia/bitacora.log" "dimitryfierro@gmail.com|3|Aprobó el comprobante"
R=$(post_form "$TMP/form_aprobar.html" "#form-accion" "op=$(php -r 'echo bin2hex(random_bytes(16));')")
get "r=solicitudes" > /dev/null
contiene "Otra operación sobre lo ya aprobado -> conflicto en palabras simples" "$TMP/pag.html" "TuVocalía: Ese comprobante ya fue atendido."
R=$(post_form "$TMP/form_aprobar.html" "#form-accion" 'fijos={"solicitud_id":"comprobante:99"}')
get "r=solicitudes" > /dev/null
contiene "Datos ocultos cambiados en el navegador -> rechazado" "$TMP/pag.html" "El formulario no es válido"

titulo "22. Pedido firmado: cuerpo cambiado, GET, versión (directo al programa de prueba)"
SEC_TV=$(php -r 'require "app/bootstrap.php"; echo instancias()->secreto("tuvocalia");')
pedido() { # pedido CUERPO_FIRMADO CUERPO_ENVIADO [METODO] [TIPO] -> codigo http y "codigo"
  local tk; tk=$(php -r 'require "src/Token.php"; echo Tukan\Token::crearV2($argv[4] ?? "clientes", "tuvocalia", "d@x.ec", "D", "dueno", $argv[1], $argv[2]);' "$1" "$SEC_TV" "" "${4:-clientes}")
  curl -s -X "${3:-POST}" -H "Content-Type: application/json" -H "X-Tukan-Token: $tk" --data-binary "$2" -o "$TMP/v2.json" -w '%{http_code}' "http://127.0.0.1:8265/tukan_central.php?accion=${4:-clientes}"
  echo " $(php -r 'echo json_decode(file_get_contents($argv[1]), true)["codigo"] ?? "ok";' "$TMP/v2.json")"
}
check "Pedido bueno" "200 ok" "$(pedido '{"pagina":1}' '{"pagina":1}')"
check "Cuerpo cambiado un byte -> 401 firma" "401 firma" "$(pedido '{"pagina":1}' '{"pagina":2}')"
contiene "...con detalle parte=cuerpo" "$TMP/v2.json" '"parte":"cuerpo"'
check "GET a una acción v2 -> 405" "405 invalido" "$(pedido '{}' '{}' GET)"
check "Cuerpo que no es JSON -> 400" "400 invalido" "$(pedido 'hola' 'hola')"
check "Token v1 (cabecera) -> 401 version" "401 version" "$(curl -s -X POST -H 'Content-Type: application/json' -H "X-Tukan-Token: $(php -r 'require "src/Token.php"; echo Tukan\Token::crear("estado","tuvocalia","d@x.ec","D",$argv[1]);' "$SEC_TV")" --data-binary '{}' -o "$TMP/v2.json" -w '%{http_code}' 'http://127.0.0.1:8265/tukan_central.php?accion=clientes') $(php -r 'echo json_decode(file_get_contents($argv[1]),true)["codigo"];' "$TMP/v2.json")"

titulo "23. Registrar pago desde la ficha: valor sugerido, errores junto al campo"
check "Ficha del cliente" "200" "$(get 'r=cliente&i=tuvocalia&id=4')"
contiene "Ficha: estado grande" "$TMP/pag.html" 'class="estado-cliente e-'
contiene "Ficha: contacto del dueño (rol dueño)" "$TMP/pag.html" "ana4@ejemplo.com"
contiene "Ficha: mensajes listos del programa" "$TMP/pag.html" "Mensajes listos (los escribe TuVocalía)"
contiene "Ficha: historial de pagos con Anular" "$TMP/pag.html" "a=anular_pago"
contiene "Ficha: historial de pagos" "$TMP/pag.html" "Pagos</h2>"
check "Cotizar (JSON): 6 meses del plan Completo = 145" '145,00' "$(curl -s -b "$JAR" "$BASE/?r=cotizar&i=tuvocalia&cliente=4&plan=2&periodo=semestral" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["monto_texto"];')"
get "r=accion&i=tuvocalia&a=registrar_pago&n=0&cliente=4&volver=%2F%3Fr%3Dcliente%26i%3Dtuvocalia%26id%3D4" > /dev/null
cp "$TMP/pag.html" "$TMP/form_pago.html"
contiene "Formulario: pide cotizar al cambiar plan o tiempo" "$TMP/form_pago.html" 'data-cotizar-url='
P4=$(pagos_de 4)
R=$(post_form "$TMP/form_pago.html" "#form-accion" "campo[monto]=mucho")
check "Valor con letras: no se manda (422)" "422" "${R%% *}"
contiene "...error junto al campo" "$TMP/post.html" "Escribe el valor con números, por ejemplo 39,00."
check "...no llegó al programa" "$P4" "$(pagos_de 4)"
R=$(post_form "$TMP/form_pago.html" "#form-accion" "campo[fecha]=$(date -d '+5 days' +%Y-%m-%d)")
check "Fecha futura: el programa la rechaza (422)" "422" "${R%% *}"
contiene "...el mensaje del programa junto al campo fecha" "$TMP/post.html" 'f-fecha-err">La fecha del pago no puede ser futura'
OP1=$(php tests/form.php "$TMP/form_pago.html" "#form-accion" | tr '\0' '\n' | grep '^op=' | cut -d= -f2)
OP2=$(php tests/form.php "$TMP/post.html" "#form-accion" | tr '\0' '\n' | grep '^op=' | cut -d= -f2)
[ -n "$OP2" ] && [ "$OP1" != "$OP2" ] && check "Corregir = operación nueva (la anterior quedó como error)" 1 1 || check "Corregir = operación nueva" "distinta" "$OP1 / $OP2"
R=$(post_form "$TMP/post.html" "#form-accion" "campo[fecha]=$(date +%Y-%m-%d)" "campo[periodo]=semestral" "campo[monto]=145,00")
check "Corregido: pago registrado" "303 /?r=cliente&i=tuvocalia&id=4" "${R%% *} $(destino "$R")"
check "Un pago más en el programa" "$((P4 + 1))" "$(pagos_de 4)"
check "El monto llegó como número (145.0)" "145" "$(mockdb tuvocalia "end(\$d['clientes']['4']['pagos'])['monto']")"
check "Copia local actualizada sin esperar la revisión" "activo" "$(sql "SELECT estado FROM clientes WHERE instancia_id='tuvocalia' AND cliente_id='4'")"

titulo "24. El programa no respondió: Revisar manda la misma operación"
P2=$(pagos_de 2)
get "r=accion&i=tuvocalia&a=registrar_pago&n=0&cliente=2" > /dev/null
cp "$TMP/pag.html" "$TMP/form_lento.html"
echo 17 > "$TMP/mock_tuvocalia/lento_ejecutar"
INI=$(date +%s)
R=$(post_form "$TMP/form_lento.html" "#form-accion")
check "Más de 15 s: el panel deja de esperar (504)" "504" "${R%% *}"
check "...a los 15 segundos" "1" "$(( $(date +%s) - INI >= 14 && $(date +%s) - INI <= 18 ? 1 : 0 ))"
contiene "Pantalla: no respondió + Revisar" "$TMP/post.html" "TuVocalía no respondió"
contiene "...explica que puede que sí se haya registrado" "$TMP/post.html" "el pago sí se haya registrado"
rm -f "$TMP/mock_tuvocalia/lento_ejecutar"; sleep 3
check "El programa sí lo hizo (1 pago más)" "$((P2 + 1))" "$(pagos_de 2)"
check "Operación guardada como sin_respuesta" "sin_respuesta" "$(sql "SELECT estado FROM operaciones WHERE accion='registrar_pago' AND cliente_id='2' ORDER BY creada DESC LIMIT 1")"
cp "$TMP/post.html" "$TMP/revisar.html"
R=$(post_form "$TMP/revisar.html" "accion:ejecutar")
check "Revisar -> vuelve" "303" "${R%% *}"
check "...sin pago duplicado" "$((P2 + 1))" "$(pagos_de 2)"
get "r=inicio" > /dev/null
contiene "...y dice que ya estaba hecho" "$TMP/pag.html" "Ya estaba hecho: Pago registrado"
echo 1 > "$TMP/mock_tuvocalia/en_curso"
abrir_accion tuvocalia 2 nota_interna
contiene "Formulario de nota interna" "$TMP/pag.html" "Nota (el cliente no la ve)"
R=$(post_form "$TMP/pag.html" "#form-accion" "campo[texto]=Pidió factura con RUC")
check "Operación en curso: espera 3 s y reintenta sola" "303" "${R%% *}"
check "...la segunda vez se hizo" "en_curso ok" "$(tail -2 "$TMP/mock_tuvocalia/ejecutar.log" | cut -d'|' -f3 | tr '\n' ' ' | sed 's/ $//')"
check "Nota guardada en el programa" "Pidió factura con RUC" "$(mockdb tuvocalia "\$d['clientes']['2']['notas']")"

titulo "25. Reglas del programa: no_permitido, suspender (peligro), reactivar, anular con 2FA"
R=$(post_form "$TMP/form_pago.html" "#form-accion" "accion=extender_prueba" "campo[dias]=7")
get "r=cliente&i=tuvocalia&id=4" > /dev/null
contiene "Cambiar la acción en el navegador (otra firma): rechazado" "$TMP/pag.html" "El formulario no es válido"
get "r=cliente&i=tuvocalia&id=9" > /dev/null
contiene "Cortesía: no vence" "$TMP/pag.html" "No vence"
abrir_accion tuvocalia 4 suspender
contiene "Suspender: peligro, pide marcar que está seguro" "$TMP/pag.html" 'name="seguro"'
cp "$TMP/pag.html" "$TMP/form_susp.html"
R=$(post_form "$TMP/form_susp.html" "#form-accion" "campo[motivo]=No paga hace meses" "seguro=")
check "Sin marcar 'estoy seguro' no se manda" "422" "${R%% *}"
R=$(post_form "$TMP/form_susp.html" "#form-accion" "campo[motivo]=No paga hace meses" "seguro=1")
check "Suspendido" "suspendido" "$(sql "SELECT estado FROM clientes WHERE instancia_id='tuvocalia' AND cliente_id='4'")"
get "r=cliente&i=tuvocalia&id=4" > /dev/null
contiene "Ficha: botón Reactivar (un toque)" "$TMP/pag.html" ">Reactivar</button>"
R=$(post_form "$TMP/pag.html" "boton:Reactivar")
check "Reactivado" "activo" "$(sql "SELECT estado FROM clientes WHERE instancia_id='tuvocalia' AND cliente_id='4'")"
PID_A=$(mockdb tuvocalia "end(\$d['clientes']['4']['pagos'])['id']")
get "r=accion&i=tuvocalia&a=anular_pago&cliente=4&pago=$PID_A" > /dev/null
contiene "Anular pago pide el código de 2 pasos" "$TMP/pag.html" 'name="codigo"'
cp "$TMP/pag.html" "$TMP/form_anular.html"
R=$(post_form "$TMP/form_anular.html" "#form-accion" "campo[motivo]=Pago repetido" "codigo=000000" "seguro=1")
check "Código malo: no se anula" "false" "$(mockdb tuvocalia "var_export(end(\$d['clientes']['4']['pagos'])['anulado'], true)")"
contiene "...mensaje del código" "$TMP/post.html" "código de 2 pasos no es correcto"
R=$(post_form "$TMP/form_anular.html" "#form-accion" "campo[motivo]=Pago repetido" "seguro=1" "codigo=$(codigo "$SECRETO_D" "$TMP/paso_d")")
get "r=cliente&i=tuvocalia&id=4" > /dev/null
contiene "Ficha: últimas acciones en el programa" "$TMP/pag.html" "Últimas acciones"
check "Código bueno: anulado en el programa" "true" "$(mockdb tuvocalia "var_export(end(\$d['clientes']['4']['pagos'])['anulado'], true)")"
get "r=cliente&i=tuvocalia&id=3" > /dev/null
abrir_accion tuvocalia 1 extender_prueba
R=$(post_form "$TMP/pag.html" "#form-accion" "campo[dias]=10")
check "Alargar la prueba de una demo" "303" "${R%% *}"
check "...10 días más en el programa" "$(date -d '+12 days' +%Y-%m-%d)" "$(mockdb tuvocalia "\$d['clientes']['1']['vence']")"

titulo "26. Crear demo (desde un pedido): accesos una sola vez, WhatsApp listo"
check "Crear demo: elegir el programa" "200" "$(get 'r=demo_nueva')"
contiene "Solo los programas que pueden crear demos" "$TMP/pag.html" "r=demo_nueva&amp;i=tuvocalia"
no_contiene "TuSalón (v1) no sale" "$TMP/pag.html" "i=tusalon"
check "Formulario lleno desde el pedido" "200" "$(get 'r=demo_nueva&i=tuvocalia&sol=demo_pedida:12')"
contiene "...nombre del negocio" "$TMP/pag.html" 'value="Liga Deportiva Calderón"'
contiene "...campos propios de TuVocalía (tipo de cuenta)" "$TMP/pag.html" 'name="extra\[tipo\]"'
contiene "...la ciudad del pedido" "$TMP/pag.html" 'name="extra\[ciudad\]" value="Quito"'
cp "$TMP/pag.html" "$TMP/form_demo.html"
R=$(post_form "$TMP/form_demo.html" "#form-demo" "campo[email]=carlos3@ejemplo.com")
check "Correo que ya tiene cuenta: error junto al campo" "422" "${R%% *}"
contiene "...con el texto del programa" "$TMP/post.html" 'd-email-err">Ese correo ya tiene una cuenta'
cp "$TMP/post.html" "$TMP/form_demo2.html"
R=$(post_form "$TMP/form_demo2.html" "#form-demo" "campo[email]=roberto@calderon.ec")
check "Demo creada (la clave se muestra aquí mismo)" "200" "${R%% *}"
contiene "Pantalla: Demo lista" "$TMP/post.html" "Demo lista"
CLAVE=$(grep -o 'data-clave-una-vez>[^<]*' "$TMP/post.html" | sed 's/.*>//')
check "Clave de 10 letras" "10" "${#CLAVE}"
contiene "Botón Enviar por WhatsApp con los accesos" "$TMP/post.html" 'href="https://wa.me/593987654321?text='
contiene "Botón Copiar" "$TMP/post.html" 'data-copiar="#mensaje-wa"'
contiene "Botón Entrar a la demo" "$TMP/post.html" "Entrar a la demo"
check "La clave NO quedó en la base del panel" "0" "$(grep -c "$CLAVE" "$TUKAN_CENTRAL_DATOS/central.sqlite" "$TUKAN_CENTRAL_DATOS"/central.sqlite-wal 2>/dev/null | awk -F: '{s+=$2} END {print s+0}')"
check "...ni en la auditoría" "0" "$(sql "SELECT count(*) FROM auditoria WHERE detalle LIKE '%$CLAVE%'")"
check "...ni en la sesión" "0" "$(grep -rl "$CLAVE" "$TUKAN_CENTRAL_DATOS/sesiones" 2>/dev/null | wc -l)"
check "El pedido quedó como demo creada en el programa" "creada" "$(mockdb tuvocalia "\$d['solicitudes']['demo_pedida:12']['estado']")"
check "Historial de demos del panel" "Liga Deportiva Calderón" "$(sql "SELECT nombre FROM demos ORDER BY id DESC LIMIT 1")"
R=$(post_form "$TMP/form_demo2.html" "#form-demo" "campo[email]=roberto@calderon.ec")
check "Repetir el mismo formulario: no crea otra cuenta" "1" "$(mockdb tuvocalia "count(array_filter(\$d['clientes'], fn(\$c) => \$c['dueno']['email'] === 'roberto@calderon.ec'))")"
contiene "...y la clave ya no se muestra" "$TMP/post.html" "la contraseña solo se muestra una vez"
DEMO=$(sql "SELECT cliente_id FROM demos ORDER BY id DESC LIMIT 1")
abrir_accion tuvocalia "$DEMO" registrar_pago
post_form "$TMP/pag.html" "#form-accion" > /dev/null
get "r=clientes" > /dev/null
post_form "$TMP/pag.html" "accion:actualizar_datos" > /dev/null
contiene "Telegram: la demo pagó" "$TMP/mock_telegram/telegram.log" "🎉 ¡La demo de Liga Deportiva Calderón pagó! \$19,00 (TuVocalía)"
get "r=demos" > /dev/null
contiene "Demos: pagó ✔" "$TMP/pag.html" "Pagó ✔"
get "r=cliente&i=tuvocalia&id=5" > /dev/null
R=$(post_form "$TMP/pag.html" "boton:Nueva clave del dueño")
contiene "Nueva clave del dueño (un toque): se muestra una vez" "$TMP/post.html" "Contraseña nueva lista"
get "r=demo_nueva&i=tusalon" > /dev/null
contiene "Programa que no puede crear demos: aviso" "$TMP/pag.html" "no puede crear demos desde el panel"

titulo "27. Clientes: todos los programas, filtros y Recordar por WhatsApp"
check "Abre Clientes" "200" "$(get 'r=clientes')"
TOTC=$(sql "SELECT count(*) FROM clientes")
check "Todos los clientes de todos los programas ($TOTC)" "$TOTC" "$(grep -c 'data-cliente=' "$TMP/pag.html")"
contiene "Chips de estado con número" "$TMP/pag.html" 'En prueba <b>'
contiene "Dogssy (una sola empresa) con su licencia" "$TMP/pag.html" "Dogssy"
contiene "Nota: TuSalón todavía no manda su lista" "$TMP/pag.html" "TuSalón, TuAcademia todavía no mandan su lista"
get "r=clientes&estado=vencido" > /dev/null
check "Filtro Vencidos" "$(sql "SELECT count(*) FROM clientes WHERE estado='vencido'")" "$(grep -c 'data-cliente=' "$TMP/pag.html")"
get "r=clientes&vence=7" > /dev/null
contiene "Se vence en 7 días" "$TMP/pag.html" "Salón Imperial"
no_contiene "...sin los que ya vencieron" "$TMP/pag.html" "Liga Carcelén"
get "r=clientes&q=0991234560" > /dev/null
check "Buscar por teléfono (dueño)" "2" "$(grep -c 'data-cliente=' "$TMP/pag.html")"
get "r=clientes&sin_uso=15" > /dev/null
contiene "Sin usar hace 15 días" "$TMP/pag.html" "Liga Parroquial Conocoto"
get "r=clientes" > /dev/null
R=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' --data-urlencode "csrf=$(csrf)" --data-urlencode "i=tuvocalia" --data-urlencode "id=1" "$BASE/?r=recordar")
check "Recordar -> abre WhatsApp con el mensaje" "1" "$(echo "$R" | grep -c '^303 https://wa.me/593991234560?text=Hola%20Juan%2C%20le%20saluda%20Tuk%C3%A1n%20Software.%20%C2%BFC%C3%B3mo%20le%20va%20con%20la%20prueba')"
get "r=clientes" > /dev/null
contiene "Recordado hace un momento por Dimitry" "$TMP/pag.html" "Recordado hace un momento por Dimitry"
check "Auditoría: recordatorio" "1" "$(audit_n recordatorio)"
curl -s -D "$TMP/cab_cl.txt" -o /dev/null -b "$JAR" "$BASE/?r=clientes"
contiene "CSP: WhatsApp permitido en form-action" "$TMP/cab_cl.txt" "https://wa.me"

titulo "28. Entrar con enlace directo (ir) a la ficha del cliente en el programa"
get "r=cliente&i=tuvocalia&id=3" > /dev/null
R=$(curl -s -b "$JAR" -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$(csrf)" --data-urlencode "id=tuvocalia" --data-urlencode "ir=/admin?cliente=3" "$BASE/?r=entrar")
check "El token lleva ir" "/admin?cliente=3" "$(php -r 'echo json_decode(base64_decode(strtr(explode(".", explode("t=", $argv[1])[1])[0], "-_", "+/")), true)["ir"] ?? "";' "$R")"
check "El programa abre esa pantalla" "200 /admin?cliente=3" "$(curl -s -L -c "$TMP/jar_ir" -b "$TMP/jar_ir" -o "$TMP/ir.html" -w '%{http_code}' "$R") $(grep -o 'id="donde">[^<]*' "$TMP/ir.html" | sed 's/.*>//')"
R=$(curl -s -b "$JAR" -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$(csrf)" --data-urlencode "id=tuvocalia" --data-urlencode "ir=//malo.com" "$BASE/?r=entrar")
check "ir no permitido: el panel no lo manda" "" "$(php -r 'echo json_decode(base64_decode(strtr(explode(".", explode("t=", $argv[1])[1])[0], "-_", "+/")), true)["ir"] ?? "";' "$R")"
R=$(curl -s -b "$JAR" -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$(csrf)" --data-urlencode "id=tusalon" --data-urlencode "ir=/admin" "$BASE/?r=entrar")
check "Programa v1: no se manda ir" "" "$(php -r 'echo json_decode(base64_decode(strtr(explode(".", explode("t=", $argv[1])[1])[0], "-_", "+/")), true)["ir"] ?? "";' "$R")"
check "Auditoría: entró a la ficha" "1" "$(sql "SELECT count(*) > 0 FROM auditoria WHERE accion='entrar' AND detalle LIKE '%/admin?cliente=3%'")"

titulo "29. Finanzas: ingresos − gastos = ganancia; gastos fijos; descargas"
check "Abre Finanzas" "200" "$(get 'r=finanzas')"
ING12=$(php -r 'require "app/bootstrap.php"; echo finanzas()->resumen(Tukan\Finanzas::meses("12"), false)["total"]["total"]["ingresos"];')
check "Ingresos de 12 meses = lo que informan los programas" "$(php -r 'echo "$".number_format((float)$argv[1],2,",",".");' "$ING12")" "$(grep -o 'data-fin="ingresos">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"
GAS0=$(php -r 'require "app/bootstrap.php"; echo finanzas()->resumen(Tukan\Finanzas::meses("12"), false)["total"]["total"]["gastos"];')
check "Abre Gastos" "200" "$(get 'r=gastos')"
R=$(post_form "$TMP/pag.html" "#form-movimiento" "fecha=$(date +%Y-%m-%d)" "categoria=servidores" "descripcion=VPS Contabo" "monto=25,50" "instancia_id=tuvocalia" "recurrente=1" "dia=5")
check "Agregar gasto fijo" "303" "${R%% *}"
get "r=gastos" > /dev/null
contiene "Gasto en la lista del mes" "$TMP/pag.html" "VPS Contabo"
contiene "Fijo en Fijos de cada mes" "$TMP/pag.html" "Día 5 · TuVocalía"
{ get "r=gastos" > /dev/null; cp "$TMP/pag.html" "$TMP/g1.html"; get "r=gastos&mes=$(date -d "$(date +%Y-%m-01) -1 month" +%Y-%m)" > /dev/null; cat "$TMP/g1.html" >> "$TMP/pag.html"; }
contiene "Costos que informan los programas (aparte)" "$TMP/pag.html" "(lo informa TuVocalía)"
get "r=gastos" > /dev/null
post_form "$TMP/pag.html" "#form-movimiento" "fecha=$(date +%Y-%m-%d)" "categoria=publicidad" "descripcion=Facebook Ads" "monto=40" > /dev/null
check "Gastos del mes en la base" "65.5" "$(sql "SELECT sum(monto) FROM gastos WHERE anulado = 0")"
get "r=finanzas" > /dev/null
check "Ganancia = ingresos − gastos (con los nuevos)" "$(php -r 'echo "$".number_format((float)$argv[1]-(float)$argv[2]-65.5,2,",",".");' "$ING12" "$GAS0")" "$(grep -o 'data-fin="ganancia">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"
contiene "Tabla por programa: gastos asignados de TuVocalía" "$TMP/pag.html" "−\$25,50"
contiene "Gastos generales (sin programa)" "$TMP/pag.html" "Gastos generales"
post_form "$TMP/pag.html" "accion:finanzas" > /dev/null
check "Repartir gastos generales: queda en Ajustes" "1" "$(sql "SELECT valor FROM ajustes WHERE clave='repartir_gastos_generales'")"
get "r=finanzas" > /dev/null
contiene "Repartidos: la parte de cada programa" "$TMP/pag.html" "Parte de gastos generales"
check "Detalle por plan de un programa" "200" "$(get 'r=finanzas&prog=tuvocalia')"
contiene "...por forma de pago" "$TMP/pag.html" "Por forma de pago"
php -r 'require "app/bootstrap.php"; finanzas()->generarFijos(date("Y-m", strtotime("first day of next month")));'
check "El fijo se crea el mes que viene (una vez)" "1" "$(sql "SELECT count(*) FROM gastos WHERE descripcion='VPS Contabo' AND mes='$(date -d "$(date +%Y-%m-01) +1 month" +%Y-%m)'")"
get "r=gastos" > /dev/null
GID=$(sql "SELECT id FROM gastos WHERE descripcion='Facebook Ads'")
R=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' --data-urlencode "csrf=$(csrf)" --data-urlencode "accion=anular" --data-urlencode "id=$GID" "$BASE/?r=gastos")
check "Anular un gasto" "1" "$(sql "SELECT anulado FROM gastos WHERE id=$GID")"
check "Auditoría: gastos" "1" "$(sql "SELECT count(*) >= 3 FROM auditoria WHERE accion='gasto'")"
get "r=ingresos_extra" > /dev/null
post_form "$TMP/pag.html" "#form-movimiento" "fecha=$(date +%Y-%m-%d)" "concepto=Capacitación" "monto=80" > /dev/null
check "Ingreso extra guardado" "80" "$(sql "SELECT monto FROM ingresos_extra WHERE concepto='Capacitación'")"
curl -s -b "$JAR" -D "$TMP/csv.txt" -o "$TMP/f.csv" "$BASE/?r=exportar&f=csv&periodo=12"
contiene "CSV: descarga" "$TMP/csv.txt" 'attachment; filename="tukan_finanzas_'
check "CSV: BOM UTF-8" "efbbbf" "$(head -c3 "$TMP/f.csv" | od -An -tx1 | tr -d ' \n')"
contiene "CSV: separado por ; con decimales con coma" "$TMP/f.csv" "Gasto;$(date +%Y-%m);$(date +%Y-%m-%d);TuVocalía;Servidores/VPS;VPS Contabo;-25,5"
no_contiene "CSV: sin el gasto anulado" "$TMP/f.csv" "Facebook Ads"
curl -s -b "$JAR" -o "$TMP/f.xlsx" "$BASE/?r=exportar&f=xlsx&periodo=12"
if python3 -c "import openpyxl" 2>/dev/null; then
  check "Excel: 5 hojas que abre openpyxl" "Resumen,Por programa,Ingresos,Gastos,Ingresos extra" "$(python3 -c 'import openpyxl,sys; print(",".join(openpyxl.load_workbook(sys.argv[1]).sheetnames))' "$TMP/f.xlsx")"
  check "Excel: el gasto fijo en la hoja Gastos" "VPS Contabo 25.5" "$(python3 -c 'import openpyxl,sys; ws=openpyxl.load_workbook(sys.argv[1])["Gastos"]; r=[x for x in ws.iter_rows(values_only=True) if x[3]=="VPS Contabo"][0]; print(r[3], r[5])' "$TMP/f.xlsx")"
fi
check "Auditoría: descargas" "2" "$(audit_n exportar)"

titulo "30. Licencia de TuGuardería: pago -> ingreso extra y fecha nueva"
get "r=programa&id=dogssy" > /dev/null
contiene "Ficha Dogssy: sección Licencia" "$TMP/pag.html" "Registrar pago de licencia"
R=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' --data-urlencode "csrf=$(csrf)" --data-urlencode "i=dogssy" --data-urlencode "accion=guardar" --data-urlencode "valor_mensual=35,00" --data-urlencode "contacto_nombre=Gabriela Ortiz" --data-urlencode "contacto_telefono=0998877665" "$BASE/?r=licencias")
check "Guardar licencia" "35" "$(sql "SELECT valor_mensual FROM licencias WHERE instancia_id='dogssy'")"
check "Contacto de la licencia cifrado" "0" "$(sql "SELECT count(*) FROM licencias WHERE contacto_cif LIKE '%0998877665%'")"
get "r=programa&id=dogssy" > /dev/null
post_form "$TMP/pag.html" "#pago-licencia" "monto=105,00" "meses=3" > /dev/null
check "Pagada hasta: hoy + 3 meses" "$(date -d '+3 months' +%Y-%m-%d)" "$(sql "SELECT activo_hasta FROM licencias WHERE instancia_id='dogssy'")"
check "Ingreso extra de Dogssy" "105" "$(sql "SELECT monto FROM ingresos_extra WHERE instancia_id='dogssy'")"
check "Clientes: Dogssy vence con su licencia" "$(date -d '+3 months' +%Y-%m-%d)" "$(sql "SELECT vence FROM clientes WHERE instancia_id='dogssy'")"
get "r=cliente&i=dogssy&id=unico" > /dev/null
contiene "Ficha del cliente único: nueva clave del administrador" "$TMP/pag.html" "Nueva clave del administrador"

titulo "31. Vencimientos, Embudo y Demos"
check "Vencimientos" "200" "$(get 'r=vencimientos')"
contiene "Franja: ya vencieron sin renovar" "$TMP/pag.html" "Ya vencieron sin renovar: <b>$(sql "SELECT count(*) FROM clientes WHERE estado='vencido'")</b>"
contiene "Calendario: prueba en amarillo" "$TMP/pag.html" 'cal-item amarillo'
contiene "Esta semana: plata en juego" "$TMP/pag.html" "al mes en juego"
check "Vencimientos de otro mes" "200" "$(get "r=vencimientos&mes=$(date -d '+3 months' +%Y-%m)")"
contiene "...la licencia de Dogssy" "$TMP/pag.html" "Dogssy"
check "Embudo" "200" "$(get 'r=embudo')"
contiene "Embudo: pasos con porcentaje" "$TMP/pag.html" 'data-embudo="pagaron"'
contiene "Pruebas que terminaron sin pagar" "$TMP/pag.html" "Liga Carcelén"
contiene "Clientes en riesgo" "$TMP/pag.html" "Liga Parroquial Conocoto"
check "Demos" "200" "$(get 'r=demos')"
contiene "Demos este mes con porcentaje" "$TMP/pag.html" 'data-kpi="demos_mes"'
contiene "Historial: de dónde vino" "$TMP/pag.html" "Panel Tukán (Dimitry)"

titulo "32. Inicio: lo de hoy (por atender, vencen esta semana, cobrado y ganancia)"
get "r=inicio" > /dev/null
check "Por atender = número rojo" "$(sql "SELECT count(*) FROM solicitudes s JOIN instancias i ON i.id=s.instancia_id AND i.activo=1 WHERE s.estado='pendiente'")" "$(grep -o 'data-kpi="por_atender">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"
contiene "Cobrado este mes" "$TMP/pag.html" 'data-kpi="cobrado"'
GM=$(php -r 'require "app/bootstrap.php"; $m=date("Y-m"); $r=finanzas()->resumen([$m], true); $c=$r["total"]["meses"][$m]["ingresos"]; foreach (monitor()->tablero(true) as $f) if (!Tukan\Capacidades::puede(capacidades()->datos($f["id"]), "finanzas") && $f["datos"] && $f["datos"]["ingresos_mes"] !== null) $c += $f["datos"]["ingresos_mes"]; echo "$".number_format($c - $r["total"]["meses"][$m]["gastos"], 2, ",", ".");')
check "Ganancia del mes = cobrado − gastos" "$GM" "$(grep -o 'data-kpi="ganancia">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"

titulo "33. Rol soporte: atiende solicitudes, no ve contactos ni plata, no cobra"
JAR_D2="$JAR"; JAR="$TMP/jar_soporte2"; IP="198.51.100.21"; get "r=login" > /dev/null
post login --data-urlencode "email=soporte@tukan.ec" --data-urlencode "clave=claveSoporte2026" > /dev/null
get "r=verificar" > /dev/null
post verificar --data-urlencode "codigo=$(codigo "$SECRETO_S" "$TMP/paso_s")" > /dev/null
check "Soporte entra" "200" "$(get 'r=solicitudes')"
no_contiene "Soporte: sin teléfonos completos" "$TMP/pag.html" "0991234561"
contiene "Soporte: teléfono escondido" "$TMP/pag.html" "09•••••61"
no_contiene "Soporte: sin botones de WhatsApp" "$TMP/pag.html" "wa.me"
no_contiene "Soporte: sin Registrar pago" "$TMP/pag.html" "a=registrar_pago"
contiene "Soporte: sí puede Marcar atendida" "$TMP/pag.html" "Marcar atendida"
no_contiene "Soporte: sin Finanzas en el menú" "$TMP/pag.html" "r=finanzas"
check "Soporte: Finanzas -> 403" "403" "$(get 'r=finanzas')"
check "Soporte: Gastos -> 403" "403" "$(get 'r=gastos')"
check "Soporte: Descargar -> 403" "403" "$(get 'r=exportar&f=csv')"
check "Soporte: Crear demo -> 403" "403" "$(get 'r=demo_nueva')"
check "Soporte: Cotizar -> 403" "403" "$(get 'r=cotizar&i=tuvocalia&cliente=3&plan=1')"
check "Soporte: foto del comprobante -> 403" "403" "$(get 'r=adjunto&i=tuvocalia&sol=comprobante:45&adj=1')"
check "Soporte: captura del ticket sí" "200" "$(get 'r=adjunto&i=tuvocalia&sol=soporte:9&adj=31')"
check "Soporte: formulario de pago negado (vuelve)" "303" "$(get 'r=accion&i=tuvocalia&a=registrar_pago&n=0&cliente=3')"
get "r=solicitudes" > /dev/null
contiene "...con el aviso" "$TMP/pag.html" "no tiene permiso"
get "r=clientes" > /dev/null
no_contiene "Soporte: Clientes sin correos" "$TMP/pag.html" "@ejemplo.com"
no_contiene "Soporte: sin Recordar por WhatsApp" "$TMP/pag.html" "r=recordar"
get "r=cliente&i=tuvocalia&id=3" > /dev/null
contiene "Soporte: ficha sin contacto" "$TMP/pag.html" "solo lo ve el dueño del panel"
no_contiene "Soporte: ficha sin plata" "$TMP/pag.html" "Ha pagado"
R=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' --data-urlencode "csrf=$(csrf)" --data-urlencode "i=tuvocalia" --data-urlencode "id=3" "$BASE/?r=recordar")
check "Soporte: Recordar -> 403" "403" "$R"
get "r=solicitudes&chip=registros" > /dev/null
N_ANTES=$(sql "SELECT count(*) FROM solicitudes WHERE estado='pendiente'")
R=$(post_form "$TMP/pag.html" "accion:ejecutar")
check "Soporte: Marcar atendida (un toque) funciona" "303" "${R%% *}"
check "...una menos por atender" "$((N_ANTES - 1))" "$(sql "SELECT count(*) FROM solicitudes WHERE estado='pendiente'")"
check "...y queda en la auditoría con el correo de soporte" "1" "$(sql "SELECT count(*) > 0 FROM auditoria WHERE accion='ejecutar' AND email='soporte@tukan.ec'")"
check "Programa recibe rol soporte en el token (bitácora con su correo)" "1" "$(cat "$TMP"/mock_*/bitacora.log | grep -c 'soporte@tukan.ec|' | sed 's/^[1-9][0-9]*$/1/')"
check "Soporte: permisos negados anotados" "1" "$(sql "SELECT count(*) >= 2 FROM auditoria WHERE accion='permiso_negado' AND email='soporte@tukan.ec' AND momento > strftime('%s','now') - 300")"
JAR="$JAR_D2"; IP="198.51.100.11"

titulo "34. Programa caído: las pantallas siguen con los últimos datos"
parar tuvocalia
check "Solicitudes abre aunque TuVocalía esté caído" "200" "$(get 'r=solicitudes')"
contiene "...con sus datos guardados" "$TMP/pag.html" "TuVocalía"
check "Ficha con el programa caído" "200" "$(get 'r=cliente&i=tuvocalia&id=3')"
contiene "...dice que no respondió y muestra lo guardado" "$TMP/pag.html" "no respondió ahora"
check "Detalle de solicitud con el programa caído" "200" "$(get 'r=solicitud&i=tuvocalia&id=soporte:9')"
contiene "...con los datos guardados" "$TMP/pag.html" "Se muestran los datos guardados"
get "r=cliente&i=tuvocalia&id=3" > /dev/null
post_form "$TMP/form_pago.html" "#form-accion" "op=$(php -r 'echo bin2hex(random_bytes(16));')" > /dev/null
contiene "Cobrar con el programa caído: pantalla Revisar (no se pierde)" "$TMP/post.html" "TuVocalía no respondió"
mock tuvocalia 8265 tuvocalia "$SEC_TV" tuvocalia
get "r=clientes" > /dev/null
R=$(post_form "$TMP/pag.html" "accion:actualizar_datos")
get "r=clientes" > /dev/null
contiene "Actualizar: vuelve a traer" "$TMP/pag.html" "Datos actualizados"
if grep -qiE "fatal|warning|deprecated|notice|Tukan Central:" "$TMP/central.log"; then
  FALLAS=$((FALLAS+1)); echo "  FALLA Avisos o errores de PHP en el panel (v2):"; grep -iE "fatal|warning|deprecated|notice|Tukan Central:" "$TMP/central.log" | sort | uniq -c | head -20
else OK=$((OK+1)); echo "  OK    Sin avisos ni errores de PHP en el panel (v2)"; fi
if cat "$TMP"/mock_*.log | grep -qiE "fatal|warning|deprecated|notice"; then
  FALLAS=$((FALLAS+1)); echo "  FALLA Avisos de PHP en los programas de prueba:"; cat "$TMP"/mock_*.log | grep -iE "fatal|warning|deprecated|notice" | sort | uniq -c | head -10
else OK=$((OK+1)); echo "  OK    Sin avisos de PHP en los programas de prueba"; fi

# =====================================================================
titulo "35. Borrar una instalación desde el panel (TuGuardería): ID + 2FA, programado, en curso, borrado, Cloudflare"
CF_TOKEN="tok_$(php -r 'echo bin2hex(random_bytes(16));')"; CF_CUENTA=$(php -r 'echo bin2hex(random_bytes(16));'); CF_TUNEL="1a2b3c4d-1111-2222-3333-444455556666"
mkdir -p "$TMP/mock_cf"
env -i PATH="$PATH" MOCK_DIR="$TMP/mock_cf" CF_TOKEN="$CF_TOKEN" CF_CUENTA="$CF_CUENTA" CF_TUNEL="$CF_TUNEL" php -S "127.0.0.1:$CF" tests/mock/cloudflare.php > "$TMP/cloudflare.log" 2>&1 &
PIDS+=($!)
for _ in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$CF/" && break; sleep 0.1; done
cf_hosts() { php -r '$e=json_decode((string)@file_get_contents($argv[1]),true); foreach(($e["config"]["ingress"]??[]) as $r) if(isset($r["hostname"])) echo $r["hostname"],"\n";' "$TMP/mock_cf/cf.json"; }
cf_dns() { php -r '$e=json_decode((string)@file_get_contents($argv[1]),true); foreach(($e["dns"]??[]) as $r) echo $r["name"],"\n";' "$TMP/mock_cf/cf.json"; }
JAR="$JAR_D2"; IP="198.51.100.11"
get "r=programa&id=dogssy" > /dev/null
no_contiene "Dogssy (v2 sin dar_de_baja): no sale la zona Borrar" "$TMP/pag.html" 'id="baja"'
contiene "Dogssy: sigue Quitar este programa del panel (como antes)" "$TMP/pag.html" "Quitar este programa del panel"
agregar tuguarderia guau "TuGuardería Guau" "http://127.0.0.1:8267" "guau"
SEC_GU=$(echo "$CMD" | awk '{print $6}')
mock guau 8267 guau "$SEC_GU" tuguarderia MOCK_BAJA=1 MOCK_CLIENTE=Guau MOCK_DOMINIO=guau.tukanec.com
probar guau
contiene "Guau (con dar_de_baja): zona Borrar esta instalación" "$TMP/pag.html" 'id="baja"'
contiene "...pide escribir el ID" "$TMP/pag.html" 'Escribe el ID: <b class="mono">guau</b>'
contiene "...y el código de 2 pasos" "$TMP/pag.html" 'id="baja-codigo"'
contiene "...dice que antes guarda una copia final" "$TMP/pag.html" "/var/backups/borrados/"
contiene "...sin Cloudflare: avisa que recordará quitar la dirección" "$TMP/pag.html" "te recordaremos quitar guau.tukanec.com"
cp "$TMP/pag.html" "$TMP/form_baja.html"
get "r=cliente&i=guau&id=unico" > /dev/null
contiene "Ficha del cliente: Borrar lleva a la zona de peligro del programa" "$TMP/pag.html" 'href="/?r=programa&amp;id=guau#baja"'
no_contiene "...y no al formulario genérico" "$TMP/pag.html" 'a=dar_de_baja'
R=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' --data-urlencode "csrf=$(csrf)" --data-urlencode "i=guau" --data-urlencode "accion=dar_de_baja" --data-urlencode "op=$(php -r 'echo bin2hex(random_bytes(16));')" "$BASE/?r=ejecutar")
check "ejecutar genérico con dar_de_baja: no se manda (va a la ficha)" "303 /?r=programa&id=guau" "$(echo "$R" | sed "s#$BASE##")"
check "...el programa no recibió nada" "0" "$(cat "$TMP/mock_guau/bajas.log" 2>/dev/null | wc -l)"
# Soporte: ni ve ni puede
JAR="$TMP/jar_soporte2"; IP="198.51.100.21"
get "r=programa&id=guau" > /dev/null
no_contiene "Soporte: no ve la zona Borrar" "$TMP/pag.html" 'id="baja"'
R=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -H "CF-Connecting-IP: $IP" --data-urlencode "csrf=$(csrf)" --data-urlencode "id=guau" --data-urlencode "accion=programar" --data-urlencode "confirmar=guau" --data-urlencode "codigo=123456" --data-urlencode "op=$(php -r 'echo bin2hex(random_bytes(16));')" "$BASE/?r=baja")
check "Soporte: programar el borrado -> 403" "403" "$R"
check "...anotado como permiso negado" "1" "$(sql "SELECT count(*) FROM auditoria WHERE accion='permiso_negado' AND email='soporte@tukan.ec' AND detalle LIKE '%baja%'")"
JAR="$JAR_D2"; IP="198.51.100.11"
# ID mal escrito y código malo
get "r=programa&id=guau" > /dev/null
R=$(post_form "$TMP/form_baja.html" "#form-baja" "confirmar=dogssy" "codigo=123456")
get "r=programa&id=guau" > /dev/null
contiene "ID mal escrito: no se borra y lo dice" "$TMP/pag.html" "escribe el ID exacto del programa (guau)"
R=$(post_form "$TMP/form_baja.html" "#form-baja" "confirmar=guau" "codigo=000000")
get "r=programa&id=guau" > /dev/null
contiene "Código de 2 pasos malo: no se borra" "$TMP/pag.html" "código de 2 pasos no es correcto"
check "...2fa_fallido en la auditoría" "1" "$(sql "SELECT count(*) FROM auditoria WHERE accion='2fa_fallido' AND detalle LIKE '%borrar la instalación de TuGuardería Guau%'")"
check "...el programa no recibió ningún pedido de borrado" "0" "$(cat "$TMP/mock_guau/bajas.log" 2>/dev/null | wc -l)"
check "...baja_rechazada anotado 2 veces" "2" "$(audit_n baja_rechazada)"
# Programar y cancelar
R=$(post_form "$TMP/form_baja.html" "#form-baja" "confirmar=guau" "codigo=$(codigo "$SECRETO_D" "$TMP/paso_d")")
check "ID + código bueno: programado" "303 /?r=programa&id=guau#baja" "$(echo "$R" | sed "s#$BASE##")"
check "...el programa recibió dar_de_baja con la carpeta, el correo y el rol dueño" "programada|guau|dimitryfierro@gmail.com|dueno" "$(cut -d'|' -f1,2,4,5 "$TMP/mock_guau/bajas.log" | tail -1)"
get "r=programa&id=guau" > /dev/null
contiene "...aviso: Programada + copia final" "$TMP/pag.html" "Programada: la instalación guau se borrará en unos 5 minutos"
contiene "Estado: Borrado programado" "$TMP/pag.html" 'id="baja-estado">Borrado programado'
contiene "...con botón Cancelar el borrado" "$TMP/pag.html" ">Cancelar el borrado</button>"
contiene "...y la página se recarga sola" "$TMP/pag.html" 'data-recargar="10"'
check "Copia local: programada" "programada|guau|guau.tukanec.com" "$(sql "SELECT estado || '|' || carpeta || '|' || hostname FROM bajas WHERE instancia_id='guau'")"
check "Auditoría: baja_programada" "1" "$(audit_n baja_programada)"
R=$(post_form "$TMP/pag.html" "boton:Cancelar el borrado")
check "Cancelar el borrado" "303" "${R%% *}"
check "...el programa recibió cancelar_baja" "cancelada|guau" "$(cut -d'|' -f1,2 "$TMP/mock_guau/bajas.log" | tail -1)"
get "r=programa&id=guau" > /dev/null
contiene "...aviso: cancelado" "$TMP/pag.html" "Borrado cancelado"
contiene "...vuelve a salir el formulario" "$TMP/pag.html" 'id="form-baja"'
check "Auditoría: baja_cancelada" "1" "$(audit_n baja_cancelada)"
# Cloudflare en Ajustes (token cifrado)
get "r=ajustes" > /dev/null
contiene "Ajustes: sección Cloudflare" "$TMP/pag.html" 'id="cloudflare"'
R=$(post ajustes --data-urlencode "accion=cloudflare" --data-urlencode "cf_token=corto" --data-urlencode "cf_cuenta=$CF_CUENTA" --data-urlencode "cf_tunel=$CF_TUNEL")
get "r=ajustes" > /dev/null
contiene "Token raro: no se guarda" "$TMP/pag.html" "el token no parece de Cloudflare"
R=$(post ajustes --data-urlencode "accion=cloudflare" --data-urlencode "cf_token=$CF_TOKEN" --data-urlencode "cf_cuenta=$CF_CUENTA" --data-urlencode "cf_tunel=$CF_TUNEL")
get "r=ajustes" > /dev/null
contiene "Cloudflare guardado" "$TMP/pag.html" "Cloudflare guardado"
no_contiene "...el token no se vuelve a mostrar" "$TMP/pag.html" "$CF_TOKEN"
check "...el token no está en claro en la base" "0" "$(sql "SELECT count(*) FROM ajustes WHERE valor LIKE '%$CF_TOKEN%'")"
check "...ni en la auditoría" "0" "$(sql "SELECT count(*) FROM auditoria WHERE detalle LIKE '%$CF_TOKEN%'")"
R=$(post ajustes --data-urlencode "accion=probar_cloudflare")
get "r=ajustes" > /dev/null
contiene "Probar Cloudflare: lee el túnel" "$TMP/pag.html" "túnel «servidorqf»"
get "r=programa&id=guau" > /dev/null
contiene "Con Cloudflare: dice que quitará la dirección sola" "$TMP/pag.html" "el panel quita guau.tukanec.com de Cloudflare"
cp "$TMP/pag.html" "$TMP/form_baja.html"
R=$(post_form "$TMP/form_baja.html" "#form-baja" "confirmar=guau" "codigo=$(codigo "$SECRETO_D" "$TMP/paso_d")")
check "Programado otra vez" "programada" "$(sql "SELECT estado FROM bajas WHERE instancia_id='guau'")"
# En curso (lo que haría la tarea de root del servidor)
echo "en_curso" > "$TMP/mock_guau/baja_estado"
get "r=programa&id=guau" > /dev/null
R=$(post_form "$TMP/pag.html" "boton:Revisar ahora")
get "r=programa&id=guau" > /dev/null
contiene "Revisar: Borrado en curso" "$TMP/pag.html" 'id="baja-estado">Borrado en curso'
no_contiene "...ya no se puede cancelar" "$TMP/pag.html" ">Cancelar el borrado</button>"
# Borrado: el programa deja de contestar después de la hora
parar guau
sql "UPDATE bajas SET borrar_desde = strftime('%s','now') - 5 WHERE instancia_id='guau'" > /dev/null
R=$(post_form "$TMP/pag.html" "boton:Revisar ahora")
get "r=programa&id=guau" > /dev/null
contiene "Primera vez sin respuesta: todavía en curso (se confirma)" "$TMP/pag.html" "se confirma en la próxima revisión"
R=$(post_form "$TMP/pag.html" "boton:Revisar ahora")
get "r=programa&id=guau" > /dev/null
contiene "Segunda vez: Borrado terminado" "$TMP/pag.html" 'id="baja-estado">Borrado terminado'
contiene "...con la copia final" "$TMP/pag.html" "/var/backups/borrados/"
contiene "...Cloudflare: quitado del túnel" "$TMP/pag.html" "Se quitó guau.tukanec.com del túnel (2 rutas)"
contiene "...Cloudflare: DNS borrado" "$TMP/pag.html" "Se borró el DNS de guau.tukanec.com"
no_contiene "...sin recordatorio (ya se hizo)" "$TMP/pag.html" 'id="baja-recordatorio"'
check "Cloudflare de mentira: guau ya no está en el túnel" "0" "$(cf_hosts | grep -c '^guau.tukanec.com$')"
check "...las demás rutas siguen (panel, dogssy, huellitas)" "panel.tukanec.com dogssy.tukanec.com huellitas.tukanec.com" "$(cf_hosts | tr '\n' ' ' | sed 's/ $//')"
check "...DNS: solo se borró el de guau" "panel.tukanec.com dogssy.tukanec.com huellitas.tukanec.com ajeno.tukanec.com" "$(cf_dns | tr '\n' ' ' | sed 's/ $//')"
check "...un solo PUT y un solo DELETE" "1 1" "$(grep -c '^PUT ' "$TMP/mock_cf/cf.log") $(grep -c '^DELETE ' "$TMP/mock_cf/cf.log")"
check "...siempre con el token (ningún pedido a la API sin él)" "0" "$(grep -c '^[A-Z]* /client/v4.*token malo' "$TMP/mock_cf/cf.log")"
check "Auditoría: terminado y Cloudflare" "1 1" "$(sql "SELECT count(*) FROM auditoria WHERE accion='baja_estado' AND detalle LIKE 'Terminado%' AND instancia_id='guau'") $(sql "SELECT count(*) FROM auditoria WHERE accion='cloudflare' AND detalle LIKE 'Listo:%'")"
N_AUD=$(sql "SELECT count(*) FROM auditoria WHERE instancia_id='guau'")
R=$(post_form "$TMP/pag.html" "boton:Quitar también del panel")
check "Quitar también del panel" "303 /?r=programas" "$(echo "$R" | sed "s#$BASE##")"
check "...ya no está en el panel" "0" "$(sql "SELECT count(*) FROM instancias WHERE id='guau'")"
check "...la auditoría se conserva" "1" "$(sql "SELECT count(*) >= $N_AUD FROM auditoria WHERE instancia_id='guau'")"
check "...instancia_eliminada: después de borrar su instalación" "1" "$(sql "SELECT count(*) FROM auditoria WHERE accion='instancia_eliminada' AND detalle LIKE '%después de borrar su instalación%'")"
get "r=auditoria&grupo=programas" > /dev/null
contiene "Auditoría (Programas): textos del borrado" "$TMP/pag.html" "Programó el borrado de una instalación"
# Sin Cloudflare: el recordatorio
get "r=ajustes" > /dev/null
post ajustes --data-urlencode "accion=borrar_cloudflare" > /dev/null
check "Borrar el token de Cloudflare" "" "$(sql "SELECT valor FROM ajustes WHERE clave='cf_token_cif'")"
agregar tuguarderia miau "TuGuardería Miau" "http://127.0.0.1:8269" "miau"
SEC_MI=$(echo "$CMD" | awk '{print $6}')
mock miau 8269 miau "$SEC_MI" tuguarderia MOCK_BAJA=1 MOCK_CLIENTE=Miau MOCK_DOMINIO=miau.tukanec.com MOCK_BAJA_ESPERA=0
probar miau
cp "$TMP/pag.html" "$TMP/form_baja.html"
R=$(post_form "$TMP/form_baja.html" "#form-baja" "confirmar=miau" "codigo=$(codigo "$SECRETO_D" "$TMP/paso_d")")
get "r=programa&id=miau" > /dev/null
contiene "Sin espera: se borrará en menos de 1 minuto" "$TMP/pag.html" "en menos de 1 minuto"
parar miau
R=$(post_form "$TMP/pag.html" "boton:Revisar ahora"); R=$(post_form "$TMP/pag.html" "boton:Revisar ahora")
get "r=programa&id=miau" > /dev/null
contiene "Miau: Borrado terminado" "$TMP/pag.html" 'id="baja-estado">Borrado terminado'
contiene "Sin Cloudflare: recordatorio claro de quitar la dirección" "$TMP/pag.html" "Falta quitar miau.tukanec.com en Cloudflare"
contiene "...con el enlace a Ajustes" "$TMP/pag.html" 'href="/?r=ajustes#cloudflare"'
check "...y no se pidió nada a Cloudflare" "0" "$(grep -c 'miau' "$TMP/mock_cf/cf.log")"
check "Mientras se borraba no hubo aviso de caída por Telegram" "0" "$(tg | grep -c 'Miau\|Guau')"
R=$(post_form "$TMP/pag.html" "boton:Quitar también del panel")
get "r=programas" > /dev/null
contiene "Al quitarla recuerda Cloudflare" "$TMP/pag.html" "No olvides quitar miau.tukanec.com en Cloudflare"
if grep -qiE "fatal|warning|deprecated|notice|Tukan Central:" "$TMP/central.log"; then
  FALLAS=$((FALLAS+1)); echo "  FALLA Avisos o errores de PHP en el panel (borrar):"; grep -iE "fatal|warning|deprecated|notice|Tukan Central:" "$TMP/central.log" | sort | uniq -c | head -20
else OK=$((OK+1)); echo "  OK    Sin avisos ni errores de PHP en el panel (borrar)"; fi
if grep -qiE "fatal|warning|deprecated|notice" "$TMP/cloudflare.log" "$TMP"/mock_guau.log "$TMP"/mock_miau.log; then
  FALLAS=$((FALLAS+1)); echo "  FALLA Avisos de PHP en los programas de prueba o en Cloudflare de mentira"; grep -iE "fatal|warning|deprecated|notice" "$TMP/cloudflare.log" "$TMP"/mock_guau.log "$TMP"/mock_miau.log | head
else OK=$((OK+1)); echo "  OK    Sin avisos de PHP en Guau, Miau ni en Cloudflare de mentira"; fi

# =====================================================================
titulo "36. Pausar y borrar clientes (Anexo A): lista, ficha, papelera con Restaurar, 2FA y soporte"
JAR="$JAR_D2"; IP="198.51.100.11"
check "Abre Clientes" "200" "$(get 'r=clientes')"
contiene "Filtro Papelera (vacía)" "$TMP/pag.html" 'Papelera <b>0</b>'
contiene "Fila: botón Pausar (abre el formulario del programa)" "$TMP/pag.html" 'href="/?r=accion&amp;i=tuvocalia&amp;a=pausar&amp;cliente=7&amp;'
contiene "Fila: botón Borrar" "$TMP/pag.html" 'href="/?r=accion&amp;i=tuvocalia&amp;a=borrar_cliente&amp;cliente=7&amp;'
contiene "Fila de un suspendido: Reactivar" "$TMP/pag.html" 'a=reactivar&amp;cliente=8&amp;'
contiene "TuGuardería (una sola empresa): Pausar toda la instalación" "$TMP/pag.html" 'href="/?r=accion&amp;i=dogssy&amp;a=pausar&amp;cliente=unico&amp;'
no_contiene "...y sin Borrar si no sabe borrarse (dar_de_baja)" "$TMP/pag.html" 'id=dogssy#baja'
no_contiene "TuGuardería: nunca borrar_cliente (se borra la instalación)" "$TMP/pag.html" 'a=borrar_cliente&amp;cliente=unico'
# Pausar (tipo + motivo)
get "r=accion&i=tuvocalia&a=pausar&cliente=7&volver=/?r=clientes" > /dev/null
contiene "Pausar: elegir el tipo" "$TMP/pag.html" 'name="campo\[tipo\]"'
contiene "...con las 5 opciones (uso indebido)" "$TMP/pag.html" 'value="abuso"'
contiene "...y el motivo" "$TMP/pag.html" 'name="campo\[motivo\]"'
contiene "...en rojo: pide marcar que está seguro" "$TMP/pag.html" 'name="seguro"'
contiene "...con el nombre del cliente" "$TMP/pag.html" 'Liga Carcelén · TuVocalía'
cp "$TMP/pag.html" "$TMP/form_pausa.html"
R=$(post_form "$TMP/form_pausa.html" "#form-accion" "campo[tipo]=falta_pago" "campo[motivo]=ab" "seguro=1")
check "Motivo de 2 letras: vuelve con el error" "422" "${R%% *}"
check "...el programa no recibió nada" "0" "$(grep -c '|pausar|' "$TMP/mock_tuvocalia/ejecutar.log")"
R=$(post_form "$TMP/form_pausa.html" "#form-accion" "campo[tipo]=falta_pago" "campo[motivo]=No pagó septiembre ni octubre" "seguro=1")
check "Pausar: listo y vuelve a Clientes" "303 /?r=clientes" "$(echo "$R" | sed "s#$BASE##")"
check "...en el programa: tipo y motivo" "falta_pago|No pagó septiembre ni octubre" "$(mockdb tuvocalia "\$d['clientes']['7']['pausa']['tipo'] . '|' . \$d['clientes']['7']['pausa']['motivo']")"
check "...con el correo de quien lo hizo (auditoría del programa)" "1" "$(grep -c 'dimitryfierro@gmail.com|7|Pausó la cuenta (Falta de pago)' "$TMP/mock_tuvocalia/bitacora.log")"
check "Auditoría del panel: Pausar · Liga Carcelén · Falta de pago" "1" "$(sql "SELECT count(*) FROM auditoria WHERE accion='ejecutar' AND detalle LIKE 'Pausar · Liga Carcelén · Falta de pago → listo%'")"
get "r=clientes" > /dev/null
contiene "Lista: etiqueta Pausado con el tipo" "$TMP/pag.html" '<span class="pausa-motivo">Falta de pago</span>'
contiene "...y ahora Reactivar" "$TMP/pag.html" 'a=reactivar&amp;cliente=7&amp;'
get "r=cliente&i=tuvocalia&id=7" > /dev/null
contiene "Ficha: estado Pausado" "$TMP/pag.html" 'class="ec-estado">Pausado'
contiene "...con el tipo y el motivo" "$TMP/pag.html" '<strong>Falta de pago</strong>: No pagó septiembre ni octubre'
contiene "Ficha: historial Pausas y borrados" "$TMP/pag.html" 'Pausas y borrados'
contiene "...quién y por qué" "$TMP/pag.html" 'Pausó · Falta de pago'
contiene "...con el motivo" "$TMP/pag.html" '«No pagó septiembre ni octubre»'
contiene "...y quién (usuario del panel)" "$TMP/pag.html" '· Dimitry Fierro</span>'
contiene "Ficha: botón Reactivar (un toque)" "$TMP/pag.html" ">Reactivar</button>"
# Soporte: pausa, pero no reactiva ni borra
JAR="$TMP/jar_soporte2"; IP="198.51.100.21"
get "r=clientes" > /dev/null
contiene "Soporte: ve Pausar" "$TMP/pag.html" 'a=pausar&amp;cliente=5&amp;'
no_contiene "Soporte: no ve Borrar" "$TMP/pag.html" 'a=borrar_cliente'
no_contiene "Soporte: no ve Reactivar (devolver el servicio lo decide el dueño)" "$TMP/pag.html" 'a=reactivar&amp;'
get "r=accion&i=tuvocalia&a=pausar&cliente=5&volver=/?r=clientes" > /dev/null
R=$(post_form "$TMP/pag.html" "#form-accion" "campo[tipo]=revision" "campo[motivo]=Revisar pagos con tarjeta" "seguro=1")
check "Soporte: pausar sí se puede" "revision" "$(mockdb tuvocalia "\$d['clientes']['5']['pausa']['tipo'] ?? ''")"
R=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' -H "CF-Connecting-IP: $IP" "$BASE/?r=accion&i=tuvocalia&a=borrar_cliente&cliente=9&volver=/?r=clientes")
check "Soporte: abrir Borrar -> no (vuelve con aviso)" "303 /?r=clientes" "$(echo "$R" | sed "s#$BASE##")"
check "...anotado como permiso negado" "1" "$(sql "SELECT count(*) FROM auditoria WHERE accion='permiso_negado' AND email='soporte@tukan.ec' AND detalle LIKE '%borrar_cliente%'")"
JAR="$JAR_D2"; IP="198.51.100.11"
get "r=cliente&i=tuvocalia&id=5" > /dev/null
contiene "Revisión: el panel avisa que el cliente ve un texto general" "$TMP/pag.html" "El cliente ve un texto general"
R=$(post_form "$TMP/pag.html" "boton:Reactivar")
check "Dueño: reactivar lo que pausó soporte" "null" "$(mockdb tuvocalia "json_encode(\$d['clientes']['5']['pausa'] ?? null)")"
get "r=cliente&i=tuvocalia&id=5" > /dev/null
contiene "...historial: Reactivó" "$TMP/pag.html" 'Reactivó'
# Borrar: nombre exacto + motivo + código de 2 pasos
get "r=accion&i=tuvocalia&a=borrar_cliente&cliente=9&volver=/?r=clientes" > /dev/null
contiene "Borrar: escribir el nombre exacto" "$TMP/pag.html" 'name="campo\[confirmar\]"'
no_contiene "...que no viene escrito" "$TMP/pag.html" 'name="campo\[confirmar\]" value="Liga Solanda"'
contiene "...el motivo" "$TMP/pag.html" 'name="campo\[motivo\]"'
contiene "...el código de 2 pasos" "$TMP/pag.html" 'name="codigo"'
contiene "...y marcar que está seguro" "$TMP/pag.html" 'name="seguro"'
cp "$TMP/pag.html" "$TMP/form_borrar.html"
N_EJ=$(grep -c '|borrar_cliente|' "$TMP/mock_tuvocalia/ejecutar.log")
R=$(post_form "$TMP/form_borrar.html" "#form-accion" "campo[confirmar]=liga solanda" "campo[motivo]=Cerró la liga" "seguro=1" "codigo=$(codigo "$SECRETO_D" "$TMP/paso_d")")
check "Nombre con otras mayúsculas: no se borra" "422" "${R%% *}"
contiene "...dice cuál es el nombre exacto" "$TMP/post.html" 'No coincide: escribe el nombre exacto («Liga Solanda»)'
R=$(post_form "$TMP/form_borrar.html" "#form-accion" "campo[confirmar]=Liga Solanda" "campo[motivo]=Cerró la liga" "seguro=1" "codigo=000000")
check "Código de 2 pasos malo: no se borra" "422" "${R%% *}"
check "...el programa no recibió ningún borrar" "$N_EJ" "$(grep -c '|borrar_cliente|' "$TMP/mock_tuvocalia/ejecutar.log")"
check "...2fa_fallido anotado" "1" "$(sql "SELECT count(*) FROM auditoria WHERE accion='2fa_fallido' AND detalle LIKE '%borrar_cliente%'")"
R=$(post_form "$TMP/form_borrar.html" "#form-accion" "campo[confirmar]=Liga Solanda" "campo[motivo]=Cerró la liga" "seguro=1" "codigo=$(codigo "$SECRETO_D" "$TMP/paso_d")")
check "Borrar: listo y vuelve a Clientes" "303 /?r=clientes" "$(echo "$R" | sed "s#$BASE##")"
check "...en el programa: en la papelera 30 días" "$(date -d '+30 days' +%Y-%m-%d)" "$(mockdb tuvocalia "\$d['clientes']['9']['papelera']['hasta'] ?? ''")"
get "r=clientes" > /dev/null
contiene "Aviso: cuándo se borra para siempre" "$TMP/pag.html" "Se borra para siempre el $(date -d '+30 days' +%d/%m/%Y)"
contiene "...y dónde quedó la copia" "$TMP/pag.html" 'Copia: /var/www/tuvocalia/respaldos/borrados/9_'
no_contiene "Ya no sale en la lista normal" "$TMP/pag.html" 'data-cliente="tuvocalia|9"'
contiene "Papelera con 1" "$TMP/pag.html" 'Papelera <b>1</b>'
check "Auditoría: Borrar cliente · Liga Solanda" "1" "$(sql "SELECT count(*) FROM auditoria WHERE accion='ejecutar' AND detalle LIKE 'Borrar cliente · Liga Solanda → listo%'")"
get "r=clientes&estado=papelera" > /dev/null
check "Filtro Papelera: solo el borrado" "1" "$(grep -c 'data-cliente=' "$TMP/pag.html")"
contiene "...Liga Solanda" "$TMP/pag.html" 'data-cliente="tuvocalia|9"'
contiene "...cuándo se borra para siempre" "$TMP/pag.html" "$(date -d '+30 days' +%d/%m/%Y) · faltan 30 días"
contiene "...el motivo" "$TMP/pag.html" 'Cerró la liga'
contiene "...con Restaurar" "$TMP/pag.html" 'a=restaurar_cliente&amp;cliente=9&amp;'
contiene "...y explica la papelera" "$TMP/pag.html" 'Cada programa los guarda <strong>30 días</strong>'
get "r=cliente&i=tuvocalia&id=9" > /dev/null
contiene "Ficha: Está en la papelera" "$TMP/pag.html" 'Está en la papelera'
contiene "...con la copia" "$TMP/pag.html" 'Copia de sus datos: /var/www/tuvocalia/respaldos/borrados/9_'
contiene "...historial: Borró (a la papelera)" "$TMP/pag.html" 'Borró (a la papelera)'
contiene "...con el motivo" "$TMP/pag.html" '«Cerró la liga»'
contiene "...botón Restaurar" "$TMP/pag.html" '>Restaurar</button>'
no_contiene "...y nada más (no se cobra a uno borrado)" "$TMP/pag.html" 'a=registrar_pago'
# Restaurar
R=$(post_form "$TMP/pag.html" "boton:Restaurar")
check "Restaurar: vuelve en el programa" "null" "$(mockdb tuvocalia "json_encode(\$d['clientes']['9']['papelera'] ?? null)")"
get "r=clientes" > /dev/null
contiene "...y en la lista normal" "$TMP/pag.html" 'data-cliente="tuvocalia|9"'
contiene "...papelera vacía" "$TMP/pag.html" 'Papelera <b>0</b>'
get "r=cliente&i=tuvocalia&id=9" > /dev/null
contiene "...historial: Restauró de la papelera" "$TMP/pag.html" 'Restauró de la papelera'
# Uno que se queda en la papelera (para las capturas)
get "r=accion&i=tuvocalia&a=borrar_cliente&cliente=2&volver=/?r=clientes" > /dev/null
R=$(post_form "$TMP/pag.html" "#form-accion" "campo[confirmar]=Escuela Fútbol Kids" "campo[motivo]=Pidió darse de baja por correo" "seguro=1" "codigo=$(codigo "$SECRETO_D" "$TMP/paso_d")")
check "Borrar otro (queda en la papelera)" "303" "${R%% *}"
# TuGuardería: pausar toda la instalación; Borrar lleva a "Borrar esta instalación"
get "r=accion&i=dogssy&a=pausar&cliente=unico&volver=/?r=clientes" > /dev/null
R=$(post_form "$TMP/pag.html" "#form-accion" "campo[tipo]=pedido_cliente" "campo[motivo]=Vacaciones del negocio hasta el lunes" "seguro=1")
check "TuGuardería: pausada" "pedido_cliente" "$(mockdb dogssy "\$d['unico']['pausa']['tipo'] ?? ''")"
get "r=cliente&i=dogssy&id=unico" > /dev/null
contiene "...ficha: Pausado con el motivo" "$TMP/pag.html" '<strong>A pedido del cliente</strong>: Vacaciones del negocio hasta el lunes'
R=$(post_form "$TMP/pag.html" "boton:Reactivar")
check "...reactivada" "null" "$(mockdb dogssy "json_encode(\$d['unico']['pausa'] ?? null)")"
agregar tuguarderia patitas "TuGuardería Patitas" "http://127.0.0.1:8267" "patitas"
SEC_PA=$(echo "$CMD" | awk '{print $6}')
mock patitas 8267 patitas "$SEC_PA" tuguarderia MOCK_BAJA=1 MOCK_CLIENTE=Patitas
probar patitas
get "r=clientes" > /dev/null; post actualizar_datos --data-urlencode "que=clientes" --data-urlencode "volver=/?r=clientes" > /dev/null
get "r=clientes" > /dev/null
contiene "TuGuardería que sabe borrarse: Borrar lleva a Borrar esta instalación" "$TMP/pag.html" 'href="/?r=programa&amp;id=patitas#baja"'
# Programa sin el Anexo A: no salen los botones
mock tuevento 8262 tuevento "$SEC_TE" tuevento MOCK_SIN_PAUSA=1
probar tuevento
get "r=clientes" > /dev/null
no_contiene "Programa sin pausar/borrar: no salen esos botones" "$TMP/pag.html" 'i=tuevento&amp;a=pausar'
no_contiene "...ni Borrar" "$TMP/pag.html" 'i=tuevento&amp;a=borrar_cliente'
R=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' -H "CF-Connecting-IP: $IP" "$BASE/?r=accion&i=tuevento&a=pausar&cliente=3&volver=/?r=clientes")
check "...y pedirlo a mano no sirve" "303 /?r=clientes" "$(echo "$R" | sed "s#$BASE##")"
get "r=programa&id=tuevento" > /dev/null
contiene "Ficha del programa: Pausar clientes con ✖" "$TMP/pag.html" '<li class="no"><svg[^>]*>.*</svg> Pausar clientes'
mock tuevento 8262 tuevento "$SEC_TE" tuevento
probar tuevento
if grep -qiE "fatal|warning|deprecated|notice|Tukan Central:" "$TMP/central.log"; then
  FALLAS=$((FALLAS+1)); echo "  FALLA Avisos o errores de PHP en el panel (pausar/borrar):"; grep -iE "fatal|warning|deprecated|notice|Tukan Central:" "$TMP/central.log" | sort | uniq -c | head -20
else OK=$((OK+1)); echo "  OK    Sin avisos ni errores de PHP en el panel (pausar/borrar)"; fi
if grep -qiE "fatal|warning|deprecated|notice" "$TMP"/mock_tuvocalia.log "$TMP"/mock_dogssy.log "$TMP"/mock_patitas.log "$TMP"/mock_tuevento.log; then
  FALLAS=$((FALLAS+1)); echo "  FALLA Avisos de PHP en los programas de prueba (pausar/borrar)"; grep -iE "fatal|warning|deprecated|notice" "$TMP"/mock_*.log | head
else OK=$((OK+1)); echo "  OK    Sin avisos de PHP en los programas de prueba (pausar/borrar)"; fi

# =====================================================================
if [ -z "${SIN_CAPTURAS:-}" ] && python3 -c "import playwright" 2>/dev/null; then
  titulo "37. Capturas de pantalla (Playwright, Chromium)"
  TUKAN_ADMIN_NOMBRE="Dimitry Fierro" TUKAN_ADMIN_EMAIL="capturas@tukan.ec" TUKAN_ADMIN_CLAVE="claveCapturas2026" php bin/crear_admin.php > /dev/null
  parar dogssy   # una caida para que se vea el estado "sin conexion"
  get "r=inicio" > /dev/null; post refrescar > /dev/null
  mock dogssy 8264 dogssy "$SEC_DG" tuguarderia   # vuelve, pero la tarjeta muestra la caida hasta actualizar
  mkdir -p tests/capturas
  if PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers BASE="$BASE" python3 tests/capturas.py tests/capturas > "$TMP/capturas.txt" 2>&1; then
    OK=$((OK+1)); echo "  OK    Capturas sin errores de JavaScript: $(tr '\n' ' ' < "$TMP/capturas.txt")"
  else
    FALLAS=$((FALLAS+1)); echo "  FALLA Capturas:"; cat "$TMP/capturas.txt"
  fi
fi

echo
echo "----------------------------------------"
echo "Pantallas — correctas: $OK  ·  Fallas: $FALLAS"
[ "$FALLAS" -eq 0 ]
