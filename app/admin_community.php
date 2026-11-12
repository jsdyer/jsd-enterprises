<?php

declare(strict_types=1);

function handle_admin_community_action(PDO $db, int $actorId, string $action, array $post): string
{
    $kind = input($post, 'kind');
    $postId = filter_var(input($post, 'post_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $reason = trim(input($post, 'reason'));
    if (!in_array($action, ['moderation-hide', 'moderation-restore'], true)
        || !in_array($kind, ['topic', 'reply'], true) || $postId === false) {
        throw new DomainException('Choose a post and moderation action.');
    }
    if (!community_length($reason, 3, 250) || preg_match('/[\x00-\x1F\x7F]/', $reason)) {
        throw new DomainException('Enter a one-line moderation reason of 3 to 250 characters.');
    }
    if (count(community_moderation_tables($db)) !== 2) {
        throw new DomainException('Install the community moderation migration to manage posts.');
    }

    $topic = $kind === 'topic';
    $postTable = $topic ? 'community_topics' : 'community_replies';
    $hiddenTable = $topic ? 'community_hidden_topics' : 'community_hidden_replies';
    $hide = $action === 'moderation-hide';
    $label = ucfirst($kind);

    $db->beginTransaction();
    try {
        $parentField = $topic ? 'id' : 'topic_id';
        $statement = $db->prepare("SELECT $parentField FROM $postTable WHERE id = ? FOR UPDATE");
        $statement->execute([$postId]);
        $topicId = $statement->fetchColumn();
        if ($topicId === false) {
            throw new DomainException('This post was removed.');
        }

        $statement = $db->prepare("SELECT 1 FROM $hiddenTable WHERE post_id = ?");
        $statement->execute([$postId]);
        $hidden = $statement->fetchColumn() !== false;
        if ($hide === $hidden) {
            throw new DomainException($hide ? 'This post is already hidden.' : 'This post is already restored.');
        }

        if ($hide) {
            $statement = $db->prepare("INSERT INTO $hiddenTable (post_id, reason) VALUES (?, ?)");
            $statement->execute([$postId, $reason]);
        } else {
            $statement = $db->prepare("DELETE FROM $hiddenTable WHERE post_id = ?");
            $statement->execute([$postId]);
        }

        $parentHidden = false;
        if (!$topic && !$hide) {
            $statement = $db->prepare('SELECT 1 FROM community_hidden_topics WHERE post_id = ?');
            $statement->execute([$topicId]);
            $parentHidden = $statement->fetchColumn() !== false;
        }

        site_admin_audit($db, $actorId, $action, 'Community ' . $kind . ' #' . $postId, $reason);
        $db->commit();

        if ($hide) {
            return $label . ' hidden.';
        }
        return $parentHidden ? 'Reply restored. Its thread is still hidden.' : $label . ' restored.';
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function admin_community_posts(PDO $db, bool $topics, bool $hiddenOnly, int $postId = 0): array
{
    $postTable = $topics ? 'community_topics' : 'community_replies';
    $hiddenTable = $topics ? 'community_hidden_topics' : 'community_hidden_replies';
    $topicFields = $topics
        ? 'p.id AS topic_id, p.title, NULL AS parent_hidden_at'
        : 'p.topic_id, topic.title, parent_hidden.hidden_at AS parent_hidden_at';
    $topicJoin = $topics ? '' :
        'JOIN community_topics topic ON topic.id = p.topic_id
         LEFT JOIN community_hidden_topics parent_hidden ON parent_hidden.post_id = p.topic_id';
    $conditions = [];
    $parameters = [];
    if ($hiddenOnly) {
        $conditions[] = $topics
            ? 'hidden.post_id IS NOT NULL'
            : '(hidden.post_id IS NOT NULL OR parent_hidden.post_id IS NOT NULL)';
    }
    if ($postId > 0) {
        $conditions[] = $topics ? 'p.id = ?' : '(p.id = ? OR p.topic_id = ?)';
        $parameters = $topics ? [$postId] : [$postId, $postId];
    }
    $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
    $statement = $db->prepare(
        "SELECT p.id, p.body, p.created_at, member.username, hidden.reason, hidden.hidden_at, $topicFields
         FROM $postTable p
         JOIN users member ON member.id = p.user_id
         LEFT JOIN $hiddenTable hidden ON hidden.post_id = p.id
         $topicJoin $where
         ORDER BY p.id DESC LIMIT 25"
    );
    $statement->execute($parameters);
    return $statement->fetchAll();
}

function render_admin_community(PDO $db, bool $boardEnabled): void
{
    if (count(community_moderation_tables($db)) !== 2) {
        empty_panel('Moderation setup', 'Install the community moderation migration to review, hide and restore posts.', 'community');
        return;
    }
    $hiddenOnly = input($_GET, 'view') === 'hidden';
    $postId = filter_var(input($_GET, 'post'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    $query = ['tab' => 'community'] + ($postId > 0 ? ['post' => $postId] : []);
    $destination = site_url('admin', $query + ($hiddenOnly ? ['view' => 'hidden'] : []));
    ?>
    <section class="panel content-panel admin-section" aria-labelledby="community-moderation">
        <div class="panel-heading">
            <div>
                <h2 id="community-moderation">Community moderation</h2>
                <p>Hide and restore posts with an internal reason. A hidden topic also hides its replies.</p>
                <?php if (!$boardEnabled): ?><p>The public board is closed. You can still review its posts here.</p><?php endif; ?>
            </div>
            <span class="tile-icon"><?= icon('community', 20) ?></span>
        </div>
        <div class="admin-moderation-filters">
            <nav class="segments" aria-label="Moderation view">
                <?php foreach (['recent' => 'Recent posts', 'hidden' => 'Hidden posts'] as $view => $name): ?>
                    <?php $current = $hiddenOnly === ($view === 'hidden'); ?>
                    <a class="<?= $current ? 'selected' : '' ?>" <?= $current ? 'aria-current="true"' : '' ?>
                        href="<?= e(site_url('admin', $query + ['view' => $view])) ?>"><?= e($name) ?></a>
                <?php endforeach; ?>
            </nav>
            <form class="inline-form" method="get">
                <input type="hidden" name="page" value="admin">
                <input type="hidden" name="tab" value="community">
                <?php if ($hiddenOnly): ?><input type="hidden" name="view" value="hidden"><?php endif; ?>
                <label class="sr-only" for="moderation-lookup">Topic or reply ID</label>
                <input type="number" id="moderation-lookup" name="post" min="1" placeholder="Topic or reply ID" value="<?= $postId > 0 ? e($postId) : '' ?>" required>
                <button class="button" type="submit">Find post</button>
                <?php if ($postId > 0): ?><a class="text-link" href="<?= e(site_url('admin', ['tab' => 'community'] + ($hiddenOnly ? ['view' => 'hidden'] : []))) ?>">Clear</a><?php endif; ?>
            </form>
        </div>
    </section>
    <div class="admin-grid">
        <?php foreach (['topic' => 'Topics', 'reply' => 'Replies'] as $kind => $heading): ?>
            <?php $posts = admin_community_posts($db, $kind === 'topic', $hiddenOnly, $postId); ?>
            <section class="panel content-panel admin-card">
                <div class="panel-heading"><div><h2><?= e($heading) ?></h2><p>Up to 25 of the latest <?= $hiddenOnly ? 'hidden' : 'submitted' ?> <?= e(strtolower($heading)) ?>.</p></div></div>
                <?php if ($posts === []): ?><p class="compact-empty">No <?= e(strtolower($heading)) ?> to review.</p><?php endif; ?>
                <?php foreach ($posts as $post): ?>
                    <?php render_admin_community_post($post, $kind, $destination, $boardEnabled); ?>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    </div>
    <?php
}

function render_admin_community_post(array $post, string $kind, string $destination, bool $boardEnabled): void
{
    $hidden = $post['hidden_at'] !== null;
    $parentHidden = $post['parent_hidden_at'] !== null;
    $state = $hidden ? 'Hidden' : ($parentHidden ? 'Topic hidden' : 'Visible');
    $id = 'moderation-' . $kind . '-' . $post['id'];
    ?>
    <details class="admin-post-details">
        <summary>
            <span><strong><?= $kind === 'reply' ? 'Re: ' : '' ?><?= e($post['title']) ?></strong>
            <small>#<?= e($post['id']) ?> · <?= e($post['username']) ?> · <?= e(display_date($post['created_at'])) ?></small></span>
            <span class="admin-status <?= $hidden || $parentHidden ? 'warning' : '' ?>"><?= e($state) ?></span>
        </summary>
        <div class="admin-post-content">
            <p class="post-body"><?= e($post['body']) ?></p>
            <?php if ($hidden): ?>
                <p class="muted"><strong>Hide reason</strong> <?= e($post['reason']) ?> · <?= e(display_date($post['hidden_at'])) ?></p>
            <?php endif; ?>
            <?php if ($parentHidden && $hidden): ?><p class="muted">The topic is also hidden.</p><?php endif; ?>
            <?php if (!$hidden && !$parentHidden && $boardEnabled): ?>
                <p><a class="text-link" href="<?= e(site_url('community', ['topic' => $post['topic_id']])) ?>">Open public discussion <?= icon('arrow', 15) ?></a></p>
            <?php endif; ?>
            <form class="stack-form" method="post" action="<?= e($destination) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= $hidden ? 'moderation-restore' : 'moderation-hide' ?>">
                <input type="hidden" name="kind" value="<?= e($kind) ?>">
                <input type="hidden" name="post_id" value="<?= e($post['id']) ?>">
                <label for="<?= e($id) ?>">Internal reason</label>
                <input id="<?= e($id) ?>" name="reason" minlength="3" maxlength="250" required>
                <button class="button <?= $hidden ? '' : 'danger' ?>" type="submit"><?= $hidden ? 'Restore' : 'Hide' ?> <?= e($kind) ?></button>
            </form>
        </div>
    </details>
    <?php
}
