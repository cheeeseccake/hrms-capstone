-- Audit Management schema for HRMS Legal & Compliance
-- Run once against the existing HRMS database. Does not alter existing tables.
CREATE TABLE IF NOT EXISTS lc_audits (
  audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  audit_code VARCHAR(32) NOT NULL,
  title VARCHAR(180) NOT NULL,
  campus VARCHAR(180) NOT NULL,
  audit_type VARCHAR(80) NOT NULL,
  description TEXT NULL,
  responsible_name VARCHAR(150) NOT NULL,
  responsible_email VARCHAR(254) NOT NULL,
  auditor_name VARCHAR(150) NOT NULL,
  scheduled_date DATE NOT NULL,
  due_date DATE NOT NULL,
  status ENUM('scheduled','in_progress','submitted','returned','approved','cancelled','overdue') NOT NULL DEFAULT 'scheduled',
  checklist_json LONGTEXT NOT NULL,
  responses_json LONGTEXT NULL,
  public_token_hash CHAR(64) NOT NULL,
  token_expires_at DATETIME NOT NULL,
  last_saved_at DATETIME NULL,
  submitted_at DATETIME NULL,
  reviewed_at DATETIME NULL,
  review_notes TEXT NULL,
  created_by BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (audit_id),
  UNIQUE KEY uq_lc_audits_code (audit_code),
  UNIQUE KEY uq_lc_audits_token_hash (public_token_hash),
  KEY idx_lc_audits_status_due (status,due_date),
  KEY idx_lc_audits_campus_date (campus,scheduled_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lc_audit_findings (
  finding_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  audit_id BIGINT UNSIGNED NOT NULL,
  checklist_item_id VARCHAR(64) NULL,
  finding_title VARCHAR(180) NOT NULL,
  finding_details TEXT NULL,
  severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  assigned_to VARCHAR(150) NULL,
  assigned_email VARCHAR(254) NULL,
  due_date DATE NULL,
  status ENUM('open','in_progress','resolved','verified','closed') NOT NULL DEFAULT 'open',
  resolution_notes TEXT NULL,
  created_by BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (finding_id),
  KEY idx_lc_audit_findings_audit (audit_id),
  KEY idx_lc_audit_findings_status_due (status,due_date),
  CONSTRAINT fk_lc_audit_findings_audit FOREIGN KEY (audit_id) REFERENCES lc_audits(audit_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lc_audit_evidence (
  evidence_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  audit_id BIGINT UNSIGNED NOT NULL,
  checklist_item_id VARCHAR(64) NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by VARCHAR(150) NOT NULL DEFAULT 'External checklist',
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (evidence_id),
  KEY idx_lc_audit_evidence_audit (audit_id),
  CONSTRAINT fk_lc_audit_evidence_audit FOREIGN KEY (audit_id) REFERENCES lc_audits(audit_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lc_audit_events (
  event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  audit_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(60) NOT NULL,
  event_message VARCHAR(500) NOT NULL,
  actor VARCHAR(180) NOT NULL DEFAULT 'System',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id),
  KEY idx_lc_audit_events_audit (audit_id),
  CONSTRAINT fk_lc_audit_events_audit FOREIGN KEY (audit_id) REFERENCES lc_audits(audit_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
