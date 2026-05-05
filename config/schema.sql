-- GreenLink Innovators Database Schema
-- Run this file to set up the database

CREATE DATABASE IF NOT EXISTS greenlink_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE greenlink_db;

-- Users Table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('farmer', 'buyer', 'admin') NOT NULL DEFAULT 'buyer',
    phone VARCHAR(20),
    location VARCHAR(150),
    profile_image VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Farmers Extended Profile
CREATE TABLE IF NOT EXISTS farmers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    farm_name VARCHAR(150),
    farm_location VARCHAR(200),
    farm_size_hectares DECIMAL(8,2),
    bio TEXT,
    certification VARCHAR(100),
    bank_account VARCHAR(100),
    rating DECIMAL(3,2) DEFAULT 0.00,
    total_sales INT DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Products Table
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    farmer_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    category VARCHAR(80),
    price_per_kg DECIMAL(10,2) NOT NULL,
    stock_kg DECIMAL(10,2) NOT NULL,
    min_order_kg DECIMAL(10,2) DEFAULT 1.00,
    location VARCHAR(150),
    harvest_date DATE,
    image VARCHAR(255) DEFAULT NULL,
    is_organic TINYINT(1) DEFAULT 0,
    is_available TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Orders Table
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    buyer_id INT NOT NULL,
    farmer_id INT NOT NULL,
    status ENUM('pending','confirmed','processing','shipped','completed','cancelled') DEFAULT 'pending',
    total_amount DECIMAL(12,2) NOT NULL,
    delivery_address TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Order Items Table
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity_kg DECIMAL(10,2) NOT NULL,
    price_per_kg DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- Messages Table
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Market Prices Table
CREATE TABLE IF NOT EXISTS market_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(100) NOT NULL,
    category VARCHAR(80),
    market_price DECIMAL(10,2) NOT NULL,
    suggested_price DECIMAL(10,2),
    unit VARCHAR(20) DEFAULT 'kg',
    location VARCHAR(100) DEFAULT 'Mindanao',
    price_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Sample Data

-- Admin User
INSERT INTO users (name, email, password, role, phone, location) VALUES
('Admin GreenLink', 'admin@greenlink.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', '09000000000', 'Davao City');

