<?php

declare(strict_types=1);

function render_dashboard(?string $symbol, string $range, string $kind, array $watchlist, array $guides): void
{
    if ($symbol === null) {
        page_intro('Canadian markets', 'Browse Canadian stocks, ETFs and benchmarks.');
        empty_panel('No listings yet', 'Listings will appear here when the market catalogue is populated.', 'market');
        return;
    }
    $series = market_series($symbol, $range);
    $snapshot = market_snapshot();
    $mode = market_mode();
    $kind = in_array($kind, ['stock', 'etf'], true) ? $kind : '';
    $listings = array_filter($snapshot, static fn (array $quote): bool =>
        $kind === '' ? in_array($quote['type'], ['stock', 'etf'], true) : $quote['type'] === $kind
    );

    page_intro('Canadian markets', 'Follow Canadian stocks, ETFs and benchmarks in one place.',
        '<span class="source-pill"><span></span>' . ($mode === 'demo' ? 'Demo data' : 'End-of-day data') . '</span>');
    ?>
    <section class="metrics" aria-label="Market benchmarks">
        <?php foreach ([
            '^GSPTSE' => ['S&P/TSX Composite', 'market'],
            '^SPCDNX' => ['TSX Venture Composite', 'stack'],
            'CAD=X' => ['USD / CAD', 'globe'],
            'XIU' => ['TSX 60 ETF', 'bank'],
        ] as $ticker => [$label, $iconName]): ?>
            <?php $metric = market_series($ticker, '1M'); ?>
            <?php if ($metric === null): continue; endif; ?>
            <a class="metric panel <?= $ticker === $symbol ? 'current' : '' ?>" href="<?= e(site_url('markets', ['symbol' => $ticker, 'range' => $range], 'chart')) ?>">
                <span class="metric-heading"><span class="metric-icon"><?= icon($iconName, 16) ?></span><?= e($label) ?></span>
                <strong><?= e(quote_price($metric)) ?></strong>
                <span class="metric-bottom">
                    <span class="delta <?= e(change_class($metric['change_percent'])) ?>"><?= e(quote_change($metric['change_percent'])) ?></span>
                    <span><?= $metric['mode'] === 'unavailable' ? 'Unavailable' : 'prev. close' ?></span>
                </span>
                <?= render_sparkline($metric['points'], change_class($metric['change_percent'])) ?>
            </a>
        <?php endforeach; ?>
    </section>

    <div class="dashboard-grid">
        <?php render_chart_panel($series, $watchlist); ?>
        <?php render_movers($snapshot, $series, $kind); ?>
    </div>

    <div class="dashboard-grid lower-grid">
        <section class="panel listings-panel" id="listings">
            <div class="panel-heading listings-heading">
                <div><h2>Canadian listings</h2><p>Explore the stock and ETF catalogue.</p></div>
                <nav class="segments" aria-label="Listing type">
                    <?php foreach (['' => 'All', 'stock' => 'Stocks', 'etf' => 'ETFs'] as $filter => $label): ?>
                    <a class="<?= $kind === $filter ? 'selected' : '' ?>" <?= $kind === $filter ? 'aria-current="true"' : '' ?>
                        href="<?= e(site_url('markets', ['symbol' => $symbol, 'range' => $range, 'kind' => $filter], 'listings')) ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>
            <?php render_quote_table($listings, false); ?>
        </section>
        <section class="panel research-panel">
            <div class="panel-heading"><div><h2>Investing guides</h2><p>Practical walkthroughs for Canada.</p></div></div>
            <?php foreach ([
                'ibkr-tws-api' => 'code',
                'rbc-direct-investing-statements' => 'bank',
                'canadian-registered-accounts' => 'book',
            ] as $slug => $iconName): ?>
                <?php if (!isset($guides[$slug])): continue; endif; ?>
                <a class="research-link" href="<?= e(site_url('guide', ['guide' => $slug])) ?>">
                    <span class="tile-icon"><?= icon($iconName, 17) ?></span>
                    <span><strong><?= e($guides[$slug]['title']) ?></strong><small><?= e($guides[$slug]['category']) ?></small></span>
                    <?= icon('chevron', 15) ?>
                </a>
            <?php endforeach; ?>
            <a class="text-link research-all" href="<?= e(site_url('guides')) ?>">Browse all guides <?= icon('arrow', 17) ?></a>
        </section>
    </div>
    <?php
}

