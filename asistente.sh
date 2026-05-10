#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# asistente.sh — Configurador interactivo de SistemaDeclaraciones
#
# Guía paso a paso para crear una instancia nueva con los datos
# del ayuntamiento/institución: puertos, BD, titular, etc.
#
# Uso: bash asistente.sh
# ═══════════════════════════════════════════════════════════════════
set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

info()    { echo -e "${BLUE}[INFO]${NC}   $*"; }
success() { echo -e "${GREEN}[OK]${NC}     $*"; }
warn()    { echo -e "${YELLOW}[AVISO]${NC}  $*"; }
header()  { echo -e "\n${BOLD}${CYAN}$*${NC}"; \
            echo -e "${CYAN}────────────────────────────────────────────────────────${NC}"; }

# Pregunta con valor por defecto: ask "Pregunta" "default" → respuesta en $REPLY
ask() {
  local prompt="$1"
  local default="$2"
  if [ -n "$default" ]; then
    echo -ne "${BOLD}  $prompt${NC} [${CYAN}${default}${NC}]: "
  else
    echo -ne "${BOLD}  $prompt${NC}: "
  fi
  read -r input
  REPLY="${input:-$default}"
}

# Pregunta de contraseña (sin echo)
ask_pass() {
  local prompt="$1"
  echo -ne "${BOLD}  $prompt${NC} (Enter para omitir): "
  read -rs input
  echo ""
  REPLY="$input"
}

BASE_DIR="$(cd "$(dirname "$0")" && pwd)"

# ── Detección automática de puertos ──────────────────────────────

# Puertos ocupados: instancias PDNMX existentes + SO + Docker
# Cada pipeline termina en `|| true` porque grep/find pueden salir con 1
# cuando no hay coincidencias (típico en una VPS recién instalada sin .env
# y sin contenedores aún), y bajo `set -euo pipefail` eso mata el script.
# Usamos `find -exec` en lugar de `find | xargs grep` para evitar el caso
# de busybox xargs corriendo grep sin archivos.
_used_ports() {
  # Instancias existentes (leen sus .env)
  find "$(dirname "$BASE_DIR")" -maxdepth 2 -name ".env" \
    -exec grep -h -E '^(BACKEND_PORT|FRONTEND_PORT)=' {} + 2>/dev/null \
    | cut -d= -f2 || true

  # Puertos del sistema operativo
  ss -tlnp 2>/dev/null | awk 'NR>1{print $4}' | grep -oE '[0-9]+$' || true

  # Puertos expuestos en Docker
  sudo docker ps --format '{{.Ports}}' 2>/dev/null \
    | grep -oE '0\.0\.0\.0:[0-9]+->' | grep -oE ':[0-9]+' | tr -d ':' || true
}

_port_free() {
  local port=$1
  ! _used_ports | sort -un | grep -qx "$port"
}

# Devuelve el primer puerto libre a partir de $1, incrementando de $2 en $2
next_port() {
  local port=$1 step=$2
  until _port_free "$port"; do
    port=$((port + step))
    [[ $port -gt 49151 ]] && { echo "Sin puertos disponibles" >&2; exit 1; }
  done
  echo "$port"
}

_show_used_ports() {
  local ports
  ports=$(_used_ports | sort -un | tr '\n' ' ')
  echo -e "  ${YELLOW}Puertos en uso detectados:${NC} ${ports}"
}

clear
echo -e "${BOLD}${BLUE}"
echo "  ╔══════════════════════════════════════════════════════╗"
echo "  ║    SistemaDeclaraciones PDNMX — Asistente de        ║"
echo "  ║    configuración                                     ║"
echo "  ╚══════════════════════════════════════════════════════╝"
echo -e "${NC}"
echo "  Este asistente genera todos los archivos de configuración"
echo "  para una instancia del Sistema de Declaraciones."
echo ""

# ═══════════════════════════════════════════════════════════════════
# SECCIÓN 1 — Datos de la instancia (técnicos)
# ═══════════════════════════════════════════════════════════════════
header "  1/4  Configuración de la instancia"
echo ""

ask "Nombre corto de la instancia (sin espacios)" "pnd"
INSTANCE_NAME="$REPLY"

_show_used_ports
SUGGESTED_BACKEND=$(next_port 3000 10)
SUGGESTED_FRONTEND=$(next_port 8080 1)

