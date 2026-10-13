#!/usr/bin/env bash
# Prueba de integracion del Panel Tukan Central con los CONECTORES REALES de los programas (no el programa de prueba).
# Levanta el panel con "php -S" y, al lado, TuEvento y TuSalon de verdad (sus repos en /home/claude/...) con una base
# PostgreSQL de prueba. Los agrega en el panel, prueba la conexion, revisa las cifras en Inicio y entra con "Entrar"
# siguiendo las redirecciones hasta el panel de super usuario del programa.
# Si falta el repo de un programa o su usuario de PostgreSQL, ese programa se OMITE (no cuenta como falla).
# Uso: bash tests/integracion.sh
# Variables: TUEVENTO_DIR, TUSALON_DIR (carpetas de los repos), PGPASSWORD (clave de los usuarios de prueba, "prueba").
# Puertos: 8240 (panel), 8241 (TuEvento), 8242 (TuSalon).
set -u
cd "$(dirname "$0")/.." || exit 1
RAIZ=$(pwd)
TMP=$(mktemp -d)
export TZ=America/Guayaquil
export PGPASSWORD=${PGPASSWORD:-prueba}
PC=8240
BASE="http://127.0.0.1:$PC"
export TUKAN_CENTRAL_DATOS="$TMP/datos"
TUKAN_CENTRAL_KEY=$(php -r "echo bin2hex(random_bytes(32));"); export TUKAN_CENTRAL_KEY
export TUKAN_CENTRAL_URL="$BASE"
export TUKAN_CENTRAL_ENV=/no/existe
unset TELEGRAM_BOT_TOKEN TELEGRAM_CHAT_ID
OK=0; FALLAS=0; OMITIDOS=0
PIDS=()
declare -A PPID_

BASES=()   # "usuario base" de prueba que se borran al terminar
limpiar() {
  for p in "${PIDS[@]}" "${PPID_[@]}"; do kill "$p" 2>/dev/null; done; pkill -f "127.0.0.1:824[0-9]" 2>/dev/null
  sleep 0.3
  for b in "${BASES[@]}"; do psql -h 127.0.0.1 -U "${b% *}" -d postgres -qc "DROP DATABASE IF EXISTS ${b#* }" > /dev/null 2>&1; done
  [ "${GUARDAR_TMP:-0}" = "1" ] && echo "Archivos de la prueba en $TMP" || rm -rf "$TMP"
}
trap limpiar EXIT

