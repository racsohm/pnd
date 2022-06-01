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
echo "$Red COMPATIBLE POR EL MOMENTO CON UBUNTU 20.04 FOCAL"

printf "\n"
printf "\n"
echo "$Red Por favor escriba 'OK', para saber que esta de acuerdo"
read -r okvar

if [[ "$okvar" != 'OK'  ]]
then
  echo "$Red Proceso detenido, gracias."
  exit
fi

printf "\n"
printf "\n"
echo "$Blue Iniciando proceso:"

#Clonamos proyectos
git clone https://github.com/PDNMX/SistemaDeclaraciones_frontend.git
git clone https://github.com/PDNMX/SistemaDeclaraciones_backend.git
git clone https://github.com/PDNMX/SistemaDeclaraciones_reportes.git

#Instalamos dependencias:
apt update
# Instalación de MONGO:

 apt-get install gnupg
wget -qO - https://www.mongodb.org/static/pgp/server-5.0.asc | apt-key add -
echo "deb http://repo.mongodb.org/apt/debian bullseye/mongodb-org/5.0 main" | sudo tee /etc/apt/sources.list.d/mongodb-org-5.0.listapt-get update
apt-get install -y mongodb-org

# Install DOcker
apt-get install \
    ca-certificates \
    curl \
    gnupg \
    lsb-release

mkdir -p /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg

echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/debian \
  $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null

apt-get update
apt-get install --yes docker-ce docker-ce-cli containerd.io docker-compose-plugin docker-compose

# Instalamos PHP
apt install --yes php-cli php-dev php-mongodb
# INSTALAMOS MONGO:
pecl install mongodb
systemctl enable mongod
systemctl stop mongod
systemctl restart mongod


# Instalamos composer:
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php -r "if (hash_file('sha384', 'composer-setup.php') === '55ce33d7678c5a611085589f1f3ddf8b3c52d662cd01d4ba75c0ee0459970c2200a51f492d557530c71c15d8dba01eae') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"
php composer-setup.php
php -r "unlink('composer-setup.php');"
mv composer.phar /usr/local/bin/composer
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
echo "$Blue ************* Iniciando instancia BackeEnd"
cd SistemaDeclaraciones_backend
docker-compose -p declaraciones-backend up -d --build --force-recreate
cd ..
echo "$Blue ************* Iniciando instancia FrontEnd"
cd SistemaDeclaraciones_frontend
docker-compose -p declaraciones-frontend up -d --build --force-recreate
fi
echo "$Green **** Proceso terminado ****"
else
echo "Proceso detenido (No presiono S), lea la documentación para más información".
fi