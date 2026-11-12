<?php

declare(strict_types=1);

const ACCOUNTS_BCRYPT_OPTIONS = ['cost' => 12];
const ACCOUNTS_DUMMY_HASH = '$2y$12$YnE8nmzSJ7GoLryGcZnbJeeD/5Go.q9WSb1WlJ7wLaA0E7tKskX8O';
const ACCOUNTS_WATCHLIST_LIMIT = 100;

function accounts_database(array $config): ?PDO
{
    $dsn = $config['dsn'] ?? '';
    if ($dsn === '') {
        return null;
    }

    $db = new PDO($dsn, $config['username'] ?? '', $config['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 3,
    ]);
    $db->exec("SET time_zone = '+00:00'");

    return $db;
}

function accounts_session_start(bool $secure): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('jsd_investing');
    $started = session_start([
        'use_strict_mode' => 1,
        'use_only_cookies' => 1,
        'cookie_httponly' => true,
        'cookie_secure' => $secure,
        'cookie_samesite' => 'Lax',
        'cookie_path' => '/',
    ]);
    if (!$started) {
        throw new RuntimeException('Could not start a session.');
    }
}

function accounts_current_user(?PDO $db): ?array
{
    $id = $_SESSION['user_id'] ?? null;
    if ($db === null || !is_int($id)) {
        return null;
    }

    $statement = $db->prepare('SELECT id, username, created_at FROM users WHERE id = ?');
    $statement->execute([$id]);
    $user = $statement->fetch();
    if ($user === false) {
        accounts_logout();
        return null;
    }

    $user['id'] = (int) $user['id'];
    return $user;
}

function accounts_csrf_token(): string
{
    return $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
}

function accounts_validate_csrf(mixed $token): void
{
    if (!is_string($token) || !hash_equals(accounts_csrf_token(), $token)) {
        throw new DomainException('Your form expired. Refresh the page and try again.');
    }
}

function accounts_register(PDO $db, string $username, string $password, string $clientIp): void
{
    $username = trim($username);
    accounts_limit_auth($db, $username, $clientIp);
    if (!accounts_valid_username($username)) {
        throw new DomainException('Use 3 to 24 letters, numbers, underscores, or hyphens for your username. Start with a letter or number.');
    }
    if (!accounts_valid_password($password)) {
        throw new DomainException('Use a password with at least 12 characters and at most 72 UTF-8 bytes.');
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ACCOUNTS_BCRYPT_OPTIONS);
    $statement = $db->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
    try {
        $statement->execute([$username, $hash]);
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
            throw new DomainException('That username is already in use.');
        }
        throw $error;
    }

    accounts_sign_in((int) $db->lastInsertId());
}

function accounts_login(PDO $db, string $username, string $password, string $clientIp): void
{
    $username = trim($username);
    accounts_limit_auth($db, $username, $clientIp);
    $user = false;
    if (accounts_valid_username($username)) {
        $statement = $db->prepare('SELECT id, password_hash FROM users WHERE username = ?');
        $statement->execute([$username]);
        $user = $statement->fetch();
    }

    $validPassword = accounts_valid_password($password);
    $hash = $user === false ? ACCOUNTS_DUMMY_HASH : $user['password_hash'];
    $matched = password_verify($validPassword ? $password : '', $hash);
    if ($user === false || !$validPassword || !$matched) {
        throw new DomainException('Check your username and password and try again.');
    }

    if (password_needs_rehash($hash, PASSWORD_BCRYPT, ACCOUNTS_BCRYPT_OPTIONS)) {
        $statement = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND password_hash = ?');
        $statement->execute([password_hash($password, PASSWORD_BCRYPT, ACCOUNTS_BCRYPT_OPTIONS), $user['id'], $hash]);
    }

    accounts_sign_in((int) $user['id']);
}

function accounts_logout(): void
{
    accounts_sign_in(null);
}

function accounts_watchlist(PDO $db, int $userId): array
{
    $statement = $db->prepare('SELECT symbol, created_at FROM watchlist WHERE user_id = ? ORDER BY symbol');
    $statement->execute([$userId]);

    return $statement->fetchAll();
}

