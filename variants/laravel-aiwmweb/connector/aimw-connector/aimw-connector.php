<?php
/**
 * Plugin Name: AIMW Connector
 * Description: Signed, scoped semantic operations for Laravel AIWMWeb.
 * Version: 0.2.0
 * Requires PHP: 8.0
 */

defined('ABSPATH') || exit;

require_once __DIR__.'/includes/class-aimw-connector-security.php';
require_once __DIR__.'/includes/class-aimw-connector-store.php';
require_once __DIR__.'/includes/class-aimw-connector-runtime.php';
require_once __DIR__.'/includes/class-aimw-connector-admin.php';

final class AIMW_Connector_V1
{
    public const PLUGIN_VERSION = '0.2.0';
    public const PROTOCOL_VERSION = '1';
    public const CLOCK_SKEW_SECONDS = 300;
    private const NS = 'aimw/v1';

    public static function boot(): void
    {
        add_action('plugins_loaded', [self::class, 'maybe_upgrade']);
        add_action('rest_api_init', [self::class, 'routes']);
        AIMW_Connector_Admin::boot();
    }

    public static function activate(): void
    {
        AIMW_Connector_Store::migrate();
        $config = get_option('aimw_connector', []);
        $config['local_enabled'] = true;
        update_option('aimw_connector', $config, false);
        AIMW_Connector_Store::record('plugin_activated', 'succeeded', [], ['version' => self::PLUGIN_VERSION], null, null, get_current_user_id() ?: null);
    }

    public static function deactivate(): void
    {
        $config = get_option('aimw_connector', []);
        $config['local_enabled'] = false;
        update_option('aimw_connector', $config, false);
        AIMW_Connector_Store::record('plugin_deactivated', 'succeeded', [], ['version' => self::PLUGIN_VERSION], null, null, get_current_user_id() ?: null);
    }

    public static function maybe_upgrade(): void
    {
        AIMW_Connector_Store::maybe_migrate();
        $config = get_option('aimw_connector', []);
        if (! array_key_exists('local_enabled', $config)) {
            $config['local_enabled'] = true;
            update_option('aimw_connector', $config, false);
        }
    }

    public static function routes(): void
    {
        register_rest_route(self::NS, '/pair', ['methods' => 'POST', 'callback' => [self::class, 'pair'], 'permission_callback' => static fn (): bool => current_user_can('manage_options')]);
        register_rest_route(self::NS, '/health', ['methods' => 'GET', 'callback' => [self::class, 'health'], 'permission_callback' => [self::class, 'authorize']]);
        register_rest_route(self::NS, '/capabilities', ['methods' => 'GET', 'callback' => [self::class, 'capabilities'], 'permission_callback' => [self::class, 'authorize']]);
        register_rest_route(self::NS, '/history', ['methods' => 'GET', 'callback' => [self::class, 'history'], 'permission_callback' => [self::class, 'authorize']]);
        register_rest_route(self::NS, '/content', ['methods' => 'GET', 'callback' => [self::class, 'content'], 'permission_callback' => [self::class, 'authorize']]);
        register_rest_route(self::NS, '/content/(?P<type>post|page)/(?P<id>\d+)', ['methods' => 'GET', 'callback' => [self::class, 'read'], 'permission_callback' => [self::class, 'authorize']]);
        register_rest_route(self::NS, '/execute', ['methods' => 'POST', 'callback' => [self::class, 'execute'], 'permission_callback' => [self::class, 'authorize']]);
        register_rest_route(self::NS, '/operate', ['methods' => 'POST', 'callback' => [self::class, 'operate'], 'permission_callback' => [self::class, 'authorize']]);
        register_rest_route(self::NS, '/rotate', ['methods' => 'POST', 'callback' => [self::class, 'rotate'], 'permission_callback' => [self::class, 'authorize']]);
        register_rest_route(self::NS, '/disconnect', ['methods' => 'POST', 'callback' => [self::class, 'disconnect'], 'permission_callback' => [self::class, 'authorize']]);
    }

