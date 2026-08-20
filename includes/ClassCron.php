<?php
declare( strict_types = 1 );

namespace WPAIC;

defined( 'ABSPATH' ) || exit;

class Cron {

    const HOOK = 'wpaic_daily_check';

    public function register_hooks(): void {
        add_action( self::HOOK, array( __CLASS__, 'run_check' ) );
        add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
        // Cron-Intervall-Filter
        add_filter( 'cron_schedules', array( __CLASS__, 'add_schedules' ) );
    }

    /**
     * Fügt benutzerdefinierte Cron-Intervalle hinzu.
     */
    public static function add_schedules( array $schedules ): array {
        $schedules['wpaic_daily'] = array(
            'interval' => DAY_IN_SECONDS,
            'display'  => __( 'Einmal täglich (24 Stunden)', 'wp-ai-health-check' ),
        );
        return $schedules;
    }

    /**
     * Plant oder löscht den Cron-Job basierend auf der Einstellung.
     */
    public static function maybe_schedule(): void {
        $enabled = get_option( 'wpaic_cron_enabled', false );
        $scheduled = wp_next_scheduled( self::HOOK );
        
        if ( $enabled && ! $scheduled ) {
            // Verwende das benutzerdefinierte Intervall
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'wpaic_daily', self::HOOK );
        } elseif ( ! $enabled && $scheduled ) {
            self::clear_scheduled();
        }
    }

    /**
     * Führt die automatische Analyse durch.
     */
    public static function run_check(): void {
        $state = HealthCollector::collect();
        $start = microtime( true );

        // Verbesserte System-Prompt (Anti-Halluzination)
        $messages = array(
            array(
                'role' => 'system',
                'content' => __(
                    'Du bist ein erfahrener WordPress-Sicherheits-Experte. Analysiere die bereitgestellten Daten.\n'
                    . "STRICT RULES:\n"
                    . "1. NUR die bereitgestellten Daten verwenden. Keine Annahmen treffen.\n"
                    . "2. KEINE Upgrade-Empfehlungen abgeben, es sei denn die Version ist explizit veraltet.\n"
                    . "3. KEINE Risiken halluzinieren. Nur erwähnen, was in den Daten sichtbar ist.\n"
                    . "4. NUR gültiges JSON zurückgeben. Kein Markdown, kein Text.\n"
                    . "5. Schema: { \"summary\": \"Ein Satz\", \"risks\": [{\"level\": \"low|medium|high|critical\", \"title\": \"Titel\", \"detail\": \"Erklärung\"}], \"recommendations\": [\"Liste\"] }\n"
                    . "6. Deutsch für alle Textfelder.\n"
                    . "7. Max. 8 Risiken, max. 5 Empfehlungen.\n",
                    'wp-ai-health-check'
                )
            ),
            array(
                'role' => 'user',
                'content' => wp_json_encode( $state )
            ),
        );
        
        $ai = OllamaClient::chat( $messages );
        $duration = (int) round( ( microtime( true ) - $start ) * 1000 );
        
        if ( ! is_wp_error( $ai ) ) {
            // Ergebnis persistieren
            AnalysisStore::insert( array(
                'run_at'        => current_time( 'mysql' ),
                'model'         => get_option( 'wpaic_ollama_model', '' ),
                'duration_ms'   => $duration,
                'status'        => 'ok',
                'error_message' => null,
                'result_json'   => wp_json_encode( $ai ),
            ) );

            // Auch Transient aktualisieren (für sofortige Anzeige)
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
        } else {
            // Fehler persistieren
            AnalysisStore::insert( array(
                'run_at'        => current_time( 'mysql' ),
                'model'         => get_option( 'wpaic_ollama_model', '' ),
                'duration_ms'   => $duration,
                'status'        => 'error',
                'error_message' => $ai->get_error_message(),
                'result_json'   => null,
            ) );
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
