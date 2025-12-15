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