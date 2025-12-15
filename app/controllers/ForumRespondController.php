<?php

namespace app\controllers;

use app\helpers\FileHelper;
use app\helpers\ResponseFormatter;
use app\models\Forum;
use app\models\ForumRespond;
require BASE_PATH . '/vendor/autoload.php';
use HTMLPurifier;
use HTMLPurifier_Config;

class ForumRespondController {
    public function index($slug, $forumId) {
        $respondModel = new ForumRespond();
        $currentUserId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

        // --- 1. Ambil Parameter Filter & Pagination ---
        // Ambil page dari query string, default 1
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        if ($page < 1) $page = 1;

        // Ambil sort dari query string, default 'top'
        $sort = isset($_GET['sort']) ? $_GET['sort'] : 'top';

        // Konfigurasi Limit
        $limit = 10;
        $offset = ($page - 1) * $limit;

        // --- 2. Ambil Data Jawaban (Paginated) ---
        $answers = $respondModel->getAnswersByForumId($forumId, $currentUserId, $sort, $limit, $offset);

        // --- 3. Hitung Total Data (Untuk Meta Pagination) ---
        $totalAnswers = $respondModel->countByForumId($forumId);
        $totalPages = ceil($totalAnswers / $limit);

        // --- 4. Format Data (Sama seperti logika sebelumnya) ---
        $config = require BASE_PATH . '/config/app.php';

        $formattedAnswers = array_map(function($ans) use ($respondModel, $config) {
            $ans['profile_picture_url'] = $ans['profile_picture'] ? $config['storage_url'] . $ans['profile_picture'] : null;
            $ans['media_url'] = $ans['path_to_media'] ? $config['storage_url'] . $ans['path_to_media'] : null;

            // Ambil Komentar/Reply untuk jawaban ini (Nested)
            // Note: Reply biasanya tidak dipagination di level ini, jadi tetap ambil semua
            $replies = $respondModel->getRepliesByParentId($ans['id']);
            $ans['replies'] = array_map(function($r) use ($config) {
                $r['profile_picture_url'] = $r['profile_picture'] ? $config['storage_url'] . $r['profile_picture'] : null;
                return $r;
            }, $replies);

            return $ans;
        }, $answers);

        // --- 5. Susun Response dengan Meta ---
        $response = [
            'data' => $formattedAnswers,
            'meta' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total_items' => $totalAnswers,
                'total_pages' => $totalPages
            ]
        ];

