-- TableFlow v2 | schema.sql | MySQL 8.0.16+ (CHECK enforced)
-- Chon database rong truoc khi chay. Khong DROP hay tu tao database.
-- Chay mot lan; neu da co bang, dung va kiem tra migration.
-- Ung dung phai cung cap created_at, updated_at theo UTC.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE branches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    branch_code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    city VARCHAR(100) NOT NULL,
    address VARCHAR(255) NULL,
    phone VARCHAR(20) NULL,
    opening_time TIME NOT NULL,
    closing_time TIME NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CHECK (opening_time >= '00:00:00' AND closing_time <= '23:59:59' AND opening_time < closing_time),
    CHECK (is_active IN (0,1)),
    INDEX idx_branches_city_active (city,is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    role ENUM('staff','admin') NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    CHECK ((role = 'staff' AND branch_id IS NOT NULL) OR (role = 'admin' AND branch_id IS NULL)),
    CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE restaurant_areas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    area_type ENUM('outdoor','indoor','vip','quiet') NOT NULL,
    description TEXT NULL,
    image_url VARCHAR(255) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    UNIQUE KEY uq_area_branch_type (branch_id,area_type),
    UNIQUE KEY uq_area_scope (id,branch_id,area_type),
    CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE restaurant_tables (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    area_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    area_type ENUM('outdoor','indoor','vip','quiet') NOT NULL,
    table_number VARCHAR(30) NOT NULL,
    capacity TINYINT UNSIGNED NOT NULL,
    physical_status ENUM('available','occupied','cleaning','out_of_service') NOT NULL DEFAULT 'available',
    position_x DECIMAL(5,2) NOT NULL,
    position_y DECIMAL(5,2) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    FOREIGN KEY (area_id,branch_id,area_type) REFERENCES restaurant_areas(id,branch_id,area_type),
    UNIQUE KEY uq_table_branch_number (branch_id,table_number),
    CHECK (capacity IN (2,4,6,8)),
    CHECK (area_type <> 'quiet' OR capacity <= 4),
    CHECK (position_x BETWEEN 0 AND 100),
    CHECK (position_y BETWEEN 0 AND 100),
    INDEX idx_tables_area_status_capacity (area_id, physical_status, capacity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE reservations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reservation_code VARCHAR(40) NOT NULL UNIQUE,
    table_id BIGINT UNSIGNED NOT NULL,
    customer_name VARCHAR(120) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    party_size INT UNSIGNED NOT NULL,
    start_at DATETIME(6) NOT NULL,
    end_at DATETIME(6) NOT NULL,
    status ENUM('pending','confirmed','checked_in','completed','cancelled') NOT NULL DEFAULT 'pending',
    note VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    FOREIGN KEY (table_id) REFERENCES restaurant_tables(id),
    CHECK (party_size BETWEEN 1 AND 8),
    CHECK (end_at > start_at),
    UNIQUE KEY uq_reservation_table (id,table_id),
    INDEX idx_reservations_overlap (table_id, status, start_at, end_at),
    INDEX idx_reservations_status_start (status, start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE menu_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE cuisines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE menu_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    category_id BIGINT UNSIGNED NOT NULL,
    cuisine_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    price DECIMAL(12,0) NOT NULL,
    image_url VARCHAR(255) NULL,
    is_available BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    FOREIGN KEY (category_id) REFERENCES menu_categories(id),
    FOREIGN KEY (cuisine_id) REFERENCES cuisines(id),
    CHECK (price >= 0),
    CHECK (is_available IN (0,1)),
    INDEX idx_menu_category_available (category_id, is_available),
    INDEX idx_menu_cuisine_available (cuisine_id,is_available)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    table_id BIGINT UNSIGNED NOT NULL,
    reservation_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    status ENUM('open','completed','cancelled') NOT NULL DEFAULT 'open',
    subtotal_amount DECIMAL(12,0) NOT NULL,
    vip_rate DECIMAL(5,2) NOT NULL,
    vip_fee DECIMAL(12,0) NOT NULL,
    total_amount DECIMAL(12,0) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    FOREIGN KEY (table_id) REFERENCES restaurant_tables(id),
    FOREIGN KEY (reservation_id,table_id) REFERENCES reservations(id,table_id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    CHECK (subtotal_amount >= 0 AND vip_fee >= 0 AND total_amount >= 0),
    CHECK (vip_rate IN (0.00,5.00)),
    CHECK (vip_fee = ROUND(subtotal_amount * vip_rate / 100,0)),
    CHECK (total_amount = subtotal_amount + vip_fee),
    INDEX idx_orders_table_status (table_id, status),
    INDEX idx_orders_reservation (reservation_id),
    INDEX idx_orders_creator (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE order_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    menu_item_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(12,0) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id),
    CHECK (quantity > 0),
    CHECK (unit_price >= 0),
    UNIQUE KEY uq_order_menu (order_id, menu_item_id),
    INDEX idx_order_items_menu (menu_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE change_events (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(60) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    payload JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    CHECK ((id = 0 AND branch_id IS NULL AND event_type = 'system.sequence') OR
           (id > 0 AND ((branch_id IS NULL AND event_type = 'menu.updated') OR
           (branch_id IS NOT NULL AND event_type IN ('reservation.created','reservation.updated','table.status_changed','order.created','order.updated'))))),
    INDEX idx_events_branch_id (branch_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Sentinel cho Long Polling; KHONG tra hang id=0 qua API.
INSERT INTO change_events (id, branch_id, event_type, entity_id, payload, created_at)
VALUES (0, NULL, 'system.sequence', 0, JSON_OBJECT('last_id', 0), UTC_TIMESTAMP(6));

-- Backend phai khoa hang restaurant_tables FOR UPDATE truoc khi kiem tra overlap.
-- Sau khoa nghiep vu, khoa sentinel FOR UPDATE, cap ID va ghi event trong cung transaction.
-- CHECK/FK khong tu ngan dat trung, vuot capacity, hay chuyen trang thai sai.
