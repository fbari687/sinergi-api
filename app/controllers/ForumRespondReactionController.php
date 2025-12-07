<?php

namespace app\controllers;

use app\helpers\ResponseFormatter;
use app\models\ForumRespond;
use app\models\ForumRespondReaction;

class ForumRespondReactionController {
    public function indexByForumRespond($forumRespondId) {
        $forumRespondReactionModel = new ForumRespondReaction();
        $forumRespondReactions = $forumRespondReactionModel->findByForumRespondId($forumRespondId);
        ResponseFormatter::success($forumRespondReactions, 'Forum respond reactions fetched successfully');;
    }
    public function indexByUser() {
        $forumRespondReactionModel = new ForumRespondReaction();
        $forumRespondReactions = $forumRespondReactionModel->findByUserId($_SESSION['user_id']);
        ResponseFormatter::success($forumRespondReactions, 'Forum respond reactions fetched successfully');;
    }
    public function showByUserAndForumRespond($forumRespondId) {
        $forumRespondReactionModel = new ForumRespondReaction();
        $forumRespondReactionData = $forumRespondReactionModel->findByForumRespondIdAndUserId($forumRespondId, $_SESSION['user_id']);
        ResponseFormatter::success($forumRespondReactionData, 'Forum respond reaction fetched successfully');;
    }
    public function store($forumRespondId) {
        $data = $_POST;

        if (!isset($data['reaction'])) {
            ResponseFormatter::error('Incomplete data', 400);
        }

        $forumRespondModel = new ForumRespond();
        $forumRespondData = $forumRespondModel->findById($forumRespondId);

        if (!$forumRespondData) {
            ResponseFormatter::error('Forum respond not found', 404);
        }

        $forumRespondReactionModel = new ForumRespondReaction();

        $forumRespondReactionData = $forumRespondReactionModel->findByForumRespondIdAndUserId($forumRespondId, $_SESSION['user_id']);

        if (!$forumRespondReactionData) {

            $forumRespondReactionData = [
                'forum_respond_id' => $forumRespondId,
                'user_id' => $_SESSION['user_id'],
                'reaction' => $data['reaction']
            ];

            $result = $forumRespondReactionModel->create($forumRespondReactionData);

            if (!$result) {
                ResponseFormatter::error('Failed to create forum respond reaction', 500);
            }
            ResponseFormatter::success(null, 'Forum respond reaction created successfully');
        }

        if ($forumRespondReactionData['reaction'] === filter_var($data['reaction'], FILTER_VALIDATE_BOOLEAN)) {
            $result = $forumRespondReactionModel->delete($forumRespondReactionData['id']);
            if (!$result) {
                ResponseFormatter::error('Failed to delete forum respond reaction', 500);
            }
            ResponseFormatter::success(null, 'Forum respond reaction deleted successfully');
        }

        if ($forumRespondReactionData['reaction'] !== filter_var($data['reaction'], FILTER_VALIDATE_BOOLEAN)) {
            $forumRespondReactionData = [
                'id' => $forumRespondReactionData['id'],
                'reaction' => $data['reaction']
            ];
            $result = $forumRespondReactionModel->update($forumRespondReactionData);
            if (!$result) {
                ResponseFormatter::error('Failed to update forum respond reaction', 500);
            }
            ResponseFormatter::success(null, 'Forum respond reaction updated successfully');
        }
    }
}