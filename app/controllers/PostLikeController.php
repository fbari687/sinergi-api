<?php

namespace app\controllers;

use app\helpers\ResponseFormatter;
use app\models\Notification;
use app\models\Post;
use app\models\PostLike;

class PostLikeController
{

    public function indexByPost($postId) {
        $postLikeModel = new PostLike();
        $postLikes = $postLikeModel->findByPostId($postId);
        ResponseFormatter::success($postLikes, 'Post likes fetched successfully');;
    }

    public function indexByUser() {
        $postLikeModel = new PostLike();
        $postLikes = $postLikeModel->findByUserId($_SESSION['user_id']);
        ResponseFormatter::success($postLikes, 'Post likes fetched successfully');;
    }

    public function showByUserAndPost($postId) {
        $postLikeModel = new PostLike();
        $postLike = $postLikeModel->findByPostAndUserId($postId, $_SESSION['user_id']);
        ResponseFormatter::success($postLike, 'Post reaction fetched successfully');;
    }

    public function store($postId)
    {
        $postModel = new Post();
        $postData = $postModel->findById($postId);

        if (!$postData) {
            ResponseFormatter::error('Post not found', 404);
            return;
        }

        $postLikeModel = new PostLike();
        $postLike = $postLikeModel->findByPostAndUserId($postId, $_SESSION['user_id']);

        // 1. Jika belum di-like -> Like & Buat Notifikasi
        if (!$postLike) {
            $postLikeData = [
                'post_id' => $postId,
                'user_id' => $_SESSION['user_id'],
            ];

            $result = $postLikeModel->create($postLikeData);

            if (!$result) {
                ResponseFormatter::error('Failed to create post like', 500);
                return;
            }

            // --- NOTIFIKASI BARU ---
            // Jangan kirim notifikasi jika like postingan sendiri
            if ($postData['user_id'] != $_SESSION['user_id']) {
                $notificationModel = new Notification();

                // Ambil username dari session (sesuai kode lama)
                $actorUsername = $_SESSION['user']['username'];

                // Pesan HTML untuk bold username
                $message = "<b>@{$actorUsername}</b> menyukai postingan Anda.";

                // Link ke detail post
                $link = '/post/' . $postId;

                $notificationModel->create(
                    $postData['user_id'],   // User ID Penerima (Pemilik Post)
                    'POST_LIKE',            // Tipe
                    $message,               // Pesan
                    $link,                  // Link
                    $_SESSION['user_id'],   // Actor ID (Yang nge-like)
                    $postId                 // Reference ID (ID Post)
                );
            }

            ResponseFormatter::success(null, 'Post like created successfully');
        }

        // 2. Jika sudah di-like -> Unlike (Hapus)
        if ($postLike) {
            $result = $postLikeModel->delete($postLike['id']);

            if (!$result) {
                ResponseFormatter::error('Failed to delete post like', 500);
                return;
            }

            ResponseFormatter::success(null, 'Post like deleted successfully');
        }
    }
}