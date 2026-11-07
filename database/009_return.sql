CREATE TABLE IF NOT EXISTS returns (
  return_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id INT UNSIGNED NOT NULL,
  returned_at DATETIME NOT NULL,
  received_by INT UNSIGNED NOT NULL,
  late_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  late_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  FOREIGN KEY (booking_id) REFERENCES bookings(booking_id),
  FOREIGN KEY (received_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS return_items (
  return_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_id INT UNSIGNED NOT NULL,
  booking_item_id INT UNSIGNED NOT NULL,
  qty_returned INT UNSIGNED NOT NULL DEFAULT 0,
  qty_damaged INT UNSIGNED NOT NULL DEFAULT 0,
  qty_missing INT UNSIGNED NOT NULL DEFAULT 0,
  damage_charge DECIMAL(10,2) NOT NULL DEFAULT 0,
  remarks VARCHAR(255) NULL,
  FOREIGN KEY (return_id) REFERENCES returns(return_id) ON DELETE CASCADE,
  FOREIGN KEY (booking_item_id) REFERENCES booking_items(booking_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;