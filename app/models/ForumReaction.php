<?php

namespace app\models;

use app\core\Database;
use PDOException;

class ForumReaction {
    private $conn;
    private $table = 'forums_reactions';
    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function create($data) {
        $query = "INSERT INTO {$this->table} (forum_id, user_id, reaction) VALUES (:forum_id, :user_id, :reaction)";
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':forum_id', $data['forum_id']);
            $stmt->bindParam(':user_id', $data['user_id']);
            $stmt->bindParam(':reaction', $data['reaction']);
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            if ($e->getCode() == '23505') {
                return false;
            }
            throw $e;
        }
    }

    public function update($data) {
        $query = "UPDATE {$this->table} SET reaction = :reaction WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':reaction', $data['reaction']);
        $stmt->bindParam(':id', $data['id']);
        $stmt->execute();
        return $stmt->rowCount();
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

    public function findByForumId($forumId) {
        $query = "SELECT * FROM {$this->table} WHERE forum_id = :forum_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':forum_id', $forumId);
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

    public function findByForumIdAndUserId($forumId, $userId) {
        $query = "SELECT * FROM {$this->table} WHERE forum_id = :forum_id AND user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':forum_id', $forumId);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        return $stmt->fetch();
    }
}