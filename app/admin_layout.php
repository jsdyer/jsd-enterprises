<?php

declare(strict_types=1);

function admin_url(string $tab = 'overview', array $parameters = []): string
{
    return site_url('admin', ['tab' => $tab] + $parameters);
}

function serve_admin(array $config, ?PDO $db, ?array $user, bool $isAdmin, bool $boardEnabled, string $method): never
{
    header('Cache-Control: private, no-store');
    header('X-Robots-Tag: noindex');

    if (!$isAdmin || $db === null || $user === null) {
        http_response_code($config['admin_enabled'] ? 403 : 404);
        render_layout_start('admin', 'Page unavailable', $user, $boardEnabled, null);
        render_missing();
        render_layout_end();
        exit;
    }

    $tabs = [
        'overview' => ['Overview', 'tools'],
        'market' => ['Market feed', 'market'],
        'guides' => ['Guide desk', 'book'],
        'community' => ['Moderation', 'community'],
        'audit' => ['Audit log', 'clock'],
    ];
    $tab = input($_GET, 'tab', 'overview');
    if (!isset($tabs[$tab])) {
        http_response_code(404);
        render_layout_start('admin', 'Page unavailable', $user, $boardEnabled, null, true);
        render_missing();
        render_layout_end();
        exit;
    }

    if ($tab === 'market' && input($_GET, 'download') === 'health' && $method !== 'POST') {
        serve_admin_market_csv($config);
    }

    $guides = $tab === 'guides' ? require dirname(__DIR__) . '/content/guides.php' : [];
    if ($method === 'POST') {
        submit_admin_action($db, (int) $user['id'], $tab, $guides);
    }

    render_layout_start('admin', $tabs[$tab][0] . ' · Admin', $user, $boardEnabled, take_flash(), true);
    ?>
    <div class="admin-console">
        <?php page_intro('Operations console', 'Manage market data, published guides and community activity.',
            '<a class="button admin-view-site" href="' . e(site_url('markets')) . '">' . icon('external', 16) . ' View public site</a>'); ?>
        <nav class="admin-tabs" aria-label="Administration">
            <?php foreach ($tabs as $key => [$label, $iconName]): ?>
            <a href="<?= e(admin_url($key)) ?>" <?= $key === $tab ? 'aria-current="page"' : '' ?>><?= icon($iconName, 18) ?><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <?php
        try {
            match ($tab) {
                'market' => render_admin_market($db, $config),
                'guides' => render_admin_guides($db, $guides),
                'community' => render_admin_community($db, $boardEnabled),
                'audit' => render_admin_audit($db),
                default => render_admin_overview($db, $config, $boardEnabled),
            };
        } catch (PDOException $exception) {
            error_log('JSD admin panel query failed: ' . $exception->getCode());
            empty_panel('Admin data unavailable', 'Check that the admin database migrations were applied and the database is reachable.');
        }
        ?>
    </div>
    <?php
    render_layout_end();
    exit;
}

