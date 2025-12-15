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
            $period = $_GET['period'] ?? '7_days';
            $type = $_GET['type'] ?? 'ALL_COMBINED';

            $dashboard = new Dashboard();
            // Pass parameter ke method getOverview
            $data = $dashboard->getOverview($period, $type);

            $config = require BASE_PATH . '/config/app.php';
            $storageBaseUrl = $config['storage_url'];

            $data['leaderboard'] = $this->formatMemberData($data['leaderboard'], $storageBaseUrl);

            ResponseFormatter::success($data, 'Dashboard overview fetched');
        } catch (\Throwable $e) {
            ResponseFormatter::error($e->getMessage(), 500);
        }
    }

    public function globalLeaderboard()
    {
        try {
            $period = $_GET['period'] ?? 'this_month';

            $dashboard = new Dashboard();

            // 1. Hitung tanggal berdasarkan helper yang sudah dibuat sebelumnya
            [$start, $end] = $dashboard->getDateRange($period);

            // 2. Ambil data dengan limit lebih besar (misal 50 atau 100)
            $data = $dashboard->getGlobalLeaderboard($start, $end, 100);

            $config = require BASE_PATH . '/config/app.php';
            $storageBaseUrl = $config['storage_url'];

            $data = $this->formatMemberData($data, $storageBaseUrl);

            ResponseFormatter::success($data, 'Global leaderboard fetched');
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

    public function rejectRequest($id)
    {
        $requestModel = new AccountRequest();
        if ($requestModel->reject($id)) {
            ResponseFormatter::success(null, 'Request rejected');
        } else {
            ResponseFormatter::error('Failed to reject', 500);
        }
    }

    private function formatMemberData($members, $storageBaseUrl) {
        return array_map(function($member) use ($storageBaseUrl) {
            if (!empty($member['path_to_profile_picture'])) {
                $member['profile_picture'] = $storageBaseUrl . $member['path_to_profile_picture'];
            } else {
                $member['profile_picture'] = null;
            }
            unset($member['path_to_profile_picture']);
            return $member;
        }, $members);
    }
}