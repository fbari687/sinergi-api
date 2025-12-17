<?php
namespace app\controllers;

use app\helpers\FileHelper;
use app\models\User;
use app\helpers\ResponseFormatter;

class AdminUserController
{
    private $userModel;

    public function __construct() {
        $this->userModel = new User();
    }

    // POST /api/admin/users
    public function createUser()
    {
        $data = $_POST;

        // 1. Validasi Input Dasar
        $fullname = trim($data['fullname'] ?? '');
        $username = trim($data['username'] ?? '');
        $email    = trim($data['email'] ?? '');
        $password = $data['password'] ?? null;
        $roleName = trim($data['role'] ?? '');
        $bio      = $data['bio'] ?? '';
//        $profilePath = $data['path_to_profile_picture'] ?? '';
        $isActive = isset($data['is_active']) ? filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN) : true;

        if (!$fullname || !$username || !$email || !$roleName) {
            ResponseFormatter::error('fullname, username, email, role wajib diisi', 400);
            return;
        }

        if (!$password || strlen($password) < 8) {
            ResponseFormatter::error('Password minimal 8 karakter', 400);
            return;
        }

        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $uploadedPath = FileHelper::upload(
                $_FILES['profile_picture'],
                'uploads/profile_picture',
                ['image/jpeg', 'image/png', 'image/jpg'],
                5 * 1024 * 1024
            );

