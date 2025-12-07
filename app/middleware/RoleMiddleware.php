<?php

namespace app\middleware;

use app\helpers\ResponseFormatter;

class RoleMiddleware {

    public function handle(...$allowedRoles) {
        // Pastikan middleware AuthenticateMiddleware sudah berjalan dan mengisi $_SESSION['user']
        if (!isset($_SESSION['user']) || !isset($_SESSION['user']['role_name'])) {
            ResponseFormatter::error('Forbidden: User role not found.', 403);
        }

        $userRole = $_SESSION['user']['role_name'];

        // Cek apakah role user ada di dalam daftar role yang diizinkan
        if (!in_array($userRole, $allowedRoles)) {
            ResponseFormatter::error('Forbidden: You do not have the required role.', 403);
        }
    }

}