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
        $query = "SELECT p.id, p.description, p.path_to_media, p.is_edited, p.user_id, p.community_id, u.fullname, u.username, u.path_to_profile_picture, r.name AS role, p.created_at, p.updated_at, (pl_user.id IS NOT NULL) AS is_liked_by_user,
            COALESCE(lc.like_count, 0) AS like_count,
            COALESCE(cc.comment_count, 0) AS comment_count
            FROM {$this->table} p
            JOIN users u ON p.user_id = u.id
            JOIN roles r ON u.role_id = r.id
            LEFT JOIN (
                SELECT post_id, COUNT(*) AS like_count
                FROM post_likes
                GROUP BY post_id
            ) AS lc ON p.id = lc.post_id
            LEFT JOIN (
                SELECT post_id, COUNT(*) AS comment_count
                FROM comments
                GROUP BY post_id
            ) AS cc ON p.id = cc.post_id
            LEFT JOIN post_likes pl_user ON p.id = pl_user.post_id 
            AND pl_user.user_id = :currentUserId
            WHERE p.community_id = :community_id";

        // 1. Logika Pencarian Dinamis
        if ($search) {
            // Cari berdasarkan deskripsi postingan ATAU username pembuat
            $query .= " AND (p.description ILIKE :search OR u.username ILIKE :search)";
        }

        // 2. Tambahkan Order dan Limit setelah filter search
        $query .= " ORDER BY p.created_at DESC LIMIT :limit OFFSET :offset";

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
        $query = "SELECT p.id, p.description, p.path_to_media, p.is_edited, p.user_id, u.fullname, u.username, u.path_to_profile_picture, r.name AS role, p.created_at, p.updated_at, (pl_user.id IS NOT NULL) AS is_liked_by_user,
            COALESCE(lc.like_count, 0) AS like_count,
            COALESCE(cc.comment_count, 0) AS comment_count
            FROM {$this->table} p
            JOIN users u ON p.user_id = u.id
            JOIN roles r ON u.role_id = r.id
            LEFT JOIN (
                SELECT post_id, COUNT(*) AS like_count
                FROM post_likes
                GROUP BY post_id
            ) AS lc ON p.id = lc.post_id
            LEFT JOIN (
                SELECT post_id, COUNT(*) AS comment_count
                FROM comments
                GROUP BY post_id
            ) AS cc ON p.id = cc.post_id
            LEFT JOIN post_likes pl_user ON p.id = pl_user.post_id 
            AND pl_user.user_id = :currentUserId        
            WHERE p.community_id IS NULL";

        // --- 1. LOGIKA PENCARIAN (TAMBAHAN BARU) ---
        if ($search) {
            // Cari deskripsi ATAU username
            $query .= " AND (p.description ILIKE :search OR u.username ILIKE :search)";
        }
        // -------------------------------------------

        // Tambahkan Order dan Limit
        $query .= " ORDER BY p.created_at DESC LIMIT :limit OFFSET :offset";

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
        $query = "SELECT p.id, p.description, p.path_to_media, p.community_id, p.user_id, u.fullname, u.username, u.path_to_profile_picture, r.name AS role, p.created_at, p.updated_at, (pl_user.id IS NOT NULL) AS is_liked_by_user,
                COALESCE(lc.like_count, 0) AS like_count,
                COALESCE(cc.comment_count, 0) AS comment_count
                FROM {$this->table} p
                JOIN users u ON p.user_id = u.id
                JOIN roles r ON u.role_id = r.id
                LEFT JOIN (
                    SELECT post_id, COUNT(*) AS like_count
                    FROM post_likes
                    GROUP BY post_id
                ) AS lc ON p.id = lc.post_id
                LEFT JOIN (
                    SELECT post_id, COUNT(*) AS comment_count
                    FROM comments
                    GROUP BY post_id
                ) AS cc ON p.id = cc.post_id
                LEFT JOIN post_likes pl_user ON p.id = pl_user.post_id 
                AND pl_user.user_id = :currentUserId        
                WHERE p.id = :post_id
                ";

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
}