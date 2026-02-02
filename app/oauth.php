<?php

declare(strict_types=1);

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/db.php';

function oauth_random_string(int $len = 64): string
{
    return rtrim(strtr(base64_encode(random_bytes($len)), '+/', '-_'), '=');
}

function oauth_save_state(string $platform, array $data): void
{
    if (!isset($_SESSION['oauth_state'])) {
        $_SESSION['oauth_state'] = [];
    }
    $_SESSION['oauth_state'][$platform] = $data;
}

function oauth_get_state(string $platform): ?array
{
    return $_SESSION['oauth_state'][$platform] ?? null;
}

function oauth_clear_state(string $platform): void
{
    if (isset($_SESSION['oauth_state'][$platform])) {
        unset($_SESSION['oauth_state'][$platform]);
    }
}

function oauth_upsert_account(string $platform, string $displayName, string $handle, string $token): void
{
    $pdo = db_connection();
    $encrypted = verify_token_encryption($token);

    $existing = $pdo->prepare('SELECT id FROM accounts WHERE platform = :platform AND handle = :handle');
    $existing->execute([':platform' => $platform, ':handle' => $handle]);
    $row = $existing->fetch();

    if ($row) {
        $update = $pdo->prepare(
            'UPDATE accounts SET display_name = :display_name, oauth_token_encrypted = :token, is_active = 1 '
            . 'WHERE id = :id'
        );
        $update->execute([
            ':display_name' => $displayName,
            ':token' => $encrypted,
            ':id' => (int) $row['id'],
        ]);
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO accounts (platform, display_name, handle, oauth_token_encrypted, is_active) '
        . 'VALUES (:platform, :display_name, :handle, :token, 1)'
    );
    $insert->execute([
        ':platform' => $platform,
        ':display_name' => $displayName,
        ':handle' => $handle,
        ':token' => $encrypted,
    ]);
}
