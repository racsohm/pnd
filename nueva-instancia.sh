#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# nueva-instancia.sh — Crea una nueva instancia de SistemaDeclaraciones
#
# Uso: bash nueva-instancia.sh <nombre> <backend_port> <frontend_port>
#
# Ejemplo:
#   bash nueva-instancia.sh inst2 3010 8081
#   bash nueva-instancia.sh inst3 3020 8082
#
# Requisito: la infraestructura compartida (MongoDB) ya debe estar
# corriendo: sudo docker compose -f docker-compose.shared.yml up -d
# ═══════════════════════════════════════════════════════════════════
set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'

info()    { echo -e "${BLUE}[INFO]${NC}   $*"; }
success() { echo -e "${GREEN}[OK]${NC}     $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}   $*"; }
err()     { echo -e "${RED}[ERROR]${NC}  $*" >&2; exit 1; }
header()  { echo -e "\n${BOLD}${BLUE}──────────────────────────────────────${NC}"; \
            echo -e "${BOLD}${BLUE}  $*${NC}"; \
            echo -e "${BOLD}${BLUE}──────────────────────────────────────${NC}"; }

# ── Validar argumentos ────────────────────────────────────────────
[ $# -lt 1 ] && err "Uso: bash nueva-instancia.sh <nombre> [backend_port] [frontend_port]\n  Ejemplo: bash nueva-instancia.sh inst2\n           bash nueva-instancia.sh inst2 3010 8081"

INSTANCE_NAME="$1"
MONGO_DB="${INSTANCE_NAME}_db"

BASE_DIR="$(cd "$(dirname "$0")" && pwd)"
INSTANCE_DIR="$(dirname "$BASE_DIR")/${INSTANCE_NAME}"

# ── Detección automática de puertos ──────────────────────────────
_used_ports() {
  find "$(dirname "$BASE_DIR")" -maxdepth 2 -name ".env" 2>/dev/null \
    | xargs grep -h -E '^(BACKEND_PORT|FRONTEND_PORT)=' 2>/dev/null \
    | cut -d= -f2
  ss -tlnp 2>/dev/null | awk 'NR>1{print $4}' | grep -oE '[0-9]+$'
  sudo docker ps --format '{{.Ports}}' 2>/dev/null \
    | grep -oE '0\.0\.0\.0:[0-9]+->' | grep -oE ':[0-9]+' | tr -d ':'
}

_port_free() { ! _used_ports | sort -un | grep -qx "$1"; }

next_port() {
  local port=$1 step=$2
  until _port_free "$port"; do
    port=$((port + step))
    [[ $port -gt 49151 ]] && { echo "Sin puertos disponibles" >&2; exit 1; }
  done
  echo "$port"
}

# Usar puertos del argumento o calcular automáticamente
if [ -n "${2:-}" ]; then
  BACKEND_PORT="$2"
else
  BACKEND_PORT=$(next_port 3000 10)
  info "Puerto backend auto-asignado: $BACKEND_PORT"
fi

if [ -n "${3:-}" ]; then
  FRONTEND_PORT="$3"
else
  FRONTEND_PORT=$(next_port 8080 1)
  info "Puerto frontend auto-asignado: $FRONTEND_PORT"
fi

echo -e "${BOLD}${BLUE}"
echo "  ╔══════════════════════════════════════════════╗"
echo "  ║   SistemaDeclaraciones PDNMX — Nueva inst.  ║"
echo "  ╚══════════════════════════════════════════════╝"
echo -e "${NC}"
echo "  Instancia:  $INSTANCE_NAME"
echo "  Directorio: $INSTANCE_DIR"
echo "  Backend:    http://localhost:$BACKEND_PORT"
echo "  Frontend:   http://localhost:$FRONTEND_PORT"
echo "  Base datos: $MONGO_DB"
echo ""

# ── Leer credenciales compartidas desde el .env base ─────────────
[ -f "$BASE_DIR/.env" ] || err "$BASE_DIR/.env no existe. Ejecuta primero asistente.sh o setup.sh."
source "$BASE_DIR/.env"
[ -z "${DB_ROOT_PASSWORD:-}" ] && err "$BASE_DIR/.env no contiene DB_ROOT_PASSWORD."

# Si pdnmx-mongo ya corre, las credenciales de $BASE_DIR/.env DEBEN coincidir
# con las que tiene el contenedor (se fijan solo en el primer arranque).
if sudo docker ps --format '{{.Names}}' 2>/dev/null | grep -q '^pdnmx-mongo$'; then
  if ! sudo docker exec pdnmx-mongo mongosh --quiet \
        -u "$DB_ROOT_USER" -p "$DB_ROOT_PASSWORD" --authenticationDatabase admin \
        --eval "db.adminCommand('ping').ok" 2>/dev/null | grep -q '^1$'; then
    err "Las credenciales de $BASE_DIR/.env NO coinciden con pdnmx-mongo.\n  La instancia no podrá conectar a la base de datos."
  fi
  info "Credenciales validadas contra pdnmx-mongo"
fi

# ── Crear directorio ──────────────────────────────────────────────
header "Creando directorio de instancia"
mkdir -p "$INSTANCE_DIR"
cd "$INSTANCE_DIR"
success "$INSTANCE_DIR"

# ── Clonar repositorios ───────────────────────────────────────────
header "Clonando repositorios"
REPOS=(
  "SistemaDeclaraciones_backend    https://github.com/PDNMX/SistemaDeclaraciones_backend.git"
  "SistemaDeclaraciones_reportes   https://github.com/PDNMX/SistemaDeclaraciones_reportes.git"
  "SistemaDeclaraciones_frontend   https://github.com/PDNMX/SistemaDeclaraciones_frontend.git"
)
for entry in "${REPOS[@]}"; do
  dir=$(echo "$entry" | awk '{print $1}')
  url=$(echo "$entry" | awk '{print $2}')
  if [ -d "$dir/.git" ]; then
    info "Actualizando $dir..."
    git -C "$dir" pull --quiet --ff-only 2>/dev/null || warn "No se pudo actualizar $dir"
  else
    info "Clonando $dir..."
    git clone --quiet "$url" "$dir"
  fi
  success "$dir"
done

# ── Generar credenciales únicas para esta instancia ───────────────
header "Generando credenciales"
JWT_SECRET=$(openssl rand -hex 32)
REFRESH_JWT_SECRET=$(openssl rand -hex 32)
INST_REPORTS_API_KEY=$(openssl rand -hex 16)

# ── .env raíz de la instancia ─────────────────────────────────────
cat > .env <<EOF
# ── Identidad de la instancia ──────────────────────────────────
COMPOSE_PROJECT_NAME=${INSTANCE_NAME}

# ── Puertos expuestos ─────────────────────────────────────────
BACKEND_PORT=${BACKEND_PORT}
FRONTEND_PORT=${FRONTEND_PORT}

# ── Credenciales MongoDB (compartidas) ───────────────────────
DB_ROOT_USER=${DB_ROOT_USER}
DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}