check() { if [ "$2" == "$3" ]; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — esperado [$2], salio [$3]"; fi; }
contiene() { if grep -q -- "$3" "$2"; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — no aparece [$3]"; fi; }
no_contiene() { if grep -q -- "$3" "$2"; then FALLAS=$((FALLAS+1)); echo "  FALLA $1 — aparece [$3]"; else OK=$((OK+1)); echo "  OK    $1"; fi; }
titulo() { echo; echo "== $1"; }
omitir() { OMITIDOS=$((OMITIDOS+1)); echo "  OMITIDO $1"; }
sqlc() { php -r '$d=new PDO("sqlite:".$argv[1]); foreach($d->query($argv[2])->fetchAll(PDO::FETCH_NUM) as $f) echo implode("|",$f),"\n";' "$TUKAN_CENTRAL_DATOS/central.sqlite" "$1"; }
esperar() { for _ in $(seq 1 50); do curl -s -o /dev/null "$1" && return 0; sleep 0.1; done; return 1; }

# --- navegador del panel (galleta en JAR) ---
JAR="$TMP/jar_panel"
get() { curl -s -b "$JAR" -c "$JAR" -o "$TMP/pag.html" -w "%{http_code}" "$BASE/?$1"; }
csrf() { grep -o 'name="csrf" content="[^"]*"' "$TMP/pag.html" | head -1 | sed 's/.*content="//;s/"$//'; }
post() {
  local ruta=$1; shift
  local t; t=$(csrf); [ -z "$t" ] && { get "r=login" > /dev/null; t=$(csrf); }
  curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code} %{redirect_url}" --data-urlencode "csrf=$t" "$@" "$BASE/?r=$ruta"
}
codigo() { # codigo SECRETO ARCHIVO_ULTIMO (sin repetir paso)
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
probar() { get "r=programa&id=$1" > /dev/null; post probar --data-urlencode "id=$1" > /dev/null; get "r=programa&id=$1" > /dev/null; }
agregar() { # agregar TIPO ID NOMBRE URL -> deja CMD y SEC
  get "r=programa_nuevo" > /dev/null
  post programa_nuevo --data-urlencode "producto=$1" --data-urlencode "id=$2" --data-urlencode "nombre=$3" --data-urlencode "url=$4" > /dev/null
  get "r=programa_comando&id=$2" > /dev/null
  CMD=$(grep -o 'data-texto>[^<]*' "$TMP/pag.html" | sed 's/data-texto>//')
  SEC=$(echo "$CMD" | awk '{print $5}')
}
rotar() { # rotar ID -> deja CMD y SEC nuevos
  get "r=programa&id=$1" > /dev/null
  post rotar --data-urlencode "id=$1" > /dev/null
  get "r=programa_comando&id=$1" > /dev/null
  CMD=$(grep -o 'data-texto>[^<]*' "$TMP/pag.html" | sed 's/data-texto>//')
  SEC=$(echo "$CMD" | awk '{print $5}')
}
tarjeta() { # tarjeta ID -> HTML de la tarjeta del programa en Inicio (de $TMP/pag.html)
  awk -v id="data-inst=\"$1\"" 'index($0, id) {dentro=1} dentro {print} dentro && /<\/article>/ {exit}' "$TMP/pag.html" > "$TMP/tarjeta_$1.html"
  echo "$TMP/tarjeta_$1.html"
}
dato() { grep -o "<dt title=\"$2\">$2</dt><dd>[^<]*" "$1" | sed 's/.*<dd>//' | head -1; }

# --- panel central ---
php -S "127.0.0.1:$PC" -t public > "$TMP/central.log" 2>&1 &
PIDS+=($!)
esperar "$BASE/?r=login" || { echo "No arranco el panel"; exit 1; }

titulo "1. Panel: dueno con 2FA"
TUKAN_ADMIN_NOMBRE="Dimitry Fierro" TUKAN_ADMIN_EMAIL="dimitryfierro@gmail.com" TUKAN_ADMIN_CLAVE="claveSegura2026" php bin/crear_admin.php > /dev/null
get "r=login" > /dev/null
R=$(post login --data-urlencode "email=dimitryfierro@gmail.com" --data-urlencode "clave=claveSegura2026")
check "Contrasena -> activar 2FA" "303 /?r=activar_2fa" "$(echo "$R" | sed "s#$BASE##")"
get "r=activar_2fa" > /dev/null
S2FA=$(grep -o 'data-clave>[^<]*' "$TMP/pag.html" | sed 's/data-clave>//;s/ //g')
R=$(post activar_2fa --data-urlencode "codigo=$(codigo "$S2FA" "$TMP/paso")")
check "2FA activado" "303 /?r=codigos" "$(echo "$R" | sed "s#$BASE##")"
get "r=codigos" > /dev/null; post codigos > /dev/null
check "Entra a Inicio" "200" "$(get r=inicio)"

# =====================================================================
# Programa real: integrar NOMBRE TIPO DIR PUERTO USUARIO_PG PREFIJO TABLA CAMPO_REGISTRO PLAN [CAMPOS_EXTRA...]
CONECTADOS=()
TOTAL_ESPERADO=0
integrar() {
  local nombre=$1 tipo=$2 dir=$3 puerto=$4 upg=$5 pre=$6 tabla=$7 campo=$8 plan=$9; shift 9
  local pbase="http://127.0.0.1:$puerto" db="tukan_central_integ_$tipo" pjar="$TMP/jar_$tipo"
  titulo "$nombre (conector real en $dir)"
  if [ ! -f "$dir/public/tukan_central.php" ] || [ ! -f "$dir/db/schema.sql" ]; then omitir "$nombre: no esta el repo con el conector"; return; fi
  if ! psql -h 127.0.0.1 -U "$upg" -d postgres -qAtc "SELECT 1" > /dev/null 2>&1; then omitir "$nombre: PostgreSQL o el usuario $upg no esta disponible"; return; fi

  # Base limpia
  psql -h 127.0.0.1 -U "$upg" -d postgres -qc "DROP DATABASE IF EXISTS $db" > /dev/null 2>&1
  psql -h 127.0.0.1 -U "$upg" -d postgres -qc "CREATE DATABASE $db" > /dev/null || { omitir "$nombre: no se pudo crear la base"; return; }
  BASES+=("$upg $db")
  psql -h 127.0.0.1 -U "$upg" -d "$db" -q -v ON_ERROR_STOP=1 -f "$dir/db/schema.sql" > /dev/null 2>&1 || { check "$nombre: schema.sql carga" ok error; return; }
  for m in "$dir"/db/migraciones/*.sql; do [ -f "$m" ] || continue; psql -h 127.0.0.1 -U "$upg" -d "$db" -q -v ON_ERROR_STOP=1 -f "$m" > /dev/null 2>&1; done
  psql_() { psql -h 127.0.0.1 -U "$upg" -d "$db" -tAc "$1"; }

  # 1) Agregarlo en el panel: el comando trae el ID y el secreto que se pegarian en el servidor
  agregar "$tipo" "$tipo" "$nombre" "$pbase"
  check "$nombre: comando con la ruta confirmada" "sudo bash" "$(echo "$CMD" | awk '{print $1,$2}')"
  check "$nombre: secreto de 64 hex en el comando" "1" "$(echo -n "$SEC" | grep -cE '^[0-9a-f]{64}$')"

  # 2) Programa sin conectar todavia (servidor arriba, conector sin ID/secreto) -> "sin conectar" o "sin configurar"
  arrancar() { # arrancar SECRETO|"" -> servidor del programa con ese secreto (lo que hace conectar_central.sh)
    [ -n "${PPID_[$tipo]:-}" ] && { kill "${PPID_[$tipo]}" 2>/dev/null; wait "${PPID_[$tipo]}" 2>/dev/null; }
    local conf=()
    [ -n "$1" ] && conf=(TUKAN_CENTRAL_ID="$tipo" TUKAN_CENTRAL_SECRET="$1" TUKAN_CENTRAL_URL="$BASE")
    (cd "$dir" && exec env -u TUKAN_CENTRAL_ID -u TUKAN_CENTRAL_SECRET -u TUKAN_CENTRAL_URL -u TUKAN_CENTRAL_DATOS -u TUKAN_CENTRAL_KEY \
        "${pre}_DB_NAME=$db" "${pre}_DB_USER=$upg" "${pre}_DB_PASS=$PGPASSWORD" "${pre}_URL=$pbase" "${pre}_UPLOADS=$TMP/up_$tipo" \
        TUKAN_SOLO_CENTRAL=0 "${conf[@]}" php -S "127.0.0.1:$puerto" -t public tests/router.php >> "$TMP/$tipo.log" 2>&1) &
    PPID_[$tipo]=$!
    esperar "$pbase/" || echo "  (no arranco $nombre)"
  }
  arrancar ""
  probar "$tipo"
  if grep -q "Programa sin conectar\|sin configurar" "$TMP/pag.html"; then check "$nombre: sin conectar -> mensaje claro" 1 1; else check "$nombre: sin conectar -> mensaje claro" "sin conectar" "$(grep -o 'class="aviso[^"]*"><svg[^>]*>.*</svg><p>[^<]*' "$TMP/pag.html" | sed 's/.*<p>//' | head -1)"; fi

  # 3) Conectado con el secreto del panel
  arrancar "$SEC"
  local sec_bueno=$SEC
  # Negocios de prueba (con el registro del propio programa): uno con la prueba por vencer
  local i n1="" ids=()
  for i in 1 2 3; do
    rm -f "$pjar"
    curl -s -b "$pjar" -c "$pjar" -o "$TMP/p.html" "$pbase/?r=registro"
    local t; t=$(grep -o 'name="csrf" value="[^"]*"' "$TMP/p.html" | head -1 | sed 's/.*value="//;s/"$//')
    local nn="$nombre Integracion $i $RANDOM"
    curl -s -b "$pjar" -c "$pjar" -o /dev/null --data-urlencode "csrf=$t" --data-urlencode "$campo=$nn" --data-urlencode "nombre=Dueno Prueba" \
         --data-urlencode "telefono=0990000000" --data-urlencode "email=dueno$i$RANDOM@prueba.ec" --data-urlencode "clave=claveSegura1" \
         --data-urlencode "plan=$plan" "$@" "$pbase/?r=registro"
    [ "$i" = 1 ] && n1=$nn
  done
  local total; total=$(psql_ "SELECT count(*) FROM $tabla")
  check "$nombre: 3 negocios registrados en su base" "3" "$total"
  psql_ "UPDATE $tabla SET prueba_hasta = CURRENT_DATE + 2 WHERE nombre = '$n1'" > /dev/null
  probar "$tipo"
  local ver; ver=$(git -C "$dir" rev-parse --short=7 HEAD 2>/dev/null)
  contiene "$nombre: Probar conexion OK" "$TMP/pag.html" "Conexión correcta: $nombre"
  contiene "$nombre: version real (commit $ver)" "$TMP/pag.html" "versión $ver"
  contiene "$nombre: ficha en linea" "$TMP/pag.html" 'estado on'
  check "$nombre: auditoria de la prueba" "1" "$(sqlc "SELECT count(*) FROM auditoria WHERE accion='probar' AND instancia_id='$tipo' AND detalle LIKE 'OK%'")"

  # 4) Inicio: sus cifras en la tarjeta y en "Para atender hoy"
  get "r=inicio" > /dev/null; post refrescar > /dev/null; get "r=inicio" > /dev/null
  local tj; tj=$(tarjeta "$tipo")
  check "$nombre: tarjeta con el total de negocios de su base" "$total" "$(dato "$tj" Negocios)"
  check "$nombre: tarjeta con los que estan en prueba" "$(psql_ "SELECT count(*) FROM $tabla WHERE estado='prueba' AND (prueba_hasta IS NULL OR prueba_hasta >= CURRENT_DATE)")" "$(dato "$tj" 'En prueba')"
  contiene "$nombre: tarjeta en linea" "$tj" 'estado on'
  contiene "$nombre: tarjeta con la version" "$tj" "v$ver"
  contiene "$nombre: Para atender hoy (prueba que vence)" "$TMP/pag.html" "$n1"
  contiene "$nombre: boton Entrar en Para atender hoy" "$TMP/pag.html" "Entrar a $nombre</button>"
  TOTAL_ESPERADO=$((TOTAL_ESPERADO + total))
  check "Inicio: total de todos los programas" "$TOTAL_ESPERADO" "$(grep -o 'data-total="negocios">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"

  # 5) Entrar: 302 con el token, el programa lo acepta y queda en su panel de super usuario
  get "r=inicio" > /dev/null
  R=$(post entrar --data-urlencode "id=$tipo")
  local destino=${R#* }
  check "$nombre: Entrar -> 302" "302" "${R%% *}"
  check "$nombre: Entrar va a tukan_central.php?accion=sso" "$pbase/tukan_central.php?accion=sso&t=" "$(echo "$destino" | sed 's/t=.*/t=/')"
  check "$nombre: auditoria de la entrada" "1" "$(sqlc "SELECT count(*) FROM auditoria WHERE accion='entrar' AND instancia_id='$tipo'")"
  rm -f "$pjar"
  R=$(curl -s -L -b "$pjar" -c "$pjar" -o "$TMP/panel_$tipo.html" -w "%{http_code} %{url_effective}" "$destino")
  check "$nombre: llega a su panel de super usuario" "200 $pbase/?r=admin" "$R"
  contiene "$nombre: panel con el boton de volver al central" "$TMP/panel_$tipo.html" "← Panel Tukán</a>"
  contiene "$nombre: el boton lleva a este panel" "$TMP/panel_$tipo.html" "href=\"$BASE\""
  check "$nombre: super usuario con el correo del dueno" "1" "$(psql_ "SELECT count(*) FROM superadmins WHERE email='dimitryfierro@gmail.com'")"
  # El mismo token otra vez (nonce repetido): no entra
  rm -f "$TMP/jar_otro"
  R=$(curl -s -b "$TMP/jar_otro" -c "$TMP/jar_otro" -o "$TMP/repetido.html" -w "%{http_code}" "$destino")
  check "$nombre: token repetido -> rechazado (401)" "401" "$R"
  check "$nombre: token repetido -> sin sesion en su panel" "302" "$(curl -s -b "$TMP/jar_otro" -o /dev/null -w '%{http_code}' "$pbase/?r=admin")"

  # 6) Problemas reales explicados en palabras simples
  rotar "$tipo"   # el panel cambia el secreto, pero el programa sigue con el anterior
  probar "$tipo"
  contiene "$nombre: secreto cambiado sin pegar el comando -> secreto incorrecto" "$TMP/pag.html" "Secreto incorrecto"
  arrancar "$SEC"   # se pega el comando nuevo
  probar "$tipo"
  contiene "$nombre: con el secreto nuevo vuelve a conectar" "$TMP/pag.html" "Conexión correcta"
  psql_ "DELETE FROM tukan_central_pedidos" > /dev/null 2>&1
  for i in $(seq 1 35); do curl -s -o /dev/null "$pbase/tukan_central.php?accion=estado&t=x.y"; done
  probar "$tipo"
  contiene "$nombre: limite de pedidos (429) -> espera un minuto" "$TMP/pag.html" "Espera un minuto"
  psql_ "DELETE FROM tukan_central_pedidos" > /dev/null 2>&1
  kill "${PPID_[$tipo]}" 2>/dev/null; wait "${PPID_[$tipo]}" 2>/dev/null; PPID_[$tipo]=""
  probar "$tipo"
  contiene "$nombre: programa apagado -> sin conexion" "$TMP/pag.html" "Sin conexión: el servidor del programa no contesta"
  arrancar "$SEC"
  probar "$tipo"
  contiene "$nombre: vuelve a estar en linea" "$TMP/pag.html" "Conexión correcta"
  if grep -qiE "fatal|warning|deprecated|notice" "$TMP/$tipo.log"; then
    check "$nombre: sin avisos de PHP en el programa" "0" "$(grep -ciE 'fatal|warning|deprecated|notice' "$TMP/$tipo.log")"
    grep -iE "fatal|warning|deprecated|notice" "$TMP/$tipo.log" | sort | uniq -c | head -5
  else
    check "$nombre: sin avisos de PHP en el programa" 1 1
  fi
  CONECTADOS+=("$nombre")
}

integrar "TuEvento" tuevento "${TUEVENTO_DIR:-/home/claude/tuevento}" 8241 tuevento TUEVENTO negocios negocio quinta --data-urlencode "ciudad=Quito"
integrar "TuSalón" tusalon "${TUSALON_DIR:-/home/claude/tusalon}" 8242 tusalon TUSALON salones salon completa

titulo "Resumen"
if [ "${#CONECTADOS[@]}" -gt 1 ]; then
  get "r=inicio" > /dev/null; post refrescar > /dev/null; get "r=inicio" > /dev/null
  check "Inicio: suma de los ${#CONECTADOS[@]} programas reales" "$TOTAL_ESPERADO" "$(grep -o 'data-total="negocios">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"
fi
if grep -qiE "fatal|warning|deprecated|notice" "$TMP/central.log"; then
  check "Sin avisos de PHP en el panel" "0" "$(grep -ciE 'fatal|warning|deprecated|notice' "$TMP/central.log")"
  grep -iE "fatal|warning|deprecated|notice" "$TMP/central.log" | sort | uniq -c | head -10
else
  check "Sin avisos de PHP en el panel" 1 1
fi
echo
echo "----------------------------------------"
echo "Integracion — correctas: $OK  ·  Fallas: $FALLAS  ·  Programas omitidos: $OMITIDOS  (conectados: ${CONECTADOS[*]:-ninguno})"
[ "$FALLAS" -eq 0 ]
