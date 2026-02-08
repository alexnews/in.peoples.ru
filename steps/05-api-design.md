# API Design Specification

## Base URL

```
/api/v1/
```

All endpoints return JSON with `Content-Type: application/json; charset=utf-8`.
Database values are automatically converted from cp1251 to UTF-8 in responses.

## Authentication

Protected endpoints require a valid session cookie (`peoples_session`).
Some endpoints accept both authenticated and guest requests (e.g., person search).

---

## Auth Endpoints

### POST /api/v1/auth/register.php

Register a new user account.

**Request:**
```json
{
    "username": "ivan_petrov",
    "email": "ivan@example.com",
    "password": "securePass123",
    "display_name": "Иван Петров"
}
```

**Response (201):**
```json
{
    "success": true,
    "data": {
        "user_id": 42,
        "username": "ivan_petrov",
        "display_name": "Иван Петров",
        "role": "user"
    }
}
```

**Errors:** 400 (validation), 409 (username/email taken)

---

### POST /api/v1/auth/login.php

**Request:**
```json
{
    "login": "ivan@example.com",
    "password": "securePass123",
    "remember": true
}
```

**Response (200):**
```json
{
    "success": true,
    "data": {
        "user_id": 42,
        "username": "ivan_petrov",
        "display_name": "Иван Петров",
        "role": "user",
        "reputation": 150,
        "avatar_path": "/uploads/avatars/42.jpg"
    }
}
```

Sets `peoples_session` cookie.

**Errors:** 401 (bad credentials), 403 (banned)

---

### POST /api/v1/auth/logout.php

Requires auth. Destroys session.

**Response (200):**
```json
{ "success": true }
```

---

### GET /api/v1/auth/profile.php

Get current user profile.

**Response (200):**
```json
{
    "success": true,
    "data": {
        "user_id": 42,
        "username": "ivan_petrov",
        "email": "ivan@example.com",
        "display_name": "Иван Петров",
        "role": "user",
        "reputation": 150,
        "bio": "Увлекаюсь историей кино",
        "avatar_path": "/uploads/avatars/42.jpg",
        "created_at": "2025-01-15 10:30:00",
        "stats": {
            "submissions_total": 28,
            "submissions_approved": 22,
            "submissions_pending": 3,
            "photos_uploaded": 45
        },
        "badges": [
            { "code": "first_submission", "name": "First Contribution", "earned_at": "2025-01-16" }
        ]
    }
}
```

---

### PUT /api/v1/auth/profile.php

Update profile. Multipart form for avatar upload.

**Request:**
```json
{
    "display_name": "Иван Петров",
    "bio": "Историк кино и театра"
}
```

---

## Submission Endpoints

### GET /api/v1/submissions/index.php

List current user's submissions.

**Query params:**
- `status` — filter: draft, pending, approved, rejected, revision_requested
- `section_type` — filter: biography, news, photo, fact, quote, poetry, song, article
- `page` — page number (default: 1)
- `per_page` — items per page (default: 20, max: 50)

**Response (200):**
```json
{
    "success": true,
    "data": [
        {
            "id": 101,
            "section_type": "biography",
            "title": "Биография Андрея Тарковского",
            "status": "pending",
            "person": {
                "id": 5432,
                "name": "Андрей Тарковский",
                "path": "/person/andrei-tarkovskij/"
            },
            "created_at": "2025-06-10 14:22:00",
            "updated_at": "2025-06-10 14:22:00"
        }
    ],
    "pagination": { "page": 1, "per_page": 20, "total": 28, "pages": 2 }
}
```

---

### POST /api/v1/submissions/index.php

Create a new submission.

**Request:**
```json
{
    "section_type": "biography",
    "KodPersons": 5432,
    "title": "Биография Андрея Тарковского",
    "content": "<p>Андрей Арсеньевич Тарковский родился 4 апреля 1932 года...</p>",
    "epigraph": "Великий советский и российский кинорежиссёр",
    "source_url": "https://example.com/source",
    "status": "draft"
}
```

`status` can be `draft` (save for later) or `pending` (submit for review).

**Response (201):**
```json
{
    "success": true,
    "data": { "id": 102, "status": "draft" }
}
```

---

### GET /api/v1/submissions/view.php?id={id}

View a single submission. Users can view their own; moderators can view any.

**Response (200):**
```json
{
    "success": true,
    "data": {
        "id": 101,
        "user": { "id": 42, "display_name": "Иван Петров" },
        "section_type": "biography",
        "KodPersons": 5432,
        "person": { "id": 5432, "name": "Андрей Тарковский" },
        "title": "Биография Андрея Тарковского",
        "content": "<p>Андрей Арсеньевич Тарковский...</p>",
        "epigraph": "Великий советский и российский кинорежиссёр",
        "source_url": "https://example.com/source",
        "status": "pending",
        "moderator_note": null,
        "photos": [
            { "id": 10, "file_path": "/uploads/temp/42/img001.jpg", "caption": "На съёмках" }
        ],
        "versions": [
            { "version_num": 1, "created_at": "2025-06-10 14:22:00", "change_note": "Initial submission" }
        ],
        "created_at": "2025-06-10 14:22:00"
    }
}
```

