<?php

namespace app\models;

use app\core\Database;

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