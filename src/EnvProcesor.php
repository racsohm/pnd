<?php

namespace Racsohm\Pnd;

class EnvProcesor extends TextosJsonCopy
{
    protected $savePath = './SistemaDeclaraciones_';

    public function __construct($tipo)
    {
        $this->me = __CLASS__;

         switch ($tipo){
             case "backend":
                 $this->env = 'ENV_BACKEND';
                 $this->savePath = './'.SISTEMA.'_';
                 $this->tipo_actual = 'backend/.env';
                 break;
             case "reportes":
                 $this->env = 'ENV_REPORTS';
                 $this->savePath = './'.SISTEMA.'_';
                 $this->tipo_actual = 'reportes/.env';
                 break;
         }

            if(!$this->file = file_get_contents('./src/'.$this->tipo_actual))
                throw new \Exception('Error al cargar la plantilla .ENV: '.$this->tipo_actual);
        }

    static function procesar($tipo){
        $self = new EnvProcesor($tipo);
        $self->reemplaza();
        $self->out();

        return $self;
    }

}