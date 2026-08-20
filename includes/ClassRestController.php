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
        // /test - GET - Debugging-Endpunkt (keine Auth erforderlich)
        // ============================================================
        register_rest_route( 'wpaic/v1', '/test', array(
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => array( $this, 'debug_test' ),
            'permission_callback' => '__return_true',
        ) );

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
                        'validate_callback' => function ( $param ) {
                            return is_numeric( $param ) && (int) $param > 0;
                        },
                    ),
                ),
            ),
        ) );
    }

    /**
     * Debug-Endpunkt – funktioniert ohne Authentifizierung
     */
    public function debug_test(): \WP_REST_Response {
        return new \WP_REST_Response( array(
            'status'           => 'ok',
            'message'          => 'REST API funktioniert!',
            'timestamp'        => current_time( 'mysql' ),
            'user_id'          => get_current_user_id(),
            'user_logged_in'   => is_user_logged_in(),
            'ollama_host'      => get_option( 'wpaic_ollama_host', WPAIC_DEFAULT_OLLAMA_HOST ),
            'ollama_available' => class_exists( __NAMESPACE__ . '\\OllamaClient' )
                                  ? OllamaClient::is_available()
                                  : false,
        ), 200 );
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
            'timestamp'        => current_time( 'mysql' ),
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
            'found'       => true,
            'id'          => $entry['id'],
            'run_at'      => $entry['run_at'],
            'model'       => $entry['model'],
            'duration_ms' => $entry['duration_ms'],
            'status'      => $entry['status'],
            'result'      => $entry['result_json'] ?? null,
        ), 200 );
    }

    public function get_history( \WP_REST_Request $req ): \WP_REST_Response {
        $limit   = (int) $req->get_param( 'limit' );
        $entries = AnalysisStore::find( $limit ?: 10 );
        return new \WP_REST_Response( array(
            'entries' => $entries,
            'count'   => count( $entries ),
        ), 200 );
    }

    /**
     * DELETE /history - Löscht einen Analyse-Eintrag
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
        $table   = AnalysisStore::get_table_name();
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

    /**
     * POST /analyze - Startet eine KI-gestützte Gesundheitsanalyse
     */
    public function analyze( \WP_REST_Request $req ): \WP_REST_Response {
        try {
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

            // Optimierter System-Prompt (Anti-Halluzination)
            $system = $this->build_system_prompt();

            $messages = array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user', 'content' => wp_json_encode( $state, JSON_PRETTY_PRINT ) ),
            );

            // An Ollama schicken
            $raw_ai   = OllamaClient::chat( $messages );
            $duration = (int) round( ( microtime( true ) - $start ) * 1000 );

            if ( is_wp_error( $raw_ai ) ) {
                // Fehler persistieren
                AnalysisStore::insert( array(
                    'run_at'        => current_time( 'mysql' ),
                    'model'         => get_option( 'wpaic_ollama_model', '' ),
                    'duration_ms'   => $duration,
                    'status'        => 'error',
                    'error_message' => $raw_ai->get_error_message(),
                    'result_json'   => null,
                ) );

                return new \WP_REST_Response( array(
                    'success' => false,
                    'error'   => $raw_ai->get_error_message(),
                    'state'   => $state,
                ), 502 );
            }

            // 🔥 FIX: Stelle sicher, dass $raw_ai ein Array ist
            if ( ! is_array( $raw_ai ) ) {
                // Versuche zu parsen
                if ( is_string( $raw_ai ) ) {
                    $parsed = $this->parse_ai_response( $raw_ai );
                    if ( is_array( $parsed ) ) {
                        $raw_ai = $parsed;
                    } else {
                        $raw_ai = array(
                            'summary'         => __( 'Die KI-Antwort konnte nicht interpretiert werden.', 'wp-ai-health-check' ),
                            'risks'           => array(),
                            'recommendations' => array(),
                            'uncertainties'   => array( 'Unerwartetes Antwortformat von Ollama.' ),
                        );
                    }
                } else {
                    $raw_ai = array(
                        'summary'         => __( 'Unerwarteter Antworttyp von Ollama.', 'wp-ai-health-check' ),
                        'risks'           => array(),
                        'recommendations' => array(),
                        'uncertainties'   => array( 'Ollama antwortete mit: ' . gettype( $raw_ai ) ),
                    );
                }
            }

            // 🔥 FIX: Stelle sicher, dass alle erwarteten Felder existieren
            if ( ! isset( $raw_ai['summary'] ) ) {
                $raw_ai['summary'] = __( 'Analyse abgeschlossen.', 'wp-ai-health-check' );
            }
            if ( ! isset( $raw_ai['risks'] ) || ! is_array( $raw_ai['risks'] ) ) {
                $raw_ai['risks'] = array();
            }
            if ( ! isset( $raw_ai['recommendations'] ) || ! is_array( $raw_ai['recommendations'] ) ) {
                $raw_ai['recommendations'] = array();
            }
            if ( ! isset( $raw_ai['uncertainties'] ) || ! is_array( $raw_ai['uncertainties'] ) ) {
                $raw_ai['uncertainties'] = array();
            }

            // 🔥 FIX: Validierung OHNE Arrow Functions (PHP 7.4+ Kompatibilität)
            $ai = $this->validate_ai_response( $raw_ai );

            // Programmatische Unsicherheiten basierend auf fehlenden Daten
            $ai = $this->add_data_driven_uncertainties( $ai, $state );

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
                'success'     => true,
                'result'      => $ai,
                'state'       => $state,
                'duration_ms' => $duration,
            ), 200 );

        } catch ( \Throwable $e ) {
            error_log( '[WPAIC] FATAL in analyze(): ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine()
                . "\n" . $e->getTraceAsString()
            );

            return new \WP_REST_Response( array(
                'success' => false,
                'error'   => $e->getMessage(),
                'file'    => basename( $e->getFile() ),
                'line'    => $e->getLine(),
            ), 500 );
        }
    }

    /**
     * Robustes JSON-Parsing für KI-Antworten.
     */
    private function parse_ai_response( mixed $raw ): array|\WP_Error {
        if ( is_array( $raw ) ) {
            return $raw;
        }

        if ( ! is_string( $raw ) ) {
            return new \WP_Error(
                'wpaic_invalid_response_type',
                sprintf( 'Erwarteter String oder Array, erhalten: %s', gettype( $raw ) )
            );
        }

        // Markdown-Codeblöcke entfernen
        $cleaned = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw ) );
        $cleaned = preg_replace( '/\s*```\s*$/', '', $cleaned );
        $cleaned = trim( $cleaned );

        $decoded = json_decode( $cleaned, true );

        if ( ! is_array( $decoded ) ) {
            return new \WP_Error(
                'wpaic_invalid_json',
                __( 'KI-Antwort ist kein gültiges JSON nach Bereinigung.', 'wp-ai-health-check' )
            );
        }

        return $decoded;
    }

    /**
     * Fügt programmatisch Unsicherheiten hinzu.
     */
    private function add_data_driven_uncertainties( array $ai, array $state ): array {
        if ( ! isset( $ai['uncertainties'] ) || ! is_array( $ai['uncertainties'] ) ) {
            $ai['uncertainties'] = array();
        }

        // Prüfe ob wp_version_status verfügbar ist
        if ( ! isset( $state['site']['wp_version_status'] ) ) {
            $ai['uncertainties'][] = __( 'WordPress-Versionsstatus konnte nicht ermittelt werden.', 'wp-ai-health-check' );
        }

        if ( ! isset( $state['health']['https_status'] ) ) {
            $ai['uncertainties'][] = __( 'HTTPS-Status aus Site Health nicht verfügbar.', 'wp-ai-health-check' );
        }

        if ( empty( $state['active_plugins'] ) ) {
            $ai['uncertainties'][] = __( 'Keine Plugin-Daten übermittelt – Plugin-Analyse nicht möglich.', 'wp-ai-health-check' );
        }

        // 🔥 FIX: array_unique mit String-Keys (keine Arrow Functions)
        $ai['uncertainties'] = array_values( array_unique( $ai['uncertainties'] ) );
        return $ai;
    }

    /**
     * Anti-Halluzination System-Prompt.
     */
    private function build_system_prompt(): string {
        return 'Du bist ein WordPress-Sicherheits-Experte. Antworte AUSSCHLIESSLICH basierend auf den bereitgestellten Daten.

STRICT RULES:
1. Verwende NUR die explizit übermittelten Daten. Triff KEINE Annahmen.
2. WORDPRESS-VERSION: Beurteile NICHT selbst, ob eine Version veraltet ist.
   Wenn "wp_version_status.security_critical" NICHT true ist, sag NICHTS zur Version.
3. Nenne KEINE Risiken, die nicht direkt aus den Daten ableitbar sind.
4. Antworte NUR mit gültigem JSON. KEIN Markdown, KEIN Text außerhalb.
5. Schema:
{
    "summary": "Ein Satz Zusammenfassung (max 2 Sätze)",
    "risks": [
        {"level": "low|medium|high|critical", "title": "Titel", "detail": "Erklärung"}
    ],
    "recommendations": ["Empfehlung 1", "Empfehlung 2"],
    "uncertainties": ["Dinge, die aus den Daten nicht hervorgehen"]
}
6. Deutsch, präzise und sachlich.
7. Maximal 8 Risiken, maximal 5 Empfehlungen.
8. Wenn KEINE Risiken erkennbar sind, gib ein leeres Array bei "risks" zurück.';
    }

    /**
     * Validiert und bereinigt die KI-Antwort.
     */
    private function validate_ai_response( array $response ): array {
        // Stelle sicher dass alle Felder existieren
        if ( ! isset( $response['summary'] ) || ! is_string( $response['summary'] ) ) {
            $response['summary'] = __( 'Analyse abgeschlossen.', 'wp-ai-health-check' );
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

        // Validiere und bereinige jedes Risiko
        $valid_levels = array( 'low', 'medium', 'high', 'critical' );
        foreach ( $response['risks'] as &$risk ) {
            if ( ! is_array( $risk ) ) {
                $risk = array( 'level' => 'low', 'title' => 'Unbekanntes Risiko', 'detail' => '' );
                continue;
            }
            if ( ! isset( $risk['level'] ) || ! in_array( $risk['level'], $valid_levels, true ) ) {
                $risk['level'] = 'low';
            }
            if ( ! isset( $risk['title'] ) || ! is_string( $risk['title'] ) ) {
                $risk['title'] = __( 'Unbekanntes Risiko', 'wp-ai-health-check' );
            }
            if ( ! isset( $risk['detail'] ) || ! is_string( $risk['detail'] ) ) {
                $risk['detail'] = __( 'Keine weiteren Details verfügbar.', 'wp-ai-health-check' );
            }
        }
        unset( $risk );

        // 🔥 FIX: Bereinige Empfehlungen (ohne Arrow Functions)
        $recommendations = array();
        foreach ( $response['recommendations'] as $rec ) {
            if ( is_string( $rec ) && strlen( $rec ) > 0 ) {
                $recommendations[] = $rec;
            }
        }
        $response['recommendations'] = $recommendations;

        // 🔥 FIX: Bereinige Uncertainties (ohne Arrow Functions)
        $uncertainties = array();
        foreach ( $response['uncertainties'] as $unc ) {
            if ( is_string( $unc ) && strlen( $unc ) > 0 ) {
                $uncertainties[] = $unc;
            }
        }
        $response['uncertainties'] = $uncertainties;

        return $response;
    }
}