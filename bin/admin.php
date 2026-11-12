<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/admin.php';

$usage = <<<'HELP'
Usage
  php bin/admin.php list
  php bin/admin.php grant USERNAME
  php bin/admin.php revoke USERNAME

HELP;

$command = $argv[1] ?? '';
if (in_array($command, ['', '-h', '--help'], true)) {
    fwrite(STDOUT, $usage . PHP_EOL);
    exit($command === '' ? 2 : 0);
}

$isChange = in_array($command, ['grant', 'revoke'], true);
if ((!$isChange && $command !== 'list') || count($argv) !== ($isChange ? 3 : 2)) {
    fwrite(STDERR, $usage . PHP_EOL);
    exit(2);
}

try {
    $db = accounts_database(site_config()['database']);
    if ($db === null) {
        throw new DomainException('Configure MariaDB before managing administrator access.');
    }

    if ($command === 'list') {
        $rows = $db->query(
            'SELECT u.username, a.granted_at FROM site_admins a
             INNER JOIN users u ON u.id = a.user_id ORDER BY u.username'
        )->fetchAll();
        if ($rows === []) {
            fwrite(STDOUT, "No administrators have been granted access.\n");
        }
        foreach ($rows as $row) {
            fwrite(STDOUT, $row['username'] . '  ' . $row['granted_at'] . " UTC\n");
        }
        exit;
    }

    $username = $argv[2];
    if (!accounts_valid_username($username)) {
        throw new DomainException('Supply an existing account username.');
    }

    $db->beginTransaction();
    // Lock the account row to serialize concurrent grants and revocations.
    $account = $db->prepare('SELECT id, username FROM users WHERE username = ? FOR UPDATE');
    $account->execute([$username]);
    $user = $account->fetch();
    if ($user === false) {
        throw new DomainException('No account matches that username. Create and verify the account first.');
    }

    $role = $db->prepare('SELECT 1 FROM site_admins WHERE user_id = ?');
    $role->execute([$user['id']]);
    $alreadyAdmin = $role->fetchColumn() !== false;
    $grant = $command === 'grant';
    if ($alreadyAdmin === $grant) {
        $db->commit();
        fwrite(STDOUT, $user['username'] . ($grant ? " already has administrator access.\n" : " has no administrator access.\n"));
        exit;
    }

    $change = $db->prepare($grant ? 'INSERT INTO site_admins (user_id) VALUES (?)' : 'DELETE FROM site_admins WHERE user_id = ?');
    $change->execute([$user['id']]);
    site_admin_audit($db, null, $grant ? 'Access granted' : 'Access revoked', $user['username']);
    $db->commit();
    fwrite(STDOUT, ($grant ? 'Granted' : 'Revoked') . ' administrator access for ' . $user['username'] . ".\n");
} catch (DomainException | PDOException $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    $message = $error instanceof DomainException ? $error->getMessage() : 'Administrator access could not be updated. Check the database connection and admin schema.';
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
