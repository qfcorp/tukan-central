#!/usr/bin/env bash
# Configura la inteligencia artificial de Tukan (modulo "Asistente IA" y la Ayuda) para TODOS los programas del servidor.
# Se corre en el servidor (qfcorp) con tu usuario normal (no root); se puede repetir para cambiar datos.
#   cd /var/www/tukan-central && git pull && bash deploy/configurar_ia.sh
# Pregunta la clave de Anthropic (no se ve al escribir), los modelos y si queda en pausa; escribe /etc/tukan-ia.env
# (root:www-data, 640) y hace una llamada de PRUEBA minima al modelo rapido.
# Otras formas:
#   bash deploy/configurar_ia.sh --pausar            corta TODO el gasto de IA de todos los programas al instante
#   bash deploy/configurar_ia.sh --reanudar          la vuelve a encender
#   bash deploy/configurar_ia.sh --tope tuevento 20  tope del mes en USD para un programa (--tope tuevento quitar: sin tope)
#   bash deploy/configurar_ia.sh --probar            solo la llamada de prueba
#   bash deploy/configurar_ia.sh --usar-clave-tuhospedaje   usa la clave que ya esta en TuHospedaje (sin preguntar)
# Si /etc/tukan-ia.env no tiene clave y TuHospedaje si (ANTHROPIC_API_KEY en /var/www/tuhospedaje/config/.env),
# ofrece reusarla (Enter = si). La clave se lee con sudo y nunca se muestra.
# Variables de entorno (no pregunta lo que ya venga): TUKAN_IA_CLAVE TUKAN_IA_MODELO_RAPIDO TUKAN_IA_MODELO_FUERTE
#   TUKAN_IA_PAUSA (si/no) IA_ENV (otro archivo, para pruebas) PROBAR_COMO (usuario de la prueba, www-data)
#   TUKAN_IA_URL (otra direccion de la API: SOLO para pruebas; se guarda en el archivo)
#   TUHOSPEDAJE_ENV (otro .env de TuHospedaje, para pruebas) USAR_CLAVE_TUHOSPEDAJE (si/no: respuesta a la pregunta)
# Los mensajes van sin tildes a proposito (se leen por SSH desde el celular).
set -u

DIR="$(cd "$(dirname "$0")/.." && pwd)"
LIB="$DIR/compartido/tukan_ia.php"
DESTINO="${IA_ENV:-/etc/tukan-ia.env}"
PROBAR_COMO="${PROBAR_COMO:-www-data}"
MODO="configurar"; TOPE_ID=""; TOPE_MONTO=""; FORZAR_TH=0
TUHOSPEDAJE_ENV="${TUHOSPEDAJE_ENV:-/var/www/tuhospedaje/config/.env}"
case "${1:-}" in
  --pausar) MODO="pausar";;
  --reanudar) MODO="reanudar";;
  --probar) MODO="probar";;
  --usar-clave-tuhospedaje) FORZAR_TH=1;;
  --tope) MODO="tope"; TOPE_ID="${2:-}"; TOPE_MONTO="${3:-}";;
  "") ;;
  -h|--help) sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit 0;;
  *) echo "ERROR: opcion desconocida: $1 (usa --pausar, --reanudar, --tope ID MONTO o --probar)"; exit 2;;
esac
if [ "$(id -u)" = "0" ]; then
  SUDO=""
  [ "$MODO" = "configurar" ] && echo "AVISO: mejor corre este comando con tu usuario normal (no como root). Se usa sudo cuando hace falta."
else
  SUDO="sudo"
fi
como_web() { if [ "$(id -u)" = "0" ]; then runuser -u "$PROBAR_COMO" -- "$@"; else sudo -u "$PROBAR_COMO" "$@"; fi; }

if [ ! -f "$LIB" ]; then
  echo "ERROR: no encuentro $LIB. Actualiza el panel: cd $DIR && git pull"; exit 1
fi

