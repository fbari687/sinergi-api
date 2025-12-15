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

    // --- HELPER: Hitung Persentase ---
    private function percentChange(int $current, int $previous): ?float
    {
        if ($previous === 0) return $current > 0 ? 100 : 0;
        return round((($current - $previous) * 100) / $previous, 1);
    }

    // --- HELPER: Hitung Tanggal Berdasarkan Periode ---
    public function getDateRange(string $period): array
    {
        $endDate = date('Y-m-d 23:59:59');

        switch ($period) {
            case '30_days':
                $startDate = date('Y-m-d 00:00:00', strtotime('-29 days'));
                $prevEndDate = date('Y-m-d 23:59:59', strtotime('-30 days'));
                $prevStartDate = date('Y-m-d 00:00:00', strtotime('-59 days'));
                break;
            case 'this_month':
                $startDate = date('Y-m-01 00:00:00');
                $prevStartDate = date('Y-m-01 00:00:00', strtotime('first day of last month'));
                $prevEndDate = date('Y-m-t 23:59:59', strtotime('last day of last month'));
                break;
            case '7_days':
            default:
                $startDate = date('Y-m-d 00:00:00', strtotime('-6 days'));
                $prevEndDate = date('Y-m-d 23:59:59', strtotime('-7 days'));
                $prevStartDate = date('Y-m-d 00:00:00', strtotime('-13 days'));
                break;
        }

        return [$startDate, $endDate, $prevStartDate, $prevEndDate];
    }

    public function getTotalUsers(): int
    {
        $sql = "SELECT COUNT(*) AS total FROM users WHERE is_active = TRUE";
        $stmt = $this->conn->query($sql);
        return (int) $stmt->fetchColumn();
    }

    // --- STATS USERS AKTIF (Dinamis) ---
    public function getActiveUsersStats($start, $end, $prevStart, $prevEnd): array
    {
        $sql = "
            WITH interactions AS (
                SELECT user_id, created_at FROM posts
                UNION ALL SELECT user_id, created_at FROM comments
                UNION ALL SELECT user_id, created_at FROM forums
                UNION ALL SELECT user_id, created_at FROM forums_responds
            ),
            agg AS (
                SELECT
                    COUNT(DISTINCT CASE WHEN created_at BETWEEN :start AND :end THEN user_id END) AS current,
                    COUNT(DISTINCT CASE WHEN created_at BETWEEN :p_start AND :p_end THEN user_id END) AS previous
                FROM interactions
            )
            SELECT current, previous FROM agg
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':start' => $start, ':end' => $end,
            ':p_start' => $prevStart, ':p_end' => $prevEnd
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'current'  => (int) ($row['current'] ?? 0),
            'previous' => (int) ($row['previous'] ?? 0),
            'change'   => $this->percentChange($row['current'], $row['previous']),
        ];
    }

    // --- STATS KOMUNITAS AKTIF (Dinamis) ---
    public function getActiveCommunitiesStats($start, $end, $prevStart, $prevEnd): array
    {
        $sql = "
            WITH interactions AS (
                SELECT community_id, created_at FROM posts WHERE community_id IS NOT NULL
                UNION ALL
                SELECT community_id, created_at FROM forums WHERE community_id IS NOT NULL
            ),
            agg AS (
                SELECT
                    COUNT(DISTINCT CASE WHEN created_at BETWEEN :start AND :end THEN community_id END) AS current,
                    COUNT(DISTINCT CASE WHEN created_at BETWEEN :p_start AND :p_end THEN community_id END) AS previous
                FROM interactions
            )
            SELECT current, previous FROM agg
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':start' => $start, ':end' => $end,
            ':p_start' => $prevStart, ':p_end' => $prevEnd
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'current'  => (int) ($row['current'] ?? 0),
            'previous' => (int) ($row['previous'] ?? 0),
            'change'   => $this->percentChange($row['current'], $row['previous']),
        ];
    }

    // --- STATS LAPORAN PENDING (Dinamis) ---
    public function getPendingReportsStats($start, $end, $prevStart, $prevEnd): array
    {
        $sql = "
            SELECT
                COUNT(CASE WHEN status = 'OPEN' AND created_at BETWEEN :start AND :end THEN 1 END) AS current,
                COUNT(CASE WHEN status = 'OPEN' AND created_at BETWEEN :p_start AND :p_end THEN 1 END) AS previous
            FROM reports
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':start' => $start, ':end' => $end,
            ':p_start' => $prevStart, ':p_end' => $prevEnd
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'current'  => (int) ($row['current'] ?? 0),
            'previous' => (int) ($row['previous'] ?? 0),
            'change'   => $this->percentChange($row['current'], $row['previous']),
        ];
    }

    // --- CHART AKTIVITAS (Filter Tipe & Waktu) ---
    public function getActivityChart($start, $end, $type = 'ALL_COMBINED'): array
    {
        // Bangun Query CTE berdasarkan tipe
        $subQueries = [];

        if ($type === 'ALL_COMBINED' || $type === 'POST')
            $subQueries[] = "SELECT created_at FROM posts";

        if ($type === 'ALL_COMBINED' || $type === 'COMMENT')
            $subQueries[] = "SELECT created_at FROM comments";

        if ($type === 'ALL_COMBINED' || $type === 'FORUM')
            $subQueries[] = "SELECT created_at FROM forums";

        if ($type === 'ALL_COMBINED' || $type === 'RESPOND')
            $subQueries[] = "SELECT created_at FROM forums_responds";

        $unionQuery = implode(" UNION ALL ", $subQueries);

        $sql = "
            WITH interactions AS (
                $unionQuery
            )
            SELECT 
                to_char(gs.day::date, 'YYYY-MM-DD') AS day,
                COUNT(i.created_at) AS total
            FROM generate_series(
                :start::timestamp,
                :end::timestamp,
                INTERVAL '1 day'
            ) AS gs(day)
            LEFT JOIN interactions i ON date_trunc('day', i.created_at) = gs.day
            GROUP BY gs.day
            ORDER BY gs.day
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':start' => $start, ':end' => $end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labels = [];
        $values = [];

        foreach ($rows as $row) {
            $labels[] = date('d M', strtotime($row['day'])); // Format tgl lebih pendek
            $values[] = (int) $row['total'];
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    // --- GLOBAL LEADERBOARD (Baru) ---
    public function getGlobalLeaderboard($start, $end, $limit = 10): array
    {
        $sql = "
        WITH activity AS (
            SELECT user_id FROM posts WHERE created_at BETWEEN :start AND :end
            UNION ALL
            SELECT user_id FROM comments WHERE created_at BETWEEN :start AND :end
            UNION ALL
            SELECT user_id FROM forums WHERE created_at BETWEEN :start AND :end
            UNION ALL
            SELECT user_id FROM forums_responds WHERE created_at BETWEEN :start AND :end
        )
        SELECT 
            u.id, 
            u.fullname, 
            u.path_to_profile_picture,
            r.name as role_name,
            COUNT(a.user_id) as total_interactions,
            (SELECT COUNT(*) FROM posts WHERE user_id = u.id AND created_at BETWEEN :start AND :end) as posts,
            (SELECT COUNT(*) FROM forums WHERE user_id = u.id AND created_at BETWEEN :start AND :end) as forums,
            (SELECT COUNT(*) FROM comments c JOIN posts p ON c.post_id = p.id WHERE c.user_id = u.id AND c.created_at BETWEEN :start AND :end) as comments,
            (SELECT COUNT(*) FROM forums_responds fr JOIN forums f ON fr.forum_id = f.id WHERE fr.user_id = u.id AND fr.created_at BETWEEN :start AND :end) as responds
        FROM users u
        JOIN roles r ON u.role_id = r.id
        JOIN activity a ON u.id = a.user_id
        WHERE u.is_active = TRUE
        GROUP BY u.id, r.name
        ORDER BY total_interactions DESC
        LIMIT :limit 
    "; // Perhatikan LIMIT :limit

        $stmt = $this->conn->prepare($sql);
        // Bind Limit sebagai integer
        $stmt->bindValue(':start', $start);
        $stmt->bindValue(':end', $end);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRoleActivity(): array
    {
        // Role activity biasanya lebih relevan All Time atau 30 hari terakhir
        // Disini saya buat all time agar datanya banyak
        $sql = "
            WITH interactions AS (
                SELECT user_id FROM posts
                UNION ALL SELECT user_id FROM comments
                UNION ALL SELECT user_id FROM forums
                UNION ALL SELECT user_id FROM forums_responds
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

        return ['labels' => $labels, 'values' => $values];
    }

    public function getPendingReportsList(): array
    {
        // ... (Kode lama tetap sama) ...
        $sql = "SELECT id, reason, status, reportable_type, reportable_id, created_at FROM reports WHERE status = 'PENDING' ORDER BY created_at DESC LIMIT 10";
        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAccountRequestsList(): array
    {
        // ... (Kode lama tetap sama) ...
        $sql = "SELECT ar.id, ar.fullname, ar.email, ar.role_name, ar.status, ar.created_at, c.name AS community_name FROM account_requests ar JOIN communities c ON c.id = ar.community_id WHERE ar.status = 'PENDING' ORDER BY ar.created_at DESC LIMIT 10";
        return $this->conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    // --- MAIN METHOD: Get Overview dengan Parameter ---
    public function getOverview(string $period = '7_days', string $interactionType = 'ALL_COMBINED'): array
    {
        // 1. Hitung Rentang Tanggal
        [$start, $end, $prevStart, $prevEnd] = $this->getDateRange($period);

        return [
            'kpis' => [
                'total_users' => [
                    'current' => $this->getTotalUsers(),
                    'change'  => null,
                ],
                'active_users'       => $this->getActiveUsersStats($start, $end, $prevStart, $prevEnd),
                'active_communities' => $this->getActiveCommunitiesStats($start, $end, $prevStart, $prevEnd),
                'pending_reports'    => $this->getPendingReportsStats($start, $end, $prevStart, $prevEnd),
            ],
            'activity_chart'        => $this->getActivityChart($start, $end, $interactionType),
            'role_activity'         => $this->getRoleActivity(),
            'pending_reports_list'  => $this->getPendingReportsList(),
            'account_requests_list' => $this->getAccountRequestsList(),
            'leaderboard'           => $this->getGlobalLeaderboard($start, $end), // Data Baru
        ];
    }
}