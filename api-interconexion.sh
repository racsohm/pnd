#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MONGO_CONTAINER="${MONGO_CONTAINER:-pdnmx-mongo}"
COMPOSE_FILE="$SCRIPT_DIR/docker-compose.seseap.yml"
REMOVE=false

if [[ "${1:-}" == "--remove" ]]; then
  REMOVE=true
elif [[ $# -gt 0 ]]; then
  echo "Uso: $0 [--remove]" >&2
  exit 2
fi

fail() { echo "[ERROR] $*" >&2; exit 1; }
info() { echo "[INFO] $*"; }
warn() { echo "[AVISO] $*" >&2; }

command -v docker >/dev/null 2>&1 || fail "Docker no está instalado."
docker compose version >/dev/null 2>&1 || fail "Falta el plugin docker compose."
[[ -f "$COMPOSE_FILE" ]] || fail "No encuentro docker-compose.seseap.yml."
[[ -f "$SCRIPT_DIR/seseap-api/Dockerfile" ]] || fail "No encuentro el paquete de la API en seseap-api/."

# Descubre la instalación de la carpeta actual y las instalaciones hermanas.
parent_dir="$(dirname "$SCRIPT_DIR")"
declare -a instances=()
if [[ -f "$SCRIPT_DIR/SistemaDeclaraciones_backend/.env" && -f "$SCRIPT_DIR/docker-compose.yml" ]]; then
  instances+=("$SCRIPT_DIR")
fi
for candidate in "$parent_dir"/*; do
  [[ -d "$candidate" ]] || continue
  [[ "$candidate" == "$SCRIPT_DIR" ]] && continue
  [[ -f "$candidate/SistemaDeclaraciones_backend/.env" && -f "$candidate/docker-compose.yml" ]] || continue
  instances+=("$candidate")
done

if ((${#instances[@]} == 0)); then
  fail "No encontré instancias PND configuradas (se requiere SistemaDeclaraciones_backend/.env y docker-compose.yml)."
fi

echo "Instancias PND detectadas:"
for i in "${!instances[@]}"; do
  printf '  %d) %s\n' "$((i + 1))" "$(basename "${instances[$i]}")"
done
if ((${#instances[@]} == 1)); then
  selection=1
  printf 'Seleccionada: %s\n' "$(basename "${instances[0]}")"
else
  read -r -p "Elige una instancia [1]: " selection
  selection="${selection:-1}"
  [[ "$selection" =~ ^[0-9]+$ ]] && ((selection >= 1 && selection <= ${#instances[@]})) || fail "Selección inválida."
fi

INSTANCE_DIR="${instances[$((selection - 1))]}"
INSTANCE_ENV="$INSTANCE_DIR/SistemaDeclaraciones_backend/.env"
INSTANCE_SLUG="$(basename "$INSTANCE_DIR" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9_-]+/-/g; s/^-+|-+$//g')"
[[ -n "$INSTANCE_SLUG" ]] || INSTANCE_SLUG="pnd"
STATE_ENV="$INSTANCE_DIR/.env.seseap"
PROJECT="pndmx-seseap-$INSTANCE_SLUG"

compose() {
  docker compose --project-name "$PROJECT" --env-file "$STATE_ENV" -f "$COMPOSE_FILE" "$@"
}

if $REMOVE; then
  [[ -f "$STATE_ENV" ]] || fail "Esta instancia no tiene configuración SESEAP ($STATE_ENV)."
  compose down
  info "Se retiró el contenedor de la API para $(basename "$INSTANCE_DIR"). La instancia PND y MongoDB permanecen intactos."
  exit 0
fi

command -v curl >/dev/null 2>&1 || fail "curl es necesario para comprobar que la API arranque."

# Lee valores de los .env generados por PND sin ejecutar su contenido como shell.
env_value() {
  local key="$1" file="$2" value
  value="$(awk -v key="$key" 'index($0, key "=") == 1 { sub("^[^=]*=", ""); print; exit }' "$file")"
  value="${value%$'\r'}"
  if [[ "$value" == \"*\" && "$value" == *\" ]]; then value="${value:1:${#value}-2}"; fi
  if [[ "$value" == \'*\' && "$value" == *\' ]]; then value="${value:1:${#value}-2}"; fi
  printf '%s' "$value"
}

mongo_user="$(env_value MONGO_USERNAME "$INSTANCE_ENV")"
mongo_password="$(env_value MONGO_PASSWORD "$INSTANCE_ENV")"
mongo_db="$(env_value MONGO_DB "$INSTANCE_ENV")"
mongo_port="$(env_value MONGO_PORT "$INSTANCE_ENV")"
[[ -n "$mongo_user" ]] || fail "MONGO_USERNAME está vacío o no existe en $INSTANCE_ENV."
[[ -n "$mongo_password" ]] || fail "MONGO_PASSWORD está vacío o no existe en $INSTANCE_ENV."
[[ -n "$mongo_db" ]] || fail "MONGO_DB está vacío o no existe en $INSTANCE_ENV."
mongo_port="${mongo_port:-27017}"
mongo_host="$(env_value MONGO_HOSTNAME "$INSTANCE_ENV")"
mongo_host="${mongo_host:-pdnmx-mongo}"

institution_config="$INSTANCE_DIR/SistemaDeclaraciones_backend/src/data/instituciones.json"
institution_name=""
if [[ -f "$institution_config" ]] && command -v python3 >/dev/null 2>&1; then
  mapfile -t institution_values < <(python3 - "$institution_config" <<'PY'
import json, sys
with open(sys.argv[1], encoding="utf-8") as source:
    data = json.load(source)
if isinstance(data, list):
    data = data[0] if data else {}
name = str(data.get("ente_publico", "")).strip()
print(name.replace("\n", " "))
PY
  )
  institution_name="${institution_values[0]:-}"
fi

ask_value() {
  local prompt="$1" default="$2" input
  read -r -p "$prompt [$default]: " input
  REPLY="${input:-$default}"
  [[ -n "$REPLY" ]] || fail "Este valor no puede quedar vacío: $prompt"
}

state_value() {
  local key="$1" fallback="$2" value=""
  if [[ -f "$STATE_ENV" ]]; then
    value="$(env_value "$key" "$STATE_ENV")"
    value="${value//\$\$/\$}"
  fi
  printf '%s' "${value:-$fallback}"
}

normalize_api_name() {
  if command -v python3 >/dev/null 2>&1; then
    python3 - "$1" <<'PY'
import re, sys, unicodedata
name = re.sub(r"^(?:H\.?\s*)?(?:AYUNTAMIENTO|MUNICIPIO)\s+DE\s+", "", sys.argv[1].strip(), flags=re.I)
name = "".join(c for c in unicodedata.normalize("NFKD", name) if not unicodedata.combining(c))
print(re.sub(r"[^A-Za-z0-9]", "", name).upper())
PY
  else
    printf '%s' "$1" | tr '[:lower:]' '[:upper:]' | sed -E 's/[^A-Z0-9]+//g'
  fi
}

if [[ -n "$institution_name" ]]; then
  ask_value "clientDescription (nombre de la configuración PND)" "$institution_name"
  client_description="$REPLY"
else
  read -r -p "clientDescription (nombre oficial de la institución PND): " client_description
  [[ -n "$client_description" ]] || fail "clientDescription es obligatorio si la configuración PND no tiene instituciones.json."
fi
api_name_default="$(normalize_api_name "$client_description")"
api_name_default="${api_name_default:-$(basename "$INSTANCE_DIR" | tr '[:lower:]' '[:upper:]')}"
ask_value "apiName (derivado del nombre de configuración)" "$api_name_default"; api_name="$REPLY"
api_name_slug="$(printf '%s' "$api_name" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/-/g; s/^-+|-+$//g')"
api_name_slug="${api_name_slug:0:48}"
[[ -n "$api_name_slug" ]] || fail "apiName debe contener letras o números para nombrar el contenedor."
container_name="pndmx-seseap-${INSTANCE_SLUG}-${api_name_slug}"
ask_value "clientId" "$(state_value SESEAP_CLIENT_ID declaraciones_tlacotepec)"; client_id="$REPLY"
ask_value "clientScopeRead" "$(state_value SESEAP_CLIENT_SCOPE_READ tlacotepec)"; client_scope_read="$REPLY"
ask_value "clientScopeWrite" "$(state_value SESEAP_CLIENT_SCOPE_WRITE tlacotepec)"; client_scope_write="$REPLY"
ask_value "mongoUsername (Enter conserva el usuario de PND)" "$mongo_user"; mongo_user="$REPLY"
read -r -s -p "mongoPassword (Enter conserva la contraseña de PND): " requested_password
printf '\n'
mongo_password="${requested_password:-$mongo_password}"
ask_value "mongoHostname" "$mongo_host"; mongo_host="$REPLY"
ask_value "mongoPort" "$mongo_port"; mongo_port="$REPLY"
[[ "$mongo_port" =~ ^[0-9]+$ ]] && ((mongo_port > 0 && mongo_port < 65536)) || fail "mongoPort debe estar entre 1 y 65535."
ask_value "mongoDatabase" "$mongo_db"; mongo_db="$REPLY"

docker inspect "$MONGO_CONTAINER" >/dev/null 2>&1 || fail "No encuentro el Mongo existente '$MONGO_CONTAINER' en este daemon Docker."
mongo_state="$(docker inspect --format '{{.State.Status}}' "$MONGO_CONTAINER")"
[[ "$mongo_state" == running ]] || fail "Mongo '$MONGO_CONTAINER' no está ejecutándose (estado: $mongo_state)."

api_port="${SESEAP_API_PORT:-9000}"
while true; do
  read -r -p "deploymentPort (puerto local detrás de Nginx) [${api_port}]: " requested_port
  api_port="${requested_port:-$api_port}"
  [[ "$api_port" =~ ^[0-9]+$ ]] && ((api_port > 0 && api_port < 65536)) || { warn "Indica un puerto entre 1 y 65535."; continue; }
  port_busy=false
  if command -v ss >/dev/null 2>&1 && ss -ltnH | awk '{print $4}' | grep -Eq "(:|\\])${api_port}$"; then
    # Permite conservar el puerto ocupado por la API de esta instancia aunque
    # el nombre de contenedor cambie por el nuevo apiName.
    if ! docker ps --filter "label=com.docker.compose.project=$PROJECT" --format '{{.Names}}' | grep -q .; then
      port_busy=true
    fi
  fi
  if $port_busy; then
    warn "El puerto $api_port ya está ocupado."
    api_port="9000"
  else
    break
  fi
done

read -r -p "Dominio público que usará Nginx para la API [ _ ]: " nginx_host
nginx_host="${nginx_host:-_}"
[[ "$nginx_host" =~ ^[A-Za-z0-9*._-]+$ ]] || fail "El nombre de host contiene caracteres no permitidos."

# Preferir la red compartida PND; si Mongo solo está en bridge, se conectará
# a una red de usuario nueva mediante alias, sin tocar su almacenamiento.
mapfile -t networks < <(docker inspect --format '{{range $name, $cfg := .NetworkSettings.Networks}}{{if ne $name "bridge"}}{{$name}}{{"\n"}}{{end}}{{end}}' "$MONGO_CONTAINER" | sed '/^[[:space:]]*$/d')
network=""
for candidate in "${networks[@]}"; do
  [[ -n "$candidate" ]] || continue
  if [[ "$candidate" == *pndmx* ]]; then network="$candidate"; break; fi
done
if [[ -z "$network" && ${#networks[@]} -eq 1 ]]; then network="${networks[0]}"; fi
if [[ -z "$network" && ${#networks[@]} -gt 1 ]]; then
  echo "Redes disponibles para Mongo:"
  for i in "${!networks[@]}"; do printf '  %d) %s\n' "$((i + 1))" "${networks[$i]}"; done
  read -r -p "Elige la red [1]: " network_choice
  network_choice="${network_choice:-1}"
  [[ "$network_choice" =~ ^[0-9]+$ ]] && ((network_choice >= 1 && network_choice <= ${#networks[@]})) || fail "Selección de red inválida."
  network="${networks[$((network_choice - 1))]}"
fi
if [[ -z "$network" ]]; then
  network="pndmx_seseap_network"
  docker network inspect "$network" >/dev/null 2>&1 || docker network create "$network" >/dev/null
  if ! docker network inspect "$network" --format '{{range .Containers}}{{.Name}}{{"\n"}}{{end}}' | grep -Fxq "$MONGO_CONTAINER"; then
    docker network connect --alias pdnmx-mongo "$network" "$MONGO_CONTAINER"
  fi
  info "Mongo existente conectado a la red '$network' mediante el alias pdnmx-mongo."
fi

vpn="${SESEAP_VPN:-false}"

# Compose interpola '$'; duplicarlo en este archivo conserva el carácter literal.
escape_compose() { printf '%s' "$1" | sed 's/\$/$$/g'; }
umask 077
{
  printf 'SESEAP_API_PORT=%s\n' "$api_port"
  printf 'MONGO_DOCKER_NETWORK=%s\n' "$(escape_compose "$network")"
  printf 'MONGO_HOSTNAME=%s\nMONGO_PORT=%s\n' "$(escape_compose "$mongo_host")" "$mongo_port"
  printf 'MONGO_DB=%s\n' "$(escape_compose "$mongo_db")"
  printf 'MONGO_USERNAME=%s\n' "$(escape_compose "$mongo_user")"
  printf 'MONGO_PASSWORD=%s\n' "$(escape_compose "$mongo_password")"
  printf 'SESEAP_API_NAME=%s\n' "$(escape_compose "$api_name")"
  printf 'SESEAP_CLIENT_ID=%s\n' "$(escape_compose "$client_id")"
  printf 'SESEAP_CLIENT_SCOPE_READ=%s\n' "$(escape_compose "$client_scope_read")"
  printf 'SESEAP_CLIENT_SCOPE_WRITE=%s\n' "$(escape_compose "$client_scope_write")"
  printf 'SESEAP_CLIENT_DESCRIPTION=%s\n' "$(escape_compose "$client_description")"
  printf 'SESEAP_CONTAINER_NAME=%s\nSESEAP_VPN=%s\n' "$container_name" "$vpn"
} > "$STATE_ENV"
chmod 600 "$STATE_ENV"

# Deja un virtual host listo para Nginx instalado en el host. La API queda
# publicada en todas las interfaces y también puede atenderse directamente.
NGINX_FILE="$INSTANCE_DIR/nginx-seseap-$INSTANCE_SLUG.conf"
cat > "$NGINX_FILE" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $nginx_host;

    location / {
        proxy_pass http://127.0.0.1:$api_port;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 120s;
    }
}
EOF

info "Instancia: $(basename "$INSTANCE_DIR") | Base Mongo: $mongo_db | Red: $network | Puerto API: $api_port"
warn "La configuración Mongo se guarda en $STATE_ENV con permisos 600; no se muestra la contraseña."
mongo_version="$(docker exec "$MONGO_CONTAINER" sh -c 'mongosh --quiet -u "$MONGODB_INITDB_ROOT_USERNAME" -p "$MONGODB_INITDB_ROOT_PASSWORD" --authenticationDatabase admin --eval "db.version()"' 2>/dev/null || true)"
if [[ -n "$mongo_version" ]]; then
  info "Versión del servidor MongoDB: $mongo_version"
else
  warn "No pude leer la versión del servidor MongoDB."
fi
warn "La API incluye MongoDB.Driver 2.13.2; MongoDB Server 8.1 y posterior es incompatible con este driver. Swagger puede responder aunque las operaciones contra Mongo fallen."
info "Construyendo e iniciando solo la API SESEAP..."
compose up --build -d

ready=false
for _ in $(seq 1 45); do
  status_code="$(curl -sS --max-time 2 -o /dev/null -w '%{http_code}' "http://127.0.0.1:${api_port}/" 2>/dev/null || true)"
  if [[ "$status_code" =~ ^[1-5][0-9][0-9]$ ]]; then
    ready=true
    break
  fi
  sleep 1
done
compose ps
if $ready; then
  swagger_status="$(curl -sS --max-time 3 -o /dev/null -w '%{http_code}' "http://127.0.0.1:${api_port}/swagger/v1/swagger.json" 2>/dev/null || true)"
  info "El proceso API responde en http://127.0.0.1:${api_port}; Docker publica el puerto en todas las interfaces (0.0.0.0)."
  if [[ "$swagger_status" == 200 ]]; then
    info "Swagger responde en http://127.0.0.1:${api_port}/swagger."
  else
    warn "La API está activa, pero Swagger respondió HTTP ${swagger_status:-sin respuesta}. Revisa apiName/scopes y los logs del contenedor."
  fi
  info "Nginx puede publicarla en http://${nginx_host}/ usando $NGINX_FILE."
  info "Para activar el virtual host en Nginx host: copia el archivo a sites-available, enlázalo en sites-enabled, valida con nginx -t y recarga Nginx."
  info "Para permitir acceso directo mediante UFW, ejecuta: bash $SCRIPT_DIR/ufw-puertos.sh $api_port"
  info "También puedes publicar la API mediante Nginx usando el virtual host generado en $NGINX_FILE."
else
  warn "El contenedor inició, pero la API no respondió. Revisa los logs:"
  compose logs --tail 60 seseap-api || true
  exit 1
fi