    public static function pair(WP_REST_Request $request): array|WP_Error
    {
        $platform = rtrim(esc_url_raw((string) $request['platform_url']), '/');
        $token = sanitize_text_field((string) $request['pairing_token']);
        if (! $platform || ! $token) {
            return new WP_Error('invalid_pairing', 'Platform URL and pairing token are required.', ['status' => 422]);
        }
        $identity = wp_generate_uuid4();
        $response = wp_remote_post($platform.'/api/connector/pair', [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'token' => $token,
                'identity' => $identity,
                'protocol_version' => self::PROTOCOL_VERSION,
                'capabilities' => AIMW_Connector_Security::CAPABILITIES,
            ]),
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 201) {
            return new WP_Error('pairing_failed', 'Laravel pairing was rejected.', ['status' => 502]);
        }
        $payload = json_decode(wp_remote_retrieve_body($response), true);
        if (! is_array($payload) || empty($payload['secret']) || empty($payload['tenant_id']) || empty($payload['site_id'])) {
            return new WP_Error('pairing_failed', 'Pairing response did not include the required identity binding.', ['status' => 502]);
        }
        $enabled = array_values(array_intersect(AIMW_Connector_Security::SAFE_DEFAULT_SCOPES, (array) ($payload['enabled_scopes'] ?? []), AIMW_Connector_Security::CAPABILITIES));
        update_option('aimw_connector', [
            'platform_url' => $platform,
            'identity' => $identity,
            'secret' => (string) $payload['secret'],
            'protocol_version' => self::PROTOCOL_VERSION,
            'tenant_id' => (string) $payload['tenant_id'],
            'site_id' => (string) $payload['site_id'],
            'enabled_scopes' => $enabled,
            'revoked' => false,
            'local_enabled' => true,
            'owner_user_id' => get_current_user_id(),
            'paired_at' => gmdate(DATE_ATOM),
        ], false);
        AIMW_Connector_Store::record('paired', 'succeeded', [], ['identity' => $identity, 'enabled_scopes' => $enabled], null, null, get_current_user_id());

