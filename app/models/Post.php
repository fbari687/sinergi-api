<?php

namespace app\models;

use app\core\Database;
use PDO; // Pastikan import PDO agar bisa pakai PDO::PARAM_INT

class Post
{
    private $conn;

    private $table = 'posts';

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    // UPDATE: Menambahkan parameter limit & offset
    public function getAllPostInOneCommunity($community_id, $user_id, $limit = 10, $offset = 0, $search = null)
    {
        // Query dasar (tanpa ORDER BY dan LIMIT dulu)
        $query = "SELECT v.*, 
                (pl_user.id IS NOT NULL) AS is_liked_by_user
                FROM v_posts_header v
                LEFT JOIN post_likes pl_user ON v.id = pl_user.post_id 
                AND pl_user.user_id = :currentUserId
                WHERE v.community_id = :community_id";

        // 1. Logika Pencarian Dinamis
        if ($search) {
            // Cari berdasarkan deskripsi postingan ATAU username pembuat
            $query .= " AND (v.description ILIKE :search OR v.username ILIKE :search)";
        }

        // 2. Tambahkan Order dan Limit setelah filter search
        $query .= " ORDER BY v.created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':currentUserId', $user_id);
        $stmt->bindParam(':community_id', $community_id);

        // 3. Bind Search Parameter jika ada
        if ($search) {
            $searchTerm = "%" . $search . "%"; // Tambahkan wildcard untuk LIKE
            $stmt->bindValue(':search', $searchTerm);
        }

        // Bind Limit & Offset
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);

        $stmt->execute();
        return $stmt->fetchAll();
    }

    // UPDATE: Menambahkan parameter limit & offset
    public function getAllPostInHome($user_id, $limit = 10, $offset = 0, $search = null)
    {
        // Query Dasar
        $query = "SELECT v.*, 
              (pl_user.id IS NOT NULL) AS is_liked_by_user
              FROM v_posts_header v
              LEFT JOIN post_likes pl_user ON v.id = pl_user.post_id 
              AND pl_user.user_id = :currentUserId
              WHERE v.community_id IS NULL";

        // --- 1. LOGIKA PENCARIAN (TAMBAHAN BARU) ---
        if ($search) {
            // Cari deskripsi ATAU username
            $query .= " AND (v.description ILIKE :search OR v.username ILIKE :search)";
        }
        // -------------------------------------------

        // Tambahkan Order dan Limit
        $query .= " ORDER BY v.is_pinned DESC, v.created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':currentUserId', $user_id);

        // --- 2. BIND PARAMETER SEARCH ---
        if ($search) {
            $searchTerm = "%" . $search . "%";
            $stmt->bindValue(':search', $searchTerm);
        }
        // --------------------------------

        // Bind Limit & Offset
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);

        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function togglePinStatus($id)
    {
        // Gunakan NOT is_pinned langsung di SQL.
        // RETURNING is_pinned digunakan untuk mendapatkan hasil akhirnya (khusus PostgreSQL)
        $query = "UPDATE {$this->table} SET is_pinned = NOT is_pinned WHERE id = :id RETURNING is_pinned";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);

        if ($stmt->execute()) {
            $result = $stmt->fetchColumn();
            // Kembalikan sebagai boolean asli PHP
            return filter_var($result, FILTER_VALIDATE_BOOLEAN);
        }

        return null;
    }

    public function findById($id)
    {
        $query = "SELECT * FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch();
    }

    public function getPostDetailById($post_id, $user_id)
    {
        $query = "SELECT v.*, (pl_user.id IS NOT NULL) AS is_liked_by_user
              FROM v_posts_header v
              LEFT JOIN post_likes pl_user ON v.id = pl_user.post_id AND pl_user.user_id = :currentUserId
              WHERE v.id = :post_id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':post_id', $post_id);
        $stmt->bindParam(':currentUserId', $user_id);

        $stmt->execute();
        return $stmt->fetch();
    }

    public function create(array $dataPost)
    {
        $query = "INSERT INTO {$this->table} (description, path_to_media, user_id, community_id) VALUES (:description, :path_to_media, :user_id, :community_id) RETURNING ID";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':description', $dataPost['description']);
        $stmt->bindParam(':path_to_media', $dataPost['path_to_media']);
        $stmt->bindParam(':user_id', $dataPost['user_id']);
        $stmt->bindParam(':community_id', $dataPost['community_id']);
        return $stmt->execute();
    }

    public function update($dataPost, $id)
    {
        $is_edited = true;
        $query = "UPDATE {$this->table} SET description = :description, path_to_media = :path_to_media, is_edited = :is_edited WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':description', $dataPost['description']);
        $stmt->bindParam(':path_to_media', $dataPost['path_to_media']);
        $stmt->bindParam(':is_edited', $is_edited);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function delete($id): bool
    {
        $query = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function getPostIdsByCommunityId(int $communityId): array
    {
        $query = "SELECT id FROM {$this->table} WHERE community_id = :community_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':community_id', $communityId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}