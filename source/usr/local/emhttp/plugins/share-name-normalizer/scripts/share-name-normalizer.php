<?php
declare(strict_types=1);

final class ShareNameNormalizer
{
    private const SHARES_INI = '/var/local/emhttp/shares.ini';
    private const VAR_INI = '/var/local/emhttp/var.ini';
    private const DISKS_INI = '/var/local/emhttp/disks.ini';
    private const BACKUP_ROOT = '/boot/config/plugins/share-name-normalizer/backups';

    public function status(): array
    {
        $shares = $this->readShares();
        $rows = [];
        $plan = [];
        $errors = [];
        $targets = [];
        $existing = [];

        foreach (array_keys($shares) as $name) {
            $existing[strtolower((string)$name)] = (string)$name;
        }

        foreach ($shares as $name => $settings) {
            $name = (string)$name;
            $target = $this->normalize($name);
            $error = '';
            $skip = '';
            if ($target !== $name) {
                if (!preg_match('/^[a-z]/', $target)) {
                    $skip = 'Skipped: Unraid requires a renamed share to begin with a letter.';
                } else {
                    $error = $this->validateTarget($target);
                    $key = strtolower($target);
                    if ($error === '' && isset($targets[$key])) {
                        $error = 'Collision: this would duplicate the target for "' . $targets[$key] . '".';
                    }
                    if ($error === '' && isset($existing[$key]) && strtolower($name) !== $key) {
                        $error = 'Collision: a share named "' . $existing[$key] . '" already exists.';
                    }
                    $targets[$key] = $name;
                    if ($error === '') {
                        $plan[] = $this->planItem($name, $target, (array)$settings, $existing);
                    } else {
                        $errors[] = $name . ': ' . $error;
                    }
                }
            }
            $rows[] = ['name' => $name, 'target' => $target, 'error' => $error, 'skip' => $skip];
        }

        $reserved = $this->reservedNames();
        foreach ($plan as $index => $item) {
            $targetKey = strtolower($item['target']);
            if (isset($reserved[$targetKey])) {
                $message = $item['source'] . ': target name "' . $item['target'] . '" is reserved by Unraid or matches a storage pool.';
                $errors[] = $message;
                foreach ($rows as &$row) {
                    if ($row['name'] === $item['source']) {
                        $row['error'] = 'Target is reserved by Unraid or matches a storage pool.';
                    }
                }
                unset($row);
                unset($plan[$index]);
            }
        }
        $plan = array_values($plan);

        $unchanged = count(array_filter($rows, static function (array $row): bool {
            return $row['name'] === $row['target'];
        }));
        $skipped = count(array_filter($rows, static function (array $row): bool {
            return $row['skip'] !== '';
        }));

        return [
            'counts' => ['total' => count($shares), 'change' => count($plan), 'unchanged' => $unchanged, 'skipped' => $skipped],
            'shares' => $rows,
            'plan' => $plan,
            'errors' => array_values(array_unique($errors)),
            'blockers' => $this->blockers(),
        ];
    }

    public function prepare(): array
    {
        $status = $this->status();
        if ($status['errors']) {
            throw new RuntimeException('Resolve the naming errors shown in the preview first.');
        }
        if ($status['blockers']) {
            throw new RuntimeException(implode(' ', $status['blockers']));
        }
        if (!$status['plan']) {
            throw new RuntimeException('No share names need changing.');
        }
        $backup = $this->createBackup($status);
        return ['plan' => $status['plan'], 'backup' => $backup];
    }

    public function locate(array $names): array
    {
        $shares = $this->readShares();
        $keys = [];
        foreach (array_keys($shares) as $name) {
            $keys[(string)$name] = true;
        }
        $result = [];
        foreach ($names as $name) {
            $name = (string)$name;
            $result[$name] = isset($keys[$name]);
        }
        return $result;
    }

    private function normalize(string $name): string
    {
        return str_replace(' ', '-', strtolower($name));
    }

    private function validateTarget(string $target): string
    {
        if ($target === '') return 'The normalized name would be empty.';
        if (strlen($target) > 40) return 'The normalized name exceeds Unraid\'s 40-character limit.';
        if (!preg_match('/^[a-z]/', $target)) return 'The normalized name must begin with a letter.';
        if (!preg_match('/^[a-z0-9._-]+$/', $target)) return 'The normalized name contains a character Unraid does not allow when renaming.';
        if (substr($target, -1) === '.') return 'The normalized name cannot end with a period.';
        return '';
    }

