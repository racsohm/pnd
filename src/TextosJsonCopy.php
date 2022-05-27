<?php

namespace Racsohm\Pnd;

class TextosJsonCopy
{
    private $tipo_actual = null;
    private $file = null;
    private $savePath= null;

    /**
     * @throws \Exception
     */
    public function __construct($tipo)
    {

        switch ($tipo){
            case "inicial":
                $this->tipo_actual = "inicio.json";
                break;
            case "modificacion":
                $this->tipo_actual = "modificacion.json";
                break;
            case "conclusion":
                $this->tipo_actual = "conclusion.json";
                break;
        }

        if(!$this->file = file_get_contents('./src/json/'.$this->tipo_actual))
            throw new \Exception('Error al cargar la plantilla JSON inicial');


    }

    /**
     * @return void
     */
    private function reemplaza(){

        $target = [];
        $values = [];
        foreach (ENV_TEXTO_ACUSE as $item){
            $target[] = "{".$item."}";
            $values[] = $_ENV["$item"];
        }

        $this->file = str_replace($target,$values,$this->file);

    }

    /**
     * @param $save
     * @return false|int|string
     */
    public function out(  $save = true){
        if($save === false){
            return $this->file;
        }
        else{
            return file_put_contents(
                './SistemaDeclaraciones_reportes/assets/json/'.$this->tipo_actual,
                $this->file
            );
        }

    }

    /**
     * @param $tipo
     * @return TextosJsonCopy
     * @throws \Exception
     */
    static function procesar($tipo){
        $self = new TextosJsonCopy($tipo);
        $self->reemplaza();
        $self->out();

        return $self;
    }
}