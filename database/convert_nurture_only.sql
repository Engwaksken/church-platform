-- CONVERTS & NURTURE MODULE ONLY
-- Use this file when Church Visitors Book + Wedding Vows Book are already installed.
-- Back up the database first.

-- ============================================================
-- CONVERTS & NURTURE / FOLLOW-UP MODULE
-- ============================================================

CREATE TABLE IF NOT EXISTS church_converts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversion_date DATE NOT NULL,
    person_type ENUM('Member','Visitor') NOT NULL DEFAULT 'Visitor',
    member_id INT NULL,
    visitor_id INT NULL,
    full_name VARCHAR(180) NOT NULL,
    gender ENUM('Male','Female','Other','Prefer not to say') NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(180) NULL,
    address VARCHAR(255) NULL,
    decision_type ENUM('New Convert','Recommitment','Transferred In','Other') NOT NULL DEFAULT 'New Convert',
    conversion_context VARCHAR(180) NULL,
    testimony TEXT NULL,
    prayer_request TEXT NULL,
    assigned_member_id INT NULL,
    nurture_start_date DATE NULL,
    nurture_end_date DATE NULL,
    nurture_period_weeks INT NOT NULL DEFAULT 12,
    status ENUM('Pending Assignment','In Nurture','Paused','Completed','Transferred') NOT NULL DEFAULT 'Pending Assignment',
    next_follow_up_date DATE NULL,
    baptism_interest TINYINT(1) NOT NULL DEFAULT 0,
    discipleship_class VARCHAR(180) NULL,
    notes TEXT NULL,
    self_registered TINYINT(1) NOT NULL DEFAULT 0,
    registration_link_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_convert_date (conversion_date),
    INDEX idx_convert_type (person_type),
    INDEX idx_convert_member (member_id),
    INDEX idx_convert_visitor (visitor_id),
    INDEX idx_convert_assigned_member (assigned_member_id),
    INDEX idx_convert_status (status),
    INDEX idx_convert_next_followup (next_follow_up_date),
    INDEX idx_convert_name (full_name),
    INDEX idx_convert_reglink (registration_link_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS church_convert_followups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    convert_id INT UNSIGNED NOT NULL,
    follow_up_date DATE NOT NULL,
    contact_method ENUM('In Person','Phone Call','WhatsApp','SMS','Home Visit','Church Meeting','Other') NOT NULL DEFAULT 'In Person',
    progress_status ENUM('Not Started','Growing','Needs Support','Ready to Complete','Completed') NOT NULL DEFAULT 'Growing',
    topics_discussed TEXT NULL,
    prayer_notes TEXT NULL,
    next_follow_up_date DATE NULL,
    recorded_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cnf_convert (convert_id),
    INDEX idx_cnf_date (follow_up_date),
    INDEX idx_cnf_next (next_follow_up_date),
    CONSTRAINT fk_cnf_convert
        FOREIGN KEY (convert_id) REFERENCES church_converts(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS convert_registration_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token CHAR(64) NOT NULL UNIQUE,
    label VARCHAR(180) NULL,
    recipient_name VARCHAR(180) NULL,
    recipient_phone VARCHAR(50) NULL,
    person_type ENUM('Member','Visitor') NULL,
    linked_member_id INT NULL,
    linked_visitor_id INT NULL,
    assigned_member_id INT NULL,
    nurture_period_weeks INT NOT NULL DEFAULT 12,
    max_uses INT NULL DEFAULT 1,
    used_count INT NOT NULL DEFAULT 0,
    expires_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    INDEX idx_crl_member (linked_member_id),
    INDEX idx_crl_visitor (linked_visitor_id),
    INDEX idx_crl_assigned (assigned_member_id),
    INDEX idx_crl_active (is_active),
    INDEX idx_crl_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions(permission_key,permission_name,module_name) VALUES
('converts.view','View Converts & Nurture Register','Converts & Nurture'),
('converts.manage','Add, edit, assign and follow up converts and manage QR registration links','Converts & Nurture')
ON DUPLICATE KEY UPDATE permission_name=VALUES(permission_name),module_name=VALUES(module_name);

