<?php

defined('ABSPATH') || exit;

final class AIMW_Connector_Store
{
    public const SCHEMA_VERSION = '2';
    private const MAX_HISTORY = 500;

    public static function migrate(): void
    {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            event varchar(100) NOT NULL,
            status varchar(40) NOT NULL,
            operation_id varchar(64) NULL,
            request_id varchar(64) NULL,
            correlation_id varchar(64) NULL,
            result_json longtext NULL,
            before_hash char(64) NULL,
            after_hash char(64) NULL,
            actor_user_id bigint unsigned NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY operation_id (operation_id),
            KEY correlation_id (correlation_id),
            KEY created_at (created_at)
        ) {$charset};");
        update_option('aimw_connector_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function maybe_migrate(): void
    {
        if ((string) get_option('aimw_connector_schema_version', '') !== self::SCHEMA_VERSION) {
            self::migrate();
        }
    }

    public static function record(string $event, string $status, array $protocol = [], mixed $result = null, mixed $before = null, mixed $after = null, ?int $actorUserId = null): void
    {
        global $wpdb;
        self::maybe_migrate();
        $wpdb->insert(self::table(), [
            'event' => sanitize_key($event),
            'status' => sanitize_key($status),
            'operation_id' => self::bounded_id($protocol['operation'] ?? null),
            'request_id' => self::bounded_id($protocol['request'] ?? null),
            'correlation_id' => self::bounded_id($protocol['correlation'] ?? null),
            'result_json' => wp_json_encode(AIMW_Connector_Security::redact($result)),
            'before_hash' => AIMW_Connector_Security::state_hash($before),
            'after_hash' => AIMW_Connector_Security::state_hash($after),
            'actor_user_id' => $actorUserId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s']);
        self::trim();
    }

    public static function history(int $limit = 100): array
    {
        global $wpdb;
        self::maybe_migrate();
        $limit = max(1, min(200, $limit));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id,event,status,operation_id,request_id,correlation_id,result_json,before_hash,after_hash,actor_user_id,created_at FROM '.self::table().' ORDER BY id DESC LIMIT %d', $limit), ARRAY_A);

        return array_map(static function (array $row): array {
            $row['result'] = $row['result_json'] ? json_decode($row['result_json'], true) : null;
            unset($row['result_json']);

            return AIMW_Connector_Security::redact($row);
        }, $rows ?: []);
    }

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix.'aimw_connector_history';
    }

    private static function trim(): void
    {
        global $wpdb;
        $table = self::table();
        $cutoff = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", self::MAX_HISTORY - 1));
        if ($cutoff) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id < %d", (int) $cutoff));
        }
    }

    private static function bounded_id(mixed $value): ?string
    {
        if (! is_scalar($value) || (string) $value === '') {
            return null;
        }

        return substr(sanitize_text_field((string) $value), 0, 64);
    }
}
