#!/usr/bin/env bash
# actualizar - actualiza los programas Tukan de ESTE servidor, solo cuando el dueno lo pide.
# Lo deja instalado en /usr/local/bin/actualizar el instalador de una sola vez: deploy/instalar_actualizador.sh
#
# Uso (con tu usuario normal, NO con sudo ni como root):
#   actualizar              muestra que programas tienen version nueva en GitHub y pregunta "Actualizar todos? [S/n]"
#   actualizar todo         actualiza todos los que tengan algo nuevo, sin preguntar
#   actualizar tuevento     solo ese (se pueden poner varios: actualizar tuevento tusalon)
#   actualizar estado       version de cada programa y si hay algo nuevo (no cambia nada)
#   actualizar ayuda        esta ayuda
#
# Por cada programa con algo nuevo, en orden:
#   1) respaldo de su base (pg_dump, mysqldump o sqlite) en /var/backups/tukan-actualizar/PROGRAMA (guarda los ultimos 10)
#   2) pasa el codigo a la version nueva y corre su instalador (log en /var/log/tukan-actualizar)
#   3) prueba que la pagina responda 200
#   4) si algo falla: vuelve al commit anterior, corre el instalador otra vez y avisa
# Nada se actualiza solo: no hay horario ni deteccion de commits. Sudo pide tu contrasena una vez, como siempre.
# Un candado (flock) impide dos corridas a la vez. Si hay Telegram en el Panel Tukan Central, avisa el resultado.
#
# Opciones internas (las usa el servicio tukan-actualizar con los pedidos del panel):
#   PROGRAMA@COMMIT   actualiza solo si en GitHub esta EXACTAMENTE esa version (la que el dueno aprobo)
#   --pedido ID  --usuario CORREO  --resultado ARCHIVO.tsv
# Rutas para las pruebas: TUKAN_ACT_* (ver abajo). Mensajes sin tildes a proposito (se leen por SSH desde el celular).
set -u
set -o pipefail

ESTADO="${TUKAN_ACT_ESTADO:-/var/lib/tukan-actualizar/estado}"
TRABAJO="${TUKAN_ACT_TRABAJO:-/var/lib/tukan-actualizar/trabajo}"
RESPALDOS="${TUKAN_ACT_RESPALDOS:-/var/backups/tukan-actualizar}"
LOGS="${TUKAN_ACT_LOGS:-/var/log/tukan-actualizar}"
LIB="${TUKAN_ACT_LIB:-/usr/local/lib/tukan-actualizar}"
CONF="${TUKAN_ACT_PROGRAMAS:-/etc/tukan-actualizar/programas.conf}"
NGINX="${TUKAN_ACT_NGINX:-/etc/nginx/sites-available}"
WWW="${TUKAN_ACT_WWW:-/var/www}"
SUDO="${TUKAN_ACT_SUDO:-sudo}"
PANEL_ENV="${TUKAN_ACT_PANEL_ENV:-/var/www/tukan-central/config/.env}"
RAMA="${TUKAN_ACT_RAMA:-main}"
ESPERA="${TUKAN_ACT_ESPERA_PAGINA:-40}"
GUARDAR="${TUKAN_ACT_GUARDAR:-10}"
SERVICIO="${TUKAN_ACT_SERVICIO:-0}"
PRUEBAS="${TUKAN_ACT_PRUEBAS:-0}"
AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd)"
AYUDANTE="$LIB/ayudante.php"
[ -f "$AYUDANTE" ] || AYUDANTE="$AQUI/actualizar_ayudante.php"
umask 027

# Programas que conoce (id|nombre|tipo|carpeta|prueba|clave del .env con la direccion|direccion por defecto).
#   deploy    = git en CARPETA, instalador: bash CARPETA/deploy/instalar.sh (como tu usuario; usa sudo por dentro)
#   guarderia = git en CARPETA; una instalacion por cliente en /var/www/CLIENTE (las encuentra en nginx):
#               sudo bash CARPETA/instalar.sh DOMINIO CLIENTE
# Solo se usan los que existen en este servidor. /etc/tukan-actualizar/programas.conf puede agregar o cambiar lineas
# (mismo formato; "id|-|quitar" lo quita).
PROGRAMAS_BASE='tuevento|TuEvento|deploy|/var/www/tuevento|http://127.0.0.1/?r=login|TUEVENTO_URL|tuevento.tukanec.com
tuplanner|TuPlanner|deploy|/var/www/tuplanner|http://127.0.0.1/?r=login|TUPLANNER_URL|tuplanner.tukanec.com
tusalon|TuSalon|deploy|/var/www/tusalon|http://127.0.0.1/?r=login|TUSALON_URL|tusalon.tukanec.com
tuspa|TuSpa|deploy|/var/www/tuspa|http://127.0.0.1:8108/?r=login||
tugroomer|TuGroomer|deploy|/var/www/tugroomer|http://127.0.0.1:8109/?r=login||
tuhospedaje|TuHospedaje|deploy|/var/www/tuhospedaje|http://127.0.0.1:8104/?r=login||
turemolque|TuRemolque|deploy|/var/www/turemolque|http://127.0.0.1/?r=login|TUREMOLQUE_URL|remolques.tukanec.com
erp-productoras|ERP para productoras|deploy|/var/www/erp-productoras|http://127.0.0.1:8106/login.php||
tuguarderia|TuGuarderia|guarderia|/home/qf/github/tuguarderia|/login.php||
tukan-central|Panel Tukan Central|deploy|/var/www/tukan-central|http://127.0.0.1/?r=login|TUKAN_CENTRAL_URL|panel.tukanec.com'

