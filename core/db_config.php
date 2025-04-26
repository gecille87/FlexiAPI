<?php
// Deny direct access
if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    http_response_code(403);
    exit("Access denied.");
}

class DBConnection
{
    private static $instance = null;
    private static $servername = "localhost";
    private static $username = "";
    private static $password = "";
    private static $defaultDB = "db_name";
    private static $currentDB = null;

    private function __construct() {}
    private function __clone() {}


    public static function getCurrentDatabase()
    {
        return self::$currentDB ?? self::$defaultDB;
    }

    // Check if database exists before connecting
    private static function databaseExists($dbName)
    {
        $conn = new mysqli(self::$servername, self::$username, self::$password);
        if ($conn->connect_error) {
            throw new Exception("Pre-check connection failed: " . $conn->connect_error);
        }

        $stmt = $conn->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
        $stmt->bind_param("s", $dbName);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        $conn->close();

        return $exists;
    }

    public static function setDatabase($dbName)
    {
        if (!self::databaseExists($dbName)) {
            throw new Exception("Database '$dbName' does not exist.");
        }

        if ($dbName !== self::$currentDB) {
            self::$currentDB = $dbName;
            self::$instance = null;
        }
    }

    public static function getConnection()
    {
        if (self::$currentDB === null) {
            self::$currentDB = self::$defaultDB;
        }

        if (self::$instance === null) {
            self::$instance = new mysqli(
                self::$servername,
                self::$username,
                self::$password,
                self::$currentDB
            );

            if (self::$instance->connect_error) {
                throw new Exception("Connection failed: " . self::$instance->connect_error);
            }
        }

        return self::$instance;
    }
}
