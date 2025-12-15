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

    public function createAlumniProfile($userId, $data) {
        $query = "INSERT INTO alumni_profiles (user_id, tahun_lulus, pekerjaan_saat_ini, nama_perusahaan)
              VALUES (:user_id, :tahun_lulus, :pekerjaan_saat_ini, :nama_perusahaan)";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':user_id' => $userId,
            ':tahun_lulus' => $data['tahun_lulus'],
            ':pekerjaan_saat_ini' => $data['pekerjaan_saat_ini'] ?? null,
            ':nama_perusahaan' => $data['nama_perusahaan'] ?? null,
        ]);
    }

    public function createMitraProfile($userId, $data) {
        $query = "INSERT INTO mitra_profiles (user_id, nama_perusahaan, jabatan, alamat_perusahaan)
              VALUES (:user_id, :nama_perusahaan, :jabatan, :alamat_perusahaan)";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':user_id' => $userId,
            ':nama_perusahaan' => $data['nama_perusahaan'] ?? '',
            ':jabatan' => $data['jabatan'] ?? '',
            ':alamat_perusahaan' => $data['alamat_perusahaan'] ?? '',
        ]);
    }

    public function createPakarProfile($userId, $data) {
        $query = "INSERT INTO pakar_profiles (user_id, bidang_keahlian, instansi_asal)
              VALUES (:user_id, :bidang_keahlian, :instansi_asal)";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':user_id' => $userId,
            ':bidang_keahlian' => $data['bidang_keahlian'] ?? '',
            ':instansi_asal' => $data['instansi_asal'] ?? ''
        ]);
    }
}