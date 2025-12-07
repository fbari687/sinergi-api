<?php

namespace app\controllers;

use app\core\Database;
use app\helpers\ResponseFormatter;
use app\models\AccountRequest;
use app\models\Dashboard;
use app\models\User;
use app\models\CommunityMember;
use app\helpers\MailHelper; // Asumsi ada helper email

class AdminController
{
    public function dashboardOverview()
    {
        try {
            $dashboard = new Dashboard();
            $data = $dashboard->getOverview();

            ResponseFormatter::success($data, 'Dashboard overview fetched');
        } catch (\Throwable $e) {
            ResponseFormatter::error($e->getMessage(), 500);
        }
    }

    // GET /api/admin/account-requests
    public function getPendingRequests()
    {
        // Validasi Role Admin Sinergi disini (Middleware)

        $requestModel = new AccountRequest();
        $requests = $requestModel->getAllPending();

        ResponseFormatter::success($requests, 'Pending requests fetched');
    }

    public function approveRequest($id)
    {
        $accountRequestModel = new AccountRequest();
        $request = $accountRequestModel->findById($id);

        if (!$request) {
            ResponseFormatter::error('Permintaan tidak ditemukan', 404);
            return;
        }

        if ($request['status'] !== 'PENDING') {
            ResponseFormatter::error('Permintaan ini sudah diproses sebelumnya.', 400);
            return;
        }

        // Generate token di PHP (biar bisa dipakai di email)
        $activationToken = bin2hex(random_bytes(32));

        // UPDATE account_requests: status APPROVED + simpan token
        // Trigger di DB akan isi token_expires_at + updated_at
        $success = $accountRequestModel->approve((int)$id, $activationToken);

        if (!$success) {
            ResponseFormatter::error('Gagal menyetujui permintaan akun.', 500);
            return;
        }

        // Kirim email ke calon pengguna
        $frontendBaseUrl = $_ENV['FRONTEND_URL']; // ganti ke URL frontend kamu
        $activationUrl = $frontendBaseUrl . '/activate-account?token=' . urlencode($activationToken);

        MailHelper::sendActivationEmail($request['email'], $activationUrl);

        ResponseFormatter::success(['token' => $activationToken], 'Permintaan akun disetujui. Link aktivasi telah dikirim ke email calon pengguna.');
    }

