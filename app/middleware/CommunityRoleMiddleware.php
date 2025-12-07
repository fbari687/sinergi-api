<?php

namespace app\middleware;

use app\helpers\ResponseFormatter;
use app\models\Community;
use app\models\CommunityMember;

class CommunityRoleMiddleware {
    public function handle(...$allowedRoles) {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            ResponseFormatter::error('Unauthorized', 401);
        }

        // Ambil slug dari url
        // Contoh urlnya : /api/communities/{slug}
        $uriSegments = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));

        $communitySlug = null;
        $key = array_search('communities', $uriSegments);
        if ($key !== false && isset($uriSegments[$key + 1])) {
            $communitySlug = $uriSegments[$key + 1];
        }

        if (!$communitySlug) {
            ResponseFormatter::error('Forbidden: Community Slug not found in URL.', 403);
        }

        $communityModel = new Community();
        $communityId = $communityModel->findIdBySlug($communitySlug);

        if (!$communityId) {
            ResponseFormatter::error('Forbidden: Community not found.', 403);
        }

        $memberModel = new CommunityMember();
        $membership = $memberModel->findRoleUserById($userId, $communityId);

        if (!$membership || !in_array($membership['role'], $allowedRoles)) {
            ResponseFormatter::error('Forbidden: You do not have required role in this community.', 403);
        }
    }
}