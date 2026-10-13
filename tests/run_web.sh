#!/usr/bin/env bash
# Pruebas de punta a punta del Panel Tukan Central (entorno simulado, sin internet).
# Levanta el panel con "php -S" y varios PROGRAMAS DE PRUEBA (tests/mock/tukan_central.php) que cumplen el protocolo.
# Uso: bash tests/run_web.sh          (SIN_CAPTURAS=1 para no sacar las capturas con Playwright)
set -u
cd "$(dirname "$0")/.." || exit 1
TMP=$(mktemp -d)
export TZ=America/Guayaquil
PC=8301                      # panel central
BASE="http://127.0.0.1:$PC"
TG=8319                      # Telegram de mentira
export TUKAN_CENTRAL_DATOS="$TMP/datos"
TUKAN_CENTRAL_KEY=$(php -r "echo bin2hex(random_bytes(32));"); export TUKAN_CENTRAL_KEY
export TUKAN_CENTRAL_URL="$BASE"
export TUKAN_CENTRAL_ENV=/no/existe
export TELEGRAM_BOT_TOKEN=prueba123 TELEGRAM_CHAT_ID=999 TELEGRAM_API_BASE="http://127.0.0.1:$TG"
export TUKAN_REVISAR_ESPERA=0
OK=0; FALLAS=0
PIDS=()

limpiar() { for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null; done; pkill -f "127.0.0.1:83[0-9][0-9]" 2>/dev/null; }
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
R=$(post programa_nuevo --data-urlencode "producto=tuevento" --data-urlencode "id=tuevento" --data-urlencode "nombre=TuEvento" --data-urlencode "url=http://127.0.0.1:8311")
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
R=$(post programa_nuevo --data-urlencode "producto=tuevento" --data-urlencode "id=Mal ID" --data-urlencode "url=http://127.0.0.1:8311")
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
agregar tusalon tusalon "TuSalón" "http://127.0.0.1:8312"
check "Comando exacto de TuSalon" "sudo bash /var/www/tusalon/deploy/conectar_central.sh tusalon" "$(echo "$CMD" | awk '{print $1,$2,$3,$4}')"
SEC_TS=$(echo "$CMD" | awk '{print $5}')
agregar tuguarderia dogssy "TuGuardería Dogssy" "http://127.0.0.1:8313" ""
check "Comando exacto de TuGuarderia (CARPETA ID SECRETO URL)" "sudo bash /home/qf/github/tuguarderia/conectar_central.sh dogssy dogssy" "$(echo "$CMD" | awk '{print $1,$2,$3,$4,$5}')"
SEC_DG=$(echo "$CMD" | awk '{print $6}')
check "TuGuarderia: URL del panel al final" "$BASE" "$(echo "$CMD" | awk '{print $7}')"
agregar tuvocalia tuvocalia "TuVocalía" "http://127.0.0.1:8314"
check "Comando exacto de TuVocalia" "sudo bash /var/www/ligas/vps/conectar_central.sh tuvocalia" "$(echo "$CMD" | awk '{print $1,$2,$3,$4}')"
SEC_TV=$(echo "$CMD" | awk '{print $5}')
agregar tuacademia tuacademia "TuAcademia" "http://127.0.0.1:8315"
check "Comando exacto de TuAcademia" "sudo bash /var/www/tuacademia/vps/conectar_central.sh tuacademia" "$(echo "$CMD" | awk '{print $1,$2,$3,$4}')"
curl -s -D "$TMP/cab4.txt" -o /dev/null -b "$JAR" "$BASE/?r=inicio"
contiene "CSP form-action permite ir a los programas" "$TMP/cab4.txt" "form-action 'self' http://127.0.0.1:8311"
curl -s -D "$TMP/cab5.txt" -o /dev/null "$BASE/?r=login"
no_contiene "Sin sesion, la CSP no revela las direcciones de los programas" "$TMP/cab5.txt" "127.0.0.1:8311"

