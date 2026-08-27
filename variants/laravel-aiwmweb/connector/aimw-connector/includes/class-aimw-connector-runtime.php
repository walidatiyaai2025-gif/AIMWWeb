<?php

defined('ABSPATH') || exit;

final class AIMW_Connector_Runtime
{
    public static function capabilities(): array
    {
        $config = get_option('aimw_connector', []);
        $states = [];
        foreach (AIMW_Connector_Security::CAPABILITIES as $scope) {
            $states[$scope] = self::scope_state($scope, $config);
        }

        return [
            'states' => $states,
            'enabled_scopes' => array_values((array) ($config['enabled_scopes'] ?? [])),
            'high_risk_disabled_by_default' => AIMW_Connector_Security::HIGH_RISK_DISABLED_SCOPES,
            'adapters' => self::adapters(),
        ];
    }

    public static function scope_state(string $scope, ?array $config = null): array
    {
        $config ??= get_option('aimw_connector', []);
        $enabled = in_array($scope, (array) ($config['enabled_scopes'] ?? []), true);
        if ($scope === 'backup.restore') {
            return ['state' => 'TEMPORARILY_UNAVAILABLE', 'enabled' => false, 'reason' => 'Full restore requires a host-specific restore adapter and is intentionally not exposed.'];
        }
        $capability = self::wp_capability_for_scope($scope);
        if ($capability !== null) {
            $owner = (int) ($config['owner_user_id'] ?? 0);
            if ($owner <= 0 || ! get_user_by('id', $owner)) {
                return ['state' => 'TEMPORARILY_UNAVAILABLE', 'enabled' => false, 'reason' => 'Connector owner identity is unavailable; re-pairing is required.'];
            }
            if (! user_can($owner, $capability)) {
                return ['state' => 'UNSUPPORTED', 'enabled' => false, 'reason' => 'The paired owner no longer has the required WordPress capability: '.$capability.'.'];
            }
        }

        return ['state' => $enabled ? 'SUPPORTED_ENABLED' : 'SUPPORTED_DISABLED', 'enabled' => $enabled, 'reason' => null];
    }

    public static function operate(string $operation, array $arguments): array|WP_Error
    {
        try {
            AIMW_Connector_Security::operation_scopes($operation, $arguments);
            self::require_owner_for_operation($operation);
            if (str_starts_with($operation, 'database.')) {
                AIMW_Connector_Security::reject_unsafe_database_arguments($arguments);
            }

            return match ($operation) {
                'adapters.list' => ['status' => 'succeeded', 'items' => self::adapters()],
                'plugins.list' => ['status' => 'succeeded', 'items' => self::plugins()],
                'plugin.install' => self::plugin_install($arguments),
                'plugin.activate' => self::plugin_activate($arguments),
                'plugin.deactivate' => self::plugin_deactivate($arguments),
                'plugin.update' => self::plugin_update($arguments),
                'plugin.delete' => self::plugin_delete($arguments),
                'themes.list' => ['status' => 'succeeded', 'items' => self::themes()],
                'theme.install' => self::theme_install($arguments),
                'theme.activate' => self::theme_activate($arguments),
                'theme.update' => self::theme_update($arguments),
                'theme.delete' => self::theme_delete($arguments),
                'cache.purge' => self::cache_purge(),
                'cron.list', 'cron.inspect' => ['status' => 'succeeded', 'items' => self::cron_inventory()],
                'cron.run_due' => self::cron_run_due(),
                'cron.run' => self::cron_run($arguments),
                'site.health' => ['status' => 'succeeded', 'health' => self::site_health()],
                'backup.list' => ['status' => 'succeeded', 'items' => self::backup_list()],
                'backup.inspect' => self::backup_inspect($arguments),
                'backup.create' => self::backup_create($arguments),
                'backup.restore' => new WP_Error('restore_unavailable', 'Full restore is not available without an approved host restore adapter.', ['status' => 409]),
                'filesystem.inspect' => self::filesystem_inspect($arguments),
                'database.health' => ['status' => 'succeeded', 'health' => self::database_health()],
                'database.optimize' => self::database_optimize($arguments),
                default => new WP_Error('unsupported_operation', 'Unsupported connector operation.', ['status' => 422]),
            };
        } catch (InvalidArgumentException $exception) {
            return new WP_Error('invalid_operation', $exception->getMessage(), ['status' => 422]);
        }
    }

