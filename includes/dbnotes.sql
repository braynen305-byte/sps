CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    first_name VARCHAR(30) NOT NULL,
    middle_name VARCHAR(30) NULL,
    last_name VARCHAR(30) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    gender VARCHAR(10) NOT NULL,
    date_of_birth DATE NOT NULL,
    telephone VARCHAR(20) NOT NULL,
    residence VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


CREATE TABLE `sps`.`users` (
    `id` SERIAL NOT NULL AUTO_INCREMENT , 
    `firstname` VARCHAR(50) NOT NULL , 
    `middlename` VARCHAR(50) NULL , 
    `lastname` VARCHAR(50) NOT NULL , 
    `email` VARCHAR(100) NOT NULL , 
    `password_hash` VARCHAR(255) NOT NULL , 
    `date_of_birth` DATE NOT NULL , 
    `telephone` VARCHAR(20) NOT NULL , 
    `residence` VARCHAR(255) NOT NULL , 
    `gender` VARCHAR(10) NOT NULL , 
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP , 
    PRIMARY KEY (`id`)) ENGINE = InnoDB;





$sql = "INSERT INTO staff (`firstname`, `middlename`, `lastname`, `email`, `gender`, `date_of_birth`, `telephone`, `residence`, `password`) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";


CREATE TABLE `workorders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
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
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`order_received_by`) REFERENCES `staff`(`id`),
    FOREIGN KEY (`work_performed_by`) REFERENCES `staff`(`id`)
) ENGINE = InnoDB DEFAULT CHARSET=utf8mb4;