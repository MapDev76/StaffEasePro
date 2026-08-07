<?php

/**
 * Shared helper functions used by controllers and views.
 * They centralize URL building, redirects, flash messages, auth checks, and HTML escaping.
 */

function startAppSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

/**
 * Builds application URLs from a route name and optional query parameters.
 */
function appBasePath(): string
{
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));

    if ($scriptDir === '.' || $scriptDir === '/' || $scriptDir === '') {
        return '';
    }

    return rtrim($scriptDir, '/');
}

function appUsesPrettyUrls(): bool
{
    // Check the raw request URI, not $_GET['route']: index.php normalizes the
    // resolved route into $_GET['route'] for every request (clean or not), so
    // checking isset($_GET['route']) here would always be true and force dirty
    // URLs everywhere, even on requests that arrived via a clean path.
    $requestUri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
    if (str_contains($requestUri, 'route=')) {
        return false;
    }

    $serverSoftware = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
    return str_contains($serverSoftware, 'apache');
}

function appRouteFromRequest(): string
{
    $route = strtolower(trim((string) ($_GET['route'] ?? '')));
    if ($route !== '') {
        return $route;
    }

    $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $basePath = appBasePath();
    $normalizedPath = $requestPath;

    if ($basePath !== '' && str_starts_with($normalizedPath, $basePath)) {
        $normalizedPath = (string) substr($normalizedPath, strlen($basePath));
    }

    $normalizedPath = trim($normalizedPath, '/');
    if ($normalizedPath === '' || $normalizedPath === 'index.php') {
        return 'home';
    }

    $segments = explode('/', $normalizedPath);
    $firstSegment = strtolower(trim((string) ($segments[0] ?? '')));

    return $firstSegment !== '' ? $firstSegment : 'home';
}

function appSupportedLocales(): array
{
    return ['fr', 'en', 'it'];
}

function appDefaultLocale(): string
{
    return 'fr';
}

function appLocale(): string
{
    static $resolved = null;
    if (is_string($resolved) && $resolved !== '') {
        return $resolved;
    }

    startAppSession();

    $supported = appSupportedLocales();
    $requested = strtolower(trim((string) ($_GET['lang'] ?? '')));
    if ($requested !== '' && in_array($requested, $supported, true)) {
        $_SESSION['app_locale'] = $requested;
    }

    $sessionLocale = strtolower(trim((string) ($_SESSION['app_locale'] ?? '')));
    if ($sessionLocale !== '' && in_array($sessionLocale, $supported, true)) {
        $resolved = $sessionLocale;
        return $resolved;
    }

    $resolved = appDefaultLocale();
    $_SESSION['app_locale'] = $resolved;

    return $resolved;
}

function appCurrentUrl(array $overrides = []): string
{
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $parsedUri = $requestUri !== '' ? parse_url($requestUri) : [];
    $query = [];
    if (!empty($parsedUri['query'])) {
        parse_str((string) $parsedUri['query'], $query);
    } else {
        $query = $_GET;
    }

    $route = appRouteFromRequest();
    unset($query['route']);

    // Ignore tracking and provider-specific params in canonical URLs.
    $ignoredParams = ['i', 'fbclid', 'gclid', 'dclid', 'msclkid'];
    foreach ($ignoredParams as $param) {
        unset($query[$param]);
    }
    foreach (array_keys($query) as $key) {
        if (str_starts_with((string) $key, 'utm_')) {
            unset($query[$key]);
        }
    }

    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
            continue;
        }
        if ($key === 'route') {
            $route = strtolower(trim((string) $value));
            continue;
        }
        $query[$key] = $value;
    }

    if (!appUsesPrettyUrls()) {
        $query['route'] = $route !== '' ? $route : 'home';
        return (appBasePath() === '' ? '' : appBasePath()) . '/?' . http_build_query($query);
    }

    return appUrl($route !== '' ? $route : 'home', $query);
}

function appTranslations(string $locale): array
{
    static $cache = [];
    if (isset($cache[$locale])) {
        return $cache[$locale];
    }

    $file = __DIR__ . '/../config/lang/' . $locale . '.php';
    if (!is_file($file)) {
        $cache[$locale] = [];
        return $cache[$locale];
    }

    $loaded = require $file;
    $cache[$locale] = is_array($loaded) ? $loaded : [];
    return $cache[$locale];
}

