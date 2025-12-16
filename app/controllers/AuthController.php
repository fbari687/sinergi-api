<?php

namespace app\controllers;

use app\core\Database;
use app\helpers\MailHelper;
use app\helpers\ResponseFormatter;
use app\models\AccountRequest;
use app\models\CommunityMember;
use app\models\Notification;
use app\models\Otp;
use app\models\Role;
use app\models\User;
use app\models\UserProfile;

// <-- TAMBAHAN: Import Model UserProfile

class AuthController
{
    public function requestOtp()
    {
        $data = $_POST;

        if (!isset($data['username']) || !isset($data['email']) || !isset($data['password'])) {
            ResponseFormatter::error('Incomplete data', 400);
        }

        $config = require BASE_PATH . '/config/app.php';
        $allowedDomains = $config['allowed_email_domains'];

        $email = $data['email'];
        $emailParts = explode("@", $email);
        $domain = end($emailParts);

        if (!in_array(strtolower($domain), $allowedDomains)) {
            ResponseFormatter::error('Email domain not allowed', 400);
        }

        if (strlen($data['password']) < 8) {
            ResponseFormatter::error('Password must be at least 8 characters long', 400);
        }

        // Cek apakah email sudah terdaftar
        $userModel = new User();
        if ($userModel->findByEmail($data['email'])) {
            ResponseFormatter::error('Email already exists.', 409);
        }

        // Generate OTP
        $otpCode = rand(100000, 999999);

        $otpModel = new Otp();
        $otpModel->create($data['email'], $otpCode);

        // FUNGSI MENGIRIMKAN EMAIL (saya matikan sementara)
        $emailSent = MailHelper::sendOtp($data['email'], $data['username'], $otpCode);

        if (!$emailSent) {
            ResponseFormatter::error('Failed to send email', 500);
        }

        $_SESSION['registration_data'] = [
            'username' => strip_tags($data['username']),
            'email' => strip_tags($data['email']),
            'password' => $data['password']
        ];

        ResponseFormatter::success(null, 'OTP has been sent to your email');
    }

    public function verifyOtp()
    {
        $data = $_POST;

        if (!isset($data['email']) || !isset($data['otp_code'])) {
            ResponseFormatter::error('Email and OTP code are required', 400);
        }

        if (!isset($_SESSION['registration_data']) || $_SESSION['registration_data']['email'] !== $data['email']) {
            ResponseFormatter::error('No Registration process started for this email', 400);
        }

        $otpModel = new Otp();
        $otpData = $otpModel->findByEmail($data['email']);

        if (!$otpData || $otpData['otp_code'] !== $data['otp_code']) {
            ResponseFormatter::error('Invalid OTP Code', 400);
        }

        if (time() > $otpData['expires_at']) {
            ResponseFormatter::error('OTP Code has expired', 400);
        }

        $registrationData = $_SESSION['registration_data'];

        $this->registerUser($registrationData['username'], $registrationData['email'], $registrationData['password']);
    }

    private function registerUser($username, $email, $password)
    {
        $bio = "No bio yet.";
        $profilePicture = "uploads/profile_picture/unknown.png";

        $emailParts = explode("@", $email);
        $nameParts = explode(".", reset($emailParts));
        $domain = end($emailParts);

        if ($domain === "stu.pnj.ac.id") {
            array_pop($nameParts);
            $role = "Mahasiswa";
        } else {
            $role = "Dosen";
        }

        $fullname = ucwords(implode(" ", $nameParts));

        $userModel = new User();
        // Gunakan akses array di sini juga
        $userId = $userModel->create($fullname, $username, $bio, $email, $password, $profilePicture, $role);

        if ($userId) {
            $otpModel = new Otp();
            $otpModel->deleteByEmail($email);
            unset($_SESSION['registration_data']);
            ResponseFormatter::success(null, 'User registered successfully');
        } else {
            ResponseFormatter::error('Failed to register user', 500);
        }
    }

