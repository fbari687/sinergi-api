<?php

namespace app\controllers;

use app\helpers\ResponseFormatter;
use app\models\Report;
use app\models\Community;
use app\models\Post;
use app\models\Forum;
use app\models\User;
use app\models\Comment;
use app\models\ForumRespond;
use app\core\Database;

class ReportController
{
    /**
     * POST /api/reports
     * Body:
     * - reportable_type: 'COMMUNITY' | 'POST' | 'FORUM' | 'USER' | 'COMMENT' | 'FORUM_RESPOND'
     * - reportable_id: integer
     * - violation_type: string (misal: 'SPAM', 'HATE', 'HARASSMENT', 'OTHER')
     * - reason: string (penjelasan teks dari user)
     */
    public function store()
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            ResponseFormatter::error('Unauthorized', 401);
        }

        $data = $_POST;

        $required = ['reportable_type', 'reportable_id', 'violation_type', 'reason'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                ResponseFormatter::error("Field {$field} is required", 400);
            }
        }

        $reportableType = strtoupper(trim($data['reportable_type']));
        $reportableId   = (int) $data['reportable_id'];
        $violationType  = trim($data['violation_type']);
        $reason         = trim($data['reason']);

        // Validasi tipe yang diizinkan
        $allowedTypes = ['COMMUNITY', 'POST', 'FORUM', 'USER', 'COMMENT', 'FORUM_RESPOND'];
        if (!in_array($reportableType, $allowedTypes, true)) {
            ResponseFormatter::error('Invalid reportable_type', 400);
        }

        // Optional: validasi violation_type
        // Boleh kamu kunci misal ['SPAM','SARA','PORNO','KEKERASAN','LAINNYA']
        if (strlen($violationType) > 100) {
            ResponseFormatter::error('violation_type too long', 400);
        }

        // Cek apakah target benar-benar ada
        if (!$this->targetExists($reportableType, $reportableId)) {
            ResponseFormatter::error('Target object not found', 404);
        }

        $reportModel = new Report();
        $success = $reportModel->createReport(
            $userId,
            $reportableType,
            $reportableId,
            $violationType,
            $reason
        );

        if (!$success) {
            ResponseFormatter::error('Failed to create report', 500);
        }

        ResponseFormatter::success(null, 'Report submitted successfully');
    }

    /**
     * Helper: cek apakah target yg di-report eksis
     */
    private function targetExists($type, $id): bool
    {
        switch ($type) {
            case 'COMMUNITY':
                $model = new Community();
                return (bool) $model->findById($id);

            case 'POST':
                $model = new Post();
                return (bool) $model->findById($id);

            case 'FORUM':
                $model = new Forum();
                return (bool) $model->findById($id);

            case 'USER':
                $model = new User();
                return (bool) $model->findById($id);

            case 'COMMENT':
                $model = new Comment();
                return (bool) $model->findById($id);

            case 'FORUM_RESPOND':
                $model = new ForumRespond();
                return (bool) $model->findById($id);

            default:
                return false;
        }
    }

    /**
     * GET /api/admin/reports/summary?status=OPEN|IN_REVIEW|RESOLVED|ALL&page=1&per_page=20
     * Output: list yang sudah "merge" per target
     */
    public function adminSummary()
    {
        $status   = $_GET['status'] ?? 'OPEN';
        $page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage  = isset($_GET['per_page']) ? max(1, (int)$_GET['per_page']) : 10;
        $offset   = ($page - 1) * $perPage;

        $reportModel = new Report();
        [$rows, $total] = $reportModel->getSummary($status, $perPage, $offset);

        $formatted = array_map(function ($row) {
            $preview = $this->buildTargetPreview($row['reportable_type'], $row['reportable_id']);

            return [
                'reportable_type'  => $row['reportable_type'],
                'reportable_id'    => (int) $row['reportable_id'],
                'total_reports'    => (int) $row['total_reports'],
                'first_report_at'  => $row['first_report_at'],
                'last_report_at'   => $row['last_report_at'],
                'status'           => $row['current_status'],
                'target_preview'   => $preview,
            ];
        }, $rows);

        ResponseFormatter::success([
            'items'    => $formatted,
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
        ], 'Reports summary fetched');
    }


    public function adminDeleteTarget($type, $id)
    {
        $reportableType = strtoupper($type);
        $reportableId   = (int) $id;

        $allowedTypes = ['COMMUNITY', 'POST', 'FORUM', 'USER', 'COMMENT', 'FORUM_RESPOND'];
        if (!in_array($reportableType, $allowedTypes, true)) {
            ResponseFormatter::error('Invalid reportable_type', 400);
        }

        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();

        try {
            // 1) Hapus / nonaktifkan konten
            switch ($reportableType) {
                case 'POST':
                    $stmt = $db->prepare("DELETE FROM posts WHERE id = :id");
                    $stmt->execute([':id' => $reportableId]);
                    break;

                case 'COMMENT':
                    $stmt = $db->prepare("DELETE FROM comments WHERE id = :id");
                    $stmt->execute([':id' => $reportableId]);
                    break;

                case 'FORUM':
                    $stmt = $db->prepare("DELETE FROM forums WHERE id = :id");
                    $stmt->execute([':id' => $reportableId]);
                    break;

                case 'FORUM_RESPOND':
                    $stmt = $db->prepare("DELETE FROM forums_responds WHERE id = :id");
                    $stmt->execute([':id' => $reportableId]);
                    break;

                case 'COMMUNITY':
                    // Hapus komunitas → akan cascade ke posts, forums, members, dst
                    $stmt = $db->prepare("DELETE FROM communities WHERE id = :id");
                    $stmt->execute([':id' => $reportableId]);
                    break;

                case 'USER':
                    // User: jangan dihapus, tapi nonaktifkan
                    $stmt = $db->prepare("UPDATE users SET is_active = FALSE WHERE id = :id");
                    $stmt->execute([':id' => $reportableId]);
                    break;
            }

            // 2) Tandai semua report terkait sebagai RESOLVED
            $reportModel = new Report();
            $reportModel->updateStatusByTarget($reportableType, $reportableId, 'RESOLVED');

            $db->commit();

            ResponseFormatter::success(null, 'Konten telah dihapus / dinonaktifkan dan laporan ditandai selesai.');
        } catch (\Throwable $e) {
            $db->rollBack();
            ResponseFormatter::error('Gagal menghapus konten: ' . $e->getMessage(), 500);
        }
    }


    /**
     * Helper: bikin preview target buat admin
     */
    private function buildTargetPreview($type, $id)
    {
        // Ambil base URL storage
        $config = require BASE_PATH . '/config/app.php';
        $storageBaseUrl = rtrim($config['storage_url'], '/');

        $buildThumbnailUrl = function ($path) use ($storageBaseUrl) {
            if (!$path) return null;
            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                return $path;
            }
            return $storageBaseUrl . '/' . ltrim($path, '/');
        };

        switch ($type) {
            case 'COMMUNITY':
                $model = new Community();
                $c = $model->findById($id);
                if (!$c) return null;

                return [
                    'label' => $c['name'],
                    'type_label' => 'Komunitas',
                    'thumbnail' => $buildThumbnailUrl($c['path_to_thumbnail']),
                    'slug' => $c['slug'] ?? ''
                ];

            case 'POST':
                $model = new Post();
                $p = $model->findById($id);
                if (!$p) return null;

                $raw = $p['description'] ?? '';
                $text = preg_replace('/\s+/', ' ', trim(strip_tags($raw)));
                $limit = 80;
                $snippet = mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;

                return [
                    'label' => $snippet,
                    'type_label' => 'Post',
                    'thumbnail' => $buildThumbnailUrl($p['path_to_media']),
                ];

            case 'FORUM':
                $model = new Forum();
                $f = $model->findById($id);
                if (!$f) return null;

                $raw = $f['description'] ?? '';
                $text = preg_replace('/\s+/', ' ', trim(strip_tags($raw)));
                $limit = 80;
                $snippet = mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;

                return [
                    'label' => $f['title'] ?? $snippet,
                    'type_label' => 'Forum Diskusi',
                    'thumbnail' => $buildThumbnailUrl($f['path_to_media']),
                ];

            case 'USER':
                $model = new User();
                $u = $model->findById($id);
                if (!$u) return null;

                return [
                    'label' => $u['fullname'],
                    'type_label' => 'Akun Pengguna',
                    'thumbnail' => $buildThumbnailUrl($u['path_to_profile_picture']),
                ];

            case 'COMMENT':
                $model = new Comment();
                $c = $model->findById($id);
                if (!$c) return null;

                $raw = $c['content'];
                $text = preg_replace('/\s+/', ' ', trim(strip_tags($raw)));
                $limit = 80;
                $snippet = mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;

                return [
                    'label' => $snippet,
                    'type_label' => 'Komentar',
                    'thumbnail' => null,
                ];

            case 'FORUM_RESPOND':
                $model = new ForumRespond();
                $r = $model->findById($id);
                if (!$r) return null;

                $raw = $r['message'];
                $text = preg_replace('/\s+/', ' ', trim(strip_tags($raw)));
                $limit = 80;
                $snippet = mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;

                return [
                    'label' => $snippet,
                    'type_label' => 'Jawaban Forum',
                    'thumbnail' => null,
                ];

            default:
                return null;
        }
    }

    /**
     * GET /api/admin/reports/{type}/{id}
     * Contoh: /api/admin/reports/POST/12
     * Menampilkan semua report terhadap target tsb
     */
    public function adminDetail($type, $id)
    {
        $reportableType = strtoupper($type);
        $reportableId   = (int) $id;

        $reportModel = new Report();
        $reports = $reportModel->getReportsByTarget($reportableType, $reportableId);
        $preview = $this->buildTargetPreview($reportableType, $reportableId);

        ResponseFormatter::success([
            'target_preview' => $preview,
            'reports'        => $reports,
        ], 'Reports detail fetched');
    }


    /**
     * POST /api/admin/reports/{type}/{id}/status
     * Body: status = 'OPEN' | 'IN_REVIEW' | 'RESOLVED' | 'IGNORED'
     * Dipakai saat admin klik "Tandai selesai" atau "Sedang ditinjau" di kelola laporan
     */
    public function updateStatusByTarget($type, $id)
    {
        $data = $_POST;
        if (empty($data['status'])) {
            ResponseFormatter::error('Status is required', 400);
        }

        $allowedStatus = ['OPEN', 'IN_REVIEW', 'RESOLVED', 'IGNORED'];
        if (!in_array($data['status'], $allowedStatus, true)) {
            ResponseFormatter::error('Invalid status value', 400);
        }

        $reportableType = strtoupper($type);
        $reportableId   = (int) $id;

        $reportModel = new Report();
        $success = $reportModel->updateStatusByTarget($reportableType, $reportableId, $data['status']);

        if (!$success) {
            ResponseFormatter::error('Failed to update status', 500);
        }

        ResponseFormatter::success(null, 'Report status updated');
    }
}
