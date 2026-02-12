<?php
namespace SevenLS_VP;

/**
 * Handles plugin updates from GitHub releases.
 */
class Github_Updater {

    private const API_URL = 'https://api.github.com/repos/7LS-SS1/video-gallery-plugin/releases/latest';
    private const REPO_URL = 'https://github.com/7LS-SS1/video-gallery-plugin';
    private const CACHE_KEY = 'sevenls_vp_github_latest_release';
    private const CACHE_TTL = HOUR_IN_SECONDS;

    private string $plugin_file;
    private string $plugin_slug;

    public function __construct() {
        $this->plugin_file = SEVENLS_VP_PLUGIN_BASENAME;
        $this->plugin_slug = dirname(SEVENLS_VP_PLUGIN_BASENAME);

        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'inject_plugin_information'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'normalize_package_source'], 10, 4);
        add_filter('http_request_args', [$this, 'inject_download_auth'], 10, 2);
        add_action('upgrader_process_complete', [$this, 'clear_release_cache'], 10, 2);
    }

    /**
     * Inject update details into WordPress plugin update transient.
     */
    public function inject_update(mixed $transient): mixed {
        if (!is_object($transient) || empty($transient->checked) || !isset($transient->checked[$this->plugin_file])) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if ($release === null) {
            return $transient;
        }

        $installed_version = (string) $transient->checked[$this->plugin_file];
        if (version_compare($release['version'], $installed_version, '<=')) {
            return $transient;
        }

        $transient->response[$this->plugin_file] = (object) [
            'id' => self::REPO_URL,
            'slug' => $this->plugin_slug,
            'plugin' => $this->plugin_file,
            'new_version' => $release['version'],
            'url' => self::REPO_URL,
            'package' => $release['package'],
            'tested' => get_bloginfo('version'),
            'requires_php' => '8.0',
        ];

        return $transient;
    }

    /**
     * Provide popup plugin details for the "View details" modal.
     */
    public function inject_plugin_information(mixed $result, string $action, mixed $args): mixed {
        if ($action !== 'plugin_information' || !is_object($args) || empty($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }

        $release = $this->get_latest_release();
        if ($release === null) {
            return $result;
        }

        return (object) [
            'name' => '7M Video Publisher',
            'slug' => $this->plugin_slug,
            'version' => $release['version'],
            'author' => '<a href="https://github.com/7LS-SS1">7LS</a>',
            'homepage' => self::REPO_URL,
            'requires' => '6.0',
            'requires_php' => '8.0',
            'last_updated' => $release['published_at'],
            'download_link' => $release['package'],
            'sections' => [
                'description' => wpautop(
                    __('Syncs videos from external media-storage-api and publishes them as custom post type in WordPress.', '7ls-video-publisher')
                ),
                'changelog' => wpautop($release['changelog']),
            ],
        ];
    }

    /**
     * Ensure update package extracts into the current plugin directory name.
     */
    public function normalize_package_source(mixed $source, string $remote_source, \WP_Upgrader $upgrader, array $hook_extra): mixed {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_file) {
            return $source;
        }

        global $wp_filesystem;
        if (!$wp_filesystem) {
            return $source;
        }

        $source = untrailingslashit((string) $source);
        $expected_source = trailingslashit(dirname($source)) . $this->plugin_slug;
        if ($source === $expected_source) {
            return $source;
        }

        if ($wp_filesystem->exists($expected_source)) {
            $wp_filesystem->delete($expected_source, true);
        }

        if (!$wp_filesystem->move($source, $expected_source, true)) {
            return new \WP_Error(
                'sevenls_vp_github_updater_source_error',
                __('Could not prepare the GitHub update package.', '7ls-video-publisher')
            );
        }

        return $expected_source;
    }

    /**
     * Add GitHub token for repository package downloads when needed.
     */
    public function inject_download_auth(array $args, string $url): array {
        $token = $this->get_github_token();
        if ($token === '') {
            return $args;
        }

        $is_repo_request = str_contains($url, 'api.github.com/repos/7LS-SS1/video-gallery-plugin')
            || str_contains($url, 'github.com/7LS-SS1/video-gallery-plugin/')
            || str_contains($url, 'codeload.github.com/7LS-SS1/video-gallery-plugin/');

        if (!$is_repo_request) {
            return $args;
        }

        if (empty($args['headers']) || !is_array($args['headers'])) {
            $args['headers'] = [];
        }

        $args['headers']['Authorization'] = 'Bearer ' . $token;
        $args['headers']['Accept'] = 'application/vnd.github+json';

        return $args;
    }

    /**
     * Clear cached release data after plugin upgrades.
     */
    public function clear_release_cache(\WP_Upgrader $upgrader, array $hook_extra): void {
        if (($hook_extra['action'] ?? '') !== 'update' || ($hook_extra['type'] ?? '') !== 'plugin') {
            return;
        }

        $updated_plugins = $hook_extra['plugins'] ?? [];
        if (!is_array($updated_plugins) || !in_array($this->plugin_file, $updated_plugins, true)) {
            return;
        }

        delete_site_transient(self::CACHE_KEY);
    }

    /**
     * Fetch and cache latest GitHub release.
     */
    private function get_latest_release(): ?array {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached) && !empty($cached['version']) && !empty($cached['package'])) {
            return $cached;
        }

        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
        ];

        $token = $this->get_github_token();
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_get(self::API_URL, [
            'headers' => $headers,
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['tag_name'])) {
            return null;
        }

        $version = ltrim((string) $body['tag_name'], "vV \t\n\r\0\x0B");
        $package = $this->pick_package_url($body);
        if ($version === '' || $package === '') {
            return null;
        }

        $release = [
            'version' => $version,
            'package' => $package,
            'published_at' => !empty($body['published_at']) ? (string) $body['published_at'] : '',
            'changelog' => !empty($body['body'])
                ? wp_kses_post((string) $body['body'])
                : __('No changelog provided for this release.', '7ls-video-publisher'),
        ];

        set_site_transient(self::CACHE_KEY, $release, self::CACHE_TTL);

        return $release;
    }

    /**
     * Prefer ZIP release assets, then fallback to GitHub zipball.
     */
    private function pick_package_url(array $release): string {
        $assets = $release['assets'] ?? [];
        if (is_array($assets)) {
            foreach ($assets as $asset) {
                if (!is_array($asset) || empty($asset['browser_download_url']) || empty($asset['name'])) {
                    continue;
                }

                $name = strtolower((string) $asset['name']);
                if (str_ends_with($name, '.zip')) {
                    return (string) $asset['browser_download_url'];
                }
            }
        }

        return !empty($release['zipball_url']) ? (string) $release['zipball_url'] : '';
    }

    /**
     * Optional token for private repositories or higher GitHub API limits.
     */
    private function get_github_token(): string {
        return trim((string) apply_filters('sevenls_vp_github_token', ''));
    }
}
