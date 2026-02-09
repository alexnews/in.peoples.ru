# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**in.peoples.ru** — Community platform where users submit biographies, news, photos, facts, poetry, and more about celebrities. Content goes through moderation, then gets published into the existing peoples.ru database tables.

## Tech Stack

- **PHP 8.1** on Ubuntu 22.04
- **Apache 2.x** with mod_rewrite
- **MySQL** — existing `peoplesru` database, charset **cp1251** (Windows Cyrillic)
- **Frontend:** Bootstrap 5, jQuery, TinyMCE
- **No framework** — vanilla PHP
- **Deployment:** GitHub Actions → git pull on server

## Server Paths

- **Project:** /usr/local/www/in.peoples.ru/
- **Public:** /usr/local/www/in.peoples.ru/www/ (Apache DocumentRoot)
- **Main site photos:** /usr/local/www/peoples.ru/www/photo/ (write access needed on approval)

## Architecture

```
www/                        # Apache DocumentRoot
├── api/v1/                 # REST API (JSON, UTF-8)
│   ├── auth/               # register, login, logout, profile
│   ├── submissions/        # CRUD for user content submissions
│   ├── photos/             # upload, list, delete
│   ├── persons/            # search existing persons table
│   ├── comments/           # list, create, delete
│   └── moderate/           # queue, review, users, stats, person-review, person-push
├── user/                   # User portal (auth required)
├── moderate/               # Moderation panel (moderator+ role)
├── includes/               # Shared PHP: db, auth, encoding, validation
├── assets/                 # CSS, JS, vendor libs
└── uploads/temp/           # Temp photo storage before approval
```

## Database

Connects to the existing `peoplesru` database. **Does NOT create a new database.**

### New tables (5):
- `users` — user accounts
- `user_sessions` — server-side session storage
- `user_submissions` — moderation staging queue for content
- `users_moderation_log` — audit trail
- `user_person_suggestions` — person suggestion staging (separate 3-step flow)

### Content flow:
User submits content → `user_submissions` (staging) → moderator approves → INSERT into **existing** target table.

### Person suggestion flow:
User suggests person → `user_person_suggestions` → moderator approves (content quality) → admin checks duplicates and pushes → INSERT into `persons` + `histories`.

The target table is determined by `peoples_section.table_name`:
- section_id=2 → `histories` (biographies)
- section_id=3 → `photo`
- section_id=4 → `news`
- section_id=5 → `peoples_forum`
- section_id=7 → `songs`
- section_id=8 → `Facts`
- section_id=19 → `poetry`

## Critical: Encoding

Database is **cp1251**. Browser/API is **UTF-8**. All code must:
- Convert UTF-8 → cp1251 before writing to DB: `iconv('UTF-8', 'CP1251//TRANSLIT', $str)`
- Convert cp1251 → UTF-8 before sending to browser: `iconv('CP1251', 'UTF-8', $str)`
- Use `www/includes/encoding.php` helpers: `toDb()` and `fromDb()`
- PDO connection charset must be `cp1251`

## Build & Deploy

```bash
# Lint check (runs in GitHub Actions)
find www/ -name "*.php" -print0 | xargs -0 -n1 php -l

# Deploy: push to main → GitHub Actions → SSH git pull on server

# Run tests
php tests/run_tests.php
```

## Key Documentation

All specs are in `steps/`:
- `steps/00-overview.md` — vision and content flow
- `steps/01-architecture.md` — directory layout, request flow
- `steps/02-database-schema.md` — 4 new tables, approval logic per section
- `steps/03-agents.md` — agent definitions and acceptance criteria
- `steps/05-api-design.md` — full API endpoint spec
- `steps/06-user-ui.md` — user portal wireframes
- `steps/07-moderation-panel.md` — moderation panel design

## Roles

- `user` — can submit content, edit own drafts, comment
- `moderator` — can review queue, approve/reject, view user activity
- `admin` — all moderator permissions + manage users, change roles
