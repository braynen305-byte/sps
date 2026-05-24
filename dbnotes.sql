CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    firstname VARCHAR(30) NOT NULL,
    middlename VARCHAR(30) NULL,
    lastname VARCHAR(30) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);