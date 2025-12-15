<?php

namespace app\controllers;

use app\helpers\FileHelper;
use app\models\Comment;
use app\models\Community;
use app\helpers\ResponseFormatter;
use app\models\CommunityMember;
use app\models\Forum;
use app\models\ForumRespond;
use app\models\Notification; // Import Model Notification

require BASE_PATH . '/vendor/autoload.php';

use app\models\Post;
use app\models\Report;
use app\models\User;
use HTMLPurifier;
use HTMLPurifier_Config;
use Exception;

class CommunityController
{

    public function index()
    {
        $communityModel = new Community();
        $communities = $communityModel->getAll();
        ResponseFormatter::Success($communities, 'Communities fetched successfully');
    }

    public function show($slug)
    {
        $communityModel = new Community();
        $community = $communityModel->findBySlug($slug, $_SESSION['user_id']);
        if (!$community) {
            ResponseFormatter::error('Community not found', 404);
        } else {

            $isExternal = $_SESSION['user']['role_name'] === 'Pakar' || $_SESSION['user']['role_name'] === 'Mitra' || $_SESSION['user']['role_name'] === 'Alumni';

            if ($isExternal) {
                $memberModel = new CommunityMember();
                $isMemberOrInvited = $memberModel->isUserMemberOrInvited($_SESSION['user_id'], $community['id']);
                if (!$isMemberOrInvited) {
                    ResponseFormatter::error('You are not a member of this community', 403);
                    return;
                }
            }

            $config = require BASE_PATH . '/config/app.php';
            $storageBaseUrl = $config['storage_url'];

            $formattedCommunity = $this->formattingCommunity($storageBaseUrl, [$community])[0];
            ResponseFormatter::Success($formattedCommunity, 'Community fetched successfully');
        }
    }

    public function store()
    {
        $data = $_POST;

        if (!isset($data['name']) || !isset($data['about']) || !isset($data['is_public'])) {
            ResponseFormatter::error('Incomplete data', 400);
        }

        if (!isset($_FILES['thumbnail']) || $_FILES['thumbnail']['error'] !== UPLOAD_ERR_OK) {
            ResponseFormatter::error('Thumbnail is required', 400);
        }

        $config = HTMLPurifier_Config::createDefault();
        $purifier = new HTMLPurifier($config);
        $safeHtmlAbout = $purifier->purify($data['about']);

        $uploadedPath = FileHelper::upload(
            $_FILES['thumbnail'],
            'uploads/community_thumbnails',
            ['image/jpeg', 'image/png', 'image/jpg'],
            5 * 1024 * 1024 // 5 mb
        );

        if (!$uploadedPath) {
            ResponseFormatter::error('Failed to upload thumbnail', 500);
        }

        $uniqueSlug = Community::generateUniqueSlug(strip_tags($data['name']));

        $communityModel = new Community();

        $dataCommunity = [
            'name' => strip_tags($data['name']),
            'slug' => $uniqueSlug,
            'invitation_link' => "http://sinergi-api.test/invite/" . $uniqueSlug,
            'about' => $safeHtmlAbout,
            'is_public' => strip_tags($data['is_public']),
            'path_to_thumbnail' => $uploadedPath
        ];

        $communityId = $communityModel->create($dataCommunity);

        if (!$communityId) {
            ResponseFormatter::error('Failed to create community', 500);
        } else {
            $memberModel = new CommunityMember();
            $userId = $_SESSION['user_id'];
            $memberModel->join($userId, $communityId, "OWNER", "GRANTED");

            ResponseFormatter::success(null, 'Community created successfully');
        }
    }

    public function update($slug)
    {
        $data = $_POST;

        $communityModel = new Community();
        $communityId = $communityModel->findIdBySlug($slug);

        if (!$communityId) {
            ResponseFormatter::error('Community not found', 404);
            return;
        }

        $communityData = $communityModel->findById($communityId);

        if (!isset($data['name']) || !isset($data['about']) || !isset($data['is_public'])) {
            ResponseFormatter::error('Incomplete data', 400);
            return;
        }

        // 1. Logic Slug: Generate hanya jika nama berubah
        $newSlug = $communityData['slug']; // Default pakai slug lama
        if ($data['name'] !== $communityData['name']) {
            $newSlug = Community::generateUniqueSlug(strip_tags($data['name']));
        }

        // 2. Logic About: Gunakan HTML Purifier agar format text tidak hilang
        $config = HTMLPurifier_Config::createDefault();
        $purifier = new HTMLPurifier($config);
        $safeHtmlAbout = $purifier->purify($data['about']);

        // 3. Logic Thumbnail
        $finalPath = $communityData['path_to_thumbnail']; // Default pakai path lama

        if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === 0) {
            // Hapus gambar lama fisik
            if ($communityData['path_to_thumbnail']) {
                FileHelper::delete($communityData['path_to_thumbnail']);
            }

            $uploadedPath = FileHelper::upload(
                $_FILES['thumbnail'],
                'uploads/community_thumbnails',
                ['image/jpeg', 'image/png', 'image/jpg'],
                5 * 1024 * 1024 // 5 mb
            );

            if (!$uploadedPath) {
                ResponseFormatter::error('Failed to upload thumbnail', 500);
                return;
            }
            $finalPath = $uploadedPath;
        }

