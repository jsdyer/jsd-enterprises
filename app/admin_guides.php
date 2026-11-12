<?php

declare(strict_types=1);

const ADMIN_GUIDE_MAX_SECTIONS = 20;
const ADMIN_GUIDE_MAX_SOURCES = 30;

function admin_published_guides(PDO $db, array $baseGuides): array
{
    $rows = $db->query(
        'SELECT p.slug, r.payload FROM site_guide_pages p
         JOIN site_guide_revisions r ON r.id = p.published_revision_id AND r.slug = p.slug
         ORDER BY p.slug'
    );
    foreach ($rows as $row) {
        $baseGuides[$row['slug']] = admin_guide_decode($row['payload']);
    }
    return $baseGuides;
}

function admin_guide_decode(?string $payload): ?array
{
    return $payload === null ? null : json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
}

function admin_guide_slug(string $slug): bool
{
    return $slug !== 'new' && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug) === 1 && strlen($slug) <= 80;
}

function admin_guide_text(string $text, int $limit, string $label): string
{
    if (strlen($text) > $limit || preg_match('//u', $text) !== 1 || str_contains($text, "\0")) {
        throw new DomainException($label . ' is too long or contains invalid characters.');
    }
    return trim(str_replace(["\r\n", "\r"], "\n", $text));
}

function admin_guide_date(string $date): ?string
{
    if ($date === '') {
        return null;
    }
    if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D', $date, $parts) !== 1
        || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
        throw new DomainException('Use a valid review date.');
    }
    return $date;
}

function admin_guide_form_payload(array $post): array
{
    $title = admin_guide_text(input($post, 'title'), 240, 'The title');
    $category = admin_guide_text(input($post, 'category'), 100, 'The category');
    $description = admin_guide_text(input($post, 'description'), 600, 'The description');
    $minutes = filter_var(input($post, 'minutes'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 60]]);
    $reviewBy = admin_guide_date(input($post, 'review_by'));
    if ($title === '' || $category === '' || $description === '' || $minutes === false) {
        throw new DomainException('Enter a title, category, description and a reading time between 1 and 60 minutes.');
    }

    $sections = [];
    $sectionFields = $post['sections'] ?? [];
    if (!is_array($sectionFields) || count($sectionFields) > ADMIN_GUIDE_MAX_SECTIONS) {
        throw new DomainException('Use up to ' . ADMIN_GUIDE_MAX_SECTIONS . ' guide sections.');
    }
    foreach ($sectionFields as $fields) {
        if (!is_array($fields)) {
            throw new DomainException('A guide section could not be read.');
        }
        $heading = admin_guide_text(input($fields, 'heading'), 200, 'A section heading');
        $paragraphs = admin_guide_text(input($fields, 'paragraphs'), 12000, 'Section paragraphs');
        $steps = admin_guide_text(input($fields, 'steps'), 12000, 'Section steps');
        $code = input($fields, 'code');
        admin_guide_text($code, 16000, 'A code example');
        $code = str_replace(["\r\n", "\r"], "\n", $code);
        if ($heading === '' && $paragraphs === '' && $steps === '' && trim($code) === '') {
            continue;
        }
        if ($heading === '' || ($paragraphs === '' && $steps === '' && trim($code) === '')) {
            throw new DomainException('Give each used section a heading and some text, steps or code.');
        }

        $section = ['heading' => $heading];
        if ($paragraphs !== '') {
            $section['paragraphs'] = array_map('trim', preg_split('/\n[ \t]*\n+/', $paragraphs));
        }
        if ($steps !== '') {
            $section['steps'] = array_values(array_filter(array_map('trim', explode("\n", $steps)), static fn (string $step): bool => $step !== ''));
        }
        if (trim($code) !== '') {
            $section['code'] = $code;
        }
        $sections[] = $section;
    }
    if ($sections === []) {
        throw new DomainException('Add at least one guide section.');
    }

    $sources = [];
    $sourceFields = $post['sources'] ?? [];
    if (!is_array($sourceFields) || count($sourceFields) > ADMIN_GUIDE_MAX_SOURCES) {
        throw new DomainException('Use up to ' . ADMIN_GUIDE_MAX_SOURCES . ' source links.');
    }
    foreach ($sourceFields as $fields) {
        if (!is_array($fields)) {
            throw new DomainException('A source link could not be read.');
        }
        $label = admin_guide_text(input($fields, 'label'), 240, 'A source label');
        $url = admin_guide_text(input($fields, 'url'), 2048, 'A source URL');
        if ($label === '' && $url === '') {
            continue;
        }
        if ($label === '' || filter_var($url, FILTER_VALIDATE_URL) === false
            || strcasecmp((string) parse_url($url, PHP_URL_SCHEME), 'https') !== 0
            || parse_url($url, PHP_URL_USER) !== null) {
            throw new DomainException('Give each source a label and a valid HTTPS URL.');
        }
        $sources[] = ['label' => $label, 'url' => $url];
    }

    return [
        'category' => $category,
        'title' => $title,
        'description' => $description,
        'minutes' => $minutes,
        'sections' => $sections,
        'sources' => $sources,
        'review_by' => $reviewBy,
    ];
}