    public function me()
    {
        $userModel = new User();
        $user = $userModel->findById($_SESSION['user_id']);
        // --- LOGIKA CEK PROFILE (BARU) ---
        $userProfileModel = new UserProfile();

        // Mengambil nama role dari hasil query user (User.php: r.name AS role)
        $roleName = $user['role'] ?? '';

        // Cek apakah user ini sudah punya data di tabel profiles (mahasiswa_profiles / dosen_profiles)
        $isProfileComplete = $userProfileModel->hasProfile($user['id'], $roleName);

        // Masukkan status ke dalam array user agar Frontend tahu harus menampilkan dialog atau tidak
        $user['is_profile_complete'] = $isProfileComplete;

        if ($roleName === 'Mahasiswa') {
            $estYear = $userProfileModel->getStudentGraduationYear($user['id']);
            // Masukkan ke array user agar terkirim ke frontend
            $user['tahun_perkiraan_lulus'] = $estYear ?? null;
        }

        $config = require BASE_PATH . '/config/app.php';
        $storageBaseUrl = $config['storage_url'];
        $user['profile_picture'] = $storageBaseUrl . $user['path_to_profile_picture'];
        unset($user['path_to_profile_picture']);

        $notificationModel = new Notification();
        $unreadCount = $notificationModel->countUnread($user['id']);

        $user['unread_notifications_count'] = $unreadCount;

        ResponseFormatter::success($user, 'User fetched successfully');
    }

    public function login()
    {
        $data = $_POST;

         if (!isset($data['captcha_code']) || !isset($_SESSION['code'])) {
            ResponseFormatter::error('Captcha code is required', 400);
         }

         if ($data['captcha_code'] !== $_SESSION['code']) {
            unset($_SESSION['code']);
            ResponseFormatter::error('Invalid captcha code', 400);
         }

        unset($_SESSION['code']);

        if (!isset($data['email']) || !isset($data['password'])) {
            ResponseFormatter::error('Email and password are required.', 400);
            return;
        }

        $email = strip_tags($data['email']);
        $password = $data['password'];

        $userModel = new User();
        // findByEmail sudah melakukan JOIN ke roles, sehingga mengembalikan kolom 'role'
        $user = $userModel->findByEmail($email);

        if ($user && password_verify($password, $user['password'])) {
            if ($user['is_active'] !== true) {
                ResponseFormatter::error('Akun anda tidak aktif. Hubungi admin untuk informasi lebih lanjut.', 403);
            }

            // Regenerasi ID sesi untuk keamanan (mencegah session fixation)
            session_regenerate_id(true);

            // --- LOGIKA CEK PROFILE (BARU) ---
            $userProfileModel = new UserProfile();

            // Mengambil nama role dari hasil query user (User.php: r.name AS role)
            $roleName = $user['role'] ?? '';

            // Cek apakah user ini sudah punya data di tabel profiles (mahasiswa_profiles / dosen_profiles)
            $isProfileComplete = $userProfileModel->hasProfile($user['id'], $roleName);

            // Masukkan status ke dalam array user agar Frontend tahu harus menampilkan dialog atau tidak
            $user['is_profile_complete'] = $isProfileComplete;
            // ----------------------------------

            if ($roleName === 'Mahasiswa') {
                $estYear = $userProfileModel->getStudentGraduationYear($user['id']);
                // Masukkan ke array user agar terkirim ke frontend
                $user['tahun_perkiraan_lulus'] = $estYear ?? null;
            }

            $config = require BASE_PATH . '/config/app.php';
            $storageBaseUrl = $config['storage_url'];
            $user['profile_picture'] = $storageBaseUrl . $user['path_to_profile_picture'];

            // Hapus data sensitif
            unset($user['path_to_profile_picture']);
            unset($user['password']);

            // Simpan informasi user ke dalam variabel global $_SESSION
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['username'];
            $_SESSION['role'] = $roleName; // <-- PENTING: Simpan role di session untuk ProfileController
            $_SESSION['logged_in_at'] = time();

            ResponseFormatter::success($user, 'Login successful');
        } else {
            ResponseFormatter::error('Invalid credentials', 401);
        }
    }

