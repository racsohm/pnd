#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# respaldo.sh — Dump y restore de la base Mongo de una instancia
#
# Uso:
#   bash respaldo.sh dump                      # respalda instancia única
#   bash respaldo.sh dump --instance pnd_x     # elige instancia
#   bash respaldo.sh dump --keep 7             # rota: deja últimos 7
#   bash respaldo.sh list                      # lista respaldos existentes
#   bash respaldo.sh restore <archivo.gz>      # restaura archivo
#   bash respaldo.sh restore <archivo.gz> --drop=false   # sin --drop
#
# Los respaldos se guardan en <instancia>/backups/. Lee credenciales de
# SistemaDeclaraciones_backend/.env y ejecuta mongodump/mongorestore
# DENTRO del contenedor pdnmx-mongo (no requiere exponer 27017).
# ═══════════════════════════════════════════════════════════════════
set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

info()    { echo -e "${BLUE}[INFO]${NC}   $*"; }
success() { echo -e "${GREEN}[OK]${NC}     $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}   $*"; }
err()     { echo -e "${RED}[ERROR]${NC}  $*" >&2; exit 1; }

_read_env() {
  local file=$1 var=$2
  [ -f "$file" ] || { echo ""; return; }
  grep -E "^${var}=" "$file" 2>/dev/null | head -1 | cut -d= -f2- || echo ""
}

# ── Args ─────────────────────────────────────────────────────────
ACTION="${1:-}"
[ -n "$ACTION" ] || { ACTION="help"; }
shift || true

INSTANCE=""
KEEP=""
DROP=true
RESTORE_FILE=""
MONGO_CONTAINER="pdnmx-mongo"

usage() {
  cat <<EOF
Uso: bash respaldo.sh <acción> [opciones]

Acciones:
  dump                       Crea respaldo .gz en <instancia>/backups/
  list                       Lista respaldos existentes
  restore <archivo.gz>       Restaura un respaldo (path absoluto o relativo
                             a <instancia>/backups/)
  help                       Esta ayuda

Opciones:
  --instance <nombre>        Selecciona instancia (auto si solo hay una)
  --keep <N>                 (solo dump) Rota: conserva últimos N respaldos
  --drop=false               (solo restore) NO usar --drop (no borra colecciones
                             antes de restaurar). Default: drop=true.
  --container <nombre>       Nombre del contenedor mongo. Default: $MONGO_CONTAINER

Ejemplos:
  bash respaldo.sh dump --keep 7
  bash respaldo.sh dump --instance pnd_tecali
  bash respaldo.sh list --instance pnd_tecali
  bash respaldo.sh restore /opt/pnd_tecali/backups/pnd_tecali_db-20260510-1430.gz
EOF
}

while [ $# -gt 0 ]; do
  case "$1" in
    --instance)  INSTANCE="$2"; shift 2 ;;
    --keep)      KEEP="$2"; shift 2 ;;
    --drop=true)  DROP=true; shift ;;
    --drop=false) DROP=false; shift ;;
    --container) MONGO_CONTAINER="$2"; shift 2 ;;
    --help|-h)   usage; exit 0 ;;
    *)
      if [ "$ACTION" = "restore" ] && [ -z "$RESTORE_FILE" ]; then
        RESTORE_FILE="$1"; shift
      else
        err "Argumento desconocido: $1"
      fi
      ;;
  esac
done

[ "$ACTION" = "help" ] && { usage; exit 0; }

# ── Detectar instancia ───────────────────────────────────────────
BASE_DIR="$(cd "$(dirname "$0")" && pwd)"
PARENT_DIR="$(dirname "$BASE_DIR")"