function accounts_watch_add(PDO $db, int $userId, string $symbol): void
{
    $symbol = accounts_normalize_symbol($symbol);
    $db->beginTransaction();
    try {
        // Serialize additions by this account so concurrent requests respect the cap.
        $statement = $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $statement->execute([$userId]);
        if ($statement->fetchColumn() === false) {
            throw new DomainException('Sign in again to update your watchlist.');
        }

        $statement = $db->prepare('SELECT symbol FROM watchlist WHERE user_id = ?');
        $statement->execute([$userId]);
        $symbols = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array($symbol, $symbols, true)) {
            if (count($symbols) >= ACCOUNTS_WATCHLIST_LIMIT) {
                throw new DomainException('Your watchlist can hold up to ' . ACCOUNTS_WATCHLIST_LIMIT . ' symbols.');
            }
            $statement = $db->prepare('INSERT INTO watchlist (user_id, symbol) VALUES (?, ?)');
            $statement->execute([$userId, $symbol]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function accounts_watch_remove(PDO $db, int $userId, string $symbol): void
{
    $statement = $db->prepare('DELETE FROM watchlist WHERE user_id = ? AND symbol = ?');
    $statement->execute([$userId, accounts_normalize_symbol($symbol)]);
}

function accounts_normalize_symbol(string $symbol): string
{
    $symbol = strtoupper(trim($symbol));
    if (strlen($symbol) > 24 || !preg_match('/\A\^?[A-Z0-9]{1,10}(?:[.-][A-Z0-9]{1,8}){0,2}(?:=[A-Z])?\z/', $symbol)) {
        throw new DomainException('Enter a valid ticker symbol, such as RY.TO or VCN.TO.');
    }
    return $symbol;
}

function accounts_valid_username(string $username): bool
{
    return preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{2,23}\z/', $username) === 1;
}

function accounts_valid_password(string $password): bool
{
    if (strlen($password) > 72 || str_contains($password, "\0")) {
        return false;
    }
    $characters = preg_match_all('/./us', $password);
    return $characters !== false && $characters >= 12;
}

function accounts_sign_in(?int $userId): void
{
    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Could not rotate the session ID.');
    }
    $_SESSION = $userId === null ? [] : ['user_id' => $userId];
}

function accounts_limit_auth(PDO $db, string $username, string $clientIp): void
{
    $message = 'Too many attempts. Try again in 15 minutes.';
    $address = inet_pton($clientIp);
    if ($address !== false && strlen($address) === 16) {
        // Normalize mapped IPv4 addresses and group native IPv6 addresses by subnet.
        $address = str_starts_with($address, str_repeat("\0", 10) . "\xFF\xFF")
            ? substr($address, 12)
            : substr($address, 0, 8);
    }
    accounts_limit($db, 'auth:ip:' . ($address === false ? 'unknown' : bin2hex($address)), 12, 900, $message);
    if (accounts_valid_username($username)) {
        accounts_limit($db, 'auth:user:' . strtolower($username), 20, 900, $message);
    }
}

function accounts_limit(PDO $db, string $scope, int $maxAttempts, int $windowSeconds, string $message): void
{
    $key = hash('sha256', $scope);
    $now = time();
    $windowStart = $now - $now % $windowSeconds;

    $db->beginTransaction();
    try {
        $statement = $db->prepare(
            'INSERT INTO rate_limits (scope, window_start, attempts) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE
                 attempts = IF(window_start < VALUES(window_start), 1, LEAST(attempts + 1, 1000)),
                 window_start = GREATEST(window_start, VALUES(window_start))'
        );
        $statement->execute([$key, $windowStart]);
        $statement = $db->prepare('SELECT attempts FROM rate_limits WHERE scope = ?');
        $statement->execute([$key]);
        $attempts = (int) $statement->fetchColumn();
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    if (random_int(1, 128) === 1) {
        $statement = $db->prepare('DELETE FROM rate_limits WHERE window_start < ? LIMIT 512');
        $statement->execute([$now - 172800]);
    }
    if ($attempts > $maxAttempts) {
        throw new DomainException($message);
    }
}
