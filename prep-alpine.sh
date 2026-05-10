#!/bin/sh
# ═══════════════════════════════════════════════════════════════════
# prep-alpine.sh — Prepara una VPS Alpine para correr SistemaDeclaraciones
#
# Alpine usa busybox + OpenRC + apk en lugar de GNU coreutils + systemd
# + apt. Este script instala las dependencias que setup.sh asume que ya
# están (bash, docker, shadow, python3, ss, sudo, openssl, git, curl) y
# arranca el demonio Docker.
#
# Después de correr esto, los demás scripts (asistente.sh, setup.sh,
# nueva-instancia.sh, optimizar-1gb.sh) corren tal cual.
#
# Importante:
#   - Este script usa /bin/sh (busybox ash) — NO depende de bash.
#   - Es idempotente: se puede correr varias veces sin daño.
#   - Sólo corre en Alpine. En cualquier otra distro aborta.
#
# Uso:
#   sh prep-alpine.sh          # interactivo
#   sh prep-alpine.sh --yes    # no preguntar
# ═══════════════════════════════════════════════════════════════════
set -eu

# ── Colores (POSIX, sin ${var//}) ────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'

info()    { printf "${BLUE}[INFO]${NC}   %s\n" "$*"; }
success() { printf "${GREEN}[OK]${NC}     %s\n" "$*"; }
warn()    { printf "${YELLOW}[WARN]${NC}   %s\n" "$*"; }
err()     { printf "${RED}[ERROR]${NC}  %s\n" "$*" >&2; exit 1; }
header()  { printf "\n${BOLD}${BLUE}──────────────────────────────────────${NC}\n"; \
            printf "${BOLD}${BLUE}  %s${NC}\n" "$*"; \
            printf "${BOLD}${BLUE}──────────────────────────────────────${NC}\n"; }

NON_INTERACTIVE=false
[ "${1:-}" = "--yes" ] || [ "${1:-}" = "-y" ] && NON_INTERACTIVE=true

# ── Banner ───────────────────────────────────────────────────────
printf "${BOLD}${BLUE}\n"
printf "  ╔══════════════════════════════════════════════╗\n"
printf "  ║  SistemaDeclaraciones PDNMX — Prep Alpine   ║\n"
printf "  ╚══════════════════════════════════════════════╝\n"
printf "${NC}\n"

# ── Verificar que estamos en Alpine ──────────────────────────────
if [ ! -f /etc/alpine-release ]; then
  err "Esta máquina no parece ser Alpine (no existe /etc/alpine-release).\n  Si es Ubuntu/Debian, corre directamente bash setup.sh — no necesitas este script."
fi

ALPINE_VERSION=$(cat /etc/alpine-release)
info "Alpine detectado: ${ALPINE_VERSION}"

# ── Verificar root o sudo ────────────────────────────────────────
if [ "$(id -u)" -ne 0 ]; then
  if ! command -v sudo >/dev/null 2>&1; then
    err "No eres root y no hay sudo. Corre como root:\n  doas su -\n  sh prep-alpine.sh"
  fi
  SUDO="sudo"
else
  SUDO=""
fi

# ── Confirmación ─────────────────────────────────────────────────
diagnose() {
  header "Plan"
  echo "  1. Habilitar el repo 'community' en /etc/apk/repositories"
  echo "  2. apk add: bash sudo shadow git curl openssl python3 iproute2"
  echo "             docker docker-cli-compose"
  echo "  3. Agregar usuario '${USER:-$(whoami)}' al grupo docker"
  echo "  4. rc-update add docker default + service docker start"
  echo "  5. Verificar 'docker info'"
  echo ""
  if [ "$NON_INTERACTIVE" = false ]; then
    printf "${BOLD}¿Continuar? [s/N] ${NC}"
    read ans
    case "$ans" in
      s|S|y|Y|si|Si|SI|yes|YES) ;;
      *) info "Cancelado por el usuario."; exit 0 ;;
    esac
  fi
}

# ── 1. Habilitar el repo community ───────────────────────────────
enable_community_repo() {
  header "Habilitar repo 'community'"
  if grep -qE '^[^#]*\bcommunity\b' /etc/apk/repositories; then
    success "Repo 'community' ya habilitado"
    return
  fi
  # Línea típica comentada: #http://dl-cdn.alpinelinux.org/alpine/v3.19/community
  if grep -qE '^#.*\bcommunity\b' /etc/apk/repositories; then
    $SUDO sed -i -E 's|^#(.*\bcommunity\b)|\1|' /etc/apk/repositories
    success "Descomentado community en /etc/apk/repositories"
  else
    # Construir URL community a partir de la línea main activa
    MAIN_URL=$(grep -E '^[^#]*\bmain\b' /etc/apk/repositories | head -1 | sed 's|/main$||')
    if [ -z "$MAIN_URL" ]; then
      warn "No se encontró 'main' activo — agrega community manualmente."
      return
    fi
    echo "${MAIN_URL}/community" | $SUDO tee -a /etc/apk/repositories >/dev/null
    success "Agregado: ${MAIN_URL}/community"
  fi
  info "Actualizando índices apk..."
  $SUDO apk update >/dev/null
}

