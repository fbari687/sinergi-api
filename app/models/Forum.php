<?php

namespace app\models;

use app\core\Database;
use PDO;

class Forum {
    private $conn;

    private $table = 'forums';

    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function create($userId, $communityId, $title, $description, $mediaPath = null) {
        $query = "INSERT INTO {$this->table} (user_id, community_id, title, description, path_to_media) 
                  VALUES (:user_id, :community_id, :title, :description, :path_to_media) RETURNING id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':community_id', $communityId);
        $stmt->bindParam(':title', $title);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':path_to_media', $mediaPath);

        if ($stmt->execute()) {
            return $stmt->fetch()['id'];
        }
        return false;
    }

    public function update($data, $id) {
        $is_edited = true;
        $query = "UPDATE {$this->table} SET title = :title, description = :description, path_to_media = :path_to_media, is_edited = :is_edited WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':title', $data['title']);
        $stmt->bindParam(':description', $data['description']);
        $stmt->bindParam(':path_to_media', $data['path_to_media']);
        $stmt->bindParam(':is_edited', $is_edited);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function getAllByCommunityId($community_id) {
        $query = "SELECT id, user_id, title, description, community_id, is_edited, created_at, updated_at FROM {$this->table} WHERE community_id = :community_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':community_id', $community_id);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getAllByCommunity($slug, $search = "", $limit = 10, $offset = 0) {
        $searchTerm = "%" . $search . "%";

        // Query join ke Users untuk info pembuat
        // Subquery (respond_count) menghitung jumlah jawaban utama (parent_id IS NULL)
        $query = "SELECT 
                    f.id, f.title, f.description, f.created_at, f.is_edited,
                    u.id as user_id, u.fullname, u.username, u.path_to_profile_picture as profile_picture,
                    (SELECT COUNT(*) FROM forums_responds fr WHERE fr.forum_id = f.id) as answer_count
                  FROM {$this->table} f
                  JOIN communities c ON f.community_id = c.id
                  JOIN users u ON f.user_id = u.id
                  WHERE c.slug = :slug
                    AND (f.title LIKE :search OR f.description LIKE :search)
                  ORDER BY f.created_at DESC
                  LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':slug', $slug);
        $stmt->bindParam(':search', $searchTerm);
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById($id, $currentUserId = null) {
        $query = "SELECT 
                    f.*, 
                    u.fullname, u.username, u.path_to_profile_picture as profile_picture,
                    r.name as role_name,
                    c.slug as community_slug, c.id as community_id,
                    -- Hitung Total Vote (SUM dari 1 dan -1)
                (SELECT COALESCE(SUM(reaction), 0) 
                 FROM forums_reactions 
                 WHERE forum_id = f.id) as vote_count,
                 
                -- Cek Vote User Login (Akan NULL jika tidak login/belum vote)
                (SELECT reaction 
                 FROM forums_reactions 
                 WHERE forum_id = f.id AND user_id = :user_id) as user_vote
                  FROM {$this->table} f
                  JOIN users u ON f.user_id = u.id
                      LEFT JOIN roles r ON u.role_id = r.id
                  JOIN communities c ON f.community_id = c.id
                  WHERE f.id = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':user_id', $currentUserId);
        $stmt->execute();
        return $stmt->fetch();
    }

    public function delete($id) {
        $query = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function getForumIdsByCommunityId(int $communityId): array
    {
        $query = "SELECT id FROM {$this->table} WHERE community_id = :community_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':community_id', $communityId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}