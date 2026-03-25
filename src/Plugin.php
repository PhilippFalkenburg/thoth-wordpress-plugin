<?php

declare(strict_types=1);

namespace ThothWordPressPlugin;

use ThothWordPressPlugin\Admin\PublicationMetaBox;
use ThothWordPressPlugin\Admin\SettingsPage;
use ThothWordPressPlugin\PostType\PublicationPostType;
use ThothWordPressPlugin\Sync\SyncManager;

final class Plugin
{
    private SettingsPage $settingsPage;
    private PublicationMetaBox $publicationMetaBox;
    private PublicationPostType $publicationPostType;
    private SyncManager $syncManager;

    public function __construct(
        ?SettingsPage $settingsPage = null,
        ?PublicationMetaBox $publicationMetaBox = null,
        ?PublicationPostType $publicationPostType = null,
        ?SyncManager $syncManager = null
    )
    {
        $this->syncManager = $syncManager ?? new SyncManager();
        $this->settingsPage = $settingsPage ?? new SettingsPage($this->syncManager);
        $this->publicationMetaBox = $publicationMetaBox ?? new PublicationMetaBox();
        $this->publicationPostType = $publicationPostType ?? new PublicationPostType();
    }

    public function register(): void
    {
        add_action('init', [$this->publicationPostType, 'register']);
        add_filter('use_block_editor_for_post_type', [$this->publicationPostType, 'useBlockEditor'], 10, 2);
        add_action('admin_menu', [$this->settingsPage, 'registerMenu']);
        add_action('admin_init', [$this->settingsPage, 'registerSettings']);
        add_action('add_meta_boxes', [$this->publicationMetaBox, 'register']);
        add_action('save_post_' . PublicationPostType::POST_TYPE, [$this->publicationMetaBox, 'save'], 10, 2);
        add_filter('redirect_post_location', [$this->publicationMetaBox, 'appendActionNoticeToRedirect'], 10, 2);
        add_action('admin_notices', [$this->publicationMetaBox, 'renderActionNotice']);
        add_action('wp_ajax_thoth_related_work_lookup', [$this->publicationMetaBox, 'ajaxRelatedWorkLookup']);
        add_action('wp_ajax_thoth_entity_lookup', [$this->publicationMetaBox, 'ajaxEntityLookup']);
        add_action('admin_post_thoth_sync_publications', [$this->settingsPage, 'handleManualSync']);
        add_action(SyncManager::CRON_HOOK, [$this, 'handleCronSync']);
    }

    public function activate(): void
    {
        $this->syncManager->schedule();
    }

    public function deactivate(): void
    {
        $this->syncManager->unschedule();
    }

    public function handleCronSync(): void
    {
        $this->syncManager->run('cron');
    }
}
