#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# SistemaDeclaraciones PDNMX — Script de instalación
# Basado en el Manual de Configuración oficial (V1.0.0, jun 2024)
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

INSTALL_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$INSTALL_DIR"

echo -e "${BOLD}${BLUE}"
echo "  ╔══════════════════════════════════════════════╗"
echo "  ║   SistemaDeclaraciones PDNMX — Instalador   ║"
echo "  ╚══════════════════════════════════════════════╝"
echo -e "${NC}"
echo "  Directorio de instalación: $INSTALL_DIR"
echo ""

# ───────────────────────────────────────────────────────────────────
# 1. Instalar Docker
# ───────────────────────────────────────────────────────────────────
install_docker() {
  header "Paso 1 — Instalar Docker"

  if command -v docker &>/dev/null; then
    success "Docker ya instalado: $(docker --version 2>&1 | head -1)"
    return
  fi

  info "Instalando Docker via script oficial..."
  curl -fsSL https://get.docker.com | sudo sh
  sudo usermod -aG docker "$USER"
  success "Docker instalado. El usuario '$USER' fue agregado al grupo 'docker'."
  warn "Los siguientes comandos de Docker se ejecutan con 'sudo' durante esta"
  warn "sesión. Para evitarlo en el futuro, cierra sesión y vuelve a entrar."
}

# ───────────────────────────────────────────────────────────────────
# 2. Clonar los 4 repositorios
# ───────────────────────────────────────────────────────────────────
clone_repos() {
  header "Paso 2 — Clonar repositorios"

  # Formato: "directorio url"
  REPOS=(
    "database-test                   https://github.com/PDNMX/database-test.git"
    "SistemaDeclaraciones_backend    https://github.com/PDNMX/SistemaDeclaraciones_backend.git"
    "SistemaDeclaraciones_reportes   https://github.com/PDNMX/SistemaDeclaraciones_reportes.git"
    "SistemaDeclaraciones_frontend   https://github.com/PDNMX/SistemaDeclaraciones_frontend.git"
  )

  for entry in "${REPOS[@]}"; do
    dir=$(echo "$entry" | awk '{print $1}')
    url=$(echo "$entry" | awk '{print $2}')

    if [ -d "$dir/.git" ]; then
      info "Actualizando $dir..."
      git -C "$dir" pull --quiet --ff-only 2>/dev/null || warn "No se pudo actualizar $dir (puede tener cambios locales)"
    else
      info "Clonando $dir..."
      git clone --quiet "$url" "$dir"
    fi
    success "$dir"
  done
}

# ───────────────────────────────────────────────────────────────────
# 3. Preparar directorios de datos de MongoDB
#    (equivalente a ejecutar step-01.sh del repo database-test)
# ───────────────────────────────────────────────────────────────────
prepare_mongo_dirs() {
  header "Paso 3 — Preparar directorios de MongoDB"

  pushd database-test > /dev/null
  bash step-01.sh
  popd > /dev/null

  success "Directorios creados:"
  success "  database-test/mongodb/data/volume/"
  success "  database-test/mongodb/data/log/"
}

