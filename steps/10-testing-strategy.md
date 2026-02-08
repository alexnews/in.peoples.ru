# Testing Strategy

## Approach

Since the project is vanilla PHP without a testing framework, tests are standalone PHP scripts that can be executed from the command line. Each test file is self-contained and reports PASS/FAIL results.

## Test Structure

```
tests/
├── run_tests.php              # Main test runner
├── bootstrap.php              # Test setup: DB connection, helper functions
├── api/
│   ├── test_auth.php          # Authentication endpoints
│   ├── test_submissions.php   # Submission CRUD
│   ├── test_photos.php        # Photo upload/delete
│   ├── test_moderation.php    # Moderation queue and review
│   ├── test_persons.php       # Person search
│   ├── test_comments.php      # Comment CRUD
│   └── test_reputation.php    # Points and badges
├── unit/
│   ├── test_encoding.php      # cp1251 <-> UTF-8 round-trips
│   ├── test_validation.php    # Input validation functions
│   ├── test_permissions.php   # Role-based access
│   └── test_upload.php        # Image processing functions
├── security/
│   ├── test_sql_injection.php # SQL injection attempts
│   ├── test_xss.php           # XSS payload tests
│   ├── test_csrf.php          # CSRF token validation
│   └── test_file_upload.php   # Malicious file upload tests
├── integration/
│   └── test_full_workflow.php # End-to-end workflow
└── fixtures/
    ├── test_image.jpg         # Valid test image
    ├── test_image.png         # Valid PNG
    ├── fake_image.php         # PHP disguised as image
    └── oversized.jpg          # >10MB image
```

## Test Runner (run_tests.php)

```php
<?php
// Usage: php tests/run_tests.php [category]
// Categories: api, unit, security, integration, all

require_once __DIR__ . '/bootstrap.php';

$category = $argv[1] ?? 'all';
$results = ['pass' => 0, 'fail' => 0, 'errors' => []];

// Discover and run test files
$dirs = $category === 'all'
    ? ['api', 'unit', 'security', 'integration']
    : [$category];

foreach ($dirs as $dir) {
    $files = glob(__DIR__ . "/{$dir}/test_*.php");
    foreach ($files as $file) {
        echo "\n=== Running: " . basename($file) . " ===\n";
        include $file;
    }
}

// Summary
echo "\n" . str_repeat('=', 50) . "\n";
echo "Results: {$results['pass']} passed, {$results['fail']} failed\n";
if ($results['errors']) {
    echo "\nFailures:\n";
    foreach ($results['errors'] as $error) {
        echo "  FAIL: {$error}\n";
    }
}
exit($results['fail'] > 0 ? 1 : 0);
```

## Test Categories

### 1. API Tests

**test_auth.php:**
```
- Register with valid data → 201
- Register with duplicate email → 409
- Register with weak password → 400
- Register with invalid email → 400
- Login with correct credentials → 200 + session cookie
- Login with wrong password → 401
- Login with banned account → 403
- Logout → 200 + session destroyed
- Get profile (authenticated) → 200 + user data
- Get profile (not authenticated) → 401
- Update profile → 200
```

**test_submissions.php:**
```
- Create biography submission (draft) → 201
- Create biography submission (pending) → 201
- Create submission without auth → 401
- Create submission with empty title → 400
- Create submission with invalid KodPersons → 400
- List my submissions → 200 + array
- List with status filter → 200 + filtered results
- View own submission → 200
- View other user's submission → 403
- Update draft → 200
- Update pending submission → 400 (can't edit pending)
- Update revision_requested → 200
- Delete draft → 200
- Delete approved submission → 400 (can't delete published)
- Submit all content types: biography, news, photo, fact, quote, poetry, song, article
```

**test_photos.php:**
```
- Upload valid JPEG → 201 + file path + thumbnails created
- Upload valid PNG → 201
- Upload valid WebP → 201
- Upload >10MB file → 413
- Upload PDF → 415
- Upload without submission_id → 400
- Upload to other user's submission → 403
- Upload 11th photo (over limit) → 400
- Delete own photo → 200
- Delete other user's photo → 403
- Verify thumbnails exist on disk
- Verify EXIF stripped from output
- Verify max dimension ≤ 1200px
```

