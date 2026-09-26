-- Messenger Lead Bot — MariaDB / MySQL (InnoDB, utf8mb4 for Nepali + emoji)
-- Import once via phpMyAdmin or: mysql -u USER -p DBNAME < schema.sql

CREATE TABLE IF NOT EXISTS mb_conversations (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  page_id         VARCHAR(32)  NOT NULL,
  psid            VARCHAR(40)  NOT NULL,              -- Page-scoped user ID from Messenger
  customer_name   VARCHAR(191) NULL,
  lang            CHAR(2)      NOT NULL DEFAULT 'ne',
  phone           VARCHAR(10)  NULL,
  phone_at        INT UNSIGNED NULL,
  bot_replies     INT UNSIGNED NOT NULL DEFAULT 0,
  ask_count       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_ask_at     INT UNSIGNED NULL,
  invalid_count   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_confirm_at INT UNSIGNED NULL,
  human_until     INT UNSIGNED NULL,                  -- bot silent until this unix time
  handover_sent   TINYINT(1)   NOT NULL DEFAULT 0,
  needs_human     TINYINT(1)   NOT NULL DEFAULT 0,
  ad_context      TEXT         NULL,                  -- JSON: ad_id, ad_title, ref, source, post_id
  first_message   TEXT         NULL,
  last_message    TEXT         NULL,
  first_seen      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_conv (page_id, psid),
  KEY idx_human (needs_human, last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mb_leads (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  page_id         VARCHAR(32)  NOT NULL,
  psid            VARCHAR(40)  NOT NULL,
  customer_name   VARCHAR(191) NULL,
  phone           VARCHAR(10)  NOT NULL,
  alt_phones      VARCHAR(255) NULL,                  -- earlier numbers if customer sent a new one
  context         TEXT         NULL,                  -- ad title + what the customer asked
  ad_context      TEXT         NULL,
  status          ENUM('new','called','no_answer','ordered','not_interested','invalid') NOT NULL DEFAULT 'new',
  notes           TEXT         NULL,
  assigned_to     VARCHAR(64)  NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lead (page_id, psid),
  KEY idx_status (status, created_at),
  KEY idx_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mb_messages (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  page_id         VARCHAR(32)  NOT NULL,
  psid            VARCHAR(40)  NOT NULL,
  direction       ENUM('in','out','echo') NOT NULL,
  mid             VARCHAR(191) NULL,
  body            TEXT         NULL,
  intent          VARCHAR(24)  NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mid (mid),                            -- Meta retries webhooks: dedupe on message id
  KEY idx_conv (page_id, psid, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
