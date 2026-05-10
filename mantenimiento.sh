#!/bin/bash
# ═══════════════════════════════════════════════════════════════════
# mantenimiento.sh — Cambios in-situ post-instalación
#
# Permite cambiar host (URL pública) y/o configuración SMTP de una
# instancia ya desplegada sin tener que rehacer todo. Detecta qué
# cambió y reinicia/reconstruye solo los contenedores afectados:
#
#   - Cambio de host  → rebuild de webapp (la URL se compila INTO el
#                        bundle Angular) + force-recreate de app
#   - Cambio de SMTP  → solo force-recreate de app (relee el .env)
#
# Modo de uso:
#   bash mantenimiento.sh                     # interactivo
#   bash mantenimiento.sh --show              # ver configuración actual
#   bash mantenimiento.sh --host https://nuevo.gob.mx
#   bash mantenimiento.sh --instance pnd_tecali --smtp-host smtp.gmail.com \
#         --smtp-user me@gmail.com --smtp-password xxx --smtp-from me@gmail.com
#
# Si paso un flag, lo cambia. Si no, lo deja como está.
# ═══════════════════════════════════════════════════════════════════
set -euo pipefail

# ── Colores ──────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

info()    { echo -e "${BLUE}[INFO]${NC}   $*"; }
success() { echo -e "${GREEN}[OK]${NC}     $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}   $*"; }
err()     { echo -e "${RED}[ERROR]${NC}  $*" >&2; exit 1; }
header()  { echo -e "\n${BOLD}${CYAN}─── $* ───${NC}"; }

# ── Helpers .env ─────────────────────────────────────────────────
_read_env() {
  # _read_env <archivo> <var>  → imprime el valor (vacío si no existe)
  local file=$1 var=$2
  [ -f "$file" ] || { echo ""; return; }
  grep -E "^${var}=" "$file" 2>/dev/null | head -1 | cut -d= -f2- || echo ""
}

_set_env() {
  # _set_env <archivo> <var> <valor>
  # Borra línea existente + agrega al final. Esto evita problemas con
  # valores que contengan caracteres especiales para sed (como $ en
  # passwords). Cosmético: la línea queda al final del archivo.
  local file=$1 var=$2 value=$3
  sed -i "/^${var}=/d" "$file"
  printf '%s=%s\n' "$var" "$value" >> "$file"
}

_backup() {
  local file=$1
  [ -f "$file" ] || return 0
  local bak="${file}.bak.$(date +%s)"
  cp -p "$file" "$bak"
  echo "$bak"
}

# ── Parse args ───────────────────────────────────────────────────
INSTANCE=""
NEW_HOST=""
NEW_SERVER_URL_OVERRIDE=""
NEW_PAGE_URL_OVERRIDE=""
NGINX_MODE=false
NEW_SMTP_HOST=""; SET_SMTP_HOST=false
NEW_SMTP_PORT=""; SET_SMTP_PORT=false
NEW_SMTP_SECURE=""; SET_SMTP_SECURE=false
NEW_SMTP_USER=""; SET_SMTP_USER=false
NEW_SMTP_PASSWORD=""; SET_SMTP_PASSWORD=false
NEW_SMTP_FROM=""; SET_SMTP_FROM=false
NEW_USE_SMTP=""; SET_USE_SMTP=false
DISABLE_SMTP=false
SHOW_ONLY=false
DRY_RUN=false
NO_RESTART=false
NON_INTERACTIVE=false
FORCE_REBUILD=false