# Valores que ya estaban (si se corre otra vez)
anterior() { $SUDO cat "$DESTINO" 2>/dev/null | grep -E "^$1=" | head -1 | cut -d= -f2-; }
TTY=""; { : < /dev/tty; } 2>/dev/null && TTY=/dev/tty
preguntar() { # preguntar VARIABLE "Texto" DEFECTO
  local var=$1 texto=$2 def=$3 r=""
  if [ -n "${!var:-}" ]; then return; fi
  if [ -n "$TTY" ]; then
    printf "%s [%s]: " "$texto" "$def" > /dev/tty
    IFS= read -r r < /dev/tty || r=""
  fi
  printf -v "$var" "%s" "${r:-$def}"
}

# guardar CLAVE=VALOR...  -> reescribe el archivo cambiando solo esas variables (las demas se conservan).
# VALOR vacio = se quita la variable.
guardar() {
  local tmp; tmp="$(mktemp)"; chmod 600 "$tmp"
  local viejo; viejo="$($SUDO cat "$DESTINO" 2>/dev/null || true)"
  {
    echo "# IA de Tukan Software - generado por tukan-central/deploy/configurar_ia.sh. NO subir a GitHub."
    printf '%s\n' "$viejo" | grep -E '^[A-Z_][A-Z0-9_]*=' | while IFS= read -r linea; do
      k="${linea%%=*}"; tocado=0
      for par in "$@"; do [ "${par%%=*}" = "$k" ] && tocado=1; done
      [ "$tocado" = "0" ] && printf '%s\n' "$linea"
    done
    for par in "$@"; do
      [ -n "${par#*=}" ] && printf '%s\n' "$par"
    done
  } > "$tmp"
  if $SUDO install -o root -g www-data -m 640 "$tmp" "$DESTINO"; then
    rm -f "$tmp"; return 0
  fi
  rm -f "$tmp"; echo "ERROR: no se pudo escribir $DESTINO"; exit 1
}

probar() {
  echo "== Llamada de prueba al modelo rapido"
  if ! como_web test -r "$DESTINO" 2>/dev/null; then
    echo "IA: NO FUNCIONA"
    echo "-> El usuario $PROBAR_COMO no puede leer $DESTINO (debe ser root:www-data 640 y la carpeta abierta). Los programas tampoco podran."
    return 1
  fi
  SALIDA="$(como_web env -i PATH="$PATH" TUKAN_IA_ENV="$DESTINO" php "$LIB" probar < /dev/null 2>&1)"; RC=$?
  echo "$SALIDA" | sed 's/^/   /'
  echo
  echo "=========== RESULTADO ==========="
  echo "Archivo: $DESTINO ($($SUDO stat -c '%U:%G %a' "$DESTINO" 2>/dev/null))"
  case "$SALIDA" in *PAUSADA*) echo "Estado: PAUSADA (los programas no gastan nada; la prueba igual se hizo)";; *) echo "Estado: encendida";; esac
  if [ "$RC" = "0" ]; then
    echo "IA: FUNCIONA"
  else
    echo "IA: NO FUNCIONA"
    case "$SALIDA" in
      *"clave no es valida"*) echo "-> La clave esta mal copiada o fue borrada. Crea otra en platform.claude.com > Settings > API keys y vuelve a correr este comando.";;
      *"no tiene saldo"*) echo "-> La cuenta de Anthropic no tiene saldo. Carga creditos en platform.claude.com > Settings > Billing.";;
      *"ocupada"*) echo "-> La API esta ocupada. Prueba otra vez en unos minutos: bash $DIR/deploy/configurar_ia.sh --probar";;
      *"no hay conexion"*) echo "-> El servidor no llega a api.anthropic.com. Revisa el internet o el firewall.";;
      *"no hay clave"*) echo "-> Falta la clave. Corre: bash $DIR/deploy/configurar_ia.sh";;
      *"rechazo el pedido"*) echo "-> Revisa el nombre del modelo rapido (por defecto claude-haiku-5-5).";;
      *"Permission denied"*|*"No such file"*) echo "-> El usuario $PROBAR_COMO no puede leer $DESTINO. Debe ser root:www-data 640.";;
    esac
    echo "Puedes corregir y correr otra vez: bash $DIR/deploy/configurar_ia.sh"
  fi
  echo "================================="
  return "$RC"
}

