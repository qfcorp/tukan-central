#!/usr/bin/env bash
# Pruebas del comando "actualizar" (deploy/actualizar.sh) y del servicio de pedidos (deploy/actualizar_pedidos.sh).
# Todo es de mentira y local: repos git (un origin "bare" y un clon por programa), instaladores falsos, paginas falsas
# (php -S), sudo/pg_dump/mysqldump/sqlite3 falsos y un Telegram falso. No toca el sistema: todas las rutas van por TUKAN_ACT_*.
# Uso: bash tests/run_actualizar.sh
set -u
cd "$(dirname "$0")/.." || exit 1
RAIZ="$(pwd)"
TMP="$(mktemp -d)"
PG=8291      # paginas falsas
TGP=8292     # Telegram falso
OK=0; FALLAS=0; PIDS=()
limpiar() { for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null; done; pkill -f "php -S 127.0.0.1:829[12]" 2>/dev/null; [ "${GUARDAR_TMP:-0}" = "1" ] && echo "Archivos de la prueba en $TMP" || rm -rf "$TMP"; }
trap limpiar EXIT
check() { if [ "$2" == "$3" ]; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — esperado [$2], salio [$3]"; fi; }
contiene() { if grep -q -- "$3" "$2" 2>/dev/null; then OK=$((OK+1)); echo "  OK    $1"; else FALLAS=$((FALLAS+1)); echo "  FALLA $1 — no aparece [$3] en $2"; [ -f "$2" ] && tail -20 "$2" | sed 's/^/        /'; fi; }
no_contiene() { if grep -q -- "$3" "$2" 2>/dev/null; then FALLAS=$((FALLAS+1)); echo "  FALLA $1 — aparece [$3]"; else OK=$((OK+1)); echo "  OK    $1"; fi; }
titulo() { echo; echo "== $1"; }
jq_php() { php -r '$j=json_decode(file_get_contents($argv[1]),true); $r=$j; foreach(explode(".",$argv[2]) as $k){ $r=is_array($r)&&array_key_exists($k,$r)?$r[$k]:null; } echo is_scalar($r)?var_export($r,true):json_encode($r,JSON_UNESCAPED_UNICODE);' "$1" "$2"; }

# ---------------------------------------------------------------- entorno de mentira
mkdir -p "$TMP"/{bin,origen,dev,www,github,nginx,estado,trabajo,respaldos,logs,pedidos,tomados,usados,tg,lib}
export TUKAN_ACT_PRUEBAS=1 TUKAN_ACT_SOLO_CONF=1
export TUKAN_ACT_PROGRAMAS="$TMP/programas.conf" TUKAN_ACT_ESTADO="$TMP/estado" TUKAN_ACT_TRABAJO="$TMP/trabajo"
export TUKAN_ACT_RESPALDOS="$TMP/respaldos" TUKAN_ACT_LOGS="$TMP/logs" TUKAN_ACT_NGINX="$TMP/nginx" TUKAN_ACT_WWW="$TMP/www"
export TUKAN_ACT_SUDO="$TMP/bin/sudo" TUKAN_ACT_PANEL_ENV="$TMP/panel.env" TUKAN_ACT_ESPERA_PAGINA=2 TUKAN_ACT_LIB="$TMP/lib"
export TUKAN_ACT_PEDIDOS="$TMP/pedidos" TUKAN_ACT_TOMADOS="$TMP/tomados" TUKAN_ACT_USADOS="$TMP/usados"
export TUKAN_ACT_LLAVE="$TMP/llave" TUKAN_ACT_AJUSTES="$TMP/nada.conf" TUKAN_ACT_SUDOERS="$TMP/sudoers-temporal"
export TUKAN_ACT_COMANDO="$RAIZ/deploy/actualizar.sh" TUKAN_ACT_SIN_ROOT=1
export TUKAN_TEST_DIR="$TMP" TUKAN_TEST_REG="$TMP/instalador.log" TUKAN_TEST_SUDOERS="$TMP/sudoers-temporal"
export GIT_AUTHOR_NAME=Prueba GIT_AUTHOR_EMAIL=prueba@tukan.ec GIT_COMMITTER_NAME=Prueba GIT_COMMITTER_EMAIL=prueba@tukan.ec
export PATH="$TMP/bin:$PATH" TZ=America/Guayaquil
ACT="$RAIZ/deploy/actualizar.sh"
PED="$RAIZ/deploy/actualizar_pedidos.sh"
openssl rand -hex 32 > "$TMP/llave"
printf 'TELEGRAM_BOT_TOKEN=prueba123\nTELEGRAM_CHAT_ID=999\nTELEGRAM_API_BASE=http://127.0.0.1:%s\n' "$TGP" > "$TMP/panel.env"

# sudo falso: quita las opciones (-v -n -u USUARIO) y corre el comando como el usuario actual
cat > "$TMP/bin/sudo" <<'EOF'
#!/bin/bash
echo "sudo $*" >> "$TUKAN_TEST_DIR/sudo.log"
while [ $# -gt 0 ]; do case "$1" in -v|-n) shift ;; -u) shift 2 ;; --) shift; break ;; -*) shift ;; *) break ;; esac; done
[ $# -eq 0 ] && exit 0
exec "$@"
EOF
# pg_dump / mysqldump / sqlite3 falsos (FALLA_PG_DUMP=1 hace fallar el respaldo)
cat > "$TMP/bin/pg_dump" <<'EOF'
#!/bin/bash
echo "pg_dump $* PGPASSWORD=${PGPASSWORD:-}" >> "$TUKAN_TEST_DIR/respaldos.log"
[ "${FALLA_PG_DUMP:-0}" = "1" ] && { echo "pg_dump: error de mentira" >&2; exit 1; }
f=""; while [ $# -gt 0 ]; do [ "$1" = "-f" ] && f="$2"; shift; done
if [ -n "$f" ]; then echo "VOLCADO DE MENTIRA" > "$f"; else echo "VOLCADO DE MENTIRA"; fi
EOF
cat > "$TMP/bin/mysqldump" <<'EOF'
#!/bin/bash
echo "mysqldump $* MYSQL_PWD=${MYSQL_PWD:-}" >> "$TUKAN_TEST_DIR/respaldos.log"
echo "-- volcado de mentira"
EOF
cat > "$TMP/bin/sqlite3" <<'EOF'
#!/bin/bash
echo "sqlite3 $*" >> "$TUKAN_TEST_DIR/respaldos.log"
echo "-- sqlite de mentira"
EOF
chmod +x "$TMP/bin/"*

# Paginas falsas: 200, salvo que exista $TMP/rota_<p> (lo crea el instalador falso cuando el commit trae PAGINA_ROTA)
# o $TMP/siempre_rota_<p>. Guarda el Host de cada visita.
cat > "$TMP/pagina.php" <<'EOF'
<?php
$p = $_GET['p'] ?? (str_contains($_SERVER['REQUEST_URI'], 'login.php') ? 'guarderia' : 'x');
file_put_contents(getenv('TUKAN_TEST_DIR') . '/visitas.log', $p . ' ' . ($_SERVER['HTTP_HOST'] ?? '') . "\n", FILE_APPEND);
$rota = is_file(getenv('TUKAN_TEST_DIR') . "/rota_$p") || is_file(getenv('TUKAN_TEST_DIR') . "/siempre_rota_$p");
http_response_code($rota ? 500 : 200);
echo $rota ? 'rota' : 'ok';
EOF
mkdir -p "$TMP/tg_dir"
(cd "$TMP" && TUKAN_TEST_DIR="$TMP" exec php -S "127.0.0.1:$PG" pagina.php > "$TMP/pagina_srv.log" 2>&1) & PIDS+=($!)
env -i PATH="$PATH" MOCK_DIR="$TMP/tg" php -S "127.0.0.1:$TGP" tests/mock/router.php > "$TMP/tg_srv.log" 2>&1 & PIDS+=($!)
for _ in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$PG/" && curl -s -o /dev/null "http://127.0.0.1:$TGP/" && break; sleep 0.1; done

# Instalador falso (va dentro de cada repo): anota quien lo corrio y en que version; su "git pull" lo debe omitir actualizar
INSTALADOR='#!/bin/bash
S="$(cd "$(dirname "$0")" && pwd)"; if [ "$(basename "$S")" = "deploy" ]; then D="$(dirname "$S")"; else D="$S"; fi
N="$(basename "$D")"
git -C "$D" pull -q 2>&1
echo "$N $(git -C "$D" rev-parse HEAD) args=$* GP=${GOOGLE_PLAY:-} SUDOERS=$([ -e "$TUKAN_TEST_SUDOERS" ] && echo si || echo no)" >> "$TUKAN_TEST_REG"
P="$N"; [ "$N" = "tuguarderia" ] && P=guarderia
if [ -f "$D/PAGINA_ROTA" ]; then touch "$TUKAN_TEST_DIR/rota_$P"; else rm -f "$TUKAN_TEST_DIR/rota_$P"; fi
[ -n "${TUKAN_TEST_ESPERA:-}" ] && sleep "$TUKAN_TEST_ESPERA"
if [ -f "$D/INSTALADOR_FALLA" ]; then echo "ERROR: falla de mentira"; exit 1; fi
echo "instalado $N"'

# nuevo_repo ID TIPO(deploy|guarderia) CARPETA_FINAL
nuevo_repo() {
  local id=$1 tipo=$2 dir=$3
  git init -q --bare -b main "$TMP/origen/$id.git"
  git clone -q "$TMP/origen/$id.git" "$TMP/dev/$id" 2> /dev/null
  if [ "$tipo" = "guarderia" ]; then printf '%s\n' "$INSTALADOR" > "$TMP/dev/$id/instalar.sh"
  else mkdir -p "$TMP/dev/$id/deploy"; printf '%s\n' "$INSTALADOR" > "$TMP/dev/$id/deploy/instalar.sh"; fi
  printf 'config/.env\n' > "$TMP/dev/$id/.gitignore"
  echo "v1" > "$TMP/dev/$id/VERSION"
  git -C "$TMP/dev/$id" add -A && git -C "$TMP/dev/$id" commit -qm "Primera versión de $id" && git -C "$TMP/dev/$id" push -q origin main 2> /dev/null
  git clone -q "$TMP/origen/$id.git" "$dir" 2> /dev/null
}
# commit ID "titulo" [archivo-a-agregar|-archivo-a-quitar]
commit() {
  local id=$1 t=$2 a=${3:-}
  date +%s%N >> "$TMP/dev/$id/VERSION"
  case "$a" in -*) git -C "$TMP/dev/$id" rm -q "${a#-}" ;; "") ;; *) touch "$TMP/dev/$id/$a" ;; esac
  git -C "$TMP/dev/$id" add -A && git -C "$TMP/dev/$id" commit -qm "$t" && git -C "$TMP/dev/$id" push -q origin main 2> /dev/null
}
head_de() { git -C "$1" rev-parse HEAD; }
origen_de() { git -C "$TMP/dev/$1" rev-parse HEAD; }

nuevo_repo tuevento deploy "$TMP/www/tuevento"
nuevo_repo tuspa deploy "$TMP/www/tuspa"
nuevo_repo tukan-central deploy "$TMP/www/tukan-central"
nuevo_repo tuguarderia guarderia "$TMP/github/tuguarderia"
mkdir -p "$TMP/www/tuevento/config" "$TMP/www/tuspa/config" "$TMP/www/tukan-central/config" "$TMP/datos_panel"
printf 'TUEVENTO_DB_HOST=127.0.0.1\nTUEVENTO_DB_NAME=tuevento\nTUEVENTO_DB_USER=tuevento\nTUEVENTO_DB_PASS=clave123\nTUEVENTO_URL=https://eventos.ejemplo.com\nTUKAN_CENTRAL_URL=https://panel.tukanec.com\nGOOGLE_PLAY_ACTIVO=1\n' > "$TMP/www/tuevento/config/.env"
printf 'TUSPA_DB_DRIVER=mysql\nTUSPA_DB_HOST=localhost\nTUSPA_DB_NAME=tuspa\nTUSPA_DB_USER=tuspa\nTUSPA_DB_PASS=claveSpa\n' > "$TMP/www/tuspa/config/.env"
printf 'TUKAN_CENTRAL_KEY=x\nTUKAN_CENTRAL_URL=https://panel.tukanec.com\nTUKAN_CENTRAL_DATOS=%s\n' "$TMP/datos_panel" > "$TMP/www/tukan-central/config/.env"
echo "base" > "$TMP/datos_panel/central.sqlite"
# Dos instalaciones de TuGuarderia (las encuentra por los sitios de nginx que hace su instalar.sh); guau tiene Google Play
for c in guau miau; do
  mkdir -p "$TMP/www/$c/app"
  printf '# %s - generado por instalar.sh (TuGuardería · Tukán Software)\nserver {\n    listen 127.0.0.1:%s;\n    server_name %s.tukanec.com;\n}\n' "$c" "$PG" "$c" > "$TMP/nginx/$c"
done
echo "ACTIVO=1" > "$TMP/www/guau/app/android.txt"
printf 'server { listen 80; server_name otro; }\n' > "$TMP/nginx/otro-sitio"
cat > "$TMP/programas.conf" <<EOF
# programas de la prueba
tuevento|TuEvento|deploy|$TMP/www/tuevento|http://127.0.0.1:$PG/?p=tuevento|TUEVENTO_URL|tuevento.tukanec.com
tuspa|TuSpa|deploy|$TMP/www/tuspa|http://127.0.0.1:$PG/?p=tuspa||
tuplanner|TuPlanner|deploy|$TMP/www/no-existe|http://127.0.0.1:$PG/?p=tuplanner||
tukan-central|Panel Tukan Central|deploy|$TMP/www/tukan-central|http://127.0.0.1:$PG/?p=tukan-central|TUKAN_CENTRAL_URL|panel.tukanec.com
tuguarderia|TuGuarderia|guarderia|$TMP/github/tuguarderia|/login.php||
EOF
n_reg() { [ -f "$TUKAN_TEST_REG" ] && wc -l < "$TUKAN_TEST_REG" | tr -d ' ' || echo 0; }

# =====================================================================
titulo "A1. Sintaxis (bash -n) de los scripts del actualizador"
for f in deploy/actualizar.sh deploy/actualizar_pedidos.sh deploy/instalar_actualizador.sh deploy/instalar.sh tests/run_actualizar.sh; do
  check "bash -n $f" "0" "$(bash -n "$f" 2>&1 > /dev/null; echo $?)"
done
check "php -l del ayudante" "0" "$(php -l deploy/actualizar_ayudante.php > /dev/null 2>&1; echo $?)"
check "Mensajes de los scripts sin tildes ni n" "0" "$(grep -hE '(decir|echo|anotar) ' deploy/actualizar.sh deploy/actualizar_pedidos.sh deploy/instalar_actualizador.sh | grep -c '[áéíóúñÁÉÍÓÚÑ]')"

titulo "A2. Sin cambios en GitHub: no hace nada"
bash "$ACT" estado > "$TMP/s.txt" 2>&1; RC=$?
check "estado: termina bien" "0" "$RC"
contiene "estado: tuevento al dia" "$TMP/s.txt" "^tuevento .* al dia"
contiene "estado: TuGuarderia con sus 2 instalaciones" "$TMP/s.txt" "tuguarderia .*al dia (guau miau)"
no_contiene "estado: un programa que no esta en el servidor no aparece" "$TMP/s.txt" "tuplanner"
check "estado: versiones.json para el panel" "'al_dia'" "$(jq_php "$TMP/estado/versiones.json" programas.0.estado)"
check "versiones.json: 4 programas" "4" "$(php -r 'echo count(json_decode(file_get_contents($argv[1]),true)["programas"]);' "$TMP/estado/versiones.json")"
check "versiones.json: legible solo por el grupo (640)" "640" "$(stat -c %a "$TMP/estado/versiones.json")"
bash "$ACT" > "$TMP/s.txt" 2>&1; RC=$?
check "actualizar sin nada nuevo: termina bien" "0" "$RC"
contiene "...dice que todo esta al dia" "$TMP/s.txt" "Todo esta al dia. No hay nada que actualizar."
bash "$ACT" todo > "$TMP/s.txt" 2>&1
contiene "actualizar todo sin nada nuevo: al dia" "$TMP/s.txt" "Todo esta al dia"
check "...no corrio ningun instalador" "0" "$(n_reg)"
check "...no hizo respaldos" "0" "$(find "$TMP/respaldos" -type f | wc -l | tr -d ' ')"
check "...no aviso por Telegram" "0" "$(cat "$TMP/tg/telegram.log" 2>/dev/null | wc -l | tr -d ' ')"

titulo "A3. Detectar lo nuevo (git fetch; estado no cambia el codigo)"
ANTES_TE="$(head_de "$TMP/www/tuevento")"
commit tuevento "Añade reservas por WhatsApp"
commit tuevento "Corrige el cálculo del depósito"
bash "$ACT" estado > "$TMP/s.txt" 2>&1
contiene "estado: 2 cambios nuevos en tuevento" "$TMP/s.txt" "^tuevento .* 2 cambios nuevos"
contiene "estado: titulos de los commits sin tildes" "$TMP/s.txt" "- [0-9a-f]* Anade reservas por WhatsApp"
contiene "...el otro titulo" "$TMP/s.txt" "Corrige el calculo del deposito"
check "estado: NO cambia el codigo" "$ANTES_TE" "$(head_de "$TMP/www/tuevento")"
check "versiones.json: tuevento con algo nuevo" "'nuevo'" "$(jq_php "$TMP/estado/versiones.json" programas.0.estado)"
check "versiones.json: 2 nuevos" "2" "$(jq_php "$TMP/estado/versiones.json" programas.0.nuevos)"
check "versiones.json: commit disponible = GitHub" "'$(origen_de tuevento)'" "$(jq_php "$TMP/estado/versiones.json" programas.0.disponible.commit)"
check "versiones.json: titulos con tildes (para el panel)" "'Corrige el cálculo del depósito'" "$(jq_php "$TMP/estado/versiones.json" programas.0.cambios.0.titulo)"
printf 'n\n' | script -qec "bash $ACT" /dev/null > "$TMP/s.txt" 2>&1
contiene "actualizar (sin nada): pregunta Actualizar todos? [S/n]" "$TMP/s.txt" "Actualizar todos? \[S/n\]"
contiene "...con n no actualiza" "$TMP/s.txt" "No se actualizo nada."
check "...el codigo sigue igual" "$ANTES_TE" "$(head_de "$TMP/www/tuevento")"
bash "$ACT" < /dev/null > "$TMP/s.txt" 2>&1
contiene "Sin terminal (sin poder preguntar): no actualiza y sugiere 'actualizar todo'" "$TMP/s.txt" "actualizar todo"

titulo "A4. Actualizar uno: respaldo, instalador, pagina 200"
ls -1 "$TMP/respaldos" > /dev/null
mkdir -p "$TMP/respaldos/tuevento"
for i in $(seq 1 12); do f="$TMP/respaldos/tuevento/tuevento_2020010${i}_000000_viejo$i.dump"; echo viejo > "$f"; touch -d "2020-01-01 +$i days" "$f"; done
bash "$ACT" tuevento > "$TMP/s.txt" 2>&1; RC=$?
check "actualizar tuevento: termina bien" "0" "$RC"
NUEVO_TE="$(origen_de tuevento)"
check "...el codigo quedo en la version de GitHub" "$NUEVO_TE" "$(head_de "$TMP/www/tuevento")"
contiene "...respaldo con pg_dump y el usuario de la app" "$TMP/respaldos.log" "pg_dump -h 127.0.0.1 -p 5432 -U tuevento -Fc -f .* tuevento PGPASSWORD=clave123"
check "...respaldo guardado (formato de pg_dump)" "1" "$(ls "$TMP/respaldos/tuevento/"tuevento_*_"${ANTES_TE:0:7}".dump 2>/dev/null | wc -l | tr -d ' ')"
check "...solo los ultimos 10 respaldos" "10" "$(ls "$TMP/respaldos/tuevento" | wc -l | tr -d ' ')"
check "...el mas nuevo se conserva y los 3 mas viejos se borraron" "0 1" "$(ls "$TMP/respaldos/tuevento" | grep -c 'viejo[123]\.dump$') $(ls "$TMP/respaldos/tuevento" | grep -c "${ANTES_TE:0:7}")"
check "...respaldo solo para el dueno (600)" "600" "$(stat -c %a "$(ls -t "$TMP/respaldos/tuevento/"* | head -1)")"
check "...el instalador corrio una vez, ya en la version nueva" "tuevento $NUEVO_TE" "$(tail -1 "$TUKAN_TEST_REG" | awk '{print $1, $2}')"
contiene "...Google Play se conserva (estaba activada)" "$TUKAN_TEST_REG" "GP=si"
LOG_TE="$(ls -t "$TMP/logs/"*_tuevento.log | head -1)"
contiene "...log del instalador en /var/log/tukan-actualizar" "$LOG_TE" "instalado tuevento"
contiene "...el git pull del instalador se omite (version fijada)" "$LOG_TE" "se omite git pull"
contiene "...probo la pagina con su direccion (Host del .env)" "$TMP/visitas.log" "tuevento eventos.ejemplo.com"
contiene "...resumen en tabla" "$TMP/s.txt" "^tuevento .*${ANTES_TE:0:7} .*${NUEVO_TE:0:7} .*OK"
contiene "...registro de corridas" "$TMP/logs/actualizar.log" "consola tuevento ${ANTES_TE:0:7}->${NUEVO_TE:0:7} ok"
check "...ultima.json: desde la consola" "'consola'" "$(jq_php "$TMP/estado/ultima.json" origen)"
check "...ultima.json: ok" "'ok'" "$(jq_php "$TMP/estado/ultima.json" programas.0.estado)"
check "...versiones.json queda al dia" "'al_dia'" "$(jq_php "$TMP/estado/versiones.json" programas.0.estado)"
contiene "...aviso por Telegram" "$TMP/tg/telegram.log" "Tukan: actualizacion lista desde la consola"
contiene "...con el programa y las versiones" "$TMP/tg/telegram.log" "tuevento: OK ${ANTES_TE:0:7} -> ${NUEVO_TE:0:7}"
no_contiene "...el token de Telegram no aparece en el log" "$TMP/logs/actualizar.log" "prueba123"
bash "$ACT" tuevento > "$TMP/s.txt" 2>&1
contiene "Otra vez: ya esta al dia (no hace nada)" "$TMP/s.txt" "tuevento: ya esta al dia"
check "...no corrio el instalador otra vez" "1" "$(n_reg)"

titulo "A5. Si falla, vuelve atras"
ANTES_TE="$(head_de "$TMP/www/tuevento")"
commit tuevento "Cambio que rompe la página" PAGINA_ROTA
N0=$(n_reg)
bash "$ACT" tuevento > "$TMP/s.txt" 2>&1; RC=$?
check "Pagina rota: termina con error" "1" "$RC"
check "...volvio al commit anterior" "$ANTES_TE" "$(head_de "$TMP/www/tuevento")"
check "...corrio el instalador 2 veces (nueva y anterior)" "$((N0+2))" "$(n_reg)"
check "...la segunda vez en la version anterior" "tuevento $ANTES_TE" "$(tail -1 "$TUKAN_TEST_REG" | awk '{print $1, $2}')"
check "...la pagina responde otra vez" "no" "$([ -f "$TMP/rota_tuevento" ] && echo si || echo no)"
contiene "...lo dice claro" "$TMP/s.txt" "VOLVIO ATRAS: tuevento sigue en ${ANTES_TE:0:7} y responde bien"
contiene "...resumen: FALLO: volvio a la version anterior" "$TMP/s.txt" "tuevento .*FALLO: volvio a la version anterior"
contiene "...dice donde quedo el respaldo de la base" "$TMP/s.txt" "Respaldo de la base: $TMP/respaldos/tuevento/"
contiene "...Telegram: con problemas" "$TMP/tg/telegram.log" "Tukan: actualizacion con problemas"
contiene "...log con la vuelta atras" "$(ls -t "$TMP/logs/"*_tuevento.log | head -1)" "VOLVER ATRAS a $ANTES_TE"
commit tuevento "Arregla la página" -PAGINA_ROTA
commit tuevento "Instalador que falla" INSTALADOR_FALLA
bash "$ACT" tuevento > "$TMP/s.txt" 2>&1; RC=$?
check "Instalador con error: vuelve atras" "1 $ANTES_TE" "$RC $(head_de "$TMP/www/tuevento")"
contiene "...motivo" "$TMP/s.txt" "FALLO: el instalador termino con error"
commit tuevento "Quita la falla" -INSTALADOR_FALLA
touch "$TMP/siempre_rota_tuevento"
bash "$ACT" tuevento > "$TMP/s.txt" 2>&1; RC=$?
check "Rota aun en la version anterior: error" "1" "$RC"
contiene "...avisa FALLO GRAVE" "$TMP/s.txt" "tuevento .*FALLO GRAVE: revisa ya"
contiene "...y que revise ya" "$TMP/s.txt" "SIGUE FALLANDO"
rm -f "$TMP/siempre_rota_tuevento"
N0=$(n_reg)
FALLA_PG_DUMP=1 bash "$ACT" tuevento > "$TMP/s.txt" 2>&1; RC=$?
check "Sin respaldo: NO se actualiza" "1 $ANTES_TE $N0" "$RC $(head_de "$TMP/www/tuevento") $(n_reg)"
contiene "...lo dice" "$TMP/s.txt" "NO se actualizo (sin respaldo)"
bash "$ACT" tuevento > "$TMP/s.txt" 2>&1
check "Con todo bien: ahora si queda en la ultima" "$(origen_de tuevento)" "$(head_de "$TMP/www/tuevento")"

titulo "A6. Candado (flock): no se encima con otra corrida"
commit tuspa "Spa: reportes nuevos"
flock "$TMP/trabajo/actualizar.lock" sleep 4 &
CPID=$!; sleep 0.5
bash "$ACT" todo > "$TMP/s.txt" 2>&1; RC=$?
check "Con otra corrida: sale con codigo 3" "3" "$RC"
contiene "...lo dice" "$TMP/s.txt" "Ya hay otra actualizacion corriendo"
bash "$ACT" estado > "$TMP/s.txt" 2>&1
contiene "estado avisa que hay una corriendo" "$TMP/s.txt" "ahora mismo hay una actualizacion corriendo"
wait "$CPID"
check "...tuspa no se toco" "1" "$(git -C "$TMP/www/tuspa" rev-list --count HEAD)"

titulo "A7. actualizar todo: MySQL, SQLite y TuGuarderia (varias instalaciones); el panel al final"
commit tukan-central "Panel: pantalla nueva"
commit tuguarderia "Guardería: fotos más rápidas"
: > "$TUKAN_TEST_REG"; : > "$TMP/respaldos.log"
bash "$ACT" todo > "$TMP/s.txt" 2>&1; RC=$?
check "actualizar todo: termina bien" "0" "$RC"
check "...orden: tuspa, tuguarderia (guau, miau) y el panel al final" "tuspa tuguarderia tuguarderia tukan-central" "$(awk '{print $1}' "$TUKAN_TEST_REG" | paste -sd' ')"
contiene "...TuSpa (MySQL): mysqldump con su usuario" "$TMP/respaldos.log" "mysqldump -h localhost -u tuspa --single-transaction --no-tablespaces tuspa MYSQL_PWD=claveSpa"
check "...respaldo .sql.gz de TuSpa" "1" "$(ls "$TMP/respaldos/tuspa/"*.sql.gz | wc -l | tr -d ' ')"
contiene "...Panel (SQLite)" "$TMP/respaldos.log" "sqlite3 $TMP/datos_panel/central.sqlite .dump"
contiene "...TuGuarderia: una base por instalacion (guau)" "$TMP/respaldos.log" "mysqldump --no-tablespaces --single-transaction guau"
contiene "...(miau)" "$TMP/respaldos.log" "mysqldump --no-tablespaces --single-transaction miau"
contiene "...instalar.sh DOMINIO CARPETA con sudo" "$TMP/sudo.log" "sudo env GOOGLE_PLAY=si bash $TMP/github/tuguarderia/instalar.sh guau.tukanec.com guau"
contiene "...miau sin Google Play" "$TUKAN_TEST_REG" "args=miau.tukanec.com miau GP=no"
check "...todos en la ultima version" "ok" "$([ "$(head_de "$TMP/www/tuspa")" = "$(origen_de tuspa)" ] && [ "$(head_de "$TMP/github/tuguarderia")" = "$(origen_de tuguarderia)" ] && [ "$(head_de "$TMP/www/tukan-central")" = "$(origen_de tukan-central)" ] && echo ok)"
contiene "...probo las paginas de TuGuarderia en su puerto" "$TMP/visitas.log" "guarderia 127.0.0.1:$PG"

titulo "A8. Version fijada (la que aprobo el dueno) y errores de uso"
commit tuspa "Spa: otra cosa"
APROBADA="$(origen_de tuspa)"
commit tuspa "Spa: llego despues de aprobar"
ANTES_SPA="$(head_de "$TMP/www/tuspa")"
bash "$ACT" "tuspa@$APROBADA" > "$TMP/s.txt" 2>&1; RC=$?
check "GitHub cambio despues de aprobar: no se actualiza" "1 $ANTES_SPA" "$RC $(head_de "$TMP/www/tuspa")"
contiene "...lo explica" "$TMP/s.txt" "distinta a la aprobada"
bash "$ACT" "tuspa@$(origen_de tuspa)" > "$TMP/s.txt" 2>&1; RC=$?
check "Con la version exacta: se actualiza" "0 $(origen_de tuspa)" "$RC $(head_de "$TMP/www/tuspa")"
bash "$ACT" tuvet > "$TMP/s.txt" 2>&1; RC=$?
check "Programa desconocido: codigo 2" "2" "$RC"
contiene "...lo dice" "$TMP/s.txt" "no conozco el programa 'tuvet'"
bash "$ACT" tuplanner > "$TMP/s.txt" 2>&1; RC=$?
check "Programa que no esta en este servidor: codigo 2" "2" "$RC"
bash "$ACT" "tuspa@abc" > /dev/null 2>&1; check "Version mal escrita: codigo 2" "2" "$?"
if [ "$(id -u)" = "0" ]; then
  TUKAN_ACT_PRUEBAS=0 bash "$ACT" estado > "$TMP/s.txt" 2>&1; RC=$?
  check "Como root: no lo corre" "1" "$RC"
  contiene "...dice que lo corra sin sudo" "$TMP/s.txt" "no lo corras como root ni con sudo"
fi
bash "$ACT" ayuda > "$TMP/s.txt" 2>&1
contiene "ayuda" "$TMP/s.txt" "actualizar estado"

# =====================================================================
# Pedidos del panel (servicio tukan-actualizar). pedido TIPO 'prog=commit,...' [creado] -> deja el archivo y devuelve el id
pedido() {
  php -r '
    require $argv[1];
    $obj = [];
    foreach (array_filter(explode(",", $argv[3])) as $x) { [$p, $c] = explode("=", $x); $obj[$p] = $c; }
    $llave = Tukan\Pedido::leerLlave($argv[4]);
    $ahora = (int) ($argv[5] ?: time());
    $p = Tukan\Pedido::crear($argv[2], $obj, "dimitryfierro@gmail.com", $llave, $ahora);
    file_put_contents($argv[6] . "/" . $ahora . "-" . $p["id"] . ".json", $p["contenido"]);
    echo $p["id"];' "$RAIZ/src/Pedido.php" "$1" "$2" "${4:-$TMP/llave}" "${3:-}" "$TMP/pedidos"
}
titulo "A9. Servicio: pedido valido del panel"
commit tuevento "Evento: pedido desde el panel"
: > "$TUKAN_TEST_REG"
ID="$(pedido actualizar "tuevento=$(origen_de tuevento)")"
check "El pedido queda en la cola" "1" "$(ls "$TMP/pedidos/"*.json | wc -l | tr -d ' ')"
bash "$PED" > "$TMP/s.txt" 2>&1
check "...el servicio lo saca de la cola" "0" "$(ls "$TMP/pedidos/" | wc -l | tr -d ' ')"
check "...actualizo tuevento" "$(origen_de tuevento)" "$(head_de "$TMP/www/tuevento")"
check "...resultado para el panel: listo" "'listo'" "$(jq_php "$TMP/estado/pedido-$ID.json" estado)"
check "...con el programa ok" "'ok'" "$(jq_php "$TMP/estado/pedido-$ID.json" programas.0.estado)"
check "...desde el panel" "'panel'" "$(jq_php "$TMP/estado/pedido-$ID.json" origen)"
check "...y quien lo pidio" "'dimitryfierro@gmail.com'" "$(jq_php "$TMP/estado/pedido-$ID.json" usuario)"
contiene "...log para el panel" "$TMP/estado/pedido-$ID.log" "LISTO: tuevento quedo en"
contiene "...sudo sin contrasena SOLO mientras corria (el instalador lo vio)" "$TUKAN_TEST_REG" "SUDOERS=si"
check "...y se quito al terminar" "no" "$([ -e "$TMP/sudoers-temporal" ] && echo si || echo no)"
contiene "...regla temporal limitada al usuario del pedido" "$TMP/s.txt" "pedido $ID (actualizar)"
contiene "...Telegram: desde el panel" "$TMP/tg/telegram.log" "desde el panel (dimitryfierro@gmail.com)"
contiene "...registro del servicio" "$TMP/logs/pedidos.log" "pedido $ID: terminado (codigo 0)"
check "...el id queda usado" "1" "$(ls "$TMP/usados/$ID" 2>/dev/null | wc -l | tr -d ' ')"

titulo "A10. Servicio: pedidos falsos, vencidos o repetidos se rechazan"
commit tuevento "Evento: no deberia instalarse"
ANTES_TE="$(head_de "$TMP/www/tuevento")"; N0=$(n_reg)
openssl rand -hex 32 > "$TMP/otra_llave"
IDF="$(pedido actualizar "tuevento=$(origen_de tuevento)" "" "$TMP/otra_llave")"
bash "$PED" > "$TMP/s.txt" 2>&1
check "Firma de otra llave: no se actualiza" "$ANTES_TE $N0" "$(head_de "$TMP/www/tuevento") $(n_reg)"
contiene "...se anota el rechazo" "$TMP/logs/pedidos.log" "RECHAZADO .*$IDF.json: firma invalida"
check "...no deja resultado a nombre de ese id" "no" "$([ -e "$TMP/estado/pedido-$IDF.json" ] && echo si || echo no)"
IDV="$(pedido actualizar "tuevento=$(origen_de tuevento)" "$(( $(date +%s) - 1200 ))")"
bash "$PED" > /dev/null 2>&1
check "Pedido vencido (20 min): no se actualiza" "$ANTES_TE" "$(head_de "$TMP/www/tuevento")"
check "...resultado: rechazado" "'rechazado'" "$(jq_php "$TMP/estado/pedido-$IDV.json" estado)"
check "...motivo: vencido" "'pedido vencido (pasaron mas de 10 minutos)'" "$(jq_php "$TMP/estado/pedido-$IDV.json" motivo)"
# Repetido: se vuelve a dejar el mismo archivo del pedido valido de A9
cp "$(ls -t "$TMP/tomados/"*"$ID".json | head -1)" "$TMP/pedidos/$(date +%s)-$ID.json"
bash "$PED" > /dev/null 2>&1
check "Pedido repetido (el mismo de antes): no se actualiza" "$ANTES_TE $N0" "$(head_de "$TMP/www/tuevento") $(n_reg)"
contiene "...rechazado por repetido" "$TMP/logs/pedidos.log" "pedido repetido (ya se uso)"
check "...el resultado del pedido original no se toca" "'listo'" "$(jq_php "$TMP/estado/pedido-$ID.json" estado)"
# Cambiado a mano: se cambia el programa dentro de los datos (sin la llave no se puede volver a firmar)
IDC="$(pedido actualizar "tuspa=$(origen_de tuspa)")"
F="$(ls "$TMP/pedidos/"*"$IDC".json)"
php -r '$j=json_decode(file_get_contents($argv[1]),true); $d=json_decode(base64_decode(strtr($j["datos"],"-_","+/")),true); $d["objetivos"]=["tuevento"=>$argv[2]]; $j["datos"]=rtrim(strtr(base64_encode(json_encode($d)),"+/","-_"),"="); file_put_contents($argv[1], json_encode($j));' "$F" "$(origen_de tuevento)"
bash "$PED" > /dev/null 2>&1
check "Pedido cambiado a mano: no se actualiza" "$ANTES_TE" "$(head_de "$TMP/www/tuevento")"
contiene "...firma invalida" "$TMP/logs/pedidos.log" "RECHAZADO .*$IDC.json: firma invalida"
echo 'no es json' > "$TMP/pedidos/$(date +%s)-basura.json"
bash "$PED" > /dev/null 2>&1
contiene "Archivo basura: rechazado" "$TMP/logs/pedidos.log" "basura.json: pedido con formato malo"
check "...la cola queda vacia" "0" "$(ls "$TMP/pedidos/" | wc -l | tr -d ' ')"
IDR="$(pedido actualizar "tuevento=0000000000000000000000000000000000000000")"
bash "$PED" > /dev/null 2>&1
check "Pedido con una version que no es la de GitHub: fallo sin tocar nada" "'fallo' $ANTES_TE" "$(jq_php "$TMP/estado/pedido-$IDR.json" estado) $(head_de "$TMP/www/tuevento")"
check "...el programa queda rechazado" "'rechazado'" "$(jq_php "$TMP/estado/pedido-$IDR.json" programas.0.estado)"

titulo "A11. Servicio: pedido de revisar (solo git fetch) y candado ocupado"
IDS="$(pedido revisar "")"
bash "$PED" > /dev/null 2>&1
check "Revisar: listo" "'listo'" "$(jq_php "$TMP/estado/pedido-$IDS.json" estado)"
check "...no cambia el codigo" "$ANTES_TE" "$(head_de "$TMP/www/tuevento")"
check "...versiones.json ve lo nuevo" "'nuevo'" "$(jq_php "$TMP/estado/versiones.json" programas.0.estado)"
contiene "...log con la tabla" "$TMP/estado/pedido-$IDS.log" "PROGRAMA"
flock "$TMP/trabajo/actualizar.lock" sleep 3 &
CPID=$!; sleep 0.5
IDL="$(pedido actualizar "tuevento=$(origen_de tuevento)")"
bash "$PED" > /dev/null 2>&1
wait "$CPID"
check "Con otra corrida: el pedido falla sin tocar nada" "'fallo' $ANTES_TE" "$(jq_php "$TMP/estado/pedido-$IDL.json" estado) $(head_de "$TMP/www/tuevento")"
contiene "...motivo: hay otra corriendo" "$TMP/estado/pedido-$IDL.json" "Ya hay otra actualizacion corriendo"
check "...y no deja sudo abierto" "no" "$([ -e "$TMP/sudoers-temporal" ] && echo si || echo no)"

echo
echo "----------------------------------------"
echo "Actualizador — correctas: $OK  ·  Fallas: $FALLAS"
[ "$FALLAS" -eq 0 ]