function t(string $key, array $replace = [], ?string $locale = null): string
{
    $locale = $locale ?: appLocale();
    $fallbackLocale = appDefaultLocale();

    $resolve = static function (array $source, string $path): ?string {
        $node = $source;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }
        return is_string($node) ? $node : null;
    };

    $value = $resolve(appTranslations($locale), $key);
    if ($value === null && $locale !== $fallbackLocale) {
        $value = $resolve(appTranslations($fallbackLocale), $key);
    }
    if ($value === null) {
        return $key;
    }

    if (!empty($replace)) {
        $pairs = [];
        foreach ($replace as $replaceKey => $replaceValue) {
            $pairs['{' . $replaceKey . '}'] = (string) $replaceValue;
        }
        $value = strtr($value, $pairs);
    }

    return $value;
}

/**
 * Returns a full application URL for the given route and query string.
 */

function appUrl(string $route, array $params = []): string
{
    $route = strtolower(trim($route));
    if ($route === '') {
        $route = 'home';
    }

    $query = $params;
    unset($query['route']);

    $basePath = appBasePath();

    if (!appUsesPrettyUrls()) {
        $query = array_merge(['route' => $route], $query);
        return ($basePath === '' ? '' : $basePath) . '/?' . http_build_query($query);
    }

    $path = $route === 'home' ? '/' : '/' . rawurlencode($route);
    $url = ($basePath === '' ? '' : $basePath) . $path;

    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    return $url;
}

function redirectTo(string $route, array $params = []): never
{
    header('Location: ' . appUrl($route, $params));
    exit;
}

/**
 * Escapes a value for safe HTML output.
 */

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function appTimezoneName(): string
{
    return 'Europe/Rome';
}

function appTimezone(): DateTimeZone
{
    static $timezone = null;

    if (!$timezone instanceof DateTimeZone) {
        $timezone = new DateTimeZone(appTimezoneName());
    }

    return $timezone;
}

function appNow(): DateTimeImmutable
{
    return new DateTimeImmutable('now', appTimezone());
}

function timeAgo(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return '-';
    }

    try {
        $then = new DateTimeImmutable($datetime, appTimezone());
    } catch (Throwable $e) {
        return '-';
    }

    $seconds = appNow()->getTimestamp() - $then->getTimestamp();
    if ($seconds < 0) {
        $seconds = 0;
    }

    if ($seconds < 60) {
        return t('settings.time_ago_just_now');
    }
    if ($seconds < 3600) {
        return t('settings.time_ago_minutes', ['count' => (string) intdiv($seconds, 60)]);
    }
    if ($seconds < 86400) {
        return t('settings.time_ago_hours', ['count' => (string) intdiv($seconds, 3600)]);
    }
    if ($seconds < 2592000) {
        return t('settings.time_ago_days', ['count' => (string) intdiv($seconds, 86400)]);
    }

    return t('settings.time_ago_months', ['count' => (string) intdiv($seconds, 2592000)]);
}

function commercialVideosConfigPath(): string
{
    return __DIR__ . '/../config/commercial-videos.json';
}

function defaultCommercialVideos(): array
{
    return [
        [
            'title' => 'Demo generale della piattaforma',
            'url' => 'https://youtu.be/LckCMzphDw0',
        ],
        [
            'title' => 'Calendario, turni e presenze',
            'url' => 'https://youtube.com/shorts/pylzptl-7Vg?feature=share',
        ],
        [
            'title' => 'Documenti, messaggi e suite HotelEase Pro',
            'url' => 'https://youtu.be/C1cWXLeGM9Y',
        ],
    ];
}

function commercialVideoIdFromUrl(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|shorts/|embed/))([A-Za-z0-9_-]{6,})~i', $url, $matches)) {
        return $matches[1];
    }

    return null;
}

function normalizeCommercialVideoEntry(mixed $entry): ?array
{
    if (!is_array($entry)) {
        return null;
    }

    $title = trim((string) ($entry['title'] ?? ''));
    $url = trim((string) ($entry['url'] ?? ''));
    $id = commercialVideoIdFromUrl($url);

    if ($id === null) {
        return null;
    }

    return [
        'id' => $id,
        'title' => $title !== '' ? $title : 'Video',
        'url' => $url,
    ];
}

