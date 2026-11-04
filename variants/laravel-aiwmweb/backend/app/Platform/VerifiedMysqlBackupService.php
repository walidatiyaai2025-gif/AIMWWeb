<?php

namespace App\Platform;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Encrypted, authenticated MySQL/InnoDB consistent snapshot.
 *
 * Does not assume that MySQL DDL can be rolled back. Restores are deliberately
 * restricted to an empty, isolated recovery schema and never target live data.
 */
final class VerifiedMysqlBackupService
{
    private const MAGIC = 'AIWMDB01';

    private const MAX_RECORD = 16777216;

    /** @return array{id: string, sha256: string, rows: int, tables: int, verified: bool} */
    public function backupNow(int $actorId): array
    {
        $pdo = DB::connection()->getPdo();
        if (DB::connection()->getDriverName() !== 'mysql' || ! extension_loaded('sodium')) {
            throw new RuntimeException('Verified MySQL/InnoDB backup requires mysql and sodium; one-click updates are blocked.');
        }

        $catalog = $pdo->query('SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')
            ->fetchAll(PDO::FETCH_ASSOC);
        if (! is_array($catalog) || $catalog === []) {
            throw new RuntimeException('Cannot create backup of an empty or unreadable schema.');
        }

        foreach ($catalog as $table) {
            if ($table['TABLE_TYPE'] !== 'BASE TABLE' || strtoupper((string) $table['ENGINE']) !== 'INNODB') {
                throw new RuntimeException('Non-InnoDB tables or views require an assisted consistent backup; update blocked.');
            }
        }

        $id = bin2hex(random_bytes(12));
        $folder = storage_path('app/system-updates/backups/mysql-'.$id);
        if (! mkdir($folder, 0700, true)) {
            throw new RuntimeException('Cannot allocate private database backup directory.');
        }

        $path = $folder.'/snapshot.enc';
        $stream = @fopen($path, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Cannot create private database snapshot.');
        }
        chmod($path, 0600);

        $salt = random_bytes(16);
        $key = $this->key($salt);
        [$crypto, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $rows = 0;
        $tables = [];
        $inTransaction = false;
        $buffered = null;

        try {
            $this->writeAll($stream, self::MAGIC.$salt.$header);
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $inTransaction = true;

            foreach ($catalog as $table) {
                $name = (string) $table['TABLE_NAME'];
                $escaped = $this->identifier($name);
                $definition = $pdo->query('SHOW CREATE TABLE '.$escaped)->fetch(PDO::FETCH_ASSOC);
                $columns = $pdo->query('SHOW COLUMNS FROM '.$escaped)->fetchAll(PDO::FETCH_ASSOC);
                if (! is_array($definition) || ! isset($definition['Create Table']) || ! is_array($columns)) {
                    throw new RuntimeException('Cannot read consistent schema metadata.');
                }
                $tables[] = [
                    'name' => $name,
                    'ddl' => (string) $definition['Create Table'],
                    'columns' => array_map(static fn (array $column): string => (string) $column['Field'], $columns),
                ];
            }

            $this->writeRecord($stream, $crypto, ['type' => 'schema', 'tables' => $tables]);
            // Use unbuffered queries to avoid unbounded RAM use on shared hosting.
            $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

            foreach ($tables as $table) {
                $stmt = $pdo->query('SELECT * FROM '.$this->identifier($table['name']));
                while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $cells = [];
                    foreach ($table['columns'] as $column) {
                        $value = $row[$column] ?? null;
                        $cells[] = $value === null ? null : base64_encode((string) $value);
                    }
                    $this->writeRecord($stream, $crypto, [
                        'type' => 'row', 'table' => $table['name'], 'cells' => $cells,
                    ]);
                    $rows++;
                }
                $stmt->closeCursor();
            }
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            $buffered = null;
            $pdo->exec('COMMIT');
            $inTransaction = false;
            $this->writeRecord($stream, $crypto, [
                'type' => 'end', 'rows' => $rows, 'tables' => count($tables),
            ], true);
            fflush($stream);
            fclose($stream);
            $stream = null;

            $receipt = [
                'id' => $id, 'sha256' => hash_file('sha256', $path),
                'rows' => $rows, 'tables' => count($tables), 'verified' => true,
                'actor_id' => $actorId, 'at' => now()->toIso8601String(),
            ];
            file_put_contents($folder.'/receipt.json', json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
            chmod($folder.'/receipt.json', 0600);
            $this->verify($id);

            return array_intersect_key($receipt, array_flip(['id', 'sha256', 'rows', 'tables', 'verified']));
        } catch (Throwable $e) {
            if ($buffered !== null) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            }
            if ($inTransaction) {
                $pdo->exec('ROLLBACK');
            }
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($path);
            @unlink($folder.'/receipt.json');
            @rmdir($folder);

            throw $e;
        }
    }

