#!/usr/bin/env bash
# tukan-actualizar-pedidos - atiende los pedidos de actualizacion que el dueno aprueba en el Panel Tukan Central.
# Lo instala deploy/instalar_actualizador.sh en /usr/local/sbin/tukan-actualizar-pedidos (copia de root, 700) y lo
# corre systemd (tukan-actualizar.service) SOLO cuando aparece un pedido en la cola (tukan-actualizar.path).
# No hay horario: si nadie pide, no hace nada.
#
# Por cada pedido de la cola /var/lib/tukan-actualizar/pedidos:
#   1) lo saca de la cola (el panel ya no lo puede cambiar)
#   2) lo revisa: firma HMAC con /etc/tukan-actualizar/llave, vigencia de 10 minutos, de un solo uso,
#      programas conocidos y commit exacto (ver src/Pedido.php). Si no sirve, se rechaza y se anota.
#   3) revisar   -> corre "actualizar estado" como el usuario normal (solo git fetch; no cambia nada)
#      actualizar -> permite sudo SIN contrasena al usuario normal SOLO mientras corre (archivo temporal en
#                    /etc/sudoers.d, revisado con visudo y borrado al terminar) y corre como ese usuario:
#                    actualizar PROGRAMA@COMMIT ... --pedido ID
#   4) deja el resultado en /var/lib/tukan-actualizar/estado/pedido-ID.json (+ .log) para el panel
#
# Rutas para las pruebas: TUKAN_ACT_* ; TUKAN_ACT_SIN_ROOT=1 corre todo con el usuario actual (sin runuser ni visudo).
# Mensajes sin tildes a proposito (se leen por SSH desde el celular).
set -u

BASE_LIB="${TUKAN_ACT_LIB:-/usr/local/lib/tukan-actualizar}"
PEDIDOS="${TUKAN_ACT_PEDIDOS:-/var/lib/tukan-actualizar/pedidos}"
TOMADOS="${TUKAN_ACT_TOMADOS:-/var/lib/tukan-actualizar/tomados}"
USADOS="${TUKAN_ACT_USADOS:-/var/lib/tukan-actualizar/usados}"
ESTADO="${TUKAN_ACT_ESTADO:-/var/lib/tukan-actualizar/estado}"
LOGS="${TUKAN_ACT_LOGS:-/var/log/tukan-actualizar}"
LLAVE="${TUKAN_ACT_LLAVE:-/etc/tukan-actualizar/llave}"
AJUSTES="${TUKAN_ACT_AJUSTES:-/etc/tukan-actualizar/actualizador.conf}"
SUDOERS="${TUKAN_ACT_SUDOERS:-/etc/sudoers.d/zz-tukan-actualizar-temporal}"
COMANDO="${TUKAN_ACT_COMANDO:-/usr/local/bin/actualizar}"
SIN_ROOT="${TUKAN_ACT_SIN_ROOT:-0}"
MAX_POR_VUELTA=10
AYUDANTE="$BASE_LIB/ayudante.php"
[ -f "$AYUDANTE" ] || AYUDANTE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/actualizar_ayudante.php"
umask 027

anotar() { mkdir -p "$LOGS" 2>/dev/null; printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >> "$LOGS/pedidos.log"; echo "$*"; }
quitar_sudo() { rm -f "$SUDOERS" "$SUDOERS.nuevo"; }
trap quitar_sudo EXIT

