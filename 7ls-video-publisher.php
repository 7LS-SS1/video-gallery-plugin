<?php
/**
 * Plugin Name: 7M Video Publisher
 * Plugin URI: https://example.com/7ls-video-publisher
 * Description: Syncs videos from external media-storage-api and publishes them as custom post type in WordPress
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: 7LS
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: video-publisher
 * Domain Path: /languages
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SEVENLS_VP_VERSION', '1.1.0');
define('SEVENLS_VP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SEVENLS_VP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SEVENLS_VP_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * PSR-4 Autoloader
 */
spl_autoload_register(function ($class) {
    $prefix = 'SevenLS_VP\\';
    $base_dir = SEVENLS_VP_PLUGIN_DIR . 'includes/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . 'class-' . strtolower(str_replace('_', '-', $relative_class)) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// Load admin classes
require_once SEVENLS_VP_PLUGIN_DIR . 'admin/class-admin.php';
require_once SEVENLS_VP_PLUGIN_DIR . 'admin/class-settings.php';

// Load CLI if WP-CLI is available
if (defined('WP_CLI') && WP_CLI) {
    require_once SEVENLS_VP_PLUGIN_DIR . 'cli/class-cli-command.php';
}

/**
 * Main plugin initialization
 */
function sevenls_vp_init() {
    // Load text domain
    load_plugin_textdomain('7ls-video-publisher', false, dirname(SEVENLS_VP_PLUGIN_BASENAME) . '/languages');
    
    // Initialize plugin
    SevenLS_VP\Plugin::get_instance();
}
add_action('plugins_loaded', 'sevenls_vp_init');

/**
 * Force update all videos (trigger API sync + full sync).
 *
 * @param bool $trigger_api Whether to call the API sync endpoint before syncing
 * @param array $api_payload Payload for API sync endpoint
 * @return array|\WP_Error
 */
function sevenls_vp_force_sync(bool $trigger_api = true, array $api_payload = []): array|\WP_Error {
    $sync_engine = new SevenLS_VP\Sync_Engine();

    return $sync_engine->force_sync($trigger_api, $api_payload);
}

/**
 * Activation hook
 */
register_activation_hook(__FILE__, function() {
    // Register CPT to flush rewrite rules
    SevenLS_VP\Post_Type::register();
    flush_rewrite_rules();
    
    // Set default options
    if (!get_option('sevenls_vp_settings')) {
        add_option('sevenls_vp_settings', [
            'api_base_url' => '',
            'api_key' => '',
            'project_id' => '',
            'sync_interval' => 'hourly',
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
            'logging_enabled' => true,
            'log_retention_days' => 30
        ]);
    }
    
    // Schedule cron
    if (!wp_next_scheduled('sevenls_vp_scheduled_sync')) {
        wp_schedule_event(time(), 'hourly', 'sevenls_vp_scheduled_sync');
    }
});

/**
 * Deactivation hook
 */
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('sevenls_vp_scheduled_sync');
});
