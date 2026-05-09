# SistemaDeclaraciones PDNMX

Sistema de Declaración Patrimonial y de Intereses — despliegue local con Docker.

Basado en el [repositorio oficial PDNMX](https://github.com/PDNMX/SistemaDeclaraciones) y el [Manual de Configuración oficial](https://docs.google.com/document/d/1HAOwKuZcrTzISx5BKaFIzJQ__-puTPbiLRmR9XACZow).

---

## Contenido

- [Arquitectura](#arquitectura)
- [Requisitos](#requisitos)
- [Instalación rápida](#instalación-rápida)
- [Scripts disponibles](#scripts-disponibles)
- [Múltiples instancias](#múltiples-instancias)
- [Configuración SMTP](#configuración-smtp)
- [Crear usuario administrador](#crear-usuario-administrador)
- [Comandos Docker útiles](#comandos-docker-útiles)
- [Limpieza y reinstalación](#limpieza-y-reinstalación)
- [Estructura de archivos](#estructura-de-archivos)
- [Fixes aplicados](#fixes-aplicados)

---

## Arquitectura

El sistema consta de 4 servicios Docker organizados en dos capas:

```
pdnmx_network (red Docker compartida)
│
├── pdnmx-mongo      [COMPARTIDO]  MongoDB  · 512 MB · puerto 27017 (interno)
│
├── {instancia}-reports             Python/Flask · 150 MB · puerto 3001 (interno)
├── {instancia}-app                 Node.js/GraphQL · 400 MB · puerto 3000 (expuesto)
└── {instancia}-webapp              Angular/nginx · 64 MB · puerto 8080 (expuesto)
```

| Servicio   | Tecnología          | Puerto expuesto | Compartido |
|------------|---------------------|-----------------|------------|
| mongo      | MongoDB Community   | —               | Sí         |
| reports    | Python 3.8 + Flask  | —               | No         |
| app        | Node.js 18 + GraphQL| `BACKEND_PORT`  | No         |
| webapp     | Angular 11 + nginx  | `FRONTEND_PORT` | No         |

> **Seguridad:** nunca expongas los puertos de `mongo` (27017) ni `reports` (3001) a Internet.

---

## Requisitos

| Requisito        | Mínimo           |
|------------------|------------------|
| Sistema operativo| Ubuntu / Debian  |
| RAM              | 4 GB             |
| Almacenamiento   | 30 GB            |
| CPU              | 2 núcleos        |
| Docker           | Se instala automáticamente |
| Git              | Instalado        |
| Python 3         | Instalado        |
| openssl          | Instalado        |

---

## Instalación rápida

### Opción A — Wizard interactivo (recomendado)

Guía paso a paso que solicita los datos de la institución, titular y correo:

```bash
bash asistente.sh
```

El wizard cubre 4 secciones:
1. **Configuración técnica** — nombre de instancia, puertos, base de datos, dominio/IP pública y protocolo
2. **Datos de la institución** — nombre oficial, clave, ciudad, titular
3. **Correo electrónico** — SMTP para reset de contraseña (opcional)
4. **Confirmación** — resumen antes de instalar

### Opción B — Instalación automática con valores por defecto

```bash
bash setup.sh
```

Al terminar el sistema queda disponible en:

- **Frontend:** http://localhost:8080
- **Backend (GraphQL):** http://localhost:3000/graphql

---

## Scripts disponibles

### `setup.sh`
Instalación completa desatendida. Instala Docker, clona los 4 repositorios, genera credenciales, configura todo y levanta los servicios.

```bash
bash setup.sh
```

### `asistente.sh`
Wizard interactivo. Solicita los datos de la institución y genera el `instituciones.json` con nombre, clave, lugar y titular. Recomendado para primeras instalaciones.

```bash
bash asistente.sh
```

### `nueva-instancia.sh`
Crea una instancia adicional del sistema para otra institución, reutilizando el MongoDB compartido.

```bash
bash nueva-instancia.sh <nombre> <backend_port> <frontend_port>

# Ejemplos:
bash nueva-instancia.sh inst2 3010 8081
bash nueva-instancia.sh inst3 3020 8082
```

### `limpiar.sh`
Borra todos los contenedores, imágenes, la red Docker, los repositorios clonados y los archivos generados. Conserva únicamente los 4 scripts.

```bash
bash limpiar.sh
# Requiere escribir BORRAR para confirmar
```

---

## Múltiples instancias

MongoDB es compartido entre todas las instancias para ahorrar recursos. Cada instancia tiene su propio backend, frontend y módulo de reportes, con base de datos separada.

### Levantar infraestructura compartida (una sola vez)

```bash
sudo docker compose -f docker-compose.shared.yml up -d
```

### Agregar una segunda instancia

```bash
bash nueva-instancia.sh inst2 3010 8081
```

### Estructura multi-instancia

```
~/Temps/
├── PND/          ← Instancia 1  (puertos 3000 / 8080, BD: newmodels)
└── inst2/        ← Instancia 2  (puertos 3010 / 8081, BD: inst2_db)
```

### Tabla de puertos sugeridos

| Instancia | Backend | Frontend | Base de datos  |
|-----------|---------|----------|----------------|
| pnd       | 3000    | 8080     | newmodels      |
| inst2     | 3010    | 8081     | inst2_db       |
| inst3     | 3020    | 8082     | inst3_db       |

### Consumo de recursos aproximado

| Configuración             | RAM aprox. |
|---------------------------|------------|
| 1 instancia sin optimizar | ~1.2 GB    |
| 3 instancias optimizadas  | ~2.0 GB    |
| Ahorro vs. 3 MongoDB      | ~1.0 GB    |

---

## Configuración de dominio / IP pública

El wizard pregunta el dominio o IP pública en la sección 1. Eso determina las URLs que se configuran automáticamente:

| Variable | Dónde se usa | Ejemplo |
|----------|-------------|---------|
| `serverUrl` en `environment.prod.ts` | Lo que el navegador llama al backend | `https://api.midominio.gob.mx` |
| `FE_RESET_PASSWORD_URL` en backend `.env` | Enlace en correos de reset | `https://declaraciones.midominio.gob.mx` |

### Ejemplos de configuración

**Acceso directo por IP (desarrollo/pruebas):**
```
Dominio o IP pública: 192.168.1.100
Protocolo: http
→ Backend URL:  http://192.168.1.100:3000
→ Frontend URL: http://192.168.1.100:8080
```

**Con dominio y nginx (producción):**
```
Dominio o IP pública: declaraciones.midominio.gob.mx
Protocolo: https
→ Backend URL:  https://declaraciones.midominio.gob.mx:3000
→ Frontend URL: https://declaraciones.midominio.gob.mx:8080
```

> Si usas nginx en puerto 80/443 para ocultar los puertos, configura los puertos como `80`/`443` y el wizard omitirá el número en la URL.

### Configuración nginx sugerida

```nginx
server {
    listen 80;
    server_name declaraciones.midominio.gob.mx;

    # Frontend Angular
    location / {
        proxy_pass http://localhost:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }

    # Backend GraphQL (si se expone en el mismo dominio)
    location /graphql {
        proxy_pass http://localhost:3000/graphql;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

---

## Configuración SMTP

Edita `SistemaDeclaraciones_backend/.env` y completa el bloque de correo:

```env
USE_SMTP=true
SMTP_HOST=smtp.tuproveedor.com
SMTP_PORT=587
SMTP_SECURE=false
SMTP_USER=tucuenta@dominio.com
SMTP_PASSWORD=tu_password
SMTP_FROM_EMAIL=tucuenta@dominio.com
```

> **Puerto 587 (STARTTLS) vs 465 (SSL):** la mayoría de ISPs domésticos y de oficina **bloquean los puertos 25 y 465** salientes para combatir spam. Usa **587 con `SMTP_SECURE=false`** salvo que sepas que tu red permite el 465. Para verificarlo: `nc -zv tu.servidor.smtp 587`.

> **Gmail:** debes usar una *App Password*, no tu contraseña normal.
> Genera una en: Cuenta Google → Seguridad → Verificación en 2 pasos → Contraseñas de aplicación.

> **Contraseñas con `$`:** dotenv preserva el carácter `$` literal (no lo expande), así que `SMTP_PASSWORD=$1abc` funciona sin escapar. Pero si edita el `.env` con `sed` u otra herramienta que sí lo expande, comilla la línea.

Después de editar, **recrea** el contenedor (no uses `restart`, que no relee `env_file`):

```bash
sudo docker compose up -d --force-recreate app
```

---

## Crear usuario administrador

El primer usuario registrado en el sistema tiene rol `declarante` por defecto. Para convertirlo en administrador (`ROOT`):

**1.** Regístrate en http://localhost:8080

**2.** Conéctate a MongoDB:

```bash
sudo docker exec -it pdnmx-mongo mongosh \
  -u pdnmx_admin --authenticationDatabase admin
```

**3.** Ejecuta en la consola de MongoDB (reemplaza `<ObjectID>`):

```js
use newmodels
var u = db.users.findOne()           // o busca por email: findOne({email:"..."})
u.roles = ["ROOT"]
db.users.updateOne({_id: u._id}, {$set: u})
db.users.find().pretty()             // verificar el cambio
```

**4.** Cierra sesión en el frontend y vuelve a entrar para que aplique el rol.

### Roles disponibles

| Rol          | Descripción                                        |
|--------------|----------------------------------------------------|
| `USER`       | Declarante — solo puede presentar su declaración   |
| `ROOT`       | Administrador — gestiona usuarios e información    |
| `ADMIN`      | Administrativo N1 — revisa declaraciones y estado  |
| `SUPER_ADMIN`| Administrativo N2 — igual que ADMIN + ver contenido|

---

## Comandos Docker útiles

```bash
# Estado de todos los servicios
sudo docker compose ps

# Logs en tiempo real
sudo docker compose logs -f app       # backend
sudo docker compose logs -f webapp    # frontend
sudo docker compose logs -f mongo     # base de datos
sudo docker compose logs -f reports   # reportes PDF

# Reiniciar un servicio sin reconstruir (NO relee .env)
sudo docker compose restart app

# Reiniciar y aplicar cambios en .env (recrea el contenedor)
sudo docker compose up -d --force-recreate app

# Reconstruir y reiniciar todo
sudo docker compose up --build -d

# Detener todo (sin borrar datos)
sudo docker compose down

# Ver uso de recursos en tiempo real
sudo docker stats
```

---

## Limpieza y reinstalación

### Borrar todo y empezar desde cero

```bash
bash limpiar.sh
# Escribe BORRAR para confirmar
```

Lo que elimina:

| Elemento                          | Se elimina |
|-----------------------------------|------------|
| Contenedores Docker               | ✓          |
| Imágenes Docker del proyecto      | ✓          |
| Red `pdnmx_network`               | ✓          |
| Repositorios clonados             | ✓          |
| Datos MongoDB (`database-test/`)  | ✓          |
| `.env`, `docker-compose*.yml`     | ✓          |
| `setup.sh`, `asistente.sh`, etc.  | ✗ (se conservan) |

### Lo que queda después de limpiar

```
PND/
├── setup.sh
├── asistente.sh
├── nueva-instancia.sh
└── limpiar.sh
```

### Reinstalar después de limpiar

```bash
bash asistente.sh    # con wizard (recomendado)
# ó
bash setup.sh        # instalación automática
```

---

## Estructura de archivos

```
PND/
├── setup.sh                          Script de instalación completa
├── asistente.sh                      Wizard interactivo de configuración
├── nueva-instancia.sh                Crear instancias adicionales
├── limpiar.sh                        Limpieza total
│
├── docker-compose.yml                Servicios por instancia (generado)
├── docker-compose.shared.yml         MongoDB compartido (generado)
├── .env                              Variables de la instancia (generado)
│
├── database-test/                    Repo clonado — configuración MongoDB
│   ├── compose.yml
│   ├── step-01.sh                    Crea directorios de datos
│   └── mongodb/
│       ├── config/mongod.conf
│       └── data/                     Datos persistentes de MongoDB
│           ├── volume/               ← archivos de la BD
│           └── log/
│
├── SistemaDeclaraciones_backend/     Repo clonado — API GraphQL (Node.js)
│   ├── Dockerfile.fixed              Dockerfile corregido (generado)
│   ├── .env                          Credenciales del backend (generado)
│   └── src/
│       └── data/
│           ├── instituciones.json    Config de la institución (generado)
│           └── instituciones.json.example
│
├── SistemaDeclaraciones_frontend/    Repo clonado — Angular + nginx
│   └── src/environments/
│       └── environment.prod.ts       URL del backend (parcheado)
│
└── SistemaDeclaraciones_reportes/    Repo clonado — generador PDF (Python)
```

---

## Fixes aplicados

Correcciones necesarias para ejecutar el proyecto con herramientas modernas y para que el setup multi-instancia funcione correctamente:

| Problema | Causa | Solución |
|----------|-------|----------|
| `yarn: not found` en build | `node:14-alpine` ya no incluye yarn | Usar `node:18-alpine` con `npm` en `Dockerfile.fixed` |
| `tsc: not found` | `NODE_ENV=production` omite `devDependencies` | `npm install --include=dev` antes de compilar |
| Error de tipos `@types/express` | Conflicto entre versiones en `apollo-server-express` v2 (EOL) y `@types/express` moderno | `tsconfig.build.json` con `strict: false` + `tsc \|\| true` para no detener el build por errores de tipo |
| `instituciones.json` sobrescrito en cada build | `cp` incondicional del `.example` | `test -f src/data/instituciones.json \|\| cp ...` para preservar el archivo personalizado por institución |
| `users.findOne() buffering timed out` (multi-instancia) | El alias del nombre de servicio `mongo` solo resuelve dentro del mismo Compose project; entre proyectos no llega | Usar `MONGO_HOSTNAME=pdnmx-mongo` (container_name único en `pdnmx_network`) |
| `MongoError: Authentication failed` al crear segunda instancia | Cada corrida del wizard regeneraba `DB_ROOT_PASSWORD`, pero MongoDB solo inicializa el root user en el **primer arranque** | `BASE_DIR/.env` es la fuente única de verdad: si existe se reutiliza; si Mongo corre sin `.env` accesible, el script aborta con instrucciones; `nueva-instancia.sh` valida con `mongosh ping` antes de construir |
| PDF de declaración devuelve `BAD REQUEST` (multi-instancia) | El alias `reports` resolvía a TODOS los `*-reports` en `pdnmx_network` y Docker DNS hacía round-robin → ~50% de las peticiones iban al reports de otra institución y la API key era rechazada | Usar `REPORTS_URL=http://${INSTANCE_NAME}-reports:3001` (container_name único) |
| Cambios en `.env` no se reflejan tras `docker compose restart` | `restart` no relee `env_file`; solo reinicia el proceso con los env vars cacheados | Usar `docker compose up -d --force-recreate <servicio>` cuando edites un `.env` manualmente |

---

## Repositorios oficiales

| Módulo    | Repositorio |
|-----------|-------------|
| Principal | https://github.com/PDNMX/SistemaDeclaraciones |
| Backend   | https://github.com/PDNMX/SistemaDeclaraciones_backend |
| Frontend  | https://github.com/PDNMX/SistemaDeclaraciones_frontend |
| Reportes  | https://github.com/PDNMX/SistemaDeclaraciones_reportes |
| Base datos| https://github.com/PDNMX/database-test |

Manual de instalación oficial: https://docs.google.com/document/d/1HAOwKuZcrTzISx5BKaFIzJQ__-puTPbiLRmR9XACZow