# ───────────────────────────────────────────────────────────────────
# 4. Generar credenciales y crear archivos .env
# ───────────────────────────────────────────────────────────────────
create_env_files() {
  header "Paso 4 — Generar credenciales y configurar .env"

  if [ -f ".env" ] && [ -f "SistemaDeclaraciones_backend/.env" ]; then
    warn ".env y SistemaDeclaraciones_backend/.env ya existen — se omiten."
    warn "Borra los archivos .env si quieres regenerar las credenciales."
    return
  fi

  # Generar secretos únicos
  JWT_SECRET=$(openssl rand -hex 32)
  REFRESH_JWT_SECRET=$(openssl rand -hex 32)
  REPORTS_API_KEY=$(openssl rand -hex 16)

  # MongoDB: reutilizar password si ya existe en .env, o si Mongo ya corre.
  # Mongo solo inicializa el usuario root en el PRIMER arranque.
  if [ -f ".env" ] && grep -q '^DB_ROOT_PASSWORD=' .env; then
    DB_ROOT_USER=$(grep '^DB_ROOT_USER=' .env | head -1 | cut -d= -f2)
    DB_ROOT_PASSWORD=$(grep '^DB_ROOT_PASSWORD=' .env | head -1 | cut -d= -f2)
    info "Reutilizando credenciales MongoDB de .env existente"
  elif sudo docker ps --format '{{.Names}}' 2>/dev/null | grep -q '^pdnmx-mongo$'; then
    err "pdnmx-mongo corre pero .env no tiene DB_ROOT_PASSWORD.\n  Restaura el .env compartido o ejecuta limpiar.sh para resetear."
  else
    DB_ROOT_USER="pdnmx_admin"
    DB_ROOT_PASSWORD=$(openssl rand -hex 16)
  fi

  # ── .env raíz (leído por docker compose automáticamente) ─────────
  cat > .env <<EOF
# ── Identidad de la instancia ──────────────────────────────────
COMPOSE_PROJECT_NAME=pnd

# ── Puertos expuestos (cambiar por instancia) ──────────────────
BACKEND_PORT=3000
FRONTEND_PORT=8080

# ── Base de datos (MongoDB) ─────────────────────────────────────
DB_ROOT_USER=${DB_ROOT_USER}
DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
DB_PORT=27017

# ── Módulo de reportes ─────────────────────────────────────────
REPORTS_API_KEY=${REPORTS_API_KEY}

# ── Entorno ────────────────────────────────────────────────────
NODE_ENV=production
EOF
  success ".env raíz generado"

  # ── SistemaDeclaraciones_backend/.env ────────────────────────────
  # MONGO_HOSTNAME=pdnmx-mongo → container_name único en pdnmx_network
  #   (NO usar "mongo" — el alias de servicio causa round-robin DNS entre instancias)
  # REPORTS_URL=http://pnd-reports:3001 → container_name único, mismo motivo
  # La cadena de conexión del backend ya incluye ?authSource=admin
  cat > SistemaDeclaraciones_backend/.env <<EOF
# ── Servidor ────────────────────────────────────────────────────
NODE_ENV=production
PORT=3000

# URL del frontend (usada en correos de reset de contraseña)
FE_RESET_PASSWORD_URL=http://localhost:8080

# ── JWT ─────────────────────────────────────────────────────────
# NUNCA establecer JWT_NO_VERIFY=true en producción
JWT_NO_VERIFY=false
JWT_SECRET=${JWT_SECRET}
JWT_EXPIRATION=8h

REFRESH_JWT_SECRET=${REFRESH_JWT_SECRET}
REFRESH_JWT_EXPIRATION=2d

# ── Correo electrónico ──────────────────────────────────────────
# USE_SMTP=true  → SMTP  |  USE_SMTP=false → SendGrid
# Configura SMTP_* o SENDGRID_* para habilitar envío de correos
# (Necesario para reset de contraseña. El sistema funciona sin esto.)
USE_SMTP=true
SENDGRID_API_KEY=
SENDGRID_MAIL_SENDER=
SMTP_HOST=
SMTP_PORT=587
SMTP_SECURE=false
SMTP_USER=
SMTP_PASSWORD=
SMTP_FROM_EMAIL=

# ── Módulo de reportes (INTERNO — no exponer puerto 3001) ────────
REPORTS_URL=http://pnd-reports:3001
REPORTS_API_KEY=${REPORTS_API_KEY}

# ── MongoDB ─────────────────────────────────────────────────────
MONGO_USERNAME=${DB_ROOT_USER}
MONGO_PASSWORD=${DB_ROOT_PASSWORD}
MONGO_HOSTNAME=pdnmx-mongo
MONGO_PORT=27017
MONGO_DB=newmodels
EOF
  success "SistemaDeclaraciones_backend/.env generado"

  info "Credenciales generadas:"
  info "  DB User:     ${DB_ROOT_USER}"
  info "  DB Password: ${DB_ROOT_PASSWORD}"
  info "  (Guarda estos datos en un lugar seguro)"
}

# ───────────────────────────────────────────────────────────────────
# 5. Crear Dockerfile corregido para el backend
#    node:14-alpine ya no incluye yarn; usamos node:18-alpine con npm
# ───────────────────────────────────────────────────────────────────
create_backend_dockerfile() {
  header "Paso 5 — Corregir Dockerfile del backend"

  cat > SistemaDeclaraciones_backend/Dockerfile.fixed <<'DOCKERFILE'
FROM node:18-alpine

ADD . /backend
WORKDIR /backend

ARG NODE_ENV

# 1. instituciones.json es requerido por el compilador; preservar custom si existe
RUN test -f src/data/instituciones.json || cp src/data/instituciones.json.example src/data/instituciones.json

# 2. tsconfig relajado para resolver conflicto de @types/express entre
#    apollo-server-express (v2 legacy) y el @types/express instalado globalmente
RUN echo '{ \
  "extends": "./tsconfig.json", \
  "compilerOptions": { \
    "strict": false, \
    "noImplicitAny": false, \
    "noUnusedLocals": false, \
    "noImplicitReturns": false \
  } \
}' > tsconfig.build.json

# 3. Instalar (incluyendo devDeps para tsc/rimraf/copyfiles), compilar, podar
RUN npm install --include=dev \
    && npx rimraf ./build \
    && (npx tsc -p tsconfig.build.json || true) \
    && npx copyfiles -u 1 "src/**/*.graphql" build/ \
    && npx copyfiles -a -u 1 "src/**/*.json" build/ \
    && npm prune --production

CMD ["node", "build/server.js"]
DOCKERFILE

  success "SistemaDeclaraciones_backend/Dockerfile.fixed creado (node:18 + npm)"
}

