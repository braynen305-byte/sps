<?php
require_once 'includes/dbh.inc.php';

echo "<h2>Setting up database...</h2>";

$sqlStaff = "CREATE TABLE IF NOT EXISTS `staff` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `firstname` VARCHAR(30) NOT NULL,
    `middlename` VARCHAR(30) DEFAULT NULL,
    `lastname` VARCHAR(30) NOT NULL,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `phone_number` VARCHAR(30) DEFAULT NULL,
    `whatsapp_number` VARCHAR(30) DEFAULT NULL,
    `role` ENUM('admin','office','staff','technician','customer') NOT NULL DEFAULT 'staff',
    `password` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET=utf8mb4";

$sqlCustomers = "CREATE TABLE IF NOT EXISTS `customers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `company_name` VARCHAR(150) DEFAULT NULL,
    `company_contact` VARCHAR(150) DEFAULT NULL,
    `phone` VARCHAR(20),
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) DEFAULT NULL,
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
    `status` VARCHAR(50) NOT NULL DEFAULT 'Open',
    `priority` VARCHAR(20) NOT NULL DEFAULT 'Normal',
    `order_number` VARCHAR(100) DEFAULT NULL,
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

$sqlWorkPerformedEntries = "CREATE TABLE IF NOT EXISTS `work_performed_entries` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `workorder_id` INT NOT NULL,
    `performed_date` DATE NOT NULL,
    `performed_time` TIME NOT NULL,
    `description` LONGTEXT NOT NULL,
    `added_by` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(`workorder_id`)
) ENGINE = InnoDB DEFAULT CHARSET=utf8mb4";

$sqlSupport = "CREATE TABLE IF NOT EXISTS `customer_support_messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT NOT NULL,
    `message` LONGTEXT NOT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'Open',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX(`customer_id`)
) ENGINE = InnoDB DEFAULT CHARSET=utf8mb4";

$sqlCustomerServiceRequests = "CREATE TABLE IF NOT EXISTS `customer_service_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT NOT NULL,
    `request_number` VARCHAR(30) DEFAULT NULL,
    `service_type` VARCHAR(100) NOT NULL,
    `location` VARCHAR(255) NOT NULL,
    `preferred_date` DATE DEFAULT NULL,
    `customer_phone` VARCHAR(30) DEFAULT NULL,
    `urgency` VARCHAR(30) NOT NULL DEFAULT 'Normal',
    `equipment_details` VARCHAR(255) DEFAULT NULL,
    `problem_summary` VARCHAR(255) DEFAULT NULL,
    `description` LONGTEXT NOT NULL,
    `special_instructions` LONGTEXT DEFAULT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'Pending',
    `approved_workorder_id` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX(`customer_id`)
) ENGINE = InnoDB DEFAULT CHARSET=utf8mb4";

try {
    $conn->exec($sqlStaff);
    $conn->exec("ALTER TABLE staff ADD COLUMN IF NOT EXISTS phone_number VARCHAR(30) NULL AFTER email");
    $conn->exec("ALTER TABLE staff ADD COLUMN IF NOT EXISTS whatsapp_number VARCHAR(30) NULL AFTER phone_number");
    echo "✓ Staff table created/verified<br>";
} catch (PDOException $e) {
    echo "✗ Staff error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "<br>";
}

try {
    $conn->exec($sqlCustomers);
    $conn->exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS password VARCHAR(255) NULL AFTER email");
    $conn->exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS company_name VARCHAR(150) NULL AFTER name");
    $conn->exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS company_contact VARCHAR(150) NULL AFTER company_name");
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

try {
    $conn->exec($sqlWorkPerformedEntries);
    echo "✓ Work performed entries table created/verified<br>";
} catch (PDOException $e) {
    echo "✗ Work performed entries error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "<br>";
}

try {
    $conn->exec($sqlSupport);
    echo "✓ Customer support table created/verified<br>";
} catch (PDOException $e) {
    echo "✗ Customer support error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "<br>";
}

try {
    $conn->exec($sqlCustomerServiceRequests);
    echo "✓ Customer service requests table created/verified<br>";
} catch (PDOException $e) {
    echo "✗ Customer service requests error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "<br>";
}

try {
    $conn->exec("CREATE TABLE IF NOT EXISTS notification_preferences (
        id INT AUTO_INCREMENT PRIMARY KEY,
        staff_id INT NOT NULL UNIQUE,
        email_enabled TINYINT(1) NOT NULL DEFAULT 1,
        whatsapp_enabled TINYINT(1) NOT NULL DEFAULT 1,
        sms_enabled TINYINT(1) NOT NULL DEFAULT 0,
        preferred_channel VARCHAR(20) NOT NULL DEFAULT 'email',
        phone_number VARCHAR(30) DEFAULT NULL,
        whatsapp_number VARCHAR(30) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $conn->exec("CREATE TABLE IF NOT EXISTS notification_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        staff_id INT NOT NULL,
        category VARCHAR(80) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        message LONGTEXT NOT NULL,
        link VARCHAR(255) DEFAULT NULL,
        channel VARCHAR(20) NOT NULL,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(30) NOT NULL DEFAULT 'queued',
        INDEX(staff_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $conn->exec("CREATE TABLE IF NOT EXISTS customer_notification_preferences (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL UNIQUE,
        email_enabled TINYINT(1) NOT NULL DEFAULT 1,
        whatsapp_enabled TINYINT(1) NOT NULL DEFAULT 1,
        sms_enabled TINYINT(1) NOT NULL DEFAULT 0,
        preferred_channel VARCHAR(20) NOT NULL DEFAULT 'email',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✓ Notification preference tables created/verified<br>";
} catch (PDOException $e) {
    echo "✗ Notification table setup error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "<br>";
}

echo "<p><a href='/sps/check_database.php'>Verify Tables</a></p>";
?>