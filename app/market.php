<?php

declare(strict_types=1);

const MARKET_DEMO_DATE = '2026-10-30';
const MARKET_DEMO_SOURCE = 'JSD Enterprises synthetic data';
const MARKET_RANGES_IN_MONTHS = ['1M' => 1, '3M' => 3, '6M' => 6, '1Y' => 12];

function market_catalog(): array
{
    static $catalog = null;
    return $catalog ??= require dirname(__DIR__) . '/content/markets.php';
}

function market_symbols(): array
{
    $symbols = [];
    foreach (market_catalog() as $symbol => $instrument) {
        $symbols[$symbol] = [
            'symbol' => $symbol,
            'name' => $instrument['name'],
            'sector' => $instrument['sector'],
            'exchange' => $instrument['exchange'] ?? 'TSX',
            'currency' => $instrument['currency'] ?? 'CAD',
            'type' => $instrument['type'],
        ];
    }
    return $symbols;
}

function market_symbol(string $raw): ?string
{
    $symbol = strtoupper(trim($raw));
    $exchange = null;
    foreach (['TSX:', 'TSXV:'] as $prefix) {
        if (str_starts_with($symbol, $prefix)) {
            $exchange = rtrim($prefix, ':');
            $symbol = trim(substr($symbol, strlen($prefix)));
            break;
        }
    }
    if (str_ends_with($symbol, '.TO')) {
        $symbol = substr($symbol, 0, -3);
    }
    if ($symbol === 'USD/CAD' || $symbol === 'USDCAD') {
        $symbol = 'CAD=X';
    }
    $instrument = market_catalog()[$symbol] ?? null;
    if ($instrument === null || ($exchange !== null && ($instrument['exchange'] ?? 'TSX') !== $exchange)) {
        return null;
    }
    return $symbol;
}

function market_ranges(): array
{
    return array_keys(MARKET_RANGES_IN_MONTHS);
}

function market_mode(): string
{
    $directory = getenv('JSD_MARKET_DATA_DIR');
    return is_string($directory) && trim($directory) !== '' ? 'licensed' : 'demo';
}

function market_snapshot(): array
{
    $snapshot = [];
    foreach (array_keys(market_catalog()) as $symbol) {
        $quote = market_series($symbol);
        unset($quote['range'], $quote['points']);
        $snapshot[$symbol] = $quote;
    }
    return $snapshot;
}

function market_series(string $symbol, string $range = '3M'): ?array
{
    $symbol = market_symbol($symbol);
    $range = strtoupper(trim($range));
    if ($symbol === null || !isset(MARKET_RANGES_IN_MONTHS[$range])) {
        return null;
    }

    $instrument = market_symbols()[$symbol];
    $data = market_history($symbol, $instrument);
    if ($data === null) {
        return $instrument + [
            'range' => $range, 'mode' => 'unavailable', 'source' => null, 'as_of' => null,
            'price' => null, 'change' => null, 'change_percent' => null, 'points' => [],
        ];
    }

    $points = $data['points'];
    $latest = $points[count($points) - 1];
    $previousClose = $points[count($points) - 2]['close'];
    $precision = $instrument['type'] === 'fx' ? 4 : 2;
    $change = $latest['close'] - $previousClose;
    $changePercent = 100 * ($change / $previousClose);
    $lastDate = new DateTimeImmutable($latest['date'], new DateTimeZone('UTC'));
    $cutoffMonth = $lastDate->modify('first day of this month')->modify('-' . MARKET_RANGES_IN_MONTHS[$range] . ' months');
    $cutoffDay = min((int) $lastDate->format('j'), (int) $cutoffMonth->format('t'));
    $cutoff = $cutoffMonth->format('Y-m-') . str_pad((string) $cutoffDay, 2, '0', STR_PAD_LEFT);

    return $instrument + [
        'range' => $range,
        'mode' => $data['mode'],
        'source' => $data['source'],
        'as_of' => $latest['date'],
        'price' => $latest['close'],
        'change' => round($change, $precision),
        'change_percent' => is_finite($changePercent) ? round($changePercent, 2) : null,
        'points' => array_values(array_filter($points, static fn (array $point): bool => $point['date'] >= $cutoff)),
    ];
}

function market_history(string $symbol, array $instrument): ?array
{
    static $histories = [];
    if (array_key_exists($symbol, $histories)) {
        return $histories[$symbol];
    }
    if (market_mode() === 'licensed') {
        return $histories[$symbol] = market_licensed_history($symbol, $instrument['currency']);
    }
    return $histories[$symbol] = [
        'mode' => 'demo',
        'source' => MARKET_DEMO_SOURCE,
        'points' => market_demo_points($symbol, market_catalog()[$symbol]),
    ];
}

function market_licensed_history(string $symbol, string $currency): ?array
{
    $directory = trim((string) getenv('JSD_MARKET_DATA_DIR'));
    $file = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . rawurlencode($symbol) . '.json';
    if (!is_file($file) || !is_readable($file)) {
        return null;
    }

    $limit = 1024 * 1024;
    $raw = @file_get_contents($file, false, null, 0, $limit + 1);
    if ($raw === false || strlen($raw) > $limit) {
        return null;
    }

    $data = json_decode($raw, true, 8);
    $source = $data['source'] ?? null;
    $rows = $data['points'] ?? null;
    if (!is_string($source) || trim($source) === '' || strlen($source) > 150
        || ($data['currency'] ?? null) !== $currency || !is_array($rows) || !array_is_list($rows) || count($rows) < 2) {
        return null;
    }

    $points = [];
    foreach ($rows as $row) {
        $date = $row['date'] ?? null;
        $close = $row['close'] ?? null;
        if (!is_string($date) || preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || isset($points[$date])
            || (!is_int($close) && !is_float($close)) || !is_finite((float) $close) || $close <= 0) {
            return null;
        }
        $points[$date] = ['date' => $date, 'close' => (float) $close];
    }
    ksort($points, SORT_STRING);
    // With one point per day, this always covers the widest chart range.
    return ['mode' => 'licensed', 'source' => trim($source), 'points' => array_slice(array_values($points), -370)];
}

function market_demo_points(string $symbol, array $instrument): array
{
    $end = new DateTimeImmutable(MARKET_DEMO_DATE, new DateTimeZone('UTC'));
    $start = $end->modify('-13 months');
    $days = (int) $start->diff($end)->days;
    $phase = market_demo_noise($symbol) * M_PI;
    $precision = $instrument['type'] === 'fx' ? 4 : 2;
    $points = [];
    for ($day = 0, $date = $start; $date <= $end; $day++, $date = $date->modify('+1 day')) {
        if ((int) $date->format('N') > 5) {
            continue;
        }
        $time = $day / $days;
        $label = $date->format('Y-m-d');
        $wave = 0.65 * sin($time * 19 + $phase) + 0.30 * sin($time * 71 + $phase * 2);
        $jitter = 0.18 * market_demo_noise($symbol . $label);
        $price = $instrument['base'] * (1 + $instrument['drift'] * $time + $instrument['volatility'] * ($wave + $jitter));
        $points[] = ['date' => $label, 'close' => round($price, $precision)];
    }
    return $points;
}

function market_demo_noise(string $key): float
{
    return 2 * (hexdec(substr(hash('sha256', $key), 0, 7)) / 0xfffffff) - 1;
}
