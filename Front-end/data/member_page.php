<?php
declare(strict_types=1);

/**
 * Renders a member-only page with its navigation already present in the first
 * HTML response.  This avoids the visible empty navbar while JavaScript loads.
 *
 * @param array<string, mixed> $member
 * @param array<string, string|int|float> $replacements
 */
function renderMemberPage(string $templatePath, array $member, array $replacements = []): void
{
    $template = file_get_contents($templatePath);
    if ($template === false) {
        http_response_code(500);
        exit('Unable to load member page template.');
    }

    $navbarPath = __DIR__ . '/../sceens/_navbar.html';
    $navbar = file_get_contents($navbarPath);
    if ($navbar === false) {
        http_response_code(500);
        exit('Unable to load member navigation.');
    }

    $displayName = htmlspecialchars(
        (string)($member['firstname'] ?? $member['username'] ?? 'ผู้ใช้'),
        ENT_QUOTES,
        'UTF-8'
    );

    $values = array_merge([
        '{{navUsername}}' => $displayName,
        '{{memberNavbar}}' => strtr($navbar, ['{{navUsername}}' => $displayName]),
    ], $replacements);

    $template = str_replace(
        '<div data-member-navbar></div>',
        '<div data-member-navbar>{{memberNavbar}}</div>',
        $template
    );
    echo strtr($template, $values);
}
