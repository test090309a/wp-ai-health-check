<?php
declare( strict_types = 1 );

namespace WPAIC;

defined( 'ABSPATH' ) || exit;

class RestController {

    public function register_hooks(): void {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes(): void {
        register_rest_route( 'wpaic/v1', '/analyze', array(
            array(
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'analyze' ),
                'permission_callback' => array( $this, 'can_manage' ),
                'args'                => array(
                    'force' => array(
                        'type'              => 'boolean',
                        'default'           => false,
                        'sanitize_callback' => 'rest_sanitize_boolean',
                    ),
                ),
            ),
        ) );
        
        // Health-Check Endpoint
        register_rest_route( 'wpaic/v1', '/health', array(
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => array( $this, 'health_check' ),
                'permission_callback' => array( $this, 'can_manage' ),
            ),
        ) );
    }

    public function can_manage( \WP_REST_Request $req ): bool|\WP_Error {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error(
                'wpaic_forbidden',
                __( 'Keine Berechtigung.', 'wp-ai-health-check' ),
                array( 'status' => 403 )
            );
        }
        return true;
    }

    public function health_check( \WP_REST_Request $req ): \WP_REST_Response {
        $available = OllamaClient::is_available();
        return new \WP_REST_Response( array(
            'ollama_available' => $available,
            'timestamp' => current_time( 'mysql' ),
        ), 200 );
    }

    public function analyze( \WP_REST_Request $req ): \WP_REST_Response {
        // Ollama-Verfügbarkeit prüfen
        if ( ! OllamaClient::is_available() ) {
            return new \WP_REST_Response( array(
                'success' => false,
                'error'   => __( 'Ollama ist nicht erreichbar.', 'wp-ai-health-check' ),
            ), 503 );
        }

        // Zustand sammeln
        $state = HealthCollector::collect();

        // System-Prompt (mehrsprachig)
        $system = __(
            'You are a WordPress expert. Analyze the data and return JSON with fields: '
            . '"summary" (short string), "risks" (array of {level, title, detail}), '
            . 'and "recommendations" (array of strings). Keep it VERY concise.',
            'wp-ai-health-check'
        );

        $messages = array(
            array( 'role' => 'system', 'content' => $system ),
            array( 'role' => 'user', 'content' => wp_json_encode( $state, JSON_PRETTY_PRINT ) ),
        );

        // An Ollama schicken
        $ai = OllamaClient::chat( $messages );
        if ( is_wp_error( $ai ) ) {
            return new \WP_REST_Response( array(
                'success' => false,
                'error'   => $ai->get_error_message(),
                'state'   => $state,
            ), 502 );
        }

        // Ergebnis cachen
        set_transient( 'wpaic_last_result', $ai, HOUR_IN_SECONDS );
        update_option( 'wpaic_last_run', current_time( 'mysql' ) );

        return new \WP_REST_Response( array(
            'success' => true,
            'result'  => $ai,
            'state'   => $state,
        ), 200 );
    }
}