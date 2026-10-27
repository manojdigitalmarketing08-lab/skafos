-- Skafos hero lead form: database schema.
-- api/mailer.php creates this table automatically on the first lead, so running this file is OPTIONAL.
-- Use it if you prefer to create the table yourself: Hostinger hPanel > Databases > phpMyAdmin > SQL tab.
-- Do not upload this file to the live site.

CREATE TABLE IF NOT EXISTS hero_leads (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(120) NOT NULL,
    phone          VARCHAR(30)  NOT NULL,
    service        VARCHAR(80)  NOT NULL,
    location       VARCHAR(120) NULL,
    visit_date     DATE         NULL,
    visit_time     VARCHAR(40)  NULL,
    form_name      VARCHAR(40)  NULL COMMENT 'which form: hero-form',
    lead_source    VARCHAR(60)  NULL COMMENT 'google, facebook, instagram, direct, or utm_source',
    page_url       VARCHAR(300) NULL COMMENT 'page the form was submitted on',
    page_title     VARCHAR(200) NULL,
    landing_page   VARCHAR(300) NULL COMMENT 'first page of the visit',
    referrer       VARCHAR(300) NULL,
    utm_source     VARCHAR(60)  NULL,
    utm_medium     VARCHAR(60)  NULL,
    utm_campaign   VARCHAR(100) NULL,
    ip_address     VARCHAR(45)  NULL,
    user_agent     VARCHAR(255) NULL,
    whatsapp_alert TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = admin WhatsApp alert sent',
    email_sent     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = notification email sent',
    status         ENUM('new','contacted','booked','closed') NOT NULL DEFAULT 'new',
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_created (created_at),
    KEY idx_status (status),
    KEY idx_source (lead_source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Handy queries
-- New leads:              SELECT * FROM hero_leads WHERE status = 'new' ORDER BY created_at DESC;
-- Leads by source:        SELECT lead_source, COUNT(*) AS leads FROM hero_leads GROUP BY lead_source ORDER BY leads DESC;
-- Leads by page:          SELECT page_url, COUNT(*) AS leads FROM hero_leads GROUP BY page_url ORDER BY leads DESC;
-- Alerts that failed:     SELECT id, name, phone, created_at FROM hero_leads WHERE whatsapp_alert = 0 OR email_sent = 0;
