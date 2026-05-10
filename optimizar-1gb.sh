#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# optimizar-1gb.sh — Ajusta el host y los contenedores para correr
# SistemaDeclaraciones en una VPS de 1 GB de RAM (con swap como red
# de seguridad). Aplica tres capas de optimización:
#
#   1) Sistema operativo
#      - swap de 4 GB (configurable con SWAP_SIZE_GB)
#      - vm.swappiness ajustada para evitar thrashing
#
#   2) MongoDB
#      - wiredTiger.cacheSizeGB capado a 0.25 GB (defecto consume hasta
#        50% de la RAM) en database-test/mongodb/config/mongod.conf
#      - mem_limit de 320 MB sobre el contenedor
#
#   3) Contenedores por instancia
#      - mem_limit ajustado por servicio
#      - NODE_OPTIONS=--max-old-space-size=200 para el backend
#        (de otro modo V8 puede crecer más allá del mem_limit y morir
#        por OOM en mitad de una request)
#      - Detecta instancias hermanas en ../<nombre>/docker-compose.yml
#        y las parcha también
#
# Cifras esperadas tras la optimización (idle, sin tráfico):
#
#   ┌──────────────────┬─────────────┬───────────────┐
#   │ Componente       │ mem_limit   │ Uso real ~    │
#   ├──────────────────┼─────────────┼───────────────┤
#   │ OS + dockerd     │ —           │ 150-200 MB    │
#   │ pdnmx-mongo      │ 320 MB      │ 250-300 MB    │
#   │ <inst>-reports   │  96 MB      │  50- 70 MB    │
#   │ <inst>-app       │ 280 MB      │ 180-220 MB    │
#   │ <inst>-webapp    │  48 MB      │  10- 15 MB    │
#   └──────────────────┴─────────────┴───────────────┘
#
#   Baseline (OS + Mongo): ~500 MB
#   Por instancia idle:    ~270 MB
#
#   1 instancia → ~770 MB   ✓ cabe en 1 GB sin swap
#   2 instancias → ~1.04 GB  swap absorbe ~40 MB
#   3 instancias → ~1.31 GB  swap absorbe ~310 MB (degrada bajo carga)
#
# ¡Atención al BUILD!
#   - Construir Angular y TypeScript en una VPS de 1 GB requiere swap
#     suficiente (mínimo 2 GB libre, recomendado 4 GB) o el build falla
#     con "JavaScript heap out of memory".
#   - La alternativa profesional: construir las imágenes en otra
#     máquina, hacer push a un registry y pull en la VPS. Ese flujo
#     no está cubierto por este script.
#
# Uso:
#   bash optimizar-1gb.sh             # interactivo, pide confirmación
#   bash optimizar-1gb.sh --yes       # no preguntar (autom.)
#   SWAP_SIZE_GB=2 bash optimizar-1gb.sh  # personalizar
#
# Idempotente: se puede ejecutar varias veces.
# ═══════════════════════════════════════════════════════════════════
set -euo pipefail

# ── Colores ──────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'

info()    { echo -e "${BLUE}[INFO]${NC}   $*"; }
success() { echo -e "${GREEN}[OK]${NC}     $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}   $*"; }
err()     { echo -e "${RED}[ERROR]${NC}  $*" >&2; exit 1; }
header()  { echo -e "\n${BOLD}${BLUE}──────────────────────────────────────${NC}"; \
            echo -e "${BOLD}${BLUE}  $*${NC}"; \
            echo -e "${BOLD}${BLUE}──────────────────────────────────────${NC}"; }

# ── Config (sobreescribibles vía env) ────────────────────────────
SWAP_SIZE_GB=${SWAP_SIZE_GB:-4}
SWAP_FILE=${SWAP_FILE:-/swapfile}
SWAPPINESS=${SWAPPINESS:-30}
MONGO_CACHE_GB=${MONGO_CACHE_GB:-0.25}
MONGO_MEM_LIMIT=${MONGO_MEM_LIMIT:-320m}
APP_MEM_LIMIT=${APP_MEM_LIMIT:-280m}
APP_NODE_HEAP_MB=${APP_NODE_HEAP_MB:-200}
REPORTS_MEM_LIMIT=${REPORTS_MEM_LIMIT:-96m}
WEBAPP_MEM_LIMIT=${WEBAPP_MEM_LIMIT:-48m}

NON_INTERACTIVE=false
[ "${1:-}" = "--yes" ] || [ "${1:-}" = "-y" ] && NON_INTERACTIVE=true

BASE_DIR="$(cd "$(dirname "$0")" && pwd)"

