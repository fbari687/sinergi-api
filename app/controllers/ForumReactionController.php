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

        $inputReaction = (int) $data['reaction'];
        if (!in_array($inputReaction, [1, -1])) {
            ResponseFormatter::error('Invalid reaction value. Must be 1 (upvote) or -1 (downvote).', 400);
            return;
        }

        $forumModel = new Forum();
        $forumData = $forumModel->findById($forumId);

        if (!$forumData) {
            ResponseFormatter::error('Forum not found', 404);
        }

        $forumReactionModel = new ForumReaction();

        $existingReaction = $forumReactionModel->findByForumIdAndUserId($forumId, $_SESSION['user_id']);

        // SKENARIO A: Belum pernah vote -> Buat baru
        if (!$existingReaction) {
            $forumReactionData = [
                'forum_id' => $forumId,
                'user_id' => $_SESSION['user_id'],
                'reaction' => $inputReaction
            ];

            $result = $forumReactionModel->create($forumReactionData);

            if (!$result) {
                ResponseFormatter::error('Failed to create forum reaction', 500);
                return;
            }
            ResponseFormatter::success(['action' => 'created', 'reaction' => $inputReaction], 'Forum reaction created successfully');
            return;
        }

        // Ambil reaction yang ada di database (pastikan jadi int)
        $currentReactionValue = (int)$existingReaction['reaction'];

        // SKENARIO B: Vote sama dengan yang dikirim -> Hapus (Toggle Off / Netral)
        // Contoh: Sudah Upvote, klik Upvote lagi -> Jadi Netral
        if ($currentReactionValue === $inputReaction) {
            $result = $forumReactionModel->delete($existingReaction['id']);
            if (!$result) {
                ResponseFormatter::error('Failed to delete forum reaction', 500);
                return;
            }
            ResponseFormatter::success(['action' => 'deleted', 'reaction' => 0], 'Forum reaction deleted (neutralized) successfully');
            return;
        }

        // SKENARIO C: Vote berbeda -> Update
        // Contoh: Sudah Upvote, klik Downvote -> Ubah jadi Downvote
        if ($currentReactionValue !== $inputReaction) {
            $updateData = [
                'id' => $existingReaction['id'],
                'reaction' => $inputReaction
            ];
            $result = $forumReactionModel->update($updateData);
            if (!$result) {
                ResponseFormatter::error('Failed to update forum reaction', 500);
                return;
            }
            ResponseFormatter::success(['action' => 'updated', 'reaction' => $inputReaction], 'Forum reaction updated successfully');
            return;
        }
    }
}