function submit_admin_action(PDO $db, int $actorId, string $tab, array $guides): never
{
    $parameters = [];
    $slug = input($_GET, 'guide');
    if ($tab === 'guides' && preg_match('/\A[a-z0-9][a-z0-9-]{0,79}\z/', $slug) === 1) {
        $parameters['guide'] = $slug;
    }

    if ($tab === 'community') {
        if (input($_GET, 'view') === 'hidden') {
            $parameters['view'] = 'hidden';
        }
        $postId = filter_var(input($_GET, 'post'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($postId !== false) {
            $parameters['post'] = $postId;
        }
    }

    try {
        accounts_validate_csrf($_POST['csrf'] ?? null);
    } catch (DomainException $exception) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo $exception->getMessage();
        exit;
    }

    $action = input($_POST, 'action');
    try {
        $message = match ($tab) {
            'market' => handle_admin_market_action($db, $actorId, $action, $_POST),
            'guides' => handle_admin_guide_action($db, $actorId, $action, $_POST, $guides),
            'community' => handle_admin_community_action($db, $actorId, $action, $_POST),
            default => throw new DomainException('This administration page has no write actions.'),
        };
        if ($tab === 'guides' && $slug === 'new' && $action === 'guide-save') {
            $parameters['guide'] = strtolower(trim(input($_POST, 'guide_slug')));
        }
        flash($message);
    } catch (DomainException $exception) {
        flash($exception->getMessage(), 'error');
    } catch (PDOException $exception) {
        error_log('JSD admin action failed: ' . $exception->getCode());
        flash('The database could not save that change. Check the admin migrations and try again.', 'error');
    }

    safe_redirect(admin_url($tab, $parameters));
}

function render_admin_overview(PDO $db, array $config, bool $boardEnabled): void
{
    $feed = admin_market_diagnostics($config);
    $counts = $feed['counts'];
    $coverage = count($feed['rows']);
    $demo = $feed['mode'] === 'demo';
    $notice = admin_market_notice($db);
    $totals = $db->query('SELECT
        (SELECT COUNT(*) FROM users) AS members,
        (SELECT COUNT(DISTINCT user_id) FROM watchlist) AS member_watchlists,
        (SELECT COUNT(*) FROM community_topics) AS threads')->fetch();
    $needsAttention = $counts['stale'] + $counts['missing'] + $counts['invalid'] + $counts['future'];
    ?>
    <div class="admin-stats">
        <a href="<?= e(admin_url('market')) ?>" class="panel admin-stat">
            <span class="tile-icon"><?= icon('market', 19) ?></span>
            <div><span>Feed coverage</span><strong><?= e($demo ? $counts['demo'] : $counts['ready']) ?> <small>/ <?= e($coverage) ?></small></strong>
                <p><?= $demo ? 'Simulated series' : e($needsAttention) . ' need attention' ?></p></div>
            <?= icon('chevron', 16) ?>
        </a>
        <div class="panel admin-stat">
            <span class="tile-icon"><?= icon('user', 19) ?></span>
            <div><span>Member accounts</span><strong><?= e(number_format((int) $totals['members'])) ?></strong>
                <p><?= e(number_format((int) $totals['member_watchlists'])) ?> with saved watchlists</p></div>
        </div>
        <a href="<?= e(admin_url('community')) ?>" class="panel admin-stat">
            <span class="tile-icon"><?= icon('community', 19) ?></span>
            <div><span>Discussion threads</span><strong><?= e(number_format((int) $totals['threads'])) ?></strong>
                <p><?= $boardEnabled ? 'Board open' : 'Board closed' ?> · all visibility states</p></div>
            <?= icon('chevron', 16) ?>
        </a>
    </div>

    <div class="admin-overview">
        <section class="panel content-panel">
            <div class="panel-heading"><div><h2>Site status</h2><p>What visitors can use right now</p></div></div>
            <dl class="admin-site-status">
                <div><dt>Prices</dt><dd><span class="admin-status <?= $demo ? 'demo' : 'ready' ?>"><?= $demo ? 'Demo' : 'Licensed feed' ?></span></dd></div>
                <div><dt>Market data JSON API</dt><dd><?= $demo || $config['publish_licensed_api'] ? 'Available' : 'Disabled for this feed' ?></dd></div>
                <div><dt>Member registration</dt><dd><?= $config['registration_enabled'] ? 'Open' : 'Closed' ?></dd></div>
                <div><dt>Community</dt><dd><?= $boardEnabled ? 'Open' : 'Closed' ?></dd></div>
            </dl>
            <div class="admin-notice-preview">
                <h3>Market notice</h3>
                <p><?= $notice === null ? 'No active public notice.' : e($notice['message']) ?></p>
                <a class="text-link" href="<?= e(admin_url('market') . '#market-notice') ?>">Manage notice <?= icon('arrow', 15) ?></a>
            </div>
        </section>
        <?php render_admin_audit($db, true); ?>
    </div>
    <?php
}

function render_admin_audit(PDO $db, bool $compact = false): void
{
    $events = site_admin_events($db, $compact ? 5 : 50);
    ?>
    <section class="panel content-panel admin-audit-panel">
        <div class="panel-heading">
            <div><h2><?= $compact ? 'Recent changes' : 'Operator audit log' ?></h2><p><?= $compact ? 'Latest actions across the console' : 'Up to 50 recorded changes, newest first. Times are UTC.' ?></p></div>
            <?php if ($compact): ?><a class="text-link" href="<?= e(admin_url('audit')) ?>">Full log <?= icon('arrow', 15) ?></a><?php endif; ?>
        </div>
        <?php if ($events === []): ?>
            <p class="compact-empty">Operator actions will appear here after the first change.</p>
        <?php else: ?>
            <ol class="admin-events">
                <?php foreach ($events as $event): ?>
                <li>
                    <span class="tile-icon"><?= icon('clock', 15) ?></span>
                    <div>
                        <strong><?= e(ucfirst(str_replace(['.', '-', '_'], ' ', $event['action']))) ?></strong>
                        <span class="admin-event-subject"><?= e($event['subject']) ?></span>
                        <?php if (!$compact && $event['detail'] !== ''): ?><p><?= e($event['detail']) ?></p><?php endif; ?>
                        <small><?= e($event['actor_username'] ?? 'Server CLI') ?> · <?= e($event['created_at']) ?> UTC</small>
                    </div>
                </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
    <?php
}
