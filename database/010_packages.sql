
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

CREATE TABLE IF NOT EXISTS package_items (
  package_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  package_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_pkg_prod (package_id, product_id),
  FOREIGN KEY (package_id) REFERENCES packages(package_id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO packages (name, description, guest_count, is_template, discount_percent)
SELECT 'Wedding 100 Guests', 'Full table setting for a wedding or walima of about 100 guests.', 100, 1, 10
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE is_template = 1 AND name = 'Wedding 100 Guests');

INSERT IGNORE INTO package_items (package_id, product_id, quantity)
SELECT (SELECT MIN(package_id) FROM packages WHERE is_template = 1 AND name = 'Wedding 100 Guests'), product_id, q
FROM (
  SELECT product_id, 100 AS q FROM products WHERE sku IN ('PL-001','PL-002','BW-001','CT-001','CT-002','CT-003','GL-001','TC-001')
  UNION ALL SELECT product_id, 5 FROM products WHERE sku IN ('SD-001','SD-002')
) AS x;

INSERT INTO packages (name, description, guest_count, is_template, discount_percent)
SELECT 'Family Daawat 30 Guests', 'A simple setting for a family gathering of about 30 guests.', 30, 1, 5
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM packages WHERE is_template = 1 AND name = 'Family Daawat 30 Guests');

INSERT IGNORE INTO package_items (package_id, product_id, quantity)
SELECT (SELECT MIN(package_id) FROM packages WHERE is_template = 1 AND name = 'Family Daawat 30 Guests'), product_id, q
FROM (
  SELECT product_id, 30 AS q FROM products WHERE sku IN ('PL-001','CT-001','CT-002','GL-001')
  UNION ALL SELECT product_id, 2 FROM products WHERE sku IN ('SD-001','SD-002')
) AS x;