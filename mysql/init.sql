SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

CREATE DATABASE IF NOT EXISTS inventory_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE inventory_db;

CREATE TABLE suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    address VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    quantity INT DEFAULT 0,
    price DECIMAL(12,2) NOT NULL,
    supplier_id INT,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    type ENUM('IN', 'OUT') NOT NULL,
    amount INT NOT NULL,
    note VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO suppliers (name, phone, address) VALUES
('Công ty Thiết bị Số Hà Nội', '0901234567', 'Cầu Giấy, Hà Nội'),
('Nhà phân phối Linh kiện Việt', '0987654321', 'Đống Đa, Hà Nội');

INSERT INTO products (sku, name, quantity, price, supplier_id) VALUES
('LAP-DELL-01', 'Laptop Dell Latitude 5420', 25, 18500000, 1),
('MON-LG-27', 'Màn hình LG 27 inch 4K', 40, 7200000, 1),
('SSD-SAM-1TB', 'Ổ cứng SSD Samsung 980 1TB', 100, 2150000, 2);

INSERT INTO transactions (product_id, type, amount, note) VALUES
(1, 'IN', 25, 'Nhập kho lô hàng đầu tháng'),
(2, 'IN', 45, 'Nhập kho màn hình LG'),
(2, 'OUT', 5, 'Xuất bán đại lý cấp 1');