        // 4. Update Invitation Link jika slug berubah
        $invitationLink = $communityData['invitation_link'];
        if ($newSlug !== $communityData['slug']) {
            $invitationLink = "http://sinergi-api.test/invite/" . $newSlug;
        }

        $dataCommunity = [
            'name' => strip_tags($data['name']),
            'slug' => $newSlug,
            'invitation_link' => $invitationLink,
            'about' => $safeHtmlAbout, // Menggunakan versi purified
            'is_public' => strip_tags($data['is_public']),
            'path_to_thumbnail' => $finalPath // Menggunakan variabel yang pasti terdefinisi
        ];

        // Pastikan Model Community punya method update yang menerima slug/id
        $communityUpdateStatus = $communityModel->update($slug, $dataCommunity);

        if (!$communityUpdateStatus) {
            ResponseFormatter::error('Failed to update community', 500);
        } else {
            // PENTING: Kembalikan data baru (terutama slug) agar frontend bisa redirect
            ResponseFormatter::success([
                'slug' => $newSlug,
                'name' => $dataCommunity['name']
            ], 'Community updated successfully');
        }
    }

    public function delete($slug)
    {
        $communityModel = new Community();
        $postModel = new Post();
        $commentModel = new Comment();
        $forumModel = new Forum();
        $respondModel = new ForumRespond();
        $reportModel = new Report();

        // 1. Ambil community ID
        $communityId = $communityModel->findIdBySlug($slug);
        if (!$communityId) {
            ResponseFormatter::error('Community not found', 404);
        }

        $communityData = $communityModel->findById($communityId);

        // === CLEANUP REPORT (HIERARKIS) ===

        // 2. Ambil semua POST ID di community
        $postIds = $postModel->getPostIdsByCommunityId($communityId);

        // 3. Dari post, ambil semua COMMENT ID
        $commentIds = [];
        if (!empty($postIds)) {
            foreach ($postIds as $postId) {
                $ids = $commentModel->getCommentIdsByPostId($postId);
                $commentIds = array_merge($commentIds, $ids);
            }
        }

        // 4. Ambil semua FORUM ID di community
        $forumIds = $forumModel->getForumIdsByCommunityId($communityId);

        // 5. Dari forum, ambil semua FORUM RESPOND ID
        $forumRespondIds = [];
        if (!empty($forumIds)) {
            foreach ($forumIds as $forumId) {
                $ids = $respondModel->getRespondIdsByForumId($forumId);
                $forumRespondIds = array_merge($forumRespondIds, $ids);
            }
        }

        // 6. Hapus REPORT berdasarkan hierarchy
        $reportModel->deleteByTarget('COMMUNITY', $communityId);

        if (!empty($postIds)) {
            $reportModel->deleteByTargets('POST', $postIds);
        }

        if (!empty($commentIds)) {
            $reportModel->deleteByTargets('COMMENT', $commentIds);
        }

        if (!empty($forumIds)) {
            $reportModel->deleteByTargets('FORUM', $forumIds);
        }

        if (!empty($forumRespondIds)) {
            $reportModel->deleteByTargets('FORUM_RESPOND', $forumRespondIds);
        }

        // === DELETE COMMUNITY (CASCADE) ===
        $deleted = $communityModel->delete($slug);
        if (!$deleted) {
            ResponseFormatter::error('Failed to delete community', 500);
        }

        // === DELETE THUMBNAIL ===
        if (!empty($communityData['path_to_thumbnail'])) {
            FileHelper::delete($communityData['path_to_thumbnail']);
        }

        ResponseFormatter::success(null, 'Community deleted successfully');
    }


    public function join($slug)
    {
        $communityModel = new Community();
        $communityData = $communityModel->checkSlugExists($slug);
        if (!$communityData) {
            ResponseFormatter::error('Community not found', 404);
        }
        $communityId = $communityModel->findIdBySlug($slug);
        $communityData = $communityModel->findById($communityId);
        $userId = $_SESSION['user_id'];
        $memberModel = new CommunityMember();

        if ($communityData['is_public'] === false) {
            $result = $memberModel->join($userId, $communityId, "MEMBER", "REQUEST");
            if (!$result) {
                ResponseFormatter::error('Failed to send request join', 500);
            }
            // Opsional: Kirim notifikasi ke Admin Komunitas bahwa ada request baru
            // (Tidak diimplementasikan di sini agar tidak spam admin, tapi bisa ditambahkan)

            ResponseFormatter::success(null, 'Community Request join sent successfully');
        } else {
            $result = $memberModel->join($userId, $communityId, "MEMBER", "GRANTED");
            if (!$result) {
                ResponseFormatter::error('Failed to join community', 500);
            }
            ResponseFormatter::success(null, 'Community Joined successfully');
        }

    }


    public function leave($slug) {
        $communityModel = new Community();
        $communityId = $communityModel->findIdBySlug($slug);

        if (!$communityId) ResponseFormatter::error('Community not found', 404);

        $userId = $_SESSION['user_id'];
        $memberModel = new CommunityMember();

        // Cek Role User saat ini
        $membership = $memberModel->findRoleUserById($userId, $communityId);

        // LOGIKA PENJAGAAN OWNER
        if ($membership && $membership['role'] === 'OWNER') {
            // Hitung total member (Admin + Member + Owner)
            $totalMembers = $memberModel->countMembersByRole($slug, ['MEMBER']) +
                $memberModel->countMembersByRole($slug, ['ADMIN']) + 1;

            // Jika masih ada anggota lain, Owner TIDAK BOLEH keluar begitu saja
            if ($totalMembers > 1) {
                ResponseFormatter::error('Owner cannot leave without transferring ownership first.', 403);
                return;
            }
            // Jika sendirian, biarkan lanjut (artinya komunitas akan kosong/bubar)
            if ($communityModel->delete($slug)) {
                ResponseFormatter::success(null, 'Community deleted successfully as you were the last member.');
                return;
            } else {
                ResponseFormatter::error('Failed to delete community', 500);
                return;
            }
        }

        $result = $memberModel->leave($userId, $communityId);

        if ($result) {
            ResponseFormatter::success(null, 'Left community successfully');
        } else {
            ResponseFormatter::error('Failed to leave', 500);
        }
    }

    public function transferOwnership($slug) {
        $data = $_POST;
        if (!isset($data['new_owner_id'])) ResponseFormatter::error('New Owner ID required', 400);

        $newOwnerId = $data['new_owner_id'];
        $oldOwnerId = $_SESSION['user_id'];

        $communityModel = new Community();
        $communityId = $communityModel->findIdBySlug($slug);
        $communityData = $communityModel->findById($communityId); // Ambil data untuk nama komunitas

        $memberModel = new CommunityMember();

        // 1. Validasi: Pastikan yang request adalah OWNER
        $currentOwnerData = $memberModel->findRoleUserById($oldOwnerId, $communityId);
        if ($currentOwnerData['role'] !== 'OWNER') {
            ResponseFormatter::error('Only the Owner can transfer ownership', 403);
        }

        // 2. Validasi: Calon Owner harus member komunitas ini
        $newOwnerData = $memberModel->findRoleUserById($newOwnerId, $communityId);
        if (!$newOwnerData) {
            ResponseFormatter::error('Candidate is not a member of this community', 404);
        }

        // 3. PROSES TRANSFER
        // A. Owner Lama -> ADMIN (Turun pangkat)
        $memberModel->updateRole($oldOwnerId, $communityId, 'ADMIN');

        // B. Calon Owner -> OWNER (Naik pangkat)
        $memberModel->updateRole($newOwnerId, $communityId, 'OWNER');

        // --- NOTIFIKASI TRANSFER KEPEMILIKAN ---
        $notificationModel = new Notification();
        $oldOwnerUsername = $_SESSION['user']['username'];
        $message = "<b>@{$oldOwnerUsername}</b> telah mentransfer kepemilikan komunitas <b>{$communityData['name']}</b> kepada Anda.";
        $link = "/communities/" . $slug;

        $notificationModel->create(
            $newOwnerId,            // Recipient (Owner Baru)
            'COMMUNITY_ROLE_CHANGE',
            $message,
            $link,
            $oldOwnerId,            // Actor (Owner Lama)
            $communityId
        );

        ResponseFormatter::success(null, 'Ownership transferred successfully');
    }

    // ... (getCommunityJoinedByUserId, search, getRecommendedCommunities, getMembers TETAP SAMA) ...
    public function getCommunityJoinedByUserId()
    {
        $config = require BASE_PATH . '/config/app.php';

        $storageBaseUrl = $config['storage_url'];

        $userId = $_SESSION['user_id'];
        $communityMemberModel = new CommunityMember();
        $communities = $communityMemberModel->getCommunitiesByUserId($userId);

        $formattedCommunities = array_map(function ($community) use ($storageBaseUrl) {
            if ($community['path_to_thumbnail']) {
                $community['thumbnail_url'] = $storageBaseUrl . $community['path_to_thumbnail'];
            } else {
                $community['thumbnail_url'] = null;
            }
            unset($community['path_to_thumbnail']);

            return $community;
        }, $communities);

        ResponseFormatter::success($formattedCommunities, 'Communities joined by user fetched successfully');
    }

    public function search()
    {
        // 1. Ambil keyword dari query param
        $keyword = $_GET['q'] ?? '';

        // Jika keyword kosong, kembalikan array kosong (hemat resource DB)
        if (trim($keyword) === '') {
            ResponseFormatter::success([], 'Empty search query');
            return;
        }

        $communityModel = new Community();

        // 2. Panggil model searchByName (yang sudah kita buat sebelumnya)
        // Kita butuh session user_id untuk cek status 'is_joined'
        $communities = $communityModel->searchByName($_SESSION['user_id'], $keyword);

        // 3. Format Data (Thumbnail URL & Type Casting)
        $config = require BASE_PATH . '/config/app.php';
        $storageBaseUrl = $config['storage_url'];

        $formattedCommunities = array_map(function ($community) use ($storageBaseUrl) {
            // Ubah path thumbnail jadi URL lengkap
            if ($community['path_to_thumbnail']) {
                $community['thumbnail_url'] = $storageBaseUrl . $community['path_to_thumbnail'];
            } else {
                $community['thumbnail_url'] = null;
            }
            unset($community['path_to_thumbnail']); // Hapus raw path

            // Casting tipe data agar sesuai dengan frontend (Boolean/Int)
            $community['is_public'] = (bool)$community['is_public'];
            $community['is_joined'] = (bool)$community['is_joined'];
            $community['total_members'] = (int)$community['total_members'];

            return $community;
        }, $communities);

        ResponseFormatter::success($formattedCommunities, 'Search results fetched successfully');
    }

    public function getRecommendedCommunities()
    {
        $config = require BASE_PATH . '/config/app.php';

        $storageBaseUrl = $config['storage_url'];
        $communityModel = new Community();
        $communities = $communityModel->getRecommendedCommunities($_SESSION['user_id']);
        $formattedCommunities = array_map(function ($community) use ($storageBaseUrl) {
            if ($community['path_to_thumbnail']) {
                $community['thumbnail_url'] = $storageBaseUrl . $community['path_to_thumbnail'];
            } else {
                $community['thumbnail_url'] = null;
            }
            unset($community['path_to_thumbnail']);

            return $community;
        }, $communities);
        ResponseFormatter::success($formattedCommunities, 'Recommended communities fetched successfully');
    }

    public function getAllCommunities()
    {
        // 1. Ambil Parameter
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        $keyword = $_GET['q'] ?? '';
        $sort = $_GET['sort'] ?? 'newest'; // newest, oldest, most_members, least_members

        if ($page < 1) $page = 1;
        $offset = ($page - 1) * $limit;

        $communityModel = new Community();

        // 2. Ambil Data & Total Count
        $communities = $communityModel->getAllWithPagination($keyword, $sort, $limit, $offset);
        $totalItems = $communityModel->countAll($keyword);
        $totalPages = ceil($totalItems / $limit);

        // 3. Format Data (URL Thumbnail)
        $config = require BASE_PATH . '/config/app.php';
        $storageBaseUrl = $config['storage_url'];

        $formattedCommunities = array_map(function ($community) use ($storageBaseUrl) {
            if ($community['path_to_thumbnail']) {
                $community['thumbnail_url'] = $storageBaseUrl . $community['path_to_thumbnail'];
            } else {
                $community['thumbnail_url'] = null;
            }
            unset($community['path_to_thumbnail']);

            // Casting
            $community['total_members'] = (int)$community['total_members'];
            $community['is_public'] = (bool)$community['is_public'];

            return $community;
        }, $communities);

        // 4. Return Response dengan Meta Pagination
        $response = [
            'data' => $formattedCommunities,
            'meta' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total_items' => $totalItems,
                'total_pages' => $totalPages
            ]
        ];

        ResponseFormatter::success($response, 'Communities fetched successfully');
    }

    public function getMembers($slug)
    {
        try {
            // 1. Load Configuration untuk Storage URL
            // Pastikan BASE_PATH sudah didefinisikan di entry point aplikasi Anda (index.php)
            $config = require BASE_PATH . '/config/app.php';
            $storageBaseUrl = $config['storage_url'];

            // 2. Ambil input
            $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
            $search = filter_input(INPUT_GET, 'q', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: "";

            $limit = 10;
            $offset = ($page - 1) * $limit;

            $communityMemberModel = new CommunityMember();

            // 3. Fetch Data Mentah dari Model
            $rawAdmins = [];
            if ($page === 1) {
                $rawAdmins = $communityMemberModel->getAdminsBySlug($slug, $search);
            }
            $rawMembers = $communityMemberModel->getMembersBySlug($slug, $search, $limit, $offset);

            // 4. FORMAT DATA (Ubah path relative jadi Full URL)
            // Kita panggil fungsi helper private di bawah
            $formattedAdmins = $this->formatMemberData($rawAdmins, $storageBaseUrl);
            $formattedMembers = $this->formatMemberData($rawMembers, $storageBaseUrl);

            // 5. Ambil Statistik
            $totalAdmins = $communityMemberModel->countMembersByRole($slug, ['ADMIN', 'OWNER']);
            $totalMembers = $communityMemberModel->countMembersByRole($slug, ['MEMBER']);

            // 6. Susun Response
            $resultData = [
                'admins' => $formattedAdmins,
                'members' => $formattedMembers,
                'stats' => [
                    'current_page' => $page,
                    'limit' => $limit,
                    'total_admins' => $totalAdmins,
                    'total_members' => $totalMembers,
                    'has_more' => count($formattedMembers) >= $limit
                ]
            ];

            ResponseFormatter::success($resultData, "Daftar anggota berhasil diambil");

        } catch (Exception $e) {
            ResponseFormatter::error("Terjadi kesalahan server: " . $e->getMessage(), 500);
        }
    }


    public function kickMember($slug) {
        $data = $_POST;
        if (!isset($data['user_id'])) {
            ResponseFormatter::error('User ID is required', 400);
        }
        $targetUserId = $data['user_id'];
        $currentUserId = $_SESSION['user_id'];

        $communityModel = new Community();
        $communityId = $communityModel->findIdBySlug($slug);
        $communityData = $communityModel->findById($communityId);

        if (!$communityId) {
            ResponseFormatter::error('Community not found', 404);
        }

        $memberModel = new CommunityMember();
        $actorRole = $memberModel->findRoleUserById($currentUserId, $communityId)['role'];
        $targetData = $memberModel->findRoleUserById($targetUserId, $communityId);

        if (!$targetData) {
            ResponseFormatter::error('Member not found', 404);
        }
        $targetRole = $targetData['role'];

        if ($targetUserId == $currentUserId) {
            ResponseFormatter::error('You cannot kick yourself', 403);
        }

        if ($actorRole === 'ADMIN' && ($targetRole === 'OWNER' || $targetRole === 'ADMIN')) {
            ResponseFormatter::error('Admins can only kick regular members', 403);
        }

        $result = $memberModel->leave($targetUserId, $communityId);

        if ($result) {
            // --- NOTIFIKASI KICK ---
            $notificationModel = new Notification();
            $actorUsername = $_SESSION['user']['username'];
            $message = "Anda telah dikeluarkan dari komunitas <b>{$communityData['name']}</b> oleh <b>@{$actorUsername}</b>.";

            // Link bisa ke halaman komunitas (nanti user akan lihat tombol Join lagi)
            $link = "/communities/" . $slug;

            $notificationModel->create(
                $targetUserId,          // Recipient (Yang di-kick)
                'COMMUNITY_KICK',       // Type
                $message,
                $link,
                $currentUserId,         // Actor (Admin)
                $communityId
            );

            ResponseFormatter::success(null, 'Member has been kicked successfully');
        } else {
            ResponseFormatter::error('Failed to kick member', 500);
        }
    }

    public function changeMemberRole($slug) {
        $data = $_POST;

        if (!isset($data['user_id'])) ResponseFormatter::error('User ID is required', 400);
        if (!isset($data['role']) || !in_array($data['role'], ['ADMIN', 'MEMBER'])) {
            ResponseFormatter::error('Invalid role. Allowed: ADMIN, MEMBER', 400);
        }

        $targetUserId = $data['user_id'];
        $newRole = $data['role'];

        $communityModel = new Community();
        $communityId = $communityModel->findIdBySlug($slug);
        $communityData = $communityModel->findById($communityId);

        $memberModel = new CommunityMember();
        $targetData = $memberModel->findRoleUserById($targetUserId, $communityId);

        if (!$targetData) ResponseFormatter::error('Member not found', 404);
        if ($targetData['role'] === 'OWNER') ResponseFormatter::error('Cannot change role of the Owner', 403);

        $result = $memberModel->updateRole($targetUserId, $communityId, $newRole);

        if ($result) {
            // --- NOTIFIKASI PERUBAHAN ROLE ---
            $notificationModel = new Notification();
            $actorUsername = $_SESSION['user']['username'];

            $roleLabel = $newRole === 'ADMIN' ? 'Admin' : 'Anggota';
            $message = "Peran Anda di komunitas <b>{$communityData['name']}</b> telah diubah menjadi <b>{$roleLabel}</b>.";
            $link = "/communities/" . $slug;

            $notificationModel->create(
                $targetUserId,          // Recipient
                'COMMUNITY_ROLE_CHANGE',
                $message,
                $link,
                $_SESSION['user_id'],   // Actor
                $communityId
            );

            ResponseFormatter::success(null, "Member role updated to $newRole");
        } else {
            ResponseFormatter::error('Failed to update role', 500);
        }
    }

    // GET: Ambil daftar request
    public function getJoinRequests($slug) {
        $memberModel = new CommunityMember();
        $requests = $memberModel->getPendingRequests($slug);

        $config = require BASE_PATH . '/config/app.php';
        $formattedRequests = $this->formatMemberData($requests, $config['storage_url']);

        ResponseFormatter::success($formattedRequests, 'Join requests fetched successfully');
    }

    // POST: Terima Request
    public function approveRequest($slug) {
        $data = $_POST;
        if (!isset($data['user_id'])) ResponseFormatter::error('User ID required', 400);

        $communityModel = new Community();
        $communityId = $communityModel->findIdBySlug($slug);
        // Ambil data komunitas untuk nama
        $communityData = $communityModel->findById($communityId);

        $memberModel = new CommunityMember();
        // Ubah status jadi GRANTED
        $result = $memberModel->updateStatus($data['user_id'], $communityId, 'GRANTED');

        if ($result) {
            // --- NOTIFIKASI APPROVE REQUEST (PENTING: Type ini memicu Logo Komunitas) ---
            $notificationModel = new Notification();
            $communityName = $communityData['name'];

            $message = "Permintaan bergabung ke komunitas <b>{$communityName}</b> telah disetujui.";
            $link = "/communities/" . $slug;

            $notificationModel->create(
                $data['user_id'],           // Recipient (User yang request)
                'COMMUNITY_JOIN_APPROVED',  // Type Khusus (Cek NotificationController)
                $message,
                $link,
                $_SESSION['user_id'],       // Actor (Admin yang approve)
                $communityId                // Reference ID (Komunitas)
            );

            ResponseFormatter::success(null, 'Member approved');
        } else {
            ResponseFormatter::error('Failed to approve', 500);
        }
    }

    // POST: Tolak Request
    public function declineRequest($slug) {
        $data = $_POST;
        if (!isset($data['user_id'])) ResponseFormatter::error('User ID required', 400);

        $communityModel = new Community();
        $communityId = $communityModel->findIdBySlug($slug);

        $memberModel = new CommunityMember();
        // Hapus data dari tabel (Reject = Hapus request)
        $result = $memberModel->leave($data['user_id'], $communityId);

        if ($result) {
            // Opsional: Notifikasi Penolakan (Seringkali tidak perlu agar tidak spam/sakit hati)
            ResponseFormatter::success(null, 'Request declined');
        } else {
            ResponseFormatter::error('Failed to decline', 500);
        }
    }

    public function searchCandidates($slug)
    {
        // 1. Ambil keyword
        $keyword = $_GET['q'] ?? '';

        if (trim($keyword) === '') {
            ResponseFormatter::success([], 'Empty keyword');
            return;
        }

        $communityModel = new Community();
        $communityId = $communityModel->findIdBySlug($slug);

        if (!$communityId) {
            ResponseFormatter::error('Community not found', 404);
            return;
        }

        // 2. Panggil Model User (REFACTOR)
        // Logika query dipindah ke User Model agar Controller bersih
        $userModel = new User();
        $users = $userModel->searchCandidatesForCommunity($keyword, $communityId);

        // 3. Format URL Foto Profil (Logic formatting view tetap di controller/helper)
        $config = require BASE_PATH . '/config/app.php';
        $storageBaseUrl = $config['storage_url'];

        $formattedUsers = array_map(function($user) use ($storageBaseUrl) {
            if ($user['path_to_profile_picture']) {
                $user['profile_picture'] = $storageBaseUrl . $user['path_to_profile_picture'];
            } else {
                $user['profile_picture'] = null;
            }
            unset($user['path_to_profile_picture']);
            return $user;
        }, $users);

        ResponseFormatter::success($formattedUsers, 'Candidates fetched successfully');
    }

    public function inviteMember($slug)
    {
        $data = $_POST;
        if (!isset($data['user_id'])) {
            ResponseFormatter::error('User ID required', 400);
            return;
        }

        $targetUserId = $data['user_id'];
        $actorId = $_SESSION['user_id'];

        $communityModel = new Community();
        $communityId = $communityModel->findIdBySlug($slug);
        $communityData = $communityModel->findById($communityId);

        // Cek Otoritas Actor
        $memberModel = new CommunityMember();
        $actorRole = $memberModel->findRoleUserById($actorId, $communityId);

        if (!$actorRole || !in_array($actorRole['role'], ['ADMIN', 'OWNER'])) {
            ResponseFormatter::error('Unauthorized', 403);
            return;
        }

        // Cek apakah target sudah member
        $isMember = $memberModel->findRoleUserById($targetUserId, $communityId);
        if ($isMember) {
            ResponseFormatter::error('User is already a member or has pending request', 409);
            return;
        }

        // [UBAH DISINI] Status awal adalah 'INVITED'
        // Pastikan kolom ENUM di database Anda mendukung 'INVITED'
        $join = $memberModel->join($targetUserId, $communityId, 'MEMBER', 'INVITED');

        if ($join) {
            // Notifikasi Undangan
            $notificationModel = new Notification();
            $actorUsername = $_SESSION['user']['username'];
            $communityName = $communityData['name'];

            $message = "Anda diundang untuk bergabung ke komunitas <b>{$communityName}</b> oleh <b>@{$actorUsername}</b>.";
            $link = "/communities/" . $slug; // Link ke halaman komunitas untuk terima/tolak

            $notificationModel->create(
                $targetUserId,
                'COMMUNITY_INVITE',
                $message,
                $link,
                $actorId,
                $communityId
            );

            ResponseFormatter::success(null, 'Invitation sent successfully');
        } else {
            ResponseFormatter::error('Failed to send invitation', 500);
        }
    }

    // [BARU] Terima Undangan
    public function acceptInvitation($slug)
    {
        $userId = $_SESSION['user_id'];
        $communityModel = new Community();
        $communityId = $communityModel->findIdBySlug($slug);

        $memberModel = new CommunityMember();
        $membership = $memberModel->findRoleUserById($userId, $communityId);

        // Validasi: Harus status INVITED
        if (!$membership || $membership['status'] !== 'INVITED') {
            ResponseFormatter::error('No pending invitation found', 404);
            return;
        }

        // Ubah status jadi GRANTED
        $result = $memberModel->updateStatus($userId, $communityId, 'GRANTED');

        if ($result) {
            ResponseFormatter::success(null, 'Invitation accepted. You are now a member.');
        } else {
            ResponseFormatter::error('Failed to accept invitation', 500);
        }
    }

    // [BARU] Tolak Undangan
    public function declineInvitation($slug)
    {
        $userId = $_SESSION['user_id'];
        $communityModel = new Community();
        $communityId = $communityModel->findIdBySlug($slug);

        $memberModel = new CommunityMember();
        $membership = $memberModel->findRoleUserById($userId, $communityId);

        if (!$membership || $membership['status'] !== 'INVITED') {
            ResponseFormatter::error('No pending invitation found', 404);
            return;
        }

        // Hapus data (Leave)
        $result = $memberModel->leave($userId, $communityId);

        if ($result) {
            ResponseFormatter::success(null, 'Invitation declined.');
        } else {
            ResponseFormatter::error('Failed to decline invitation', 500);
        }
    }

    public function requestExternalUser($slug)
    {
        $data = $_POST;
        $requesterId = $_SESSION['user_id'];

        // 1. Validasi Input Dasar
        if (!isset($data['email']) || !isset($data['fullname']) || !isset($data['username']) || !isset($data['role'])) {
            ResponseFormatter::error('Data incomplete', 400);
            return;
        }

        $validRoles = ['Alumni', 'Mitra', 'Pakar'];
        if (!in_array($data['role'], $validRoles)) {
            ResponseFormatter::error('Invalid role type', 400);
            return;
        }

        // 2. Cek Otoritas (Hanya Owner/Admin komunitas yang boleh)
        $communityModel = new Community();
        $communityId = $communityModel->findIdBySlug($slug);

        $memberModel = new CommunityMember();
        $membership = $memberModel->findRoleUserById($requesterId, $communityId);

        if (!$membership || !in_array($membership['role'], ['OWNER', 'ADMIN'])) {
            ResponseFormatter::error('Unauthorized', 403);
            return;
        }

        // 3. Cek Duplikasi Email
        // A. Cek di tabel User (sudah punya akun?)
        $userModel = new User();
        if ($userModel->findByEmail($data['email'])) {
            ResponseFormatter::error('Email ini sudah terdaftar sebagai pengguna Sinergi.', 409);
            return;
        }

        // B. Cek di tabel Request (sedang direquest?)
        $requestModel = new \app\models\AccountRequest();
        if ($requestModel->isEmailPending($data['email'])) {
            ResponseFormatter::error('Email ini sedang dalam proses pengajuan.', 409);
            return;
        }

        // 4. Siapkan Data Profile Spesifik (JSON)
        $profileData = [];
        if ($data['role'] === 'Alumni') {
            $profileData['tahun_lulus'] = $data['tahun_lulus'] ?? null;
            $profileData['pekerjaan_saat_ini'] = $data['pekerjaan_saat_ini'] ?? null;
            $profileData['nama_perusahaan'] = $data['nama_perusahaan'] ?? null;
        } elseif ($data['role'] === 'Mitra') {
            $profileData['nama_perusahaan'] = $data['nama_perusahaan'] ?? null;
            $profileData['jabatan'] = $data['jabatan'] ?? null;
            $profileData['alamat_perusahaan'] = $data['alamat_perusahaan'] ?? null;
        } elseif ($data['role'] === 'Pakar') {
            $profileData['bidang_keahlian'] = $data['bidang_keahlian'] ?? null;
            $profileData['instansi_asal'] = $data['instansi_asal'] ?? null;
        }

        // 5. Simpan Request
        $requestData = [
            'email' => $data['email'],
            'username' => $data['username'],
            'fullname' => $data['fullname'],
            'role' => $data['role'],
            'profile_data' => $profileData
        ];

        if ($requestModel->create($requesterId, $communityId, $requestData)) {
            ResponseFormatter::success(null, 'Permintaan pembuatan akun terkirim. Menunggu persetujuan Admin Sinergi.');
        } else {
            ResponseFormatter::error('Gagal membuat permintaan.', 500);
        }
    }

    public function getDashboardMetrics($slug)
    {
        // 1. Ambil Parameter Period dari Query String
        $period = $_GET['period'] ?? '7_days'; // 7_days, 30_days, this_month

        // 2. Tentukan Rentang Tanggal (Format PostgreSQL Timestamp)
        $endDate = date('Y-m-d 23:59:59');
        $startDate = date('Y-m-d 00:00:00', strtotime('-6 days')); // Default

        // Rentang waktu periode sebelumnya (untuk menghitung % perubahan)
        $prevEndDate = date('Y-m-d 23:59:59', strtotime('-7 days'));
        $prevStartDate = date('Y-m-d 00:00:00', strtotime('-13 days'));

        if ($period === '30_days') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-29 days'));

            $prevEndDate = date('Y-m-d 23:59:59', strtotime('-30 days'));
            $prevStartDate = date('Y-m-d 00:00:00', strtotime('-59 days'));
        } elseif ($period === 'this_month') {
            $startDate = date('Y-m-01 00:00:00');

            // Previous month calculation
            $prevStartDate = date('Y-m-01 00:00:00', strtotime('first day of last month'));
            $prevEndDate = date('Y-m-t 23:59:59', strtotime('last day of last month'));
        }

        // 3. Validasi Komunitas
        $communityModel = new Community();
        // Asumsi model punya method findBySlug
        $communityId = $communityModel->findIdBySlug($slug);

        if (!$communityId) {
            ResponseFormatter::error(null, 'Community not found', 404);
            return;
        }

        // 4. Panggil Logic Data Dashboard
        try {
            $dashboardData = $communityModel->getDashboardData(
                $communityId,
                $startDate,
                $endDate,
                $prevStartDate,
                $prevEndDate
            );

            ResponseFormatter::success($dashboardData, 'Dashboard data fetched successfully');
        } catch (Exception $e) {
            ResponseFormatter::error(null, $e->getMessage(), 500);
        }
    }

    public function getLeaderboard($slug)
    {
        // 1. Ambil Parameter Period
        $period = $_GET['period'] ?? 'this_month'; // Default bulan ini agar leaderboard relevan

        // 2. Tentukan Rentang Tanggal
        $endDate = date('Y-m-d 23:59:59');
        $startDate = date('Y-m-d 00:00:00', strtotime('-6 days'));

        if ($period === '7_days') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-6 days'));
        } elseif ($period === '30_days') {
            $startDate = date('Y-m-d 00:00:00', strtotime('-29 days'));
        } elseif ($period === 'this_month') {
            $startDate = date('Y-m-01 00:00:00');
        } elseif ($period === 'all_time') {
            $startDate = date('2000-01-01 00:00:00'); // Tanggal jauh di masa lalu
        }

        // 3. Validasi Komunitas
        $communityModel = new Community();
        $communityId = $communityModel->findIdBySlug($slug);

        if (!$communityId) {
            ResponseFormatter::error(null, 'Community not found', 404);
            return;
        }

        $community = $communityModel->findById($communityId);

        // 4. Ambil Data
        try {
            $leaderboard = $communityModel->getLeaderboardData($communityId, $startDate, $endDate);

            $config = require BASE_PATH . '/config/app.php';
            $storageBaseUrl = $config['storage_url'];

            $formattedMemberLeaderBoard = $this->formatMemberData($leaderboard, $storageBaseUrl);

            ResponseFormatter::success([
                'community_name' => $community['name'],
                'period' => $period,
                'leaderboard' => $formattedMemberLeaderBoard
            ], 'Leaderboard fetched successfully');
        } catch (Exception $e) {
            ResponseFormatter::error(null, $e->getMessage(), 500);
        }
    }

    public function formattingCommunity($storageBaseUrl, $communities)
    {
        $formattedCommunities = array_map(function ($community) use ($storageBaseUrl) {
            if ($community['path_to_thumbnail']) {
                $community['thumbnail_url'] = $storageBaseUrl . $community['path_to_thumbnail'];
            } else {
                $community['thumbnail_url'] = null;
            }
            unset($community['path_to_thumbnail']);

            return $community;
        }, $communities);
        return $formattedCommunities;
    }

    private function formatMemberData($members, $storageBaseUrl) {
        return array_map(function($member) use ($storageBaseUrl) {
            if (!empty($member['path_to_profile_picture'])) {
                $member['profile_picture'] = $storageBaseUrl . $member['path_to_profile_picture'];
            } else {
                $member['profile_picture'] = null;
            }
            unset($member['path_to_profile_picture']);
            return $member;
        }, $members);
    }
}