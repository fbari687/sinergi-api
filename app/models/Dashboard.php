<?php

namespace app\models;

use app\core\Database;
use PDO;

class Dashboard
{
    private $conn;

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    private function percentChange(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return null;
        }
        return round((($current - $previous) * 100) / $previous, 1);
    }

    public function getTotalUsers(): int
    {
        $sql = "SELECT COUNT(*) AS total FROM users WHERE is_active = TRUE";
        $stmt = $this->conn->query($sql);
        return (int) $stmt->fetchColumn();
    }

    public function getActiveUsersStats(): array
    {
        $sql = "
            WITH interactions AS (
                SELECT user_id, created_at FROM posts
                UNION ALL
                SELECT user_id, created_at FROM comments
                UNION ALL
                SELECT user_id, created_at FROM forums
                UNION ALL
                SELECT user_id, created_at FROM forums_responds
            ),
            agg AS (
                SELECT
                    COUNT(DISTINCT CASE 
                        WHEN created_at >= NOW() - INTERVAL '7 days'
                        THEN user_id END
                    ) AS current,
                    COUNT(DISTINCT CASE 
                        WHEN created_at >= NOW() - INTERVAL '14 days'
                          AND created_at < NOW() - INTERVAL '7 days'
                        THEN user_id END
                    ) AS previous
                FROM interactions
            )
            SELECT current, previous FROM agg
        ";

        $row = $this->conn->query($sql)->fetch(PDO::FETCH_ASSOC);

        $current  = (int) ($row['current'] ?? 0);
        $previous = (int) ($row['previous'] ?? 0);

        return [
            'current'  => $current,
            'previous' => $previous,
            'change'   => $this->percentChange($current, $previous),
        ];
    }

    public function getActiveCommunitiesStats(): array
    {
        $sql = "
            WITH interactions AS (
                SELECT community_id, created_at 
                FROM posts 
                WHERE community_id IS NOT NULL

                UNION ALL

                SELECT community_id, created_at 
                FROM forums 
                WHERE community_id IS NOT NULL
            ),
            agg AS (
                SELECT
                    COUNT(DISTINCT CASE 
                        WHEN created_at >= NOW() - INTERVAL '7 days'
                        THEN community_id END
                    ) AS current,
                    COUNT(DISTINCT CASE 
                        WHEN created_at >= NOW() - INTERVAL '14 days'
                          AND created_at < NOW() - INTERVAL '7 days'
                        THEN community_id END
                    ) AS previous
                FROM interactions
            )
            SELECT current, previous FROM agg
        ";

        $row = $this->conn->query($sql)->fetch(PDO::FETCH_ASSOC);

        $current  = (int) ($row['current'] ?? 0);
        $previous = (int) ($row['previous'] ?? 0);

        return [
            'current'  => $current,
            'previous' => $previous,
            'change'   => $this->percentChange($current, $previous),
        ];
    }

    public function getPendingReportsStats(): array
    {
        $sql = "
            WITH data AS (
                SELECT status, created_at FROM reports
            ),
            agg AS (
                SELECT
                    COUNT(CASE 
                        WHEN status = 'PENDING'
                         AND created_at >= NOW() - INTERVAL '7 days'
                        THEN 1 END
                    ) AS current,
                    COUNT(CASE 
                        WHEN status = 'PENDING'
                         AND created_at >= NOW() - INTERVAL '14 days'
                         AND created_at <  NOW() - INTERVAL '7 days'
                        THEN 1 END
                    ) AS previous
                FROM data
            )
            SELECT current, previous FROM agg
        ";

        $row = $this->conn->query($sql)->fetch(PDO::FETCH_ASSOC);

        $current  = (int) ($row['current'] ?? 0);
        $previous = (int) ($row['previous'] ?? 0);

        return [
            'current'  => $current,
            'previous' => $previous,
            'change'   => $this->percentChange($current, $previous),
        ];
    }

    public function getActivityChart(): array
    {
        $sql = "
            WITH interactions AS (
                SELECT date_trunc('day', created_at) AS day FROM posts
                UNION ALL
                SELECT date_trunc('day', created_at) FROM comments
                UNION ALL
                SELECT date_trunc('day', created_at) FROM forums
                UNION ALL
                SELECT date_trunc('day', created_at) FROM forums_responds
            )
            SELECT 
                to_char(gs.day::date, 'YYYY-MM-DD') AS day,
                COUNT(i.day) AS total
            FROM generate_series(
                date_trunc('day', NOW()) - INTERVAL '6 days',
                date_trunc('day', NOW()),
                INTERVAL '1 day'
            ) AS gs(day)
            LEFT JOIN interactions i ON i.day = gs.day
            GROUP BY gs.day
            ORDER BY gs.day
        ";

        $stmt = $this->conn->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labels = [];
        $values = [];

        foreach ($rows as $row) {
            $labels[] = $row['day'];
            $values[] = (int) $row['total'];
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    public function getRoleActivity(): array
    {
        $sql = "
            WITH interactions AS (
                SELECT user_id FROM posts
                UNION ALL
                SELECT user_id FROM comments
                UNION ALL
                SELECT user_id FROM forums
                UNION ALL
                SELECT user_id FROM forums_responds
            )
            SELECT r.name AS role_name, COUNT(*) AS total
            FROM interactions i
            JOIN users u ON u.id = i.user_id
            JOIN roles r ON r.id = u.role_id
            GROUP BY r.name
            ORDER BY total DESC
        ";

        $rows = $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $labels = [];
        $values = [];

        foreach ($rows as $row) {
            $labels[] = $row['role_name'];
            $values[] = (int) $row['total'];
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    public function getPendingReportsList(): array
    {
        $sql = "
            SELECT id, reason, status, reportable_type, reportable_id, created_at
            FROM reports
            WHERE status = 'PENDING'
            ORDER BY created_at DESC
            LIMIT 10
        ";

        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAccountRequestsList(): array
    {
        $sql = "
            SELECT 
                ar.id,
                ar.fullname,
                ar.email,
                ar.role_name,
                ar.status,
                ar.created_at,
                c.name AS community_name
            FROM account_requests ar
            JOIN communities c ON c.id = ar.community_id
            WHERE ar.status = 'PENDING'
            ORDER BY ar.created_at DESC
            LIMIT 10
        ";

        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getOverview(): array
    {
        $totalUsers = $this->getTotalUsers();
        $activeUsers = $this->getActiveUsersStats();
        $activeCommunities = $this->getActiveCommunitiesStats();
        $pendingReports = $this->getPendingReportsStats();
        $activityChart = $this->getActivityChart();
        $roleActivity = $this->getRoleActivity();
        $pendingReportsList = $this->getPendingReportsList();
        $accountRequestsList = $this->getAccountRequestsList();

        return [
            'kpis' => [
                'total_users' => [
                    'current' => $totalUsers,
                    'change'  => null, // bisa kamu isi kalau nanti ingin banding mingguan
                ],
                'active_users'       => $activeUsers,
                'active_communities' => $activeCommunities,
                'pending_reports'    => $pendingReports,
            ],
            'activity_chart'        => $activityChart,
            'role_activity'         => $roleActivity,
            'pending_reports_list'  => $pendingReportsList,
            'account_requests_list' => $accountRequestsList,
        ];
    }
}
