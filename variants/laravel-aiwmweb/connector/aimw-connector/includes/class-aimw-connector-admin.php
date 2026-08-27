<?php

defined('ABSPATH') || exit;

final class AIMW_Connector_Admin
{
    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_aimw_connector_save', [self::class, 'save']);
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