    public function requestLifecycleOtp()
    {
        // 1. Cek Login
        if (!isset($_SESSION['user_id'])) {
            ResponseFormatter::error('Unauthorized', 401);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $type = $input['type'] ?? null;
        $userId = $_SESSION['user_id'];
        $userModel = new User();
        $user = $userModel->findById($userId);

        if (!$user) {
            ResponseFormatter::error('User not found', 404);
            return;
        }

        $emailTarget = "";
        $context = "";

        // 2. Tentukan Email Tujuan berdasarkan Tipe
        if ($type === 'extend_student') {
            // Kirim ke email kampus saat ini
            $emailTarget = $user['email'];
            $context = "Perpanjangan Masa Studi";

        } elseif ($type === 'convert_alumni') {
            // Validasi Email Baru
            $newEmail = $input['new_email'] ?? '';

            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                ResponseFormatter::error('Format email tidak valid', 400);
                return;
            }

            // Pastikan bukan email kampus (pnj.ac.id)
            if (str_ends_with($newEmail, 'pnj.ac.id')) {
                ResponseFormatter::error('Gunakan email pribadi (Gmail/Yahoo/dll) untuk akun Alumni.', 400);
                return;
            }

            // Cek apakah email sudah dipakai user lain
            if ($userModel->findByEmail($newEmail)) {
                ResponseFormatter::error('Email sudah digunakan oleh pengguna lain.', 409);
                return;
            }

            $emailTarget = $newEmail;
            $context = "Konversi Akun ke Alumni";
        } else {
            ResponseFormatter::error('Invalid request type', 400);
            return;
        }

        // 3. Generate OTP
        try {
            $otpCode = random_int(100000, 999999);
        } catch (\Exception $e) {
            $otpCode = rand(100000, 999999);
        }

        // 4. Simpan OTP (Hapus yg lama dulu biar bersih)
        $otpModel = new Otp();
        $otpModel->deleteByEmail($emailTarget);
        $otpModel->create($emailTarget, $otpCode);

        // 5. Kirim Email
        $sent = MailHelper::sendOtp($emailTarget, $user['username'], $otpCode, $context);

        if ($sent) {
            ResponseFormatter::success(null, 'OTP berhasil dikirim ke ' . $emailTarget);
        } else {
            ResponseFormatter::error('Gagal mengirim email OTP', 500);
        }
    }

