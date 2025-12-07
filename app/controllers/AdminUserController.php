<?php
namespace app\controllers;

use app\core\Database;
use app\helpers\ResponseFormatter;
use PDO;

class AdminUserController
{
    // POST /api/admin/users
    public function createUser()
    {
        // Admin only (middleware assumed)
        $conn = Database::getInstance()->getConnection();

        // untuk multipart/formdata, gunakan $_POST, $_FILES
        $data = $_POST;

        $fullname = trim($data['fullname'] ?? '');
        $username = trim($data['username'] ?? '');
        $email    = trim($data['email'] ?? '');
        $password = $data['password'] ?? null;
        $roleName = trim($data['role'] ?? '');

        if (!$fullname || !$username || !$email || !$roleName) {
            ResponseFormatter::error('fullname, username, email, role wajib diisi', 400);
            return;
        }

        if (!$password || strlen($password) < 8) {
            ResponseFormatter::error('Password minimal 8 karakter', 400);
            return;
        }

        // Ambil role_id
        $stmt = $conn->prepare("SELECT id FROM roles WHERE name = :name");
        $stmt->execute([':name' => $roleName]);
        $roleId = $stmt->fetchColumn();
        if (!$roleId) {
            ResponseFormatter::error('Role tidak valid', 400);
            return;
        }

        // Begin transaction
        $conn->beginTransaction();
        try {
            // Insert ke users
            $hashed = password_hash($password, PASSWORD_BCRYPT);

            $q = "INSERT INTO users (fullname, username, email, bio, password, path_to_profile_picture, is_active, role_id)
                  VALUES (:fullname, :username, :email, :bio, :password, :profile, :is_active, :role_id)
                  RETURNING id, created_at";
            $stmt = $conn->prepare($q);
            $bio = $data['bio'] ?? '';
            $profilePath = $data['path_to_profile_picture'] ?? ''; // jika admin upload file, simpannya lalu masukkan path di sini (implementasikan upload sesuai project)
            $isActive = isset($data['is_active']) ? (bool)$data['is_active'] : true;

            $stmt->execute([
                ':fullname' => $fullname,
                ':username' => $username,
                ':email' => $email,
                ':bio' => $bio,
                ':password' => $hashed,
                ':profile' => $profilePath,
                ':is_active' => $isActive,
                ':role_id' => $roleId
            ]);

            $userId = (int)$stmt->fetchColumn();

            // Insert profile sesuai role
            $profileData = json_decode($data['profile_data'] ?? '{}', true);
            // Fallback: allow admin submit individual fields as plain keys
            if (empty($profileData)) {
                // collect known keys
                $keys = ['nim','prodi','tahun_masuk','tahun_perkiraan_lulus','nidn','bidang_keahlian','tahun_lulus','nama_perusahaan','jabatan'];
                foreach ($keys as $k) {
                    if (isset($data[$k])) $profileData[$k] = $data[$k];
                }
            }

            // depending on role
            if ($roleName === 'Dosen') {
                if (empty($profileData['nidn'])) {
                    throw new \Exception('nidn wajib untuk Dosen');
                }
                $q = "INSERT INTO dosen_profiles (user_id, nidn, bidang_keahlian) VALUES (:user_id, :nidn, :bidang)";
                $stmt = $conn->prepare($q);
                $stmt->execute([
                    ':user_id' => $userId,
                    ':nidn' => $profileData['nidn'],
                    ':bidang' => $profileData['bidang_keahlian'] ?? ''
                ]);
            } elseif ($roleName === 'Mahasiswa') {
                if (empty($profileData['nim']) || empty($profileData['prodi']) || empty($profileData['tahun_masuk'])) {
                    throw new \Exception('nim, prodi, tahun_masuk wajib untuk Mahasiswa');
                }
                $q = "INSERT INTO mahasiswa_profiles (user_id, nim, prodi, tahun_masuk, tahun_perkiraan_lulus)
                      VALUES (:user_id, :nim, :prodi, :tahun_masuk, :tahun_perkiraan_lulus)";
                $stmt = $conn->prepare($q);
                $stmt->execute([
                    ':user_id' => $userId,
                    ':nim' => $profileData['nim'],
                    ':prodi' => $profileData['prodi'],
                    ':tahun_masuk' => (int)$profileData['tahun_masuk'],
                    ':tahun_perkiraan_lulus' => isset($profileData['tahun_perkiraan_lulus']) ? (int)$profileData['tahun_perkiraan_lulus'] : null
                ]);
            } elseif ($roleName === 'Alumni') {
                if (empty($profileData['tahun_lulus'])) {
                    throw new \Exception('tahun_lulus wajib untuk Alumni');
                }
                $q = "INSERT INTO alumni_profiles (user_id, tahun_lulus) VALUES (:user_id, :tahun_lulus)";
                $stmt = $conn->prepare($q);
                $stmt->execute([
                    ':user_id' => $userId,
                    ':tahun_lulus' => (int)$profileData['tahun_lulus']
                ]);
            } elseif ($roleName === 'Pakar') {
                if (empty($profileData['bidang_keahlian'])) {
                    throw new \Exception('bidang_keahlian wajib untuk Pakar');
                }
                $q = "INSERT INTO pakar_profiles (user_id, bidang_keahlian) VALUES (:user_id, :bidang)";
                $stmt = $conn->prepare($q);
                $stmt->execute([
                    ':user_id' => $userId,
                    ':bidang' => $profileData['bidang_keahlian']
                ]);
            } elseif ($roleName === 'Mitra') {
                if (empty($profileData['nama_perusahaan']) || empty($profileData['jabatan'])) {
                    throw new \Exception('nama_perusahaan dan jabatan wajib untuk Mitra');
                }
                $q = "INSERT INTO mitra_profiles (user_id, nama_perusahaan, jabatan) VALUES (:user_id, :nama_perusahaan, :jabatan)";
                $stmt = $conn->prepare($q);
                $stmt->execute([
                    ':user_id' => $userId,
                    ':nama_perusahaan' => $profileData['nama_perusahaan'],
                    ':jabatan' => $profileData['jabatan']
                ]);
            }

            $conn->commit();

            ResponseFormatter::success(['id' => $userId], 'User berhasil dibuat');
        } catch (\Throwable $e) {
            $conn->rollBack();
            ResponseFormatter::error('Gagal membuat user: ' . $e->getMessage(), 500);
        }
    }

