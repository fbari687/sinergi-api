<?php
namespace app\models;

use app\core\Database;
use PDO;

class User {
    private $conn;
    // Di Oracle, nama tabel dan kolom biasanya uppercase secara default
    private $table = 'users';

    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function findByEmail($email) {
        $query = "SELECT u.id, fullname, username, email, bio, path_to_profile_picture, password, is_active, r.name AS role FROM {$this->table} u JOIN roles r ON role_id = r.id WHERE email = :email";

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

    public function createActivatedUser($fullname, $username, $email, $password, $roleId) {
        $query = "INSERT INTO {$this->table} (fullname, username, email, bio, password, role_id, is_active)
              VALUES (:fullname, :username, :email, '', :password, :role_id, TRUE)
              RETURNING id";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':fullname' => $fullname,
            ':username' => $username,
            ':email' => $email,
            ':password' => $password,
            ':role_id' => $roleId,
        ]);

        return (int)$stmt->fetchColumn();
    }

    public function getRoleIdByName($roleName) {
        $stmt = $this->conn->prepare("SELECT id FROM roles WHERE name = :name");
        $stmt->execute([':name' => $roleName]);
        return $stmt->fetchColumn();
    }

    // --- FEATURE: Create User with Profile (Transaction) ---
    public function createWithProfile($userData, $profileData, $roleName) {
        $roleId = $this->getRoleIdByName($roleName);
        if (!$roleId) return ['success' => false, 'message' => 'Role tidak valid'];

        $this->conn->beginTransaction();
        try {
            // 1. Insert User
            $hashed = password_hash($userData['password'], PASSWORD_BCRYPT);
            $q = "INSERT INTO {$this->table} (fullname, username, email, bio, password, path_to_profile_picture, is_active, role_id)
                  VALUES (:fullname, :username, :email, :bio, :password, :profile, :is_active, :role_id)
                  RETURNING id";

            $stmt = $this->conn->prepare($q);
            $stmt->execute([
                ':fullname' => $userData['fullname'],
                ':username' => $userData['username'],
                ':email'    => $userData['email'],
                ':bio'      => $userData['bio'],
                ':password' => $hashed,
                ':profile'  => $userData['path_to_profile_picture'],
                ':is_active'=> $userData['is_active'],
                ':role_id'  => $roleId
            ]);

            $userId = (int)$stmt->fetchColumn();

            // 2. Insert Profile via UserProfile Model
            $userProfileModel = new UserProfile();

            if ($roleName === 'Dosen') {
                if (empty($profileData['nidn'])) throw new \Exception('nidn wajib untuk Dosen');
                $userProfileModel->createDosenProfile($userId, $profileData);
            } elseif ($roleName === 'Mahasiswa') {
                if (empty($profileData['nim']) || empty($profileData['prodi']) || empty($profileData['tahun_masuk'])) {
                    throw new \Exception('nim, prodi, tahun_masuk wajib untuk Mahasiswa');
                }
                $userProfileModel->createMahasiswaProfile($userId, $profileData);
            } elseif ($roleName === 'Alumni') {
                if (empty($profileData['tahun_lulus'])) throw new \Exception('tahun_lulus wajib untuk Alumni');
                $userProfileModel->createAlumniProfile($userId, $profileData);
            } elseif ($roleName === 'Pakar') {
                if (empty($profileData['bidang_keahlian'])) throw new \Exception('bidang_keahlian wajib untuk Pakar');
                $userProfileModel->createPakarProfile($userId, $profileData);
            } elseif ($roleName === 'Mitra') {
                if (empty($profileData['nama_perusahaan']) || empty($profileData['jabatan'])) {
                    throw new \Exception('nama_perusahaan dan jabatan wajib untuk Mitra');
                }
                $userProfileModel->createMitraProfile($userId, $profileData);
            }

            $this->conn->commit();
            return ['success' => true, 'user_id' => $userId];

        } catch (\Throwable $e) {
            $this->conn->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // --- FEATURE: List Users (Complex Query) ---
    public function getUsersWithProfiles($limit, $offset, $filters = []) {
        $where = [];
        $params = [];

        if (!empty($filters['role'])) {
            $where[] = "r.name = :role";
            $params[':role'] = $filters['role'];
        }

        if (!empty($filters['search'])) {
            // ILIKE untuk PostgreSQL, gunakan LIKE untuk MySQL
            $where[] = "(u.fullname ILIKE :q OR u.email ILIKE :q OR u.username ILIKE :q)";
            $params[':q'] = '%' . $filters['search'] . '%';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDir = $filters['sort_dir'] ?? 'DESC';

        // 1. Get Count
        $countSql = "SELECT COUNT(1) FROM {$this->table} u LEFT JOIN roles r ON u.role_id = r.id $whereSql";
        $stmt = $this->conn->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        // 2. Get Data
        $sql = "
            SELECT u.id, u.fullname, u.username, u.email, u.is_active, u.created_at,
                   r.name as role_name,
                   m.nim, m.prodi, m.tahun_masuk, m.tahun_perkiraan_lulus,
                   d.nidn, d.bidang_keahlian AS dosen_bidang,
                   a.tahun_lulus AS alumni_tahun_lulus,
                   p.bidang_keahlian AS pakar_bidang,
                   mt.nama_perusahaan AS mitra_perusahaan, mt.jabatan AS mitra_jabatan,
                   u.path_to_profile_picture
            FROM {$this->table} u
            LEFT JOIN roles r ON u.role_id = r.id
            LEFT JOIN mahasiswa_profiles m ON m.user_id = u.id
            LEFT JOIN dosen_profiles d ON d.user_id = u.id
            LEFT JOIN alumni_profiles a ON a.user_id = u.id
            LEFT JOIN pakar_profiles p ON p.user_id = u.id
            LEFT JOIN mitra_profiles mt ON mt.user_id = u.id
            $whereSql
            ORDER BY {$sortBy} {$sortDir}
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->conn->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total
        ];
    }

    // --- FEATURE: Update Role ---
    public function updateUserRole($userId, $roleName) {
        $roleId = $this->getRoleIdByName($roleName);
        if (!$roleId) return ['success' => false, 'message' => 'Role tidak valid'];

        try {
            $stmt = $this->conn->prepare("UPDATE {$this->table} SET role_id = :role WHERE id = :id");
            $stmt->execute([':role' => $roleId, ':id' => $userId]);
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateUserComplete($id, $data)
    {
        $this->conn->beginTransaction();
        try {
            $fields = [];
            $params = [':id' => $id];

            // 1. Field Dasar
            $fields[] = "fullname = :fullname";
            $params[':fullname'] = $data['fullname'];

            $fields[] = "username = :username";
            $params[':username'] = $data['username'];

            $fields[] = "bio = :bio";
            $params[':bio'] = $data['bio'];

            $fields[] = "path_to_profile_picture = :path";
            $params[':path'] = $data['path_to_profile_picture'];

            // 2. Password (Opsional)
            if (!empty($data['password'])) {
                $fields[] = "password = :password";
                $params[':password'] = password_hash($data['password'], PASSWORD_BCRYPT);
            }

            // 3. Role (Perlu lookup ID)
            if (!empty($data['role_name'])) {
                $roleId = $this->getRoleIdByName($data['role_name']);
                if ($roleId) {
                    $fields[] = "role_id = :role_id";
                    $params[':role_id'] = $roleId;
                }
            }

            // 4. Eksekusi Query
            $setClause = implode(", ", $fields);
            $sql = "UPDATE {$this->table} SET $setClause WHERE id = :id";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);

            $this->conn->commit();
            return ['success' => true];

        } catch (\Throwable $e) {
            $this->conn->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // --- FEATURE: Toggle Active ---
    public function toggleActiveStatus($userId) {
        // 1. Ambil ID dan is_active untuk memastikan barisnya ada
        $stmt = $this->conn->prepare("SELECT id, is_active FROM {$this->table} WHERE id = :id");
        $stmt->execute([':id' => $userId]);

        // Gunakan fetch assoc agar kita bisa cek apakah row-nya ada atau tidak
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Jika $user bernilai false, berarti baris benar-benar tidak ada di DB
        if (!$user) {
            return ['success' => false, 'message' => 'User tidak ditemukan'];
        }

        // 2. Ambil status sekarang
        // Postgres terkadang mengembalikan boolean sebagai string 't'/'f' atau 1/0 atau native boolean
        // Kita paksa cast ke boolean agar aman
        $currentStatus = filter_var($user['is_active'], FILTER_VALIDATE_BOOLEAN);

        // 3. Balik statusnya
        $newState = !$currentStatus;

        // 4. Update ke Database
        $stmt = $this->conn->prepare("UPDATE {$this->table} SET is_active = :active WHERE id = :id");

        // Postgres menerima string 'true'/'false' untuk kolom boolean dengan aman
        $stmt->execute([
            ':active' => $newState ? 'true' : 'false',
            ':id' => $userId
        ]);

        return ['success' => true, 'new_state' => $newState];
    }

    // --- FEATURE: Delete User & Profiles ---
    public function deleteUserAndProfiles($userId) {
        $this->conn->beginTransaction();
        try {
            // Delete profiles manual (opsional jika database tidak CASCADE)
            $tables = ['mahasiswa_profiles', 'dosen_profiles', 'alumni_profiles', 'pakar_profiles', 'mitra_profiles'];
            foreach ($tables as $t) {
                $stmt = $this->conn->prepare("DELETE FROM $t WHERE user_id = :id");
                $stmt->execute([':id' => $userId]);
            }

            // Delete User
            $stmt = $this->conn->prepare("DELETE FROM {$this->table} WHERE id = :id");
            $stmt->execute([':id' => $userId]);

            $this->conn->commit();
            return ['success' => true];
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // --- FEATURE: Promote to Alumni ---
    public function promoteToAlumniBatch($userIds, $useEstimated, $manualYear) {
        $alumniId = $this->getRoleIdByName('Alumni');
        $mhsId = $this->getRoleIdByName('Mahasiswa');

        if (!$alumniId || !$mhsId) return ['success' => false, 'message' => 'Role Mahasiswa/Alumni missing'];

        $results = ['promoted' => [], 'skipped' => []];

        $this->conn->beginTransaction();
        try {
            foreach ($userIds as $uid) {
                // Cek User & Role
                $stmt = $this->conn->prepare("SELECT role_id FROM {$this->table} WHERE id = :id");
                $stmt->execute([':id' => $uid]);
                $currRole = $stmt->fetchColumn();

                if ($currRole != $mhsId) {
                    $results['skipped'][] = ['id' => $uid, 'reason' => 'not_mahasiswa'];
                    continue;
                }

                // Tentukan Tahun Lulus
                $tahunLulus = $manualYear;
                if ($useEstimated && empty($manualYear)) {
                    $stmtEst = $this->conn->prepare("SELECT tahun_perkiraan_lulus FROM mahasiswa_profiles WHERE user_id = :id");
                    $stmtEst->execute([':id' => $uid]);
                    $est = $stmtEst->fetchColumn();
                    $tahunLulus = $est ? (int)$est : null;
                }

                // Update Role User
                $this->conn->prepare("UPDATE {$this->table} SET role_id = :rid WHERE id = :id")
                    ->execute([':rid' => $alumniId, ':id' => $uid]);

                // Update/Insert Alumni Profile
                // Cek exists
                $stmtCheck = $this->conn->prepare("SELECT 1 FROM alumni_profiles WHERE user_id = :id");
                $stmtCheck->execute([':id' => $uid]);

                if ($stmtCheck->fetchColumn()) {
                    if ($tahunLulus) {
                        $this->conn->prepare("UPDATE alumni_profiles SET tahun_lulus = :th WHERE user_id = :id")
                            ->execute([':th' => $tahunLulus, ':id' => $uid]);
                    }
                } else {
                    $this->conn->prepare("INSERT INTO alumni_profiles (user_id, tahun_lulus) VALUES (:id, :th)")
                        ->execute([':id' => $uid, ':th' => $tahunLulus]);
                }

                $results['promoted'][] = ['id' => $uid, 'tahun_lulus' => $tahunLulus];
            }

            $this->conn->commit();
            return ['success' => true, 'data' => $results];

        } catch (\Throwable $e) {
            $this->conn->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateEmailAndRole($userId, $newEmail, $newRoleId) {
        $sql = "UPDATE users SET email = :email, role_id = :rid WHERE id = :uid";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':email' => $newEmail,
            ':rid' => $newRoleId,
            ':uid' => $userId
        ]);
    }
}