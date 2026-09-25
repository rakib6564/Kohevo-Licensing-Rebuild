-- Coaching plugin — install schema (Wave 1).
--
-- Wave 1 tables only: the profile (with embedded auto-computed metrics)
-- and the goals foundation. Later waves add: coaching_diary_entry,
-- coaching_diary_food, coaching_diary_photo, coaching_hydration,
-- coaching_activity, coaching_thread, coaching_message,
-- coaching_meal_structure, coaching_shopping_list, coaching_recipe,
-- coaching_challenge, coaching_summary.
--
-- Access control is delegated to the membership plugin — a client is
-- "in the program" when they hold an active membership whose plan
-- matches the setting `coaching.program_plan_id`. No new access table
-- here; see Coaching::isEnrolled().

-- ── Profile ─────────────────────────────────────────────────────────
-- One row per customer. Auto-computed metrics (BMI, BMR, TDEE) are
-- embedded here — recomputed on save, so we don't need a join to read
-- them. Kept nullable so a fresh row (from customer_registered) is a
-- blank slate the practitioner + client fill in over time.
CREATE TABLE IF NOT EXISTS `coaching_profile` (
    `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`              INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`            INT UNSIGNED NOT NULL,

    -- Demographics
    `dob`                    DATE NULL,
    `gender`                 ENUM('female','male','other','undisclosed') NULL,
    `height_cm`              DECIMAL(5,1) NULL,
    `weight_kg`              DECIMAL(5,1) NULL,

    -- Body measurements as JSON: chest, waist, hips, thighs, arms, etc.
    `body_measurements`      JSON NULL,
    `body_type`              VARCHAR(80) NULL,

    -- Diet + intolerances as JSON arrays / objects.
    `intolerances`           JSON NULL,
    `dietary_preferences`    JSON NULL,

    -- Medical section (reassuring copy in the client UI).
    `pathologies`            TEXT NULL,
    `ongoing_care`           TEXT NULL,
    `alternative_medicine`   TEXT NULL,
    `personal_issues`        TEXT NULL,
    `therapist_contact`      JSON NULL,

    -- Auto-computed on save. Hidden by default; toggleable per-client.
    `bmi`                    DECIMAL(5,2) NULL,
    `bmr`                    INT UNSIGNED NULL,
    `tdee`                   INT UNSIGNED NULL,
    `show_computed`          TINYINT(1) NOT NULL DEFAULT 0,
    `activity_factor`        DECIMAL(3,2) NOT NULL DEFAULT 1.40,

    -- Optional per-client enabling of the three optional modules.
    `has_meal_structure`     TINYINT(1) NOT NULL DEFAULT 0,
    `has_shopping_list`      TINYINT(1) NOT NULL DEFAULT 0,
    `has_recipes`            TINYINT(1) NOT NULL DEFAULT 0,

    `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_customer` (`tenant_id`, `customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Goals ───────────────────────────────────────────────────────────
-- A goal is authored by the practitioner and tracked by the client.
-- Scope determines cadence: daily goals get checked in every day,
-- weekly once a week, monthly once a month, general/personal are
-- narrative.
CREATE TABLE IF NOT EXISTS `coaching_goal` (
    `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`              INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`            INT UNSIGNED NOT NULL,

    `scope`                  ENUM('daily','weekly','monthly','general','personal') NOT NULL DEFAULT 'daily',
    `title`                  VARCHAR(200) NOT NULL,
    `description`            TEXT NULL,

    -- Optional numeric target ("do 3 cardiac coherences").
    `target_count`           INT UNSIGNED NULL,

    `sort_order`             INT NOT NULL DEFAULT 0,
    `is_active`              TINYINT(1) NOT NULL DEFAULT 1,
    `retired_at`             DATETIME NULL,
    `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `tenant_customer_active` (`tenant_id`, `customer_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- One check-in per (goal, day). Status has the three levels the client
-- asked for — plus 'exceeded' which is the whole reason for this design.
CREATE TABLE IF NOT EXISTS `coaching_goal_checkin` (
    `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`              INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`            INT UNSIGNED NOT NULL,
    `goal_id`                INT UNSIGNED NOT NULL,

    `day`                    DATE NOT NULL,
    `status`                 ENUM('not_achieved','partial','achieved','exceeded') NOT NULL,
    `note`                   TEXT NULL,

    `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `goal_day` (`goal_id`, `day`),
    KEY `tenant_customer_day` (`tenant_id`, `customer_id`, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- "Additional actions" free-text — things the client did that weren't
-- planned goals. Feeds the "you added 15 min of walking today" insights.
CREATE TABLE IF NOT EXISTS `coaching_extra_action` (
    `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`              INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`            INT UNSIGNED NOT NULL,

    `day`                    DATE NOT NULL,
    `action_text`            VARCHAR(500) NOT NULL,

    `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `tenant_customer_day` (`tenant_id`, `customer_id`, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Wave 2 — food diary / hydration / activity / emotions ───────────
-- Each diary entry represents one meal, snack, or logged moment. Emotion
-- and hunger/satiety are on the entry itself; the foods eaten are line
-- items in coaching_diary_food (many per entry). Photos hang off the
-- entry as well (many per entry).

CREATE TABLE IF NOT EXISTS `coaching_diary_entry` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`      INT UNSIGNED NOT NULL,

    `day`              DATE NOT NULL,               -- indexable rollup key
    `meal_type`        ENUM('breakfast','lunch','dinner','snack','binge','drink','other') NOT NULL DEFAULT 'other',
    `started_at`       TIME NULL,
    `duration_min`     INT UNSIGNED NULL,

    -- Emotion + hunger scale (1-5). Optional so the client can be quick.
    `emotion`          ENUM('joy','stress','fatigue','anxiety','boredom','anger','sadness','serenity','neutrality','other') NULL,
    `emotion_note`     VARCHAR(500) NULL,
    `hunger_before`    TINYINT UNSIGNED NULL,        -- 1-5, 1=starving 5=not hungry
    `satiety_after`    TINYINT UNSIGNED NULL,        -- 1-5, 1=still hungry 5=overfull

    -- Context.
    `context`          ENUM('home','work','friends','family','restaurant','commute','other') NULL,
    `context_note`     VARCHAR(200) NULL,

    -- Free-text describing quantities + any other notes.
    `quantity_note`    VARCHAR(300) NULL,
    `notes`            TEXT NULL,

    -- Denormalized snapshot summary used by the practitioner feed to
    -- render row headings without joining every food line item.
    `summary`          VARCHAR(300) NULL,

    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `tenant_customer_day` (`tenant_id`, `customer_id`, `day`),
    KEY `tenant_day` (`tenant_id`, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `coaching_diary_food` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
    `entry_id`         INT UNSIGNED NOT NULL,

    `name`             VARCHAR(200) NOT NULL,       -- free text ("green salad")
    `category`         ENUM('fruits_vegetables','starches','proteins','dairy','fats','pleasure','other') NULL,
    `is_pleasure_food` TINYINT(1) NOT NULL DEFAULT 0,
    `is_balanced`      TINYINT(1) NOT NULL DEFAULT 0,  -- flagged by practitioner
    `sort_order`       INT NOT NULL DEFAULT 0,

    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `entry_idx` (`entry_id`),
    KEY `tenant_category` (`tenant_id`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Photos stored as file paths under uploads/coaching-diary/. Media library
-- integration is a Wave 2.5 polish; using plain paths keeps Wave 2 shippable.
CREATE TABLE IF NOT EXISTS `coaching_diary_photo` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
    `entry_id`         INT UNSIGNED NOT NULL,

    `file_path`        VARCHAR(500) NOT NULL,       -- relative to SLATE_ROOT
    `caption`          VARCHAR(200) NULL,
    `sort_order`       INT NOT NULL DEFAULT 0,

    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `entry_idx` (`entry_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Daily hydration rollup — one row per (customer, day). liters wins over
-- glass_count when both are set (the client-side UI picks a mode).
CREATE TABLE IF NOT EXISTS `coaching_hydration` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`      INT UNSIGNED NOT NULL,

    `day`              DATE NOT NULL,
    `liters`           DECIMAL(4,2) NOT NULL DEFAULT 0,
    `glass_count`      INT UNSIGNED NOT NULL DEFAULT 0,
    `other_drinks`     VARCHAR(500) NULL,

    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_customer_day` (`tenant_id`, `customer_id`, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Physical activity, one row per session (not rolled up per day —
-- keeps a walk + a yoga session distinguishable for charts).
CREATE TABLE IF NOT EXISTS `coaching_activity` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`      INT UNSIGNED NOT NULL,

    `day`              DATE NOT NULL,
    `kind`             VARCHAR(60) NOT NULL,
    `duration_min`     INT UNSIGNED NULL,
    `notes`            VARCHAR(300) NULL,

    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `tenant_customer_day` (`tenant_id`, `customer_id`, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Wave 4 — chat ───────────────────────────────────────────────────
-- One thread per customer (auto-provisioned on customer_registered).
CREATE TABLE IF NOT EXISTS `coaching_thread` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`           INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`         INT UNSIGNED NOT NULL,

    `last_message_at`     DATETIME NULL,
    `unread_practitioner` INT UNSIGNED NOT NULL DEFAULT 0,
    `unread_customer`     INT UNSIGNED NOT NULL DEFAULT 0,

    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_customer` (`tenant_id`, `customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Scheduled messages have send_at set + sent_at NULL. Cron flips sent_at
-- when due. Live messages have send_at NULL and sent_at at insert time.
CREATE TABLE IF NOT EXISTS `coaching_message` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`           INT UNSIGNED NOT NULL DEFAULT 1,
    `thread_id`           INT UNSIGNED NOT NULL,

    `sender`              ENUM('practitioner','customer') NOT NULL,
    `body`                TEXT NULL,
    `photo_path`          VARCHAR(500) NULL,

    `send_at`             DATETIME NULL,
    `sent_at`             DATETIME NULL,
    `seen_at`             DATETIME NULL,

    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `thread_created` (`thread_id`, `created_at`),
    KEY `pending_send` (`sent_at`, `send_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Wave 5 — meal structure, shopping list, recipes ──────────────────
-- Each of the three tables uses the same "customer_id NULL = library
-- template" pattern. "Copy to client" duplicates the row with a customer_id
-- set. Client sees only rows where customer_id = their own id. Editing a
-- copy never touches the library original.

CREATE TABLE IF NOT EXISTS `coaching_meal_structure` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`      INT UNSIGNED NULL,        -- NULL = library template

    `title`            VARCHAR(200) NOT NULL,
    `slot`             ENUM('breakfast','lunch','dinner','snack','note') NOT NULL DEFAULT 'note',
    `notes_html`       TEXT NULL,               -- rich HTML the client sees
    `tags_json`        JSON NULL,               -- ["gluten-free","low-gi",…]

    `sort_order`       INT NOT NULL DEFAULT 0,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `tenant_customer` (`tenant_id`, `customer_id`),
    KEY `tenant_library` (`tenant_id`, `customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Shopping list. sections_json holds the structured content:
--   [{"heading": "Staples",       "items": ["…","…"]},
--    {"heading": "Suitable alt.", "items": ["…"]},
--    {"heading": "Avoid",         "items": ["…"]}]
CREATE TABLE IF NOT EXISTS `coaching_shopping_list` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`      INT UNSIGNED NULL,

    `name`             VARCHAR(200) NOT NULL,
    `sections_json`    JSON NULL,
    `tags_json`        JSON NULL,

    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `tenant_customer` (`tenant_id`, `customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Recipes are bidirectional: authored either by the practitioner or by a
-- customer. author='customer' + customer_id set = a recipe the client
-- submitted to the practitioner. Library recipes are author='practitioner'
-- + customer_id NULL. Assigned copies are author='practitioner' + customer_id set.
CREATE TABLE IF NOT EXISTS `coaching_recipe` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`      INT UNSIGNED NULL,

    `author`           ENUM('practitioner','customer') NOT NULL DEFAULT 'practitioner',
    `title`            VARCHAR(200) NOT NULL,
    `photo_path`       VARCHAR(500) NULL,
    `ingredients_json` JSON NULL,
    `instructions_html` TEXT NULL,
    `video_url`        VARCHAR(500) NULL,
    `notes`            TEXT NULL,
    `tags_json`        JSON NULL,

    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `tenant_customer` (`tenant_id`, `customer_id`),
    KEY `tenant_author` (`tenant_id`, `author`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Wave 6 — motivation & end-of-program summary ─────────────────────
-- Challenges + exercises the practitioner sends to a client. Scheduled
-- encouragement messages continue to use the chat's send_at flow — no
-- new entity needed for those. Client marks a challenge complete;
-- practitioner sees who's engaging.
CREATE TABLE IF NOT EXISTS `coaching_challenge` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`      INT UNSIGNED NOT NULL,

    `kind`             ENUM('challenge','exercise') NOT NULL DEFAULT 'challenge',
    `title`            VARCHAR(200) NOT NULL,
    `description_html` TEXT NULL,
    `video_url`        VARCHAR(500) NULL,

    `starts_at`        DATE NOT NULL,
    `ends_at`          DATE NULL,           -- open-ended if NULL
    `completed_at`     DATETIME NULL,       -- client marks this
    `client_note`      VARCHAR(500) NULL,   -- client's reflection on completion

    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `tenant_customer` (`tenant_id`, `customer_id`),
    KEY `tenant_active` (`tenant_id`, `completed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Materialized end-of-program summary. Generated by cron 3 days before
-- membership expiry so the client (and practitioner) can revisit after
-- program end even when the membership is inactive.
CREATE TABLE IF NOT EXISTS `coaching_summary` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`        INT UNSIGNED NOT NULL DEFAULT 1,
    `customer_id`      INT UNSIGNED NOT NULL,

    `period_start`     DATE NULL,
    `period_end`       DATE NULL,
    `summary_json`     JSON NULL,           -- {successes[], metrics{}, recommendations, message}

    `generated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notified_at`      DATETIME NULL,       -- when we surfaced it to the client

    PRIMARY KEY (`id`),
    UNIQUE KEY `tenant_customer` (`tenant_id`, `customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
