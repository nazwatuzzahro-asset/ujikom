<?php

require_once __DIR__ . '/repositories/DokterRepository.php';

/**
 * Set header response JSON
 */
header('Content-Type: application/json');

/**
 * Validasi parameter GET
 */
$bulan = isset($_GET['bulan']) ? (int) $_GET['bulan'] : null;
$tahun = isset($_GET['tahun']) ? (int) $_GET['tahun'] : null;

if (!$bulan || !$tahun) {
    echo json_encode([
        "status" => "error",
        "message" => "Parameter bulan dan tahun wajib diisi"
    ]);
    exit;
}

try {
    $repo = new DokterRepository();
    $data = $repo->getLaporanDokter($bulan, $tahun);

    echo json_encode([
        "status" => "success",
        "data" => $data
    ]);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}