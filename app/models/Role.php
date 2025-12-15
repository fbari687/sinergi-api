<?php
namespace app\models;

use app\core\Database;
use PDO;

class Role {
    private $conn;
    private $table = 'roles';

    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function findIdByName($name) {
        $query = "SELECT id FROM {$this->table} WHERE name = :name";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->execute();
        return $stmt->fetchColumn();
    }
}