<?php
namespace SevenLS_VP;

/**
 * Sync Engine Class
 * 
 * Handles synchronization of videos from API to WordPress
 */
class Sync_Engine {
    
    private API_Client $api_client;
    private array $settings;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->api_client = new API_Client();
        $this->settings = get_option('sevenls_vp_settings', []);
    }
    
    /**
     * Run sync.
     *
     * @param array $options Sync options (full_sync, bypass_cache, since)
     * @return array|\WP_Error Sync results or error
     */
    public function sync(array $options = []): array|\WP_Error {
        set_time_limit(300); // 5 minutes
        
        $start_time = microtime(true);
        $processed = 0;
        $created = 0;
        $updated = 0;
        $errors = 0;
        
        $full_sync = !empty($options['full_sync']);
        $bypass_cache = !empty($options['bypass_cache']) || $full_sync;
        $since_override = $options['since'] ?? null;

        // Get last sync time
        $last_sync = $full_sync ? null : ($since_override ?: get_option('sevenls_vp_last_sync', null));

        if ($full_sync) {
            $this->clear_sync_transients();
        }

        Logger::log(
            ($full_sync ? 'Starting full sync' : 'Starting sync') . ($last_sync ? ' (since ' . $last_sync . ')' : ''),
            'info'
        );
        
        // Fetch videos with pagination
        $page = 1;
        $has_more = true;
        
        $per_page = 50;
        
        while ($has_more) {
            // Check transient to avoid hammering API
            $transient_key = 'sevenls_vp_page_' . $page;
            $cached_data = $bypass_cache ? false : get_transient($transient_key);
            
            if ($cached_data !== false) {
                $response = $cached_data;
            } else {
                $response = $this->api_client->fetch_videos([
                    'page' => $page,
                    'per_page' => $per_page,
                    'since' => $last_sync
                ]);
                
                if (is_wp_error($response)) {
                    Logger::log('Sync failed on page ' . $page . ': ' . $response->get_error_message(), 'error');
                    return $response;
                }
                
                // Cache for 5 minutes
                if (!$bypass_cache) {
                    set_transient($transient_key, $response, 300);
                }
            }
            
            // Process videos
            $videos = $response['data'] ?? [];
            
            foreach ($videos as $video_data) {
                $result = $this->process_video($video_data);
                
                if (is_wp_error($result)) {
                    $errors++;
                    Logger::log('Failed to process video ' . ($video_data['id'] ?? 'unknown') . ': ' . $result->get_error_message(), 'error');
                } else {
                    $processed++;
                    if ($result['action'] === 'created') {
                        $created++;
                    } else {
                        $updated++;
                    }
                }
            }
            
            // Check for next page
            $pagination = $response['pagination'] ?? [];
            $has_more = false;
            $next_page = isset($pagination['next_page']) ? (int) $pagination['next_page'] : null;
            
            if (array_key_exists('has_more', $pagination) && $pagination['has_more'] !== null) {
                $has_more = (bool) $pagination['has_more'];
            } elseif ($next_page && $next_page > $page) {
                $has_more = true;
            } elseif (($pagination['page'] ?? null) !== null && ($pagination['total_pages'] ?? null) !== null) {
                $has_more = (int) $pagination['page'] < (int) $pagination['total_pages'];
            } elseif (count($videos) === $per_page) {
                $has_more = true;
            }
            
            if (empty($videos)) {
                $has_more = false;
            }
            
            if ($has_more) {
                $page = ($next_page && $next_page > $page) ? $next_page : $page + 1;
            }
            
            // Safety limit
            if ($page > 100) {
                Logger::log('Sync stopped: reached page limit', 'warning');
                break;
            }
        }
        
        // Update last sync time
        update_option('sevenls_vp_last_sync', current_time('mysql'));
        
        $duration = round(microtime(true) - $start_time, 2);
        
        $summary = [
            'processed' => $processed,
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
            'duration' => $duration
        ];
        
        Logger::log(sprintf(
            'Sync completed: %d processed (%d created, %d updated, %d errors) in %s seconds',
            $processed, $created, $updated, $errors, $duration
        ), 'info');
        
        return $summary;
    }

    /**
     * Force sync videos by optionally triggering API sync first.
     *
     * @param bool $trigger_api Whether to call the API sync endpoint before syncing
     * @param array $api_payload Payload for API sync endpoint
     * @return array|\WP_Error Sync results or error
     */
    public function force_sync(bool $trigger_api = true, array $api_payload = []): array|\WP_Error {
        if ($trigger_api) {
            $payload = $api_payload ?: ['limit' => 1000];
            $api_result = $this->api_client->trigger_plugin_sync($payload);

            if (is_wp_error($api_result)) {
                return $api_result;
            }
        }

        return $this->sync([
            'full_sync' => true,
            'bypass_cache' => true
        ]);
    }
    
    /**
     * Process single video from API
     * 
     * @param array $video_data Video data from API
     * @return array|\WP_Error Result with action (created/updated) or error
     */
    private function process_video(array $video_data): array|\WP_Error {
        // Map API fields (customize this based on your API structure)
        $mapped_data = $this->map_video_fields($video_data);
        
        if (is_wp_error($mapped_data)) {
            return $mapped_data;
        }
        
        // Check if video already exists
        $existing_post = $this->find_existing_post($mapped_data['external_id']);
        
        if ($existing_post) {
            // Update existing post
            $post_id = $this->update_video_post($existing_post->ID, $mapped_data);
            $action = 'updated';
        } else {
            // Create new post
            $post_id = $this->create_video_post($mapped_data);
            $action = 'created';
        }
        
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        
        return [
            'post_id' => $post_id,
            'action' => $action
        ];
    }
    
    /**
     * Map API fields to WordPress fields
     * 
     * @param array $video_data Raw API data
     * @return array|\WP_Error Mapped data or error
     */
    private function map_video_fields(array $video_data): array|\WP_Error {
        if (isset($video_data['video']) && is_array($video_data['video'])) {
            $video_data = $video_data['video'];
        }
        
        $external_id = $video_data['id'] ?? $video_data['video_id'] ?? $video_data['videoId'] ?? '';
        
        // Validate required fields
        if (empty($external_id)) {
            return new \WP_Error('missing_field', __('Video ID is required', '7ls-video-publisher'));
        }
        
        $playback_url = $video_data['playback_url'] ?? $video_data['playbackUrl'] ?? '';
        $video_url = $playback_url ?: ($video_data['video_url'] ?? $video_data['videoUrl'] ?? $video_data['url'] ?? '');
        $thumbnail_url = $video_data['thumbnail_url'] ?? $video_data['thumbnailUrl'] ?? $video_data['thumbUrl'] ?? '';
        $created_at = $video_data['created_at'] ?? $video_data['createdAt'] ?? '';
        $updated_at = $video_data['updated_at'] ?? $video_data['updatedAt'] ?? '';
        
        $categories = $this->normalize_term_input(
            $video_data['categories']
                ?? $video_data['category']
                ?? $video_data['categorys']
                ?? []
        );

        $tags = $this->normalize_term_input($video_data['tags'] ?? []);

        $actors = $this->normalize_term_input(
            $video_data['actors']
                ?? $video_data['actor']
                ?? $video_data['casts']
                ?? $video_data['cast']
                ?? $video_data['performers']
                ?? $video_data['starring']
                ?? $video_data['stars']
                ?? $video_data['actor_names']
                ?? []
        );
        
        return [
            'external_id' => sanitize_text_field($external_id),
            'title' => sanitize_text_field($video_data['title'] ?? $video_data['name'] ?? 'Untitled Video'),
            'description' => wp_kses_post($video_data['description'] ?? $video_data['desc'] ?? ''),
            'video_url' => esc_url_raw($video_url),
            'playback_url' => esc_url_raw($playback_url),
            'thumbnail_url' => esc_url_raw($thumbnail_url),
            'duration' => absint($video_data['duration'] ?? 0),
            'categories' => $categories,
            'tags' => $tags,
            'actors' => $actors,
            'created_at' => sanitize_text_field($created_at),
            'updated_at' => sanitize_text_field($updated_at),
            'raw_payload' => wp_json_encode($video_data)
        ];
    }

    private function normalize_term_input(mixed $value): array {
        $terms = [];

        if (is_string($value)) {
            $terms = $this->split_terms($value);
        } elseif (is_array($value)) {
            if ($value === []) {
                $terms = [];
            } elseif ($this->is_assoc($value)) {
                $term = $this->extract_term_label($value);
                if ($term !== '') {
                    $terms = $this->split_terms($term);
                }
            } else {
                foreach ($value as $item) {
                    if (is_string($item)) {
                        $terms = array_merge($terms, $this->split_terms($item));
                        continue;
                    }

                    if (is_array($item)) {
                        $term = $this->extract_term_label($item);
                        if ($term !== '') {
                            $terms = array_merge($terms, $this->split_terms($term));
                        }
                    }
                }
            }
        }

        $terms = array_map('sanitize_text_field', $terms);
        $terms = array_filter($terms, static function ($term) {
            return $term !== '';
        });

        return array_values(array_unique($terms));
    }

    private function split_terms(string $value): array {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        if (str_contains($value, ',') || str_contains($value, '|')) {
            $parts = preg_split('/[,\|]+/', $value);
            $parts = array_map('trim', $parts);
            return array_values(array_filter($parts, static function ($part) {
                return $part !== '';
            }));
        }

        return [$value];
    }

    private function extract_term_label(array $value): string {
        $keys = ['name', 'title', 'label', 'slug'];
        foreach ($keys as $key) {
            if (!empty($value[$key]) && is_string($value[$key])) {
                return $value[$key];
            }
        }

        return '';
    }

    private function is_assoc(array $value): bool {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }
    
    /**
     * Find existing post by external ID
     * 
     * @param string $external_id External video ID
     * @return \WP_Post|null Post object or null
     */
    private function find_existing_post(string $external_id): ?\WP_Post {
        $args = [
            'post_type' => 'video',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_sevenls_vp_external_id',
                    'value' => $external_id,
                    'compare' => '='
                ]
            ]
        ];
        
        $query = new \WP_Query($args);
        
        return $query->have_posts() ? $query->posts[0] : null;
    }
    
    /**
     * Create new video post
     * 
     * @param array $data Mapped video data
     * @return int|\WP_Error Post ID or error
     */
    private function create_video_post(array $data): int|\WP_Error {
        $post_data = [
            'post_type' => 'video',
            'post_title' => $data['title'],
            'post_content' => $data['description'],
            'post_status' => $this->settings['post_status'] ?? 'publish',
            'post_author' => $this->settings['post_author'] ?? get_current_user_id(),
        ];
        
        $post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        
        // Save meta data
        $this->save_video_meta($post_id, $data);
        
        // Set categories
        if (!empty($data['categories'])) {
            wp_set_object_terms($post_id, $data['categories'], 'video_category');
        }

        // Set tags
        if (!empty($data['tags'])) {
            wp_set_object_terms($post_id, $data['tags'], 'video_tag');
        }

        // Set actors
        if (!empty($data['actors'])) {
            $this->set_actor_terms($post_id, $data['actors']);
        }
        
        // Download and set thumbnail
        if (!empty($data['thumbnail_url'])) {
            $this->set_post_thumbnail($post_id, $data['thumbnail_url']);
        }
        
        return $post_id;
    }
    
    /**
     * Update existing video post
     * 
     * @param int $post_id Post ID
     * @param array $data Mapped video data
     * @return int|\WP_Error Post ID or error
     */
    private function update_video_post(int $post_id, array $data): int|\WP_Error {
        $post_data = [
            'ID' => $post_id,
            'post_title' => $data['title'],
            'post_content' => $data['description'],
        ];
        
        $result = wp_update_post($post_data, true);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Update meta data
        $this->save_video_meta($post_id, $data);
        
        // Update categories
        if (!empty($data['categories'])) {
            wp_set_object_terms($post_id, $data['categories'], 'video_category');
        }

        // Update tags
        if (!empty($data['tags'])) {
            wp_set_object_terms($post_id, $data['tags'], 'video_tag');
        }

        // Update actors
        if (!empty($data['actors'])) {
            $this->set_actor_terms($post_id, $data['actors']);
        }
        
        // Update thumbnail if URL changed
        $current_thumbnail_url = get_post_meta($post_id, '_sevenls_vp_thumbnail_url', true);
        if (!empty($data['thumbnail_url']) && $data['thumbnail_url'] !== $current_thumbnail_url) {
            $this->set_post_thumbnail($post_id, $data['thumbnail_url']);
        }
        
        return $post_id;
    }
    
    /**
     * Save video meta data
     * 
     * @param int $post_id Post ID
     * @param array $data Video data
     */
    private function save_video_meta(int $post_id, array $data): void {
        update_post_meta($post_id, '_sevenls_vp_external_id', $data['external_id']);
        update_post_meta($post_id, '_sevenls_vp_video_url', $data['video_url']);
        update_post_meta($post_id, '_sevenls_vp_playback_url', $data['playback_url'] ?? '');
        update_post_meta($post_id, '_sevenls_vp_thumbnail_url', $data['thumbnail_url']);
        update_post_meta($post_id, '_sevenls_vp_duration', $data['duration']);
        update_post_meta($post_id, '_sevenls_vp_source_created_at', $data['created_at']);
        update_post_meta($post_id, '_sevenls_vp_source_updated_at', $data['updated_at']);
        update_post_meta($post_id, '_sevenls_vp_raw_payload', $data['raw_payload']);
    }
    
    /**
     * Download and set post thumbnail
     * 
     * @param int $post_id Post ID
     * @param string $image_url Image URL
     */
    private function set_post_thumbnail(int $post_id, string $image_url): void {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        $attachment_id = media_sideload_image($image_url, $post_id, null, 'id');
        
        if (!is_wp_error($attachment_id)) {
            set_post_thumbnail($post_id, $attachment_id);
        }
    }

    private function set_actor_terms(int $post_id, array $actors): void {
        $actors = array_values(array_filter(array_map('sanitize_text_field', $actors), static function ($actor) {
            return $actor !== '';
        }));

        if (empty($actors)) {
            return;
        }

        $parent_id = $this->ensure_actor_parent_term();
        $term_ids = [];

        foreach ($actors as $actor) {
            $existing = term_exists($actor, 'video_actor');
            if (is_array($existing)) {
                $term_ids[] = (int) $existing['term_id'];
                continue;
            }

            if (is_int($existing)) {
                $term_ids[] = $existing;
                continue;
            }

            $args = [];
            if ($parent_id) {
                $args['parent'] = $parent_id;
            }

            $created = wp_insert_term($actor, 'video_actor', $args);
            if (!is_wp_error($created)) {
                $term_ids[] = (int) $created['term_id'];
            }
        }

        if (!empty($term_ids)) {
            wp_set_object_terms($post_id, $term_ids, 'video_actor');
        }
    }

    private function ensure_actor_parent_term(): ?int {
        $slug = 'actors';
        $name = 'นักแสดง';

        $existing = term_exists($slug, 'video_actor');
        if (!$existing) {
            $existing = term_exists($name, 'video_actor');
        }

        if ($existing) {
            return is_array($existing) ? (int) $existing['term_id'] : (int) $existing;
        }

        $created = wp_insert_term($name, 'video_actor', [
            'slug' => $slug,
        ]);

        if (is_wp_error($created)) {
            return null;
        }

        return (int) $created['term_id'];
    }

    /**
     * Clear cached sync transients.
     */
    private function clear_sync_transients(): void {
        global $wpdb;

        if (!$wpdb) {
            return;
        }

        $like = $wpdb->esc_like('_transient_sevenls_vp_page_') . '%';
        $timeout_like = $wpdb->esc_like('_transient_timeout_sevenls_vp_page_') . '%';

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $like,
            $timeout_like
        ));
    }
}
