# Agent Definitions & Responsibilities

## Simplified Agent Architecture

With only 4 new tables and content going into existing tables, we need **5 agents** instead of 8:

```
Phase 1 (Foundation)          Phase 2 (Core)               Phase 3 (Polish)
┌───────────────────┐        ┌───────────────────┐        ┌───────────────────┐
│ Agent 1:          │        │ Agent 3:          │        │ Agent 5:          │
│ Project Setup +   │───────▶│ User Portal       │───────▶│ Testing & QA      │
│ Database + Auth   │        │ (forms, dashboard)│        │                   │
└───────────────────┘        └───────────────────┘        └───────────────────┘
                             ┌───────────────────┐
                      ──────▶│ Agent 4:          │───────▶
                             │ Moderation Panel  │
                             └───────────────────┘
┌───────────────────┐
│ Agent 2:          │───────▶ (used by Agents 3, 4)
│ API Backend       │
└───────────────────┘
```

---

## Agent 1: Project Setup, Database & Auth

**Phase:** 1 (Foundation)
**Depends on:** Nothing
**Blocks:** Agents 2, 3, 4

### Responsibilities
1. Initialize the project at `/usr/local/www/in.peoples.ru/www/`
2. Create git repo structure with `.github/workflows/deploy.yml`
3. Create the 4 new tables in `peoplesru` database (migrations)
4. Build `www/includes/` shared library:
   - `db.php` — PDO singleton connecting to `peoplesru`, cp1251
   - `auth.php` — register, login, logout, getCurrentUser
   - `session.php` — session management via `user_sessions` table
   - `encoding.php` — UTF-8 ↔ cp1251 helpers (toDb/fromDb)
   - `validation.php` — email, password, username, HTML sanitization
   - `permissions.php` — requireRole(), isLoggedIn(), isModerator()
   - `csrf.php` — token generation & validation
   - `response.php` — JSON helpers with proper UTF-8 headers
   - `upload.php` — image validation, resize, thumbnail, EXIF strip
5. Create `.htaccess` with routing rules
6. Create `www/assets/` with Bootstrap CSS/JS
7. Seed admin user

### Deliverables
```
www/
├── .htaccess
├── includes/
│   ├── db.php
│   ├── auth.php
│   ├── session.php
│   ├── encoding.php
│   ├── validation.php
│   ├── permissions.php
│   ├── csrf.php
│   ├── response.php
│   └── upload.php
├── assets/
│   ├── css/bootstrap.min.css
│   ├── css/app.css
│   ├── js/bootstrap.min.js
│   ├── js/jquery.min.js
│   └── vendor/tinymce/
├── uploads/
│   ├── temp/
│   └── .htaccess          # disable PHP execution
SOURCE/MIGRATIONS/
├── 001-004 migration + rollback files
└── seed_admin_user.sql
.github/workflows/deploy.yml
composer.json
```

### Acceptance Criteria
- `db.php` connects and can query `persons` table
- Registration creates a user in `users` table
- Login returns valid session, logout destroys it
- Encoding round-trip: "Тарковский" → cp1251 → UTF-8 identical
- CSRF token validates correctly
- `.htaccess` routes work (no 404 on clean URLs)

---

## Agent 2: API Backend

