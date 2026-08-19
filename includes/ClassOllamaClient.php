<?php
declare( strict_types = 1 );

namespace WPAIC;

defined( 'ABSPATH' ) || exit;

class OllamaClient {

    /**
     * Prüft ob Ollama erreichbar ist
     */
    public static function is_available(): bool {
        $host = trailingslashit( get_option( 'wpaic_ollama_host', 'http://192.168.0.194:11434' ) );
        $url  = $host . 'api/tags';
        
        $response = wp_remote_get( $url, array(
            'timeout' => 5,
            'headers' => array( 'Content-Type' => 'application/json' ),
        ) );
        
        if ( is_wp_error( $response ) ) {
            return false;
        }
        
        $code = wp_remote_retrieve_response_code( $response );
        return 200 === (int) $code;
    }

    /**
     * Ruft alle verfügbaren Modelle von Ollama ab
     */
    public static function get_models(): array {
        $host = trailingslashit( get_option( 'wpaic_ollama_host', 'http://192.168.0.194:11434' ) );
        $url  = $host . 'api/tags';
        
        $response = wp_remote_get( $url, array(
            'timeout' => 5,
            'headers' => array( 'Content-Type' => 'application/json' ),
        ) );
        
        if ( is_wp_error( $response ) ) {
            return array();
        }
        
        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== (int) $code ) {
            return array();
        }
        
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || empty( $data['models'] ) ) {
            return array();
        }
        
        $models = array();
        foreach ( $data['models'] as $model ) {
            $models[] = $model['name'];
        }
        
        return $models;
    }

    /**
     * Einzige HTTP-Anfrage an Ollama. Gibt decodiertes JSON oder WP_Error zurück.
     */
    public static function chat( array $messages, array $opts = array() ) {
        $host   = trailingslashit( get_option( 'wpaic_ollama_host', 'http://192.168.0.194:11434' ) );
        $model  = get_option( 'wpaic_ollama_model', 'llama3.2' );
        $url    = $host . 'api/chat';

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
            return new \WP_Error( 'wpaic_json', __( 'Konnte Anfrage nicht kodieren.', 'wp-ai-health-check' ) );
        }

        $response = wp_remote_post( $url, array(
            'method'      => 'POST',
            'timeout'     => 60,
            'redirection' => 0,
            'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
            'body'        => $body,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== (int) $code ) {
            return new \WP_Error(
                'wpaic_http',
                sprintf(
                    __( 'Ollama antwortete mit HTTP %d.', 'wp-ai-health-check' ),
                    $code
                ),
                array(
                    'status' => $code,
                    'body'   => wp_remote_retrieve_body( $response ),
                )
            );
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $decoded ) || empty( $decoded['message']['content'] ) ) {
            return new \WP_Error( 'wpaic_parse', __( 'Unerwartete Ollama-Antwort.', 'wp-ai-health-check' ) );
        }

        // content ist JSON-String (wegen format:'json') → nochmal decoden
        $inner = json_decode( $decoded['message']['content'], true );
        return is_array( $inner ) ? $inner : $decoded['message']['content'];
    }
}