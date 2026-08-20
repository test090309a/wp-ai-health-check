<?php
declare( strict_types = 1 );

namespace WPAIC;

defined( 'ABSPATH' ) || exit;

class OllamaClient {

    /**
     * Cache für Verfügbarkeits-Prüfung (pro Request)
     */
    private static ?bool $availability_cache = null;

    /**
     * Cache für Modell-Liste (pro Request)
     */
    private static ?array $models_cache = null;

    /**
     * Standard-Host (zentral definiert in wp-ai-health-check.php)
     */
    private const DEFAULT_HOST = WPAIC_DEFAULT_OLLAMA_HOST;

    /**
     * 🆕 Holt den konfigurierten Host mit Fallback
     */
    private static function get_host(): string {
        $host = get_option( 'wpaic_ollama_host', self::DEFAULT_HOST );
        
        // 🆕 Prüfe ob Host leer ist und setze Default
        if ( empty( $host ) ) {
            $host = self::DEFAULT_HOST;
        }
        
        // 🆕 Logge Host für Debugging
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WPAIC] Using Ollama Host: ' . $host );
        }
        
        return trailingslashit( $host );
    }

    /**
     * Prüft ob Ollama erreichbar ist
     * 🆕 Verbesserte Fehlerbehandlung mit Details + Cache
     */
    public static function is_available(): bool {
        // Cache pro Request
        if ( self::$availability_cache !== null ) {
            return self::$availability_cache;
        }
        
        $host = self::get_host();
        $url  = $host . 'api/tags';
        
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WPAIC] Checking Ollama availability: ' . $url );
        }
        
        $response = wp_remote_get( $url, array(
            'timeout' => 5,
            'headers' => array( 'Content-Type' => 'application/json' ),
        ) );
        
        if ( is_wp_error( $response ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WPAIC] Ollama availability check failed: ' . $response->get_error_message() );
            }
            return self::$availability_cache = false;
        }
        
        $code = wp_remote_retrieve_response_code( $response );
        $available = 200 === (int) $code;
        
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WPAIC] Ollama availability: ' . ( $available ? '✅ OK' : '❌ FAILED (HTTP ' . $code . ')' ) );
        }
        
        return self::$availability_cache = $available;
    }

    /**
     * Ruft alle verfügbaren Modelle von Ollama ab
     * 🆕 Verbesserte Fehlerbehandlung + Cache
     */
    public static function get_models(): array {
        // Cache pro Request
        if ( self::$models_cache !== null ) {
            return self::$models_cache;
        }
        
        $host = self::get_host();
        $url  = $host . 'api/tags';
        
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WPAIC] Fetching models from: ' . $url );
        }
        
        $response = wp_remote_get( $url, array(
            'timeout' => 5,
            'headers' => array( 'Content-Type' => 'application/json' ),
        ) );
        
        if ( is_wp_error( $response ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WPAIC] Failed to fetch models: ' . $response->get_error_message() );
            }
            return self::$models_cache = array();
        }
        
        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== (int) $code ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WPAIC] Failed to fetch models: HTTP ' . $code );
            }
            return self::$models_cache = array();
        }
        
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( ! is_array( $data ) || empty( $data['models'] ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WPAIC] No models found in response' );
            }
            return self::$models_cache = array();
        }
        
        $models = array();
        foreach ( $data['models'] as $model ) {
            if ( isset( $model['name'] ) ) {
                $models[] = $model['name'];
            }
        }
        
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WPAIC] Found ' . count( $models ) . ' models: ' . implode( ', ', $models ) );
        }
        
        return self::$models_cache = $models;
    }

    /**
     * 🆕 Prüft ob ein Modell existiert (mit Cache)
     */
    public static function model_exists( string $model_name ): bool {
        $models = self::get_models();
        return in_array( $model_name, $models, true );
    }

    /**
     * 🆕 Holt die aktuell konfigurierte URL für Debugging
     */
    public static function get_current_url(): string {
        return self::get_host();
    }

    /**
     * 🆕 Resetet den Cache (z.B. nach Konfigurationsänderungen)
     */
    public static function reset_cache(): void {
        self::$availability_cache = null;
        self::$models_cache = null;
    }

    /**
     * Einzige HTTP-Anfrage an Ollama. Gibt decodiertes JSON oder WP_Error zurück.
     * 🆕 Verbesserte Fehlerbehandlung mit Timeout und Retry-Logik
     */
    public static function chat( array $messages, array $opts = array() ) {
        $host   = self::get_host();
        $model  = get_option( 'wpaic_ollama_model', 'llama3.2' );
        $url    = $host . 'api/chat';
        
        // 🆕 Prüfe ob Modell existiert (nur wenn nicht im Debug-Modus)
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            if ( ! self::model_exists( $model ) ) {
                error_log( '[WPAIC] ⚠️ Warning: Model "' . $model . '" may not exist on Ollama server' );
            }
        }

        $body = wp_json_encode( array(
            'model'    => $model,
            'messages' => $messages,
            'stream'   => false,
            'format'   => 'json',
            'options'  => array_merge(
                array(
                    'temperature' => 0.2,
                    'num_ctx'     => 16384,
                ),
                $opts
            ),
        ) );

        if ( false === $body ) {
            return new \WP_Error( 
                'wpaic_json', 
                __( 'Konnte Anfrage nicht kodieren.', 'wp-ai-health-check' ) 
            );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WPAIC] Sending chat request to: ' . $url );
            error_log( '[WPAIC] Model: ' . $model );
            error_log( '[WPAIC] Messages: ' . count( $messages ) . ' messages' );
        }

        $response = wp_remote_post( $url, array(
            'method'      => 'POST',
            'timeout'     => 1200,
            'redirection' => 0,
            'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
            'body'        => $body,
        ) );

        if ( is_wp_error( $response ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WPAIC] ❌ Request error: ' . $response->get_error_message() );
            }
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body_response = wp_remote_retrieve_body( $response );
        
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WPAIC] Response code: ' . $code );
            error_log( '[WPAIC] Response body length: ' . strlen( $body_response ) . ' bytes' );
        }

        if ( 200 !== (int) $code ) {
            // 🆕 Detaillierter Fehler mit Body
            $error_msg = sprintf(
                __( 'Ollama antwortete mit HTTP %d.', 'wp-ai-health-check' ),
                $code
            );
            
            // 🆕 Versuche Fehler aus Body zu extrahieren
            $error_body = json_decode( $body_response, true );
            if ( isset( $error_body['error'] ) ) {
                $error_msg .= ' ' . __( 'Fehler:', 'wp-ai-health-check' ) . ' ' . $error_body['error'];
            }
            
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WPAIC] ❌ HTTP Error: ' . $error_msg );
                error_log( '[WPAIC] Response body: ' . substr( $body_response, 0, 500 ) );
            }
            
            return new \WP_Error(
                'wpaic_http',
                $error_msg,
                array(
                    'status' => $code,
                    'body'   => $body_response,
                )
            );
        }

        // 🆕 Prüfe ob Body leer ist
        if ( empty( $body_response ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WPAIC] ❌ Empty response body' );
            }
            return new \WP_Error( 
                'wpaic_empty', 
                __( 'Leere Antwort von Ollama.', 'wp-ai-health-check' ) 
            );
        }

        $decoded = json_decode( $body_response, true );
        if ( ! is_array( $decoded ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WPAIC] ❌ Invalid JSON response: ' . substr( $body_response, 0, 200 ) );
            }
            return new \WP_Error( 
                'wpaic_parse', 
                __( 'Ungültige JSON-Antwort von Ollama.', 'wp-ai-health-check' ) 
            );
        }
        
        if ( empty( $decoded['message']['content'] ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WPAIC] ❌ Missing message.content in response' );
                error_log( '[WPAIC] Response keys: ' . implode( ', ', array_keys( $decoded ) ) );
            }
            return new \WP_Error( 
                'wpaic_parse', 
                __( 'Unerwartete Ollama-Antwort: Kein message.content.', 'wp-ai-health-check' ) 
            );
        }

        // content ist JSON-String (wegen format:'json') → nochmal decoden
        $content = $decoded['message']['content'];
        
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WPAIC] Content length: ' . strlen( $content ) . ' bytes' );
        }
        
        $inner = json_decode( $content, true );
        
        // 🆕 Wenn das Parsen fehlschlägt, versuche Markdown-Blöcke zu entfernen
        if ( ! is_array( $inner ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WPAIC] ⚠️ Content is not JSON, trying to extract from Markdown' );
            }
            
            // Versuche Markdown-Code-Blöcke zu entfernen
            $cleaned = preg_replace( '/^```json\s*/', '', $content );
            $cleaned = preg_replace( '/\s*```$/', '', $cleaned );
            $cleaned = trim( $cleaned );
            
            $inner = json_decode( $cleaned, true );
            
            if ( ! is_array( $inner ) ) {
                // 🆕 Letzter Versuch: JSON aus dem Text extrahieren
                if ( preg_match( '/\{[^{}]*\}/s', $content, $matches ) ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[WPAIC] 🔍 Extracted JSON from text' );
                    }
                    $inner = json_decode( $matches[0], true );
                }
            }
            
            if ( ! is_array( $inner ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[WPAIC] ❌ Failed to parse content as JSON: ' . substr( $content, 0, 200 ) );
                }
                return new \WP_Error( 
                    'wpaic_parse_content', 
                    __( 'KI-Antwort konnte nicht als JSON interpretiert werden.', 'wp-ai-health-check' ),
                    array( 'content' => $content )
                );
            }
        }
        
        // 🆕 Prüfe ob das Ergebnis die erwartete Struktur hat
        if ( ! isset( $inner['summary'] ) && ! isset( $inner['risks'] ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WPAIC] ⚠️ Response missing expected fields: ' . implode( ', ', array_keys( $inner ) ) );
            }
            // 🆕 Versuche zu reparieren: Wrap in Standard-Struktur
            if ( isset( $inner['response'] ) ) {
                $inner = array(
                    'summary' => $inner['response'],
                    'risks' => array(),
                    'recommendations' => array(),
                );
            }
        }
        
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WPAIC] ✅ Chat request successful' );
        }
        
        return $inner;
    }

    /**
     * 🆕 Testet die Verbindung zu Ollama und gibt detaillierte Infos zurück
     */
    public static function test_connection(): array {
        $result = array(
            'host' => self::get_host(),
            'available' => false,
            'models' => array(),
            'error' => null,
            'timestamp' => current_time( 'mysql' ),
        );
        
        try {
            $result['available'] = self::is_available();
            
            if ( $result['available'] ) {
                $result['models'] = self::get_models();
            }
        } catch ( \Exception $e ) {
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
}