function render_sparkline(array $points, string $direction): string
{
    if (count($points) < 2) {
        return '';
    }

    $prices = array_column($points, 'close');
    $min = min($prices);
    $span = max($prices) - $min;
    $last = count($prices) - 1;
    $path = [];
    foreach ($prices as $i => $price) {
        $y = $span === 0.0 ? 15 : 26 - ($price - $min) / $span * 22;
        $path[] = round(74 * $i / $last, 2) . ',' . round($y, 2);
    }

    return '<svg class="sparkline ' . e($direction) . '" width="76" height="30" viewBox="-1 0 76 30" aria-hidden="true">'
        . '<polyline fill="none" stroke="currentColor" stroke-width="1.5" points="' . implode(' ', $path) . '"/></svg>';
}

function render_chart_panel(array $series, array $watchlist): void
{
    $ticker = $series['symbol'];
    $isSaved = in_array($ticker, $watchlist, true);
    $unit = match ($series['type']) {
        'index' => 'points',
        'fx' => 'CAD per USD',
        default => $series['currency'],
    };
    ?>
    <section class="panel chart-panel" id="chart" aria-labelledby="chart-title">
        <div class="chart-header">
            <div class="chart-ident">
                <span class="tile-icon chart-icon"><?= icon($series['type'] === 'fx' ? 'globe' : 'market', 21) ?></span>
                <div><h2 id="chart-title"><?= e($series['name']) ?></h2><p><?= e(display_ticker($series)) ?> <span>·</span> <?= e($series['exchange']) ?></p></div>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= $isSaved ? 'watch-remove' : 'watch-add' ?>">
                <input type="hidden" name="symbol" value="<?= e($ticker) ?>">
                <input type="hidden" name="return" value="markets">
                <input type="hidden" name="range" value="<?= e($series['range']) ?>">
                <button class="button watch-button <?= $isSaved ? 'saved' : '' ?>" type="submit"><?= icon('star', 17) ?><span><?= $isSaved ? 'Watching' : 'Watch' ?></span></button>
            </form>
        </div>
        <div class="chart-quote">
            <div>
                <span class="chart-price"><?= e(quote_price($series)) ?></span>
                <span class="chart-unit"><?= e($unit) ?></span>
                <?php if ($series['change_percent'] !== null): ?>
                    <span class="delta change-chip <?= e(change_class($series['change_percent'])) ?>"><?= e(quote_change($series['change_percent'])) ?></span>
                <?php endif; ?>
            </div>
            <nav class="segments period" aria-label="Chart range">
                <?php foreach (market_ranges() as $range): ?>
                    <a class="<?= $series['range'] === $range ? 'selected' : '' ?>" <?= $series['range'] === $range ? 'aria-current="true"' : '' ?>
                        href="<?= e(site_url('markets', ['symbol' => $ticker, 'range' => $range], 'chart')) ?>"><?= e($range) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php if ($series['points'] !== []): ?>
        <div class="price-chart" data-price-chart
            data-points="<?= e(json_encode($series['points'], JSON_THROW_ON_ERROR)) ?>"
            data-label="<?= e($series['name']) ?>"
            data-type="<?= e($series['type']) ?>"
            data-currency="<?= e($series['currency']) ?>">
            <noscript>Enable JavaScript to view the price history chart.</noscript>
        </div>
        <?php else: ?>
        <div class="chart-unavailable"><?= icon('info', 23) ?><p>Price history is unavailable for this symbol.</p></div>
        <?php endif; ?>
        <div class="chart-foot">
            <span><?= icon('clock', 14) ?> <?= $series['as_of'] === null ? 'Close date unavailable' : 'Close · ' . e(display_date($series['as_of'])) ?></span>
            <span><?= $series['mode'] === 'demo' ? 'Simulated prices' : e($series['source'] ?? 'Feed unavailable') ?></span>
        </div>
    </section>
    <?php
}

