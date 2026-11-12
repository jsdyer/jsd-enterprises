<?php

declare(strict_types=1);

function site_admin_is(?PDO $db, ?array $user, bool $enabled): bool
{
    if (!$enabled || $db === null || !is_int($user['id'] ?? null) || $user['id'] <= 0) {
        return false;
    }

    try {
        $statement = $db->prepare('SELECT 1 FROM site_admins WHERE user_id = ?');
        $statement->execute([$user['id']]);
        return $statement->fetchColumn() !== false;
    } catch (PDOException $error) {
        error_log('JSD admin access check failed: ' . $error->getCode());
        return false;
    }
}

function site_admin_audit(PDO $db, ?int $actorId, string $action, string $subject, string $detail = ''): void
{
    if ($actorId === null) {
        if (PHP_SAPI !== 'cli') {
            throw new LogicException('An audit actor is required for web requests.');
        }
        $statement = $db->prepare(
            "INSERT INTO site_admin_audit (actor_user_id, actor_username, action, subject, detail)
             VALUES (NULL, 'Operator CLI', ?, ?, ?)"
        );
        $statement->execute([$action, $subject, $detail]);
        return;
    }

    // Keep the actor's name in the event even if the account is deleted later.
    $statement = $db->prepare(
        'INSERT INTO site_admin_audit (actor_user_id, actor_username, action, subject, detail)
         SELECT u.id, u.username, ?, ?, ? FROM users u
         INNER JOIN site_admins a ON a.user_id = u.id WHERE u.id = ?'
    );
    $statement->execute([$action, $subject, $detail, $actorId]);
    if ($statement->rowCount() !== 1) {
        throw new DomainException('Administrator access is required.');
    }
}

function site_admin_events(PDO $db, int $limit = 30): array
{
    $statement = $db->prepare(
        'SELECT actor_username, action, subject, detail, created_at
         FROM site_admin_audit ORDER BY id DESC LIMIT ?'
    );
    $statement->bindValue(1, max(1, min($limit, 100)), PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}