usage() {
  cat <<EOF
Uso: bash mantenimiento.sh [opciones]

Selección de instancia:
  --instance <nombre>      Nombre del directorio de instancia (ej: pnd_tecali).
                           Si se omite y hay una sola, se usa esa.

Cambio de host:
  --host <url>             URL pública nueva (https://dominio o http://IP).
                           Los puertos se toman del .env automáticamente.
                           Útil cuando expones puertos directos (sin proxy).
  --nginx <url>            Como --host pero SIN agregar puertos. Útil cuando
                           tienes nginx delante en HTTPS (puerto 443) y proxea
                           internamente al backend/frontend.
  --server-url <url>       Override directo del serverUrl (frontend → API).
  --page-url <url>         Override directo del pageUrl (URL pública).

Configuración SMTP (todos opcionales — solo cambia los que pases):
  --smtp-host <host>
  --smtp-port <port>       Default: 587 (STARTTLS)
  --smtp-secure <true|false>  Default: false
  --smtp-user <user>
  --smtp-password <pass>
  --smtp-from <email>
  --use-smtp <true|false>  Activar/desactivar SMTP
  --disable-smtp           Atajo: USE_SMTP=false (no envía correos)

Modo:
  --show                   Mostrar config actual y salir
  --dry-run                Mostrar qué cambiaría sin aplicar
  --no-restart             No reiniciar contenedores tras cambios
  --force-rebuild          Forzar rebuild de webapp + recreate de app aunque
                           no haya cambios detectados. Útil cuando editaste
                           archivos manualmente y solo quieres reconstruir.
                           Alias: --rebuild
  --yes, -y                No pedir confirmación
  --help, -h               Esta ayuda

Ejemplos:
  bash mantenimiento.sh --show
  bash mantenimiento.sh --host http://192.168.1.100        # IP directa con puertos
  bash mantenimiento.sh --nginx https://decl.tecali.gob.mx # nginx delante (HTTPS:443)
  bash mantenimiento.sh --server-url https://api.tecali.gob.mx \\
       --page-url https://decl.tecali.gob.mx              # subdominios separados
  bash mantenimiento.sh --instance pnd_tecali \\
       --smtp-host mail.dataismo.mx --smtp-port 587 \\
       --smtp-user notif@dataismo.mx --smtp-password 'secret' \\
       --smtp-from notif@dataismo.mx
EOF
}

# Validar que una URL trae scheme http:// o https://. Sin scheme, los
# navegadores la interpretan como path relativo y se duplica el host
# en links/forms — bug clásico que costó tiempo en producción.
_validate_url_scheme() {
  local url=$1 flag=$2
  if ! echo "$url" | grep -qE '^https?://'; then
    err "$flag espera URL con scheme — recibí: $url\n  Usa: http://...  o  https://...\n  Sin scheme, el navegador trata la cadena como path y duplica el host."
  fi
}

while [ $# -gt 0 ]; do
  case "$1" in
    --instance)       INSTANCE="$2"; shift 2 ;;
    --host)           _validate_url_scheme "$2" "--host"; NEW_HOST="$2"; shift 2 ;;
    --nginx)          _validate_url_scheme "$2" "--nginx"; NEW_HOST="$2"; NGINX_MODE=true; shift 2 ;;
    --server-url)     _validate_url_scheme "$2" "--server-url"; NEW_SERVER_URL_OVERRIDE="$2"; shift 2 ;;
    --page-url)       _validate_url_scheme "$2" "--page-url"; NEW_PAGE_URL_OVERRIDE="$2"; shift 2 ;;
    --smtp-host)      NEW_SMTP_HOST="$2"; SET_SMTP_HOST=true; shift 2 ;;
    --smtp-port)      NEW_SMTP_PORT="$2"; SET_SMTP_PORT=true; shift 2 ;;
    --smtp-secure)    NEW_SMTP_SECURE="$2"; SET_SMTP_SECURE=true; shift 2 ;;
    --smtp-user)      NEW_SMTP_USER="$2"; SET_SMTP_USER=true; shift 2 ;;
    --smtp-password)  NEW_SMTP_PASSWORD="$2"; SET_SMTP_PASSWORD=true; shift 2 ;;
    --smtp-from)      NEW_SMTP_FROM="$2"; SET_SMTP_FROM=true; shift 2 ;;
    --use-smtp)       NEW_USE_SMTP="$2"; SET_USE_SMTP=true; shift 2 ;;
    --disable-smtp)   DISABLE_SMTP=true; shift ;;
    --show)           SHOW_ONLY=true; shift ;;
    --dry-run)        DRY_RUN=true; shift ;;
    --no-restart)     NO_RESTART=true; shift ;;
    --force-rebuild|--rebuild) FORCE_REBUILD=true; shift ;;
    --yes|-y)         NON_INTERACTIVE=true; shift ;;
    --help|-h)        usage; exit 0 ;;
    *)                err "Argumento desconocido: $1\n  Usa --help para ver opciones." ;;
  esac
