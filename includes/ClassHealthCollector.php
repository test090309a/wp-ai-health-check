<?php
declare( strict_types = 1 );

namespace WPAIC;

defined( 'ABSPATH' ) || exit;

class HealthCollector {

    /** Sensible Schlüssel, die gefiltert werden */
    private static array $sensitive_keys = array(
        'password', 'passwd', 'secret', 'api_key', 'apikey',
        'token', 'credential', 'private_key', 'auth_token',
    );

    // 🔥 WHITELIST: Keys die "auth"/"key" enthalten aber NICHT sensibel sind
    private static array $safe_keys = array(
        'authorization_header', 'https_status', 'background_updates',
        'loopback_requests', 'page_cache', 'dotorg_communication',
        'wordpress-version', 'object_cache', 'max_execution_time',
    );

    /**
     * Sammelt nicht-sensible Zustandsdaten als Array
     */
    public static function collect(): array {
        global $wpdb;

        $data = array(
            'site' => array(
                'wp_version'         => get_bloginfo( 'version' ),
                'wp_version_status'  => self::get_wp_version_status(),
                'php_version'        => PHP_VERSION,
                'mysql_version'      => $wpdb->db_version(),
                'language'           => get_locale(),
                'is_multisite'       => is_multisite(),
                'https'              => is_ssl(),
                'debug_mode'         => ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
            ),
            'active_plugins' => self::active_plugins(),
            'active_theme'   => self::active_theme(),
            'health'         => self::get_site_health_summary(),
            'db'             => array(
                'size_mb'       => self::db_size_mb(),
                'options_count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ),
            ),
            'performance'    => array(
                'object_cache'   => wp_using_ext_object_cache(),
                'memory_limit'   => ini_get( 'memory_limit' ) ?: 'n/a',
                'max_exec_time'  => ini_get( 'max_execution_time' ) ?: 'n/a',
            ),
            'collected_at'   => current_time( 'mysql' ),
        );

        // Sensible Daten filtern
        return self::filter_sensitive_data( $data );
    }

    /**
     * Ermittelt den expliziten Sicherheitsstatus der WordPress-Version.
     */
    private static function get_wp_version_status(): array {
        $current_version = get_bloginfo( 'version' );
        
        if ( ! function_exists( 'get_core_updates' ) ) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        
        $updates = get_core_updates();
        
        if ( empty( $updates ) || ! is_array( $updates ) ) {
            return array(
                'status'          => 'current',
                'message'         => null,
                'security_critical' => false,
            );
        }
        
        foreach ( $updates as $update ) {
            if ( isset( $update->response ) && $update->response === 'upgrade' ) {
                $version_check = self::quick_test( 'wordpress-version' );
                
                $is_security = in_array( $version_check, array( 'critical', 'recommended' ), true );
                
                return array(
                    'status'          => 'outdated',
                    'message'         => sprintf(
                        'WordPress %s ist verfügbar (aktuell installiert: %s). Site Health Status: %s',
                        $update->current ?? 'unbekannt',
                        $current_version,
                        $version_check
                    ),
                    'security_critical' => $version_check === 'critical',
                );
            }
        }
        
        return array(
            'status'          => 'current',
            'message'         => null,
            'security_critical' => false,
        );
    }

    /**
     * Filtert sensible Daten aus dem Array
     */
    private static function filter_sensitive_data( array $data ): array {
        array_walk_recursive( $data, function ( &$value, $key ) {
            if ( self::is_sensitive_key( (string) $key ) ) {
                $value = '[REDACTED]';
            }
        } );
        return $data;
    }

    /**
     * 🔥 FIX: Prüft ob ein Schlüssel sensibel ist (ONCE!)
     * Mit Whitelist für bekannte Safe-Keys
     */
    private static function is_sensitive_key( string $key ): bool {
        $key_lower = strtolower( $key );
        
        // 🔥 Whitelist: Schlüssel die NICHT sensibel sind
        if ( in_array( $key_lower, self::$safe_keys, true ) ) {
            return false;
        }
        
        foreach ( self::$sensitive_keys as $sensitive ) {
            if ( strpos( $key_lower, $sensitive ) !== false ) {
                return true;
            }
        }
        return false;
    }

    private static function active_plugins(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $out   = array();
        $files = (array) get_option( 'active_plugins', array() );
        
        foreach ( $files as $file ) {
            $data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
            $out[] = array(
                'name'    => $data['Name'] ?? basename( $file ),
                'version' => $data['Version'] ?? '',
                'slug'    => dirname( $file ),
            );
        }
        return $out;
    }

    private static function active_theme(): array {
        $theme = wp_get_theme();
        return array(
            'name'    => $theme->get( 'Name' ),
            'version' => $theme->get( 'Version' ),
            'template'=> $theme->get_template(),
        );
    }

    /**
     * Interner REST-Aufruf der Core-Site-Health-Tests
     */
    private static function get_site_health_summary(): array {
        return array(
            'loopback_requests'    => self::quick_test( 'loopback-requests' ),
            'background_updates'   => self::quick_test( 'background-updates' ),
            'https_status'         => self::quick_test( 'https-status' ),
            'page_cache'           => self::quick_test( 'page-cache' ),
            'authorization_header' => self::quick_test( 'authorization-header' ),
            'dotorg_communication' => self::quick_test( 'dotorg-communication' ),
            'wordpress-version'    => self::quick_test( 'wordpress-version' ),
        );
    }

    private static function quick_test( string $slug ): string {
        $request = new \WP_REST_Request( 'GET', "/wp-site-health/v1/tests/{$slug}" );
        $response = rest_do_request( $request );
        $data = $response->get_data();
        return is_array( $data ) ? ( $data['status'] ?? 'unknown' ) : 'unknown';
    }

    private static function db_size_mb(): float {
        global $wpdb;
        
        $rows = $wpdb->get_results( "SHOW TABLE STATUS", ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return 0.0;
        }
        
        $sum = 0;
        foreach ( $rows as $row ) {
            $sum += ( $row['Data_length'] ?? 0 ) + ( $row['Index_length'] ?? 0 );
        }
        return round( $sum / 1024 / 1024, 2 );
    }
}