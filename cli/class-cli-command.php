<?php
namespace SevenLS_VP;

/**
 * WP-CLI Command Class
 * 
 * Provides CLI commands for video sync operations
 */
class CLI_Command {
    
    /**
     * Sync videos from external API
     * 
     * ## EXAMPLES
     * 
     *     wp sevenls-vp sync
     * 
     * @when after_wp_load
     */
    public function sync($args, $assoc_args) {
        \WP_CLI::line('Starting video sync...');
        
        $sync_engine = new Sync_Engine();
        $result = $sync_engine->sync();
        
        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_message());
        }
        
        \WP_CLI::success(sprintf(
            'Sync completed: %d videos processed (%d created, %d updated, %d errors) in %s seconds',
            $result['processed'],
            $result['created'],
            $result['updated'],
            $result['errors'],
            $result['duration']
        ));
    }
    
    /**
     * Clear all logs
     * 
     * ## EXAMPLES
     * 
     *     wp sevenls-vp clear-logs
     * 
     * @when after_wp_load
     */
    public function clear_logs($args, $assoc_args) {
        Logger::clear_logs();
        \WP_CLI::success('Logs cleared successfully.');
    }
    
    /**
     * Test API connection
     * 
     * ## EXAMPLES
     * 
     *     wp sevenls-vp test-connection
     * 
     * @when after_wp_load
     */
    public function test_connection($args, $assoc_args) {
        \WP_CLI::line('Testing API connection...');
        
        $api_client = new API_Client();
        $result = $api_client->test_connection();
        
        if (is_wp_error($result)) {
            \WP_CLI::error('Connection failed: ' . $result->get_error_message());
        }
        
        \WP_CLI::success('API connection successful!');
    }
    
    /**
     * Show sync statistics
     * 
     * ## EXAMPLES
     * 
     *     wp sevenls-vp stats
     * 
     * @when after_wp_load
     */
    public function stats($args, $assoc_args) {
        $video_count = wp_count_posts('video');
        $last_sync = get_option('sevenls_vp_last_sync', 'Never');
        
        \WP_CLI::line('=== Video Publisher Statistics ===');
        \WP_CLI::line('Total Videos: ' . ($video_count->publish + $video_count->draft + $video_count->pending));
        \WP_CLI::line('Published: ' . $video_count->publish);
        \WP_CLI::line('Draft: ' . $video_count->draft);
        \WP_CLI::line('Pending: ' . $video_count->pending);
        \WP_CLI::line('Last Sync: ' . $last_sync);
    }
}

\WP_CLI::add_command('sevenls-vp', 'SevenLS_VP\CLI_Command');
