# Database Schema: New Tables (9 tables)

All new tables live in the existing `peoplesru` database.
All use `CHARACTER SET cp1251 COLLATE cp1251_general_ci` to match existing schema.

Content submissions are staged in `user_submissions`, then published into existing tables
(`histories`, `photo`, `news`, etc.) via the `peoples_section` mapping after moderation.

Person suggestions are staged in a **separate** `user_person_suggestions` table and follow
a 3-step flow: User → Moderator (content quality) → Admin (push to `persons` table).

---

## 1. users (User Accounts)

```sql
CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(50)  NOT NULL,
    email           VARCHAR(255) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    display_name    VARCHAR(100) NOT NULL,
    avatar_path     VARCHAR(255) DEFAULT NULL,
    role            ENUM('user', 'moderator', 'admin') DEFAULT 'user',
    status          ENUM('active', 'banned', 'suspended') DEFAULT 'active',
    reputation      INT DEFAULT 0,
    bio             TEXT DEFAULT NULL,
    last_login      DATETIME DEFAULT NULL,
    login_ip        VARCHAR(45) DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY idx_username (username),
    UNIQUE KEY idx_email (email),
    KEY idx_role (role),
    KEY idx_status (status)
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
```

## 2. user_sessions (Server-side Sessions)

```sql
CREATE TABLE user_sessions (
    id              VARCHAR(128) PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    ip_address      VARCHAR(45) NOT NULL,
    user_agent      VARCHAR(255) DEFAULT NULL,
    last_activity   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_user_id (user_id),
    KEY idx_last_activity (last_activity),
    CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
```

## 3. user_submissions (Moderation Queue / Staging)

This is the central staging table. Content lives here while pending review.
On approval, it gets INSERTed into the real target table (looked up via `peoples_section.table_name`).

```sql
CREATE TABLE user_submissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    section_id      INT NOT NULL,              -- FK to peoples_section.id (2=histories, 3=photo, 4=news, etc.)
    KodPersons      INT DEFAULT NULL,          -- FK to persons.Persons_id
    title           VARCHAR(500) DEFAULT NULL,
    content         MEDIUMTEXT DEFAULT NULL,
    epigraph        VARCHAR(1000) DEFAULT NULL,
    source_url      VARCHAR(500) DEFAULT NULL,
    photo_path      VARCHAR(500) DEFAULT NULL,  -- for photo submissions: temp file path
    status          ENUM('draft', 'pending', 'approved', 'rejected', 'revision_requested') DEFAULT 'draft',
    moderator_id    INT UNSIGNED DEFAULT NULL,
    moderator_note  TEXT DEFAULT NULL,
    reviewed_at     DATETIME DEFAULT NULL,
    published_id    INT DEFAULT NULL,           -- ID in the target table after approval
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_user_id (user_id),
    KEY idx_status (status),
    KEY idx_section_id (section_id),
    KEY idx_person (KodPersons),
    KEY idx_created (created_at),
    CONSTRAINT fk_submission_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
```

**How `section_id` works:**
```
user_submissions.section_id = 2
  → peoples_section WHERE id=2 → table_name='histories'
  → On approve: INSERT INTO histories (...)
  → Set published_id = histories.Histories_id
```

## 4. users_moderation_log (Audit Trail)

```sql
CREATE TABLE users_moderation_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    moderator_id    INT UNSIGNED NOT NULL,
    action          ENUM('approve', 'reject', 'request_revision', 'ban_user', 'unban_user', 'promote', 'demote') NOT NULL,
    target_type     VARCHAR(50) NOT NULL,       -- 'submission', 'user', 'person_suggestion'
    target_id       INT UNSIGNED NOT NULL,
    note            TEXT DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_moderator (moderator_id),
    KEY idx_target (target_type, target_id),
    KEY idx_created (created_at),
    CONSTRAINT fk_modlog_moderator FOREIGN KEY (moderator_id) REFERENCES users(id)
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
```

## 5. user_person_suggestions (Person Suggestion Staging)

