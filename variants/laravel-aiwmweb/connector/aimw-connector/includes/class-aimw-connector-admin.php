<?php

defined('ABSPATH') || exit;

final class AIMW_Connector_Admin
{
    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_aimw_connector_save', [self::class, 'save']);
        add_action('admin_post_aimw_connector_pair', [self::class, 'pair']);
        add_action('admin_post_aimw_connector_emergency_disconnect', [self::class, 'disconnect']);
    }

    public static function menu(): void
    {
        add_management_page('AIMW Connector', 'AIMW Connector', 'manage_options', 'aimw-connector', [self::class, 'render']);
    }

    public static function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage the AIMW Connector.', 'aimw-connector'));
        }
        $config = get_option('aimw_connector', []);
        $caps = AIMW_Connector_Runtime::capabilities();
        $state = AIMW_Connector_V1::connection_state();
        ?>
        <div class="wrap">
            <h1>AIMW Connector</h1>
            <p><strong>Version:</strong> <?php echo esc_html(AIMW_Connector_V1::PLUGIN_VERSION); ?> &nbsp; <strong>Protocol:</strong> <?php echo esc_html(AIMW_Connector_V1::PROTOCOL_VERSION); ?></p>
            <p><strong>Connection:</strong> <?php echo esc_html($state['connection']); ?> &nbsp; <strong>Protocol state:</strong> <?php echo esc_html($state['protocol_state']); ?></p>
            <p><strong>Connector identity:</strong> <?php echo esc_html((string) ($config['identity'] ?? 'Not paired')); ?></p>
            <p><strong>Secret:</strong> <em>stored locally and never displayed</em></p>

            <h2>Connect to AIWMWeb Manager</h2>
            <p>Enter the HTTPS address of your Laravel AIWMWeb manager and the one-time pairing token created for this WordPress site in AIWMWeb. The token is used once and is never displayed again.</p>
            <?php if (isset($_GET['pairing']) && $_GET['pairing'] === 'success') : ?>
                <div class="notice notice-success"><p>Connected to AIWMWeb Manager successfully.</p></div>
            <?php elseif (isset($_GET['pairing']) && $_GET['pairing'] === 'failed') : ?>
                <div class="notice notice-error"><p>Connection failed. Verify the Manager URL, site pairing token and HTTPS connectivity. Your token is not stored on failure.</p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="off">
                <input type="hidden" name="action" value="aimw_connector_pair">
                <?php wp_nonce_field('aimw_connector_pair'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="aimw-platform-url">Manager URL</label></th>
                        <td><input id="aimw-platform-url" name="platform_url" type="url" class="regular-text" required
                            placeholder="https://aiwm.walidatiya.com" value="<?php echo esc_attr((string) ($config['platform_url'] ?? '')); ?>" />
                            <p class="description">The public HTTPS URL of your AIWMWeb Laravel platform, without /login or /wp-admin.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aimw-pairing-token">One-time pairing token</label></th>
                        <td><input id="aimw-pairing-token" name="pairing_token" type="password" class="regular-text" required
                            maxlength="512" autocomplete="new-password" />
                            <p class="description">Generate the token from the selected WordPress site in AIWMWeb. Never paste your WordPress or platform password here.</p></td>
                    </tr>
                </table>
                <?php submit_button('Connect & Verify'); ?>
            </form>

            <h2>Owner-enabled scopes</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="aimw_connector_save">
                <?php wp_nonce_field('aimw_connector_save'); ?>
                <table class="widefat striped" style="max-width:1000px">
                    <thead><tr><th>Scope</th><th>Runtime state</th><th>Enabled</th><th>Notes</th></tr></thead>
                    <tbody>
                    <?php foreach ($caps['states'] as $scope => $detail) : ?>
                        <tr>
                            <td><code><?php echo esc_html($scope); ?></code></td>
                            <td><?php echo esc_html($detail['state']); ?></td>
                            <td>
                                <label>
                                    <input type="checkbox" name="scopes[]" value="<?php echo esc_attr($scope); ?>" <?php checked(in_array($scope, (array) ($config['enabled_scopes'] ?? []), true)); ?> <?php disabled(in_array($detail['state'], ['UNSUPPORTED', 'TEMPORARILY_UNAVAILABLE'], true)); ?>>
                                    enabled
                                </label>
                            </td>
                            <td><?php echo esc_html((string) ($detail['reason'] ?? (in_array($scope, AIMW_Connector_Security::HIGH_RISK_DISABLED_SCOPES, true) ? 'Sensitive scope; disabled by default.' : ''))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button class="button button-primary" type="submit">Save scopes</button></p>
            </form>

            <h2>Provider adapters</h2>
            <table class="widefat striped" style="max-width:800px">
                <thead><tr><th>Adapter</th><th>State</th><th>Version</th></tr></thead>
                <tbody>
                <?php foreach ($caps['adapters'] as $adapter) : ?>
                    <tr><td><?php echo esc_html($adapter['id']); ?></td><td><?php echo esc_html($adapter['state']); ?></td><td><?php echo esc_html((string) ($adapter['version'] ?? '')); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Emergency disconnect</h2>
            <p>This immediately revokes the local connector secret. Re-pairing is required afterward.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Emergency disconnect AIMW Connector?');">
                <input type="hidden" name="action" value="aimw_connector_emergency_disconnect">
                <?php wp_nonce_field('aimw_connector_emergency_disconnect'); ?>
                <button class="button button-secondary" type="submit">Emergency disconnect</button>
            </form>
        </div>
        <?php
    }

    public static function pair(): void
    {
        self::guard('aimw_connector_pair');
        $rawUrl = $_POST['platform_url'] ?? '';
        $rawToken = $_POST['pairing_token'] ?? '';
        $platform = is_string($rawUrl) ? esc_url_raw(wp_unslash($rawUrl)) : '';
        $token = is_string($rawToken) ? (string) wp_unslash($rawToken) : '';
        $parts = $platform !== '' ? wp_parse_url($platform) : false;

        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || strlen($platform) > 1024 || $token === '' || strlen($token) > 512) {
            wp_safe_redirect(admin_url('tools.php?page=aimw-connector&pairing=failed'));
            exit;
        }

        $request = new WP_REST_Request('POST', '/aimw/v1/pair');
        $request->set_param('platform_url', $platform);
        $request->set_param('pairing_token', $token);
        $result = AIMW_Connector_V1::pair($request);
        $success = ! is_wp_error($result) && is_array($result) && ! empty($result['paired']);

        // Do not put the pairing token or upstream response into query strings or logs.
        wp_safe_redirect(admin_url('tools.php?page=aimw-connector&pairing='.($success ? 'success' : 'failed')));
        exit;
    }

    public static function save(): void
    {
        self::guard('aimw_connector_save');
        $config = get_option('aimw_connector', []);
        $requested = array_values(array_unique(array_map('sanitize_text_field', (array) ($_POST['scopes'] ?? []))));
        $allowed = [];
        foreach ($requested as $scope) {
            if (! in_array($scope, AIMW_Connector_Security::CAPABILITIES, true)) {
                continue;
            }
            $detail = AIMW_Connector_Runtime::scope_state($scope, $config);
            if (! in_array($detail['state'], ['UNSUPPORTED', 'TEMPORARILY_UNAVAILABLE'], true)) {
                $allowed[] = $scope;
            }
        }
        $config['enabled_scopes'] = $allowed;
        $config['owner_user_id'] = get_current_user_id();
        update_option('aimw_connector', $config, false);
        AIMW_Connector_Store::record('scopes_updated', 'succeeded', [], ['enabled_scopes' => $allowed], null, null, get_current_user_id());
        wp_safe_redirect(admin_url('tools.php?page=aimw-connector&updated=1'));
        exit;
    }

    public static function disconnect(): void
    {
        self::guard('aimw_connector_emergency_disconnect');
        AIMW_Connector_V1::emergency_disconnect(get_current_user_id());
        wp_safe_redirect(admin_url('tools.php?page=aimw-connector&disconnected=1'));
        exit;
    }

    private static function guard(string $nonceAction): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage the AIMW Connector.', 'aimw-connector'));
        }
        check_admin_referer($nonceAction);
    }
}
