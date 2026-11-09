#!/usr/bin/env bash
set -euo pipefail

fail() { echo "[ERROR] $*" >&2; exit 1; }
info() { echo "[INFO] $*"; }
warn() { echo "[AVISO] $*" >&2; }

usage() {
  cat <<'EOF'
Abre puertos TCP en UFW para publicar servicios del host.

Uso:
  bash ufw-puertos.sh                 # solicita los puertos (por defecto 80 443)
  bash ufw-puertos.sh --nginx         # permite HTTP y pregunta si también HTTPS
  bash ufw-puertos.sh <puerto> [...]  # permite los puertos indicados

Ejemplos:
  bash ufw-puertos.sh --nginx
  bash ufw-puertos.sh 80 443
  bash ufw-puertos.sh 8080 3010

No habilita UFW ni abre automáticamente los puertos internos de la API SESEAP.
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi

command -v ufw >/dev/null 2>&1 || fail "UFW no está instalado. Instálalo primero (por ejemplo: sudo apt install ufw)."

declare -a ports=()
if [[ "${1:-}" == "--nginx" ]]; then
  [[ $# -eq 1 ]] || fail "--nginx no se combina con otros argumentos."
  ports=(80)
  read -r -p "¿Permitir también HTTPS (443/tcp)? [S/n]: " allow_https
  if [[ ! "$allow_https" =~ ^[Nn]$ ]]; then ports+=(443); fi
elif (($# > 0)); then
  ports=("$@")
else
  read -r -p "Puertos TCP a permitir, separados por espacios [80 443]: " port_line
  read -r -a ports <<< "${port_line:-80 443}"
fi

((${#ports[@]} > 0)) || fail "No indicaste puertos."
declare -A seen=()
for port in "${ports[@]}"; do
  [[ "$port" =~ ^[0-9]{1,5}$ ]] || fail "Puerto inválido: '$port'. Usa números entre 1 y 65535."
  ((10#$port >= 1 && 10#$port <= 65535)) || fail "Puerto fuera de rango: $port."
  port=$((10#$port))
  [[ "$port" != 27017 && "$port" != 3001 ]] || fail "No se abrirá el puerto interno $port (MongoDB/reportes)."
  seen["$port"]=1
done
ports=("${!seen[@]}")
mapfile -t ports < <(printf '%s\n' "${ports[@]}" | sort -n)

echo "Se agregarán estas reglas de entrada TCP a UFW: ${ports[*]}"
read -r -p "¿Continuar? [s/N]: " confirm
[[ "$confirm" =~ ^[Ss]$ ]] || { info "Sin cambios."; exit 0; }

if ((EUID == 0)); then
  UFW=(ufw)
else
  command -v sudo >/dev/null 2>&1 || fail "Ejecuta como root o instala sudo."
  UFW=(sudo ufw)
fi

for port in "${ports[@]}"; do
  "${UFW[@]}" allow "${port}/tcp"
done

status="$("${UFW[@]}" status 2>&1 || true)"
if grep -qi '^Status: inactive' <<< "$status"; then
  warn "Las reglas quedaron agregadas, pero UFW está inactivo. No lo habilité automáticamente."
  warn "Habilítalo después de revisar que SSH esté permitido para evitar perder acceso remoto."
else
  info "Reglas UFW aplicadas."
fi
