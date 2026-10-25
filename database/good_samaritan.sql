-- GOOD SAMARITAN COLLECTIONS (good_samaritan/)
-- Staff record Good Samaritan collections, from one member or a general basket; members see their own records under My Giving
-- on the website and in the member app. Back up the database first, then run this file once. It is safe to re-run.

CREATE TABLE IF NOT EXISTS good_samaritan (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_no VARCHAR(40) NOT NULL,
    member_id INT NULL,  -- NULL for a general (basket) collection
    amount DECIMAL(12,2) NOT NULL,
    payment_method VARCHAR(40) NOT NULL DEFAULT 'Cash',
    given_on DATE NOT NULL,
    notes TEXT NULL,
    recorded_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_good_samaritan_receipt (receipt_no),
    INDEX idx_good_samaritan_member (member_id),
    INDEX idx_good_samaritan_date (given_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions(permission_key,permission_name,module_name) VALUES
('good_samaritan.view','Record, edit and delete Good Samaritan collections','Finance & Giving')
ON DUPLICATE KEY UPDATE permission_name=VALUES(permission_name),module_name=VALUES(module_name);

-- Roles that can manage Contributions get Good Samaritan too. Adjust in Users > Permissions afterwards if needed.
INSERT INTO role_permissions(role,permission_id,allowed)
  SELECT rp.role, gs.id, rp.allowed
  FROM role_permissions rp
  JOIN permissions c ON c.id=rp.permission_id AND c.permission_key='contributions.view'
  JOIN permissions gs ON gs.permission_key='good_samaritan.view'
  ON DUPLICATE KEY UPDATE allowed=VALUES(allowed);
