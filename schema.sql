CREATE DATABASE IF NOT EXISTS daluyan_uim
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE daluyan_uim;

CREATE TABLE IF NOT EXISTS roles (
  role_key VARCHAR(32) PRIMARY KEY,
  label VARCHAR(100) NOT NULL,
  dashboard_access TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS permissions (
  permission_key VARCHAR(64) PRIMARY KEY,
  description VARCHAR(180) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_key VARCHAR(32) NOT NULL,
  permission_key VARCHAR(64) NOT NULL,
  PRIMARY KEY (role_key, permission_key),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_key) REFERENCES roles(role_key),
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_key) REFERENCES permissions(permission_key)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS committees (
  committee_key VARCHAR(32) PRIMARY KEY,
  full_title VARCHAR(180) NOT NULL,
  tag_color VARCHAR(32) NOT NULL,
  icon VARCHAR(64) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  seed_key VARCHAR(64) NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(254) NOT NULL UNIQUE,
  role_key VARCHAR(32) NOT NULL,
  position VARCHAR(160) NOT NULL,
  directory_category VARCHAR(32) NOT NULL,
  tag_color VARCHAR(32) NOT NULL DEFAULT 'brandCyan',
  icon VARCHAR(64) NOT NULL DEFAULT 'fa-user',
  directory_visible TINYINT(1) NOT NULL DEFAULT 1,
  password_hash VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role FOREIGN KEY (role_key) REFERENCES roles(role_key),
  INDEX idx_users_active_role (active, role_key),
  INDEX idx_users_category (directory_category)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_committees (
  user_id BIGINT UNSIGNED NOT NULL,
  committee_key VARCHAR(32) NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, committee_key),
  INDEX idx_user_committees_committee (committee_key, user_id),
  CONSTRAINT fk_user_committees_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_committees_committee FOREIGN KEY (committee_key) REFERENCES committees(committee_key)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dtn_sequences (
  sequence_year SMALLINT UNSIGNED PRIMARY KEY,
  next_number INT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dtn_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_code VARCHAR(32) NOT NULL UNIQUE,
  subject VARCHAR(200) NOT NULL,
  committee_key VARCHAR(32) NOT NULL,
  requester_id BIGINT UNSIGNED NOT NULL,
  requester_email VARCHAR(254) NOT NULL,
  description TEXT NOT NULL,
  submitted_by BIGINT UNSIGNED NOT NULL,
  status ENUM('submitted','approved','rejected') NOT NULL DEFAULT 'submitted',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_dtn_created (created_at),
  INDEX idx_dtn_committee (committee_key, created_at),
  CONSTRAINT fk_dtn_committee FOREIGN KEY (committee_key) REFERENCES committees(committee_key),
  CONSTRAINT fk_dtn_requester FOREIGN KEY (requester_id) REFERENCES users(id),
  CONSTRAINT fk_dtn_submitter FOREIGN KEY (submitted_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  officer_id BIGINT UNSIGNED NOT NULL,
  event_name VARCHAR(200) NOT NULL,
  venue VARCHAR(200) NOT NULL,
  status ENUM('checked_in','complete','on_call') NOT NULL,
  check_in_at DATETIME NOT NULL,
  check_out_at DATETIME NULL,
  hours DECIMAL(8,2) NOT NULL DEFAULT 0,
  logged_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_attendance_time (check_in_at),
  INDEX idx_attendance_officer_status (officer_id, status, check_in_at),
  INDEX idx_attendance_committee_lookup (officer_id, check_in_at),
  CONSTRAINT fk_attendance_officer FOREIGN KEY (officer_id) REFERENCES users(id),
  CONSTRAINT fk_attendance_logger FOREIGN KEY (logged_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS leave_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  officer_id BIGINT UNSIGNED NOT NULL,
  committee_key VARCHAR(32) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  reason TEXT NOT NULL,
  submitted_by BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_leave_status_created (status, created_at),
  CONSTRAINT fk_leave_officer FOREIGN KEY (officer_id) REFERENCES users(id),
  CONSTRAINT fk_leave_committee FOREIGN KEY (committee_key) REFERENCES committees(committee_key),
  CONSTRAINT fk_leave_submitter FOREIGN KEY (submitted_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  attempt_key CHAR(64) NOT NULL,
  attempted_at DATETIME NOT NULL,
  INDEX idx_login_attempt_key_time (attempt_key, attempted_at)
) ENGINE=InnoDB;

INSERT INTO roles (role_key, label, dashboard_access) VALUES
  ('adviser', 'Faculty Adviser', 1),
  ('executive', 'Higher Executive', 1),
  ('officer', 'Council Officer', 0)
ON DUPLICATE KEY UPDATE label = VALUES(label), dashboard_access = VALUES(dashboard_access);

INSERT INTO permissions (permission_key, description) VALUES
  ('attendance.view_all', 'View attendance records across all committees'),
  ('attendance.submit_self', 'Submit attendance for the signed-in user'),
  ('attendance.submit_any', 'Submit attendance for any directory officer'),
  ('dtn.view_all', 'View document tracking requests'),
  ('dtn.submit_self', 'Submit a document tracking request for the signed-in user'),
  ('dtn.submit_any', 'Submit a document tracking request for any requester'),
  ('leave.view_all', 'View leave requests across all committees'),
  ('leave.submit_self', 'Submit a leave request for the signed-in user'),
  ('leave.submit_any', 'Submit a leave request for any officer'),
  ('reports.view_all', 'View executive attendance reports')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO role_permissions (role_key, permission_key) VALUES
  ('adviser', 'attendance.view_all'),
  ('adviser', 'attendance.submit_self'),
  ('adviser', 'attendance.submit_any'),
  ('adviser', 'dtn.view_all'),
  ('adviser', 'dtn.submit_self'),
  ('adviser', 'dtn.submit_any'),
  ('adviser', 'leave.view_all'),
  ('adviser', 'leave.submit_self'),
  ('adviser', 'leave.submit_any'),
  ('adviser', 'reports.view_all'),
  ('executive', 'attendance.view_all'),
  ('executive', 'attendance.submit_self'),
  ('executive', 'attendance.submit_any'),
  ('executive', 'dtn.view_all'),
  ('executive', 'dtn.submit_self'),
  ('executive', 'dtn.submit_any'),
  ('executive', 'leave.view_all'),
  ('executive', 'leave.submit_self'),
  ('executive', 'leave.submit_any'),
  ('executive', 'reports.view_all'),
  ('officer', 'attendance.submit_self'),
  ('officer', 'dtn.submit_self'),
  ('officer', 'leave.submit_self')
ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key);

INSERT INTO committees (committee_key, full_title, tag_color, icon) VALUES
  ('Executives', 'Higher Executive', 'brandCyan', 'fa-crown'),
  ('CommAIRS', 'Committee on Archival Information and Records Security', 'brandCyan', 'fa-folder-tree'),
  ('CommCREL', 'Committee on Community Relations and External Linkages', 'brandTeal', 'fa-handshake'),
  ('CommFIMA', 'Committee on Financial Management and Audit', 'brandOrange', 'fa-chart-pie'),
  ('CommPROL', 'Committee on Procurement and Logistics', 'brandAmber', 'fa-boxes-packing'),
  ('Adviser', 'SSC Faculty Adviser', 'brandAmber', 'fa-user-tie')
ON DUPLICATE KEY UPDATE full_title = VALUES(full_title), tag_color = VALUES(tag_color), icon = VALUES(icon);
