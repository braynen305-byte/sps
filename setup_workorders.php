<?php
require_once 'includes/dbh.inc.php';

$sql = "CREATE TABLE IF NOT EXISTS `workorders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `asset_id` INT DEFAULT NULL,
    `client_name` VARCHAR(100) NOT NULL,
    `client_phone` VARCHAR(20),
    `location` VARCHAR(255) NOT NULL,
    `work_location` VARCHAR(30) DEFAULT NULL,
    `service_call_fee` DECIMAL(10, 2) NOT NULL DEFAULT 0,
    `order_date` DATE NOT NULL,
    `service_type` VARCHAR(100) DEFAULT NULL,
    `equipment_details` VARCHAR(255) DEFAULT NULL,
    `special_instructions` LONGTEXT DEFAULT NULL,
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
    `permission_anytime_with_time` TINYINT(1) NOT NULL DEFAULT 0,
    `permission_date` DATE,
    `permission_time` TIME,
    `entry_date` DATE,
    `time_entered` TIME,
    `time_departed` TIME,
    `permission_time_relation` VARCHAR(10) DEFAULT NULL,
    `work_description` LONGTEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`order_received_by`) REFERENCES `staff`(`id`),
    FOREIGN KEY (`work_performed_by`) REFERENCES `staff`(`id`)
) ENGINE = InnoDB DEFAULT CHARSET=utf8mb4";

try {
    $conn->exec($sql);
    $conn->exec("CREATE TABLE IF NOT EXISTS `workorder_property_entry_logs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `workorder_id` INT NOT NULL,
        `entry_date` DATE NOT NULL,
        `time_entered` TIME DEFAULT NULL,
        `time_departed` TIME DEFAULT NULL,
        `logged_by` INT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(`workorder_id`, `entry_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ Workorders and property entry log tables created successfully!";
} catch (PDOException $e) {
    echo "Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
?>