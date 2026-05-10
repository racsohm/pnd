#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# pnd-backups/setup.sh — Despliegue del panel de respaldos
#
# Levanta un contenedor (PHP + nginx + Laravel + mongodb-tools) que se
# une a la red 'pdnmx_network' y monta /opt:/host/instances:ro para
# auto-detectar instancias PND.
#
# Pre-requisitos:
#   - Docker + docker compose en el host
#   - Red 'pdnmx_network' creada por setup.sh principal
#   - Contenedor 'pdnmx-mongo' corriendo
# ═══════════════════════════════════════════════════════════════════
set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

info()    { echo -e "${BLUE}[INFO]${NC}   $*"; }
success() { echo -e "${GREEN}[OK]${NC}     $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}   $*"; }
err()     { echo -e "${RED}[ERROR]${NC}  $*" >&2; exit 1; }
header()  { echo -e "\n${BOLD}${CYAN}─── $* ───${NC}"; }

cd "$(dirname "$0")"

header "1) Verificando prerequisitos"
command -v docker >/dev/null 2>&1 || err "docker no instalado"
docker compose version >/dev/null 2>&1 || err "docker compose plugin no instalado"
sudo docker network inspect pdnmx_network >/dev/null 2>&1 \
  || err "Red 'pdnmx_network' no existe. Ejecuta primero el setup principal de PND."
sudo docker ps --format '{{.Names}}' | grep -q '^pdnmx-mongo$' \
  || warn "Contenedor 'pdnmx-mongo' no está corriendo. El panel arrancará pero los dump fallarán hasta que lo levantes."
success "Docker + red OK"

header "2) Configurando .env"
if [ ! -f .env ]; then
  cp .env.example .env
  # Password admin aleatoria
  RAND_PASS=$(openssl rand -hex 12)
  sed -i "s|ADMIN_PASSWORD=cambia-esto-por-favor|ADMIN_PASSWORD=$RAND_PASS|" .env
  # APP_KEY: hay que generarla acá (no en el entrypoint), porque
  # env_file inyecta APP_KEY="" al contenedor y getenv() gana sobre
  # el .env file en config:cache → la key del entrypoint se ignora.
  APP_KEY="base64:$(openssl rand -base64 32)"
  sed -i "s|^APP_KEY=$|APP_KEY=$APP_KEY|" .env
  success ".env creado (password admin y APP_KEY generados)."
  warn "Password admin: $RAND_PASS"
  warn "Guárdalo ya — no se volverá a mostrar."
else
  info ".env ya existe — no se sobrescribe."
  # Backfill si APP_KEY está vacío. Detecta también APP_KEY="", APP_KEY=''
  # y variantes con espacios, no solo el match literal de antes.
  CURRENT_KEY=$(grep -m1 '^APP_KEY=' .env 2>/dev/null \
    | sed -e 's/^APP_KEY=//' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' \
          -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/" || true)
  if [ -z "$CURRENT_KEY" ]; then
    APP_KEY="base64:$(openssl rand -base64 32)"
    sed -i "s|^APP_KEY=.*|APP_KEY=$APP_KEY|" .env
    warn "APP_KEY estaba vacío en .env existente — generada ahora."
  fi
fi

header "3) Construyendo imagen"
sudo docker compose build

header "4) Iniciando contenedor"
sudo docker compose up -d
sleep 3

header "5) Verificando"
if sudo docker ps --format '{{.Names}}' | grep -q '^pnd-backups$'; then
  success "pnd-backups corriendo."
  BIND=$(grep '^BIND_ADDRESS=' .env | cut -d= -f2)
  PORT=$(grep '^HOST_PORT=' .env | cut -d= -f2)
  ADMIN_EMAIL=$(grep '^ADMIN_EMAIL=' .env | cut -d= -f2)
  echo ""
  echo -e "  ${BOLD}URL:${NC}    http://$BIND:$PORT"
  echo -e "  ${BOLD}Admin:${NC}  $ADMIN_EMAIL"
  if [ "$BIND" = "127.0.0.1" ]; then
    warn "Servicio BIND en 127.0.0.1 — solo accesible desde el host."
    warn "Para acceso público edita BIND_ADDRESS=0.0.0.0 en .env y haz:"
    warn "  sudo docker compose up -d --force-recreate"
  else
    warn "Servicio EXPUESTO públicamente sin TLS — los respaldos viajarán en claro."
    warn "Considera nginx + Let's Encrypt cuanto antes."
  fi
else
  err "El contenedor no arrancó. Revisa: sudo docker compose logs"
fi
