#!/usr/bin/env bash
# ============================================================
# scripts/db-dump.sh — Backup completo de la DB de desarrollo
#
# Uso (desde la raíz del repo):
#   ./scripts/db-dump.sh                  # nombre auto: backups/ithub-dump-YYYYMMDD-HHMM.sql.gz
#   ./scripts/db-dump.sh mi-backup        # nombre custom: backups/mi-backup.sql.gz
#
# Lee credenciales desde .env (raíz). No imprime passwords.
# El archivo resultante NO se commitea (backups/ está gitignored).
# ============================================================
set -euo pipefail

cd "$(dirname "$0")/.."

if [[ ! -f .env ]]; then
  echo "ERROR: falta .env en la raíz. Copialo de .env.example y completá credenciales."
  exit 1
fi

# Cargar .env sin contaminar el shell del usuario
set -a
# shellcheck disable=SC1091
source .env
set +a

NAME="${1:-ithub-dump-$(date +%Y%m%d-%H%M)}"
OUT_DIR="backups"
OUT_FILE="${OUT_DIR}/${NAME}.sql.gz"

mkdir -p "$OUT_DIR"

echo "==> Generando dump de '${DB_NAME}' a ${OUT_FILE}"

docker compose exec -T db \
  mysqldump \
    -uroot \
    -p"${DB_ROOT_PASSWORD}" \
    --databases "${DB_NAME}" \
    --single-transaction \
    --routines \
    --triggers \
    --events \
    --default-character-set=utf8mb4 \
    --set-gtid-purged=OFF \
  | gzip > "${OUT_FILE}"

SIZE=$(du -h "${OUT_FILE}" | cut -f1)
echo "==> OK — ${OUT_FILE} (${SIZE})"
echo ""
echo "Para restaurarlo en otra máquina:"
echo "  ./scripts/db-restore.sh ${OUT_FILE}"