    // GET /api/admin/users
    public function listUsers()
    {
        $conn = Database::getInstance()->getConnection();

        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
        $offset = ($page - 1) * $perPage;

        $role = isset($_GET['role']) ? trim($_GET['role']) : null; // role name
        $search = isset($_GET['q']) ? trim($_GET['q']) : null;
        $sortBy = isset($_GET['sort_by']) ? trim($_GET['sort_by']) : 'created_at';
        $sortDir = (isset($_GET['sort_dir']) && strtoupper($_GET['sort_dir']) === 'ASC') ? 'ASC' : 'DESC';

        $allowedSort = ['created_at', 'fullname', 'email', 'tahun_perkiraan_lulus'];
        if (!in_array($sortBy, $allowedSort)) $sortBy = 'created_at';

        $where = [];
        $params = [];

        if ($role) {
            $where[] = "r.name = :role";
            $params[':role'] = $role;
        }

        if ($search) {
            $where[] = "(u.fullname ILIKE :q OR u.email ILIKE :q OR u.username ILIKE :q)";
            $params[':q'] = '%' . $search . '%';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // total count
        $countSql = "SELECT COUNT(1) FROM users u
                     LEFT JOIN roles r ON u.role_id = r.id
                     $whereSql";
        $stmt = $conn->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        // main query: join profile tables with LEFT JOIN to fetch profile columns where applicable
        $sql = "
            SELECT u.id, u.fullname, u.username, u.email, u.is_active, u.created_at,
                   r.name as role_name,
                   m.nim, m.prodi, m.tahun_masuk, m.tahun_perkiraan_lulus,
                   d.nidn, d.bidang_keahlian AS dosen_bidang,
                   a.tahun_lulus AS alumni_tahun_lulus,
                   p.bidang_keahlian AS pakar_bidang,
                   mt.nama_perusahaan AS mitra_perusahaan, mt.jabatan AS mitra_jabatan,
                   u.path_to_profile_picture
            FROM users u
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

        $stmt = $conn->prepare($sql);
        // bind params
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // prefix storage URL for profile_picture (if available)
        $config = require BASE_PATH . '/config/app.php';
        $storageBaseUrl = rtrim($config['storage_url'], '/');
        foreach ($rows as &$r) {
            $r['profile_picture'] = $r['path_to_profile_picture']
                ? (strpos($r['path_to_profile_picture'], 'http') === 0 ? $r['path_to_profile_picture'] : $storageBaseUrl . '/' . ltrim($r['path_to_profile_picture'], '/'))
                : null;
        }

        ResponseFormatter::success([
            'items' => $rows,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total
        ], 'Users fetched');
    }

    // POST /api/admin/users/{id}/role
    public function updateUserRole($id)
    {
        $conn = Database::getInstance()->getConnection();
        $data = $_POST;
        $roleName = trim($data['role'] ?? '');
        if (!$roleName) {
            ResponseFormatter::error('Role wajib dipilih', 400);
            return;
        }

        $stmt = $conn->prepare("SELECT id FROM roles WHERE name = :name");
        $stmt->execute([':name' => $roleName]);
        $roleId = $stmt->fetchColumn();
        if (!$roleId) {
            ResponseFormatter::error('Role tidak valid', 400);
            return;
        }

        // update user role and (optionally) add missing profile row
        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("UPDATE users SET role_id = :role WHERE id = :id");
            $stmt->execute([':role' => $roleId, ':id' => (int)$id]);

            // Note: we do NOT automatically migrate profile data; admin can create profile entries as needed via createUser or separate endpoints
            $conn->commit();
            ResponseFormatter::success(null, 'Role pengguna diperbarui');
        } catch (\Throwable $e) {
            $conn->rollBack();
            ResponseFormatter::error('Gagal memperbarui role: ' . $e->getMessage(), 500);
        }
    }

