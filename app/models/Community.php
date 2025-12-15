<?php

namespace app\models;

use app\core\Database;
use PDO;

class Community
{
    private $conn;

    private $table = 'communities';

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function getAll()
    {
        $query = "SELECT slug, name, path_to_thumbnail, is_public, about FROM {$this->table}";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getRecommendedCommunities($userId)
    {
        $query = "
            SELECT c.slug, c.name, c.path_to_thumbnail, c.is_public, COUNT(all_members.id) AS total_members
            FROM {$this->table} c
            -- Join untuk menghitung total member (hanya yang GRANTED)
            LEFT JOIN community_members all_members ON c.id = all_members.community_id AND all_members.status = 'GRANTED'
            
            -- PERBAIKAN DISINI:
            -- Tambahkan AND cm.status = 'GRANTED'
            -- Artinya: Jika user ada di tabel tapi statusnya masih 'REQUEST', 
            -- join ini akan gagal (return NULL), sehingga lolos dari filter WHERE cm.id IS NULL di bawah.
            LEFT JOIN community_members cm ON c.id = cm.community_id 
                AND cm.user_id = :user_id 
                AND cm.status = 'GRANTED'

            WHERE cm.id IS NULL -- Hanya ambil yang join-nya NULL (Belum GRANTED)
            GROUP BY c.id, c.slug, c.name, c.path_to_thumbnail, c.is_public
            ORDER BY total_members DESC
            LIMIT 6;
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function searchByName($userId, $keyword)
    {
        $searchTerm = "%" . $keyword . "%";

        // Menggunakan ILIKE (PostgreSQL) agar pencarian tidak case-sensitive (a == A)
        // LEFT JOIN pertama untuk menghitung total member
        // LEFT JOIN kedua untuk mengecek apakah user yang sedang login sudah bergabung
        $query = "
            SELECT 
                c.slug, 
                c.name, 
                c.path_to_thumbnail, 
                c.is_public, 
                COUNT(all_members.id) AS total_members,
                MAX(CASE WHEN cm.user_id IS NOT NULL AND cm.status = 'GRANTED' THEN 1 ELSE 0 END) as is_joined
            FROM {$this->table} c
            LEFT JOIN community_members all_members ON c.id = all_members.community_id AND all_members.status = 'GRANTED'
            LEFT JOIN community_members cm ON c.id = cm.community_id AND cm.user_id = :user_id
            WHERE c.name ILIKE :search
            GROUP BY c.id, c.slug, c.name, c.path_to_thumbnail, c.is_public
            ORDER BY total_members DESC
            LIMIT 20
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':search', $searchTerm);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getAllWithPagination($keyword = '', $sort = 'newest', $limit = 10, $offset = 0)
    {
        $searchTerm = "%" . $keyword . "%";

        // Tentukan Sorting
        $orderBy = "c.created_at DESC"; // Default
        switch ($sort) {
            case 'oldest':
                $orderBy = "c.created_at ASC";
                break;
            case 'most_members':
                $orderBy = "total_members DESC";
                break;
            case 'least_members':
                $orderBy = "total_members ASC";
                break;
            case 'newest':
            default:
                $orderBy = "c.created_at DESC";
                break;
        }

        $query = "
            SELECT 
                c.id, c.slug, c.name, c.path_to_thumbnail, c.is_public, c.created_at,
                COUNT(cm.id) AS total_members
            FROM {$this->table} c
            LEFT JOIN community_members cm ON c.id = cm.community_id AND cm.status = 'GRANTED'
            WHERE c.name ILIKE :search
            GROUP BY c.id
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':search', $searchTerm);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    // KHUSUS ADMIN: Hitung total data untuk pagination
    public function countAll($keyword = '')
    {
        $searchTerm = "%" . $keyword . "%";

        $query = "SELECT COUNT(*) as total FROM {$this->table} WHERE name ILIKE :search";

        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':search', $searchTerm);
        $stmt->execute();
        $result = $stmt->fetch();

        return $result ? (int)$result['total'] : 0;
    }

    public function findById($id)
    {
        $query = "SELECT slug, name, path_to_thumbnail, is_public, about, invitation_link FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch();
    }

    public function findIdBySlug($slug)
    {
        $query = "SELECT id FROM {$this->table} WHERE slug = :slug";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':slug', $slug);
        $stmt->execute();
        return $stmt->fetchColumn();
    }

