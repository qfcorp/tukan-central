#!/usr/bin/env bash
# Prueba de integracion del Panel Tukan Central con los CONECTORES REALES de los programas (no el programa de prueba).
# Levanta el panel con "php -S" y, al lado, TuEvento y TuSalon de verdad (sus repos en /home/claude/...) con una base
# PostgreSQL de prueba. Los agrega en el panel, prueba la conexion, revisa las cifras en Inicio y entra con "Entrar"
# siguiendo las redirecciones hasta el panel de super usuario del programa.
# Si falta el repo de un programa o su usuario de PostgreSQL, ese programa se OMITE (no cuenta como falla).
# Uso: bash tests/integracion.sh
# Variables: TUEVENTO_DIR, TUSALON_DIR, TUVOCALIA_DIR (carpetas de los repos), PGPASSWORD (clave de los usuarios de prueba, "prueba").
# Puertos: 8240 (panel), 8241 (TuEvento), 8242 (TuSalon), 8243 (TuVocalia, SQLite, con su pruebas/tukan_central/reiniciar.sh).
# Con cada programa v2 prueba ademas: capacidades, clientes, crear demo y entrar con la clave, alargar prueba, cobro
# (y reintento idempotente), finanzas (ganancia = ingresos - gastos), suspender/reactivar y un registro nuevo en Solicitudes.
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

