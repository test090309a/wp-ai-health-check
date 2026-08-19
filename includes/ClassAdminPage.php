<?php
declare( strict_types = 1 );

namespace WPAIC;

defined( 'ABSPATH' ) || exit;

class AdminPage {

    public function register_hooks(): void {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_init', array( Cron::class, 'maybe_schedule' ) );
    }

    public function add_menu(): void {
        add_management_page(
            __( 'KI-Zustandsanalyse', 'wp-ai-health-check' ),
            __( 'KI Health Check', 'wp-ai-health-check' ),
            'manage_options',
            'wp-ai-health-check',
            array( $this, 'render_page' )
        );
    }

    public function register_settings(): void {
        register_setting( 'wpaic_settings', 'wpaic_ollama_host', array(
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => 'http://192.168.0.194:11434',
        ) );
        
        register_setting( 'wpaic_settings', 'wpaic_ollama_model', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'llama3.2',
        ) );
        
        register_setting( 'wpaic_settings', 'wpaic_cron_enabled', array(
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ) );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        
        $last_raw = get_transient( 'wpaic_last_result' );
        $last_run = get_option( 'wpaic_last_run' );
        
        // 💡 JSON aus Markdown extrahieren - MEHRERE METHODEN
        $last = null;
        if (!empty($last_raw)) {
            if (is_string($last_raw)) {
                // Entferne Markdown
                $clean = preg_replace('/^```json\s*/', '', $last_raw);
                $clean = preg_replace('/\s*```$/', '', $clean);
                $clean = trim($clean);
                
                // Versuche JSON zu parsen
                $decoded = json_decode($clean, true);
                if (is_array($decoded)) {
                    $last = $decoded;
                } else {
                    // 🔧 Versuche abgeschnittenes JSON zu reparieren
                    // Suche nach dem letzten vollständigen Objekt
                    $fixed = $clean;
                    
                    // Zähle offene Klammern
                    $open_braces = substr_count($fixed, '{') - substr_count($fixed, '}');
                    $open_brackets = substr_count($fixed, '[') - substr_count($fixed, ']');
                    
                    // Füge fehlende schließende Klammern hinzu
                    if ($open_braces > 0 || $open_brackets > 0) {
                        // Versuche den letzten vollständigen Teil zu finden
                        $fixed = rtrim($fixed);
                        
                        // Wenn der String mit Komma endet, entferne es
                        if (substr($fixed, -1) === ',') {
                            $fixed = substr($fixed, 0, -1);
                        }
                        
                        // Füge fehlende Klammern hinzu
                        for ($i = 0; $i < $open_braces; $i++) {
                            $fixed .= '}';
                        }
                        for ($i = 0; $i < $open_brackets; $i++) {
                            $fixed .= ']';
                        }
                        
                        // Versuche erneut zu parsen
                        $decoded = json_decode($fixed, true);
                        if (is_array($decoded)) {
                            $last = $decoded;
                        }
                    }
                    
                    // Wenn immer noch nicht geparst werden kann, versuche mit Regex den JSON-Teil zu extrahieren
                    if (!is_array($last)) {
                        preg_match('/\{[^{}]*"summary"[^{}]*("[^"]*"|{[^{}]*})*.*\}/s', $clean, $matches);
                        if (!empty($matches[0])) {
                            $decoded = json_decode($matches[0], true);
                            if (is_array($decoded)) {
                                $last = $decoded;
                            }
                        }
                    }
                }
            } elseif (is_array($last_raw)) {
                $last = $last_raw;
            }
        }
        