    /**
     * [BARU] Verify OTP dan Eksekusi Perubahan
     */
    public function verifyLifecycleOtp()
    {
        if (!isset($_SESSION['user_id'])) {
            ResponseFormatter::error('Unauthorized', 401);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $type = $input['type'] ?? null;
        $otpCode = $input['otp'] ?? '';
        $userId = $_SESSION['user_id'];

        // 1. Tentukan Email yang dicek OTP-nya
        $emailToCheck = "";

        $userModel = new User();
        $currentUser = $userModel->findById($userId);

        if ($type === 'extend_student') {
            $emailToCheck = $currentUser['email'];
        } elseif ($type === 'convert_alumni') {
            $emailToCheck = $input['new_email'] ?? '';
        } else {
            ResponseFormatter::error('Invalid request type', 400);
            return;
        }

        // 2. Verifikasi OTP
        $otpModel = new Otp();
        $otpData = $otpModel->findByEmail($emailToCheck);

        if (!$otpData || $otpData['otp_code'] !== $otpCode) {
            ResponseFormatter::error('Kode OTP Salah', 400);
            return;
        }
        if (time() > $otpData['expires_at']) {
            ResponseFormatter::error('Kode OTP Kadaluarsa', 400);
            return;
        }

        // 3. Eksekusi Logic berdasarkan Tipe
        $conn = Database::getInstance()->getConnection();
        $conn->beginTransaction();

        try {
            if ($type === 'extend_student') {
                // LOGIC A: Tambah 1 tahun di mahasiswa_profiles
                $profileModel = new UserProfile();
                $profileModel->extendStudentYear($userId);

                $message = "Masa studi berhasil diperpanjang.";

            } elseif ($type === 'convert_alumni') {
                // LOGIC B: Konversi ke Alumni

                // a. Ambil Role ID Alumni
                $roleModel = new Role();
                $alumniRoleId = $roleModel->findIdByName('Alumni'); // Pastikan ada method ini atau hardcode ID jika perlu
                if (!$alumniRoleId) throw new \Exception("Role Alumni not found");

                // b. Update User (Email & Role)
                $userModel->updateEmailAndRole($userId, $emailToCheck, $alumniRoleId);

                // c. Migrasi Profile (Mahasiswa -> Alumni)
                $profileModel = new UserProfile();
                $profileModel->migrateToAlumni($userId);

                // d. Logout user (Hapus Session)
                session_destroy();

                $message = "Akun berhasil diubah menjadi Alumni. Silakan login ulang.";
            }

            // Hapus OTP setelah sukses
            $otpModel->deleteByEmail($emailToCheck);

            $conn->commit();
            ResponseFormatter::success(null, $message);

        } catch (\Throwable $e) {
            $conn->rollBack();
            ResponseFormatter::error('Gagal memproses permintaan: ' . $e->getMessage(), 500);
        }
    }

    public function requestForgotPasswordOtp()
    {
        $data = $_POST;

        if (!isset($data['email'])) {
            ResponseFormatter::error('Email is required', 400);
            return;
        }

        $email = strip_tags($data['email']);

        // 1. Cek apakah email TERDAFTAR (Kebalikan dari register)
        $userModel = new User();
        $user = $userModel->findByEmail($email);

        if (!$user) {
            // Demi keamanan, message bisa dibuat umum "If email exists, OTP sent".
            // Tapi untuk development/UX yang jelas, kita pakai 404.
            ResponseFormatter::error('Email not found.', 404);
            return;
        }

        // 2. Generate OTP
        $otpCode = rand(100000, 999999);

        $otpModel = new Otp();
        // Hapus OTP lama jika ada, lalu buat baru
        $otpModel->deleteByEmail($email);
        $otpModel->create($email, $otpCode);

        // 3. Kirim Email
        // Kita ambil username dari database user untuk personalisasi email
        $username = $user['username'];
        $emailSent = MailHelper::sendOtp($email, $username, $otpCode);

        if (!$emailSent) {
            ResponseFormatter::error('Failed to send OTP email', 500);
            return;
        }

        // 4. Simpan state di session untuk langkah selanjutnya
        $_SESSION['forgot_password_email'] = $email;
        $_SESSION['forgot_password_verified'] = false; // Reset status verifikasi

        ResponseFormatter::success(null, 'OTP for password reset has been sent to your email');
    }

    public function verifyForgotPasswordOtp()
    {
        $data = $_POST;

        if (!isset($data['otp_code'])) {
            ResponseFormatter::error('OTP code is required', 400);
            return;
        }

        // Pastikan flow dimulai dari request OTP (Session harus ada)
        if (!isset($_SESSION['forgot_password_email'])) {
            ResponseFormatter::error('Session expired. Please request OTP again.', 400);
            return;
        }

        $email = $_SESSION['forgot_password_email'];
        $otpCode = $data['otp_code'];

        $otpModel = new Otp();
        $otpData = $otpModel->findByEmail($email);

        // Validasi OTP
        if (!$otpData || $otpData['otp_code'] !== $otpCode) {
            ResponseFormatter::error('Invalid OTP Code', 400);
            return;
        }

        // Validasi Expiry
        if (time() > $otpData['expires_at']) {
            ResponseFormatter::error('OTP Code has expired', 400);
            return;
        }

        // Tandai di session bahwa user ini SUDAH lolos verifikasi OTP
        $_SESSION['forgot_password_verified'] = true;

        // Hapus OTP bekas pakai agar tidak bisa dipakai ulang (Replay Attack protection)
        $otpModel->deleteByEmail($email);

        ResponseFormatter::success(null, 'OTP verified. You can now reset your password.');
    }

    public function resetPassword()
    {
        $data = $_POST;

        if (!isset($data['new_password']) || !isset($data['confirm_password'])) {
            ResponseFormatter::error('New password and confirmation are required', 400);
            return;
        }

        // 1. Keamanan: Cek apakah user sudah melewati tahap verifikasi OTP
        if (!isset($_SESSION['forgot_password_verified']) || $_SESSION['forgot_password_verified'] !== true) {
            ResponseFormatter::error('Unauthorized. Please verify OTP first.', 403);
            return;
        }

        if (!isset($_SESSION['forgot_password_email'])) {
            ResponseFormatter::error('Session expired.', 400);
            return;
        }

        $newPassword = $data['new_password'];
        $confirmPassword = $data['confirm_password'];

        // 2. Validasi Password
        if (strlen($newPassword) < 8) {
            ResponseFormatter::error('Password must be at least 8 characters long', 400);
            return;
        }

        if ($newPassword !== $confirmPassword) {
            ResponseFormatter::error('Password confirmation does not match', 400);
            return;
        }

        // 3. Update Password di Database
        $email = $_SESSION['forgot_password_email'];
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

        $userModel = new User();
        // Asumsi: Anda perlu menambahkan method updatePasswordByEmail di model User
        $updated = $userModel->updatePasswordByEmail($email, $hashedPassword);

        if ($updated) {
            // 4. Bersihkan Session
            unset($_SESSION['forgot_password_email']);
            unset($_SESSION['forgot_password_verified']);

            ResponseFormatter::success(null, 'Password has been reset successfully. Please login.');
        } else {
            ResponseFormatter::error('Failed to reset password', 500);
        }
    }

    public function logout()
    {
        // 1. Hapus semua variabel sesi
        $_SESSION = [];

        // 2. Hapus cookie sesi dari browser
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }

        // 3. Hancurkan sesi di server (akan memanggil method destroy() di handler kita)
        session_destroy();

        ResponseFormatter::success(null, 'Logout successful');
    }

//    public function activateExternalAccount()
//    {
//        // Ambil body JSON
//        $input = json_decode(file_get_contents('php://input'), true);
//        $token = $input['token'] ?? null;
//
//        if (!$token) {
//            ResponseFormatter::error('Token tidak ditemukan.', 400);
//            return;
//        }
//
//        $requestModel = new AccountRequest();
//        $request = $requestModel->findByToken($token);
//
//        if (!$request) {
//            ResponseFormatter::error('Token tidak valid.', 404);
//            return;
//        }
//
//        // Pastikan status masih APPROVED, belum ACTIVATED
//        if ($request['status'] !== 'APPROVED') {
//            // Bisa sudah ACTIVATED, REJECTED, atau PENDING
//            ResponseFormatter::error('Permintaan akun ini tidak dapat diaktifkan.', 400);
//            return;
//        }
//
//        // Cek expiry token (kalau ada)
//        if (!empty($request['token_expires_at'])) {
//            $now = new \DateTimeImmutable('now');
//            $expiresAt = new \DateTimeImmutable($request['token_expires_at']);
//
//            if ($now > $expiresAt) {
//                ResponseFormatter::error('Token aktivasi sudah kadaluarsa.', 400);
//                return;
//            }
//        }
//
//        // Kalau semua valid: update status menjadi ACTIVATED
//        if (!$requestModel->markActivated((int) $request['id'])) {
//            ResponseFormatter::error('Gagal mengaktifkan akun.', 500);
//            return;
//        }
//
//        // Di sini kamu bisa lakukan hal lain kalau mau, misalnya:
//        // - pastikan user masih is_active = TRUE
//        // - catat log aktivasi, dsb.
//
//        ResponseFormatter::success(null, 'Akun Anda berhasil diaktifkan. Silakan login menggunakan email dan password Anda.');
//    }

