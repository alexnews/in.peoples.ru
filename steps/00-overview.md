# Project Overview: in.peoples.ru Community Platform

## Vision

Transform peoples.ru from a read-only celebrity encyclopedia into a **community-driven platform** where users can contribute, edit, and enrich content — biographies, news, articles, photos, and more.

User-submitted content goes through a moderation queue, then gets published directly into the existing peoples.ru tables (histories, photo, news, etc.) — no separate content storage.

## Target

- **URL:** https://in.peoples.ru
- **Server path:** /usr/local/www/in.peoples.ru/www/
- **Separate repo** with GitHub Actions CI/CD
- **Same database:** connects to existing `peoplesru` MySQL DB
- **PHP 8.1** on production (Ubuntu 22.04)

## What Users Can Do

1. **Register** — open registration, email + password
2. **Write & edit biographies** — rich text editor, linked to a person from the existing DB
3. **Submit news articles** — news about celebrities with photo attachments
4. **Add photos** — upload, tag, and describe celebrity photos
5. **Add facts & trivia** — interesting facts linked to people
6. **Post quotes, poetry, songs** — user-submitted literary content
7. **Comment & discuss** — threaded comments on content

All submissions land in `user_submissions` (staging queue), then a moderator approves → content moves to the real table (`histories`, `photo`, `news`, etc.) using the `peoples_section` mapping.

## What Moderators/Admins Can Do

1. **Review queue** — approve/reject/request edits on user content
2. **Manage users** — ban, promote to moderator, view activity
3. **See statistics** — submission rates, top contributors, queue size

## Key Constraints

- **Encoding:** Database is cp1251. New code handles UTF-8↔cp1251 transparently.
- **No framework:** Vanilla PHP 8.1, matching codebase style.
- **Existing data:** 175k+ persons, 564k photos, 184k histories. Content goes INTO these tables.
- **`peoples_section` is the master map** — it defines section_id → table_name for all content types.
- **Deployment:** GitHub Actions → server pull. QA checks before merge.

## Content Flow

```
User submits "biography" (section_id=2)
  → INSERT INTO user_submissions (section_id=2, KodPersons=5432, content=..., status='pending')

Moderator clicks "Approve"
  → SELECT table_name FROM peoples_section WHERE id=2  →  'histories'
  → INSERT INTO histories (KodPersons=5432, Content=..., ...)
  → UPDATE user_submissions SET status='approved', published_id=NEW_ID
```

Section mapping (from `peoples_section`):
| section_id | Name        | Target table       |
|------------|-------------|--------------------|
| 2          | Histories   | histories          |
| 3          | Photos      | photo              |
| 4          | News        | news               |
| 5          | Forum       | peoples_forum      |
| 7          | Songs       | songs              |
| 8          | Facts       | Facts              |
| 13         | Interesting | interesting        |
| 19         | Poetry      | poetry             |
