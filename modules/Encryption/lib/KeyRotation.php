<?php

require_once __DIR__ . '/Crypto.php';
require_once __DIR__ . '/../../AuditLog/lib/AuditChain.php';
require_once __DIR__ . '/../../AuditLog/lib/AuditLogger.php';

/**
 * Changes the encryption key (APP_AES_KEY), for db/rotate_aes_key.php. Every encrypted value (each
 * *_enc column) is decrypted with the old key and encrypted again with the new one, and the audit log is
 * re-sealed, since its seals are built from the key too (modules/AuditLog/lib/AuditChain.php).
 *
 * rotate() runs inside the caller's transaction, so the caller decides whether it is kept: the dry run
 * rolls it back. Nothing is changed when a value cannot be read with the old key, when an encrypted value
 * sits in a column not named *_enc, or when the audit log does not check out with the old key
 * (re-sealing would hide that). Before rotate() returns, every value is read back with the new key and
 * compared with what it was, and the audit log is checked with the new key.
 */
class KeyRotation
{
    /** Whether $base64 is a usable key: base64 of exactly 32 bytes. */
    public static function validKey(string $base64): bool
    {
        $raw = base64_decode($base64, true);
        return $raw !== false && strlen($raw) === 32;
    }

    /** A short fingerprint to tell keys apart in the output without showing them. */
    public static function fingerprint(string $base64): string
    {
        return substr(hash('sha256', 'ProfilePath key fingerprint|' . $base64), 0, 12);
    }

    /** Makes $base64 the APP_AES_KEY for the rest of this process. */
    public static function useKey(string $base64): void
    {
        $_ENV['APP_AES_KEY'] = $base64;
        putenv('APP_AES_KEY=' . $base64);
        Crypto::forgetKey();
    }

    /**
     * Every encrypted column: the text columns named *_enc, each with its table's primary key.
     *
     * @return list<array{table:string,column:string,key:string}>
     */
    public static function encryptedColumns(PDO $pdo): array
    {
        $columns = $pdo->query(
            "SELECT table_name, column_name FROM information_schema.columns
             WHERE table_schema = current_schema() AND column_name LIKE '%\\_enc'
               AND data_type IN ('text', 'character varying')
             ORDER BY table_name, column_name"
        )->fetchAll(PDO::FETCH_ASSOC);
        $keys = $pdo->prepare(
            "SELECT k.column_name FROM information_schema.table_constraints t
             JOIN information_schema.key_column_usage k ON k.constraint_name = t.constraint_name AND k.table_schema = t.table_schema
             WHERE t.table_schema = current_schema() AND t.table_name = ? AND t.constraint_type = 'PRIMARY KEY'"
        );
        $out = [];
        foreach ($columns as $c) {
            $keys->execute([$c['table_name']]);
            $key = $keys->fetchAll(PDO::FETCH_COLUMN);
            if (count($key) !== 1) {
                throw new RuntimeException("Table {$c['table_name']} has no one-column primary key, so its rows cannot be updated one by one.");
            }
            $out[] = ['table' => $c['table_name'], 'column' => $c['column_name'], 'key' => $key[0]];
        }
        return $out;
    }