detect_instances() {
  local d
  for d in "$BASE_DIR" "$PARENT_DIR"/*/; do
    d="${d%/}"
    if [ -f "$d/SistemaDeclaraciones_backend/.env" ]; then
      echo "$d"
    fi
  done | sort -u
}

resolve_instance_dir() {
  if [ -n "$INSTANCE" ]; then
    if [ -d "$PARENT_DIR/$INSTANCE" ]; then echo "$PARENT_DIR/$INSTANCE"
    elif [ -d "$INSTANCE" ]; then echo "$(cd "$INSTANCE" && pwd)"
    else err "Instancia '$INSTANCE' no encontrada en $PARENT_DIR/"
    fi
    return
  fi
  local instances
  mapfile -t instances < <(detect_instances)
  case ${#instances[@]} in
    0) err "No se encontraron instancias en $PARENT_DIR/" ;;
    1) echo "${instances[0]}" ;;
    *)
      echo "" >&2
      echo "Instancias detectadas:" >&2
      local i
      for i in "${!instances[@]}"; do
        echo "  $((i+1))) $(basename "${instances[$i]}")" >&2
      done
      echo -ne "${BOLD}  Elige una [1-${#instances[@]}]: ${NC}" >&2
      read -r choice
      [[ "$choice" =~ ^[0-9]+$ ]] && [ "$choice" -ge 1 ] && [ "$choice" -le "${#instances[@]}" ] \
        || err "Selección inválida"
      echo "${instances[$((choice-1))]}"
      ;;
  esac
}

INSTANCE_DIR="$(resolve_instance_dir)"
INSTANCE_NAME="$(basename "$INSTANCE_DIR")"
BACKEND_ENV="$INSTANCE_DIR/SistemaDeclaraciones_backend/.env"
BACKUPS_DIR="$INSTANCE_DIR/backups"

[ -f "$BACKEND_ENV" ] || err "$BACKEND_ENV no existe"

DB_NAME=$(_read_env "$BACKEND_ENV" MONGO_DB)
DB_USER=$(_read_env "$BACKEND_ENV" MONGO_USERNAME)
DB_PASS=$(_read_env "$BACKEND_ENV" MONGO_PASSWORD)

[ -n "$DB_NAME" ] || err "MONGO_DB vacío en $BACKEND_ENV"
[ -n "$DB_USER" ] || err "MONGO_USERNAME vacío en $BACKEND_ENV"
[ -n "$DB_PASS" ] || err "MONGO_PASSWORD vacío en $BACKEND_ENV"

sudo docker ps --format '{{.Names}}' | grep -q "^${MONGO_CONTAINER}\$" \
  || err "El contenedor '$MONGO_CONTAINER' no está corriendo.\n  Ajusta con --container <nombre>."

mkdir -p "$BACKUPS_DIR"

# ── Acciones ─────────────────────────────────────────────────────
do_dump() {
  local stamp file remote_path
  stamp=$(date +%Y%m%d-%H%M%S)
  file="${DB_NAME}-${stamp}.gz"
  remote_path="/tmp/${file}"

  info "Instancia: $INSTANCE_NAME  |  DB: $DB_NAME"
  info "Generando dump en $MONGO_CONTAINER ..."

  # Pasar credenciales por env para evitar problemas de escape de $/!
  sudo docker exec \
    -e MUSER="$DB_USER" -e MPASS="$DB_PASS" \
    "$MONGO_CONTAINER" sh -c "mongodump \
      --username \"\$MUSER\" --password \"\$MPASS\" \
      --authenticationDatabase admin \
      --db '$DB_NAME' --gzip --archive='$remote_path' --quiet"

  info "Copiando archivo al host ..."
  sudo docker cp "$MONGO_CONTAINER:$remote_path" "$BACKUPS_DIR/$file"
  sudo docker exec "$MONGO_CONTAINER" rm -f "$remote_path"

  local size
  size=$(du -h "$BACKUPS_DIR/$file" | cut -f1)
  success "Respaldo creado: $BACKUPS_DIR/$file ($size)"

  if [ -n "$KEEP" ]; then
    [[ "$KEEP" =~ ^[0-9]+$ ]] || err "--keep espera un número entero"
    info "Rotando: conservando últimos $KEEP respaldos de $DB_NAME ..."
    local removed=0
    # Listado por mtime descendente; saltar los primeros $KEEP, eliminar el resto
    while IFS= read -r f; do
      rm -f "$f"
      info "  eliminado: $(basename "$f")"
      removed=$((removed+1))
    done < <(ls -1t "$BACKUPS_DIR/${DB_NAME}-"*.gz 2>/dev/null | tail -n +$((KEEP+1)))
    [ $removed -eq 0 ] && info "  no se eliminó ningún archivo."
  fi
}

do_list() {
  info "Respaldos en $BACKUPS_DIR:"
  if [ -z "$(ls -A "$BACKUPS_DIR" 2>/dev/null)" ]; then
    warn "  (sin respaldos todavía)"
    return
  fi
  ls -lh --time-style=long-iso "$BACKUPS_DIR" | awk 'NR>1 {printf "  %s  %s  %s %s\n", $5, $6, $7, $8}'
}

do_restore() {
  [ -n "$RESTORE_FILE" ] || err "Falta archivo a restaurar.\n  Uso: bash respaldo.sh restore <archivo.gz>"

  # Resolver path: absoluto, o relativo a backups dir
  local src
  if [ -f "$RESTORE_FILE" ]; then
    src="$(cd "$(dirname "$RESTORE_FILE")" && pwd)/$(basename "$RESTORE_FILE")"
  elif [ -f "$BACKUPS_DIR/$RESTORE_FILE" ]; then
    src="$BACKUPS_DIR/$RESTORE_FILE"
  else
    err "Archivo no encontrado: $RESTORE_FILE\n  Buscado también en $BACKUPS_DIR/"
  fi

  warn "Restauración → DB: $DB_NAME (instancia: $INSTANCE_NAME)"
  warn "Archivo: $src"
  $DROP && warn "Modo: --drop ACTIVO (las colecciones existentes serán reemplazadas)" \
        || warn "Modo: SIN --drop (mezcla con datos actuales)"
  echo -ne "${BOLD}¿Continuar? [s/N]: ${NC}"
  read -r confirm
  [[ "$confirm" =~ ^[sSyY]$ ]] || { info "Cancelado."; exit 0; }

  local remote_path="/tmp/restore-$(date +%s).gz"
  info "Copiando archivo al contenedor ..."
  sudo docker cp "$src" "$MONGO_CONTAINER:$remote_path"

  local drop_flag=""
  $DROP && drop_flag="--drop"

  info "Restaurando en $MONGO_CONTAINER ..."
  sudo docker exec \
    -e MUSER="$DB_USER" -e MPASS="$DB_PASS" \
    "$MONGO_CONTAINER" sh -c "mongorestore \
      --username \"\$MUSER\" --password \"\$MPASS\" \
      --authenticationDatabase admin \
      --gzip --archive='$remote_path' $drop_flag \
      --nsFrom='*.*' --nsTo='${DB_NAME}.*' --quiet"

  sudo docker exec "$MONGO_CONTAINER" rm -f "$remote_path"
  success "Restauración completada en DB '$DB_NAME'."
}

case "$ACTION" in
  dump)    do_dump ;;
  list)    do_list ;;
  restore) do_restore ;;
  *)       err "Acción desconocida: $ACTION\n  Usa: dump | list | restore | help" ;;
esac