    public function findBySlug($slug, $user_id)
    {
        $query = "SELECT c.id, c.slug, c.name, c.path_to_thumbnail, c.is_public, c.about, COUNT(cm_all.id) AS total_members, cm.status AS user_membership_status, cm.role AS current_user_role
                    FROM communities c 
                    LEFT JOIN community_members cm_all ON cm_all.community_id = c.id AND cm_all.status = 'GRANTED'
                    LEFT JOIN community_members cm ON cm.community_id = c.id AND cm.user_id = :user_id
                    WHERE c.slug = :slug 
                    GROUP BY c.id, c.slug, c.name, c.path_to_thumbnail, c.is_public, c.about, cm.status, cm.role";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':slug', $slug);
        $stmt->execute();
        return $stmt->fetch();
    }

    public function checkSlugExists($slug)
    {
        $query = "SELECT slug FROM {$this->table} WHERE slug = :slug";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':slug', $slug);
        $stmt->execute();
        return $stmt->fetchColumn();
    }

    public function delete($slug)
    {
        $query = "DELETE FROM {$this->table} WHERE slug = :slug";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':slug', $slug);
        return $stmt->execute();
    }

    public function deleteById($id)
    {
        $query = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }

    public function update($slug, array $dataCommunity)
    {
        $query = "UPDATE {$this->table} SET slug = :new_slug, name = :name, path_to_thumbnail = :path_to_thumbnail, is_public = :is_public, about = :about, invitation_link = :invitation_link WHERE slug = :old_slug";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':new_slug', $dataCommunity['slug']);
        $stmt->bindParam(':name', $dataCommunity['name']);
        $stmt->bindParam(':path_to_thumbnail', $dataCommunity['path_to_thumbnail']);
        $stmt->bindParam(':is_public', $dataCommunity['is_public']);
        $stmt->bindParam(':about', $dataCommunity['about']);
        $stmt->bindParam(':invitation_link', $dataCommunity['invitation_link']);
        $stmt->bindParam(':old_slug', $slug);
        return $stmt->execute();
    }

    public function create(array $dataCommunity)
    {

        $query = "INSERT INTO {$this->table} (name, slug, path_to_thumbnail, is_public, about, invitation_link) VALUES (:name, :slug, :path_to_thumbnail, :is_public, :about, :invitation_link) RETURNING id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':name', $dataCommunity['name']);
        $stmt->bindParam(':slug', $dataCommunity['slug']);
        $stmt->bindParam(':path_to_thumbnail', $dataCommunity['path_to_thumbnail']);
        $stmt->bindParam(':is_public', $dataCommunity['is_public']);
        $stmt->bindParam(':about', $dataCommunity['about']);
        $stmt->bindParam(':invitation_link', $dataCommunity['invitation_link']);
        $stmt->execute();

        return $stmt->fetchColumn();
    }

    public function getDashboardData($communityId, $start, $end, $prevStart, $prevEnd)
    {
        return [
            'kpis' => $this->getKpis($communityId, $start, $end, $prevStart, $prevEnd),
            'charts' => [
                'activity' => $this->getActivityChart($communityId, $start, $end),
                'roles' => $this->getRoleDistribution($communityId)
            ],
            'popular_topics' => $this->getPopularTopics($communityId),
            'top_contributors' => $this->getTopContributors($communityId),
            'inactive_members' => $this->getInactiveMembers($communityId)
        ];
    }

    // ==========================================
    // 1. KPI SECTION
    // ==========================================
    private function getKpis($commId, $start, $end, $prevStart, $prevEnd)
    {
        // A. Total Members (Current & New)
        // Status 'GRANTED' berdasarkan schema enum community_member_status
        $sqlNew = "SELECT COUNT(*) FROM community_members WHERE community_id = :cid AND status = 'GRANTED' AND created_at BETWEEN :start AND :end";
        $stmt = $this->conn->prepare($sqlNew);
        $stmt->execute([':cid' => $commId, ':start' => $start, ':end' => $end]);
        $newMembers = $stmt->fetchColumn();

        $sqlTotal = "SELECT COUNT(*) FROM community_members WHERE community_id = :cid AND status = 'GRANTED'";
        $stmt = $this->conn->prepare($sqlTotal);
        $stmt->execute([':cid' => $commId]);
        $totalMembers = $stmt->fetchColumn();

        // B. Interactions (Current vs Previous)
        $currInteractions = $this->countInteractions($commId, $start, $end);
        $prevInteractions = $this->countInteractions($commId, $prevStart, $prevEnd);

        $interactionChange = 0;
        if ($prevInteractions > 0) {
            $interactionChange = (($currInteractions - $prevInteractions) / $prevInteractions) * 100;
        } elseif ($currInteractions > 0) {
            $interactionChange = 100;
        }

        // C. Pending Requests
        $sqlPending = "SELECT COUNT(*) FROM community_members WHERE community_id = :cid AND status = 'REQUEST'";
        $stmt = $this->conn->prepare($sqlPending);
        $stmt->execute([':cid' => $commId]);
        $pendingCount = $stmt->fetchColumn();

        // D. Inactive Members (Placeholder, logic kompleks ada di bawah)
        $inactiveCount = count($this->getInactiveMembers($commId));

        return [
            'total_members' => ['current' => (int)$totalMembers, 'change' => (int)$newMembers],
            'interactions' => ['current' => (int)$currInteractions, 'change' => round($interactionChange, 1)],
            'pending_requests' => ['current' => (int)$pendingCount, 'change' => null],
            'inactive_members' => ['current' => (int)$inactiveCount, 'change' => 0]
        ];
    }

    // Helper: Hitung Post + Forum + Comment + Responds
    private function countInteractions($commId, $start, $end)
    {
        $sql = "
            SELECT (
                (SELECT COUNT(*) FROM posts WHERE community_id = :cid AND created_at BETWEEN :start AND :end) +
                (SELECT COUNT(*) FROM forums WHERE community_id = :cid AND created_at BETWEEN :start AND :end) +
                -- Join Comment ke Post ke Community
                (SELECT COUNT(*) FROM comments c JOIN posts p ON c.post_id = p.id WHERE p.community_id = :cid AND c.created_at BETWEEN :start AND :end) +
                -- Join Respond ke Forum ke Community
                (SELECT COUNT(*) FROM forums_responds fr JOIN forums f ON fr.forum_id = f.id WHERE f.community_id = :cid AND fr.created_at BETWEEN :start AND :end)
            ) as total
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':cid' => $commId, ':start' => $start, ':end' => $end]);
        return (int)$stmt->fetchColumn();
    }

    // ==========================================
    // 2. CHART ACTIVITY (Line Chart)
    // ==========================================
    private function getActivityChart($commId, $start, $end)
    {
        // Generate array tanggal agar grafik tidak bolong
        $period = new \DatePeriod(
            new \DateTime($start),
            new \DateInterval('P1D'),
            (new \DateTime($end))->modify('+1 day')
        );

        // Fetch data grouped by Date
        // PostgreSQL menggunakan DATE(created_at)
        $sqlPosts = "SELECT DATE(created_at) as d, COUNT(*) as c FROM posts WHERE community_id = :cid AND created_at BETWEEN :start AND :end GROUP BY d";
        $rawPosts = $this->fetchKeyed($sqlPosts, $commId, $start, $end);

        $sqlForums = "SELECT DATE(created_at) as d, COUNT(*) as c FROM forums WHERE community_id = :cid AND created_at BETWEEN :start AND :end GROUP BY d";
        $rawForums = $this->fetchKeyed($sqlForums, $commId, $start, $end);

        $sqlComments = "SELECT DATE(c.created_at) as d, COUNT(*) as c FROM comments c JOIN posts p ON c.post_id = p.id WHERE p.community_id = :cid AND c.created_at BETWEEN :start AND :end GROUP BY d";
        $rawComments = $this->fetchKeyed($sqlComments, $commId, $start, $end);

        $sqlResponds = "SELECT DATE(fr.created_at) as d, COUNT(*) as c FROM forums_responds fr JOIN forums f ON fr.forum_id = f.id WHERE f.community_id = :cid AND fr.created_at BETWEEN :start AND :end GROUP BY d";
        $rawResponds = $this->fetchKeyed($sqlResponds, $commId, $start, $end);

        $labels = []; $dPost = []; $dForum = []; $dComment = []; $dRespond = [];

        foreach ($period as $dt) {
            $dateStr = $dt->format('Y-m-d');
            $labels[] = $dt->format('d M'); // Format Label Frontend

            $dPost[] = $rawPosts[$dateStr] ?? 0;
            $dForum[] = $rawForums[$dateStr] ?? 0;
            $dComment[] = $rawComments[$dateStr] ?? 0;
            $dRespond[] = $rawResponds[$dateStr] ?? 0;
        }

        return [
            'labels' => $labels,
            'posts' => $dPost,
            'forums' => $dForum,
            'comments' => $dComment,
            'responds' => $dRespond
        ];
    }

    // Helper PDO fetch key-value pair
    private function fetchKeyed($sql, $commId, $start, $end) {
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':cid' => $commId, ':start' => $start, ':end' => $end]);
        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    // ==========================================
    // 3. CHART ROLES (Doughnut)
    // ==========================================
    private function getRoleDistribution($commId)
    {
        // Join Users -> Roles untuk mendapatkan nama role (Admin, Dosen, Mahasiswa, dll)
        $sql = "
            SELECT r.name, COUNT(cm.id) as total
            FROM community_members cm
            JOIN users u ON cm.user_id = u.id
            JOIN roles r ON u.role_id = r.id
            WHERE cm.community_id = :cid AND cm.status = 'GRANTED'
            GROUP BY r.name
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':cid' => $commId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $labels = [];
        $data = [];
        foreach ($rows as $r) {
            $labels[] = $r['name'];
            $data[] = (int)$r['total'];
        }

        return ['labels' => $labels, 'data' => $data];
    }

    // ==========================================
    // 4. POPULAR TOPICS (Table)
    // ==========================================
    private function getPopularTopics($commId)
    {
        // Union Posts dan Forums, urutkan berdasarkan jumlah komentar/tanggapan
        $sql = "
            (SELECT p.id, substring(p.description, 1, 50) as title, 'POST' as type, COUNT(c.id) as replies, u.fullname as author
             FROM posts p
             JOIN users u ON p.user_id = u.id
             LEFT JOIN comments c ON p.id = c.post_id
             WHERE p.community_id = :cid
             GROUP BY p.id, u.fullname
            )
            UNION ALL
            (SELECT f.id, f.title, 'FORUM' as type, COUNT(fr.id) as replies, u.fullname as author
             FROM forums f
             JOIN users u ON f.user_id = u.id
             LEFT JOIN forums_responds fr ON f.id = fr.forum_id
             WHERE f.community_id = :cid
             GROUP BY f.id, u.fullname
            )
            ORDER BY replies DESC
            LIMIT 5
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':cid' => $commId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ==========================================
    // 5. TOP CONTRIBUTORS (Table)
    // ==========================================
    private function getTopContributors($commId)
    {
        // Hitung total kontribusi user di komunitas ini
        // Menggunakan nama tabel sesuai schema: forums_responds, comments
        $sql = "
            SELECT 
                u.id, u.fullname, r.name as role,
                (SELECT COUNT(*) FROM posts WHERE user_id = u.id AND community_id = :cid) as posts,
                (SELECT COUNT(*) FROM forums WHERE user_id = u.id AND community_id = :cid) as forums,
                (SELECT COUNT(*) FROM comments c JOIN posts p ON c.post_id = p.id WHERE c.user_id = u.id AND p.community_id = :cid) as comments,
                (SELECT COUNT(*) FROM forums_responds fr JOIN forums f ON fr.forum_id = f.id WHERE fr.user_id = u.id AND f.community_id = :cid) as responds
            FROM community_members cm
            JOIN users u ON cm.user_id = u.id
            JOIN roles r ON u.role_id = r.id
            WHERE cm.community_id = :cid AND cm.status = 'GRANTED'
            LIMIT 20
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':cid' => $commId]);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Kalkulasi Total & Sort di PHP (Menghindari query SQL yang terlalu kompleks/lambat)
        foreach ($results as &$row) {
            $row['total'] = $row['posts'] + $row['forums'] + $row['comments'] + $row['responds'];
        }
        unset($row);

        usort($results, function($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        return array_slice($results, 0, 5);
    }

    // ==========================================
    // 6. INACTIVE MEMBERS (Table)
    // ==========================================
    private function getInactiveMembers($commId)
    {
        // Member yang tidak melakukan Post, Comment, Forum, atau Respond dalam 30 hari terakhir
        $thirtyDaysAgo = date('Y-m-d 00:00:00', strtotime('-30 days'));

        $sql = "
            SELECT u.id, u.fullname, r.name as role
            FROM community_members cm
            JOIN users u ON cm.user_id = u.id
            JOIN roles r ON u.role_id = r.id
            WHERE cm.community_id = :cid AND cm.status = 'GRANTED'
            AND u.id NOT IN (
                SELECT user_id FROM posts WHERE community_id = :cid AND created_at >= :date
                UNION
                SELECT user_id FROM forums WHERE community_id = :cid AND created_at >= :date
                UNION
                SELECT c.user_id FROM comments c JOIN posts p ON c.post_id = p.id WHERE p.community_id = :cid AND c.created_at >= :date
                UNION
                SELECT fr.user_id FROM forums_responds fr JOIN forums f ON fr.forum_id = f.id WHERE f.community_id = :cid AND fr.created_at >= :date
            )
            LIMIT 5
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':cid' => $commId, ':date' => $thirtyDaysAgo]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getLeaderboardData($communityId, $start, $end)
    {
        // Query ini menghitung aktivitas HANYA dalam rentang tanggal yang dipilih
        $sql = "
        SELECT 
            u.id, u.fullname, u.username, u.path_to_profile_picture, r.name as role,
            (SELECT COUNT(*) FROM posts WHERE user_id = u.id AND community_id = :cid AND created_at BETWEEN :start AND :end) as posts,
            (SELECT COUNT(*) FROM forums WHERE user_id = u.id AND community_id = :cid AND created_at BETWEEN :start AND :end) as forums,
            (SELECT COUNT(*) FROM comments c JOIN posts p ON c.post_id = p.id WHERE c.user_id = u.id AND p.community_id = :cid AND c.created_at BETWEEN :start AND :end) as comments,
            (SELECT COUNT(*) FROM forums_responds fr JOIN forums f ON fr.forum_id = f.id WHERE fr.user_id = u.id AND f.community_id = :cid AND fr.created_at BETWEEN :start AND :end) as responds
        FROM community_members cm
        JOIN users u ON cm.user_id = u.id
        JOIN roles r ON u.role_id = r.id
        WHERE cm.community_id = :cid AND cm.status = 'GRANTED'
    ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':cid' => $communityId, ':start' => $start, ':end' => $end]);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Hitung Total Skor di PHP
        foreach ($results as &$row) {
            $row['total'] = $row['posts'] + $row['forums'] + $row['comments'] + $row['responds'];

            // Buat URL Profile Picture lengkap (Opsional, sesuaikan dengan config Anda)
            // $row['profile_picture_url'] = ...
        }
        unset($row);

        // Urutkan dari skor tertinggi (Descending)
        usort($results, function($a, $b) {
            // Jika skor sama, urutkan nama (Ascending)
            if ($b['total'] == $a['total']) {
                return strcmp($a['fullname'], $b['fullname']);
            }
            return $b['total'] <=> $a['total'];
        });

        // Ambil Top 50 saja agar tidak terlalu berat
        return array_slice($results, 0, 50);
    }

    /**
     * Membuat slug yang unik dari sebuah nama.
     * Jika slug sudah ada, akan ditambahkan akhiran _2, _3, dst.
     * @param string $name Nama community
     * @return string Slug yang dijamin unik
     */
    public static function generateUniqueSlug(string $name): string
    {
        // 1. Buat slug dasar
        $baseSlug = strtolower($name);
        // Ganti spasi dan karakter non-alfanumerik dengan underscore
        $baseSlug = preg_replace('/[^a-z0-9]+/', '_', $baseSlug);
        // Hapus underscore di awal atau akhir
        $baseSlug = trim($baseSlug, '_');

        // 2. Cek keunikan slug
        $model = new self(); // Buat instance dari class ini sendiri
        $slug = $baseSlug;
        $counter = 2;

        // Terus looping selama slug yang di-generate sudah ada di database
        while ($model->checkSlugExists($slug)) {
            $slug = $baseSlug . '_' . $counter;
            $counter++;
        }

        return $slug;
    }
}