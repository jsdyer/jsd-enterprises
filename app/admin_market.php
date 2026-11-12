<?php

declare(strict_types=1);

function admin_market_diagnostics(array $config): array
{
    $mode = market_mode();
    $staleDays = max(1, (int) ($config['market_stale_days'] ?? 5));
    $timezone = new DateTimeZone('America/Toronto');
    $today = new DateTimeImmutable('today', $timezone);
    $counts = array_fill_keys(['ready', 'stale', 'missing', 'invalid', 'future', 'demo'], 0);
    $rows = [];

    foreach (market_symbols() as $symbol => $instrument) {
        $feed = $mode === 'licensed'
            ? market_licensed_feed($symbol, $instrument['currency'])
            : ['status' => 'demo', 'issue' => '', 'history' => market_history($symbol, $instrument)];
        $history = $feed['history'];
        $points = $history['points'] ?? [];
        $firstDate = $points[0]['date'] ?? null;
        $lastDate = $points[array_key_last($points)]['date'] ?? null;
        $age = null;
        $status = $feed['status'];
        $issue = $feed['issue'];

        if ($status === 'ready' && $lastDate !== null) {
            $lastClose = new DateTimeImmutable($lastDate, $timezone);
            $age = (int) $lastClose->diff($today)->format('%r%a');
            if ($age < 0) {
                $status = 'future';
                $issue = 'The last close date is ahead of today in Toronto.';
            } elseif ($age > $staleDays) {
                $status = 'stale';
                $issue = 'The last close is older than the configured threshold.';
            }
        }

        $counts[$status]++;
        $rows[] = $instrument + [
            'status' => $status,
            'source' => $history['source'] ?? null,
            'first_close' => $firstDate,
            'last_close' => $lastDate,
            'age_days' => $age,
            'point_count' => count($points),
            'expected_file' => $mode === 'licensed' ? rawurlencode($symbol) . '.json' : null,
            'issue' => $issue,
        ];
    }

    return ['mode' => $mode, 'stale_days' => $staleDays, 'counts' => $counts, 'rows' => $rows, 'checked_on' => $today->format('Y-m-d')];
}

function admin_market_notice(PDO $db): ?array
{
    try {
        $row = admin_market_notice_record($db);
    } catch (PDOException $exception) {
        if ($exception->getCode() !== '42S02') {
            error_log('JSD market notice unavailable: ' . $exception->getCode());
        }
        return null;
    }
    return $row !== null && (bool) $row['active'] ? $row : null;
}

function admin_market_notice_record(PDO $db): ?array
{
    return $db->query(
        'SELECT message, severity, expires_at, updated_at, (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) AS active
         FROM market_notice WHERE id = 1'
    )->fetch() ?: null;
}

