<?php

declare(strict_types=1);

namespace ThothWordPressPlugin\Admin;

use ThothWordPressPlugin\Import\WorkImporter;
use ThothWordPressPlugin\Metadata\NestedMetadataMapper;
use ThothWordPressPlugin\PostType\PublicationPostType;

final class PublicationMetaBox
{
    public const META_BOX_ID = 'thoth_publication_nested_metadata';
    private const NONCE_ACTION = 'thoth_publication_nested_save';
    private const NONCE_NAME = 'thoth_publication_nested_nonce';
    private const NOTICE_QUERY_ARG = 'thoth_notice';
    private const NOTICE_OVERRIDE_CLEARED = 'override_cleared';
    private const NOTICE_RESTORED_IMPORT = 'restored_import_snapshot';
    private const NOTICE_INVALID_STATUS_TRANSITION = 'invalid_status_transition';
    private const NOTICE_RULES_APPLIED = 'conditional_rules_applied';
    private const NOTICE_INVERSE_SYNC_APPLIED = 'inverse_related_sync_applied';
    private const NOTICE_VALIDATION_FAILED = 'validation_failed';
    private const NOTICE_INVERSE_SYNC_ADDED_ARG = 'thoth_inverse_added';
    private const NOTICE_INVERSE_SYNC_REMOVED_ARG = 'thoth_inverse_removed';
    private const WORK_ID_COUNTER_OPTION = 'thoth_work_id_counters';
    private const VALIDATION_ERROR_TRANSIENT_PREFIX = 'thoth_validation_errors_';

    private const BASE_FIELD_WORK_TYPE_VISIBILITY = [
        'pageBreakdown' => ['monograph', 'edited_book', 'textbook', 'journal_issue', 'book_set'],
        'firstPage' => ['book_chapter'],
        'lastPage' => ['book_chapter'],
    ];

    private const SECTION_WORK_TYPE_VISIBILITY = [
        'publications' => ['monograph', 'edited_book', 'textbook', 'journal_issue', 'book_set'],
        'issues' => ['journal_issue'],
        'series' => ['monograph', 'edited_book', 'textbook', 'journal_issue', 'book_set'],
    ];

    private const SECTION_REQUIRED_BY_WORK_TYPE = [
        'issues' => ['journal_issue'],
        'series' => ['book_set'],
    ];

    private const SECTION_REQUIRED_BY_STATUS = [
        'active' => ['publications'],
    ];

    private const REQUIRED_BASE_FIELDS = [
        'fullTitle',
        'imprint',
        'workType',
        'workStatus',
        'license',
        'copyrightHolder',
    ];

    private const WORK_TYPE_OPTIONS = [
        'book_chapter' => 'Book Chapter',
        'monograph' => 'Monograph',
        'edited_book' => 'Edited Book',
        'textbook' => 'Textbook',
        'journal_issue' => 'Journal Issue',
        'book_set' => 'Book Set',
    ];

    private const WORK_STATUS_OPTIONS = [
        'forthcoming' => 'Forthcoming',
        'active' => 'Active',
        'withdrawn' => 'Withdrawn',
        'superseded' => 'Superseded',
        'postponed_indefinitely' => 'Postponed Indefinitely',
        'cancelled' => 'Cancelled',
    ];

    private const RELATION_TYPE_OPTIONS = [
        'replaces' => 'Replaces',
        'has_translation' => 'Has Translation',
        'has_part' => 'Has Part',
        'has_child' => 'Has Child',
        'is_replaced_by' => 'Is Replaced By',
        'is_translation_of' => 'Is Translation Of',
        'is_part_of' => 'Is Part Of',
        'is_child_of' => 'Is Child Of',
    ];

    private const INVERSE_RELATION_TYPE_MAP = [
        'replaces' => 'is_replaced_by',
        'is_replaced_by' => 'replaces',
        'has_translation' => 'is_translation_of',
        'is_translation_of' => 'has_translation',
        'has_part' => 'is_part_of',
        'is_part_of' => 'has_part',
        'has_child' => 'is_child_of',
        'is_child_of' => 'has_child',
    ];

    private const LANGUAGE_RELATION_OPTIONS = [
        'original' => 'Original',
        'translated_from' => 'Translated from',
        'translated_into' => 'Translated into',
    ];

    private const PUBLICATION_TYPE_OPTIONS = [
        'paperback' => 'Paperback',
        'hardback' => 'Hardback',
        'pdf' => 'PDF',
        'html' => 'HTML',
        'xml' => 'XML',
        'epub' => 'Epub',
        'mobi' => 'Mobi',
        'azw3' => 'AZW3',
        'docx' => 'DOCX',
        'fictionbook' => 'FictionBook',
    ];

    private bool $isUpdatingPostTitle = false;
    private bool $statusTransitionRejected = false;
    private bool $conditionalRulesApplied = false;
    private ?array $inverseRelatedSyncStats = null;
    private array $uiVisibility = [];
    private array $validationErrors = [];

    public function register(): void
    {
        add_meta_box(
            self::META_BOX_ID,
            __('Thoth Nested Metadata', 'thoth-data-connector'),
            [$this, 'render'],
            PublicationPostType::POST_TYPE,
            'normal',
            'default'
        );
    }

    public function render(\WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        wp_enqueue_media();
        $this->uiVisibility = $this->getUiVisibilitySettings();

        $postId = (int) $post->ID;
        $contributors = $this->decodeJsonMeta((int) $post->ID, '_thoth_nested_contributors');
        $affiliations = $this->decodeJsonMeta((int) $post->ID, '_thoth_nested_affiliations');
        $subjects = $this->decodeJsonMeta((int) $post->ID, '_thoth_nested_subjects');
        $fundings = $this->decodeJsonMeta((int) $post->ID, '_thoth_nested_fundings');
        $relatedWorks = $this->decodeJsonMeta((int) $post->ID, '_thoth_nested_related_works');
        $languages = $this->decodeJsonMeta((int) $post->ID, '_thoth_nested_languages');
        $publications = $this->decodeJsonMeta((int) $post->ID, '_thoth_nested_publications');
        $issues = $this->decodeJsonMeta((int) $post->ID, '_thoth_nested_issues');
        $series = $this->decodeJsonMeta((int) $post->ID, '_thoth_nested_series');

        echo '<div class="thoth-meta-wrap">';

        $this->renderConflictStatus($postId);
        $this->renderBaseFieldsEditor($postId, $post);

        if ($this->isUiFieldVisible('section_contributors')) {
            $this->renderContributorsEditor($contributors, $affiliations);
        } elseif ($this->isUiFieldVisible('section_affiliations')) {
            $this->renderAffiliationsEditor($affiliations);
        }
        if ($this->isUiFieldVisible('section_subjects')) {
            $this->renderSubjectsEditor($subjects);
        }
        if ($this->isUiFieldVisible('section_fundings')) {
            $this->renderFundingsEditor($fundings);
        }
        if ($this->isUiFieldVisible('section_publications')) {
            $this->renderPublicationsEditor($publications);
        }
        if ($this->isUiFieldVisible('section_languages')) {
            $this->renderLanguagesEditor($languages);
        }
        if ($this->isUiFieldVisible('section_issues')) {
            $this->renderIssuesEditor($issues);
        }
        if ($this->isUiFieldVisible('section_series')) {
            $this->renderSeriesEditor($series);
        }
        if ($this->isUiFieldVisible('section_related_works')) {
            $this->renderRelatedWorksEditor($postId, $relatedWorks);
        }
        echo '</div>';

        $this->renderUiStyles();
        $this->renderRepeaterScript();
    }

    public function save(int $postId, \WP_Post $post): void
    {
        if ($post->post_type !== PublicationPostType::POST_TYPE) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($postId)) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        if (!isset($_POST[self::NONCE_NAME])) {
            return;
        }

        $nonce = sanitize_text_field((string) wp_unslash($_POST[self::NONCE_NAME]));
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        if ($this->isUpdatingPostTitle) {
            return;
        }

        $this->inverseRelatedSyncStats = null;
        $uiVisibility = $this->getUiVisibilitySettings();

        $previousRelatedWorks = $this->decodeJsonMeta($postId, '_thoth_nested_related_works');
        $syncInverseRelatedWorks = $this->isUiKeyVisibleIn($uiVisibility, 'section_related_works')
            ? (isset($_POST['thoth_sync_inverse_related_works'])
                && sanitize_text_field((string) wp_unslash($_POST['thoth_sync_inverse_related_works'])) === '1')
            : ((string) get_post_meta($postId, '_thoth_sync_inverse_related_works', true) === '1');
        update_post_meta($postId, '_thoth_sync_inverse_related_works', $syncInverseRelatedWorks ? '1' : '0');

        $shouldClearManualOverride = isset($_POST['thoth_clear_manual_override'])
            && sanitize_text_field((string) wp_unslash($_POST['thoth_clear_manual_override'])) === '1';
        if ($shouldClearManualOverride) {
            $this->clearManualOverride($postId);
            return;
        }

        $shouldResetToImport = isset($_POST['thoth_reset_to_import'])
            && sanitize_text_field((string) wp_unslash($_POST['thoth_reset_to_import'])) === '1';
        if ($shouldResetToImport) {
            $this->restoreFromImportSnapshot($postId);
            return;
        }

        $baseFields = $this->sanitizeBaseFields($this->parsePostedBaseFields());
        $this->preserveHiddenBaseFields($postId, $baseFields, $uiVisibility);
        $this->applyConditionalRulesToBaseFields($postId, $baseFields);
        $this->resolveCoverFromAttachment($baseFields);
        $this->assignGeneratedWorkId($postId, $baseFields);
        if (!$this->isAllowedWorkStatusTransition($postId, (string) ($baseFields['workStatus'] ?? ''))) {
            $baseFields['workStatus'] = $this->getCurrentWorkStatus($postId);
            $this->statusTransitionRejected = true;
        }

        $contributors = $this->sanitizeContributors($this->parsePostedRows('contributors'));
        $affiliations = $this->sanitizeAffiliations($this->parsePostedRows('affiliations'));
        $subjects = $this->sanitizeSubjects($this->parsePostedRows('subjects'));
        $fundings = $this->sanitizeFundings($this->parsePostedRows('fundings'));
        $publications = $this->sanitizePublications($this->parsePostedRows('publications'));
        $languages = $this->sanitizeLanguages($this->parsePostedRows('languages'));
        $issues = $this->sanitizeIssues($this->parsePostedRows('issues'));
        $series = $this->sanitizeSeries($this->parsePostedRows('series'));
        $relatedWorks = $this->sanitizeRelatedWorks($this->parsePostedRows('related_works'));

        $this->preserveHiddenSectionRows($postId, $uiVisibility, $contributors, $affiliations, $subjects, $fundings, $publications, $languages, $issues, $series, $relatedWorks);

        $this->applyConditionalRulesToNestedSections(
            $postId,
            (string) ($baseFields['workType'] ?? ''),
            (string) ($baseFields['workStatus'] ?? ''),
            $publications,
            $issues,
            $series
        );

        $validationErrors = $this->validateSanitizedInput($postId, $baseFields, $contributors, $relatedWorks, $uiVisibility);
        if ($validationErrors !== []) {
            $this->validationErrors = $validationErrors;
            $this->storeValidationErrors($postId, $validationErrors);
            return;
        }

        $this->persistBaseFields($postId, $baseFields);
        $this->updatePostTitleFromBase($postId, $baseFields);
        $this->updatePayloadBaseData($postId, $baseFields);

        update_post_meta($postId, '_thoth_nested_contributors', $this->toJson($contributors));
        update_post_meta($postId, '_thoth_nested_affiliations', $this->toJson($affiliations));
        update_post_meta($postId, '_thoth_nested_subjects', $this->toJson($subjects));
        update_post_meta($postId, '_thoth_nested_fundings', $this->toJson($fundings));
        update_post_meta($postId, '_thoth_nested_publications', $this->toJson($publications));
        update_post_meta($postId, '_thoth_nested_languages', $this->toJson($languages));
        update_post_meta($postId, '_thoth_nested_issues', $this->toJson($issues));
        update_post_meta($postId, '_thoth_nested_series', $this->toJson($series));
        update_post_meta($postId, '_thoth_nested_related_works', $this->toJson($relatedWorks));
        update_post_meta($postId, '_thoth_contributor_names', $this->collectDistinctValues($contributors, 'fullName'));
        update_post_meta($postId, '_thoth_institution_ids', $this->collectDistinctValues($affiliations, 'institutionId'));
        update_post_meta($postId, '_thoth_subject_codes', $this->collectDistinctValues($subjects, 'subjectCode'));
        update_post_meta($postId, '_thoth_funding_programs', $this->collectDistinctValues($fundings, 'program'));
        update_post_meta($postId, '_thoth_publication_isbns', $this->collectDistinctValues($publications, 'isbn'));
        update_post_meta($postId, '_thoth_language_codes', $this->collectDistinctValues($languages, 'languageCode'));
        update_post_meta($postId, '_thoth_issue_ids', $this->collectDistinctValues($issues, 'issueId'));
        update_post_meta($postId, '_thoth_series_ids', $this->collectDistinctValues($series, 'seriesId'));
        update_post_meta($postId, '_thoth_related_work_ids', $this->collectDistinctValues($relatedWorks, 'relatedWorkId'));
        update_post_meta($postId, '_thoth_last_synced_at', gmdate('c'));
        update_post_meta($postId, '_thoth_manual_override', '1');
        update_post_meta($postId, '_thoth_manual_updated_at', gmdate('c'));
        update_post_meta($postId, '_thoth_sync_state', 'manual_override');

        $this->updatePayloadNestedData($postId, $contributors, $affiliations, $subjects, $fundings, $publications, $languages, $issues, $series, $relatedWorks);

