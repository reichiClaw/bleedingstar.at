<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Shared by bin/install.php and the one-shot /setup page. The web variant exists because
 * shared hosting without SSH still needs a way to create the schema and the first admin.
 */
final class Installer
{
    public function __construct(private Database $db, private string $root)
    {
    }

    public function installed(): bool
    {
        return is_file($this->marker());
    }

    public function schema(): void
    {
        $schema = file_get_contents($this->root . '/database/schema.sql');
        if ($schema === false) {
            throw new RuntimeException('database/schema.sql fehlt.');
        }
        $pdo = $this->db->pdo();
        $schema = preg_replace('/^--.*$/m', '', $schema) ?? $schema;
        foreach (preg_split('/;\s*\n/', $schema) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
        $grid = $pdo->query("SHOW COLUMNS FROM releases LIKE 'cover_grid_path'")->fetch();
        if ($grid === false) {
            $pdo->exec('ALTER TABLE releases ADD COLUMN cover_grid_path VARCHAR(500) NULL AFTER cover_path');
        }
    }

    public function import(string $exportPath): array
    {
        return (new LegacyImporter($this->db, $this->root))->import($exportPath);
    }

    /** @return 'created'|'updated' */
    public function admin(string $email, string $password): string
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Ungültige E-Mail-Adresse.');
        }
        if (strlen($password) < 12) {
            throw new RuntimeException('Das Passwort braucht mindestens 12 Zeichen.');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $existing = $this->db->one('SELECT id FROM users WHERE email = ?', [$email]);
        if ($existing) {
            $this->db->exec('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $existing['id']]);
            return 'updated';
        }
        $this->db->insert('INSERT INTO users (email, password_hash, created_at) VALUES (?,?,NOW())', [$email, $hash]);
        return 'created';
    }

    public function finish(): void
    {
        $dir = dirname($this->marker());
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($this->marker(), date('c') . "\n");
    }

    /** Only a long, configured token opens the web installer, and only until the marker exists. */
    public function webAllowed(array $config, ?string $token): bool
    {
        $expected = (string) ($config['setup_token'] ?? '');
        if ($this->installed() || strlen($expected) < 32 || !is_string($token)) {
            return false;
        }
        return hash_equals($expected, $token);
    }

    private function marker(): string
    {
        return $this->root . '/storage/install.done';
    }
}
