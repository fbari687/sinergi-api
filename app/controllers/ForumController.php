<?php

namespace app\controllers;

use app\helpers\FileHelper;
use app\helpers\ResponseFormatter;
use app\models\Community;
use app\models\CommunityMember;
use app\models\Forum;
require BASE_PATH . '/vendor/autoload.php';
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

        $config = HTMLPurifier_Config::createDefault();
        $purifier = new HTMLPurifier($config);
        $safeHtmlDescription = $purifier->purify($data['description']);

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
        $forumData = $forumModel->findById($id);
        if (!$forumData) {
            ResponseFormatter::error('Forum not found', 404);
        }

        $data = $_POST;
        if (!isset($data['title']) || !isset($data['description'])) {
            ResponseFormatter::error('Incomplete data', 400);
        }

        if (isset($_FILES['media']) && $_FILES['media']['error'] === 0) {
            if ($forumData['path_to_media']) {
                FileHelper::delete($forumData['path_to_media']);
            }

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

        $dataForum = [
            'title' => strip_tags($data['title']),
            'description' => $data['description'],
            'path_to_media' => $uploadedPath ?? $forumData['path_to_media'],
        ];

        $forum = $forumModel->update($dataForum, $id);
        if (!$forum) {
            ResponseFormatter::error('Failed to update forum', 500);
        }
        ResponseFormatter::success(null, 'Forum updated successfully');
    }

    public function delete($id) {
        $forumModel = new Forum();
        $forumData = $forumModel->findById($id);
        if (!$forumData) {
            ResponseFormatter::error('Forum not found', 404);
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