# ───────────────────────────────────────────────────────────────────
# 6. Configurar URL del backend en el frontend Angular
#    environment.prod.ts se compila dentro de la imagen Docker
# ───────────────────────────────────────────────────────────────────
patch_frontend_env() {
  header "Paso 6 — Configurar URL del backend en el frontend"

  local env_file="SistemaDeclaraciones_frontend/src/environments/environment.prod.ts"

  # serverUrl debe ser accesible desde el NAVEGADOR del usuario → localhost:3000
  sed -i \
    "s|serverUrl: '.*'|serverUrl: 'http://localhost:3000'|; \
     s|pageUrl: '.*'|pageUrl: 'http://localhost:8080/'|" \
    "$env_file"

  success "environment.prod.ts actualizado:"
  success "  serverUrl → http://localhost:3000"
  success "  pageUrl   → http://localhost:8080/"
}

# ───────────────────────────────────────────────────────────────────
# 7. Crear docker-compose.yml maestro en PND/
#    Un solo archivo que orquesta todos los servicios en una red común
# ───────────────────────────────────────────────────────────────────
create_master_compose() {
  header "Paso 7 — Crear docker-compose.yml maestro"

  if [ -f "docker-compose.yml" ]; then
    warn "docker-compose.yml ya existe — se sobreescribe."
  fi

  cat > docker-compose.yml <<'COMPOSE'
# ═══════════════════════════════════════════════════════════════════
# SistemaDeclaraciones PDNMX — Orquestación completa (un solo nodo)
#
# Servicios internos (NO exponer a Internet):
#   mongo   → puerto 27017
#   reports → puerto 3001
#
# Servicios accesibles:
#   app     → http://localhost:3000   (API GraphQL)
#   webapp  → http://localhost:8080   (Frontend)
# ═══════════════════════════════════════════════════════════════════
services:

  # ── Base de datos MongoDB ──────────────────────────────────────
  mongo:
    image: mongodb/mongodb-community-server:latest
    container_name: pdnmx-mongo
    restart: always
    environment:
      MONGODB_INITDB_ROOT_USERNAME: ${DB_ROOT_USER}
      MONGODB_INITDB_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    volumes:
      - ./database-test/mongodb/config/mongod.conf:/etc/mongod.conf
      - ./database-test/mongodb/data/log/:/var/log/mongodb
      - ./database-test/mongodb/data/volume:/var/lib/mongodb
    command: ['-f', '/etc/mongod.conf']
    healthcheck:
      test: ["CMD-SHELL", "mongosh --quiet --eval \"db.adminCommand('ping').ok\" || exit 1"]
      interval: 15s
      timeout: 10s
      retries: 8
      start_period: 60s

  # ── Módulo de Reportes/PDF (INTERNO) ──────────────────────────
  reports:
    build:
      context: ./SistemaDeclaraciones_reportes
    container_name: pdnmx-reports
    restart: unless-stopped
    environment:
      PORT: "3001"
      API_KEY: ${REPORTS_API_KEY}

  # ── Backend — API GraphQL ──────────────────────────────────────
  app:
    build:
      context: ./SistemaDeclaraciones_backend
      dockerfile: Dockerfile.fixed
      args:
        - NODE_ENV=production
    container_name: pdnmx-backend
    restart: unless-stopped
    depends_on:
      mongo:
        condition: service_healthy
      reports:
        condition: service_started
    env_file:
      - ./SistemaDeclaraciones_backend/.env
    ports:
      - "3000:3000"

  # ── Frontend — Angular servido por nginx ───────────────────────
  webapp:
    build:
      context: ./SistemaDeclaraciones_frontend
    container_name: pdnmx-frontend
    restart: always
    ports:
      - "8080:80"
    depends_on:
      - app
COMPOSE

  success "docker-compose.yml maestro creado"
}

