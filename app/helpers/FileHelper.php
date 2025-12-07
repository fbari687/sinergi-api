<?php
namespace app\helpers;

class FileHelper
{
    /**
     * Menangani upload file.
     *
     * @param array $fileData Data file dari $_FILES['nama_input'].
     * @param string $destinationPath Path tujuan di dalam folder 'storage/app/public/'.
     * @param array $allowedMimeTypes Tipe file yang diizinkan.
     * @param int $maxSize Ukuran file maksimum dalam bytes.
     * @return string|false Path file yang berhasil diupload, atau false jika gagal.
     */
    public static function upload(array $fileData, string $destinationPath, array $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif'], int $maxSize = 2097152) // Default 2MB
    {
        // 1. Cek apakah ada error saat upload
        if ($fileData['error'] !== UPLOAD_ERR_OK) {
            // Bisa ditambahkan logging error di sini
            return false;
        }

        // 2. Cek ukuran file
        if ($fileData['size'] > $maxSize) {
            return false;
        }

        // 3. Cek tipe MIME file
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $fileData['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedMimeTypes)) {
            return false;
        }

        // 4. Buat nama file baru yang unik untuk menghindari tumpang tindih
        $extension = pathinfo($fileData['name'], PATHINFO_EXTENSION);
        $newFileName = uniqid('', true) . '.' . strtolower($extension);

        // 5. Tentukan path lengkap tujuan
        // Ingat, kita menyimpan di luar public root (storage/app/public)
        $fullDestinationPath = BASE_PATH . '/storage/app/public/' . $destinationPath;

        // Buat direktori jika belum ada
        if (!is_dir($fullDestinationPath)) {
            mkdir($fullDestinationPath, 0777, true);
        }

        $finalFilePath = $fullDestinationPath . '/' . $newFileName;

        // 6. Pindahkan file dari lokasi temporary ke tujuan akhir
        if (move_uploaded_file($fileData['tmp_name'], $finalFilePath)) {
            // Kembalikan path relatif yang bisa disimpan di database dan diakses via URL
            // Contoh: 'community_thumbnails/69deadbeef.jpg'
            return $destinationPath . '/' . $newFileName;
        }

        return false;
    }

    /**
     * Menghapus file dari storage.
     *
     * @param string|null $relativePath Path file relatif yang disimpan di database
     * (contoh: 'community_thumbnails/69deadbeef.jpg').
     * @return bool True jika berhasil dihapus atau jika path kosong, false jika gagal.
     */
    public static function delete(?string $relativePath): bool
    {
        // Jika path kosong atau null, anggap berhasil (karena tidak ada yang perlu dihapus).
        if (empty($relativePath)) {
            return true;
        }

        // 1. Buat path absolut ke file di server
        $fullPath = BASE_PATH . '/storage/app/public/' . $relativePath;

        // 2. Cek apakah file benar-benar ada sebelum mencoba menghapus
        if (file_exists($fullPath) && is_file($fullPath)) {
            // 3. Hapus file menggunakan unlink()
            return unlink($fullPath);
        }

        // Jika file tidak ada, anggap saja "berhasil" karena tujuannya agar file tersebut hilang.
        return true;
    }
}