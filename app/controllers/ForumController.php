<?php

namespace app\controllers;

use app\helpers\FileHelper;
use app\helpers\ResponseFormatter;
use app\models\Community;
use app\models\CommunityMember;
use app\models\Forum;
require BASE_PATH . '/vendor/autoload.php';

use app\models\ForumRespond;
use app\models\Report;
use app\services\ModerationService;
use HTMLPurifier;
use HTMLPurifier_Config;

class ForumController {
    public function index($communitySlug) {
        $communityModel = new Community();
        $communityId = $communityModel->findIdBySlug($communitySlug);

        if (!$communityId) {
            ResponseFormatter::error('Community not found', 404);
        }

        $forumModel = new Forum();
        $forums = $forumModel->getAllByCommunityId($communityId);
        ResponseFormatter::Success($forums, 'Forums fetched successfully');
    }

    public function indexByCommunity($slug) {
        // ... (Logika Auth & Role Middleware sudah handle security dasar) ...

        $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
        $search = filter_input(INPUT_GET, 'q', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: "";
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $forumModel = new Forum();
        $forums = $forumModel->getAllByCommunity($slug, $search, $limit, $offset);

        // Format Foto Profil (URL Helper)
        $config = require BASE_PATH . '/config/app.php';
        $formattedForums = array_map(function($f) use ($config) {
            $f['profile_picture_url'] = $f['profile_picture'] ? $config['storage_url'] . $f['profile_picture'] : null;
            return $f;
        }, $forums);

        ResponseFormatter::success($formattedForums, "Forums fetched successfully");
    }

    public function show($slug, $id) {
        $forumModel = new Forum();
        $forum = $forumModel->findById($id, $_SESSION['user_id']);

        if (!$forum) {
            ResponseFormatter::error("Forum topic not found", 404);
        }

        $memberModel = new CommunityMember();
        $isMember = $memberModel->isUserMember($_SESSION['user_id'], $forum['community_id']);

        $isAdmin = $_SESSION['user']['role_name'] === 'Admin';

        if (!$isMember && !$isAdmin) {
            ResponseFormatter::error('You are not a member of this community', 403);
            return;
        }

        // Format Foto
        $config = require BASE_PATH . '/config/app.php';
        $forum['profile_picture_url'] = $forum['profile_picture']
            ? $config['storage_url'] . $forum['profile_picture']
            : null;

        // 2. Format Media/Lampiran Forum (INI YANG BARU)
        $forum['media_url'] = $forum['path_to_media']
            ? $config['storage_url'] . $forum['path_to_media']
            : null;

        ResponseFormatter::success($forum, "Forum detail fetched");
    }

    public function showById($id) {
        $forumModel = new Forum();
        $forum = $forumModel->findById($id, $_SESSION['user_id']);

        if (!$forum) {
            ResponseFormatter::error("Forum topic not found", 404);
        }

        $memberModel = new CommunityMember();
        $isMember = $memberModel->isUserMember($_SESSION['user_id'], $forum['community_id']);

        $isAdmin = $_SESSION['user']['role_name'] === 'Admin';

        if (!$isMember && !$isAdmin) {
            ResponseFormatter::error('You are not a member of this community', 403);
            return;
        }

        // Format Foto
        $config = require BASE_PATH . '/config/app.php';
        $forum['profile_picture_url'] = $forum['profile_picture']
            ? $config['storage_url'] . $forum['profile_picture']
            : null;

        // 2. Format Media/Lampiran Forum (INI YANG BARU)
        $forum['media_url'] = $forum['path_to_media']
            ? $config['storage_url'] . $forum['path_to_media']
            : null;

        ResponseFormatter::success($forum, "Forum detail fetched");
    }

    public function store($slug) {
        $data = $_POST;

        if (!isset($data['title']) || !isset($data['description'])) {
            ResponseFormatter::error('Incomplete data', 400);
        }

        $communityModel = new Community();
        $communityId = $communityModel->findIdBySlug($slug);

        if (!$communityId) {
            ResponseFormatter::error('Community not found', 404);
        }

        $config = HTMLPurifier_Config::createDefault();
        $purifier = new HTMLPurifier($config);
        $safeHtmlDescription = $purifier->purify($data['description']);

        if (!empty($data['title'])) {
            $moderation = new ModerationService();
            $result = $moderation->check($data['title']);

            if ($result['flagged']) {
                ResponseFormatter::error(
                    'Konten Anda terindikasi melanggar kebijakan etika kampus. Silakan perbaiki dan coba kembali.',
                    422
                );
            }
        }

        $plainText = strip_tags($safeHtmlDescription);
        $plainText = html_entity_decode($plainText);
        $plainText = trim(preg_replace('/\s+/', ' ', $plainText));

        if (!empty($plainText)) {
            $moderation = new ModerationService();
            $result = $moderation->check($plainText);

            if ($result['flagged']) {
                ResponseFormatter::error(
                    'Konten Anda terindikasi melanggar kebijakan etika kampus. Silakan perbaiki dan coba kembali.',
                    422
                );
            }
        }

        if (isset($_FILES['media']) && $_FILES['media']['error'] === 0) {
            $uploadedPath = FileHelper::upload(
                $_FILES['media'],
                'uploads/forum_media',
                ['image/jpeg', 'image/png', 'image/jpg'],
                5 * 1024 * 1024
            );

            if (!$uploadedPath) {
                ResponseFormatter::error('Failed to upload media', 500);
            }
        }

        $forumModel = new Forum();
        $forumId = $forumModel->create(
            $_SESSION['user_id'],
            $communityId,
            $data['title'],
            $safeHtmlDescription,
            $uploadedPath ?? null
        );

        if (!$forumId) {
            ResponseFormatter::error('Failed to create forum', 500);
        }
        ResponseFormatter::success(null, 'Forum created successfully');
    }

    public function update($id) {
        $forumModel = new Forum();
        $forumData = $forumModel->findById($id, $_SESSION['user_id']); // Ambil data lama

        if (!$forumData) {
            ResponseFormatter::error('Forum not found', 404);
            return;
        }

        $data = $_POST;
        // Validasi Title dan Description
        if (!isset($data['title']) || !isset($data['description'])) {
            ResponseFormatter::error('Incomplete data. Title and description are required.', 400);
            return;
        }

        // 1. Sanitasi Deskripsi menggunakan HTMLPurifier (Sama seperti PostController)
        // Ini penting agar user tidak bisa menyisipkan script berbahaya di deskripsi forum
        $config = HTMLPurifier_Config::createDefault();
        $purifier = new HTMLPurifier($config);
        $safeHtmlDescription = $purifier->purify($data['description']);

        if (!empty($data['title'])) {
            $moderation = new ModerationService();
            $result = $moderation->check($data['title']);

            if ($result['flagged']) {
                ResponseFormatter::error(
                    'Konten Anda terindikasi melanggar kebijakan etika kampus. Silakan perbaiki dan coba kembali.',
                    422
                );
            }
        }

        $plainText = strip_tags($safeHtmlDescription);
        $plainText = html_entity_decode($plainText);
        $plainText = trim(preg_replace('/\s+/', ' ', $plainText));

        if (!empty($plainText)) {
            $moderation = new ModerationService();
            $result = $moderation->check($plainText);

            if ($result['flagged']) {
                ResponseFormatter::error(
                    'Konten Anda terindikasi melanggar kebijakan etika kampus. Silakan perbaiki dan coba kembali.',
                    422
                );
            }
        }

        // 2. Logika Media (Gambar)

        // Default: Gunakan path lama jika tidak ada perubahan
        $finalMediaPath = $forumData['path_to_media'];

        // Cek A: Apakah user menghapus gambar di frontend? (Flag 'delete_media')
        if (isset($data['delete_media']) && $data['delete_media'] === 'true') {
            // Hapus file fisik lama jika ada
            if ($forumData['path_to_media']) {
                FileHelper::delete($forumData['path_to_media']);
            }
            $finalMediaPath = null; // Set database jadi NULL
        }

        // Cek B: Apakah user mengupload file BARU? (Replace)
        if (isset($_FILES['media']) && $_FILES['media']['error'] === 0) {
            // Hapus file lama fisik jika ada (dan belum dihapus di langkah A)
            if ($forumData['path_to_media'] && $finalMediaPath !== null) {
                FileHelper::delete($forumData['path_to_media']);
            }

            $uploadedPath = FileHelper::upload(
                $_FILES['media'],
                'uploads/forum_media', // Folder upload
                ['image/jpeg', 'image/png', 'image/jpg'],
                5 * 1024 * 1024 // 5MB
            );

            if (!$uploadedPath) {
                ResponseFormatter::error('Failed to upload media', 500);
                return;
            }

            $finalMediaPath = $uploadedPath;
        }

        // 3. Susun Data Update
        $dataForum = [
            'title' => strip_tags($data['title']), // Title tetap pakai strip_tags biasa
            'description' => $safeHtmlDescription, // Description pakai hasil HTMLPurifier
            'path_to_media' => $finalMediaPath,    // Hasil logika media di atas
        ];

        // 4. Lakukan Update Database
        $updateResult = $forumModel->update($dataForum, $id);

        if (!$updateResult) {
            ResponseFormatter::error('Failed to update forum', 500);
            return;
        }

        // 5. Kembalikan Data Terbaru (PENTING untuk Frontend Vue)
        // Agar Vue bisa langsung update UI tanpa reload halaman
        $updatedForum = $forumModel->findById($id, $_SESSION['user_id']); // Ambil data segar setelah update

        // Format Foto
        $config = require BASE_PATH . '/config/app.php';
        $updatedForum['profile_picture_url'] = $updatedForum['profile_picture']
            ? $config['storage_url'] . $updatedForum['profile_picture']
            : null;

        // 2. Format Media/Lampiran Forum (INI YANG BARU)
        $updatedForum['media_url'] = $updatedForum['path_to_media']
            ? $config['storage_url'] . $updatedForum['path_to_media']
            : null;

        ResponseFormatter::success($updatedForum, 'Forum updated successfully');
    }

    public function delete($id) {
        $forumModel = new Forum();
        $forumData = $forumModel->findById($id, $_SESSION['user_id']);

        if (!$forumData) {
            ResponseFormatter::error('Forum not found', 404);
        }

        $respondModel = new ForumRespond();
        $respondIds = $respondModel->getRespondIdsByForumId((int)$id);

        $reportModel = new Report();
        $reportModel->deleteByTarget('FORUM', (int)$id);

        if (!empty($respondIds)) {
            $reportModel->deleteByTargets('FORUM_RESPOND', $respondIds);
        }

        $deleteForum = $forumModel->delete($id);
        if (!$deleteForum) {
            ResponseFormatter::error('Failed to delete forum', 500);
        }
        if ($forumData['path_to_media']) {
            FileHelper::delete($forumData['path_to_media']);
        }
        ResponseFormatter::success(null, 'Forum deleted successfully');
    }
}