<?php

namespace app\models;

use app\core\Database;
use PDOException;

class ForumRespondReaction {
    private $conn;
    private $table = 'forums_respond_reactions';
    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function create($data) {
        $query = "INSERT INTO {$this->table} (forum_respond_id, user_id, reaction) VALUES (:forum_respond_id, :user_id, :reaction)";
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':forum_respond_id', $data['forum_respond_id']);
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

    public function findByForumRespondId($forumRespondId) {
        $query = "SELECT * FROM {$this->table} WHERE forum_respond_id = :forum_respond_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':forum_respond_id', $forumRespondId);
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

    public function findByForumRespondIdAndUserId($forumRespondId, $userId) {
        $query = "SELECT * FROM {$this->table} WHERE forum_respond_id = :forum_respond_id AND user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':forum_respond_id', $forumRespondId);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        return $stmt->fetch();
    }
}