# --- formularios del panel (como un navegador sin JavaScript) ---
post_form() { # post_form PAGINA.html SELECTOR [campo=valor ...] -> "codigo destino"; deja $TMP/post.html
  local html=$1 sel=$2; shift 2
  local args=() accion="" kv
  while IFS= read -r -d '' kv; do
    if [ -z "$accion" ] && [[ "$kv" == ACTION=* ]]; then accion=${kv#ACTION=}; continue; fi
    args+=(--data-urlencode "$kv")
  done < <(php tests/form.php "$html" "$sel" "$@")
  curl -s -b "$JAR" -c "$JAR" -o "$TMP/post.html" -w "%{http_code} %{redirect_url}" "${args[@]}" "$BASE$accion"
}
destino() { echo "${1#* }" | sed "s#^$BASE##"; }
abrir_accion() { # abrir_accion INST CLIENTE ACCION -> formulario de ese boton de la ficha en $TMP/pag.html
  get "r=cliente&i=$1&id=$2" > /dev/null
  local h; h=$(grep -o "href=\"/?r=accion&amp;i=$1&amp;a=$3&amp;n=[0-9]*[^\"]*\"" "$TMP/pag.html" | head -1 | sed 's/^href="\/?//;s/"$//;s/&amp;/\&/g')
  [ -n "$h" ] && get "$h" > /dev/null
}
actualizar() { get "r=inicio" > /dev/null; post actualizar_datos --data-urlencode "que=$2" --data-urlencode "i=$1" --data-urlencode "volver=/?r=inicio" > /dev/null; }
dinero() { php -r 'echo "$".number_format((float)$argv[1],2,",",".");' "$1"; }

# integrar_v2 NOMBRE TIPO -> todo el protocolo v2 con el conector real. Usa los "ganchos" que define cada programa:
#   h_total, h_nombre ID, h_id NOMBRE, h_prueba ID (AAAA-MM-DD), h_estado ID (prueba|activo|suspendido), h_pagos ID,
#   h_pagos_mes AAAA-MM (suma), h_ops_pago, h_atencion SOLICITUD_ID, h_login EMAIL CLAVE (-> ok|no), h_registro NOMBRE
#   y DEMO_EXTRA (campos propios del formulario de demo).
integrar_v2() {
  local nombre=$1 tipo=$2 R t

  # Capacidades y clientes
  check "$nombre v2: capacidades guardadas con protocolo 2" "2" "$(sqlc "SELECT protocolo FROM capacidades WHERE instancia_id='$tipo'")"
  get "r=programa&id=$tipo" > /dev/null
  contiene "$nombre v2: ficha del programa dice Protocolo v2" "$TMP/pag.html" "Protocolo v2"
  contiene "$nombre v2: puede crear demos (capacidad)" "$TMP/pag.html" "Crear demos"
  actualizar "$tipo" clientes
  local total; total=$(h_total)
  check "$nombre v2: copia local con todos sus clientes ($total)" "$total" "$(sqlc "SELECT count(*) FROM clientes WHERE instancia_id='$tipo'")"
  get "r=clientes&i=$tipo" > /dev/null
  check "$nombre v2: Clientes muestra los $total" "$total" "$(grep -c "data-cliente=\"$tipo|" "$TMP/pag.html")"
  local c1; c1=$(sqlc "SELECT cliente_id FROM clientes WHERE instancia_id='$tipo' ORDER BY cliente_id LIMIT 1")
  contiene "$nombre v2: Clientes con el nombre real" "$TMP/pag.html" "$(h_nombre "$c1")"

  # Crear demo desde el panel y entrar al programa con la clave que devuelve
  check "$nombre v2: formulario Crear demo" "200" "$(get "r=demo_nueva&i=$tipo")"
  cp "$TMP/pag.html" "$TMP/demo_$tipo.html"
  local correo="demo$RANDOM@integracion.ec" ndemo="Demo Integracion $nombre $RANDOM"
  R=$(post_form "$TMP/demo_$tipo.html" "#form-demo" "campo[nombre]=$ndemo" "campo[contacto_nombre]=Maria Demo" "campo[telefono]=0987654321" "campo[email]=$correo" "${DEMO_EXTRA[@]}")
  check "$nombre v2: demo creada" "200" "${R%% *}"
  contiene "$nombre v2: pantalla Demo lista" "$TMP/post.html" "Demo lista"
  local clave; clave=$(grep -o 'data-clave-una-vez>[^<]*' "$TMP/post.html" | sed 's/.*>//')
  check "$nombre v2: la clave se muestra" "1" "$([ ${#clave} -ge 8 ] && echo 1 || echo 0)"
  check "$nombre v2: la clave no queda en el panel" "0" "$(cat "$TUKAN_CENTRAL_DATOS"/central.sqlite* | grep -ac -- "${clave:-sin-clave}")"
  local demo; demo=$(h_id "$ndemo")
  check "$nombre v2: la demo existe en la base del programa" "1" "$([ -n "$demo" ] && echo 1 || echo 0)"
  check "$nombre v2: historial de demos del panel" "$demo" "$(sqlc "SELECT cliente_id FROM demos WHERE instancia_id='$tipo' ORDER BY id DESC LIMIT 1")"
  check "$nombre v2: el dueno de la demo entra al programa con esa clave" "ok" "$(h_login "$correo" "$clave")"
  check "$nombre v2: con otra clave no entra" "no" "$(h_login "$correo" "otraClave999")"

  # Alargar la prueba
  local antes; antes=$(h_prueba "$demo")
  abrir_accion "$tipo" "$demo" extender_prueba
  R=$(post_form "$TMP/pag.html" "#form-accion" "campo[dias]=5")
  check "$nombre v2: alargar la prueba -> vuelve" "303" "${R%% *}"
  check "$nombre v2: prueba 5 dias mas en su base" "$(date -d "$antes +5 days" +%Y-%m-%d)" "$(h_prueba "$demo")"
  check "$nombre v2: auditoria de la accion" "1" "$(sqlc "SELECT count(*) > 0 FROM auditoria WHERE accion='ejecutar' AND instancia_id='$tipo' AND detalle LIKE '%listo%'")"

  # Registrar pago (y repetir el mismo formulario: un solo pago)
  abrir_accion "$tipo" "$demo" registrar_pago
  cp "$TMP/pag.html" "$TMP/pago_$tipo.html"
  R=$(post_form "$TMP/pago_$tipo.html" "#form-accion")
  check "$nombre v2: registrar pago -> vuelve" "303" "${R%% *}"
  check "$nombre v2: un pago en su base" "1" "$(h_pagos "$demo")"
  check "$nombre v2: queda activo" "activo" "$(h_estado "$demo")"
  check "$nombre v2: la copia del panel lo ve activo" "activo" "$(sqlc "SELECT estado FROM clientes WHERE instancia_id='$tipo' AND cliente_id='$demo'")"
  R=$(post_form "$TMP/pago_$tipo.html" "#form-accion")
  check "$nombre v2: reintento con el mismo operacion_id -> vuelve" "303" "${R%% *}"
  check "$nombre v2: reintento idempotente: sigue un solo pago" "1" "$(h_pagos "$demo")"
  get "r=inicio" > /dev/null
  contiene "$nombre v2: reintento -> Ya estaba hecho" "$TMP/pag.html" "Ya estaba hecho"
  check "$nombre v2: el programa la guardo una sola vez" "1" "$(h_ops_pago)"

  # Finanzas: el pago aparece y ganancia = ingresos - gastos
  actualizar "$tipo" finanzas
  local mes; mes=$(date +%Y-%m)
  local ing; ing=$(sqlc "SELECT json_extract(datos, '$.ingresos') FROM finanzas_mes WHERE instancia_id='$tipo' AND mes='$mes'")
  check "$nombre v2: finanzas del mes = pagos del mes en su base" "$(dinero "$(h_pagos_mes "$mes")")" "$(dinero "${ing:-0}")"
  get "r=gastos" > /dev/null
  R=$(post_form "$TMP/pag.html" "#form-movimiento" "fecha=$(date +%Y-%m-%d)" "categoria=servidores" "descripcion=Servidor $nombre" "monto=7,25" "instancia_id=$tipo")
  check "$nombre v2: gasto del programa guardado" "303" "${R%% *}"
  get "r=finanzas&periodo=mes" > /dev/null
  local ri rg
  ri=$(php -r 'require "app/bootstrap.php"; echo finanzas()->resumen(Tukan\Finanzas::meses("mes"), false)["total"]["total"]["ingresos"];')
  rg=$(php -r 'require "app/bootstrap.php"; echo finanzas()->resumen(Tukan\Finanzas::meses("mes"), false)["total"]["total"]["gastos"];')
  check "$nombre v2: Finanzas: ingresos del mes" "$(dinero "$ri")" "$(grep -o 'data-fin="ingresos">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"
  check "$nombre v2: Finanzas: ganancia = ingresos - gastos" "$(php -r 'echo "$".number_format((float)$argv[1]-(float)$argv[2],2,",",".");' "$ri" "$rg")" "$(grep -o 'data-fin="ganancia">[^<]*' "$TMP/pag.html" | sed 's/.*>//')"
  check "$nombre v2: Finanzas: los gastos incluyen el nuevo" "1" "$(php -r 'echo (float)$argv[1] >= 7.25 ? 1 : 0;' "$rg")"
  check "$nombre v2: Finanzas: los ingresos incluyen el pago de la demo" "1" "$(php -r 'echo (float)$argv[1] >= (float)$argv[2] && (float)$argv[2] > 0 ? 1 : 0;' "$ri" "${ing:-0}")"

  # Suspender y reactivar
  abrir_accion "$tipo" "$demo" suspender
  R=$(post_form "$TMP/pag.html" "#form-accion" "campo[motivo]=Prueba de integracion" "seguro=1")
  check "$nombre v2: suspender -> vuelve" "303" "${R%% *}"
  check "$nombre v2: suspendido en su base" "suspendido" "$(h_estado "$demo")"
  check "$nombre v2: la copia del panel lo ve suspendido" "suspendido" "$(sqlc "SELECT estado FROM clientes WHERE instancia_id='$tipo' AND cliente_id='$demo'")"
  get "r=cliente&i=$tipo&id=$demo" > /dev/null
  R=$(post_form "$TMP/pag.html" "boton:Reactivar" 2>/dev/null)
  [ "${R%% *}" = 303 ] || { abrir_accion "$tipo" "$demo" reactivar; R=$(post_form "$TMP/pag.html" "#form-accion" "seguro=1"); }
  check "$nombre v2: reactivar -> vuelve" "303" "${R%% *}"
  check "$nombre v2: activo otra vez en su base" "activo" "$(h_estado "$demo")"

  # Registro nuevo (el negocio se registra solo): la revision automatica lo trae a la bandeja; atenderlo
  local nuevo="$nombre Registro Nuevo $RANDOM"
  h_registro "$nuevo"
  local nid; nid=$(h_id "$nuevo")
  check "$nombre v2: se registro solo en el programa" "1" "$([ -n "$nid" ] && echo 1 || echo 0)"
  php bin/revisar.php > "$TMP/revisar_$tipo.log" 2>&1
  check "$nombre v2: la revision automatica trae el registro nuevo" "pendiente" "$(sqlc "SELECT estado FROM solicitudes WHERE instancia_id='$tipo' AND solicitud_id='registro_nuevo:$nid'")"
  get "r=solicitudes&i=$tipo" > /dev/null
  contiene "$nombre v2: en la bandeja de Solicitudes" "$TMP/pag.html" "$nuevo"
  get "r=solicitud&i=$tipo&id=registro_nuevo:$nid" > /dev/null
  local h; h=$(grep -o "href=\"/?r=accion&amp;i=$tipo&amp;a=atender_solicitud&amp;n=[0-9]*[^\"]*\"" "$TMP/pag.html" | head -1 | sed 's/^href="\/?//;s/"$//;s/&amp;/\&/g')
  if [ -n "$h" ]; then get "$h" > /dev/null; R=$(post_form "$TMP/pag.html" "#form-accion" "campo[resultado]=atendida"); else R=$(post_form "$TMP/pag.html" "boton:Marcar atendida"); fi
  check "$nombre v2: Marcar atendida -> vuelve" "303" "${R%% *}"
  check "$nombre v2: atendida en el programa" "atendida" "$(h_atencion "registro_nuevo:$nid")"
  check "$nombre v2: atendida en la copia del panel" "atendida" "$(sqlc "SELECT estado FROM solicitudes WHERE instancia_id='$tipo' AND solicitud_id='registro_nuevo:$nid'")"
  if grep -qiE "fatal|warning|deprecated|notice" "$TMP/revisar_$tipo.log"; then check "$nombre v2: revisar.php sin avisos de PHP" "" "$(head -3 "$TMP/revisar_$tipo.log")"; else check "$nombre v2: revisar.php sin avisos de PHP" 1 1; fi
}

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
  contiene "$nombre: Para atender hoy enlaza a sus solicitudes (v2)" "$TMP/pag.html" "r=solicitud&amp;i=$tipo&amp;id="
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

  # 7) Protocolo v2 con el conector real: capacidades, clientes, demo, acciones, finanzas, solicitudes
  local fk; fk=$([ "$tabla" = negocios ] && echo negocio_id || echo salon_id)
  h_total() { psql_ "SELECT count(*) FROM $tabla"; }
  h_nombre() { psql_ "SELECT nombre FROM $tabla WHERE id=$1"; }
  h_id() { psql_ "SELECT id FROM $tabla WHERE nombre='$1'"; }
  h_prueba() { psql_ "SELECT prueba_hasta FROM $tabla WHERE id=$1"; }
  h_estado() { psql_ "SELECT estado FROM $tabla WHERE id=$1"; }
  h_pagos() { psql_ "SELECT count(*) FROM suscripciones WHERE $fk=$1"; }
  h_pagos_mes() { psql_ "SELECT coalesce(sum(monto),0) FROM suscripciones WHERE to_char(pagado_en,'YYYY-MM')='$1'"; }
  h_ops_pago() { psql_ "SELECT count(*) FROM tukan_central_operaciones WHERE accion='registrar_pago'"; }
  h_atencion() { psql_ "SELECT estado FROM tukan_central_atenciones WHERE solicitud_id='$1'"; }
  h_login() { # el login del programa: POST /?r=login con csrf, correo y clave
    rm -f "$TMP/jar_login"
    curl -s -b "$TMP/jar_login" -c "$TMP/jar_login" -o "$TMP/login_p.html" "$pbase/?r=login"
    local t; t=$(grep -o 'name="csrf" value="[^"]*"' "$TMP/login_p.html" | head -1 | sed 's/.*value="//;s/"$//')
    local r; r=$(curl -s -b "$TMP/jar_login" -c "$TMP/jar_login" -o /dev/null -w "%{http_code} %{redirect_url}" --data-urlencode "csrf=$t" --data-urlencode "email=$1" --data-urlencode "clave=$2" "$pbase/?r=login")
    echo "$r" | grep -qE '^30[23] .*r=(inicio|mi_cuenta)' && echo ok || echo no
  }
  local extra=("$@")
  h_registro() {
    rm -f "$TMP/jar_reg"
    curl -s -b "$TMP/jar_reg" -c "$TMP/jar_reg" -o "$TMP/p.html" "$pbase/?r=registro"
    local t; t=$(grep -o 'name="csrf" value="[^"]*"' "$TMP/p.html" | head -1 | sed 's/.*value="//;s/"$//')
    curl -s -b "$TMP/jar_reg" -c "$TMP/jar_reg" -o /dev/null --data-urlencode "csrf=$t" --data-urlencode "$campo=$1" --data-urlencode "nombre=Pedro Nuevo" \
         --data-urlencode "telefono=0991112233" --data-urlencode "email=nuevo$RANDOM@prueba.ec" --data-urlencode "clave=claveSegura1" \
         --data-urlencode "plan=$plan" "${extra[@]}" "$pbase/?r=registro"
  }
  DEMO_EXTRA=()
  integrar_v2 "$nombre" "$tipo"
  TOTAL_ESPERADO=$((TOTAL_ESPERADO - total + $(psql_ "SELECT count(*) FROM $tabla")))
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

