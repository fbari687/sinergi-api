<?php

namespace app\controllers;

use app\helpers\FileHelper;
use app\helpers\ResponseFormatter;
use app\models\Community;
use app\models\CommunityMember;
use app\models\Post;

require BASE_PATH . '/vendor/autoload.php';

use app\models\PostLike;
use HTMLPurifier;
use HTMLPurifier_Config;

class PostController {

    public function indexHome() {
        $config = require BASE_PATH . '/config/app.php';
        $storageBaseUrl = $config['storage_url'];

        // 1. Ambil page dari query string
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        if ($page < 1) $page = 1;

        // 2. Ambil parameter SEARCH (q) dari Frontend
        // Frontend mengirim: /api/posts?page=1&q=keyword
        $search = isset($_GET['search']) ? $_GET['search'] : null;

        $limit = 5;
        $offset = ($page - 1) * $limit;

        $postModel = new Post();

        // 3. Kirim parameter $search ke Model
        $posts = $postModel->getAllPostInHome($_SESSION['user_id'], $limit, $offset, $search);

        $formattedPosts = $this->formattingPost($storageBaseUrl, $posts);

        ResponseFormatter::Success($formattedPosts, 'Posts fetched successfully');
    }

    public function indexByCommunity($slug) {
        $communityModel = new Community();
        $communityId = $communityModel->findIdBySlug($slug);

        if (!$communityId) {
            ResponseFormatter::error('Community not found', 404);
        }

        $community = $communityModel->findById($communityId);

        $isPublic = $community['is_public'];
        $isMember = false;
        if (!$isPublic) {
            $memberModel = new CommunityMember();
            $membership = $memberModel->findRoleUserById($_SESSION['user_id'], $communityId);
            if ($membership && $membership['role']) {
                $isMember = true;
            }
        }

        if (!$isPublic && !$isMember) {
            ResponseFormatter::error('This community is private. Join to view posts.', 403);
        }

        $config = require BASE_PATH . '/config/app.php';
        $storageBaseUrl = $config['storage_url'];

        // --- UPDATE INFINITE SCROLL ---
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $search = isset($_GET['search']) ? $_GET['search'] : null; // 1. Tangkap parameter search

        if ($page < 1) $page = 1;

        $limit = 5;
        $offset = ($page - 1) * $limit;

        $postModel = new Post();

        // 2. Kirim parameter $search ke model
        $posts = $postModel->getAllPostInOneCommunity($communityId, $_SESSION['user_id'], $limit, $offset, $search);

        $formattedPosts = $this->formattingPost($storageBaseUrl, $posts);

        ResponseFormatter::success($formattedPosts, 'Posts fetched successfully');
    }

    public function storeHome() {
        $data = $_POST;

        if (!isset($data['description'])) {
            ResponseFormatter::error('Incomplete data', 400);
        }

        $config = HTMLPurifier_Config::createDefault();
        $purifier = new HTMLPurifier($config);
        $safeHtmlDescription = $purifier->purify($data['description']);

        if (isset($_FILES['media']) && $_FILES['media']['error'] === 0) {
            $uploadedPath = FileHelper::upload(
                $_FILES['media'],
                'uploads/post_media',
                ['image/jpeg', 'image/png', 'image/jpg'],
                5 * 1024 * 1024
            );

            if (!$uploadedPath) {
                ResponseFormatter::error('Failed to upload media', 500);
            }
        }

        $dataPost = [
            'description' => $safeHtmlDescription,
            'path_to_media' => $uploadedPath ?? null,
            'user_id' => $_SESSION['user_id'],
            'community_id' => null
        ];

        $postModel = new Post();
        $postId = $postModel->create($dataPost);

        if (!$postId) {
            ResponseFormatter::error('Failed to create post', 500);
        }
        ResponseFormatter::success(null, 'Post created successfully');
    }

    public function storeByCommunity($slug) {
        $data = $_POST;

        if (!isset($data['description'])) {
            ResponseFormatter::error('Incomplete data', 400);
        }

        $config = HTMLPurifier_Config::createDefault();
        $purifier = new HTMLPurifier($config);
        $safeHtmlDescription = $purifier->purify($data['description']);

        $communityModel = new Community();
        $communityId = $communityModel->findIdBySlug($slug);

        if (!$communityId) {
            ResponseFormatter::error('Community not found', 404);
        }

        if (isset($_FILES['media']) && $_FILES['media']['error'] === 0) {
            $uploadedPath = FileHelper::upload(
                $_FILES['media'],
                'uploads/post_media',
                ['image/jpeg', 'image/png', 'image/jpg'],
                5 * 1024 * 1024
            );

            if (!$uploadedPath) {
                ResponseFormatter::error('Failed to upload media', 500);
            }
        }

        $dataPost = [
            'description' => $safeHtmlDescription,
            'path_to_media' => $uploadedPath ?? null,
            'user_id' => $_SESSION['user_id'],
            'community_id' => $communityId
        ];

        $postModel = new Post();
        $postId = $postModel->create($dataPost);

        if (!$postId) {
            ResponseFormatter::error('Failed to create post', 500);
        }

        ResponseFormatter::success(null, 'Post created successfully');
    }