# ── Reportes ──────────────────────────────────────────────────
REPORTS_API_KEY=${INST_REPORTS_API_KEY}

NODE_ENV=production
EOF
success ".env raíz creado"

# ── Backend .env ──────────────────────────────────────────────────
cat > SistemaDeclaraciones_backend/.env <<EOF
NODE_ENV=production
PORT=3000

FE_RESET_PASSWORD_URL=http://localhost:${FRONTEND_PORT}

JWT_NO_VERIFY=false
JWT_SECRET=${JWT_SECRET}
JWT_EXPIRATION=8h

REFRESH_JWT_SECRET=${REFRESH_JWT_SECRET}
REFRESH_JWT_EXPIRATION=2d

USE_SMTP=true
SENDGRID_API_KEY=
SENDGRID_MAIL_SENDER=
SMTP_HOST=
SMTP_PORT=587
SMTP_SECURE=false
SMTP_USER=
SMTP_PASSWORD=
SMTP_FROM_EMAIL=

REPORTS_URL=http://${INSTANCE_NAME}-reports:3001
REPORTS_API_KEY=${INST_REPORTS_API_KEY}

MONGO_USERNAME=${DB_ROOT_USER}
MONGO_PASSWORD=${DB_ROOT_PASSWORD}
MONGO_HOSTNAME=pdnmx-mongo
MONGO_PORT=27017
MONGO_DB=${MONGO_DB}
EOF
success "SistemaDeclaraciones_backend/.env creado"

