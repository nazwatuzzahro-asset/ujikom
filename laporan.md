# Laporan Analisis & Optimasi Query MySQL — SIMRS

**Sistem Informasi Manajemen Rumah Sakit (SIMRS)**
**Database:** `simrs` | **Tools:** phpMyAdmin 5.2.1
**Tanggal Pengujian:** 5 Mei 2026
**Server:** localhost / 127.0.0.1

---

## 1. Query yang Diuji

### Query 1 — Performa Dokter per Bulan

```sql
SELECT
    d.nama AS nama_dokter,
    d.spesialisasi,
    COUNT(p.id_pendaftaran) AS total_kunjungan,
    SUM(t.total_biaya) AS total_pendapatan,
    AVG(rm.kepuasan) AS rata_rata_kepuasan
FROM dokter d
JOIN jadwal_dokter jd ON d.id_dokter = jd.id_dokter
JOIN pendaftaran p ON jd.id_jadwal = p.id_jadwal
LEFT JOIN rekam_medis rm ON p.id_pendaftaran = rm.id_pendaftaran
LEFT JOIN tagihan t ON p.id_pendaftaran = t.id_pendaftaran
WHERE MONTH(p.tgl_daftar) = 5
AND YEAR(p.tgl_daftar) = 2026
GROUP BY d.id_dokter;
```

### Query 2 — Riwayat Pasien Berdasarkan Nama

```sql
SELECT
    ps.nama,
    ps.no_rm,
    p.tgl_daftar,
    d.nama AS nama_dokter,
    rm.diagnosis
FROM pasien ps
JOIN pendaftaran p ON ps.id_pasien = p.id_pasien
JOIN jadwal_dokter jd ON p.id_jadwal = jd.id_jadwal
JOIN dokter d ON jd.id_dokter = d.id_dokter
LEFT JOIN rekam_medis rm ON p.id_pendaftaran = rm.id_pendaftaran
WHERE ps.nama LIKE '%a%';
```

### Query 3 — Tagihan Belum Lunas

```sql
SELECT
    ps.nama,
    p.id_pendaftaran,
    t.total_biaya,
    t.status_bayar
FROM pasien ps
JOIN pendaftaran p ON ps.id_pasien = p.id_pasien
JOIN tagihan t ON p.id_pendaftaran = t.id_pendaftaran
WHERE t.status_bayar != 'lunas'
ORDER BY t.total_biaya DESC;
```

---

## 2. Hasil EXPLAIN — Sebelum Optimasi (1:15 PM)

Data diambil langsung dari file `after.pdf` — phpMyAdmin sebelum index dibuat.

### 2.1 EXPLAIN Query 1 — BEFORE

| id | select_type | table | type    | possible_keys           | key      | key_len  | ref                  | rows | Extra                                              |
|----|-------------|-------|---------|-------------------------|----------|----------|----------------------|------|----------------------------------------------------|
| 1  | SIMPLE      | **p** | **ALL** | idx_pendaftaran_jadwal  | **NULL** | **NULL** | **NULL**             | **3** | **Using where; Using temporary; Using filesort**  |
| 1  | SIMPLE      | jd    | eq_ref  | PRIMARY,idx_jadwal_dokter | PRIMARY | 4        | simrs.p.id_jadwal    | 1    | Using where                                        |
| 1  | SIMPLE      | d     | eq_ref  | PRIMARY                 | PRIMARY  | 4        | simrs.jd.id_dokter   | 1    | *(null)*                                           |
| 1  | SIMPLE      | **rm**| **ALL** | idx_rm_pendaftaran      | **NULL** | **NULL** | **NULL**             | **2** | Using where; **Using join buffer (flat, BNL join)**|
| 1  | SIMPLE      | **t** | **ALL** | idx_tagihan_pendaftaran | **NULL** | **NULL** | **NULL**             | **2** | Using where; **Using join buffer (incremental, BNL join)** |

**Temuan kritis Query 1 (BEFORE):**
- **3 tabel full scan** — `p`, `rm`, `t` semua `type = ALL`
- `key = NULL` pada ketiga tabel — tidak ada index yang dipakai
- `Using temporary; Using filesort` — GROUP BY memaksa tabel sementara dan sort manual
- **2× BNL join** — `rm` pakai flat BNL, `t` pakai incremental BNL (lebih lambat dari flat BNL)
- **Root cause:** `MONTH(p.tgl_daftar)` dan `YEAR(p.tgl_daftar)` membungkus kolom dalam fungsi, sehingga B-Tree index pada `tgl_daftar` tidak dapat digunakan

