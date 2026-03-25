<?php

declare(strict_types=1);

namespace ThothWordPressPlugin\Import;

use ThothApi\GraphQL\Client as GraphQLClient;
use ThothWordPressPlugin\Admin\SettingsPage;
use ThothWordPressPlugin\Metadata\NestedMetadataMapper;
use ThothWordPressPlugin\PostType\PublicationPostType;
use Throwable;

final class WorkImporter
{
    public const CONFLICT_STRATEGY_KEEP_MANUAL = 'keep_manual';
    public const CONFLICT_STRATEGY_OVERWRITE = 'overwrite';

    private NestedMetadataMapper $nestedMetadataMapper;

    public function __construct(?NestedMetadataMapper $nestedMetadataMapper = null)
    {
        $this->nestedMetadataMapper = $nestedMetadataMapper ?? new NestedMetadataMapper();
    }

    public function importWorks(int $limit = 50, int $offset = 0): array
    {
        $result = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'skipped_manual' => 0,
            'errors' => 0,
            'processed' => 0,
            'limit' => 0,
            'offset' => 0,
            'message' => '',
        ];

        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $result['limit'] = $limit;
        $result['offset'] = $offset;

        try {
            $client = $this->buildClient();
            $works = $client->works([
                'limit' => $limit,
                'offset' => $offset,
            ]);
        } catch (Throwable $throwable) {
            $result['errors']++;
            $result['message'] = $throwable->getMessage();
            return $result;
        }

