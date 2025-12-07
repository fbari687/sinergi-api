<?php

namespace app\controllers;

use app\helpers\FileHelper;
use app\helpers\ResponseFormatter;
use app\models\User;

class UserController
{
    public function index()
    {
        $userModel = new User();
        $users = $userModel->getAll();
        ResponseFormatter::Success($users, 'Users fetched successfully');
    }

    public function show($id)
    {
        $userModel = new User();
        $user = $userModel->findById($id);

        if ($user) {
            ResponseFormatter::Success($user, 'User fetched successfully');
        } else {
            ResponseFormatter::error('User not found', 404);
        }
    }

    public function updateProfile()
    {
        $userId = $_SESSION['user_id'];
        $userModel = new User();
        $currentUser = $userModel->findById($userId);

        if (!$currentUser) {
            ResponseFormatter::error('User not found', 404);
            return;
        }

        $data = $_POST;

        // 1. Validasi Username
        $newUsername = isset($data['username']) ? trim($data['username']) : $currentUser['username'];

        // Jika username berubah, cek apakah sudah dipakai orang lain
        if ($newUsername !== $currentUser['username']) {
            // Cek regex username (misal: huruf, angka, underscore, titik)
            if (!preg_match('/^[a-zA-Z0-9._]+$/', $newUsername)) {
                ResponseFormatter::error('Username hanya boleh berisi huruf, angka, titik, dan underscore.', 400);
                return;
            }

            // Cek di DB
            $existingUser = $userModel->findByUsername($newUsername);
            if ($existingUser) {
                ResponseFormatter::error('Username sudah digunakan pengguna lain.', 409);
                return;
            }
        }

        // 2. Handle Bio
        $newBio = isset($data['bio']) ? strip_tags($data['bio']) : $currentUser['bio'];

        // 3. Handle Foto Profil
        $uploadedPath = $currentUser['path_to_profile_picture']; // Default pakai yang lama

        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === 0) {
            // Tentukan path foto default
            $defaultProfilePicture = "uploads/profile_picture/unknown.png";

            // Hapus foto lama HANYA JIKA:
            // 1. Ada path foto lama
            // 2. Foto lama BUKAN foto default
            if ($currentUser['path_to_profile_picture'] && $currentUser['path_to_profile_picture'] !== $defaultProfilePicture) {
                FileHelper::delete($currentUser['path_to_profile_picture']);
            }

            $uploadedPath = FileHelper::upload(
                $_FILES['profile_picture'],
                'uploads/profile_picture', // Pastikan folder ini ada
                ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'],
                2 * 1024 * 1024 // 2 MB
            );

            if (!$uploadedPath) {
                ResponseFormatter::error('Gagal mengupload foto profil', 500);
                return;
            }
        }

        // 4. Update Database
        $updateData = [
            'username' => $newUsername,
            'bio' => $newBio,
            'path_to_profile_picture' => $uploadedPath
        ];

        // Implementasi update di model
        $result = $userModel->update($userId, $updateData);

        if ($result) {
            // Kembalikan data user terbaru agar frontend bisa update state authStore
            $updatedUser = $userModel->findById($userId);

            // Format URL gambar untuk response
            $config = require BASE_PATH . '/config/app.php';
            if ($updatedUser['path_to_profile_picture']) {
                $updatedUser['profile_picture_url'] = $config['storage_url'] . $updatedUser['path_to_profile_picture'];
            } else {
                $updatedUser['profile_picture_url'] = null;
            }

            ResponseFormatter::success($updatedUser, 'Profil berhasil diperbarui');
        } else {
            ResponseFormatter::error('Gagal memperbarui profil di database', 500);
        }
    }
}