<?php

namespace app\models;

use app\core\Database;
use PDO;

class UserProfile
{
    private $conn;

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    // Fungsi untuk mengecek apakah user sudah mengisi data lengkap
    public function hasProfile($userId, $roleName)
    {
        $table = '';
        // Kita hanya memaksa Mahasiswa dan Dosen
        if ($roleName === 'Mahasiswa') $table = 'mahasiswa_profiles';
        elseif ($roleName === 'Dosen') $table = 'dosen_profiles';
        else return true; // Role lain (Admin/Umum) dianggap sudah lengkap

        $query = "SELECT user_id FROM $table WHERE user_id = :user_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    public function createMahasiswaProfile($userId, $data)
    {
        $query = "INSERT INTO mahasiswa_profiles (user_id, nim, prodi, tahun_masuk, tahun_perkiraan_lulus) 
                  VALUES (:user_id, :nim, :prodi, :tahun_masuk, :tahun_perkiraan_lulus)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':nim', $data['nim']);
        $stmt->bindParam(':prodi', $data['prodi']);
        $stmt->bindParam(':tahun_masuk', $data['tahun_masuk']);
        $stmt->bindParam(':tahun_perkiraan_lulus', $data['tahun_perkiraan_lulus']);
        return $stmt->execute();
    }

    public function createDosenProfile($userId, $data)
    {
        $query = "INSERT INTO dosen_profiles (user_id, nidn, bidang_keahlian) 
                  VALUES (:user_id, :nidn, :bidang_keahlian)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':nidn', $data['nidn']);
        $stmt->bindParam(':bidang_keahlian', $data['bidang_keahlian']);
        return $stmt->execute();
    }
}