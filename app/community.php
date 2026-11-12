<?php

declare(strict_types=1);

function community_topics(PDO $db): array
{
    $visibleTopic = community_visible_sql($db, 'community_hidden_topics', 't.id');
    $visibleReply = community_visible_sql($db, 'community_hidden_replies', 'r.id');
    $statement = $db->query(
        "SELECT t.id, t.title, t.body, t.user_id, t.created_at, u.username,
                (SELECT COUNT(*) FROM community_replies r WHERE r.topic_id = t.id $visibleReply) AS reply_count
         FROM community_topics t JOIN users u ON u.id = t.user_id
         WHERE 1 = 1 $visibleTopic
         ORDER BY t.id DESC LIMIT 50"
    );
    return $statement->fetchAll();
}

function community_topic(PDO $db, int $id): ?array
{
    $visibleTopic = community_visible_sql($db, 'community_hidden_topics', 't.id');
    $statement = $db->prepare(
        "SELECT t.id, t.title, t.body, t.user_id, t.created_at, u.username
         FROM community_topics t JOIN users u ON u.id = t.user_id WHERE t.id = ? $visibleTopic"
    );
    $statement->execute([$id]);
    return $statement->fetch() ?: null;
}

function community_replies(PDO $db, int $topicId): array
{
    $visibleTopic = community_visible_sql($db, 'community_hidden_topics', 'r.topic_id');
    $visibleReply = community_visible_sql($db, 'community_hidden_replies', 'r.id');
    $statement = $db->prepare(
        "SELECT r.id, r.topic_id, r.body, r.user_id, r.created_at, u.username
         FROM community_replies r JOIN users u ON u.id = r.user_id
         WHERE r.topic_id = ? $visibleTopic $visibleReply ORDER BY r.id DESC LIMIT 100"
    );
    $statement->execute([$topicId]);
    return array_reverse($statement->fetchAll());
}

function community_post(PDO $db, int $userId, string $title, string $body): int
{
    $title = trim($title);
    if (preg_match('/[\x00-\x1F\x7F]/', $title) || !community_length($title, 5, 120)) {
        throw new DomainException('Use a title between 5 and 120 characters.');
    }
    $body = community_body($body, 4000);
    community_limit_writes($db, $userId);

    $statement = $db->prepare('INSERT INTO community_topics (user_id, title, body) VALUES (?, ?, ?)');
    $statement->execute([$userId, $title, $body]);
    return (int) $db->lastInsertId();
}

function community_delete(PDO $db, int $userId, int $id): void
{
    $statement = $db->prepare('DELETE FROM community_topics WHERE id = ? AND user_id = ?');
    $statement->execute([$id, $userId]);
}

function community_reply(PDO $db, int $userId, int $topicId, string $body): void
{
    $body = community_body($body, 2000);
    community_limit_writes($db, $userId);

    $visibleTopic = community_visible_sql($db, 'community_hidden_topics', 't.id');
    $statement = $db->prepare(
        "INSERT INTO community_replies (topic_id, user_id, body)
         SELECT t.id, ?, ? FROM community_topics t WHERE t.id = ? $visibleTopic"
    );
    $statement->execute([$userId, $body, $topicId]);
    if ($statement->rowCount() === 0) {
        throw new DomainException('This discussion is no longer available.');
    }
}

function community_moderation_tables(PDO $db): array
{
    static $available = new WeakMap();
    if (!isset($available[$db])) {
        $statement = $db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('community_hidden_topics', 'community_hidden_replies')"
        );
        $available[$db] = $statement->fetchAll(PDO::FETCH_COLUMN);
    }
    return $available[$db];
}

function community_visible_sql(PDO $db, string $table, string $postId): string
{
    return in_array($table, community_moderation_tables($db), true)
        ? "AND NOT EXISTS (SELECT 1 FROM $table hidden WHERE hidden.post_id = $postId)"
        : '';
}

function community_reply_delete(PDO $db, int $userId, int $topicId, int $replyId): void
{
    $statement = $db->prepare('DELETE FROM community_replies WHERE id = ? AND topic_id = ? AND user_id = ?');
    $statement->execute([$replyId, $topicId, $userId]);
}

function community_body(string $body, int $maxCharacters): string
{
    $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
    if (preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $body) || !community_length($body, 1, $maxCharacters)) {
        throw new DomainException('Write a message between 1 and ' . $maxCharacters . ' characters.');
    }
    return $body;
}

function community_length(string $value, int $minimum, int $maximum): bool
{
    if (strlen($value) > $maximum * 4) {
        return false;
    }
    $length = preg_match_all('/./us', $value);
    return $length !== false && $length >= $minimum && $length <= $maximum;
}

function community_limit_writes(PDO $db, int $userId): void
{
    accounts_limit($db, 'community:minute:' . $userId, 4, 60, 'You’re posting quickly. Try again in a minute.');
    accounts_limit($db, 'community:day:' . $userId, 30, 86400, 'You’ve reached today’s posting limit. Try again tomorrow.');
}