    // POST /api/admin/users/{id}/toggle-active
    public function toggleActive($id)
    {
        $conn = Database::getInstance()->getConnection();
        $stmt = $conn->prepare("SELECT is_active FROM users WHERE id = :id");
        $stmt->execute([':id' => (int)$id]);
        $cur = $stmt->fetchColumn();
        if ($cur === false) {
            ResponseFormatter::error('User tidak ditemukan', 404);
            return;
        }

        $new = $cur ? false : true;
        $stmt = $conn->prepare("UPDATE users SET is_active = :active WHERE id = :id");
        $stmt->execute([':active' => $new, ':id' => (int)$id]);
        ResponseFormatter::success(['is_active' => (bool)$new], $new ? 'User diaktifkan' : 'User dinonaktifkan');
    }

    // DELETE /api/admin/users/{id}
    public function deleteUser($id)
    {
        $conn = Database::getInstance()->getConnection();
        $conn->beginTransaction();
        try {
            // delete profil (cascade via FK would also work but do explicit delete)
            $stmt = $conn->prepare("DELETE FROM mahasiswa_profiles WHERE user_id = :id");
            $stmt->execute([':id' => (int)$id]);
            $stmt = $conn->prepare("DELETE FROM dosen_profiles WHERE user_id = :id");
            $stmt->execute([':id' => (int)$id]);
            $stmt = $conn->prepare("DELETE FROM alumni_profiles WHERE user_id = :id");
            $stmt->execute([':id' => (int)$id]);
            $stmt = $conn->prepare("DELETE FROM pakar_profiles WHERE user_id = :id");
            $stmt->execute([':id' => (int)$id]);
            $stmt = $conn->prepare("DELETE FROM mitra_profiles WHERE user_id = :id");
            $stmt->execute([':id' => (int)$id]);

            // finally delete user row
            $stmt = $conn->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute([':id' => (int)$id]);

            $conn->commit();
            ResponseFormatter::success(null, 'User berhasil dihapus');
        } catch (\Throwable $e) {
            $conn->rollBack();
            ResponseFormatter::error('Gagal menghapus user: ' . $e->getMessage(), 500);
        }
    }

