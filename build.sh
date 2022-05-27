#!/bin/bash
#
# Esto es un ejemplo en Bash del clasico "Hola Mundo"
#

echo "Iniciando Construcción."
echo "ADVERTENCIA: ESTE ESCRIPT ESTA DISEÑADO PARA IMPLEMENTAR EL SISTEMA DE DECLARACIÓN PATRIMONIAL Y DE CONFLICTO DE ÍNTERES"
echo "PROVISTO POR LA PND Y NO DEBERÁ USARSE PARA MODIFICAR UN SISTEMA EN PRODUCCIÓN, USE BAJO SU PROPIO RIESGO"
echo "DATAISMO SOFTWARE NO ES, NI SERÁ RESPONSABLE POR LA PERDIDA DE INFORMACIÓN O CUALQUIER OTRO DAÑO ATRIBUIBLE AL USO "
echo "DE ESTE SCRIPT. LEA LA DOCUMENTACIÓN ANTES DE EMPEZAR"

#Instalamos dependencias:
# sudo apt update
# sudo apt install --yes php7.4-common php7.4-mongodb docker docker-compose mongodb composer
echo "¿Ya modifico las variables de la carpeta ./src (S = Sí, N = No)?"

# Ask the user for their name

read -r varname

if [ "$varname" == 'S' ]
then
#Iniciamos la preparación del sistema:
php ./src/prepara.php
#Iniciamos la construcción del contenedor:

else
echo "Proceso detenido (No presiono S), lea la documentación para más información".
fi