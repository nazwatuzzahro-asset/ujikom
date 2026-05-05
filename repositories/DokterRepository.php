<?php

require_once __DIR__ . '/../config/db_connection.php';

/**
 * Class DokterRepository
 * Menangani query terkait dokter dan laporan
 */
class DokterRepository
{
    private $conn;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->conn = DatabaseConnection::getConnection();
    }

    /**
 * Mengambil laporan rekap kunjungan dokter berdasarkan bulan dan tahun
 *
 * @param int $bulan
 * @param int $tahun
 * @return array
 */
public function getLaporanDokter(int $bulan, int $tahun): array
{
    $query = "
        SELECT 
            d.nama AS nama_dokter,
            d.spesialisasi,
            COUNT(p.id_pendaftaran) AS total_kunjungan,
            COALESCE(SUM(t.total_biaya), 0) AS total_pendapatan,
            COALESCE(AVG(rm.kepuasan), 0) AS rata_rata_kepuasan
        FROM dokter d
        LEFT JOIN jadwal_dokter jd ON d.id_dokter = jd.id_dokter
        LEFT JOIN pendaftaran p ON jd.id_jadwal = p.id_jadwal
        LEFT JOIN tagihan t ON p.id_pendaftaran = t.id_pendaftaran
        LEFT JOIN rekam_medis rm ON p.id_pendaftaran = rm.id_pendaftaran
        WHERE MONTH(p.tgl_daftar) = :bulan
        AND YEAR(p.tgl_daftar) = :tahun
        GROUP BY d.id_dokter, d.nama, d.spesialisasi
    ";

    $stmt = $this->conn->prepare($query);
    $stmt->bindParam(':bulan', $bulan, PDO::PARAM_INT);
    $stmt->bindParam(':tahun', $tahun, PDO::PARAM_INT);

    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
}