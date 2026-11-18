#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# respaldo.sh — Dump y restore de la base Mongo de una instancia
#
# Uso:
#   bash respaldo.sh dump                      # respalda instancia única
#   bash respaldo.sh dump --instance pnd_x     # elige instancia
#   bash respaldo.sh dump --keep 7             # rota: deja últimos 7
#   bash respaldo.sh list                      # lista respaldos existentes
#   bash respaldo.sh restore <archivo.zip>     # restaura ZIP con BSON
#   bash respaldo.sh restore <archivo.gz>      # admite archivos archive antiguos
#   bash respaldo.sh restore <archivo.zip> --drop=false   # sin --drop
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
  dump                       Crea respaldo .zip con BSON en <instancia>/backups/
  list                       Lista respaldos existentes
  restore <archivo.zip|gz>   Restaura un respaldo (ZIP BSON nuevo o archive
                             .gz anterior; path absoluto o relativo a backups/)
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
  bash respaldo.sh restore /opt/pnd_tecali/backups/pnd_tecali_db-20260510-1430.zip
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
  local stamp file remote_path staging_dir bson_count size
  stamp=$(date +%Y%m%d-%H%M%S)
  file="${DB_NAME}-${stamp}.zip"
  remote_path="/tmp/mongodump-${DB_NAME}-${stamp}-$$"
  staging_dir="$(mktemp -d "${TMPDIR:-/tmp}/pnd-dump-${stamp}.XXXXXX")"

  cleanup_dump() {
    sudo docker exec "$MONGO_CONTAINER" rm -rf "$remote_path" >/dev/null 2>&1 || true
    rm -rf -- "$staging_dir"
  }
  trap cleanup_dump EXIT

  info "Instancia: $INSTANCE_NAME  |  DB: $DB_NAME"
  info "Generando dump en $MONGO_CONTAINER ..."

  # Salida de mongodump en archivos BSON y metadatos por colección.
  sudo docker exec \
    -e MUSER="$DB_USER" -e MPASS="$DB_PASS" \
    -e DB_NAME="$DB_NAME" -e DUMP_DIR="$remote_path" \
    "$MONGO_CONTAINER" sh -c 'mongodump \
      --username "$MUSER" --password "$MPASS" \
      --authenticationDatabase admin \
      --db "$DB_NAME" --out "$DUMP_DIR" --quiet'

  info "Validando que mongorestore pueda leer el dump BSON ..."
  sudo docker exec \
    -e MUSER="$DB_USER" -e MPASS="$DB_PASS" \
    -e DB_NAME="$DB_NAME" -e DUMP_DIR="$remote_path" \
    "$MONGO_CONTAINER" sh -c 'mongorestore \
      --username "$MUSER" --password "$MPASS" \
      --authenticationDatabase admin \
      --dryRun --dir "$DUMP_DIR" \
      --nsFrom="*.*" --nsTo="$DB_NAME.*" --quiet'

  mkdir -p "$staging_dir/$DB_NAME"
  info "Copiando BSON al host para crear el ZIP ..."
  sudo docker cp "$MONGO_CONTAINER:$remote_path/$DB_NAME/." "$staging_dir/$DB_NAME/"

  command -v python3 >/dev/null 2>&1 || err "python3 es necesario para crear y validar el ZIP."
  bson_count="$(python3 - "$staging_dir" "$BACKUPS_DIR/$file" <<'PY'
from pathlib import Path
import sys, zipfile

root = Path(sys.argv[1])
destination = Path(sys.argv[2])
temporary = destination.with_suffix(destination.suffix + '.tmp')
files = sorted(p for p in root.rglob('*') if p.is_file())
bson_files = [p for p in files if p.suffix == '.bson']
if not bson_files:
    raise SystemExit('El dump no contiene archivos .bson; no se creará un ZIP vacío.')

