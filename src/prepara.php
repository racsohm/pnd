<?php
require_once './vendor/autoload.php';
require_once 'variables.php';

try{
    // Leemos el .env para iniciar con las modificaciones del sistema:
    $dotenvBack = Dotenv\Dotenv::createImmutable(getcwd());
    $dotenvBack->load();

    // Ahora creamos la base de datos:

    // COPIAMOS EL LOGO
    $headerFile = file_get_contents('./src/header.component.html');
    $acuseFile = file_get_contents('./src/acuse.html');




    /**
     * Preparamos la image
     * que debe ser nombrado extrictamente como logo.jpeg para agregar al sistema.
     */

    $img_file = $_ENV['URL_LOGO'];
    if(!$imgData = file_get_contents($img_file))
        throw new Exception('Error al obtener la imagen');


    $file_info = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $file_info->buffer($imgData);

    $src = 'data:'.$mime_type.';base64,'.base64_encode($imgData);

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
        throw new Exception('Error al copiar el archivo de acuse');
    }

    /**
     * Copiamos el json para los textos.
     * Que son usados paa mostrar el PDF del acuse de declaración
     *
     */
    {
            \Racsohm\Pnd\TextosJsonCopy::procesar('inicial');
            \Racsohm\Pnd\TextosJsonCopy::procesar('modificacion');
            \Racsohm\Pnd\TextosJsonCopy::procesar('conclusion');

    }

    /**
     *  Copiamos ahora los archivos .env de configuración.
     * Nota: si requiere ajustar los valores para adecuar al sistema favor
     * cambiarlos antes de inicar el script.
     */

    {
        \Racsohm\Pnd\EnvProcesor::procesar('backend');
        \Racsohm\Pnd\EnvProcesor::procesar('reportes');
    }


}
catch (Exception $exception){
    echo "\e[1;37;41m***************** ".$exception->getMessage()." ********************\e[0m\n";
    exit;
}

echo "\e[1;37;42m ----------------- Preparación Terminada! ------------------------\e[0m\n";;