function getCommercialVideos(): array
{
    $path = commercialVideosConfigPath();
    $videos = [];

    if (is_file($path)) {
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (is_array($decoded)) {
            foreach ($decoded as $entry) {
                $normalized = normalizeCommercialVideoEntry($entry);
                if ($normalized !== null) {
                    $videos[] = $normalized;
                }
            }
        }
    }

    if (empty($videos)) {
        foreach (defaultCommercialVideos() as $entry) {
            $normalized = normalizeCommercialVideoEntry($entry);
            if ($normalized !== null) {
                $videos[] = $normalized;
            }
        }
    }

    return array_slice($videos, 0, 3);
}

function saveCommercialVideos(array $videos): array
{
    $normalized = [];
    foreach ($videos as $entry) {
        $video = normalizeCommercialVideoEntry($entry);
        if ($video !== null) {
            $normalized[] = $video;
        }
    }

    if (empty($normalized)) {
        throw new RuntimeException('At least one valid YouTube video is required.');
    }

    $normalized = array_slice($normalized, 0, 3);
    $path = commercialVideosConfigPath();
    $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        throw new RuntimeException('Unable to encode video configuration.');
    }

    if (@file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Unable to save video configuration.');
    }

    return $normalized;
}

function resolveUserCompanyId(PDO $pdo, array $user): int
{
    $companyId = (int) ($user['company_id'] ?? 0);
    if ($companyId > 0) {
        return $companyId;
    }

    $departmentId = (int) ($user['department_id'] ?? 0);
    if ($departmentId <= 0) {
        return 0;
    }

    $statement = $pdo->prepare('SELECT company_id FROM departments WHERE id = :id');
    $statement->execute(['id' => $departmentId]);

    return (int) ($statement->fetchColumn() ?: 0);
}

function isCompanyActive(PDO $pdo, int $companyId): bool
{
    if ($companyId <= 0) {
        return true;
    }

    $statement = $pdo->prepare('SELECT is_active FROM companies WHERE id = :id');
    $statement->execute(['id' => $companyId]);
    $value = $statement->fetchColumn();

    return $value === false || (int) $value === 1;
}

function ensureConnectionTrackingSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_connections (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                session_id VARCHAR(128) NOT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                logged_in_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                logged_out_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_connections_session (session_id),
                KEY idx_user_connections_user (user_id),
                KEY idx_user_connections_last_seen (last_seen_at),
                KEY idx_user_connections_logged_out (logged_out_at),
                CONSTRAINT fk_user_connections_user
                    FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        // Keep the app running even if the current database user cannot create tables.
    }

    $initialized = true;
}

function recordUserConnectionLogin(PDO $pdo, ?array $user = null): void
{
    $user = $user ?? currentUser();
    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    ensureConnectionTrackingSchema($pdo);

    $now = appNow()->format('Y-m-d H:i:s');
    $statement = $pdo->prepare(
        'INSERT INTO user_connections (user_id, session_id, ip_address, user_agent, logged_in_at, last_seen_at, logged_out_at)
         VALUES (:user_id, :session_id, :ip_address, :user_agent, :logged_in_at, :last_seen_at, NULL)
         ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            ip_address = VALUES(ip_address),
            user_agent = VALUES(user_agent),
            logged_in_at = VALUES(logged_in_at),
            last_seen_at = VALUES(last_seen_at),
            logged_out_at = NULL'
    );
    $statement->execute([
        'user_id' => $userId,
        'session_id' => session_id(),
        'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'logged_in_at' => $now,
        'last_seen_at' => $now,
    ]);
}

function touchCurrentUserConnection(PDO $pdo, ?array $user = null): void
{
    $user = $user ?? currentUser();
    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    ensureConnectionTrackingSchema($pdo);

    $now = appNow()->format('Y-m-d H:i:s');
    $statement = $pdo->prepare(
        'INSERT INTO user_connections (user_id, session_id, ip_address, user_agent, logged_in_at, last_seen_at, logged_out_at)
         VALUES (:user_id, :session_id, :ip_address, :user_agent, :logged_in_at, :last_seen_at, NULL)
         ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            ip_address = VALUES(ip_address),
            user_agent = VALUES(user_agent),
            last_seen_at = VALUES(last_seen_at),
            logged_out_at = NULL'
    );
    $statement->execute([
        'user_id' => $userId,
        'session_id' => session_id(),
        'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'logged_in_at' => $now,
        'last_seen_at' => $now,
    ]);
}