    public function show($id) {
        $postModel = new Post();
        $post = $postModel->getPostDetailById($id, $_SESSION['user_id']);

        if (!$post) {
            ResponseFormatter::error('Post not found', 404);
            return;
        }

        if ($post['community_id'] !== null) {
            $memberModel = new CommunityMember();
            $isMember = $memberModel->isUserMember($_SESSION['user_id'], $post['community_id']);

            $isAdmin = $_SESSION['user']['role_name'] === 'Admin';

            if (!$isMember && !$isAdmin) {
                ResponseFormatter::error('You are not a member of this community', 403);
                return;
            }
        } else {
            $isExternal = $_SESSION['user']['role_name'] === 'Pakar' || $_SESSION['user']['role_name'] === 'Mitra' || $_SESSION['user']['role_name'] === 'Alumni';

            if ($isExternal) {
                ResponseFormatter::error('You are not a internal member', 403);
                return;
            }
        }

        $config = require BASE_PATH . '/config/app.php';
        $storageBaseUrl = $config['storage_url'];

        $formattedPost = $this->formattingPost($storageBaseUrl, [$post])[0];
        ResponseFormatter::success($formattedPost, 'Post fetched successfully');
    }

    public function update($id) {
        $postModel = new Post();
        $postData = $postModel->findById($id);

        if (!$postData) {
            ResponseFormatter::error('Post not found', 404);
            return;
        }

        $data = $_POST;
        if (!isset($data['description'])) {
            ResponseFormatter::error('Incomplete data', 400);
            return;
        }

        $config = HTMLPurifier_Config::createDefault();
        $purifier = new HTMLPurifier($config);
        $safeHtmlDescription = $purifier->purify($data['description']);

        // --- LOGIKA MEDIA ---

        // Default: Gunakan path lama
        $finalMediaPath = $postData['path_to_media'];

        // 1. Cek apakah ada permintaan HAPUS gambar (Flag dari frontend)
        // Kita cek string 'true' karena FormData mengirim semuanya sebagai string
        if (isset($data['delete_media']) && $data['delete_media'] === 'true') {
            // Hapus file lama fisik jika ada
            if ($postData['path_to_media']) {
                FileHelper::delete($postData['path_to_media']);
            }
            $finalMediaPath = null; // Set jadi null
        }

        // 2. Cek apakah ada file BARU yang diupload (Replace)
        if (isset($_FILES['media']) && $_FILES['media']['error'] === 0) {
            // Hapus file lama fisik jika ada (dan belum dihapus di langkah 1)
            if ($postData['path_to_media'] && $finalMediaPath !== null) {
                FileHelper::delete($postData['path_to_media']);
            }

            $uploadedPath = FileHelper::upload(
                $_FILES['media'],
                'uploads/post_media',
                ['image/jpeg', 'image/png', 'image/jpg'],
                5 * 1024 * 1024
            );

            if (!$uploadedPath) {
                ResponseFormatter::error('Failed to upload media', 500);
                return;
            }

            $finalMediaPath = $uploadedPath;
        }

        $dataPost = [
            'description' => $safeHtmlDescription,
            'path_to_media' => $finalMediaPath, // Gunakan hasil logika di atas
        ];

        $updatePost = $postModel->update($dataPost, $id);

        if (!$updatePost) {
            ResponseFormatter::error('Failed to update post', 500);
            return;
        }

        ResponseFormatter::success(null, 'Post updated successfully');
    }

    public function delete($id) {
        $postModel = new Post();
        $postData = $postModel->findById($id);
        if (!$postData) {
            ResponseFormatter::error('Post not found', 404);
        }
        $deletePost = $postModel->delete($id);
        if (!$deletePost) {
            ResponseFormatter::error('Failed to delete post', 500);
        }
        if ($postData['path_to_media']) {
            FileHelper::delete($postData['path_to_media']);
        }
        ResponseFormatter::success(null, 'Post deleted successfully');
    }

    /**
     * @param mixed $storageBaseUrl
     * @param array $posts
     * @return array
     */
    public function formattingPost(mixed $storageBaseUrl, array $posts): array
    {
        $formattedPosts = array_map(function ($post) use ($storageBaseUrl) {
            $post['is_liked_by_user'] = (bool)$post['is_liked_by_user'];

            // Format Data User
            $post['user'] = [
                'id' => $post['user_id'],
                'fullname' => $post['fullname'],
                'username' => $post['username'],
                'profile_picture' => $storageBaseUrl . $post['path_to_profile_picture'],
                'role' => $post['role']
            ];

            // Format Data Community (Jika ada, biasanya dari Homepage)
            // Pastikan alias query di Model sudah sesuai (community_name, community_slug, dll)
            if (isset($post['community_name'])) {
                $post['community'] = [
                    'name' => $post['community_name'],
                    'slug' => $post['community_slug'],
                    'thumbnail' => isset($post['community_thumbnail']) ? $storageBaseUrl . $post['community_thumbnail'] : null
                ];
            } else {
                $post['community'] = null;
            }

            // Buat 'media_url'
            if ($post['path_to_media']) {
                $post['media_url'] = $storageBaseUrl . $post['path_to_media'];
            } else {
                $post['media_url'] = null;
            }

            // Bersihkan field flat agar response lebih rapi (nested)
            unset($post['fullname']);
            unset($post['username']);
            unset($post['path_to_profile_picture']);
            unset($post['role']);
            unset($post['user_id']);
            unset($post['path_to_media']);
//            unset($post['community_id']);

            // Unset data community flat jika ada
            if(isset($post['community_name'])) unset($post['community_name']);
            if(isset($post['community_slug'])) unset($post['community_slug']);
            if(isset($post['community_thumbnail'])) unset($post['community_thumbnail']);

            return $post;
        }, $posts);
        return $formattedPosts;
    }
}