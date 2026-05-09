# Ajustes para que los contenedores funcionen

Bitácora técnica de los 8 problemas que encontramos al desplegar `SistemaDeclaraciones` en Docker — síntoma, causa raíz, solución aplicada y cómo verificarlo.

Útil para entender **por qué** los scripts (`setup.sh`, `asistente.sh`, `nueva-instancia.sh`) generan los archivos como lo hacen, y como referencia si algún día hay que portarlo a otra versión del código.

---

## Índice

1. [`yarn: not found` durante el build del backend](#1-yarn-not-found-durante-el-build-del-backend)
2. [`tsc: not found` después de cambiar a npm](#2-tsc-not-found-después-de-cambiar-a-npm)
3. [Errores de tipo en `@types/express`](#3-errores-de-tipo-en-typesexpress)
4. [`instituciones.json` faltante o sobrescrito](#4-institucionesjson-faltante-o-sobrescrito)
5. [`Operation users.findOne() buffering timed out` en multi-instancia](#5-operation-usersfindone-buffering-timed-out-en-multi-instancia)
6. [`MongoError: Authentication failed` al crear segunda instancia](#6-mongoerror-authentication-failed-al-crear-segunda-instancia)
7. [PDF de declaración devuelve `BAD REQUEST` en multi-instancia](#7-pdf-de-declaración-devuelve-bad-request-en-multi-instancia)
8. [`Connection timeout` al enviar correo SMTP](#8-connection-timeout-al-enviar-correo-smtp)

Y dos cuestiones operativas relacionadas:

- [Editar `.env` no surte efecto: `restart` vs `--force-recreate`](#editar-env-no-surte-efecto-restart-vs---force-recreate)
- [Heredocs con `! sudo tee <<EOF` en Claude Code se cuelgan](#heredocs-con--sudo-tee-eof-en-claude-code-se-cuelgan)

---

## 1. `yarn: not found` durante el build del backend

### Síntoma
```
Step 5/9 : RUN yarn install
yarn: not found
The command '/bin/sh -c yarn install' returned a non-zero code: 127
```

### Causa raíz
El `Dockerfile` original usa `node:14-alpine`. Las imágenes oficiales de Node.js dejaron de incluir `yarn` preinstalado a partir de cierta versión. Además, Node 14 está EOL desde abril 2023.

### Solución
Usar `node:18-alpine` y `npm` en su lugar. Los scripts generan un `Dockerfile.fixed` con esta imagen base.

```dockerfile
FROM node:18-alpine
ADD . /backend
WORKDIR /backend
ARG NODE_ENV
RUN npm install --include=dev \
    && npx rimraf ./build \
    && (npx tsc -p tsconfig.build.json || true) \
    && npx copyfiles -u 1 "src/**/*.graphql" build/ \
    && npx copyfiles -a -u 1 "src/**/*.json" build/ \
    && npm prune --production
CMD ["node", "build/server.js"]
```

### Verificar
```bash
sudo docker compose logs app | grep "node:" 
# Debe mostrar 18.x.x
```

---

## 2. `tsc: not found` después de cambiar a npm

### Síntoma
```
> tsc -p tsconfig.json
sh: tsc: not found
```

### Causa raíz
El `docker-compose.yml` pasa `NODE_ENV=production` como build arg. Cuando `npm install` ve `NODE_ENV=production`, **omite las `devDependencies`** — y `typescript`, `rimraf`, `copyfiles` están ahí. Sin TypeScript no hay forma de compilar.

### Solución
Forzar la instalación de devDependencies con `--include=dev`, compilar, y limpiar al final con `npm prune --production`:

```dockerfile
RUN npm install --include=dev \
    && (npx tsc -p tsconfig.build.json || true) \
    && npm prune --production
```

### Verificar
```bash
sudo docker compose logs app | grep "Server is running"
# Debe aparecer: "Server is running on port = 3000"
```

---

## 3. Errores de tipo en `@types/express`

### Síntoma
```
node_modules/apollo-server-express/dist/ApolloServer.d.ts:5:23 -
  error TS2305: Module '"express-serve-static-core"' has no exported member 'CoreOptions'.
```

### Causa raíz
`apollo-server-express` v2 (EOL desde 2023) trae sus propios types desactualizados que chocan con la versión moderna de `@types/express`. Es un conflicto irresoluble sin actualizar a Apollo Server v4 (lo cual implica reescribir partes del código del proyecto upstream).

### Solución
Crear un `tsconfig.build.json` que afloja la comprobación de tipos, y permitir que `tsc` falle sin abortar el build (los errores de tipo no afectan el JavaScript generado):

```dockerfile
RUN echo '{
  "extends": "./tsconfig.json",
  "compilerOptions": {
    "strict": false,
    "noImplicitAny": false,
    "noUnusedLocals": false,
    "noImplicitReturns": false
  }
}' > tsconfig.build.json

RUN (npx tsc -p tsconfig.build.json || true)
```

El `|| true` ignora el exit code 2 de `tsc`. El JS resultante en `build/` corre sin problemas.

### Verificar
```bash
sudo docker compose exec app ls build/server.js
# Si existe, el build funcionó (aunque tsc haya quejado)
```

---

## 4. `instituciones.json` faltante o sobrescrito

### Síntoma A (faltante)
```
Error: Cannot find module './data/instituciones.json'
```

### Síntoma B (sobrescrito)
Tras personalizar `instituciones.json` con el nombre del ayuntamiento real y reconstruir, se vuelve al ejemplo genérico.

### Causa raíz
El repositorio sólo incluye `instituciones.json.example`. Si no existe, el código falla en runtime. Si los scripts hacen `cp ...example ...json` incondicional, sobrescriben el custom en cada `docker compose up --build`.

### Solución
Crear desde el ejemplo **solo si no existe**:

```dockerfile
RUN test -f src/data/instituciones.json || cp src/data/instituciones.json.example src/data/instituciones.json
```

El asistente genera el archivo personalizado **antes** del build, así que cuando llega al `RUN`, el archivo ya está y `test -f` evita sobrescribirlo.

### Verificar
```bash
sudo docker compose exec app cat src/data/instituciones.json | jq .institucion
# Debe mostrar el nombre que pusiste en el wizard
```

---

## 5. `Operation users.findOne() buffering timed out` en multi-instancia

### Síntoma
Tras crear una segunda instancia (ej. `pnd_tlacotepec_v1`), el login no avanza:

```json
{"errors":[{"message":"Operation `users.findOne()` buffering timed out after 10000ms"}]}
```

La 1ª instancia (que tiene Mongo en su mismo Compose project) sí funciona. La 2ª no.

### Causa raíz
El backend conecta a `MONGO_HOSTNAME=mongo`. En Docker Compose, el alias `mongo` se registra dentro de la red **del mismo proyecto**. Pero `pdnmx_network` es una red **externa compartida** entre proyectos, y aunque Compose registra el alias del nombre de servicio en cualquier red que use, **ese alias sólo es resoluble desde otros contenedores del mismo proyecto** que sirvan ese servicio.

Resultado: desde `pnd_tlacotepec_v1-app`, el nombre `mongo` no resuelve a `pdnmx-mongo` (que vive en otro proyecto, `docker-compose.shared.yml`).

### Solución
Conectar al **`container_name`** explícito, que sí es resoluble globalmente en la red compartida:

```env
MONGO_HOSTNAME=pdnmx-mongo
```

El `container_name: pdnmx-mongo` ya está fijado en `docker-compose.shared.yml`.

### Verificar
```bash
sudo docker exec <instancia>-app getent hosts pdnmx-mongo
# Debe devolver una IP. Si dice "name not found", hay que conectar
# el contenedor a la red pdnmx_network.
```

---

## 6. `MongoError: Authentication failed` al crear segunda instancia

### Síntoma
La 2ª instancia llega a Mongo (DNS funciona) pero rechaza credenciales:

```
MongoError: Authentication failed.
  ok: 0,
  code: 18,
  codeName: 'AuthenticationFailed'
```

### Causa raíz
La imagen oficial de MongoDB inicializa el usuario root **una sola vez**, en el primer arranque, leyendo `MONGODB_INITDB_ROOT_PASSWORD`. Cualquier cambio posterior de esa env var es ignorado — el usuario ya existe con la password vieja.

Cuando los scripts regeneraban una password nueva en cada corrida (`openssl rand -hex 16`), pasaba esto:

1. Corrida 1: genera password A → Mongo se inicializa con A
2. Corrida 2 (otra instancia): genera password B → la escribe en su `.env` → al conectar usa B → Mongo todavía tiene A → rechaza.

### Solución
Tratar `PND/.env` como **fuente única de verdad** de las credenciales compartidas:

- Si existe con `DB_ROOT_PASSWORD=...` → reutilizarlo (no regenerar)
- Si no existe pero `pdnmx-mongo` está corriendo → abortar con instrucciones (no se puede recuperar la password sin acceso a Mongo)
- Si no existe ni Mongo corre → es un primer arranque, generar y persistir

```bash
if [ -f "$BASE_DIR/.env" ] && grep -q '^DB_ROOT_PASSWORD=' "$BASE_DIR/.env"; then
  DB_ROOT_USER=$(grep '^DB_ROOT_USER=' "$BASE_DIR/.env" | head -1 | cut -d= -f2)
  DB_ROOT_PASSWORD=$(grep '^DB_ROOT_PASSWORD=' "$BASE_DIR/.env" | head -1 | cut -d= -f2)
elif sudo docker ps --format '{{.Names}}' 2>/dev/null | grep -q '^pdnmx-mongo$'; then
  err "pdnmx-mongo corre pero $BASE_DIR/.env no tiene DB_ROOT_PASSWORD."
else
  DB_ROOT_USER="pdnmx_admin"
  DB_ROOT_PASSWORD=$(openssl rand -hex 16)
fi
```

Adicional: `nueva-instancia.sh` valida con `mongosh ping` que la password realmente funcione **antes** de construir nada.

### Verificar
```bash
# Las dos passwords deben coincidir entre PND/.env y la instancia:
grep DB_ROOT_PASSWORD /home/herrerao/Temps/PND/.env
grep MONGO_PASSWORD /home/herrerao/Temps/<instancia>/SistemaDeclaraciones_backend/.env

# Y deben funcionar contra Mongo:
sudo docker exec pdnmx-mongo mongosh --quiet \
  -u pdnmx_admin -p "$(grep DB_ROOT_PASSWORD /home/herrerao/Temps/PND/.env | cut -d= -f2)" \
  --authenticationDatabase admin --eval "db.adminCommand('ping').ok"
# Debe devolver: 1
```

---

## 7. PDF de declaración devuelve `BAD REQUEST` en multi-instancia

### Síntoma
Tras firmar la declaración o pedir el preview:

```json
{"success":false,"message":"Something went wrong"}
```

Y en el log del backend, axios devuelve:

```
isAxiosError: true
data: <Buffer ... "BAD REQUEST" ...>
host: 'reports'
```

Lo más raro: los logs de `<instancia>-reports` **no muestran la petición**. Parece que la petición nunca llegó al reports correcto.

### Causa raíz
Mismo patrón que con Mongo, pero peor. Cada instancia tiene su servicio `reports` con `container_name: <inst>-reports`. Pero Compose **también** registra el alias `reports` (nombre de servicio) en `pdnmx_network`. Como hay varios contenedores con ese alias, Docker DNS hace **round-robin** entre todos.

`http://reports:3001` resolvía cada vez a un reports distinto. ~50% de las peticiones del `pnd_tlacotepec_v1-app` llegaban a `pnd_tecali_v1-reports`, que recibe la API key de tlacotepec, no la reconoce, y responde 400 BAD REQUEST.

### Solución
Conectar al `container_name` único, idéntico al fix de Mongo:

```env
REPORTS_URL=http://<INSTANCE_NAME>-reports:3001
```

Ejemplos:
- `pnd_tlacotepec_v1-app` → `http://pnd_tlacotepec_v1-reports:3001`
- `pnd_tecali_v1-app` → `http://pnd_tecali_v1-reports:3001`

### Verificar
```bash
sudo docker exec <inst>-app printenv REPORTS_URL
# Debe terminar en <inst>-reports:3001 (no sólo "reports:3001")

# Test de que llega:
sudo docker exec <inst>-app sh -c 'wget -qO- --timeout=5 http://<inst>-reports:3001/'
# El servicio reports debe responder algo (incluso si es 404 — confirma conectividad)
```

---

## 8. `Connection timeout` al enviar correo SMTP

### Síntoma
```
Error creating SMTP transporter: Error: Connection timeout
  code: 'ETIMEDOUT',
  command: 'CONN'
Failed to send email: InternalServerError: Failed to create SMTP transport
```

### Causa raíz
La configuración inicial usaba puerto **465** (SSL implícito). La gran mayoría de ISPs domésticos y de oficina **bloquean el tráfico saliente a los puertos 25 y 465** para combatir spam — sólo permiten el **587 (submission)**.

Diagnóstico:
```bash
nc -zv mail.dataismo.mx 465  # → Operation timed out
nc -zv mail.dataismo.mx 587  # → succeeded
nc -zv mail.dataismo.mx 25   # → Operation timed out
```

### Solución
Cambiar a puerto 587 con STARTTLS (no SSL implícito):

```env
SMTP_PORT=587
SMTP_SECURE=false
```

`SMTP_SECURE=false` no significa "sin cifrado" — significa "no usar TLS implícito desde la conexión". Nodemailer negocia STARTTLS automáticamente cuando el servidor lo anuncia (`250-STARTTLS`).

El asistente ahora muestra esta advertencia explícitamente cuando pregunta el puerto.

### Verificar
```bash
# Greeting + STARTTLS desde el contenedor:
sudo docker exec <inst>-app sh -c '
  ( echo "EHLO test"; sleep 2; echo "QUIT" ) | nc -w 10 mail.dataismo.mx 587
'
# Debe devolver: 220 mail.dataismo.mx ESMTP ... 250-STARTTLS ...
```

### Alternativa robusta
Si tu red bloquea **todos** los puertos SMTP (poco común, pero pasa), usa un servicio HTTPS como SendGrid o Mailgun:

```env
USE_SMTP=false
SENDGRID_API_KEY=SG.xxxxx
SENDGRID_MAIL_SENDER=verificado@tudominio.com
```

Estos servicios entregan correo via HTTPS (puerto 443), inmune a bloqueos de SMTP.

---

## Editar `.env` no surte efecto: `restart` vs `--force-recreate`

### Síntoma
Editas `SistemaDeclaraciones_backend/.env`, ejecutas `docker compose restart app`, pero el contenedor sigue con los valores anteriores. Logs siguen mostrando la config vieja.

### Causa raíz
`docker compose restart` **sólo reinicia el proceso** dentro del contenedor existente. Las env vars vienen de cuando el contenedor fue **creado** (tomadas de `env_file`). Reiniciar el proceso no relee el archivo.

### Solución
Recrear el contenedor con el nuevo env:

```bash
sudo docker compose -p <proyecto> -f <compose.yml> up -d --force-recreate app
```

O alternativamente:

```bash
sudo docker compose stop app && \
sudo docker compose rm -f app && \
sudo docker compose up -d app
```

### Verificar
```bash
sudo docker exec <inst>-app printenv | grep <VAR_QUE_CAMBIASTE>
# Debe mostrar el valor nuevo
```

---

## Heredocs con `! sudo tee <<EOF` en Claude Code se cuelgan

### Síntoma
La terminal se queda esperando input al pegar:

```bash
! sudo tee archivo.env > /dev/null <<'EOF'
contenido...
EOF
```

### Causa raíz
El prefijo `!` en Claude Code agrega indentación a las líneas pegadas. Bash exige que el delimitador de cierre (`EOF`) esté en **columna 0** (sin espacios al inicio) para `<<EOF`. La variante `<<-EOF` permite TABS al inicio, pero **no espacios**.

### Solución (sin heredoc)
Usar `printf` o múltiples `echo`:

```bash
! sudo bash -c 'printf "%s\n" "VAR1=val1" "VAR2=val2" > archivo.env'
```

Si necesitas reemplazar líneas en un archivo existente:
```bash
! sudo sed -i 's/^SMTP_PORT=465/SMTP_PORT=587/' archivo.env
```

Editar archivos con la herramienta `Edit` (cuando se trabaja vía Claude) en vez de heredocs.

---

## Patrones que conviene recordar

1. **En redes Docker compartidas, conecta por `container_name`, no por nombre de servicio**. Los nombres de servicio causan colisiones y round-robin DNS impredecible.
2. **MongoDB sólo inicializa el root user en el primer arranque**. Cualquier password generada después del primer arranque es ignorada — hay que reutilizar la original o resetear el volumen.
3. **`docker compose restart` no relee `env_file`** — usa `up -d --force-recreate` cuando edites un `.env`.
4. **Prueba la conectividad al SMTP con `nc -zv`** antes de asumir que el problema es de credenciales o configuración del servidor.
5. **`SMTP_SECURE=false` con puerto 587 es lo correcto** (STARTTLS). `SMTP_SECURE=true` es sólo para puerto 465 (SSL implícito).
6. **`NODE_ENV=production` omite devDependencies en `npm install`** — si necesitas typescript/rimraf/etc para el build, usa `npm install --include=dev` y limpia con `npm prune --production` después.