# ── Banner ───────────────────────────────────────────────────────
echo -e "${BOLD}${BLUE}"
echo "  ╔══════════════════════════════════════════════╗"
echo "  ║  SistemaDeclaraciones PDNMX — Optim. 1 GB   ║"
echo "  ╚══════════════════════════════════════════════╝"
echo -e "${NC}"

# ── 1. Diagnóstico previo ────────────────────────────────────────
diagnose() {
  header "Diagnóstico"
  local total_kb=$(awk '/^MemTotal:/ {print $2}' /proc/meminfo)
  local total_mb=$((total_kb / 1024))
  local swap_kb=$(awk '/^SwapTotal:/ {print $2}' /proc/meminfo)
  local swap_mb=$((swap_kb / 1024))
  local cur_swappiness=$(cat /proc/sys/vm/swappiness 2>/dev/null || echo "?")

  echo "  RAM total:      ${total_mb} MB"
  echo "  Swap actual:    ${swap_mb} MB"
  echo "  swappiness:     ${cur_swappiness}"
  echo "  BASE_DIR:       ${BASE_DIR}"
  echo ""
  echo "  Plan:"
  echo "    • swap objetivo:       ${SWAP_SIZE_GB} GB (en ${SWAP_FILE})"
  echo "    • vm.swappiness:       ${SWAPPINESS}"
  echo "    • mongo cacheSizeGB:   ${MONGO_CACHE_GB}"
  echo "    • mongo mem_limit:     ${MONGO_MEM_LIMIT}"
  echo "    • app mem_limit:       ${APP_MEM_LIMIT}  (NODE heap ${APP_NODE_HEAP_MB}m)"
  echo "    • reports mem_limit:   ${REPORTS_MEM_LIMIT}"
  echo "    • webapp mem_limit:    ${WEBAPP_MEM_LIMIT}"
  echo ""

  if [ "$NON_INTERACTIVE" = false ]; then
    read -rp "¿Continuar? [s/N] " ans
    [[ "$ans" =~ ^[sSyY]$ ]] || { info "Cancelado por el usuario."; exit 0; }
  fi
}

# ── 2. Crear/agrandar swap ───────────────────────────────────────
ensure_swap() {
  header "Swap (${SWAP_SIZE_GB} GB)"
  local desired_mb=$((SWAP_SIZE_GB * 1024))
  local current_mb=$(awk '/^SwapTotal:/ {print int($2 / 1024)}' /proc/meminfo)

  if [ "$current_mb" -ge "$desired_mb" ]; then
    success "Swap ya cubre el objetivo: ${current_mb} MB ≥ ${desired_mb} MB"
    # Aún así, asegurar que la entrada en fstab y el servicio OpenRC
    # estén configurados — ejecuciones previas pueden haber omitido esto.
    if ! grep -qF "$SWAP_FILE" /etc/fstab 2>/dev/null; then
      info "Swap activo pero falta entrada en /etc/fstab — agregándola"
      echo "$SWAP_FILE none swap sw 0 0" | sudo tee -a /etc/fstab > /dev/null
    fi
    ensure_swap_persistence_alpine
    return
  fi

  if sudo test -f "$SWAP_FILE"; then
    warn "Existe $SWAP_FILE pero es menor al objetivo. Lo recreamos."
    sudo swapoff "$SWAP_FILE" 2>/dev/null || true
    sudo rm -f "$SWAP_FILE"
  fi

  info "Creando $SWAP_FILE (${SWAP_SIZE_GB} GB)..."
  if sudo fallocate -l "${SWAP_SIZE_GB}G" "$SWAP_FILE" 2>/dev/null; then
    :
  else
    info "fallocate no disponible — fallback a dd (más lento)..."
    sudo dd if=/dev/zero of="$SWAP_FILE" bs=1M count=$((SWAP_SIZE_GB * 1024)) status=progress
  fi

  sudo chmod 600 "$SWAP_FILE"
  sudo mkswap "$SWAP_FILE" > /dev/null
  sudo swapon "$SWAP_FILE"

  # Verificar que sí se activó: hay VPS basadas en OpenVZ donde swapon
  # retorna 0 pero el kernel del host no monta swap del huésped.
  if ! grep -qF "$SWAP_FILE" /proc/swaps 2>/dev/null; then
    err "swapon corrió pero $SWAP_FILE no aparece en /proc/swaps.\n  Posibles causas:\n  • VPS basada en OpenVZ (no permite swap del huésped — pide al proveedor o cambia a KVM)\n  • Kernel sin soporte de swap (raro)\n  Diagnostica con: sudo swapon $SWAP_FILE 2>&1"
  fi

  if ! grep -qF "$SWAP_FILE" /etc/fstab; then
    echo "$SWAP_FILE none swap sw 0 0" | sudo tee -a /etc/fstab > /dev/null
    success "Persistencia agregada a /etc/fstab"
  fi

  ensure_swap_persistence_alpine
  success "Swap activo: $(awk '/^SwapTotal:/ {print int($2 / 1024) " MB"}' /proc/meminfo)"
}

