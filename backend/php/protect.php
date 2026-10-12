<?php
require_once __DIR__ . '/../../Front-end/connectapi/security.php';
require_once __DIR__ . '/../../Front-end/connectapi/app_config.php';
securityStartSession();

$loginUrl = appUrl('Front-end/sceens/login.html');

if (!isset($_SESSION['admin']) || empty($_SESSION['admin']['admin_id'])) {
    header('Location: ' . $loginUrl);
    exit;
}

require_once __DIR__ . '/../../Front-end/connectapi/db.php';

$adminId = $_SESSION['admin']['admin_id'];

try {
    $stmt = $conn->prepare('SELECT admin_id, username, name, email FROM admin WHERE admin_id = :id LIMIT 1');
    $stmt->execute([':id' => $adminId]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        session_destroy();
        header('Location: ' . appUrl('Front-end/sceens/login.html'));
        exit;
    }

    $_SESSION['admin'] = [
        'admin_id' => (int) $admin['admin_id'],
        'firstname' => $admin['name'] ?? '',
        'lastname' => '',
        'email' => $admin['email'] ?? '',
    ];
} catch (PDOException $exception) {
    error_log('Admin authorization failed: ' . $exception->getMessage());
    session_destroy();
    header('Location: ' . $loginUrl);
    exit;
}

function renderAdminTemplate(string $path, array $replacements = []): void
{
    $template = file_get_contents($path);
    if ($template === false) {
        throw new RuntimeException('Unable to read admin template: ' . $path);
    }

    if ($replacements !== []) {
        $template = strtr($template, $replacements);
    }

    echo str_replace('/projectjapan/', appBasePath() . '/', $template);
}
