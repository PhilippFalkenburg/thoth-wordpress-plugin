<?php

declare(strict_types=1);

namespace ThothWordPressPlugin\Admin;

use ThothWordPressPlugin\Import\WorkImporter;
use ThothWordPressPlugin\Sync\SyncManager;

final class SettingsPage
{
    public const OPTION_GROUP = 'thoth_data_connector_group';
    public const OPTION_NAME = 'thoth_data_connector_options';
    public const PAGE_SLUG = 'thoth-data-connector';
    public const MANUAL_SYNC_ACTION = 'thoth_sync_publications';
    public const MANUAL_SYNC_NONCE = 'thoth_manual_sync';
    public const UI_VISIBILITY_OPTION_KEY = 'ui_visibility';
    public const WORK_ID_PREFIX_OPTION_KEY = 'work_id_prefix';

    private const UI_VISIBILITY_FIELDS = [
        'fullTitle' => ['label' => 'Full Title', 'required' => true, 'group' => 'base'],
        'subtitle' => ['label' => 'Subtitle', 'required' => false, 'group' => 'base'],
        'edition' => ['label' => 'Edition', 'required' => false, 'group' => 'base'],
        'doi' => ['label' => 'DOI', 'required' => false, 'group' => 'base'],
        'imprint' => ['label' => 'Imprint', 'required' => true, 'group' => 'base'],
        'workType' => ['label' => 'Work Type', 'required' => true, 'group' => 'base'],
        'workStatus' => ['label' => 'Work Status', 'required' => true, 'group' => 'base'],
        'publicationDate' => ['label' => 'Publication Date', 'required' => false, 'group' => 'base'],
        'placeOfPublication' => ['label' => 'Place of Publication', 'required' => false, 'group' => 'base'],
        'coverUrl' => ['label' => 'Cover URL', 'required' => false, 'group' => 'base'],
        'cover' => ['label' => 'Cover (Media Picker)', 'required' => false, 'group' => 'base'],
        'coverCaption' => ['label' => 'Cover Caption', 'required' => false, 'group' => 'base'],
        'landingPage' => ['label' => 'Landing Page', 'required' => false, 'group' => 'base'],
        'license' => ['label' => 'License', 'required' => true, 'group' => 'base'],
        'copyrightHolder' => ['label' => 'Copyright Holder', 'required' => true, 'group' => 'base'],
        'lccn' => ['label' => 'LCCN', 'required' => false, 'group' => 'base'],
        'oclc' => ['label' => 'OCLC Number', 'required' => false, 'group' => 'base'],
        'internalReference' => ['label' => 'Internal Reference', 'required' => false, 'group' => 'base'],
        'pageCount' => ['label' => 'Page Count', 'required' => false, 'group' => 'base'],
        'pageBreakdown' => ['label' => 'Page Breakdown', 'required' => false, 'group' => 'base'],
        'pageRange' => ['label' => 'Page Range (First + Last Page)', 'required' => false, 'group' => 'base'],
        'imageCount' => ['label' => 'Image Count', 'required' => false, 'group' => 'base'],
        'tableCount' => ['label' => 'Table Count', 'required' => false, 'group' => 'base'],
        'audioCount' => ['label' => 'Audio Count', 'required' => false, 'group' => 'base'],
        'videoCount' => ['label' => 'Video Count', 'required' => false, 'group' => 'base'],
        'shortAbstract' => ['label' => 'Short Abstract', 'required' => false, 'group' => 'base'],
        'longAbstract' => ['label' => 'Long Abstract', 'required' => false, 'group' => 'base'],
        'generalNote' => ['label' => 'General Note', 'required' => false, 'group' => 'base'],
        'bibliographyNote' => ['label' => 'Bibliography Note', 'required' => false, 'group' => 'base'],
        'tableOfContents' => ['label' => 'Table of Contents', 'required' => false, 'group' => 'base'],
        'section_contributors' => ['label' => 'Contributors', 'required' => false, 'group' => 'sections'],
        'section_affiliations' => ['label' => 'Contributor Affiliations', 'required' => false, 'group' => 'sections'],
        'section_subjects' => ['label' => 'Subjects', 'required' => false, 'group' => 'sections'],
        'section_fundings' => ['label' => 'Fundings', 'required' => false, 'group' => 'sections'],
        'section_publications' => ['label' => 'Publications', 'required' => false, 'group' => 'sections'],
        'section_languages' => ['label' => 'Languages', 'required' => false, 'group' => 'sections'],
        'section_issues' => ['label' => 'Issues', 'required' => false, 'group' => 'sections'],
        'section_series' => ['label' => 'Series', 'required' => false, 'group' => 'sections'],
        'section_related_works' => ['label' => 'Related Works', 'required' => false, 'group' => 'sections'],
    ];

