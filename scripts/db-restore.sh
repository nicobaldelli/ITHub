#!/usr/bin/env bash
# ============================================================
# scripts/db-restore.sh — Restaurar dump en la DB de desarrollo
#
# Uso (desde la raíz del repo):
#   ./scripts/db-restore.sh ruta/al/dump.sql.gz
#
# Requisitos:
#   - docker compose up -d db ya corriendo
#   - .env con DB_ROOT_PASSWORD y DB_NAME definidos
#
# ⚠️ DROPEA la DB existente antes de importar. Pide confirmación.
# ============================================================
set -euo pipefail

cd "$(dirname "$0")/.."

if [[ $# -lt 1 ]]; then
  echo "Uso: $0 <archivo.sql.gz>"
  exit 1
fi

DUMP_FILE="$1"

if [[ ! -f "$DUMP_FILE" ]]; then
  echo "ERROR: no existe el archivo $DUMP_FILE"
  exit 1
fi

if [[ ! -f .env ]]; then
  echo "ERROR: falta .env en la raíz."
  exit 1
fi

set -a
# shellcheck disable=SC1091
source .env
set +a

echo "==> ATENCIÓN: esto DROPEA la base '${DB_NAME}' y restaura desde:"
echo "    ${DUMP_FILE}"
read -rp "¿Continuar? (escribí 'yes' para confirmar): " CONFIRM
if [[ "$CONFIRM" != "yes" ]]; then
  echo "Cancelado."
  exit 0
fi

echo "==> 1/3 Dropeando y recreando ${DB_NAME}..."
docker compose exec -T db \
  mysql -uroot -p"${DB_ROOT_PASSWORD}" \
  -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`;
      CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
      GRANT ALL ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';"

echo "==> 2/3 Importando dump (puede tardar)..."
if [[ "$DUMP_FILE" == *.gz ]]; then
  gunzip -c "$DUMP_FILE" | docker compose exec -T db \
    mysql -uroot -p"${DB_ROOT_PASSWORD}" "${DB_NAME}"
else
  docker compose exec -T db \
    mysql -uroot -p"${DB_ROOT_PASSWORD}" "${DB_NAME}" < "$DUMP_FILE"
fi

echo "==> 3/3 Verificando..."
docker compose exec -T db \
  mysql -uroot -p"${DB_ROOT_PASSWORD}" "${DB_NAME}" \
  -e "SELECT COUNT(*) AS tablas FROM information_schema.tables WHERE table_schema='${DB_NAME}';
      SELECT COUNT(*) AS users FROM users;
      SELECT MAX(version) AS ultima_migracion FROM phinxlog;"

echo ""
echo "==> Restauración completa. Si el phinxlog no coincide con tus migraciones locales:"
echo "    docker compose exec api vendor/bin/phinx migrate"
