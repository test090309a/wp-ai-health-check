<?php
declare( strict_types = 1 );

namespace WPAIC;

defined( 'ABSPATH' ) || exit;

/**
 * Persistente Speicherung der KI-Analyse-Ergebnisse in einer eigenen Tabelle.
 * Verwendet dbDelta für sicheres Table-Management.
 */
class AnalysisStore {

    private static string $table = '';

    public static function get_table_name(): string {
        if ( empty( self::$table ) ) {
            global $wpdb;
            self::$table = $wpdb->prefix . 'wpaic_analyses';
        }
        return self::$table;
    }

    /**
     * Erstellt die Tabelle falls nicht vorhanden.
     * MUSS über activate/deactivate Hook oder beim Plugin-Load aufgerufen werden.
     */
    public static function create_table(): void {
        global $wpdb;

        $table = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            run_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            model varchar(128) NOT NULL DEFAULT '',
            duration_ms int UNSIGNED DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'ok',
            error_message text DEFAULT NULL,
            result_json longtext DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY idx_run_at (run_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Fügt eine neue Analyse ein und gibt die ID zurück.
     */
    public static function insert( array $entry ): int|false {
        global $wpdb;
        $table = self::get_table_name();

        $data = array(
            'run_at'        => $entry['run_at'] ?? current_time( 'mysql' ),
            'model'         => $entry['model'] ?? '',
            'duration_ms'   => (int) ( $entry['duration_ms'] ?? 0 ),
            'status'        => $entry['status'] ?? 'ok',
            'error_message' => $entry['error_message'] ?? null,
            'result_json'   => $entry['result_json'] ?? null,
        );

        $formats = array( '%s', '%s', '%d', '%s', '%s', '%s' );

        $wpdb->insert( $table, $data, $formats );

        if ( $wpdb->insert_id ) {
            return (int) $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Liefert die letzte/n Analysen.
     *
     * @param int $limit  Anzahl der zurückzugebenden Einträge.
     * @return array<int, array{id:int, run_at:string, model:string, duration_ms:int, status:string, error_message:?string, result_json:?string}>
     */
    public static function find( int $limit = 10 ): array {
        global $wpdb;
        $table = self::get_table_name();
        $limit = max( 1, min( $limit, 100 ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY run_at DESC, id DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) ) {
            return array();
        }

        $out = array();
        foreach ( $rows as $row ) {
            $row['duration_ms'] = (int) ( $row['duration_ms'] ?? 0 );
            $row['result_json'] = ! empty( $row['result_json'] ) ? json_decode( (string) $row['result_json'], true ) : null;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Liefert die neueste Analyse (oder null).
     */
    public static function latest(): ?array {
        $rows = self::find( 1 );
        return ! empty( $rows ) ? $rows[0] : null;
    }

    /**
     * Löscht alle Analysen.
     */
    public static function delete_all(): int {
        global $wpdb;
        $table = self::get_table_name();
        return (int) $wpdb->delete( $table, array() );
    }
}
