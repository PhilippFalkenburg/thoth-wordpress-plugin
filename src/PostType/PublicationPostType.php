<?php

declare(strict_types=1);

namespace ThothWordPressPlugin\PostType;

final class PublicationPostType
{
    public const POST_TYPE = 'thoth_publication';

    public function useBlockEditor(bool $useBlockEditor, string $postType): bool
    {
        if ($postType === self::POST_TYPE) {
            return false;
        }

        return $useBlockEditor;
    }

    public function register(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Thoth Publications', 'thoth-data-connector'),
                'singular_name' => __('Thoth Publication', 'thoth-data-connector'),
                'menu_name' => __('Thoth Publications', 'thoth-data-connector'),
                'add_new' => __('Add Thoth Publication', 'thoth-data-connector'),
                'add_new_item' => __('Add New Thoth Publication', 'thoth-data-connector'),
                'edit_item' => __('Edit Thoth Publication', 'thoth-data-connector'),
                'new_item' => __('New Thoth Publication', 'thoth-data-connector'),
                'view_item' => __('View Thoth Publication', 'thoth-data-connector'),
                'search_items' => __('Search Thoth Publications', 'thoth-data-connector'),
                'not_found' => __('No Thoth Publications found', 'thoth-data-connector'),
            ],
            'public' => true,
            'show_in_rest' => true,
            'supports' => ['revisions'],
            'has_archive' => true,
            'rewrite' => ['slug' => 'thoth-publications'],
            'menu_icon' => 'dashicons-book-alt',
        ]);

        $metaConfig = [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'auth_callback' => static function (bool $allowed, string $metaKey, int $postId, int $userId, string $cap, array $caps): bool {
                if ($cap === 'read_post_meta') {
                    return true;
                }

                return current_user_can('edit_posts');
            },
        ];

        register_post_meta(self::POST_TYPE, '_thoth_work_id', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_doi', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_imprint', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_work_type', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_work_status', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_subtitle', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_edition', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_publication_date', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_place_of_publication', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_cover_url', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_cover_attachment_id', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_cover_caption', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_landing_page', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_license', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_copyright_holder', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_lccn', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_oclc', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_internal_reference', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_page_count', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_page_breakdown', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_first_page', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_last_page', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_image_count', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_table_count', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_audio_count', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_video_count', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_full_title', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_short_abstract', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_long_abstract', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_general_note', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_bibliography_note', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_table_of_contents', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_nested_contributors', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_nested_affiliations', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_nested_subjects', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_nested_fundings', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_nested_publications', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_nested_languages', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_nested_issues', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_nested_series', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_nested_related_works', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_sync_inverse_related_works', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_contributor_names', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_institution_ids', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_subject_codes', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_funding_programs', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_publication_isbns', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_language_codes', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_issue_ids', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_series_ids', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_related_work_ids', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_payload', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_import_payload', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_checksum', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_import_checksum', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_last_synced_at', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_manual_override', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_manual_updated_at', $metaConfig);
        register_post_meta(self::POST_TYPE, '_thoth_sync_state', $metaConfig);
    }
}
