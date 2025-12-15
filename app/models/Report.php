<?php

namespace app\models;

use app\core\Database;
use PDO;
use PDOException;

class Report
{
    private $conn;
    private $table = 'reports';

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    /**
     * Buat report baru
     */
    public function createReport($userId, $reportableType, $reportableId, $violationType, $reason)
    {
        $query = "
            INSERT INTO {$this->table} (user_id, reason, status, reportable_type, reportable_id, violation_type)
            VALUES (:user_id, :reason, 'OPEN', :reportable_type, :reportable_id, :violation_type)
            ON CONFLICT (user_id, reportable_type, reportable_id)
            DO UPDATE SET 
                reason = EXCLUDED.reason,
                violation_type = EXCLUDED.violation_type,
                updated_at = CURRENT_TIMESTAMP
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':reason', $reason);
        $stmt->bindParam(':reportable_type', $reportableType);
        $stmt->bindParam(':reportable_id', $reportableId, PDO::PARAM_INT);
        $stmt->bindParam(':violation_type', $violationType);

        return $stmt->execute();
    }

    /**
     * Summary / merge report per target.
     * Satu baris = satu (reportable_type + reportable_id)
     */
    public function getSummary($status = null, $limit = 20, $offset = 0)
    {
        $params = [];
        $where = '';

        if ($status && $status !== 'ALL') {
            $where = "WHERE status = :status";
            $params[':status'] = $status;
        }

        $sql = "
        WITH grouped AS (
            SELECT 
                reportable_type,
                reportable_id,
                COUNT(*) AS total_reports,
                MIN(created_at) AS first_report_at,
                MAX(created_at) AS last_report_at,
                (ARRAY_AGG(status ORDER BY created_at DESC))[1] AS current_status
            FROM {$this->table}
            {$where}
            GROUP BY reportable_type, reportable_id
        )
        SELECT 
            g.*,
            (SELECT COUNT(*) FROM grouped) AS total_items
        FROM grouped g
        ORDER BY g.last_report_at DESC
        LIMIT :limit OFFSET :offset
    ";

        $stmt = $this->conn->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = 0;
        if (!empty($rows)) {

            $total = (int) ($rows[0]['total_items'] ?? 0);
        }

        // buang kolom total_items dari tiap row
        foreach ($rows as &$row) {
            unset($row['total_items']);
        }

        return [$rows, $total];
    }


    /**
     * Detail semua report untuk 1 target (untuk modal detail di admin)
     */
    public function getReportsByTarget($reportableType, $reportableId)
    {
        $sql = "
            SELECT 
                r.*,
                u.username,
                u.fullname
            FROM {$this->table} r
            JOIN users u ON r.user_id = u.id
            WHERE r.reportable_type = :type
              AND r.reportable_id = :id
            ORDER BY r.created_at DESC
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':type', $reportableType);
        $stmt->bindParam(':id', $reportableId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Update status semua report untuk 1 target (misal jadi RESOLVED/IGNORED)
     */
    public function updateStatusByTarget($reportableType, $reportableId, $status)
    {
        $sql = "
            UPDATE {$this->table}
            SET status = :status, updated_at = CURRENT_TIMESTAMP
            WHERE reportable_type = :type AND reportable_id = :id
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':type', $reportableType);
        $stmt->bindParam(':id', $reportableId, \PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function deleteByTarget(string $reportableType, int $reportableId): bool
    {
        $sql = "
        DELETE FROM {$this->table}
        WHERE reportable_type = :type
          AND reportable_id = :id
    ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':type', $reportableType);
        $stmt->bindParam(':id', $reportableId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function deleteByTargets(string $reportableType, array $reportableIds): bool
    {
        if (empty($reportableIds)) {
            return true; // tidak ada yang perlu dihapus
        }

        // Pastikan semua ID integer
        $placeholders = implode(',', array_fill(0, count($reportableIds), '?'));

        $sql = "
        DELETE FROM {$this->table}
        WHERE reportable_type = ?
          AND reportable_id IN ({$placeholders})
    ";

        $stmt = $this->conn->prepare($sql);

        // Bind parameter (type di awal, lalu ID)
        $stmt->bindValue(1, $reportableType);
        $index = 2;
        foreach ($reportableIds as $id) {
            $stmt->bindValue($index++, (int)$id, PDO::PARAM_INT);
        }

        return $stmt->execute();
    }
}