Separate from `user_submissions`. Stores structured person data for the 3-step approval flow:
User submits → Moderator checks content quality → Admin checks for duplicates and pushes to `persons`.

```sql
CREATE TABLE user_person_suggestions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,

    -- Person fields (structured data)
    NameRus             VARCHAR(255) NOT NULL,
    SurNameRus          VARCHAR(255) NOT NULL,
    NameEngl            VARCHAR(255) DEFAULT NULL,
    SurNameEngl         VARCHAR(255) DEFAULT NULL,
    DateIn              DATE DEFAULT NULL,
    DateOut             DATE DEFAULT NULL,
    gender              CHAR(1) DEFAULT NULL,
    TownIn              VARCHAR(255) DEFAULT NULL,
    cc2born             CHAR(2) DEFAULT NULL,
    cc2dead             CHAR(2) DEFAULT NULL,
    cc2                 CHAR(2) DEFAULT NULL,

    -- Article fields
    title               VARCHAR(500) DEFAULT NULL,  -- Person's rank/occupation (Звание) → persons.Epigraph
    epigraph            VARCHAR(1000) DEFAULT NULL,  -- Short article description → histories.Epigraph

    -- Content
    biography           MEDIUMTEXT DEFAULT NULL,    -- Biography text written by user
    source_url          VARCHAR(500) DEFAULT NULL,
    person_photo_path   VARCHAR(500) DEFAULT NULL,  -- Portrait photo (temp storage)
    photo_path          VARCHAR(500) DEFAULT NULL,  -- Article photo (temp storage)

    -- Moderation (moderator checks content quality)
    status              ENUM('pending', 'approved', 'rejected', 'revision_requested', 'published') DEFAULT 'pending',
    moderator_id        INT UNSIGNED DEFAULT NULL,
    moderator_note      TEXT DEFAULT NULL,
    reviewed_at         DATETIME DEFAULT NULL,

    -- Admin push (admin checks duplicates, pushes to real persons table)
    published_person_id INT DEFAULT NULL,           -- Persons_id after admin pushes to persons
    published_at        DATETIME DEFAULT NULL,

    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_user_id (user_id),
    KEY idx_status (status),
    KEY idx_created (created_at),
    CONSTRAINT fk_person_suggestion_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
```

**Why a separate table (not `user_submissions`)?**
- Never trust user data directly into production tables
- Person suggestions need an extra admin step (duplicate check before push)
- Structured columns (NameRus, SurNameRus, etc.) instead of JSON blob
- Person created with `approve='NO'` — admin sets AllUrlInSity, path, KodStructure later

---

## Migration Files

```
SOURCE/MIGRATIONS/
├── 001_create_users.sql
├── 001_rollback_users.sql
├── 002_create_user_sessions.sql
├── 002_rollback_user_sessions.sql
├── 003_create_user_submissions.sql
├── 003_rollback_user_submissions.sql
├── 004_create_moderation_log.sql
├── 004_rollback_moderation_log.sql
├── 005_seed_admin_user.sql
├── 006_add_person_data.sql          -- creates user_person_suggestions
├── 006_rollback_person_data.sql
├── 010_create_booking_categories.sql   -- booking feature
├── 010_rollback_booking_categories.sql
├── 011_create_booking_persons.sql
├── 011_rollback_booking_persons.sql
├── 012_create_booking_requests.sql
├── 012_rollback_booking_requests.sql
├── 013_create_booking_request_status_log.sql
└── 013_rollback_booking_request_status_log.sql
```

## Approval Logic Per Section

When moderator approves a submission, the API looks up `peoples_section` and INSERTs into the correct table:

| section_id | table_name      | INSERT fields needed                                              |
|------------|-----------------|-------------------------------------------------------------------|
| 2          | histories       | KodPersons, Content, Epigraph, NameURLArticle, date_pub          |
| 3          | photo           | KodPersons, NamePhoto, path_photo, date_registration             |
| 4          | news            | KodPersons, title(?), content, approve='YES', date_registration  |
| 5          | peoples_forum   | KodPersons, Title, Message, NameAuthor, date_registration        |
| 7          | songs           | KodPersons, content, date_registration                           |
| 8          | Facts           | KodPersons, content, date_registration                           |
| 19         | poetry          | KodPersons, content, date_registration                           |