# ── 2b. Persistencia de swap en Alpine/OpenRC ─────────────────────
# systemd procesa /etc/fstab automáticamente al boot, pero OpenRC NO.
# En Alpine el servicio 'swap' debe estar en el runlevel 'boot' para
# que `swapon -a` se ejecute al iniciar. Sin esto, después de un reboot
# el swap aparece en fstab pero queda inactivo.
ensure_swap_persistence_alpine() {
  # Sólo aplica en distros con OpenRC (Alpine, Gentoo, Devuan opcional)
  command -v rc-update >/dev/null 2>&1 || return 0

  if [ -f /etc/init.d/swap ]; then
    if sudo rc-update show boot 2>/dev/null | grep -qE '^[[:space:]]*swap\b'; then
      success "Servicio OpenRC 'swap' ya está en runlevel boot"
    else
      sudo rc-update add swap boot 2>/dev/null \
        && success "Servicio OpenRC 'swap' agregado al runlevel boot" \
        || warn "No se pudo agregar 'swap' a boot — instalando fallback /etc/local.d/"
    fi
  else
    # Fallback: script en /etc/local.d/ ejecutado por el servicio 'local'.
    # Funciona en Alpines minimalistas que no traen /etc/init.d/swap.
    warn "/etc/init.d/swap no existe — usando fallback /etc/local.d/swap.start"
    sudo mkdir -p /etc/local.d
    sudo tee /etc/local.d/swap.start > /dev/null <<'LOCAL_EOF'
#!/bin/sh
# Activa todas las entradas swap de /etc/fstab al boot.
# Generado por optimizar-1gb.sh — PDNMX SistemaDeclaraciones.
swapon -a 2>/dev/null
LOCAL_EOF
    sudo chmod +x /etc/local.d/swap.start
    sudo rc-update add local default 2>/dev/null || true
    success "Swap se activará al boot via /etc/local.d/swap.start"
  fi
}

# ── 3. swappiness ────────────────────────────────────────────────
tune_swappiness() {
  header "Sintonizar vm.swappiness=${SWAPPINESS}"
  sudo sysctl -w "vm.swappiness=${SWAPPINESS}" > /dev/null

  local sysctl_file=/etc/sysctl.d/99-pdnmx-1gb.conf
  echo "vm.swappiness=${SWAPPINESS}" | sudo tee "$sysctl_file" > /dev/null
  success "swappiness=${SWAPPINESS} aplicado y persistido en ${sysctl_file}"
}

# ── 4. Cap del cache de MongoDB ──────────────────────────────────
patch_mongo_conf() {
  header "Limitar cache de MongoDB a ${MONGO_CACHE_GB} GB"
  local conf="$BASE_DIR/database-test/mongodb/config/mongod.conf"

  if [ ! -f "$conf" ]; then
    warn "No existe $conf — omitir (ejecuta setup.sh/asistente.sh primero)"
    return
  fi

  if grep -qE '^[[:space:]]*cacheSizeGB:[[:space:]]*'"${MONGO_CACHE_GB}"'\b' "$conf"; then
    success "mongod.conf ya tiene cacheSizeGB: ${MONGO_CACHE_GB}"
    return
  fi

  sudo cp "$conf" "${conf}.bak.$(date +%s)"

  if grep -qE '^[[:space:]]*cacheSizeGB:' "$conf"; then
    sudo sed -i -E "s|^([[:space:]]*)cacheSizeGB:.*|\1cacheSizeGB: ${MONGO_CACHE_GB}|" "$conf"
    success "cacheSizeGB actualizado en mongod.conf"
    return
  fi

  # No hay cacheSizeGB en absoluto: añadir bloque storage.wiredTiger al final.
  # Si ya existe storage:, lo dejamos intacto y agregamos un segundo bloque
  # storage: redundante; YAML acepta múltiples claves de mismo nivel solo si
  # están bajo distinto padre, así que validamos primero.
  if grep -qE '^[[:space:]]*storage:[[:space:]]*$' "$conf"; then
    # Insertar wiredTiger justo debajo de storage:
    sudo awk -v cache="${MONGO_CACHE_GB}" '
      BEGIN { inserted=0 }
      /^[[:space:]]*storage:[[:space:]]*$/ && !inserted {
        print
        print "  wiredTiger:"
        print "    engineConfig:"
        print "      cacheSizeGB: " cache
        inserted=1
        next
      }
      { print }
    ' "$conf" | sudo tee "${conf}.tmp" > /dev/null
    sudo mv "${conf}.tmp" "$conf"
  else
    # No hay sección storage — añadir todo al final
    sudo tee -a "$conf" > /dev/null <<EOF

storage:
  wiredTiger:
    engineConfig:
      cacheSizeGB: ${MONGO_CACHE_GB}
EOF
  fi

  success "mongod.conf parcheado (backup en ${conf}.bak.*)"
}

