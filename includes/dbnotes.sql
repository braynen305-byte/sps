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