**Phase:** 1-2 (starts after Agent 1's includes are ready)
**Depends on:** Agent 1
**Blocks:** Agents 3, 4

### Responsibilities
Build all REST API endpoints at `www/api/v1/`:

**Auth endpoints:**
- POST `/auth/register` — create user account
- POST `/auth/login` — login, set session cookie
- POST `/auth/logout` — destroy session
- GET/PUT `/auth/profile` — get/update current user

**Submission endpoints:**
- GET `/submissions/` — list my submissions (filterable by status, section_id)
- POST `/submissions/` — create new submission
- GET `/submissions/{id}` — view single submission
- PUT `/submissions/{id}` — update draft/revision
- DELETE `/submissions/{id}` — withdraw submission

**Person endpoints:**
- GET `/persons/search?q=` — autocomplete search (queries `persons` and `peoplesru_search_person`)
- GET `/persons/{id}` — person detail

**Photo endpoints:**
- POST `/photos/upload` — upload image(s) for a submission
- DELETE `/photos/{id}` — remove uploaded photo

**Moderation endpoints:**
- GET `/moderate/queue` — pending submissions list
- POST `/moderate/review` — approve/reject/revision
- GET `/moderate/users` — user list
- PUT `/moderate/users/{id}` — ban/promote
- GET `/moderate/stats` — dashboard statistics

### Key Logic: Approval Handler
```php
// On approve:
$section = query("SELECT table_name FROM peoples_section WHERE id = ?", $submission['section_id']);

match($submission['section_id']) {
    2 => insertIntoHistories($submission),
    3 => insertIntoPhoto($submission),
    4 => insertIntoNews($submission),
    5 => insertIntoForum($submission),
    7 => insertIntoSongs($submission),
    8 => insertIntoFacts($submission),
    19 => insertIntoPoetry($submission),
};
```

### Acceptance Criteria
- All endpoints return consistent JSON `{success, data, error}`
- Person search returns results from existing 175k persons
- Submission CRUD works for all section types
- Approval correctly INSERTs into the target table (`histories`, `photo`, `news`, etc.)
- Moderation endpoints restricted to moderator/admin roles
- All input validated, all output UTF-8 encoded

---

## Agent 3: User Portal

**Phase:** 2 (Core)
**Depends on:** Agents 1, 2
**Blocks:** Agent 5

### Responsibilities
Build all user-facing pages at `www/user/`:

1. **login.php** — login form
2. **register.php** — registration form
3. **index.php** — dashboard (stats, recent submissions, quick actions)
4. **profile.php** — view/edit profile
5. **submit.php** — universal submission form
   - Section selector (biography, news, photo, fact, quote, poetry, song)
   - Person autocomplete (hits `/api/v1/persons/search`)
   - TinyMCE rich text editor for content
   - Photo upload zone (for photo submissions)
   - Draft autosave via AJAX
6. **submissions.php** — list all my submissions with status filters
7. **view.php** — view single submission, moderator feedback, edit if revision requested
8. **Shared layout** — header/footer matching peoples.ru design

### Key Design Decisions
- **One submit form** (`submit.php`) instead of separate forms per type — section selector shows/hides relevant fields
- **Person autocomplete** — debounced search, shows name + dates + thumbnail
- **TinyMCE** — limited toolbar: bold, italic, links, headings, lists (no scripts/iframes)
- **Photo upload** — drag-and-drop with preview, max 10 files
- **Russian UI** — all text in Russian

### Acceptance Criteria
- User can register and log in
- Dashboard shows submission counts and recent activity
- User can search for a person, pick a section, write content, and submit
- Photo upload works with drag-and-drop and shows previews
- Submissions list shows status (draft/pending/approved/rejected)
- Rejected submissions show moderator feedback
- Design matches peoples.ru (Bootstrap, red #d92228, same fonts)
- Mobile-responsive

---

## Agent 4: Moderation Panel

**Phase:** 2 (Core, parallel with Agent 3)
**Depends on:** Agents 1, 2
**Blocks:** Agent 5

### Responsibilities
Build moderation interface at `www/moderate/`:

1. **index.php** — dashboard (pending count, today's stats, recent actions, top contributors)
2. **queue.php** — submission review queue with filters (type, date, sort)
3. **review.php** — single submission review page
   - Content preview
   - Side-by-side diff for biography edits (if existing bio exists)
   - Approve / Request revision / Reject buttons
   - Moderator note field
   - For photos: thumbnail grid with per-photo approve/reject
4. **users.php** — user list with search, role/status filters, action buttons
5. **log.php** — moderation audit log (all actions with timestamps)

### Key Features
- Keyboard shortcuts: J/K navigate queue, A approve, R reject, E request revision
- Bulk actions: select multiple → approve/reject all
- All actions logged to `moderation_log`
- Restricted to role >= moderator

### Acceptance Criteria
- Queue shows only pending submissions, sorted oldest first
- Approve moves content to correct target table
- Reject saves moderator_note, user sees feedback
- User management allows ban/unban/promote
- Keyboard shortcuts work
- All actions create `moderation_log` entries

---

## Agent 5: Testing & QA

**Phase:** 3 (Polish)
**Depends on:** All other agents
**Blocks:** Nothing (final)

### Responsibilities
1. Write PHP test scripts in `tests/`
2. Test all API endpoints (auth, submissions, photos, moderation, persons)
3. Test encoding: Russian text through full pipeline (UTF-8 → cp1251 → UTF-8)
4. Test security: SQL injection, XSS, CSRF, file upload exploits
5. Test permissions: user can't access mod panel, mod can't promote to admin
6. Test full workflow: register → submit → moderate → check target table
7. Test GitHub Actions pipeline works (lint + deploy)

### Deliverables
```
tests/
├── run_tests.php
├── bootstrap.php
├── test_auth.php
├── test_submissions.php
├── test_photos.php
├── test_moderation.php
├── test_encoding.php
├── test_security.php
└── test_full_workflow.php
```

### Acceptance Criteria
- All tests pass
- Russian text survives encoding round-trip
- SQL injection attempts blocked
- XSS payloads sanitized
- Unauthorized access returns 403
- Full workflow: register → submit → approve → content in target table
