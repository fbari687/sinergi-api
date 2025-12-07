<?php

namespace app\models;

use app\core\Database;
use PDO;
use PDOException;

class Notification {
    private $conn;
    private $table = 'notifications';

    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }

    // [DIUBAH] Menyesuaikan dengan kolom baru database
    public function create($userId, $type, $message, $link, $actorId = null, $referenceId = null) {
        $query = "INSERT INTO {$this->table} 
                  (user_id, type, message, link_to_page, actor_id, reference_id, read_at) 
                  VALUES (:user_id, :type, :message, :link_to_page, :actor_id, :reference_id, NULL)";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $userId);
            $stmt->bindParam(':type', $type);
            $stmt->bindParam(':message', $message);
            $stmt->bindParam(':link_to_page', $link);

            // Handle Nullable Integer
            $stmt->bindValue(':actor_id', $actorId, $actorId ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':reference_id', $referenceId, $referenceId ? PDO::PARAM_INT : PDO::PARAM_NULL);

            $stmt->execute();
            return true;
        } catch (PDOException $e) {
            // Log error jika perlu: error_log($e->getMessage());
            return false;
        }
    }

    public function getAllByUserId($userId) {
        $query = "SELECT * FROM {$this->table} WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 50"; // Limit agar tidak terlalu berat
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // [BARU] Untuk menampilkan angka di lonceng notifikasi (misal: "3")
    public function countUnread($userId)
    {
        // Hitung baris dimana user_id cocok DAN read_at masih kosong
        $query = "SELECT COUNT(*) as total FROM notifications WHERE user_id = :user_id AND read_at IS NULL";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':user_id', $userId);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        // Pastikan return integer, bukan string
        return (int) ($result['total'] ?? 0);
    }

    public function markAsReadById($id) {
        // Gunakan current timestamp database
        $query = "UPDATE {$this->table} SET read_at = CURRENT_TIMESTAMP WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function markAsReadAll($userId) {
        $query = "UPDATE {$this->table} SET read_at = CURRENT_TIMESTAMP WHERE user_id = :user_id AND read_at IS NULL";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        return $stmt->execute();
    }

    public function delete($id) {
        $query = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }
}