    private SyncManager $syncManager;

    public function __construct(?SyncManager $syncManager = null)
    {
        $this->syncManager = $syncManager ?? new SyncManager();
    }

    public function registerMenu(): void
    {
        add_options_page(
            __('Thoth Data Connector', 'thoth-data-connector'),
            __('Thoth Data', 'thoth-data-connector'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void
    {
        register_setting(self::OPTION_GROUP, self::OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitizeOptions'],
            'default' => $this->defaultOptions(),
        ]);

        add_settings_section(
            'thoth_data_connector_api_section',
            __('API-Konfiguration', 'thoth-data-connector'),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            'thoth_graphql_base_uri',
            __('GraphQL Base URI', 'thoth-data-connector'),
            [$this, 'renderGraphqlBaseUriField'],
            self::PAGE_SLUG,
            'thoth_data_connector_api_section'
        );

        add_settings_field(
            'thoth_api_token',
            __('API Token', 'thoth-data-connector'),
            [$this, 'renderApiTokenField'],
            self::PAGE_SLUG,
            'thoth_data_connector_api_section'
        );

        add_settings_field(
            'thoth_import_limit',
            __('Import Limit pro Lauf', 'thoth-data-connector'),
            [$this, 'renderImportLimitField'],
            self::PAGE_SLUG,
            'thoth_data_connector_api_section'
        );

        add_settings_field(
            'thoth_conflict_strategy',
            __('Konfliktstrategie (Import vs. manuell)', 'thoth-data-connector'),
            [$this, 'renderConflictStrategyField'],
            self::PAGE_SLUG,
            'thoth_data_connector_api_section'
        );

        add_settings_field(
            'thoth_work_id_prefix',
            __('Work ID Prefix', 'thoth-data-connector'),
            [$this, 'renderWorkIdPrefixField'],
            self::PAGE_SLUG,
            'thoth_data_connector_api_section'
        );

        add_settings_field(
            'thoth_ui_visibility',
            __('Feldsichtbarkeit im Editor (nur UI)', 'thoth-data-connector'),
            [$this, 'renderUiVisibilityField'],
            self::PAGE_SLUG,
            'thoth_data_connector_api_section'
        );
    }

    public function sanitizeOptions($input): array
    {
        $defaults = $this->defaultOptions();
        $input = is_array($input) ? $input : [];

        return [
            'graphql_base_uri' => isset($input['graphql_base_uri']) && $input['graphql_base_uri'] !== ''
                ? esc_url_raw($input['graphql_base_uri'])
                : $defaults['graphql_base_uri'],
            'api_token' => isset($input['api_token'])
                ? sanitize_text_field((string) $input['api_token'])
                : '',
            'import_limit' => isset($input['import_limit'])
                ? max(1, min(500, absint($input['import_limit'])))
                : $defaults['import_limit'],
            'conflict_strategy' => isset($input['conflict_strategy'])
                ? $this->sanitizeConflictStrategy((string) $input['conflict_strategy'])
                : $defaults['conflict_strategy'],
            self::WORK_ID_PREFIX_OPTION_KEY => isset($input[self::WORK_ID_PREFIX_OPTION_KEY])
                ? $this->sanitizeWorkIdPrefix((string) $input[self::WORK_ID_PREFIX_OPTION_KEY])
                : $defaults[self::WORK_ID_PREFIX_OPTION_KEY],
            self::UI_VISIBILITY_OPTION_KEY => self::normalizeUiVisibility($input[self::UI_VISIBILITY_OPTION_KEY] ?? []),
        ];
    }

    public static function normalizeUiVisibility($input): array
    {
        $input = is_array($input) ? $input : [];
        $normalized = self::defaultUiVisibility();

        foreach (self::UI_VISIBILITY_FIELDS as $key => $definition) {
            $isEnabled = isset($input[$key])
                ? (string) $input[$key] === '1'
                : ((string) ($normalized[$key] ?? '1') === '1');
            if (!empty($definition['required'])) {
                $isEnabled = true;
            }

            $normalized[$key] = $isEnabled ? '1' : '0';
        }

        return $normalized;
    }

    public function handleManualSync(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Nicht erlaubt.', 'thoth-data-connector'));
        }

        check_admin_referer(self::MANUAL_SYNC_NONCE);

        $result = $this->syncManager->run('manual');

        $queryArgs = [
            'page' => self::PAGE_SLUG,
            'sync_done' => 1,
            'sync_created' => (int) ($result['created'] ?? 0),
            'sync_updated' => (int) ($result['updated'] ?? 0),
            'sync_skipped' => (int) ($result['skipped'] ?? 0),
            'sync_errors' => (int) ($result['errors'] ?? 0),
            'sync_skipped_manual' => (int) ($result['skipped_manual'] ?? 0),
            'sync_processed' => (int) ($result['processed'] ?? 0),
            'sync_offset' => (int) ($result['offset'] ?? 0),
        ];

        if (!empty($result['message']) && is_string($result['message'])) {
            $queryArgs['sync_message'] = rawurlencode($result['message']);
        }

        $redirectUrl = add_query_arg($queryArgs, admin_url('options-general.php'));
        wp_safe_redirect($redirectUrl);
        exit;
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getOptions();
        $clientDetected = class_exists(\ThothApi\GraphQL\Client::class);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Thoth Data Connector', 'thoth-data-connector'); ?></h1>
            <p><?php echo esc_html__('Basis-Setup für die Weiterentwicklung des Thoth WordPress Plugins.', 'thoth-data-connector'); ?></p>

            <?php $this->renderSyncNotice(); ?>

            <table class="widefat" style="max-width:760px;margin:16px 0;">
                <tbody>
                    <tr>
                        <td style="width:220px;"><strong><?php echo esc_html__('thoth-client-php geladen', 'thoth-data-connector'); ?></strong></td>
                        <td><?php echo $clientDetected ? esc_html__('Ja', 'thoth-data-connector') : esc_html__('Nein', 'thoth-data-connector'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php echo esc_html__('Aktuelle GraphQL URI', 'thoth-data-connector'); ?></strong></td>
                        <td><code><?php echo esc_html($options['graphql_base_uri']); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong><?php echo esc_html__('CPT verfügbar', 'thoth-data-connector'); ?></strong></td>
                        <td><?php echo post_type_exists('thoth_publication') ? esc_html__('Ja', 'thoth-data-connector') : esc_html__('Nein', 'thoth-data-connector'); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php echo esc_html__('Letzte Synchronisierung', 'thoth-data-connector'); ?></strong></td>
                        <td><?php echo esc_html($this->getLastSyncText()); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php echo esc_html__('Nächster Cron-Lauf', 'thoth-data-connector'); ?></strong></td>
                        <td><?php echo esc_html($this->getNextCronRunText()); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php echo esc_html__('Konfliktstrategie', 'thoth-data-connector'); ?></strong></td>
                        <td><?php echo esc_html($this->describeConflictStrategy((string) $options['conflict_strategy'])); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php echo esc_html__('Work ID Prefix', 'thoth-data-connector'); ?></strong></td>
                        <td><code><?php echo esc_html((string) ($options[self::WORK_ID_PREFIX_OPTION_KEY] ?? '')); ?></code></td>
                    </tr>
                </tbody>
            </table>

            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::PAGE_SLUG);
                submit_button(__('Speichern', 'thoth-data-connector'));
                ?>
            </form>

            <hr style="margin:24px 0;" />
            <h2><?php echo esc_html__('Manueller Import', 'thoth-data-connector'); ?></h2>
            <p><?php echo esc_html__('Importiert Publikationen aus Thoth als Beiträge vom Typ "Thoth Publication".', 'thoth-data-connector'); ?></p>
            <?php $this->renderManualSyncForm(); ?>

            <h2 style="margin-top:24px;"><?php echo esc_html__('Sync-Log', 'thoth-data-connector'); ?></h2>
            <?php $this->renderSyncLog(); ?>
        </div>
        <?php
    }

    public function renderGraphqlBaseUriField(): void
    {
        $options = $this->getOptions();
        ?>
        <input
            type="url"
            class="regular-text"
            name="<?php echo esc_attr(self::OPTION_NAME); ?>[graphql_base_uri]"
            value="<?php echo esc_attr($options['graphql_base_uri']); ?>"
            placeholder="https://api.thoth.pub/"
        />
        <?php
    }

    public function renderApiTokenField(): void
    {
        $options = $this->getOptions();
        ?>
        <input
            type="password"
            class="regular-text"
            name="<?php echo esc_attr(self::OPTION_NAME); ?>[api_token]"
            value="<?php echo esc_attr($options['api_token']); ?>"
            autocomplete="off"
        />
        <?php
    }

    public function renderImportLimitField(): void
    {
        $options = $this->getOptions();
        ?>
        <input
            type="number"
            class="small-text"
            min="1"
            max="500"
            name="<?php echo esc_attr(self::OPTION_NAME); ?>[import_limit]"
            value="<?php echo esc_attr((string) $options['import_limit']); ?>"
        />
        <?php
    }

    public function renderConflictStrategyField(): void
    {
        $options = $this->getOptions();
        $value = (string) $options['conflict_strategy'];
        ?>
        <select name="<?php echo esc_attr(self::OPTION_NAME); ?>[conflict_strategy]">
            <option value="<?php echo esc_attr(WorkImporter::CONFLICT_STRATEGY_KEEP_MANUAL); ?>" <?php selected($value, WorkImporter::CONFLICT_STRATEGY_KEEP_MANUAL); ?>>
                <?php echo esc_html__('Manuelle Änderungen schützen (empfohlen)', 'thoth-data-connector'); ?>
            </option>
            <option value="<?php echo esc_attr(WorkImporter::CONFLICT_STRATEGY_OVERWRITE); ?>" <?php selected($value, WorkImporter::CONFLICT_STRATEGY_OVERWRITE); ?>>
                <?php echo esc_html__('Import darf manuelle Änderungen überschreiben', 'thoth-data-connector'); ?>
            </option>
        </select>
        <?php
    }

    public function renderWorkIdPrefixField(): void
    {
        $options = $this->getOptions();
        $prefix = (string) ($options[self::WORK_ID_PREFIX_OPTION_KEY] ?? '');
        ?>
        <input
            type="text"
            class="regular-text"
            name="<?php echo esc_attr(self::OPTION_NAME); ?>[<?php echo esc_attr(self::WORK_ID_PREFIX_OPTION_KEY); ?>]"
            value="<?php echo esc_attr($prefix); ?>"
            placeholder="OR"
        />
        <p class="description">
            <?php echo esc_html__('Optional. New Work IDs are generated automatically as PREFIX:number (for example OR:1). Leave empty to use plain numbers.', 'thoth-data-connector'); ?>
        </p>
        <?php
    }

    public function renderUiVisibilityField(): void
    {
        $options = $this->getOptions();
        $visibility = isset($options[self::UI_VISIBILITY_OPTION_KEY]) && is_array($options[self::UI_VISIBILITY_OPTION_KEY])
            ? $options[self::UI_VISIBILITY_OPTION_KEY]
            : self::normalizeUiVisibility([]);

        echo '<p class="description">'
            . esc_html__('Steuert nur die Sichtbarkeit im Editor. Versteckte Felder verändern den Datenbestand nicht.', 'thoth-data-connector')
            . '</p>';

        foreach (['base' => __('Basisfelder', 'thoth-data-connector'), 'sections' => __('Nested Sections', 'thoth-data-connector')] as $groupKey => $groupLabel) {
            echo '<p><strong>' . esc_html($groupLabel) . '</strong></p>';
            echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(260px,1fr));gap:6px 16px;max-width:780px;">';

            foreach (self::UI_VISIBILITY_FIELDS as $key => $definition) {
                if (($definition['group'] ?? '') !== $groupKey) {
                    continue;
                }

                $required = !empty($definition['required']);
                $checked = isset($visibility[$key]) ? (string) $visibility[$key] === '1' : true;

                echo '<label style="display:flex;align-items:center;gap:8px;">';
                if (!$required) {
                    echo '<input type="hidden" name="' . esc_attr(self::OPTION_NAME) . '[' . esc_attr(self::UI_VISIBILITY_OPTION_KEY) . '][' . esc_attr($key) . ']" value="0" />';
                }
                echo '<input type="checkbox" name="' . esc_attr(self::OPTION_NAME) . '[' . esc_attr(self::UI_VISIBILITY_OPTION_KEY) . '][' . esc_attr($key) . ']" value="1" ' . checked($checked, true, false) . ($required ? ' disabled="disabled"' : '') . ' />';
                echo '<span>' . esc_html((string) ($definition['label'] ?? $key));
                if ($required) {
                    echo ' <em>(' . esc_html__('Pflichtfeld', 'thoth-data-connector') . ')</em>';
                    echo '<input type="hidden" name="' . esc_attr(self::OPTION_NAME) . '[' . esc_attr(self::UI_VISIBILITY_OPTION_KEY) . '][' . esc_attr($key) . ']" value="1" />';
                }
                echo '</span>';
                echo '</label>';
            }

            echo '</div>';
        }
    }

