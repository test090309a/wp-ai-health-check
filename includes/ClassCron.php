<?php
declare( strict_types = 1 );

namespace WPAIC;

defined( 'ABSPATH' ) || exit;

class Cron {

    const HOOK = 'wpaic_daily_check';

    public function register_hooks(): void {
        add_action( self::HOOK, array( __CLASS__, 'run_check' ) );
        add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
    }

    public static function maybe_schedule(): void {
        $enabled = get_option( 'wpaic_cron_enabled', false );
        $scheduled = wp_next_scheduled( self::HOOK );
        
        if ( $enabled && ! $scheduled ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
        } elseif ( ! $enabled && $scheduled ) {
            self::clear_scheduled();
        }
    }

    public static function run_check(): void {
        $state = HealthCollector::collect();
        
        $messages = array(
            array(
                'role' => 'system',
                'content' => __(
                    'Kompakte WordPress-Zustandsanalyse als JSON mit summary, risks und recommendations.',
                    'wp-ai-health-check'
                )
            ),
            array(
                'role' => 'user',
                'content' => wp_json_encode( $state )
            ),
        );
        
        $ai = OllamaClient::chat( $messages );
        if ( ! is_wp_error( $ai ) ) {
            set_transient( 'wpaic_last_result', $ai, DAY_IN_SECONDS );
            update_option( 'wpaic_last_run', current_time( 'mysql' ) );
            
            // Prüfen auf kritische Risiken und Benachrichtigung senden
            if ( isset( $ai['risks'] ) && is_array( $ai['risks'] ) ) {
                $critical = array_filter( $ai['risks'], function( $risk ) {
                    return isset( $risk['level'] ) && 'high' === $risk['level'];
                } );
                
                if ( ! empty( $critical ) ) {
                    self::send_alert( $critical, $ai['summary'] ?? '' );
                }
            }
        }
    }

    /**
     * Sendet eine E-Mail-Benachrichtigung bei kritischen Problemen
     */
    private static function send_alert( array $risks, string $summary ): void {
        $admin_email = get_option( 'admin_email' );
        $subject = sprintf(
            /* translators: %s: Site-Name */
            __( '[%s] Kritische WordPress-Probleme erkannt', 'wp-ai-health-check' ),
            get_bloginfo( 'name' )
        );
        
        $message = __( 'Die automatische Analyse hat kritische Probleme erkannt:', 'wp-ai-health-check' ) . "\n\n";
        $message .= $summary . "\n\n";
        
        foreach ( $risks as $risk ) {
            $message .= sprintf(
                "• %s: %s\n",
                $risk['title'] ?? __( 'Problem', 'wp-ai-health-check' ),
                $risk['detail'] ?? ''
            );
        }
        
        $message .= "\n" . __( 'Bitte überprüfen Sie den WordPress-Admin-Bereich für weitere Details.', 'wp-ai-health-check' );
        
        wp_mail( $admin_email, $subject, $message );
    }

    public static function clear_scheduled(): void {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
    }
}