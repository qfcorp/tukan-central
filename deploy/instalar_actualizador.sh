#!/usr/bin/env bash
# Instalador de una sola vez del ACTUALIZADOR de programas Tukan (servidor qfcorp).
# Deja listo:
#   - el comando  /usr/local/bin/actualizar   (actualizar | actualizar todo | actualizar tuevento | actualizar estado)
#   - la cola de pedidos del Panel Tukan Central y el servicio que los atiende (tukan-actualizar.path + .service)
#   - la llave para firmar los pedidos (/etc/tukan-actualizar/llave, root:www-data 640)
#   - carpetas de respaldos (/var/backups/tukan-actualizar), logs (/var/log/tukan-actualizar) y estado
#   - la plantilla de sudo TEMPORAL (solo se activa mientras corre un pedido aprobado; ver docs/ACTUALIZAR.md)
# NO actualiza nada por su cuenta: no hay horario ni deteccion automatica de commits.
#
# Uso (con tu usuario normal, NO como root; usa sudo por dentro):
#   bash /var/www/tukan-central/deploy/instalar.sh && bash /var/www/tukan-central/deploy/instalar_actualizador.sh
# Se puede correr varias veces (no cambia la llave ni borra respaldos).
# Mensajes sin tildes a proposito (se leen por SSH desde el celular).
set -u

AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RAIZ="$(dirname "$AQUI")"
YO="$(id -un)"
LIB=/usr/local/lib/tukan-actualizar
ETC=/etc/tukan-actualizar
VAR=/var/lib/tukan-actualizar
SUDOERS_TEMP=/etc/sudoers.d/zz-tukan-actualizar-temporal
cd /tmp || exit 1

if [ "$(id -u)" = "0" ]; then
  echo "ERROR: no lo corras como root ni con sudo. Entra con tu usuario normal (qf) y vuelve a correrlo."
  exit 1
fi
for f in actualizar.sh actualizar_pedidos.sh actualizar_ayudante.php; do
  [ -f "$AQUI/$f" ] || { echo "ERROR: falta $AQUI/$f. Actualiza el panel primero: bash $RAIZ/deploy/instalar.sh"; exit 1; }
done
[ -f "$RAIZ/src/Pedido.php" ] || { echo "ERROR: falta $RAIZ/src/Pedido.php"; exit 1; }
if ! sudo -v; then echo "ERROR: tu usuario necesita permiso de sudo."; exit 1; fi

echo "== 1. Programas necesarios (git, flock, curl, php, sqlite3)"
FALTAN=""
for c in git flock curl php sqlite3 runuser visudo; do command -v "$c" > /dev/null || FALTAN="$FALTAN $c"; done
if [ -n "$FALTAN" ]; then
  sudo apt-get install -y git util-linux curl php-cli sqlite3 sudo < /dev/null > /dev/null 2>&1 && echo "   instalado:$FALTAN" || echo "   AVISO: faltan:$FALTAN"
else
  echo "   listo"
fi
getent group www-data > /dev/null || { echo "ERROR: no existe el grupo www-data (nginx/PHP no estan instalados)"; exit 1; }

echo "== 2. Carpetas"
sudo install -d -o root -g root -m 755 "$ETC" "$LIB" "$VAR"
sudo install -d -o root -g www-data -m 770 "$VAR/pedidos"          # el panel (www-data) deja pedidos aqui
sudo install -d -o root -g root -m 700 "$VAR/tomados" "$VAR/usados" # solo root
sudo install -d -o "$YO" -g www-data -m 2750 "$VAR/estado"          # actualizar escribe; el panel solo lee
sudo install -d -o "$YO" -g "$YO" -m 700 "$VAR/trabajo"             # candado (flock)
sudo install -d -o "$YO" -g "$YO" -m 700 /var/backups/tukan-actualizar
sudo install -d -o "$YO" -g adm -m 2750 /var/log/tukan-actualizar
echo "   listo"

echo "== 3. Llave para firmar los pedidos del panel"
if sudo test -s "$ETC/llave"; then
  echo "   se conservo la llave"
else
  openssl rand -hex 32 | sudo tee "$ETC/llave" > /dev/null && echo "   llave nueva"
fi
sudo chown root:www-data "$ETC/llave" && sudo chmod 640 "$ETC/llave"
printf 'USUARIO=%s\n' "$YO" | sudo tee "$ETC/actualizador.conf" > /dev/null
sudo chmod 644 "$ETC/actualizador.conf"
if ! sudo test -f "$ETC/programas.conf"; then
  sudo tee "$ETC/programas.conf" > /dev/null <<'EOF'