ask "Puerto del backend  (API GraphQL)" "$SUGGESTED_BACKEND"
BACKEND_PORT="$REPLY"

ask "Puerto del frontend (interfaz web)" "$SUGGESTED_FRONTEND"
FRONTEND_PORT="$REPLY"

ask "Nombre de la base de datos en MongoDB" "newmodels"
MONGO_DB="$REPLY"

echo ""
echo -e "  ${YELLOW}Acceso público (dominio o IP que verán los usuarios):${NC}"

ask "Dominio o IP pública del servidor" "localhost"
PUBLIC_HOST="$REPLY"

ask "Protocolo (http / https)" "http"
PROTOCOL="$REPLY"

# Construir URLs públicas:
# Si el frontend corre en puerto 80/443 no se incluye el puerto en la URL
if { [[ "$PROTOCOL" == "https" && "$FRONTEND_PORT" == "443" ]] || \
     [[ "$PROTOCOL" == "http"  && "$FRONTEND_PORT" == "80"  ]]; }; then
  FRONTEND_URL="${PROTOCOL}://${PUBLIC_HOST}"
else
  FRONTEND_URL="${PROTOCOL}://${PUBLIC_HOST}:${FRONTEND_PORT}"
fi

if { [[ "$PROTOCOL" == "https" && "$BACKEND_PORT" == "443" ]] || \
     [[ "$PROTOCOL" == "http"  && "$BACKEND_PORT" == "80"  ]]; }; then
  BACKEND_URL="${PROTOCOL}://${PUBLIC_HOST}"
else
  BACKEND_URL="${PROTOCOL}://${PUBLIC_HOST}:${BACKEND_PORT}"
fi

# ═══════════════════════════════════════════════════════════════════
# SECCIÓN 2 — Datos de la institución
# ═══════════════════════════════════════════════════════════════════
echo ""
header "  2/4  Datos de la institución"
echo ""

ask "Nombre oficial del ayuntamiento / institución" "AYUNTAMIENTO MUNICIPAL"
ENTE_PUBLICO="$REPLY"

ask "Clave corta de la institución (se usa como prefijo en documentos)" "AYT_"
CLAVE="$REPLY"

ask "Ciudad o municipio" "Ciudad de México"
LUGAR="$REPLY"

echo ""
echo -e "  ${YELLOW}Titular que firma y recibe las declaraciones:${NC}"

ask "Nombre completo del titular" "NOMBRE APELLIDO APELLIDO"
TITULAR_NOMBRE="$REPLY"

ask "Cargo del titular" "SECRETARIO/A EJECUTIVO/A"
TITULAR_CARGO="$REPLY"

# ═══════════════════════════════════════════════════════════════════
# SECCIÓN 3 — Correo electrónico (para reset de contraseña)
# ═══════════════════════════════════════════════════════════════════
echo ""
header "  3/4  Correo electrónico (para reset de contraseña)"
echo ""
echo -e "  ${YELLOW}Deja en blanco si lo configuras después.${NC}"
echo -e "  ${YELLOW}Recomendado: puerto 587 con STARTTLS (SMTP_SECURE=false).${NC}"
echo -e "  ${YELLOW}El puerto 465 suele estar bloqueado por ISPs domésticos.${NC}"
echo ""

ask "Servidor SMTP (ej: smtp.gmail.com)" ""
SMTP_HOST="$REPLY"

if [ -n "$SMTP_HOST" ]; then
  ask "Puerto SMTP (587 STARTTLS recomendado / 465 SSL)" "587"
  SMTP_PORT="$REPLY"

  ask "¿Conexión SSL implícita? (true solo si puerto 465; false para 587)" "false"
  SMTP_SECURE="$REPLY"

  ask "Usuario SMTP / correo" ""
  SMTP_USER="$REPLY"

  ask_pass "Contraseña SMTP (o App Password de Gmail)"
  SMTP_PASSWORD="$REPLY"

  ask "Correo remitente" "$SMTP_USER"
  SMTP_FROM="$REPLY"
else
  SMTP_PORT="587"; SMTP_SECURE="false"
  SMTP_USER=""; SMTP_PASSWORD=""; SMTP_FROM=""
  warn "Correo omitido — el reset de contraseña no funcionará hasta configurarlo."
