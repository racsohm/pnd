<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment('Respaldo es libertad.');
})->purpose('Frase del día');
