<?php

namespace app\controllers;

use app\helpers\ResponseFormatter;
use app\models\UserProfile;
use app\models\User;

class ProfileController
{
    public function store()
    {
        $userId = $_SESSION['user_id'];
        $role = $_SESSION['role'] ?? '';
        $data = $_POST;

        $profileModel = new UserProfile();

        // Ambil email user untuk deteksi tahun
        $userModel = new User();
        $currentUser = $userModel->findById($userId);
        $email = $currentUser['email'];

        if ($role === 'Mahasiswa') {
            if (!isset($data['nim']) || !isset($data['prodi'])) {
                ResponseFormatter::error('NIM dan Prodi wajib diisi', 400);
                return;
            }

            // LOGIKA AUTO DETECT TAHUN DARI EMAIL
            // Contoh: user.name.tik24@stu.pnj.ac.id -> 2024
            $tahunMasuk = date('Y'); // Default

            try {
                $localPart = explode('@', $email)[0];
                $parts = explode('.', $localPart);
                $lastPart = end($parts); // ambil "tik24"

                if (preg_match('/(\d{2})$/', $lastPart, $matches)) {
                    $yearShort = $matches[1]; // "24"
                    $tahunMasuk = intval("20" . $yearShort); // 2024
                }
            } catch (\Exception $e) { }

            if ($data['prodi'] !== 'TKJ') {
                $lamaPerkuliahan = 4;
            } else {
                $lamaPerkuliahan = 1;
            }
            $data['tahun_masuk'] = $tahunMasuk;
            $data['tahun_perkiraan_lulus'] = $tahunMasuk + $lamaPerkuliahan;

            if ($profileModel->createMahasiswaProfile($userId, $data)) {
                ResponseFormatter::success(null, 'Profil mahasiswa berhasil disimpan');
            } else {
                ResponseFormatter::error('Gagal menyimpan profil', 500);
            }
        }
        elseif ($role === 'Dosen') {
            if (!isset($data['nidn']) || !isset($data['bidang_keahlian'])) {
                ResponseFormatter::error('NIDN dan Keahlian wajib diisi', 400);
                return;
            }
            if ($profileModel->createDosenProfile($userId, $data)) {
                ResponseFormatter::success(null, 'Profil dosen berhasil disimpan');
            } else {
                ResponseFormatter::error('Gagal menyimpan profil', 500);
            }
        }
        else {
            ResponseFormatter::success(null, 'Role ini tidak butuh profil tambahan');
        }
    }

    public function show($username)
    {
        // 1. Panggil logika dari Model
        $userModel = new User();
        $user = $userModel->findByUsernameWithProfile($username);


        // 2. Handle jika user tidak ditemukan
        if (!$user) {
            ResponseFormatter::error("User not found", 404);
            return;
        }

        $config = require BASE_PATH . '/config/app.php';
        $storageBaseUrl = $config['storage_url'];

        $formattedUser = $this->formatUserData([$user], $storageBaseUrl)[0];

        // 3. Return sukses
        ResponseFormatter::success($formattedUser, "Berhasil mengambil data profil");
    }

    private function formatUserData($users, $storageBaseUrl) {
        return array_map(function($user) use ($storageBaseUrl) {
            // Cek apakah user punya foto profil
            if (!empty($user['path_to_profile_picture'])) {
                // Gabungkan Base URL dengan Path dari database
                $user['profile_picture'] = $storageBaseUrl . $user['path_to_profile_picture'];
            } else {
                // Opsional: Anda bisa set default avatar di sini jika mau
                $user['profile_picture'] = null;
                // Contoh default: $user['profile_picture_url'] = $storageBaseUrl . '/defaults/avatar.png';
            }

            // Hapus key lama (path mentah) agar response bersih
            unset($user['path_to_profile_picture']);

            return $user;
        }, $users);
    }
}