    public static function adapters(): array
    {
        require_once ABSPATH.'wp-admin/includes/plugin.php';

        return [
            self::adapter('wordpress-core', true, true, get_bloginfo('version')),
            self::plugin_adapter('yoast-seo', 'wordpress-seo/wp-seo.php', defined('WPSEO_VERSION') || class_exists('WPSEO_Options')),
            self::plugin_adapter('rank-math', 'seo-by-rank-math/rank-math.php', defined('RANK_MATH_VERSION') || class_exists('RankMath')),
            self::plugin_adapter('woocommerce', 'woocommerce/woocommerce.php', defined('WC_VERSION') || class_exists('WooCommerce')),
            self::plugin_adapter('elementor', 'elementor/elementor.php', defined('ELEMENTOR_VERSION') || class_exists('Elementor\\Plugin')),
            self::plugin_adapter('litespeed-cache', 'litespeed-cache/litespeed-cache.php', defined('LSCWP_V') || has_action('litespeed_purge_all')),
            self::plugin_adapter('wp-rocket', 'wp-rocket/wp-rocket.php', defined('WP_ROCKET_VERSION') || function_exists('rocket_clean_domain')),
        ];
    }

    public static function seo_values(int $postId): array
    {
        $adapters = array_column(self::adapters(), null, 'id');
        if (($adapters['rank-math']['state'] ?? null) === 'SUPPORTED_ENABLED') {
            return [
                'provider' => 'rank-math',
                'seo_title' => (string) get_post_meta($postId, 'rank_math_title', true),
                'seo_description' => (string) get_post_meta($postId, 'rank_math_description', true),
            ];
        }
        if (($adapters['yoast-seo']['state'] ?? null) === 'SUPPORTED_ENABLED') {
            return [
                'provider' => 'yoast-seo',
                'seo_title' => (string) get_post_meta($postId, '_yoast_wpseo_title', true),
                'seo_description' => (string) get_post_meta($postId, '_yoast_wpseo_metadesc', true),
            ];
        }

        return ['provider' => null, 'seo_title' => '', 'seo_description' => ''];
    }

    public static function write_seo(int $postId, array $changes): array|WP_Error
    {
        $adapters = array_column(self::adapters(), null, 'id');
        if (($adapters['rank-math']['state'] ?? null) === 'SUPPORTED_ENABLED') {
            if (array_key_exists('seo_title', $changes)) {
                update_post_meta($postId, 'rank_math_title', sanitize_text_field((string) $changes['seo_title']));
            }
            if (array_key_exists('seo_description', $changes)) {
                update_post_meta($postId, 'rank_math_description', sanitize_textarea_field((string) $changes['seo_description']));
            }

            return ['provider' => 'rank-math'];
        }
        if (($adapters['yoast-seo']['state'] ?? null) === 'SUPPORTED_ENABLED') {
            if (array_key_exists('seo_title', $changes)) {
                update_post_meta($postId, '_yoast_wpseo_title', sanitize_text_field((string) $changes['seo_title']));
            }
            if (array_key_exists('seo_description', $changes)) {
                update_post_meta($postId, '_yoast_wpseo_metadesc', sanitize_textarea_field((string) $changes['seo_description']));
            }

            return ['provider' => 'yoast-seo'];
        }

        return new WP_Error('seo_provider_unsupported', 'No supported enabled SEO provider is available for semantic SEO writes.', ['status' => 409]);
    }

    public static function site_health(): array
    {
        $theme = wp_get_theme();
        $plugins = self::plugins();
        $cron = self::cron_inventory();
        $restState = 'SUPPORTED_ENABLED';
        $restCode = null;
        $rest = wp_remote_get(rest_url(), ['timeout' => 5, 'redirection' => 1]);
        if (is_wp_error($rest)) {
            $restState = 'TEMPORARILY_UNAVAILABLE';
            $restCode = $rest->get_error_code();
        } elseif (wp_remote_retrieve_response_code($rest) >= 400) {
            $restState = 'TEMPORARILY_UNAVAILABLE';
            $restCode = wp_remote_retrieve_response_code($rest);
        }

        return [
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'memory_usage_bytes' => memory_get_usage(true),
            'disk' => self::disk_info(),
            'rest' => ['state' => $restState, 'code' => $restCode, 'url' => rest_url()],
            'database' => self::database_health(),
            'active_theme' => ['name' => $theme->get('Name'), 'stylesheet' => $theme->get_stylesheet(), 'version' => $theme->get('Version')],
            'plugins' => ['total' => count($plugins), 'active' => count(array_filter($plugins, static fn (array $item): bool => (bool) $item['active']))],
            'cron' => ['events' => count($cron), 'due' => count(array_filter($cron, static fn (array $item): bool => (bool) $item['due']))],
            'adapters' => self::adapters(),
        ];
    }

    public static function plugins(): array
    {
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        wp_update_plugins();
        $updates = get_site_transient('update_plugins');
        $items = [];
        foreach (get_plugins() as $file => $data) {
            $items[] = [
                'plugin' => $file,
                'name' => (string) ($data['Name'] ?? $file),
                'version' => (string) ($data['Version'] ?? ''),
                'active' => is_plugin_active($file),
                'network_active' => is_multisite() && is_plugin_active_for_network($file),
                'update_available' => isset($updates->response[$file]),
            ];
        }

        return $items;
    }

