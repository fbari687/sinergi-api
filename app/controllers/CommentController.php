<?php

namespace app\controllers;

use app\helpers\ResponseFormatter;
use app\models\Comment;
use app\models\Notification;
use app\models\Post;
use app\services\ModerationService;

class CommentController {

    public function index($postId) {
        $commentModel = new Comment();
        $comments = $commentModel->findPostComments($postId);

        $config = require BASE_PATH . '/config/app.php';
        $storageBaseUrl = $config['storage_url'];

        $formattedComments = array_map(function($comment) use ($storageBaseUrl) {
            $comment['user'] = [
                'id' => $comment['user_id'],
                'username' => $comment['username'],
                'profile_picture' => $storageBaseUrl . $comment['path_to_profile_picture'],
                'role' => $comment['role']
            ];

            unset($comment['user_id']);
            unset($comment['path_to_profile_picture']);
            unset($comment['role']);
            unset($comment['username']);

            return $comment;
        }, $comments);

        ResponseFormatter::Success($formattedComments, 'Comments fetched successfully');
    }

    public function getReplies($commentId) {
        $commentModel = new Comment();
        $comments = $commentModel->findCommentReplies($commentId);

        $config = require BASE_PATH . '/config/app.php';
        $storageBaseUrl = $config['storage_url'];

        $formattedComments = array_map(function($comment) use ($storageBaseUrl) {
            $comment['user'] = [
                'id' => $comment['user_id'],
                'username' => $comment['username'],
                'profile_picture' => $storageBaseUrl . $comment['path_to_profile_picture'],
                'role' => $comment['role']
            ];

            unset($comment['user_id']);
            unset($comment['path_to_profile_picture']);
            unset($comment['role']);
            unset($comment['username']);

            return $comment;
        }, $comments);

        ResponseFormatter::Success($formattedComments, 'Comments fetched successfully');
    }

    public function store($postId) {
        $data = $_POST;

        if (!isset($data['content'])) {
            ResponseFormatter::error('Incomplete data', 400);
            return;
        }

        $postModel = new Post();
        $postData = $postModel->findById($postId);

        if (!$postData) {
            ResponseFormatter::error('Post not found', 404);
            return;
        }

        $content = htmlspecialchars($data['content']);

        if (!empty($content)) {
            $moderation = new ModerationService();
            $result = $moderation->check($content);

            if ($result['flagged']) {
                ResponseFormatter::error(
                    'Konten Anda terindikasi melanggar kebijakan etika kampus. Silakan perbaiki dan coba kembali.',
                    422
                );
            }
        }

        $commentModel = new Comment();

        $dataComment = [
            'post_id' => $postId,
            'content' => $content,
            'user_id' => $_SESSION['user_id'],
            'parent_id' => null
        ];

        $comment = $commentModel->create($dataComment);

        if (!$comment) {
            ResponseFormatter::error('Failed to create comment', 500);
            return;
        }

        // --- NOTIFIKASI BARU (POST COMMENT) ---
        // Cek agar tidak notifikasi diri sendiri
        if ($postData['user_id'] != $_SESSION['user_id']) {
            $notificationModel = new Notification();

            $actorUsername = $_SESSION['user']['username'];

            // Format pesan HTML
            $message = "<b>@{$actorUsername}</b> mengomentari postingan Anda.";

            // Link ke postingan
            $link = '/post/' . $postId;

            $notificationModel->create(
                $postData['user_id'],       // Recipient (Pemilik Post)
                'POST_COMMENT',             // Type
                $message,                   // Message
                $link,                      // Link
                $_SESSION['user_id'],       // Actor
                $postId                     // Reference ID (Post ID)
            );
        }

        ResponseFormatter::success(null, 'Comment created successfully');
    }

    public function storeReply($commentId) {
        $data = $_POST;

        if (!isset($data['content'])) {
            ResponseFormatter::error('Incomplete data', 400);
            return;
        }

        $commentModel = new Comment();
        $commentData = $commentModel->findById($commentId);

        if (!$commentData) {
            ResponseFormatter::error('Comment not found', 404);
            return;
        }

        $postModel = new Post();
        $postData = $postModel->findById($commentData['post_id']);

        if (!$postData) {
            ResponseFormatter::error('Post not found', 404);
            return;
        }

        $content = htmlspecialchars($data['content']);
        if (!empty($content)) {
            $moderation = new ModerationService();
            $result = $moderation->check($content);

            if ($result['flagged']) {
                ResponseFormatter::error(
                    'Konten Anda terindikasi melanggar kebijakan etika kampus. Silakan perbaiki dan coba kembali.',
                    422
                );
            }
        }


        $dataComment = [
            'post_id' => $commentData['post_id'],
            'content' => strip_tags($data['content']),
            'user_id' => $_SESSION['user_id'],
            'parent_id' => $commentId
        ];

        $comment = $commentModel->create($dataComment);

        if (!$comment) {
            ResponseFormatter::error('Failed to create comment', 500);
            return;
        }

        $notificationModel = new Notification();
        $actorUsername = $_SESSION['user']['username'];
        $link = '/post/' . $postData['id'];

        // --- NOTIFIKASI 1: KE PEMILIK KOMENTAR YANG DIBALAS ---
        if ($commentData['user_id'] != $_SESSION['user_id']) {
            $messageReply = "<b>@{$actorUsername}</b> membalas komentar Anda.";

            $notificationModel->create(
                $commentData['user_id'],    // Recipient (Pemilik Komentar Lama)
                'COMMENT_REPLY',            // Type
                $messageReply,
                $link,
                $_SESSION['user_id'],
                $postData['id']             // Reference ke Post ID
            );
        }

        // --- NOTIFIKASI 2: KE PEMILIK POSTINGAN (Jika berbeda orang) ---
        // Logic: Kirim notif ke pemilik post HANYA JIKA:
        // 1. Pemilik post BUKAN user yang sedang login (self-action).
        // 2. Pemilik post BUKAN pemilik komentar yang baru saja dibalas (agar tidak dapat 2 notif sekaligus).
        if ($postData['user_id'] != $_SESSION['user_id'] && $postData['user_id'] != $commentData['user_id']) {
            $messagePost = "<b>@{$actorUsername}</b> mengomentari postingan Anda.";

            $notificationModel->create(
                $postData['user_id'],       // Recipient (Pemilik Post)
                'POST_COMMENT',             // Type
                $messagePost,
                $link,
                $_SESSION['user_id'],
                $postData['id']
            );
        }

        ResponseFormatter::success(null, 'Comment created successfully');
    }

    public function delete($commentId) {
        $commentModel = new Comment();
        $commentData = $commentModel->findById($commentId);
        if (!$commentData) {
            ResponseFormatter::error('Comment not found', 404);
        }
        $deleteComment = $commentModel->delete($commentId);
        if (!$deleteComment) {
            ResponseFormatter::error('Failed to delete comment', 500);
        }
        ResponseFormatter::success(null, 'Comment deleted successfully');
    }
}