    public function promoteToAlumni()
    {
        $conn = Database::getInstance()->getConnection();

        // Terima JSON body atau form-data
        $inputRaw = file_get_contents('php://input');
        $data = json_decode($inputRaw, true);
        if (!$data) {
            // fallback ke $_POST (form)
            $data = $_POST;
        }

        $ids = $data['user_ids'] ?? null; // array of user ids
        $use_estimated = !empty($data['use_estimated']) ? (bool)$data['use_estimated'] : false;
        $tahun_lulus_override = isset($data['tahun_lulus']) && $data['tahun_lulus'] !== '' ? (int)$data['tahun_lulus'] : null;

        if (!is_array($ids) || count($ids) === 0) {
            ResponseFormatter::error('user_ids harus berupa array berisi id mahasiswa yang akan dipromosikan.', 400);
            return;
        }

        // Ambil role id untuk 'Alumni' dan 'Mahasiswa'
        $stmt = $conn->prepare("SELECT id FROM roles WHERE name = :name");
        $stmt->execute([':name' => 'Alumni']);
        $alumniRoleId = $stmt->fetchColumn();

        $stmt->execute([':name' => 'Mahasiswa']);
        $mahasiswaRoleId = $stmt->fetchColumn();

        if (!$alumniRoleId || !$mahasiswaRoleId) {
            ResponseFormatter::error('Role Mahasiswa/Alumni tidak ditemukan.', 500);
            return;
        }

        $results = [
            'promoted' => [],
            'skipped' => [], // reasons: not_mahasiswa, already_alumni, error
        ];

        $conn->beginTransaction();
        try {
            foreach ($ids as $uid) {
                $uid = (int)$uid;
                if ($uid <= 0) continue;

                // Ambil user basic + current role
                $q = "SELECT u.id, u.role_id, r.name as role_name
                  FROM users u
                  LEFT JOIN roles r ON u.role_id = r.id
                  WHERE u.id = :id
                  LIMIT 1";
                $stmt = $conn->prepare($q);
                $stmt->execute([':id' => $uid]);
                $user = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (!$user) {
                    $results['skipped'][] = ['id' => $uid, 'reason' => 'not_found'];
                    continue;
                }

                if ((int)$user['role_id'] !== (int)$mahasiswaRoleId) {
                    // jika sudah alumni atau bukan mahasiswa
                    $results['skipped'][] = ['id' => $uid, 'reason' => 'not_mahasiswa', 'current_role' => $user['role_name']];
                    continue;
                }

                // Tentukan tahun_lulus: jika use_estimated true -> ambil tahun_perkiraan_lulus dari mahasiswa_profiles
                $tahun_lulus = $tahun_lulus_override;
                if ($use_estimated && $tahun_lulus_override === null) {
                    $stmt2 = $conn->prepare("SELECT tahun_perkiraan_lulus FROM mahasiswa_profiles WHERE user_id = :id LIMIT 1");
                    $stmt2->execute([':id' => $uid]);
                    $est = $stmt2->fetchColumn();
                    if ($est !== false && $est !== null && $est !== '') {
                        $tahun_lulus = (int)$est;
                    } else {
                        // tetap null if not available
                        $tahun_lulus = null;
                    }
                }

                // Update role_id di users
                $stmt3 = $conn->prepare("UPDATE users SET role_id = :role WHERE id = :id");
                $stmt3->execute([':role' => $alumniRoleId, ':id' => $uid]);

                // Insert atau update alumni_profiles
                // Cek kalau sudah ada alumni_profiles (mungkin admin pernah mempromosikan)
                $stmt4 = $conn->prepare("SELECT user_id FROM alumni_profiles WHERE user_id = :id LIMIT 1");
                $stmt4->execute([':id' => $uid]);
                $exists = $stmt4->fetchColumn();

                if ($exists) {
                    // update tahun_lulus jika ada nilai
                    if ($tahun_lulus !== null) {
                        $stmt5 = $conn->prepare("UPDATE alumni_profiles SET tahun_lulus = :tahun WHERE user_id = :id");
                        $stmt5->execute([':tahun' => $tahun_lulus, ':id' => $uid]);
                    }
                } else {
                    $stmt6 = $conn->prepare("INSERT INTO alumni_profiles (user_id, tahun_lulus) VALUES (:id, :tahun)");
                    $stmt6->execute([':id' => $uid, ':tahun' => $tahun_lulus]);
                }

                $results['promoted'][] = ['id' => $uid, 'tahun_lulus' => $tahun_lulus];
            }

            $conn->commit();
            ResponseFormatter::success($results, 'Promosi selesai');
        } catch (\Throwable $e) {
            $conn->rollBack();
            ResponseFormatter::error('Gagal mempromosikan: ' . $e->getMessage(), 500);
        }
    }
}
