<?php
namespace SevenLS_VP;

/**
 * API Client Class
 * 
 * Handles communication with external media-storage-api
 */
class API_Client {
    
    private string $base_url;
    private string $api_key;
    private string $project_id;
    
    /**
     * Constructor
     */
    public function __construct() {
        $settings = get_option('sevenls_vp_settings', []);
        
        $this->base_url = rtrim($settings['api_base_url'] ?? '', '/');
        $this->api_key = $settings['api_key'] ?? '';
        $this->project_id = $settings['project_id'] ?? '';
    }
    
    /**
     * Fetch videos from API
     * 
     * @param array $args Query arguments (page, per_page, since)
     * @return array|\WP_Error Response data or error
     */
    public function fetch_videos(array $args = []): array|\WP_Error {
        if (empty($this->base_url) || empty($this->api_key)) {
            return new \WP_Error('api_not_configured', __('API credentials not configured', '7ls-video-publisher'));
        }
        
        $defaults = [
            'page' => 1,
            'per_page' => 20,
            'limit' => null,
            'since' => null
        ];
        
        $args = wp_parse_args($args, $defaults);
        $limit = $args['limit'] !== null ? (int) $args['limit'] : null;
        $per_page = $limit !== null ? $limit : (int) $args['per_page'];
        
        // Build query string
        $query_params = [
            'page' => $args['page'],
            'per_page' => $per_page,
            'limit' => $per_page
        ];
        
        if ($args['since']) {
            $query_params['since'] = $args['since'];
        }
        
        if (!empty($this->project_id)) {
            $query_params['project_id'] = $this->project_id;
        }
        
        $url = $this->build_list_url($query_params);
        
        // Make request
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Accept' => 'application/json',
                'User-Agent' => '7LS-Video-Publisher/' . SEVENLS_VP_VERSION
            ],
            'timeout' => 30
        ]);
        
        // Handle errors
        if (is_wp_error($response)) {
            Logger::log('API request failed: ' . $response->get_error_message(), 'error');
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            $error_msg = sprintf(__('API returned status %d: %s', '7ls-video-publisher'), $status_code, $body);
            Logger::log($error_msg, 'error');
            return new \WP_Error('api_error', $error_msg);
        }
        
        // Parse JSON
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error('json_error', __('Failed to parse API response', '7ls-video-publisher'));
        }

        return $this->normalize_list_response($data);
    }
    
    /**
     * Fetch single video by ID
     * 
     * @param string $video_id External video ID
     * @return array|\WP_Error Video data or error
     */
    public function fetch_video(string $video_id): array|\WP_Error {
        if (empty($this->base_url) || empty($this->api_key)) {
            return new \WP_Error('api_not_configured', __('API credentials not configured', '7ls-video-publisher'));
        }
        
        $url = $this->build_single_url($video_id);
        
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Accept' => 'application/json'
            ],
            'timeout' => 15
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code !== 200) {
            return new \WP_Error('api_error', sprintf(__('API returned status %d', '7ls-video-publisher'), $status_code));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error('json_error', __('Failed to parse API response', '7ls-video-publisher'));
        }

        return $this->normalize_single_response($data);
    }
    
    /**
     * Test API connection
     * 
     * @return bool|\WP_Error True on success, WP_Error on failure
     */
    public function test_connection(): bool|\WP_Error {
        $result = $this->fetch_videos(['per_page' => 1]);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return true;
    }

    /**
     * Trigger server-side sync for all videos.
     *
     * @return array|\WP_Error Response data or error
     */
    public function trigger_plugin_sync(array $payload = []): array|\WP_Error {
        if (empty($this->base_url) || empty($this->api_key)) {
            return new \WP_Error('api_not_configured', __('API credentials not configured', '7ls-video-publisher'));
        }

        $url = $this->build_plugin_sync_url();
        $body = wp_json_encode($payload);

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => '7LS-Video-Publisher/' . SEVENLS_VP_VERSION
            ],
            'body' => $body,
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            Logger::log('API sync request failed: ' . $response->get_error_message(), 'error');
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code < 200 || $status_code >= 300) {
            $error_msg = sprintf(__('API sync returned status %d: %s', '7ls-video-publisher'), $status_code, $body);
            Logger::log($error_msg, 'error');
            return new \WP_Error('api_error', $error_msg);
        }

        $data = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }

        return [
            'status_code' => $status_code,
            'body' => $body
        ];
    }

    /**
     * Build list endpoint URL.
     *
     * @param array $query_params Query parameters
     * @return string
     */
    private function build_list_url(array $query_params): string {
        $endpoint = $this->build_videos_endpoint();
        $query = http_build_query($query_params);
        
        if ($query === '') {
            return $endpoint;
        }
        
        return $endpoint . '?' . $query;
    }
    
    /**
     * Build single video endpoint URL.
     *
     * @param string $video_id External video ID
     * @return string
     */
    private function build_single_url(string $video_id): string {
        $endpoint = $this->build_videos_endpoint();
        
        return $endpoint . '/' . urlencode($video_id);
    }
    
    /**
     * Build base endpoint for videos.
     *
     * @return string
     */
    private function build_videos_endpoint(): string {
        $base = rtrim($this->base_url, '/');
        
        if ($base === '') {
            return '/videos';
        }
        
        if ($this->ends_with_path_segment($base, 'videos')) {
            return $base;
        }
        
        return $base . '/videos';
    }

    /**
     * Build plugin sync endpoint URL.
     *
     * @return string
     */
    private function build_plugin_sync_url(): string {
        $base = rtrim($this->base_url, '/');

        if ($base === '') {
            return '/api/plugin/videos/sync';
        }

        if ($this->ends_with_path_segment($base, 'sync')) {
            return $base;
        }

        if ($this->ends_with_path_segment($base, 'videos')) {
            return $base . '/sync';
        }

        if ($this->ends_with_path_segment($base, 'plugin')) {
            return $base . '/videos/sync';
        }

        if ($this->ends_with_path_segment($base, 'api')) {
            return $base . '/plugin/videos/sync';
        }

        return $base . '/api/plugin/videos/sync';
    }
    
    /**
     * Check if URL ends with a path segment.
     *
     * @param string $url URL or path
     * @param string $segment Segment name
     * @return bool
     */
    private function ends_with_path_segment(string $url, string $segment): bool {
        $trimmed = rtrim($url, '/');
        $parts = explode('/', $trimmed);
        $last = end($parts);
        
        return strtolower($last) === strtolower($segment);
    }

    /**
     * Normalize list responses into the expected shape.
     *
     * @param array $data Raw decoded response
     * @return array|\WP_Error Normalized response or error
     */
    private function normalize_list_response(array $data): array|\WP_Error {
        $videos = $this->extract_videos($data);
        
        if ($videos === null) {
            return new \WP_Error('api_response_invalid', __('API response does not include video data', '7ls-video-publisher'));
        }
        
        return [
            'data' => $videos,
            'pagination' => $this->extract_pagination($data),
        ];
    }
    
    /**
     * Normalize single video responses.
     *
     * @param array $data Raw decoded response
     * @return array|\WP_Error Video data or error
     */
    private function normalize_single_response(array $data): array|\WP_Error {
        if (isset($data['video']) && is_array($data['video'])) {
            return $data['video'];
        }
        
        if (isset($data['data']) && is_array($data['data']) && $this->looks_like_video($data['data'])) {
            return $data['data'];
        }
        
        return $data;
    }
    
    /**
     * Extract video list from multiple possible response shapes.
     *
     * @param array $data Raw decoded response
     * @return array|null Video list or null when not found
     */
    private function extract_videos(array $data): ?array {
        if ($this->is_list_array($data)) {
            return $data;
        }
        
        if (isset($data['data'])) {
            if (is_array($data['data']) && $this->is_list_array($data['data'])) {
                return $data['data'];
            }
            if (is_array($data['data']) && $this->looks_like_video($data['data'])) {
                return [$data['data']];
            }
            if (is_array($data['data']) && isset($data['data']['videos']) && is_array($data['data']['videos'])) {
                if ($this->is_list_array($data['data']['videos'])) {
                    return $data['data']['videos'];
                }
                if ($this->looks_like_video($data['data']['videos'])) {
                    return [$data['data']['videos']];
                }
            }
        }
        
        if (isset($data['videos']) && is_array($data['videos'])) {
            if ($this->is_list_array($data['videos'])) {
                return $data['videos'];
            }
            if ($this->looks_like_video($data['videos'])) {
                return [$data['videos']];
            }
        }
        
        if (isset($data['items']) && is_array($data['items']) && $this->is_list_array($data['items'])) {
            return $data['items'];
        }
        
        if (isset($data['results']) && is_array($data['results']) && $this->is_list_array($data['results'])) {
            return $data['results'];
        }
        
        if (isset($data['video']) && is_array($data['video'])) {
            return [$data['video']];
        }
        
        return null;
    }
    
    /**
     * Extract pagination with fallbacks.
     *
     * @param array $data Raw decoded response
     * @return array Normalized pagination data
     */
    private function extract_pagination(array $data): array {
        $pagination = [];
        
        if (isset($data['pagination']) && is_array($data['pagination'])) {
            $pagination = $data['pagination'];
        } elseif (isset($data['meta']) && is_array($data['meta'])) {
            $pagination = $data['meta'];
        }
        
        $page = $pagination['page']
            ?? $pagination['current_page']
            ?? $pagination['currentPage']
            ?? $data['page']
            ?? 1;
        $per_page = $pagination['per_page']
            ?? $pagination['perPage']
            ?? $pagination['limit']
            ?? $pagination['page_size']
            ?? $pagination['pageSize']
            ?? $data['per_page']
            ?? $data['perPage']
            ?? $data['limit']
            ?? null;
        $total = $pagination['total']
            ?? $pagination['total_count']
            ?? $pagination['totalCount']
            ?? $data['total']
            ?? $data['totalCount']
            ?? null;
        $total_pages = $pagination['total_pages']
            ?? $pagination['totalPages']
            ?? $pagination['last_page']
            ?? $pagination['pages']
            ?? $data['total_pages']
            ?? $data['totalPages']
            ?? null;
        $next_page = $pagination['next_page']
            ?? $pagination['nextPage']
            ?? $data['next_page']
            ?? $data['nextPage']
            ?? null;
        $has_more = $pagination['has_more']
            ?? $pagination['hasMore']
            ?? $data['has_more']
            ?? $data['hasMore']
            ?? null;
        
        $page = (int) $page;
        $per_page = $per_page !== null ? (int) $per_page : null;
        $total = $total !== null ? (int) $total : null;
        $total_pages = $total_pages !== null ? (int) $total_pages : null;
        $next_page = $next_page !== null ? (int) $next_page : null;
        
        if ((!$total_pages || $total_pages < 1) && $total !== null && $per_page) {
            $total_pages = (int) ceil($total / max(1, $per_page));
        }
        
        if ($has_more !== null) {
            $has_more = (bool) $has_more;
        } elseif ($next_page && $next_page > $page) {
            $has_more = true;
        } elseif ($total !== null && $per_page) {
            $has_more = ($page * $per_page) < $total;
        } elseif ($total_pages !== null) {
            $has_more = $page < $total_pages;
        } else {
            $has_more = null;
        }
        
        return [
            'page' => $page > 0 ? $page : 1,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => $total_pages && $total_pages > 0 ? $total_pages : 1,
            'next_page' => $next_page && $next_page > 0 ? $next_page : null,
            'has_more' => $has_more,
        ];
    }
    
    /**
     * Check if array is a list (numeric keys).
     *
     * @param array $data Array to check
     * @return bool True when list-like
     */
    private function is_list_array(array $data): bool {
        if ($data === []) {
            return true;
        }
        
        return array_keys($data) === range(0, count($data) - 1);
    }
    
    /**
     * Detect if data looks like a video object.
     *
     * @param array $data Data to check
     * @return bool True when likely video data
     */
    private function looks_like_video(array $data): bool {
        return isset($data['id']) || isset($data['video_url']) || isset($data['videoUrl']);
    }
}
