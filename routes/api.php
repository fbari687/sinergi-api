<?php

use app\core\Router;

function register_routes(Router $router)
{
    // Rute Autentikasi (tanpa middleware)
    $router->addRoute('POST', 'api/register/request', 'AuthController@requestOtp');
    $router->addRoute('POST', 'api/register/verify', 'AuthController@verifyOtp');
    $router->addRoute('GET', 'api/captcha', 'CaptchaController@generate');
    $router->addRoute('POST', 'api/login', 'AuthController@login');
    $router->addRoute('POST', 'api/logout', 'AuthController@logout',
        ['Authenticate']
    );
    $router->addRoute('GET', 'api/me', 'AuthController@me',
        ['Authenticate']
    );

    $router->addRoute('POST', 'api/forgot-password/request', 'AuthController@requestForgotPasswordOtp');
    $router->addRoute('POST', 'api/forgot-password/verify', 'AuthController@verifyForgotPasswordOtp');
    $router->addRoute('POST', 'api/forgot-password/reset', 'AuthController@resetPassword');

    // Rute User (dengan middleware 'Authenticate')
    $router->addRoute('GET', 'api/users', 'UserController@index',
        ['Authenticate', 'Role:Admin,Dosen']
    );
    $router->addRoute('GET', 'api/users/{id}', 'UserController@show',
        ['Authenticate']
    );
    $router->addRoute('POST', 'api/users/profile/update', 'UserController@updateProfile',
        ['Authenticate']
    );

    $router->addRoute('POST', 'api/profile/complete-data', 'ProfileController@store',
        ['Authenticate']
    );
    $router->addRoute('GET', 'api/profile/{username}', 'ProfileController@show',
        ['Authenticate']
    );

    // Rute Community
    $router->addRoute('POST', 'api/communities', 'CommunityController@store',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa']
    );
    $router->addRoute('GET', 'api/communities', 'CommunityController@index',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa']
    );
    $router->addRoute('GET', 'api/communities/all', 'CommunityController@getAllCommunities',
        ['Authenticate', 'Role:Admin']
    );
    $router->addRoute('GET', 'api/communities/recommended', 'CommunityController@getRecommendedCommunities',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa']
    );
    $router->addRoute('GET', 'api/communities/search', 'CommunityController@search',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa']
    );
    $router->addRoute('GET', 'api/communities/{slug}', 'CommunityController@show',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('GET', 'api/communities/{slug}/search-candidates', 'CommunityController@searchCandidates',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa']
    );
    $router->addRoute('POST', 'api/communities/{slug}/invite-external', 'CommunityController@requestExternalUser',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa'] // Sesuaikan middleware
    );
    $router->addRoute('POST', 'api/communities/{slug}/update', 'CommunityController@update',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa', 'CommunityRole:OWNER,ADMIN']
    );
    $router->addRoute('POST', 'api/communities/{slug}/delete', 'CommunityController@delete',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa', 'CommunityRole:OWNER']
    );
    $router->addRoute('GET', 'api/communities/{slug}/dashboard', 'CommunityController@getDashboardMetrics',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa', 'CommunityRole:OWNER,ADMIN']
    );
    $router->addRoute('GET', 'api/communities/{slug}/leaderboard', 'CommunityController@getLeaderboard',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa', 'CommunityRole:OWNER,ADMIN']
    );
    $router->addRoute('POST', 'api/join/communities/{slug}', 'CommunityController@join',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa']
    );
    $router->addRoute('POST', 'api/leave/communities/{slug}', 'CommunityController@leave',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar', 'CommunityRole:OWNER,ADMIN,MEMBER']
    );
    $router->addRoute('GET', 'api/joined/communities', 'CommunityController@getCommunityJoinedByUserId',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('GET', 'api/communities/{slug}/members', 'CommunityController@getMembers',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('POST', 'api/communities/{slug}/members/kick', 'CommunityController@kickMember',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar', 'CommunityRole:OWNER,ADMIN']
    );
    $router->addRoute('POST', 'api/communities/{slug}/members/role', 'CommunityController@changeMemberRole',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar', 'CommunityRole:OWNER']
    );
    // List Request (Hanya Owner/Admin)
    $router->addRoute('GET', 'api/communities/{slug}/requests', 'CommunityController@getJoinRequests',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar', 'CommunityRole:OWNER,ADMIN']
    );
    $router->addRoute('POST', 'api/communities/{slug}/transfer-ownership', 'CommunityController@transferOwnership',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar', 'CommunityRole:OWNER']
    );
    $router->addRoute('POST', 'api/communities/{slug}/invite-internal', 'CommunityController@inviteMember',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa']
    );
    $router->addRoute('POST', 'api/communities/{slug}/accept-invite', 'CommunityController@acceptInvitation',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('POST', 'api/communities/{slug}/decline-invite', 'CommunityController@declineInvitation',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    // Action Approve (Hanya Owner/Admin)
    $router->addRoute('POST', 'api/communities/{slug}/requests/approve', 'CommunityController@approveRequest',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar', 'CommunityRole:OWNER,ADMIN']
    );
    // Action Decline (Hanya Owner/Admin)
    $router->addRoute('POST', 'api/communities/{slug}/requests/decline', 'CommunityController@declineRequest',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar', 'CommunityRole:OWNER,ADMIN']
    );

    // Rute Post
    $router->addRoute('GET', 'api/posts/communities/{slug}', 'PostController@indexByCommunity',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('GET', 'api/posts', 'PostController@indexHome',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa']
    );
    $router->addRoute('POST', 'api/posts/communities/{slug}', 'PostController@storeByCommunity',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar', 'CommunityRole:OWNER,ADMIN,MEMBER']
    );
    $router->addRoute('POST', 'api/posts', 'PostController@storeHome',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa']
    );
    $router->addRoute('GET', 'api/posts/{id}', 'PostController@show',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('POST', 'api/posts/{id}/update', 'PostController@update',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar', 'PostOwnerOrAdmin']
    );
    $router->addRoute('POST', 'api/posts/{id}/delete', 'PostController@delete',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar', 'PostOwnerOrAdmin']
    );

    // Rute Comment
    $router->addRoute('GET', 'api/posts/{postId}/comments', 'CommentController@index',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('POST', 'api/posts/{postId}/comments', 'CommentController@store',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('POST', 'api/comments/{commentId}/replies', 'CommentController@storeReply',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('GET', 'api/comments/{commentId}/replies', 'CommentController@getReplies',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('POST', 'api/comments/{commentId}/delete', 'CommentController@delete',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar', 'CommentOwnerOrAdmin']
    );

    // Rute Post Like
    $router->addRoute('POST', 'api/likes/posts/{postId}', 'PostLikeController@store',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('GET', 'api/likes/posts/{postId}', 'PostLikeController@indexByPost',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('GET', 'api/mylike/posts/{postId}', 'PostLikeController@showByUserAndPost',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('GET', 'api/allmylike/posts', 'PostLikeController@indexByUser',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );

    // Rute Forum
    $router->addRoute('GET', 'api/communities/{slug}/forums', 'ForumController@indexByCommunity',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    // Buat Topik Baru
    $router->addRoute('POST', 'api/communities/{slug}/forums', 'ForumController@store',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    // Detail Topik
    $router->addRoute('GET', 'api/communities/{slug}/forums/{id}', 'ForumController@show',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('GET', 'api/forums/{id}', 'ForumController@showById',
        ['Authenticate', 'Role:Admin']
    );
    $router->addRoute('POST', 'api/forums/{id}/update', 'ForumController@update',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar', 'ForumOwnerOrAdminMiddleware']
    );
    $router->addRoute('POST', 'api/forums/{id}/delete', 'ForumController@delete',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar', 'ForumOwnerOrAdminMiddleware']
    );

    // Rute Forum Respond
    $router->addRoute('GET', 'api/communities/{slug}/forums/{forumId}/responds', 'ForumRespondController@index',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    // Kirim Jawaban/Komentar
    $router->addRoute('POST', 'api/communities/{slug}/forums/{forumId}/responds', 'ForumRespondController@store',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    // Tandai Solusi (Mark Accepted) - FITUR PBL
    $router->addRoute('POST', 'api/communities/{slug}/forums/{forumId}/responds/{respondId}/accept', 'ForumRespondController@markAccepted',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('POST', 'api/responds/{forumRespondId}/replies', 'ForumRespondController@storeReply',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('POST', 'api/responds/{forumRespondId}/update', 'ForumRespondController@update',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar', 'ForumRespondOwnerOrAdmin']
    );
    $router->addRoute('POST', 'api/responds/{forumRespondId}/delete', 'ForumRespondController@delete',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar', 'ForumRespondOwnerOrAdmin']
    );

    // Rute Forum Reaction
    $router->addRoute('POST', 'api/reactions/forums/{forumId}', 'ForumReactionController@store',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('GET', 'api/reactions/forums/{forumId}', 'ForumReactionController@indexByForum',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('GET', 'api/myreaction/forums/{forumId}', 'ForumReactionController@showByUserAndForum',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('GET', 'api/allmyreaction/forums', 'ForumReactionController@indexByUser',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );

    // Rute Forum Respond Reaction
    $router->addRoute('POST', 'api/reactions/forumrespond/{forumRespondId}', 'ForumRespondReactionController@store',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('GET', 'api/reactions/forumrespond/{forumRespondId}', 'ForumRespondReactionController@indexByForumRespond',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('GET', 'api/myreaction/forumrespond/{forumRespondId}', 'ForumRespondReactionController@showByUserAndForumRespond',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('GET', 'api/allmyreaction/forumrespond', 'ForumRespondReactionController@indexByUser',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );

    // Rute Notification
    $router->addRoute('GET', 'api/notifications', 'NotificationController@index',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('POST', 'api/notifications/{id}/markasread', 'NotificationController@markAsRead',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('POST', 'api/notifications/readall', 'NotificationController@readAll',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );
    $router->addRoute('POST', 'api/notifications/{id}/delete', 'NotificationController@delete',
        ['Authenticate', 'Role:Admin,Dosen,Mahasiswa,Alumni,Mitra,Pakar']
    );

    // Rute Admin
    $router->addRoute('GET', 'api/admin/dashboard/overview', 'AdminController@dashboardOverview',
        ['Authenticate', 'Role:Admin,Dosen']
    );
    $router->addRoute('GET', 'api/admin/leaderboard', 'AdminController@globalLeaderboard',
        ['Authenticate', 'Role:Admin,Dosen']
    );
    $router->addRoute('GET', 'api/admin/account-requests', 'AdminController@getPendingRequests',
        ['Authenticate', 'Role:Admin']
    );
    $router->addRoute('POST', 'api/admin/account-requests/{id}/approve', 'AdminController@approveRequest',
        ['Authenticate', 'Role:Admin']
    );
    $router->addRoute('POST', 'api/admin/account-requests/{id}/reject', 'AdminController@rejectRequest',
        ['Authenticate', 'Role:Admin']
    );

    $router->addRoute('POST', 'api/activate-account', 'AuthController@activateExternalAccount');

    /**
     * =====================
     * ROUTE REPORT (USER)
     * =====================
     */
    $router->addRoute('POST', 'api/reports', 'ReportController@store', [
        'Authenticate' // semua user login boleh report
    ]);

    /**
     * =====================
     * ROUTE REPORT (ADMIN)
     * =====================
     */
    // Summary / merge
    $router->addRoute('GET', 'api/admin/reports/summary', 'ReportController@adminSummary', [
        'Authenticate',
        'Role:Admin'
    ]);

    // Detail semua report untuk 1 target
    $router->addRoute('GET', 'api/admin/reports/{type}/{id}', 'ReportController@adminDetail', [
        'Authenticate',
        'Role:Admin'
    ]);

    // Update status semua report untuk 1 target
    $router->addRoute('POST', 'api/admin/reports/{type}/{id}/status', 'ReportController@updateStatusByTarget', [
        'Authenticate',
        'Role:Admin'
    ]);

    $router->addRoute('POST', 'api/admin/reports/{type}/{id}/delete-target', 'ReportController@adminDeleteTarget', [
        'Authenticate',
        'Role:Admin'
    ]);

    // Admin user management
    $router->addRoute('POST', 'api/admin/users', 'AdminUserController@createUser', ['Authenticate', 'Role:Admin']);
    $router->addRoute('GET',  'api/admin/users', 'AdminUserController@listUsers', ['Authenticate', 'Role:Admin']);
    $router->addRoute('POST', 'api/admin/users/{id}/update', 'AdminUserController@updateUser', ['Authenticate', 'Role:Admin']);
    $router->addRoute('POST', 'api/admin/users/{id}/toggle-active', 'AdminUserController@toggleActive', ['Authenticate', 'Role:Admin']);
    $router->addRoute('POST', 'api/admin/users/{id}/delete', 'AdminUserController@deleteUser', ['Authenticate', 'Role:Admin']);
    $router->addRoute('POST', 'api/admin/users/promote-to-alumni', 'AdminUserController@promoteToAlumni', [
        'Authenticate',
        'Role:Admin'
    ]);
}