    // POST /api/admin/account-requests/{id}/approve
//    public function approveRequest($id)
//    {
//        $conn = Database::getInstance()->getConnection();
//        $requestModel = new AccountRequest();
//        $request = $requestModel->findById($id);
//
//        if (!$request || $request['status'] !== 'PENDING') {
//            ResponseFormatter::error('Request not found or already processed', 404);
//            return;
//        }
//
//        try {
//            // MULAI TRANSAKSI
//            $conn->beginTransaction();
//
//            // 1. Buat User Baru di tabel 'users'
//            // Password kita set random dulu, user akan set sendiri saat aktivasi
//            $tempPassword = bin2hex(random_bytes(8));
//
//            // Kita pakai model User manual query agar bisa insert ke dalam transaction
//            // (Atau gunakan User Model yang ada jika support transaction passing, disini saya tulis raw query agar aman dalam transaction)
//
//            // Ambil Role ID berdasarkan nama role (Alumni/Mitra/Pakar)
//            $stmtRole = $conn->prepare("SELECT id FROM roles WHERE name = :name");
//            $stmtRole->execute([':name' => $request['role_name']]);
//            $roleId = $stmtRole->fetchColumn();
//
//            if (!$roleId) throw new \Exception("Role {$request['role_name']} not found");
//
//            $hashedPassword = password_hash($tempPassword, PASSWORD_BCRYPT);
//            $defaultBio = "User eksternal ({$request['role_name']})";
//            $defaultPic = "uploads/profile_picture/unknown.png";
//
//            $sqlUser = "INSERT INTO users (fullname, username, email, bio, password, path_to_profile_picture, role_id)
//                        VALUES (:fullname, :username, :email, :bio, :password, :pic, :role_id) RETURNING id";
//
//            $stmtUser = $conn->prepare($sqlUser);
//            $stmtUser->execute([
//                ':fullname' => $request['fullname'],
//                ':username' => $request['username'],
//                ':email' => $request['email'],
//                ':bio' => $defaultBio,
//                ':password' => $hashedPassword,
//                ':pic' => $defaultPic,
//                ':role_id' => $roleId
//            ]);
//            $newUserId = $stmtUser->fetchColumn();
//
//            // 2. Insert ke Tabel Profil Spesifik
//            $profileData = $request['profile_data']; // Sudah di-decode di model findById
//
//            if ($request['role_name'] === 'Alumni') {
//                $sqlProfile = "INSERT INTO alumni_profiles (user_id, tahun_lulus, pekerjaan_saat_ini, nama_perusahaan)
//                               VALUES (:uid, :thn, :job, :comp)";
//                $stmtProf = $conn->prepare($sqlProfile);
//                $stmtProf->execute([
//                    ':uid' => $newUserId,
//                    ':thn' => $profileData['tahun_lulus'] ?? 0,
//                    ':job' => $profileData['pekerjaan_saat_ini'] ?? null,
//                    ':comp' => $profileData['nama_perusahaan'] ?? null
//                ]);
//            }
//            elseif ($request['role_name'] === 'Mitra') {
//                $sqlProfile = "INSERT INTO mitra_profiles (user_id, nama_perusahaan, jabatan, alamat_perusahaan)
//                               VALUES (:uid, :comp, :job, :addr)";
//                $stmtProf = $conn->prepare($sqlProfile);
//                $stmtProf->execute([
//                    ':uid' => $newUserId,
//                    ':comp' => $profileData['nama_perusahaan'] ?? '-',
//                    ':job' => $profileData['jabatan'] ?? '-',
//                    ':addr' => $profileData['alamat_perusahaan'] ?? null
//                ]);
//            }
//            elseif ($request['role_name'] === 'Pakar') {
//                $sqlProfile = "INSERT INTO pakar_profiles (user_id, bidang_keahlian, instansi_asal)
//                               VALUES (:uid, :skill, :inst)";
//                $stmtProf = $conn->prepare($sqlProfile);
//                $stmtProf->execute([
//                    ':uid' => $newUserId,
//                    ':skill' => $profileData['bidang_keahlian'] ?? '-',
//                    ':inst' => $profileData['instansi_asal'] ?? null
//                ]);
//            }
//
//            // 3. Masukkan User Baru ke Komunitas Pengundang
//            $sqlMember = "INSERT INTO community_members (user_id, community_id, role, status)
//                          VALUES (:uid, :cid, 'MEMBER', 'GRANTED')";
//            $stmtMember = $conn->prepare($sqlMember);
//            $stmtMember->execute([
//                ':uid' => $newUserId,
//                ':cid' => $request['community_id']
//            ]);
//
//            // 4. Update Status Request & Generate Activation Token
//            $activationToken = bin2hex(random_bytes(32)); // Token 64 char
//            $requestModel->approve($id, $activationToken);
//
//            // 5. Kirim Email Aktivasi (Simulasi)
//             $activationLink = "http://localhost:5173/activate-account?token=" . $activationToken;
//             MailHelper::sendActivationEmail($request['email'], $activationLink);
//
//            // COMMIT TRANSAKSI
//            $conn->commit();
//
//            ResponseFormatter::success(['token' => $activationToken], 'Account approved and created successfully');
//
//        } catch (\Exception $e) {
//            $conn->rollBack();
//            ResponseFormatter::error('Approval failed: ' . $e->getMessage(), 500);
//        }
//    }

    // POST /api/admin/account-requests/{id}/reject
    public function rejectRequest($id)
    {
        $requestModel = new AccountRequest();
        if ($requestModel->reject($id)) {
            ResponseFormatter::success(null, 'Request rejected');
        } else {
            ResponseFormatter::error('Failed to reject', 500);
        }
    }
}