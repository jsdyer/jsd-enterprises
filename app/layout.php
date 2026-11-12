<?php

declare(strict_types=1);

function render_layout_start(string $page, string $title, ?array $user, bool $boardEnabled, ?array $notice): void
{
    $nav = [
        'markets' => ['Market overview', 'market'],
        'watchlist' => ['Watchlist', 'star'],
        'guides' => ['Guides', 'book'],
        'tools' => ['Calculators', 'tools'],
    ];
    if ($boardEnabled) {
        $nav['community'] = ['Community', 'community'];
    }
    $active = $page === 'guide' ? 'guides' : $page;
    $search = array_values(array_map(
        static fn (array $listing): array => array_intersect_key($listing, array_flip(['symbol', 'name', 'exchange'])),
        market_symbols()
    ));
    ?>
<!doctype html>
<html lang="en-CA">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Explore Canadian equities and ETFs, build a private watchlist, and find practical Canadian investing guides.">
    <meta name="theme-color" content="#111321">
    <title><?= e($title) ?> · JSD Enterprises</title>
    <link rel="stylesheet" href="assets/site.css">
    <script src="assets/market.js" defer></script>
</head>
<body>
<a href="#main" class="skip-link">Skip to content</a>
<div class="shell">
    <aside class="sidebar">
        <a class="brand" href="<?= e(site_url('markets')) ?>">
            <span class="brand-icon"><?= icon('logo', 24) ?></span>
            <span><strong>JSD Enterprises</strong><small>Canadian investing</small></span>
        </a>
        <nav class="nav" aria-label="Main navigation">
            <?php foreach ($nav as $key => [$label, $icon]): ?>
            <a href="<?= e(site_url($key)) ?>" aria-label="<?= e($label) ?>" title="<?= e($label) ?>" <?= $active === $key ? 'aria-current="page"' : '' ?>>
                <?= icon($icon, 19) ?><span><?= e($label) ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-bottom">
            <a href="<?= e(site_url('api-guide')) ?>" <?= $page === 'api-guide' ? 'aria-current="page"' : '' ?>><?= icon('code', 18) ?> Market data API</a>
            <div class="sidebar-region"><span class="canada-flag" aria-hidden="true">🇨🇦</span><span>Canadian markets</span></div>
        </div>
    </aside>
    <div class="workspace">
        <header class="topbar">
            <div class="top-path"><?= icon('market', 17) ?><span>Investing</span><?= icon('chevron', 13) ?><strong><?= e($title) ?></strong></div>
            <div class="top-actions">
                <form class="search-form" method="get" action="#chart" data-symbol-search data-symbols="<?= e(json_encode($search, JSON_THROW_ON_ERROR)) ?>">
                    <input type="hidden" name="page" value="markets">
                    <button type="submit" aria-label="Search symbols"><?= icon('search', 18) ?></button>
                    <label class="sr-only" for="symbol-search">Search ticker or company</label>
                    <input id="symbol-search" type="search" name="symbol" placeholder="Search ticker or company" maxlength="80" autocomplete="off" required>
                    <div class="symbol-results" id="symbol-results" data-symbol-results hidden></div>
                </form>
                <a class="account-link" href="<?= e(site_url('account')) ?>"><?= icon('user', 17) ?><span><?= e($user['username'] ?? 'Sign in') ?></span></a>
            </div>
        </header>
        <main id="main" class="main-content">
            <?php if ($notice !== null): ?>
            <div class="notice <?= e($notice['kind']) ?>" role="<?= $notice['kind'] === 'error' ? 'alert' : 'status' ?>"><?= icon('info', 18) ?><?= e($notice['text']) ?></div>
            <?php endif; ?>
    <?php
}

function render_layout_end(): void
{
    ?>
        </main>
        <footer class="footer">
            <span>JSD Enterprises · Canada</span>
            <span>For research and education</span>
            <a href="<?= e(site_url('api-guide')) ?>">Market data API</a>
        </footer>
    </div>
</div>
</body>
</html>
    <?php
}

function page_intro(string $title, string $description, string $actions = ''): void
{
    ?>
    <div class="page-intro">
        <div><h1><?= e($title) ?></h1><p><?= e($description) ?></p></div>
        <?php if ($actions !== ''): ?><div class="intro-actions"><?= $actions ?></div><?php endif; ?>
    </div>
    <?php
}

function empty_panel(string $title, string $message, string $iconName = 'info'): void
{
    ?>
    <div class="panel empty-panel">
        <span class="tile-icon"><?= icon($iconName, 21) ?></span>
        <h2><?= e($title) ?></h2><p><?= e($message) ?></p>
    </div>
    <?php
}
