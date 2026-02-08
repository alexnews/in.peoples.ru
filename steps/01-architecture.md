# System Architecture

## High-Level Architecture

```
┌──────────────────────────────────────────────────────────┐
│            in.peoples.ru (Apache VirtualHost)             │
│         /usr/local/www/in.peoples.ru/www/                │
├──────────────┬──────────────┬───────────────────────────┤
│  Public      │  User Portal │     Moderation Panel      │
│  / (landing) │  /user/*     │     /moderate/*           │
│              │  (auth req)  │     (moderator+ only)     │
├──────────────┴──────────────┴───────────────────────────┤
│                      /api/v1/*                           │
│                 REST API Layer (JSON)                     │
├──────────────────────────────────────────────────────────┤
│                     /includes/*                          │
│         Auth │ Session │ Encoding │ Upload │ Validation  │
├──────────────────────────────────────────────────────────┤
│               MySQL: peoplesru (cp1251)                  │
│  persons │ histories │ photo │ news │ user_submissions   │
└──────────────────────────────────────────────────────────┘
```

## Server Layout

```
/usr/local/www/in.peoples.ru/
├── www/                        # Apache DocumentRoot
│   ├── index.php               # Landing page
│   ├── .htaccess               # Routing, security
│   │
│   ├── api/v1/                 # REST API endpoints
│   │   ├── config.php          # DB connection, helpers
│   │   ├── auth/               # register, login, logout, profile
│   │   ├── submissions/        # CRUD for user submissions
│   │   ├── photos/             # upload, list, delete
│   │   ├── persons/            # search (queries existing persons table)
│   │   ├── comments/           # list, create, delete
│   │   └── moderate/           # queue, review, users, stats
│   │
│   ├── user/                   # User portal (authenticated)
│   │   ├── index.php           # Dashboard
│   │   ├── login.php           # Login form
│   │   ├── register.php        # Registration form
│   │   ├── profile.php         # User profile
│   │   ├── submissions.php     # My submissions list
│   │   ├── submit.php          # Universal submission form
│   │   └── view.php            # View single submission + feedback
│   │
│   ├── moderate/               # Moderation panel (moderator+)
│   │   ├── index.php           # Dashboard
│   │   ├── queue.php           # Review queue
│   │   ├── review.php          # Single submission review
│   │   ├── users.php           # User management
│   │   └── log.php             # Audit log
│   │
│   ├── includes/               # Shared PHP code
│   │   ├── db.php              # PDO singleton (connects to peoplesru DB)
│   │   ├── auth.php            # Authentication functions
│   │   ├── session.php         # Session management
│   │   ├── encoding.php        # UTF-8 ↔ cp1251 helpers
│   │   ├── validation.php      # Input validation
│   │   ├── permissions.php     # Role-based access control
│   │   ├── upload.php          # Photo upload & processing
│   │   ├── response.php        # JSON response helpers
│   │   └── csrf.php            # CSRF token handling
│   │
│   ├── assets/                 # Static files
│   │   ├── css/
│   │   ├── js/
│   │   └── vendor/             # Bootstrap, TinyMCE, jQuery
│   │
│   └── uploads/                # User uploaded files (temp)
│       └── temp/               # Before moderation approval
│
├── .github/
│   └── workflows/
│       └── deploy.yml          # GitHub Actions: lint + deploy
│
├── composer.json
└── README.md
```

## Separate Repo, Same Database

- **Own git repo:** `github.com/alexnews/in-peoples-ru` (or similar)
- **Connects to same MySQL:** `peoplesru` database on the same server
- **Reads existing tables:** `persons`, `histories`, `photo`, `news`, `peoples_section`, etc.
- **Writes to existing tables:** On approval, content goes into `histories`, `photo`, `news`, etc.
- **Owns new tables:** `users`, `user_sessions`, `user_submissions`, `moderation_log`

## Apache VirtualHost

```apache
<VirtualHost *:443>
    ServerName in.peoples.ru
    DocumentRoot /usr/local/www/in.peoples.ru/www

    <Directory /usr/local/www/in.peoples.ru/www>
        AllowOverride All
        Require all granted
    </Directory>

    # SSL config...
</VirtualHost>
```

## Request Flow

### User submits a biography:
```
Browser (UTF-8 form)
  → POST /api/v1/submissions/index.php
    → session.php: verify logged in
    → validation.php: sanitize input
    → encoding.php: UTF-8 → cp1251
    → INSERT INTO user_submissions (section_id=2, KodPersons, content, status='pending')
    → response.php: JSON {success: true, id: 123}
```

### Moderator approves:
```
Moderator at /moderate/review.php
  → POST /api/v1/moderate/review.php {submission_id: 123, action: 'approve'}
    → permissions.php: verify moderator role
    → SELECT table_name FROM peoples_section WHERE id = submission.section_id
    → INSERT INTO {table_name} (KodPersons, Content, ...)
    → UPDATE user_submissions SET status='approved', published_id=LAST_INSERT_ID()
    → INSERT INTO moderation_log (action='approve', ...)
```

## Encoding Strategy

Users type UTF-8 (browser default). All DB operations go through encoding layer:
```
User Input (UTF-8)
  → iconv('UTF-8', 'CP1251//TRANSLIT', $input)   [write to DB]
  → MySQL (cp1251)
  → iconv('CP1251', 'UTF-8', $output)             [read from DB]
  → Browser/JSON (UTF-8)
```

All new pages serve `Content-Type: text/html; charset=utf-8`.

## Photo Upload Flow

```
Upload → www/uploads/temp/{user_id}/{filename}     (staging)
Approve → /usr/local/www/peoples.ru/www/photo/{person_path}/{filename}  (production, on main site)
```

On approval, photos move from in.peoples.ru temp storage to the main peoples.ru photo directory. This requires the in.peoples.ru process to have write access to `/usr/local/www/peoples.ru/www/photo/`.

## GitHub Actions Deployment

```yaml
# .github/workflows/deploy.yml
on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: PHP Lint
        run: find www/ -name "*.php" -exec php -l {} \;
      - name: Deploy to server
        # SSH deploy or rsync to /usr/local/www/in.peoples.ru/
```
