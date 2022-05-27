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
sudo apt install --yes php-cli php-dev php-mongodb docker docker-compose mongodb
sudo pecl install mongodb
# Instalamos composer:
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php -r "if (hash_file('sha384', 'composer-setup.php') === '55ce33d7678c5a611085589f1f3ddf8b3c52d662cd01d4ba75c0ee0459970c2200a51f492d557530c71c15d8dba01eae') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"
php composer-setup.php
php -r "unlink('composer-setup.php');"
sudo mv composer.phar /usr/local/bin/composer
# HORA COPIAMOS EL .extension=mongodb.so

#echo "/mnt/pg_master/wal_archives     10.20.20.5(rw,sync,no_root_squash)" >> /etc/exports
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
echo "$Blue Iniciando instancia BackeEnd"
cd SistemaDeclaraciones_backend
sudo docker-compose -p declaraciones-backend up -d --build --force-recreate
cd ..
echo "$Blue Iniciando instancia FrontEnd"
cd SistemaDeclaraciones_frontend
sudo docker-compose -p declaraciones-frontend up -d --build --force-recreate
fi
echo "$Green **** Proceso terminado ****"
else
echo "Proceso detenido (No presiono S), lea la documentación para más información".
fi