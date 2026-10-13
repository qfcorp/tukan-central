#!/usr/bin/env bash
# Instalador del Panel Tukan Central (Ubuntu con PHP, SQLite, nginx y tunel de Cloudflare) - servidor qfcorp.
# Uso (como el usuario normal, NO como root):
#   curl -fsSL https://raw.githubusercontent.com/qfcorp/tukan-central/main/deploy/instalar.sh -o ~/instalar_central.sh && bash ~/instalar_central.sh
# Se puede correr varias veces: NO borra la base ni cambia la llave TUKAN_CENTRAL_KEY.
# Los mensajes van sin tildes a proposito (se leen por SSH desde el celular).
set -u

APP="${APP:-/var/www/tukan-central}"
DOMINIO="${DOMINIO:-panel.tukanec.com}"
REPO="${REPO:-https://github.com/qfcorp/tukan-central.git}"
PHPV="${PHPV:-$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo 8.3)}"
RESPALDOS="${RESPALDOS:-/var/backups/tukan-central}"
YO="$(id -un)"
cd /tmp || exit 1

if [ "$(id -u)" = "0" ]; then
  echo "AVISO: corre este instalador con tu usuario normal (no como root). Se usa sudo cuando hace falta."
fi

echo "== 1. Programas (PHP $PHPV con SQLite, curl y mbstring)"
sudo apt-get install -y "php$PHPV-fpm" "php$PHPV-cli" "php$PHPV-sqlite3" "php$PHPV-curl" "php$PHPV-mbstring" sqlite3 git openssl < /dev/null > /dev/null \
  && echo "   listo" || echo "   AVISO: no se pudieron instalar algunos programas"
if php -r 'exit(function_exists("sodium_crypto_aead_xchacha20poly1305_ietf_encrypt") ? 0 : 1);'; then
  echo "   cifrado (sodium): OK"
else
  echo "   ERROR: PHP no tiene la extension sodium (viene con php$PHPV-common). Instalala y vuelve a correr."
  exit 1
fi

echo "== 2. Bajar o actualizar el panel"
if [ -d "$APP/.git" ]; then
  sudo chown -R "$YO":www-data "$APP"
  git -C "$APP" pull -q && echo "   actualizado ($(git -C "$APP" rev-parse --short HEAD))" || echo "   AVISO: no se pudo actualizar (git pull)"
else
  if sudo git clone -q "$REPO" "$APP"; then echo "   bajado"; else echo "   ERROR: no se pudo bajar $REPO"; exit 1; fi
fi
sudo chown -R "$YO":www-data "$APP"
sudo chmod -R g-w,o-rwx "$APP"
chmod +x "$APP/deploy/"*.sh 2>/dev/null || true

echo "== 3. Carpetas de datos (fuera de la carpeta publica)"
sudo mkdir -p "$APP/datos/sesiones" "$APP/config" /var/log/tukan-central
sudo chown -R www-data:www-data "$APP/datos" /var/log/tukan-central
sudo chmod 750 "$APP/datos" && sudo chmod 700 "$APP/datos/sesiones"
sudo chown "$YO":www-data "$APP/config" && sudo chmod 750 "$APP/config"
echo "   listo"

echo "== 4. Configuracion (config/.env)"
ENV="$APP/config/.env"
LLAVE=""; EXTRA=""
if [ -f "$ENV" ]; then   # se conservan la llave, Telegram y los ajustes propios
  LLAVE="$(grep -E '^TUKAN_CENTRAL_KEY=' "$ENV" | head -1 | cut -d= -f2-)"
  EXTRA="$(grep -E '^(TELEGRAM_|TUKAN_PROXIES_CONFIABLES=)' "$ENV" || true)"
fi
if [ -z "$LLAVE" ]; then
  if [ -s "$APP/datos/central.sqlite" ] && [ "${FORZAR_LLAVE_NUEVA:-0}" != "1" ]; then
    echo "   ERROR: ya hay una base de datos pero falta la llave TUKAN_CENTRAL_KEY en $ENV."
    echo "   Sin esa llave no se pueden leer los secretos guardados. Copia config/.env desde el respaldo"
    echo "   ($RESPALDOS/env_*.txt) y vuelve a correr. Si de verdad quieres una llave nueva:"
    echo "   FORZAR_LLAVE_NUEVA=1 bash ~/instalar_central.sh   (luego: Cambiar secreto en cada programa y quitar_2fa a cada usuario)"
    exit 1
  fi
  LLAVE="$(openssl rand -hex 32)"
  echo "   llave nueva generada"
else
  echo "   se conservo la llave"