### 2.2 EXPLAIN Query 2 — BEFORE

| id | select_type | table | type    | possible_keys                               | key      | key_len  | ref                    | rows | Extra                                     |
|----|-------------|-------|---------|---------------------------------------------|----------|----------|------------------------|------|-------------------------------------------|
| 1  | SIMPLE      | **p** | **ALL** | idx_pendaftaran_pasien,idx_pendaftaran_jadwal | **NULL** | **NULL** | **NULL**             | **3** | Using where                               |
| 1  | SIMPLE      | ps    | eq_ref  | PRIMARY,id_pasien                           | PRIMARY  | 4        | simrs.p.id_pasien      | 1    | Using where                               |
| 1  | SIMPLE      | jd    | eq_ref  | PRIMARY,idx_jadwal_dokter                   | PRIMARY  | 4        | simrs.p.id_jadwal      | 1    | Using where                               |
| 1  | SIMPLE      | d     | eq_ref  | PRIMARY                                     | PRIMARY  | 4        | simrs.jd.id_dokter     | 1    | *(null)*                                  |
| 1  | SIMPLE      | **rm**| **ALL** | idx_rm_pendaftaran                          | **NULL** | **NULL** | **NULL**             | **2** | Using where; **Using join buffer (flat, BNL join)** |

**Temuan kritis Query 2 (BEFORE):**
- **2 tabel full scan** — `p` dan `rm` dengan `type = ALL`
- Meski ada `idx_pendaftaran_pasien` dan `idx_pendaftaran_jadwal` di possible_keys, keduanya tidak dipakai
- **Root cause:** Filter `LIKE '%a%'` (wildcard di awal) pada `pasien` tidak bisa memanfaatkan B-Tree index apapun, memaksa join dimulai dari `pendaftaran` secara full scan
- `rm` bergabung via BNL join (flat)

### 2.3 EXPLAIN Query 3 — BEFORE

| id | select_type | table | type    | possible_keys              | key      | key_len  | ref                    | rows | Extra                            |
|----|-------------|-------|---------|----------------------------|----------|----------|------------------------|------|----------------------------------|
| 1  | SIMPLE      | **t** | **ALL** | idx_tagihan_pendaftaran    | **NULL** | **NULL** | **NULL**               | **2** | **Using where; Using filesort** |
| 1  | SIMPLE      | p     | eq_ref  | PRIMARY,idx_pendaftaran_pasien | PRIMARY | 4     | simrs.t.id_pendaftaran | 1    | Using where                      |
| 1  | SIMPLE      | ps    | eq_ref  | PRIMARY,id_pasien           | PRIMARY  | 4        | simrs.p.id_pasien      | 1    | *(null)*                         |

**Temuan kritis Query 3 (BEFORE):**
- **1 tabel full scan** — `t` (tagihan) dengan `type = ALL`, rows = 2
- `idx_tagihan_pendaftaran` ada di possible_keys tapi `key = NULL`
- **Root cause:** Operator negasi `!= 'lunas'` tidak efisien untuk index; MySQL memilih full scan
- `Using filesort` — `ORDER BY t.total_biaya DESC` tanpa index pada `total_biaya` memaksa sort eksternal

---

## 3. Identifikasi Query Paling Bermasalah

### Ranking Berdasarkan Output EXPLAIN BEFORE

| Rank | Query | Tabel type=ALL | key=NULL | Extra Berbahaya | Severity |
|------|-------|---------------|----------|-----------------|----------|
| 🔴 **#1** | **Query 1** | **3 tabel** (p, rm, t) | 3 kolom | Using temporary + filesort + **flat BNL + incremental BNL** | **KRITIS** |
| 🟠 **#2** | **Query 2** | **2 tabel** (p, rm) | 2 kolom | BNL join (flat) | TINGGI |
| 🟡 **#3** | **Query 3** | **1 tabel** (t) | 1 kolom | Using filesort | SEDANG |

### Query Paling Bermasalah: **Query 1**

Query 1 adalah yang paling bermasalah karena memiliki **semua indikator masalah sekaligus**:
1. Full scan pada 3 tabel secara bersamaan (`p`, `rm`, `t`)
2. Dua jenis BNL join — *flat* (pada `rm`) dan *incremental* (pada `t`), yang terakhir lebih lambat
3. `Using temporary; Using filesort` yang muncul bersamaan menandakan GROUP BY dan ORDER BY tidak dapat memanfaatkan index
4. Index-index yang ada (`idx_pendaftaran_jadwal`, `idx_rm_pendaftaran`, `idx_tagihan_pendaftaran`) semuanya diabaikan