        return [
            'paired' => true,
            'identity' => $identity,
            'protocol_version' => self::PROTOCOL_VERSION,
            'capabilities' => AIMW_Connector_Security::CAPABILITIES,
            'enabled_scopes' => $enabled,
            'connection' => self::connection_state(),
        ];
    }

    public static function authorize(WP_REST_Request $request): true|WP_Error
    {
        $config = get_option('aimw_connector', []);
        if (empty($config['secret']) || ! empty($config['revoked']) || array_key_exists('local_enabled', $config) && ! $config['local_enabled']) {
            return new WP_Error('connector_inactive', 'Connector is not active.', ['status' => 401]);
        }
        if (empty($config['tenant_id']) || empty($config['site_id'])) {
            return new WP_Error('connector_repair_required', 'Connector identity binding is incomplete; re-pairing is required.', ['status' => 409]);
        }
        $headers = array_change_key_case($request->get_headers(), CASE_LOWER);
        $one = static fn (string $name): string => is_array($headers[$name] ?? null) ? (string) ($headers[$name][0] ?? '') : (string) ($headers[$name] ?? '');
        $values = [
            'version' => $one('x-aimw-version'),
            'tenant' => $one('x-aimw-tenant'),
            'site' => $one('x-aimw-site'),
            'connector' => $one('x-aimw-connector'),
            'timestamp' => $one('x-aimw-timestamp'),
            'nonce' => $one('x-aimw-nonce'),
            'request' => $one('x-aimw-request-id'),
            'correlation' => $one('x-aimw-correlation-id'),
            'operation' => $one('x-aimw-operation-id'),
            'scope' => $one('x-aimw-scope'),
            'signature' => $one('x-aimw-signature'),
        ];
        if (in_array('', array_values($values), true)
            || $values['version'] !== self::PROTOCOL_VERSION
            || $values['connector'] !== (string) ($config['identity'] ?? '')
            || $values['tenant'] !== (string) $config['tenant_id']
            || $values['site'] !== (string) $config['site_id']) {
            return new WP_Error('invalid_protocol', 'Protocol identity/version mismatch.', ['status' => 401]);
        }
        if (abs(time() - (int) $values['timestamp']) > self::CLOCK_SKEW_SECONDS) {
            return new WP_Error('expired_request', 'Request timestamp expired.', ['status' => 401]);
        }
        $required = self::required_scopes($request);
        if (is_wp_error($required)) {
            return $required;
        }
        $primary = end($required);
        if ($values['scope'] !== $primary) {
            return new WP_Error('scope_mismatch', 'Signed scope does not match the operation-required scope.', ['status' => 403]);
        }
        foreach ($required as $scope) {
            if (! in_array($scope, (array) ($config['enabled_scopes'] ?? []), true)) {
                return new WP_Error('scope_disabled', 'Required connector scope is disabled: '.$scope.'.', ['status' => 403]);
            }
        }
        if (get_transient('aimw_nonce_'.hash('sha256', $values['nonce']))) {
            return new WP_Error('replay', 'Nonce already used.', ['status' => 409]);
        }
        $path = '/wp-json'.$request->get_route();
        $query = $request->get_query_params();
        if ($query) {
            $path .= '?'.http_build_query($query);
        }
        $canonical = implode("\n", [
            strtoupper($request->get_method()),
            $path,
            hash('sha256', $request->get_body()),
            $values['version'],
            $values['tenant'],
            $values['site'],
            $values['connector'],
            $values['timestamp'],
            $values['nonce'],
            $values['request'],
            $values['correlation'],
            $values['operation'],
            $values['scope'],
        ]);
        if (! hash_equals(hash_hmac('sha256', $canonical, (string) $config['secret']), $values['signature'])) {
            return new WP_Error('invalid_signature', 'Invalid request signature.', ['status' => 401]);
        }
        set_transient('aimw_nonce_'.hash('sha256', $values['nonce']), 1, self::CLOCK_SKEW_SECONDS);
        $request->set_attribute('aimw_protocol', $values);

        return true;
    }

    public static function health(): array
    {
        return [
            'status' => 'healthy',
            'protocol_version' => self::PROTOCOL_VERSION,
            'plugin_version' => self::PLUGIN_VERSION,
            'connection' => self::connection_state(),
            'capabilities' => AIMW_Connector_Runtime::capabilities(),
            'wordpress' => get_bloginfo('version'),
            'php' => PHP_VERSION,
        ];
    }

    public static function capabilities(): array
    {
        return [
            'protocol_version' => self::PROTOCOL_VERSION,
            'plugin_version' => self::PLUGIN_VERSION,
            'connection' => self::connection_state(),
            'runtime' => AIMW_Connector_Runtime::capabilities(),
        ];
    }

    public static function history(WP_REST_Request $request): array
    {
        return ['items' => AIMW_Connector_Store::history((int) ($request['limit'] ?: 100))];
    }

    public static function content(WP_REST_Request $request): array
    {
        $args = ['post_type' => ['post', 'page'], 'post_status' => ['publish', 'draft', 'private'], 'posts_per_page' => 100, 'orderby' => 'modified', 'order' => 'ASC'];
        if ($request['modified_after']) {
            $args['date_query'] = [['column' => 'post_modified_gmt', 'after' => sanitize_text_field((string) $request['modified_after'])]];
        }

        return ['items' => array_map([self::class, 'serialize'], get_posts($args))];
    }

    public static function read(WP_REST_Request $request): array|WP_Error
    {
        $post = get_post((int) $request['id']);
        if (! $post || $post->post_type !== $request['type']) {
            return new WP_Error('not_found', 'Content not found.', ['status' => 404]);
        }

        return self::serialize($post);
    }

    public static function execute(WP_REST_Request $request): array|WP_Error
    {
        $protocol = (array) $request->get_attribute('aimw_protocol');
        $operation = (string) $protocol['operation'];
        $cacheKey = 'aimw_operation_'.hash('sha256', $operation);
        $prior = get_transient($cacheKey);
        if (is_array($prior)) {
            return $prior;
        }
        $payload = $request->get_json_params();
        $post = get_post((int) ($payload['remote_id'] ?? 0));
        if (! $post || ! in_array($post->post_type, ['post', 'page'], true) || $post->post_type !== ($payload['resource_type'] ?? '')) {
            return new WP_Error('not_found', 'Content not found.', ['status' => 404]);
        }
        $changes = array_intersect_key((array) ($payload['changes'] ?? []), array_flip(['title', 'content', 'slug', 'seo_title', 'seo_description', 'seo_canonical', 'seo_robots']));
        $before = self::serialize($post);
        $update = ['ID' => $post->ID];
        if (isset($changes['title'])) {
            $update['post_title'] = wp_kses_post((string) $changes['title']);
        }
        if (isset($changes['content'])) {
            $update['post_content'] = wp_kses_post((string) $changes['content']);
        }
        if (isset($changes['slug'])) {
            $update['post_name'] = sanitize_title((string) $changes['slug']);
        }
        if (count($update) > 1) {
            $result = wp_update_post($update, true);
            if (is_wp_error($result)) {
                AIMW_Connector_Store::record('content_execute', 'failed', $protocol, ['error' => $result->get_error_code()], $before, null);

                return $result;
            }
        }
        if (array_intersect(['seo_title', 'seo_description', 'seo_canonical', 'seo_robots'], array_keys($changes))) {
            $seo = self::writeSeoMetadata($post->ID, $changes);
            if (is_wp_error($seo)) {
                AIMW_Connector_Store::record('content_execute', 'failed', $protocol, ['error' => $seo->get_error_code()], $before, null);

                return $seo;
            }
        }
        $after = self::serialize(get_post($post->ID));
        $response = ['operation_id' => $operation, 'before' => $before, 'after' => $after, 'status' => 'succeeded'];
        set_transient($cacheKey, $response, DAY_IN_SECONDS);
        AIMW_Connector_Store::record('content_execute', 'succeeded', $protocol, ['resource_type' => $post->post_type, 'remote_id' => $post->ID], $before, $after);

        return $response;
    }

    public static function operate(WP_REST_Request $request): array|WP_Error
    {
        $protocol = (array) $request->get_attribute('aimw_protocol');
        $payload = (array) $request->get_json_params();
        $operation = sanitize_text_field((string) ($payload['operation'] ?? ''));
        $arguments = (array) ($payload['arguments'] ?? []);
        if ($operation === '') {
            return new WP_Error('operation_required', 'Semantic operation is required.', ['status' => 422]);
        }
        $cacheKey = 'aimw_operation_'.hash('sha256', (string) $protocol['operation']);
        if (AIMW_Connector_Security::is_mutating_operation($operation)) {
            $prior = get_transient($cacheKey);
            if (is_array($prior)) {
                return $prior;
            }
        }
        $result = AIMW_Connector_Runtime::operate($operation, $arguments);
        if (is_wp_error($result)) {
            AIMW_Connector_Store::record($operation, 'failed', $protocol, ['error' => $result->get_error_code(), 'message' => $result->get_error_message()]);

            return $result;
        }
        $before = $result['before'] ?? null;
        $after = $result['after'] ?? null;
        $response = AIMW_Connector_Security::redact($result);
        $response['operation_id'] = (string) $protocol['operation'];
        $response['request_id'] = (string) $protocol['request'];
        $response['correlation_id'] = (string) $protocol['correlation'];
        if (AIMW_Connector_Security::is_mutating_operation($operation)) {
            set_transient($cacheKey, $response, DAY_IN_SECONDS);
        }
        AIMW_Connector_Store::record($operation, (string) ($response['status'] ?? 'succeeded'), $protocol, $response['result'] ?? $response, $before, $after);

        return $response;
    }

    public static function rotate(WP_REST_Request $request): array|WP_Error
    {
        $config = get_option('aimw_connector', []);
        $secret = (string) (($request->get_json_params()['new_secret'] ?? ''));
        if (strlen($secret) < 32) {
            return new WP_Error('invalid_secret', 'Replacement secret is invalid.', ['status' => 422]);
        }
        $config['secret'] = $secret;
        update_option('aimw_connector', $config, false);
        AIMW_Connector_Store::record('secret_rotated', 'succeeded', (array) $request->get_attribute('aimw_protocol'), ['rotated' => true]);

        return ['rotated' => true];
    }

    public static function disconnect(WP_REST_Request $request): array
    {
        self::emergency_disconnect(null, (array) $request->get_attribute('aimw_protocol'));

        return ['disconnected' => true];
    }

    public static function emergency_disconnect(?int $actorUserId = null, array $protocol = []): void
    {
        $config = get_option('aimw_connector', []);
        $config['revoked'] = true;
        $config['secret'] = '';
        $config['enabled_scopes'] = [];
        $config['disconnected_at'] = gmdate(DATE_ATOM);
        update_option('aimw_connector', $config, false);
        AIMW_Connector_Store::record('emergency_disconnect', 'succeeded', $protocol, ['revoked' => true], null, null, $actorUserId);
    }

    public static function connection_state(): array
    {
        $config = get_option('aimw_connector', []);
        $paired = ! empty($config['identity']) && ! empty($config['platform_url']);
        $bound = ! empty($config['tenant_id']) && ! empty($config['site_id']);
        $revoked = ! empty($config['revoked']);
        $enabled = ! array_key_exists('local_enabled', $config) || (bool) $config['local_enabled'];
        $protocol = (string) ($config['protocol_version'] ?? '');

        return [
            'connection' => ! $paired ? 'UNPAIRED' : ($revoked ? 'REVOKED' : ($enabled ? 'CONNECTED' : 'LOCALLY_DISABLED')),
            'protocol_state' => ! $paired ? 'UNPAIRED' : (! $bound ? 'REPAIR_REQUIRED' : ($protocol === self::PROTOCOL_VERSION ? 'SUPPORTED_ENABLED' : 'UNSUPPORTED')),
            'schema_version' => (string) get_option('aimw_connector_schema_version', ''),
            'owner_bound' => ! empty($config['owner_user_id']),
        ];
    }

    public static function serialize(WP_Post $post): array
    {
        preg_match_all('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $post->post_content, $matches);
        $media = get_attached_media('image', $post->ID);
        $seo = self::seoState($post->ID);

        return [
            'type' => $post->post_type,
            'id' => $post->ID,
            'slug' => $post->post_name,
            'title' => get_the_title($post),
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'headings' => array_map('wp_strip_all_tags', $matches[1] ?? []),
            'taxonomy' => ['categories' => wp_get_post_categories($post->ID), 'tags' => wp_get_post_tags($post->ID, ['fields' => 'ids'])],
            'media' => array_map(static fn (WP_Post $item): array => ['id' => $item->ID, 'url' => wp_get_attachment_url($item->ID)], array_values($media)),
            'seo_provider' => $seo['provider'],
            'seo_title' => $seo['seo_title'],
            'seo_description' => $seo['seo_description'],
            'seo_canonical' => $seo['seo_canonical'],
            'seo_robots' => $seo['seo_robots'],
            'modified_at' => get_post_modified_time(DATE_ATOM, true, $post),
        ];
    }

    private static function seoState(int $postId): array
    {
        $base = AIMW_Connector_Runtime::seo_values($postId);
        $provider = $base['provider'] ?? null;
        $canonical = '';
        $robots = [];

        if ($provider === 'rank-math') {
            $canonical = (string) get_post_meta($postId, 'rank_math_canonical_url', true);
            $robots = self::normalizeRobots(get_post_meta($postId, 'rank_math_robots', true), false);
        } elseif ($provider === 'yoast-seo') {
            $canonical = (string) get_post_meta($postId, '_yoast_wpseo_canonical', true);
            $noindex = (string) get_post_meta($postId, '_yoast_wpseo_meta-robots-noindex', true);
            $nofollow = (string) get_post_meta($postId, '_yoast_wpseo_meta-robots-nofollow', true);
            if ($noindex === '1') {
                $robots[] = 'noindex';
            } elseif ($noindex === '2') {
                $robots[] = 'index';
            }
            if ($nofollow === '1') {
                $robots[] = 'nofollow';
            } elseif ($nofollow === '0' && metadata_exists('post', $postId, '_yoast_wpseo_meta-robots-nofollow')) {
                $robots[] = 'follow';
            }
            $advanced = preg_split('/\s*,\s*/', (string) get_post_meta($postId, '_yoast_wpseo_meta-robots-adv', true), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $robots = self::normalizeRobots(array_merge($robots, $advanced), false);
        } else {
            $unsupported = self::unsupportedSeoProvider();
            if ($unsupported !== null) {
                $provider = $unsupported;
            } else {
                $permalink = get_permalink($postId);
                $canonical = is_string($permalink) ? $permalink : '';
                if ((string) get_option('blog_public', '1') === '0') {
                    $robots[] = 'noindex';
                }
            }
        }

        return [
            'provider' => $provider,
            'seo_title' => (string) ($base['seo_title'] ?? ''),
            'seo_description' => (string) ($base['seo_description'] ?? ''),
            'seo_canonical' => $canonical,
            'seo_robots' => is_wp_error($robots) ? [] : $robots,
        ];
    }

    private static function writeSeoMetadata(int $postId, array $changes): array|WP_Error
    {
        $state = self::seoState($postId);
        $provider = $state['provider'];
        if (! in_array($provider, ['rank-math', 'yoast-seo'], true)) {
            return new WP_Error('seo_provider_unsupported', 'No supported enabled SEO provider is available for semantic SEO metadata writes.', ['status' => 409]);
        }

        if (array_key_exists('seo_title', $changes) || array_key_exists('seo_description', $changes)) {
            $written = AIMW_Connector_Runtime::write_seo($postId, $changes);
            if (is_wp_error($written)) {
                return $written;
            }
        }

        if (array_key_exists('seo_canonical', $changes)) {
            $raw = trim((string) $changes['seo_canonical']);
            $canonical = $raw === '' ? '' : esc_url_raw($raw);
            if ($raw !== '' && $canonical === '') {
                return new WP_Error('invalid_canonical', 'Canonical URL must be an absolute valid URL.', ['status' => 422]);
            }
            $key = $provider === 'rank-math' ? 'rank_math_canonical_url' : '_yoast_wpseo_canonical';
            if ($canonical === '') {
                delete_post_meta($postId, $key);
            } else {
                update_post_meta($postId, $key, $canonical);
            }
        }

        if (array_key_exists('seo_robots', $changes)) {
            $robots = self::normalizeRobots($changes['seo_robots'], true);
            if (is_wp_error($robots)) {
                return $robots;
            }
            if (in_array('index', $robots, true) && in_array('noindex', $robots, true)) {
                return new WP_Error('invalid_robots', 'Robots directives cannot contain both index and noindex.', ['status' => 422]);
            }
            if (in_array('follow', $robots, true) && in_array('nofollow', $robots, true)) {
                return new WP_Error('invalid_robots', 'Robots directives cannot contain both follow and nofollow.', ['status' => 422]);
            }
            if ($provider === 'rank-math') {
                if ($robots === []) {
                    delete_post_meta($postId, 'rank_math_robots');
                } else {
                    update_post_meta($postId, 'rank_math_robots', $robots);
                }
            } else {
                if (in_array('noindex', $robots, true)) {
                    update_post_meta($postId, '_yoast_wpseo_meta-robots-noindex', '1');
                } elseif (in_array('index', $robots, true)) {
                    update_post_meta($postId, '_yoast_wpseo_meta-robots-noindex', '2');
                } else {
                    delete_post_meta($postId, '_yoast_wpseo_meta-robots-noindex');
                }
                if (in_array('nofollow', $robots, true)) {
                    update_post_meta($postId, '_yoast_wpseo_meta-robots-nofollow', '1');
                } elseif (in_array('follow', $robots, true)) {
                    update_post_meta($postId, '_yoast_wpseo_meta-robots-nofollow', '0');
                } else {
                    delete_post_meta($postId, '_yoast_wpseo_meta-robots-nofollow');
                }
                $advanced = array_values(array_intersect(['noarchive', 'nosnippet', 'noimageindex'], $robots));
                if ($advanced === []) {
                    delete_post_meta($postId, '_yoast_wpseo_meta-robots-adv');
                } else {
                    update_post_meta($postId, '_yoast_wpseo_meta-robots-adv', implode(',', $advanced));
                }
            }
        }

        return ['provider' => $provider];
    }

    private static function normalizeRobots(mixed $value, bool $strict): array|WP_Error
    {
        $values = is_array($value) ? $value : preg_split('/[\s,]+/', strtolower((string) $value), -1, PREG_SPLIT_NO_EMPTY);
        $allowed = ['index', 'noindex', 'follow', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex'];
        $normalized = array_values(array_unique(array_filter(array_map(static fn ($item): string => strtolower(trim((string) $item)), $values ?: []))));
        $unknown = array_values(array_diff($normalized, $allowed));
        if ($strict && $unknown !== []) {
            return new WP_Error('unsupported_robots_directive', 'Unsupported robots directive: '.implode(', ', $unknown).'.', ['status' => 422]);
        }
        $normalized = array_values(array_intersect($allowed, $normalized));
        sort($normalized);

        return $normalized;
    }

    private static function unsupportedSeoProvider(): ?string
    {
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        foreach (get_plugins() as $file => $data) {
            if (! is_plugin_active($file) || in_array($file, ['wordpress-seo/wp-seo.php', 'seo-by-rank-math/rank-math.php'], true)) {
                continue;
            }
            $haystack = strtolower((string) ($data['Name'] ?? '').' '.$file);
            if (str_contains($haystack, 'seo')) {
                return 'unsupported:'.$file;
            }
        }

        return null;
    }

    private static function required_scopes(WP_REST_Request $request): array|WP_Error
    {
        $route = $request->get_route();
        if ($route === '/'.self::NS.'/health' || $route === '/'.self::NS.'/capabilities') {
            return ['health'];
        }
        if ($route === '/'.self::NS.'/history') {
            return ['audit.local'];
        }
        if ($route === '/'.self::NS.'/content' || preg_match('#^/'.self::NS.'/content/(post|page)/\d+$#', $route)) {
            return ['content.read'];
        }
        if ($route === '/'.self::NS.'/rotate' || $route === '/'.self::NS.'/disconnect') {
            return ['connector.manage'];
        }
        if ($route === '/'.self::NS.'/execute') {
            $changes = (array) (($request->get_json_params()['changes'] ?? []));
            $required = [];
            if (array_intersect(['title', 'content', 'slug'], array_keys($changes))) {
                $required[] = 'content.update';
            }
            if (array_intersect(['seo_title', 'seo_description', 'seo_canonical', 'seo_robots'], array_keys($changes))) {
                $required[] = 'seo.write';
            }
            if ($required) {
                return $required;
            }
        }
        if ($route === '/'.self::NS.'/operate') {
            $payload = (array) $request->get_json_params();
            try {
                return AIMW_Connector_Security::operation_scopes((string) ($payload['operation'] ?? ''), (array) ($payload['arguments'] ?? []));
            } catch (InvalidArgumentException $exception) {
                return new WP_Error('unsupported_operation', $exception->getMessage(), ['status' => 422]);
            }
        }

        return new WP_Error('scope_denied', 'No approved connector scope maps to this operation.', ['status' => 403]);
    }
}

register_activation_hook(__FILE__, [AIMW_Connector_V1::class, 'activate']);
register_deactivation_hook(__FILE__, [AIMW_Connector_V1::class, 'deactivate']);
AIMW_Connector_V1::boot();