function recordCurrentUserLogout(PDO $pdo, ?array $user = null): void
{
    $user = $user ?? currentUser();
    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    ensureConnectionTrackingSchema($pdo);

    $now = appNow()->format('Y-m-d H:i:s');
    $statement = $pdo->prepare(
        'UPDATE user_connections
         SET logged_out_at = :logged_out_at,
             last_seen_at = :last_seen_at
         WHERE session_id = :session_id
           AND user_id = :user_id
         ORDER BY id DESC
         LIMIT 1'
    );
    $statement->execute([
        'logged_out_at' => $now,
        'last_seen_at' => $now,
        'session_id' => session_id(),
        'user_id' => $userId,
    ]);
}

function appSessionIdleTimeoutSeconds(): int
{
    return 1800; // 30 minutes
}

function forceLogout(PDO $pdo, string $message): never
{
    try {
        recordCurrentUserLogout($pdo);
    } catch (Throwable $e) {
        // Ignore logout tracking failures.
    }

    unset($_SESSION['auth_user']);
    session_regenerate_id(true);
    setFlash('error', $message);
    redirectTo('login');
}

/**
 * Verifies the current session is still valid on every request: the user's
 * company must still be active, and the session must not have been idle
 * longer than appSessionIdleTimeoutSeconds(). Forces a logout otherwise.
 * Call once per request, in place of touchCurrentUserConnection().
 */
function enforceActiveSession(PDO $pdo): void
{
    $user = currentUser();
    if ($user === null) {
        return;
    }

    if (($user['role'] ?? '') !== 'super_admin') {
        $companyId = resolveUserCompanyId($pdo, $user);
        if (!isCompanyActive($pdo, $companyId)) {
            forceLogout($pdo, t('auth.company_inactive'));
        }
    }

    $statement = $pdo->prepare('SELECT last_seen_at FROM user_connections WHERE session_id = :session_id LIMIT 1');
    $statement->execute(['session_id' => session_id()]);
    $lastSeenAt = $statement->fetchColumn();

    if ($lastSeenAt !== false && $lastSeenAt !== null) {
        try {
            $lastSeen = new DateTimeImmutable((string) $lastSeenAt, appTimezone());
            $idleSeconds = appNow()->getTimestamp() - $lastSeen->getTimestamp();
            if ($idleSeconds > appSessionIdleTimeoutSeconds()) {
                forceLogout($pdo, t('auth.session_expired'));
            }
        } catch (Throwable $e) {
            // Ignore malformed timestamps and fall through to refreshing the session.
        }
    }

    touchCurrentUserConnection($pdo, $user);
}

/**
 * Sends a JSON response and stops execution.
 */

function jsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Stores and retrieves one-time flash messages from the session.
 */

function setFlash(string $key, string $message): void
{
    $_SESSION['flash'][$key] = $message;
}

function getFlash(string $key): ?string
{
    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $message = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);

    return $message;
}

/**
 * Authentication helpers used to inspect the current session user and roles.
 */

function currentUser(): ?array
{
    return $_SESSION['auth_user'] ?? null;
}

