#!/bin/bash

Red=$'\e[1;31m'
Green=$'\e[1;32m'
Blue=$'\e[1;34m'

#
# Esto es un ejemplo en Bash del clasico "Hola Mundo"
#

echo "Iniciando Construcción."
echo "$Red ADVERTENCIA: ESTE ESCRIPT ESTA DISEÑADO PARA IMPLEMENTAR EL SISTEMA DE DECLARACIÓN PATRIMONIAL Y DE CONFLICTO DE ÍNTERES"
echo "$Red PROVISTO POR LA PND Y NO DEBERÁ USARSE PARA MODIFICAR UN SISTEMA EN PRODUCCIÓN, USE BAJO SU PROPIO RIESGO"
echo "$Red DATAISMO SOFTWARE NO ES, NI SERÁ RESPONSABLE POR LA PERDIDA DE INFORMACIÓN O CUALQUIER OTRO DAÑO ATRIBUIBLE AL USO "
echo "$Red DE ESTE SCRIPT. LEA LA DOCUMENTACIÓN ANTES DE EMPEZAR"


#Clonamos proyectos
git clone https://github.com/PDNMX/SistemaDeclaraciones_frontend.git
git clone https://github.com/PDNMX/SistemaDeclaraciones_backend.git
git clone https://github.com/PDNMX/SistemaDeclaraciones_reportes.git

#Instalamos dependencias:
sudo apt update
sudo apt install --yes php-cli php-mongodb docker docker-compose mongodb composer
# Procedemos a instalar las dependencias

composer install

echo "$Blue ¿Ya modifico las variables del archivo .env (S = Sí, N = No)?"

# Ask the user for their name

read -r varname

if [[ "$varname" == 'S'  ]]
then
#Iniciamos la preparación del sistema:
php ./src/prepara.php

echo "$Blue ¿Deseas iniciar con la creacion de las instancias de Docker (S = Sí, N = No) ?"
read -r compilarimagenes

if [[ "$compilarimagenes" == "S" ]]
then
#Iniciamos la construcción del contenedor:
echo "$Blue Iniciando instancias"

cd SistemaDeclaraciones_backend
sudo docker-compose -p declaraciones-backend up -d --build --force-recreate
cd ..
cd SistemaDeclaraciones_frontend
sudo docker-compose -p declaraciones-frontend up -d --build --force-recreate
fi
echo "$Green **** Proceso terminado ****"
else
echo "Proceso detenido (No presiono S), lea la documentación para más información".
fi