        ResponseFormatter::success($response, "Answers fetched successfully");
    }

    public function store($slug, $forumId) {
        $data = $_POST;
        if (empty($data['message'])) {
            ResponseFormatter::error("Message cannot be empty", 400);
        }

        // parent_id opsional. Jika null = Answer, Jika isi = Reply
        $parentId = $data['parent_id'] == 'null' ? null : $data['parent_id'];

        if (isset($_FILES['media']) && $_FILES['media']['error'] === 0) {
            $uploadedPath = FileHelper::upload(
                $_FILES['media'],
                'uploads/forum_respond_media',
                ['image/jpeg', 'image/png', 'image/jpg'],
                5 * 1024 * 1024
            );

            if (!$uploadedPath) {
                ResponseFormatter::error("Failed to upload media", 500);
            }
        }

        $config = HTMLPurifier_Config::createDefault();
        $purifier = new HTMLPurifier($config);
        $safeHtmlMessage = $purifier->purify($data['message']);

        $respondModel = new ForumRespond();
        $id = $respondModel->create(
            $_SESSION['user_id'],
            $forumId,
            $safeHtmlMessage,
            $parentId,
            $uploadedPath ?? null,
        );

        if ($id) {
            ResponseFormatter::success(['id' => $id], "Response added");
        } else {
            ResponseFormatter::error("Failed", 500);
        }
    }

    public function markAccepted($slug, $forumId, $respondId) {
        $forumModel = new Forum();
        $forum = $forumModel->findById($forumId);

        if (!$forum) ResponseFormatter::error("Forum not found", 404);

        // PERMISSION CHECK:
        // Siapa yang boleh menandai solusi?
        // Biasanya hanya PEMBUAT PERTANYAAN (User ID di tabel forum)
        if ($forum['user_id'] != $_SESSION['user_id']) {
            ResponseFormatter::error("Only the question author can mark the solution", 403);
        }

        $respondModel = new ForumRespond();
        // Cek apakah respond ini milik forum ini (Security)
        $respond = $respondModel->findById($respondId);
        if (!$respond || $respond['forum_id'] != $forumId) {
            ResponseFormatter::error("Invalid answer ID", 400);
        }

        if ($respondModel->markAsAccepted($respondId, $forumId)) {
            ResponseFormatter::success(null, "Answer marked as solution");
        } else {
            ResponseFormatter::error("Failed to mark solution", 500);
        }
    }

    public function storeReply($forumRespondId) {
        $data = $_POST;

        if (!isset($data['message'])) {
            ResponseFormatter::error('Incomplete data', 400);
        }

        $forumRespondModel = new ForumRespond();
        $forumRespondData = $forumRespondModel->findById($forumRespondId);
        if (!$forumRespondData) {
            ResponseFormatter::error('Forum respond not found', 404);
        }
        $dataForumRespond = [
            'forum_id' => $forumRespondData['forum_id'],
            'user_id' => $_SESSION['user_id'],
            'message' => strip_tags($data['message']),
            'parent_id' => $forumRespondId
        ];

        $forumRespond = $forumRespondModel->create($dataForumRespond);
        if (!$forumRespond) {
            ResponseFormatter::error('Failed to create forum respond', 500);
        }
        ResponseFormatter::success(null, 'Forum respond created successfully');
    }

    public function update($forumRespondId) {
        $data = $_POST;

        if (!isset($data['message'])) {
            ResponseFormatter::error('Incomplete data', 400);
        }

        $forumRespondModel = new ForumRespond();
        $forumRespondData = $forumRespondModel->findById($forumRespondId);
        if (!$forumRespondData) {
            ResponseFormatter::error('Forum respond not found', 404);
        }

        $config = HTMLPurifier_Config::createDefault();
        $purifier = new HTMLPurifier($config);
        $safeHtmlMessage = $purifier->purify($data['message']);

        $finalMediaPath = $forumRespondData['path_to_media'];

        if (isset($data['delete_media']) && $data['delete_media'] === 'true') {
            if ($forumRespondData['path_to_media']) {
                FileHelper::delete($forumRespondData['path_to_media']);
            }
            $finalMediaPath = null;
        }

        if (isset($_FILES['media']) && $_FILES['media']['error'] === 0) {
            if ($forumRespondData['path_to_media'] && $finalMediaPath !== null) {
                FileHelper::delete($forumRespondData['path_to_media']);
            }

            $uploadedPath = FileHelper::upload(
                $_FILES['media'],
                'uploads/forum_respond_media',
                ['image/jpeg', 'image/png', 'image/jpg'],
                5 * 1024 * 1024
            );

            if (!$uploadedPath) {
                ResponseFormatter::error("Failed to upload media", 500);
                return;
            }

            $finalMediaPath = $uploadedPath;
        }

        $dataForumRespond = [
            'message' => $safeHtmlMessage,
            'path_to_media' => $finalMediaPath
        ];

        $updateForumRespond = $forumRespondModel->update($forumRespondId, $dataForumRespond);
        if (!$updateForumRespond) {
            ResponseFormatter::error('Failed to update forum respond', 500);
        }

        ResponseFormatter::success(null, 'Forum respond updated successfully');
    }

    public function delete($forumRespondId) {
        $forumRespondModel = new ForumRespond();
        $forumRespondData = $forumRespondModel->findById($forumRespondId);
        if (!$forumRespondData) {
            ResponseFormatter::error('Forum respond not found', 404);
        }
        $deleteForumRespond = $forumRespondModel->delete($forumRespondId);
        if (!$deleteForumRespond) {
            ResponseFormatter::error('Failed to delete forum respond', 500);
        }
        if ($forumRespondData['path_to_media']) {
            FileHelper::delete($forumRespondData['path_to_media']);
        }
        ResponseFormatter::success(null, 'Forum respond deleted successfully');
    }
}