<?php

declare(strict_types=1);

function handle_action(array $config, ?PDO $db, ?array $user, bool $boardEnabled): never
{
    $action = input($_POST, 'action');
    $symbol = market_symbol(input($_POST, 'symbol'));
    $topicId = post_id('topic_id');
    $destination = match ($action) {
        'register' => site_url('register'),
        'watch-add', 'watch-remove' => input($_POST, 'return') === 'markets' && $symbol !== null
            ? site_url('markets', ['symbol' => $symbol, 'range' => input($_POST, 'range', '3M')], 'chart')
            : site_url('watchlist'),
        'topic-post', 'topic-delete' => site_url('community'),
        'reply-post', 'reply-delete' => site_url('community', ['topic' => $topicId]),
        default => site_url('account'),
    };

    try {
        accounts_validate_csrf($_POST['csrf'] ?? null);
        switch ($action) {
            case 'register':
            case 'login':
                if ($db === null || ($action === 'register' && !$config['registration_enabled'])) {
                    throw new DomainException('Account access is unavailable.');
                }
                $username = input($_POST, 'username');
                $password = input($_POST, 'password');
                $address = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
                $guestWatchlist = $_SESSION['guest_watchlist'] ?? [];
                if ($action === 'register') {
                    accounts_register($db, $username, $password, $address);
                    flash('Your account is ready.');
                } else {
                    accounts_login($db, $username, $password, $address);
                    flash('You are signed in.');
                }
                $_SESSION['guest_watchlist'] = $guestWatchlist;
                $destination = site_url('watchlist');
                break;
            case 'logout':
                $guestWatchlist = $_SESSION['guest_watchlist'] ?? [];
                accounts_logout();
                $_SESSION['guest_watchlist'] = $guestWatchlist;
                break;
            case 'watch-add':
            case 'watch-remove':
                $symbol ??= accounts_normalize_symbol(input($_POST, 'symbol'));
                $adding = $action === 'watch-add';
                if ($db !== null && $user !== null) {
                    if ($adding) {
                        accounts_watch_add($db, (int) $user['id'], $symbol);
                    } else {
                        accounts_watch_remove($db, (int) $user['id'], $symbol);
                    }
                } else {
                    $symbols = $_SESSION['guest_watchlist'] ?? [];
                    if ($adding && count($symbols) >= ACCOUNTS_WATCHLIST_LIMIT && !in_array($symbol, $symbols, true)) {
                        throw new DomainException('Your watchlist can hold up to ' . ACCOUNTS_WATCHLIST_LIMIT . ' symbols.');
                    }
                    $_SESSION['guest_watchlist'] = $adding
                        ? array_values(array_unique([...$symbols, $symbol]))
                        : array_values(array_diff($symbols, [$symbol]));
                }
                flash($symbol . ($adding ? ' added to your watchlist.' : ' removed from your watchlist.'));
                break;
            case 'topic-post':
            case 'topic-delete':
            case 'reply-post':
            case 'reply-delete':
                if (!$boardEnabled || $db === null || $user === null) {
                    throw new DomainException('Sign in to post on the discussion board.');
                }
                $userId = (int) $user['id'];
                switch ($action) {
                    case 'topic-post':
                        $topicId = community_post($db, $userId, input($_POST, 'title'), input($_POST, 'body'));
                        $destination = site_url('community', ['topic' => $topicId]);
                        break;
                    case 'topic-delete':
                        community_delete($db, $userId, $topicId);
                        break;
                    case 'reply-post':
                        community_reply($db, $userId, $topicId, input($_POST, 'body'));
                        break;
                    case 'reply-delete':
                        community_reply_delete($db, $userId, $topicId, post_id('reply_id'));
                        break;
                }
                break;
            default:
                throw new DomainException('Choose an available action.');
        }
    } catch (DomainException $exception) {
        flash($exception->getMessage(), 'error');
    } catch (PDOException $exception) {
        error_log('JSD database action failed: ' . $exception->getCode());
        flash('The database could not save that change. Try again shortly.', 'error');
    }

    safe_redirect($destination);
}

function post_id(string $field): int
{
    return filter_var(input($_POST, $field), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
}