        foreach ($works as $work) {
            $result['processed']++;
            try {
                $payload = $work->getAllData();
                $workId = (string) ($payload['workId'] ?? '');

                if ($workId === '') {
                    $result['errors']++;
                    continue;
                }

                $payloadJson = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($payloadJson)) {
                    $result['errors']++;
                    continue;
                }

                $checksum = md5($payloadJson);
                $existingPostId = $this->findByWorkId($workId);

                if ($existingPostId > 0) {
                    $existingChecksum = (string) get_post_meta($existingPostId, '_thoth_checksum', true);
                    $conflictStrategy = $this->getConflictStrategy();
                    $isManualOverride = (string) get_post_meta($existingPostId, '_thoth_manual_override', true) === '1';

                    if ($isManualOverride && $conflictStrategy === self::CONFLICT_STRATEGY_KEEP_MANUAL) {
                        update_post_meta($existingPostId, '_thoth_import_payload', $payloadJson);
                        update_post_meta($existingPostId, '_thoth_import_checksum', $checksum);
                        update_post_meta($existingPostId, '_thoth_sync_state', 'manual_override_remote_update');
                        update_post_meta($existingPostId, '_thoth_last_synced_at', gmdate('c'));
                        $result['skipped']++;
                        $result['skipped_manual']++;
                        continue;
                    }

                    if ($existingChecksum === $checksum) {
                        $result['skipped']++;
                        continue;
                    }

                    $postData = $this->buildPostArray($payload);
                    $postData['ID'] = $existingPostId;
                    wp_update_post($postData);
                    $this->persistMeta($existingPostId, $payload, $payloadJson, $checksum);
                    $result['updated']++;
                    continue;
                }

                $postId = wp_insert_post($this->buildPostArray($payload));
                if (is_wp_error($postId)) {
                    $result['errors']++;
                    continue;
                }

                $this->persistMeta((int) $postId, $payload, $payloadJson, $checksum);
                $result['created']++;
            } catch (Throwable $throwable) {
                $result['errors']++;
            }
        }

        return $result;
    }

    private function buildClient(): GraphQLClient
    {
        $options = get_option(SettingsPage::OPTION_NAME, []);
        $options = is_array($options) ? $options : [];

        $httpConfig = [];
        $baseUri = isset($options['graphql_base_uri']) ? (string) $options['graphql_base_uri'] : '';
        if ($baseUri !== '') {
            $httpConfig['base_uri'] = $baseUri;
        }

        $client = new GraphQLClient($httpConfig);

        $token = isset($options['api_token']) ? trim((string) $options['api_token']) : '';
        if ($token !== '') {
            $client->setToken($token);
        }

        return $client;
    }

    private function getConflictStrategy(): string
    {
        $options = get_option(SettingsPage::OPTION_NAME, []);
        $options = is_array($options) ? $options : [];

        $strategy = isset($options['conflict_strategy'])
            ? (string) $options['conflict_strategy']
            : self::CONFLICT_STRATEGY_KEEP_MANUAL;

        return in_array($strategy, [self::CONFLICT_STRATEGY_KEEP_MANUAL, self::CONFLICT_STRATEGY_OVERWRITE], true)
            ? $strategy
            : self::CONFLICT_STRATEGY_KEEP_MANUAL;
    }

    private function buildPostArray(array $payload): array
    {
        $title = (string) ($payload['fullTitle'] ?? $payload['title'] ?? __('Untitled Work', 'thoth-data-connector'));
        $content = (string) ($payload['longAbstract'] ?? $payload['shortAbstract'] ?? '');
        $excerpt = (string) ($payload['shortAbstract'] ?? '');

        return [
            'post_type' => PublicationPostType::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
        ];
    }

    private function persistMeta(int $postId, array $payload, string $payloadJson, string $checksum): void
    {
        $nested = $this->nestedMetadataMapper->map($payload);

        update_post_meta($postId, '_thoth_work_id', (string) ($payload['workId'] ?? ''));
        update_post_meta($postId, '_thoth_doi', (string) ($payload['doi'] ?? ''));
        update_post_meta($postId, '_thoth_work_type', (string) ($payload['workType'] ?? ''));
        update_post_meta($postId, '_thoth_work_status', (string) ($payload['workStatus'] ?? ''));
        update_post_meta($postId, '_thoth_publication_date', (string) ($payload['publicationDate'] ?? ''));
        update_post_meta($postId, '_thoth_cover_url', (string) ($payload['coverUrl'] ?? ''));
        update_post_meta($postId, '_thoth_nested_contributors', $this->toJson($nested['contributors'] ?? []));
        update_post_meta($postId, '_thoth_nested_affiliations', $this->toJson($nested['affiliations'] ?? []));
        update_post_meta($postId, '_thoth_nested_subjects', $this->toJson($nested['subjects'] ?? []));
        update_post_meta($postId, '_thoth_nested_fundings', $this->toJson($nested['fundings'] ?? []));
        update_post_meta($postId, '_thoth_nested_publications', $this->toJson($nested['publications'] ?? []));
        update_post_meta($postId, '_thoth_nested_languages', $this->toJson($nested['languages'] ?? []));
        update_post_meta($postId, '_thoth_nested_issues', $this->toJson($nested['issues'] ?? []));
        update_post_meta($postId, '_thoth_nested_series', $this->toJson($nested['series'] ?? []));
        update_post_meta($postId, '_thoth_nested_related_works', $this->toJson($nested['related_works'] ?? []));
        update_post_meta($postId, '_thoth_contributor_names', (string) ($nested['contributor_names'] ?? ''));
        update_post_meta($postId, '_thoth_institution_ids', (string) ($nested['institution_ids'] ?? ''));
        update_post_meta($postId, '_thoth_subject_codes', (string) ($nested['subject_codes'] ?? ''));
        update_post_meta($postId, '_thoth_funding_programs', (string) ($nested['funding_programs'] ?? ''));
        update_post_meta($postId, '_thoth_publication_isbns', (string) ($nested['publication_isbns'] ?? ''));
        update_post_meta($postId, '_thoth_language_codes', (string) ($nested['language_codes'] ?? ''));
        update_post_meta($postId, '_thoth_issue_ids', (string) ($nested['issue_ids'] ?? ''));
        update_post_meta($postId, '_thoth_series_ids', (string) ($nested['series_ids'] ?? ''));
        update_post_meta($postId, '_thoth_related_work_ids', (string) ($nested['related_work_ids'] ?? ''));
        update_post_meta($postId, '_thoth_payload', $payloadJson);
        update_post_meta($postId, '_thoth_import_payload', $payloadJson);
        update_post_meta($postId, '_thoth_checksum', $checksum);
        update_post_meta($postId, '_thoth_import_checksum', $checksum);
        update_post_meta($postId, '_thoth_last_synced_at', gmdate('c'));
        update_post_meta($postId, '_thoth_manual_override', '0');
        update_post_meta($postId, '_thoth_sync_state', 'synced');
    }

    private function toJson(array $value): string
    {
        $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '[]';
    }

    private function findByWorkId(string $workId): int
    {
        $posts = get_posts([
            'post_type' => PublicationPostType::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_key' => '_thoth_work_id',
            'meta_value' => $workId,
            'suppress_filters' => false,
        ]);

        if (empty($posts)) {
            return 0;
        }

        return (int) $posts[0];
    }
}
