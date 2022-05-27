<?php

namespace Racsohm\Pnd;

class MongoMange
{
    private $db_host = 'localhost';
    private $db_name = 'db_name';
    private $db_user = 'new_user';
    private $db_pass = 'new_pass';
    private $db_port = '27017';

    private  $connection;

    public function __construct($db_host,$db_name,$db_user,$db_pass,$db_port=27017)
    {
        $this->db_host = $db_host;
        $this->db_user = $db_user;
        $this->db_name = $db_name;
        $this->db_pass = $db_pass;

    }

    public function connect(){
        $this->connection = new \MongoDB\Driver\Manager('mongodb://'.$this->db_host.':'.$this->db_port);
    }



    public function createDB(){

        $commandArr = array
        (
            "createUser" => $this->db_user,
            "pwd"        => $this->db_pass,
            "roles"      => array
            (
                array("role" => "dbOwner", "db" => $this->db_name)
            )
        );

        $command = new \MongoDB\Driver\Command($commandArr);

        try {
            $cursor = $this->connection->executeCommand('admin', $command);
        } catch(\MongoDB\Driver\Exception $e) {
            echo $e->getMessage(), "\n";
            exit;
        }
    }
}