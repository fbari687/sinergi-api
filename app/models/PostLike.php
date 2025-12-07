<?php

namespace app\models;

use app\core\Database;
use PDOException;

class PostLike {
    private $conn;

    private $table = 'post_likes';
    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }
    public function create($data) {
        $query = "INSERT INTO {$this->table} (post_id, user_id) VALUES (:post_id, :user_id)";
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':post_id', $data['post_id']);
            $stmt->bindParam(':user_id', $data['user_id']);
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            if ($e->getCode() == '23505') {
                return false;
            }
            throw $e;
        }
    }

    public function delete($id) {
        $query = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function findById($id) {
        $query = "SELECT * FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch();
    }

    public function findByPostAndUserId($postId, $userId) {
        $query = "SELECT * FROM {$this->table} WHERE post_id = :post_id AND user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':post_id', $postId);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        return $stmt->fetch();
    }

    public function findByPostId($postId) {
        $query = "SELECT * FROM {$this->table} WHERE post_id = :post_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':post_id', $postId);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findByUserId($userId) {
        $query = "SELECT * FROM {$this->table} WHERE user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}