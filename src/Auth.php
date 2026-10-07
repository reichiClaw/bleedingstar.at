<?php

declare(strict_types=1);

namespace App;

final class Auth
{
    public function __construct(private Database $db)
    {
    }

    public function user(): ?array
    {
        $id = $_SESSION['uid'] ?? null;
        if (!$id) {
            return null;
        }
        return $this->db->one('SELECT id, email FROM users WHERE id = ?', [(int) $id]);
    }

    public function attempt(string $email, string $password, string $ip): bool
    {
        $this->db->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 15 MINUTE)');
        $count = $this->db->one(
            'SELECT COUNT(*) AS c FROM login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)',
            [$ip]
        );
        if ((int) ($count['c'] ?? 0) >= 8) {
            return false;
        }
        $user = $this->db->one('SELECT id, password_hash FROM users WHERE email = ?', [$email]);
        $ok = $user && password_verify($password, $user['password_hash']);
        if (!$ok) {
            $this->db->exec('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, NOW())', [$ip]);
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        return true;
    }

    public function logout(): void
    {
        unset($_SESSION['uid']);
        session_regenerate_id(true);
    }
}