    private function getOptions(): array
    {
        $options = get_option(self::OPTION_NAME, []);
        return array_merge($this->defaultOptions(), is_array($options) ? $options : []);
    }

    private function defaultOptions(): array
    {
        return [
            'graphql_base_uri' => 'https://api.thoth.pub/',
            'api_token' => '',
            'import_limit' => 100,
            'conflict_strategy' => WorkImporter::CONFLICT_STRATEGY_KEEP_MANUAL,
            self::WORK_ID_PREFIX_OPTION_KEY => '',
            self::UI_VISIBILITY_OPTION_KEY => self::defaultUiVisibility(),
        ];
    }

    private static function defaultUiVisibility(): array
    {
        $visibility = [];
        foreach (self::UI_VISIBILITY_FIELDS as $key => $definition) {
            $visibility[$key] = !empty($definition['required']) ? '1' : '1';
        }

        return $visibility;
    }

    private function getSyncLimit(): int
    {
        $options = $this->getOptions();
        return max(1, min(500, (int) $options['import_limit']));
    }

    private function renderManualSyncForm(): void
    {
        $actionUrl = admin_url('admin-post.php');
        ?>
        <form method="post" action="<?php echo esc_url($actionUrl); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::MANUAL_SYNC_ACTION); ?>" />
            <?php wp_nonce_field(self::MANUAL_SYNC_NONCE); ?>
            <?php submit_button(__('Jetzt synchronisieren', 'thoth-data-connector'), 'secondary', 'submit', false); ?>
        </form>
        <?php
    }

