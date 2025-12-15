<?php

namespace app\models;

use app\core\Database;
use PDO;

class AccountRequest
{
    private $conn;
    private $table = 'account_requests';

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    // 1. Create Request Baru
    public function create($requesterId, $communityId, $data)
    {
        $query = "INSERT INTO {$this->table} 
                  (requester_id, community_id, email, username, fullname, role_name, profile_data, status) 
                  VALUES 
                  (:requester_id, :community_id, :email, :username, :fullname, :role_name, :profile_data, 'PENDING')";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':requester_id', $requesterId);
        $stmt->bindParam(':community_id', $communityId);
        $stmt->bindParam(':email', $data['email']);
        $stmt->bindParam(':username', $data['username']);
        $stmt->bindParam(':fullname', $data['fullname']);
        $stmt->bindParam(':role_name', $data['role']);

        // Data spesifik (tahun lulus, perusahaan, dll) disimpan sebagai JSON
        $jsonProfile = json_encode($data['profile_data']);
        $stmt->bindParam(':profile_data', $jsonProfile);

        return $stmt->execute();
    }

    // 2. Ambil semua request pending (Untuk Admin Sinergi)
    public function getAllPending()
    {
        $query = "SELECT ar.*, c.name as community_name, u.fullname as requester_name 
              FROM {$this->table} ar
              JOIN communities c ON ar.community_id = c.id
              JOIN users u ON ar.requester_id = u.id
              ORDER BY ar.created_at DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decode JSON profile_data supaya di frontend langsung berupa object
        foreach ($rows as &$row) {
            if (!empty($row['profile_data'])) {
                $row['profile_data'] = json_decode($row['profile_data'], true);
            } else {
                $row['profile_data'] = null;
            }
        }

        return $rows;
    }

    // 3. Cari berdasarkan ID
    public function findById($id)
    {
        $query = "SELECT * FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $result = $stmt->fetch();

        if ($result) {
            $result['profile_data'] = json_decode($result['profile_data'], true);
        }

        return $result;
    }

    // 4. Update Status & Token (Saat Approve)
    public function approve($id, $token)
    {
        $query = "UPDATE {$this->table} 
                  SET status = 'APPROVED', activation_token = :token 
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':token', $token);
        return $stmt->execute();
    }

    // 5. Reject Request
    public function reject($id)
    {
        $query = "UPDATE {$this->table} SET status = 'REJECTED' WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    // 6. Cek apakah email sedang dalam proses request (mencegah duplikat request)
    public function isEmailPending($email)
    {
        $query = "SELECT id FROM {$this->table} WHERE email = :email AND status = 'PENDING'";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        return $stmt->fetchColumn();
    }

    public function findByToken(string $token)
    {
        $query = "SELECT * FROM {$this->table} WHERE activation_token = :token LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':token', $token);
        $stmt->execute();
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($result && !empty($result['profile_data'])) {
            $result['profile_data'] = json_decode($result['profile_data'], true);
        }

        return $result ?: null;
    }

    public function markActivated(int $id): bool
    {
        $query = "UPDATE {$this->table} SET status = 'ACTIVATED' WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id, \PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function updateStatus($id, $status) {
        $query = "UPDATE {$this->table} SET status = :status WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':status' => $status,
            ':id' => $id
        ]);
    }
}