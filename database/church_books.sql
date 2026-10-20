-- CHURCH VISITORS BOOK + WEDDING VOWS BOOK + CONVERTS & NURTURE + PUBLIC QR/LINK REGISTRATION
-- Back up the database first, then run this file once.

CREATE TABLE IF NOT EXISTS church_visitors (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visit_date DATE NOT NULL,
    member_id INT NULL,
    full_name VARCHAR(180) NOT NULL,
    gender ENUM('Male','Female','Other','Prefer not to say') NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(180) NULL,
    address VARCHAR(255) NULL,
    visitor_type ENUM('First Time','Returning') NOT NULL DEFAULT 'First Time',
    service_attended VARCHAR(180) NULL,
    invited_by VARCHAR(180) NULL,
    reason_for_visit VARCHAR(255) NULL,
    prayer_request TEXT NULL,
    follow_up_required TINYINT(1) NOT NULL DEFAULT 0,
    follow_up_status ENUM('Not Required','Pending','Contacted','Completed') NOT NULL DEFAULT 'Not Required',
    assigned_to VARCHAR(180) NULL,
    notes TEXT NULL,
    self_registered TINYINT(1) NOT NULL DEFAULT 0,
    registration_link_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_visitors_date (visit_date), INDEX idx_visitors_member (member_id), INDEX idx_visitors_type (visitor_type),
    INDEX idx_visitors_followup (follow_up_status), INDEX idx_visitors_name (full_name), INDEX idx_visitors_reglink (registration_link_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE church_visitors ADD COLUMN IF NOT EXISTS self_registered TINYINT(1) NOT NULL DEFAULT 0 AFTER notes;
ALTER TABLE church_visitors ADD COLUMN IF NOT EXISTS registration_link_id INT NULL AFTER self_registered;

CREATE TABLE IF NOT EXISTS wedding_vows (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    register_no VARCHAR(80) NULL,
    wedding_date DATE NOT NULL,
    wedding_time TIME NULL,
    venue VARCHAR(180) NULL,
    status ENUM('Scheduled','Banns','Solemnized','Postponed','Cancelled') NOT NULL DEFAULT 'Scheduled',
    groom_member_id INT NULL, groom_name VARCHAR(180) NOT NULL, groom_phone VARCHAR(50) NULL, groom_address VARCHAR(255) NULL, groom_father VARCHAR(180) NULL, groom_mother VARCHAR(180) NULL,
    bride_member_id INT NULL, bride_name VARCHAR(180) NOT NULL, bride_phone VARCHAR(50) NULL, bride_address VARCHAR(255) NULL, bride_father VARCHAR(180) NULL, bride_mother VARCHAR(180) NULL,
    ceremony_type ENUM('Banns','Licence','Special Licence','Other') NOT NULL DEFAULT 'Banns',
    banns_date_1 DATE NULL, banns_date_2 DATE NULL, banns_date_3 DATE NULL,
    marriage_licence_no VARCHAR(100) NULL, certificate_no VARCHAR(100) NULL,
    witness_1 VARCHAR(180) NULL, witness_2 VARCHAR(180) NULL, officiant VARCHAR(180) NULL, assisting_minister VARCHAR(180) NULL,
    vows_text TEXT NULL, scripture_theme VARCHAR(255) NULL, remarks TEXT NULL,
    self_registered TINYINT(1) NOT NULL DEFAULT 0,
    registration_link_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wedding_register_no (register_no), INDEX idx_wedding_date (wedding_date), INDEX idx_wedding_status (status),
    INDEX idx_wedding_groom_member (groom_member_id), INDEX idx_wedding_bride_member (bride_member_id), INDEX idx_wedding_certificate (certificate_no), INDEX idx_wedding_reglink (registration_link_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE wedding_vows ADD COLUMN IF NOT EXISTS self_registered TINYINT(1) NOT NULL DEFAULT 0 AFTER remarks;
ALTER TABLE wedding_vows ADD COLUMN IF NOT EXISTS registration_link_id INT NULL AFTER self_registered;

CREATE TABLE IF NOT EXISTS visitor_registration_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token CHAR(64) NOT NULL UNIQUE,
    label VARCHAR(180) NULL,
    recipient_name VARCHAR(180) NULL,
    recipient_phone VARCHAR(50) NULL,
    max_uses INT NULL DEFAULT 1,
    used_count INT NOT NULL DEFAULT 0,
    expires_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    INDEX idx_vrl_active (is_active), INDEX idx_vrl_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wedding_registration_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wedding_id INT NULL,
    token CHAR(64) NOT NULL UNIQUE,
    label VARCHAR(180) NULL,
    recipient_name VARCHAR(180) NULL,
    recipient_phone VARCHAR(50) NULL,
    max_uses INT NULL DEFAULT 1,
    used_count INT NOT NULL DEFAULT 0,
    expires_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    INDEX idx_wrl_wedding (wedding_id), INDEX idx_wrl_active (is_active), INDEX idx_wrl_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions(permission_key,permission_name,module_name) VALUES
('visitors.view','View Church Visitors Book','Church Visitors Book'),
('visitors.manage','Add, edit, delete visitors and manage visitor registration links','Church Visitors Book'),
('wedding_vows.view','View Wedding Vows Book','Wedding Vows Book'),
('wedding_vows.manage','Add, edit, delete weddings and manage groom registration links','Wedding Vows Book')
ON DUPLICATE KEY UPDATE permission_name=VALUES(permission_name),module_name=VALUES(module_name);

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