function render_movers(array $snapshot, array $series, string $kind): void
{
    $day = null;
    foreach ($snapshot as $quote) {
        if (in_array($quote['type'], ['stock', 'etf'], true) && $quote['as_of'] !== null) {
            $day = $day === null ? $quote['as_of'] : max($day, $quote['as_of']);
        }
    }
    $decliners = input($_GET, 'movers') === 'down';
    $movers = array_filter($snapshot, static fn (array $quote): bool =>
        $day !== null && $quote['as_of'] === $day && in_array($quote['type'], ['stock', 'etf'], true)
        && ($decliners ? $quote['change_percent'] < 0 : $quote['change_percent'] > 0)
    );
    uasort($movers, static fn (array $first, array $second): int =>
        ($decliners ? 1 : -1) * ($first['change_percent'] <=> $second['change_percent'])
    );
    ?>
    <section class="panel movers-panel" id="movers" aria-labelledby="movers-title">
        <div class="panel-heading">
            <div><h2 id="movers-title">Market movers</h2><p>Change from previous close</p></div>
            <span class="tile-icon movers-icon"><?= icon('up', 19) ?></span>
        </div>
        <nav class="segments movers-tabs" aria-label="Market mover direction">
            <?php foreach (['up' => 'Gainers', 'down' => 'Decliners'] as $key => $label): ?>
                <?php $selected = $decliners === ($key === 'down'); ?>
                <a class="<?= $selected ? 'selected' : '' ?>" <?= $selected ? 'aria-current="true"' : '' ?>
                    href="<?= e(site_url('markets', ['symbol' => $series['symbol'], 'range' => $series['range'], 'movers' => $key, 'kind' => $kind], 'movers')) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="mover-list">
            <?php foreach (array_slice($movers, 0, 5) as $quote): ?>
            <a class="mover" href="<?= e(site_url('markets', ['symbol' => $quote['symbol'], 'range' => $series['range']], 'chart')) ?>">
                <?= ticker_tile($quote) ?>
                <span class="mover-name"><strong><?= e($quote['symbol']) ?></strong><small title="<?= e($quote['name']) ?>"><?= e($quote['name']) ?></small></span>
                <span class="delta <?= e(change_class($quote['change_percent'])) ?>"><?= e(quote_change($quote['change_percent'])) ?></span>
            </a>
            <?php endforeach; ?>
            <?php if ($movers === []): ?><p class="muted mover-empty">No <?= $decliners ? 'decliners' : 'gainers' ?> for the latest available close.</p><?php endif; ?>
        </div>
        <div class="mover-foot"><?= market_mode() === 'demo' ? 'Simulated session' : e(display_date($day)) ?><a href="#listings">View listings <?= icon('arrow', 15) ?></a></div>
    </section>
    <?php
}

function render_quote_table(array $quotes, bool $watchlist): void
{
    ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Symbol</th><th scope="col">Sector</th><th scope="col" class="number">Close</th><th scope="col" class="number">Change</th><?php if ($watchlist): ?><th scope="col"><span class="sr-only">Remove</span></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($quotes as $quote): ?>
                <tr>
                    <th scope="row">
                        <?php if ($quote['type'] === 'custom'): ?>
                        <span class="table-symbol"><?= ticker_tile($quote) ?><span><strong><?= e($quote['symbol']) ?></strong><small>Outside the chart catalogue</small></span></span>
                        <?php else: ?>
                        <a class="table-symbol" href="<?= e(site_url('markets', ['symbol' => $quote['symbol']], 'chart')) ?>">
                            <?= ticker_tile($quote) ?><span><strong><?= e(display_ticker($quote)) ?></strong><small><?= e($quote['name']) ?></small></span>
                        </a>
                        <?php endif; ?>
                    </th>
                    <td><?= e($quote['sector']) ?></td>
                    <td class="number close-number"><?= e(quote_price($quote)) ?></td>
                    <td class="number delta <?= e(change_class($quote['change_percent'])) ?>"><?= e(quote_change($quote['change_percent'])) ?></td>
                    <?php if ($watchlist): ?>
                    <td class="number">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="watch-remove">
                            <input type="hidden" name="symbol" value="<?= e($quote['symbol']) ?>">
                            <button class="button icon-button" type="submit" aria-label="Remove <?= e(display_ticker($quote)) ?>"><?= icon('minus', 16) ?></button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function display_ticker(array $quote): string
{
    return $quote['type'] === 'fx' ? 'USD/CAD' : $quote['symbol'];
}

function ticker_tile(array $quote): string
{
    $ticker = display_ticker($quote);
    $letter = ltrim($ticker, '^')[0];
    $colour = ord($letter) % 5;
    return '<span class="ticker-tile ticker-colour-' . $colour . '" aria-hidden="true">' . e($letter) . '</span>';
}