    private function planItem(string $source, string $target, array $share, array $existing): array
    {
        $temp = $this->uniqueInternalName('snr-', $source, $existing);
        $existing[strtolower($temp)] = $temp;
        $rollback = $this->uniqueInternalName('snb-', $source, $existing);
        return [
            'source' => $source,
            'target' => $target,
            'temp' => $temp,
            'rollback' => $rollback,
            'settings' => [
                'shareComment' => (string)($share['comment'] ?? ''),
                'shareAllocator' => (string)($share['allocator'] ?? 'highwater'),
                'shareFloor' => (string)($share['floor'] ?? ''),
                'shareSplitLevel' => (string)($share['splitLevel'] ?? ''),
                'shareInclude' => (string)($share['include'] ?? ''),
                'shareExclude' => (string)($share['exclude'] ?? ''),
                'shareUseCache' => (string)($share['useCache'] ?? ''),
                'shareCachePool' => (string)($share['cachePool'] ?? ''),
                'shareCachePool2' => (string)($share['cachePool2'] ?? ''),
            ],
        ];
    }

    private function uniqueInternalName(string $prefix, string $source, array $existing): string
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $candidate = $prefix . substr(hash('sha256', $source . ':' . $attempt), 0, 12);
            if (!isset($existing[strtolower($candidate)])) return $candidate;
        }
        throw new RuntimeException('Could not allocate a safe temporary share name.');
    }

    private function readShares(): array
    {
        $parsed = @parse_ini_file(self::SHARES_INI, true, INI_SCANNER_RAW);
        if (!is_array($parsed)) {
            throw new RuntimeException('Could not read the current Unraid user shares. Make sure the array is started.');
        }

        // Unraid releases differ in how shares.ini sections are indexed. Some
        // use the share name as the section key, while others expose an index
        // and put the authoritative name in the record's `name` field.
        $shares = [];
        foreach ($parsed as $section => $record) {
            if (!is_array($record)) continue;
            $recordName = trim((string)($record['name'] ?? ''));
            $name = $recordName !== '' ? $recordName : trim((string)$section);
            if ($name === '') continue;
            $shares[$name] = $record;
        }
        ksort($shares, SORT_NATURAL | SORT_FLAG_CASE);
        return $shares;
    }

    private function reservedNames(): array
    {
        $result = [];
        $var = @parse_ini_file(self::VAR_INI, false, INI_SCANNER_RAW);
        if (is_array($var)) {
            foreach (explode(',', (string)($var['reservedNames'] ?? '')) as $name) {
                $name = trim($name);
                if ($name !== '') $result[strtolower($name)] = true;
            }
        }
        $disks = @parse_ini_file(self::DISKS_INI, true, INI_SCANNER_RAW);
        if (is_array($disks)) {
            foreach ($disks as $name => $disk) {
                if (strpos((string)$name, 'disk') !== 0 && $name !== 'parity' && $name !== 'parity2' && $name !== 'flash') {
                    $result[strtolower((string)$name)] = true;
                }
            }
        }
        return $result;
    }

    private function blockers(): array
    {
        $blockers = [];
        $var = @parse_ini_file(self::VAR_INI, false, INI_SCANNER_RAW);
        if (!is_array($var) || strtoupper((string)($var['mdState'] ?? '')) !== 'STARTED') {
            $blockers[] = 'Start the array before renaming shares.';
        }
        if ($this->processRunning(['dockerd'])) {
            $blockers[] = 'Stop the Docker service under Settings → Docker.';
        }
        if ($this->processRunning(['libvirtd', 'virtqemud'])) {
            $blockers[] = 'Stop the VM service under Settings → VM Manager.';
        }
        if ($this->processRunning(['mover'])) {
            $blockers[] = 'Wait for the mover to finish.';
        }
        return $blockers;
    }

    private function processRunning(array $names): bool
    {
        foreach (glob('/proc/[0-9]*/comm') ?: [] as $file) {
            $name = trim((string)@file_get_contents($file));
            if (in_array($name, $names, true)) return true;
        }
        return false;
    }

    private function createBackup(array $status): string
    {
        if (!is_dir(self::BACKUP_ROOT) && !@mkdir(self::BACKUP_ROOT, 0755, true) && !is_dir(self::BACKUP_ROOT)) {
            throw new RuntimeException('Could not create the backup directory on the flash drive.');
        }
        $stamp = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $directory = self::BACKUP_ROOT . '/' . $stamp;
        if (!@mkdir($directory, 0755, true)) {
            throw new RuntimeException('Could not create the configuration backup.');
        }
        foreach (glob('/boot/config/shares/*.cfg') ?: [] as $file) {
            if (!@copy($file, $directory . '/' . basename($file))) {
                throw new RuntimeException('Could not back up ' . basename($file) . '.');
            }
        }
        $manifest = [
            'created_utc' => gmdate('c'),
            'warning' => 'Configuration snapshot from before share-name normalization. Restoring files alone does not rename data directories.',
            'plan' => $status['plan'],
        ];
        if (@file_put_contents($directory . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            throw new RuntimeException('Could not write the backup manifest.');
        }
        return $directory;
    }
}