    public static function themes(): array
    {
        wp_update_themes();
        $updates = get_site_transient('update_themes');
        $active = get_stylesheet();
        $items = [];
        foreach (wp_get_themes() as $stylesheet => $theme) {
            $items[] = [
                'stylesheet' => $stylesheet,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'active' => $stylesheet === $active,
                'update_available' => isset($updates->response[$stylesheet]),
            ];
        }

        return $items;
    }

    public static function cron_inventory(): array
    {
        $cron = _get_cron_array();
        $items = [];
        $now = time();
        foreach ($cron ?: [] as $timestamp => $hooks) {
            foreach ($hooks as $hook => $instances) {
                foreach ($instances as $key => $event) {
                    $items[] = [
                        'event_id' => hash('sha256', $timestamp.'|'.$hook.'|'.$key),
                        'hook' => $hook,
                        'timestamp' => (int) $timestamp,
                        'scheduled_at' => gmdate(DATE_ATOM, (int) $timestamp),
                        'schedule' => $event['schedule'] ?? null,
                        'due' => (int) $timestamp <= $now,
                    ];
                }
            }
        }
        usort($items, static fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);

        return array_slice($items, 0, 500);
    }

    public static function database_health(): array
    {
        global $wpdb;
        $connected = $wpdb->check_connection(false);
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix).'%')) ?: [];

        return [
            'connected' => (bool) $connected,
            'database_name' => DB_NAME,
            'table_prefix_hash' => hash('sha256', $wpdb->prefix),
            'table_count' => count($tables),
            'last_error_present' => $wpdb->last_error !== '',
        ];
    }

    private static function plugin_install(array $arguments): array|WP_Error
    {
        $slug = AIMW_Connector_Security::assert_slug((string) ($arguments['slug'] ?? ''));
        require_once ABSPATH.'wp-admin/includes/plugin-install.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        foreach (get_plugins() as $file => $data) {
            if (str_starts_with($file, $slug.'/') || $file === $slug.'.php') {
                return new WP_Error('already_installed', 'Plugin is already installed.', ['status' => 409]);
            }
        }
        $api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
        if (is_wp_error($api) || empty($api->download_link)) {
            return new WP_Error('plugin_unavailable', 'WordPress.org plugin package is unavailable.', ['status' => 502]);
        }
        $before = self::plugins();
        $result = (new Plugin_Upgrader(new Automatic_Upgrader_Skin))->install($api->download_link);
        if (is_wp_error($result) || ! $result) {
            return is_wp_error($result) ? $result : new WP_Error('install_failed', 'Plugin installation failed.', ['status' => 500]);
        }

        return ['status' => 'succeeded', 'before' => $before, 'after' => self::plugins(), 'result' => ['slug' => $slug]];
    }

    private static function plugin_activate(array $arguments): array|WP_Error
    {
        $plugin = self::require_plugin_file((string) ($arguments['plugin'] ?? ''));
        if (is_wp_error($plugin)) {
            return $plugin;
        }
        $before = self::plugins();
        $result = activate_plugin($plugin, '', false, true);
        if (is_wp_error($result)) {
            return $result;
        }

        return ['status' => 'succeeded', 'before' => $before, 'after' => self::plugins(), 'result' => ['plugin' => $plugin, 'active' => true]];
    }

    private static function plugin_deactivate(array $arguments): array|WP_Error
    {
        $plugin = self::require_plugin_file((string) ($arguments['plugin'] ?? ''));
        if (is_wp_error($plugin)) {
            return $plugin;
        }
        $before = self::plugins();
        deactivate_plugins($plugin, true, false);

        return ['status' => 'succeeded', 'before' => $before, 'after' => self::plugins(), 'result' => ['plugin' => $plugin, 'active' => false]];
    }

    private static function plugin_update(array $arguments): array|WP_Error
    {
        $plugin = self::require_plugin_file((string) ($arguments['plugin'] ?? ''));
        if (is_wp_error($plugin)) {
            return $plugin;
        }
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        wp_update_plugins();
        $updates = get_site_transient('update_plugins');
        if (! isset($updates->response[$plugin])) {
            return new WP_Error('no_update_available', 'No plugin update is currently available.', ['status' => 409]);
        }
        $before = self::plugins();
        $result = (new Plugin_Upgrader(new Automatic_Upgrader_Skin))->upgrade($plugin);
        if (is_wp_error($result) || ! $result) {
            return is_wp_error($result) ? $result : new WP_Error('update_failed', 'Plugin update failed.', ['status' => 500]);
        }

        return ['status' => 'succeeded', 'before' => $before, 'after' => self::plugins(), 'result' => ['plugin' => $plugin]];
    }

    private static function plugin_delete(array $arguments): array|WP_Error
    {
        $plugin = self::require_plugin_file((string) ($arguments['plugin'] ?? ''));
        if (is_wp_error($plugin)) {
            return $plugin;
        }
        if (is_plugin_active($plugin) || (is_multisite() && is_plugin_active_for_network($plugin))) {
            return new WP_Error('active_plugin_delete_denied', 'Active plugins must be deactivated before deletion.', ['status' => 409]);
        }
        $before = self::plugins();
        $result = delete_plugins([$plugin]);
        if (is_wp_error($result) || ! $result) {
            return is_wp_error($result) ? $result : new WP_Error('delete_failed', 'Plugin deletion failed.', ['status' => 500]);
        }

        return ['status' => 'succeeded', 'before' => $before, 'after' => self::plugins(), 'result' => ['plugin' => $plugin]];
    }

    private static function theme_install(array $arguments): array|WP_Error
    {
        $slug = AIMW_Connector_Security::assert_slug((string) ($arguments['slug'] ?? ''));
        require_once ABSPATH.'wp-admin/includes/theme-install.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        if (wp_get_theme($slug)->exists()) {
            return new WP_Error('already_installed', 'Theme is already installed.', ['status' => 409]);
        }
        $api = themes_api('theme_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
        if (is_wp_error($api) || empty($api->download_link)) {
            return new WP_Error('theme_unavailable', 'WordPress.org theme package is unavailable.', ['status' => 502]);
        }
        $before = self::themes();
        $result = (new Theme_Upgrader(new Automatic_Upgrader_Skin))->install($api->download_link);
        if (is_wp_error($result) || ! $result) {
            return is_wp_error($result) ? $result : new WP_Error('install_failed', 'Theme installation failed.', ['status' => 500]);
        }

        return ['status' => 'succeeded', 'before' => $before, 'after' => self::themes(), 'result' => ['slug' => $slug]];
    }

    private static function theme_activate(array $arguments): array|WP_Error
    {
        $stylesheet = sanitize_key((string) ($arguments['stylesheet'] ?? ''));
        $theme = wp_get_theme($stylesheet);
        if ($stylesheet === '' || ! $theme->exists()) {
            return new WP_Error('theme_not_found', 'Theme is not installed.', ['status' => 404]);
        }
        $before = self::themes();
        switch_theme($stylesheet);
        if (get_stylesheet() !== $stylesheet) {
            return new WP_Error('activation_failed', 'Theme activation failed.', ['status' => 500]);
        }

        return ['status' => 'succeeded', 'before' => $before, 'after' => self::themes(), 'result' => ['stylesheet' => $stylesheet, 'active' => true]];
    }

    private static function theme_update(array $arguments): array|WP_Error
    {
        $stylesheet = sanitize_key((string) ($arguments['stylesheet'] ?? ''));
        if ($stylesheet === '' || ! wp_get_theme($stylesheet)->exists()) {
            return new WP_Error('theme_not_found', 'Theme is not installed.', ['status' => 404]);
        }
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        wp_update_themes();
        $updates = get_site_transient('update_themes');
        if (! isset($updates->response[$stylesheet])) {
            return new WP_Error('no_update_available', 'No theme update is currently available.', ['status' => 409]);
        }
        $before = self::themes();
        $result = (new Theme_Upgrader(new Automatic_Upgrader_Skin))->upgrade($stylesheet);
        if (is_wp_error($result) || ! $result) {
            return is_wp_error($result) ? $result : new WP_Error('update_failed', 'Theme update failed.', ['status' => 500]);
        }

        return ['status' => 'succeeded', 'before' => $before, 'after' => self::themes(), 'result' => ['stylesheet' => $stylesheet]];
    }

    private static function theme_delete(array $arguments): array|WP_Error
    {
        $stylesheet = sanitize_key((string) ($arguments['stylesheet'] ?? ''));
        if ($stylesheet === '' || ! wp_get_theme($stylesheet)->exists()) {
            return new WP_Error('theme_not_found', 'Theme is not installed.', ['status' => 404]);
        }
        if ($stylesheet === get_stylesheet() || $stylesheet === get_template()) {
            return new WP_Error('active_theme_delete_denied', 'The active theme or its parent cannot be deleted.', ['status' => 409]);
        }
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/theme.php';
        $before = self::themes();
        $result = delete_theme($stylesheet);
        if (is_wp_error($result) || ! $result) {
            return is_wp_error($result) ? $result : new WP_Error('delete_failed', 'Theme deletion failed.', ['status' => 500]);
        }

        return ['status' => 'succeeded', 'before' => $before, 'after' => self::themes(), 'result' => ['stylesheet' => $stylesheet]];
    }

    private static function cache_purge(): array|WP_Error
    {
        $adapters = array_column(self::adapters(), null, 'id');
        if (($adapters['litespeed-cache']['state'] ?? null) === 'SUPPORTED_ENABLED') {
            if (has_action('litespeed_purge_all')) {
                do_action('litespeed_purge_all');

                return ['status' => 'succeeded', 'provider' => 'litespeed-cache'];
            }

            return new WP_Error('cache_provider_unavailable', 'LiteSpeed Cache is active but its purge adapter is unavailable.', ['status' => 503]);
        }
        if (($adapters['wp-rocket']['state'] ?? null) === 'SUPPORTED_ENABLED') {
            if (function_exists('rocket_clean_domain')) {
                rocket_clean_domain();

                return ['status' => 'succeeded', 'provider' => 'wp-rocket'];
            }

            return new WP_Error('cache_provider_unavailable', 'WP Rocket is active but its purge adapter is unavailable.', ['status' => 503]);
        }
        if (function_exists('wp_cache_flush')) {
            $flushed = wp_cache_flush();
            if ($flushed === false) {
                return new WP_Error('cache_purge_failed', 'WordPress object cache refused the purge.', ['status' => 503]);
            }

            return ['status' => 'succeeded', 'provider' => 'wordpress-core-object-cache'];
        }

        return new WP_Error('cache_provider_unsupported', 'No supported cache purge provider is available.', ['status' => 409]);
    }

    private static function cron_run_due(): array
    {
        $ran = [];
        foreach (self::cron_inventory() as $event) {
            if (! $event['due']) {
                continue;
            }
            $result = self::run_cron_event($event['event_id']);
            if (! is_wp_error($result)) {
                $ran[] = $result['result'];
            }
            if (count($ran) >= 20) {
                break;
            }
        }

        return ['status' => 'succeeded', 'result' => ['ran' => $ran]];
    }

    private static function cron_run(array $arguments): array|WP_Error
    {
        $eventId = sanitize_text_field((string) ($arguments['event_id'] ?? ''));
        if ($eventId === '') {
            return new WP_Error('event_required', 'A scheduled event_id is required.', ['status' => 422]);
        }

        return self::run_cron_event($eventId);
    }

    private static function run_cron_event(string $eventId): array|WP_Error
    {
        $cron = _get_cron_array();
        foreach ($cron ?: [] as $timestamp => $hooks) {
            foreach ($hooks as $hook => $instances) {
                foreach ($instances as $key => $event) {
                    if (! hash_equals(hash('sha256', $timestamp.'|'.$hook.'|'.$key), $eventId)) {
                        continue;
                    }
                    $args = array_values((array) ($event['args'] ?? []));
                    do_action_ref_array($hook, $args);
                    if (! empty($event['schedule'])) {
                        wp_reschedule_event((int) $timestamp, $event['schedule'], $hook, $args, true);
                    } else {
                        wp_unschedule_event((int) $timestamp, $hook, $args, true);
                    }

                    return ['status' => 'succeeded', 'result' => ['event_id' => $eventId, 'hook' => $hook]];
                }
            }
        }

        return new WP_Error('cron_event_not_found', 'Only existing scheduled cron events may be executed.', ['status' => 404]);
    }

    private static function backup_create(array $arguments): array|WP_Error
    {
        $level = strtoupper(sanitize_text_field((string) ($arguments['level'] ?? 'L1')));
        if (! in_array($level, ['L1', 'L2', 'L3'], true)) {
            return new WP_Error('invalid_backup_level', 'Backup level must be L1, L2, or L3.', ['status' => 422]);
        }
        $dir = self::backup_dir();
        if (is_wp_error($dir)) {
            return $dir;
        }
        $backupId = wp_generate_uuid4();
        $manifest = [
            'backup_id' => $backupId,
            'level' => $level,
            'created_at' => gmdate(DATE_ATOM),
            'wordpress_version' => get_bloginfo('version'),
            'site_url_hash' => hash('sha256', home_url('/')),
            'artifacts' => [],
            'complete' => true,
            'restore_ready' => $level !== 'L3',
        ];
        if ($level === 'L1') {
            $manifest['object_snapshot'] = [
                'active_theme' => get_stylesheet(),
                'plugins' => self::plugins(),
                'adapters' => self::adapters(),
                'cron' => self::cron_inventory(),
                'database' => self::database_health(),
            ];
        } elseif ($level === 'L2') {
            $components = array_values((array) ($arguments['components'] ?? []));
            if ($components === []) {
                return new WP_Error('components_required', 'L2 backup requires explicit approved components.', ['status' => 422]);
            }
            foreach ($components as $component) {
                $artifact = self::archive_component((array) $component, $dir, $backupId);
                if (is_wp_error($artifact)) {
                    return $artifact;
                }
                $manifest['artifacts'][] = $artifact;
            }
        } else {
            $manifest['complete'] = false;
            $manifest['restore_ready'] = false;
            $manifest['foundation'] = [
                'plugin_inventory' => self::plugins(),
                'theme_inventory' => self::themes(),
                'uploads' => self::filesystem_root_summary('uploads'),
                'database' => self::database_health(),
                'database_export' => ['state' => 'TEMPORARILY_UNAVAILABLE', 'reason' => 'No approved host database-export adapter is integrated.'],
                'full_restore' => ['state' => 'TEMPORARILY_UNAVAILABLE', 'reason' => 'No approved host restore adapter is integrated.'],
            ];
        }
        $manifestPath = trailingslashit($dir).$backupId.'.manifest.json';
        $encoded = wp_json_encode(AIMW_Connector_Security::redact($manifest), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || file_put_contents($manifestPath, $encoded, LOCK_EX) === false) {
            return new WP_Error('backup_write_failed', 'Backup manifest could not be written.', ['status' => 500]);
        }
        $manifest['manifest_sha256'] = hash_file('sha256', $manifestPath);
        $manifest['verified'] = self::verify_manifest($manifest, $dir);

        return ['status' => $level === 'L3' ? 'foundation_ready' : 'succeeded', 'backup' => $manifest];
    }

    private static function backup_list(): array
    {
        $dir = self::backup_dir();
        if (is_wp_error($dir)) {
            return [];
        }
        $items = [];
        foreach (glob(trailingslashit($dir).'*.manifest.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $items[] = [
                    'backup_id' => $data['backup_id'] ?? basename($file, '.manifest.json'),
                    'level' => $data['level'] ?? null,
                    'created_at' => $data['created_at'] ?? null,
                    'complete' => (bool) ($data['complete'] ?? false),
                    'restore_ready' => (bool) ($data['restore_ready'] ?? false),
                    'manifest_sha256' => hash_file('sha256', $file),
                ];
            }
        }

        return array_slice(array_reverse($items), 0, 100);
    }

    private static function backup_inspect(array $arguments): array|WP_Error
    {
        $id = sanitize_text_field((string) ($arguments['backup_id'] ?? ''));
        if (! preg_match('/^[a-f0-9-]{20,50}$/i', $id)) {
            return new WP_Error('invalid_backup_id', 'Invalid backup identifier.', ['status' => 422]);
        }
        $dir = self::backup_dir();
        if (is_wp_error($dir)) {
            return $dir;
        }
        $file = trailingslashit($dir).$id.'.manifest.json';
        if (! is_file($file)) {
            return new WP_Error('backup_not_found', 'Backup manifest not found.', ['status' => 404]);
        }
        $manifest = json_decode((string) file_get_contents($file), true);
        if (! is_array($manifest)) {
            return new WP_Error('manifest_invalid', 'Backup manifest is invalid.', ['status' => 500]);
        }
        $manifest['manifest_sha256'] = hash_file('sha256', $file);
        $manifest['verified'] = self::verify_manifest($manifest, $dir);

        return ['status' => 'succeeded', 'backup' => AIMW_Connector_Security::redact($manifest)];
    }

    private static function filesystem_inspect(array $arguments): array|WP_Error
    {
        $root = sanitize_key((string) ($arguments['root'] ?? ''));
        $relative = AIMW_Connector_Security::normalize_relative_path((string) ($arguments['path'] ?? ''));
        if ($root === 'uploads' && ($relative === 'aimw-connector-backups' || str_starts_with($relative, 'aimw-connector-backups/'))) {
            return new WP_Error('protected_path', 'Connector backup storage is not exposed through filesystem inspection.', ['status' => 403]);
        }
        $base = self::approved_root($root);
        if (is_wp_error($base)) {
            return $base;
        }
        $baseReal = realpath($base);
        if ($baseReal === false) {
            return new WP_Error('root_unavailable', 'Approved filesystem root is unavailable.', ['status' => 503]);
        }
        $target = $relative === '' ? $baseReal : realpath($baseReal.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($target === false || ! self::inside($target, $baseReal)) {
            return new WP_Error('path_not_found', 'Requested approved path does not exist.', ['status' => 404]);
        }
        if (is_file($target)) {
            return ['status' => 'succeeded', 'item' => ['path' => $relative, 'type' => 'file', 'size' => filesize($target), 'sha256' => hash_file('sha256', $target), 'modified_at' => gmdate(DATE_ATOM, filemtime($target))]];
        }
        $items = [];
        foreach (array_slice(scandir($target) ?: [], 0, 250) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $target.DIRECTORY_SEPARATOR.$name;
            $items[] = [
                'name' => $name,
                'type' => is_dir($child) ? 'directory' : 'file',
                'size' => is_file($child) ? filesize($child) : null,
                'modified_at' => @filemtime($child) ? gmdate(DATE_ATOM, filemtime($child)) : null,
            ];
        }

        return ['status' => 'succeeded', 'root' => $root, 'path' => $relative, 'items' => $items];
    }

    private static function database_optimize(array $arguments): array|WP_Error
    {
        global $wpdb;
        $allowed = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix).'%')) ?: [];
        $requested = array_values((array) ($arguments['tables'] ?? []));
        $targets = $requested === [] ? array_slice($allowed, 0, 20) : $requested;
        if (count($targets) > 20) {
            return new WP_Error('too_many_tables', 'At most 20 WordPress tables may be optimized per operation.', ['status' => 422]);
        }
        foreach ($targets as $table) {
            if (! is_string($table) || ! in_array($table, $allowed, true)) {
                return new WP_Error('unsafe_table', 'Only existing WordPress-prefixed tables may be optimized.', ['status' => 422]);
            }
        }
        $results = [];
        foreach ($targets as $table) {
            $rows = $wpdb->get_results('OPTIMIZE TABLE `'.str_replace('`', '``', $table).'`', ARRAY_A);
            $results[] = ['table_hash' => hash('sha256', $table), 'messages' => array_map(static fn (array $row): array => ['type' => $row['Msg_type'] ?? null, 'text' => $row['Msg_text'] ?? null], $rows ?: [])];
        }

        return ['status' => 'succeeded', 'result' => ['optimized' => $results]];
    }

    private static function require_owner_for_operation(string $operation): void
    {
        $capability = self::wp_capability_for_operation($operation);
        if ($capability === null) {
            return;
        }
        $config = get_option('aimw_connector', []);
        $owner = (int) ($config['owner_user_id'] ?? 0);
        if ($owner <= 0 || ! get_user_by('id', $owner)) {
            throw new InvalidArgumentException('Connector owner identity is unavailable; re-pairing is required.');
        }
        if (! user_can($owner, $capability)) {
            throw new InvalidArgumentException('Paired owner lacks required WordPress capability: '.$capability.'.');
        }
    }

    private static function wp_capability_for_operation(string $operation): ?string
    {
        return match ($operation) {
            'plugins.list' => 'activate_plugins',
            'plugin.install' => 'install_plugins',
            'plugin.activate', 'plugin.deactivate' => 'activate_plugins',
            'plugin.update' => 'update_plugins',
            'plugin.delete' => 'delete_plugins',
            'themes.list' => 'switch_themes',
            'theme.install' => 'install_themes',
            'theme.activate' => 'switch_themes',
            'theme.update' => 'update_themes',
            'theme.delete' => 'delete_themes',
            'adapters.list', 'cache.purge', 'cron.list', 'cron.inspect', 'cron.run_due', 'cron.run', 'site.health',
            'backup.list', 'backup.inspect', 'backup.create', 'backup.restore', 'filesystem.inspect', 'database.health', 'database.optimize' => 'manage_options',
            default => null,
        };
    }

    private static function wp_capability_for_scope(string $scope): ?string
    {
        return match ($scope) {
            'plugins.read' => 'activate_plugins',
            'plugins.manage' => 'install_plugins',
            'themes.read' => 'switch_themes',
            'themes.manage' => 'install_themes',
            'cache.manage', 'cron.read', 'cron.manage', 'diagnostics.read', 'backup.read', 'backup.create', 'backup.restore', 'filesystem.read', 'database.read', 'database.manage', 'adapters.read' => 'manage_options',
            default => null,
        };
    }

    private static function require_plugin_file(string $plugin): string|WP_Error
    {
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        $plugin = plugin_basename(sanitize_text_field($plugin));
        if ($plugin === '' || ! array_key_exists($plugin, get_plugins())) {
            return new WP_Error('plugin_not_found', 'Plugin is not installed.', ['status' => 404]);
        }

        return $plugin;
    }

    private static function plugin_adapter(string $id, string $file, bool $probe): array
    {
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        $installed = array_key_exists($file, get_plugins());
        if (! $installed) {
            return self::adapter($id, false, false, null);
        }
        if (! is_plugin_active($file)) {
            return ['id' => $id, 'state' => 'SUPPORTED_DISABLED', 'version' => get_plugins()[$file]['Version'] ?? null];
        }
        if (! $probe) {
            return ['id' => $id, 'state' => 'TEMPORARILY_UNAVAILABLE', 'version' => get_plugins()[$file]['Version'] ?? null];
        }

        return ['id' => $id, 'state' => 'SUPPORTED_ENABLED', 'version' => get_plugins()[$file]['Version'] ?? null];
    }

    private static function adapter(string $id, bool $installed, bool $enabled, ?string $version): array
    {
        return ['id' => $id, 'state' => ! $installed ? 'UNSUPPORTED' : ($enabled ? 'SUPPORTED_ENABLED' : 'SUPPORTED_DISABLED'), 'version' => $version];
    }

    private static function disk_info(): array
    {
        $free = @disk_free_space(ABSPATH);
        $total = @disk_total_space(ABSPATH);

        return [
            'state' => ($free === false || $total === false) ? 'TEMPORARILY_UNAVAILABLE' : 'SUPPORTED_ENABLED',
            'free_bytes' => $free === false ? null : (int) $free,
            'total_bytes' => $total === false ? null : (int) $total,
        ];
    }

    private static function backup_dir(): string|WP_Error
    {
        $uploads = wp_upload_dir(null, false);
        if (! empty($uploads['error'])) {
            return new WP_Error('uploads_unavailable', 'WordPress uploads directory is unavailable.', ['status' => 503]);
        }
        $dir = trailingslashit($uploads['basedir']).'aimw-connector-backups';
        if (! wp_mkdir_p($dir) || ! is_writable($dir)) {
            return new WP_Error('backup_storage_unavailable', 'Connector backup storage is not writable.', ['status' => 503]);
        }
        if (! is_file($dir.'/index.php')) {
            file_put_contents($dir.'/index.php', "<?php\n// Silence is golden.\n", LOCK_EX);
        }
        if (! is_file($dir.'/.htaccess')) {
            file_put_contents($dir.'/.htaccess', "Require all denied\n", LOCK_EX);
        }

        return $dir;
    }

    private static function archive_component(array $component, string $dir, string $backupId): array|WP_Error
    {
        $type = sanitize_key((string) ($component['type'] ?? ''));
        $slug = sanitize_text_field((string) ($component['slug'] ?? ''));
        if ($type === 'plugin') {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
            $plugin = self::require_plugin_file($slug);
            if (is_wp_error($plugin)) {
                return $plugin;
            }
            $base = realpath(WP_PLUGIN_DIR);
            $source = realpath(WP_PLUGIN_DIR.'/'.dirname($plugin));
            if ($source === false || $base === false || ! self::inside($source, $base)) {
                return new WP_Error('component_path_invalid', 'Plugin component path is invalid.', ['status' => 422]);
            }
            $name = sanitize_file_name(str_replace('/', '-', dirname($plugin) === '.' ? basename($plugin, '.php') : dirname($plugin)));
        } elseif ($type === 'theme') {
            $stylesheet = sanitize_key($slug);
            $theme = wp_get_theme($stylesheet);
            if (! $theme->exists()) {
                return new WP_Error('theme_not_found', 'Theme component is not installed.', ['status' => 404]);
            }
            $base = realpath(get_theme_root());
            $source = realpath($theme->get_stylesheet_directory());
            if ($source === false || $base === false || ! self::inside($source, $base)) {
                return new WP_Error('component_path_invalid', 'Theme component path is invalid.', ['status' => 422]);
            }
            $name = sanitize_file_name($stylesheet);
        } else {
            return new WP_Error('unsupported_component', 'Only plugin and theme components may be archived.', ['status' => 422]);
        }
        require_once ABSPATH.'wp-admin/includes/class-pclzip.php';
        $filename = $backupId.'-'.$type.'-'.$name.'.zip';
        $path = trailingslashit($dir).$filename;
        $archive = new PclZip($path);
        $result = $archive->create($source, PCLZIP_OPT_REMOVE_PATH, dirname($source));
        if ($result === 0 || ! is_file($path)) {
            return new WP_Error('archive_failed', 'Component archive could not be created.', ['status' => 500]);
        }

        return ['name' => $filename, 'type' => $type, 'slug' => $slug, 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
    }

    private static function verify_manifest(array $manifest, string $dir): bool
    {
        foreach ((array) ($manifest['artifacts'] ?? []) as $artifact) {
            $name = basename((string) ($artifact['name'] ?? ''));
            $file = trailingslashit($dir).$name;
            if (! is_file($file) || ! hash_equals((string) ($artifact['sha256'] ?? ''), hash_file('sha256', $file))) {
                return false;
            }
        }

        return true;
    }

    private static function approved_root(string $root): string|WP_Error
    {
        if ($root === 'plugins') {
            return WP_PLUGIN_DIR;
        }
        if ($root === 'themes') {
            return get_theme_root();
        }
        if ($root === 'uploads') {
            $uploads = wp_upload_dir(null, false);

            return empty($uploads['error']) ? $uploads['basedir'] : new WP_Error('uploads_unavailable', 'Uploads root is unavailable.', ['status' => 503]);
        }

        return new WP_Error('unsupported_root', 'Filesystem root must be plugins, themes, or uploads.', ['status' => 422]);
    }

    private static function filesystem_root_summary(string $root): array
    {
        $base = self::approved_root($root);
        if (is_wp_error($base) || ! is_dir($base)) {
            return ['state' => 'TEMPORARILY_UNAVAILABLE'];
        }
        $count = 0;
        $bytes = 0;
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $count++;
                    $bytes += $file->getSize();
                    if ($count >= 10000) {
                        break;
                    }
                }
            }
        } catch (Throwable) {
            return ['state' => 'TEMPORARILY_UNAVAILABLE'];
        }

        return ['state' => 'SUPPORTED_ENABLED', 'files_counted' => $count, 'bytes_counted' => $bytes, 'bounded' => $count >= 10000];
    }

    private static function inside(string $target, string $base): bool
    {
        $target = rtrim(str_replace('\\', '/', $target), '/');
        $base = rtrim(str_replace('\\', '/', $base), '/');

        return $target === $base || str_starts_with($target.'/', $base.'/');
    }
}