---

### PUT /api/v1/submissions/update.php

Update a draft or revision-requested submission.

**Request:**
```json
{
    "id": 101,
    "title": "Updated title",
    "content": "<p>Updated content...</p>",
    "status": "pending",
    "change_note": "Added early career details"
}
```

Creates a new version entry. Can change status from `draft`→`pending` or `revision_requested`→`pending`.

---

### DELETE /api/v1/submissions/delete.php

Withdraw a submission (only drafts and pending).

**Request:**
```json
{ "id": 101 }
```

---

## Photo Endpoints

### POST /api/v1/photos/upload.php

Upload photo(s) for a submission. Multipart form data.

**Form fields:**
- `submission_id` — required
- `photos[]` — file array (max 10 per request)
- `captions[]` — caption for each photo

**Response (201):**
```json
{
    "success": true,
    "data": [
        { "id": 10, "file_path": "/uploads/temp/42/img001.jpg", "thumbnail": "/uploads/temp/42/thumb_img001.jpg" },
        { "id": 11, "file_path": "/uploads/temp/42/img002.jpg", "thumbnail": "/uploads/temp/42/thumb_img002.jpg" }
    ]
}
```

---

### DELETE /api/v1/photos/delete.php

Remove an uploaded photo (owner only, before approval).

---

## Person Endpoints

### GET /api/v1/persons/search.php

Search for a person. Used by autocomplete in submission forms.

**Query params:**
- `q` — search query (min 2 chars)
- `limit` — max results (default: 10)

**Response (200):**
```json
{
    "success": true,
    "data": [
        {
            "id": 5432,
            "name": "Андрей Тарковский",
            "name_eng": "Andrei Tarkovsky",
            "dates": "1932-1986",
            "photo": "/photo/andrei-tarkovskij/thumb.jpg",
            "famous_for": "Кинорежиссёр",
            "path": "/person/andrei-tarkovskij/"
        }
    ]
}
```

Searches existing `persons` table and in-memory `peoplesru_search_person`.

---

### GET /api/v1/persons/view.php?id={id}

Person detail with content counts.

---

## Comment Endpoints

### GET /api/v1/comments/index.php?type={type}&id={id}

List comments for a content item.

**Query params:**
- `type` — person, submission, news, photo
- `id` — item ID
- `page`, `per_page`

---

### POST /api/v1/comments/index.php

Add a comment. Requires auth.

**Request:**
```json
{
    "type": "person",
    "id": 5432,
    "message": "Отличная биография!",
    "parent_id": null
}
```

---

## Moderation Endpoints

All require `moderator` or `admin` role.

### GET /api/v1/moderate/queue.php

**Query params:**
- `status` — pending (default), revision_requested
- `section_type` — filter by type
- `sort` — oldest (default), newest
- `page`, `per_page`

---

### POST /api/v1/moderate/review.php

**Request:**
```json
{
    "submission_id": 101,
    "action": "approve",
    "note": "Great contribution!"
}
```

`action`: `approve`, `reject`, `request_revision`

On approve:
1. Copy content to target table (histories, news, photo, etc.)
2. Update `user_submissions.status = 'approved'`, set `published_id` and `published_table`
3. Award reputation points to user
4. Check and award badges

On reject:
1. Update status to `rejected`
2. Deduct reputation points
3. Send feedback to user (moderator_note)

On request_revision:
1. Update status to `revision_requested`
2. Set moderator_note with feedback
3. User can edit and resubmit

---

### GET /api/v1/moderate/stats.php

**Response (200):**
```json
{
    "success": true,
    "data": {
        "queue_size": 15,
        "approved_today": 8,
        "rejected_today": 2,
        "top_contributors": [
            { "user_id": 42, "display_name": "Иван Петров", "approved_count": 22 }
        ],
        "by_type": {
            "biography": 5,
            "news": 4,
            "photo": 3,
            "fact": 2,
            "quote": 1
        }
    }
}
```

---

## Error Codes

| HTTP | Code                | Description                        |
|------|---------------------|------------------------------------|
| 400  | VALIDATION_ERROR    | Invalid input data                 |
| 401  | UNAUTHORIZED        | Not logged in                      |
| 403  | FORBIDDEN           | Insufficient permissions           |
| 404  | NOT_FOUND           | Resource not found                 |
| 409  | CONFLICT            | Duplicate (username, email)        |
| 413  | FILE_TOO_LARGE      | Upload exceeds size limit          |
| 415  | UNSUPPORTED_TYPE    | Invalid file type                  |
| 429  | RATE_LIMITED        | Too many requests                  |
| 500  | INTERNAL_ERROR      | Server error                       |