# Programas extra o cambiados para "actualizar" (los normales ya vienen dentro del comando).
# Formato: id|nombre|tipo|carpeta|prueba|clave del .env con la direccion|direccion por defecto
#   tipo deploy    -> bash CARPETA/deploy/instalar.sh     tipo guarderia -> sudo bash CARPETA/instalar.sh DOMINIO CLIENTE
#   "id|-|quitar"  -> no lo toca
# Ejemplo:  tuvet|TuVet|deploy|/var/www/tuvet|http://127.0.0.1:8110/?r=login||
EOF
  sudo chmod 644 "$ETC/programas.conf"
fi
echo "   listo"

echo "== 4. Comando actualizar y servicio (copias de root: el panel no las puede cambiar)"
sudo install -o root -g root -m 755 "$AQUI/actualizar.sh" /usr/local/bin/actualizar
sudo install -o root -g root -m 700 "$AQUI/actualizar_pedidos.sh" /usr/local/sbin/tukan-actualizar-pedidos
sudo install -o root -g root -m 644 "$AQUI/actualizar_ayudante.php" "$LIB/ayudante.php"
sudo install -o root -g root -m 644 "$RAIZ/src/Pedido.php" "$LIB/Pedido.php"
sudo tee /etc/systemd/system/tukan-actualizar.path > /dev/null <<EOF
[Unit]
Description=Tukan: pedidos de actualizacion aprobados en el Panel Tukan Central

[Path]
PathExistsGlob=$VAR/pedidos/*.json
Unit=tukan-actualizar.service

[Install]
WantedBy=multi-user.target
EOF
sudo tee /etc/systemd/system/tukan-actualizar.service > /dev/null <<EOF
[Unit]
Description=Tukan: atiende los pedidos de actualizacion aprobados por el dueno
After=network-online.target postgresql.service mariadb.service

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/tukan-actualizar-pedidos
ExecStopPost=/bin/rm -f $SUDOERS_TEMP $SUDOERS_TEMP.nuevo
TimeoutStartSec=3h
Nice=5
EOF
sudo rm -f "$SUDOERS_TEMP" "$SUDOERS_TEMP.nuevo"
# Se prueba que la regla temporal sea valida (no se deja puesta)
PRUEBA="$(mktemp)"
printf 'Defaults:%s !requiretty\n%s ALL=(ALL:ALL) NOPASSWD: ALL\n' "$YO" "$YO" > "$PRUEBA"
if sudo visudo -cqf "$PRUEBA"; then echo "   regla de sudo temporal: valida (solo se activa durante un pedido)"; else echo "   ERROR: visudo rechazo la regla temporal"; fi
rm -f "$PRUEBA"
sudo systemctl daemon-reload
sudo systemctl enable --now tukan-actualizar.path > /dev/null 2>&1 && echo "   servicio: listo" || echo "   ERROR: no se pudo activar tukan-actualizar.path"

echo "== 5. Prueba: revisar versiones (no cambia nada)"
actualizar estado 2>&1 | tail -15 | sed 's/^/   /'

echo "== 6. Prueba del camino del panel (pedido firmado de solo revisar)"
ID="$(sudo -u www-data php -r '
  require $argv[1];
  $llave = Tukan\Pedido::leerLlave("/etc/tukan-actualizar/llave");
  $p = Tukan\Pedido::crear("revisar", [], "instalar_actualizador", $llave);
  file_put_contents("/var/lib/tukan-actualizar/pedidos/.prueba.tmp", $p["contenido"]);
  rename("/var/lib/tukan-actualizar/pedidos/.prueba.tmp", "/var/lib/tukan-actualizar/pedidos/" . time() . "-" . $p["id"] . ".json");
  echo $p["id"];' "$LIB/Pedido.php" 2>/dev/null)"
RES="ERROR (el panel no pudo dejar el pedido)"
if [ -n "$ID" ]; then
  RES="ERROR (el servicio no lo atendio en 60 segundos: sudo journalctl -u tukan-actualizar)"
  for _ in $(seq 1 60); do
    if grep -qE '"estado": "(listo|fallo)"' "$VAR/estado/pedido-$ID.json" 2>/dev/null; then
      grep -q '"estado": "listo"' "$VAR/estado/pedido-$ID.json" && RES="OK" || RES="FALLO (mira $VAR/estado/pedido-$ID.log)"
      break
    fi
    sleep 1
  done
fi
echo "   $RES"

echo
echo "=========== RESULTADO ==========="
echo "1) Comando: $(command -v actualizar || echo FALTA)"
echo "2) Servicio: $(systemctl is-active tukan-actualizar.path 2>/dev/null) (debe ser active)"
echo "3) Llave: $(sudo stat -c '%U:%G %a' "$ETC/llave" 2>/dev/null) (debe ser root:www-data 640)"
echo "4) Sudo permanente agregado: ninguno (solo temporal mientras corre un pedido aprobado)"
echo "5) Prueba del panel: $RES"
echo "================================="
echo "Desde ahora, para actualizar escribe solo:  actualizar"
echo "O en el panel: Mas > Actualizaciones (con tu codigo de 2 pasos)."
