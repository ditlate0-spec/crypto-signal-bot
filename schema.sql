-- ============================================
-- Crypto Signal Bot — Database Schema
-- ============================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS `volta`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_general_ci;

USE `volta`;

-- ============================================
-- KF-СИГНАЛЫ
-- ============================================

CREATE TABLE `oth_1d` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `Nazvanie` VARCHAR(50) DEFAULT NULL,
    `kf` FLOAT DEFAULT NULL,
    `data` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_symbol_day` (`Nazvanie`, `data`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `oth_1h` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `Nazvanie` VARCHAR(50) DEFAULT NULL,
    `kf` FLOAT DEFAULT NULL,
    `data` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_symbol_day` (`Nazvanie`, `data`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `oth_15m` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `Nazvanie` VARCHAR(50) DEFAULT NULL,
    `kf` FLOAT DEFAULT NULL,
    `data` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_symbol_day` (`Nazvanie`, `data`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================
-- СЛИВ ПО ТРЕНДУ
-- ============================================

CREATE TABLE `old_bot_signals_1d` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `symbol` VARCHAR(20) DEFAULT NULL,
    `kf` FLOAT DEFAULT NULL,
    `text` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_symbol_day` (`symbol`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `old_bot_signals_1h` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `symbol` VARCHAR(20) DEFAULT NULL,
    `kf` FLOAT DEFAULT NULL,
    `text` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_symbol_day` (`symbol`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `old_bot_signals_15m` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `symbol` VARCHAR(20) DEFAULT NULL,
    `kf` FLOAT DEFAULT NULL,
    `text` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_symbol_day` (`symbol`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================
-- НЕЙРОСЕТЬ
-- ============================================

CREATE TABLE `neural_predictions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `symbol` VARCHAR(20) NOT NULL,
    `timeframe` VARCHAR(10) NOT NULL,
    `signal` VARCHAR(10) NOT NULL,
    `confidence` FLOAT NOT NULL,
    `change_percent` FLOAT NOT NULL,
    `current_price` FLOAT NOT NULL,
    `future_price` FLOAT NOT NULL,
    `candles_analyzed` INT(11) NOT NULL,
    `json_data` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `symbol_timeframe` (`symbol`, `timeframe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================
-- ДЕДУПЛИКАЦИЯ TELEGRAM
-- ============================================

CREATE TABLE `telegram_sent` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `hash` VARCHAR(100) NOT NULL,
    `sent_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_hash` (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;