# ── 5. Detectar archivos compose (raíz + instancias hermanas) ────
detect_compose_files() {
  local parent
  parent="$(dirname "$BASE_DIR")"
  # Master compose de setup.sh, shared compose, e instancias
  for f in "$BASE_DIR/docker-compose.shared.yml" "$BASE_DIR/docker-compose.yml"; do
    [ -f "$f" ] && echo "$f"
  done
  find "$parent" -maxdepth 2 -mindepth 2 -name "docker-compose.yml" \
    -not -path "$BASE_DIR/*" -print 2>/dev/null
}

detect_instance_dirs() {
  # Directorios con SistemaDeclaraciones_backend/.env (instancias completas)
  # Portable: GNU find soporta -printf, busybox find no. Usamos dirname doble
  # — primer dirname quita ".env", segundo quita "SistemaDeclaraciones_backend".
  local parent
  parent="$(dirname "$BASE_DIR")"
  find "$parent" -maxdepth 3 -name ".env" -path "*/SistemaDeclaraciones_backend/.env" 2>/dev/null \
    | while IFS= read -r f; do dirname "$(dirname "$f")"; done \
    | sort -u
}

# ── 6. Parchear backend .env (NODE_OPTIONS) ──────────────────────
patch_instance_env() {
  local dir="$1"
  local env_file="$dir/SistemaDeclaraciones_backend/.env"
  [ -f "$env_file" ] || return 0

  if grep -qE '^NODE_OPTIONS=' "$env_file"; then
    sed -i -E "s|^NODE_OPTIONS=.*|NODE_OPTIONS=--max-old-space-size=${APP_NODE_HEAP_MB}|" "$env_file"
  else
    cat >> "$env_file" <<EOF

# ── Memoria (1 GB VPS) ─────────────────────────────────────────
NODE_OPTIONS=--max-old-space-size=${APP_NODE_HEAP_MB}
EOF
  fi
  success "  $env_file → NODE_OPTIONS heap ${APP_NODE_HEAP_MB} MB"
}

# ── 7. Parchear mem_limit por servicio en cualquier compose ──────
patch_compose() {
  local f="$1"
  [ -f "$f" ] || return 0

  info "Parcheando $f"

  python3 - "$f" \
      "$MONGO_MEM_LIMIT" "$REPORTS_MEM_LIMIT" "$APP_MEM_LIMIT" "$WEBAPP_MEM_LIMIT" <<'PY'
import sys, re

path, mongo_lim, reports_lim, app_lim, webapp_lim = sys.argv[1:6]
with open(path) as fh:
    lines = fh.readlines()

LIMITS = {
    'mongo':   mongo_lim,
    'reports': reports_lim,
    'app':     app_lim,
    'webapp':  webapp_lim,
}

# Regex: cabecera de servicio en la 1ª columna de indentación (2 espacios).
service_re = re.compile(r'^(  )([A-Za-z0-9_-]+):\s*$')
mem_re     = re.compile(r'^(\s+)mem_limit:\s*\S+\s*$')
container_re = re.compile(r'^(\s+)container_name:\s*\S+\s*$')

# Encontrar bloques de servicio
blocks = []   # (start_idx, end_idx_exclusive, service_name)
current = None
for i, ln in enumerate(lines):
    m = service_re.match(ln)
    if m:
        if current is not None:
            blocks.append((current[0], i, current[1]))
        current = (i, m.group(2))
    elif ln.startswith('networks:') or ln.startswith('volumes:'):
        if current is not None:
            blocks.append((current[0], i, current[1]))
            current = None
if current is not None:
    blocks.append((current[0], len(lines), current[1]))

# Procesar de atrás hacia adelante para no romper índices
for start, end, name in reversed(blocks):
    if name not in LIMITS:
        continue
    target = LIMITS[name]
    # ¿Existe ya mem_limit en este bloque?
    found = False
    for j in range(start, end):
        m = mem_re.match(lines[j])
        if m:
            indent = m.group(1)
            lines[j] = f'{indent}mem_limit: {target}\n'
            found = True
            break
    if not found:
        # Insertar tras container_name si existe; si no, tras la cabecera
        insert_at = start + 1
        for j in range(start, end):
            if container_re.match(lines[j]):
                insert_at = j + 1
                break
        # Detectar indentación del bloque (4 espacios estándar Compose)
        indent = '    '
        lines.insert(insert_at, f'{indent}mem_limit: {target}\n')

with open(path, 'w') as fh:
    fh.writelines(lines)
PY
  success "  $f → mem_limits actualizados"
}

