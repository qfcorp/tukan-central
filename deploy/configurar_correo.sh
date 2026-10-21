#!/usr/bin/env bash
# Configura el correo de Tukan (info@tukansoftware.com) para el Panel Tukan Central y los programas del servidor.
# Se corre UNA vez en el servidor (qfcorp), con tu usuario normal (no root); se puede repetir para cambiar datos.
#   cd /var/www/tukan-central && git pull && bash deploy/configurar_correo.sh
# Pregunta los datos del SMTP (Enter = valor entre corchetes), escribe /etc/tukan-correo.env (root:www-data, 640)
# y manda un correo de PRUEBA. Tambien acepta variables de entorno (no pregunta lo que ya venga):
#   TUKAN_SMTP_HOST TUKAN_SMTP_PUERTO TUKAN_SMTP_SEGURIDAD TUKAN_SMTP_USUARIO TUKAN_SMTP_CLAVE
#   TUKAN_CORREO_DE TUKAN_CORREO_NOMBRE TUKAN_CORREO_RESPONDER PRUEBA_A (correo para la prueba)
#   CORREO_ENV (otro archivo, para pruebas) PROBAR_COMO (usuario que manda la prueba, www-data)
# Los mensajes van sin tildes a proposito (se leen por SSH desde el celular).
set -u

DIR="$(cd "$(dirname "$0")/.." && pwd)"
LIB="$DIR/compartido/tukan_correo.php"
DESTINO="${CORREO_ENV:-/etc/tukan-correo.env}"
PROBAR_COMO="${PROBAR_COMO:-www-data}"
if [ "$(id -u)" = "0" ]; then
  SUDO=""
  echo "AVISO: mejor corre este comando con tu usuario normal (no como root). Se usa sudo cuando hace falta."
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

echo "== 1. Datos del correo de Tukan (Enter = lo que esta entre corchetes)"
preguntar TUKAN_SMTP_HOST "Servidor SMTP" "$(anterior TUKAN_SMTP_HOST | grep . || echo mail.qfproducciones.com)"
preguntar TUKAN_SMTP_PUERTO "Puerto (587 = tls, 465 = ssl)" "$(anterior TUKAN_SMTP_PUERTO | grep . || echo 587)"
DEF_SEG=tls; [ "$TUKAN_SMTP_PUERTO" = "465" ] && DEF_SEG=ssl
preguntar TUKAN_SMTP_SEGURIDAD "Seguridad (tls o ssl)" "$(anterior TUKAN_SMTP_SEGURIDAD | grep . || echo $DEF_SEG)"
preguntar TUKAN_SMTP_USUARIO "Usuario (el buzon completo)" "$(anterior TUKAN_SMTP_USUARIO | grep . || echo info@tukansoftware.com)"
if [ -z "${TUKAN_SMTP_CLAVE:-}" ]; then
  ANT_CLAVE="$(anterior TUKAN_SMTP_CLAVE)"
  if [ -n "$TTY" ]; then
    if [ -n "$ANT_CLAVE" ]; then printf "Clave del buzon (no se ve al escribir; Enter = la misma de antes): " > /dev/tty
    else printf "Clave del buzon (no se ve al escribir): " > /dev/tty; fi
    IFS= read -r -s TUKAN_SMTP_CLAVE < /dev/tty || TUKAN_SMTP_CLAVE=""
    echo > /dev/tty
  fi
  TUKAN_SMTP_CLAVE="${TUKAN_SMTP_CLAVE:-$ANT_CLAVE}"
fi
TUKAN_CORREO_DE="${TUKAN_CORREO_DE:-$(anterior TUKAN_CORREO_DE | grep . || echo "$TUKAN_SMTP_USUARIO")}"
TUKAN_CORREO_NOMBRE="${TUKAN_CORREO_NOMBRE:-$(anterior TUKAN_CORREO_NOMBRE | grep . || printf 'Tuk\303\241n Software')}"
TUKAN_CORREO_RESPONDER="${TUKAN_CORREO_RESPONDER:-$(anterior TUKAN_CORREO_RESPONDER)}"
TUKAN_SMTP_SEGURIDAD="$(echo "$TUKAN_SMTP_SEGURIDAD" | tr 'A-Z' 'a-z')"
TUKAN_SMTP_CA="${TUKAN_SMTP_CA:-$(anterior TUKAN_SMTP_CA)}"
TUKAN_SMTP_VERIFICAR="${TUKAN_SMTP_VERIFICAR:-$(anterior TUKAN_SMTP_VERIFICAR)}"

