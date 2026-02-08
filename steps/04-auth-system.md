# Authentication & Authorization System

## Overview

Session-based authentication using PHP native sessions backed by a database table. No external libraries needed.

## Registration Flow

```
1. User fills form (username, email, password, display_name)
2. Client-side validation (JS)
3. POST /api/v1/auth/register.php
4. Server validates:
   - Username: 3-50 chars, alphanumeric + underscore, unique
   - Email: valid format, unique
   - Password: min 8 chars, at least 1 letter + 1 digit
   - Display name: 2-100 chars
5. password_hash($password, PASSWORD_DEFAULT)
6. Generate verify_token (random 64-char hex)
7. INSERT INTO users (status='unverified')
8. Send verification email with token link
9. Return success JSON
```

## Email Verification

```
1. User clicks link: /user/verify.php?token=abc123...
2. Server finds user by verify_token
3. UPDATE users SET status='active', email_verified=1, verify_token=NULL
4. Auto-login user
5. Redirect to dashboard
```

**Note:** For initial deployment, email verification can be optional (auto-activate).
Make this configurable in `www/includes/config.php`.

## Login Flow

```
1. POST /api/v1/auth/login.php (email/username + password)
2. SELECT user by email OR username
3. password_verify($password, $password_hash)
4. Check user status != 'banned'
5. Generate session ID (random 128-char hex)
6. INSERT INTO user_sessions (id, user_id, ip, user_agent)
7. Set cookie: peoples_session={session_id}
   - HttpOnly: true
   - Secure: true (production)
   - SameSite: Lax
   - Path: /
   - Expires: 30 days (remember-me) or session
8. UPDATE users SET last_login=NOW(), login_ip=IP
9. Return user data JSON
```

## Session Validation (Every Request)

```php
// www/includes/session.php

function getCurrentUser() {
    $sessionId = $_COOKIE['peoples_session'] ?? null;
    if (!$sessionId) return null;

    $db = getDb();
    $stmt = $db->prepare("
        SELECT u.* FROM users u
        JOIN user_sessions s ON s.user_id = u.id
        WHERE s.id = :sid
        AND s.last_activity > DATE_SUB(NOW(), INTERVAL 30 DAY)
        AND u.status = 'active'
    ");
    $stmt->execute([':sid' => $sessionId]);
    $user = $stmt->fetch();

    if ($user) {
        // Refresh last_activity
        $db->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE id = :sid")
           ->execute([':sid' => $sessionId]);
    }

    return $user;
}
```

## Logout

```
1. POST /api/v1/auth/logout.php
2. DELETE FROM user_sessions WHERE id = :session_id
3. Clear cookie
4. Redirect to homepage
```

## Password Reset

```
1. POST /api/v1/auth/forgot-password.php (email)
2. Generate reset_token, set reset_expires = NOW() + 1 HOUR
3. Send email with reset link
4. User clicks link → /user/reset-password.php?token=abc...
5. Validate token and expiry
6. User enters new password
7. password_hash(), UPDATE users, clear token
8. Auto-login
```

## Role-Based Access Control

### Roles
| Role      | Permissions                                           |
|-----------|-------------------------------------------------------|
| guest     | Read public content, register                         |
| user      | Submit content, edit own drafts, comment, upload photos|
| moderator | Review queue, approve/reject, view user activity      |
| admin     | All moderator + manage users, change roles, settings  |

### Permission Check Pattern
```php
// www/includes/permissions.php

function requireRole($role) {
    $user = getCurrentUser();
    if (!$user) {
        redirectToLogin();
    }
    $hierarchy = ['user' => 1, 'moderator' => 2, 'admin' => 3];
    if ($hierarchy[$user['role']] < $hierarchy[$role]) {
        http_response_code(403);
        exit(jsonResponse(['error' => 'Forbidden']));
    }
    return $user;
}

// Usage in API endpoints:
$user = requireRole('moderator');  // blocks users, allows moderators and admins
```

## CSRF Protection

Every form includes a hidden CSRF token:
```php
// Generate (once per session)
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));

// In form
<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

// Validate on POST
if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die('CSRF validation failed');
}
```

For API calls from JS, send token in `X-CSRF-Token` header.

## Session Cleanup

Scheduled task (add to MySQL events or cron):
```sql
-- Delete sessions older than 30 days
DELETE FROM user_sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

## Security Measures

- Passwords stored with bcrypt via `password_hash()`
- Session IDs are 128-char cryptographically random hex
- Cookies: HttpOnly + Secure + SameSite
- Rate limiting: max 5 login attempts per 15 minutes per IP
- Account lockout after 10 failed attempts
- Session IP validation (optional, configurable)
- All tokens use `random_bytes()` (CSPRNG)