if [ "$SIN_ROOT" != "1" ] && [ "$(id -u)" != "0" ]; then echo "ERROR: esto lo corre systemd como root (tukan-actualizar.service)"; exit 1; fi
USUARIO="$(grep -E '^USUARIO=' "$AJUSTES" 2>/dev/null | head -1 | cut -d= -f2-)"
USUARIO="${USUARIO:-qf}"
if [ "$SIN_ROOT" != "1" ]; then
  [[ "$USUARIO" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] && [ "$USUARIO" != "root" ] && id "$USUARIO" > /dev/null 2>&1 \
    || { anotar "ERROR: usuario '$USUARIO' invalido en $AJUSTES"; exit 1; }
fi
quitar_sudo   # por si quedo de una corrida cortada
mkdir -p "$TOMADOS" "$USADOS" && chmod 700 "$TOMADOS" "$USADOS"
find "$USADOS" -type f -mtime +2 -delete 2>/dev/null
find "$TOMADOS" -type f -mtime +30 -delete 2>/dev/null
find "$ESTADO" -maxdepth 1 -type f -name 'pedido-*' -mtime +90 -delete 2>/dev/null

# Corre un comando como el usuario normal, con un entorno limpio
como_usuario() {
  if [ "$SIN_ROOT" = "1" ]; then TUKAN_ACT_SERVICIO=1 "$@"; return; fi
  local casa; casa="$(getent passwd "$USUARIO" | cut -d: -f6)"
  runuser -u "$USUARIO" -- env -i HOME="$casa" USER="$USUARIO" LOGNAME="$USUARIO" LANG=C.UTF-8 \
    PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin TUKAN_ACT_SERVICIO=1 "$@"
}

# sudo sin contrasena para el usuario normal, solo mientras corre un pedido aprobado
permitir_sudo() {
  local tmp="$SUDOERS.nuevo"
  {
    echo "# TEMPORAL - lo crea tukan-actualizar-pedidos mientras corre un pedido aprobado en el Panel Tukan Central"
    echo "# y lo borra al terminar (tambien lo borra systemd: ExecStopPost). No lo edites."
    echo "$USUARIO ALL=(ALL:ALL) NOPASSWD: ALL"
  } > "$tmp"
  chmod 440 "$tmp"
  if [ "$SIN_ROOT" != "1" ]; then
    chown root:root "$tmp"
    visudo -cqf "$tmp" || { rm -f "$tmp"; return 1; }
  fi
  mv -f "$tmp" "$SUDOERS"
}

estado_pedido() { # estado_pedido ID TIPO ESTADO MOTIVO USUARIO
  php "$AYUDANTE" pedido "$ESTADO/pedido-$1.json" "$1" "$2" "$3" "$4" "$5" 2>/dev/null
}

atender() {
  local archivo=$1 nombre salida linea primera id tipo usuario objetivos=() rc inicio fin res log
  nombre="$(basename "$archivo")"
  [ -f "$archivo" ] && [ ! -L "$archivo" ] || { rm -f "$archivo"; return; }
  if ! mv -f "$archivo" "$TOMADOS/$nombre" 2>/dev/null; then rm -f "$archivo"; anotar "pedido $nombre: no se pudo sacar de la cola (borrado)"; return; fi
  archivo="$TOMADOS/$nombre"
  salida="$(php "$AYUDANTE" validar "$archivo" "$LLAVE" "$USADOS" 2>&1)"; rc=$?
  primera="$(printf '%s\n' "$salida" | head -1)"
  if [ "$rc" != "0" ] || [ "${primera%%$'\t'*}" != "OK" ]; then
    IFS=$'\t' read -r _ id motivo <<< "$primera"
    motivo="${motivo:-$primera}"
    anotar "RECHAZADO $nombre: $motivo"
    if [[ "${id:-}" =~ ^[0-9a-f]{32}$ ]] && [ ! -e "$ESTADO/pedido-$id.json" ]; then estado_pedido "$id" "-" rechazado "$motivo" ""; fi
    return
  fi
  IFS=$'\t' read -r _ id tipo usuario <<< "$primera"
  while IFS=$'\t' read -r p c; do [ -n "$p" ] && objetivos+=("$p@$c"); done < <(printf '%s\n' "$salida" | tail -n +2)
  log="$ESTADO/pedido-$id.log"
  : > "$log"; chmod 640 "$log" 2>/dev/null
  inicio=$(date +%s)
  estado_pedido "$id" "$tipo" en_curso "" "$usuario"
  anotar "pedido $id ($tipo) de $usuario: ${objetivos[*]:-revisar}"
  if [ "$tipo" = "revisar" ]; then
    como_usuario "$COMANDO" estado < /dev/null >> "$log" 2>&1; rc=$?
    php "$AYUDANTE" resultado "$ESTADO/pedido-$id.json" panel "$id" "$usuario" "$inicio" "$(date +%s)" "$([ $rc = 0 ] && echo listo || echo fallo)" < /dev/null 2>/dev/null
    anotar "pedido $id: revision terminada (codigo $rc)"
    return
  fi
  res="$(mktemp)"; chmod 644 "$res" 2>/dev/null
  [ "$SIN_ROOT" = "1" ] || chown "$USUARIO" "$res"
  if ! permitir_sudo; then
    anotar "pedido $id: ERROR: no se pudo preparar sudo (visudo)"
    php "$AYUDANTE" pedido "$ESTADO/pedido-$id.json" "$id" "$tipo" fallo "el servidor no pudo preparar sudo (revisa instalar_actualizador.sh)" "$usuario" 2>/dev/null
    rm -f "$res"; return
  fi
  como_usuario "$COMANDO" "${objetivos[@]}" --pedido "$id" --usuario "$usuario" --resultado "$res" < /dev/null >> "$log" 2>&1; rc=$?
  quitar_sudo
  fin=$(date +%s)
  if [ ! -s "$res" ]; then   # actualizar no llego a probar nada (candado ocupado, error de uso...)
    motivo="$(tail -3 "$log" | tr '\n' ' ' | cut -c1-300)"
    php "$AYUDANTE" pedido "$ESTADO/pedido-$id.json" "$id" "$tipo" fallo "${motivo:-actualizar termino con codigo $rc}" "$usuario" 2>/dev/null
  else
    php "$AYUDANTE" resultado "$ESTADO/pedido-$id.json" panel "$id" "$usuario" "$inicio" "$fin" < "$res" 2>/dev/null
  fi
  rm -f "$res"
  anotar "pedido $id: terminado (codigo $rc)"
}

n=0
while :; do
  hay=0
  for f in "$PEDIDOS"/*.json; do
    [ -e "$f" ] || continue
    hay=1; n=$((n+1))
    [ "$n" -gt "$MAX_POR_VUELTA" ] && { anotar "demasiados pedidos: se borra $(basename "$f")"; rm -f "$f"; continue; }
    atender "$f"
  done
  [ "$hay" = "0" ] && break
done
exit 0
