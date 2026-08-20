<?php
declare( strict_types = 1 );

namespace WPAIC;

defined( 'ABSPATH' ) || exit;

class RestController {

    public function register_hooks(): void {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes(): void {
        // ============================================================
        // /analyze - POST - Analyse starten
        // ============================================================
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
        
        // ============================================================
        // /health - GET - Health Check
        // ============================================================
        register_rest_route( 'wpaic/v1', '/health', array(
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => array( $this, 'health_check' ),
                'permission_callback' => array( $this, 'can_manage' ),
            ),
        ) );

        // ============================================================
        // /latest - GET - Neueste Analyse
        // ============================================================
        register_rest_route( 'wpaic/v1', '/latest', array(
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_latest' ),
                'permission_callback' => array( $this, 'can_manage' ),
            ),
        ) );

        // ============================================================
        // /history - GET - Historie abrufen
        // /history - DELETE - Eintrag löschen
        // ============================================================
        register_rest_route( 'wpaic/v1', '/history', array(
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_history' ),
                'permission_callback' => array( $this, 'can_manage' ),
            ),
            array(
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => array( $this, 'delete_history' ),
                'permission_callback' => array( $this, 'can_manage' ),
                'args'                => array(
                    'id' => array(
                        'type'              => 'integer',
                        'required'          => true,
                        'validate_callback' => function($param) { return $param > 0; },
                    ),
                ),
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

    public function get_latest( \WP_REST_Request $req ): \WP_REST_Response {
        $entry = AnalysisStore::latest();
        if ( ! $entry ) {
            return new \WP_REST_Response( array(
                'found' => false,
            ), 200 );
        }
        return new \WP_REST_Response( array(
            'found'    => true,
            'id'       => $entry['id'],
            'run_at'   => $entry['run_at'],
            'model'    => $entry['model'],
            'duration_ms' => $entry['duration_ms'],
            'status'   => $entry['status'],
            'result'   => $entry['result_json'] ?? null,
        ), 200 );
    }

    public function get_history( \WP_REST_Request $req ): \WP_REST_Response {
        $limit = (int) $req->get_param( 'limit' );
        $entries = AnalysisStore::find( $limit ?: 10 );
        return new \WP_REST_Response( array(
            'entries' => $entries,
            'count'   => count( $entries ),
        ), 200 );
    }

    /**
     * 🆕 DELETE /history - Löscht einen Analyse-Eintrag
     */
    public function delete_history( \WP_REST_Request $req ): \WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        
        if ( $id <= 0 ) {
            return new \WP_REST_Response( array(
                'success' => false,
                'error'   => __( 'Ungültige ID.', 'wp-ai-health-check' ),
            ), 400 );
        }

        global $wpdb;
        $table = AnalysisStore::get_table_name();
        $deleted = $wpdb->delete( $table, array( 'id' => $id ) );

        if ( $deleted ) {
            return new \WP_REST_Response( array(
                'success' => true,
                'id'      => $id,
            ), 200 );
        }

        return new \WP_REST_Response( array(
            'success' => false,
            'error'   => __( 'Löschen fehlgeschlagen.', 'wp-ai-health-check' ),
        ), 500 );
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
        $start = microtime( true );

        // 🔥 Verbesserte System-Prompt (Anti-Halluzination)
        $system = $this->build_system_prompt();

        $messages = array(
            array( 'role' => 'system', 'content' => $system ),
            array( 'role' => 'user', 'content' => wp_json_encode( $state, JSON_PRETTY_PRINT ) ),
        );

        // An Ollama schicken
        $ai = OllamaClient::chat( $messages );
        $duration = (int) round( ( microtime( true ) - $start ) * 1000 );

        if ( is_wp_error( $ai ) ) {
            // Fehler persistieren
            AnalysisStore::insert( array(
                'run_at'        => current_time( 'mysql' ),
                'model'         => get_option( 'wpaic_ollama_model', '' ),
                'duration_ms'   => $duration,
                'status'        => 'error',
                'error_message' => $ai->get_error_message(),
                'result_json'   => null,
            ) );

            return new \WP_REST_Response( array(
                'success' => false,
                'error'   => $ai->get_error_message(),
                'state'   => $state,
            ), 502 );
        }

        // 🔥 Sicherstellen dass das Ergebnis die erwartete Struktur hat
        if ( ! isset( $ai['uncertainties'] ) ) {
            $ai['uncertainties'] = array();
        }

        // Ergebnis cachen UND persistieren
        set_transient( 'wpaic_last_result', $ai, DAY_IN_SECONDS );
        update_option( 'wpaic_last_run', current_time( 'mysql' ) );

        AnalysisStore::insert( array(
            'run_at'        => current_time( 'mysql' ),
            'model'         => get_option( 'wpaic_ollama_model', '' ),
            'duration_ms'   => $duration,
            'status'        => 'ok',
            'error_message' => null,
            'result_json'   => wp_json_encode( $ai ),
        ) );

        return new \WP_REST_Response( array(
            'success' => true,
            'result'  => $ai,
            'state'   => $state,
            'duration_ms' => $duration,
        ), 200 );
    }

    /**
     * 🔥 Anti-Halluzination System-Prompt.
     * Verhindert erfundene Risiken und falsche Upgrade-Aufforderungen.
     * Mit "uncertainties"-Feld für Transparenz.
     */
    private function build_system_prompt(): string {
        return 'Du bist ein WordPress-Sicherheits-Experte, aber du HALLUZINIERST NICHT.

STRICT RULES:
1. NUR die bereitgestellten Daten verwenden. KEINE Annahmen.
2. DU KENNST KEINE WORDPRESS-VERSIONEN AUSWENDIG.
   - Wenn wp_version in den Daten NICHT explizit alt ist, sag KEIN "veraltet".
   - Erfinde KEINE Daten. Wenn du etwas nicht weißt, sag es nicht.
3. KEINE Upgrade-Empfehlungen, es sei denn, es gibt handfeste Daten dafür.
4. KEINE erfundenen Risiken. Nur was in den Daten sichtbar ist.
5. Antworte NUR mit gültigem JSON. KEIN Markdown, KEIN Text.
6. Verwende das EXAKTE Schema:
{
    "summary": "Ein Satz Zusammenfassung (max 2 Sätze)",
    "risks": [
        {"level": "low|medium|high|critical", "title": "Kurzer Titel", "detail": "Erklärung"}
    ],
    "recommendations": ["Empfehlung 1", "Empfehlung 2"],
    "uncertainties": ["Dinge, die du nicht weißt oder unsicher bist"]
}
7. Deutsch, aber präzise und sachlich.
8. Maximal 8 Risiken, maximal 5 Empfehlungen.
9. Wenn du unsicher bist, schreibe es in "uncertainties" – das ist besser als zu raten.
10. Wenn du keine Risiken findest, gib ein leeres Array zurück.
11. Die WordPress-Version in den Daten ist die INSTALLIERTE Version – beurteile NICHT, ob sie veraltet ist, es sei denn, die Daten sagen es explizit.';
    }

    /**
     * 🔥 Hilfsfunktion: Validiert und bereinigt die KI-Antwort
     */
    private function validate_ai_response( array $response ): array {
        // Stelle sicher dass alle Felder existieren
        if ( ! isset( $response['summary'] ) ) {
            $response['summary'] = 'Analyse abgeschlossen.';
        }
        if ( ! isset( $response['risks'] ) || ! is_array( $response['risks'] ) ) {
            $response['risks'] = array();
        }
        if ( ! isset( $response['recommendations'] ) || ! is_array( $response['recommendations'] ) ) {
            $response['recommendations'] = array();
        }
        if ( ! isset( $response['uncertainties'] ) || ! is_array( $response['uncertainties'] ) ) {
            $response['uncertainties'] = array();
        }

        // Begrenze Anzahl der Einträge
        if ( count( $response['risks'] ) > 8 ) {
            $response['risks'] = array_slice( $response['risks'], 0, 8 );
        }
        if ( count( $response['recommendations'] ) > 5 ) {
            $response['recommendations'] = array_slice( $response['recommendations'], 0, 5 );
        }

        // Validiere jedes Risiko
        foreach ( $response['risks'] as &$risk ) {
            if ( ! isset( $risk['level'] ) ) {
                $risk['level'] = 'low';
            }
            if ( ! in_array( $risk['level'], array( 'low', 'medium', 'high', 'critical' ), true ) ) {
                $risk['level'] = 'low';
            }
            if ( ! isset( $risk['title'] ) ) {
                $risk['title'] = 'Unbekanntes Risiko';
            }
            if ( ! isset( $risk['detail'] ) ) {
                $risk['detail'] = 'Keine weiteren Details verfügbar.';
            }
        }

        return $response;
    }
}