    private function renderSyncNotice(): void
    {
        if (!isset($_GET['sync_done'])) {
            return;
        }

        $created = isset($_GET['sync_created']) ? absint(wp_unslash($_GET['sync_created'])) : 0;
        $updated = isset($_GET['sync_updated']) ? absint(wp_unslash($_GET['sync_updated'])) : 0;
        $skipped = isset($_GET['sync_skipped']) ? absint(wp_unslash($_GET['sync_skipped'])) : 0;
        $skippedManual = isset($_GET['sync_skipped_manual']) ? absint(wp_unslash($_GET['sync_skipped_manual'])) : 0;
        $errors = isset($_GET['sync_errors']) ? absint(wp_unslash($_GET['sync_errors'])) : 0;

        $message = sprintf(
            __('Sync abgeschlossen. Neu: %1$d, Aktualisiert: %2$d, Unverändert: %3$d (davon manuell geschützt: %4$d), Fehler: %5$d, Verarbeitet: %6$d (Offset: %7$d).', 'thoth-data-connector'),
            $created,
            $updated,
            $skipped,
            $skippedManual,
            $errors,
            isset($_GET['sync_processed']) ? absint(wp_unslash($_GET['sync_processed'])) : 0,
            isset($_GET['sync_offset']) ? absint(wp_unslash($_GET['sync_offset'])) : 0
        );

        if (isset($_GET['sync_message']) && $_GET['sync_message'] !== '') {
            $detail = rawurldecode((string) wp_unslash($_GET['sync_message']));
            $message .= ' ' . $detail;
        }

        $noticeClass = $errors > 0 ? 'notice notice-warning' : 'notice notice-success';
        ?>
        <div class="<?php echo esc_attr($noticeClass); ?>"><p><?php echo esc_html($message); ?></p></div>
        <?php
    }

