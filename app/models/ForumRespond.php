<?php

namespace app\models;

use app\core\Database;

class ForumRespond {
    private $conn;
    private $table = 'forums_responds';
    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function create($userId, $forumId, $message, $parentId = null) {
        $query = "INSERT INTO {$this->table} (user_id, forum_id, message, parent_id) 
                  VALUES (:user_id, :forum_id, :message, :parent_id) RETURNING id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':forum_id', $forumId);
        $stmt->bindParam(':message', $message);
        $stmt->bindParam(':parent_id', $parentId);

        if ($stmt->execute()) {
            return $stmt->fetch();
        }
        return false;
    }

    public function getAnswersByForumId($forumId) {
        $query = "SELECT 
                    fr.*,
                    u.fullname, u.username, u.path_to_profile_picture as profile_picture
                  FROM {$this->table} fr
                  JOIN users u ON fr.user_id = u.id
                  WHERE fr.forum_id = :forum_id 
                    AND fr.parent_id IS NULL -- Hanya jawaban utama
                  ORDER BY 
                    fr.is_accepted DESC, -- Solusi terpilih selalu paling atas (ala StackOverflow)
                    fr.created_at ASC";  // Jawaban lama di atas (kronologis)

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':forum_id', $forumId);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getRepliesByParentId($parentId) {
        $query = "SELECT 
                    fr.*,
                    u.fullname, u.username, u.path_to_profile_picture as profile_picture
                  FROM {$this->table} fr
                  JOIN users u ON fr.user_id = u.id
                  WHERE fr.parent_id = :parent_id
                  ORDER BY fr.created_at ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':parent_id', $parentId);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function markAsAccepted($respondId, $forumId) {
        try {
            $this->conn->beginTransaction();

            // 1. Reset semua 'is_accepted' di forum ini menjadi false
            // (Karena hanya boleh ada 1 solusi per topik)
            $resetQuery = "UPDATE {$this->table} SET is_accepted = FALSE WHERE forum_id = :forum_id";
            $stmtReset = $this->conn->prepare($resetQuery);
            $stmtReset->bindParam(':forum_id', $forumId);
            $stmtReset->execute();

            // 2. Set respond yang dipilih menjadi true
            $updateQuery = "UPDATE {$this->table} SET is_accepted = TRUE WHERE id = :id";
            $stmtUpdate = $this->conn->prepare($updateQuery);
            $stmtUpdate->bindParam(':id', $respondId);
            $stmtUpdate->execute();

            $this->conn->commit();
            return true;
        } catch (\Exception $e) {
            $this->conn->rollBack();
            return false;
        }
    }

    public function findForumResponds($forumId) {
        $query = "SELECT * FROM {$this->table} WHERE forum_id = :forum_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':forum_id', $forumId);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findById($id) {
        $query = "SELECT * FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch();
    }

    public function update($id, $data) {
        $is_edited = true;
        $query = "UPDATE {$this->table} SET message = :message, is_edited = :is_edited WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':message', $data['message']);
        $stmt->bindParam(':is_edited', $is_edited);
        return $stmt->execute();
    }

    public function delete($id) {
        $query = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }
}