---

## 4. Slow Query Log

### Konfigurasi Slow Query Log

```sql
-- Aktifkan slow query log
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL slow_query_log_file = '401-10-slow.log';
SET GLOBAL long_query_time = 1;
SET GLOBAL log_queries_not_using_indexes = 'ON';

-- Verifikasi
SHOW VARIABLES LIKE 'slow_query%';
SHOW VARIABLES LIKE 'long_query_time';
```

### Catatan Slow Log Setelah Menjalankan query_test.sql

Dengan flag `log_queries_not_using_indexes = ON`, ketiga query otomatis tercatat karena semua memiliki `key = NULL` pada tabel utamanya. Pada database produksi skala penuh, estimasi dampak:

| Query | Rows Examined (Estimasi Produksi) | Query_time (Estimasi) |
|-------|-----------------------------------|----------------------|
| Query 1 | ~500K × 3 join = **>1.000.000** | **10–30 detik** |
| Query 2 | ~500K × 2 join = **>500.000** | **5–15 detik** |
| Query 3 | ~500.000 (tagihan) | **3–10 detik** |

---

## 5. Index yang Dibuat

Berdasarkan analisis EXPLAIN BEFORE, berikut index yang ditambahkan:

```sql
-- Untuk Query 1: perbaiki join rekam_medis dan tagihan
-- (index pada tgl_daftar tidak cukup selama fungsi MONTH/YEAR tetap digunakan)
ALTER TABLE rekam_medis
    ADD INDEX idx_rm_id_pendaftaran (id_pendaftaran);

ALTER TABLE tagihan
    ADD INDEX idx_tagihan_id_pendaftaran (id_pendaftaran);

-- Untuk Query 2: perbaiki join pendaftaran
ALTER TABLE pendaftaran
    ADD INDEX idx_pendaftaran_pasien_jadwal (id_pasien, id_jadwal);

-- Untuk Query 3: composite index untuk filter + sort
ALTER TABLE tagihan
    ADD INDEX idx_tagihan_status_biaya (status_bayar, total_biaya);
```

---

## 6. Hasil EXPLAIN — Setelah Optimasi (1:23 PM)

Data diambil langsung dari file `sebelum.pdf` — phpMyAdmin setelah index dibuat.

### 6.1 EXPLAIN Query 1 — AFTER

| id | select_type | table | type      | possible_keys           | key            | key_len | ref                    | rows | Extra                      |
|----|-------------|-------|-----------|-------------------------|----------------|---------|------------------------|------|----------------------------|
| 1  | SIMPLE      | **jd**| **index** | PRIMARY,id_dokter       | **id_dokter**  | **5**   | **NULL**               | **10** | **Using where; Using index** |
| 1  | SIMPLE      | d     | eq_ref    | PRIMARY                 | PRIMARY        | 4       | simrs.jd.id_dokter     | 1    | *(null)*                   |
| 1  | SIMPLE      | **p** | **ref**   | id_jadwal               | **id_jadwal**  | **5**   | simrs.jd.id_jadwal     | **4** | Using where                |
| 1  | SIMPLE      | **rm**| **ref**   | id_pendaftaran          | **id_pendaftaran** | **5** | simrs.p.id_pendaftaran | **1** | *(null)*                   |
| 1  | SIMPLE      | **t** | **ref**   | id_pendaftaran          | **id_pendaftaran** | **5** | simrs.p.id_pendaftaran | **1** | *(null)*                   |

**Perubahan signifikan Query 1 (AFTER):**
- `p`: `ALL → ref` — index `id_jadwal` digunakan
- `rm`: `ALL → ref` — index `id_pendaftaran` digunakan
- `t`: `ALL → ref` — index `id_pendaftaran` digunakan
- `Using temporary; Using filesort` **hilang sepenuhnya**
- Kedua BNL join **hilang sepenuhnya**
- `jd` sekarang menjadi tabel driver dengan `type = index` dan `Using index` (covering index)

### 6.2 EXPLAIN Query 2 — AFTER

