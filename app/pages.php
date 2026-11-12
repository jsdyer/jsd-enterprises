<?php

declare(strict_types=1);

function render_watchlist(array $symbols, ?array $user, bool $accountsAvailable): void
{
    $action = $user === null && $accountsAvailable
        ? '<a class="button primary" href="' . e(site_url('account')) . '">' . icon('user', 17) . ' Sign in</a>'
        : '';
    $description = $user === null
        ? 'Your guest watchlist lasts for this browser session.'
        : 'Your saved symbols are private to your account.';
    page_intro('Watchlist', $description, $action);
    ?>
    <section class="panel content-panel watchlist-panel">
        <div class="panel-heading">
            <div><h2>Saved symbols</h2><p>Add a ticker such as RY or WSP. Prices are available for symbols in the chart catalogue.</p></div>
            <span class="tile-icon"><?= icon('star', 20) ?></span>
        </div>
        <form class="inline-form" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="watch-add">
            <label class="sr-only" for="watch-symbol">Ticker symbol</label>
            <input id="watch-symbol" type="text" name="symbol" placeholder="Ticker symbol" maxlength="24" autocomplete="off" spellcheck="false" required>
            <button type="submit" class="button primary"><?= icon('plus', 17) ?> Add symbol</button>
        </form>
        <?php if ($symbols === []): ?>
            <div class="compact-empty">Your watchlist is empty. Add a ticker above or choose Watch on a chart.</div>
        <?php else: ?>
            <?php
            $snapshot = market_snapshot();
            $quotes = array_map(static fn (string $symbol): array => $snapshot[$symbol] ?? [
                'symbol' => $symbol, 'type' => 'custom', 'sector' => '—', 'price' => null, 'change_percent' => null,
            ], $symbols);
            render_quote_table($quotes, true);
            ?>
            <p class="table-note"><?= market_mode() === 'demo' ? 'Prices and changes are simulated.' : 'Prices reflect the latest close available for each symbol.' ?></p>
        <?php endif; ?>
    </section>
    <?php
}

function render_guides(array $guides): void
{
    page_intro('Investing guides', 'Canadian brokerage walkthroughs, investing basics and practical ways to check your costs.');
    ?>
    <div class="guide-grid">
        <?php foreach ($guides as $slug => $guide): ?>
        <a class="panel guide-card" href="<?= e(site_url('guide', ['guide' => $slug])) ?>">
            <span class="tile-icon"><?= icon($guide['category'] === 'Brokerage APIs' ? 'code' : 'book', 21) ?></span>
            <span class="guide-category"><?= e($guide['category']) ?></span>
            <h2><?= e($guide['title']) ?></h2>
            <p><?= e($guide['description']) ?></p>
            <span class="guide-bottom"><span><?= icon('clock', 15) ?> <?= e($guide['minutes']) ?> min read</span><?= icon('arrow', 18) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php
}

function render_guide(array $guide): void
{
    ?>
    <a class="text-link back-link" href="<?= e(site_url('guides')) ?>"><span aria-hidden="true">←</span> All guides</a>
    <?php page_intro($guide['title'], $guide['description']); ?>
    <article class="panel article-panel">
        <div class="article-meta"><span class="guide-category"><?= e($guide['category']) ?></span><span><?= icon('clock', 15) ?> <?= e($guide['minutes']) ?> min read</span></div>
        <?php foreach ($guide['sections'] as $section): ?>
        <section>
            <h2><?= e($section['heading']) ?></h2>
            <?php foreach ($section['paragraphs'] ?? [] as $paragraph): ?><p><?= e($paragraph) ?></p><?php endforeach; ?>
            <?php if (!empty($section['steps'])): ?>
                <ol><?php foreach ($section['steps'] as $step): ?><li><?= e($step) ?></li><?php endforeach; ?></ol>
            <?php endif; ?>
            <?php if (isset($section['code'])): ?><pre><code><?= e($section['code']) ?></code></pre><?php endif; ?>
        </section>
        <?php endforeach; ?>
        <section class="article-sources">
            <h2>Sources</h2>
            <ul><?php foreach ($guide['sources'] as $source): ?>
                <li><a class="text-link" href="<?= e($source['url']) ?>"><?= e($source['label']) ?> <?= icon('external', 15) ?></a></li>
            <?php endforeach; ?></ul>
        </section>
    </article>
    <?php
}

