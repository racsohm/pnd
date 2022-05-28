# Script de despliegue para el sitema de declaración patrimonial provisto por P.N.D
***
La finalidad de este script es simplificar el proceso de implementación en los servidores de DATAISMO 
SOFTWARE, aunque su uso es libre, ya que el sistema de declaración patrimonial y conflicto de interes 
es un sistema que no ha sido desarrollado por dataismo y es de libre acceso, sin embargo, debe de tomar 
las siguientes consideraciones:

<strong>
"ADVERTENCIA": USE BAJO SU PROPIO RIESGO YA QUE DATAISMO SOFTWARE SAS NO ES, NI SERÁ RESPONSABLE POR LA PERDIDA 
DE INFORMACIÓN O CUALQUIER OTRO DAÑO ATRIBUIBLE AL USO DE ESTE SCRIPT. LEA LA DOCUMENTACIÓN ANTES DE EMPEZAR
</strong>

***
**Requisitos:**
- El script precisa ubuntu 20.04 y precisa de PHP para realizar las modicaciones de las platillas y leer los datos de las vairables .env.
- Puede ser usando en windows con las tecnologías WLS (Windows Linux Subsystem).
- No debe de existir una base de MongoDB instalada previamente, ya que puede ocasionar perdida de datos ya que el 
script crea automaticamente la base dedatos del .env del backend si se indica.
- Se requiere ser administrador del sistema (derechos elevados).
- Tener conexión a internet.
- Contar con almenos 15 GB de Espació en disco para la construcción de los contenedores.

**Preparativos:**
- Primero modifique el archivo ubicado en **.env** donde debera especificar el nombre de
la base de datos, el usuario y la contraseña (estos se crearan automáticamente por el script de instalación) los valores
 a modificar para una instalación simple son:
  - MONGO_USERNAME=username
  -  MONGO_PASSWORD=passwd
  -  MONGO_DB=newmodels
- Para agregar el logo del municipio al portal de inicio de sesión, por favor indique la url de la imagen a descargar e
indique si de debe hacer el procedimiento en las varaibles: URL_LOGO, IMPLEMENTAR_LOGO
- Para cambiar el texto del acuse por favor modifique USAR_ACUSE_PERSONALIZADO=1, el arvhivo que usa el sistema para generar el PDF
del acuse se encuetra en ./src/acuse.html el cual puede modificar antes de inicar la construcción de los contenedores.
- Por favor para mas información sobre las variables de entorno por favor visite la documentación del autor:
  
  | Manual            | Descripción | Recurso |
  | ----------------- | ----------- | --------|
  | Manual de usuario | Manual para usuarios del Sistema de Declaraciones (declarantes y usuarios administrativos). | [Manual](manuales/manual_usuario.pdf)|
  | Manual de instalación | Manual orientado al personal encargado de la instalación y soporte técnico del Sistema de Declaraciones. | [Manual](manuales/manual_instalacion.pdf)|

**¿Como usar el script?**

Para iniciar con la instalación debe ejecutar el archivo llamado **build.sh** el cual instalara todas
las dependencias necesarias 
