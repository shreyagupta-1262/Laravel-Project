
<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = "localhost";
$username = "root";
$password = "shreya";
$database = "horizon_bank";
$port = 3306;

try {
    $conn = new mysqli(
        $host,
        $username,
        $password,
        $database,
        $port
    );

    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    exit(
        "Database connection failed. Check MySQL service, " .
        "credentials and database configuration.\n"
    );
}
?>