function admin_guide_make_revision(PDO $db, string $slug, int $actorId, string $payload): string
{
    $statement = $db->prepare('INSERT INTO site_guide_revisions (slug, actor_id, payload) VALUES (?, ?, ?)');
    $statement->execute([$slug, $actorId, $payload]);
    return $db->lastInsertId();
}

function handle_admin_guide_action(PDO $db, int $actorId, string $action, array $post, array $baseGuides): string
{
    $slug = input($post, 'guide_slug');
    if (!admin_guide_slug($slug)) {
        throw new DomainException('Use a guide address of up to 80 lowercase letters, digits and hyphens.');
    }
    if (!in_array($action, ['guide-save', 'guide-publish', 'guide-restore'], true)) {
        throw new DomainException('Choose an available guide action.');
    }
    $new = $action === 'guide-save' && input($post, 'guide_new') === '1';
    if ($new && isset($baseGuides[$slug])) {
        throw new DomainException('That guide address is already in use.');
    }
    $version = filter_var(input($post, 'edit_version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($version === false) {
        throw new DomainException('Reload the guide editor and try again.');
    }
    $draftPayload = $action === 'guide-save'
        ? json_encode(admin_guide_form_payload($post), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : null;
    $db->beginTransaction();
    try {
        if ($action === 'guide-save') {
            $ensure = $db->prepare('INSERT INTO site_guide_pages (slug) VALUES (?) ON DUPLICATE KEY UPDATE slug = VALUES(slug)');
            $ensure->execute([$slug]);
        }
        $lock = $db->prepare(
            'SELECT draft_payload, published_revision_id, edit_version FROM site_guide_pages WHERE slug = ? FOR UPDATE'
        );
        $lock->execute([$slug]);
        $page = $lock->fetch();
        if ($page === false || (int) $page['edit_version'] !== $version) {
            throw new DomainException('This guide changed while it was open. Reload the editor before retrying.');
        }
        if ($new && $version !== 0) {
            throw new DomainException('That guide address is already in use.');
        }

        $publishedId = $page['published_revision_id'];
        $detail = '';
        if ($action === 'guide-save') {
            $message = 'Guide draft saved.';
        } elseif ($action === 'guide-publish') {
            if ($page['draft_payload'] === null) {
                throw new DomainException('Save a draft before publishing.');
            }
            $publishedId = admin_guide_make_revision($db, $slug, $actorId, $page['draft_payload']);
            $detail = 'Revision ' . $publishedId;
            $message = 'Guide published.';
        } else {
            $draftPayload = $page['draft_payload'];
            $restore = input($post, 'restore_revision');
            if ($restore === 'original' && $publishedId !== null) {
                if (!isset($baseGuides[$slug]) && $draftPayload === null) {
                    $draftPayload = admin_guide_find_revision($db, $slug, (string) $publishedId);
                }
                $publishedId = null;
                $detail = isset($baseGuides[$slug]) ? 'Repository original' : 'Unpublished custom guide';
                $message = isset($baseGuides[$slug]) ? 'Repository guide restored.' : 'Guide unpublished.';
            } else {
                $payload = admin_guide_find_revision($db, $slug, $restore);
                $publishedId = admin_guide_make_revision($db, $slug, $actorId, $payload);
                $detail = 'Restored revision ' . $restore . ' as revision ' . $publishedId;
                $message = 'Published guide revision restored.';
            }
        }

        $update = $db->prepare(
            'UPDATE site_guide_pages SET draft_payload = ?, published_revision_id = ?, edit_version = edit_version + 1 WHERE slug = ?'
        );
        $update->execute([$draftPayload, $publishedId, $slug]);
        site_admin_audit($db, $actorId, $action, $slug, $detail);
        $db->commit();
        return $message;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function admin_guide_find_revision(PDO $db, string $slug, string $revision): string
{
    if (filter_var($revision, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
        throw new DomainException('Choose an available guide revision.');
    }
    $statement = $db->prepare('SELECT payload FROM site_guide_revisions WHERE slug = ? AND id = ?');
    $statement->execute([$slug, $revision]);
    $payload = $statement->fetchColumn();
    if ($payload === false) {
        throw new DomainException('Choose an available guide revision.');
    }
    return $payload;
}

function admin_guide_url(array $parameters = []): string
{
    return site_url('admin', ['tab' => 'guides'] + $parameters);
}

function admin_guide_records(PDO $db): array
{
    $rows = $db->query(
        'SELECT p.slug, p.edit_version, p.draft_payload, p.published_revision_id,
                r.payload AS published_payload, r.created_at AS published_at
         FROM site_guide_pages p LEFT JOIN site_guide_revisions r ON r.id = p.published_revision_id AND r.slug = p.slug
         ORDER BY p.slug'
    );
    $records = [];
    foreach ($rows as $row) {
        $row['draft'] = admin_guide_decode($row['draft_payload']);
        $row['published'] = admin_guide_decode($row['published_payload']);
        unset($row['draft_payload'], $row['published_payload']);
        $records[$row['slug']] = $row;
    }
    return $records;
}

function render_admin_guides(PDO $db, array $baseGuides): void
{
    $slug = input($_GET, 'guide');
    $records = admin_guide_records($db);
    if ($slug === '') {
        render_admin_guide_list($records, $baseGuides);
        return;
    }

    $new = $slug === 'new';
    $record = $records[$slug] ?? null;
    $base = $baseGuides[$slug] ?? null;
    if (!$new && $record === null && $base === null) {
        empty_panel('Guide unavailable', 'Choose a guide from the editor list.', 'book');
        echo '<p class="standalone-action"><a class="text-link" href="' . e(admin_guide_url()) . '">Back to guide desk</a></p>';
        return;
    }

    $preview = input($_GET, 'preview');
    $revision = input($_GET, 'revision');
    if ($preview === '1' && isset($record['draft'])) {
        render_admin_guide_preview($slug, 'Private draft preview', $record['draft']);
        return;
    }
    if ($revision !== '' && $record !== null) {
        try {
            $archived = admin_guide_decode(admin_guide_find_revision($db, $slug, $revision));
            render_admin_guide_preview($slug, 'Published revision ' . $revision . ' preview', $archived);
        } catch (DomainException $error) {
            empty_panel('Revision unavailable', $error->getMessage(), 'book');
            echo '<p class="standalone-action"><a class="text-link" href="'
                . e(admin_guide_url(['guide' => $slug])) . '">Back to editor</a></p>';
        }
        return;
    }

    $current = $record['draft'] ?? $record['published'] ?? $base ?? [];
    $version = (int) ($record['edit_version'] ?? 0);
    ?>
    <p class="back-link"><a class="text-link" href="<?= e(admin_guide_url()) ?>">← Guide desk</a></p>
    <div class="admin-grid admin-guide-layout">
        <section class="panel content-panel admin-section">
            <div class="panel-heading">
                <div><h2><?= $new ? 'New guide' : e($current['title'] ?? $slug) ?></h2>
                    <p><?= $new ? 'Set the guide address and start a draft.' : 'Edit the text, steps, code examples and sources.' ?></p></div>
                <span class="tile-icon"><?= icon('book', 20) ?></span>
            </div>
            <form class="stack-form admin-guide-editor" method="post" action="<?= e(admin_guide_url(['guide' => $slug])) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="guide-save">
                <input type="hidden" name="edit_version" value="<?= $version ?>">
                <?php if ($new): ?>
                    <input type="hidden" name="guide_new" value="1">
                    <label for="guide-slug">Guide address</label>
                    <input id="guide-slug" name="guide_slug" placeholder="reading-a-dividend-history" maxlength="80" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" required>
                <?php else: ?>
                    <input type="hidden" name="guide_slug" value="<?= e($slug) ?>">
                <?php endif; ?>
                <label for="guide-title">Title</label>
                <input id="guide-title" name="title" maxlength="240" value="<?= e($current['title'] ?? '') ?>" required>
                <label for="guide-category">Category</label>
                <input id="guide-category" name="category" maxlength="100" value="<?= e($current['category'] ?? '') ?>" required>
                <label for="guide-description">Short description</label>
                <textarea id="guide-description" name="description" rows="2" maxlength="600" required><?= e($current['description'] ?? '') ?></textarea>
                <label for="guide-minutes">Reading time in minutes</label>
                <input type="number" id="guide-minutes" name="minutes" min="1" max="60" value="<?= e($current['minutes'] ?? 5) ?>" required>
                <label for="guide-review">Review by <span class="muted">(optional)</span></label>
                <input type="date" id="guide-review" name="review_by" value="<?= e($current['review_by'] ?? '') ?>">
                <p class="field-help">The guide desk flags published guides when their review date arrives.</p>

                <h3>Sections</h3>
                <p class="field-help">Separate paragraphs with a blank line. Enter one step per line. Clear a section’s fields to remove it.</p>
                <?php
                $sections = $current['sections'] ?? [];
                if (count($sections) < ADMIN_GUIDE_MAX_SECTIONS) {
                    $sections[] = [];
                }
                foreach ($sections as $i => $section) {
                    render_admin_guide_section($i, $section, $i === 0);
                }
                ?>

                <h3>Sources</h3>
                <p class="field-help">Use HTTPS links. Fill the extra row to add a source or clear an existing row to remove it.</p>
                <?php
                $sources = $current['sources'] ?? [];
                if (count($sources) < ADMIN_GUIDE_MAX_SOURCES) {
                    $sources[] = [];
                }
                foreach ($sources as $i => $source) {
                    render_admin_guide_source($i, $source);
                }
                ?>
                <button class="button primary" type="submit">Save draft</button>
            </form>
        </section>
        <?php render_admin_guide_publication($db, $slug, $version, $record, $base !== null); ?>
    </div>
    <?php
}

function render_admin_guide_preview(string $slug, string $label, array $guide): void
{
    echo '<p class="notice" role="status"><span>' . e($label) . '</span><a class="text-link" href="'
        . e(admin_guide_url(['guide' => $slug])) . '">Back to editor</a></p>';
    render_guide($guide);
}

function render_admin_guide_list(array $records, array $baseGuides): void
{
    $slugs = array_unique([...array_keys($baseGuides), ...array_keys($records)]);
    $today = new DateTimeImmutable('today', new DateTimeZone('America/Toronto'));
    ?>
    <section class="panel content-panel admin-section">
        <div class="panel-heading">
            <div><h2>Guide desk</h2><p>Publish walkthroughs, revisit sources and restore earlier versions.</p></div>
            <a class="button primary" href="<?= e(admin_guide_url(['guide' => 'new'])) ?>"><?= icon('plus', 17) ?> New guide</a>
        </div>
        <?php if ($slugs === []): ?><p class="compact-empty">Create a guide to get started.</p><?php endif; ?>
        <?php foreach ($slugs as $slug):
            $record = $records[$slug] ?? null;
            $public = $record['published'] ?? $baseGuides[$slug] ?? null;
            $draft = $record['draft'] ?? null;
            $guide = $draft ?? $public;
            if ($guide === null) {
                continue;
            }
            $reviewBy = $public['review_by'] ?? null;
            $status = match (true) {
                $public === null => 'Draft only',
                $draft !== null => 'Live with draft',
                isset($record['published']) => 'Published',
                default => 'Repository guide',
            };
            ?>
            <a class="topic-row admin-guide-row" href="<?= e(admin_guide_url(['guide' => $slug])) ?>">
                <span>
                    <strong><?= e($guide['title']) ?></strong>
                    <small><?= e($guide['category']) ?> · <?= e($slug) ?></small>
                </span>
                <span>
                    <span class="admin-status"><?= e($status) ?></span>
                    <?php if ($reviewBy !== null): ?>
                        <small><?= $reviewBy <= $today->format('Y-m-d') ? 'Review due' : 'Review by' ?> <?= e(display_date($reviewBy)) ?></small>
                    <?php endif; ?>
                </span>
            </a>
        <?php endforeach; ?>
    </section>
    <?php
}

function render_admin_guide_section(int $i, array $section, bool $open): void
{
    $label = $section['heading'] ?? 'Add a section';
    ?>
    <details class="admin-guide-block" <?= $open ? 'open' : '' ?>>
        <summary><?= e($label) ?></summary>
        <div class="stack-form">
            <label for="guide-heading-<?= $i ?>">Heading</label>
            <input id="guide-heading-<?= $i ?>" name="sections[<?= $i ?>][heading]" maxlength="200" value="<?= e($section['heading'] ?? '') ?>">
            <label for="guide-paragraphs-<?= $i ?>">Paragraphs</label>
            <textarea id="guide-paragraphs-<?= $i ?>" name="sections[<?= $i ?>][paragraphs]" rows="4" maxlength="12000"><?= e(implode("\n\n", $section['paragraphs'] ?? [])) ?></textarea>
            <label for="guide-steps-<?= $i ?>">Steps</label>
            <textarea id="guide-steps-<?= $i ?>" name="sections[<?= $i ?>][steps]" rows="4" maxlength="12000"><?= e(implode("\n", $section['steps'] ?? [])) ?></textarea>
            <label for="guide-code-<?= $i ?>">Code example</label>
            <textarea class="admin-guide-code" id="guide-code-<?= $i ?>" name="sections[<?= $i ?>][code]" rows="4" maxlength="16000" spellcheck="false"><?= e($section['code'] ?? '') ?></textarea>
        </div>
    </details>
    <?php
}

function render_admin_guide_source(int $i, array $source): void
{
    ?>
    <details class="admin-guide-block" <?= $source === [] ? 'open' : '' ?>>
        <summary><?= e($source['label'] ?? 'Add a source') ?></summary>
        <div class="stack-form">
            <label for="guide-source-label-<?= $i ?>">Source label</label>
            <input id="guide-source-label-<?= $i ?>" name="sources[<?= $i ?>][label]" maxlength="240" value="<?= e($source['label'] ?? '') ?>">
            <label for="guide-source-url-<?= $i ?>">HTTPS URL</label>
            <input type="url" id="guide-source-url-<?= $i ?>" name="sources[<?= $i ?>][url]" maxlength="2048" value="<?= e($source['url'] ?? '') ?>" placeholder="https://">
        </div>
    </details>
    <?php
}

function render_admin_guide_publication(PDO $db, string $slug, int $version, ?array $record, bool $hasBase): void
{
    $public = $hasBase || isset($record['published']);
    ?>
    <section class="panel content-panel admin-section">
        <div class="panel-heading">
            <div><h2>Publication</h2><p><?= $public ? 'The current guide is visible to readers.' : 'Save a draft to start the publication workflow.' ?></p></div>
        </div>
        <?php if (isset($record['draft'])): ?>
            <div class="form-actions">
                <a class="button" href="<?= e(admin_guide_url(['guide' => $slug, 'preview' => '1'])) ?>">Preview draft</a>
                <?php render_admin_guide_action($slug, $version, 'guide-publish', 'Publish draft'); ?>
            </div>
        <?php endif; ?>
        <?php if ($public): ?>
            <p class="standalone-action"><a class="text-link" href="<?= e(site_url('guide', ['guide' => $slug])) ?>">View public guide <?= icon('arrow', 16) ?></a></p>
        <?php endif; ?>
        <?php if ($record !== null):
            $revisions = $db->prepare(
                'SELECT r.id, r.created_at, u.username
                 FROM site_guide_revisions r LEFT JOIN users u ON u.id = r.actor_id
                 WHERE r.slug = ? ORDER BY r.id DESC LIMIT 12'
            );
            $revisions->execute([$slug]);
            $history = $revisions->fetchAll();
            ?>
            <?php if ($history !== []): ?>
                <div class="admin-guide-history standalone-action">
                    <h3>Recent publications</h3>
                    <?php foreach ($history as $revision): ?>
                    <div class="topic-row">
                        <span><a class="text-link" href="<?= e(admin_guide_url(['guide' => $slug, 'revision' => $revision['id']])) ?>">Revision <?= e($revision['id']) ?></a>
                            <small><?= e($revision['created_at']) ?> UTC<?= isset($revision['username']) ? ' · ' . e($revision['username']) : '' ?></small>
                        </span>
                        <?php if ((string) $revision['id'] === (string) $record['published_revision_id']): ?>
                            <span class="admin-status">Live</span>
                        <?php else: ?>
                            <?php render_admin_guide_action($slug, $version, 'guide-restore', 'Restore', (string) $revision['id']); ?>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (isset($record['published'])): ?>
                <div class="standalone-action">
                    <?php render_admin_guide_action($slug, $version, 'guide-restore', $hasBase ? 'Restore repository guide' : 'Unpublish guide', 'original'); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
}

function render_admin_guide_action(string $slug, int $version, string $action, string $label, string $revision = ''): void
{
    ?>
    <form method="post" action="<?= e(admin_guide_url(['guide' => $slug])) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= e($action) ?>">
        <input type="hidden" name="guide_slug" value="<?= e($slug) ?>">
        <input type="hidden" name="edit_version" value="<?= $version ?>">
        <?php if ($revision !== ''): ?><input type="hidden" name="restore_revision" value="<?= e($revision) ?>"><?php endif; ?>
        <button class="button <?= $action === 'guide-publish' ? 'primary' : '' ?>" type="submit"><?= e($label) ?></button>
    </form>
    <?php
}
