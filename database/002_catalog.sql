CREATE TABLE IF NOT EXISTS categories (
  category_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  product_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  sku VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  material VARCHAR(60) NULL,
  image_url VARCHAR(255) NULL,
  rent_per_day DECIMAL(10,2) NOT NULL CHECK (rent_per_day >= 0),
  replacement_cost DECIMAL(10,2) NOT NULL CHECK (replacement_cost >= 0),
  total_quantity INT UNSIGNED NOT NULL DEFAULT 0,
  min_order_qty INT UNSIGNED NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(category_id),
  INDEX idx_products_cat (category_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO categories (name, sort_order) VALUES
  ('Plates', 1), ('Bowls', 2), ('Cutlery', 3), ('Serving dishes', 4), ('Glassware', 5), ('Tea and coffee', 6);

INSERT IGNORE INTO products (category_id, sku, name, description, material, rent_per_day, replacement_cost, total_quantity) VALUES
  ((SELECT category_id FROM categories WHERE name='Plates'), 'PL-001', 'Dinner plate, 10 inch', 'Classic white round dinner plate.', 'Porcelain', 8, 450, 400),
  ((SELECT category_id FROM categories WHERE name='Plates'), 'PL-002', 'Side plate, 7 inch', 'For starters and desserts.', 'Porcelain', 5, 300, 400),
  ((SELECT category_id FROM categories WHERE name='Plates'), 'PL-003', 'Gold rim charger plate', 'Decorative base plate for formal tables.', 'Ceramic', 15, 900, 150),
  ((SELECT category_id FROM categories WHERE name='Bowls'), 'BW-001', 'Soup bowl', 'Deep bowl for shorba and desserts.', 'Porcelain', 6, 350, 300),
  ((SELECT category_id FROM categories WHERE name='Bowls'), 'BW-002', 'Rice bowl, medium', 'Medium bowl for rice and raita.', 'Melamine', 4, 200, 250),
  ((SELECT category_id FROM categories WHERE name='Cutlery'), 'CT-001', 'Dinner fork', 'Polished stainless steel fork.', 'Stainless steel', 3, 150, 600),
  ((SELECT category_id FROM categories WHERE name='Cutlery'), 'CT-002', 'Dinner spoon', 'Polished stainless steel spoon.', 'Stainless steel', 3, 150, 600),
  ((SELECT category_id FROM categories WHERE name='Cutlery'), 'CT-003', 'Table knife', 'Stainless steel table knife.', 'Stainless steel', 3, 170, 400),
  ((SELECT category_id FROM categories WHERE name='Serving dishes'), 'SD-001', 'Biryani serving dish with lid', 'Large dish for biryani and pulao.', 'Stainless steel', 80, 4500, 40),
  ((SELECT category_id FROM categories WHERE name='Serving dishes'), 'SD-002', 'Oval curry dish', 'Oval dish with handles.', 'Porcelain', 60, 3200, 60),
  ((SELECT category_id FROM categories WHERE name='Glassware'), 'GL-001', 'Water glass', 'Clear tumbler.', 'Glass', 4, 180, 500),
  ((SELECT category_id FROM categories WHERE name='Glassware'), 'GL-002', 'Juice jug, 1.5 litre', 'Jug with handle.', 'Glass', 35, 1400, 50),
  ((SELECT category_id FROM categories WHERE name='Tea and coffee'), 'TC-001', 'Tea cup and saucer', 'White cup with matching saucer.', 'Porcelain', 7, 400, 300),
  ((SELECT category_id FROM categories WHERE name='Tea and coffee'), 'TC-002', 'Kahwa teapot', 'Traditional teapot for kahwa.', 'Brass', 90, 5200, 25);