<?php

namespace app\models;

use app\core\Database;
use PDO;

class Comment {
    private $conn;
    private $table = 'comments';
    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function create($data) {
        $query = "INSERT INTO {$this->table} (post_id, user_id, parent_id, content) VALUES (:post_id, :user_id, :parent_id, :content)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':post_id', $data['post_id']);
        $stmt->bindParam(':user_id', $data['user_id']);
        $stmt->bindParam(':parent_id', $data['parent_id']);
        $stmt->bindParam(':content', $data['content']);
        return $stmt->execute();
    }

    public function findPostComments($postId) {
        // Tambahkan subquery: (SELECT COUNT(*) FROM comments c2 WHERE c2.parent_id = c.id) as reply_count
        $query = "SELECT c.id, c.content, c.user_id, c.post_id, c.created_at, 
                     u.username, u.path_to_profile_picture, r.name AS role,
                     (SELECT COUNT(*) FROM {$this->table} c2 WHERE c2.parent_id = c.id) as reply_count 
              FROM {$this->table} c 
              JOIN users u ON c.user_id = u.id 
              JOIN roles r ON u.role_id = r.id 
              WHERE post_id = :post_id AND parent_id IS NULL 
              ORDER BY c.created_at ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':post_id', $postId);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findCommentReplies($parentId) {
        // Tambahkan juga reply_count di sini untuk balasan bertingkat (opsional, tapi disarankan)
        $query = "SELECT c.id, c.content, c.user_id, c.post_id, c.created_at, 
                     u.username, u.path_to_profile_picture, r.name AS role,
                     (SELECT COUNT(*) FROM {$this->table} c2 WHERE c2.parent_id = c.id) as reply_count
              FROM {$this->table} c 
              JOIN users u ON c.user_id = u.id 
              JOIN roles r ON u.role_id = r.id 
              WHERE parent_id = :parent_id 
              ORDER BY c.created_at ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':parent_id', $parentId);
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

    public function delete($id): bool {
        $query = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function getCommentIdsByPostId(int $postId): array
    {
        $query = "SELECT id FROM {$this->table} WHERE post_id = :post_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':post_id', $postId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

}