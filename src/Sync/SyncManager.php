<?php

declare(strict_types=1);

namespace ThothWordPressPlugin\Sync;

use ThothWordPressPlugin\Import\WorkImporter;

final class SyncManager
{
    public const CRON_HOOK = 'thoth_data_connector_cron_sync';
    public const OPTION_LAST_SYNC = 'thoth_data_connector_last_sync';
    public const OPTION_SYNC_LOG = 'thoth_data_connector_sync_log';
    public const OPTION_SYNC_CURSOR = 'thoth_data_connector_sync_cursor';

    private WorkImporter $workImporter;

    public function __construct(?WorkImporter $workImporter = null)
    {
        $this->workImporter = $workImporter ?? new WorkImporter();
    }

    public function schedule(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 120, 'hourly', self::CRON_HOOK);
        }
    }

    public function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    public function run(string $source = 'manual'): array
    {
        $source = $source === 'cron' ? 'cron' : 'manual';
        $options = get_option(\ThothWordPressPlugin\Admin\SettingsPage::OPTION_NAME, []);
        $options = is_array($options) ? $options : [];
        $limit = isset($options['import_limit']) ? max(1, min(500, (int) $options['import_limit'])) : 100;

        $offset = 0;
        if ($source === 'cron') {
            $offset = max(0, (int) get_option(self::OPTION_SYNC_CURSOR, 0));
        }

        $result = $this->workImporter->importWorks($limit, $offset);
        $result['source'] = $source;
        $result['timestamp'] = gmdate('c');

        $nextOffset = $offset + (int) ($result['processed'] ?? 0);
        if (($result['processed'] ?? 0) < $limit) {
            $nextOffset = 0;
        }

        update_option(self::OPTION_SYNC_CURSOR, $nextOffset, false);
        update_option(self::OPTION_LAST_SYNC, $result, false);
        $this->appendLogEntry($result);

        return $result;
    }

    public function getLastSync(): array
    {
        $data = get_option(self::OPTION_LAST_SYNC, []);
        return is_array($data) ? $data : [];
    }

    public function getLog(int $maxEntries = 20): array
    {
        $log = get_option(self::OPTION_SYNC_LOG, []);
        if (!is_array($log)) {
            return [];
        }

        return array_slice($log, 0, max(1, $maxEntries));
    }

    private function appendLogEntry(array $entry): void
    {
        $log = get_option(self::OPTION_SYNC_LOG, []);
        $log = is_array($log) ? $log : [];

        array_unshift($log, [
            'timestamp' => (string) ($entry['timestamp'] ?? gmdate('c')),
            'source' => (string) ($entry['source'] ?? 'manual'),
            'created' => (int) ($entry['created'] ?? 0),
            'updated' => (int) ($entry['updated'] ?? 0),
            'skipped' => (int) ($entry['skipped'] ?? 0),
            'skipped_manual' => (int) ($entry['skipped_manual'] ?? 0),
            'errors' => (int) ($entry['errors'] ?? 0),
            'processed' => (int) ($entry['processed'] ?? 0),
            'offset' => (int) ($entry['offset'] ?? 0),
            'message' => (string) ($entry['message'] ?? ''),
        ]);

        $log = array_slice($log, 0, 50);
        update_option(self::OPTION_SYNC_LOG, $log, false);
    }
}