fi

# ═══════════════════════════════════════════════════════════════════
# SECCIÓN 4 — Confirmación
# ═══════════════════════════════════════════════════════════════════
echo ""
header "  4/4  Resumen — confirma antes de continuar"
echo ""
echo -e "  ${BOLD}Instancia:${NC}        $INSTANCE_NAME"
echo -e "  ${BOLD}Backend:${NC}          ${BACKEND_URL}"
echo -e "  ${BOLD}Frontend:${NC}         ${FRONTEND_URL}"
echo -e "  ${BOLD}Base de datos:${NC}    $MONGO_DB"
echo ""
echo -e "  ${BOLD}Institución:${NC}      $ENTE_PUBLICO"
echo -e "  ${BOLD}Clave:${NC}            $CLAVE"
echo -e "  ${BOLD}Lugar:${NC}            $LUGAR"
echo -e "  ${BOLD}Titular:${NC}          $TITULAR_NOMBRE  ($TITULAR_CARGO)"
echo ""
if [ -n "$SMTP_HOST" ]; then
  echo -e "  ${BOLD}SMTP:${NC}             $SMTP_HOST:$SMTP_PORT ($SMTP_USER)"
else
  echo -e "  ${BOLD}SMTP:${NC}             ${YELLOW}no configurado${NC}"
fi
echo ""
echo -ne "${BOLD}  ¿Continuar con la instalación? [S/n]:${NC} "
read -r confirm
[[ "${confirm,,}" == "n" ]] && { echo "Instalación cancelada."; exit 0; }

# ═══════════════════════════════════════════════════════════════════
# INSTALACIÓN
# ═══════════════════════════════════════════════════════════════════

INSTANCE_DIR="$(dirname "$BASE_DIR")/${INSTANCE_NAME}"

# Si la instancia es "pnd" usar el directorio base directamente
[ "$INSTANCE_NAME" = "pnd" ] && INSTANCE_DIR="$BASE_DIR"

echo ""
info "Creando instancia en: $INSTANCE_DIR"
mkdir -p "$INSTANCE_DIR"
cd "$INSTANCE_DIR"

# ── Clonar repositorios ───────────────────────────────────────────
echo ""
info "Clonando repositorios..."
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
    git -C "$dir" pull --quiet --ff-only 2>/dev/null || true
    success "$dir (actualizado)"
  else
    git clone --quiet "$url" "$dir"
    success "$dir (clonado)"
  fi
done

# ── Preparar directorios de MongoDB ──────────────────────────────
if [ -d "database-test" ]; then
  pushd database-test > /dev/null
  bash step-01.sh
  popd > /dev/null
fi

# ── Generar secretos ──────────────────────────────────────────────
JWT_SECRET=$(openssl rand -hex 32)
REFRESH_JWT_SECRET=$(openssl rand -hex 32)
REPORTS_API_KEY=$(openssl rand -hex 16)

# MongoDB: reutilizar credenciales compartidas si existen.
# Mongo solo inicializa el usuario root en el PRIMER arranque, así que la
# password debe ser estable mientras el contenedor pdnmx-mongo viva.
if [ -f "$BASE_DIR/.env" ] && grep -q '^DB_ROOT_PASSWORD=' "$BASE_DIR/.env"; then
  DB_ROOT_USER=$(grep '^DB_ROOT_USER=' "$BASE_DIR/.env" | head -1 | cut -d= -f2)
  DB_ROOT_PASSWORD=$(grep '^DB_ROOT_PASSWORD=' "$BASE_DIR/.env" | head -1 | cut -d= -f2)
  info "Reutilizando credenciales MongoDB de $BASE_DIR/.env"
elif sudo docker ps --format '{{.Names}}' 2>/dev/null | grep -q '^pdnmx-mongo$'; then
  err "pdnmx-mongo corre pero $BASE_DIR/.env no tiene DB_ROOT_PASSWORD.\n  Soluciones:\n    a) Restaura el .env compartido (recupera DB_ROOT_PASSWORD de un .env de instancia)\n    b) Ejecuta limpiar.sh para resetear todo y empezar de cero"
else
  DB_ROOT_USER="pdnmx_admin"
  DB_ROOT_PASSWORD=$(openssl rand -hex 16)
  info "Generadas credenciales MongoDB nuevas (primer arranque)"