function render_tools(): void
{
    $value = tool_amount('trade', 5000.0, 100000000.0);
    $commission = tool_amount('commission', 9.95, 1000000.0);
    $fx = tool_amount('fx', 1.5, 100.0);
    $fee = tool_amount('annual_fee', 120.0, 1000000.0);
    $monthly = tool_amount('monthly_value', 8.0, 1000000.0);
    $tradeCost = $commission + $value * $fx / 100;
    $annualValue = $monthly * 12 - $fee;
    page_intro('Calculators', 'Estimate trading costs and compare a subscription or card fee with the benefits you actually use.');
    ?>
    <div class="tool-grid">
        <section class="panel content-panel" id="trading-cost">
            <div class="panel-heading"><div><h2>Trading cost estimator</h2><p>Commission plus currency conversion for one trade.</p></div><span class="tile-icon"><?= icon('dollar', 21) ?></span></div>
            <form class="stack-form" method="get" action="#trading-cost">
                <input type="hidden" name="page" value="tools">
                <input type="hidden" name="annual_fee" value="<?= e($fee) ?>">
                <input type="hidden" name="monthly_value" value="<?= e($monthly) ?>">
                <?php render_amount('trade', 'Trade value (CAD)', $value, 100000000); ?>
                <?php render_amount('commission', 'Commission (CAD)', $commission, 1000000); ?>
                <?php render_amount('fx', 'FX markup (%)', $fx, 100); ?>
                <button type="submit" class="button primary">Calculate trade cost</button>
            </form>
            <div class="tool-result"><span>Estimated cost</span><strong>$<?= e(number_format($tradeCost, 2)) ?> CAD</strong><small>Enter 0% for a trade that requires no currency conversion. The estimate excludes spreads and other charges.</small></div>
        </section>
        <section class="panel content-panel" id="annual-value">
            <div class="panel-heading"><div><h2>Annual fee check</h2><p>Compare a recurring fee with your own estimate of its value.</p></div><span class="tile-icon"><?= icon('tools', 21) ?></span></div>
            <form class="stack-form" method="get" action="#annual-value">
                <input type="hidden" name="page" value="tools">
                <input type="hidden" name="trade" value="<?= e($value) ?>">
                <input type="hidden" name="commission" value="<?= e($commission) ?>">
                <input type="hidden" name="fx" value="<?= e($fx) ?>">
                <?php render_amount('annual_fee', 'Annual fee (CAD)', $fee, 1000000); ?>
                <?php render_amount('monthly_value', 'Monthly benefits you use (CAD)', $monthly, 1000000); ?>
                <button type="submit" class="button primary">Calculate annual value</button>
            </form>
            <div class="tool-result"><span>Net annual value</span><strong><?= $annualValue < 0 ? '−' : '' ?>$<?= e(number_format(abs($annualValue), 2)) ?> CAD</strong><small>Monthly benefits × 12, minus the annual fee.</small></div>
        </section>
    </div>
    <?php
}

function tool_amount(string $name, float $default, float $max): float
{
    $raw = input($_GET, $name);
    if (!is_numeric($raw)) {
        return $default;
    }
    $amount = (float) $raw;
    return is_finite($amount) && $amount >= 0 && $amount <= $max ? $amount : $default;
}

function render_amount(string $name, string $label, float $value, int $max): void
{
    ?>
    <label for="<?= e($name) ?>"><?= e($label) ?></label>
    <input id="<?= e($name) ?>" name="<?= e($name) ?>" type="number" inputmode="decimal" min="0" max="<?= e($max) ?>" step="0.01" value="<?= e(number_format($value, 2, '.', '')) ?>" required>
    <?php
}

