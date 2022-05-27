<?php
require_once "../vendor/autoload.php";
require_once "MongoMange.php";

$mongo = new \Racsohm\Pnd\MongoMange('localhost','pdn_dev','pdn_user','1205');
$mongo->connect();
$mongo->createDB();

