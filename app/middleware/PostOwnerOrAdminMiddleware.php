<?php

namespace app\middleware;

use app\helpers\ResponseFormatter;
use app\models\Post;

class PostOwnerOrAdminMiddleware
{
    public function handle()
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            ResponseFormatter::error('Unauthorized', 401);
        }

        // Ambil slug dari url
        // Contoh urlnya : /api/communities/{slug}
        $uriSegments = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));

        $key = array_search('posts', $uriSegments);
        if ($key !== false && isset($uriSegments[$key + 1])) {
            $postId = $uriSegments[$key + 1];
        }

        if (!$postId) {
            ResponseFormatter::error('Forbidden: Post Id not found in URL.', 403);
        }

        $postModel = new Post();
        $postData = $postModel->findById($postId);

        if (!$postData) {
            ResponseFormatter::error('Forbidden: Post not found.', 403);
        }


        if ($_SESSION['user']['role_name'] === 'Admin') {
            return;
        } else {
            $postUserId = $postData['user_id'];

            if ($postUserId !== $userId) {
                ResponseFormatter::error('Forbidden: You are not the owner of this post.', 403);
            }
        }

    }
}