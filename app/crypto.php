<?php

declare(strict_types=1);

function encryption_key(): string
{
    $raw = getenv('ECHOTREE_SECRET_KEY');
    if (!$raw) {
        throw new RuntimeException('Missing ECHOTREE_SECRET_KEY env var.');
    }

    $key = base64_decode($raw, true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('ECHOTREE_SECRET_KEY must be base64-encoded 32 bytes.');
    }

    return $key;
}

function encrypt_token(string $plaintext): string
{
    $key = encryption_key();
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($ciphertext === false) {
        throw new RuntimeException('Failed to encrypt token.');
    }

    return base64_encode($iv . $tag . $ciphertext);
}

function verify_token_encryption(string $plaintext): string
{
    $encrypted = encrypt_token($plaintext);
    $decrypted = decrypt_token($encrypted);
    if (!hash_equals($plaintext, $decrypted)) {
        throw new RuntimeException('Token encryption verification failed.');
    }

    return $encrypted;
}

function decrypt_token(string $encoded): string
{
    $key = encryption_key();
    $payload = base64_decode($encoded, true);
    if ($payload === false || strlen($payload) < 12 + 16 + 1) {
        throw new RuntimeException('Invalid encrypted token payload.');
    }

    $iv = substr($payload, 0, 12);
    $tag = substr($payload, 12, 16);
    $ciphertext = substr($payload, 28);

    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($plaintext === false) {
        throw new RuntimeException('Failed to decrypt token.');
    }

    return $plaintext;
}