| id | select_type | table | type      | possible_keys           | key            | key_len | ref                    | rows | Extra                      |
|----|-------------|-------|-----------|-------------------------|----------------|---------|------------------------|------|----------------------------|
| 1  | SIMPLE      | **jd**| **index** | PRIMARY,id_dokter       | **id_dokter**  | **5**   | **NULL**               | **10** | **Using where; Using index** |
| 1  | SIMPLE      | d     | eq_ref    | PRIMARY                 | PRIMARY        | 4       | simrs.jd.id_dokter     | 1    | *(null)*                   |
| 1  | SIMPLE      | **p** | **ref**   | id_pasien,id_jadwal     | **id_jadwal**  | **5**   | simrs.jd.id_jadwal     | **4** | Using where                |
| 1  | SIMPLE      | **ps**| **eq_ref**| PRIMARY                 | **PRIMARY**    | **4**   | simrs.p.id_pasien      | **1** | Using where                |
| 1  | SIMPLE      | **rm**| **ref**   | id_pendaftaran          | **id_pendaftaran** | **5** | simrs.p.id_pendaftaran | **1** | *(null)*                   |

**Perubahan signifikan Query 2 (AFTER):**
- `p`: `ALL → ref` — menggunakan `id_jadwal`
- `rm`: `ALL → ref` — menggunakan `id_pendaftaran`
- BNL join pada `rm` **hilang sepenuhnya**
- `ps` masih `eq_ref` dengan `Using where` — filter `LIKE '%a%'` tetap dieksekusi row-by-row, namun sekarang hanya pada baris yang lolos join (lebih efisien dari sebelumnya)
- `LIKE '%a%'` (wildcard di awal) tidak bisa sepenuhnya dioptimalkan dengan B-Tree index — FULLTEXT index masih diperlukan untuk solusi optimal

### 6.3 EXPLAIN Query 3 — AFTER

| id | select_type | table | type    | possible_keys              | key      | key_len  | ref                    | rows   | Extra                            |
|----|-------------|-------|---------|----------------------------|----------|----------|------------------------|--------|----------------------------------|
| 1  | SIMPLE      | **t** | **ALL** | id_pendaftaran             | **NULL** | **NULL** | **NULL**               | **40** | **Using where; Using filesort** |
| 1  | SIMPLE      | p     | eq_ref  | PRIMARY,idx_pendaftaran_pasien | PRIMARY | 4    | simrs.t.id_pendaftaran | 1      | Using where                      |
| 1  | SIMPLE      | ps    | eq_ref  | PRIMARY                    | PRIMARY  | 4        | simrs.p.id_pasien      | 1      | *(null)*                         |

**Catatan penting Query 3 (AFTER):**
- `t` (tagihan): masih `type = ALL` dengan `key = NULL`
- `Using where; Using filesort` masih ada
- **rows justru naik dari 2 → 40** — ini menandakan data bertambah DAN index baru belum cukup menyelesaikan masalah
- **Root cause belum tertangani:** Operator `!= 'lunas'` tidak dapat memanfaatkan B-Tree index; diperlukan perubahan query dan composite index yang tepat

---

## 7. Perbandingan Before vs After (Data Asli)

### 7.1 Query 1

| Metrik | BEFORE (1:15 PM) | AFTER (1:23 PM) | Status |
|--------|-----------------|-----------------|--------|
| type — p (pendaftaran) | **ALL** | **ref** |  Membaik |
| type — rm (rekam_medis) | **ALL** | **ref** |  Membaik |
| type — t (tagihan) | **ALL** | **ref** |  Membaik |
| key — p | **NULL** | **id_jadwal** |  Index dipakai |
| key — rm | **NULL** | **id_pendaftaran** |  Index dipakai |
| key — t | **NULL** | **id_pendaftaran** |  Index dipakai |
| rows — p | **3** | **4** | — |
| rows — jd | — | **10** | (driver table baru) |
| Using temporary | **Ada** | **Tidak ada** |  Hilang |
| Using filesort | **Ada** | **Tidak ada** |  Hilang |
| BNL join | **2× (flat + incremental)** | **Tidak ada** |  Hilang |
| Tabel full scan | **3** | **0** |  **-100%** |
| **Estimasi speedup** | — | — | **Sangat signifikan** |

### 7.2 Query 2

| Metrik | BEFORE (1:15 PM) | AFTER (1:23 PM) | Status |
|--------|-----------------|-----------------|--------|
| type — p (pendaftaran) | **ALL** | **ref** |  Membaik |
| type — rm (rekam_medis) | **ALL** | **ref** |  Membaik |
| type — ps (pasien) | eq_ref | eq_ref | — (tetap) |
| key — p | **NULL** | **id_jadwal** |  Index dipakai |
| key — rm | **NULL** | **id_pendaftaran** |  Index dipakai |
| rows — p | **3** | **4** | — |
| rows — jd | — | **10** | (driver table baru) |
| BNL join | **1× (flat)** | **Tidak ada** |  Hilang |
| Filter LIKE '%a%' | Full scan driven | Row-level setelah join |  Masih ada |
| Tabel full scan | **2** | **0** |  **-100%** |
| **Estimasi speedup** | — | — | **Signifikan** |

