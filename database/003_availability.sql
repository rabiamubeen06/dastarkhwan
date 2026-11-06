CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(60) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('buffer_days', '1'), ('deposit_percent', '25'), ('min_deposit', '5000'),
  ('advance_percent', '30'), ('late_fee_per_day', '500'),
  ('shop_whatsapp', ''), ('payment_instructions', '');

CREATE TABLE IF NOT EXISTS delivery_zones (
  zone_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  city VARCHAR(80) NOT NULL,
  area VARCHAR(100) NOT NULL,
  fee DECIMAL(10,2) NOT NULL CHECK (fee >= 0),
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_zone (city, area)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS packages (
  package_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  guest_count INT UNSIGNED NULL,
  is_template TINYINT(1) NOT NULL DEFAULT 0,
  discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
  booking_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_no VARCHAR(20) NOT NULL UNIQUE,
  user_id INT UNSIGNED NOT NULL,
  package_id INT UNSIGNED NULL,
  event_type ENUM('wedding','party','family','daawat','corporate','other') NOT NULL,
  event_name VARCHAR(150) NULL,
  guest_count INT UNSIGNED NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  rental_days SMALLINT UNSIGNED NOT NULL,
  delivery_method ENUM('pickup','delivery') NOT NULL DEFAULT 'pickup',
  delivery_address VARCHAR(255) NULL,
  delivery_zone_id INT UNSIGNED NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0,
  delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL,
  security_deposit DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('pending','confirmed','delivered','return_pending',
              'returned','completed','rejected','cancelled') NOT NULL DEFAULT 'pending',
  customer_notes TEXT NULL,
  admin_notes TEXT NULL,
  confirmed_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CHECK (end_date >= start_date),
  FOREIGN KEY (user_id) REFERENCES users(user_id),
  FOREIGN KEY (package_id) REFERENCES packages(package_id) ON DELETE SET NULL,
  FOREIGN KEY (confirmed_by) REFERENCES users(user_id),
  FOREIGN KEY (delivery_zone_id) REFERENCES delivery_zones(zone_id),
  INDEX idx_book_dates (status, start_date, end_date),
  INDEX idx_book_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_items (
  booking_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL CHECK (quantity > 0),
  unit_price DECIMAL(10,2) NOT NULL,
  replacement_cost DECIMAL(10,2) NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  UNIQUE KEY uq_book_prod (booking_id, product_id),
  FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(product_id),
  INDEX idx_bi_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- For reasons damaged / lost / repair, change_qty is the number of units taken out of
-- service (positive). A later NEGATIVE row puts units back (e.g. repair finished).
CREATE TABLE IF NOT EXISTS inventory_adjustments (
  adjustment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  change_qty INT NOT NULL,
  reason ENUM('restock','damaged','lost','repair','correction') NOT NULL,
  booking_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(product_id),
  FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;