try:
    with zipfile.ZipFile(temporary, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
        for path in files:
            archive.write(path, path.relative_to(root).as_posix())

    with zipfile.ZipFile(temporary) as archive:
        corrupt = archive.testzip()
        if corrupt:
            raise SystemExit(f'El ZIP no pasó la verificación CRC: {corrupt}')
        names = archive.namelist()
        if not any(name.endswith('.bson') for name in names):
            raise SystemExit('El ZIP final no contiene archivos .bson.')
    temporary.replace(destination)
except BaseException:
    temporary.unlink(missing_ok=True)
    raise
print(len(bson_files))
PY
)"

  size=$(du -h "$BACKUPS_DIR/$file" | cut -f1)
  success "Respaldo ZIP creado: $BACKUPS_DIR/$file ($size; $bson_count archivos BSON y metadatos)"

  if [ -n "$KEEP" ]; then
    [[ "$KEEP" =~ ^[0-9]+$ ]] || err "--keep espera un número entero"
    info "Rotando: conservando últimos $KEEP respaldos de $DB_NAME ..."
    local removed=0
    # Listado por mtime descendente; saltar los primeros $KEEP, eliminar el resto
    while IFS= read -r f; do
      rm -f "$f"
      info "  eliminado: $(basename "$f")"
      removed=$((removed+1))
    done < <(find "$BACKUPS_DIR" -maxdepth 1 -type f \
      \( -name "${DB_NAME}-*.zip" -o -name "${DB_NAME}-*.gz" \) \
      -printf '%T@ %p\n' 2>/dev/null | sort -nr | tail -n +$((KEEP+1)) | cut -d' ' -f2-)
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
  [ -n "$RESTORE_FILE" ] || err "Falta archivo a restaurar.\n  Uso: bash respaldo.sh restore <archivo.zip|gz>"

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

  local remote_path="/tmp/restore-$(date +%s)-$$"
  local extract_dir=""
  local kind="${src##*.}"
  local drop_flag=""
  $DROP && drop_flag="--drop"

  cleanup_restore() {
    sudo docker exec "$MONGO_CONTAINER" rm -rf "$remote_path" "$remote_path.gz" >/dev/null 2>&1 || true
    [ -z "$extract_dir" ] || rm -rf -- "$extract_dir"
  }
  trap cleanup_restore EXIT

  case "$kind" in
    zip)
      command -v python3 >/dev/null 2>&1 || err "python3 es necesario para extraer respaldos ZIP."
      extract_dir="$(mktemp -d "${TMPDIR:-/tmp}/pnd-restore.XXXXXX")"
      info "Validando y extrayendo BSON del ZIP ..."
      python3 - "$src" "$extract_dir" "$DB_NAME" <<'PYZIP'
from pathlib import Path, PurePosixPath
import sys, zipfile

archive_path, destination, db_name = sys.argv[1:4]
destination = Path(destination)
with zipfile.ZipFile(archive_path) as archive:
    bad = archive.testzip()
    if bad:
        raise SystemExit(f'ZIP dañado (falló CRC): {bad}')
    names = [info.filename for info in archive.infolist() if not info.is_dir()]
    if not names or not any(name.endswith('.bson') for name in names):
        raise SystemExit('El ZIP no contiene archivos BSON para restaurar.')
    for info in archive.infolist():
        name = PurePosixPath(info.filename)
        mode = info.external_attr >> 16
        if name.is_absolute() or '..' in name.parts or (mode & 0o170000) == 0o120000:
            raise SystemExit(f'Ruta o enlace no permitido dentro del ZIP: {info.filename}')
    roots = {PurePosixPath(name).parts[0] for name in names}
    if len(roots) != 1 or next(iter(roots)) != db_name:
        raise SystemExit(f'El ZIP debe contener una carpeta raíz llamada {db_name}.')
    archive.extractall(destination)
print('ZIP válido')
PYZIP
      local_dump_dir="$extract_dir/$DB_NAME"
      [ -d "$local_dump_dir" ] || err "El ZIP no contiene la carpeta esperada '$DB_NAME'."
      info "Copiando carpeta BSON al contenedor ..."
      sudo docker exec "$MONGO_CONTAINER" mkdir -p "$remote_path"
      sudo docker cp "$local_dump_dir" "$MONGO_CONTAINER:$remote_path/"
      info "Restaurando BSON en $MONGO_CONTAINER ..."
      sudo docker exec \
        -e MUSER="$DB_USER" -e MPASS="$DB_PASS" \
        -e DB_NAME="$DB_NAME" -e RESTORE_DIR="$remote_path" \
        "$MONGO_CONTAINER" sh -c 'mongorestore \
          --username "$MUSER" --password "$MPASS" \
          --authenticationDatabase admin \
          --dir "$RESTORE_DIR" '"$drop_flag"' \
          --nsFrom="*.*" --nsTo="$DB_NAME.*" --quiet'
      ;;
    gz)
      info "Copiando archive antiguo al contenedor ..."
      sudo docker cp "$src" "$MONGO_CONTAINER:$remote_path.gz"
      info "Restaurando archive gzip en $MONGO_CONTAINER ..."
      sudo docker exec \
        -e MUSER="$DB_USER" -e MPASS="$DB_PASS" \
        -e DB_NAME="$DB_NAME" -e RESTORE_FILE="$remote_path.gz" \
        "$MONGO_CONTAINER" sh -c 'mongorestore \
          --username "$MUSER" --password "$MPASS" \
          --authenticationDatabase admin \
          --gzip --archive="$RESTORE_FILE" '"$drop_flag"' \
          --nsFrom="*.*" --nsTo="$DB_NAME.*" --quiet'
      ;;
    *) err "Formato no compatible: .$kind. Usa .zip o .gz." ;;
  esac

  success "Restauración completada en DB '$DB_NAME'."
}

case "$ACTION" in
  dump)    do_dump ;;
  list)    do_list ;;
  restore) do_restore ;;
  *)       err "Acción desconocida: $ACTION\n  Usa: dump | list | restore | help" ;;
esac