        // Debug-Ausgabe (nur im Debug-Modus)
        if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) {
            echo '<div style="background:#f0f6fc;padding:12px 16px;margin:10px 0;border:1px solid #2271b1;border-radius:4px;font-size:13px;">';
            echo '<strong>🔍 Debug Info</strong><br>';
            echo 'Transient Typ: ' . gettype($last_raw) . '<br>';
            if (is_string($last_raw)) {
                echo 'Transient Länge: ' . strlen($last_raw) . ' Zeichen<br>';
                echo 'Erste 100 Zeichen: <code>' . esc_html(substr($last_raw, 0, 100)) . '...</code><br>';
            }
            echo 'Geparstes Array: ' . (is_array($last) ? '✅ ja' : '❌ nein') . '<br>';
            if (is_array($last)) {
                echo 'Summary: ' . (isset($last['summary']) ? '✅' : '❌') . '<br>';
                echo 'Risiken: ' . (isset($last['risks']) ? count($last['risks']) : '0') . '<br>';
                echo 'Empfehlungen: ' . (isset($last['recommendations']) ? count($last['recommendations']) : '0') . '<br>';
            } elseif (is_string($last_raw)) {
                echo 'JSON Error: ' . json_last_error_msg() . '<br>';
            }
            echo '</div>';
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'KI-Zustandsanalyse', 'wp-ai-health-check' ); ?></h1>
            
            <?php if ( $last_run ) : ?>
                <div class="notice notice-info">
                    <p>
                        <?php
                        printf(
                            /* translators: %s: Datum der letzten Analyse */
                            esc_html__( 'Letzte Analyse: %s', 'wp-ai-health-check' ),
                            esc_html( $last_run )
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Ollama-Konfiguration', 'wp-ai-health-check' ); ?></h2>
            <form method="post" action="options.php">
                <?php settings_fields( 'wpaic_settings' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="wpaic_ollama_host">
                                <?php esc_html_e( 'Ollama HTTP-URL', 'wp-ai-health-check' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="url" id="wpaic_ollama_host"
                                   name="wpaic_ollama_host"
                                   value="<?php echo esc_attr( get_option( 'wpaic_ollama_host' ) ); ?>"
                                   class="regular-text">
                            <p class="description">
                                <?php esc_html_e( 'Die URL unter der Ollama erreichbar ist.', 'wp-ai-health-check' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wpaic_ollama_model">
                                <?php esc_html_e( 'Modell', 'wp-ai-health-check' ); ?>
                            </label>
                        </th>
                        <td>
                            <?php
                            $current_model = get_option( 'wpaic_ollama_model', 'llama3.2' );
                            $models = \WPAIC\OllamaClient::get_models();
                            ?>
                            <select id="wpaic_ollama_model" name="wpaic_ollama_model" class="regular-text">
                                <?php if ( empty( $models ) ) : ?>
                                    <option value="<?php echo esc_attr( $current_model ); ?>">
                                        <?php echo esc_html( $current_model ); ?>
                                    </option>
                                    <option value="" disabled>
                                        <?php esc_html_e( '⚠️ Keine Modelle gefunden (Ollama erreichbar?)', 'wp-ai-health-check' ); ?>
                                    </option>
                                <?php else : ?>
                                    <?php foreach ( $models as $model ) : ?>
                                        <option value="<?php echo esc_attr( $model ); ?>" 
                                            <?php selected( $model, $current_model ); ?>>
                                            <?php echo esc_html( $model ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'Wähle ein installiertes Ollama-Modell.', 'wp-ai-health-check' ); ?>
                            </p>
                            <?php if ( ! empty( $models ) ) : ?>
                                <p class="description" style="color: #46b450;">
                                    ✅ <?php echo count( $models ); ?> Modelle verfügbar
                                </p>
                            <?php else : ?>
                                <p class="description" style="color: #d63638;">
                                    ⚠️ <?php esc_html_e( 'Keine Modelle gefunden. Stelle sicher, dass Ollama läuft und Modelle installiert sind.', 'wp-ai-health-check' ); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'Automatische Analyse', 'wp-ai-health-check' ); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpaic_cron_enabled" value="1"
                                       <?php checked( get_option( 'wpaic_cron_enabled' ) ); ?>>
                                <?php esc_html_e( 'Täglich automatisch analysieren', 'wp-ai-health-check' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Führt einmal täglich eine automatische Analyse durch.', 'wp-ai-health-check' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Speichern', 'wp-ai-health-check' ) ); ?>
            </form>

            <h2><?php esc_html_e( 'Analyse', 'wp-ai-health-check' ); ?></h2>
            <p>
                <button type="button" class="button button-primary" id="wpaic-run">
                    <?php esc_html_e( 'Jetzt analysieren', 'wp-ai-health-check' ); ?>
                </button>
                <span class="spinner" id="wpaic-spinner"></span>
            </p>

            <div id="wpaic-output">
                <?php if ( ! empty( $last ) && is_array( $last ) ) : ?>
                    
                    <?php if ( isset( $last['summary'] ) ) : ?>
                        <div class="notice notice-info" style="margin: 10px 0; border-left-color: #2271b1;">
                            <h3 style="margin-top: 0;">📋 Zusammenfassung</h3>
                            <p style="font-size: 14px;"><?php echo wp_kses_post( $last['summary'] ); ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ( ! empty( $last['risks'] ) ) : ?>
                        <div style="margin: 20px 0;">
                            <h3>⚠️ Risiken</h3>
                            <ul style="list-style-type: none; padding-left: 0;">
                            <?php foreach ( $last['risks'] as $risk ) : 
                                $level = strtolower($risk['level'] ?? 'low');
                                $color = match($level) {
                                    'critical', 'high' => '#d63638',
                                    'medium' => '#dba617',
                                    default => '#007cba'
                                };
                                $bg_color = match($level) {
                                    'critical', 'high' => '#fcf0f0',
                                    'medium' => '#fcf9f0',
                                    default => '#f0f6fc'
                                };
                                ?>
                                <li style="margin: 8px 0; padding: 12px 16px; background: <?php echo esc_attr($bg_color); ?>; border-radius: 4px; border-left: 4px solid <?php echo esc_attr($color); ?>;">
                                    <span style="color: <?php echo esc_attr($color); ?>; font-weight: bold; display: block; margin-bottom: 4px;">
                                        <?php echo esc_html( $risk['title'] ?? '' ); ?>
                                    </span>
                                    <span style="display: block; color: #444; font-size: 13px;">
                                        <?php echo esc_html( $risk['detail'] ?? '' ); ?>
                                    </span>
                                    <span style="display: inline-block; font-size: 10px; text-transform: uppercase; color: #fff; font-weight: bold; background: <?php echo esc_attr($color); ?>; padding: 1px 10px; border-radius: 10px; margin-top: 4px;">
                                        <?php echo esc_html( $level ); ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ( ! empty( $last['recommendations'] ) ) : ?>
                        <div style="margin: 20px 0;">
                            <h3>💡 Empfehlungen</h3>
                            <ol style="padding-left: 25px; margin: 10px 0;">
                            <?php foreach ( $last['recommendations'] as $rec ) : ?>
                                <li style="margin: 6px 0; padding: 4px 0;"><?php echo esc_html( $rec ); ?></li>
                            <?php endforeach; ?>
                            </ol>
                        </div>
                    <?php endif; ?>
                    
                <?php else : ?>
                    <div class="notice notice-warning">
                        <p><?php esc_html_e( 'Keine Ergebnisse vorhanden. Starten Sie eine Analyse.', 'wp-ai-health-check' ); ?></p>
                        <?php if (!empty($last_raw)) : ?>
                            <p style="font-size: 11px; color: #999; word-break: break-all;">
                                <?php esc_html_e( '(Debug: Rohdaten vorhanden, aber Format nicht erkannt)', 'wp-ai-health-check' ); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}