fi

# ── .env raíz ─────────────────────────────────────────────────────
cat > .env <<EOF
COMPOSE_PROJECT_NAME=${INSTANCE_NAME}
BACKEND_PORT=${BACKEND_PORT}
FRONTEND_PORT=${FRONTEND_PORT}
DB_ROOT_USER=${DB_ROOT_USER}
DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
DB_PORT=27017
REPORTS_API_KEY=${REPORTS_API_KEY}
NODE_ENV=production
EOF
success ".env raíz generado"

# ── Backend .env ──────────────────────────────────────────────────
cat > SistemaDeclaraciones_backend/.env <<EOF
NODE_ENV=production
PORT=3000
FE_RESET_PASSWORD_URL=${FRONTEND_URL}

JWT_NO_VERIFY=false
JWT_SECRET=${JWT_SECRET}
JWT_EXPIRATION=8h
REFRESH_JWT_SECRET=${REFRESH_JWT_SECRET}
REFRESH_JWT_EXPIRATION=2d

USE_SMTP=true
SENDGRID_API_KEY=
SENDGRID_MAIL_SENDER=
SMTP_HOST=${SMTP_HOST}
SMTP_PORT=${SMTP_PORT}
SMTP_SECURE=${SMTP_SECURE}
SMTP_USER=${SMTP_USER}
SMTP_PASSWORD=${SMTP_PASSWORD}
SMTP_FROM_EMAIL=${SMTP_FROM}

REPORTS_URL=http://${INSTANCE_NAME}-reports:3001
REPORTS_API_KEY=${REPORTS_API_KEY}

MONGO_USERNAME=${DB_ROOT_USER}
MONGO_PASSWORD=${DB_ROOT_PASSWORD}
MONGO_HOSTNAME=pdnmx-mongo
MONGO_PORT=27017
MONGO_DB=${MONGO_DB}
EOF
success "backend/.env generado"