function currentUserCompanyId(?array $user = null): ?int
{
    $user = $user ?? currentUser();

    if ($user === null || empty($user['department_id'])) {
        return null;
    }

    try {
        $pdo = getPDO();
        $statement = $pdo->prepare(
            'SELECT company_id
             FROM departments
             WHERE id = :department_id
             LIMIT 1'
        );
        $statement->execute(['department_id' => (int) $user['department_id']]);

        $companyId = $statement->fetchColumn();

        return $companyId !== false ? (int) $companyId : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Checks whether the current user is logged in.
 */
function isLoggedIn(): bool
{
    return currentUser() !== null;
}

/**
 * Access middleware helpers.
 * Ensure the user is authenticated and has the expected role.
 * Redirect to login or dashboard when requirements are not met.
 */

/**
 * Checks whether the current user has the requested role.
 */
function hasRole(string $role): bool
{
    $user = currentUser();

    return $user !== null && ($user['role'] ?? null) === $role;
}

/**
 * Shortcut for checking the super admin role.
 */
function isSuperAdmin(): bool
{
    return hasRole('super_admin');
}


/**
 * Ensures the current user has the requested role or stops the request.
 */
function requireRole(string $role, string $errorMessage = ''): void
{
    if ($errorMessage === '') {
        $errorMessage = t('common.access_restricted');
    }

    if (!isLoggedIn()) {
        setFlash('error', t('common.login_required'));
        redirectTo('login');
    }

    if (!hasRole($role)) {
        setFlash('error', $errorMessage);
        redirectTo('dashboard');
    }
}

/**
 * Ensures the current user is a super admin or stops the request.
 */
function requireSuperAdmin(): void
{
    requireRole('super_admin', t('common.super_admin_only'));
}

/**
 * Ensures scheduler-related columns support shift kinds and open assignments
 * without introducing extra tables.
 */
function ensureSchedulerSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    try {
        $pdo->exec("ALTER TABLE shifts ADD COLUMN kind ENUM('work', 'rest', 'vacation', 'sick', 'overtime') NOT NULL DEFAULT 'work' AFTER description");
    } catch (Throwable $e) {
        // Column already exists.
    }

    try {
        $pdo->exec("ALTER TABLE shifts MODIFY kind ENUM('work', 'rest', 'vacation', 'sick', 'overtime') NOT NULL DEFAULT 'work'");
    } catch (Throwable $e) {
        // Ignore if enum already matches.
    }

    try {
        $pdo->exec('ALTER TABLE user_shifts MODIFY user_id INT NULL');
    } catch (Throwable $e) {
        // Ignore if already nullable.
    }

    try {
        $pdo->exec('ALTER TABLE user_shifts MODIFY status ENUM("open", "assigned", "completed", "cancelled", "in_progress") NOT NULL DEFAULT "assigned"');
    } catch (Throwable $e) {
        // Ignore if enum already matches.
    }

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_department_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                department_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_department (user_id, department_id),
                KEY idx_user_department_user (user_id),
                KEY idx_user_department_department (department_id),
                CONSTRAINT fk_user_department_links_user
                    FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_user_department_links_department
                    FOREIGN KEY (department_id) REFERENCES departments(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        // Table already exists or legacy schema issue.
    }

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_month_hours_plans (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                month_key DATE NOT NULL,
                planned_hours DECIMAL(7,2) NOT NULL DEFAULT 0,
                worked_hours_override DECIMAL(7,2) NULL,
                note VARCHAR(255) NULL,
                updated_by_user_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_month (user_id, month_key),
                KEY idx_user_month_key (month_key),
                KEY idx_user_month_updated_by (updated_by_user_id),
                CONSTRAINT fk_user_month_hours_plans_user
                    FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_user_month_hours_plans_updated_by
                    FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        // Table already exists or legacy schema issue.
    }

    $initialized = true;
}

function ensureDocumentStorageSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    try {
        $pdo->exec('ALTER TABLE documents ADD COLUMN file_blob LONGBLOB NULL AFTER file_path');
    } catch (Throwable $e) {
        // Column already exists.
    }

    try {
        $pdo->exec('ALTER TABLE documents ADD COLUMN file_mime_type VARCHAR(100) NULL AFTER file_blob');
    } catch (Throwable $e) {
        // Column already exists.
    }

    try {
        $pdo->exec('ALTER TABLE documents ADD COLUMN signed_at TIMESTAMP NULL DEFAULT NULL AFTER upload_date');
    } catch (Throwable $e) {
        // Column already exists.
    }

    try {
        $pdo->exec('ALTER TABLE documents ADD COLUMN signed_by_user_id INT NULL AFTER signed_at');
    } catch (Throwable $e) {
        // Column already exists.
    }

    try {
        $pdo->exec('ALTER TABLE documents ADD COLUMN signed_page INT NULL AFTER signed_by_user_id');
    } catch (Throwable $e) {
        // Column already exists.
    }

    // Keep legacy installations compatible with document archive/restore flow.
    try {
        $statusInfoStmt = $pdo->query("SHOW COLUMNS FROM documents LIKE 'status'");
        $statusInfo = $statusInfoStmt ? $statusInfoStmt->fetch(PDO::FETCH_ASSOC) : null;
        $statusType = strtolower((string) ($statusInfo['Type'] ?? ''));
        if ($statusType !== '' && str_contains($statusType, 'enum(') && !str_contains($statusType, "'archived'")) {
            $pdo->exec("ALTER TABLE documents MODIFY COLUMN status ENUM('valid','expired','pending','archived') NOT NULL DEFAULT 'pending'");
        }
    } catch (Throwable $e) {
        // Ignore to keep bootstrap resilient on restricted SQL privileges.
    }

    // Keep legacy installations compatible with message archive flow.
    try {
        $requestStatusInfoStmt = $pdo->query("SHOW COLUMNS FROM requests LIKE 'status'");
        $requestStatusInfo = $requestStatusInfoStmt ? $requestStatusInfoStmt->fetch(PDO::FETCH_ASSOC) : null;
        $requestStatusType = strtolower((string) ($requestStatusInfo['Type'] ?? ''));
        if ($requestStatusType !== '' && str_contains($requestStatusType, 'enum(') && !str_contains($requestStatusType, "'archived'")) {
            $pdo->exec("ALTER TABLE requests MODIFY COLUMN status ENUM('pending','approved','rejected','cancelled','read','unread','archived') NOT NULL DEFAULT 'pending'");
        }
    } catch (Throwable $e) {
        // Ignore to keep bootstrap resilient on restricted SQL privileges.
    }

    $initialized = true;
}

