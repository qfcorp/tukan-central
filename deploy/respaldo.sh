#!/usr/bin/env bash
# Respaldo diario del Panel Tukan Central: base SQLite + config/.env (la llave para leer los secretos). Guarda 14.
# Cron (root, todos los dias a las 3:40):
#   40 3 * * * APP=/var/www/tukan-central /var/www/tukan-central/deploy/respaldo.sh >> /var/log/tukan-central/respaldo.log 2>&1
set -euo pipefail
umask 077   # solo root puede leer los respaldos (tienen la llave)

APP="${APP:-/var/www/tukan-central}"
DESTINO="${DESTINO:-/var/backups/tukan-central}"
GUARDAR="${GUARDAR:-14}"
BASE="$APP/datos/central.sqlite"

if [ ! -f "$BASE" ]; then
  echo "$(date '+%F %T') ERROR: no existe $BASE"
  exit 1
fi
FECHA=$(date +%Y-%m-%d_%H%M%S)
mkdir -p "$DESTINO"
chmod 700 "$DESTINO"
COPIA="$DESTINO/central_$FECHA.sqlite"

# Copia segura con la base en uso (.backup de sqlite3; si no esta, VACUUM INTO con PHP)
if command -v sqlite3 > /dev/null; then
  sqlite3 "$BASE" ".backup '$COPIA'"
else
  php -r '$d = new PDO("sqlite:" . $argv[1]); $d->exec("VACUUM INTO " . $d->quote($argv[2]));' "$BASE" "$COPIA"
fi
# Verifica que el respaldo se pueda leer
if command -v sqlite3 > /dev/null; then
  [ "$(sqlite3 "$COPIA" 'PRAGMA integrity_check')" = "ok" ] || { echo "$(date '+%F %T') ERROR: el respaldo no paso la revision"; exit 1; }
fi
gzip -f "$COPIA"
[ -f "$APP/config/.env" ] && cp "$APP/config/.env" "$DESTINO/env_$FECHA.txt"

# Deja solo los ultimos $GUARDAR
ls -1t "$DESTINO"/central_*.sqlite.gz 2>/dev/null | tail -n +"$((GUARDAR + 1))" | xargs -r rm -f
ls -1t "$DESTINO"/env_*.txt 2>/dev/null | tail -n +"$((GUARDAR + 1))" | xargs -r rm -f

echo "$(date '+%F %T') respaldo OK: $(du -h "$COPIA.gz" | cut -f1) ($(ls -1 "$DESTINO"/central_*.sqlite.gz | wc -l) guardados)"
