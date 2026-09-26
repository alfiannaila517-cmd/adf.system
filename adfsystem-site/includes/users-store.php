<?php
/**
 * JSON-backed store for admin panel users (username, email, password hash, role).
 * Roles: 'admin' (full access) or 'staff' (no access to Pengguna & Pembayaran).
 */

function adf_users_path(): string
{
    return __DIR__ . '/../data/users.json';
}

function adf_users_load(): array
{
    $path = adf_users_path();
    if (!is_file($path)) {
        adf_users_seed_if_missing();
    }
    $raw = file_get_contents($path);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

function adf_users_save(array $users): bool
{
    $path = adf_users_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $json = json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return file_put_contents($path, $json, LOCK_EX) !== false;
}

/**
 * Migrates the legacy single-admin constants (ADMIN_USERNAME/ADMIN_PASSWORD_HASH/ADMIN_EMAIL)
 * into users.json the first time this runs, so existing installs keep working.
 */
function adf_users_seed_if_missing(): void
{
    $path = adf_users_path();
    if (is_file($path)) {
        return;
    }
    $users = [[
        'id' => '1',
        'username' => defined('ADMIN_USERNAME') ? ADMIN_USERNAME : 'admin',
        'email' => defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '',
        'password_hash' => defined('ADMIN_PASSWORD_HASH') ? ADMIN_PASSWORD_HASH : '',
        'role' => 'admin',
        'created_at' => date('c'),
    ]];
    adf_users_save($users);
}

function adf_users_find_by_username(string $username): ?array
{
    foreach (adf_users_load() as $user) {
        if (strcasecmp($user['username'], $username) === 0) {
            return $user;
        }
    }
    return null;
}

function adf_users_find_by_email(string $email): ?array
{
    foreach (adf_users_load() as $user) {
        if (strcasecmp($user['email'], $email) === 0) {
            return $user;
        }
    }
    return null;
}

function adf_users_find_by_id(string $id): ?array
{
    foreach (adf_users_load() as $user) {
        if ((string) $user['id'] === $id) {
            return $user;
        }
    }
    return null;
}

/**
 * Adds a new user. Returns false if the username or email is already taken.
 */
function adf_users_add(string $username, string $email, string $passwordHash, string $role): bool
{
    $users = adf_users_load();
    foreach ($users as $user) {
        if (strcasecmp($user['username'], $username) === 0 || strcasecmp($user['email'], $email) === 0) {
            return false;
        }
    }
    $maxId = 0;
    foreach ($users as $user) {
        $maxId = max($maxId, (int) $user['id']);
    }
    $users[] = [
        'id' => (string) ($maxId + 1),
        'username' => $username,
        'email' => $email,
        'password_hash' => $passwordHash,
        'role' => $role === 'admin' ? 'admin' : 'staff',
        'created_at' => date('c'),
    ];
    return adf_users_save($users);
}

function adf_users_update_password(string $id, string $newHash): bool
{
    $users = adf_users_load();
    $found = false;
    foreach ($users as &$user) {
        if ((string) $user['id'] === $id) {
            $user['password_hash'] = $newHash;
            $found = true;
            break;
        }
    }
    unset($user);
    if (!$found) {
        return false;
    }
    return adf_users_save($users);
}

/**
 * Deletes a user. Refuses to delete the last remaining admin to avoid lockout.
 */
function adf_users_delete(string $id): bool
{
    $users = adf_users_load();
    $target = null;
    foreach ($users as $user) {
        if ((string) $user['id'] === $id) {
            $target = $user;
            break;
        }
    }
    if ($target === null) {
        return false;
    }
    if ($target['role'] === 'admin') {
        $adminCount = count(array_filter($users, fn($u) => $u['role'] === 'admin'));
        if ($adminCount <= 1) {
            return false;
        }
    }
    $users = array_values(array_filter($users, fn($u) => (string) $u['id'] !== $id));
    return adf_users_save($users);
}
