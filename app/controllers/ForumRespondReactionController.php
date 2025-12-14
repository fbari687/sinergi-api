<?php

namespace app\controllers;

use app\helpers\ResponseFormatter;
use app\models\ForumRespond;
use app\models\ForumRespondReaction;

class ForumRespondReactionController
{
    public function indexByForumRespond($forumRespondId)
    {
        $forumRespondReactionModel = new ForumRespondReaction();
        $forumRespondReactions = $forumRespondReactionModel->findByForumRespondId($forumRespondId);
        ResponseFormatter::success($forumRespondReactions, 'Forum respond reactions fetched successfully');;
    }

    public function indexByUser()
    {
        $forumRespondReactionModel = new ForumRespondReaction();
        $forumRespondReactions = $forumRespondReactionModel->findByUserId($_SESSION['user_id']);
        ResponseFormatter::success($forumRespondReactions, 'Forum respond reactions fetched successfully');;
    }

    public function showByUserAndForumRespond($forumRespondId)
    {
        $forumRespondReactionModel = new ForumRespondReaction();
        $forumRespondReactionData = $forumRespondReactionModel->findByForumRespondIdAndUserId($forumRespondId, $_SESSION['user_id']);
        ResponseFormatter::success($forumRespondReactionData, 'Forum respond reaction fetched successfully');;
    }

    public function store($forumRespondId)
    {
        $data = $_POST;

        if (!isset($data['reaction'])) {
            ResponseFormatter::error('Incomplete data. Reaction is required.', 400);
            return;
        }

        // Validasi Integer (1 atau -1)
        $inputReaction = (int)$data['reaction'];
        if (!in_array($inputReaction, [1, -1])) {
            ResponseFormatter::error('Invalid reaction value. Must be 1 (upvote) or -1 (downvote).', 400);
            return;
        }

        $forumRespondModel = new ForumRespond();
        $forumRespondData = $forumRespondModel->findById($forumRespondId);

        if (!$forumRespondData) {
            ResponseFormatter::error('Forum respond not found', 404);
            return;
        }

        $forumRespondReactionModel = new ForumRespondReaction();
        $existingReaction = $forumRespondReactionModel->findByForumRespondIdAndUserId($forumRespondId, $_SESSION['user_id']);

        // SKENARIO A: Buat Baru
        if (!$existingReaction) {
            $forumRespondReactionData = [
                'forum_respond_id' => $forumRespondId,
                'user_id' => $_SESSION['user_id'],
                'reaction' => $inputReaction
            ];

            $result = $forumRespondReactionModel->create($forumRespondReactionData);

            if (!$result) {
                ResponseFormatter::error('Failed to create forum respond reaction', 500);
                return;
            }
            ResponseFormatter::success(['action' => 'created', 'reaction' => $inputReaction], 'Forum respond reaction created successfully');
            return;
        }

        // Ambil nilai lama
        $currentReactionValue = (int)$existingReaction['reaction'];

        // SKENARIO B: Hapus (Toggle)
        if ($currentReactionValue === $inputReaction) {
            $result = $forumRespondReactionModel->delete($existingReaction['id']);
            if (!$result) {
                ResponseFormatter::error('Failed to delete forum respond reaction', 500);
                return;
            }
            ResponseFormatter::success(['action' => 'deleted', 'reaction' => 0], 'Forum respond reaction deleted successfully');
            return;
        }

        // SKENARIO C: Update (Ganti Vote)
        if ($currentReactionValue !== $inputReaction) {
            $updateData = [
                'id' => $existingReaction['id'],
                'reaction' => $inputReaction
            ];
            $result = $forumRespondReactionModel->update($updateData);
            if (!$result) {
                ResponseFormatter::error('Failed to update forum respond reaction', 500);
                return;
            }
            ResponseFormatter::success(['action' => 'updated', 'reaction' => $inputReaction], 'Forum respond reaction updated successfully');
            return;
        }
    }
}