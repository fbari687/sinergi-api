<?php

namespace app\controllers;

use app\helpers\ResponseFormatter;
use app\models\Notification;
use app\models\User;
use app\models\Community;

class NotificationController
{
    public function index() {
        $userId = $_SESSION['user_id'];
        $notificationModel = new Notification();

        // 1. Ambil Notifikasi (Limit 50 terbaru dari Model)
        $notifs = $notificationModel->getAllByUserId($userId);

        // 2. Ambil Jumlah Belum Dibaca (Untuk badge di navbar)
        $unreadCount = $notificationModel->countUnread($userId);

        $config = require BASE_PATH . '/config/app.php';
        $storageBaseUrl = $config['storage_url'];

        // 3. Format Data (Termasuk Logika Gambar Dinamis)
        $formattedNotifs = array_map(function($notif) use ($storageBaseUrl) {

            $imageUrl = null;

            // --- LOGIKA GAMBAR DINAMIS ---
            // Cek tipe notifikasi.
            // A. Jika berkaitan dengan Komunitas (misal: Approved Join, Invite), ambil Logo Komunitas.
            // B. Jika interaksi User (Like, Comment), ambil Foto Actor (User).

            if ($notif['type'] === 'COMMUNITY_JOIN_APPROVED' || $notif['type'] === 'COMMUNITY_INVITE') {
                // A. Ambil Logo Komunitas
                if ($notif['reference_id']) {
                    $commModel = new Community();
                    $comm = $commModel->findById($notif['reference_id']);

                    if ($comm) {
                        // Gunakan path_to_icon jika ada, jika tidak pakai default
                        $iconPath = $comm['path_to_thumbnail'] ?? 'defaults/community_default.png';
                        $imageUrl = $storageBaseUrl . $iconPath;
                    } else {
                        // Fallback jika komunitas sudah dihapus
                        $imageUrl = '/logo_sinergi.png';
                    }
                }
            } else {
                // B. Default: Ambil Foto Actor (User yang me-like/komen)
                if ($notif['actor_id']) {
                    $userModel = new User();
                    $actor = $userModel->findById($notif['actor_id']);

                    if ($actor) {
                        $profilePath = $actor['path_to_profile_picture'] ?? 'defaults/user_default.png';
                        $imageUrl = $storageBaseUrl . $profilePath;
                    } else {
                        // Fallback jika user sudah dihapus
                        $imageUrl = '/defaults/user_default.png';
                    }
                } else {
                    // C. Jika Actor NULL (Notifikasi Sistem), pakai Logo Sinergi (Asset Frontend)
                    $imageUrl = '/logo_sinergi.png';
                }
            }

            return [
                'id' => $notif['id'],
                'type' => $notif['type'],
                'message' => $notif['message'], // Pesan ini diharapkan sudah berisi HTML (<b>Bold</b>) dari saat dibuat
                'link' => $notif['link_to_page'],
                'image_url' => $imageUrl, // <--- URL Gambar final untuk Frontend
                'is_read' => !is_null($notif['read_at']),
                'created_at' => $notif['created_at']
            ];

        }, $notifs);

        ResponseFormatter::success([
            'notifications' => $formattedNotifs,
            'unread_count' => $unreadCount
        ], 'Notifications fetched successfully');
    }

    public function markAsRead($id) {
        $model = new Notification();
        if ($model->markAsReadById($id)) {
            ResponseFormatter::success(null, 'Notification marked as read');
        } else {
            ResponseFormatter::error('Failed to update notification', 500);
        }
    }

    public function readAll() {
        $userId = $_SESSION['user_id'];
        $model = new Notification();
        if ($model->markAsReadAll($userId)) {
            ResponseFormatter::success(null, 'All notifications marked as read');
        } else {
            ResponseFormatter::error('Failed to update notifications', 500);
        }
    }

    public function delete($id) {
        $notificationModel = new Notification();
        if ($notificationModel->delete($id)) {
            ResponseFormatter::success(null, 'Notification deleted successfully');
        } else {
            ResponseFormatter::error('Failed to delete notification', 500);
        }
    }
}