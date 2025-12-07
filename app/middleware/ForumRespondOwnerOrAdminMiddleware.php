<?php

namespace app\middleware;

use app\helpers\ResponseFormatter;
use app\models\ForumRespond;

class ForumRespondOwnerOrAdminMiddleware {
    public function handle() {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            ResponseFormatter::error('Unauthorized', 401);
        }

        $uriSegments = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));

        $key = array_search('responds', $uriSegments);
        if ($key !== false && isset($uriSegments[$key + 1])) {
            $forumRespondId = $uriSegments[$key + 1];
        }

        if (!$forumRespondId) {
            ResponseFormatter::error('Forbidden: Forum Respond Id not found in URL.', 403);
        }

        $forumRespondModel = new ForumRespond();
        $forumRespondData = $forumRespondModel->findById($forumRespondId);

        if (!$forumRespondData) {
            ResponseFormatter::error('Forbidden: Forum Respond not found.', 403);
        }

        if ($_SESSION['user']['role_name'] === 'Admin') {
            exit();
        } else {
            $forumRespondUserId = $forumRespondData['user_id'];

            if ($forumRespondUserId !== $userId) {
                ResponseFormatter::error('Forbidden: You are not the owner of this forum respond.', 403);
            }
        }
    }
}