# ── instituciones.json ────────────────────────────────────────────
# Textos legales estándar (según normatividad del DOF 23/09/2019)
TEXTO_INICIAL="CON ESTA FECHA SE RECIBIÓ SU DECLARACIÓN INICIAL, EN TÉRMINOS DE LA DECIMOPRIMERA DE LAS NORMAS E INSTRUCTIVO PARA EL LLENADO Y PRESENTACIÓN DEL FORMATO DE DECLARACIONES: DE SITUACIÓN PATRIMONIAL Y DE INTERESES, PUBLICADAS EN EL DIARIO OFICIAL DE LA FEDERACIÓN EL 23 DE SEPTIEMBRE DE 2019, PRESENTADA BAJO PROTESTA DE DECIR VERDAD, EN CUMPLIMIENTO A LO DISPUESTO EN LOS ARTÍCULOS, 108 DE LA CONSTITUCIÓN POLÍTICA DE LOS ESTADOS UNIDOS MEXICANOS, 32 Y 33 FRACCIÓN I, DE LA LEY GENERAL DE RESPONSABILIDADES ADMINISTRATIVAS, DE LA QUE SE ACUSA DE RECIBO."
TEXTO_MODIF="CON ESTA FECHA SE RECIBIÓ SU DECLARACIÓN DE MODIFICACION, EN TÉRMINOS DE LA DECIMOPRIMERA DE LAS NORMAS E INSTRUCTIVO PARA EL LLENADO Y PRESENTACIÓN DEL FORMATO DE DECLARACIONES: DE SITUACIÓN PATRIMONIAL Y DE INTERESES, PUBLICADAS EN EL DIARIO OFICIAL DE LA FEDERACIÓN EL 23 DE SEPTIEMBRE DE 2019, PRESENTADA BAJO PROTESTA DE DECIR VERDAD, EN CUMPLIMIENTO A LO DISPUESTO EN LOS ARTÍCULOS, 108 DE LA CONSTITUCIÓN POLÍTICA DE LOS ESTADOS UNIDOS MEXICANOS, 32 Y 33 FRACCIÓN II, DE LA LEY GENERAL DE RESPONSABILIDADES ADMINISTRATIVAS, DE LA QUE SE ACUSA DE RECIBO."
TEXTO_CONCL="CON ESTA FECHA SE RECIBIÓ SU DECLARACIÓN DE CONCLUSIÓN, EN TÉRMINOS DE LA DECIMOPRIMERA DE LAS NORMAS E INSTRUCTIVO PARA EL LLENADO Y PRESENTACIÓN DEL FORMATO DE DECLARACIONES: DE SITUACIÓN PATRIMONIAL Y DE INTERESES, PUBLICADAS EN EL DIARIO OFICIAL DE LA FEDERACIÓN EL 23 DE SEPTIEMBRE DE 2019, PRESENTADA BAJO PROTESTA DE DECIR VERDAD, EN CUMPLIMIENTO A LO DISPUESTO EN LOS ARTÍCULOS, 108 DE LA CONSTITUCIÓN POLÍTICA DE LOS ESTADOS UNIDOS MEXICANOS, 32 Y 33 FRACCIÓN III, DE LA LEY GENERAL DE RESPONSABILIDADES ADMINISTRATIVAS, DE LA QUE SE ACUSA DE RECIBO."
TEXTO_ELECTRONICO="LA DECLARACIÓN DE SITUACIÓN PATRIMONIAL Y DE INTERESES HA SIDO PRESENTADA DE MANERA ELECTRÓNICA."
DEC_INICIAL="BAJO PROTESTA DE DECIR VERDAD, PRESENTO A USTED MI DECLARACIÓN PATRIMONIAL Y DE INTERESES, CONFORME A LO DISPUESTO EN LOS ARTÍCULOS, 108 DE LA CONSTITUCIÓN POLÍTICA DE LOS ESTADOS UNIDOS MEXICANOS, 32 Y 33 FRACCIÓN I, DE LA LEY GENERAL DE RESPONSABILIDADES ADMINISTRATIVAS."
DEC_MODIF="BAJO PROTESTA DE DECIR VERDAD, PRESENTO A USTED MI DECLARACIÓN PATRIMONIAL Y DE INTERESES, CONFORME A LO DISPUESTO EN LOS ARTÍCULOS, 108 DE LA CONSTITUCIÓN POLÍTICA DE LOS ESTADOS UNIDOS MEXICANOS, 32 Y 33 FRACCIÓN II, DE LA LEY GENERAL DE RESPONSABILIDADES ADMINISTRATIVAS."
DEC_CONCL="BAJO PROTESTA DE DECIR VERDAD, PRESENTO A USTED MI DECLARACIÓN PATRIMONIAL Y DE INTERESES, CONFORME A LO DISPUESTO EN LOS ARTÍCULOS, 108 DE LA CONSTITUCIÓN POLÍTICA DE LOS ESTADOS UNIDOS MEXICANOS, 32 Y 33 FRACCIÓN III, DE LA LEY GENERAL DE RESPONSABILIDADES ADMINISTRATIVAS."

python3 - <<PYEOF
import json

data = [{
    "ente_publico": "${ENTE_PUBLICO}",
    "clave": "${CLAVE}",
    "lugar": "${LUGAR}",
    "servidor_publico_recibe": {
        "nombre": "${TITULAR_NOMBRE}",
        "cargo": "${TITULAR_CARGO}"
    },
    "acuse": {
        "inicial": {
            "tipo_declaracion": "DECLARACIÓN INICIAL",
            "texto1_cuerpo_acuse": "${TEXTO_INICIAL}",
            "texto2_cuerpo_acuse": "${TEXTO_ELECTRONICO}"
        },
        "modificacion": {
            "tipo_declaracion": "DECLARACIÓN DE MODIFICACIÓN",
            "texto1_cuerpo_acuse": "${TEXTO_MODIF}",
            "texto2_cuerpo_acuse": "${TEXTO_ELECTRONICO}"
        },
        "conclusion": {
            "tipo_declaracion": "DECLARACIÓN DE CONCLUSIÓN",
            "texto1_cuerpo_acuse": "${TEXTO_CONCL}",
            "texto2_cuerpo_acuse": "${TEXTO_ELECTRONICO}"
        }
    },
    "declaracion": {
        "subtitulo": "DECLARACIÓN PATRIMONIAL Y DE INTERESES",
        "inicial": {
            "tipo_declaracion": "DECLARACIÓN INICIAL",
            "texto_declaratoria": "${DEC_INICIAL}"
        },
        "modificacion": {
            "tipo_declaracion": "DECLARACIÓN DE MODIFICACIÓN",
            "texto_declaratoria": "${DEC_MODIF}"
        },
        "conclusion": {
            "tipo_declaracion": "DECLARACIÓN DE CONCLUSIÓN",
            "texto_declaratoria": "${DEC_CONCL}"
        }
    }
}]

