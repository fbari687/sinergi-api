<?php

namespace app\models;

use app\core\Database;
use PDO;

class ForumRespond {
    private $conn;
    private $table = 'forums_responds';
    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function create($userId, $forumId, $message, $parentId = null, $pathToMedia = null) {
        $query = "INSERT INTO {$this->table} (user_id, forum_id, message, parent_id, path_to_media) 
                  VALUES (:user_id, :forum_id, :message, :parent_id, :path_to_media) RETURNING id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':forum_id', $forumId);
        $stmt->bindParam(':message', $message);
        $stmt->bindParam(':parent_id', $parentId);
        $stmt->bindParam(':path_to_media', $pathToMedia);

        if ($stmt->execute()) {
            return $stmt->fetch();
        }
        return false;
    }

    public function getAnswersByForumId($forumId, $currentUserId = null, $sort = 'top', $limit = 10, $offset = 0) {
        // 1. Tentukan Logic Sorting
        // NOTE: fr.is_accepted DESC selalu ditaruh paling awal agar solusi tetap di atas (Pinned)
        $orderBy = "";

        switch ($sort) {
            case 'newest':
                // Terbaru: Solusi -> Tanggal Dibuat (Baru ke Lama)
                $orderBy = "fr.is_accepted DESC, fr.created_at DESC";
                break;
            case 'oldest':
                // Terlama: Solusi -> Tanggal Dibuat (Lama ke Baru)
                $orderBy = "fr.is_accepted DESC, fr.created_at ASC";
                break;
            case 'top':
            default:
                // Vote Terbanyak: Solusi -> Jumlah Vote -> Tanggal (Lama ke Baru)
                $orderBy = "fr.is_accepted DESC, vote_count DESC, fr.created_at ASC";
                break;
        }

        $query = "SELECT 
                fr.*,
                u.fullname, u.username, u.path_to_profile_picture as profile_picture,
                r.name as role_name,
                
                -- Hitung Total Vote per Jawaban
                (SELECT COALESCE(SUM(reaction), 0) 
                 FROM forums_respond_reactions 
                 WHERE forum_respond_id = fr.id) as vote_count,
                 
                -- Cek Vote User Login per Jawaban
                (SELECT reaction 
                 FROM forums_respond_reactions 
                 WHERE forum_respond_id = fr.id AND user_id = :user_id) as user_vote

              FROM {$this->table} fr
              JOIN users u ON fr.user_id = u.id
              LEFT JOIN roles r ON u.role_id = r.id
              WHERE fr.forum_id = :forum_id 
                AND fr.parent_id IS NULL 
              ORDER BY {$orderBy}
              LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':forum_id', $forumId);
        $stmt->bindValue(':user_id', $currentUserId);
        // Gunakan bindValue dengan PARAM_INT untuk limit & offset
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);

        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countByForumId($forumId) {
        $query = "SELECT COUNT(*) as total FROM {$this->table} WHERE forum_id = :forum_id AND parent_id IS NULL";
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':forum_id', $forumId);
        $stmt->execute();
        $result = $stmt->fetch();
        return $result ? $result['total'] : 0;
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
        $query = "UPDATE {$this->table} SET message = :message, path_to_media = :path_to_media, is_edited = :is_edited WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':message', $data['message']);
        $stmt->bindParam(':path_to_media', $data['path_to_media']);
        $stmt->bindParam(':is_edited', $is_edited);
        return $stmt->execute();
    }

    public function delete($id) {
        $query = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function getRespondIdsByForumId(int $forumId): array
    {
        $query = "SELECT id FROM {$this->table} WHERE forum_id = :forum_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':forum_id', $forumId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}