function handle_admin_market_action(PDO $db, int $actorId, string $action, array $post): string
{
    if ($action === 'market-notice-clear') {
        $db->beginTransaction();
        try {
            if ($db->exec('DELETE FROM market_notice WHERE id = 1') > 0) {
                site_admin_audit($db, $actorId, 'market-notice-clear', 'Market notice');
            }
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
        return 'Market notice cleared.';
    }
    if ($action !== 'market-notice-save') {
        throw new DomainException('Choose an available market action.');
    }

    $message = trim((string) preg_replace('/\s+/u', ' ', input($post, 'message')));
    $severity = input($post, 'severity');
    $expiresAt = admin_market_notice_expiry(input($post, 'expires_at'));
    if (preg_match('/\A[^\x00-\x1f\x7f]{1,280}\z/u', $message) !== 1) {
        throw new DomainException('Enter a notice between 1 and 280 characters.');
    }
    if (!in_array($severity, ['info', 'warning'], true)) {
        throw new DomainException('Choose Information or Warning.');
    }

    $db->beginTransaction();
    try {
        $statement = $db->prepare(
            'INSERT INTO market_notice (id, message, severity, expires_at) VALUES (1, ?, ?, ?)
             ON DUPLICATE KEY UPDATE message = VALUES(message), severity = VALUES(severity),
                 expires_at = VALUES(expires_at), updated_at = UTC_TIMESTAMP()'
        );
        $statement->execute([$message, $severity, $expiresAt]);
        $detail = ucfirst($severity) . ($expiresAt === null ? ' · No expiry' : ' · Expires ' . $expiresAt . ' UTC');
        site_admin_audit($db, $actorId, 'market-notice-save', 'Market notice', $detail);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
    return 'Market notice published.';
}

function admin_market_notice_expiry(string $value): ?string
{
    if ($value === '') {
        return null;
    }

    $timezone = new DateTimeZone('UTC');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $timezone);
    if ($date === false || $date->format('Y-m-d\TH:i') !== $value || $date <= new DateTimeImmutable('now', $timezone)) {
        throw new DomainException('Use a future expiry date and time in UTC, or leave it blank.');
    }
    return $date->format('Y-m-d H:i:s');
}

function serve_admin_market_csv(array $config): never
{
    $report = admin_market_diagnostics($config);
    header('Cache-Control: private, no-store');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="market-feed-health-' . $report['checked_on'] . '.csv"');
    $columns = ['symbol', 'exchange', 'currency', 'status', 'source', 'first_close', 'last_close', 'age_days', 'point_count', 'expected_file', 'issue'];
    $output = fopen('php://output', 'wb');
    fputcsv($output, $columns, ',', '"', '');
    foreach ($report['rows'] as $row) {
        $cells = [];
        foreach ($columns as $column) {
            $cell = (string) $row[$column];
            if (is_string($row[$column])) {
                $cell = (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $cell);
                if (preg_match('/^[\s\x{FEFF}]*[=+\-@＝＋－＠]/u', $cell) === 1) {
                    $cell = "'" . $cell;
                }
            }
            $cells[] = $cell;
        }
        fputcsv($output, $cells, ',', '"', '');
    }
    exit;
}

function render_admin_market(PDO $db, array $config): void
{
    $report = admin_market_diagnostics($config);
    $counts = $report['counts'];
    $attention = input($_GET, 'feed') === 'attention';
    $rows = $attention
        ? array_filter($report['rows'], static fn (array $row): bool => !in_array($row['status'], ['ready', 'demo'], true))
        : $report['rows'];
    $issueCount = $counts['stale'] + $counts['missing'] + $counts['invalid'] + $counts['future'];
    $demo = $report['mode'] === 'demo';
    $notice = admin_market_notice_record($db);
    ?>
    <div class="admin-grid admin-section admin-feed-summary">
        <section class="panel content-panel admin-card">
            <h2>Feed mode</h2>
            <p><strong><?= $demo ? 'Demo prices' : 'Licensed end-of-day' ?></strong></p>
            <p class="muted"><?= $demo ? 'Synthetic history ends on ' . e(display_date(MARKET_DEMO_DATE)) . '.' : 'Reads from the configured JSON directory.' ?></p>
        </section>
        <section class="panel content-panel admin-card">
            <h2>Coverage</h2>
            <p><strong><?= e($demo ? $counts['demo'] : $counts['ready']) ?> / <?= e(count($report['rows'])) ?></strong></p>
            <p class="muted"><?= $demo ? 'Symbols with sample data' : 'Symbols with a current close' ?></p>
        </section>
        <section class="panel content-panel admin-card">
            <h2>Needs attention</h2>
            <p><strong><?= e($issueCount) ?></strong></p>
            <p class="muted"><?= $demo ? 'Freshness checks apply to licensed data.' : 'Stale, missing, invalid or future-dated closes.' ?></p>
        </section>
        <section class="panel content-panel admin-card">
            <h2>Market JSON API</h2>
            <p><strong><?= $demo || !empty($config['publish_licensed_api']) ? 'Available' : 'Disabled' ?></strong></p>
            <p class="muted"><?= $demo ? 'Sample quotes only.' : 'Controlled by the licensed API setting.' ?></p>
        </section>
    </div>

    <section class="panel content-panel admin-section">
        <div class="panel-heading">
            <div>
                <h2>Symbol diagnostics</h2>
                <p>A close is marked stale after <?= e($report['stale_days']) ?> calendar <?= $report['stale_days'] === 1 ? 'day' : 'days' ?>. Dates are compared in Toronto.</p>
            </div>
            <a class="button" href="<?= e(admin_url('market', ['download' => 'health'])) ?>">Export health CSV</a>
        </div>
        <nav class="segments" aria-label="Filter symbol diagnostics">
            <a href="<?= e(admin_url('market')) ?>"<?= !$attention ? ' class="selected" aria-current="true"' : '' ?>>All symbols</a>
            <a href="<?= e(admin_url('market', ['feed' => 'attention'])) ?>"<?= $attention ? ' class="selected" aria-current="true"' : '' ?>>Needs attention (<?= e($issueCount) ?>)</a>
        </nav>
        <?php if ($rows === []): ?>
            <div class="compact-empty"><?= $attention ? 'No symbols need attention.' : 'The market catalogue is empty.' ?></div>
        <?php else: ?>
            <div class="table-wrap admin-table">
                <table>
                    <thead><tr>
                        <th scope="col">Symbol</th>
                        <th scope="col">Status</th>
                        <th scope="col">Last close date</th>
                        <th scope="col">Age (days)</th>
                        <th scope="col">Closes</th>
                        <th scope="col">Source or issue</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <th scope="row"><a class="text-link" href="<?= e(site_url('markets', ['symbol' => $row['symbol']], 'chart')) ?>"><?= e($row['symbol']) ?></a></th>
                            <td><span class="admin-status <?= e($row['status']) ?>"><?= e($row['status'] === 'future' ? 'Future date' : ucfirst($row['status'])) ?></span></td>
                            <td><?= e(display_date($row['last_close'])) ?></td>
                            <td><?= e($row['age_days'] ?? '—') ?></td>
                            <td><?= e($row['point_count']) ?></td>
                            <td class="admin-feed-detail">
                                <?php if ($row['issue'] !== ''): ?><span><?= e($row['issue']) ?></span><?php endif; ?>
                                <?php if ($row['source'] !== null): ?><span><?= e($row['source']) ?></span><?php endif; ?>
                                <?php if ($row['expected_file'] !== null): ?><small><code><?= e($row['expected_file']) ?></code></small><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section id="market-notice" class="panel content-panel admin-section">
        <div class="panel-heading">
            <div><h2>Public market notice</h2><p>Use a short notice when data is delayed or maintenance is scheduled.</p></div>
            <span class="admin-status <?= $notice !== null && (bool) $notice['active'] ? 'ready' : 'demo' ?>">
                <?= $notice === null ? 'Inactive' : ((bool) $notice['active'] ? 'Published' : 'Expired') ?>
            </span>
        </div>
        <form method="post" action="<?= e(admin_url('market')) ?>" class="stack-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="market-notice-save">
            <label for="market-notice-message">Message</label>
            <textarea id="market-notice-message" name="message" rows="3" maxlength="280" required><?= e($notice['message'] ?? '') ?></textarea>
            <label for="market-notice-severity">Severity</label>
            <select id="market-notice-severity" name="severity">
                <option value="info">Information</option>
                <option value="warning"<?= ($notice['severity'] ?? null) === 'warning' ? ' selected' : '' ?>>Warning</option>
            </select>
            <label for="market-notice-expiry">Expires at (UTC)</label>
            <input id="market-notice-expiry" type="datetime-local" name="expires_at" value="<?= e(isset($notice['expires_at']) ? str_replace(' ', 'T', substr($notice['expires_at'], 0, 16)) : '') ?>">
            <p class="field-help">Leave the expiry blank to keep the notice visible until you clear it.</p>
            <button type="submit" class="button primary">Publish notice</button>
        </form>
        <?php if ($notice !== null): ?>
        <form method="post" action="<?= e(admin_url('market')) ?>" class="delete-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="market-notice-clear">
            <button type="submit" class="button danger">Clear notice</button>
        </form>
        <?php endif; ?>
    </section>
    <?php
}
