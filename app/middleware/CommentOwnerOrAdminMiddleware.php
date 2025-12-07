<?php

namespace app\middleware;

use app\helpers\ResponseFormatter;
use app\models\Comment;

class CommentOwnerOrAdminMiddleware {
    public function handle() {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            ResponseFormatter::error('Unauthorized', 401);
        }

        // Ambil slug dari url
        // Contoh urlnya : /api/communities/{slug}
        $uriSegments = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));

        $key = array_search('comments', $uriSegments);
        if ($key !== false && isset($uriSegments[$key + 1])) {
            $commentId = $uriSegments[$key + 1];
        }

        if (!$commentId) {
            ResponseFormatter::error('Forbidden: Comment Id not found in URL.', 403);
        }

        $commentModel = new Comment();
        $commentData = $commentModel->findById($commentId);

        if (!$commentData) {
            ResponseFormatter::error('Forbidden: Comment not found.', 403);
        }

        if ($_SESSION['user']['role_name'] === 'Admin') {
            exit();
        } else {
            $commentUserId = $commentData['user_id'];

            if ($commentUserId !== $userId) {
                ResponseFormatter::error('Forbidden: You are not the owner of this comment.', 403);
            }
        }
    }
}