            if (!$uploadedPath) {
                ResponseFormatter::error('Failed to upload media', 500);
                return; // Penting: Return agar tidak lanjut insert ke DB
            }
        }

        // 2. Siapkan Data Profil
        $profileData = json_decode($data['profile_data'] ?? '{}', true);
        if (empty($profileData)) {
            $keys = ['nim','prodi','tahun_masuk','tahun_perkiraan_lulus','nidn','bidang_keahlian','tahun_lulus', 'pekerjaan_saat_ini', 'nama_perusahaan','jabatan', 'alamat_perusahaan', 'bidang_keahlian', 'instansi_asal'];
            foreach ($keys as $k) {
                if (isset($data[$k])) $profileData[$k] = $data[$k];
            }
        }

        // 3. Panggil Model untuk Proses DB
        $userData = [
            'fullname' => $fullname,
            'username' => $username,
            'email'    => $email,
            'bio'      => $bio,
            'password' => $password,
            'path_to_profile_picture' => $uploadedPath ?? 'uploads/profile_picture/unknown.png',
            'is_active'=> $isActive
        ];

        $result = $this->userModel->createWithProfile($userData, $profileData, $roleName);

        if ($result['success']) {
            ResponseFormatter::success(['id' => $result['user_id']], 'User berhasil dibuat');
        } else {
            ResponseFormatter::error('Gagal membuat user: ' . $result['message'], 400); // 400 karena biasanya error validasi logic
        }
    }

    // GET /api/admin/users
    public function listUsers()
    {
        // 1. Ambil Parameter
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
        $offset = ($page - 1) * $perPage;

        $filters = [
            'role'    => isset($_GET['role']) ? trim($_GET['role']) : null,
            'search'  => isset($_GET['q']) ? trim($_GET['q']) : null,
            'sort_by' => isset($_GET['sort_by']) && in_array($_GET['sort_by'], ['created_at', 'fullname', 'email', 'tahun_perkiraan_lulus']) ? $_GET['sort_by'] : 'created_at',
            'sort_dir'=> (isset($_GET['sort_dir']) && strtoupper($_GET['sort_dir']) === 'ASC') ? 'ASC' : 'DESC'
        ];

        // 2. Panggil Model
        $result = $this->userModel->getUsersWithProfiles($perPage, $offset, $filters, true, $_SESSION['user_id']);

        // 3. Format Response (URL Processing)
        $config = require BASE_PATH . '/config/app.php';
        $storageBaseUrl = rtrim($config['storage_url'], '/');

        $items = $result['items'];
        foreach ($items as &$r) {
            $r['profile_picture'] = $r['path_to_profile_picture']
                ? (strpos($r['path_to_profile_picture'], 'http') === 0 ? $r['path_to_profile_picture'] : $storageBaseUrl . '/' . ltrim($r['path_to_profile_picture'], '/'))
                : null;
        }

        ResponseFormatter::success([
            'items' => $items,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $result['total']
        ], 'Users fetched');
    }

    // POST /api/admin/users/{id}/role
    public function updateUserRole($id)
    {
        $roleName = trim($_POST['role'] ?? '');
        if (!$roleName) {
            ResponseFormatter::error('Role wajib dipilih', 400);
            return;
        }

        $result = $this->userModel->updateUserRole($id, $roleName);

        if ($result['success']) {
            ResponseFormatter::success(null, 'Role pengguna diperbarui');
        } else {
            ResponseFormatter::error($result['message'], 400);
        }
    }

    public function updateUser($id)
    {
        // 1. Cek User Existing
        $currentUser = $this->userModel->findById($id);
        if (!$currentUser) {
            ResponseFormatter::error('User tidak ditemukan', 404);
            return;
        }

        $data = $_POST;

        // 2. Validasi Input Wajib
        if (empty($data['fullname']) || empty($data['username']) || empty($data['role'])) {
            ResponseFormatter::error('Nama, Username, dan Role wajib diisi.', 400);
            return;
        }

        // 3. Validasi Username Unik (Jika berubah)
        $newUsername = trim($data['username']);
        if ($newUsername !== $currentUser['username']) {
            if (!preg_match('/^[a-zA-Z0-9._]+$/', $newUsername)) {
                ResponseFormatter::error('Username hanya boleh huruf, angka, titik, underscore.', 400);
                return;
            }
            if ($this->userModel->findByUsername($newUsername)) {
                ResponseFormatter::error('Username sudah digunakan user lain.', 409);
                return;
            }
        }

        // 4. Handle Foto Profil (Logic mirip UserController)
        $currentPath = $currentUser['path_to_profile_picture'];
        $defaultPath = "uploads/profile_picture/unknown.png";
        $finalPath = $currentPath; // Default: tidak berubah

        // Cek Flag Delete dari Frontend
        $isDeleteRequested = isset($data['delete_profile_picture']) && $data['delete_profile_picture'] == '1';

        if ($isDeleteRequested) {
            // KASUS A: User minta hapus foto -> Reset ke default
            if ($currentPath && $currentPath !== $defaultPath) {
                FileHelper::delete($currentPath);
            }
            $finalPath = $defaultPath;

        } elseif (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === 0) {
            // KASUS B: User upload foto baru

            // Upload file baru
            $uploadedPath = FileHelper::upload(
                $_FILES['profile_picture'],
                'uploads/profile_picture',
                ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'],
                2 * 1024 * 1024 // 2MB
            );

            if ($uploadedPath) {
                // Jika upload sukses, hapus foto lama (kecuali default)
                if ($currentPath && $currentPath !== $defaultPath) {
                    FileHelper::delete($currentPath);
                }
                $finalPath = $uploadedPath;
            } else {
                ResponseFormatter::error('Gagal mengupload foto profil (Format/Size invalid)', 400);
                return;
            }
        }

        // 5. Siapkan Data untuk Model
        $updateData = [
            'fullname'  => trim($data['fullname']),
            'username'  => $newUsername,
            'role_name' => trim($data['role']),
            'bio'       => isset($data['bio']) ? strip_tags($data['bio']) : '',
            'path_to_profile_picture' => $finalPath,
        ];

        // Password opsional (hanya kirim jika diisi)
        if (!empty($data['password'])) {
            if (strlen($data['password']) < 8) {
                ResponseFormatter::error('Password minimal 8 karakter', 400);
                return;
            }
            $updateData['password'] = $data['password'];
        }

        // 6. Eksekusi Update di Model
        $result = $this->userModel->updateUserComplete($id, $updateData);

        if ($result['success']) {
            ResponseFormatter::success(null, 'Data user berhasil diperbarui');
        } else {
            ResponseFormatter::error('Gagal update: ' . $result['message'], 500);
        }
    }

    // POST /api/admin/users/{id}/toggle-active
    public function toggleActive($id)
    {
        $result = $this->userModel->toggleActiveStatus($id);

        if ($result['success']) {
            ResponseFormatter::success(['is_active' => $result['new_state']], $result['new_state'] ? 'User diaktifkan' : 'User dinonaktifkan');
        } else {
            ResponseFormatter::error($result['message'], 404);
        }
    }

    // DELETE /api/admin/users/{id}
    public function deleteUser($id)
    {
        $result = $this->userModel->deleteUserAndProfiles($id);

        if ($result['success']) {
            ResponseFormatter::success(null, 'User berhasil dihapus');
        } else {
            ResponseFormatter::error('Gagal menghapus user: ' . $result['message'], 500);
        }
    }

    // POST /api/admin/users/promote-alumni
    public function promoteToAlumni()
    {
        $inputRaw = file_get_contents('php://input');
        $data = json_decode($inputRaw, true) ?? $_POST;

        $ids = $data['user_ids'] ?? [];
        $useEstimated = !empty($data['use_estimated']);
        $manualYear = isset($data['tahun_lulus']) && $data['tahun_lulus'] !== '' ? (int)$data['tahun_lulus'] : null;

        if (!is_array($ids) || count($ids) === 0) {
            ResponseFormatter::error('user_ids harus array', 400);
            return;
        }

        $result = $this->userModel->promoteToAlumniBatch($ids, $useEstimated, $manualYear);

        if ($result['success']) {
            ResponseFormatter::success($result['data'], 'Promosi selesai');
        } else {
            ResponseFormatter::error($result['message'], 500);
        }
    }
}
?>