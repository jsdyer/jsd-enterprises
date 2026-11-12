<?php

declare(strict_types=1);

require_once __DIR__ . '/market.php';
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/community.php';

function site_config(): array
{
    $defaults = [
        'database' => [
            'dsn' => getenv('JSD_DB_DSN') ?: '',
            'username' => getenv('JSD_DB_USER') ?: '',
            'password' => (string) getenv('JSD_DB_PASSWORD'),
        ],
        'registration_enabled' => true,
        'community_enabled' => false,
        'secure_cookies' => false,
        'market_data_dir' => '',
        'publish_licensed_api' => false,
    ];

    $file = dirname(__DIR__) . '/config/local.php';
    return is_file($file) ? array_replace_recursive($defaults, require $file) : $defaults;
}

function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function input(array $source, string $key, string $default = ''): string
{
    return is_string($source[$key] ?? null) ? $source[$key] : $default;
}

function site_url(string $page, array $parameters = [], string $fragment = ''): string
{
    $url = '?' . http_build_query(['page' => $page] + $parameters, '', '&', PHP_QUERY_RFC3986);
    return $url . ($fragment === '' ? '' : '#' . rawurlencode($fragment));
}

function safe_redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function flash(string $message, string $kind = 'success'): void
{
    $_SESSION['notice'] = ['text' => $message, 'kind' => $kind];
}

function take_flash(): ?array
{
    $notice = $_SESSION['notice'] ?? null;
    unset($_SESSION['notice']);
    return $notice;
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(accounts_csrf_token()) . '">';
}

function quote_price(array $quote): string
{
    if ($quote['price'] === null) {
        return '—';
    }
    return number_format($quote['price'], $quote['type'] === 'fx' ? 4 : 2);
}

function quote_change(?float $number): string
{
    return $number === null ? '—' : ($number > 0 ? '+' : '') . number_format($number, 2) . '%';
}

function change_class(?float $number): string
{
    return match (true) {
        $number === null, $number === 0.0 => 'neutral',
        $number > 0 => 'positive',
        default => 'negative',
    };
}

function display_date(?string $date): string
{
    return $date === null ? '—' : (new DateTimeImmutable($date, new DateTimeZone('UTC')))->format('M j, Y');
}

function icon(string $name, int $size = 20): string
{
    $paths = [
        'logo' => '<path d="m3 18 5-5 4 3 8-10m-5 0h5v5"/><path d="M4 6v4m-2-2h4"/>',
        'market' => '<path d="M3 3v18h18M7 15l5-5 4 3 5-6"/>',
        'star' => '<path d="m12 3 2.8 5.7 6.3.9-4.6 4.5 1.1 6.3-5.6-3-5.6 3 1.1-6.3L3 9.6l6.2-.9L12 3Z"/>',
        'book' => '<path d="M4 19V5a2 2 0 0 1 2-2h14v18H6a2 2 0 0 1 0-4h14M8 7h8m-8 4h5"/>',
        'tools' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M9 12h1m4 0h1m-6 4h1m4 0h1"/>',
        'community' => '<path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5H5l1.4-3A8.5 8.5 0 1 1 21 11.5Z"/><path d="M8 11h.01m4 0h.01m4 0h.01"/>',
        'code' => '<path d="m8 6-6 6 6 6m8-12 6 6-6 6m-2-15-4 18"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/>',
        'user' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 21v-2a7 7 0 0 1 14 0v2"/>',
        'up' => '<path d="m7 15 8-8m-6 0h6v6"/>',
        'down' => '<path d="m7 9 8 8m-6 0h6v-6"/>',
        'arrow' => '<path d="M4 12h15m-6-6 6 6-6 6"/>',
        'chevron' => '<path d="m9 18 6-6-6-6"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18m-9-9a14 14 0 0 1 0 18m0-18a14 14 0 0 0 0 18"/>',
        'stack' => '<path d="m12 3 9 5-9 5-9-5 9-5Zm-9 9 9 5 9-5M3 16l9 5 9-5"/>',
        'dollar' => '<path d="M12 2v20m5-16H9a3 3 0 0 0 0 6h6a3 3 0 1 1 0 6H6"/>',
        'bank' => '<path d="m3 8 9-5 9 5v2H3V8Zm2 12h14M7 13v4m5-4v4m5-4v4"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8h.01"/>',
        'external' => '<path d="M13 4h7v7m0-7-9 9m7 0v7H4V6h7"/>',
        'minus' => '<path d="M5 12h14"/>',
    ];

    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"'
        . ' stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? '') . '</svg>';
}