# =====================================================================
# TuVocalia (SQLite): su propio entorno de pruebas (pruebas/tukan_central/reiniciar.sh) en el puerto 8243.
# reiniciar.sh borra y crea data/ DENTRO de su repo (se niega en un servidor); no se toca nada fuera de ese repo.
integrar_tuvocalia() {
  local nombre="TuVocalía" tipo=tuvocalia dir=${TUVOCALIA_DIR:-/home/claude/tuvocalia} puerto=8243
  local pbase="http://127.0.0.1:$puerto" bd="$dir/data/ligas.sqlite"
  titulo "$nombre (conector real en $dir, SQLite)"
  if [ ! -f "$dir/pruebas/tukan_central/reiniciar.sh" ] || [ ! -f "$dir/public/tukan_central.php" ]; then omitir "$nombre: no esta el repo con el conector"; return; fi
  if [ -f "$dir/app/config.php" ] && ! grep -q "SOLO PRUEBAS" "$dir/app/config.php"; then omitir "$nombre: app/config.php no es de pruebas (no se toca)"; return; fi
  agregar "$tipo" "$tipo" "$nombre" "$pbase"
  check "$nombre: secreto de 64 hex en el comando" "1" "$(echo -n "$SEC" | grep -cE '^[0-9a-f]{64}$')"
  (cd "$dir" && env -u TUKAN_CENTRAL_ID -u TUKAN_CENTRAL_SECRET -u TUKAN_CENTRAL_URL -u TUKAN_CENTRAL_DATOS -u TUKAN_CENTRAL_KEY -u TUKAN_CENTRAL_ENV \
      TA_PUERTO=$puerto bash pruebas/tukan_central/reiniciar.sh > "$TMP/tuvocalia_reiniciar.log" 2>&1) || { omitir "$nombre: no se pudo preparar su entorno de pruebas"; cat "$TMP/tuvocalia_reiniciar.log"; return; }
  printf 'TUKAN_CENTRAL_ID=%s\nTUKAN_CENTRAL_SECRET=%s\nTUKAN_CENTRAL_URL=%s\n' "$tipo" "$SEC" "$BASE" > "$dir/data/tukan_central.env"
  esperar "$pbase/" || { omitir "$nombre: no arranco"; return; }
  sqlv() { php -r '$d=new PDO("sqlite:".$argv[1]); foreach($d->query($argv[2])->fetchAll(PDO::FETCH_NUM) as $f) echo implode("|",$f),"\n";' "$bd" "$1"; }
  probar "$tipo"
  contiene "$nombre: Probar conexion OK" "$TMP/pag.html" "Conexión correcta: $nombre"
  contiene "$nombre: habla el protocolo v2" "$TMP/pag.html" "Habla el protocolo v2"
  get "r=inicio" > /dev/null; post refrescar > /dev/null; get "r=inicio" > /dev/null
  contiene "$nombre: tarjeta en linea en Inicio" "$(tarjeta "$tipo")" 'estado on'

  h_total() { sqlv "SELECT count(*) FROM ligas"; }
  h_nombre() { sqlv "SELECT nombre FROM ligas WHERE id=$1"; }
  h_id() { sqlv "SELECT id FROM ligas WHERE nombre='$1'"; }
  h_prueba() { sqlv "SELECT vence FROM ligas WHERE id=$1"; }
  h_estado() { sqlv "SELECT CASE WHEN activa = 0 THEN 'suspendido' WHEN es_prueba = 1 THEN 'prueba' ELSE 'activo' END FROM ligas WHERE id=$1"; }
  h_pagos() { sqlv "SELECT count(*) FROM pagos_suscripcion WHERE liga_id=$1"; }
  h_pagos_mes() { sqlv "SELECT coalesce(sum(monto),0) FROM pagos_suscripcion WHERE substr(fecha,1,7)='$1'"; }
  h_ops_pago() { sqlv "SELECT count(*) FROM tukan_central_operaciones WHERE accion='registrar_pago'"; }
  h_atencion() { sqlv "SELECT estado FROM tukan_central_atenciones WHERE solicitud_id='$1'"; }
  h_login() { # admin.php?p=login; entra = va a admin.php
    rm -f "$TMP/jar_login"
    curl -s -b "$TMP/jar_login" -c "$TMP/jar_login" -o "$TMP/login_p.html" "$pbase/admin.php?p=login"
    local t; t=$(grep -o 'name="csrf" value="[^"]*"' "$TMP/login_p.html" | head -1 | sed 's/.*value="//;s/"$//')
    local r; r=$(curl -s -b "$TMP/jar_login" -c "$TMP/jar_login" -o /dev/null -w "%{http_code} %{redirect_url}" -H "CF-Connecting-IP: 10.9.$((RANDOM % 250)).$((RANDOM % 250 + 1))" \
                 --data-urlencode "csrf=$t" --data-urlencode "email=$1" --data-urlencode "clave=$2" "$pbase/admin.php?p=login")
    echo "$r" | grep -qE '^30[23] .*admin\.php$' && echo ok || echo no
  }
  h_registro() { # prueba gratis automatica (se abre el registro en sus ajustes, como lo haria el super)
    (cd "$dir" && php -r 'require "app/bootstrap.php"; guardar_ajuste("registro_abierto", 1);')
    rm -f "$TMP/jar_reg"
    curl -s -b "$TMP/jar_reg" -c "$TMP/jar_reg" -o "$TMP/p.html" "$pbase/index.php?v=registro"
    local t; t=$(grep -o 'name="csrf" value="[^"]*"' "$TMP/p.html" | head -1 | sed 's/.*value="//;s/"$//')
    curl -s -b "$TMP/jar_reg" -c "$TMP/jar_reg" -o "$TMP/reg_tv.html" --data-urlencode "csrf=$t" --data-urlencode "nombre=$1" --data-urlencode "ciudad=Quito" \
         --data-urlencode "modalidad=F7" --data-urlencode "tipo=liga" --data-urlencode "admin_nombre=Pedro Nuevo" --data-urlencode "email=nuevo$RANDOM@prueba.ec" \
         --data-urlencode "telefono=0991112233" --data-urlencode "clave=claveSegura1" --data-urlencode "clave2=claveSegura1" --data-urlencode "acepto=1" \
         --data-urlencode "sitio=" "$pbase/index.php?v=registro"
  }
  DEMO_EXTRA=()
  integrar_v2 "$nombre" "$tipo"
  TOTAL_ESPERADO=$((TOTAL_ESPERADO + $(h_total)))
  if grep -qiE "fatal|warning|deprecated|notice" "$dir/pruebas/tukan_central/web.log"; then
    check "$nombre: sin avisos de PHP en el programa" "0" "$(grep -ciE 'fatal|warning|deprecated|notice' "$dir/pruebas/tukan_central/web.log")"
    grep -iE "fatal|warning|deprecated|notice" "$dir/pruebas/tukan_central/web.log" | sort | uniq -c | head -5
  else
    check "$nombre: sin avisos de PHP en el programa" 1 1
  fi
  CONECTADOS+=("$nombre")
}
integrar_tuvocalia

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
