# 11. Celebrity Booking System (Lead Generation)

## Overview

The booking system leverages peoples.ru's 175k+ celebrity database to capture booking leads.
Admins mark celebrities as bookable (with categories, pricing, descriptions), public pages
display them with inquiry forms, and leads flow to the admin panel for processing.

## Database Tables (4 new)

All tables use `booking_` prefix and `InnoDB / cp1251` encoding.

| Table | Purpose |
|-------|---------|
| `booking_categories` | Category taxonomy (Ведущие, Певцы, DJ, etc.) with slug, icon, sort_order |
| `booking_persons` | Links persons → categories with pricing, descriptions, featured flag |
| `booking_requests` | Customer inquiries with client info, event details, processing status |
| `booking_request_status_log` | Audit trail for request status changes |

### Key relationships
- `booking_persons.person_id` → `persons.Persons_id` (no FK constraint, legacy table)
- `booking_persons.category_id` → `booking_categories.id`
- `booking_persons.added_by` → `users.id`
- `booking_requests.booking_person_id` → `booking_persons.id`
- `booking_requests.assigned_to` → `users.id`
- One person can appear in multiple categories (unique constraint on person_id + category_id)

### Migration files
```
SOURCE/MIGRATIONS/
├── 010_create_booking_categories.sql    (+ seed data: 8 categories)
├── 010_rollback_booking_categories.sql
├── 011_create_booking_persons.sql
├── 011_rollback_booking_persons.sql
├── 012_create_booking_requests.sql
├── 012_rollback_booking_requests.sql
├── 013_create_booking_request_status_log.sql
└── 013_rollback_booking_request_status_log.sql
```

## Public API

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/v1/booking/categories.php` | GET | No | Active categories with person counts |
| `/api/v1/booking/persons.php` | GET | No | Bookable persons (filter: category, price, search, pagination) |
| `/api/v1/booking/request.php` | POST | No | Submit inquiry (honeypot + bot_token, 5/hr rate limit) |

### GET /api/v1/booking/persons.php

Query params: `category` (slug), `q` (search), `price_min`, `price_max`, `featured`, `page`, `per_page` (max 50)

Returns person name, photo, category, price range, short description, peoples.ru path.
Joins `booking_persons` + `persons` + `booking_categories`. Filters active, living persons only.

### POST /api/v1/booking/request.php

Bot protection: honeypot (`website` field must be empty) + time-based token (`bot_token`, format `ok_{timestamp}`, valid 1-10 min after generation).

Rate limit: 5 requests per hour per IP (file-based, same pattern as newsletter subscribe).

On success: creates `booking_requests` row, logs to `booking_request_status_log`, sends admin email notification.

## Admin API

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/v1/booking/admin-categories.php` | POST | Admin+CSRF | CRUD categories (create, update, delete, reorder) |
| `/api/v1/booking/admin-persons.php` | POST | Admin+CSRF | Manage bookable persons (add, update, remove, toggle) |
| `/api/v1/booking/admin-requests.php` | GET/POST | Admin+CSRF | List + manage requests (update_status, assign, add_note) |
| `/api/v1/booking/admin-stats.php` | GET | Admin | Dashboard statistics |

## Admin Pages

| Page | Description |
|------|-------------|
| `/moderate/booking.php` | Requests: stats cards, status filter, request cards with actions |
| `/moderate/booking-persons.php` | Persons: table with filters, add modal with person autocomplete |
| `/moderate/booking-categories.php` | Categories: table, add/edit modal, toggle active, delete |

Navigation: "Букинг" nav item added to `header.php` with red badge for new request count.

## Public Pages

| URL | File | Description |
|-----|------|-------------|
| `/booking/` | `index.php` | Landing: hero + search, category grid, featured, how-it-works, form |
| `/booking/category/{slug}/` | `category.php` | Category page: filtered grid, pagination |
| `/booking/person/{id}/` | `person.php` | Individual: photo, info, price, full form, similar artists, JSON-LD |

### URL Rewriting (`www/booking/.htaccess`)
```apache
RewriteRule ^$ index.php [L]
RewriteRule ^category/([a-z0-9-]+)/?$ category.php?slug=$1 [L,QSA]
RewriteRule ^person/(\d+)/?$ person.php?id=$1 [L,QSA]
```

## Assets

- `www/assets/css/booking.css` — Hero, category grid, person cards, price badges, form styles
- `www/assets/js/booking.js` — Form submission, person search autocomplete, bot token generation

## Request Status Flow

```
new → in_progress → contacted → completed
  └→ cancelled
  └→ spam
```

All transitions logged to `booking_request_status_log` with user ID and optional note.

## Seed Categories

| # | Name | Slug | Icon |
|---|------|------|------|
| 1 | Ведущие | vedushchie | bi-mic |
| 2 | Певцы и музыканты | pevtsy-muzykanty | bi-music-note-beamed |
| 3 | Блогеры | blogery | bi-camera-video |
| 4 | Комики и юмористы | komiki | bi-emoji-laughing |
| 5 | DJ | dj | bi-disc |
| 6 | Актёры | aktyory | bi-film |
| 7 | Спортсмены | sportsmeny | bi-trophy |
| 8 | Писатели и поэты | pisateli | bi-book |

## .env

Optional addition:
```
BOOKING_ADMIN_EMAIL=alex@peoples.ru
```
Falls back to `MAIL_REPLY_TO` if not set.

## Event Types

```php
'corporate'  => 'Корпоратив',
'wedding'    => 'Свадьба',
'birthday'   => 'День рождения',
'concert'    => 'Концерт',
'private'    => 'Частная вечеринка',
'city_event' => 'Городское мероприятие',
'charity'    => 'Благотворительное мероприятие',
'opening'    => 'Открытие / презентация',
'other'      => 'Другое',
```