    /**
     * Rotates every encrypted value and the audit log seals from $oldKey to $newKey. Leaves APP_AES_KEY
     * set to $newKey in this process.
     *
     * @return array{columns:list<array{table:string,column:string,values:int}>,values:int,auditSealed:int}
     */
    public static function rotate(PDO $pdo, string $oldKey, string $newKey): array
    {
        if (!$pdo->inTransaction()) {
            throw new LogicException('KeyRotation::rotate() must run inside a transaction.');
        }
        if (!self::validKey($oldKey) || !self::validKey($newKey)) {
            throw new InvalidArgumentException('Both keys must be base64 of exactly 32 bytes.');
        }
        if (base64_decode($oldKey, true) === base64_decode($newKey, true)) {
            throw new InvalidArgumentException('The new key is the same as the current one.');
        }

        $columns = self::encryptedColumns($pdo);
        // Writes wait (reads go on) until the transaction ends, so nothing is written with the old key meanwhile.
        $tables = array_values(array_unique([...array_column($columns, 'table'), 'audit_log', 'security_policies']));
        $pdo->exec('LOCK TABLE ' . implode(', ', array_map(fn($t) => '"' . $t . '"', $tables)) . ' IN SHARE ROW EXCLUSIVE MODE');

        self::useKey($oldKey);
        if (!self::keyFits($pdo, $columns)) {
            throw new RuntimeException('APP_AES_KEY cannot read this database\'s encrypted data: it is not the key the data was saved with (or this rotation was already done).');
        }
        $stray = self::strayEncrypted($pdo);
        if ($stray) {
            throw new RuntimeException('Encrypted values found in columns not named *_enc, which would not be rotated: ' . implode(', ', $stray) . '.');
        }
        $chained = AuditChain::enabled($pdo);
        $start = AuditChain::ZERO;
        if ($chained) {
            $check = AuditChain::verify($pdo);
            if (!$check['intact']) {
                throw new RuntimeException("The audit log does not check out with the current key (entry #{$check['brokenId']}: {$check['problem']}). Re-sealing it would hide that.");
            }
            $start = AuditChain::anchor($pdo);
        }

        // Read everything with the old key first, so an unreadable value stops the rotation before any change.
        $plain = [];
        $unreadable = [];
        foreach ($columns as $i => $c) {
            $rows = $pdo->query(
                "SELECT \"{$c['key']}\", \"{$c['column']}\" FROM \"{$c['table']}\" WHERE \"{$c['column']}\" IS NOT NULL AND \"{$c['column']}\" <> ''"
            )->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($rows as $id => $blob) {
                try {
                    $plain[$i][$id] = Crypto::dec((string) $blob);
                } catch (Throwable $e) {
                    $unreadable[] = "{$c['table']}.{$c['column']} #$id";
                }
            }
        }
        if ($unreadable) {
            throw new RuntimeException(count($unreadable) . ' value(s) cannot be read with the current key: '
                . implode(', ', array_slice($unreadable, 0, 20)) . (count($unreadable) > 20 ? ', ...' : '') . '.');
        }

        self::useKey($newKey);
        $report = [];
        $total = 0;
        foreach ($columns as $i => $c) {
            $update = $pdo->prepare("UPDATE \"{$c['table']}\" SET \"{$c['column']}\" = ? WHERE \"{$c['key']}\" = ?");
            foreach ($plain[$i] ?? [] as $id => $text) {
                $update->execute([Crypto::enc($text), $id]);
            }
            $report[] = ['table' => $c['table'], 'column' => $c['column'], 'values' => count($plain[$i] ?? [])];
            $total += count($plain[$i] ?? []);
        }
        // The seals cover detail_enc, so they are made after it was re-encrypted.
        $sealed = $chained ? AuditChain::resealAll($pdo, $start) : 0;
        AuditLogger::log(null, null, 'rotate_encryption_key', 'system', null,
            "Re-encrypted $total values in " . count($columns) . " columns and re-sealed $sealed audit log entries with a new key.");

        // Read everything back with the new key: each value must decrypt to exactly what it was.
        foreach ($columns as $i => $c) {
            $rows = $pdo->query(
                "SELECT \"{$c['key']}\", \"{$c['column']}\" FROM \"{$c['table']}\" WHERE \"{$c['column']}\" IS NOT NULL AND \"{$c['column']}\" <> ''"
            )->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($plain[$i] ?? [] as $id => $text) {
                if (!isset($rows[$id]) || Crypto::dec((string) $rows[$id]) !== $text) {
                    throw new RuntimeException("{$c['table']}.{$c['column']} #$id does not read back correctly with the new key.");
                }
            }
        }
        if ($chained) {
            $check = AuditChain::verify($pdo);
            if (!$check['intact']) {
                throw new RuntimeException("The re-sealed audit log does not check out with the new key (entry #{$check['brokenId']}: {$check['problem']}).");
            }
        }

        return ['columns' => $report, 'values' => $total, 'auditSealed' => $sealed];
    }

    /**
     * Whether the current key reads the first stored value of each encrypted column (true when there is
     * nothing encrypted yet). Catches the wrong key before the slower checks.
     *
     * @param list<array{table:string,column:string,key:string}> $columns
     */
    private static function keyFits(PDO $pdo, array $columns): bool
    {
        foreach ($columns as $c) {
            $blob = $pdo->query(
                "SELECT \"{$c['column']}\" FROM \"{$c['table']}\" WHERE \"{$c['column']}\" IS NOT NULL AND \"{$c['column']}\" <> '' LIMIT 1"
            )->fetchColumn();
            if ($blob === false) {
                continue;
            }
            try {
                Crypto::dec((string) $blob);
                return true;
            } catch (Throwable $e) {
                // try the next column: one damaged value is reported later, by name
            }
        }
        foreach ($columns as $c) {
            if ($pdo->query("SELECT 1 FROM \"{$c['table']}\" WHERE \"{$c['column']}\" IS NOT NULL AND \"{$c['column']}\" <> '' LIMIT 1")->fetchColumn()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Text columns not named *_enc that hold a value the current key decrypts (such a value would be left
     * on the old key and become unreadable).
     *
     * @return list<string> "table.column"
     */
    private static function strayEncrypted(PDO $pdo): array
    {
        $columns = $pdo->query(
            "SELECT table_name, column_name FROM information_schema.columns
             WHERE table_schema = current_schema() AND column_name NOT LIKE '%\\_enc'
               AND data_type IN ('text', 'character varying')
             ORDER BY table_name, column_name"
        )->fetchAll(PDO::FETCH_ASSOC);
        $found = [];
        foreach ($columns as $c) {
            // A Crypto value is base64 of at least version + IV + one block (33 bytes -> 44 characters).
            $values = $pdo->query(
                "SELECT \"{$c['column_name']}\" FROM \"{$c['table_name']}\"
                 WHERE length(\"{$c['column_name']}\") >= 44 AND \"{$c['column_name']}\" ~ '^[A-Za-z0-9+/]+={0,2}$'"
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($values as $v) {
                try {
                    Crypto::dec((string) $v);
                    $found[] = "{$c['table_name']}.{$c['column_name']}";
                    break;
                } catch (Throwable $e) {
                    // not encrypted with this key
                }
            }
        }
        return $found;
    }
}