function render_account(?array $user, bool $available, bool $registrationEnabled, bool $register): void
{
    if ($user !== null) {
        page_intro('Your account', 'Manage access to your private watchlist.');
        ?>
        <section class="panel content-panel form-panel">
            <div class="panel-heading"><div><h2><?= e($user['username']) ?></h2><p>Member since <?= e(display_date($user['created_at'])) ?></p></div><span class="tile-icon"><?= icon('user', 22) ?></span></div>
            <form method="post" class="form-actions"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><a class="button primary" href="<?= e(site_url('watchlist')) ?>">Open watchlist</a><button type="submit" class="button">Sign out</button></form>
        </section>
        <?php
        return;
    }

    page_intro($register ? 'Create an account' : 'Sign in', 'Save a private watchlist you can open on other devices.');
    if (!$available || ($register && !$registrationEnabled)) {
        empty_panel('Accounts are unavailable', 'You can still use a guest watchlist in this browser session.', 'user');
        echo '<a class="button primary standalone-action" href="' . e(site_url('watchlist')) . '">Open guest watchlist</a>';
        return;
    }
    ?>
    <section class="panel content-panel form-panel">
        <div class="panel-heading"><div><h2><?= $register ? 'Choose your credentials' : 'Welcome back' ?></h2></div><span class="tile-icon"><?= icon('user', 22) ?></span></div>
        <form class="stack-form" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $register ? 'register' : 'login' ?>">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" autocomplete="username" minlength="3" maxlength="24" pattern="[a-zA-Z0-9][a-zA-Z0-9_\-]{2,23}" spellcheck="false" required>
            <?php if ($register): ?><p class="field-help">3 to 24 letters, numbers, underscores or hyphens. Start with a letter or number.</p><?php endif; ?>
            <label for="password">Password</label>
            <input id="password" name="password" type="password" minlength="12" autocomplete="<?= $register ? 'new-password' : 'current-password' ?>" required>
            <?php if ($register): ?><p class="field-help">At least 12 characters and up to 72 UTF-8 bytes. Save it in your password manager.</p><?php endif; ?>
            <button class="button primary" type="submit"><?= $register ? 'Create account' : 'Sign in' ?></button>
        </form>
        <?php if ($register || $registrationEnabled): ?><div class="form-switch"><a class="text-link" href="<?= e(site_url($register ? 'account' : 'register')) ?>"><?= $register ? 'Already have an account? Sign in' : 'Create an account' ?> <?= icon('arrow', 16) ?></a></div><?php endif; ?>
    </section>
    <?php
}

function render_community(?PDO $db, ?array $user, int $topicId): void
{
    if ($db === null) {
        page_intro('Community', 'Discuss Canadian markets, brokerage workflows and personal finance.');
        empty_panel('The board is closed', 'Discussions will appear here when the site operator opens the board.', 'community');
        return;
    }

    if ($topicId !== 0) {
        $topic = community_topic($db, $topicId);
        if ($topic === null) {
            render_missing();
            return;
        }
        ?>
        <a class="text-link back-link" href="<?= e(site_url('community')) ?>"><span aria-hidden="true">←</span> All discussions</a>
        <?php page_intro($topic['title'], 'Discussion started by ' . $topic['username']); ?>
        <section class="panel article-panel discussion-panel">
            <p class="post-body"><?= e($topic['body']) ?></p>
            <?php if (($user['id'] ?? null) === (int) $topic['user_id']): render_delete_form($topicId); endif; ?>
            <h2>Replies</h2>
            <?php $replies = community_replies($db, $topicId); ?>
            <?php foreach ($replies as $reply): ?>
                <article class="reply"><div><strong><?= e($reply['username']) ?></strong> <time datetime="<?= e(str_replace(' ', 'T', $reply['created_at'])) ?>Z"><?= e(display_date($reply['created_at'])) ?></time></div>
                <p class="post-body"><?= e($reply['body']) ?></p>
                <?php if (($user['id'] ?? null) === (int) $reply['user_id']): render_delete_form($topicId, (int) $reply['id']); endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if ($replies === []): ?><p class="muted">No replies yet.</p><?php endif; ?>
            <?php render_post_form($user, $topicId); ?>
        </section>
        <?php
        return;
    }

    page_intro('Community', 'Discuss Canadian markets, brokerage workflows and personal finance.');
    ?>
    <div class="tool-grid community-grid">
        <section class="panel content-panel">
            <div class="panel-heading"><div><h2>Latest discussions</h2></div><span class="tile-icon"><?= icon('community', 21) ?></span></div>
            <?php $topics = community_topics($db); ?>
            <?php foreach ($topics as $topic): ?>
                <a class="topic-row" href="<?= e(site_url('community', ['topic' => $topic['id']])) ?>">
                    <span><strong><?= e($topic['title']) ?></strong><small><?= e($topic['username']) ?> · <?= e(display_date($topic['created_at'])) ?></small></span>
                    <span><?= e($topic['reply_count']) ?> <?= (int) $topic['reply_count'] === 1 ? 'reply' : 'replies' ?></span>
                </a>
            <?php endforeach; ?>
            <?php if ($topics === []): ?><p class="compact-empty">No discussions yet.</p><?php endif; ?>
        </section>
        <section class="panel content-panel">
            <div class="panel-heading"><div><h2>Start a discussion</h2><p>Posts and usernames are public.</p></div></div>
            <?php render_post_form($user, 0); ?>
        </section>
    </div>
    <?php
}

