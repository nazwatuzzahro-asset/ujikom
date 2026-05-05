<?php

/**
 * Class DatabaseConnection
 * Menangani koneksi database menggunakan PDO
 */
class DatabaseConnection
{
    private static $host = "localhost";
    private static $db_name = "simrs";
    private static $username = "root";
    private static $password = "";

    /**
     * Membuat koneksi PDO ke database
     *
     * @return PDO
     */
    public static function getConnection(): PDO
    {
        try {
            $conn = new PDO(
                "mysql:host=" . self::$host . ";dbname=" . self::$db_name,
                self::$username,
                self::$password
            );

            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $conn;

        } catch (PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }
}