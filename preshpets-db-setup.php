-- ============================================================
-- Presh Pets — MySQL Database Setup
-- Run this in phpMyAdmin > SQL tab
-- ============================================================

CREATE DATABASE IF NOT EXISTS preshpets CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE preshpets;

-- Users table (customers)
CREATE TABLE IF NOT EXISTS users (
  id          VARCHAR(32)  PRIMARY KEY,
  name        VARCHAR(150) NOT NULL,
  email       VARCHAR(200) NOT NULL UNIQUE,
  phone       VARCHAR(30)  DEFAULT '',
  address     TEXT         DEFAULT '',
  password    VARCHAR(255) NOT NULL,
  created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP
);

-- Orders table
CREATE TABLE IF NOT EXISTS orders (
  id             VARCHAR(32)   PRIMARY KEY,
  user_id        VARCHAR(32)   DEFAULT '',
  customer_name  VARCHAR(150)  NOT NULL,
  phone          VARCHAR(30)   DEFAULT '',
  address        TEXT          DEFAULT '',
  items          JSON          NOT NULL,
  total          DECIMAL(12,2) NOT NULL DEFAULT 0,
  delivery_fee   DECIMAL(12,2) NOT NULL DEFAULT 0,
  fulfillment    VARCHAR(20)   DEFAULT 'delivery',
  status         VARCHAR(60)   DEFAULT 'Order Received',
  status_history JSON          DEFAULT '[]',
  paystack_ref   VARCHAR(100)  DEFAULT '',
  created_at     DATETIME      DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id),
  INDEX (status),
  INDEX (created_at)
);

-- Products table
CREATE TABLE IF NOT EXISTS products (
  id          INT          AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(200) NOT NULL,
  category    VARCHAR(50)  DEFAULT 'Accessories',
  price       DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock       INT          NOT NULL DEFAULT 0,
  description TEXT         DEFAULT '',
  image       LONGTEXT     DEFAULT '',
  created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP
);