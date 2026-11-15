<?php
declare(strict_types=1);

namespace RankMathWebapp\Core;

use PDO;

/**
 * Applies database/migrations/NNN_name.sql once each, tracked in {prefix}schema_migrations.
 * `{{prefix}}` in SQL is replaced with the validated table prefix. MySQL DDL auto-commits, so a failed
 * migration may be partially applied: fix the cause and re-run (completed files are skipped).
 */
final class Migrator
{
    public function __construct(private PDO $pdo, private string $prefix, private ?string $dir = null)
    {
        if (!preg_match('/^[A-Za-z0-9_]{0,16}$/', $prefix)) {
            throw new ConfigException('Invalid table prefix');
        }
        $this->dir ??= dirname(__DIR__) . '/database/migrations';
    }

    /** @return string[] names of migrations applied by this call */
    public function run(): array
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $lock = $this->pdo->query("SELECT GET_LOCK('rmw_migrate', 10)")->fetchColumn();
        if ((int)$lock !== 1) {
            throw new DatabaseException('Could not obtain migration lock');
        }
        try {
            return $this->apply();
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('rmw_migrate')");
        }
    }

    /** @return array<string,bool> migration name => applied? */
    public function status(): array
    {
        $files = $this->files();
        $done = $this->tableExists('schema_migrations')
            ? $this->pdo->query("SELECT `name` FROM `{$this->prefix}schema_migrations`")->fetchAll(PDO::FETCH_COLUMN)
            : [];
        return array_combine(array_keys($files), array_map(static fn ($n) => in_array($n, $done, true), array_keys($files)));
    }

    private function apply(): array
    {
        $legacy = !$this->tableExists('schema_migrations') && $this->tableExists('settings');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `{$this->prefix}schema_migrations` (
            `name` VARCHAR(100) NOT NULL PRIMARY KEY,
            `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=ascii");

        $done = $this->pdo->query("SELECT `name` FROM `{$this->prefix}schema_migrations`")->fetchAll(PDO::FETCH_COLUMN);
        $files = $this->files();
        $applied = [];

        if ($legacy && !$done) {
            // A v1.0 install (rm_settings exists, no migration table): its schema is exactly 001.
            $first = array_key_first($files);
            $this->record($first);
            $done[] = $first;
        }

        foreach ($files as $name => $path) {
            if (in_array($name, $done, true)) { continue; }
            foreach ($this->statements((string)file_get_contents($path)) as $sql) {
                $this->pdo->exec($sql);
            }
            $this->record($name);
            $applied[] = $name;
        }
        return $applied;
    }

    private function record(string $name): void
    {
        $this->pdo->prepare("INSERT INTO `{$this->prefix}schema_migrations` (`name`) VALUES (?)")->execute([$name]);
    }

    /** @return array<string,string> name => path, sorted */
    private function files(): array
    {
        $out = [];
        foreach (glob($this->dir . '/[0-9][0-9][0-9]_*.sql') ?: [] as $f) {
            $out[basename($f, '.sql')] = $f;
        }
        ksort($out);
        return $out;
    }

    /** @return string[] */
    private function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $sql = str_replace('{{prefix}}', $this->prefix, (string)$sql);
        $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        return array_values(array_filter(array_map('trim', $parts), 'strlen'));
    }

    private function tableExists(string $table): bool
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $st->execute([$this->prefix . $table]);
        return (int)$st->fetchColumn() > 0;
    }
}
