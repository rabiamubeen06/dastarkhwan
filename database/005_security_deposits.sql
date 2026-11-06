
CREATE TABLE IF NOT EXISTS security_deposits (
  deposit_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id INT UNSIGNED NOT NULL UNIQUE,
  amount_held DECIMAL(12,2) NOT NULL DEFAULT 0,
  amount_deducted DECIMAL(12,2) NOT NULL DEFAULT 0,
  amount_refunded DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('pending','held','partially_refunded','refunded','forfeited') NOT NULL DEFAULT 'pending',
  settled_at DATETIME NULL,
  FOREIGN KEY (booking_id) REFERENCES bookings(booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;