    /** @return array{rows: int, tables: int, sha256: string} */
    public function verify(string $id): array
    {
        [$folder, $receipt] = $this->receipt($id);
        $path = $folder.'/snapshot.enc';
        if (! is_file($path) || ! hash_equals((string) $receipt['sha256'], (string) hash_file('sha256', $path))) {
            throw new RuntimeException('Database backup checksum verification failed.');
        }

        $rows = 0;
        $tables = [];
        $finished = false;
        $this->readRecords($path, function (array $record, bool $isFinal) use (&$rows, &$tables, &$finished): void {
            if ($finished) {
                throw new RuntimeException('Database backup contains trailing records.');
            }
            if ($record['type'] === 'schema' && $tables === [] && $rows === 0) {
                $tables = $record['tables'] ?? [];
                if (! is_array($tables) || $tables === []) {
                    throw new RuntimeException('Database backup schema inventory is invalid.');
                }
            } elseif ($record['type'] === 'row' && $tables !== [] && ! $isFinal) {
                ++$rows;
            } elseif ($record['type'] === 'end' && $isFinal) {
                if ($tables === [] || $rows !== ($record['rows'] ?? null) ||
                    count($tables) !== ($record['tables'] ?? null)) {
                    throw new RuntimeException('Database backup row inventory is inconsistent.');
                }
                $finished = true;
            } else {
                throw new RuntimeException('Database backup record order is invalid.');
            }
        });

        if (! $finished || $rows !== $receipt['rows'] || count($tables) !== $receipt['tables']) {
            throw new RuntimeException('Database backup cannot be verified completely.');
        }

        return ['rows' => $rows, 'tables' => count($tables), 'sha256' => $receipt['sha256']];
    }