patch_all_composes() {
  header "Parchear mem_limit en docker-compose.*"
  for f in $(detect_compose_files); do
    patch_compose "$f"
  done
}

patch_all_envs() {
  header "Parchear NODE_OPTIONS en backends"
  for d in $(detect_instance_dirs); do
    info "Instancia: $d"
    patch_instance_env "$d"
  done
}

# ── 8. Reiniciar servicios para aplicar cambios ──────────────────
restart_services() {
  header "Reiniciar servicios para aplicar cambios"

  if ! command -v docker &>/dev/null; then
    warn "Docker no instalado — saltar reinicio"
    return
  fi

  # docker-compose.shared.yml (modo multi-instancia)
  if [ -f "$BASE_DIR/docker-compose.shared.yml" ] \
     && sudo docker ps --format '{{.Names}}' 2>/dev/null | grep -q '^pdnmx-mongo$'; then
    info "Recreando pdnmx-mongo (shared)..."
    (cd "$BASE_DIR" && sudo docker compose -f docker-compose.shared.yml up -d --force-recreate)
  fi

  # Cada directorio con docker-compose.yml (incluye PND/ master + hermanas)
  # Portable: busybox find no soporta -printf, así que extraemos el dir con dirname.
  local parent
  parent="$(dirname "$BASE_DIR")"
  local seen=""
  local instance_dirs
  instance_dirs="$(find "$parent" -maxdepth 2 -mindepth 2 -name "docker-compose.yml" 2>/dev/null \
    | while IFS= read -r f; do dirname "$f"; done)"
  for d in "$BASE_DIR" $instance_dirs; do
    [ -f "$d/docker-compose.yml" ] || continue
    case " $seen " in *" $d "*) continue ;; esac
    seen="$seen $d"

    local proj
    proj="$(grep -E '^COMPOSE_PROJECT_NAME=' "$d/.env" 2>/dev/null | cut -d= -f2 || true)"
    proj="${proj:-$(basename "$d")}"

    if sudo docker compose -p "$proj" ps --quiet 2>/dev/null | grep -q .; then
      info "Recreando contenedores del proyecto '${proj}'..."
      (cd "$d" && sudo docker compose -p "$proj" up -d --force-recreate)
    else
      info "Proyecto '${proj}' no está arriba — omitir"
    fi
  done
}

# ── 9. Resumen ───────────────────────────────────────────────────
print_summary() {
  echo ""
  echo -e "${BOLD}${GREEN}═══════════════════════════════════════════════════${NC}"
  echo -e "${BOLD}${GREEN}   Optimización 1 GB aplicada${NC}"
  echo -e "${BOLD}${GREEN}═══════════════════════════════════════════════════${NC}"
  echo ""
  echo "  Verificaciones útiles:"
  echo ""
  echo "    free -m                                  # ver swap activo"
  echo "    cat /proc/sys/vm/swappiness              # ver swappiness"
  echo "    sudo docker stats --no-stream            # uso real por contenedor"
  echo "    sudo docker exec pdnmx-mongo mongosh --quiet -u \$DB_ROOT_USER \\"
  echo "         -p \$DB_ROOT_PASSWORD --authenticationDatabase admin --eval \\"
  echo "         'db.serverStatus().wiredTiger.cache[\"maximum bytes configured\"]'"
  echo ""
  echo "  Si una instancia muere por OOM tras el cambio, sube su mem_limit"
  echo "  o aumenta APP_NODE_HEAP_MB y vuelve a correr este script."
  echo ""
}

# ── Main ─────────────────────────────────────────────────────────
diagnose
ensure_swap
tune_swappiness
patch_mongo_conf
patch_all_composes
patch_all_envs
restart_services
print_summary