# ───────────────────────────────────────────────────────────────────
# 7. Crear infraestructura compartida (red + docker-compose.shared.yml)
# ───────────────────────────────────────────────────────────────────
create_shared_infra() {
  header "Paso 7 — Infraestructura compartida"

  # Crear red Docker compartida si no existe
  if sudo docker network inspect pdnmx_network &>/dev/null; then
    success "Red 'pdnmx_network' ya existe"
  else
    sudo docker network create pdnmx_network
    success "Red 'pdnmx_network' creada"
  fi

  cat > docker-compose.shared.yml <<'SHARED'
# ═══════════════════════════════════════════════════════════════════
# Infraestructura compartida — arranca UNA vez para TODAS las instancias
# Uso: sudo docker compose -f docker-compose.shared.yml up -d
# ═══════════════════════════════════════════════════════════════════
services:

  mongo:
    image: mongodb/mongodb-community-server:latest
    container_name: pdnmx-mongo
    restart: always
    mem_limit: 512m
    environment:
      MONGODB_INITDB_ROOT_USERNAME: ${DB_ROOT_USER}
      MONGODB_INITDB_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    volumes:
      - ./database-test/mongodb/config/mongod.conf:/etc/mongod.conf
      - ./database-test/mongodb/data/log/:/var/log/mongodb
      - ./database-test/mongodb/data/volume:/var/lib/mongodb
    command: ['-f', '/etc/mongod.conf']
    networks:
      - pdnmx_network
    healthcheck:
      test: ["CMD-SHELL", "mongosh --quiet --eval \"db.adminCommand('ping').ok\" || exit 1"]
      interval: 15s
      timeout: 10s
      retries: 8
      start_period: 60s

networks:
  pdnmx_network:
    external: true
SHARED
  success "docker-compose.shared.yml creado"
}

# ───────────────────────────────────────────────────────────────────
# 8. Construir imágenes e iniciar servicios
# ───────────────────────────────────────────────────────────────────
start_services() {
  header "Paso 8 — Construir e iniciar servicios"
  warn "La primera construcción puede tardar 5-15 minutos..."
  echo ""

  info "Iniciando infraestructura compartida (MongoDB)..."
  sudo docker compose -f docker-compose.shared.yml up -d

  info "Iniciando servicios de la instancia..."
  sudo docker compose up --build -d

  echo ""
  info "Esperando que los servicios estén listos..."
  sleep 5
  sudo docker compose ps
}

# ───────────────────────────────────────────────────────────────────
# 8. Resumen final
# ───────────────────────────────────────────────────────────────────
print_summary() {
  echo ""
  echo -e "${BOLD}${GREEN}═══════════════════════════════════════════════════${NC}"
  echo -e "${BOLD}${GREEN}   Sistema de Declaraciones PDNMX — INSTALADO${NC}"
  echo -e "${BOLD}${GREEN}═══════════════════════════════════════════════════${NC}"
  echo ""
  echo -e "   ${BOLD}Frontend:${NC}  http://localhost:8080"
  echo -e "   ${BOLD}Backend:${NC}   http://localhost:3000/graphql"
  echo ""
  echo -e "${BOLD}Comandos útiles:${NC}"
  echo -e "   sudo docker compose ps              # Estado de servicios"
  echo -e "   sudo docker compose logs -f app     # Logs del backend"
  echo -e "   sudo docker compose logs -f webapp  # Logs del frontend"
  echo -e "   sudo docker compose logs -f mongo   # Logs de MongoDB"
  echo -e "   sudo docker compose down            # Detener todo"
  echo -e "   sudo docker compose up -d           # Reiniciar todo"
  echo ""
  echo -e "${BOLD}${YELLOW}Próximo paso — Crear usuario administrador:${NC}"
  echo -e "  1. Regístrate en http://localhost:8080"
  echo -e "  2. Conéctate a MongoDB para asignar el rol ROOT:"
  echo -e "     sudo docker exec -it pdnmx-mongo mongosh -u pdnmx_admin \\"
  echo -e "       --authenticationDatabase admin"
  echo -e "  3. Ejecuta (reemplaza <ObjectID> con el ID de tu usuario):"
  echo -e "     use newmodels"
  echo -e "     var u = db.users.findOne({_id: ObjectId('<ObjectID>')})"
  echo -e "     u.roles = ['ROOT']"
  echo -e "     db.users.updateOne({_id: u._id}, {\$set: u})"
  echo ""
  echo -e "${YELLOW}Nota:${NC} Para hacer el sistema permanente con HTTPS,"
  echo -e "configura un proxy NGINX según el manual de instalación."
  echo ""
}

# ───────────────────────────────────────────────────────────────────
# Ejecución principal
# ───────────────────────────────────────────────────────────────────
main() {
  install_docker
  clone_repos
  prepare_mongo_dirs
  create_env_files
  create_backend_dockerfile
  patch_frontend_env
  create_shared_infra
  create_master_compose
  start_services
  print_summary
}

main
