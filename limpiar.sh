#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# limpiar.sh — Borra TODO y deja solo los scripts de instalación
#
# Elimina:
#   • Contenedores Docker de todas las instancias
#   • Imágenes Docker construidas por el proyecto
#   • Red Docker pdnmx_network
#   • Repositorios clonados (database-test, SistemaDeclaraciones_*)
#   • Archivos generados (.env, docker-compose.yml, etc.)
#   • Datos de MongoDB (database-test/mongodb/data/)
#
# Conserva:
#   • setup.sh
#   • asistente.sh
#   • nueva-instancia.sh
#   • optimizar-1gb.sh
#   • prep-alpine.sh
#   • limpiar.sh  ← este mismo script
#
# NO toca cambios a nivel de host (swap, vm.swappiness, dockerd) hechos
# por optimizar-1gb.sh / prep-alpine.sh — esos persisten para futuras
# reinstalaciones.
# ═══════════════════════════════════════════════════════════════════
set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'

success() { echo -e "${GREEN}[OK]${NC}     $*"; }
warn()    { echo -e "${YELLOW}[AVISO]${NC}  $*"; }
info()    { echo -e "${BLUE}[INFO]${NC}   $*"; }
header()  { echo -e "\n${BOLD}${RED}$*${NC}"; echo -e "${RED}$(printf '─%.0s' {1..50})${NC}"; }

BASE_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$BASE_DIR"

echo -e "${BOLD}${RED}"
echo "  ╔══════════════════════════════════════════════╗"
echo "  ║   SistemaDeclaraciones PDNMX — LIMPIEZA     ║"
echo "  ║   Esta operación NO se puede deshacer        ║"
echo "  ╚══════════════════════════════════════════════╝"
echo -e "${NC}"

# ── Mostrar qué se va a borrar ────────────────────────────────────
echo -e "${BOLD}Se eliminarán:${NC}"
echo -e "  ${RED}✗${NC} Contenedores: pdnmx-mongo, pnd-*, inst*-*"
echo -e "  ${RED}✗${NC} Imágenes Docker del proyecto"
echo -e "  ${RED}✗${NC} Red Docker: pdnmx_network"
echo -e "  ${RED}✗${NC} Repos clonados: database-test/, SistemaDeclaraciones_*/"
echo -e "  ${RED}✗${NC} Archivos generados: .env, docker-compose*.yml"
echo -e "  ${RED}✗${NC} Datos de MongoDB: database-test/mongodb/data/"
echo ""
echo -e "${BOLD}Se conservarán:${NC}"
echo -e "  ${GREEN}✓${NC} setup.sh"
echo -e "  ${GREEN}✓${NC} asistente.sh"
echo -e "  ${GREEN}✓${NC} nueva-instancia.sh"
echo -e "  ${GREEN}✓${NC} optimizar-1gb.sh"
echo -e "  ${GREEN}✓${NC} prep-alpine.sh"
echo -e "  ${GREEN}✓${NC} limpiar.sh"
echo -e "  ${GREEN}✓${NC} swap + vm.swappiness + dockerd (cambios a nivel host)"
echo ""

# ── Confirmación ──────────────────────────────────────────────────
echo -ne "${BOLD}${RED}¿Confirmas la limpieza completa? escribe 'BORRAR' para continuar: ${NC}"
read -r confirm
[ "$confirm" != "BORRAR" ] && { echo "Operación cancelada."; exit 0; }

# ═══════════════════════════════════════════════════════════════════
# 1. Detener y eliminar contenedores
# ═══════════════════════════════════════════════════════════════════
header "Paso 1 — Contenedores Docker"

# Bajar instancia principal si existe
if [ -f "docker-compose.yml" ]; then
  info "Bajando instancia principal..."
  sudo docker compose down --remove-orphans 2>/dev/null || true
fi

# Bajar infraestructura compartida (MongoDB)
if [ -f "docker-compose.shared.yml" ]; then
  info "Bajando infraestructura compartida..."
  sudo docker compose -f docker-compose.shared.yml down --remove-orphans 2>/dev/null || true
fi

# Forzar eliminación de cualquier contenedor pdnmx-* o *-app/*-reports/*-webapp
CONTAINERS=$(sudo docker ps -a --format '{{.Names}}' 2>/dev/null | \
  grep -E '^(pdnmx-|pnd-|inst[0-9]*-)' || true)

if [ -n "$CONTAINERS" ]; then
  info "Eliminando contenedores restantes..."
  echo "$CONTAINERS" | xargs sudo docker rm -f 2>/dev/null || true
  echo "$CONTAINERS" | while read -r c; do success "Eliminado: $c"; done
else
  success "No hay contenedores adicionales"
fi

# ═══════════════════════════════════════════════════════════════════
# 2. Eliminar imágenes Docker del proyecto
# ═══════════════════════════════════════════════════════════════════
header "Paso 2 — Imágenes Docker"

IMAGES=$(sudo docker images --format '{{.Repository}}:{{.Tag}} {{.ID}}' 2>/dev/null | \
  grep -E '^(pnd|inst[0-9]*)-' || true)

if [ -n "$IMAGES" ]; then
  echo "$IMAGES" | awk '{print $2}' | xargs sudo docker rmi -f 2>/dev/null || true
  echo "$IMAGES" | while read -r line; do success "Eliminada: $(echo "$line" | awk '{print $1}')"; done
else
  success "No hay imágenes del proyecto"
fi

# ═══════════════════════════════════════════════════════════════════
# 3. Eliminar red Docker
# ═══════════════════════════════════════════════════════════════════
header "Paso 3 — Red Docker"

if sudo docker network inspect pdnmx_network &>/dev/null 2>&1; then
  sudo docker network rm pdnmx_network 2>/dev/null || \
    warn "No se pudo eliminar pdnmx_network (puede tener contenedores activos)"
  success "Red pdnmx_network eliminada"
else
  success "Red pdnmx_network no existía"
fi

# ═══════════════════════════════════════════════════════════════════
# 4. Eliminar archivos generados y repos clonados
# ═══════════════════════════════════════════════════════════════════
header "Paso 4 — Archivos generados y repositorios"

# Archivos generados en la raíz
GENERATED_FILES=(
  ".env"
  "docker-compose.yml"
  "docker-compose.shared.yml"
)
for f in "${GENERATED_FILES[@]}"; do
  if [ -f "$f" ]; then
    rm -f "$f"
    success "Eliminado: $f"
  fi
done

# Repositorios clonados
CLONED_DIRS=(
  "database-test"
  "SistemaDeclaraciones_backend"
  "SistemaDeclaraciones_frontend"
  "SistemaDeclaraciones_reportes"
)
for d in "${CLONED_DIRS[@]}"; do
  if [ -d "$d" ]; then
    rm -rf "$d"
    success "Eliminado: $d/"
  fi
done

# ═══════════════════════════════════════════════════════════════════
# Resultado final
# ═══════════════════════════════════════════════════════════════════
echo ""
echo -e "${BOLD}${GREEN}══════════════════════════════════════════════════════${NC}"
echo -e "${BOLD}${GREEN}   Limpieza completa${NC}"
echo -e "${BOLD}${GREEN}══════════════════════════════════════════════════════${NC}"
echo ""
echo -e "${BOLD}Archivos que quedaron:${NC}"
ls -1 "$BASE_DIR"
echo ""
echo -e "Para reinstalar desde cero:  ${BOLD}bash setup.sh${NC}"
echo -e "Para configurar con wizard:  ${BOLD}bash asistente.sh${NC}"
echo ""