fi
{
  echo "# Panel Tukan Central - generado por deploy/instalar.sh. NO subir a GitHub."
  echo "TUKAN_CENTRAL_KEY=$LLAVE"
  echo "TUKAN_CENTRAL_URL=https://$DOMINIO"
  echo "TUKAN_CENTRAL_DATOS=$APP/datos"
  [ -n "$EXTRA" ] && echo "$EXTRA"
} > "$ENV.nuevo"
mv "$ENV.nuevo" "$ENV"
chmod 640 "$ENV" && sudo chgrp www-data "$ENV" && echo "   listo"
echo "$EXTRA" | grep -q '^TELEGRAM_BOT_TOKEN=' && echo "   Telegram: se conservo la configuracion" \
  || echo "   Telegram: sin configurar (agrega TELEGRAM_BOT_TOKEN y TELEGRAM_CHAT_ID en $ENV)"

echo "== 5. Base de datos (SQLite)"
if sudo -u www-data php "$APP/bin/revisar.php" < /dev/null > /dev/null 2>&1; then
  echo "   lista: $APP/datos/central.sqlite"
else
  echo "   ERROR al crear la base. Prueba: sudo -u www-data php $APP/bin/revisar.php"
fi

echo "== 6. nginx (puerto 80, $DOMINIO)"
if command -v nginx > /dev/null; then
  sudo tee /etc/nginx/sites-available/tukan-central > /dev/null <<EOF
server {
    listen 80;
    server_name $DOMINIO;
    root $APP/public;
    index index.php;
    client_max_body_size 1M;
    server_tokens off;

    location / {
        try_files \$uri /index.php\$is_args\$args;
    }
    location /assets/ {
        expires 7d;
        try_files \$uri =404;
    }
    location ~ /\. { deny all; }
    location = /index.php {
        include snippets/fastcgi-php.conf;
        fastcgi_param HTTP_PROXY "";
        fastcgi_pass unix:/run/php/php$PHPV-fpm.sock;
    }
    location ~ \.php\$ { return 404; }
}
EOF
  sudo ln -sf /etc/nginx/sites-available/tukan-central /etc/nginx/sites-enabled/tukan-central
  sudo nginx -t -q && sudo systemctl reload nginx && sudo systemctl restart "php$PHPV-fpm" && echo "   listo" || echo "   ERROR en la configuracion de nginx"
else
  echo "   (nginx no esta instalado: se omite)"
fi

echo "== 7. Revision cada 15 minutos y respaldo diario (3:40, guarda 14)"
(sudo crontab -u www-data -l 2>/dev/null | grep -v "tukan-central/bin/revisar"; echo "*/15 * * * * php $APP/bin/revisar.php >> /var/log/tukan-central/revisar.log 2>&1") | sudo crontab -u www-data -
(sudo crontab -l 2>/dev/null | grep -v "tukan-central/deploy/respaldo"; echo "40 3 * * * APP=$APP $APP/deploy/respaldo.sh >> /var/log/tukan-central/respaldo.log 2>&1") | sudo crontab -
echo "   listo"

echo
echo "=========== RESULTADO ==========="
[ -f "$ENV" ] && echo "1) Configuracion: OK" || echo "1) Configuracion: FALTA"
echo "2) Extensiones de PHP: $(php -m | grep -E -c '^(pdo_sqlite|curl|mbstring|sodium)$') (deben ser 4)"
echo "3) Tablas en la base: $(sudo -u www-data sqlite3 "$APP/datos/central.sqlite" "SELECT count(*) FROM sqlite_master WHERE type='table'" 2>/dev/null) (deben ser 9)"
if command -v nginx > /dev/null; then
  curl -s -o /dev/null -w "4) Prueba de la pagina: %{http_code} (debe ser 200)\n" -H "Host: $DOMINIO" "http://127.0.0.1/?r=login"
fi
echo "5) Revision: $(sudo -u www-data php "$APP/bin/revisar.php" < /dev/null 2>&1 | tail -1)"
echo "6) Respaldo: $(sudo APP="$APP" "$APP/deploy/respaldo.sh" < /dev/null 2>&1 | tail -1)"
echo "7) Usuarios del panel: $(sudo -u www-data sqlite3 "$APP/datos/central.sqlite" "SELECT count(*) FROM usuarios" 2>/dev/null)"
echo "================================="
echo "Para crear tu usuario (o cambiar la contrasena):"
echo "   sudo -u www-data php $APP/bin/crear_admin.php"
echo "En Cloudflare (Zero Trust > Tunnels > tu tunel > Public Hostname):"
echo "   $DOMINIO -> HTTP -> localhost:80   (HTTP Host Header: $DOMINIO)"
echo "Luego entra en: https://$DOMINIO"