case "$TUKAN_SMTP_SEGURIDAD" in tls|ssl|ninguna) ;; *) echo "ERROR: la seguridad debe ser tls o ssl (escribiste: $TUKAN_SMTP_SEGURIDAD)"; exit 1;; esac
case "$TUKAN_SMTP_PUERTO" in ''|*[!0-9]*) echo "ERROR: el puerto debe ser un numero (ej. 587)"; exit 1;; esac
if [ -z "$TUKAN_SMTP_CLAVE" ]; then echo "ERROR: falta la clave del buzon $TUKAN_SMTP_USUARIO"; exit 1; fi
for v in TUKAN_SMTP_HOST TUKAN_SMTP_USUARIO TUKAN_SMTP_CLAVE TUKAN_CORREO_DE TUKAN_CORREO_NOMBRE TUKAN_CORREO_RESPONDER; do
  case "${!v}" in *$'\n'*|*$'\r'*) echo "ERROR: $v no puede tener saltos de linea"; exit 1;; esac
done

echo "== 2. Guardar $DESTINO (root:www-data, permisos 640)"
TMPF="$(mktemp)"; chmod 600 "$TMPF"
{
  echo "# Correo de Tukan Software - generado por tukan-central/deploy/configurar_correo.sh. NO subir a GitHub."
  echo "TUKAN_SMTP_HOST=$TUKAN_SMTP_HOST"
  echo "TUKAN_SMTP_PUERTO=$TUKAN_SMTP_PUERTO"
  echo "TUKAN_SMTP_SEGURIDAD=$TUKAN_SMTP_SEGURIDAD"
  echo "TUKAN_SMTP_USUARIO=$TUKAN_SMTP_USUARIO"
  printf 'TUKAN_SMTP_CLAVE=%s\n' "$TUKAN_SMTP_CLAVE"
  echo "TUKAN_CORREO_DE=$TUKAN_CORREO_DE"
  printf 'TUKAN_CORREO_NOMBRE=%s\n' "$TUKAN_CORREO_NOMBRE"
  [ -n "$TUKAN_CORREO_RESPONDER" ] && echo "TUKAN_CORREO_RESPONDER=$TUKAN_CORREO_RESPONDER"
  [ -n "${TUKAN_SMTP_CA:-}" ] && echo "TUKAN_SMTP_CA=$TUKAN_SMTP_CA"
  [ -n "${TUKAN_SMTP_VERIFICAR:-}" ] && echo "TUKAN_SMTP_VERIFICAR=$TUKAN_SMTP_VERIFICAR"
} > "$TMPF"
if $SUDO install -o root -g www-data -m 640 "$TMPF" "$DESTINO"; then
  echo "   listo"
else
  rm -f "$TMPF"; echo "ERROR: no se pudo escribir $DESTINO"; exit 1
fi
rm -f "$TMPF"
unset TUKAN_SMTP_CLAVE

echo "== 3. Correo de prueba"
preguntar PRUEBA_A "A que correo mando la prueba" "$TUKAN_SMTP_USUARIO"
SALIDA="$(como_web env TUKAN_CORREO_ENV="$DESTINO" php "$LIB" probar "$PRUEBA_A" < /dev/null 2>&1)"; RC=$?
echo "$SALIDA" | sed 's/^/   /'

echo
echo "=========== RESULTADO ==========="
echo "Archivo: $DESTINO ($($SUDO stat -c '%U:%G %a' "$DESTINO" 2>/dev/null))"
echo "Servidor: $TUKAN_SMTP_HOST:$TUKAN_SMTP_PUERTO ($TUKAN_SMTP_SEGURIDAD) - usuario $TUKAN_SMTP_USUARIO"
if [ "$RC" = "0" ]; then
  echo "Correo de prueba: ENVIADO a $PRUEBA_A (revisa la bandeja y la carpeta de spam)"
else
  echo "Correo de prueba: NO SE ENVIO"
  case "$SALIDA" in
    *"rechazo el usuario o la clave"*) echo "-> La clave o el usuario no son correctos. Revisa el buzon en HestiaCP (Correo > tukansoftware.com > info) y vuelve a correr este comando.";;
    *"certificado"*|*"cifrado TLS"*|*"conexion segura"*) echo "-> El certificado no coincide con el nombre del servidor. Prueba con el servidor mail.tukansoftware.com (o mail.qfproducciones.com si usaste otro).";;
    *"no contesto a tiempo"*|*"tiempo agotado"*|*"conexion rechazada"*) echo "-> No hay conexion con $TUKAN_SMTP_HOST:$TUKAN_SMTP_PUERTO. Revisa el nombre, el puerto (587 o 465) y que el firewall de Hestia lo deje pasar.";;
    *"No se encontro el servidor"*) echo "-> El nombre $TUKAN_SMTP_HOST no existe. Revisa como se escribe (ej. mail.qfproducciones.com).";;
    *"STARTTLS"*) echo "-> Ese puerto no ofrece STARTTLS: usa el puerto 465 con seguridad ssl.";;
  esac
  echo "Puedes corregir y correr otra vez: bash $DIR/deploy/configurar_correo.sh"
fi
echo "================================="
exit "$RC"