done

# ── Detectar/elegir instancia ────────────────────────────────────
BASE_DIR="$(cd "$(dirname "$0")" && pwd)"
PARENT_DIR="$(dirname "$BASE_DIR")"

# Una "instancia" es un dir que tiene .env + SistemaDeclaraciones_backend/.env
detect_instances() {
  local d
  for d in "$BASE_DIR" "$PARENT_DIR"/*/; do
    d="${d%/}"
    if [ -f "$d/.env" ] && [ -f "$d/SistemaDeclaraciones_backend/.env" ]; then
      echo "$d"
    fi
  done | sort -u
}

resolve_instance_dir() {
  if [ -n "$INSTANCE" ]; then
    # Buscar como subdir del padre o dir hermano
    if [ -d "$PARENT_DIR/$INSTANCE" ]; then
      echo "$PARENT_DIR/$INSTANCE"
    elif [ -d "$INSTANCE" ]; then
      echo "$(cd "$INSTANCE" && pwd)"
    else
      err "Instancia '$INSTANCE' no encontrada en $PARENT_DIR/"
    fi
    return
  fi

  # Auto-detect
  local instances
  mapfile -t instances < <(detect_instances)
  case ${#instances[@]} in
    0) err "No se encontraron instancias instaladas en $PARENT_DIR/" ;;
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
INSTANCE_ENV="$INSTANCE_DIR/.env"
FRONTEND_ENV_TS="$INSTANCE_DIR/SistemaDeclaraciones_frontend/src/environments/environment.prod.ts"

[ -f "$BACKEND_ENV" ]    || err "$BACKEND_ENV no existe"
[ -f "$INSTANCE_ENV" ]   || err "$INSTANCE_ENV no existe"
[ -f "$FRONTEND_ENV_TS" ] || err "$FRONTEND_ENV_TS no existe"

# ── Banner ───────────────────────────────────────────────────────
echo -e "${BOLD}${CYAN}"
echo "  ╔══════════════════════════════════════════════╗"
echo "  ║   SistemaDeclaraciones — Mantenimiento      ║"
echo "  ╚══════════════════════════════════════════════╝"
echo -e "${NC}"
echo "  Instancia:   $INSTANCE_NAME"
echo "  Directorio:  $INSTANCE_DIR"
echo ""

# ── Leer configuración actual ────────────────────────────────────
CUR_BACKEND_PORT=$(_read_env "$INSTANCE_ENV" BACKEND_PORT)
CUR_FRONTEND_PORT=$(_read_env "$INSTANCE_ENV" FRONTEND_PORT)

# Extraer host (protocolo + dominio, sin puerto) del serverUrl actual
CUR_SERVER_URL=$(grep -oE "serverUrl: '[^']*'" "$FRONTEND_ENV_TS" | head -1 | sed "s|serverUrl: '||;s|'$||")
CUR_PAGE_URL=$(grep -oE "pageUrl: '[^']*'" "$FRONTEND_ENV_TS" | head -1 | sed "s|pageUrl: '||;s|'$||")
# Quitar :PORT al final para obtener el host base
CUR_HOST=$(echo "$CUR_SERVER_URL" | sed -E 's|:[0-9]+$||')

CUR_USE_SMTP=$(_read_env "$BACKEND_ENV" USE_SMTP)
CUR_SMTP_HOST=$(_read_env "$BACKEND_ENV" SMTP_HOST)
CUR_SMTP_PORT=$(_read_env "$BACKEND_ENV" SMTP_PORT)
CUR_SMTP_SECURE=$(_read_env "$BACKEND_ENV" SMTP_SECURE)
CUR_SMTP_USER=$(_read_env "$BACKEND_ENV" SMTP_USER)
CUR_SMTP_PASSWORD=$(_read_env "$BACKEND_ENV" SMTP_PASSWORD)
CUR_SMTP_FROM=$(_read_env "$BACKEND_ENV" SMTP_FROM_EMAIL)
CUR_FE_RESET_URL=$(_read_env "$BACKEND_ENV" FE_RESET_PASSWORD_URL)

# Detectar si las URLs actuales no tienen scheme (síntoma del bug clásico)
URLS_MISSING_SCHEME=false
for u in "$CUR_SERVER_URL" "$CUR_PAGE_URL" "$CUR_FE_RESET_URL"; do
  [ -z "$u" ] && continue
  echo "$u" | grep -qE '^https?://' || URLS_MISSING_SCHEME=true
done

show_current() {
  header "Configuración actual"
  if $URLS_MISSING_SCHEME; then
    warn "Una o más URLs no tienen scheme (http:// o https://)"
    warn "Esto causa que el navegador trate la cadena como path → host duplicado en links/forms"
    warn "Corrige con: bash mantenimiento.sh --server-url https://... --page-url https://..."
    echo ""
  fi
  echo -e "  ${BOLD}Host / URLs${NC}"
  echo "    Host base:                   $CUR_HOST"
  echo "    serverUrl  (frontend → API): $CUR_SERVER_URL"
  echo "    pageUrl    (público):        $CUR_PAGE_URL"
  echo "    FE_RESET_PASSWORD_URL:       $CUR_FE_RESET_URL"
  echo "    Puerto backend:              $CUR_BACKEND_PORT"
  echo "    Puerto frontend:             $CUR_FRONTEND_PORT"
  echo ""
  echo -e "  ${BOLD}SMTP${NC}"
  echo "    USE_SMTP:                    $CUR_USE_SMTP"
  echo "    SMTP_HOST:                   $CUR_SMTP_HOST"
  echo "    SMTP_PORT:                   $CUR_SMTP_PORT"
  echo "    SMTP_SECURE:                 $CUR_SMTP_SECURE"
  echo "    SMTP_USER:                   $CUR_SMTP_USER"
  echo "    SMTP_PASSWORD:               $([ -n "$CUR_SMTP_PASSWORD" ] && echo '(definida)' || echo '(vacía)')"
  echo "    SMTP_FROM_EMAIL:             $CUR_SMTP_FROM"
}

show_current

if [ "$SHOW_ONLY" = true ]; then
  exit 0
fi

# ── Modo interactivo si no se pasaron flags de cambio ────────────
ANY_FLAG=false
[ -n "$NEW_HOST" ] && ANY_FLAG=true
[ -n "$NEW_SERVER_URL_OVERRIDE" ] && ANY_FLAG=true
[ -n "$NEW_PAGE_URL_OVERRIDE" ] && ANY_FLAG=true
$FORCE_REBUILD   && ANY_FLAG=true
$SET_SMTP_HOST   && ANY_FLAG=true
$SET_SMTP_PORT   && ANY_FLAG=true
$SET_SMTP_SECURE && ANY_FLAG=true
$SET_SMTP_USER   && ANY_FLAG=true
$SET_SMTP_PASSWORD && ANY_FLAG=true
$SET_SMTP_FROM   && ANY_FLAG=true
$SET_USE_SMTP    && ANY_FLAG=true
$DISABLE_SMTP    && ANY_FLAG=true

ask() {
  # ask <prompt> <default>  → respuesta en $REPLY (default si Enter)
  local prompt="$1" default="$2"
  if [ -n "$default" ]; then
    echo -ne "${BOLD}  $prompt${NC} [${CYAN}${default}${NC}]: "
  else
    echo -ne "${BOLD}  $prompt${NC}: "
  fi
  read -r input
  REPLY="${input:-$default}"
}

ask_pass() {
  local prompt="$1"
  echo -ne "${BOLD}  $prompt${NC} (Enter para mantener actual): "
  read -rs input
  echo ""
  REPLY="$input"
}

if [ "$ANY_FLAG" = false ] && [ "$NON_INTERACTIVE" = false ]; then
  echo ""
  echo -e "  ${BOLD}¿Qué quieres cambiar?${NC}"
  echo "    1) Host / URL pública"
  echo "    2) Configuración SMTP"
  echo "    3) Ambos"
  echo "    4) Salir sin cambios"
  echo -ne "${BOLD}  Elige [1-4]: ${NC}"
  read -r menu

  case "$menu" in
    1|3)
      header "Cambio de host"
      echo "  La URL debe traer scheme: http://...  o  https://..."
      ask "URL pública (vacío = mantener)" "$CUR_HOST"
      if [ "$REPLY" != "$CUR_HOST" ]; then
        _validate_url_scheme "$REPLY" "URL pública"
        NEW_HOST="$REPLY"
      fi
      ;;
  esac

  case "$menu" in
    2|3)
      header "Cambio SMTP"
      ask "USE_SMTP (true/false)" "$CUR_USE_SMTP"
      NEW_USE_SMTP="$REPLY"; SET_USE_SMTP=true
      ask "SMTP_HOST" "$CUR_SMTP_HOST"
      NEW_SMTP_HOST="$REPLY"; SET_SMTP_HOST=true
      ask "SMTP_PORT (587 STARTTLS / 465 SSL)" "${CUR_SMTP_PORT:-587}"
      NEW_SMTP_PORT="$REPLY"; SET_SMTP_PORT=true
      ask "SMTP_SECURE (true solo para 465)" "${CUR_SMTP_SECURE:-false}"
      NEW_SMTP_SECURE="$REPLY"; SET_SMTP_SECURE=true
      ask "SMTP_USER" "$CUR_SMTP_USER"
      NEW_SMTP_USER="$REPLY"; SET_SMTP_USER=true
      ask_pass "SMTP_PASSWORD"
      if [ -n "$REPLY" ]; then
        NEW_SMTP_PASSWORD="$REPLY"; SET_SMTP_PASSWORD=true
      fi
      ask "SMTP_FROM_EMAIL" "${CUR_SMTP_FROM:-$NEW_SMTP_USER}"
      NEW_SMTP_FROM="$REPLY"; SET_SMTP_FROM=true
      ;;
  esac

  case "$menu" in
    4) info "Sin cambios."; exit 0 ;;
  esac
fi

# ── Aplicar --disable-smtp como atajo ─────────────────────────────
if $DISABLE_SMTP; then
  NEW_USE_SMTP="false"; SET_USE_SMTP=true
  NEW_SMTP_HOST=""; SET_SMTP_HOST=true
  NEW_SMTP_USER=""; SET_SMTP_USER=true
  NEW_SMTP_PASSWORD=""; SET_SMTP_PASSWORD=true
  NEW_SMTP_FROM=""; SET_SMTP_FROM=true
fi

# ── Detectar qué cambia realmente ────────────────────────────────
HOST_CHANGED=false
SMTP_CHANGED=false

if [ -n "$NEW_HOST" ] && [ "$NEW_HOST" != "$CUR_HOST" ]; then
  HOST_CHANGED=true
fi
if [ -n "$NEW_SERVER_URL_OVERRIDE" ] && [ "$NEW_SERVER_URL_OVERRIDE" != "$CUR_SERVER_URL" ]; then
  HOST_CHANGED=true
fi
if [ -n "$NEW_PAGE_URL_OVERRIDE" ] && [ "$NEW_PAGE_URL_OVERRIDE" != "$CUR_PAGE_URL" ]; then
  HOST_CHANGED=true
fi

check_smtp_change() {
  local cur="$1" new="$2" set="$3"
  if [ "$set" = true ] && [ "$new" != "$cur" ]; then
    SMTP_CHANGED=true
  fi
}
check_smtp_change "$CUR_USE_SMTP"      "$NEW_USE_SMTP"      "$SET_USE_SMTP"
check_smtp_change "$CUR_SMTP_HOST"     "$NEW_SMTP_HOST"     "$SET_SMTP_HOST"
check_smtp_change "$CUR_SMTP_PORT"     "$NEW_SMTP_PORT"     "$SET_SMTP_PORT"
check_smtp_change "$CUR_SMTP_SECURE"   "$NEW_SMTP_SECURE"   "$SET_SMTP_SECURE"
check_smtp_change "$CUR_SMTP_USER"     "$NEW_SMTP_USER"     "$SET_SMTP_USER"
check_smtp_change "$CUR_SMTP_PASSWORD" "$NEW_SMTP_PASSWORD" "$SET_SMTP_PASSWORD"
check_smtp_change "$CUR_SMTP_FROM"     "$NEW_SMTP_FROM"     "$SET_SMTP_FROM"

if [ "$HOST_CHANGED" = false ] && [ "$SMTP_CHANGED" = false ]; then
  if $FORCE_REBUILD; then
    info "Sin cambios en archivos — pero --force-rebuild solicitado, se reconstruirá."
  else
    warn "Ningún cambio detectado — saliendo."
    info "Usa --force-rebuild para reconstruir aunque no haya cambios."
    exit 0
  fi
fi

# ── Construir URLs nuevas (si cambia host) ───────────────────────
# Precedencia:
#   1) --server-url / --page-url (overrides directos) — ganan siempre
#   2) --nginx <url>             — usa la URL tal cual, sin puertos
#   3) --host <url>              — agrega los puertos del .env (modo directo)
NEW_SERVER_URL="$CUR_SERVER_URL"
NEW_PAGE_URL="$CUR_PAGE_URL"
NEW_FE_RESET_URL="$CUR_FE_RESET_URL"

if [ -n "$NEW_HOST" ] || [ -n "$NEW_SERVER_URL_OVERRIDE" ] || [ -n "$NEW_PAGE_URL_OVERRIDE" ]; then
  if [ -n "$NEW_HOST" ]; then
    if $NGINX_MODE; then
      # Modo nginx: usar la URL tal cual, sin agregar puertos.
      NEW_SERVER_URL="$NEW_HOST"
      NEW_PAGE_URL="$NEW_HOST"
    elif echo "$NEW_HOST" | grep -qE ':[0-9]+$'; then
      # Host con puerto explícito → usar tal cual para ambas URLs.
      NEW_SERVER_URL="$NEW_HOST"
      NEW_PAGE_URL="$NEW_HOST"
    else
      # Modo directo: construir según puertos del .env.
      if [ "$CUR_BACKEND_PORT" = "80" ] || [ "$CUR_BACKEND_PORT" = "443" ]; then
        NEW_SERVER_URL="$NEW_HOST"
      else
        NEW_SERVER_URL="${NEW_HOST}:${CUR_BACKEND_PORT}"
      fi
      if [ "$CUR_FRONTEND_PORT" = "80" ] || [ "$CUR_FRONTEND_PORT" = "443" ]; then
        NEW_PAGE_URL="$NEW_HOST"
      else
        NEW_PAGE_URL="${NEW_HOST}:${CUR_FRONTEND_PORT}"
      fi
    fi
  fi

  # Overrides directos: ganan sobre cualquier construcción anterior.
  [ -n "$NEW_SERVER_URL_OVERRIDE" ] && NEW_SERVER_URL="$NEW_SERVER_URL_OVERRIDE"
  [ -n "$NEW_PAGE_URL_OVERRIDE" ]   && NEW_PAGE_URL="$NEW_PAGE_URL_OVERRIDE"

  # FE_RESET_PASSWORD_URL siempre apunta a la página pública.
  NEW_FE_RESET_URL="$NEW_PAGE_URL"
  # Quitar trailing slash si lo tiene (para que no aparezca https://x// en correos)
  NEW_FE_RESET_URL="${NEW_FE_RESET_URL%/}"
fi

# ── Mostrar diff y confirmar ─────────────────────────────────────
header "Cambios a aplicar"

if $HOST_CHANGED; then
  echo -e "  ${BOLD}Host${NC}"
  echo "    serverUrl:  $CUR_SERVER_URL  →  $NEW_SERVER_URL"
  echo "    pageUrl:    $CUR_PAGE_URL  →  $NEW_PAGE_URL"
  echo "    FE_RESET_PASSWORD_URL: $CUR_FE_RESET_URL  →  $NEW_FE_RESET_URL"
fi

if $SMTP_CHANGED; then
  echo -e "  ${BOLD}SMTP${NC}"
  $SET_USE_SMTP      && [ "$NEW_USE_SMTP"      != "$CUR_USE_SMTP" ]      && echo "    USE_SMTP:        $CUR_USE_SMTP  →  $NEW_USE_SMTP"
  $SET_SMTP_HOST     && [ "$NEW_SMTP_HOST"     != "$CUR_SMTP_HOST" ]     && echo "    SMTP_HOST:       $CUR_SMTP_HOST  →  $NEW_SMTP_HOST"
  $SET_SMTP_PORT     && [ "$NEW_SMTP_PORT"     != "$CUR_SMTP_PORT" ]     && echo "    SMTP_PORT:       $CUR_SMTP_PORT  →  $NEW_SMTP_PORT"
  $SET_SMTP_SECURE   && [ "$NEW_SMTP_SECURE"   != "$CUR_SMTP_SECURE" ]   && echo "    SMTP_SECURE:     $CUR_SMTP_SECURE  →  $NEW_SMTP_SECURE"
  $SET_SMTP_USER     && [ "$NEW_SMTP_USER"     != "$CUR_SMTP_USER" ]     && echo "    SMTP_USER:       $CUR_SMTP_USER  →  $NEW_SMTP_USER"
  $SET_SMTP_PASSWORD && [ "$NEW_SMTP_PASSWORD" != "$CUR_SMTP_PASSWORD" ] && echo "    SMTP_PASSWORD:   (cambia)"
  $SET_SMTP_FROM     && [ "$NEW_SMTP_FROM"     != "$CUR_SMTP_FROM" ]     && echo "    SMTP_FROM_EMAIL: $CUR_SMTP_FROM  →  $NEW_SMTP_FROM"
fi

echo ""
echo -e "  ${BOLD}Acciones tras cambios:${NC}"
{ $HOST_CHANGED || $FORCE_REBUILD; } && echo "    • Rebuild de la imagen webapp (URL en bundle Angular)"
{ $HOST_CHANGED || $FORCE_REBUILD; } && echo "    • Force-recreate de webapp"
{ $HOST_CHANGED || $SMTP_CHANGED || $FORCE_REBUILD; } && echo "    • Force-recreate de app (relee .env)"
$FORCE_REBUILD && [ "$HOST_CHANGED" = false ] && [ "$SMTP_CHANGED" = false ] \
  && echo "      [--force-rebuild] — sin cambios en archivos, solo se reconstruyen contenedores"
$NO_RESTART && echo "    • [--no-restart] — no se reiniciarán contenedores"

if [ "$DRY_RUN" = true ]; then
  info "[--dry-run] No se aplicaron cambios."
  exit 0
fi

if [ "$NON_INTERACTIVE" = false ]; then
  echo ""
  echo -ne "${BOLD}¿Aplicar estos cambios? [s/N] ${NC}"
  read -r confirm
  case "$confirm" in
    s|S|y|Y|si|Si|SI|yes|YES) ;;
    *) info "Cancelado."; exit 0 ;;
  esac
fi

# ── Aplicar cambios ──────────────────────────────────────────────
header "Aplicando cambios"

if $HOST_CHANGED; then
  bak=$(_backup "$FRONTEND_ENV_TS")
  sed -i \
    "s|serverUrl: '.*'|serverUrl: '${NEW_SERVER_URL}'|; \
     s|pageUrl: '.*'|pageUrl: '${NEW_PAGE_URL}/'|" \
    "$FRONTEND_ENV_TS"
  success "environment.prod.ts → backup en $(basename "$bak")"

  bak=$(_backup "$BACKEND_ENV")
  _set_env "$BACKEND_ENV" FE_RESET_PASSWORD_URL "$NEW_FE_RESET_URL"
  success "backend/.env (FE_RESET_PASSWORD_URL) → backup en $(basename "$bak")"
fi

if $SMTP_CHANGED; then
  # Solo hacer un backup por ejecución de SMTP
  if ! $HOST_CHANGED; then
    bak=$(_backup "$BACKEND_ENV")
    success "backend/.env → backup en $(basename "$bak")"
  fi

  $SET_USE_SMTP      && _set_env "$BACKEND_ENV" USE_SMTP            "$NEW_USE_SMTP"
  $SET_SMTP_HOST     && _set_env "$BACKEND_ENV" SMTP_HOST           "$NEW_SMTP_HOST"
  $SET_SMTP_PORT     && _set_env "$BACKEND_ENV" SMTP_PORT           "$NEW_SMTP_PORT"
  $SET_SMTP_SECURE   && _set_env "$BACKEND_ENV" SMTP_SECURE         "$NEW_SMTP_SECURE"
  $SET_SMTP_USER     && _set_env "$BACKEND_ENV" SMTP_USER           "$NEW_SMTP_USER"
  $SET_SMTP_PASSWORD && _set_env "$BACKEND_ENV" SMTP_PASSWORD       "$NEW_SMTP_PASSWORD"
  $SET_SMTP_FROM     && _set_env "$BACKEND_ENV" SMTP_FROM_EMAIL     "$NEW_SMTP_FROM"
  success "Variables SMTP actualizadas"
fi

# ── Restart inteligente ──────────────────────────────────────────
NEED_REBUILD_WEBAPP=false
NEED_RECREATE_APP=false
{ $HOST_CHANGED || $FORCE_REBUILD; }                  && NEED_REBUILD_WEBAPP=true
{ $HOST_CHANGED || $SMTP_CHANGED || $FORCE_REBUILD; } && NEED_RECREATE_APP=true

if [ "$NO_RESTART" = true ]; then
  warn "[--no-restart] — los cambios no surten efecto hasta que rearmes los contenedores manualmente:"
  if $NEED_REBUILD_WEBAPP; then
    echo "    sudo docker compose -p $INSTANCE_NAME up --build -d --force-recreate webapp app"
  elif $NEED_RECREATE_APP; then
    echo "    sudo docker compose -p $INSTANCE_NAME up -d --force-recreate app"
  fi
  exit 0
fi

header "Reiniciando contenedores"
cd "$INSTANCE_DIR"

if $NEED_REBUILD_WEBAPP; then
  info "Rebuild de webapp (Angular bakea la URL al compilar)..."
  sudo docker compose -p "$INSTANCE_NAME" up --build -d --force-recreate webapp
fi

if $NEED_RECREATE_APP; then
  info "Force-recreate de app para que relea el .env..."
  sudo docker compose -p "$INSTANCE_NAME" up -d --force-recreate app
fi

echo ""
echo -e "${BOLD}${GREEN}═══════════════════════════════════════════════════${NC}"
echo -e "${BOLD}${GREEN}   Cambios aplicados${NC}"
echo -e "${BOLD}${GREEN}═══════════════════════════════════════════════════${NC}"
echo ""
echo "  Verifica:"
echo "    sudo docker compose -p $INSTANCE_NAME ps"
{ $HOST_CHANGED || $FORCE_REBUILD; } && echo "    curl -sI ${NEW_PAGE_URL%/} | head -3"
$SMTP_CHANGED && echo "    sudo docker compose -p $INSTANCE_NAME logs app | grep -i smtp"
echo ""
echo "  Backups quedaron en ${INSTANCE_DIR}/SistemaDeclaraciones_*/.env.bak.*"
echo "  y ${INSTANCE_DIR}/SistemaDeclaraciones_frontend/src/.../environment.prod.ts.bak.*"
echo ""
