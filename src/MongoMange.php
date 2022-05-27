<?php

namespace Racsohm\Pnd;

class MongoMange
{
    private $db_host = 'localhost';
    private $db_name = 'db_name';
    private $db_user = 'new_user';
    private $db_pass = 'new_pass';

    private  $connection;

    public function __construct($db_host,$db_name,$db_user,$db_pass)
    {
        $this->db_host = $db_host;
        $this->db_user = $db_user;
        $this->db_name = $db_name;
        $this->db_pass = $db_pass;

    }

    public function connect(){

        $db = new \MongoDB\Client("mongodb://root:$this->db_user@$this->db_host/admin");


    }



    public function createDB(){
        $this->connection->selectDB( $this->db_name );

        $command = array
        (
            "createUser" => $this->db_user,
            "pwd"        => $this->db_pass,
            "roles"      => array
            (
                array("role" => "dbOwner", "db" => $this->db_name)
            )
        );

        $this->connection->command( $command );
    }
}