# ── 2. Instalar paquetes ─────────────────────────────────────────
install_packages() {
  header "Instalar paquetes base"

  # Lista mínima requerida por los scripts del proyecto
  PKGS="bash sudo shadow git curl openssl python3 iproute2 grep sed gawk findutils tar"
  # Docker y plugin compose
  PKGS="$PKGS docker docker-cli-compose"

  info "Paquetes: $PKGS"
  # shellcheck disable=SC2086
  $SUDO apk add --no-cache $PKGS

  success "Paquetes instalados"
}

# ── 3. Usuario al grupo docker ───────────────────────────────────
add_user_to_docker_group() {
  header "Agregar usuario al grupo docker"

  TARGET_USER="${SUDO_USER:-${USER:-$(whoami)}}"

  if [ "$TARGET_USER" = "root" ]; then
    info "Eres root — no necesitas estar en el grupo docker"
    return
  fi

  if ! getent group docker >/dev/null 2>&1; then
    $SUDO addgroup docker
  fi

  if id "$TARGET_USER" 2>/dev/null | grep -q '\bdocker\b'; then
    success "Usuario '${TARGET_USER}' ya está en el grupo docker"
  else
    $SUDO addgroup "$TARGET_USER" docker
    success "Usuario '${TARGET_USER}' agregado al grupo docker"
    warn "Cierra sesión y vuelve a entrar para que el grupo surta efecto"
    warn "(o usa 'newgrp docker' en esta sesión para no esperar)"
  fi
}

# ── 4. Habilitar e iniciar dockerd ───────────────────────────────
enable_docker_service() {
  header "Habilitar e iniciar dockerd (OpenRC)"

  if ! command -v rc-update >/dev/null 2>&1; then
    warn "rc-update no encontrado — esta Alpine no usa OpenRC"
    warn "Inicia dockerd manualmente: $SUDO dockerd &"
    return
  fi

  $SUDO rc-update add docker default 2>/dev/null || true
  success "docker añadido al runlevel 'default'"

  if $SUDO service docker status 2>/dev/null | grep -q started; then
    success "dockerd ya está corriendo"
  else
    info "Iniciando dockerd..."
    $SUDO service docker start
    # Esperar hasta 30s a que el socket esté listo
    i=0
    while [ $i -lt 30 ]; do
      [ -S /var/run/docker.sock ] && break
      sleep 1
      i=$((i + 1))
    done
    if [ ! -S /var/run/docker.sock ]; then
      err "dockerd no levantó después de 30s.\n  Revisa: $SUDO service docker status"
    fi
    success "dockerd corriendo (/var/run/docker.sock listo)"
  fi
}

# ── 5. Verificación final ────────────────────────────────────────
verify() {
  header "Verificar instalación"

  if ! $SUDO docker info >/dev/null 2>&1; then
    err "'docker info' falla.\n  Revisa: $SUDO service docker status\n  Logs:   $SUDO tail -50 /var/log/docker.log"
  fi
  success "$($SUDO docker --version)"
  success "$($SUDO docker compose version 2>/dev/null || echo 'compose plugin OK')"
  success "$(bash --version | head -1)"
  success "$(python3 --version)"
}

# ── 6. Resumen ───────────────────────────────────────────────────
print_summary() {
  echo ""
  printf "${BOLD}${GREEN}═══════════════════════════════════════════════════${NC}\n"
  printf "${BOLD}${GREEN}   Alpine listo para SistemaDeclaraciones${NC}\n"
  printf "${BOLD}${GREEN}═══════════════════════════════════════════════════${NC}\n"
  echo ""
  echo "  Próximos pasos en este orden:"
  echo ""
  echo "    1. (recomendado en VPS de 1 GB) Crear swap antes del 1er build:"
  echo "         bash optimizar-1gb.sh --yes"
  echo ""
  echo "    2. Instalar la primera instancia (con wizard interactivo):"
  echo "         bash asistente.sh"
  echo ""
  echo "    3. Si pusiste 'optimizar' antes del setup, vuelve a correrlo"
  echo "       después para parchear los compose recién generados:"
  echo "         bash optimizar-1gb.sh --yes"
  echo ""
}

# ── Main ─────────────────────────────────────────────────────────
diagnose
enable_community_repo
install_packages
add_user_to_docker_group
enable_docker_service
verify
print_summary