### 7.3 Query 3

| Metrik | BEFORE (1:15 PM) | AFTER (1:23 PM) | Status |
|--------|-----------------|-----------------|--------|
| type — t (tagihan) | **ALL** | **ALL** |  **Tidak berubah** |
| key — t | **NULL** | **NULL** |  **Tidak berubah** |
| rows — t | **2** | **40** |  **Bertambah** |
| Using filesort | **Ada** | **Ada** |  **Tidak berubah** |
| Tabel full scan | **1** | **1** |  **Tidak berubah** |
| **Status optimasi** | — | — | **Belum terselesaikan** |

---

## 8. Analisis Mendalam: Mengapa Query 3 Belum Membaik?

Query 3 adalah **satu-satunya query yang tidak mengalami perbaikan** setelah penambahan index. Ada dua penyebab:

### Penyebab 1 — Operator Negasi (`!=`)

```sql
WHERE t.status_bayar != 'lunas'
```

MySQL tidak dapat menggunakan B-Tree index untuk kondisi `!=` secara efisien. Index B-Tree bekerja dengan cara mencari nilai dari titik awal lalu berjalan searah. Kondisi "tidak sama dengan" berarti MySQL harus mengambil **semua baris kecuali satu nilai** — hampir setara dengan full scan, sehingga optimizer memilih untuk skip index sama sekali.

### Penyebab 2 — Tidak Ada Index pada `total_biaya`

```sql
ORDER BY t.total_biaya DESC
```

Tidak ada index pada kolom `total_biaya`, sehingga MySQL harus melakukan sorting setelah semua data dikumpulkan (`Using filesort`). Rows yang meningkat dari 2 ke 40 memperparah cost sort ini.

### Solusi yang Diperlukan

```sql
-- Langkah 1: Tambah composite index yang tepat
ALTER TABLE tagihan
    ADD INDEX idx_tagihan_status_biaya (status_bayar, total_biaya DESC);

-- Langkah 2 (WAJIB): Ubah query untuk menghindari operator negasi
-- BEFORE (bermasalah):
WHERE t.status_bayar != 'lunas'

-- AFTER (direkomendasikan):
WHERE t.status_bayar IN ('belum', 'cicil')
-- Atau sesuaikan dengan nilai ENUM yang ada di tabel tagihan
```

Dengan perubahan ini, tipe akses pada `t` akan berubah dari `ALL` menjadi `range`, dan `Using filesort` akan hilang karena MySQL dapat memanfaatkan urutan data dalam composite index.

---

## 9. Ringkasan Keseluruhan

### Status Optimasi per Query

| Query | Masalah Utama | Status Setelah Index | Tindakan Lanjutan |
|-------|--------------|---------------------|-------------------|
| **Query 1** | 3× full scan, 2× BNL join, filesort |  **TERSELESAIKAN** — semua index digunakan | Pertimbangkan ubah `MONTH/YEAR` → `BETWEEN` untuk hasil optimal |
| **Query 2** | 2× full scan, BNL join |  **SEBAGIAN** — full scan & BNL hilang, LIKE masih bermasalah | Tambah FULLTEXT index untuk `LIKE '%a%'` |
| **Query 3** | 1× full scan, filesort |  **BELUM TERSELESAIKAN** — rows malah naik 2→40 | Ubah `!=` ke `IN(...)` + composite index `(status_bayar, total_biaya)` |

### Dampak Index yang Sudah Dibuat

Index yang berhasil membantu:
- `id_dokter` pada `jadwal_dokter` → menjadi tabel driver dengan `Using index` (covering), menghilangkan `Using temporary` dan `Using filesort` pada Query 1
- `id_pendaftaran` pada `rekam_medis` → mengubah BNL join flat menjadi `ref` lookup
- `id_pendaftaran` pada `tagihan` (untuk Query 1) → mengubah BNL join incremental menjadi `ref` lookup
- `id_jadwal` pada `pendaftaran` → mengubah full scan menjadi `ref` lookup

Index yang belum efektif:
- Index apapun pada `tagihan` untuk Query 3 — karena `!= 'lunas'` tidak kompatibel dengan B-Tree index

---

*Database: simrs @ localhost/127.0.0.1*
