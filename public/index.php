<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$config = site_config();
if ($config['market_data_dir'] !== '' && getenv('JSD_MARKET_DATA_DIR') === false) {
    putenv('JSD_MARKET_DATA_DIR=' . $config['market_data_dir']);
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

$page = input($_GET, 'page', 'markets');
if ($page === 'api') {
    require dirname(__DIR__) . '/app/api.php';
    serve_market_api($config);
}

require_once dirname(__DIR__) . '/app/actions.php';
require_once dirname(__DIR__) . '/app/layout.php';
require_once dirname(__DIR__) . '/app/dashboard.php';
require_once dirname(__DIR__) . '/app/pages.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD, POST');
    exit;
}

$secure = $config['secure_cookies'] || !in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['', 'off', '0'], true);
accounts_session_start($secure);
try {
    $db = accounts_database($config['database']);
    $user = accounts_current_user($db);
} catch (PDOException $exception) {
    error_log('JSD database unavailable: ' . $exception->getCode());
    $db = $user = null;
}

$boardEnabled = $config['community_enabled'] && $db !== null;
if ($method === 'POST') {
    handle_action($config, $db, $user, $boardEnabled);
}

$guides = require dirname(__DIR__) . '/content/guides.php';
$guide = $guides[input($_GET, 'guide')] ?? null;
$symbolQuery = input($_GET, 'symbol');
$symbol = market_symbol($symbolQuery);
if ($page === 'markets' && $symbol === null && $symbolQuery !== '') {
    foreach (market_symbols() as $candidate) {
        if (strcasecmp($candidate['name'], trim($symbolQuery)) === 0) {
            $symbol = $candidate['symbol'];
            break;
        }
    }
}
$range = strtoupper(trim(input($_GET, 'range', '3M')));
if (!in_array($range, market_ranges(), true)) {
    $range = '3M';
}
$topicId = filter_var(input($_GET, 'topic'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

$pages = [
    'markets' => 'Market overview',
    'watchlist' => 'Watchlist',
    'guides' => 'Guides',
    'guide' => $guide['title'] ?? 'Guide unavailable',
    'tools' => 'Calculators',
    'api-guide' => 'Market data API',
    'community' => 'Community',
    'account' => $user === null ? 'Sign in' : 'Account',
    'register' => 'Create an account',
];
if (!isset($pages[$page]) || ($page === 'guide' && $guide === null)) {
    http_response_code(404);
}
$watchlist = $user !== null && $db !== null
    ? array_column(accounts_watchlist($db, (int) $user['id']), 'symbol')
    : ($_SESSION['guest_watchlist'] ?? []);
$notice = take_flash();
if ($page === 'markets' && $symbol === null && trim($symbolQuery) !== '') {
    $notice = ['text' => 'No listing matches “' . substr($symbolQuery, 0, 64) . '”. Try RY, ENB, XIU or another ticker below.', 'kind' => 'error'];
}

render_layout_start($page, $pages[$page] ?? 'Page unavailable', $user, $boardEnabled, $notice);
match ($page) {
    'markets' => render_dashboard($symbol ?? market_symbol('^GSPTSE') ?? array_key_first(market_symbols()), $range, input($_GET, 'kind'), $watchlist, $guides),
    'watchlist' => render_watchlist($watchlist, $user, $db !== null),
    'guides' => render_guides($guides),
    'guide' => $guide !== null ? render_guide($guide) : render_missing(),
    'tools' => render_tools(),
    'api-guide' => render_api_guide($config),
    'community' => render_community($boardEnabled ? $db : null, $user, $topicId),
    'account' => render_account($user, $db !== null, $config['registration_enabled'], false),
    'register' => render_account($user, $db !== null, $config['registration_enabled'], true),
    default => render_missing(),
};
render_layout_end();