# =====================================================================
titulo "4. Probar conexion: cada problema explicado en palabras simples"
probar tuevento
contiene "Programa apagado -> sin conexion" "$TMP/pag.html" "Sin conexión: el servidor del programa no contesta"
mock tuevento 8311 "" "" tuevento
probar tuevento
contiene "Responde sin conector (404) -> programa sin conectar" "$TMP/pag.html" "Programa sin conectar"
mock tuevento 8311 tuevento "$SEC_TE" tuevento
probar tuevento
contiene "Con el secreto -> conexion correcta" "$TMP/pag.html" "Conexión correcta: TuEvento versión 75df6b3"
contiene "Estado en linea en la ficha" "$TMP/pag.html" 'estado on'
check "Auditoria: probar conexion" "1" "$(sql "SELECT count(*) FROM auditoria WHERE accion='probar' AND detalle LIKE 'OK%'")"
mock tusalon 8312 tusalon "$(printf 'f%.0s' $(seq 1 64))" tusalon
probar tusalon
contiene "Secreto equivocado -> secreto incorrecto" "$TMP/pag.html" "Secreto incorrecto"
mock tusalon 8312 otro-id "$SEC_TS" tusalon
probar tusalon
contiene "ID distinto en el programa -> el ID no coincide" "$TMP/pag.html" "El ID no coincide"
mock tusalon 8312 tusalon "$SEC_TS" tusalon
probar tusalon
contiene "TuSalon conectado" "$TMP/pag.html" "Conexión correcta"
mock tuvocalia 8314 tuvocalia "$SEC_TV" tuvocalia MOCK_DESFASE=300
probar tuvocalia
contiene "Reloj del programa corrido 5 min -> reloj desfasado" "$TMP/pag.html" "Reloj desfasado"
mock tuvocalia 8314 tuvocalia "$SEC_TV" tuvocalia MOCK_ROTO=1
probar tuvocalia
contiene "JSON danado -> formato del protocolo" "$TMP/pag.html" "no tienen el formato del Protocolo"
mock tuvocalia 8314 tuvocalia "$SEC_TV" tuvocalia MOCK_LIMITE=1
curl -s -o /dev/null "http://127.0.0.1:8314/tukan_central.php?accion=estado&t=x"
probar tuvocalia
contiene "Limite de pedidos (429)" "$TMP/pag.html" "Demasiados pedidos"
mock tuvocalia 8314 tuvocalia "$SEC_TV" tuvocalia
mock dogssy 8313 dogssy "$SEC_DG" tuguarderia MOCK_CLIENTE=Dogssy
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
check "Total de negocios 12+23+9 = 44" "44" "$(grep -o 'data-total="negocios">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"
check "En prueba 4+7+2 = 13" "13" "$(grep -o 'data-total="prueba">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"
check "Activos 6+14+6 = 26" "26" "$(grep -o 'data-total="activos">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"
check "Vencidos 1+1+1 = 3" "3" "$(grep -o 'data-total="vencidos">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"
check "Nuevos 30 dias 3+5+1 = 9" "9" "$(grep -o 'data-total="nuevos_30d">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"
check "Ingresos del mes 414+690,50+180" '$1.284,50' "$(grep -o 'data-total="ingresos_mes">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"
contiene "Variacion vs mes anterior (1284,5 / 1131 = +13,6 %)" "$TMP/pag.html" "13,6 %"
contiene "Para atender: prueba de TuEvento" "$TMP/pag.html" "Quinta Los Alamos"
contiene "Para atender: prueba de TuSalon" "$TMP/pag.html" "Barbería El Bigote"
contiene "Para atender: pago vencido" "$TMP/pag.html" "Salón Imperial"
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
check "Va a tukan_central.php?accion=sso&t=" "http://127.0.0.1:8311/tukan_central.php?accion=sso&t=" "${SSO%%t=*}t="
TOKEN=${SSO##*t=}
PAYLOAD=$(php -r 'echo base64_decode(strtr(explode(".", $argv[1])[0], "-_", "+/"));' "$TOKEN")
check "Token: email del admin" "dimitryfierro@gmail.com" "$(echo "$PAYLOAD" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["email"];')"
check "Token: nombre" "Dimitry Fierro" "$(echo "$PAYLOAD" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["nombre"];')"
check "Token: exp - iat = 60" "60" "$(echo "$PAYLOAD" | php -r '$p=json_decode(stream_get_contents(STDIN), true); echo $p["exp"]-$p["iat"];')"
check "Token: orden de claves del protocolo" "v,tipo,inst,email,nombre,iat,exp,nonce" "$(echo "$PAYLOAD" | php -r 'echo implode(",", array_keys(json_decode(stream_get_contents(STDIN), true)));')"
R2=$(curl -s -L -c "$TMP/jar_prog" -b "$TMP/jar_prog" -o "$TMP/prog.html" -w '%{http_code} %{url_effective}' "$SSO")
check "El programa acepta el token y abre su panel" "200 http://127.0.0.1:8311/admin" "$R2"
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
check "Token firmado con otro secreto -> 401" "401" "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8311/tukan_central.php?accion=sso&t=$FALSO")"
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
check "Los totales conservan el ultimo dato" "44" "$(grep -o 'data-total="negocios">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"
mock tuvocalia 8314 tuvocalia "$SEC_TV" tuvocalia MOCK_LENTO=8
get "r=inicio" > /dev/null
INI=$(date +%s%N); post refrescar > /dev/null; FIN=$(date +%s%N)
MS=$(( (FIN - INI) / 1000000 ))
[ "$MS" -ge 4500 ] && [ "$MS" -lt 7500 ] && check "Consulta corta a los 5 s (tardo ${MS} ms, en paralelo)" 1 1 || check "Consulta corta a los 5 s" "4500-7500 ms" "$MS ms"
get "r=inicio" > /dev/null
contiene "Programa lento -> no respondio a tiempo" "$TMP/pag.html" "no respondió a tiempo"
T=$(curl -s -b "$JAR" -o /dev/null -w '%{time_total}' "$BASE/?r=inicio")
check "Inicio se dibuja en menos de 1 s aunque haya caidos ($T s)" "1" "$(php -r "echo $T < 1 ? 1 : 0;")"
mock tuvocalia 8314 tuvocalia "$SEC_TV" tuvocalia

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
mock dogssy 8313 dogssy "$SEC_DG" tuguarderia
echo 13 > "$TMP/mock_tuevento/total"
php bin/revisar.php > /dev/null
contiene "Telegram: Dogssy volvio" "$TMP/mock_telegram/telegram.log" "TuGuardería Dogssy volvió a estar en línea"
contiene "Telegram: negocio nuevo en TuEvento" "$TMP/mock_telegram/telegram.log" "Un negocio nuevo en TuEvento. Ahora son 13"
check "Historial de disponibilidad guardado" "1" "$(sql "SELECT count(*) > 0 FROM historial WHERE instancia_id='tuevento'")"
check "El programa recibe tokens de estado (no de sso)" "0" "$(grep -c 'estado|tipo incorrecto' "$TMP/mock_tuevento/rechazos.log" 2>/dev/null || true)"
get "r=ajustes" > /dev/null
post ajustes --data-urlencode "accion=guardar" --data-urlencode "avisar_caidas=1" > /dev/null
check "Auditoria: ajustes cambiados (avisos de negocios apagados)" "1" "$(sql "SELECT count(*) FROM auditoria WHERE accion='ajustes' AND detalle LIKE '%avisar_negocios=0%'")"
echo 14 > "$TMP/mock_tuevento/total"
php bin/revisar.php > /dev/null
no_contiene "Con el aviso apagado no avisa negocios" "$TMP/mock_telegram/telegram.log" "Ahora son 14"
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
mock tusalon 8312 tusalon "$SEC_TS2" tusalon
probar tusalon
contiene "Con el comando nuevo vuelve a conectar" "$TMP/pag.html" "Conexión correcta"
check "Auditoria: secreto cambiado" "1" "$(audit_n secreto_cambiado)"

