<?php
declare(strict_types=1);

function securityStartSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (session_status() === PHP_SESSION_NONE) {
        $cookieParams = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!empty($cookieParams['path']) && $cookieParams['path'] !== '/') {
            session_set_cookie_params(['path' => '/']);
        }

        session_start();
    }
}

function csrfToken(): string
{
    securityStartSession();
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function requireCsrfToken(?string $token = null): void
{
    $provided = $token ?? (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($provided === '') {
        $provided = (string) ($_POST['csrf_token'] ?? '');
    }
    if ($provided === '' || !hash_equals(csrfToken(), $provided)) {
        http_response_code(419);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => 'คำขอไม่ถูกต้อง กรุณาโหลดหน้าใหม่'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function enforceRateLimit(string $scope, string $identifier, int $limit = 10, int $windowSeconds = 900): void
{
    $key = hash('sha256', $scope . '|' . $identifier);
    $file = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'projectjapan_rate_' . $key . '.json';
    $now = time();
    $attempts = [];
    if (is_file($file)) {
        $decoded = json_decode((string) file_get_contents($file), true);
        $attempts = is_array($decoded) ? array_filter($decoded, static fn ($time): bool => is_int($time) && $time > $now - $windowSeconds) : [];
    }
    if (count($attempts) >= $limit) {
        http_response_code(429);
        header('Retry-After: ' . $windowSeconds);
        exit('คำขอมากเกินไป กรุณาลองใหม่ภายหลัง');
    }
    $attempts[] = $now;
    file_put_contents($file, json_encode(array_values($attempts)), LOCK_EX);
}

function clearRateLimit(string $scope, string $identifier): void
{
    $key = hash('sha256', $scope . '|' . $identifier);
    $file = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'projectjapan_rate_' . $key . '.json';
    if (is_file($file)) {
        @unlink($file);
    }
}

function passwordMatchesStoredValue(string $stored, string $password): bool
{
    $stored = trim((string) $stored);
    $password = (string) $password;

    if ($stored === '' || $password === '') {
        return false;
    }

    if (password_verify($password, $stored)) {
        return true;
    }

    return strcasecmp($stored, $password) === 0;
}

function addSecurityHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function currentAuth(): array
{
    if (!empty($_SESSION['auth']) && is_array($_SESSION['auth'])) {
        $authType = strtolower((string) ($_SESSION['auth']['type'] ?? ''));
        $memberId = (int) ($_SESSION['auth']['member_id'] ?? $_SESSION['auth']['user_id'] ?? $_SESSION['auth']['id'] ?? 0);
        $adminId = (int) ($_SESSION['auth']['admin_id'] ?? 0);
        if ($adminId <= 0 && $authType === 'admin') {
            $adminId = (int) ($_SESSION['auth']['id'] ?? 0);
        }

        if ($authType === 'admin' || ($adminId > 0 && $memberId === 0)) {
            return ['type' => 'admin', 'id' => $adminId, 'member_id' => 0, 'admin_id' => $adminId];
        }

        if ($authType === 'member' || $memberId > 0) {
            return ['type' => 'member', 'id' => $memberId, 'member_id' => $memberId, 'admin_id' => 0];
        }
    }

    if (!empty($_SESSION['member']['id'])) {
        return ['type' => 'member', 'id' => (int) $_SESSION['member']['id'], 'member_id' => (int) $_SESSION['member']['id'], 'admin_id' => 0];
    }

    if (!empty($_SESSION['admin']['admin_id'])) {
        $adminId = (int) $_SESSION['admin']['admin_id'];
        return ['type' => 'admin', 'id' => $adminId, 'member_id' => 0, 'admin_id' => $adminId];
    }

    return ['type' => null, 'id' => 0, 'member_id' => 0, 'admin_id' => 0];
}

function resolveCurrentMemberId(): int
{
    $auth = currentAuth();
    if ($auth['type'] === 'member' && $auth['id'] > 0) {
        return $auth['id'];
    }

    $memberId = (int) ($_SESSION['member']['id'] ?? 0);
    if ($memberId > 0) {
        return $memberId;
    }

    return 0;
}

function requireSameOriginRequest(): void
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $requestOrigin = $origin !== '' ? $origin : ($referer !== '' ? $referer : '');
    if ($requestOrigin === '') {
        requireCsrfToken();
        return;
    }

    $requestHost = parse_url($requestOrigin, PHP_URL_HOST);
    $requestPort = parse_url($requestOrigin, PHP_URL_PORT);
    $expectedHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($requestHost === false || $requestHost === null || $requestHost !== ($_SERVER['SERVER_NAME'] ?? $expectedHost)
        || ($requestPort !== null && $requestPort !== (int) ($_SERVER['SERVER_PORT'] ?? 0))) {
        http_response_code(403);
        exit('คำขอข้ามต้นทางถูกปฏิเสธ');
    }
}

/**
 * Login attempts are tracked separately from the legacy request limiter.
 * The older limiter counted every submitted form, including successful
 * logins, which could lock a legitimate local user out while testing.
 */
function failedLoginRateLimitFile(string $scope, string $identifier): string
{
    $key = hash('sha256', 'failed-login-v2|' . $scope . '|' . $identifier);
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'projectjapan_rate_' . $key . '.json';
}

function failedLoginAttempts(string $scope, string $identifier, int $windowSeconds): array
{
    $file = failedLoginRateLimitFile($scope, $identifier);
    if (!is_file($file)) {
        return [];
    }

    $now = time();
    $decoded = json_decode((string) file_get_contents($file), true);
    return is_array($decoded)
        ? array_values(array_filter($decoded, static fn ($time): bool => is_int($time) && $time > $now - $windowSeconds))
        : [];
}

function enforceFailedLoginRateLimit(string $scope, string $identifier, int $limit = 8, int $windowSeconds = 900): void
{
    if (count(failedLoginAttempts($scope, $identifier, $windowSeconds)) < $limit) {
        return;
    }

    http_response_code(429);
    header('Retry-After: ' . $windowSeconds);
    exit('คำขอมากเกินไป กรุณาลองใหม่ภายหลัง');
}

function recordFailedLoginAttempt(string $scope, string $identifier, int $windowSeconds = 900): void
{
    $attempts = failedLoginAttempts($scope, $identifier, $windowSeconds);
    $attempts[] = time();
    file_put_contents(failedLoginRateLimitFile($scope, $identifier), json_encode($attempts), LOCK_EX);
}

function clearFailedLoginRateLimit(string $scope, string $identifier): void
{
    $file = failedLoginRateLimitFile($scope, $identifier);
    if (is_file($file)) {
        @unlink($file);
    }
}
