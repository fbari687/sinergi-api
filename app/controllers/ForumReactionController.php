<?php

namespace app\controllers;

use app\helpers\ResponseFormatter;
use app\models\Forum;
use app\models\ForumReaction;

class ForumReactionController {

    public function indexByForum($forumId) {
        $forumReactionModel = new ForumReaction();
        $forumReactions = $forumReactionModel->findByForumId($forumId);
        ResponseFormatter::success($forumReactions, 'Forum reactions fetched successfully');
    }

    public function indexByUser() {
        $forumReactionModel = new ForumReaction();
        $forumReactions = $forumReactionModel->findByUserId($_SESSION['user_id']);
        ResponseFormatter::success($forumReactions, 'Forum reactions fetched successfully');;
    }

    public function showByUserAndForum($forumId) {
        $forumReactionModel = new ForumReaction();
        $forumReaction = $forumReactionModel->findByForumIdAndUserId($forumId, $_SESSION['user_id']);
        ResponseFormatter::success($forumReaction, 'Forum reaction fetched successfully');;
    }

    public function store($forumId) {
        $data = $_POST;

        if (!isset($data['reaction'])) {
            ResponseFormatter::error('Incomplete data', 400);
        }

        $forumModel = new Forum();
        $forumData = $forumModel->findById($forumId);

        if (!$forumData) {
            ResponseFormatter::error('Forum not found', 404);
        }

        $forumReactionModel = new ForumReaction();

        $forumReactionData = $forumReactionModel->findByForumIdAndUserId($forumId, $_SESSION['user_id']);

        if (!$forumReactionData) {
            $forumReactionData = [
                'forum_id' => $forumId,
                'user_id' => $_SESSION['user_id'],
                'reaction' => $data['reaction']
            ];

            $result = $forumReactionModel->create($forumReactionData);

            if (!$result) {
                ResponseFormatter::error('Failed to create forum reaction', 500);
            }
            ResponseFormatter::success(null, 'Forum reaction created successfully');
        }

        if ($forumReactionData['reaction'] === filter_var($data['reaction'], FILTER_VALIDATE_BOOLEAN)) {
            $result = $forumReactionModel->delete($forumReactionData['id']);
            if (!$result) {
                ResponseFormatter::error('Failed to delete forum reaction', 500);
            }
            ResponseFormatter::success(null, 'Forum reaction deleted successfully');
        }

        if ($forumReactionData['reaction'] !== filter_var($data['reaction'], FILTER_VALIDATE_BOOLEAN)) {
            $forumReactionData = [
                'id' => $forumReactionData['id'],
                'reaction' => $data['reaction']
            ];
            $result = $forumReactionModel->update($forumReactionData);
            if (!$result) {
                ResponseFormatter::error('Failed to update forum reaction', 500);
            }
            ResponseFormatter::success(null, 'Forum reaction updated successfully');
        }
    }
}