        if ($syncInverseRelatedWorks) {
            $sourceWorkId = $this->resolveSourceWorkId($postId, $baseFields);
            $this->inverseRelatedSyncStats = $this->syncInverseRelatedWorks($postId, $sourceWorkId, $previousRelatedWorks, $relatedWorks);
        }
    }

    public function appendActionNoticeToRedirect(string $location, int $postId): string
    {
        if (get_post_type($postId) !== PublicationPostType::POST_TYPE) {
            return $location;
        }

        if (!isset($_POST[self::NONCE_NAME])) {
            return $location;
        }

        $nonce = sanitize_text_field((string) wp_unslash($_POST[self::NONCE_NAME]));
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return $location;
        }

        $notice = '';

        $shouldClearManualOverride = isset($_POST['thoth_clear_manual_override'])
            && sanitize_text_field((string) wp_unslash($_POST['thoth_clear_manual_override'])) === '1';
        if ($shouldClearManualOverride) {
            $notice = self::NOTICE_OVERRIDE_CLEARED;
        }

        $shouldResetToImport = isset($_POST['thoth_reset_to_import'])
            && sanitize_text_field((string) wp_unslash($_POST['thoth_reset_to_import'])) === '1';
        if ($shouldResetToImport) {
            $notice = self::NOTICE_RESTORED_IMPORT;
        }

        if ($notice === '' && $this->statusTransitionRejected) {
            $notice = self::NOTICE_INVALID_STATUS_TRANSITION;
        }

        if ($notice === '' && $this->validationErrors !== []) {
            $notice = self::NOTICE_VALIDATION_FAILED;
        }

        if ($notice === '' && $this->conditionalRulesApplied) {
            $notice = self::NOTICE_RULES_APPLIED;
        }

        $queryArgs = [];
        if ($notice === '' && is_array($this->inverseRelatedSyncStats)) {
            $notice = self::NOTICE_INVERSE_SYNC_APPLIED;
            $queryArgs[self::NOTICE_INVERSE_SYNC_ADDED_ARG] = absint($this->inverseRelatedSyncStats['added'] ?? 0);
            $queryArgs[self::NOTICE_INVERSE_SYNC_REMOVED_ARG] = absint($this->inverseRelatedSyncStats['removed'] ?? 0);
        }

        if ($notice === '') {
            return $location;
        }

        $queryArgs[self::NOTICE_QUERY_ARG] = rawurlencode($notice);
        return add_query_arg($queryArgs, $location);
    }

    public function renderActionNotice(): void
    {
        if (!is_admin()) {
            return;
        }

        global $pagenow;
        if ($pagenow !== 'post.php') {
            return;
        }

        $postId = isset($_GET['post']) ? absint(wp_unslash($_GET['post'])) : 0;
        if ($postId <= 0 || get_post_type($postId) !== PublicationPostType::POST_TYPE) {
            return;
        }

        $notice = isset($_GET[self::NOTICE_QUERY_ARG])
            ? sanitize_key((string) wp_unslash($_GET[self::NOTICE_QUERY_ARG]))
            : '';

        if ($notice === self::NOTICE_OVERRIDE_CLEARED) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Override-Flag wurde entfernt. Der Datensatz kann beim nächsten Import wieder aktualisiert werden.', 'thoth-data-connector')
                . '</p></div>';
            return;
        }

        if ($notice === self::NOTICE_RESTORED_IMPORT) {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__('Der Datensatz wurde auf den letzten Importstand zurückgesetzt.', 'thoth-data-connector')
                . '</p></div>';
            return;
        }

        if ($notice === self::NOTICE_INVALID_STATUS_TRANSITION) {
            echo '<div class="notice notice-warning is-dismissible"><p>'
                . esc_html__('Ungültiger Work-Status-Wechsel: Ein bereits aktiver Datensatz kann nur auf Withdrawn oder Superseded gesetzt werden.', 'thoth-data-connector')
                . '</p></div>';
            return;
        }

        if ($notice === self::NOTICE_VALIDATION_FAILED) {
            $errors = $this->consumeValidationErrors($postId);
            echo '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__('Validation failed. Please fix the data issues and save again.', 'thoth-data-connector')
                . '</p>';
            if ($errors !== []) {
                echo '<ul style="margin:0 0 0 18px;list-style:disc;">';
                foreach ($errors as $error) {
                    echo '<li>' . esc_html((string) $error) . '</li>';
                }
                echo '</ul>';
            }
            echo '</div>';
            return;
        }

        if ($notice === self::NOTICE_RULES_APPLIED) {
            echo '<div class="notice notice-info is-dismissible"><p>'
                . esc_html__('Thoth-Regeln wurden angewendet: nicht erlaubte Felder wurden geleert bzw. Pflichtfelder auf bestehende Werte zurückgesetzt.', 'thoth-data-connector')
                . '</p></div>';
            return;
        }

        if ($notice === self::NOTICE_INVERSE_SYNC_APPLIED) {
            $added = isset($_GET[self::NOTICE_INVERSE_SYNC_ADDED_ARG])
                ? absint(wp_unslash($_GET[self::NOTICE_INVERSE_SYNC_ADDED_ARG]))
                : 0;
            $removed = isset($_GET[self::NOTICE_INVERSE_SYNC_REMOVED_ARG])
                ? absint(wp_unslash($_GET[self::NOTICE_INVERSE_SYNC_REMOVED_ARG]))
                : 0;

            echo '<div class="notice notice-info is-dismissible"><p>'
                . esc_html(sprintf(
                    /* translators: 1: number of added inverse relations, 2: number of removed inverse relations */
                    __('Inverse Related-Works sync applied: %1$d added, %2$d removed.', 'thoth-data-connector'),
                    $added,
                    $removed
                ))
                . '</p></div>';
        }
    }

    public function ajaxRelatedWorkLookup(): void
    {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $nonce = isset($_GET['nonce']) ? sanitize_text_field((string) wp_unslash($_GET['nonce'])) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        $term = isset($_GET['term']) ? sanitize_text_field((string) wp_unslash($_GET['term'])) : '';
        $term = trim($term);
        $currentPostId = isset($_GET['currentPostId']) ? absint(wp_unslash($_GET['currentPostId'])) : 0;

        if ($term === '' || strlen($term) < 2) {
            wp_send_json_success(['items' => []]);
        }

        $items = [];
        $postIds = [];

        $titleMatches = get_posts([
            'post_type' => PublicationPostType::POST_TYPE,
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => 10,
            's' => $term,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        if (is_array($titleMatches)) {
            foreach ($titleMatches as $id) {
                $postIds[(int) $id] = true;
            }
        }

        $workIdMatches = get_posts([
            'post_type' => PublicationPostType::POST_TYPE,
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => 10,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_thoth_work_id',
                    'value' => $term,
                    'compare' => 'LIKE',
                ],
            ],
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        if (is_array($workIdMatches)) {
            foreach ($workIdMatches as $id) {
                $postIds[(int) $id] = true;
            }
        }

        foreach (array_keys($postIds) as $postId) {
            if ((int) $postId === $currentPostId) {
                continue;
            }

            $workId = trim((string) get_post_meta((int) $postId, '_thoth_work_id', true));
            if ($workId === '') {
                continue;
            }

            $title = get_the_title((int) $postId);
            if (!is_string($title) || trim($title) === '') {
                $title = (string) get_post_meta((int) $postId, '_thoth_full_title', true);
            }

            $editUrl = get_edit_post_link((int) $postId, 'raw');
            $items[] = [
                'postId' => (int) $postId,
                'workId' => $workId,
                'title' => $title,
                'label' => trim($title) !== '' ? ($title . ' (' . $workId . ')') : $workId,
                'editUrl' => is_string($editUrl) ? $editUrl : '',
            ];

            if (count($items) >= 10) {
                break;
            }
        }

        wp_send_json_success(['items' => $items]);
    }

    public function ajaxEntityLookup(): void
    {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $nonce = isset($_GET['nonce']) ? sanitize_text_field((string) wp_unslash($_GET['nonce'])) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        $term = isset($_GET['term']) ? sanitize_text_field((string) wp_unslash($_GET['term'])) : '';
        $entityType = isset($_GET['entityType']) ? sanitize_key((string) wp_unslash($_GET['entityType'])) : '';
        $currentPostId = isset($_GET['currentPostId']) ? absint(wp_unslash($_GET['currentPostId'])) : 0;

        $term = trim($term);
        if ($term === '' || strlen($term) < 2) {
            wp_send_json_success(['items' => []]);
        }

        $allowedTypes = ['contributor', 'affiliation', 'series', 'issue', 'related_work'];
        if (!in_array($entityType, $allowedTypes, true)) {
            wp_send_json_error(['message' => 'Invalid entity type'], 400);
        }

        $items = $this->findEntityLookupItems($entityType, $term, $currentPostId);
        wp_send_json_success(['items' => $items]);
    }

    private function findEntityLookupItems(string $entityType, string $term, int $currentPostId): array
    {
        $normalizedTerm = $this->normalizeLookupText($term);
        if ($normalizedTerm === '') {
            return [];
        }

        $postIds = get_posts([
            'post_type' => PublicationPostType::POST_TYPE,
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => 250,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        if (!is_array($postIds)) {
            return [];
        }

        $ranked = [];
        $seen = [];

        foreach ($postIds as $postIdRaw) {
            $postId = (int) $postIdRaw;
            if ($postId <= 0 || $postId === $currentPostId) {
                continue;
            }

            $records = $this->extractEntityRecordsFromPost($postId, $entityType);
            foreach ($records as $record) {
                $signature = strtolower(trim((string) ($record['idValue'] ?? ''))) . '|' . strtolower(trim((string) ($record['label'] ?? '')));
                if ($signature === '|') {
                    continue;
                }

                if (isset($seen[$signature])) {
                    continue;
                }

                $score = $this->computeLookupScore(
                    $normalizedTerm,
                    (string) ($record['label'] ?? ''),
                    (string) ($record['idValue'] ?? ''),
                    (string) ($record['secondary'] ?? '')
                );

                if ($score <= 0) {
                    continue;
                }

                $editUrl = get_edit_post_link($postId, 'raw');
                $record['postId'] = $postId;
                $record['editUrl'] = is_string($editUrl) ? $editUrl : '';
                $record['score'] = $score;
                $record['entityType'] = $entityType;

                $ranked[] = $record;
                $seen[$signature] = true;
            }
        }

        if ($ranked === []) {
            return [];
        }

        usort($ranked, static function (array $left, array $right): int {
            $scoreCompare = (int) ($right['score'] ?? 0) <=> (int) ($left['score'] ?? 0);
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            return strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });

        $items = [];
        foreach (array_slice($ranked, 0, 12) as $entry) {
            $items[] = [
                'entityType' => (string) ($entry['entityType'] ?? ''),
                'postId' => (int) ($entry['postId'] ?? 0),
                'idValue' => (string) ($entry['idValue'] ?? ''),
                'secondary' => (string) ($entry['secondary'] ?? ''),
                'label' => (string) ($entry['label'] ?? ''),
                'displayLabel' => (string) ($entry['displayLabel'] ?? ''),
                'editUrl' => (string) ($entry['editUrl'] ?? ''),
                'score' => (int) ($entry['score'] ?? 0),
            ];
        }

        return $items;
    }

    private function extractEntityRecordsFromPost(int $postId, string $entityType): array
    {
        if ($entityType === 'related_work') {
            $workId = trim((string) get_post_meta($postId, '_thoth_work_id', true));
            if ($workId === '') {
                return [];
            }

            $title = get_the_title($postId);
            if (!is_string($title) || trim($title) === '') {
                $title = (string) get_post_meta($postId, '_thoth_full_title', true);
            }

            $display = trim((string) $title) !== '' ? ($title . ' (' . $workId . ')') : $workId;
            return [[
                'idValue' => $workId,
                'secondary' => '',
                'label' => trim((string) $title),
                'displayLabel' => $display,
            ]];
        }

        $metaMap = [
            'contributor' => '_thoth_nested_contributors',
            'affiliation' => '_thoth_nested_affiliations',
            'series' => '_thoth_nested_series',
            'issue' => '_thoth_nested_issues',
        ];

        if (!isset($metaMap[$entityType])) {
            return [];
        }

        $rows = $this->decodeJsonMeta($postId, $metaMap[$entityType]);
        if ($rows === []) {
            return [];
        }

        $records = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ($entityType === 'contributor') {
                $label = trim((string) ($row['fullName'] ?? ''));
                if ($label === '') {
                    $first = trim((string) ($row['firstName'] ?? ''));
                    $last = trim((string) ($row['lastName'] ?? ''));
                    $label = trim($first . ' ' . $last);
                }

                $idValue = trim((string) ($row['contributorId'] ?? ''));
                $secondary = trim((string) ($row['orcid'] ?? ''));
                if ($label === '' && $idValue === '' && $secondary === '') {
                    continue;
                }

                $suffixParts = [];
                if ($idValue !== '') {
                    $suffixParts[] = $idValue;
                }
                if ($secondary !== '') {
                    $suffixParts[] = 'ORCID ' . $secondary;
                }
                $suffix = $suffixParts !== [] ? (' (' . implode(' · ', $suffixParts) . ')') : '';

                $records[] = [
                    'idValue' => $idValue,
                    'secondary' => $secondary,
                    'label' => $label,
                    'displayLabel' => ($label !== '' ? $label : ($idValue !== '' ? $idValue : $secondary)) . $suffix,
                ];
                continue;
            }

            if ($entityType === 'affiliation') {
                $label = trim((string) ($row['institutionName'] ?? ''));
                $idValue = trim((string) ($row['institutionId'] ?? ''));
                if ($label === '' && $idValue === '') {
                    continue;
                }

                $records[] = [
                    'idValue' => $idValue,
                    'secondary' => '',
                    'label' => $label,
                    'displayLabel' => $label !== '' ? ($idValue !== '' ? ($label . ' (' . $idValue . ')') : $label) : $idValue,
                ];
                continue;
            }

            if ($entityType === 'series') {
                $label = trim((string) ($row['seriesName'] ?? ''));
                $idValue = trim((string) ($row['seriesId'] ?? ''));
                if ($label === '' && $idValue === '') {
                    continue;
                }

                $records[] = [
                    'idValue' => $idValue,
                    'secondary' => '',
                    'label' => $label,
                    'displayLabel' => $label !== '' ? ($idValue !== '' ? ($label . ' (' . $idValue . ')') : $label) : $idValue,
                ];
                continue;
            }

            if ($entityType === 'issue') {
                $label = trim((string) ($row['issueNumber'] ?? ''));
                $idValue = trim((string) ($row['issueId'] ?? ''));
                if ($label === '' && $idValue === '') {
                    continue;
                }

                $records[] = [
                    'idValue' => $idValue,
                    'secondary' => '',
                    'label' => $label,
                    'displayLabel' => $label !== '' ? ($idValue !== '' ? ($label . ' (' . $idValue . ')') : $label) : $idValue,
                ];
            }
        }

        return $records;
    }

    private function normalizeLookupText(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }

        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }

    private function computeLookupScore(string $normalizedTerm, string $label, string $idValue, string $secondary): int
    {
        $normalizedLabel = $this->normalizeLookupText($label);
        $normalizedId = $this->normalizeLookupText($idValue);
        $normalizedSecondary = $this->normalizeLookupText($secondary);

        if ($normalizedTerm === '') {
            return 0;
        }

        if ($normalizedId !== '' && $normalizedId === $normalizedTerm) {
            return 120;
        }

        if ($normalizedSecondary !== '' && $normalizedSecondary === $normalizedTerm) {
            return 120;
        }

        $score = 0;
        if ($normalizedLabel !== '') {
            if (strpos($normalizedLabel, $normalizedTerm) !== false) {
                $score = max($score, 95);
            }
            if (strpos($normalizedTerm, $normalizedLabel) !== false) {
                $score = max($score, 88);
            }
        }

        if ($normalizedId !== '' && strpos($normalizedId, $normalizedTerm) !== false) {
            $score = max($score, 90);
        }

        if ($normalizedSecondary !== '' && strpos($normalizedSecondary, $normalizedTerm) !== false) {
            $score = max($score, 90);
        }

        return $score;
    }

    private function resolveRelatedWorkPostByWorkId(string $workId): int
    {
        $normalizedWorkId = trim($workId);
        if ($normalizedWorkId === '') {
            return 0;
        }

        $matches = get_posts([
            'post_type' => PublicationPostType::POST_TYPE,
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_thoth_work_id',
                    'value' => $normalizedWorkId,
                    'compare' => '=',
                ],
            ],
        ]);

        if (!is_array($matches) || !isset($matches[0])) {
            return 0;
        }

        return (int) $matches[0];
    }

    private function buildRelatedWorkLookupLabel(string $workId, int $postId): string
    {
        $normalizedWorkId = trim($workId);
        if ($normalizedWorkId === '') {
            return '';
        }

        if ($postId <= 0) {
            return $normalizedWorkId;
        }

        $title = get_the_title($postId);
        if (!is_string($title) || trim($title) === '') {
            $title = (string) get_post_meta($postId, '_thoth_full_title', true);
        }

        return trim($title) !== '' ? ($title . ' (' . $normalizedWorkId . ')') : $normalizedWorkId;
    }

    private function renderBaseFieldsEditor(int $postId, \WP_Post $post): void
    {
        $base = [
            'fullTitle' => (string) get_post_meta($postId, '_thoth_full_title', true),
            'subtitle' => (string) get_post_meta($postId, '_thoth_subtitle', true),
            'edition' => (string) get_post_meta($postId, '_thoth_edition', true),
            'workId' => (string) get_post_meta($postId, '_thoth_work_id', true),
            'doi' => (string) get_post_meta($postId, '_thoth_doi', true),
            'imprint' => (string) get_post_meta($postId, '_thoth_imprint', true),
            'workType' => (string) get_post_meta($postId, '_thoth_work_type', true),
            'workStatus' => (string) get_post_meta($postId, '_thoth_work_status', true),
            'publicationDate' => (string) get_post_meta($postId, '_thoth_publication_date', true),
            'placeOfPublication' => (string) get_post_meta($postId, '_thoth_place_of_publication', true),
            'coverUrl' => (string) get_post_meta($postId, '_thoth_cover_url', true),
            'coverAttachmentId' => (string) get_post_meta($postId, '_thoth_cover_attachment_id', true),
            'coverCaption' => (string) get_post_meta($postId, '_thoth_cover_caption', true),
            'landingPage' => (string) get_post_meta($postId, '_thoth_landing_page', true),
            'license' => (string) get_post_meta($postId, '_thoth_license', true),
            'copyrightHolder' => (string) get_post_meta($postId, '_thoth_copyright_holder', true),
            'lccn' => (string) get_post_meta($postId, '_thoth_lccn', true),
            'oclc' => (string) get_post_meta($postId, '_thoth_oclc', true),
            'internalReference' => (string) get_post_meta($postId, '_thoth_internal_reference', true),
            'pageCount' => (string) get_post_meta($postId, '_thoth_page_count', true),
            'pageBreakdown' => (string) get_post_meta($postId, '_thoth_page_breakdown', true),
            'firstPage' => (string) get_post_meta($postId, '_thoth_first_page', true),
            'lastPage' => (string) get_post_meta($postId, '_thoth_last_page', true),
            'imageCount' => (string) get_post_meta($postId, '_thoth_image_count', true),
            'tableCount' => (string) get_post_meta($postId, '_thoth_table_count', true),
            'audioCount' => (string) get_post_meta($postId, '_thoth_audio_count', true),
            'videoCount' => (string) get_post_meta($postId, '_thoth_video_count', true),
            'shortAbstract' => (string) get_post_meta($postId, '_thoth_short_abstract', true),
            'longAbstract' => (string) get_post_meta($postId, '_thoth_long_abstract', true),
            'generalNote' => (string) get_post_meta($postId, '_thoth_general_note', true),
            'bibliographyNote' => (string) get_post_meta($postId, '_thoth_bibliography_note', true),
            'tableOfContents' => (string) get_post_meta($postId, '_thoth_table_of_contents', true),
        ];

        if ($base['fullTitle'] === '') {
            $base['fullTitle'] = $post->post_title;
        }

        if ($base['shortAbstract'] === '') {
            $base['shortAbstract'] = $post->post_excerpt;
        }

        if ($base['longAbstract'] === '') {
            $base['longAbstract'] = $post->post_content;
        }

        echo '<section class="thoth-meta-section">';
        echo '<h3>' . esc_html__('Basic Metadata', 'thoth-data-connector') . '</h3>';
        echo '<table class="form-table" role="presentation"><tbody>';

        if ($this->isUiFieldVisible('workType')) { $this->renderSelectFieldRow('Work Type', 'workType', $base['workType'], self::WORK_TYPE_OPTIONS, 'Mandatory. Select the type of work.'); }
        if ($this->isUiFieldVisible('workStatus')) { $this->renderSelectFieldRow('Work Status', 'workStatus', $base['workStatus'], self::WORK_STATUS_OPTIONS, 'Mandatory. Controls publication and distribution state.'); }
        $workIdDisplay = $this->buildWorkIdDisplayValue($postId, $base['workId']);
        $this->renderReadonlyFieldRow('Work ID', $workIdDisplay['value'], (string) $workIdDisplay['tooltip'], [], $workIdDisplay['hint']);
        if ($this->isUiFieldVisible('fullTitle')) { $this->renderTextFieldRow('Full Title', 'fullTitle', $base['fullTitle'], 'Mandatory. The title of the work.'); }
        if ($this->isUiFieldVisible('subtitle')) { $this->renderTextFieldRow('Subtitle', 'subtitle', $base['subtitle'], 'The subtitle of the work.'); }
        if ($this->isUiFieldVisible('edition')) { $this->renderTextFieldRow('Edition', 'edition', $base['edition'], 'Edition number or name. Use 1 for the initial edition.'); }
        if ($this->isUiFieldVisible('doi')) { $this->renderTextFieldRow('DOI', 'doi', $base['doi'], 'Digital Object Identifier of the work.'); }
        if ($this->isUiFieldVisible('imprint')) { $this->renderTextFieldRow('Imprint', 'imprint', $base['imprint'], 'Mandatory. The imprint under which the work is published.'); }
        if ($this->isUiFieldVisible('publicationDate')) { $this->renderTextFieldRow('Publication Date', 'publicationDate', $base['publicationDate'], 'Recommended. Publication date, usually dd/mm/yyyy.'); }
        if ($this->isUiFieldVisible('placeOfPublication')) { $this->renderTextFieldRow('Place of Publication', 'placeOfPublication', $base['placeOfPublication'], 'Recommended. Place where the work is published.'); }
        $isCoverUrlVisible = $this->isUiFieldVisible('coverUrl');
        if ($isCoverUrlVisible) {
            $this->renderUrlFieldRow('Cover URL', 'coverUrl', $base['coverUrl'], 'Recommended. Fixed Thoth field. Public URL of the cover image asset.');
        }
        if ($this->isUiFieldVisible('cover')) {
            if (!$isCoverUrlVisible) {
                echo '<input type="hidden" id="thoth-base-coverUrl" name="thoth_base[coverUrl]" value="' . esc_attr($base['coverUrl']) . '" />';
            }
            $this->renderCoverPickerFieldRow('Cover', $base['coverUrl'], $base['coverAttachmentId'], 'Choose/upload a cover image via WordPress media picker. This updates Cover URL.');
        }
        if ($this->isUiFieldVisible('coverCaption')) { $this->renderTextFieldRow('Cover Caption', 'coverCaption', $base['coverCaption'], 'Recommended. Caption that refers to the cover image.'); }
        if ($this->isUiFieldVisible('landingPage')) { $this->renderUrlFieldRow('Landing Page', 'landingPage', $base['landingPage'], 'Recommended. Public URL for the work. Do not use a DOI URL.'); }
        if ($this->isUiFieldVisible('license')) { $this->renderUrlFieldRow('License', 'license', $base['license'], 'Mandatory. URL of the license applying to this work.'); }
        if ($this->isUiFieldVisible('copyrightHolder')) { $this->renderTextFieldRow('Copyright Holder', 'copyrightHolder', $base['copyrightHolder'], 'Mandatory. Name of the copyright holder.'); }
        if ($this->isUiFieldVisible('lccn')) { $this->renderTextFieldRow('LCCN', 'lccn', $base['lccn'], 'Optional. Library of Congress Control Number.'); }
        if ($this->isUiFieldVisible('oclc')) { $this->renderTextFieldRow('OCLC Number', 'oclc', $base['oclc'], 'Optional. OCLC number of the work.'); }
        if ($this->isUiFieldVisible('internalReference')) { $this->renderTextFieldRow('Internal Reference', 'internalReference', $base['internalReference'], 'Optional. Internal publisher reference for this work.'); }
        if ($this->isUiFieldVisible('pageCount')) { $this->renderNumberFieldRow('Page Count', 'pageCount', $base['pageCount'], 'Recommended. Total page count of the work.'); }
        if ($this->isUiFieldVisible('pageBreakdown')) {
            $this->renderTextFieldRow('Page Breakdown', 'pageBreakdown', $base['pageBreakdown'], 'Optional. Breakdown of frontmatter, main matter, and backmatter pages.', self::BASE_FIELD_WORK_TYPE_VISIBILITY['pageBreakdown']);
        }
        if ($this->isUiFieldVisible('pageRange')) {
            $this->renderNumberFieldRow('First Page', 'firstPage', $base['firstPage'], 'Required for book chapters. First page number.', self::BASE_FIELD_WORK_TYPE_VISIBILITY['firstPage']);
            $this->renderNumberFieldRow('Last Page', 'lastPage', $base['lastPage'], 'Required for book chapters. Last page number.', self::BASE_FIELD_WORK_TYPE_VISIBILITY['lastPage']);
        }
        if ($this->isUiFieldVisible('imageCount')) { $this->renderNumberFieldRow('Image Count', 'imageCount', $base['imageCount'], 'Optional. Total number of images.'); }
        if ($this->isUiFieldVisible('tableCount')) { $this->renderNumberFieldRow('Table Count', 'tableCount', $base['tableCount'], 'Optional. Total number of tables.'); }
        if ($this->isUiFieldVisible('audioCount')) { $this->renderNumberFieldRow('Audio Count', 'audioCount', $base['audioCount'], 'Optional. Total number of audio fragments.'); }
        if ($this->isUiFieldVisible('videoCount')) { $this->renderNumberFieldRow('Video Count', 'videoCount', $base['videoCount'], 'Optional. Total number of video fragments.'); }

        if ($this->isUiFieldVisible('shortAbstract')) { $this->renderTextareaFieldRow('Short Abstract', 'shortAbstract', $base['shortAbstract'], 3, 'Optional. Short abstract; not output in most metadata formats.'); }
        if ($this->isUiFieldVisible('longAbstract')) { $this->renderTextareaFieldRow('Long Abstract', 'longAbstract', $base['longAbstract'], 6, 'Recommended. Main abstract used in metadata formats.'); }
        if ($this->isUiFieldVisible('generalNote')) { $this->renderTextareaFieldRow('General Note', 'generalNote', $base['generalNote'], 3, 'Optional. General-purpose note for additional context.'); }
        if ($this->isUiFieldVisible('bibliographyNote')) { $this->renderTextareaFieldRow('Bibliography Note', 'bibliographyNote', $base['bibliographyNote'], 3, 'Optional. Note about bibliography or similar references.'); }
        if ($this->isUiFieldVisible('tableOfContents')) { $this->renderTextareaFieldRow('Table of Contents', 'tableOfContents', $base['tableOfContents'], 5, 'Optional. Table of contents text for the work.'); }

        echo '</tbody></table>';
        echo '</section>';
    }

    private function renderTextFieldRow(string $label, string $key, string $value, string $tooltip, array $visibleWorkTypes = []): void
    {
        echo '<tr' . $this->buildWorkTypeVisibilityAttribute($visibleWorkTypes) . '><th scope="row"><label for="thoth-base-' . esc_attr($key) . '">' . $this->renderLabelWithTooltipIcon($label, $tooltip) . '</label></th><td>';
        echo '<input type="text" id="thoth-base-' . esc_attr($key) . '" class="regular-text" name="thoth_base[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" title="' . esc_attr($tooltip) . '" />';
        echo '</td></tr>';
    }

    private function renderReadonlyFieldRow(string $label, string $value, string $tooltip, array $visibleWorkTypes = [], string $hint = ''): void
    {
        echo '<tr' . $this->buildWorkTypeVisibilityAttribute($visibleWorkTypes) . '><th scope="row">' . $this->renderLabelWithTooltipIcon($label, $tooltip) . '</th><td>';
        echo '<input type="text" class="regular-text" value="' . esc_attr($value) . '" title="' . esc_attr($tooltip) . '" readonly="readonly" />';
        if ($hint !== '') {
            echo '<p class="description" style="margin:4px 0 0;">' . esc_html($hint) . '</p>';
        }
        echo '</td></tr>';
    }

    private function buildWorkIdDisplayValue(int $postId, string $currentWorkId): array
    {
        $existing = trim($currentWorkId);
        if ($existing !== '') {
            $prefix = $this->extractWorkIdPrefix($existing);
            $hint = $prefix !== ''
                ? sprintf(
                    /* translators: %s: work id prefix */
                    __('Prefix: %s', 'thoth-data-connector'),
                    $prefix
                )
                : (string) __('Prefix: none', 'thoth-data-connector');

            return [
                'value' => $existing,
                'tooltip' => __('Automatically generated internal identifier.', 'thoth-data-connector'),
                'hint' => $hint,
            ];
        }

        $prefix = $this->getConfiguredWorkIdPrefix();
        $previewCounter = $this->determineNextWorkIdCounter($prefix, $postId);
        $previewId = $this->formatGeneratedWorkId($prefix, $previewCounter);
        $hintPrefix = $prefix !== '' ? $prefix : (string) __('none', 'thoth-data-connector');

        return [
            'value' => $previewId,
            'tooltip' => __('Preview of the next automatically generated internal identifier.', 'thoth-data-connector'),
            'hint' => sprintf(
                /* translators: 1: prefix, 2: work id preview */
                __('Prefix: %1$s · Preview: %2$s', 'thoth-data-connector'),
                $hintPrefix,
                $previewId
            ),
        ];
    }

    private function extractWorkIdPrefix(string $workId): string
    {
        $normalized = trim($workId);
        if ($normalized === '') {
            return '';
        }

        $position = strpos($normalized, ':');
        if ($position === false) {
            return '';
        }

        return substr($normalized, 0, $position);
    }

    private function renderUrlFieldRow(string $label, string $key, string $value, string $tooltip, array $visibleWorkTypes = []): void
    {
        echo '<tr' . $this->buildWorkTypeVisibilityAttribute($visibleWorkTypes) . '><th scope="row"><label for="thoth-base-' . esc_attr($key) . '">' . $this->renderLabelWithTooltipIcon($label, $tooltip) . '</label></th><td>';
        echo '<input type="url" id="thoth-base-' . esc_attr($key) . '" class="regular-text" name="thoth_base[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" title="' . esc_attr($tooltip) . '" />';
        echo '</td></tr>';
    }

    private function renderCoverPickerFieldRow(string $label, string $urlValue, string $attachmentIdValue, string $tooltip): void
    {
        $attachmentId = absint($attachmentIdValue);
        $imageUrl = $urlValue;

        if ($attachmentId > 0) {
            $resolvedUrl = wp_get_attachment_url($attachmentId);
            if (is_string($resolvedUrl) && $resolvedUrl !== '') {
                $imageUrl = $resolvedUrl;
            }
        }

        echo '<tr><th scope="row">' . $this->renderLabelWithTooltipIcon($label, $tooltip) . '</th><td>';
        echo '<input type="hidden" id="thoth-base-coverAttachmentId" name="thoth_base[coverAttachmentId]" value="' . esc_attr((string) $attachmentId) . '" />';
        echo '<button type="button" class="button" data-thoth-cover-picker="1">'
            . esc_html__('Select / Upload Cover', 'thoth-data-connector')
            . '</button> ';
        echo '<button type="button" class="button button-secondary" data-thoth-cover-remove="1">'
            . esc_html__('Remove Cover', 'thoth-data-connector')
            . '</button>';
        echo '<div style="margin-top:8px;">';
        echo '<img id="thoth-cover-preview" src="' . esc_url($imageUrl) . '" alt="" style="max-width:180px;height:auto;' . ($imageUrl === '' ? 'display:none;' : '') . '" />';
        echo '</div>';
        echo '</td></tr>';
    }

    private function renderNumberFieldRow(string $label, string $key, string $value, string $tooltip, array $visibleWorkTypes = []): void
    {
        echo '<tr' . $this->buildWorkTypeVisibilityAttribute($visibleWorkTypes) . '><th scope="row"><label for="thoth-base-' . esc_attr($key) . '">' . $this->renderLabelWithTooltipIcon($label, $tooltip) . '</label></th><td>';
        echo '<input type="number" min="0" id="thoth-base-' . esc_attr($key) . '" class="small-text" name="thoth_base[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" title="' . esc_attr($tooltip) . '" />';
        echo '</td></tr>';
    }

    private function renderSelectFieldRow(string $label, string $key, string $value, array $options, string $tooltip, array $visibleWorkTypes = []): void
    {
        $normalized = $key === 'workStatus'
            ? $this->normalizeWorkStatus($value)
            : $this->normalizeWorkType($value);

        echo '<tr' . $this->buildWorkTypeVisibilityAttribute($visibleWorkTypes) . '><th scope="row"><label for="thoth-base-' . esc_attr($key) . '">' . $this->renderLabelWithTooltipIcon($label, $tooltip) . '</label></th><td>';
        echo '<select id="thoth-base-' . esc_attr($key) . '" name="thoth_base[' . esc_attr($key) . ']" title="' . esc_attr($tooltip) . '">';
        echo '<option value="">' . esc_html__('Select…', 'thoth-data-connector') . '</option>';
        foreach ($options as $optionValue => $optionLabel) {
            echo '<option value="' . esc_attr($optionValue) . '" ' . selected($normalized, $optionValue, false) . '>' . esc_html($optionLabel) . '</option>';
        }
        echo '</select>';
        echo '</td></tr>';
    }

    private function renderTextareaFieldRow(string $label, string $key, string $value, int $rows, string $tooltip, array $visibleWorkTypes = []): void
    {
        echo '<tr' . $this->buildWorkTypeVisibilityAttribute($visibleWorkTypes) . '><th scope="row"><label for="thoth-base-' . esc_attr($key) . '">' . $this->renderLabelWithTooltipIcon($label, $tooltip) . '</label></th><td>';
        echo '<textarea id="thoth-base-' . esc_attr($key) . '" name="thoth_base[' . esc_attr($key) . ']" rows="' . esc_attr((string) $rows) . '" class="large-text" title="' . esc_attr($tooltip) . '">' . esc_textarea($value) . '</textarea>';
        echo '</td></tr>';
    }

    private function renderLabelWithTooltipIcon(string $label, string $tooltip): string
    {
        return esc_html($label)
            . ' <span class="thoth-tooltip-icon" data-thoth-tooltip="'
            . esc_attr($tooltip)
            . '" aria-label="'
            . esc_attr($tooltip)
            . '" tabindex="0">i</span>';
    }

    private function renderConflictStatus(int $postId): void
    {
        $syncState = (string) get_post_meta($postId, '_thoth_sync_state', true);
        $manualOverride = (string) get_post_meta($postId, '_thoth_manual_override', true) === '1';
        $manualUpdatedAt = (string) get_post_meta($postId, '_thoth_manual_updated_at', true);
        $lastSyncedAt = (string) get_post_meta($postId, '_thoth_last_synced_at', true);
        $hasImportSnapshot = (string) get_post_meta($postId, '_thoth_import_payload', true) !== '';

        $options = get_option(SettingsPage::OPTION_NAME, []);
        $options = is_array($options) ? $options : [];
        $strategy = isset($options['conflict_strategy'])
            ? (string) $options['conflict_strategy']
            : WorkImporter::CONFLICT_STRATEGY_KEEP_MANUAL;

        $stateLabel = __('Synchronisiert', 'thoth-data-connector');
        $stateClass = 'thoth-state-ok';

        if ($syncState === 'manual_override') {
            $stateLabel = __('Manuelle Änderungen vorhanden', 'thoth-data-connector');
            $stateClass = 'thoth-state-warning';
        } elseif ($syncState === 'manual_override_remote_update') {
            $stateLabel = __('Konflikt: Remote-Update verfügbar', 'thoth-data-connector');
            $stateClass = 'thoth-state-danger';
        } elseif ($syncState === 'pending_import') {
            $stateLabel = __('Override entfernt: Update beim nächsten Import', 'thoth-data-connector');
            $stateClass = 'thoth-state-info';
        }

        $strategyLabel = $strategy === WorkImporter::CONFLICT_STRATEGY_OVERWRITE
            ? __('Import überschreibt manuelle Änderungen', 'thoth-data-connector')
            : __('Manuelle Änderungen werden geschützt', 'thoth-data-connector');

        echo '<section class="thoth-meta-section thoth-state-panel">';
        echo '<h3>' . esc_html__('Synchronisationsstatus', 'thoth-data-connector') . '</h3>';
        echo '<p><span class="thoth-state-badge ' . esc_attr($stateClass) . '">' . esc_html($stateLabel) . '</span></p>';
        echo '<p><strong>' . esc_html__('Konfliktstrategie:', 'thoth-data-connector') . '</strong> ' . esc_html($strategyLabel) . '</p>';

        if ($lastSyncedAt !== '') {
            echo '<p><strong>' . esc_html__('Letzter Import:', 'thoth-data-connector') . '</strong> <code>' . esc_html($lastSyncedAt) . '</code></p>';
        }

        if ($manualUpdatedAt !== '') {
            echo '<p><strong>' . esc_html__('Letzte manuelle Änderung:', 'thoth-data-connector') . '</strong> <code>' . esc_html($manualUpdatedAt) . '</code></p>';
        }

        if ($manualOverride) {
            echo '<p><button type="submit" class="button" name="thoth_clear_manual_override" value="1" data-thoth-quick-action="1" onclick="return confirm(\''
                . esc_js(__('Manuellen Schutz entfernen? Beim nächsten Import kann dieser Datensatz aktualisiert werden.', 'thoth-data-connector'))
                . '\');">'
                . esc_html__('Override-Flag löschen', 'thoth-data-connector')
                . '</button></p>';
        }

        if ($hasImportSnapshot) {
            echo '<p><button type="submit" class="button button-secondary" name="thoth_reset_to_import" value="1" data-thoth-quick-action="1" onclick="return confirm(\''
                . esc_js(__('Lokale Änderungen verwerfen und letzten Importstand wiederherstellen?', 'thoth-data-connector'))
                . '\');">'
                . esc_html__('Lokale Änderungen auf letzten Importstand zurücksetzen', 'thoth-data-connector')
                . '</button></p>';
        } else {
            echo '<p>' . esc_html__('Noch kein Import-Snapshot vorhanden, daher ist kein Zurücksetzen möglich.', 'thoth-data-connector') . '</p>';
        }

        echo '</section>';
    }

    private function decodeJsonMeta(int $postId, string $metaKey): array
    {
        $raw = get_post_meta($postId, $metaKey, true);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function renderContributorsEditor(array $rows, array $affiliations): void
    {
        echo '<section class="thoth-meta-section">';
        echo '<h3>' . esc_html__('Contributors', 'thoth-data-connector') . '</h3>';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th>' . esc_html__('Full Name', 'thoth-data-connector') . '</th>'
            . '<th>' . esc_html__('Role', 'thoth-data-connector') . '</th>'
            . '<th>' . esc_html__('ORCID', 'thoth-data-connector') . '</th>'
            . '<th>' . esc_html__('Main', 'thoth-data-connector') . '</th>'
            . '<th></th>'
            . '</tr></thead><tbody data-repeater="contributors">';

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $this->renderContributorRow($row, (int) $index);
        }

        if (empty($rows)) {
            $this->renderContributorRow([], 0);
        }

        echo '</tbody></table>';
        echo '<p><button type="button" class="button button-secondary" data-toggle-advanced="contributors" data-open="0" data-label-show="'
            . esc_attr__('Show Advanced Fields', 'thoth-data-connector')
            . '" data-label-hide="'
            . esc_attr__('Hide Advanced Fields', 'thoth-data-connector')
            . '">'
            . esc_html__('Show Advanced Fields', 'thoth-data-connector')
            . '</button></p>';

        if ($this->isUiFieldVisible('section_affiliations')) {
            echo '<div class="thoth-subsection">';
            echo '<h4>' . esc_html__('Contributor Affiliations', 'thoth-data-connector') . '</h4>';
            echo '<table class="widefat striped"><thead><tr>'
                . '<th title="Contribution UUID this affiliation belongs to.">' . esc_html__('Contribution ID', 'thoth-data-connector') . '</th>'
                . '<th title="Contributor UUID this affiliation belongs to.">' . esc_html__('Contributor ID', 'thoth-data-connector') . '</th>'
                . '<th title="Institution UUID from Thoth/ROR context.">' . esc_html__('Institution ID', 'thoth-data-connector') . '</th>'
                . '<th title="Institution display name.">' . esc_html__('Institution Name', 'thoth-data-connector') . '</th>'
                . '<th title="Optional free-text role/position at institution.">' . esc_html__('Position', 'thoth-data-connector') . '</th>'
                . '<th title="Required if institution is set. Integer order starting at 1.">' . esc_html__('Affiliation Ordinal', 'thoth-data-connector') . '</th>'
                . '<th></th>'
                . '</tr></thead><tbody data-repeater="affiliations">';

            foreach ($affiliations as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $this->renderAffiliationRow($row, (int) $index);
            }

            if (empty($affiliations)) {
                $this->renderAffiliationRow([], 0);
            }

            echo '</tbody></table>';
            echo '</div>';
        }

            echo '</section>';
    }

    private function renderAffiliationsEditor(array $rows): void
    {
        echo '<section class="thoth-meta-section">';
        echo '<h3>' . esc_html__('Contributor Affiliations', 'thoth-data-connector') . '</h3>';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th title="Contribution UUID this affiliation belongs to.">' . esc_html__('Contribution ID', 'thoth-data-connector') . '</th>'
            . '<th title="Contributor UUID this affiliation belongs to.">' . esc_html__('Contributor ID', 'thoth-data-connector') . '</th>'
            . '<th title="Institution UUID from Thoth/ROR context.">' . esc_html__('Institution ID', 'thoth-data-connector') . '</th>'
            . '<th title="Institution display name.">' . esc_html__('Institution Name', 'thoth-data-connector') . '</th>'
            . '<th title="Optional free-text role/position at institution.">' . esc_html__('Position', 'thoth-data-connector') . '</th>'
            . '<th title="Required if institution is set. Integer order starting at 1.">' . esc_html__('Affiliation Ordinal', 'thoth-data-connector') . '</th>'
            . '<th></th>'
            . '</tr></thead><tbody data-repeater="affiliations">';

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $this->renderAffiliationRow($row, (int) $index);
        }

        if (empty($rows)) {
            $this->renderAffiliationRow([], 0);
        }

        echo '</tbody></table>';
        echo '</section>';
    }

    private function renderSubjectsEditor(array $rows): void
    {
        echo '<section class="thoth-meta-section">';
        echo '<h3>' . esc_html__('Subjects', 'thoth-data-connector') . '</h3>';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th>' . esc_html__('Code', 'thoth-data-connector') . '</th>'
            . '<th>' . esc_html__('Type', 'thoth-data-connector') . '</th>'
            . '<th>' . esc_html__('Ordinal', 'thoth-data-connector') . '</th>'
            . '<th></th>'
            . '</tr></thead><tbody data-repeater="subjects">';

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $this->renderSubjectRow($row, (int) $index);
        }

        if (empty($rows)) {
            $this->renderSubjectRow([], 0);
        }

        echo '</tbody></table>';
        echo '<p><button type="button" class="button button-secondary" data-toggle-advanced="subjects" data-open="0" data-label-show="'
            . esc_attr__('Show Advanced Fields', 'thoth-data-connector')
            . '" data-label-hide="'
            . esc_attr__('Hide Advanced Fields', 'thoth-data-connector')
            . '">'
            . esc_html__('Show Advanced Fields', 'thoth-data-connector')
            . '</button></p>';
            echo '</section>';
    }

    private function renderFundingsEditor(array $rows): void
    {
        echo '<section class="thoth-meta-section">';
        echo '<h3>' . esc_html__('Fundings', 'thoth-data-connector') . '</h3>';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th>' . esc_html__('Program', 'thoth-data-connector') . '</th>'
            . '<th>' . esc_html__('Project', 'thoth-data-connector') . '</th>'
            . '<th>' . esc_html__('Grant Number', 'thoth-data-connector') . '</th>'
            . '<th>' . esc_html__('Jurisdiction', 'thoth-data-connector') . '</th>'
            . '<th></th>'
            . '</tr></thead><tbody data-repeater="fundings">';

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $this->renderFundingRow($row, (int) $index);
        }

        if (empty($rows)) {
            $this->renderFundingRow([], 0);
        }

        echo '</tbody></table>';
        echo '<p><button type="button" class="button button-secondary" data-toggle-advanced="fundings" data-open="0" data-label-show="'
            . esc_attr__('Show Advanced Fields', 'thoth-data-connector')
            . '" data-label-hide="'
            . esc_attr__('Hide Advanced Fields', 'thoth-data-connector')
            . '">'
            . esc_html__('Show Advanced Fields', 'thoth-data-connector')
            . '</button></p>';
            echo '</section>';
    }

    private function renderLanguagesEditor(array $rows): void
    {
        echo '<section class="thoth-meta-section">';
        echo '<h3>' . esc_html__('Languages', 'thoth-data-connector') . '</h3>';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th title="ISO 639-3 language code (for example: eng, deu, fra).">' . esc_html__('Language Code', 'thoth-data-connector') . '</th>'
            . '<th title="Language relation relative to the work.">' . esc_html__('Language Relation', 'thoth-data-connector') . '</th>'
            . '<th title="Mark if this is the main language of the work.">' . esc_html__('Main', 'thoth-data-connector') . '</th>'
            . '<th></th>'
            . '</tr></thead><tbody data-repeater="languages">';

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $this->renderLanguageRow($row, (int) $index);
        }

        if (empty($rows)) {
            $this->renderLanguageRow([], 0);
        }

        echo '</tbody></table>';
        echo '</section>';
    }

    private function renderPublicationsEditor(array $rows): void
    {
        echo '<section class="thoth-meta-section"' . $this->buildWorkTypeVisibilityAttribute(self::SECTION_WORK_TYPE_VISIBILITY['publications']) . '>';
        echo '<h3>' . esc_html__('Publications', 'thoth-data-connector') . '</h3>';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th title="Select publication format type.">' . esc_html__('Publication Type', 'thoth-data-connector') . '</th>'
            . '<th title="Recommended. ISBN-13 of this publication instance.">' . esc_html__('ISBN', 'thoth-data-connector') . '</th>'
            . '<th title="Width value (recommended for print formats).">' . esc_html__('Width', 'thoth-data-connector') . '</th>'
            . '<th title="Height value (recommended for print formats).">' . esc_html__('Height', 'thoth-data-connector') . '</th>'
            . '<th title="Depth value (recommended for print formats).">' . esc_html__('Depth', 'thoth-data-connector') . '</th>'
            . '<th title="Weight value (recommended for print formats).">' . esc_html__('Weight', 'thoth-data-connector') . '</th>'
            . '<th></th>'
            . '</tr></thead><tbody data-repeater="publications">';

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $this->renderPublicationRow($row, (int) $index);
        }

        if (empty($rows)) {
            $this->renderPublicationRow([], 0);
        }

        echo '</tbody></table>';
        echo '</section>';
    }

    private function buildWorkTypeVisibilityAttribute(array $visibleWorkTypes): string
    {
        if ($visibleWorkTypes === []) {
            return '';
        }

        $normalized = [];
        foreach ($visibleWorkTypes as $workType) {
            $value = $this->normalizeWorkType((string) $workType);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        $normalized = array_values(array_unique($normalized));
        if ($normalized === []) {
            return '';
        }

        return ' data-thoth-work-types="' . esc_attr(implode(',', $normalized)) . '"';
    }

    private function getUiVisibilitySettings(): array
    {
        $options = get_option(SettingsPage::OPTION_NAME, []);
        $options = is_array($options) ? $options : [];
        $rawVisibility = isset($options[SettingsPage::UI_VISIBILITY_OPTION_KEY]) && is_array($options[SettingsPage::UI_VISIBILITY_OPTION_KEY])
            ? $options[SettingsPage::UI_VISIBILITY_OPTION_KEY]
            : [];

        return SettingsPage::normalizeUiVisibility($rawVisibility);
    }

    private function isUiFieldVisible(string $key): bool
    {
        if ($this->uiVisibility === []) {
            $this->uiVisibility = $this->getUiVisibilitySettings();
        }

        return $this->isUiKeyVisibleIn($this->uiVisibility, $key);
    }

    private function isUiKeyVisibleIn(array $visibility, string $key): bool
    {
        if (!isset($visibility[$key])) {
            return true;
        }

        return (string) $visibility[$key] === '1';
    }

    private function preserveHiddenBaseFields(int $postId, array &$baseFields, array $visibility): void
    {
        $fieldGroups = [
            'subtitle' => ['subtitle'],
            'edition' => ['edition'],
            'doi' => ['doi'],
            'publicationDate' => ['publicationDate'],
            'placeOfPublication' => ['placeOfPublication'],
            'coverUrl' => ['coverUrl'],
            'cover' => ['coverAttachmentId'],
            'coverCaption' => ['coverCaption'],
            'landingPage' => ['landingPage'],
            'lccn' => ['lccn'],
            'oclc' => ['oclc'],
            'internalReference' => ['internalReference'],
            'pageCount' => ['pageCount'],
            'pageBreakdown' => ['pageBreakdown'],
            'pageRange' => ['firstPage', 'lastPage'],
            'imageCount' => ['imageCount'],
            'tableCount' => ['tableCount'],
            'audioCount' => ['audioCount'],
            'videoCount' => ['videoCount'],
            'shortAbstract' => ['shortAbstract'],
            'longAbstract' => ['longAbstract'],
            'generalNote' => ['generalNote'],
            'bibliographyNote' => ['bibliographyNote'],
            'tableOfContents' => ['tableOfContents'],
        ];

        foreach ($fieldGroups as $uiKey => $fieldKeys) {
            if ($this->isUiKeyVisibleIn($visibility, $uiKey)) {
                continue;
            }

            foreach ($fieldKeys as $fieldKey) {
                $metaKey = $this->getBaseFieldMetaKey($fieldKey);
                if ($metaKey === '') {
                    continue;
                }
                $baseFields[$fieldKey] = (string) get_post_meta($postId, $metaKey, true);
            }
        }
    }

    private function preserveHiddenSectionRows(int $postId, array $visibility, array &$contributors, array &$affiliations, array &$subjects, array &$fundings, array &$publications, array &$languages, array &$issues, array &$series, array &$relatedWorks): void
    {
        if (!$this->isUiKeyVisibleIn($visibility, 'section_contributors')) {
            $contributors = $this->decodeJsonMeta($postId, '_thoth_nested_contributors');
        }
        if (!$this->isUiKeyVisibleIn($visibility, 'section_affiliations')) {
            $affiliations = $this->decodeJsonMeta($postId, '_thoth_nested_affiliations');
        }
        if (!$this->isUiKeyVisibleIn($visibility, 'section_subjects')) {
            $subjects = $this->decodeJsonMeta($postId, '_thoth_nested_subjects');
        }
        if (!$this->isUiKeyVisibleIn($visibility, 'section_fundings')) {
            $fundings = $this->decodeJsonMeta($postId, '_thoth_nested_fundings');
        }
        if (!$this->isUiKeyVisibleIn($visibility, 'section_publications')) {
            $publications = $this->decodeJsonMeta($postId, '_thoth_nested_publications');
        }
        if (!$this->isUiKeyVisibleIn($visibility, 'section_languages')) {
            $languages = $this->decodeJsonMeta($postId, '_thoth_nested_languages');
        }
        if (!$this->isUiKeyVisibleIn($visibility, 'section_issues')) {
            $issues = $this->decodeJsonMeta($postId, '_thoth_nested_issues');
        }
        if (!$this->isUiKeyVisibleIn($visibility, 'section_series')) {
            $series = $this->decodeJsonMeta($postId, '_thoth_nested_series');
        }
        if (!$this->isUiKeyVisibleIn($visibility, 'section_related_works')) {
            $relatedWorks = $this->decodeJsonMeta($postId, '_thoth_nested_related_works');
        }
    }

    private function renderRelatedWorksEditor(int $postId, array $rows): void
    {
        $syncInverse = (string) get_post_meta($postId, '_thoth_sync_inverse_related_works', true) === '1';

        echo '<section class="thoth-meta-section">';
        echo '<h3>' . esc_html__('Related Works', 'thoth-data-connector') . '</h3>';
        echo '<p><label><input type="checkbox" name="thoth_sync_inverse_related_works" value="1" ' . checked($syncInverse, true, false) . ' /> '
            . esc_html__('Automatically maintain inverse relations on related works (optional).', 'thoth-data-connector')
            . '</label></p>';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th title="Internal Work ID of the related work (for example OR:12).">' . esc_html__('Related Work ID', 'thoth-data-connector') . '</th>'
            . '<th title="Select the relation type defined by Thoth.">' . esc_html__('Relation Type', 'thoth-data-connector') . '</th>'
            . '<th title="Integer order for listing related works, starting at 1.">' . esc_html__('Relation Ordinal', 'thoth-data-connector') . '</th>'
            . '<th></th>'
            . '</tr></thead><tbody data-repeater="related_works">';

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $this->renderRelatedWorkRow($row, (int) $index);
        }

        if (empty($rows)) {
            $this->renderRelatedWorkRow([], 0);
        }

        echo '</tbody></table>';
        echo '</section>';
    }

    private function renderIssuesEditor(array $rows): void
    {
        echo '<section class="thoth-meta-section"' . $this->buildWorkTypeVisibilityAttribute(self::SECTION_WORK_TYPE_VISIBILITY['issues']) . '>';
        echo '<h3>' . esc_html__('Issues', 'thoth-data-connector') . '</h3>';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th title="Issue UUID.">' . esc_html__('Issue ID', 'thoth-data-connector') . '</th>'
            . '<th title="Issue number or label.">' . esc_html__('Issue Number', 'thoth-data-connector') . '</th>'
            . '<th title="Integer order for multiple issue links.">' . esc_html__('Issue Ordinal', 'thoth-data-connector') . '</th>'
            . '<th></th>'
            . '</tr></thead><tbody data-repeater="issues">';

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $this->renderIssueRow($row, (int) $index);
        }

        if (empty($rows)) {
            $this->renderIssueRow([], 0);
        }

        echo '</tbody></table>';
        echo '</section>';
    }

    private function renderSeriesEditor(array $rows): void
    {
        echo '<section class="thoth-meta-section"' . $this->buildWorkTypeVisibilityAttribute(self::SECTION_WORK_TYPE_VISIBILITY['series']) . '>';
        echo '<h3>' . esc_html__('Series', 'thoth-data-connector') . '</h3>';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th title="Series UUID.">' . esc_html__('Series ID', 'thoth-data-connector') . '</th>'
            . '<th title="Display name of the series.">' . esc_html__('Series Name', 'thoth-data-connector') . '</th>'
            . '<th title="Integer order for multiple series links.">' . esc_html__('Series Ordinal', 'thoth-data-connector') . '</th>'
            . '<th></th>'
            . '</tr></thead><tbody data-repeater="series">';

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $this->renderSeriesRow($row, (int) $index);
        }

        if (empty($rows)) {
            $this->renderSeriesRow([], 0);
        }

        echo '</tbody></table>';
        echo '</section>';
    }

    private function buildCreateEntityUrl(string $entityType): string
    {
        return add_query_arg([
            'post_type' => PublicationPostType::POST_TYPE,
            'thoth_entity_type' => $entityType,
        ], admin_url('post-new.php'));
    }

    private function renderEntityLookupUi(string $entityType, string $placeholder, string $lookupValue = '', string $openUrl = '', int $postId = 0): void
    {
        echo '<div class="thoth-entity-lookup-wrap" style="margin-top:6px;">';
        echo '<input type="text" class="regular-text" value="' . esc_attr($lookupValue) . '" placeholder="' . esc_attr($placeholder) . '" data-thoth-entity-lookup="' . esc_attr($entityType) . '" autocomplete="off" />';
        echo '<input type="hidden" value="' . esc_attr((string) $postId) . '" data-thoth-entity-post-id="' . esc_attr($entityType) . '" />';
        echo '<div class="thoth-entity-results" data-thoth-entity-results="' . esc_attr($entityType) . '"></div>';
        echo '<small class="thoth-entity-warning" data-thoth-entity-warning="' . esc_attr($entityType) . '" style="display:none;"></small>';
        echo '<div class="thoth-entity-links" style="margin-top:4px;">';

        if ($openUrl !== '') {
            echo '<a href="' . esc_url($openUrl) . '" target="_blank" rel="noopener" data-thoth-entity-open-link="' . esc_attr($entityType) . '">' . esc_html__('Open entity', 'thoth-data-connector') . '</a>';
        } else {
            echo '<a href="#" style="display:none;" target="_blank" rel="noopener" data-thoth-entity-open-link="' . esc_attr($entityType) . '">' . esc_html__('Open entity', 'thoth-data-connector') . '</a>';
        }

        echo ' <span aria-hidden="true">·</span> ';
        echo '<a href="' . esc_url($this->buildCreateEntityUrl($entityType)) . '" target="_blank" rel="noopener" data-thoth-entity-create-link="' . esc_attr($entityType) . '">' . esc_html__('Create new entity', 'thoth-data-connector') . '</a>';
        echo '</div>';
        echo '</div>';
    }

    private function renderRepeaterActionsCell(string $repeaterKey): void
    {
        echo '<td class="thoth-row-actions">';
        echo '<button type="button" class="thoth-icon-button thoth-icon-button-add" data-add-row="' . esc_attr($repeaterKey) . '" aria-label="' . esc_attr__('Add', 'thoth-data-connector') . '" title="' . esc_attr__('Add', 'thoth-data-connector') . '"><span class="material-icons" aria-hidden="true">add</span></button>';
        echo '<button type="button" class="thoth-icon-button thoth-icon-button-delete" data-remove-row="1" aria-label="' . esc_attr__('Delete', 'thoth-data-connector') . '" title="' . esc_attr__('Delete', 'thoth-data-connector') . '"><span class="material-icons" aria-hidden="true">delete</span></button>';
        echo '</td>';
    }

    private function renderContributorRow(array $row, int $index): void
    {
        $base = 'thoth_nested_contributors[' . $index . ']';
        echo '<tr>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($base . '[fullName]') . '" value="' . esc_attr((string) ($row['fullName'] ?? '')) . '" data-thoth-entity-target-label="contributor" />';
        $this->renderEntityLookupUi('contributor', 'Search local contributors by name, UUID or ORCID', (string) ($row['fullName'] ?? ''));
        echo '<div class="thoth-advanced-fields" style="display:none;margin-top:8px;">';
        echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:8px;">';
        echo '<label>' . esc_html__('Contribution ID', 'thoth-data-connector') . '<br /><input type="text" class="regular-text" name="' . esc_attr($base . '[contributionId]') . '" value="' . esc_attr((string) ($row['contributionId'] ?? '')) . '" placeholder="UUID" pattern="^[a-fA-F0-9-]{36}$" title="UUID erwartet" /></label>';
        echo '<label>' . esc_html__('Contributor ID', 'thoth-data-connector') . '<br /><input type="text" class="regular-text" name="' . esc_attr($base . '[contributorId]') . '" value="' . esc_attr((string) ($row['contributorId'] ?? '')) . '" placeholder="UUID" pattern="^[a-fA-F0-9-]{36}$" title="UUID erwartet" data-thoth-entity-target-id="contributor" /></label>';
        echo '<label>' . esc_html__('First Name', 'thoth-data-connector') . '<br /><input type="text" class="regular-text" name="' . esc_attr($base . '[firstName]') . '" value="' . esc_attr((string) ($row['firstName'] ?? '')) . '" /></label>';
        echo '<label>' . esc_html__('Last Name', 'thoth-data-connector') . '<br /><input type="text" class="regular-text" name="' . esc_attr($base . '[lastName]') . '" value="' . esc_attr((string) ($row['lastName'] ?? '')) . '" /></label>';
        echo '</div></div></td>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($base . '[contributionType]') . '" value="' . esc_attr((string) ($row['contributionType'] ?? '')) . '" /></td>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($base . '[orcid]') . '" value="' . esc_attr((string) ($row['orcid'] ?? '')) . '" placeholder="0000-0000-0000-0000" pattern="^\\d{4}-\\d{4}-\\d{4}-\\d{3}[\\dX]$" title="ORCID im Format 0000-0000-0000-0000" data-thoth-entity-target-secondary="contributor" /></td>';
        echo '<td><label><input type="checkbox" name="' . esc_attr($base . '[mainContribution]') . '" value="1" ' . checked(!empty($row['mainContribution']), true, false) . ' /> ' . esc_html__('Yes', 'thoth-data-connector') . '</label></td>';
        $this->renderRepeaterActionsCell('contributors');
        echo '</tr>';
    }

    private function renderSubjectRow(array $row, int $index): void
    {
        $base = 'thoth_nested_subjects[' . $index . ']';
        echo '<tr>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($base . '[subjectCode]') . '" value="' . esc_attr((string) ($row['subjectCode'] ?? '')) . '" />';
        echo '<div class="thoth-advanced-fields" style="display:none;margin-top:8px;">';
        echo '<label>' . esc_html__('Subject ID', 'thoth-data-connector') . '<br /><input type="text" class="regular-text" name="' . esc_attr($base . '[subjectId]') . '" value="' . esc_attr((string) ($row['subjectId'] ?? '')) . '" placeholder="UUID" pattern="^[a-fA-F0-9-]{36}$" title="UUID erwartet" /></label>';
        echo '</div></td>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($base . '[subjectType]') . '" value="' . esc_attr((string) ($row['subjectType'] ?? '')) . '" /></td>';
        echo '<td><input type="text" class="small-text" name="' . esc_attr($base . '[subjectOrdinal]') . '" value="' . esc_attr((string) ($row['subjectOrdinal'] ?? '')) . '" /></td>';
        $this->renderRepeaterActionsCell('subjects');
        echo '</tr>';
    }

    private function renderFundingRow(array $row, int $index): void
    {
        $base = 'thoth_nested_fundings[' . $index . ']';
        echo '<tr>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($base . '[program]') . '" value="' . esc_attr((string) ($row['program'] ?? '')) . '" />';
        echo '<div class="thoth-advanced-fields" style="display:none;margin-top:8px;">';
        echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:8px;">';
        echo '<label>' . esc_html__('Funding ID', 'thoth-data-connector') . '<br /><input type="text" class="regular-text" name="' . esc_attr($base . '[fundingId]') . '" value="' . esc_attr((string) ($row['fundingId'] ?? '')) . '" placeholder="UUID" pattern="^[a-fA-F0-9-]{36}$" title="UUID erwartet" /></label>';
        echo '<label>' . esc_html__('Institution ID', 'thoth-data-connector') . '<br /><input type="text" class="regular-text" name="' . esc_attr($base . '[institutionId]') . '" value="' . esc_attr((string) ($row['institutionId'] ?? '')) . '" placeholder="UUID" pattern="^[a-fA-F0-9-]{36}$" title="UUID erwartet" /></label>';
        echo '<label>' . esc_html__('Project Short Name', 'thoth-data-connector') . '<br /><input type="text" class="regular-text" name="' . esc_attr($base . '[projectShortName]') . '" value="' . esc_attr((string) ($row['projectShortName'] ?? '')) . '" /></label>';
        echo '</div></div></td>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($base . '[projectName]') . '" value="' . esc_attr((string) ($row['projectName'] ?? '')) . '" /></td>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($base . '[grantNumber]') . '" value="' . esc_attr((string) ($row['grantNumber'] ?? '')) . '" /></td>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($base . '[jurisdiction]') . '" value="' . esc_attr((string) ($row['jurisdiction'] ?? '')) . '" /></td>';
        $this->renderRepeaterActionsCell('fundings');
        echo '</tr>';
    }

    private function renderLanguageRow(array $row, int $index): void
    {
        $base = 'thoth_nested_languages[' . $index . ']';
        $relation = $this->normalizeLanguageRelation((string) ($row['languageRelation'] ?? ''));

        echo '<tr>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($base . '[languageCode]') . '" value="' . esc_attr((string) ($row['languageCode'] ?? '')) . '" placeholder="eng" pattern="^[a-zA-Z]{3}$" title="ISO 639-3 language code, for example eng, deu, fra." /></td>';
        echo '<td><select name="' . esc_attr($base . '[languageRelation]') . '" title="Language relation relative to the work.">';
        echo '<option value="">' . esc_html__('Select…', 'thoth-data-connector') . '</option>';
        foreach (self::LANGUAGE_RELATION_OPTIONS as $optionValue => $optionLabel) {
            echo '<option value="' . esc_attr($optionValue) . '" ' . selected($relation, $optionValue, false) . '>' . esc_html($optionLabel) . '</option>';
        }
        echo '</select></td>';
        echo '<td><label><input type="checkbox" name="' . esc_attr($base . '[mainLanguage]') . '" value="1" ' . checked(!empty($row['mainLanguage']), true, false) . ' /> ' . esc_html__('Yes', 'thoth-data-connector') . '</label></td>';
        $this->renderRepeaterActionsCell('languages');
        echo '</tr>';
    }

    private function renderPublicationRow(array $row, int $index): void
    {
        $base = 'thoth_nested_publications[' . $index . ']';
        $publicationType = $this->normalizePublicationType((string) ($row['publicationType'] ?? ''));

        echo '<tr>';
        echo '<td><select name="' . esc_attr($base . '[publicationType]') . '" title="Select publication format type.">';
        echo '<option value="">' . esc_html__('Select…', 'thoth-data-connector') . '</option>';
        foreach (self::PUBLICATION_TYPE_OPTIONS as $optionValue => $optionLabel) {
            echo '<option value="' . esc_attr($optionValue) . '" ' . selected($publicationType, $optionValue, false) . '>' . esc_html($optionLabel) . '</option>';
        }
        echo '</select></td>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($base . '[isbn]') . '" value="' . esc_attr((string) ($row['isbn'] ?? '')) . '" placeholder="978..." pattern="^[0-9Xx-]{10,17}$" title="ISBN-13 preferred. Digits, hyphen and X are accepted." /></td>';
        echo '<td><input type="text" class="small-text" name="' . esc_attr($base . '[width]') . '" value="' . esc_attr((string) ($row['width'] ?? '')) . '" title="Width value for this publication format." /></td>';
        echo '<td><input type="text" class="small-text" name="' . esc_attr($base . '[height]') . '" value="' . esc_attr((string) ($row['height'] ?? '')) . '" title="Height value for this publication format." /></td>';
        echo '<td><input type="text" class="small-text" name="' . esc_attr($base . '[depth]') . '" value="' . esc_attr((string) ($row['depth'] ?? '')) . '" title="Depth value for this publication format." /></td>';
        echo '<td><input type="text" class="small-text" name="' . esc_attr($base . '[weight]') . '" value="' . esc_attr((string) ($row['weight'] ?? '')) . '" title="Weight value for this publication format." /></td>';
        $this->renderRepeaterActionsCell('publications');
        echo '</tr>';
    }

    private function renderAffiliationRow(array $row, int $index): void
    {
        $base = 'thoth_nested_affiliations[' . $index . ']';

        echo '<tr>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($base . '[contributionId]') . '" value="' . esc_attr((string) ($row['contributionId'] ?? '')) . '" placeholder="UUID" pattern="^[a-fA-F0-9-]{36}$" title="Contribution UUID this affiliation belongs to." /></td>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($base . '[contributorId]') . '" value="' . esc_attr((string) ($row['contributorId'] ?? '')) . '" placeholder="UUID" pattern="^[a-fA-F0-9-]{36}$" title="Contributor UUID this affiliation belongs to." /></td>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($base . '[institutionId]') . '" value="' . esc_attr((string) ($row['institutionId'] ?? '')) . '" placeholder="UUID" pattern="^[a-fA-F0-9-]{36}$" title="Institution UUID from Thoth/ROR context." data-thoth-entity-target-id="affiliation" /></td>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($base . '[institutionName]') . '" value="' . esc_attr((string) ($row['institutionName'] ?? '')) . '" title="Institution display name." data-thoth-entity-target-label="affiliation" />';
        $this->renderEntityLookupUi('affiliation', 'Search local affiliations by institution name or UUID', (string) ($row['institutionName'] ?? ''));
        echo '</td>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($base . '[position]') . '" value="' . esc_attr((string) ($row['position'] ?? '')) . '" title="Optional free-text role/position at institution." /></td>';
        echo '<td><input type="number" min="1" class="small-text" name="' . esc_attr($base . '[affiliationOrdinal]') . '" value="' . esc_attr((string) ($row['affiliationOrdinal'] ?? '')) . '" title="Required if institution is set. Integer order starting at 1." /></td>';
        $this->renderRepeaterActionsCell('affiliations');
        echo '</tr>';
    }

    private function renderRelatedWorkRow(array $row, int $index): void
    {
        $base = 'thoth_nested_related_works[' . $index . ']';
        $relationType = $this->normalizeRelationType((string) ($row['relationType'] ?? ''));
        $relatedWorkId = (string) ($row['relatedWorkId'] ?? '');
        $relatedPostId = $this->resolveRelatedWorkPostByWorkId($relatedWorkId);
        $relatedLookupLabel = $this->buildRelatedWorkLookupLabel($relatedWorkId, $relatedPostId);
        $relatedEditUrl = $relatedPostId > 0 ? get_edit_post_link($relatedPostId, 'raw') : '';

        echo '<tr>';
        echo '<td>';
        echo '<input type="text" class="regular-text" value="' . esc_attr($relatedLookupLabel) . '" placeholder="Search by title or Work ID" title="Search local Thoth publications and pick one." data-thoth-entity-lookup="related_work" autocomplete="off" />';
        echo '<input type="hidden" value="' . esc_attr((string) $relatedPostId) . '" data-thoth-entity-post-id="related_work" />';
        echo '<div class="thoth-entity-results" data-thoth-entity-results="related_work"></div>';
        echo '<small class="thoth-entity-warning" data-thoth-entity-warning="related_work" style="display:none;"></small>';
        echo '<input type="text" class="regular-text" name="' . esc_attr($base . '[relatedWorkId]') . '" value="' . esc_attr($relatedWorkId) . '" placeholder="OR:1" pattern="^(?:([A-Za-z0-9_-]+:\\d+|\\d+)|[a-fA-F0-9-]{36})$" title="Use internal Work ID (for example OR:12), number, or UUID." style="margin-top:6px;" data-thoth-entity-target-id="related_work" />';
        echo '<div class="thoth-entity-links" style="margin-top:6px;">';
        if (is_string($relatedEditUrl) && $relatedEditUrl !== '') {
            echo '<a href="' . esc_url($relatedEditUrl) . '" target="_blank" rel="noopener" data-thoth-entity-open-link="related_work">'
                . esc_html__('Open related work', 'thoth-data-connector')
                . '</a>';
        } else {
            echo '<a href="#" style="display:none;" target="_blank" rel="noopener" data-thoth-entity-open-link="related_work">'
                . esc_html__('Open related work', 'thoth-data-connector')
                . '</a>';
        }
        echo ' <span aria-hidden="true">·</span> ';
        echo '<a href="' . esc_url($this->buildCreateEntityUrl('related_work')) . '" target="_blank" rel="noopener" data-thoth-entity-create-link="related_work">'
            . esc_html__('Create new entity', 'thoth-data-connector')
            . '</a>';
        echo '</div>';
        echo '</td>';
        echo '<td><select name="' . esc_attr($base . '[relationType]') . '" title="Select the relation type defined by Thoth.">';
        echo '<option value="">' . esc_html__('Select…', 'thoth-data-connector') . '</option>';
        foreach (self::RELATION_TYPE_OPTIONS as $optionValue => $optionLabel) {
            echo '<option value="' . esc_attr($optionValue) . '" ' . selected($relationType, $optionValue, false) . '>' . esc_html($optionLabel) . '</option>';
        }
        echo '</select></td>';
        echo '<td><input type="number" min="1" class="small-text" name="' . esc_attr($base . '[relationOrdinal]') . '" value="' . esc_attr((string) ($row['relationOrdinal'] ?? '')) . '" title="Integer order for listing related works, starting at 1." /></td>';
        $this->renderRepeaterActionsCell('related_works');
        echo '</tr>';
    }

    private function renderIssueRow(array $row, int $index): void
    {
        $base = 'thoth_nested_issues[' . $index . ']';

        echo '<tr>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($base . '[issueId]') . '" value="' . esc_attr((string) ($row['issueId'] ?? '')) . '" placeholder="UUID" pattern="^[a-fA-F0-9-]{36}$" title="Issue UUID." data-thoth-entity-target-id="issue" /></td>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($base . '[issueNumber]') . '" value="' . esc_attr((string) ($row['issueNumber'] ?? '')) . '" title="Issue number or label." data-thoth-entity-target-label="issue" />';
        $this->renderEntityLookupUi('issue', 'Search local issues by number or UUID', (string) ($row['issueNumber'] ?? ''));
        echo '</td>';
        echo '<td><input type="number" min="1" class="small-text" name="' . esc_attr($base . '[issueOrdinal]') . '" value="' . esc_attr((string) ($row['issueOrdinal'] ?? '')) . '" title="Integer order for multiple issue links." /></td>';
        $this->renderRepeaterActionsCell('issues');
        echo '</tr>';
    }

    private function renderSeriesRow(array $row, int $index): void
    {
        $base = 'thoth_nested_series[' . $index . ']';

        echo '<tr>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($base . '[seriesId]') . '" value="' . esc_attr((string) ($row['seriesId'] ?? '')) . '" placeholder="UUID" pattern="^[a-fA-F0-9-]{36}$" title="Series UUID." data-thoth-entity-target-id="series" /></td>';
        echo '<td><input type="text" class="regular-text" name="' . esc_attr($base . '[seriesName]') . '" value="' . esc_attr((string) ($row['seriesName'] ?? '')) . '" title="Display name of the series." data-thoth-entity-target-label="series" />';
        $this->renderEntityLookupUi('series', 'Search local series by name or UUID', (string) ($row['seriesName'] ?? ''));
        echo '</td>';
        echo '<td><input type="number" min="1" class="small-text" name="' . esc_attr($base . '[seriesOrdinal]') . '" value="' . esc_attr((string) ($row['seriesOrdinal'] ?? '')) . '" title="Integer order for multiple series links." /></td>';
        $this->renderRepeaterActionsCell('series');
        echo '</tr>';
    }

    private function renderRepeaterScript(): void
    {
        ?>
        <script>
            (function () {
                const ensureErrorNode = function (input) {
                    const parent = input.parentElement;
                    if (!parent) {
                        return null;
                    }

                    let node = parent.querySelector('.thoth-inline-error');
                    if (!node) {
                        node = document.createElement('small');
                        node.className = 'thoth-inline-error';
                        node.style.display = 'none';
                        parent.appendChild(node);
                    }

                    return node;
                };

                const openAdvancedForInput = function (input) {
                    const advancedWrap = input.closest('.thoth-advanced-fields');
                    if (!advancedWrap) {
                        return;
                    }

                    advancedWrap.style.display = 'block';

                    const tbody = input.closest('tbody[data-repeater]');
                    if (!tbody) {
                        return;
                    }

                    const key = tbody.getAttribute('data-repeater');
                    if (!key) {
                        return;
                    }

                    const button = document.querySelector('[data-toggle-advanced="' + key + '"]');
                    if (!button) {
                        return;
                    }

                    button.setAttribute('data-open', '1');
                    const hideLabel = button.getAttribute('data-label-hide');
                    if (hideLabel) {
                        button.textContent = hideLabel;
                    }

                    tbody.querySelectorAll('.thoth-advanced-fields').forEach(function (field) {
                        field.style.display = 'block';
                    });
                };

                const validateInput = function (input) {
                    if (!(input instanceof HTMLInputElement)) {
                        return true;
                    }

                    if (!input.hasAttribute('pattern')) {
                        return true;
                    }

                    const value = (input.value || '').trim();
                    const errorNode = ensureErrorNode(input);

                    if (value === '') {
                        input.classList.remove('thoth-invalid');
                        if (errorNode) {
                            errorNode.textContent = '';
                            errorNode.style.display = 'none';
                        }
                        return true;
                    }

                    const isValid = input.checkValidity();
                    if (isValid) {
                        input.classList.remove('thoth-invalid');
                        if (errorNode) {
                            errorNode.textContent = '';
                            errorNode.style.display = 'none';
                        }
                        return true;
                    }

                    input.classList.add('thoth-invalid');
                    openAdvancedForInput(input);
                    if (errorNode) {
                        errorNode.textContent = input.getAttribute('title') || 'Ungültiges Format';
                        errorNode.style.display = 'block';
                    }

                    return false;
                };

                const validateScope = function (scope) {
                    const fields = Array.from(scope.querySelectorAll('input[pattern]'));
                    let ok = true;
                    fields.forEach(function (field) {
                        if (!validateInput(field)) {
                            ok = false;
                        }
                    });
                    return ok;
                };

                const applyWorkTypeVisibility = function () {
                    const workTypeSelect = document.getElementById('thoth-base-workType');
                    const selectedWorkType = workTypeSelect ? (workTypeSelect.value || '').trim() : '';

                    document.querySelectorAll('[data-thoth-work-types]').forEach(function (node) {
                        const attr = node.getAttribute('data-thoth-work-types') || '';
                        const allowed = attr.split(',').map(function (entry) {
                            return entry.trim();
                        }).filter(Boolean);

                        const visible = selectedWorkType === '' || allowed.includes(selectedWorkType);
                        node.style.display = visible ? '' : 'none';

                        node.querySelectorAll('input,select,textarea,button').forEach(function (field) {
                            if (field instanceof HTMLElement) {
                                field.disabled = !visible;
                            }
                        });
                    });
                };

                const initCoverMediaPicker = function () {
                    const openButton = document.querySelector('[data-thoth-cover-picker]');
                    const removeButton = document.querySelector('[data-thoth-cover-remove]');
                    const urlInput = document.getElementById('thoth-base-coverUrl');
                    const idInput = document.getElementById('thoth-base-coverAttachmentId');
                    const preview = document.getElementById('thoth-cover-preview');

                    if (!openButton || !urlInput || !idInput) {
                        return;
                    }

                    let frame;

                    const getMediaApi = function () {
                        if (window.wp && window.wp.media) {
                            return window.wp.media;
                        }

                        return null;
                    };

                    const updatePreview = function (url) {
                        if (!preview) {
                            return;
                        }

                        if (!url) {
                            preview.setAttribute('src', '');
                            preview.style.display = 'none';
                            return;
                        }

                        preview.setAttribute('src', url);
                        preview.style.display = '';
                    };

                    openButton.addEventListener('click', function () {
                        const mediaApi = getMediaApi();
                        if (!mediaApi) {
                            return;
                        }

                        if (!frame) {
                            frame = mediaApi({
                                title: 'Select Cover Image',
                                button: { text: 'Use this image' },
                                library: { type: 'image' },
                                multiple: false
                            });

                            frame.on('select', function () {
                                const selection = frame.state().get('selection');
                                const attachment = selection && selection.first ? selection.first().toJSON() : null;
                                if (!attachment) {
                                    return;
                                }

                                const url = attachment.url || '';
                                urlInput.value = url;
                                idInput.value = attachment.id ? String(attachment.id) : '';
                                updatePreview(url);
                            });
                        }

                        frame.open();
                    });

                    if (removeButton) {
                        removeButton.addEventListener('click', function () {
                            urlInput.value = '';
                            idInput.value = '';
                            updatePreview('');
                        });
                    }

                    urlInput.addEventListener('input', function () {
                        if (!urlInput.value) {
                            idInput.value = '';
                        }
                        updatePreview(urlInput.value || '');
                    });

                    updatePreview(urlInput.value || '');
                };

                const rebalanceNames = function (tbody, key) {
                    const rows = Array.from(tbody.querySelectorAll('tr'));
                    rows.forEach(function (row, index) {
                        row.querySelectorAll('input,select,textarea').forEach(function (field) {
                            const current = field.getAttribute('name') || '';
                            const updated = current.replace(new RegExp('thoth_nested_' + key + '\\[\\d+\\]'), 'thoth_nested_' + key + '[' + index + ']');
                            field.setAttribute('name', updated);
                        });
                    });
                };

                const initEntityLookups = function () {
                    const nonceInput = document.querySelector('input[name="thoth_publication_nested_nonce"]');
                    const nonce = nonceInput instanceof HTMLInputElement ? nonceInput.value : '';
                    const postIdInput = document.getElementById('post_ID');
                    const currentPostId = postIdInput instanceof HTMLInputElement ? (postIdInput.value || '') : '';

                    const normalize = function (value) {
                        return String(value || '').trim().toLowerCase().replace(/\s+/g, ' ');
                    };

                    const clearResults = function (resultsNode) {
                        if (!(resultsNode instanceof HTMLElement)) {
                            return;
                        }
                        resultsNode.innerHTML = '';
                    };

                    const setOpenLink = function (row, type, editUrl) {
                        const link = row.querySelector('[data-thoth-entity-open-link="' + type + '"]');
                        if (!(link instanceof HTMLAnchorElement)) {
                            return;
                        }

                        if (!editUrl) {
                            link.setAttribute('href', '#');
                            link.style.display = 'none';
                            return;
                        }

                        link.setAttribute('href', editUrl);
                        link.style.display = '';
                    };

                    const updateCreateLink = function (row, type, term) {
                        const link = row.querySelector('[data-thoth-entity-create-link="' + type + '"]');
                        if (!(link instanceof HTMLAnchorElement)) {
                            return;
                        }

                        const baseHref = link.getAttribute('data-base-href') || link.getAttribute('href') || '';
                        if (baseHref === '') {
                            return;
                        }

                        if (!link.getAttribute('data-base-href')) {
                            link.setAttribute('data-base-href', baseHref);
                        }

                        const url = new URL(baseHref, window.location.origin);
                        if (term) {
                            url.searchParams.set('thoth_entity_term', term);
                        } else {
                            url.searchParams.delete('thoth_entity_term');
                        }
                        link.setAttribute('href', url.toString());
                    };

                    const setDuplicateWarning = function (row, type, term, items) {
                        const warningNode = row.querySelector('[data-thoth-entity-warning="' + type + '"]');
                        if (!(warningNode instanceof HTMLElement)) {
                            return;
                        }

                        const normalizedTerm = normalize(term);
                        if (normalizedTerm.length < 3 || !Array.isArray(items) || !items.length) {
                            warningNode.textContent = '';
                            warningNode.style.display = 'none';
                            return;
                        }

                        const likelyDuplicate = items.some(function (item) {
                            const score = Number(item && item.score ? item.score : 0);
                            const label = normalize(item && item.label ? item.label : item && item.displayLabel ? item.displayLabel : '');
                            const idValue = normalize(item && item.idValue ? item.idValue : '');
                            const secondary = normalize(item && item.secondary ? item.secondary : '');

                            if (score >= 90) {
                                return true;
                            }

                            if (label && (label.includes(normalizedTerm) || normalizedTerm.includes(label))) {
                                return true;
                            }

                            if (idValue && idValue.includes(normalizedTerm)) {
                                return true;
                            }

                            return !!(secondary && secondary.includes(normalizedTerm));
                        });

                        if (!likelyDuplicate) {
                            warningNode.textContent = '';
                            warningNode.style.display = 'none';
                            return;
                        }

                        warningNode.textContent = 'Likely duplicate exists locally. Please select an existing entity if it matches.';
                        warningNode.style.display = 'block';
                    };

                    const renderResultChips = function (row, type, items, applyItem) {
                        const resultsNode = row.querySelector('[data-thoth-entity-results="' + type + '"]');
                        clearResults(resultsNode);

                        if (!(resultsNode instanceof HTMLElement) || !Array.isArray(items) || !items.length) {
                            return;
                        }

                        items.forEach(function (item) {
                            const chip = document.createElement('button');
                            chip.type = 'button';
                            chip.className = 'thoth-entity-chip';
                            chip.textContent = item.displayLabel || item.label || item.idValue || '';
                            chip.addEventListener('click', function () {
                                applyItem(item);
                            });
                            resultsNode.appendChild(chip);
                        });
                    };

                    const bindLookupInput = function (row, lookupInput) {
                        if (!(lookupInput instanceof HTMLInputElement)) {
                            return;
                        }

                        const type = lookupInput.getAttribute('data-thoth-entity-lookup') || '';
                        if (type === '') {
                            return;
                        }

                        const idInput = row.querySelector('[data-thoth-entity-target-id="' + type + '"]');
                        const labelInput = row.querySelector('[data-thoth-entity-target-label="' + type + '"]');
                        const secondaryInput = row.querySelector('[data-thoth-entity-target-secondary="' + type + '"]');
                        const postIdHidden = row.querySelector('[data-thoth-entity-post-id="' + type + '"]');

                        if (!(idInput instanceof HTMLInputElement) && !(labelInput instanceof HTMLInputElement)) {
                            return;
                        }

                        let debounceTimer = null;

                        const applyItem = function (item) {
                            const displayLabel = item.displayLabel || item.label || item.idValue || '';
                            lookupInput.value = displayLabel;

                            if (labelInput instanceof HTMLInputElement) {
                                labelInput.value = item.label || item.idValue || '';
                            }

                            if (idInput instanceof HTMLInputElement) {
                                idInput.value = item.idValue || '';
                            }

                            if (secondaryInput instanceof HTMLInputElement && !secondaryInput.value) {
                                secondaryInput.value = item.secondary || '';
                            }

                            if (postIdHidden instanceof HTMLInputElement) {
                                postIdHidden.value = item.postId ? String(item.postId) : '';
                            }

                            setOpenLink(row, type, item.editUrl || '');
                            setDuplicateWarning(row, type, '', []);
                            renderResultChips(row, type, [], applyItem);
                            updateCreateLink(row, type, lookupInput.value || '');
                        };

                        lookupInput.addEventListener('input', function () {
                            const term = (lookupInput.value || '').trim();
                            if (postIdHidden instanceof HTMLInputElement) {
                                postIdHidden.value = '';
                            }
                            setOpenLink(row, type, '');
                            updateCreateLink(row, type, term);

                            if (debounceTimer) {
                                clearTimeout(debounceTimer);
                            }

                            if (term.length < 2) {
                                renderResultChips(row, type, [], applyItem);
                                setDuplicateWarning(row, type, '', []);
                                return;
                            }

                            debounceTimer = setTimeout(function () {
                                const params = new URLSearchParams();
                                params.set('action', 'thoth_entity_lookup');
                                params.set('term', term);
                                params.set('entityType', type);
                                params.set('nonce', nonce);
                                params.set('currentPostId', currentPostId);

                                fetch((window.ajaxurl || '') + '?' + params.toString(), {
                                    credentials: 'same-origin'
                                })
                                    .then(function (response) {
                                        return response.json();
                                    })
                                    .then(function (payload) {
                                        const items = payload && payload.success && payload.data && Array.isArray(payload.data.items)
                                            ? payload.data.items
                                            : [];

                                        renderResultChips(row, type, items, applyItem);
                                        setDuplicateWarning(row, type, term, items);
                                    })
                                    .catch(function () {
                                        renderResultChips(row, type, [], applyItem);
                                        setDuplicateWarning(row, type, '', []);
                                    });
                            }, 220);
                        });

                        if (idInput instanceof HTMLInputElement) {
                            idInput.addEventListener('input', function () {
                                const value = (idInput.value || '').trim();
                                if (!value) {
                                    if (postIdHidden instanceof HTMLInputElement) {
                                        postIdHidden.value = '';
                                    }
                                    setOpenLink(row, type, '');
                                }
                            });
                        }

                        if (labelInput instanceof HTMLInputElement) {
                            labelInput.addEventListener('input', function () {
                                const value = (labelInput.value || '').trim();
                                if (value && !lookupInput.value) {
                                    lookupInput.value = value;
                                }
                            });
                        }

                        updateCreateLink(row, type, lookupInput.value || '');
                    };

                    const bindLookupsInScope = function (scope) {
                        if (!(scope instanceof HTMLElement) && scope !== document) {
                            return;
                        }

                        const root = scope === document ? document : scope;
                        root.querySelectorAll('[data-thoth-entity-lookup]').forEach(function (lookupInput) {
                            if (!(lookupInput instanceof HTMLInputElement)) {
                                return;
                            }

                            if (lookupInput.getAttribute('data-thoth-lookup-bound') === '1') {
                                return;
                            }

                            const row = lookupInput.closest('tr');
                            if (!(row instanceof HTMLTableRowElement)) {
                                return;
                            }

                            lookupInput.setAttribute('data-thoth-lookup-bound', '1');
                            bindLookupInput(row, lookupInput);
                        });
                    };

                    bindLookupsInScope(document);
                    window.thothBindEntityLookups = bindLookupsInScope;
                };

                document.querySelectorAll('[data-add-row]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        const key = button.getAttribute('data-add-row');
                        const tbody = document.querySelector('tbody[data-repeater="' + key + '"]');
                        if (!tbody) {
                            return;
                        }

                        const first = tbody.querySelector('tr');
                        if (!first) {
                            return;
                        }

                        const clone = first.cloneNode(true);
                        clone.querySelectorAll('input').forEach(function (input) {
                            if (input.type === 'checkbox') {
                                input.checked = false;
                            } else {
                                input.value = '';
                            }

                            if (input.hasAttribute('data-thoth-lookup-bound')) {
                                input.removeAttribute('data-thoth-lookup-bound');
                            }
                        });

                        clone.querySelectorAll('[data-thoth-entity-results]').forEach(function (node) {
                            if (node instanceof HTMLElement) {
                                node.innerHTML = '';
                            }
                        });

                        clone.querySelectorAll('[data-thoth-entity-warning]').forEach(function (node) {
                            if (node instanceof HTMLElement) {
                                node.textContent = '';
                                node.style.display = 'none';
                            }
                        });

                        clone.querySelectorAll('[data-thoth-entity-open-link]').forEach(function (node) {
                            if (node instanceof HTMLAnchorElement) {
                                node.setAttribute('href', '#');
                                node.style.display = 'none';
                            }
                        });

                        tbody.appendChild(clone);
                        rebalanceNames(tbody, key);

                        const toggleButton = document.querySelector('[data-toggle-advanced="' + key + '"]');
                        const isOpen = toggleButton && toggleButton.getAttribute('data-open') === '1';
                        clone.querySelectorAll('.thoth-advanced-fields').forEach(function (field) {
                            field.style.display = isOpen ? 'block' : 'none';
                        });

                        validateScope(clone);
                        if (typeof window.thothBindEntityLookups === 'function') {
                            window.thothBindEntityLookups(clone);
                        }
                    });
                });

                document.querySelectorAll('[data-toggle-advanced]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        const key = button.getAttribute('data-toggle-advanced');
                        const tbody = document.querySelector('tbody[data-repeater="' + key + '"]');
                        if (!tbody) {
                            return;
                        }

                        const isOpen = button.getAttribute('data-open') === '1';
                        const nextOpen = !isOpen;
                        button.setAttribute('data-open', nextOpen ? '1' : '0');

                        const label = nextOpen ? button.getAttribute('data-label-hide') : button.getAttribute('data-label-show');
                        if (label) {
                            button.textContent = label;
                        }

                        tbody.querySelectorAll('.thoth-advanced-fields').forEach(function (field) {
                            field.style.display = nextOpen ? 'block' : 'none';
                        });
                    });
                });

                document.querySelectorAll('tbody[data-repeater]').forEach(function (tbody) {
                    tbody.addEventListener('click', function (event) {
                        const target = event.target;
                        if (!(target instanceof HTMLElement) || !target.hasAttribute('data-remove-row')) {
                            return;
                        }

                        const row = target.closest('tr');
                        if (!row) {
                            return;
                        }

                        const key = tbody.getAttribute('data-repeater');
                        if (!key) {
                            return;
                        }

                        const rows = tbody.querySelectorAll('tr');
                        if (rows.length <= 1) {
                            row.querySelectorAll('input').forEach(function (input) {
                                if (input.type === 'checkbox') {
                                    input.checked = false;
                                } else {
                                    input.value = '';
                                }
                            });
                            return;
                        }

                        row.remove();
                        rebalanceNames(tbody, key);
                    });
                });

                document.addEventListener('input', function (event) {
                    const target = event.target;
                    if (target instanceof HTMLInputElement && target.hasAttribute('pattern')) {
                        validateInput(target);
                    }
                });

                document.addEventListener('blur', function (event) {
                    const target = event.target;
                    if (target instanceof HTMLInputElement && target.hasAttribute('pattern')) {
                        validateInput(target);
                    }
                }, true);

                const root = document.querySelector('.thoth-meta-wrap');
                const form = root ? root.closest('form') : null;
                if (form) {
                    form.addEventListener('submit', function (event) {
                        const submitter = event.submitter;
                        if (submitter instanceof HTMLElement && submitter.hasAttribute('data-thoth-quick-action')) {
                            return;
                        }

                        const valid = validateScope(root);
                        if (valid) {
                            return;
                        }

                        event.preventDefault();
                        const firstInvalid = root.querySelector('input.thoth-invalid');
                        if (firstInvalid) {
                            firstInvalid.focus();
                        }
                    });
                }

                if (root) {
                    validateScope(root);
                }

                const workTypeSelect = document.getElementById('thoth-base-workType');
                if (workTypeSelect) {
                    workTypeSelect.addEventListener('change', applyWorkTypeVisibility);
                }
                applyWorkTypeVisibility();
                initCoverMediaPicker();
                initEntityLookups();
            }());
        </script>
        <?php
    }

    private function renderUiStyles(): void
    {
        ?>
        <style>
            @import url('https://fonts.googleapis.com/icon?family=Material+Icons');

            .thoth-meta-wrap {
                display: grid;
                gap: 16px;
            }

            .thoth-meta-section {
                border: 1px solid #dcdcde;
                border-radius: 6px;
                padding: 12px;
                background: #fff;
            }

            .thoth-meta-section h3 {
                margin: 0 0 8px;
            }

            .thoth-state-panel {
                background: #f8fafc;
            }

            .thoth-state-badge {
                display: inline-block;
                padding: 2px 10px;
                border-radius: 999px;
                font-size: 12px;
                font-weight: 600;
            }

            .thoth-state-ok {
                background: #ecfdf3;
                color: #067647;
            }

            .thoth-state-warning {
                background: #fffaeb;
                color: #b54708;
            }

            .thoth-state-danger {
                background: #fef3f2;
                color: #b42318;
            }

            .thoth-state-info {
                background: #eff8ff;
                color: #175cd3;
            }

            .thoth-tooltip-icon {
                display: inline-flex;
                position: relative;
                align-items: center;
                justify-content: center;
                width: 16px;
                height: 16px;
                margin-left: 6px;
                border-radius: 50%;
                border: 1px solid #8c8f94;
                color: #50575e;
                font-size: 11px;
                font-weight: 700;
                line-height: 1;
                cursor: help;
                vertical-align: middle;
                background: #fff;
            }

            .thoth-tooltip-icon:hover::after,
            .thoth-tooltip-icon:focus::after,
            .thoth-tooltip-icon:focus-visible::after {
                content: attr(data-thoth-tooltip);
                position: absolute;
                left: 20px;
                top: 50%;
                transform: translateY(-50%);
                min-width: 220px;
                max-width: 380px;
                padding: 8px 10px;
                border-radius: 6px;
                background: #1d2327;
                color: #fff;
                font-size: 12px;
                font-weight: 400;
                line-height: 1.4;
                white-space: normal;
                z-index: 1000;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            }

            .thoth-meta-section table input.regular-text {
                width: 100%;
                max-width: 100%;
            }

            .thoth-row-actions {
                text-align: right;
                white-space: nowrap;
                width: 88px;
            }

            .thoth-icon-button {
                width: 26px;
                min-width: 26px;
                height: 26px;
                padding: 0;
                margin-left: 6px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                vertical-align: middle;
                border: 0;
                background: transparent;
                box-shadow: none;
                cursor: pointer;
            }

            .thoth-icon-button .material-icons {
                display: block;
                font-size: 20px;
                line-height: 1;
            }

            .thoth-icon-button-add {
                color: #00a32a;
            }

            .thoth-icon-button-delete {
                color: #d63638;
            }

            .thoth-icon-button:hover,
            .thoth-icon-button:focus-visible {
                opacity: 0.85;
            }

            .thoth-icon-button:focus-visible {
                outline: 2px solid #2271b1;
                outline-offset: 1px;
            }

            .thoth-entity-results {
                margin-top: 6px;
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
            }

            .thoth-entity-chip {
                border: 1px solid #c3c4c7;
                background: #f6f7f7;
                color: #1d2327;
                border-radius: 999px;
                padding: 2px 10px;
                font-size: 12px;
                line-height: 1.6;
                cursor: pointer;
            }

            .thoth-entity-chip:hover,
            .thoth-entity-chip:focus-visible {
                border-color: #2271b1;
                background: #eff6ff;
                outline: none;
            }

            .thoth-entity-warning {
                display: block;
                margin-top: 4px;
                color: #b54708;
            }

            .thoth-entity-links {
                font-size: 12px;
            }

            .thoth-subsection {
                margin-top: 14px;
                padding-top: 12px;
                border-top: 1px solid #dcdcde;
            }

            .thoth-subsection h4 {
                margin: 0 0 8px;
                font-size: 13px;
            }

            .thoth-meta-section input.thoth-invalid {
                border-color: #d63638;
                box-shadow: 0 0 0 1px #d63638;
            }

            .thoth-meta-section .thoth-inline-error {
                display: block;
                margin-top: 4px;
                color: #b32d2e;
            }

            @media (max-width: 900px) {
                .thoth-meta-section table,
                .thoth-meta-section thead,
                .thoth-meta-section tbody,
                .thoth-meta-section tr,
                .thoth-meta-section th,
                .thoth-meta-section td {
                    display: block;
                    width: 100%;
                }

                .thoth-meta-section tr {
                    border: 1px solid #f0f0f1;
                    padding: 8px;
                    margin-bottom: 8px;
                    border-radius: 4px;
                }

                .thoth-meta-section thead {
                    display: none;
                }

                .thoth-meta-section td {
                    padding: 4px 0;
                }
            }
        </style>
        <?php
    }

    private function parsePostedRows(string $fieldName): array
    {
        $key = 'thoth_nested_' . $fieldName;
        if (!isset($_POST[$key]) || !is_array($_POST[$key])) {
            return [];
        }

        $rows = wp_unslash($_POST[$key]);
        return is_array($rows) ? $rows : [];
    }

    private function parsePostedBaseFields(): array
    {
        if (!isset($_POST['thoth_base']) || !is_array($_POST['thoth_base'])) {
            return [];
        }

        $base = wp_unslash($_POST['thoth_base']);
        return is_array($base) ? $base : [];
    }

    private function sanitizeBaseFields(array $fields): array
    {
        $workType = $this->normalizeWorkType($this->sanitizeString($fields['workType'] ?? ''));
        $workStatus = $this->normalizeWorkStatus($this->sanitizeString($fields['workStatus'] ?? ''));

        return [
            'fullTitle' => $this->sanitizeString($fields['fullTitle'] ?? ''),
            'subtitle' => $this->sanitizeString($fields['subtitle'] ?? ''),
            'edition' => $this->sanitizeString($fields['edition'] ?? ''),
            'workId' => '',
            'doi' => $this->sanitizeString($fields['doi'] ?? ''),
            'imprint' => $this->sanitizeString($fields['imprint'] ?? ''),
            'workType' => $workType,
            'workStatus' => $workStatus,
            'publicationDate' => $this->sanitizeString($fields['publicationDate'] ?? ''),
            'placeOfPublication' => $this->sanitizeString($fields['placeOfPublication'] ?? ''),
            'coverUrl' => esc_url_raw((string) ($fields['coverUrl'] ?? '')),
            'coverAttachmentId' => $this->sanitizePositiveInt($fields['coverAttachmentId'] ?? ''),
            'coverCaption' => $this->sanitizeString($fields['coverCaption'] ?? ''),
            'landingPage' => esc_url_raw((string) ($fields['landingPage'] ?? '')),
            'license' => esc_url_raw((string) ($fields['license'] ?? '')),
            'copyrightHolder' => $this->sanitizeString($fields['copyrightHolder'] ?? ''),
            'lccn' => $this->sanitizeString($fields['lccn'] ?? ''),
            'oclc' => $this->sanitizeString($fields['oclc'] ?? ''),
            'internalReference' => $this->sanitizeString($fields['internalReference'] ?? ''),
            'pageCount' => $this->sanitizePositiveInt($fields['pageCount'] ?? ''),
            'pageBreakdown' => $this->sanitizeString($fields['pageBreakdown'] ?? ''),
            'firstPage' => $this->sanitizePositiveInt($fields['firstPage'] ?? ''),
            'lastPage' => $this->sanitizePositiveInt($fields['lastPage'] ?? ''),
            'imageCount' => $this->sanitizePositiveInt($fields['imageCount'] ?? ''),
            'tableCount' => $this->sanitizePositiveInt($fields['tableCount'] ?? ''),
            'audioCount' => $this->sanitizePositiveInt($fields['audioCount'] ?? ''),
            'videoCount' => $this->sanitizePositiveInt($fields['videoCount'] ?? ''),
            'shortAbstract' => sanitize_textarea_field((string) ($fields['shortAbstract'] ?? '')),
            'longAbstract' => sanitize_textarea_field((string) ($fields['longAbstract'] ?? '')),
            'generalNote' => sanitize_textarea_field((string) ($fields['generalNote'] ?? '')),
            'bibliographyNote' => sanitize_textarea_field((string) ($fields['bibliographyNote'] ?? '')),
            'tableOfContents' => sanitize_textarea_field((string) ($fields['tableOfContents'] ?? '')),
        ];
    }

    private function validateSanitizedInput(int $postId, array $baseFields, array $contributors, array $relatedWorks, array $visibility): array
    {
        $errors = [];

        if ($this->isUiKeyVisibleIn($visibility, 'doi')) {
            $doi = trim((string) ($baseFields['doi'] ?? ''));
            if ($doi !== '' && !$this->isValidDoi($doi)) {
                $errors[] = __('DOI is invalid. Use a DOI like 10.1234/abcde or a doi.org URL.', 'thoth-data-connector');
            }
        }

        if ($this->isUiKeyVisibleIn($visibility, 'publicationDate')) {
            $publicationDate = trim((string) ($baseFields['publicationDate'] ?? ''));
            if ($publicationDate !== '' && !$this->isValidPublicationDate($publicationDate)) {
                $errors[] = __('Publication Date is invalid. Use YYYY-MM-DD or DD/MM/YYYY.', 'thoth-data-connector');
            }
        }

        if ($this->isUiKeyVisibleIn($visibility, 'pageRange')) {
            $firstPage = (string) ($baseFields['firstPage'] ?? '');
            $lastPage = (string) ($baseFields['lastPage'] ?? '');
            if ($firstPage !== '' && $lastPage !== '' && (int) $firstPage > (int) $lastPage) {
                $errors[] = __('Page Range is invalid: First Page must be less than or equal to Last Page.', 'thoth-data-connector');
            }
        }

        if ($this->isUiKeyVisibleIn($visibility, 'section_contributors')) {
            foreach ($contributors as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $orcid = trim((string) ($row['orcid'] ?? ''));
                if ($orcid === '') {
                    continue;
                }

                if (!$this->isValidOrcid($orcid)) {
                    $errors[] = sprintf(
                        /* translators: %d: contributor row number */
                        __('Contributor row %d has an invalid ORCID checksum.', 'thoth-data-connector'),
                        (int) $index + 1
                    );
                }
            }
        }

        if ($this->isUiKeyVisibleIn($visibility, 'section_related_works')) {
            foreach ($relatedWorks as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $relatedWorkId = trim((string) ($row['relatedWorkId'] ?? ''));
                if ($relatedWorkId === '') {
                    continue;
                }

                if (!$this->isValidRelatedWorkId($relatedWorkId)) {
                    $errors[] = sprintf(
                        /* translators: %d: related works row number */
                        __('Related Works row %d has an invalid Related Work ID format. Use PREFIX:number, number, or UUID.', 'thoth-data-connector'),
                        (int) $index + 1
                    );
                    continue;
                }

                if (!$this->workIdExists($relatedWorkId, $postId)) {
                    $errors[] = sprintf(
                        /* translators: 1: row number, 2: related work id */
                        __('Related Works row %1$d references unknown Work ID "%2$s".', 'thoth-data-connector'),
                        (int) $index + 1,
                        $relatedWorkId
                    );
                }
            }
        }

        return $errors;
    }

    private function isValidDoi(string $value): bool
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return true;
        }

        $normalized = preg_replace('#^https?://(dx\.)?doi\.org/#i', '', $normalized) ?? $normalized;
        return preg_match('/^10\.\d{4,9}\/\S+$/i', $normalized) === 1;
    }

    private function isValidPublicationDate(string $value): bool
    {
        $date = trim($value);
        if ($date === '') {
            return true;
        }

        return $this->matchesDateFormat($date, 'Y-m-d') || $this->matchesDateFormat($date, 'd/m/Y');
    }

    private function matchesDateFormat(string $date, string $format): bool
    {
        $parsed = \DateTime::createFromFormat($format, $date);
        if (!$parsed instanceof \DateTime) {
            return false;
        }

        return $parsed->format($format) === $date;
    }

    private function isValidOrcid(string $value): bool
    {
        $normalized = strtoupper(trim($value));
        if (preg_match('/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/', $normalized) !== 1) {
            return false;
        }

        $digits = str_replace('-', '', $normalized);
        $total = 0;
        for ($index = 0; $index < 15; $index++) {
            $total = ($total + (int) $digits[$index]) * 2;
        }

        $remainder = $total % 11;
        $result = (12 - $remainder) % 11;
        $checkDigit = $result === 10 ? 'X' : (string) $result;

        return $checkDigit === $digits[15];
    }

    private function isValidRelatedWorkId(string $value): bool
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return true;
        }

        if (preg_match('/^(?:[A-Za-z0-9_-]+:\d+|\d+)$/', $normalized) === 1) {
            return true;
        }

        return preg_match('/^[a-fA-F0-9-]{36}$/', $normalized) === 1;
    }

    private function storeValidationErrors(int $postId, array $errors): void
    {
        $userId = get_current_user_id();
        if ($userId <= 0 || $postId <= 0) {
            return;
        }

        $sanitized = [];
        foreach ($errors as $error) {
            $message = sanitize_text_field((string) $error);
            if ($message !== '') {
                $sanitized[] = $message;
            }
        }

        set_transient($this->buildValidationErrorTransientKey($postId, $userId), $sanitized, 5 * MINUTE_IN_SECONDS);
    }

    private function consumeValidationErrors(int $postId): array
    {
        $userId = get_current_user_id();
        if ($userId <= 0 || $postId <= 0) {
            return [];
        }

        $key = $this->buildValidationErrorTransientKey($postId, $userId);
        $value = get_transient($key);
        delete_transient($key);

        return is_array($value) ? $value : [];
    }

    private function buildValidationErrorTransientKey(int $postId, int $userId): string
    {
        return self::VALIDATION_ERROR_TRANSIENT_PREFIX . $postId . '_' . $userId;
    }

    private function assignGeneratedWorkId(int $postId, array &$baseFields): void
    {
        $existing = trim((string) get_post_meta($postId, '_thoth_work_id', true));
        if ($existing !== '') {
            $baseFields['workId'] = $existing;
            return;
        }

        $prefix = $this->getConfiguredWorkIdPrefix();
        $nextCounter = $this->determineNextWorkIdCounter($prefix, $postId);
        $baseFields['workId'] = $this->formatGeneratedWorkId($prefix, $nextCounter);
        $this->storeWorkIdCounter($prefix, $nextCounter);
    }

    private function getConfiguredWorkIdPrefix(): string
    {
        $options = get_option(SettingsPage::OPTION_NAME, []);
        $options = is_array($options) ? $options : [];

        $prefix = isset($options[SettingsPage::WORK_ID_PREFIX_OPTION_KEY])
            ? sanitize_text_field((string) $options[SettingsPage::WORK_ID_PREFIX_OPTION_KEY])
            : '';

        $prefix = trim(str_replace(':', '', $prefix));
        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', $prefix) ?? '';

        return substr($prefix, 0, 24);
    }

    private function determineNextWorkIdCounter(string $prefix, int $excludePostId = 0): int
    {
        $storedCounter = $this->getStoredWorkIdCounter($prefix);
        $scannedCounter = $this->scanMaxWorkIdCounter($prefix);

        $candidate = max($storedCounter, $scannedCounter) + 1;
        while ($this->workIdExists($this->formatGeneratedWorkId($prefix, $candidate), $excludePostId)) {
            $candidate++;
        }

        return $candidate;
    }

    private function getStoredWorkIdCounter(string $prefix): int
    {
        $allCounters = get_option(self::WORK_ID_COUNTER_OPTION, []);
        $allCounters = is_array($allCounters) ? $allCounters : [];
        $key = $this->getWorkIdCounterKey($prefix);

        return isset($allCounters[$key]) ? absint($allCounters[$key]) : 0;
    }

    private function storeWorkIdCounter(string $prefix, int $counter): void
    {
        $allCounters = get_option(self::WORK_ID_COUNTER_OPTION, []);
        $allCounters = is_array($allCounters) ? $allCounters : [];
        $key = $this->getWorkIdCounterKey($prefix);

        $allCounters[$key] = max(absint($counter), isset($allCounters[$key]) ? absint($allCounters[$key]) : 0);
        update_option(self::WORK_ID_COUNTER_OPTION, $allCounters, false);
    }

    private function getWorkIdCounterKey(string $prefix): string
    {
        return $prefix === '' ? '__default__' : strtolower($prefix);
    }

    private function formatGeneratedWorkId(string $prefix, int $counter): string
    {
        $normalizedCounter = max(1, $counter);
        return $prefix === '' ? (string) $normalizedCounter : ($prefix . ':' . $normalizedCounter);
    }

    private function scanMaxWorkIdCounter(string $prefix): int
    {
        $postIds = get_posts([
            'post_type' => PublicationPostType::POST_TYPE,
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        if (!is_array($postIds) || $postIds === []) {
            return 0;
        }

        $max = 0;
        foreach ($postIds as $postId) {
            $workId = trim((string) get_post_meta((int) $postId, '_thoth_work_id', true));
            $counter = $this->extractGeneratedWorkIdCounter($workId, $prefix);
            if ($counter > $max) {
                $max = $counter;
            }
        }

        return $max;
    }

    private function extractGeneratedWorkIdCounter(string $workId, string $prefix): int
    {
        $workId = trim($workId);
        if ($workId === '') {
            return 0;
        }

        if ($prefix === '') {
            return ctype_digit($workId) ? (int) $workId : 0;
        }

        $pattern = '/^' . preg_quote($prefix, '/') . ':(\d+)$/';
        if (preg_match($pattern, $workId, $matches) !== 1) {
            return 0;
        }

        return isset($matches[1]) ? (int) $matches[1] : 0;
    }

    private function workIdExists(string $workId, int $excludePostId = 0): bool
    {
        if ($workId === '') {
            return false;
        }

        $query = [
            'post_type' => PublicationPostType::POST_TYPE,
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_thoth_work_id',
                    'value' => $workId,
                    'compare' => '=',
                ],
            ],
        ];

        if ($excludePostId > 0) {
            $query['post__not_in'] = [$excludePostId];
        }

        $matches = get_posts($query);
        return is_array($matches) && isset($matches[0]);
    }

    private function resolveCoverFromAttachment(array &$baseFields): void
    {
        $attachmentId = absint((string) ($baseFields['coverAttachmentId'] ?? ''));
        if ($attachmentId <= 0) {
            return;
        }

        $attachmentUrl = wp_get_attachment_url($attachmentId);
        if (!is_string($attachmentUrl) || $attachmentUrl === '') {
            $baseFields['coverAttachmentId'] = '';
            return;
        }

        $baseFields['coverAttachmentId'] = (string) $attachmentId;
        $baseFields['coverUrl'] = esc_url_raw($attachmentUrl);
    }

    private function applyConditionalRulesToBaseFields(int $postId, array &$baseFields): void
    {
        $workType = (string) ($baseFields['workType'] ?? '');
        $workStatus = (string) ($baseFields['workStatus'] ?? '');

        foreach (self::BASE_FIELD_WORK_TYPE_VISIBILITY as $field => $allowedWorkTypes) {
            if ($workType === '' || in_array($workType, $allowedWorkTypes, true)) {
                continue;
            }

            if (trim((string) ($baseFields[$field] ?? '')) !== '') {
                $this->conditionalRulesApplied = true;
            }
            $baseFields[$field] = '';
        }

        $requiredFields = self::REQUIRED_BASE_FIELDS;
        if ($workType === 'book_chapter') {
            $requiredFields[] = 'firstPage';
            $requiredFields[] = 'lastPage';
        }

        if ($workStatus === 'active') {
            $requiredFields[] = 'publicationDate';
        }

        foreach (array_values(array_unique($requiredFields)) as $field) {
            if (trim((string) ($baseFields[$field] ?? '')) !== '') {
                continue;
            }

            $metaKey = $this->getBaseFieldMetaKey($field);
            if ($metaKey === '') {
                continue;
            }

            $existingValue = (string) get_post_meta($postId, $metaKey, true);
            if ($existingValue === '') {
                continue;
            }

            $baseFields[$field] = $existingValue;
            $this->conditionalRulesApplied = true;
        }
    }

    private function isSectionVisibleForWorkType(string $section, string $workType): bool
    {
        if (!isset(self::SECTION_WORK_TYPE_VISIBILITY[$section])) {
            return true;
        }

        if ($workType === '') {
            return true;
        }

        return in_array($workType, self::SECTION_WORK_TYPE_VISIBILITY[$section], true);
    }

    private function applyConditionalRulesToNestedSections(int $postId, string $workType, string $workStatus, array &$publications, array &$issues, array &$series): void
    {
        $sectionRows = [
            'publications' => &$publications,
            'issues' => &$issues,
            'series' => &$series,
        ];

        foreach ($sectionRows as $section => &$rows) {
            if ($this->isSectionVisibleForWorkType($section, $workType)) {
                continue;
            }

            if ($rows !== []) {
                $this->conditionalRulesApplied = true;
            }
            $rows = [];
        }
        unset($rows);

        $requiredSections = [];
        foreach (self::SECTION_REQUIRED_BY_WORK_TYPE as $section => $allowedWorkTypes) {
            if ($workType !== '' && in_array($workType, $allowedWorkTypes, true)) {
                $requiredSections[$section] = true;
            }
        }

        if (isset(self::SECTION_REQUIRED_BY_STATUS[$workStatus])) {
            foreach (self::SECTION_REQUIRED_BY_STATUS[$workStatus] as $section) {
                $requiredSections[(string) $section] = true;
            }
        }

        foreach (array_keys($requiredSections) as $section) {
            if (!isset($sectionRows[$section])) {
                continue;
            }

            if (!$this->isSectionVisibleForWorkType($section, $workType)) {
                continue;
            }

            if ($sectionRows[$section] !== []) {
                continue;
            }

            $metaKey = $this->getNestedSectionMetaKey($section);
            if ($metaKey === '') {
                continue;
            }

            $existingRows = $this->decodeJsonMeta($postId, $metaKey);
            if ($existingRows === []) {
                continue;
            }

            $sectionRows[$section] = $existingRows;
            $this->conditionalRulesApplied = true;
        }
    }

    private function getNestedSectionMetaKey(string $section): string
    {
        $map = [
            'publications' => '_thoth_nested_publications',
            'issues' => '_thoth_nested_issues',
            'series' => '_thoth_nested_series',
        ];

        return isset($map[$section]) ? $map[$section] : '';
    }

    private function getBaseFieldMetaKey(string $baseField): string
    {
        $map = [
            'fullTitle' => '_thoth_full_title',
            'subtitle' => '_thoth_subtitle',
            'edition' => '_thoth_edition',
            'workId' => '_thoth_work_id',
            'doi' => '_thoth_doi',
            'imprint' => '_thoth_imprint',
            'workType' => '_thoth_work_type',
            'workStatus' => '_thoth_work_status',
            'publicationDate' => '_thoth_publication_date',
            'placeOfPublication' => '_thoth_place_of_publication',
            'coverUrl' => '_thoth_cover_url',
            'coverAttachmentId' => '_thoth_cover_attachment_id',
            'coverCaption' => '_thoth_cover_caption',
            'landingPage' => '_thoth_landing_page',
            'license' => '_thoth_license',
            'copyrightHolder' => '_thoth_copyright_holder',
            'lccn' => '_thoth_lccn',
            'oclc' => '_thoth_oclc',
            'internalReference' => '_thoth_internal_reference',
            'pageCount' => '_thoth_page_count',
            'pageBreakdown' => '_thoth_page_breakdown',
            'firstPage' => '_thoth_first_page',
            'lastPage' => '_thoth_last_page',
            'imageCount' => '_thoth_image_count',
            'tableCount' => '_thoth_table_count',
            'audioCount' => '_thoth_audio_count',
            'videoCount' => '_thoth_video_count',
            'shortAbstract' => '_thoth_short_abstract',
            'longAbstract' => '_thoth_long_abstract',
            'generalNote' => '_thoth_general_note',
            'bibliographyNote' => '_thoth_bibliography_note',
            'tableOfContents' => '_thoth_table_of_contents',
        ];

        return isset($map[$baseField]) ? $map[$baseField] : '';
    }

    private function persistBaseFields(int $postId, array $baseFields): void
    {
        update_post_meta($postId, '_thoth_full_title', (string) ($baseFields['fullTitle'] ?? ''));
        update_post_meta($postId, '_thoth_subtitle', (string) ($baseFields['subtitle'] ?? ''));
        update_post_meta($postId, '_thoth_edition', (string) ($baseFields['edition'] ?? ''));
        update_post_meta($postId, '_thoth_work_id', (string) ($baseFields['workId'] ?? ''));
        update_post_meta($postId, '_thoth_doi', (string) ($baseFields['doi'] ?? ''));
        update_post_meta($postId, '_thoth_imprint', (string) ($baseFields['imprint'] ?? ''));
        update_post_meta($postId, '_thoth_work_type', (string) ($baseFields['workType'] ?? ''));
        update_post_meta($postId, '_thoth_work_status', (string) ($baseFields['workStatus'] ?? ''));
        update_post_meta($postId, '_thoth_publication_date', (string) ($baseFields['publicationDate'] ?? ''));
        update_post_meta($postId, '_thoth_place_of_publication', (string) ($baseFields['placeOfPublication'] ?? ''));
        update_post_meta($postId, '_thoth_cover_url', (string) ($baseFields['coverUrl'] ?? ''));
        update_post_meta($postId, '_thoth_cover_attachment_id', (string) ($baseFields['coverAttachmentId'] ?? ''));
        update_post_meta($postId, '_thoth_cover_caption', (string) ($baseFields['coverCaption'] ?? ''));
        update_post_meta($postId, '_thoth_landing_page', (string) ($baseFields['landingPage'] ?? ''));
        update_post_meta($postId, '_thoth_license', (string) ($baseFields['license'] ?? ''));
        update_post_meta($postId, '_thoth_copyright_holder', (string) ($baseFields['copyrightHolder'] ?? ''));
        update_post_meta($postId, '_thoth_lccn', (string) ($baseFields['lccn'] ?? ''));
        update_post_meta($postId, '_thoth_oclc', (string) ($baseFields['oclc'] ?? ''));
        update_post_meta($postId, '_thoth_internal_reference', (string) ($baseFields['internalReference'] ?? ''));
        update_post_meta($postId, '_thoth_page_count', (string) ($baseFields['pageCount'] ?? ''));
        update_post_meta($postId, '_thoth_page_breakdown', (string) ($baseFields['pageBreakdown'] ?? ''));
        update_post_meta($postId, '_thoth_first_page', (string) ($baseFields['firstPage'] ?? ''));
        update_post_meta($postId, '_thoth_last_page', (string) ($baseFields['lastPage'] ?? ''));
        update_post_meta($postId, '_thoth_image_count', (string) ($baseFields['imageCount'] ?? ''));
        update_post_meta($postId, '_thoth_table_count', (string) ($baseFields['tableCount'] ?? ''));
        update_post_meta($postId, '_thoth_audio_count', (string) ($baseFields['audioCount'] ?? ''));
        update_post_meta($postId, '_thoth_video_count', (string) ($baseFields['videoCount'] ?? ''));
        update_post_meta($postId, '_thoth_short_abstract', (string) ($baseFields['shortAbstract'] ?? ''));
        update_post_meta($postId, '_thoth_long_abstract', (string) ($baseFields['longAbstract'] ?? ''));
        update_post_meta($postId, '_thoth_general_note', (string) ($baseFields['generalNote'] ?? ''));
        update_post_meta($postId, '_thoth_bibliography_note', (string) ($baseFields['bibliographyNote'] ?? ''));
        update_post_meta($postId, '_thoth_table_of_contents', (string) ($baseFields['tableOfContents'] ?? ''));
    }

    private function updatePostTitleFromBase(int $postId, array $baseFields): void
    {
        $title = trim((string) ($baseFields['fullTitle'] ?? ''));
        if ($title === '') {
            return;
        }

        $this->isUpdatingPostTitle = true;
        wp_update_post([
            'ID' => $postId,
            'post_title' => $title,
            'post_excerpt' => (string) ($baseFields['shortAbstract'] ?? ''),
            'post_content' => (string) ($baseFields['longAbstract'] ?? ''),
        ]);
        $this->isUpdatingPostTitle = false;
    }

    private function updatePayloadBaseData(int $postId, array $baseFields): void
    {
        $rawPayload = get_post_meta($postId, '_thoth_payload', true);
        $payload = is_string($rawPayload) ? json_decode($rawPayload, true) : null;
        if (!is_array($payload)) {
            $payload = [];
        }

        $payload['fullTitle'] = (string) ($baseFields['fullTitle'] ?? '');
        $payload['subtitle'] = (string) ($baseFields['subtitle'] ?? '');
        $payload['edition'] = (string) ($baseFields['edition'] ?? '');
        $payload['workId'] = (string) ($baseFields['workId'] ?? '');
        $payload['doi'] = (string) ($baseFields['doi'] ?? '');
        $payload['imprint'] = (string) ($baseFields['imprint'] ?? '');
        $payload['workType'] = (string) ($baseFields['workType'] ?? '');
        $payload['workStatus'] = (string) ($baseFields['workStatus'] ?? '');
        $payload['publicationDate'] = (string) ($baseFields['publicationDate'] ?? '');
        $payload['placeOfPublication'] = (string) ($baseFields['placeOfPublication'] ?? '');
        $payload['coverUrl'] = (string) ($baseFields['coverUrl'] ?? '');
        $payload['coverCaption'] = (string) ($baseFields['coverCaption'] ?? '');
        $payload['landingPage'] = (string) ($baseFields['landingPage'] ?? '');
        $payload['license'] = (string) ($baseFields['license'] ?? '');
        $payload['copyrightHolder'] = (string) ($baseFields['copyrightHolder'] ?? '');
        $payload['lccn'] = (string) ($baseFields['lccn'] ?? '');
        $payload['oclcNumber'] = (string) ($baseFields['oclc'] ?? '');
        $payload['internalReference'] = (string) ($baseFields['internalReference'] ?? '');
        $payload['pageCount'] = (string) ($baseFields['pageCount'] ?? '');
        $payload['pageBreakdown'] = (string) ($baseFields['pageBreakdown'] ?? '');
        $payload['firstPage'] = (string) ($baseFields['firstPage'] ?? '');
        $payload['lastPage'] = (string) ($baseFields['lastPage'] ?? '');
        $payload['imageCount'] = (string) ($baseFields['imageCount'] ?? '');
        $payload['tableCount'] = (string) ($baseFields['tableCount'] ?? '');
        $payload['audioCount'] = (string) ($baseFields['audioCount'] ?? '');
        $payload['videoCount'] = (string) ($baseFields['videoCount'] ?? '');
        $payload['shortAbstract'] = (string) ($baseFields['shortAbstract'] ?? '');
        $payload['longAbstract'] = (string) ($baseFields['longAbstract'] ?? '');
        $payload['generalNote'] = (string) ($baseFields['generalNote'] ?? '');
        $payload['bibliographyNote'] = (string) ($baseFields['bibliographyNote'] ?? '');
        $payload['tableOfContents'] = (string) ($baseFields['tableOfContents'] ?? '');

        $payloadJson = $this->toJson($payload);
        update_post_meta($postId, '_thoth_payload', $payloadJson);
        update_post_meta($postId, '_thoth_checksum', md5($payloadJson));
    }

    private function sanitizePositiveInt($value): string
    {
        $normalized = absint($value);
        return $normalized > 0 ? (string) $normalized : '';
    }

    private function sanitizeDecimal($value): string
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        if ($normalized === '' || !preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
            return '';
        }

        return $normalized;
    }

    private function normalizeWorkType(string $value): string
    {
        $normalized = strtolower(str_replace([' ', '-'], '_', trim($value)));
        return isset(self::WORK_TYPE_OPTIONS[$normalized]) ? $normalized : '';
    }

    private function normalizeWorkStatus(string $value): string
    {
        $normalized = strtolower(str_replace([' ', '-'], '_', trim($value)));
        return isset(self::WORK_STATUS_OPTIONS[$normalized]) ? $normalized : '';
    }

    private function normalizePublicationType(string $value): string
    {
        $normalized = strtolower(str_replace([' ', '-'], '_', trim($value)));
        return isset(self::PUBLICATION_TYPE_OPTIONS[$normalized]) ? $normalized : '';
    }

    private function getCurrentWorkStatus(int $postId): string
    {
        return $this->normalizeWorkStatus((string) get_post_meta($postId, '_thoth_work_status', true));
    }

    private function isAllowedWorkStatusTransition(int $postId, string $requestedStatus): bool
    {
        if ($requestedStatus === '') {
            return true;
        }

        $currentStatus = $this->getCurrentWorkStatus($postId);
        if ($currentStatus !== 'active') {
            return true;
        }

        return in_array($requestedStatus, ['active', 'withdrawn', 'superseded'], true);
    }

    private function sanitizeContributors(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = [
                'contributionId' => $this->sanitizeString($row['contributionId'] ?? ''),
                'contributionType' => $this->sanitizeString($row['contributionType'] ?? ''),
                'mainContribution' => !empty($row['mainContribution']),
                'contributorId' => $this->sanitizeString($row['contributorId'] ?? ''),
                'fullName' => $this->sanitizeString($row['fullName'] ?? ''),
                'firstName' => $this->sanitizeString($row['firstName'] ?? ''),
                'lastName' => $this->sanitizeString($row['lastName'] ?? ''),
                'orcid' => $this->sanitizeString($row['orcid'] ?? ''),
            ];

            if ($this->hasAnyValue($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function sanitizeAffiliations(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = [
                'contributionId' => $this->sanitizeString($row['contributionId'] ?? ''),
                'contributorId' => $this->sanitizeString($row['contributorId'] ?? ''),
                'institutionId' => $this->sanitizeString($row['institutionId'] ?? ''),
                'institutionName' => $this->sanitizeString($row['institutionName'] ?? ''),
                'position' => $this->sanitizeString($row['position'] ?? ''),
                'affiliationOrdinal' => $this->sanitizePositiveInt($row['affiliationOrdinal'] ?? ''),
            ];

            if ($this->hasAnyValue($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function sanitizeSubjects(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = [
                'subjectId' => $this->sanitizeString($row['subjectId'] ?? ''),
                'subjectType' => $this->sanitizeString($row['subjectType'] ?? ''),
                'subjectCode' => $this->sanitizeString($row['subjectCode'] ?? ''),
                'subjectOrdinal' => $this->sanitizeString($row['subjectOrdinal'] ?? ''),
            ];

            if ($this->hasAnyValue($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function sanitizeFundings(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = [
                'fundingId' => $this->sanitizeString($row['fundingId'] ?? ''),
                'institutionId' => $this->sanitizeString($row['institutionId'] ?? ''),
                'projectName' => $this->sanitizeString($row['projectName'] ?? ''),
                'projectShortName' => $this->sanitizeString($row['projectShortName'] ?? ''),
                'grantNumber' => $this->sanitizeString($row['grantNumber'] ?? ''),
                'program' => $this->sanitizeString($row['program'] ?? ''),
                'jurisdiction' => $this->sanitizeString($row['jurisdiction'] ?? ''),
            ];

            if ($this->hasAnyValue($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function sanitizeLanguages(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $languageCode = strtolower($this->sanitizeString($row['languageCode'] ?? ''));
            if ($languageCode !== '' && !preg_match('/^[a-z]{3}$/', $languageCode)) {
                $languageCode = '';
            }

            $item = [
                'languageCode' => $languageCode,
                'languageRelation' => $this->normalizeLanguageRelation($this->sanitizeString($row['languageRelation'] ?? '')),
                'mainLanguage' => !empty($row['mainLanguage']),
            ];

            if ($this->hasAnyValue($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function sanitizePublications(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = [
                'publicationType' => $this->normalizePublicationType($this->sanitizeString($row['publicationType'] ?? '')),
                'isbn' => strtoupper(preg_replace('/[^0-9X]/i', '', $this->sanitizeString($row['isbn'] ?? '')) ?? ''),
                'width' => $this->sanitizeDecimal($row['width'] ?? ''),
                'height' => $this->sanitizeDecimal($row['height'] ?? ''),
                'depth' => $this->sanitizeDecimal($row['depth'] ?? ''),
                'weight' => $this->sanitizeDecimal($row['weight'] ?? ''),
            ];

            if ($this->hasAnyValue($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function sanitizeRelatedWorks(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = [
                'relatedWorkId' => $this->sanitizeString($row['relatedWorkId'] ?? ''),
                'relationType' => $this->normalizeRelationType($this->sanitizeString($row['relationType'] ?? '')),
                'relationOrdinal' => $this->sanitizePositiveInt($row['relationOrdinal'] ?? ''),
            ];

            if ($this->hasAnyValue($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function sanitizeIssues(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = [
                'issueId' => $this->sanitizeString($row['issueId'] ?? ''),
                'issueNumber' => $this->sanitizeString($row['issueNumber'] ?? ''),
                'issueOrdinal' => $this->sanitizePositiveInt($row['issueOrdinal'] ?? ''),
            ];

            if ($this->hasAnyValue($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function sanitizeSeries(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = [
                'seriesId' => $this->sanitizeString($row['seriesId'] ?? ''),
                'seriesName' => $this->sanitizeString($row['seriesName'] ?? ''),
                'seriesOrdinal' => $this->sanitizePositiveInt($row['seriesOrdinal'] ?? ''),
            ];

            if ($this->hasAnyValue($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function hasAnyValue(array $item): bool
    {
        foreach ($item as $value) {
            if (is_bool($value)) {
                if ($value) {
                    return true;
                }
                continue;
            }

            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function sanitizeString($value): string
    {
        return sanitize_text_field((string) $value);
    }

    private function collectDistinctValues(array $rows, string $field): string
    {
        $values = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $value = isset($row[$field]) ? trim((string) $row[$field]) : '';
            if ($value === '') {
                continue;
            }

            $values[$value] = true;
        }

        return implode(' | ', array_keys($values));
    }

    private function toJson(array $value): string
    {
        $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '[]';
    }

    private function updatePayloadNestedData(int $postId, array $contributors, array $affiliations, array $subjects, array $fundings, array $publications, array $languages, array $issues, array $series, array $relatedWorks): void
    {
        $rawPayload = get_post_meta($postId, '_thoth_payload', true);
        if (!is_string($rawPayload) || $rawPayload === '') {
            return;
        }

        $payload = json_decode($rawPayload, true);
        if (!is_array($payload)) {
            return;
        }

        $payload['contributions'] = array_map(static function (array $row): array {
            return [
                'contributionId' => (string) ($row['contributionId'] ?? ''),
                'contributionType' => (string) ($row['contributionType'] ?? ''),
                'mainContribution' => !empty($row['mainContribution']),
                'contributor' => [
                    'contributorId' => (string) ($row['contributorId'] ?? ''),
                    'fullName' => (string) ($row['fullName'] ?? ''),
                    'firstName' => (string) ($row['firstName'] ?? ''),
                    'lastName' => (string) ($row['lastName'] ?? ''),
                    'orcid' => (string) ($row['orcid'] ?? ''),
                ],
            ];
        }, $contributors);

        $payload['contributions'] = $this->attachAffiliationsToContributions($payload['contributions'], $affiliations);

        $payload['subjects'] = $subjects;
        $payload['fundings'] = $fundings;
        $payload['publications'] = array_map(static function (array $row): array {
            return [
                'publicationType' => (string) ($row['publicationType'] ?? ''),
                'isbn' => (string) ($row['isbn'] ?? ''),
                'width' => (string) ($row['width'] ?? ''),
                'height' => (string) ($row['height'] ?? ''),
                'depth' => (string) ($row['depth'] ?? ''),
                'weight' => (string) ($row['weight'] ?? ''),
            ];
        }, $publications);
        $payload['languages'] = array_map(static function (array $row): array {
            return [
                'languageCode' => (string) ($row['languageCode'] ?? ''),
                'languageRelation' => (string) ($row['languageRelation'] ?? ''),
                'mainLanguage' => !empty($row['mainLanguage']),
            ];
        }, $languages);
        $payload['issues'] = array_map(static function (array $row): array {
            return [
                'issueId' => (string) ($row['issueId'] ?? ''),
                'issueNumber' => (string) ($row['issueNumber'] ?? ''),
                'issueOrdinal' => (string) ($row['issueOrdinal'] ?? ''),
            ];
        }, $issues);
        $payload['series'] = array_map(static function (array $row): array {
            return [
                'seriesId' => (string) ($row['seriesId'] ?? ''),
                'seriesName' => (string) ($row['seriesName'] ?? ''),
                'seriesOrdinal' => (string) ($row['seriesOrdinal'] ?? ''),
            ];
        }, $series);
        $payload['relatedWorks'] = array_map(static function (array $row): array {
            return [
                'relatedWorkId' => (string) ($row['relatedWorkId'] ?? ''),
                'relationType' => (string) ($row['relationType'] ?? ''),
                'relationOrdinal' => (string) ($row['relationOrdinal'] ?? ''),
            ];
        }, $relatedWorks);

        update_post_meta($postId, '_thoth_payload', $this->toJson($payload));
    }

    private function resolveSourceWorkId(int $postId, array $baseFields): string
    {
        $workId = trim((string) ($baseFields['workId'] ?? ''));
        if ($workId !== '') {
            return $workId;
        }

        return trim((string) get_post_meta($postId, '_thoth_work_id', true));
    }

    private function syncInverseRelatedWorks(int $sourcePostId, string $sourceWorkId, array $previousRelatedWorks, array $currentRelatedWorks): array
    {
        $stats = [
            'added' => 0,
            'removed' => 0,
        ];

        $sourceWorkId = trim($sourceWorkId);
        if ($sourceWorkId === '') {
            return $stats;
        }

        $previousInverse = $this->buildInverseRelationMap($sourceWorkId, $previousRelatedWorks);
        $currentInverse = $this->buildInverseRelationMap($sourceWorkId, $currentRelatedWorks);

        $toAdd = array_diff_key($currentInverse, $previousInverse);
        $toRemove = array_diff_key($previousInverse, $currentInverse);

        if ($toAdd === [] && $toRemove === []) {
            return $stats;
        }

        $targetWorkIds = [];
        foreach ($toAdd as $entry) {
            $targetWorkIds[(string) $entry['targetWorkId']] = true;
        }
        foreach ($toRemove as $entry) {
            $targetWorkIds[(string) $entry['targetWorkId']] = true;
        }

        foreach (array_keys($targetWorkIds) as $targetWorkId) {
            $targetPostId = $this->resolveRelatedWorkPostByWorkId((string) $targetWorkId);
            if ($targetPostId <= 0 || $targetPostId === $sourcePostId) {
                continue;
            }

            $rows = $this->decodeJsonMeta($targetPostId, '_thoth_nested_related_works');
            $rows = is_array($rows) ? $rows : [];

            $removeTypes = [];
            foreach ($toRemove as $entry) {
                if ((string) ($entry['targetWorkId'] ?? '') !== (string) $targetWorkId) {
                    continue;
                }
                $removeTypes[(string) ($entry['relationType'] ?? '')] = true;
            }

            $addTypes = [];
            foreach ($toAdd as $entry) {
                if ((string) ($entry['targetWorkId'] ?? '') !== (string) $targetWorkId) {
                    continue;
                }
                $addTypes[(string) ($entry['relationType'] ?? '')] = true;
            }

            $changed = false;
            if ($removeTypes !== []) {
                $filtered = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $relatedWorkId = trim((string) ($row['relatedWorkId'] ?? ''));
                    $relationType = $this->normalizeRelationType((string) ($row['relationType'] ?? ''));

                    if ($relatedWorkId === $sourceWorkId && isset($removeTypes[$relationType])) {
                        $stats['removed']++;
                        $changed = true;
                        continue;
                    }

                    $filtered[] = $row;
                }
                $rows = $filtered;
            }

            foreach (array_keys($addTypes) as $inverseRelationType) {
                if ($inverseRelationType === '') {
                    continue;
                }

                $exists = false;
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $relatedWorkId = trim((string) ($row['relatedWorkId'] ?? ''));
                    $relationType = $this->normalizeRelationType((string) ($row['relationType'] ?? ''));
                    if ($relatedWorkId === $sourceWorkId && $relationType === $inverseRelationType) {
                        $exists = true;
                        break;
                    }
                }

                if ($exists) {
                    continue;
                }

                $rows[] = [
                    'relatedWorkId' => $sourceWorkId,
                    'relationType' => $inverseRelationType,
                    'relationOrdinal' => (string) $this->getNextRelationOrdinal($rows),
                ];
                $stats['added']++;
                $changed = true;
            }

            if (!$changed) {
                continue;
            }

            $rows = $this->deduplicateRelatedWorksRows($rows);

            update_post_meta($targetPostId, '_thoth_nested_related_works', $this->toJson($rows));
            update_post_meta($targetPostId, '_thoth_related_work_ids', $this->collectDistinctValues($rows, 'relatedWorkId'));
            update_post_meta($targetPostId, '_thoth_manual_override', '1');
            update_post_meta($targetPostId, '_thoth_manual_updated_at', gmdate('c'));
            update_post_meta($targetPostId, '_thoth_sync_state', 'manual_override');

            $this->updateRelatedWorksPayloadOnly($targetPostId, $rows);
        }

        return $stats;
    }

    private function buildInverseRelationMap(string $sourceWorkId, array $relatedWorks): array
    {
        $map = [];

        foreach ($relatedWorks as $row) {
            if (!is_array($row)) {
                continue;
            }

            $targetWorkId = trim((string) ($row['relatedWorkId'] ?? ''));
            $relationType = $this->normalizeRelationType((string) ($row['relationType'] ?? ''));
            $inverseRelationType = $this->getInverseRelationType($relationType);

            if ($targetWorkId === '' || $targetWorkId === $sourceWorkId || $inverseRelationType === '') {
                continue;
            }

            $signature = $targetWorkId . '|' . $inverseRelationType;
            $map[$signature] = [
                'targetWorkId' => $targetWorkId,
                'relationType' => $inverseRelationType,
            ];
        }

        return $map;
    }

    private function getInverseRelationType(string $relationType): string
    {
        return isset(self::INVERSE_RELATION_TYPE_MAP[$relationType])
            ? (string) self::INVERSE_RELATION_TYPE_MAP[$relationType]
            : '';
    }

    private function deduplicateRelatedWorksRows(array $rows): array
    {
        $result = [];
        $seen = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $relatedWorkId = trim((string) ($row['relatedWorkId'] ?? ''));
            $relationType = $this->normalizeRelationType((string) ($row['relationType'] ?? ''));
            $relationOrdinal = $this->sanitizePositiveInt($row['relationOrdinal'] ?? '');

            if ($relatedWorkId === '' || $relationType === '') {
                continue;
            }

            $signature = $relatedWorkId . '|' . $relationType;
            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $result[] = [
                'relatedWorkId' => $relatedWorkId,
                'relationType' => $relationType,
                'relationOrdinal' => $relationOrdinal,
            ];
        }

        return $result;
    }

    private function getNextRelationOrdinal(array $rows): int
    {
        $max = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $ordinal = absint($row['relationOrdinal'] ?? 0);
            if ($ordinal > $max) {
                $max = $ordinal;
            }
        }

        return $max + 1;
    }

    private function updateRelatedWorksPayloadOnly(int $postId, array $relatedWorks): void
    {
        $rawPayload = get_post_meta($postId, '_thoth_payload', true);
        if (!is_string($rawPayload) || $rawPayload === '') {
            return;
        }

        $payload = json_decode($rawPayload, true);
        if (!is_array($payload)) {
            return;
        }

        $payload['relatedWorks'] = array_map(static function (array $row): array {
            return [
                'relatedWorkId' => (string) ($row['relatedWorkId'] ?? ''),
                'relationType' => (string) ($row['relationType'] ?? ''),
                'relationOrdinal' => (string) ($row['relationOrdinal'] ?? ''),
            ];
        }, $relatedWorks);

        update_post_meta($postId, '_thoth_payload', $this->toJson($payload));
    }

    private function attachAffiliationsToContributions(array $contributions, array $affiliations): array
    {
        foreach ($contributions as $index => $contribution) {
            if (!is_array($contribution)) {
                continue;
            }

            $contributionId = (string) ($contribution['contributionId'] ?? '');
            $contributorId = isset($contribution['contributor']) && is_array($contribution['contributor'])
                ? (string) ($contribution['contributor']['contributorId'] ?? '')
                : '';

            $matches = [];
            foreach ($affiliations as $affiliation) {
                if (!is_array($affiliation)) {
                    continue;
                }

                $affContributionId = (string) ($affiliation['contributionId'] ?? '');
                $affContributorId = (string) ($affiliation['contributorId'] ?? '');

                $matchByContribution = $contributionId !== '' && $affContributionId !== '' && hash_equals($contributionId, $affContributionId);
                $matchByContributor = $contributorId !== '' && $affContributorId !== '' && hash_equals($contributorId, $affContributorId);

                if (!$matchByContribution && !$matchByContributor) {
                    continue;
                }

                $matches[] = [
                    'institutionId' => (string) ($affiliation['institutionId'] ?? ''),
                    'institutionName' => (string) ($affiliation['institutionName'] ?? ''),
                    'position' => (string) ($affiliation['position'] ?? ''),
                    'affiliationOrdinal' => (string) ($affiliation['affiliationOrdinal'] ?? ''),
                ];
            }

            $contributions[$index]['affiliations'] = $matches;
        }

        return $contributions;
    }

    private function restoreFromImportSnapshot(int $postId): void
    {
        $snapshotJson = get_post_meta($postId, '_thoth_import_payload', true);
        if (!is_string($snapshotJson) || $snapshotJson === '') {
            return;
        }

        $payload = json_decode($snapshotJson, true);
        if (!is_array($payload)) {
            return;
        }

        $mapped = (new NestedMetadataMapper())->map($payload);

        $contributors = isset($mapped['contributors']) && is_array($mapped['contributors']) ? $mapped['contributors'] : [];
        $affiliations = isset($mapped['affiliations']) && is_array($mapped['affiliations']) ? $mapped['affiliations'] : [];
        $subjects = isset($mapped['subjects']) && is_array($mapped['subjects']) ? $mapped['subjects'] : [];
        $fundings = isset($mapped['fundings']) && is_array($mapped['fundings']) ? $mapped['fundings'] : [];
        $publications = isset($mapped['publications']) && is_array($mapped['publications']) ? $mapped['publications'] : [];
        $languages = isset($mapped['languages']) && is_array($mapped['languages']) ? $mapped['languages'] : [];
        $issues = isset($mapped['issues']) && is_array($mapped['issues']) ? $mapped['issues'] : [];
        $series = isset($mapped['series']) && is_array($mapped['series']) ? $mapped['series'] : [];
        $relatedWorks = isset($mapped['related_works']) && is_array($mapped['related_works']) ? $mapped['related_works'] : [];

        update_post_meta($postId, '_thoth_nested_contributors', $this->toJson($contributors));
        update_post_meta($postId, '_thoth_nested_affiliations', $this->toJson($affiliations));
        update_post_meta($postId, '_thoth_nested_subjects', $this->toJson($subjects));
        update_post_meta($postId, '_thoth_nested_fundings', $this->toJson($fundings));
        update_post_meta($postId, '_thoth_nested_publications', $this->toJson($publications));
        update_post_meta($postId, '_thoth_nested_languages', $this->toJson($languages));
        update_post_meta($postId, '_thoth_nested_issues', $this->toJson($issues));
        update_post_meta($postId, '_thoth_nested_series', $this->toJson($series));
        update_post_meta($postId, '_thoth_nested_related_works', $this->toJson($relatedWorks));
        update_post_meta($postId, '_thoth_contributor_names', (string) ($mapped['contributor_names'] ?? ''));
        update_post_meta($postId, '_thoth_institution_ids', (string) ($mapped['institution_ids'] ?? ''));
        update_post_meta($postId, '_thoth_subject_codes', (string) ($mapped['subject_codes'] ?? ''));
        update_post_meta($postId, '_thoth_funding_programs', (string) ($mapped['funding_programs'] ?? ''));
        update_post_meta($postId, '_thoth_publication_isbns', (string) ($mapped['publication_isbns'] ?? ''));
        update_post_meta($postId, '_thoth_language_codes', (string) ($mapped['language_codes'] ?? ''));
        update_post_meta($postId, '_thoth_issue_ids', (string) ($mapped['issue_ids'] ?? ''));
        update_post_meta($postId, '_thoth_series_ids', (string) ($mapped['series_ids'] ?? ''));
        update_post_meta($postId, '_thoth_related_work_ids', (string) ($mapped['related_work_ids'] ?? ''));
        update_post_meta($postId, '_thoth_payload', $snapshotJson);
        delete_post_meta($postId, '_thoth_cover_attachment_id');

        $checksum = md5($snapshotJson);
        update_post_meta($postId, '_thoth_checksum', $checksum);
        update_post_meta($postId, '_thoth_import_checksum', $checksum);
        update_post_meta($postId, '_thoth_manual_override', '0');
        delete_post_meta($postId, '_thoth_manual_updated_at');
        update_post_meta($postId, '_thoth_sync_state', 'synced');
        update_post_meta($postId, '_thoth_last_synced_at', gmdate('c'));
    }

    private function normalizeRelationType(string $value): string
    {
        $normalized = strtolower(str_replace([' ', '-'], '_', trim($value)));
        return isset(self::RELATION_TYPE_OPTIONS[$normalized]) ? $normalized : '';
    }

    private function normalizeLanguageRelation(string $value): string
    {
        $normalized = strtolower(str_replace([' ', '-'], '_', trim($value)));
        return isset(self::LANGUAGE_RELATION_OPTIONS[$normalized]) ? $normalized : '';
    }

    private function clearManualOverride(int $postId): void
    {
        update_post_meta($postId, '_thoth_manual_override', '0');
        delete_post_meta($postId, '_thoth_manual_updated_at');

        $currentChecksum = (string) get_post_meta($postId, '_thoth_checksum', true);
        $importChecksum = (string) get_post_meta($postId, '_thoth_import_checksum', true);

        if ($importChecksum !== '' && $currentChecksum !== '' && hash_equals($importChecksum, $currentChecksum)) {
            update_post_meta($postId, '_thoth_sync_state', 'synced');
            return;
        }

        if ($importChecksum !== '') {
            update_post_meta($postId, '_thoth_sync_state', 'pending_import');
            return;
        }

        update_post_meta($postId, '_thoth_sync_state', 'synced');
    }
}