# =====================================================================
titulo "10. Editar y quitar programas"
get "r=programa_editar&id=tuacademia" > /dev/null
R=$(post "programa_editar&id=tuacademia" --data-urlencode "producto=tuacademia" --data-urlencode "nombre=TuAcademia Norte" --data-urlencode "url=http://127.0.0.1:8315" --data-urlencode "notas=VPS")
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
if [ -z "${SIN_CAPTURAS:-}" ] && python3 -c "import playwright" 2>/dev/null; then
  titulo "18. Capturas de pantalla (Playwright, Chromium)"
  TUKAN_ADMIN_NOMBRE="Dimitry Fierro" TUKAN_ADMIN_EMAIL="capturas@tukan.ec" TUKAN_ADMIN_CLAVE="claveCapturas2026" php bin/crear_admin.php > /dev/null
  mock tuacademia 8315 tuacademia "x" tuacademia
  get "r=programa_nuevo" > /dev/null
  agregar tuacademia tuacademia "TuAcademia" "http://127.0.0.1:8315"
  mock tuacademia 8315 tuacademia "$(echo "$CMD" | awk '{print $5}')" tuacademia
  parar dogssy   # una caida para que se vea el estado "sin conexion"
  get "r=inicio" > /dev/null; post refrescar > /dev/null
  mock dogssy 8313 dogssy "$SEC_DG" tuguarderia   # vuelve, pero la tarjeta muestra la caida hasta actualizar
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
