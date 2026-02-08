# Implementation Order & Agent Execution Plan

## Phase 1: Foundation (Agent 1, then Agent 2)

### Agent 1: Project Setup, Database & Auth

**What it does:** Creates the project structure, database tables, and shared includes.

**Agent prompt:**
```
You are setting up the in.peoples.ru community platform from scratch.

Server: PHP 8.1 on Ubuntu 22.04, Apache, MySQL (existing `peoplesru` database, cp1251 charset).
Project path: /usr/local/www/in.peoples.ru/www/

Read these docs for context:
- steps/00-overview.md — project vision and content flow
- steps/01-architecture.md — directory layout and architecture
- steps/02-database-schema.md — 4 new tables to create
- steps/04-auth-system.md — auth implementation details

Tasks:
1. Create project directory structure per 01-architecture.md
2. Write SOURCE/MIGRATIONS/ SQL files for the 4 tables (users, user_sessions, user_submissions, moderation_log)
3. Build all files in www/includes/ (db.php, auth.php, session.php, encoding.php, validation.php, permissions.php, csrf.php, response.php, upload.php)
4. Create www/.htaccess with mod_rewrite routing
5. Create www/uploads/ with .htaccess disabling PHP execution
6. Set up www/assets/ with Bootstrap 5, jQuery, TinyMCE
7. Create .github/workflows/deploy.yml for GitHub Actions
8. Create composer.json
9. Create seed_admin_user.sql
10. Run migrations to create tables

Key constraints:
- PHP 8.1 features OK (match, named args, readonly, enums)
- Database charset: cp1251 — all includes must handle UTF-8↔cp1251
- PDO with prepared statements only
- password_hash() with PASSWORD_DEFAULT for passwords
- Session cookies: HttpOnly, SameSite=Lax
```

### Agent 2: API Backend

**What it does:** Builds all REST API endpoints.

**Agent prompt:**
```
You are building the REST API for in.peoples.ru.

PHP 8.1, MySQL (peoplesru DB, cp1251).
Shared includes are at www/includes/ (built by Agent 1).

Read these docs:
- steps/05-api-design.md — full endpoint spec with request/response formats
- steps/02-database-schema.md — table structure, especially user_submissions
- steps/00-overview.md — content flow and peoples_section mapping

Tasks:
1. Create www/api/v1/ directory structure
2. Build config.php — API bootstrap, CORS, error handler
3. Build auth endpoints (register, login, logout, profile)
4. Build submission endpoints (list, create, view, update, delete)
5. Build person search endpoint (query existing persons + peoplesru_search_person tables)
6. Build photo upload endpoint (uses includes/upload.php)
7. Build moderation endpoints (queue, review with approval handler, users, stats)
8. Build comment endpoints (list, create, delete)

Critical: The approval handler must:
- Read peoples_section.table_name for the submission's section_id
- Use match() to INSERT into the correct target table (histories, photo, news, etc.)
- Set user_submissions.published_id to the new row's ID
- Log to moderation_log

All endpoints:
- Return JSON {success, data} or {success: false, error: {code, message}}
- Convert DB output cp1251→UTF-8 via includes/encoding.php
- Validate CSRF on POST/PUT/DELETE
- Use prepared statements only
```

---

## Phase 2: Core UI (Agents 3 & 4 — Parallel)

### Agent 3: User Portal

**Agent prompt:**
```
You are building the user-facing portal for in.peoples.ru.

PHP 8.1, Bootstrap 5, TinyMCE for rich text.
API is at www/api/v1/ — use AJAX calls to interact with it.
Shared includes at www/includes/.
Read steps/06-user-ui.md for wireframes and component specs.

Tasks:
1. Build www/user/login.php and register.php
2. Build www/user/index.php — dashboard
3. Build www/user/profile.php — view/edit
4. Build www/user/submit.php — universal submission form
   - Section dropdown populated from peoples_section
   - Person autocomplete (debounced, hits /api/v1/persons/search)
   - TinyMCE editor (limited toolbar)
   - Photo drag-and-drop upload zone
   - Autosave drafts every 60s
5. Build www/user/submissions.php — list with filters
6. Build www/user/view.php — single submission with moderator feedback
7. Build shared layout (header, footer, nav)
8. Build www/assets/css/app.css and www/assets/js/app.js

Design: Match peoples.ru (Bootstrap, red #d92228 accent).
All UI text in Russian.
Mobile-responsive.
```

### Agent 4: Moderation Panel

**Agent prompt:**
```
You are building the moderation panel for in.peoples.ru.

PHP 8.1, Bootstrap 5. Same design as user portal.
API is at www/api/v1/moderate/ — use AJAX.
Read steps/07-moderation-panel.md for wireframes and workflows.

Tasks:
1. Build www/moderate/index.php — dashboard (pending count, stats)
2. Build www/moderate/queue.php — review queue with filters
3. Build www/moderate/review.php — single submission review
   - Content preview
   - Approve / Revision / Reject buttons with note field
   - Photo grid for photo submissions
4. Build www/moderate/users.php — user management
5. Build www/moderate/log.php — audit log
6. Build keyboard shortcuts (J/K navigate, A approve, R reject)
7. Build shared layout (mod-header, mod-footer)

All pages require moderator role (use requireRole('moderator')).
All actions go through API endpoints.
```

---

## Phase 3: Polish (Agent 5)

### Agent 5: Testing & QA

**Agent prompt:**
```
You are the QA agent for in.peoples.ru.

The entire platform has been built. Your job: test everything, find bugs, fix critical issues.
Read all files in steps/ for specs.

Tasks:
1. Create tests/ directory with test runner
2. Test API auth: register, login, logout, profile CRUD
3. Test submissions: create for each section type, list, update, delete
4. Test approval: verify content appears in correct target table
5. Test encoding: "Тарковский", "ё", "ъ", mixed Russian/English
6. Test security: SQL injection, XSS, CSRF without token, PHP file upload
7. Test permissions: user accessing /moderate/ → 403
8. Test full workflow: register → submit biography → approve → check histories table
9. Verify GitHub Actions pipeline runs (lint check)
10. Fix critical bugs found during testing

Run: php tests/run_tests.php
```

---

## Execution Summary

```
Step 1: Agent 1 (setup + DB + includes)     ← must complete first
Step 2: Agent 2 (API)                        ← needs includes from Agent 1
Step 3: Agent 3 + Agent 4 (UI, parallel)     ← need API from Agent 2
Step 4: Agent 5 (testing)                    ← needs everything built
```

## Checkpoints

**After Agent 1:**
- [ ] 4 tables exist in MySQL
- [ ] `includes/db.php` connects and queries `persons` table
- [ ] Registration creates user, login returns session
- [ ] Encoding round-trip works

**After Agent 2:**
- [ ] POST `/api/v1/auth/register` creates user → 201
- [ ] POST `/api/v1/submissions/` creates submission → 201
- [ ] GET `/api/v1/persons/search?q=Пушкин` returns results
- [ ] POST `/api/v1/moderate/review` with approve moves content to target table

**After Agents 3 & 4:**
- [ ] User can register, log in, see dashboard
- [ ] User can submit a biography with person autocomplete
- [ ] Moderator can see queue, approve/reject
- [ ] Mobile layout works

**After Agent 5:**
- [ ] All API tests pass
- [ ] Encoding tests pass
- [ ] Security tests pass
- [ ] Full workflow test passes
