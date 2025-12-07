<?php
namespace app\middleware;

use app\helpers\ResponseFormatter;
use app\models\User;

class AuthenticateMiddleware {
    public function handle() {
        if (!isset($_SESSION['user_id'])) {
            ResponseFormatter::error('Unauthorized', 401);
        }

        // Jika user sudah login tapi data lengkapnya belum ada di sesi, muat sekarang.
        if (!isset($_SESSION['user'])) {
            $userModel = new User();
            // Anda perlu membuat method findByIdWithRole() di model User
            $user = $userModel->findByIdWithRole($_SESSION['user_id']);
            if (!$user) {
                // Jika user tidak ditemukan (misal sudah dihapus), hancurkan sesi
                session_destroy();
                ResponseFormatter::error('Unauthorized', 401);
            }
            // Simpan data user lengkap (termasuk nama role) ke sesi
            $config = require BASE_PATH . '/config/app.php';
            $storageBaseUrl = $config['storage_url'];
            $user['profile_picture'] = $storageBaseUrl . $user['path_to_profile_picture'];
            unset($user['path_to_profile_picture']);
            $_SESSION['user'] = (array) $user;
        }
    }
}