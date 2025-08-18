<?php

namespace KissSmartBatchInstaller\Core;

/**
 * GitHub Organization Scraper
 *
 * Handles scraping GitHub organization repositories page and
 * identifying WordPress plugins from the most recently updated repos.
 */
class GitHubScraper
{
    private $org_name;
    private $cache_duration;
    private $repo_limit;

    public function __construct()
    {
        $this->org_name = get_option('kiss_sbi_github_org', '');
        $this->cache_duration = get_option('kiss_sbi_cache_duration', 3600);
        $this->repo_limit = get_option('kiss_sbi_repo_limit', 15);

        // AJAX handlers
        add_action('wp_ajax_kiss_sbi_refresh_repos', [$this, 'ajaxRefreshRepositories']);
        add_action('wp_ajax_kiss_sbi_scan_plugins', [$this, 'ajaxScanForPlugins']);
        add_action('wp_ajax_kiss_sbi_clear_cache', [$this, 'ajaxClearCache']);
    }

    /**
     * Get repositories from cache or scrape fresh data
     */
    public function getRepositories($force_refresh = false, $page = 1, $per_page = null)
    {
        if (empty($this->org_name)) {
            return new \WP_Error('no_org', __('No GitHub organization configured.', 'kiss-smart-batch-installer'));
        }

        if ($per_page === null) {
            $per_page = $this->repo_limit;
        }

        $cache_key = 'kiss_sbi_repositories_' . sanitize_key($this->org_name);

        if (!$force_refresh) {
            $cached_repos = get_transient($cache_key);
            if ($cached_repos !== false) {
                return $this->paginateRepositories($cached_repos, $page, $per_page);
            }
        }

        $repositories = $this->scrapeOrganizationRepos();

        if (is_wp_error($repositories)) {
            return $repositories;
        }

        // Cache the results
        set_transient($cache_key, $repositories, $this->cache_duration);

        return $this->paginateRepositories($repositories, $page, $per_page);
    }

