# Nota de continuidad

Fecha: 2026-11-18

## Estado actual

- Repositorio: `/home/herrerao/Descargas/Tecali/pnd`, rama `main`, remoto `git@github.com:racsohm/pnd.git`.
- `respaldo.sh` crea respaldos `.zip` con BSON y metadatos por colección, comprueba el ZIP y acepta `.gz` antiguos al restaurar. `--keep` rota ambos formatos.
- La sintaxis de `respaldo.sh` y `git diff --check` pasaron. No se ejecutó un dump ni una restauración real.
- Instancia de pruebas desplegada en `/home/herrerao/Descargas/Tecali/pnd_pruebas`, creada con `bash asistente.sh` y configuración ficticia: `AYUNTAMIENTO DE PRUEBAS`, base `pnd_pruebas_db`, backend `3020`, frontend `8082`.
- Contenedores activos: `pdnmx-mongo` (healthy), `pnd_pruebas-app`, `pnd_pruebas-webapp` y `pnd_pruebas-reports`. El frontend respondió HTTP 200 y el backend confirmó conexión con MongoDB.
- La base tiene las colecciones `users` y `users_dec`, ambas con cero documentos. No se importaron declaraciones de muestra; registrar un usuario desde la interfaz será el primer paso para probarla.
- La configuración y los clones de la instancia están fuera del repositorio Git. Los secretos viven en `.env`; no subirlos. Para reproducirla en otro equipo, clonar el repositorio y ejecutar `bash asistente.sh` con los mismos datos de prueba, usando los puertos disponibles.
- En la compilación se mostraron errores TypeScript en el backend, pero el Dockerfile del asistente continúa con `|| true`; el contenedor arrancó y conectó a MongoDB. Revisar esos errores si aparecen fallos funcionales.
- Se limpió Docker antes de desplegar: se eliminaron los contenedores, la red personalizada anterior, las imágenes y los volúmenes locales. El despliegue volvió a descargar/construir las imágenes y creó `pdnmx_network`.

## Próximo paso

Al retomar en otro equipo, hacer `git pull`, revisar esta nota y recrear la instancia ficticia con `bash asistente.sh` si se necesita. Para la función de respaldo, revisar el recorrido de restauración ZIP y comprobar un ciclo dump/list/restore con datos desechables; aún no está validado contra MongoDB real.

## Contexto previo

- La API SESEAP había presentado error de autenticación al conectarse a `pdnmx-mongo`; aún no se confirmó si eran credenciales o configuración de autenticación.
- El usuario había indicado que quería actualizar el binario de la API. La conversación se enfocó después en revisar que el respaldo pudiera reintegrarse a MongoDB con BSON.
- El usuario prefiere ZIP porque es más fácil de abrir para los usuarios.
