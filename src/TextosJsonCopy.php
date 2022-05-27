<?php

namespace Racsohm\Pnd;

class TextosJsonCopy
{
    protected $tipo_actual = null;
    protected $file = null;
    protected $savePath= './SistemaDeclaraciones_reportes/assets/json/';
    protected $env = 'ENV_TEXTO_ACUSE';

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

            default:
                throw new \Exception('No indico un tipo de texto valido: '.$tipo);
                break;
        }

        if(!$this->file = @file_get_contents('./src/json/'.$this->tipo_actual))
            throw new \Exception('Error al cargar la base: '.$this->tipo_actual);


    }

    /**
     * @return void
     */
    protected function reemplaza(){
        $envs = constant($this->env);

        $target = [];
        $values = [];
        foreach ($envs as $item){
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
                $this->savePath.$this->tipo_actual,
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