    public function activateExternalAccount()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $token = $input['token'] ?? null;
        $password = $input['password'] ?? null;
        $passwordConfirmation = $input['password_confirmation'] ?? null;

        if (!$token) {
                ResponseFormatter::error('Token tidak ditemukan.', 400);
            return;
        }

        if (!$password || strlen($password) < 8) {
            ResponseFormatter::error('Password minimal 8 karakter.', 400);
            return;
        }

        if ($password !== $passwordConfirmation) {
            ResponseFormatter::error('Konfirmasi password tidak sesuai.', 400);
            return;
        }

        $requestModel = new AccountRequest();
        $request = $requestModel->findByToken($token);

        if (!$request) {
            ResponseFormatter::error('Token tidak valid.', 404);
            return;
        }

        if ($request['status'] !== 'APPROVED') {
            ResponseFormatter::error('Permintaan akun ini tidak dapat diaktifkan (status bukan APPROVED).', 400);
            return;
        }

        // Cek expiry token
        if (!empty($request['token_expires_at'])) {
            $now = new \DateTimeImmutable('now');
            $expiresAt = new \DateTimeImmutable($request['token_expires_at']);

            if ($now > $expiresAt) {
                ResponseFormatter::error('Token aktivasi sudah kadaluarsa.', 400);
                return;
            }
        }

        $conn = Database::getInstance()->getConnection();
        $conn->beginTransaction();

        try {
            // 1. Ambil Role ID
            $roleModel = new Role();
            $roleId = $roleModel->findIdByName($request['role_name']);

            if (!$roleId) {
                throw new \Exception('Role tidak ditemukan di tabel roles.');
            }

            // 2. Buat User Baru
            $userModel = new User();
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            // Method baru di model User
            $userId = $userModel->createActivatedUser(
                $request['fullname'],
                $request['username'],
                $request['email'],
                $hashedPassword,
                $roleId
            );

            // 3. Insert Profile
            $profileModel = new UserProfile();
            $profileData = $request['profile_data'] ?? [];

            if ($request['role_name'] === 'Alumni') {
                $profileModel->createAlumniProfile($userId, $profileData);
            } elseif ($request['role_name'] === 'Mitra') {
                $profileModel->createMitraProfile($userId, $profileData);
            } elseif ($request['role_name'] === 'Pakar') {
                $profileModel->createPakarProfile($userId, $profileData);
            }

            // 4. Masukkan ke Komunitas
            $communityMemberModel = new CommunityMember();
            $communityMemberModel->addMember($userId, $request['community_id']);

            // 5. Update Status Request
            $requestModel->updateStatus($request['id'], 'ACTIVATED');

            // --- COMMIT TRANSAKSI ---
            $conn->commit();

            ResponseFormatter::success(null, 'Akun berhasil dibuat dan diaktivasi. Silakan login.');

        } catch (\Throwable $e) {
            // --- ROLLBACK JIKA ERROR ---
            $conn->rollBack();
            // Log error sebenarnya untuk developer, tapi kembalikan pesan umum/spesifik ke user
            // error_log($e->getMessage());
            ResponseFormatter::error('Gagal mengaktifkan akun: ' . $e->getMessage(), 500);
        }
    }
}