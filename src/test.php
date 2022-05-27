<?php
require_once "../vendor/autoload.php";
require_once "MongoMange.php";

$m = new \Racsohm\Pnd\MongoMange('localhost','deb_pnd','dev_usr','1205');
$m->connect();
$m->createDB();

var_dump($m);