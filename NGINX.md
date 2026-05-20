# Producción: nginx + SSL para SistemaDeclaraciones

Guía para exponer la plataforma a Internet con HTTPS usando nginx como reverse proxy. Los servicios internos (Angular + GraphQL Node) no manejan TLS — nginx termina la conexión SSL y los proxiéa por HTTP local.

---

## Índice

- [Arquitectura recomendada](#arquitectura-recomendada)
- [Pre-requisitos](#pre-requisitos)
- [Decisión clave: serverUrl del frontend](#decisión-clave-serverurl-del-frontend)
- [Pasos de despliegue](#pasos-de-despliegue)
- [Configuración nginx (una instancia)](#configuración-nginx-una-instancia)
- [Configuración nginx (multi-instancia con subdominios)](#configuración-nginx-multi-instancia-con-subdominios)
- [Hardening adicional](#hardening-adicional)
- [Verificación post-deploy](#verificación-post-deploy)
- [Troubleshooting](#troubleshooting)

---

## Arquitectura recomendada

```
                    ┌──────────────────────────────┐
Internet ─HTTPS─►   │  nginx (host, puertos 80/443)│
                    │  • SSL termination            │
                    │  • HTTP→HTTPS redirect        │
                    │  • Reverse proxy              │
                    └──┬──────────────────────┬────┘
                       │                      │
                       │ http://127.0.0.1:8080│ http://127.0.0.1:3000
                       ▼                      ▼
                  ┌─────────┐            ┌─────────┐
                  │ webapp  │            │   app   │
                  │ Angular │            │ GraphQL │
                  └─────────┘            └─────────┘
```

**Decisión arquitectónica importante:**

> Servir frontend y backend bajo el **mismo dominio** y diferenciar por path (`/` para Angular, `/graphql` para el backend). Esto evita CORS, simplifica el certificado SSL, y permite que el frontend use URLs relativas si se reconstruye así.

**Por qué nginx en el host y no en un contenedor:**

- Más simple de combinar con `certbot` para Let's Encrypt
- No depende de `docker-compose` para estar arriba (clave si hay que reiniciar contenedores)
- Renovación automática de certificados sin tocar el setup Docker

---

## Pre-requisitos

| Requisito | Cómo verificarlo |
|-----------|------------------|
| Dominio público apuntando al servidor | `dig +short declaraciones.tudominio.gob.mx` debe devolver tu IP |
| Puertos 80 y 443 abiertos en el firewall del proveedor cloud | `nc -zv <ip> 80 443` desde otra máquina |
| Acceso `sudo` en el servidor | `sudo -v` |
| Frontend y backend funcionando en `localhost:8080` y `localhost:3000` | `curl http://localhost:3000/graphql -X POST -H 'content-type: application/json' -d '{"query":"{__typename}"}'` |
| UFW o equivalente permite 80/443 | `sudo ufw status` |

---

## Decisión clave: `serverUrl` del frontend

El frontend Angular **embebe la URL del backend en tiempo de build** en `src/environments/environment.prod.ts`:

```ts
export const environment = {
  production: true,
  serverUrl: 'http://localhost:3000',  // ← se compila dentro del bundle JS
  pageUrl:   'http://localhost:8080/',
};
```

Esa URL viaja al navegador del usuario. Si dice `http://`, el navegador bloqueará las llamadas desde una página HTTPS (**Mixed Content**). Tres opciones:

### A) URL absoluta con HTTPS al mismo dominio (recomendado)

```ts
serverUrl: 'https://declaraciones.tudominio.gob.mx',
pageUrl:   'https://declaraciones.tudominio.gob.mx/',
```

El asistente ya hace esto si en la sección 1 del wizard contestas:
- Protocolo: `https`
- Dominio: `declaraciones.tudominio.gob.mx`
- Puerto frontend/backend: `443`

> Si estos valores son los que ya pusiste durante el wizard, el frontend ya está construido correctamente y no hace falta rebuild.

### B) URL relativa (mismo origen)

Editar `environment.prod.ts` y poner `serverUrl: ''`. Las llamadas a `/graphql` van al mismo origen automáticamente. Ventaja: portable entre dominios sin rebuild. Desventaja: hay que rebuild una vez para aplicar el cambio.

### C) Backend en subdominio separado

```ts
serverUrl: 'https://api-declaraciones.tudominio.gob.mx',
```

Funciona pero requiere certificado SSL para el subdominio extra y configurar CORS.

> **Para esta guía asumo opción A — todo bajo el mismo dominio.**

---

## Pasos de despliegue

### 1. Configurar el wizard con datos de producción

```bash
bash limpiar.sh   # si vienes de pruebas locales
bash asistente.sh
```

En la sección 1, contesta:
- Protocolo: `https`
- Dominio o IP pública: `declaraciones.tudominio.gob.mx`
- Puerto backend: `443` (el wizard omitirá el número en la URL)
- Puerto frontend: `443`

Esto deja:
- `serverUrl` del frontend = `https://declaraciones.tudominio.gob.mx`
- `FE_RESET_PASSWORD_URL` del backend = `https://declaraciones.tudominio.gob.mx`

### 2. Mantener los puertos del **contenedor** internos

Aunque el "puerto público" sea 443, los **puertos expuestos del contenedor** siguen siendo `8080` y `3000` (los `BACKEND_PORT` y `FRONTEND_PORT` reales del `.env`). Pueden quedar como están — son los que nginx va a proxiear. Por seguridad, **bindea sólo a localhost** editando el `docker-compose.yml`:

```yaml
app:
  ports:
    - "127.0.0.1:3000:3000"   # antes: "3000:3000"
webapp:
  ports:
    - "127.0.0.1:8080:80"     # antes: "8080:80"
```

Tras editarlo:

```bash
sudo docker compose up -d --force-recreate app webapp
```

Esto hace que **ningún puerto interno sea accesible desde Internet** — sólo el 80/443 de nginx. Crítico para no exponer el backend sin TLS.

### 3. Instalar nginx + certbot

```bash
sudo apt update
sudo apt install -y nginx python3-certbot-nginx
```

### 4. Configurar nginx (config en la siguiente sección)

```bash
sudo tee /etc/nginx/sites-available/declaraciones > /dev/null < <(cat NGINX-config-snippet.conf)
sudo ln -sf /etc/nginx/sites-available/declaraciones /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t        # validar sintaxis
sudo systemctl reload nginx
```

### 5. Obtener certificado Let's Encrypt

```bash
sudo certbot --nginx -d declaraciones.tudominio.gob.mx
```

Certbot detecta el bloque `server_name` de nginx, obtiene el certificado, modifica la config para servir HTTPS, y configura un `cron` para renovar automáticamente.

### 6. Verificar

```bash
curl -I https://declaraciones.tudominio.gob.mx
# Debe devolver HTTP/2 200 con header strict-transport-security
```

Y desde un navegador, visita la URL — el candado debe aparecer cerrado.

---

## Configuración nginx (una instancia)

Archivo: `/etc/nginx/sites-available/declaraciones`

```nginx
# ── Redirigir todo HTTP a HTTPS ────────────────────────────────────
server {
    listen 80;
    listen [::]:80;
    server_name declaraciones.tudominio.gob.mx;

    # certbot necesita servir su challenge en HTTP — dejarlo pasar
    location /.well-known/acme-challenge/ {
        root /var/www/html;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

# ── HTTPS principal ────────────────────────────────────────────────
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name declaraciones.tudominio.gob.mx;

    # Certbot llenará estas líneas automáticamente al ejecutarlo:
    # ssl_certificate     /etc/letsencrypt/live/declaraciones.../fullchain.pem;
    # ssl_certificate_key /etc/letsencrypt/live/declaraciones.../privkey.pem;

    # ── SSL hardening ───────────────────────────────────────────────
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers on;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
    ssl_session_tickets off;

    # HSTS — el navegador recordará usar HTTPS por 6 meses
    add_header Strict-Transport-Security "max-age=15768000; includeSubDomains" always;

    # ── Tamaño de uploads ───────────────────────────────────────────
    # Las declaraciones pueden llevar anexos. Subir si es necesario.
    client_max_body_size 50M;

    # ── Timeouts (la generación de PDFs puede tardar) ──────────────
    proxy_connect_timeout 60s;
    proxy_send_timeout    300s;
    proxy_read_timeout    300s;

    # ── Headers para upstreams ──────────────────────────────────────
    proxy_set_header Host              $host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Host  $host;

    # ── Backend GraphQL ─────────────────────────────────────────────
    # Esta location debe ir ANTES del location / para tener prioridad.
    location /graphql {
        proxy_pass http://127.0.0.1:3000;

        # Por si Apollo/GraphQL usa subscriptions sobre WebSocket
        proxy_http_version 1.1;
        proxy_set_header   Upgrade    $http_upgrade;
        proxy_set_header   Connection "upgrade";
    }

    # ── Frontend Angular (SPA) ──────────────────────────────────────
    location / {
        proxy_pass http://127.0.0.1:8080;

        # No cachear index.html (Angular renombra los assets con hash)
        proxy_set_header Cache-Control "no-cache";
    }

    # ── Logging ─────────────────────────────────────────────────────
    access_log /var/log/nginx/declaraciones.access.log;
    error_log  /var/log/nginx/declaraciones.error.log;
}
```

---

## Configuración nginx (multi-instancia con subdominios)

Si despliegas varias instituciones en el mismo servidor (`pnd_tlacotepec_v1`, `pnd_tecali_v1`, etc.), hay dos esquemas:

### Esquema A: subdominio por instancia (recomendado)

```
declaraciones-tlacotepec.tudominio.gob.mx → puertos 8081/3010 internos
declaraciones-tecali.tudominio.gob.mx     → puertos 8082/3020 internos
```

Un archivo nginx por institución (mismo template, valores distintos):

```nginx
server {
    listen 443 ssl http2;
    server_name declaraciones-tlacotepec.tudominio.gob.mx;

    ssl_certificate     /etc/letsencrypt/live/declaraciones-tlacotepec.../fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/declaraciones-tlacotepec.../privkey.pem;

    # ... resto idéntico al de una instancia ...

    location /graphql {
        proxy_pass http://127.0.0.1:3010;   # ← BACKEND_PORT de esta instancia
        # ...
    }
    location / {
        proxy_pass http://127.0.0.1:8081;   # ← FRONTEND_PORT de esta instancia
    }
}
```

Y un certificado por subdominio:

```bash
sudo certbot --nginx -d declaraciones-tlacotepec.tudominio.gob.mx
sudo certbot --nginx -d declaraciones-tecali.tudominio.gob.mx
```

O un certificado wildcard si tienes acceso DNS:

```bash
sudo certbot certonly --manual --preferred-challenges dns -d "*.tudominio.gob.mx"
```

### Esquema B: path por instancia (no recomendado)

```
tudominio.gob.mx/tlacotepec  → puertos 8081/3010
tudominio.gob.mx/tecali      → puertos 8082/3020
```

Funciona pero requiere modificar el frontend Angular para que use `<base href="/tlacotepec/">` en `index.html` y reconstruirlo. Más fricción, mejor evítalo a menos que tengas restricciones de DNS.

---

## Hardening adicional

### Limitar acceso por IP (opcional, para entornos administrativos)

```nginx
location /graphql {
    # allow 200.123.45.0/24;     # IPs internas del gobierno
    # deny  all;
    proxy_pass http://127.0.0.1:3000;
    # ...
}
```

### Rate limiting (mitigar abuso del login)

Al inicio del archivo (fuera del `server {}`):

```nginx
limit_req_zone $binary_remote_addr zone=login:10m rate=5r/s;
```

Dentro del `server {}`:

```nginx
location /graphql {
    limit_req zone=login burst=10 nodelay;
    proxy_pass http://127.0.0.1:3000;
    # ...
}
```

### Bloquear paths privados expuestos por error

```nginx
location ~ /\.(env|git|svn) {
    deny all;
    return 404;
}
```

### Compresión gzip (acelera la carga del bundle Angular)

En `/etc/nginx/nginx.conf` (sección `http {}`) o dentro del `server {}`:

```nginx
gzip on;
gzip_vary on;
gzip_proxied any;
gzip_comp_level 6;
gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript;
```

---

## Verificación post-deploy

```bash
# 1. nginx levantado y configurado
sudo nginx -t
sudo systemctl status nginx

# 2. Certificado válido
echo | openssl s_client -connect declaraciones.tudominio.gob.mx:443 -servername declaraciones.tudominio.gob.mx 2>/dev/null \
  | openssl x509 -noout -subject -dates

# 3. Calidad del SSL
curl -s "https://api.ssllabs.com/api/v3/analyze?host=declaraciones.tudominio.gob.mx" | jq .endpoints[0].grade
# (o usa el sitio web ssllabs.com/ssltest/)

# 4. Frontend responde
curl -I https://declaraciones.tudominio.gob.mx

# 5. Backend GraphQL responde
curl -s -X POST https://declaraciones.tudominio.gob.mx/graphql \
  -H 'content-type: application/json' \
  -d '{"query":"{ __typename }"}'

# 6. HTTP redirige a HTTPS
curl -I http://declaraciones.tudominio.gob.mx | grep -i location

# 7. Renovación de certificado funciona (dry run)
sudo certbot renew --dry-run

# 8. Los puertos internos NO son accesibles desde fuera
# Desde otra máquina:
nc -zv <ip-publica> 8080      # debe fallar
nc -zv <ip-publica> 3000      # debe fallar
nc -zv <ip-publica> 443       # debe abrir
```

---

## Troubleshooting

### `Mixed Content` en la consola del navegador

```
Mixed Content: The page at 'https://...' was loaded over HTTPS,
but requested an insecure XMLHttpRequest endpoint 'http://localhost:3000/graphql'.
```

**Causa:** el frontend fue construido con `serverUrl: 'http://localhost:3000'` (build local), no con tu dominio HTTPS.

**Solución:** Edita `SistemaDeclaraciones_frontend/src/environments/environment.prod.ts`:

```ts
serverUrl: 'https://declaraciones.tudominio.gob.mx',
```

Reconstruye:

```bash
sudo docker compose up -d --build webapp
```

O regenera con el wizard usando los datos correctos.

---

### `502 Bad Gateway`

**Causa:** nginx no puede llegar al upstream. Probablemente el contenedor Docker no está arriba o no escucha en `127.0.0.1`.

```bash
sudo docker ps | grep -E 'app|webapp'
ss -tlnp | grep -E ':3000|:8080'
sudo tail -20 /var/log/nginx/declaraciones.error.log
```

Si los contenedores están bindeando a `0.0.0.0` y nginx busca `127.0.0.1`, ambos resuelven al mismo socket — debería funcionar. Si bindean sólo a una IP específica que no es localhost, ajusta `proxy_pass`.

---

### `413 Request Entity Too Large` al subir archivos

```bash
sudo nano /etc/nginx/sites-available/declaraciones
# subir client_max_body_size a 100M o lo que necesites
sudo nginx -t && sudo systemctl reload nginx
```

---

### Reset password manda enlace con `http://`

**Causa:** `FE_RESET_PASSWORD_URL` en `SistemaDeclaraciones_backend/.env` apunta a HTTP.

```bash
sudo sed -i 's|^FE_RESET_PASSWORD_URL=http://.*|FE_RESET_PASSWORD_URL=https://declaraciones.tudominio.gob.mx|' \
  SistemaDeclaraciones_backend/.env

sudo docker compose up -d --force-recreate app
```

---

### CORS error en consola

Sólo aparece si serviste backend y frontend en **dominios distintos**. Si seguiste la guía con un único dominio, no debería pasar.

Si lo necesitas (ej. backend en subdominio), añade en el código del backend (no nginx):

```ts
app.use(cors({
  origin: 'https://declaraciones.tudominio.gob.mx',
  credentials: true,
}));
```

---

### El cliente ve la IP del proxy, no la real, en logs

El backend lee `req.ip` directamente. Para que respete el header `X-Forwarded-For`, en el código del backend Express:

```ts
app.set('trust proxy', 1);
```

Si no quieres tocar el código upstream, ignóralo — los logs están del lado de nginx (`access.log`).

---

### Renovación del certificado falla

```bash
sudo certbot renew --dry-run
sudo journalctl -u certbot.timer
```

Las causas más comunes:
- Bloque `server` HTTP que redirige a HTTPS sin excluir `/.well-known/acme-challenge/` — ver el snippet de la config arriba que lo deja pasar
- Firewall cierra el 80 — ábrelo aunque sólo redirijas

---

### Subscriptions GraphQL no se conectan (WebSocket)

Si la consola dice `WebSocket connection failed`, falta la config de upgrade. La incluí en el snippet del `location /graphql`. Verifica que esté:

```nginx
proxy_http_version 1.1;
proxy_set_header   Upgrade    $http_upgrade;
proxy_set_header   Connection "upgrade";
```

---

## Resumen ejecutivo

1. **Mismo dominio para frontend y backend** — paths separados (`/graphql` vs `/`)
2. **Bindea los puertos del contenedor a `127.0.0.1`** — nadie debe poder llegar al backend HTTP directamente
3. **Configura el wizard con `https` y el dominio real** — para que `serverUrl` y `FE_RESET_PASSWORD_URL` queden bien
4. **Let's Encrypt con certbot** — auto-renovación incluida
5. **`client_max_body_size`** y **timeouts largos** — los PDFs pesan
6. **HSTS** activo — el navegador recordará HTTPS aunque alguien teclee HTTP
7. **WebSocket headers** en `location /graphql` — por si algún día se usan subscriptions
8. **Multi-instancia: subdominio por institución** — un certificado y un bloque `server` por cada una