case "$MODO" in
  pausar)
    guardar "TUKAN_IA_PAUSA=1"
    echo "IA: PAUSADA para todos los programas. No se gasta nada desde ahora."
    echo "La ayuda sigue respondiendo con las preguntas guardadas y el manual; las fotos piden hacerlo a mano."
    echo "Para volver a encenderla: bash $DIR/deploy/configurar_ia.sh --reanudar"
    exit 0;;
  reanudar)
    guardar "TUKAN_IA_PAUSA=0"
    echo "IA: ENCENDIDA otra vez para todos los programas."
    [ -z "$(anterior TUKAN_IA_CLAVE)" ] && echo "AVISO: todavia no hay clave. Corre: bash $DIR/deploy/configurar_ia.sh"
    exit 0;;
  tope)
    ID="$(echo "$TOPE_ID" | tr 'a-z-' 'A-Z_')"
    case "$ID" in ''|*[!A-Z0-9_]*) echo "ERROR: escribe el ID del programa (ej. --tope tuevento 20)"; exit 2;; esac
    case "$TOPE_MONTO" in
      quitar|"") guardar "TUKAN_IA_TOPE_PROGRAMA_$ID="; echo "Tope del programa $TOPE_ID: QUITADO (solo cuentan los topes de cada negocio)."; exit 0;;
    esac
    MONTO="$(echo "$TOPE_MONTO" | tr ',' '.')"
    if ! echo "$MONTO" | grep -Eq '^[0-9]+(\.[0-9]{1,2})?$'; then echo "ERROR: el monto debe ser un numero en dolares (ej. 20 o 7.50)"; exit 2; fi
    guardar "TUKAN_IA_TOPE_PROGRAMA_$ID=$MONTO"
    echo "Tope del programa $TOPE_ID: \$$MONTO al mes. Al llegar, ese programa deja de gastar (en silencio) hasta el mes siguiente."
    exit 0;;
  probar)
    probar; exit $?;;
esac

echo "== 1. Datos de la IA (Enter = lo que esta entre corchetes)"
# Clave que ya esta en TuHospedaje (se lee con sudo; nunca se muestra)
clave_tuhospedaje() { $SUDO cat "$TUHOSPEDAJE_ENV" 2>/dev/null | grep -E '^[[:space:]]*(export[[:space:]]+)?ANTHROPIC_API_KEY=' | tail -1 | cut -d= -f2- | tr -d ' \t\r\n"'"'"; }
if [ "$FORZAR_TH" = "1" ]; then
  TUKAN_IA_CLAVE="$(clave_tuhospedaje)"
  if [ -z "$TUKAN_IA_CLAVE" ]; then echo "ERROR: no se encontro ANTHROPIC_API_KEY en $TUHOSPEDAJE_ENV"; exit 1; fi
  echo "Se usa la clave de TuHospedaje ($TUHOSPEDAJE_ENV)."
elif [ -z "${TUKAN_IA_CLAVE:-}" ] && [ -z "$(anterior TUKAN_IA_CLAVE)" ]; then
  TH_CLAVE="$(clave_tuhospedaje)"
  if [ -n "$TH_CLAVE" ]; then
    if [ -z "${USAR_CLAVE_TUHOSPEDAJE:-}" ] && [ -n "$TTY" ]; then
      printf "Se encontro la clave de TuHospedaje, usarla? [S/n]: " > /dev/tty
      IFS= read -r USAR_CLAVE_TUHOSPEDAJE < /dev/tty || USAR_CLAVE_TUHOSPEDAJE=""
    fi
    USAR_CLAVE_TUHOSPEDAJE="${USAR_CLAVE_TUHOSPEDAJE:-s}"
    case "$(echo "$USAR_CLAVE_TUHOSPEDAJE" | tr 'A-Z' 'a-z')" in
      s|si|y|yes|1) TUKAN_IA_CLAVE="$TH_CLAVE"; echo "Se usa la clave de TuHospedaje.";;
      *) echo "No se usa la clave de TuHospedaje.";;
    esac
  fi
  unset TH_CLAVE