    private function getLastSyncText(): string
    {
        $lastSync = $this->syncManager->getLastSync();
        $timestamp = isset($lastSync['timestamp']) ? (string) $lastSync['timestamp'] : '';

        if ($timestamp === '') {
            return (string) __('Noch nie', 'thoth-data-connector');
        }

        $source = isset($lastSync['source']) ? (string) $lastSync['source'] : 'manual';
        return sprintf('%s (%s)', $timestamp, $source);
    }

    private function getNextCronRunText(): string
    {
        $timestamp = wp_next_scheduled(SyncManager::CRON_HOOK);
        if (!$timestamp) {
            return (string) __('Kein Cron geplant', 'thoth-data-connector');
        }

        return gmdate('c', (int) $timestamp);
    }

    private function renderSyncLog(): void
    {
        $entries = $this->syncManager->getLog(10);
        if (empty($entries)) {
            echo '<p>' . esc_html__('Noch keine Synchronisierung protokolliert.', 'thoth-data-connector') . '</p>';
            return;
        }
        ?>
        <table class="widefat" style="max-width:960px;">
            <thead>
                <tr>
                    <th><?php echo esc_html__('Zeit', 'thoth-data-connector'); ?></th>
                    <th><?php echo esc_html__('Quelle', 'thoth-data-connector'); ?></th>
                    <th><?php echo esc_html__('Neu', 'thoth-data-connector'); ?></th>
                    <th><?php echo esc_html__('Aktualisiert', 'thoth-data-connector'); ?></th>
                    <th><?php echo esc_html__('Unverändert', 'thoth-data-connector'); ?></th>
                    <th><?php echo esc_html__('Manuell geschützt', 'thoth-data-connector'); ?></th>
                    <th><?php echo esc_html__('Fehler', 'thoth-data-connector'); ?></th>
                    <th><?php echo esc_html__('Verarbeitet', 'thoth-data-connector'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $entry) : ?>
                    <tr>
                        <td><?php echo esc_html((string) ($entry['timestamp'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($entry['source'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) (int) ($entry['created'] ?? 0)); ?></td>
                        <td><?php echo esc_html((string) (int) ($entry['updated'] ?? 0)); ?></td>
                        <td><?php echo esc_html((string) (int) ($entry['skipped'] ?? 0)); ?></td>
                        <td><?php echo esc_html((string) (int) ($entry['skipped_manual'] ?? 0)); ?></td>
                        <td><?php echo esc_html((string) (int) ($entry['errors'] ?? 0)); ?></td>
                        <td><?php echo esc_html((string) (int) ($entry['processed'] ?? 0)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function sanitizeConflictStrategy(string $value): string
    {
        return in_array($value, [WorkImporter::CONFLICT_STRATEGY_KEEP_MANUAL, WorkImporter::CONFLICT_STRATEGY_OVERWRITE], true)
            ? $value
            : WorkImporter::CONFLICT_STRATEGY_KEEP_MANUAL;
    }

    private function sanitizeWorkIdPrefix(string $value): string
    {
        $prefix = trim(sanitize_text_field($value));
        $prefix = str_replace(':', '', $prefix);
        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', $prefix) ?? '';

        return substr($prefix, 0, 24);
    }

    private function describeConflictStrategy(string $value): string
    {
        return $value === WorkImporter::CONFLICT_STRATEGY_OVERWRITE
            ? (string) __('Import überschreibt manuelle Änderungen', 'thoth-data-connector')
            : (string) __('Manuelle Änderungen werden geschützt', 'thoth-data-connector');
    }
}
