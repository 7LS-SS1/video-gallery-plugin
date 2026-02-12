<?php
namespace SevenLS_VP;

/**
 * Main Plugin Class
 * 
 * Coordinates all plugin components
 */
class Plugin {
    
    /**
     * Singleton instance
     */
    private static ?Plugin $instance = null;
    
    /**
     * Get singleton instance
     */
    public static function get_instance(): Plugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
        $this->init_components();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks(): void {
        // Register custom post type
        add_action('init', [Post_Type::class, 'register']);
        
        // Register shortcodes
        add_action('init', [Shortcodes::class, 'register']);

        // SEO plugin compatibility
        add_action('init', [SEO_Compat::class, 'register']);
        
        // Add custom cron schedules
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
        
        // Handle scheduled sync
        add_action('sevenls_vp_scheduled_sync', [$this, 'run_scheduled_sync']);
        
        // Template override for single video
        add_filter('template_include', [$this, 'load_video_template']);
    }
    
    /**
     * Initialize plugin components
     */
    private function init_components(): void {
        new Github_Updater();

        // Initialize admin interface
        if (is_admin()) {
            new \SevenLS_VP_Admin();
        }

        new Elementor_Integration();
    }
    
    /**
     * Add custom cron schedules
     */
    public function add_cron_schedules(array $schedules): array {
        $schedules['five_minutes'] = [
            'interval' => 300,
            'display' => __('Every 5 Minutes', '7ls-video-publisher')
        ];
        
        $schedules['fifteen_minutes'] = [
            'interval' => 900,
            'display' => __('Every 15 Minutes', '7ls-video-publisher')
        ];
        
        return $schedules;
    }
    
    /**
     * Run scheduled sync
     */
    public function run_scheduled_sync(): void {
        $settings = get_option('sevenls_vp_settings', []);
        
        // Check if API is configured
        if (empty($settings['api_base_url']) || empty($settings['api_key'])) {
            Logger::log('Scheduled sync skipped: API not configured', 'warning');
            return;
        }
        
        // Run sync
        $sync_engine = new Sync_Engine();
        $result = $sync_engine->sync();
        
        // Log result
        if (is_wp_error($result)) {
            Logger::log('Scheduled sync failed: ' . $result->get_error_message(), 'error');
        } else {
            Logger::log(sprintf('Scheduled sync completed: %d videos processed', $result['processed']), 'info');
        }
    }
    
    /**
     * Load custom template for single video posts
     */
    public function load_video_template(string $template): string {
        if (is_singular('video')) {
            if ($this->should_bypass_template_override()) {
                return $template;
            }

            // Check if theme has override
            $theme_template = locate_template(['single-video.php']);
            
            if ($theme_template) {
                return $theme_template;
            }
            
            // Use plugin template
            $plugin_template = SEVENLS_VP_PLUGIN_DIR . 'templates/single-video.php';
            if (file_exists($plugin_template)) {
                return $plugin_template;
            }
        }
        
        return $template;
    }

    /**
     * Determine if Elementor should control the template output.
     */
    private function should_bypass_template_override(): bool {
        if (!did_action('elementor/loaded')) {
            return false;
        }

        if (class_exists('\Elementor\Plugin')) {
            $elementor = \Elementor\Plugin::$instance;
            if ($elementor->preview->is_preview_mode() || $elementor->editor->is_edit_mode()) {
                return true;
            }
        }

        if (defined('ELEMENTOR_PRO_VERSION')) {
            return true;
        }

        return false;
    }
}