decir() { printf '%s\n' "$*"; }
sin_tildes() {
  sed -e 's/á/a/g;s/é/e/g;s/í/i/g;s/ó/o/g;s/ú/u/g;s/ü/u/g;s/ñ/n/g;s/Á/A/g;s/É/E/g;s/Í/I/g;s/Ó/O/g;s/Ú/U/g;s/Ü/U/g;s/Ñ/N/g' \
      -e 's/¿//g;s/¡//g;s/—/-/g;s/–/-/g;s/…/.../g;s/·/-/g;s/«/"/g;s/»/"/g;s/→/->/g' -e 's/[^[:print:]\t]//g'
}
corto() { printf '%s' "${1:0:7}"; }
una_linea() { tr '\t\r\n' '   ' | sed 's/  */ /g;s/^ //;s/ $//' | cut -c1-400; }

ayuda() { sed -n '2,25p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; }

# ---------------------------------------------------------------- programas
declare -a IDS=()
declare -A P_NOMBRE=() P_TIPO=() P_DIR=() P_PRUEBA=() P_HCLAVE=() P_HDEF=()
cargar_programas() {
  local id nombre tipo dir prueba hclave hdef nuevos=()
  while IFS='|' read -r id nombre tipo dir prueba hclave hdef; do
    id="${id//[[:space:]]/}"
    [[ "$id" =~ ^[a-z0-9][a-z0-9-]{0,39}$ ]] || continue
    if [ "$tipo" = "quitar" ]; then
      unset "P_TIPO[$id]"; nuevos=(); for x in "${IDS[@]}"; do [ "$x" != "$id" ] && nuevos+=("$x"); done; IDS=("${nuevos[@]}")
      continue
    fi
    case "$tipo" in deploy|guarderia) ;; *) continue ;; esac
    [ -z "${P_TIPO[$id]:-}" ] && IDS+=("$id")
    P_NOMBRE[$id]="${nombre:-$id}"; P_TIPO[$id]="$tipo"; P_DIR[$id]="$dir"; P_PRUEBA[$id]="$prueba"; P_HCLAVE[$id]="$hclave"; P_HDEF[$id]="$hdef"
  done < <({ [ "${TUKAN_ACT_SOLO_CONF:-0}" = "1" ] || printf '%s\n' "$PROGRAMAS_BASE"; [ -r "$CONF" ] && grep -v '^[[:space:]]*\(#\|$\)' "$CONF"; } 2>/dev/null)
  # El panel va al final: su instalador reinicia PHP y es el que muestra los resultados
  local orden=() x
  for x in "${IDS[@]}"; do [ "$x" != "tukan-central" ] && orden+=("$x"); done
  for x in "${IDS[@]}"; do [ "$x" = "tukan-central" ] && orden+=("$x"); done
  IDS=("${orden[@]}")
}
presente() { [ -n "${P_TIPO[$1]:-}" ] && [ -d "${P_DIR[$1]}/.git" ]; }

