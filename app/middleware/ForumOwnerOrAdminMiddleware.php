<?php

namespace app\middleware;

use app\helpers\ResponseFormatter;
use app\models\Forum;

class ForumOwnerOrAdminMiddleware {
    public function handle() {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            ResponseFormatter::error('Unauthorized', 401);
        }

        $uriSegments = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));

        $key = array_search('forums', $uriSegments);
        if ($key !== false && isset($uriSegments[$key + 1])) {
            $forumId = $uriSegments[$key + 1];
        }

        if (!$forumId) {
            ResponseFormatter::error('Forbidden: Forum Id not found in URL.', 403);
        }

        $forumModel = new Forum();
        $forumData = $forumModel->findById($forumId);

        if (!$forumData) {
            ResponseFormatter::error('Forbidden: Forum not found.', 403);
        }

        if ($_SESSION['user']['role_name'] === 'Admin') {
            return;
        } else {
            $forumUserId = $forumData['user_id'];

            if ($forumUserId !== $userId) {
                ResponseFormatter::error('Forbidden: You are not the owner of this forum.', 403);
            }
        }
    }
}