function absenceShiftTemplateDefinitions(): array
{
    return [
        'rest' => [
            'name' => 'Rest day',
            'icon' => 'popcorn.svg',
            'color' => '#9ca3af',
            'description' => 'System template for rest day assignment.',
            'start_time' => '00:00:00',
            'end_time' => '23:59:00',
        ],
        'vacation' => [
            'name' => 'Vacation',
            'icon' => 'parasol.svg',
            'color' => '#9ca3af',
            'description' => 'System template for vacation assignment.',
            'start_time' => '00:00:00',
            'end_time' => '23:59:00',
        ],
        'sick' => [
            'name' => 'Sick leave',
            'icon' => 'heart-pulse.svg',
            'color' => '#9ca3af',
            'description' => 'System template for sick leave assignment.',
            'start_time' => '00:00:00',
            'end_time' => '23:59:00',
        ],
    ];
}

function ensureDepartmentAbsenceShiftTemplates(PDO $pdo, int $departmentId): array
{
    if ($departmentId <= 0) {
        return [];
    }

    $templates = absenceShiftTemplateDefinitions();
    $idsByKind = [];

    $lookup = $pdo->prepare(
        'SELECT id
         FROM shifts
         WHERE department_id = :department_id
           AND kind = :kind
         ORDER BY id ASC
         LIMIT 1'
    );

    $insert = $pdo->prepare(
        'INSERT INTO shifts (department_id, name, icon, color, description, kind, start_time, end_time)
         VALUES (:department_id, :name, :icon, :color, :description, :kind, :start_time, :end_time)'
    );

    $refreshExisting = $pdo->prepare(
        'UPDATE shifts
         SET name = :name,
             icon = :icon,
             color = :color,
             description = :description,
             start_time = :start_time,
             end_time = :end_time
         WHERE id = :id'
    );

    foreach ($templates as $kind => $template) {
        $lookup->execute([
            'department_id' => $departmentId,
            'kind' => $kind,
        ]);

        $existingId = (int) ($lookup->fetchColumn() ?: 0);
        if ($existingId > 0) {
            $refreshExisting->execute([
                'name' => (string) ($template['name'] ?? ucfirst($kind)),
                'icon' => (string) ($template['icon'] ?? ''),
                'color' => (string) ($template['color'] ?? '#9ca3af'),
                'description' => (string) ($template['description'] ?? ''),
                'start_time' => (string) ($template['start_time'] ?? '00:00:00'),
                'end_time' => (string) ($template['end_time'] ?? '23:59:00'),
                'id' => $existingId,
            ]);
            $idsByKind[$kind] = $existingId;
            continue;
        }

        $insert->execute([
            'department_id' => $departmentId,
            'name' => (string) ($template['name'] ?? ucfirst($kind)),
            'icon' => (string) ($template['icon'] ?? ''),
            'color' => (string) ($template['color'] ?? '#9ca3af'),
            'description' => (string) ($template['description'] ?? ''),
            'kind' => $kind,
            'start_time' => (string) ($template['start_time'] ?? '00:00:00'),
            'end_time' => (string) ($template['end_time'] ?? '23:59:00'),
        ]);
        $idsByKind[$kind] = (int) $pdo->lastInsertId();
    }

    return $idsByKind;
}

