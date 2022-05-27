#!/bin/bash

PhpFile="$(php --ini | grep 'Loaded Configuration File')";

readarray -d : -t strarr <<< "$PhpFile"
echo "Agregando extension mongo:"
printf "\n"
echo 'extension=mongodb.so' | sudo tee -a ${strarr[1]}