Each target table has different columns. The approval handler needs section-specific INSERT logic (a switch/match on section_id).

## Person Suggestion Flow

```
user_person_suggestions.status:
  pending → (moderator approves) → approved → (admin pushes) → published
  pending → (moderator rejects) → rejected
  pending → (moderator requests revision) → revision_requested → pending (user resubmits)
```

On admin push (`person-push.php`):
1. INSERT into `persons` (approve='NO', admin sets URL/path/structure later)
2. INSERT into `histories` (biography linked to new Persons_id)
3. UPDATE `user_person_suggestions.published_person_id` = new Persons_id
4. UPDATE `users.reputation` += 10
5. INSERT into `users_moderation_log`

## 6. booking_categories (Booking Category Taxonomy)

```sql
CREATE TABLE booking_categories (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    slug            VARCHAR(100) NOT NULL,
    description     VARCHAR(500) DEFAULT NULL,
    icon            VARCHAR(50) DEFAULT NULL,          -- Bootstrap Icon class
    sort_order      INT UNSIGNED NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY idx_slug (slug)
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
```

Seeded with 8 categories: Ведущие, Певцы, Блогеры, Комики, DJ, Актёры, Спортсмены, Писатели.

## 7. booking_persons (Person ↔ Category Linking)

```sql
CREATE TABLE booking_persons (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id       INT NOT NULL,                      -- persons.Persons_id
    category_id     INT UNSIGNED NOT NULL,             -- FK → booking_categories
    price_from      INT UNSIGNED DEFAULT NULL,
    price_to        INT UNSIGNED DEFAULT NULL,
    description     TEXT DEFAULT NULL,
    short_desc      VARCHAR(500) DEFAULT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    is_featured     TINYINT(1) NOT NULL DEFAULT 0,
    sort_order      INT UNSIGNED NOT NULL DEFAULT 0,
    added_by        INT UNSIGNED DEFAULT NULL,         -- FK → users

    UNIQUE KEY idx_person_category (person_id, category_id)
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
```

## 8. booking_requests (Customer Inquiries)

```sql
CREATE TABLE booking_requests (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id           INT DEFAULT NULL,
    booking_person_id   INT UNSIGNED DEFAULT NULL,
    client_name         VARCHAR(255) NOT NULL,
    client_phone        VARCHAR(50) NOT NULL,
    client_email        VARCHAR(255) DEFAULT NULL,
    client_company      VARCHAR(255) DEFAULT NULL,
    event_type          VARCHAR(100) DEFAULT NULL,
    event_date          DATE DEFAULT NULL,
    event_city          VARCHAR(255) DEFAULT NULL,
    event_venue         VARCHAR(500) DEFAULT NULL,
    guest_count         INT UNSIGNED DEFAULT NULL,
    budget_from         INT UNSIGNED DEFAULT NULL,
    budget_to           INT UNSIGNED DEFAULT NULL,
    message             TEXT DEFAULT NULL,
    status              ENUM('new','in_progress','contacted','completed','cancelled','spam') DEFAULT 'new',
    admin_note          TEXT DEFAULT NULL,
    assigned_to         INT UNSIGNED DEFAULT NULL,
    ip_address          VARCHAR(45) DEFAULT NULL,
    user_agent          VARCHAR(500) DEFAULT NULL
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
```

## 9. booking_request_status_log (Status Audit Trail)

```sql
CREATE TABLE booking_request_status_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id      INT UNSIGNED NOT NULL,             -- FK → booking_requests
    old_status      VARCHAR(20) DEFAULT NULL,
    new_status      VARCHAR(20) NOT NULL,
    note            TEXT DEFAULT NULL,
    changed_by      INT UNSIGNED DEFAULT NULL,         -- FK → users
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
```

---

## Notes

- **No reputation tables** — `users.reputation` INT field is enough for now; add tables later if gamification grows
- **No badge tables** — not needed for v1
- **No version history tables** — not needed for v1 (can add later)
