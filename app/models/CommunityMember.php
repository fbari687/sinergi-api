<?php

namespace app\models;

use app\core\Database;
use PDOException;
use PDO;

class CommunityMember
{
    private $conn;

    private $table = "community_members";

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function join($userId, $communityId, $role, $status)
    {
        $query = "INSERT INTO {$this->table} (role, status, user_id, community_id) VALUES (:role, :status, :user_id, :community_id)";

        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':role', $role);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':user_id', $userId);
            $stmt->bindParam(':community_id', $communityId);
            $stmt->execute();

            return true;
        } catch (PDOException $e) {
            if ($e->getCode() == '23505') {
                return false;
            } else {
                throw $e;
            }
        }
    }

    public function leave($userId, $communityId)
    {
        $query = "DELETE FROM {$this->table} WHERE user_id = :user_id AND community_id = :community_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':community_id', $communityId);
        return $stmt->execute();
    }

    public function findRoleUserById($userId, $communityId)
    {
        $query = "SELECT role, status FROM {$this->table} WHERE user_id = :user_id AND community_id = :community_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':community_id', $communityId);
        $stmt->execute();
        return $stmt->fetch();
    }

    public function getCommunitiesByUserId(int $userId, ?int $limit = null)
    {
        $sql = "
        SELECT 
            c.slug,
            c.name,
            c.path_to_thumbnail,
            c.is_public,
            cm.role AS current_user_role,
            COUNT(all_members.id) AS total_members
        FROM {$this->table} cm
        JOIN communities c ON cm.community_id = c.id
        JOIN community_members all_members 
            ON all_members.community_id = c.id 
           AND all_members.status = 'GRANTED'
        WHERE cm.user_id = :user_id 
          AND cm.status = 'GRANTED'
        GROUP BY c.id, c.slug, c.name, c.path_to_thumbnail, c.is_public, cm.role
        ORDER BY c.created_at DESC
    ";

        // Tambahkan LIMIT hanya jika ada
        if ($limit !== null) {
            $sql .= " LIMIT :limit";
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);

        if ($limit !== null) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchAll();
    }

    // 1. AMBIL ADMIN & OWNER (Tanpa Limit, karena biasanya jumlahnya sedikit)
    public function getAdminsBySlug($slug, $search = "") {
        $searchTerm = "%" . $search . "%";

        $query = "SELECT 
                    u.id, u.fullname, u.username, u.path_to_profile_picture,
                    cm.role, cm.created_at
                  FROM community_members cm
                  JOIN users u ON cm.user_id = u.id
                  JOIN communities c ON cm.community_id = c.id
                  WHERE c.slug = :slug 
                    AND cm.status = 'GRANTED'
                    AND cm.role IN ('OWNER', 'ADMIN')
                    AND (u.fullname LIKE :search OR u.username LIKE :search)
                  ORDER BY 
                    CASE WHEN cm.role = 'OWNER' THEN 1 ELSE 2 END, -- Owner selalu paling atas
                    cm.created_at ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':slug', $slug);
        $stmt->bindParam(':search', $searchTerm);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // 2. AMBIL ANGGOTA BIASA (Dengan LIMIT & OFFSET untuk Infinite Scroll)
    public function getMembersBySlug($slug, $search = "", $limit = 10, $offset = 0) {
        $searchTerm = "%" . $search . "%";

        $query = "SELECT 
                    u.id, u.fullname, u.username, u.path_to_profile_picture,
                    cm.role, cm.created_at
                  FROM community_members cm
                  JOIN users u ON cm.user_id = u.id
                  JOIN communities c ON cm.community_id = c.id
                  WHERE c.slug = :slug 
                    AND cm.status = 'GRANTED'
                    AND cm.role = 'MEMBER' -- Hanya member biasa
                    AND (u.fullname LIKE :search OR u.username LIKE :search)
                  ORDER BY cm.created_at DESC -- Member baru di atas
                  LIMIT :limit OFFSET :offset";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':slug', $slug);
        $stmt->bindParam(':search', $searchTerm);

        // PDO limit/offset harus tipe integer
        $stmt->bindParam(':limit', $limit);
        $stmt->bindParam(':offset', $offset);

        $stmt->execute();
        return $stmt->fetchAll();
    }

    // 3. Hitung Total (Untuk Statistik)
    public function countMembersByRole($slug, $roles = []) {
        // $roles bisa ['MEMBER'] atau ['ADMIN', 'OWNER']
        $placeholders = implode(',', array_fill(0, count($roles), '?'));

        $query = "SELECT COUNT(*) as total 
                  FROM community_members cm
                  JOIN communities c ON cm.community_id = c.id
                  WHERE c.slug = ? 
                    AND cm.status = 'GRANTED' 
                    AND cm.role IN ($placeholders)";

        $params = array_merge([$slug], $roles);
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);

        $result = $stmt->fetch();
        return $result ? $result['total'] : 0;
    }

    public function updateRole($userId, $communityId, $newRole)
    {
        $query = "UPDATE {$this->table} SET role = :role WHERE user_id = :user_id AND community_id = :community_id";
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':role', $newRole);
            $stmt->bindParam(':user_id', $userId);
            $stmt->bindParam(':community_id', $communityId);
            return $stmt->execute();
        } catch (PDOException $e) {
            return false;
        }
    }

    // Ambil daftar user yang statusnya REQUEST (Menunggu persetujuan)
    public function getPendingRequests($slug) {
        $query = "SELECT 
                    u.id, u.fullname, u.username, u.path_to_profile_picture,
                    cm.created_at
                  FROM community_members cm
                  JOIN users u ON cm.user_id = u.id
                  JOIN communities c ON cm.community_id = c.id
                  WHERE c.slug = :slug 
                    AND cm.status = 'REQUEST'
                  ORDER BY cm.created_at ASC"; // Yang lama di atas

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':slug', $slug);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // Update status member (untuk Approve)
    public function updateStatus($userId, $communityId, $status) {
        $query = "UPDATE {$this->table} SET status = :status WHERE user_id = :user_id AND community_id = :community_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':community_id', $communityId);
        return $stmt->execute();
    }

    public function isUserMember($userId, $communityId) {
        $query = "SELECT COUNT(*) as total FROM {$this->table} WHERE user_id = :user_id AND community_id = :community_id AND status = 'GRANTED'";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':community_id', $communityId);
        $stmt->execute();
        return $stmt->fetch()['total'] > 0;
    }

    public function isUserMemberOrInvited($userId, $communityId) {
        $query = "SELECT COUNT(*) as total FROM {$this->table} WHERE user_id = :user_id AND community_id = :community_id AND status IN ('GRANTED', 'INVITED')";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':community_id', $communityId);
        $stmt->execute();
        return $stmt->fetch()['total'] > 0;
    }

    public function addMember($userId, $communityId, $status = 'GRANTED', $role = 'MEMBER') {
        $query = "INSERT INTO {$this->table} (user_id, community_id, status, role)
                  VALUES (:user_id, :community_id, :status, :role)";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':user_id' => $userId,
            ':community_id' => $communityId,
            ':status' => $status,
            ':role' => $role
        ]);
    }
}