fi
if [ -z "${TUKAN_IA_CLAVE:-}" ]; then
  ANT_CLAVE="$(anterior TUKAN_IA_CLAVE)"
  if [ -n "$TTY" ]; then
    if [ -n "$ANT_CLAVE" ]; then printf "Clave de Anthropic, empieza con sk-ant- (no se ve al escribir; Enter = la misma de antes): " > /dev/tty
    else printf "Clave de Anthropic, empieza con sk-ant- (no se ve al escribir): " > /dev/tty; fi
    IFS= read -r -s TUKAN_IA_CLAVE < /dev/tty || TUKAN_IA_CLAVE=""
    echo > /dev/tty
  fi
  TUKAN_IA_CLAVE="${TUKAN_IA_CLAVE:-$ANT_CLAVE}"
fi
TUKAN_IA_CLAVE="$(printf '%s' "$TUKAN_IA_CLAVE" | tr -d ' \t\r\n')"
if [ -z "$TUKAN_IA_CLAVE" ]; then echo "ERROR: falta la clave de Anthropic (platform.claude.com > Settings > API keys)"; exit 1; fi
case "$TUKAN_IA_CLAVE" in sk-ant-*) ;; *) echo "AVISO: la clave no empieza con sk-ant- (revisa que la copiaste completa)";; esac
preguntar TUKAN_IA_MODELO_RAPIDO "Modelo rapido (ayuda y casi todo)" "$(anterior TUKAN_IA_MODELO_RAPIDO | grep . || echo claude-haiku-5-5)"
preguntar TUKAN_IA_MODELO_FUERTE "Modelo fuerte (fotos dificiles)" "$(anterior TUKAN_IA_MODELO_FUERTE | grep . || echo claude-sonnet-5-5)"
DEF_PAUSA=no; [ "$(anterior TUKAN_IA_PAUSA)" = "1" ] && DEF_PAUSA=si
preguntar TUKAN_IA_PAUSA "Dejarla en pausa (si = no se gasta nada)" "$DEF_PAUSA"
case "$(echo "$TUKAN_IA_PAUSA" | tr 'A-Z' 'a-z')" in si|s|1|yes|y) PAUSA=1;; no|n|0|"") PAUSA=0;; *) echo "ERROR: responde si o no (escribiste: $TUKAN_IA_PAUSA)"; exit 1;; esac
for v in TUKAN_IA_MODELO_RAPIDO TUKAN_IA_MODELO_FUERTE; do
  case "${!v}" in ''|*[!a-z0-9.-]*) echo "ERROR: $v debe ser un nombre de modelo (ej. claude-haiku-5-5)"; exit 1;; esac
done

echo "== 2. Guardar $DESTINO (root:www-data, permisos 640)"
guardar "TUKAN_IA_CLAVE=$TUKAN_IA_CLAVE" "TUKAN_IA_MODELO_RAPIDO=$TUKAN_IA_MODELO_RAPIDO" "TUKAN_IA_MODELO_FUERTE=$TUKAN_IA_MODELO_FUERTE" \
        "TUKAN_IA_PAUSA=$PAUSA" ${TUKAN_IA_URL:+"TUKAN_IA_URL=$TUKAN_IA_URL"}
echo "   listo"
unset TUKAN_IA_CLAVE
echo "Modelos: rapido $TUKAN_IA_MODELO_RAPIDO - fuerte $TUKAN_IA_MODELO_FUERTE - $([ "$PAUSA" = "1" ] && echo PAUSADA || echo encendida)"
probar
exit $?
