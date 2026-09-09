<?php
declare(strict_types=1);

final class UpgradeService
{
    public function __construct(private PDO $pdo, private string $root)
    {
        $this->root = rtrim($this->root, '/\\');
    }

    public function ensureTrackingTables(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration_key VARCHAR(190) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            checksum_sha256 CHAR(64) NOT NULL,
            statement_count INT UNSIGNED NOT NULL DEFAULT 0,
            execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
            app_version_after VARCHAR(40) NULL,
            applied_by BIGINT UNSIGNED NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_schema_migration_key (migration_key),
            KEY idx_schema_migrations_applied_at (applied_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS upgrade_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration_key VARCHAR(190) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            status ENUM('running','success','failed') NOT NULL DEFAULT 'running',
            statement_count INT UNSIGNED NOT NULL DEFAULT 0,
            execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
            error_message TEXT NULL,
            run_by BIGINT UNSIGNED NULL,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            KEY idx_upgrade_runs_started (started_at),
            KEY idx_upgrade_runs_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** @return array<int,array<string,mixed>> */
    public function migrations(): array
    {
        $files = array_merge(
            glob($this->root . '/db/[0-9][0-9][0-9]_*.sql') ?: [],
            glob($this->root . '/db/[0-9][0-9][0-9]_*.sql.gz') ?: []
        );
        sort($files, SORT_NATURAL);
        $out = [];
        foreach ($files as $path) {
            $filename = basename($path);
            $key = preg_replace('/\.(sql|sql\.gz)$/i', '', $filename) ?: $filename;
            $raw = (string) file_get_contents($path);
            $sqlRaw = str_ends_with($path, '.gz') ? gzdecode($raw) : $raw;
            if ($sqlRaw === false) {
                throw new RuntimeException('Could not decompress ' . $filename . '.');
            }
            $sql = self::sqlForSelectedDatabase((string) $sqlRaw);
            $out[] = [
                'key' => $key,
                'filename' => $filename,
                'path' => $path,
                'checksum' => hash('sha256', (string) $sqlRaw),
                'bytes' => strlen((string) $sqlRaw),
                'app_version' => self::extractAppVersion($sql),
            ];
        }
        return $out;
    }

    public function currentAppVersion(): string
    {
        try {
            $stmt = $this->pdo->prepare("SELECT meta_value FROM app_meta WHERE meta_key='app_version' LIMIT 1");
            $stmt->execute();
            return (string) ($stmt->fetchColumn() ?: 'unknown');
        } catch (Throwable) {
            return 'unknown';
        }
    }

    public function targetAppVersion(): string
    {
        $version = $this->currentAppVersion();
        foreach ($this->migrations() as $migration) {
            if (!empty($migration['app_version'])) {
                $version = (string) $migration['app_version'];
            }
        }
        return $version;
    }

    /** @return array<string,array<string,mixed>> */
    public function appliedMap(): array
    {
        $this->ensureTrackingTables();
        $rows = $this->pdo->query('SELECT migration_key,filename,checksum_sha256,statement_count,execution_ms,app_version_after,applied_at FROM schema_migrations ORDER BY id')->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['migration_key']] = $row;
        }
        return $map;
    }