function render_post_form(?array $user, int $topicId): void
{
    if ($user === null) {
        echo '<a class="button primary standalone-action" href="' . e(site_url('account')) . '">Sign in to post</a>';
        return;
    }
    ?>
    <form class="stack-form" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $topicId === 0 ? 'topic-post' : 'reply-post' ?>">
        <?php if ($topicId === 0): ?>
            <label for="topic-title">Title</label><input id="topic-title" name="title" minlength="5" maxlength="120" required>
        <?php else: ?>
            <input type="hidden" name="topic_id" value="<?= e($topicId) ?>">
        <?php endif; ?>
        <label for="topic-body"><?= $topicId === 0 ? 'Message' : 'Your reply' ?></label>
        <textarea id="topic-body" name="body" rows="5" maxlength="<?= $topicId === 0 ? 4000 : 2000 ?>" required></textarea>
        <button class="button primary" type="submit"><?= $topicId === 0 ? 'Post discussion' : 'Post reply' ?></button>
    </form>
    <?php
}

function render_delete_form(int $topicId, int $replyId = 0): void
{
    ?>
    <form method="post" class="delete-form">
        <?= csrf_field() ?>
        <input type="hidden" name="topic_id" value="<?= e($topicId) ?>">
        <input type="hidden" name="action" value="<?= $replyId === 0 ? 'topic-delete' : 'reply-delete' ?>">
        <?php if ($replyId !== 0): ?><input type="hidden" name="reply_id" value="<?= e($replyId) ?>"><?php endif; ?>
        <button type="submit" class="button danger"><?= $replyId === 0 ? 'Delete thread and replies' : 'Delete reply' ?></button>
    </form>
    <?php
}

function render_missing(): void
{
    page_intro('Page unavailable', 'This address does not match an available page.');
    echo '<a class="button primary" href="' . e(site_url('markets')) . '">Back to markets</a>';
}


function render_api_guide(array $config): void
{
    $enabled = market_mode() === 'demo' || $config['publish_licensed_api'];
    page_intro('Market data API', 'Fetch daily closes for the Canadian stocks, ETFs and benchmarks shown in the dashboard.');
    ?>
    <article class="panel article-panel">
        <section>
            <h2>Endpoints</h2>
            <?php if (!$enabled): ?>
            <p>JSON access is disabled for the market feed configured on this site.</p>
            <?php else: ?>
            <p><?= market_mode() === 'demo' ? 'Responses contain simulated prices for development and testing.' : 'Responses contain end-of-day prices from the configured feed.' ?></p>
            <?php endif; ?>
            <ul>
                <li><code>GET /?page=api</code> returns the most recent close and change for each catalogued symbol. <?php if ($enabled): ?><a class="text-link" href="<?= e(site_url('api')) ?>">View snapshot JSON <?= icon('arrow', 15) ?></a><?php endif; ?></li>
                <li><code>GET /?page=api&amp;symbol=RY&amp;range=3M</code> returns daily closes for one symbol. Ranges are <code>1M</code>, <code>3M</code>, <code>6M</code> and <code>1Y</code>. <?php if ($enabled): ?><a class="text-link" href="<?= e(site_url('api', ['symbol' => 'RY', 'range' => '3M'])) ?>">View RY history JSON <?= icon('arrow', 15) ?></a><?php endif; ?></li>
            </ul>
        </section>
        <section>
            <h2>Read the response</h2>
            <p><code>mode</code> distinguishes demo prices, licensed data and unavailable quotes. Use <code>as_of</code> for the date of the latest close and <code>source</code> for attribution. The history response includes <code>points</code> in date order with a <code>date</code> and <code>close</code> for each observation.</p>
        </section>
        <section class="article-sources">
            <h2>Other Canadian data and brokerage APIs</h2>
            <ul>
                <li><a class="text-link" href="<?= e(site_url('guide', ['guide' => 'bank-of-canada-valet-api'])) ?>">Get exchange rates from the Bank of Canada <?= icon('arrow', 15) ?></a></li>
                <li><a class="text-link" href="<?= e(site_url('guide', ['guide' => 'ibkr-tws-api'])) ?>">Make a read-only IBKR API call <?= icon('arrow', 15) ?></a></li>
            </ul>
        </section>
    </article>
    <?php
}
