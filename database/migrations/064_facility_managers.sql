-- Per-facility manager assignments (admins/coordinators who receive booking notifications and can approve).
-- Run after 059_facilities_domain.sql.

CREATE TABLE IF NOT EXISTS facility_managers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  facility_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_facility_user (facility_id, user_id),
  KEY idx_user (user_id),
  CONSTRAINT fk_facility_managers_facility FOREIGN KEY (facility_id) REFERENCES facilities (id) ON DELETE CASCADE,
  CONSTRAINT fk_facility_managers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