# ── Dockerfile.fixed para el backend ──────────────────────────────
header "Creando Dockerfile.fixed del backend"
cat > SistemaDeclaraciones_backend/Dockerfile.fixed <<'DOCKERFILE'
FROM node:18-alpine

ADD . /backend
WORKDIR /backend

ARG NODE_ENV

RUN test -f src/data/instituciones.json || cp src/data/instituciones.json.example src/data/instituciones.json

RUN echo '{ \
  "extends": "./tsconfig.json", \
  "compilerOptions": { \
    "strict": false, \
    "noImplicitAny": false, \
    "noUnusedLocals": false, \
    "noImplicitReturns": false \
  } \
}' > tsconfig.build.json

RUN npm install --include=dev \
    && npx rimraf ./build \
    && (npx tsc -p tsconfig.build.json || true) \
    && npx copyfiles -u 1 "src/**/*.graphql" build/ \
    && npx copyfiles -a -u 1 "src/**/*.json" build/ \
    && npm prune --production

CMD ["node", "build/server.js"]
DOCKERFILE
success "Dockerfile.fixed creado"

# ── Parchear serverUrl del frontend ───────────────────────────────
header "Configurando URL del backend en el frontend"
sed -i \
  "s|serverUrl: '.*'|serverUrl: 'http://localhost:${BACKEND_PORT}'|; \
   s|pageUrl: '.*'|pageUrl: 'http://localhost:${FRONTEND_PORT}/'|" \
  SistemaDeclaraciones_frontend/src/environments/environment.prod.ts
success "serverUrl → http://localhost:${BACKEND_PORT}"

# ── docker-compose.yml de la instancia ────────────────────────────
header "Creando docker-compose.yml"
cat > docker-compose.yml <<'COMPOSE'
services:

  reports:
    build:
      context: ./SistemaDeclaraciones_reportes
    container_name: ${COMPOSE_PROJECT_NAME}-reports
    restart: unless-stopped
    mem_limit: 150m
    environment:
      PORT: "3001"
      API_KEY: ${REPORTS_API_KEY}
    networks:
      - pdnmx_network

  app:
    build:
      context: ./SistemaDeclaraciones_backend
      dockerfile: Dockerfile.fixed
      args:
        - NODE_ENV=production
    container_name: ${COMPOSE_PROJECT_NAME}-app
    restart: unless-stopped
    mem_limit: 400m
    depends_on:
      - reports
    env_file:
      - ./SistemaDeclaraciones_backend/.env
    ports:
      - "${BACKEND_PORT:-3000}:3000"
    networks:
      - pdnmx_network

  webapp:
    build:
      context: ./SistemaDeclaraciones_frontend
    container_name: ${COMPOSE_PROJECT_NAME}-webapp
    restart: always
    mem_limit: 64m
    ports:
      - "${FRONTEND_PORT:-8080}:80"
    networks:
      - pdnmx_network
    depends_on:
      - app

networks:
  pdnmx_network:
    external: true
COMPOSE
success "docker-compose.yml creado"

# ── Iniciar servicios ─────────────────────────────────────────────
header "Iniciando servicios"
warn "La primera construcción puede tardar varios minutos..."
sudo docker compose up --build -d

echo ""
echo -e "${BOLD}${GREEN}═══════════════════════════════════════════════════${NC}"
echo -e "${BOLD}${GREEN}   Instancia '${INSTANCE_NAME}' lista${NC}"
echo -e "${BOLD}${GREEN}═══════════════════════════════════════════════════${NC}"
echo ""
echo -e "   ${BOLD}Frontend:${NC}  http://localhost:${FRONTEND_PORT}"
echo -e "   ${BOLD}Backend:${NC}   http://localhost:${BACKEND_PORT}/graphql"
echo -e "   ${BOLD}Base datos:${NC} ${MONGO_DB}"
echo ""
echo -e "   sudo docker compose -p ${INSTANCE_NAME} ps"
echo -e "   sudo docker compose -p ${INSTANCE_NAME} logs -f app"
echo ""
