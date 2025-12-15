<?php
namespace app\models;

use app\core\Database;

class User {
    private $conn;
    // Di Oracle, nama tabel dan kolom biasanya uppercase secara default
    private $table = 'users';

    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function findByEmail($email) {
        $query = "SELECT u.id, fullname, username, email, bio, path_to_profile_picture, password, r.name AS role FROM {$this->table} u JOIN roles r ON role_id = r.id WHERE email = :email";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        return $stmt->fetch();
    }

    // [BARU] Method ringan untuk cek username (dipakai di UserController::updateProfile)
    public function findByUsername($username) {
        $query = "SELECT id, username FROM {$this->table} WHERE username = :username LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        return $stmt->fetch();
    }

    public function findById($id) {
        $query = "SELECT u.id, fullname, username, email, bio, path_to_profile_picture, r.name AS role FROM {$this->table} u JOIN roles r ON u.role_id = r.id WHERE u.id = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch();
    }

    public function findByIdWithRole($id) {
        $query = "SELECT u.id, fullname, username, email, bio, path_to_profile_picture, r.name AS role_name FROM {$this->table} u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch();
    }

    public function getAll() {
        $query = "SELECT u.id, fullname, username, email, bio, path_to_profile_picture, r.name AS role FROM {$this->table} u JOIN roles r ON role_id = r.id";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function create($fullname, $username, $bio, $email, $password, $profilePicture, $role) {
        // Menggunakan klausa RETURNING ID INTO :last_id untuk mendapatkan ID yang baru dibuat

        $query = "SELECT id FROM roles WHERE name = :role";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':role', $role);
        $stmt->execute();

        $row = $stmt->fetch();
        if ($row) {
            $role_id = $row['id'];
        } else {
            return;
        }

        $query = "INSERT INTO {$this->table} (fullname, username, email, bio, password, path_to_profile_picture, role_id) VALUES (:fullname, :username, :email, :bio, :password, :profile_picture, :role_id) RETURNING ID";

        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':fullname', $fullname);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':bio', $bio);
        $stmt->bindParam(':password', $hashedPassword);
        $stmt->bindParam(':profile_picture', $profilePicture);
        $stmt->bindParam(':role_id', $role_id);

        $stmt->execute();

        return $stmt->fetchColumn();
    }

    public function updatePasswordByEmail($email, $hashedPassword)
    {
        // Query SQL standar untuk update password
        $query = "UPDATE users SET password = :password WHERE email = :email";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':password', $hashedPassword);
        $stmt->bindParam(':email', $email);

        return $stmt->execute();
    }

    // [BARU] Method untuk update data profil user
    public function update($id, $data) {
        $fields = [];
        foreach ($data as $key => $value) {
            $fields[] = "$key = :$key";
        }
        $setClause = implode(", ", $fields);

        $query = "UPDATE {$this->table} SET $setClause WHERE id = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);

        foreach ($data as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }

        return $stmt->execute();
    }

    public function findByUsernameWithProfile($username)
    {
        // 1. Ambil Data User Dasar + Nama Role (Gunakan JOIN)
        $query = "SELECT u.id, u.fullname, u.username, u.email, u.bio, u.path_to_profile_picture, u.created_at, r.name as role 
                  FROM {$this->table} u
                  JOIN roles r ON u.role_id = r.id
                  WHERE u.username = :username LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        $user = $stmt->fetch();

        if (!$user) {
            return null;
        }

        // 2. Ambil Detail Profil Berdasarkan Role
        $details = null;
        $role = $user['role'];
        $userId = $user['id'];

        // Mapping Role ke Nama Tabel
        $tableMap = [
            'Mahasiswa' => 'mahasiswa_profiles',
            'Dosen'     => 'dosen_profiles',
            'Alumni'    => 'alumni_profiles',
            'Mitra'     => 'mitra_profiles',    // Ditambahkan
            'Pakar'     => 'pakar_profiles'     // Ditambahkan
        ];

        if (isset($tableMap[$role])) {
            $tableName = $tableMap[$role];

            // Query dinamis ke tabel profil yang sesuai
            $queryDet = "SELECT * FROM {$tableName} WHERE user_id = :uid LIMIT 1";
            $stmtDet = $this->conn->prepare($queryDet);
            $stmtDet->bindParam(':uid', $userId);
            $stmtDet->execute();
            $details = $stmtDet->fetch();
        }

        // Gabungkan detail ke data user
        $user['details'] = $details;

        return $user;
    }

    public function searchCandidatesForCommunity($keyword, $communityId) {
        // Menggunakan ILIKE untuk PostgreSQL (Case Insensitive).
        // Ganti ke LIKE jika menggunakan MySQL.
        $query = "SELECT id, fullname, username, path_to_profile_picture 
                  FROM {$this->table} 
                  WHERE (fullname ILIKE :keyword OR username ILIKE :keyword)
                  AND id NOT IN (
                      SELECT user_id FROM community_members WHERE community_id = :community_id
                  )
                  LIMIT 10";

        $stmt = $this->conn->prepare($query);
        $searchTerm = "%" . $keyword . "%";

        $stmt->bindParam(':keyword', $searchTerm);
        $stmt->bindParam(':community_id', $communityId);

        $stmt->execute();
        return $stmt->fetchAll();
    }
}