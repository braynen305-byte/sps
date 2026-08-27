<?php
require_once 'includes/dbh.inc.php';

echo "<h2>Setting up database...</h2>";

$sqlStaff = "CREATE TABLE IF NOT EXISTS `staff` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `firstname` VARCHAR(30) NOT NULL,
    `middlename` VARCHAR(30) DEFAULT NULL,
    `lastname` VARCHAR(30) NOT NULL,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `role` ENUM('admin','office','staff','technician') NOT NULL DEFAULT 'staff',
    `password` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET=utf8mb4";

$sqlCustomers = "CREATE TABLE IF NOT EXISTS `customers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(20),
    `email` VARCHAR(100),
    `address` VARCHAR(255),
    `city` VARCHAR(100),
    `state` VARCHAR(50),
    `zip` VARCHAR(10),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET=utf8mb4";

$sqlWorkorders = "CREATE TABLE IF NOT EXISTS `workorders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT,
    `client_name` VARCHAR(100) NOT NULL,
    `client_phone` VARCHAR(20),
    `location` VARCHAR(255) NOT NULL,
    `order_date` DATE NOT NULL,
    `expected_start_date` DATE,
    `expected_end_date` DATE,
    `requested_work` LONGTEXT,
    `additional_comments` LONGTEXT,
    `vessel_vin` VARCHAR(100),
    `vessel_hours` DECIMAL(10, 2),
    `labor_time` VARCHAR(100),
    `parts_cost` DECIMAL(10, 2),
    `chargeable_to` VARCHAR(255),
    `order_received_by` INT,
    `work_performed_by` INT,
    `permission_anytime` TINYINT DEFAULT 0,
    `permission_date` DATE,
    `permission_time` TIME,
    `entry_date` DATE,
    `time_entered` TIME,
    `time_departed` TIME,
    `work_description` LONGTEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET=utf8mb4";

try {
    $conn->exec($sqlStaff);
    echo "✓ Staff table created/verified<br>";
} catch (PDOException $e) {
    echo "✗ Staff error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "<br>";
}

try {
    $conn->exec($sqlCustomers);
    echo "✓ Customers table created/verified<br>";
} catch (PDOException $e) {
    echo "✗ Customers error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "<br>";
}

try {
    $conn->exec($sqlWorkorders);
    echo "✓ Workorders table created/verified<br>";
} catch (PDOException $e) {
    echo "✗ Workorders error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "<br>";
}

echo "<p><a href='/sps/check_database.php'>Verify Tables</a></p>";
?>