**test_moderation.php:**
```
- Access queue as user → 403
- Access queue as moderator → 200
- View queue → 200 + pending submissions
- Approve submission → 200 + content in target table
- Approve biography → check histories table
- Approve news → check news table
- Approve photo → check photo table + file moved
- Reject submission → 200 + status='rejected'
- Request revision → 200 + status='revision_requested'
- Moderator note saved correctly
- Reputation awarded on approve
- Reputation deducted on reject
- Moderation log entry created
```

### 2. Unit Tests

**test_encoding.php:**
```
- "Тарковский" round-trip (UTF-8 → cp1251 → UTF-8) = identical
- "ё" character preserved
- "ъ" (hard sign) preserved
- Mixed Russian/English: "Hello Мир" preserved
- Quotes and special chars: «кавычки» preserved
- Empty string → empty string
- NULL → NULL
- Long string (10000 chars) round-trip
- Array conversion (toDbArray/fromDbArray)
```

**test_validation.php:**
```
- Valid emails pass
- Invalid emails fail (no @, no domain, spaces)
- Password requirements: min 8 chars, letter + digit
- Username: 3-50 chars, alphanumeric + underscore
- HTML sanitization: strips <script>, keeps <p><b><i>
- URL validation
```

**test_permissions.php:**
```
- Guest cannot access /user/ pages
- User cannot access /moderate/ pages
- Moderator can access /moderate/ pages
- Admin can access everything
- Role hierarchy: admin > moderator > user
```

### 3. Security Tests

**test_sql_injection.php:**
```
Test these payloads in every input field (login, register, search, submissions):
- ' OR '1'='1
- '; DROP TABLE users;--
- ' UNION SELECT * FROM users--
- 1' AND 1=CONVERT(int,@@version)--

Expected: All return validation error or escaped safely, no SQL errors exposed
```

**test_xss.php:**
```
Test these payloads in submission content, titles, comments, display names:
- <script>alert('xss')</script>
- <img onerror=alert(1) src=x>
- javascript:alert(1)
- <svg onload=alert(1)>
- "><script>alert(document.cookie)</script>

Expected: All payloads HTML-escaped in output, no script execution
```

**test_csrf.php:**
```
- POST without CSRF token → 403
- POST with wrong CSRF token → 403
- POST with valid CSRF token → success
- GET requests don't require CSRF
```

**test_file_upload.php:**
```
- Upload file.php renamed to .jpg → rejected (content check)
- Upload file with double extension .php.jpg → rejected
- Upload .htaccess file → rejected
- Upload file with null bytes in name → rejected
- Upload extremely large dimensions (50000x50000) → rejected
- Upload valid image → accepted
```

### 4. Integration Tests

**test_full_workflow.php:**
```
Full end-to-end test:

1. Register new user "testuser_" + random
2. Login as testuser
3. Search for a person (any existing person in DB)
4. Submit a biography for that person (status: pending)
5. Verify submission in user's list
6. Login as admin
7. View moderation queue
8. Approve the submission
9. Verify content in histories table
10. Verify reputation points awarded
11. Login as testuser
12. Verify submission status = 'approved'
13. Check reputation increased
14. Clean up: delete test user and data
```

## Running Tests

```bash
# Run all tests
php tests/run_tests.php all

# Run specific category
php tests/run_tests.php api
php tests/run_tests.php unit
php tests/run_tests.php security
php tests/run_tests.php integration

# Run single test file
php tests/api/test_auth.php
```

## Test Environment Setup

Tests require:
1. Running MySQL with `peoplesru` database and all migrations applied
2. Apache serving the application (for API tests via HTTP)
3. Test fixtures in `tests/fixtures/`
4. A designated test user that can be created/destroyed

The `bootstrap.php` sets up:
- Database connection
- Base URL for API requests
- Helper functions: `assert_equals()`, `assert_true()`, `api_get()`, `api_post()`
- Test user creation/cleanup
- Result tracking
