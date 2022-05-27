<?php
const SISTEMA = 'SistemaDeclaraciones';

try{
    $headerFile = file_get_contents('./src/header.component.html');
    $acuseFile = file_get_contents('./src/acuse.html');

    /**
     * Preparamos la image
     * que debe ser nombrado extrictamente como logo.jpeg para agregar al sistema.
     */

    $img_file = './src/logo.jpeg';
    $imgData = base64_encode(file_get_contents($img_file));
    $src = 'data: '.mime_content_type($img_file).';base64,'.$imgData;

    $headerFileChange = str_replace('{logo}', $src, $headerFile);
    $acuseFileChange = str_replace('{logo}', $src, $acuseFile);

    // Copiamos los archivos HTML modificados:

    /**
     * Header FRONT
     * Agregamos un texto de soporte y el logotipo del municipio.
     */
    if(!file_put_contents(
        './'.SISTEMA.'_frontend/src/app/@shared/header/header.component.html',
        $headerFileChange
    )){
        throw new Exception('Error al copiar el archivo de cabecera');
    }

    // Modificacion Acuse:
    if(!file_put_contents(
        './'.SISTEMA.'_reportes/templates/acuse.html',
        $headerFileChange
    )){
        throw new Exception('Error al copiar el archivo de cabecera');
    }

    /**
     * Copiamos el json para los textos.
     * Que son usados paa mostrar el PDF del acuse de declaración
     *
     */
    {
        $json = file_get_contents('./src/variables.json');
        if(!file_put_contents(
            './'.SISTEMA.'_reportes/assets/json/modificacion.json',
            $json
        )){
            throw new Exception('Error al copiar el json de modificacion');
        }

        if(!file_put_contents(
            './'.SISTEMA.'_reportes/assets/json/inicio.json',
            $json
        )){
            throw new Exception('Error al copiar el json de modificacion');
        }

        if(!file_put_contents(
            './'.SISTEMA.'_reportes/assets/json/conclusion.json',
            $json
        )){
            throw new Exception('Error al copiar el json de modificacion');
        }
    }

    /**
     *  Copiamos ahora los archivos .env de configuración.
     * Nota: si requiere ajustar los valores para adecuar al sistema favor
     * cambiarlos antes de inicar el script.
     */

    {
        // Archivo de configuración del backend:
        $EnvBackend = file_get_contents('./src/.env.example.backend');

        if(!$EnvBackend)
            throw new Exception('No fue posible ubicar el archivo ejemplo del backend');

        if(!file_put_contents(
            './'.SISTEMA.'_backend/.env',
            $EnvBackend
        )){
            throw new Exception('Error al copiar .env del Backend');
        }

        // Archivo de configuración del reporteador:
        $EnvReporteador = file_get_contents('./src/.env.example.reportes');

        if(!$EnvReporteador)
            throw new Exception('No fue posible ubicar el archivo ejemplo del reporteador');

        if(!file_put_contents(
            './'.SISTEMA.'_reportes/.env',
            $EnvReporteador
        )){
            throw new Exception('Error al copiar .env del Reporteador');
        }
    }


}
catch (Exception $exception){
    echo "\e[1;37;41m***************** ".$exception->getMessage()." ********************\e[0m\n";
    exit;
}

echo "\e[1;37;42m ----------------- Preparación Terminada! ------------------------\e[0m\n";;
