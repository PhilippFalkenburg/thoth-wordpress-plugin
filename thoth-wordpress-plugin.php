<?php
/**
 * Plugin Name: Thoth Data Connector
 * Description: Grundgerüst zur Integration von Thoth-Daten in WordPress.
 * Version: 0.1.0
 * Requires PHP: 7.4
 * Author: Thoth
 */

if (!defined('ABSPATH')) {
    exit;
}

$autoloadFile = __DIR__ . '/vendor/autoload.php';
if (is_readable($autoloadFile)) {
    require_once $autoloadFile;
}

if (!class_exists(\ThothWordPressPlugin\Plugin::class)) {
    add_action('admin_notices', static function (): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="notice notice-warning"><p>'
            . esc_html__('Thoth Data Connector: Bitte zuerst im Plugin-Ordner Composer ausführen, damit Abhängigkeiten geladen sind.', 'thoth-data-connector')
            . '</p></div>';
    });

    return;
}

$thothPlugin = new \ThothWordPressPlugin\Plugin();
$thothPlugin->register();

register_activation_hook(__FILE__, [$thothPlugin, 'activate']);
register_deactivation_hook(__FILE__, [$thothPlugin, 'deactivate']);
