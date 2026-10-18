CREATE DATABASE IF NOT EXISTS game_project;
USE game_project;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    minecraft_nick VARCHAR(32) UNIQUE NOT NULL,   -- ник в майнкрафт (логин)
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    has_pass BOOLEAN DEFAULT FALSE,                -- куплена ли проходка
    subscription_days INT DEFAULT 0,               -- дней подписки осталось
    remember_token VARCHAR(255) NULL,              -- для запоминания устройства
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Индексы для быстрого поиска
CREATE INDEX idx_minecraft_nick ON users(minecraft_nick);
CREATE INDEX idx_email ON users(email);
CREATE INDEX idx_remember_token ON users(remember_token);