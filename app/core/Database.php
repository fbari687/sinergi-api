<?php

namespace app\core;

use app\helpers\ResponseFormatter;
use PDO;
use PDOException;

class Database {
    private static $instance = null;
    private $conn;

    private function __construct() {
        $driver = $_ENV['DB_DRIVER'];
        $host = $_ENV['DB_HOST'];
        $port = $_ENV['DB_PORT'];
        $dbname = $_ENV['DB_NAME'];
        $username = $_ENV['DB_USERNAME'];
        $password = $_ENV['DB_PASS'];

        $dsn = "{$driver}:host={$host};port={$port};dbname={$dbname}";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->conn = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            ResponseFormatter::error('Database connection failed: ' . $e->getMessage(), 500);
        }
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->conn;
    }

    // Tambahkan destructor untuk menutup koneksi
    public function __destruct() {
        if ($this->conn) {
            unset($this->conn);
        }
    }
}