-- Sample Farmers
INSERT INTO users (name, email, password, role, phone, location) VALUES
('Juan dela Cruz', 'juan@greenlink.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'farmer', '09171234567', 'Davao del Sur'),
('Maria Santos', 'maria@greenlink.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'farmer', '09189876543', 'Bukidnon'),
('Pedro Reyes', 'pedro@greenlink.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'farmer', '09205556789', 'Cagayan de Oro');

-- Sample Buyers
INSERT INTO users (name, email, password, role, phone, location) VALUES
('La Vieille Cuisine Restaurant', 'restaurant@greenlink.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'buyer', '09221112233', 'Davao City'),
('FreshMart Grocery', 'freshmart@greenlink.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'buyer', '09233334444', 'Cagayan de Oro');

-- Farmer Profiles
INSERT INTO farmers (user_id, farm_name, farm_location, farm_size_hectares, bio, certification) VALUES
(2, 'Dela Cruz Organic Farm', 'Davao del Sur', 5.5, 'Family-owned organic farm growing premium vegetables for over 20 years.', 'Organic PH Certified'),
(3, 'Santos Highland Farms', 'Bukidnon', 12.0, 'High-altitude farm producing coffee, corn, and exotic vegetables.', 'GAP Certified'),
(4, 'Reyes Agri Estate', 'Cagayan de Oro', 8.3, 'Mixed-crop farm specializing in tropical fruits and vegetables.', 'HACCP Certified');

-- Sample Products
INSERT INTO products (farmer_id, name, description, category, price_per_kg, stock_kg, min_order_kg, location, harvest_date, is_organic, is_available) VALUES
(2, 'Organic Pechay', 'Fresh organic pechay (bok choy) grown without pesticides. Perfect for stir-fry and soups.', 'Vegetables', 45.00, 200.00, 5.00, 'Davao del Sur', '2025-05-10', 1, 1),
(2, 'Sweet Corn', 'Freshly harvested sweet yellow corn, perfect for grilling or boiling.', 'Grains', 30.00, 500.00, 10.00, 'Davao del Sur', '2025-05-08', 0, 1),
(3, 'Arabica Coffee Beans', 'Premium single-origin Arabica from Bukidnon highlands at 1200masl. Smooth and fruity notes.', 'Coffee', 380.00, 100.00, 2.00, 'Bukidnon', '2025-04-20', 1, 1),
(3, 'Organic Cabbage', 'Large, firm heads of cabbage grown organically on highland soil.', 'Vegetables', 35.00, 350.00, 10.00, 'Bukidnon', '2025-05-12', 1, 1),
(4, 'Carabao Mango', 'World-famous Philippine carabao mango, sweet and fibrous. Export quality.', 'Fruits', 120.00, 800.00, 5.00, 'Cagayan de Oro', '2025-05-05', 0, 1),
(4, 'Banana (Lakatan)', 'Premium lakatan bananas, rich in flavor and aroma. Restaurant-ready.', 'Fruits', 55.00, 600.00, 10.00, 'Cagayan de Oro', '2025-05-06', 0, 1),
(2, 'Sitaw (String Beans)', 'Fresh and crisp string beans, ideal for vegetable dishes and stews.', 'Vegetables', 60.00, 150.00, 5.00, 'Davao del Sur', '2025-05-09', 0, 1),
(3, 'White Corn (Bigas Mais)', 'Traditional white corn for grits and native dishes. A Bukidnon staple.', 'Grains', 28.00, 1000.00, 20.00, 'Bukidnon', '2025-04-28', 0, 1);

-- Market Prices
INSERT INTO market_prices (product_name, category, market_price, suggested_price, unit, location, price_date) VALUES
('Pechay', 'Vegetables', 40.00, 45.00, 'kg', 'Davao', CURDATE()),
('Cabbage', 'Vegetables', 30.00, 35.00, 'kg', 'Bukidnon', CURDATE()),
('Sweet Corn', 'Grains', 25.00, 30.00, 'kg', 'Davao', CURDATE()),
('Carabao Mango', 'Fruits', 100.00, 120.00, 'kg', 'Mindanao', CURDATE()),
('Banana (Lakatan)', 'Fruits', 45.00, 55.00, 'kg', 'Mindanao', CURDATE()),
('Arabica Coffee', 'Coffee', 350.00, 380.00, 'kg', 'Bukidnon', CURDATE()),
('String Beans', 'Vegetables', 50.00, 60.00, 'kg', 'Davao', CURDATE()),
('White Corn', 'Grains', 22.00, 28.00, 'kg', 'Bukidnon', CURDATE()),
('Tomato', 'Vegetables', 55.00, 65.00, 'kg', 'Mindanao', CURDATE()),
('Ampalaya', 'Vegetables', 70.00, 80.00, 'kg', 'Mindanao', CURDATE());

-- Sample Orders
INSERT INTO orders (buyer_id, farmer_id, status, total_amount, delivery_address, notes) VALUES
(5, 2, 'confirmed', 3375.00, '123 Restaurant Row, Davao City', 'Please deliver early morning.'),
(5, 3, 'pending', 760.00, '123 Restaurant Row, Davao City', 'Urgent order for weekend menu.'),
(6, 4, 'completed', 6600.00, '456 Market St, Cagayan de Oro', 'Weekly supply order.');

INSERT INTO order_items (order_id, product_id, quantity_kg, price_per_kg, subtotal) VALUES
(1, 1, 50.00, 45.00, 2250.00),
(1, 7, 18.75, 60.00, 1125.00),
(2, 3, 2.00, 380.00, 760.00),
(3, 5, 30.00, 120.00, 3600.00),
(3, 6, 54.54, 55.00, 2999.70);

-- Sample Messages
INSERT INTO messages (sender_id, receiver_id, message, is_read) VALUES
(5, 2, 'Hello! I would like to order 50kg of your organic pechay weekly. Can we set up a regular supply?', 1),
(2, 5, 'Good day! Yes, we can definitely arrange a weekly supply. What day works best for delivery?', 1),
(5, 2, 'Every Monday morning would be perfect. Do you also have sitaw available?', 0),
(6, 4, 'Hi Pedro, we need 30kg of carabao mangoes every Friday. Are you able to commit to that?', 1),
(4, 6, 'Hello! Yes, we can do that. Our mangoes are freshly harvested and export quality. I will confirm stock every Thursday.', 0);