    /**
     * Paginate repositories array
     */
    private function paginateRepositories($repositories, $page = 1, $per_page = 15)
    {
        $total = count($repositories);
        $offset = ($page - 1) * $per_page;
        $paged_repos = array_slice($repositories, $offset, $per_page);

        return [
            'repositories' => $paged_repos,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $per_page,
                'total_items' => $total,
                'total_pages' => ceil($total / $per_page)
            ]
        ];
    }

    /**
     * Scrape GitHub organization repositories page
     */
    private function scrapeOrganizationRepos()
    {
        $url = sprintf('https://github.com/%s?tab=repositories', urlencode($this->org_name));

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ]
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('request_failed', __('Failed to fetch GitHub page.', 'kiss-smart-batch-installer'));
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return new \WP_Error('invalid_response', sprintf(__('GitHub returned status code: %d', 'kiss-smart-batch-installer'), $response_code));
        }

        $html = wp_remote_retrieve_body($response);

        // Try DOM parsing first
        $repositories = $this->parseRepositoriesFromHtml($html);

        // If DOM parsing fails, try regex fallback
        if (is_wp_error($repositories)) {
            error_log('KISS SBI: DOM parsing failed, trying regex fallback');
            $repositories = $this->parseRepositoriesWithRegex($html);
        }

        return $repositories;
    }

    /**
     * Parse repositories from GitHub HTML
     */
    private function parseRepositoriesFromHtml($html)
    {
        $repositories = [];

        // Use DOMDocument for robust HTML parsing
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // GitHub repository list items - updated selectors for current GitHub structure
        $selectors = [
            // New GitHub structure (2024)
            '//div[@data-testid="repositories-list"]//div[contains(@class, "Box-row")]',
            '//div[@data-testid="repository-list"]//div[contains(@class, "Box-row")]',
            '//div[contains(@class, "user-repo-search-results-summary")]//following-sibling::div//div[contains(@class, "Box-row")]',
            '//div[@id="user-repositories-list"]//div[contains(@class, "Box-row")]',
            '//li[contains(@class, "Box-row")]',
            // Fallback: any div with repo link pattern
            '//div[.//a[contains(@href, "/' . $this->org_name . '/")]]',
            // Alternative: article elements
            '//article[contains(@class, "Box-row")]'
        ];

        $repo_elements = null;
        foreach ($selectors as $selector) {
            $repo_elements = $xpath->query($selector);
            if ($repo_elements && $repo_elements->length > 0) {
                break;
            }
        }

        if (!$repo_elements || $repo_elements->length === 0) {
            // Debug: Log some information about what we found
            error_log('KISS SBI Debug: No repository elements found');
            error_log('KISS SBI Debug: HTML length: ' . strlen($html));
            error_log('KISS SBI Debug: HTML contains "kissplugins": ' . (strpos($html, 'kissplugins') !== false ? 'yes' : 'no'));
            error_log('KISS SBI Debug: HTML contains "Box-row": ' . (strpos($html, 'Box-row') !== false ? 'yes' : 'no'));

            // Try to find any repository-like elements for debugging
            $debug_selectors = [
                '//div[contains(@class, "Box")]',
                '//div[contains(text(), "' . $this->org_name . '")]',
                '//a[contains(@href, "' . $this->org_name . '")]'
            ];

            foreach ($debug_selectors as $selector) {
                $debug_elements = $xpath->query($selector);
                error_log('KISS SBI Debug: Selector "' . $selector . '" found ' . $debug_elements->length . ' elements');
            }

            return new \WP_Error('parse_failed', __('Could not find repositories in GitHub page.', 'kiss-smart-batch-installer'));
        }

        $count = 0;
        $seen_repos = []; // Track seen repositories to prevent duplicates

        foreach ($repo_elements as $element) {
            if ($count >= $this->repo_limit) {
                break;
            }

            $repo_data = $this->extractRepositoryData($element, $xpath);
            if ($repo_data) {
                // Check for duplicates
                $repo_name = $repo_data['name'];
                if (isset($seen_repos[$repo_name])) {
                    continue; // Skip duplicate
                }

                $seen_repos[$repo_name] = true;
                $repositories[] = $repo_data;
                $count++;
            }
        }

        // Final safety check: remove any remaining duplicates
        $unique_repositories = [];
        $seen_names = [];

        foreach ($repositories as $repo) {
            if (!isset($seen_names[$repo['name']])) {
                $seen_names[$repo['name']] = true;
                $unique_repositories[] = $repo;
            }
        }

        if (count($repositories) !== count($unique_repositories)) {
            error_log('KISS SBI Debug: Removed ' . (count($repositories) - count($unique_repositories)) . ' duplicate repositories in final check');
        }

        return $unique_repositories;
    }

    /**
     * Fallback: Parse repositories using regex when DOM parsing fails
     */
    private function parseRepositoriesWithRegex($html)
    {
        $repositories = [];

        // Look for repository links in the HTML
        $pattern = '#<a[^>]+href=["\']/' . preg_quote($this->org_name, '#') . '/([^/"\'\?]+)["\'][^>]*>([^<]+)</a>#i';

        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            $count = 0;
            $seen_repos = [];

            foreach ($matches as $match) {
                if ($count >= $this->repo_limit) {
                    break;
                }

                $repo_name = trim($match[1]);

                // Skip duplicates and invalid names
                if (empty($repo_name) || isset($seen_repos[$repo_name]) ||
                    strpos($repo_name, '.') === 0 || // Skip hidden repos
                    in_array($repo_name, ['settings', 'security', 'insights'])) { // Skip non-repo pages
                    continue;
                }

                $seen_repos[$repo_name] = true;

                $repositories[] = [
                    'name' => sanitize_text_field($repo_name),
                    'description' => '', // Will be empty in regex fallback
                    'language' => '',
                    'updated_at' => '',
                    'url' => 'https://github.com/' . $this->org_name . '/' . $repo_name,
                    'is_wordpress_plugin' => null
                ];

                $count++;
            }
        }

        if (empty($repositories)) {
            return new \WP_Error('parse_failed', __('Could not find any repositories using fallback method.', 'kiss-smart-batch-installer'));
        }

        // Final safety check: remove any remaining duplicates (though regex method already handles this)
        $unique_repositories = [];
        $seen_names = [];

        foreach ($repositories as $repo) {
            if (!isset($seen_names[$repo['name']])) {
                $seen_names[$repo['name']] = true;
                $unique_repositories[] = $repo;
            }
        }

        if (count($repositories) !== count($unique_repositories)) {
            error_log('KISS SBI Debug: Removed ' . (count($repositories) - count($unique_repositories)) . ' duplicate repositories in regex fallback final check');
        }

        error_log('KISS SBI: Regex fallback found ' . count($unique_repositories) . ' repositories');
        return $unique_repositories;
    }

    /**
     * Extract repository data from DOM element
     */
    private function extractRepositoryData($element, $xpath)
    {
        // Find repository name link - try multiple patterns
        $link_selectors = [
            './/a[contains(@href, "/' . $this->org_name . '/")]',
            './/h3//a[contains(@href, "/' . $this->org_name . '/")]',
            './/h4//a[contains(@href, "/' . $this->org_name . '/")]',
            './/span[contains(@class, "repo")]//a'
        ];

        $name_link = null;
        foreach ($link_selectors as $selector) {
            $name_links = $xpath->query($selector, $element);
            if ($name_links->length > 0) {
                $name_link = $name_links->item(0);
                break;
            }
        }

        if (!$name_link) {
            return null;
        }

        $href = $name_link->getAttribute('href');

        // Extract repo name from href
        if (!preg_match('#/' . preg_quote($this->org_name, '#') . '/([^/\?]+)#', $href, $matches)) {
            return null;
        }

        $repo_name = $matches[1];

        // Get description - try multiple selectors
        $description_selectors = [
            './/p[contains(@class, "repository-description")]',
            './/p[contains(@class, "description")]',
            './/div[contains(@class, "description")]//p',
            './/span[contains(@class, "description")]'
        ];

        $description = '';
        foreach ($description_selectors as $selector) {
            $description_elements = $xpath->query($selector, $element);
            if ($description_elements->length > 0) {
                $description = trim($description_elements->item(0)->textContent);
                if (!empty($description)) {
                    break;
                }
            }
        }

        // Get language - try multiple selectors
        $language_selectors = [
            './/*[contains(@class, "language")]',
            './/*[@data-testid="repository-language"]',
            './/span[contains(@class, "programmingLanguage")]'
        ];

        $language = '';
        foreach ($language_selectors as $selector) {
            $language_elements = $xpath->query($selector, $element);
            if ($language_elements->length > 0) {
                $language = trim($language_elements->item(0)->textContent);
                if (!empty($language)) {
                    break;
                }
            }
        }

        // Get last update info - try multiple selectors
        $time_selectors = [
            './/relative-time',
            './/*[@datetime]',
            './/time'
        ];

        $updated_at = '';
        foreach ($time_selectors as $selector) {
            $time_elements = $xpath->query($selector, $element);
            if ($time_elements->length > 0) {
                $updated_at = $time_elements->item(0)->getAttribute('datetime');
                if (!empty($updated_at)) {
                    break;
                }
            }
        }

        return [
            'name' => sanitize_text_field($repo_name),
            'description' => sanitize_text_field($description),
            'language' => sanitize_text_field($language),
            'updated_at' => sanitize_text_field($updated_at),
            'url' => 'https://github.com/' . $this->org_name . '/' . $repo_name,
            'is_wordpress_plugin' => null // To be determined later
        ];
    }

    /**
     * Check if repository contains a WordPress plugin
     */
    public function isWordPressPlugin($repo_name)
    {
        $cache_key = 'kiss_sbi_plugin_check_' . sanitize_key($this->org_name . '_' . $repo_name);
        $cached_result = get_transient($cache_key);

        // Clear cache for known false positives to force re-check with improved logic
        $false_positives = ['tab-toggle', 'secret-sauce-llm-system-instructions'];
        if (in_array($repo_name, $false_positives)) {
            delete_transient($cache_key);
            $cached_result = false;
        }

        if ($cached_result !== false) {
            return $cached_result;
        }

        $plugin_data = $this->checkForPluginFile($repo_name);

        // Cache for 24 hours
        set_transient($cache_key, $plugin_data, DAY_IN_SECONDS);

        return $plugin_data;
    }

    /**
     * Check repository for WordPress plugin files
     */
    private function checkForPluginFile($repo_name)
    {
        // Common plugin file patterns
        $possible_files = [
            $repo_name . '.php',
            'index.php',
            $repo_name . '-plugin.php',
            'plugin.php',
            'main.php'
        ];

        foreach ($possible_files as $filename) {
            $plugin_data = $this->fetchPluginHeader($repo_name, $filename);
            if ($plugin_data !== false) {
                return $plugin_data;
            }
        }

        return false;
    }

    /**
     * Fetch and parse WordPress plugin header
     */
    private function fetchPluginHeader($repo_name, $filename)
    {
        // Only check PHP files for WordPress plugin headers
        if (!preg_match('/\.php$/i', $filename)) {
            return false;
        }

        $url = sprintf(
            'https://raw.githubusercontent.com/%s/%s/main/%s',
            urlencode($this->org_name),
            urlencode($repo_name),
            urlencode($filename)
        );

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $content = wp_remote_retrieve_body($response);

        // Ensure this is actually a PHP file with proper opening tag
        if (!preg_match('/^\s*<\?php/i', $content)) {
            return false;
        }

        // Look for WordPress plugin header in a PHP comment block
        // Must be in the format: Plugin Name: [name] within the first 8 kilobytes
        $header_content = substr($content, 0, 8192);

        // Look for plugin header within a comment block
        if (preg_match('/\/\*.*?Plugin Name:\s*(.+?).*?\*\//is', $header_content, $matches)) {
            $plugin_name = trim($matches[1]);

            // Additional validation: ensure it's not just documentation
            if (empty($plugin_name) || strlen($plugin_name) > 100) {
                return false;
            }

            // Extract other headers from the same comment block
            $version = '';
            $description = '';

            if (preg_match('/\/\*.*?Version:\s*(.+?).*?\*\//is', $header_content, $version_matches)) {
                $version = trim($version_matches[1]);
            }

            if (preg_match('/\/\*.*?Description:\s*(.+?).*?\*\//is', $header_content, $desc_matches)) {
                $description = trim($desc_matches[1]);
            }

            return [
                'plugin_file' => $filename,
                'plugin_name' => sanitize_text_field($plugin_name),
                'version' => sanitize_text_field($version),
                'description' => sanitize_text_field($description)
            ];
        }

        return false;
    }

    /**
     * AJAX handler for refreshing repositories
     */
    public function ajaxRefreshRepositories()
    {
        check_ajax_referer('kiss_sbi_admin_nonce', 'nonce');

        if (!current_user_can('install_plugins')) {
            wp_die(__('Insufficient permissions.', 'kiss-smart-batch-installer'));
        }

        $repositories = $this->getRepositories(true);

        if (is_wp_error($repositories)) {
            wp_send_json_error($repositories->get_error_message());
        }

        wp_send_json_success([
            'repositories' => $repositories,
            'count' => count($repositories)
        ]);
    }

    /**
     * AJAX handler to clear repository cache
     */
    public function ajaxClearCache()
    {
        check_ajax_referer('kiss_sbi_admin_nonce', 'nonce');

        if (!current_user_can('install_plugins')) {
            wp_die(__('Insufficient permissions.', 'kiss-smart-batch-installer'));
        }

        $cache_key = 'kiss_sbi_repositories_' . sanitize_key($this->org_name);
        delete_transient($cache_key);

        wp_send_json_success(['cleared' => true]);
    }

    /**
     * AJAX handler for scanning plugins
     */
    public function ajaxScanForPlugins()
    {
        check_ajax_referer('kiss_sbi_admin_nonce', 'nonce');

        if (!current_user_can('install_plugins')) {
            wp_die(__('Insufficient permissions.', 'kiss-smart-batch-installer'));
        }

        $repo_name = sanitize_text_field($_POST['repo_name'] ?? '');

        if (empty($repo_name)) {
            wp_send_json_error(__('Repository name is required.', 'kiss-smart-batch-installer'));
        }

        $plugin_data = $this->isWordPressPlugin($repo_name);

        wp_send_json_success([
            'is_plugin' => $plugin_data !== false,
            'plugin_data' => $plugin_data
        ]);
    }
}