function ensureAbsenceShiftTemplatesForDepartments(PDO $pdo, array $departmentIds): void
{
    foreach ($departmentIds as $departmentId) {
        ensureDepartmentAbsenceShiftTemplates($pdo, (int) $departmentId);
    }
}

/**
 * Resolve a best-guess MIME type from a file extension.
 * Returns 'application/octet-stream' when the extension is unknown.
 */
function mimeTypeFromFileExtension(string $fileName): string
{
    return match (strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION))) {
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        default => 'application/octet-stream',
    };
}

/**
 * Mark a document request as read and send a "document viewed" notification to the original sender.
 *
 * Used by both DocumentDownloadController (triggered via URL parameter) and
 * EmployeeSpaceController (triggered via POST action).
 *
 * Returns true when the request row was updated (i.e. it was previously unread/pending),
 * false when nothing changed (already read, wrong owner, etc.).
 *
 * @param PDO   $pdo          Active PDO connection.
 * @param int   $requestId    ID of the requests row to mark as read.
 * @param int   $recipientId  ID of the current user (must match requests.recipient_id).
 * @param array $viewer       Current user array (keys: id, first_name, last_name, email).
 * @param int   $documentId   Optional document ID for an additional WHERE filter (0 = skip).
 */
function markRequestReadAndNotifySender(PDO $pdo, int $requestId, int $recipientId, array $viewer, int $documentId = 0): bool
{
    $documentFilter = $documentId > 0 ? ' AND document_id = :document_id' : '';
    $markReadStatement = $pdo->prepare(
        'UPDATE requests
         SET status = "read",
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id
           AND recipient_id = :recipient_id'
        . $documentFilter . '
           AND type IN ("notification", "document_signature")
           AND status IN ("pending", "unread")
         LIMIT 1'
    );
    $params = [
        'id' => $requestId,
        'recipient_id' => $recipientId,
    ];
    if ($documentId > 0) {
        $params['document_id'] = $documentId;
    }
    $markReadStatement->execute($params);

    if ($markReadStatement->rowCount() === 0) {
        return false;
    }

    $requestInfoLookup = $pdo->prepare(
        'SELECT user_id, title, document_id
         FROM requests
         WHERE id = :id
           AND recipient_id = :recipient_id
         LIMIT 1'
    );
    $requestInfoLookup->execute([
        'id' => $requestId,
        'recipient_id' => $recipientId,
    ]);
    $requestInfo = $requestInfoLookup->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($requestInfo) {
        $senderId = (int) ($requestInfo['user_id'] ?? 0);
        if ($senderId > 0) {
            $viewerName = trim((string) (($viewer['first_name'] ?? '') . ' ' . ($viewer['last_name'] ?? '')));
            if ($viewerName === '') {
                $viewerName = (string) ($viewer['email'] ?? 'Employee');
            }

            $insertNotification = $pdo->prepare(
                'INSERT INTO requests (user_id, recipient_id, type, title, message, status, document_id)
                 VALUES (:user_id, :recipient_id, :type, :title, :message, :status, :document_id)'
            );
            $insertNotification->execute([
                'user_id' => (int) ($viewer['id'] ?? 0),
                'recipient_id' => $senderId,
                'type' => 'notification',
                'title' => 'Document viewed',
                'message' => $viewerName . ' viewed document "' . ((string) ($requestInfo['title'] ?? 'Document')) . '" successfully.',
                'status' => 'unread',
                'document_id' => (int) ($requestInfo['document_id'] ?? 0),
            ]);
        }
    }

    return true;
}

/**
 * Build a small preview thumbnail data URL for document cards.
 * Supports PDF (first page) and image mime types via Imagick.
 */
