CREATE TABLE IF NOT EXISTS payments (
  payment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id INT UNSIGNED NOT NULL,
  type ENUM('advance','balance','deposit','refund','damage_charge','late_fee') NOT NULL,
  method ENUM('cash','bank_transfer','easypaisa','jazzcash','other') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  reference_no VARCHAR(80) NULL,
  paid_at DATETIME NOT NULL,
  received_by INT UNSIGNED NULL,
  notes VARCHAR(255) NULL,
  FOREIGN KEY (booking_id) REFERENCES bookings(booking_id),
  FOREIGN KEY (received_by) REFERENCES users(user_id),
  INDEX idx_pay_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;