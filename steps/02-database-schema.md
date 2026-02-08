# Database Schema: New Tables (4 tables only)

All new tables live in the existing `peoplesru` database.
All use `CHARACTER SET cp1251 COLLATE cp1251_general_ci` to match existing schema.

Content is NOT stored in new tables — it goes directly into existing tables
(`histories`, `photo`, `news`, etc.) via the `peoples_section` mapping after moderation.

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

## 4. moderation_log (Audit Trail)

```sql
CREATE TABLE moderation_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    moderator_id    INT UNSIGNED NOT NULL,
    action          ENUM('approve', 'reject', 'request_revision', 'ban_user', 'unban_user', 'promote', 'demote') NOT NULL,
    target_type     VARCHAR(50) NOT NULL,       -- 'submission', 'user'
    target_id       INT UNSIGNED NOT NULL,
    note            TEXT DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_moderator (moderator_id),
    KEY idx_target (target_type, target_id),
    KEY idx_created (created_at),
    CONSTRAINT fk_modlog_moderator FOREIGN KEY (moderator_id) REFERENCES users(id)
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
```

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
└── seed_admin_user.sql
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

## No Additional Tables Needed

- **No separate content tables** — existing tables handle all content
- **No reputation tables** — `users.reputation` INT field is enough for now; add tables later if gamification grows
- **No badge tables** — not needed for v1
- **No version history tables** — not needed for v1 (can add later)