    /**
     * Existing Vacation Brain installs through v1.13 predate schema_migrations.
     * Establish a baseline once based on app_meta.app_version so old migrations are not replayed.
     */
    public function bootstrapLegacyHistory(?int $adminId = null): int
    {
        $this->ensureTrackingTables();
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        if ($count > 0) {
            return 0;
        }
        $current = $this->currentAppVersion();
        if ($current === 'unknown') {
            return 0;
        }
        $cutoff = $this->legacyCutoffForVersion($current);
        if ($cutoff <= 0) {
            return 0;
        }

        $insert = $this->pdo->prepare('INSERT IGNORE INTO schema_migrations
            (migration_key,filename,checksum_sha256,statement_count,execution_ms,app_version_after,applied_by,applied_at)
            VALUES (?,?,?,?,?,?,?,NOW())');
        $marked = 0;
        foreach ($this->migrations() as $migration) {
            $number = (int) substr((string) $migration['key'], 0, 3);
            if ($number > $cutoff) {
                continue;
            }
            $insert->execute([
                $migration['key'], $migration['filename'], $migration['checksum'], 0, 0,
                $migration['app_version'] ?: $current, $adminId,
            ]);
            $marked += $insert->rowCount() > 0 ? 1 : 0;
        }
        return $marked;
    }

    /** @return array<int,array<string,mixed>> */
    public function pendingMigrations(): array
    {
        $applied = $this->appliedMap();
        $pending = [];
        foreach ($this->migrations() as $migration) {
            if (!isset($applied[$migration['key']])) {
                $pending[] = $migration;
            }
        }
        return $pending;
    }

    /** @return array<int,array<string,mixed>> */
    public function migrationStatus(): array
    {
        $applied = $this->appliedMap();
        $status = [];
        foreach ($this->migrations() as $migration) {
            $row = $applied[$migration['key']] ?? null;
            $migration['status'] = $row ? 'applied' : 'pending';
            $migration['applied_at'] = $row['applied_at'] ?? null;
            $migration['checksum_changed'] = $row ? !hash_equals((string) $row['checksum_sha256'], (string) $migration['checksum']) : false;
            $status[] = $migration;
        }
        return $status;
    }

    /** @return array<int,array<string,mixed>> */
    public function applyPending(?int $adminId = null): array
    {
        $this->ensureTrackingTables();
        $this->bootstrapLegacyHistory($adminId);
        $gotLock = (int) $this->pdo->query("SELECT GET_LOCK('vacation_brain_schema_upgrade', 0)")->fetchColumn();
        if ($gotLock !== 1) {
            throw new RuntimeException('Another Vacation Brain upgrade is already running.');
        }
        try {
            $results = [];
            foreach ($this->pendingMigrations() as $migration) {
                $results[] = $this->applyMigration($migration, $adminId);
            }
            return $results;
        } finally {
            try { $this->pdo->query("SELECT RELEASE_LOCK('vacation_brain_schema_upgrade')"); } catch (Throwable) {}
        }
    }

    /** @return array<string,mixed> */
    public function applyMigration(array $migration, ?int $adminId = null): array
    {
        $raw = (string) file_get_contents((string) $migration['path']);
        if (str_ends_with((string) $migration['path'], '.gz')) {
            $decoded = gzdecode($raw);
            if ($decoded === false) {
                throw new RuntimeException('Could not decompress ' . $migration['filename'] . '.');
            }
            $raw = $decoded;
        }
        $key = (string) ($migration['key'] ?? '');
        $sql = self::sqlForSelectedDatabase($raw);
        $sql = self::compatibilitySqlForMigration($key, $sql);
        $resumeSafe = $key === '023_sample_data_destination_research';
        $run = $this->pdo->prepare('INSERT INTO upgrade_runs (migration_key,filename,status,run_by) VALUES (?,? ,"running",?)');
        $run->execute([$migration['key'], $migration['filename'], $adminId]);
        $runId = (int) $this->pdo->lastInsertId();
        $start = microtime(true);
        try {
            $statements = $sql === '' ? 0 : self::executeSqlScript($this->pdo, $sql, $resumeSafe);
            $ms = max(0, (int) round((microtime(true) - $start) * 1000));
            $after = $migration['app_version'] ?: $this->currentAppVersion();
            $stmt = $this->pdo->prepare('INSERT INTO schema_migrations
                (migration_key,filename,checksum_sha256,statement_count,execution_ms,app_version_after,applied_by,applied_at)
                VALUES (?,?,?,?,?,?,?,NOW())');
            $stmt->execute([$migration['key'],$migration['filename'],$migration['checksum'],$statements,$ms,$after,$adminId]);
            $this->pdo->prepare('UPDATE upgrade_runs SET status="success",statement_count=?,execution_ms=?,completed_at=NOW() WHERE id=?')
                ->execute([$statements,$ms,$runId]);
            return ['key'=>$migration['key'],'filename'=>$migration['filename'],'statements'=>$statements,'execution_ms'=>$ms,'app_version'=>$after];
        } catch (Throwable $e) {
            $ms = max(0, (int) round((microtime(true) - $start) * 1000));
            $this->pdo->prepare('UPDATE upgrade_runs SET status="failed",execution_ms=?,error_message=?,completed_at=NOW() WHERE id=?')
                ->execute([$ms,substr($e->getMessage(),0,65000),$runId]);
            throw new RuntimeException('Upgrade failed in ' . $migration['filename'] . ': ' . $e->getMessage(), 0, $e);
        }
    }

    public static function executeSqlScript(PDO $pdo, string $sql, bool $allowAlreadyAppliedDdl = false): int
    {
        $count = 0; $stmt = ''; $quote = null; $len = strlen($sql); $escape = false; $lineComment = false; $blockComment = false;
        for ($i=0; $i<$len; $i++) {
            $ch=$sql[$i]; $next=$i+1<$len?$sql[$i+1]:'';
            if ($lineComment) { if ($ch === "\n") { $lineComment=false; $stmt .= $ch; } continue; }
            if ($blockComment) { if ($ch==='*' && $next==='/') { $blockComment=false; $i++; } continue; }
            if ($quote === null) {
                if ($ch==='-' && $next==='-' && ($i+2 >= $len || ctype_space($sql[$i+2]))) { $lineComment=true; $i++; continue; }
                if ($ch==='#') { $lineComment=true; continue; }
                if ($ch==='/' && $next==='*') { $blockComment=true; $i++; continue; }
                if ($ch==="'" || $ch==='"' || $ch==='`') { $quote=$ch; $stmt.=$ch; continue; }
                if ($ch===';') {
                    $trim=trim($stmt);
                    if ($trim!=='') {
                        self::executeStatement($pdo, $trim, $allowAlreadyAppliedDdl);
                        $count++;
                    }
                    $stmt='';
                    continue;
                }
                $stmt.=$ch;
                continue;
            }
            $stmt.=$ch;
            if ($quote==='`') { if ($ch==='`') $quote=null; continue; }
            if ($escape) { $escape=false; continue; }
            if ($ch==='\\') { $escape=true; continue; }
            if ($ch===$quote) {
                if ($next===$quote) { $stmt.=$next; $i++; continue; }
                $quote=null;
            }
        }
        $trim=trim($stmt);
        if ($trim!=='') {
            self::executeStatement($pdo, $trim, $allowAlreadyAppliedDdl);
            $count++;
        }
        return $count;
    }

    public static function sqlForSelectedDatabase(string $sql): string
    {
        $sql = preg_replace('/CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+vacation_brain\s+CHARACTER\s+SET\s+utf8mb4\s+COLLATE\s+utf8mb4_0900_ai_ci\s*;/is','',$sql) ?? $sql;
        $sql = preg_replace('/\bUSE\s+vacation_brain\s*;/i','',$sql) ?? $sql;
        return trim($sql);
    }

    private static function compatibilitySqlForMigration(string $key, string $sql): string
    {
        if ($key !== '023_sample_data_destination_research') {
            return $sql;
        }

        /*
         * Migration 023 bridges older core tables created with MySQL 8's
         * utf8mb4_0900_ai_ci collation and the newer catalog tables created with
         * utf8mb4_unicode_ci. Comparing places.name/city directly with
         * destination_catalog.name/city can therefore raise MySQL error 1267.
         * Keep the source migration checksum stable, but execute those two
         * comparisons with an explicit common collation.
         */
        $sql = str_replace(
            "p.name=CONCAT(dc.name,' Sample Hotel')",
            "p.name COLLATE utf8mb4_unicode_ci=CONCAT(dc.name,' Sample Hotel') COLLATE utf8mb4_unicode_ci",
            $sql
        );
        $sql = str_replace(
            "p.name=CONCAT(dc.name,' Local Favorite')",
            "p.name COLLATE utf8mb4_unicode_ci=CONCAT(dc.name,' Local Favorite') COLLATE utf8mb4_unicode_ci",
            $sql
        );
        $sql = str_replace(
            "COALESCE(p.city,'')=COALESCE(dc.city,'')",
            "COALESCE(p.city,'') COLLATE utf8mb4_unicode_ci=COALESCE(dc.city,'') COLLATE utf8mb4_unicode_ci",
            $sql
        );
        return $sql;
    }

    private static function executeStatement(PDO $pdo, string $sql, bool $allowAlreadyAppliedDdl): void
    {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            if ($allowAlreadyAppliedDdl && self::isAlreadyAppliedDdlError($e, $sql)) {
                return;
            }
            throw $e;
        }
    }

    private static function isAlreadyAppliedDdlError(PDOException $e, string $sql): bool
    {
        $normalized = strtoupper(ltrim($sql));
        if (!str_starts_with($normalized, 'ALTER TABLE') && !str_starts_with($normalized, 'CREATE TABLE')) {
            return false;
        }

        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        return in_array($driverCode, [1050, 1060, 1061, 1826], true);
    }

    private static function extractAppVersion(string $sql): ?string
    {
        if (preg_match_all("/['\"]app_version['\"]\s*,\s*['\"]([^'\"]+)['\"]/i", $sql, $m) && !empty($m[1])) {
            return (string) end($m[1]);
        }
        return null;
    }

    private function legacyCutoffForVersion(string $version): int
    {
        $map = [
            '1.15'=>22,'1.14'=>21,'1.13'=>20,'1.12'=>19,'1.11'=>18,'1.10'=>17,'1.9'=>16,'1.8'=>15,'1.7'=>14,'1.6'=>13,
            '1.5'=>12,'1.4'=>11,'1.3'=>10,'1.2'=>9,'1.1'=>5,'1.0'=>5,
        ];
        if (isset($map[$version])) return $map[$version];
        foreach ($map as $known => $cutoff) {
            if (version_compare($version, $known, '>=')) return $cutoff;
        }
        return 0;
    }
}
