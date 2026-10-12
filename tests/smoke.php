<?php
declare(strict_types=1);

require_once __DIR__ . '/../Front-end/connectapi/app_config.php';
require_once __DIR__ . '/../Front-end/connectapi/security.php';

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

putenv('PROJECTJAPAN_BASE_PATH=/jp-learning');
check(appBasePath() === '/jp-learning', 'Base path normalization failed');
check(appUrl('Front-end/data/member.php') === '/jp-learning/Front-end/data/member.php', 'URL generation failed');
putenv('PROJECTJAPAN_BASE_PATH');

$_SERVER['SCRIPT_NAME'] = '/Front-end/connectapi/login.php';
check(appBasePath() === '', 'Root deployment base path detection failed');
$_SERVER['SCRIPT_NAME'] = '/projectjapan/Front-end/connectapi/login.php';
check(appBasePath() === '/projectjapan', 'Subdirectory deployment base path detection failed');
unset($_SERVER['SCRIPT_NAME']);

$_SERVER['HTTPS'] = 'off';
securityStartSession();
$token = csrfToken();
check(strlen($token) === 64, 'CSRF token length is invalid');
check(hash_equals($token, csrfToken()), 'CSRF token is not stable during a session');

fwrite(STDOUT, "Smoke tests passed: {$checks}\n");