function documentThumbnailDataUrl(array $document, int $maxWidth = 220, int $maxHeight = 120): ?string
{
    if (!class_exists('Imagick')) {
        return null;
    }

    $maxWidth = max(60, $maxWidth);
    $maxHeight = max(40, $maxHeight);

    $documentId = (int) ($document['id'] ?? $document['document_id'] ?? 0);
    $fileName = (string) ($document['file_name'] ?? 'document');
    $uploadStamp = (string) ($document['upload_date'] ?? $document['created_at'] ?? '');
    $cacheKey = ($documentId > 0 ? (string) $documentId : ($fileName . '|' . $uploadStamp))
        . '|' . $maxWidth . 'x' . $maxHeight;

    static $cache = [];
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $mimeType = strtolower(trim((string) ($document['file_mime_type'] ?? '')));
    if ($mimeType === '') {
        $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension === 'pdf') {
            $mimeType = 'application/pdf';
        }
    }

    $isPdf = str_contains($mimeType, 'pdf');
    $isImage = str_starts_with($mimeType, 'image/');
    if (!$isPdf && !$isImage) {
        $cache[$cacheKey] = null;
        return null;
    }

    $blobContent = $document['file_blob'] ?? null;
    $filePath = trim((string) ($document['file_path'] ?? ''));
    $resolvedPath = null;

    if ($filePath !== '') {
        $candidates = [
            $filePath,
            __DIR__ . '/../' . ltrim($filePath, '/'),
            __DIR__ . '/../public/' . ltrim($filePath, '/'),
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                $resolvedPath = $candidate;
                break;
            }
        }
    }

    try {
        $imagick = new Imagick();
        if ($isPdf) {
            $imagick->setResolution(120, 120);
        }

        if (is_string($blobContent) && $blobContent !== '') {
            $imagick->readImageBlob($blobContent);
            if ($isPdf && $imagick->getNumberImages() > 1) {
                $imagick->setIteratorIndex(0);
            }
        } elseif ($resolvedPath !== null) {
            if ($isPdf) {
                $imagick->readImage($resolvedPath . '[0]');
            } else {
                $imagick->readImage($resolvedPath);
            }
        } else {
            $cache[$cacheKey] = null;
            return null;
        }

        $imagick->setImageBackgroundColor('white');
        if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
            $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        }

        $imagick->thumbnailImage($maxWidth, $maxHeight, true, true);
        $imagick->setImageFormat('jpeg');
        $blob = (string) $imagick->getImageBlob();
        $imagick->clear();
        $imagick->destroy();

        if ($blob === '') {
            $cache[$cacheKey] = null;
            return null;
        }

        $cache[$cacheKey] = 'data:image/jpeg;base64,' . base64_encode($blob);
        return $cache[$cacheKey];
    } catch (Throwable $e) {
        $cache[$cacheKey] = null;
        return null;
    }
}

function csrfToken(): string
{
    startAppSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool
{
    startAppSession();
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');

    return $sessionToken !== '' && is_string($token) && hash_equals($sessionToken, $token);
}

function newSignupCaptchaChallenge(): array
{
    startAppSession();
    $a = random_int(1, 9);
    $b = random_int(1, 9);
    $_SESSION['signup_captcha_a'] = $a;
    $_SESSION['signup_captcha_b'] = $b;
    $_SESSION['signup_captcha_answer'] = $a + $b;

    return ['a' => $a, 'b' => $b];
}

function verifySignupCaptcha(mixed $submitted): bool
{
    $expected = $_SESSION['signup_captcha_answer'] ?? null;

    return $expected !== null && (string) $expected === trim((string) $submitted);
}

function ensureSignupThrottleSchema(PDO $pdo): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS signup_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_signup_attempts_ip_created (ip_address, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        // Keep the app running even if the current database user cannot create tables.
    }

    $initialized = true;
}

function recentSignupAttemptCount(PDO $pdo, string $ip, int $windowMinutes = 60): int
{
    try {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM signup_attempts WHERE ip_address = :ip AND created_at >= DATE_SUB(NOW(), INTERVAL :mins MINUTE)'
        );
        $statement->bindValue(':ip', $ip);
        $statement->bindValue(':mins', $windowMinutes, PDO::PARAM_INT);
        $statement->execute();

        return (int) $statement->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function recordSignupAttempt(PDO $pdo, string $ip): void
{
    try {
        $pdo->prepare('INSERT INTO signup_attempts (ip_address) VALUES (:ip)')->execute(['ip' => $ip]);
    } catch (Throwable $e) {
        // Ignore throttle-tracking failures so signup can still proceed.
    }
}