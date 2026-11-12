<?php

declare(strict_types=1);

function serve_market_api(array $config): never
{
    header('Content-Type: application/json; charset=utf-8');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        market_api_response(['error' => 'Method not allowed.'], 405);
    }

    $licensed = market_mode() === 'licensed';
    if ($licensed && !$config['publish_licensed_api']) {
        market_api_response(['error' => 'The market data API is disabled for this feed.'], 403);
    }

    $query = input($_GET, 'symbol');
    $range = strtoupper(trim(input($_GET, 'range', '3M')));
    if ((isset($_GET['symbol']) && $query === '') || !in_array($range, market_ranges(), true)) {
        market_api_response(['error' => 'Specify a symbol and a valid range (1M, 3M, 6M or 1Y).'], 400);
    }
    if ($query === '') {
        header('Cache-Control: public, max-age=300');
        market_api_response(['mode' => $licensed ? 'licensed' : 'demo', 'quotes' => array_values(market_snapshot())]);
    }

    $symbol = market_symbol($query);
    $series = $symbol === null ? null : market_series($symbol, $range);
    if ($series === null) {
        market_api_response(['error' => 'Unknown symbol.'], 404);
    }
    if ($series['mode'] === 'unavailable') {
        market_api_response($series, 503);
    }
    header('Cache-Control: public, max-age=300');
    market_api_response($series);
}

function market_api_response(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    exit;
}