with open("SistemaDeclaraciones_backend/src/data/instituciones.json", "w", encoding="utf-8") as f:
    json.dump(data, f, ensure_ascii=False, indent=2)
print("OK")
PYEOF
success "instituciones.json generado para: $ENTE_PUBLICO"

# ── Parchear URL del backend en el frontend ────────────────────────
sed -i \
  "s|serverUrl: '.*'|serverUrl: '${BACKEND_URL}'|; \
   s|pageUrl: '.*'|pageUrl: '${FRONTEND_URL}/'|" \
  SistemaDeclaraciones_frontend/src/environments/environment.prod.ts
success "Frontend configurado → ${BACKEND_URL}"

# ── Dockerfile.fixed del backend ──────────────────────────────────
cat > SistemaDeclaraciones_backend/Dockerfile.fixed <<'DOCKERFILE'
FROM node:18-alpine

ADD . /backend
WORKDIR /backend

ARG NODE_ENV

# Usar instituciones.json personalizado si existe, si no usar el ejemplo
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
success "Dockerfile.fixed del backend actualizado"

# ── Red Docker compartida ─────────────────────────────────────────
sudo docker network create pdnmx_network 2>/dev/null && \
  success "Red 'pdnmx_network' creada" || \
  success "Red 'pdnmx_network' ya existe"

# ── docker-compose.shared.yml (solo para instancia principal) ─────
if [ "$INSTANCE_DIR" = "$BASE_DIR" ] || [ ! -f "$(dirname "$BASE_DIR")/docker-compose.shared.yml" ]; then
  cat > docker-compose.shared.yml <<'SHARED'
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
  success "docker-compose.shared.yml generado"
fi

# ── docker-compose.yml de la instancia ────────────────────────────
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
success "docker-compose.yml generado"

# ── Iniciar todo ──────────────────────────────────────────────────
echo ""
if sudo docker ps --format '{{.Names}}' 2>/dev/null | grep -q '^pdnmx-mongo$'; then
  success "MongoDB compartido ya está corriendo — se reutiliza"
else
  info "Iniciando MongoDB compartido..."
  sudo docker compose -f docker-compose.shared.yml up -d
fi

echo ""
warn "Construyendo e iniciando servicios de la instancia (puede tardar varios minutos)..."
sudo docker compose up --build -d

# ── Resumen ───────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${GREEN}══════════════════════════════════════════════════════${NC}"
echo -e "${BOLD}${GREEN}   ¡Listo! Instancia '${INSTANCE_NAME}' configurada${NC}"
echo -e "${BOLD}${GREEN}══════════════════════════════════════════════════════${NC}"
echo ""
echo -e "   ${BOLD}Frontend:${NC}     ${FRONTEND_URL}"
echo -e "   ${BOLD}Backend:${NC}      ${BACKEND_URL}/graphql"
echo -e "   ${BOLD}Institución:${NC}  ${ENTE_PUBLICO}"
echo -e "   ${BOLD}Base datos:${NC}   ${MONGO_DB}"
echo ""
echo -e "${BOLD}Próximo paso — crear el primer usuario administrador:${NC}"
echo -e "  1. Regístrate en ${FRONTEND_URL}"
echo -e "  2. Luego ejecuta:"
echo -e "     ${CYAN}sudo docker exec -it pdnmx-mongo mongosh \\${NC}"
echo -e "     ${CYAN}  -u ${DB_ROOT_USER} --authenticationDatabase admin${NC}"
echo -e "     ${CYAN}use ${MONGO_DB}${NC}"
echo -e "     ${CYAN}var u = db.users.findOne()${NC}"
echo -e "     ${CYAN}u.roles = ['ROOT']${NC}"
echo -e "     ${CYAN}db.users.updateOne({_id: u._id}, {\\\$set: u})${NC}"
echo ""
echo -e "${BOLD}Comandos útiles:${NC}"
echo -e "   sudo docker compose ps"
echo -e "   sudo docker compose logs -f app"
echo -e "   sudo docker compose down"
echo ""
