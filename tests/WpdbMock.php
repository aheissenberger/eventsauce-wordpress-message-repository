<?php
namespace EventSauce\WordpressMessageRepository\Tests;

class WpdbMock{
    public function __construct()
    {
        $this->dbh=\mysqli_connect("db","mariadb","mariadb","mariadb");
    }
    public function connection()
    {
        return $this;
    }
}