    /**
     * Recovery only into an EMPTY disposable MySQL database. This never truncates
     * or automatically reverses production tables or non-reversible migrations.
     */
    public function restoreToEmptyDatabase(string $id, PDO $target): void
    {
        $this->verify($id);
        if ($target->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' ||
            $target->query('SHOW TABLES')->fetchColumn() !== false) {
            throw new RuntimeException('Recovery requires an empty isolated MySQL schema.');
        }

        [$folder] = $this->receipt($id);
        $target->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $statements = [];
            $this->readRecords($folder.'/snapshot.enc', function (array $record) use ($target, &$statements): void {
                if ($record['type'] === 'schema') {
                    foreach ($record['tables'] as $table) {
                        $target->exec((string) $table['ddl']);
                        $statements[$table['name']] = $target->prepare(
                            'INSERT INTO '.$this->identifier($table['name']).
                            ' ('.implode(',', array_map($this->identifier(...), $table['columns'])).')'.
                            ' VALUES ('.implode(',', array_fill(0, count($table['columns']), '?')).')',
                        );
                    }
                } elseif ($record['type'] === 'row') {
                    if (! isset($statements[$record['table']])) {
                        throw new RuntimeException('Recovery row does not map to a verified table.');
                    }
                    $values = array_map(static function ($value) {
                        if ($value === null) {
                            return null;
                        }
                        $decoded = base64_decode((string) $value, true);
                        if ($decoded === false) {
                            throw new RuntimeException('Recovery cell encoding is invalid.');
                        }

                        return $decoded;
                    }, $record['cells']);
                    if (! $statements[$record['table']]->execute($values)) {
                        throw new RuntimeException('Recovery insertion failed.');
                    }
                }
            });
        } finally {
            $target->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /** @return array{0: string, 1: array} */
    private function receipt(string $id): array
    {
        if (! preg_match('/^[a-f0-9]{24}$/D', $id)) {
            throw new RuntimeException('Invalid database backup identifier.');
        }
        $folder = storage_path('app/system-updates/backups/mysql-'.$id);
        $file = $folder.'/receipt.json';
        $receipt = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($receipt) || ($receipt['id'] ?? '') !== $id ||
            ! preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['sha256'] ?? ''))) {
            throw new RuntimeException('Verified database backup receipt is missing.');
        }

        return [$folder, $receipt];
    }

    private function key(string $salt): string
    {
        $raw = (string) config('app.key');
        $key = str_starts_with($raw, 'base64:') ? base64_decode(substr($raw, 7), true) : $raw;
        if (! is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('A secure 256-bit APP_KEY is required to encrypt database backups.');
        }

        return hash_hkdf('sha256', $key, 32, 'aiwm-mysql-snapshot-v1', $salt);
    }

    private function identifier(string $name): string
    {
        if ($name === '' || strlen($name) > 64 || str_contains($name, "\0")) {
            throw new RuntimeException('Unsafe schema identifier.');
        }

        return '`'.str_replace('`', '``', $name).'`';
    }

    private function writeAll($stream, string $data): void
    {
        while ($data !== '') {
            $count = fwrite($stream, $data);
            if ($count === false || $count === 0) {
                throw new RuntimeException('Cannot finish writing encrypted database snapshot.');
            }
            $data = substr($data, $count);
        }
    }

    private function writeRecord($stream, string &$crypto, array $data, bool $final = false): void
    {
        $plain = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
            $crypto, $plain, '', $final
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
        );
        if (strlen($cipher) > self::MAX_RECORD) {
            throw new RuntimeException('Database snapshot row exceeds safe streaming limit.');
        }
        $this->writeAll($stream, pack('N', strlen($cipher)).$cipher);
    }

    private function readRecords(string $path, callable $consumer): void
    {
        $stream = fopen($path, 'rb');
        try {
            $prefix = fread($stream, 8);
            $salt = fread($stream, 16);
            $header = fread($stream, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if ($prefix !== self::MAGIC || strlen($salt) !== 16 ||
                strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new RuntimeException('Encrypted database snapshot header is invalid.');
            }
            $crypto = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key($salt));
            $finished = false;
            while (! feof($stream)) {
                $length = fread($stream, 4);
                if ($length === '') {
                    break;
                }
                if (strlen($length) !== 4) {
                    throw new RuntimeException('Truncated database snapshot record.');
                }
                $size = unpack('N', $length)[1];
                if ($size < 17 || $size > self::MAX_RECORD) {
                    throw new RuntimeException('Invalid encrypted record size.');
                }
                $cipher = '';
                while (strlen($cipher) < $size) {
                    $chunk = fread($stream, $size - strlen($cipher));
                    if ($chunk === false || $chunk === '') {
                        throw new RuntimeException('Truncated encrypted database backup.');
                    }
                    $cipher .= $chunk;
                }
                $decoded = sodium_crypto_secretstream_xchacha20poly1305_pull($crypto, $cipher);
                if ($decoded === false) {
                    throw new RuntimeException('Database backup authentication failed.');
                }
                [$plain, $tag] = $decoded;
                $record = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($record) || ! is_string($record['type'] ?? null)) {
                    throw new RuntimeException('Database backup record is invalid.');
                }
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
                $consumer($record, $final);
                if ($final) {
                    $finished = true;
                    if (fread($stream, 1) !== '') {
                        throw new RuntimeException('Trailing encrypted bytes in database backup.');
                    }
                    break;
                }
            }
            if (! $finished) {
                throw new RuntimeException('Database backup final authentication record missing.');
            }
        } finally {
            fclose($stream);
        }
    }
}