# Instalaciones de TuGuarderia: los sitios de nginx que hizo su instalar.sh -> "carpeta dominio puerto"
instancias_guarderia() {
  local f primera c dom puerto
  for f in "$NGINX"/*; do
    [ -f "$f" ] || continue
    primera="$(head -1 "$f" 2>/dev/null)"
    case "$primera" in "# "*" - generado por instalar.sh (TuGuarder"*) ;; *) continue ;; esac
    c="$(printf '%s' "$primera" | awk '{print $2}')"
    [[ "$c" =~ ^[a-z][a-z0-9_]{1,30}$ ]] || continue
    dom="$(grep -oE 'server_name[[:space:]]+[^;[:space:]]+' "$f" | head -1 | awk '{print $2}')"
    puerto="$(grep -oE 'listen[[:space:]]+127\.0\.0\.1:[0-9]+' "$f" | head -1 | grep -oE '[0-9]+$')"
    [ -n "$dom" ] && [ -n "$puerto" ] && [ -d "$WWW/$c" ] || continue
    printf '%s %s %s\n' "$c" "$dom" "$puerto"
  done
}
lista_instancias() { instancias_guarderia | awk '{print $1}' | paste -sd, -; }

leer_env() { [ -r "$1" ] && grep -E "^$2=" "$1" 2>/dev/null | head -1 | cut -d= -f2- | sed 's/^"\(.*\)"$/\1/'; }

# ---------------------------------------------------------------- git
g() { local d=$1; shift; GIT_TERMINAL_PROMPT=0 git -C "$d" -c core.fileMode=false "$@"; }
info_commit() { g "$1" log -1 --format='%H%x09%cI%x09%s' "$2" 2>/dev/null | head -1; }

declare -A R_ESTADO=() R_MSG=() R_HEAD=() R_NUEVO=() R_N=()
# revisar ID: baja de GitHub (git fetch, no cambia el codigo) y compara HEAD con origin/RAMA
revisar() {
  local id=$1 dir=${P_DIR[$1]} salida
  R_HEAD[$id]="$(g "$dir" rev-parse HEAD 2>/dev/null)"; R_NUEVO[$id]=""; R_N[$id]=0; R_MSG[$id]=""
  if ! salida="$(GIT_SSH_COMMAND="ssh -o BatchMode=yes -o ConnectTimeout=20" timeout 120 git -C "$dir" fetch -q origin "$RAMA" 2>&1 < /dev/null)"; then
    R_ESTADO[$id]=error; R_MSG[$id]="no se pudo revisar GitHub: $(printf '%s' "$salida" | tail -1 | una_linea)"; return 1
  fi
  R_NUEVO[$id]="$(g "$dir" rev-parse "refs/remotes/origin/$RAMA" 2>/dev/null)"
  if [ -z "${R_HEAD[$id]}" ] || [ -z "${R_NUEVO[$id]}" ]; then R_ESTADO[$id]=error; R_MSG[$id]="no se pudo leer la version (git)"; return 1; fi
  if [ "${R_HEAD[$id]}" = "${R_NUEVO[$id]}" ]; then R_ESTADO[$id]=al_dia; return 0; fi
  if ! g "$dir" merge-base --is-ancestor "${R_HEAD[$id]}" "${R_NUEVO[$id]}" 2>/dev/null; then
    R_ESTADO[$id]=error; R_MSG[$id]="la copia del servidor tiene commits que no estan en GitHub: no se toca (revisa a mano con git status)"; return 1
  fi
  R_ESTADO[$id]=nuevo; R_N[$id]="$(g "$dir" rev-list --count "${R_HEAD[$id]}..${R_NUEVO[$id]}" 2>/dev/null || echo 1)"
  return 0
}
cambios() { g "${P_DIR[$1]}" log -n "${2:-30}" --format='%h%x09%s' "${R_HEAD[$1]}..${R_NUEVO[$1]}" 2>/dev/null; }

# ---------------------------------------------------------------- versiones.json (lo lee el panel)
escribir_versiones() {
  command -v php > /dev/null && [ -f "$AYUDANTE" ] || return 0
  [ -d "$ESTADO" ] && [ -w "$ESTADO" ] || return 0
  local id dir reg h n inst
  reg="$(mktemp)" || return 0
  for id in "${IDS[@]}"; do
    presente "$id" || continue
    dir=${P_DIR[$id]}
    h="$(info_commit "$dir" "${R_HEAD[$id]:-HEAD}")"; [ -z "$h" ] && h=$'\t\t'
    if [ -n "${R_NUEVO[$id]:-}" ] && [ "${R_ESTADO[$id]:-}" = "nuevo" ]; then n="$(info_commit "$dir" "${R_NUEVO[$id]}")"; else n=""; fi
    [ -z "$n" ] && n=$'\t\t'
    inst=""; [ "${P_TIPO[$id]}" = "guarderia" ] && inst="$(lista_instancias)"
    printf 'P\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$id" "${P_NOMBRE[$id]}" "${P_TIPO[$id]}" "$dir" "${R_ESTADO[$id]:-error}" \
      "$(printf '%s' "${R_MSG[$id]:-}" | una_linea)" "$inst" "$h" "$n" "${R_N[$id]:-0}" >> "$reg"
    [ "${R_ESTADO[$id]:-}" = "nuevo" ] && cambios "$id" 30 | while IFS=$'\t' read -r c t; do printf 'C\t%s\t%s\t%s\n' "$id" "$c" "$t"; done >> "$reg"
  done
  php "$AYUDANTE" versiones "$ESTADO/versiones.json" < "$reg" 2>/dev/null || true
  rm -f "$reg"
}

# ---------------------------------------------------------------- respaldo de la base
RESPALDO_ARCHIVO=""
podar() { # podar CARPETA PREFIJO: deja los ultimos $GUARDAR
  local f n=0
  while IFS= read -r f; do n=$((n+1)); [ "$n" -gt "$GUARDAR" ] && rm -f "$f"; done < <(ls -1t "$1/$2"_* 2>/dev/null)
  return 0
}
volcar() { # volcar ARCHIVO_TMP: corre el resto de los argumentos y revisa que salga algo
  local destino=$1; shift
  "$@" && [ -s "$destino" ]
}
respaldar_deploy() {
  local id=$1 sha=$2 dir=${P_DIR[$1]} envf pref nombre usuario clave host puerto motor carpeta datos f tmp
  envf="$dir/config/.env"
  carpeta="$RESPALDOS/$id"; mkdir -p "$carpeta" && chmod 700 "$carpeta" || { decir "   ERROR: no se puede crear $carpeta"; return 1; }
  f="$carpeta/${id}_$(date +%Y%m%d_%H%M%S)_$(corto "$sha")"
  pref="$(grep -oE '^[A-Z][A-Z0-9_]*_DB_NAME=' "$envf" 2>/dev/null | head -1 | sed 's/_DB_NAME=$//')"
  if [ -n "$pref" ]; then
    nombre="$(leer_env "$envf" "${pref}_DB_NAME")"; usuario="$(leer_env "$envf" "${pref}_DB_USER")"; clave="$(leer_env "$envf" "${pref}_DB_PASS")"
    host="$(leer_env "$envf" "${pref}_DB_HOST")"; puerto="$(leer_env "$envf" "${pref}_DB_PORT")"
    motor="$(leer_env "$envf" "${pref}_DB_DRIVER")$(leer_env "$envf" "${pref}_DB_CONNECTION")$(leer_env "$envf" "${pref}_DB_MOTOR")"
    case "$motor" in *mysql*|*mariadb*) motor=mysql ;; *) [ "$puerto" = "3306" ] && motor=mysql || motor=pgsql ;; esac
    if [ "$motor" = "pgsql" ]; then
      f="$f.dump"; tmp="$f.parcial"
      if [ -n "$clave" ] && [ -n "$usuario" ]; then
        volcar "$tmp" env PGPASSWORD="$clave" pg_dump -h "${host:-127.0.0.1}" -p "${puerto:-5432}" -U "$usuario" -Fc -f "$tmp" "$nombre" < /dev/null
      else
        volcar "$tmp" bash -c '"$1" -u postgres pg_dump -Fc "$2" > "$3"' _ "$SUDO" "$nombre" "$tmp" < /dev/null
      fi || { rm -f "$tmp"; decir "   ERROR: pg_dump fallo (base $nombre)"; return 1; }
    else
      f="$f.sql.gz"; tmp="$f.parcial"
      volcar "$tmp" bash -c 'MYSQL_PWD="$1" mysqldump -h "$2" ${3:+-P "$3"} -u "$4" --single-transaction --no-tablespaces "$5" | gzip > "$6"' \
        _ "$clave" "${host:-localhost}" "$puerto" "$usuario" "$nombre" "$tmp" < /dev/null \
        || { rm -f "$tmp"; decir "   ERROR: mysqldump fallo (base $nombre)"; return 1; }
    fi
    decir "   respaldo ($motor, base $nombre): $f"
  elif datos="$(leer_env "$envf" TUKAN_CENTRAL_DATOS)" && [ -n "$datos" ]; then
    f="$f.sql.gz"; tmp="$f.parcial"
    if [ -r "$datos/central.sqlite" ]; then
      volcar "$tmp" bash -c 'sqlite3 "$1" .dump | gzip > "$2"' _ "$datos/central.sqlite" "$tmp" < /dev/null
    else
      volcar "$tmp" bash -c '"$1" -u www-data sqlite3 "$2" .dump | gzip > "$3"' _ "$SUDO" "$datos/central.sqlite" "$tmp" < /dev/null
    fi || { rm -f "$tmp"; decir "   ERROR: no se pudo respaldar $datos/central.sqlite"; return 1; }
    decir "   respaldo (sqlite): $f"
  else
    decir "   ERROR: no encontre la base en $envf (falta *_DB_NAME)"; return 1
  fi
  mv -f "$tmp" "$f" && chmod 600 "$f"
  RESPALDO_ARCHIVO="$f"
  podar "$carpeta" "$id"
}
respaldar_guarderia() {
  local id=$1 sha=$2 c dom puerto carpeta f tmp n=0
  carpeta="$RESPALDOS/$id"; mkdir -p "$carpeta" && chmod 700 "$carpeta" || { decir "   ERROR: no se puede crear $carpeta"; return 1; }
  RESPALDO_ARCHIVO=""
  while read -r c dom puerto; do
    n=$((n+1))
    f="$carpeta/${c}_$(date +%Y%m%d_%H%M%S)_$(corto "$sha").sql.gz"; tmp="$f.parcial"
    if ! volcar "$tmp" bash -c '"$1" mysqldump --no-tablespaces --single-transaction "$2" | gzip > "$3"' _ "$SUDO" "$c" "$tmp" < /dev/null; then
      rm -f "$tmp"; decir "   ERROR: mysqldump fallo (base $c)"; return 1
    fi
    mv -f "$tmp" "$f" && chmod 600 "$f"
    decir "   respaldo (mysql, base $c): $f"
    RESPALDO_ARCHIVO="${RESPALDO_ARCHIVO:+$RESPALDO_ARCHIVO }$f"
    podar "$carpeta" "$c"
  done < <(instancias_guarderia)
  [ "$n" -gt 0 ] || { decir "   ERROR: no hay instalaciones de TuGuarderia en nginx"; return 1; }
}
respaldar() { if [ "${P_TIPO[$1]}" = "guarderia" ]; then respaldar_guarderia "$@"; else respaldar_deploy "$@"; fi; }

# ---------------------------------------------------------------- instalador
# Durante la actualizacion el codigo ya quedo en la version aprobada: los instaladores NO deben bajar otra
# (su "git pull" se cambia por nada). Asi lo que se publica es exactamente lo que el dueno aprobo, y volver atras funciona.
SIN_PULL=""
preparar_sin_pull() {
  local real; real="$(command -v git)" || return 1
  SIN_PULL="$(mktemp -d)" || return 1
  cat > "$SIN_PULL/git" <<EOF
#!/bin/sh
for a in "\$@"; do
  if [ "\$a" = "pull" ]; then echo "   (actualizar: la version ya quedo fijada; se omite git pull)"; exit 0; fi
done
exec "$real" "\$@"
EOF
  chmod 755 "$SIN_PULL/git"
}
google_play_deploy() { grep -q '^GOOGLE_PLAY_ACTIVO=1' "${P_DIR[$1]}/config/.env" 2>/dev/null && echo si || echo no; }
google_play_guarderia() { $SUDO grep -qs '^ACTIVO=1' "$WWW/$1/app/android.txt" < /dev/null && echo si || echo no; }
instalar() {
  local id=$1 dir=${P_DIR[$1]} c dom puerto rc=0
  if [ "${P_TIPO[$id]}" = "guarderia" ]; then
    while read -r c dom puerto; do
      echo "===== instalar.sh $dom $c ($(date '+%Y-%m-%d %H:%M:%S'))"
      (cd /tmp && $SUDO env GOOGLE_PLAY="$(google_play_guarderia "$c")" bash "$dir/instalar.sh" "$dom" "$c" < /dev/null) || { rc=1; echo "===== ERROR: el instalador de $c termino con error"; break; }
    done < <(instancias_guarderia)
  else
    echo "===== bash $dir/deploy/instalar.sh ($(date '+%Y-%m-%d %H:%M:%S'))"
    (cd /tmp && PATH="$SIN_PULL:$PATH" GOOGLE_PLAY="$(google_play_deploy "$id")" bash "$dir/deploy/instalar.sh" < /dev/null) || rc=1
  fi
  return $rc
}

# ---------------------------------------------------------------- prueba de la pagina
PAGINA_MSG=""
una_pagina() { # una_pagina URL HOST -> 0 si responde 200 antes de $ESPERA segundos
  local url=$1 host=$2 codigo inicio=$SECONDS
  while :; do
    if [ -n "$host" ]; then codigo="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 -H "Host: $host" "$url" 2>/dev/null)"
    else codigo="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$url" 2>/dev/null)"; fi
    [ "$codigo" = "200" ] && return 0
    [ $((SECONDS - inicio)) -ge "$ESPERA" ] && { PAGINA_MSG="$url respondio ${codigo:-000}"; return 1; }
    sleep 2
  done
}
probar_paginas() {
  local id=$1 dir=${P_DIR[$1]} host c dom puerto n=0
  PAGINA_MSG=""
  if [ "${P_TIPO[$id]}" = "guarderia" ]; then
    while read -r c dom puerto; do
      n=$((n+1)); una_pagina "http://127.0.0.1:$puerto${P_PRUEBA[$id]}" "" || { PAGINA_MSG="$c: $PAGINA_MSG"; return 1; }
    done < <(instancias_guarderia)
    [ "$n" -gt 0 ]
  else
    host=""
    if [ -n "${P_HCLAVE[$id]}" ]; then
      host="$(leer_env "$dir/config/.env" "${P_HCLAVE[$id]}" | sed -E 's#^[a-z]+://##; s#[/:].*$##')"
      host="${host:-${P_HDEF[$id]}}"
    fi
    una_pagina "${P_PRUEBA[$id]}" "$host"
  fi
}

# ---------------------------------------------------------------- resultados
RES_TSV=""
declare -a R_FILAS=()
resultado() { # resultado ID ESTADO ANTES DESPUES MENSAJE
  local m; m="$(printf '%s' "$5" | una_linea)"
  printf '%s\t%s\t%s\t%s\t%s\n' "$1" "$2" "$3" "$4" "$m" >> "$RES_TSV"
  R_FILAS+=("$1|$2|$(corto "$3")|$(corto "$4")|$m")
  [ -d "$LOGS" ] && [ -w "$LOGS" ] && printf '%s %s %s %s->%s %s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "${PEDIDO:-consola}" "$1" "$(corto "$3")" "$(corto "$4")" "$2" "$m" >> "$LOGS/actualizar.log"
}
texto_estado() {
  case "$1" in
    ok) echo "OK" ;; al_dia) echo "ya estaba al dia" ;; revertido) echo "FALLO: volvio a la version anterior" ;;
    grave) echo "FALLO GRAVE: revisa ya" ;; sin_respaldo) echo "NO se actualizo (sin respaldo)" ;;
    rechazado) echo "NO se actualizo (rechazado)" ;; *) echo "ERROR" ;;
  esac
}

# actualizar_uno ID [COMMIT_APROBADO]
actualizar_uno() {
  local id=$1 fijo=${2:-} dir=${P_DIR[$1]} antes nuevo log fallo="" rc
  RESPALDO_ARCHIVO=""
  if ! revisar "$id"; then resultado "$id" error "${R_HEAD[$id]}" "${R_HEAD[$id]}" "${R_MSG[$id]}"; decir "== $id: ERROR: ${R_MSG[$id]}"; return 1; fi
  antes=${R_HEAD[$id]}; nuevo=${R_NUEVO[$id]}
  if [ -n "$fijo" ] && [ "$fijo" != "$nuevo" ]; then
    if [ "$fijo" = "$antes" ]; then resultado "$id" al_dia "$antes" "$antes" "ya tenia la version aprobada"; decir "== $id: ya tenia la version aprobada ($(corto "$fijo"))"; return 0; fi
    resultado "$id" rechazado "$antes" "$antes" "en GitHub hay otra version ($(corto "$nuevo")) distinta a la que aprobaste ($(corto "$fijo")): busca novedades y aprueba otra vez"
    decir "== $id: NO se actualizo: en GitHub hay otra version ($(corto "$nuevo")) distinta a la aprobada ($(corto "$fijo"))"; return 1
  fi
  if [ "${R_ESTADO[$id]}" = "al_dia" ]; then resultado "$id" al_dia "$antes" "$antes" ""; decir "== $id: ya esta al dia ($(corto "$antes"))"; return 0; fi
  if [ "${P_TIPO[$id]}" = "guarderia" ] && [ -z "$(lista_instancias)" ]; then
    resultado "$id" error "$antes" "$antes" "no hay instalaciones de TuGuarderia en este servidor"; decir "== $id: ERROR: no hay instalaciones"; return 1
  fi
  mkdir -p "$LOGS" 2>/dev/null
  log="$LOGS/$(date +%Y%m%d_%H%M%S)_$id.log"
  : > "$log" 2>/dev/null || log=/dev/null
  decir ""
  decir "== $id: $(corto "$antes") -> $(corto "$nuevo") (${R_N[$id]} cambios)"
  decir "   1. respaldo de la base"
  if ! respaldar "$id" "$antes"; then
    resultado "$id" sin_respaldo "$antes" "$antes" "no se pudo respaldar la base: NO se actualizo"; return 1
  fi
  decir "   2. version nueva e instalador (log: $log)"
  if ! g "$dir" merge -q --ff-only "$nuevo" >> "$log" 2>&1 < /dev/null; then
    resultado "$id" error "$antes" "$antes" "git no pudo pasar a la version nueva (mira $log). No se cambio nada."
    decir "   ERROR: git no pudo pasar a la version nueva. No se cambio nada."; return 1
  fi
  instalar "$id" >> "$log" 2>&1 || fallo="el instalador termino con error"
  if [ -z "$fallo" ] && [ "$(g "$dir" rev-parse HEAD 2>/dev/null)" != "$nuevo" ]; then fallo="el codigo no quedo en la version nueva"; fi
  if [ -z "$fallo" ]; then
    decir "   3. prueba de la pagina"
    probar_paginas "$id" || fallo="la pagina no responde 200 ($PAGINA_MSG)"
  fi
  if [ -z "$fallo" ]; then
    resultado "$id" ok "$antes" "$nuevo" "respaldo: $RESPALDO_ARCHIVO"
    decir "   LISTO: $id quedo en $(corto "$nuevo")"; return 0
  fi
  decir "   FALLO: $fallo"
  decir "   ultimas lineas del log:"; tail -8 "$log" 2>/dev/null | sin_tildes | sed 's/^/     | /'
  decir "   4. volviendo a la version anterior ($(corto "$antes"))"
  echo "===== VOLVER ATRAS a $antes ($(date '+%Y-%m-%d %H:%M:%S')): $fallo" >> "$log"
  rc=0
  g "$dir" reset -q --hard "$antes" >> "$log" 2>&1 < /dev/null || rc=1
  if [ "$rc" = "0" ]; then instalar "$id" >> "$log" 2>&1 || rc=1; fi
  if [ "$rc" = "0" ] && probar_paginas "$id"; then
    resultado "$id" revertido "$antes" "$antes" "$fallo. Se volvio a la version anterior y responde bien. Respaldo de la base: $RESPALDO_ARCHIVO"
    decir "   VOLVIO ATRAS: $id sigue en $(corto "$antes") y responde bien"
  else
    resultado "$id" grave "$antes" "$(g "$dir" rev-parse HEAD 2>/dev/null)" "$fallo. Se intento volver a la version anterior PERO sigue fallando: revisa YA ($log). Respaldo de la base: $RESPALDO_ARCHIVO"
    decir "   ATENCION: se intento volver atras pero $id SIGUE FALLANDO. Revisa YA: $log"
  fi
  return 1
}

avisar_telegram() {
  local token chat base
  token="$(leer_env "$PANEL_ENV" TELEGRAM_BOT_TOKEN)"; chat="$(leer_env "$PANEL_ENV" TELEGRAM_CHAT_ID)"
  base="$(leer_env "$PANEL_ENV" TELEGRAM_API_BASE)"; base="${base:-https://api.telegram.org}"
  [ -n "$token" ] && [ -n "$chat" ] || return 0
  # El token va por la entrada de curl (no en la linea de comandos, que otros usuarios pueden ver)
  printf 'url = "%s/bot%s/sendMessage"\n' "${base%/}" "$token" \
    | curl -s -o /dev/null --max-time 10 -K - --data-urlencode "chat_id=$chat" --data-urlencode "text=$1" --data "disable_web_page_preview=true" 2>/dev/null || true
}

tabla_estado() {
  local id inst
  printf '%-18s %-9s %-9s %s\n' "PROGRAMA" "INSTALADA" "GITHUB" "ESTADO"
  for id in "${IDS[@]}"; do
    presente "$id" || continue
    inst=""; [ "${P_TIPO[$id]}" = "guarderia" ] && inst=" ($(lista_instancias | tr ',' ' '))"
    case "${R_ESTADO[$id]}" in
      al_dia) printf '%-18s %-9s %-9s %s\n' "$id" "$(corto "${R_HEAD[$id]}")" "$(corto "${R_NUEVO[$id]}")" "al dia$inst" ;;
      nuevo) printf '%-18s %-9s %-9s %s\n' "$id" "$(corto "${R_HEAD[$id]}")" "$(corto "${R_NUEVO[$id]}")" "${R_N[$id]} cambios nuevos$inst" ;;
      *) printf '%-18s %-9s %-9s %s\n' "$id" "$(corto "${R_HEAD[$id]}")" "-" "ERROR: ${R_MSG[$id]}" ;;
    esac
  done
}
lista_cambios() {
  local id=$1
  cambios "$id" 10 | sin_tildes | sed 's/\t/ /;s/^/     - /'
  [ "${R_N[$id]}" -gt 10 ] 2>/dev/null && decir "     ... y $(( R_N[$id] - 10 )) mas"
  return 0
}

# ================================================================= principal
MODO=""; PEDIDO=""; USUARIO_P=""; SALIDA_TSV=""
declare -a PEDIDOS_OBJ=()
while [ $# -gt 0 ]; do
  case "$1" in
    --pedido) PEDIDO="${2:-}"; shift 2 || shift ;;
    --usuario) USUARIO_P="${2:-}"; shift 2 || shift ;;
    --resultado) SALIDA_TSV="${2:-}"; shift 2 || shift ;;
    estado|todo|ayuda) [ -n "$MODO" ] && [ "$MODO" != "$1" ] && { decir "ERROR: usa una sola de: estado, todo"; exit 2; }; MODO=$1; shift ;;
    -h|--help) MODO=ayuda; shift ;;
    -*) decir "ERROR: opcion desconocida: $1 (mira: actualizar ayuda)"; exit 2 ;;
    *) PEDIDOS_OBJ+=("$1"); shift ;;
  esac
done
[ "$MODO" = "ayuda" ] && { ayuda; exit 0; }
if [ "$(id -u)" = "0" ] && [ "$PRUEBAS" != "1" ]; then
  decir "ERROR: no lo corras como root ni con sudo. Escribe solo:  actualizar"
  decir "(el comando usa sudo por dentro cuando hace falta y te pide la contrasena una vez)"
  exit 1
fi
[ -n "$PEDIDO" ] && ! [[ "$PEDIDO" =~ ^[0-9a-f]{32}$ ]] && { decir "ERROR: --pedido invalido"; exit 2; }
[ -n "$MODO" ] && [ ${#PEDIDOS_OBJ[@]} -gt 0 ] && { decir "ERROR: no mezcles '$MODO' con nombres de programas"; exit 2; }

cargar_programas
PRESENTES=()
for id in "${IDS[@]}"; do presente "$id" && PRESENTES+=("$id"); done

# Objetivos: nombres de programa (o programa@commit)
declare -A FIJO=()
ELEGIDOS=()
for o in "${PEDIDOS_OBJ[@]}"; do
  p="${o%%@*}"; c=""; [ "$p" != "$o" ] && c="${o#*@}"
  if [ -z "${P_TIPO[$p]:-}" ]; then decir "ERROR: no conozco el programa '$p'. Programas en este servidor: ${PRESENTES[*]:-ninguno}"; exit 2; fi
  if ! presente "$p"; then decir "ERROR: $p no esta en este servidor (falta ${P_DIR[$p]}/.git)"; exit 2; fi
  if [ -n "$c" ] && ! [[ "$c" =~ ^[0-9a-f]{40}$ ]]; then decir "ERROR: version invalida para $p (debe ser el commit completo)"; exit 2; fi
  [ -n "${FIJO[$p]+x}" ] && continue
  FIJO[$p]="$c"; ELEGIDOS+=("$p")
done
[ ${#PRESENTES[@]} -eq 0 ] && { decir "No hay programas Tukan en este servidor."; exit 0; }

# --- estado: solo mira (no cambia nada, no usa el candado)
if [ "$MODO" = "estado" ]; then
  decir "Revisando GitHub (git fetch; no se cambia nada)..."
  for id in "${PRESENTES[@]}"; do revisar "$id"; done
  decir ""; tabla_estado
  for id in "${PRESENTES[@]}"; do
    [ "${R_ESTADO[$id]}" = "nuevo" ] && { decir ""; decir "$id - lo nuevo:"; lista_cambios "$id"; }
  done
  escribir_versiones
  if [ -f "$TRABAJO/actualizar.lock" ] && [ -w "$TRABAJO/actualizar.lock" ] && ! flock -n "$TRABAJO/actualizar.lock" true; then
    decir ""; decir "(ahora mismo hay una actualizacion corriendo)"
  fi
  exit 0
fi

# --- candado: una sola corrida a la vez
mkdir -p "$TRABAJO" 2>/dev/null
if ! exec 9>>"$TRABAJO/actualizar.lock"; then decir "ERROR: no puedo abrir el candado $TRABAJO/actualizar.lock (corre instalar_actualizador.sh)"; exit 1; fi
if ! flock -n 9; then decir "Ya hay otra actualizacion corriendo. Espera a que termine y vuelve a intentar."; exit 3; fi

# --- que actualizar
if [ ${#ELEGIDOS[@]} -eq 0 ]; then
  decir "Revisando GitHub..."
  CON_NUEVO=()
  for id in "${PRESENTES[@]}"; do revisar "$id"; [ "${R_ESTADO[$id]}" = "nuevo" ] && CON_NUEVO+=("$id"); done
  escribir_versiones
  hay_error=0; for id in "${PRESENTES[@]}"; do [ "${R_ESTADO[$id]}" = "error" ] && hay_error=1; done
  if [ ${#CON_NUEVO[@]} -eq 0 ]; then
    decir ""; [ "$hay_error" = "1" ] && tabla_estado && decir ""
    decir "Todo esta al dia. No hay nada que actualizar."; exit 0
  fi
  decir ""; decir "Hay version nueva en GitHub:"
  for id in "${CON_NUEVO[@]}"; do decir ""; decir "  $id: $(corto "${R_HEAD[$id]}") -> $(corto "${R_NUEVO[$id]}") (${R_N[$id]} cambios)"; lista_cambios "$id"; done
  for id in "${PRESENTES[@]}"; do [ "${R_ESTADO[$id]}" = "error" ] && decir "  $id: ERROR: ${R_MSG[$id]}"; done
  decir ""
  if [ "$MODO" != "todo" ]; then
    if [ ! -t 0 ]; then decir "Para actualizar sin preguntar escribe:  actualizar todo"; exit 0; fi
    read -r -p "Actualizar todos? [S/n] " resp
    case "$(printf '%s' "$resp" | tr 'A-Z' 'a-z')" in ""|s|si|y|yes) ;; *) decir "No se actualizo nada."; exit 0 ;; esac
  fi
  ELEGIDOS=("${CON_NUEVO[@]}")
fi
# El panel siempre al final
ORDEN=(); for id in "${IDS[@]}"; do for e in "${ELEGIDOS[@]}"; do [ "$id" = "$e" ] && ORDEN+=("$id"); done; done

# --- sudo una sola vez (el servicio del panel ya lo tiene permitido mientras corre)
if [ "$SERVICIO" = "1" ]; then
  $SUDO -n true < /dev/null 2>/dev/null || { decir "ERROR: sudo pide contrasena y el servicio no puede escribirla (revisa instalar_actualizador.sh)"; exit 1; }
else
  decir "Sudo puede pedirte tu contrasena (una vez):"
  $SUDO -v || { decir "ERROR: sin sudo no se puede actualizar."; exit 1; }
  ( exec 9>&- 8>&-; while sleep 50; do $SUDO -n -v < /dev/null 2>/dev/null || exit; done ) > /dev/null 2>&1 &
  MANTENER_SUDO=$!
fi

RES_TSV="$(mktemp)" || exit 1
preparar_sin_pull || { decir "ERROR: no se pudo preparar el comando git"; exit 1; }
terminar() { [ -n "${MANTENER_SUDO:-}" ] && kill "$MANTENER_SUDO" 2>/dev/null; [ -n "$SIN_PULL" ] && rm -rf "$SIN_PULL"; [ -n "$RES_TSV" ] && rm -f "$RES_TSV"; }
trap terminar EXIT
INICIO=$(date +%s)
FALLOS=0
for id in "${ORDEN[@]}"; do actualizar_uno "$id" "${FIJO[$id]:-}" || FALLOS=$((FALLOS+1)); done
FIN=$(date +%s)

decir ""
decir "=========== RESUMEN ==========="
printf '%-18s %-8s %-8s %s\n' "PROGRAMA" "ANTES" "AHORA" "RESULTADO"
for f in "${R_FILAS[@]}"; do
  IFS='|' read -r id est a d m <<< "$f"
  printf '%-18s %-8s %-8s %s\n' "$id" "$a" "$d" "$(texto_estado "$est")"
done
decir "==============================="
for f in "${R_FILAS[@]}"; do
  IFS='|' read -r id est a d m <<< "$f"
  case "$est" in ok|al_dia) ;; *) decir "$id: $m" ;; esac
done
[ "$FALLOS" -gt 0 ] && decir "Logs: $LOGS" || decir "Todo salio bien."

# Para el panel y el servicio
[ -n "$SALIDA_TSV" ] && cp "$RES_TSV" "$SALIDA_TSV" 2>/dev/null
if command -v php > /dev/null && [ -f "$AYUDANTE" ] && [ -d "$ESTADO" ] && [ -w "$ESTADO" ]; then
  php "$AYUDANTE" resultado "$ESTADO/ultima.json" "$([ -n "$PEDIDO" ] && echo panel || echo consola)" "$PEDIDO" "${USUARIO_P:-$(id -un)}" "$INICIO" "$FIN" < "$RES_TSV" 2>/dev/null || true
fi
for id in "${PRESENTES[@]}"; do revisar "$id" > /dev/null 2>&1; done
escribir_versiones

# Telegram (si el panel lo tiene configurado): solo si se intento actualizar algo
HUBO=0; TXT=""
for f in "${R_FILAS[@]}"; do
  IFS='|' read -r id est a d m <<< "$f"
  [ "$est" != "al_dia" ] && HUBO=1
  case "$est" in ok) TXT="$TXT
$id: OK $a -> $d" ;; al_dia) TXT="$TXT
$id: ya estaba al dia" ;; *) TXT="$TXT
$id: $(texto_estado "$est") - $m" ;; esac
done
if [ "$HUBO" = "1" ]; then
  ORIGEN="desde la consola ($(id -un))"; [ -n "$PEDIDO" ] && ORIGEN="desde el panel (${USUARIO_P:-dueno})"
  [ "$FALLOS" -gt 0 ] && CAB="Tukan: actualizacion con problemas" || CAB="Tukan: actualizacion lista"
  avisar_telegram "$CAB $ORIGEN en $(hostname)$TXT"
fi
find "$LOGS" -maxdepth 1 -name '*.log' ! -name 'actualizar.log' -mtime +90 -delete 2>/dev/null
[ "$FALLOS" -eq 0 ]
