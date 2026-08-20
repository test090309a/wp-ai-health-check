<?php
/**
 * Plugin Name:       WP AI Health Check
 * Plugin URI:        https://test090309a.free.nf/dtf1606/artikel.php?id=4003
 * GitHub Plugin URI: https://github.com/test090309a/wp-ai-health-check
 * Description:       Prüft den WordPress-Zustand und analysiert ihn über eine lokale Ollama-HTTP-API.
 * Version:           0.3.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            JN
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-ai-health-check
 * Domain Path:       /languages
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

define( 'WPAIC_VERSION', '0.3.0' );
define( 'WPAIC_FILE', __FILE__ );
define( 'WPAIC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPAIC_URL', plugin_dir_url( __FILE__ ) );

// ============================================================
// 🔧 FIX: Default Ollama Host (zentrale Konstante)
// ============================================================
define( 'WPAIC_DEFAULT_OLLAMA_HOST', 'http://localhost:11434' );

// ============================================================
// 🔧 FIX: Hilfsfunktion für gecachte REST-Routen-Prüfung
// 🆕 FIX: is_admin() Check vor get_current_screen()
// ============================================================
/**
 * Prüft ob WPAIC REST-Routen registriert sind (mit Cache)
 */
function wpaic_has_rest_routes(): bool {
    static $cached = null;
    if ( $cached !== null ) {
        return $cached;
    }
    
    // 🔥 FIX: Nur im Admin-Kontext prüfen
    if ( ! is_admin() ) {
        return $cached = false;
    }
    
    $screen = get_current_screen();
    if ( ! $screen || ! in_array( $screen->id, array( 'tools_page_wp-ai-health-check', 'admin_page_wp-ai-health-check' ) ) ) {
        return $cached = false;
    }
    
    $routes = rest_get_server()->get_routes();
    foreach ( array_keys( $routes ) as $route ) {
        if ( strpos( $route, '/wpaic/' ) !== false ) {
            return $cached = true;
        }
    }
    return $cached = false;
}

// ============================================================
// 🔧 FIX: Hilfsfunktion für korrekte REST-URL (HTTP/HTTPS-kompatibel)
// ============================================================
/**
 * Gibt die korrekte REST-URL zurück (kompatibel mit HTTP/HTTPS)
 */
function wpaic_get_rest_url( string $endpoint = '' ): string {
    $rest_url = rest_url( 'wpaic/v1' . $endpoint );
    
    // 🔥 FIX: Erzwinge dasselbe Protokoll wie die aktuelle Seite
    $scheme = is_ssl() ? 'https' : 'http';
    $rest_url = set_url_scheme( $rest_url, $scheme );
    
    return $rest_url;
}

// ============================================================
// 🔧 FIX: Hilfsfunktion für korrekte Admin-AJAX-URL
// ============================================================
function wpaic_get_admin_ajax_url(): string {
    $url = admin_url( 'admin-ajax.php' );
    $scheme = is_ssl() ? 'https' : 'http';
    return set_url_scheme( $url, $scheme );
}

// Autoloader
spl_autoload_register( function ( string $class ): void {
    $prefix = 'WPAIC\\';
    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $path     = WPAIC_DIR . 'includes/Class' . str_replace( '\\', '/', $relative ) . '.php';
    if ( is_readable( $path ) ) {
        require_once $path;
    }
} );

// i18n
add_action( 'init', function (): void {
    load_plugin_textdomain(
        'wp-ai-health-check',
        false,
        dirname( plugin_basename( WPAIC_FILE ) ) . '/languages'
    );
} );

// ============================================================
// 🔧 FIX: Settings registrieren (auch im Fallback)
// 🆕 FIX: Statische Variable statt DB-Flag
// ============================================================
add_action( 'admin_init', function(): void {
    static $registered = false;
    if ( $registered ) {
        return;
    }
    
    register_setting( 'wpaic_settings', 'wpaic_ollama_host', array(
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => WPAIC_DEFAULT_OLLAMA_HOST,
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
    
    $registered = true;
}, 5 ); // Priorität 5 = vor AdminPage (Priorität 10)

// Tabelle bei jedem Load sicherstellen (dbDelta ist idempotent)
add_action( 'plugins_loaded', function (): void {
    if ( class_exists( 'WPAIC\\AnalysisStore' ) ) {
        \WPAIC\AnalysisStore::create_table();
    }
}, 0 );

// Activation / Deactivation Hooks
register_activation_hook( __FILE__, function (): void {
    if ( class_exists( 'WPAIC\\AnalysisStore' ) ) {
        \WPAIC\AnalysisStore::create_table();
    }
} );

// ============================================================
// 🆕 FIX: REST-API mit WP_DEBUG-Logging
// ============================================================
add_action( 'rest_api_init', function (): void {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[WPAIC] REST API wird initialisiert...' );
    }
    if ( class_exists( 'WPAIC\\RestController' ) ) {
        ( new \WPAIC\RestController() )->register_routes();
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WPAIC] REST API Routes registriert' );
        }
    } else {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WPAIC] RestController nicht gefunden!' );
        }
    }
}, 1 );

// ============================================================
// 🆕 FIX: Test-Endpunkt nur für Admins, kein ollama_host
// ============================================================
add_action( 'rest_api_init', function (): void {
    register_rest_route( 'wpaic/v1', '/test', array(
        'methods' => 'GET',
        'callback' => function() {
            return new \WP_REST_Response( array(
                'status' => 'ok',
                'message' => 'REST API funktioniert!',
                'timestamp' => current_time( 'mysql' ),
                'user_logged_in' => is_user_logged_in(),
                'ollama_available' => class_exists( 'WPAIC\\OllamaClient' ) ? \WPAIC\OllamaClient::is_available() : false,
            ), 200 );
        },
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        }
    ) );
}, 2 );

// ============================================================
// 🆕 FIX: WP_DEBUG Logging für REST-Fehler
// ============================================================
add_action( 'rest_api_init', function() {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        add_filter( 'rest_authentication_errors', function( $result ) {
            if ( is_wp_error( $result ) ) {
                error_log( '[WPAIC] REST Auth Error: ' . $result->get_error_message() );
            }
            return $result;
        } );
    }
} );

/**
 * ADMIN MENU - Nur einmal registrieren
 */
add_action( 'admin_menu', function() {
    if ( class_exists( 'WPAIC\\AdminPage' ) ) {
        return;
    }
    
    global $submenu;
    $menu_exists = false;
    
    if ( isset( $submenu['tools.php'] ) ) {
        foreach ( $submenu['tools.php'] as $item ) {
            if ( isset( $item[2] ) && $item[2] === 'wp-ai-health-check' ) {
                $menu_exists = true;
                break;
            }
        }
    }
    
    if ( ! $menu_exists ) {
        add_management_page(
            'KI-Zustandsanalyse',
            'KI Health Check',
            'manage_options',
            'wp-ai-health-check',
            'wpaic_render_admin_page'
        );
    }
}, 10 );

/**
 * FALLBACK: Rendering-Funktion außerhalb der Klasse (nur für Notfälle)
 */
function wpaic_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Keine Berechtigung.' );
    }
    
    if ( class_exists( 'WPAIC\\AdminPage' ) ) {
        $page = new \WPAIC\AdminPage();
        $page->render_page();
        return;
    }
    
    // 🔥 Enqueue Fallback JS
    wp_enqueue_script(
        'wpaic-admin-fallback',
        WPAIC_URL . 'assets/admin-fallback.js',
        array(),
        WPAIC_VERSION,
        true
    );
    
    // 🔥 FIX: Verwende die Hilfsfunktionen für korrekte URLs
    wp_localize_script( 'wpaic-admin-fallback', 'WPAIC_FALLBACK_CFG', array(
        'restUrl' => esc_url_raw( wpaic_get_rest_url( '/analyze' ) ),
        'nonce'   => wp_create_nonce( 'wp_rest' ),
    ) );
    
    // FALLBACK: Einfache HTML-Seite
    $host = get_option( 'wpaic_ollama_host', WPAIC_DEFAULT_OLLAMA_HOST );
    $model = get_option( 'wpaic_ollama_model', 'llama3.2' );
    $last = get_transient( 'wpaic_last_result' );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'KI-Zustandsanalyse', 'wp-ai-health-check' ); ?></h1>
        
        <div class="notice notice-info">
            <p>⚠️ AdminPage-Klasse nicht gefunden – Fallback-Modus aktiv.</p>
        </div>
        
        <div style="background:#f0f0f0;padding:10px;margin:10px 0;border:1px solid #ccc;border-radius:4px;">
            <h3>🛠️ Debug-Info</h3>
            <pre style="margin:0;font-size:12px;">
REST URL: <?php echo esc_url( wpaic_get_rest_url( '/analyze' ) ); ?>
Test URL: <?php echo esc_url( wpaic_get_rest_url( '/test' ) ); ?>
Nonce: <?php echo wp_create_nonce( 'wp_rest' ); ?>
Ollama Host: <?php echo esc_html( get_option( 'wpaic_ollama_host', WPAIC_DEFAULT_OLLAMA_HOST ) ); ?>
Model: <?php echo esc_html( get_option( 'wpaic_ollama_model', 'llama3.2' ) ); ?>
Ollama verfügbar: <?php echo class_exists( 'WPAIC\\OllamaClient' ) && \WPAIC\OllamaClient::is_available() ? '✅ Ja' : '❌ Nein'; ?>
User ID: <?php echo get_current_user_id(); ?>
User logged in: <?php echo is_user_logged_in() ? '✅ Ja' : '❌ Nein'; ?>
REST Routes: <?php 
$routes = rest_get_server()->get_routes();
$wpaic_routes = array_filter( array_keys( $routes ), function( $r ) { return strpos( $r, '/wpaic/' ) !== false; } );
echo ! empty( $wpaic_routes ) ? implode( ', ', $wpaic_routes ) : '❌ Keine WPAIC-Routen gefunden!';
?>
            </pre>
        </div>
        
        <h2>Ollama-Konfiguration</h2>
        <form method="post" action="options.php">
            <?php settings_fields( 'wpaic_settings' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="wpaic_ollama_host">Ollama URL</label></th>
                    <td>
                        <input type="url" id="wpaic_ollama_host" 
                               name="wpaic_ollama_host" 
                               value="<?php echo esc_attr( $host ); ?>" 
                               class="regular-text">
                        <p class="description">
                            <?php 
                            printf(
                                esc_html__( 'Die Ollama-URL. Standard: %s', 'wp-ai-health-check' ),
                                WPAIC_DEFAULT_OLLAMA_HOST
                            );
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wpaic_ollama_model">Modell</label></th>
                    <td>
                        <input type="text" id="wpaic_ollama_model" 
                               name="wpaic_ollama_model" 
                               value="<?php echo esc_attr( $model ); ?>" 
                               class="regular-text">
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Speichern', 'wp-ai-health-check' ) ); ?>
        </form>
        
        <h2>Analyse starten</h2>
        <p>
            <button type="button" class="button button-primary" id="wpaic-run">
                Jetzt analysieren
            </button>
            <span class="spinner" id="wpaic-spinner"></span>
        </p>
        
        <div id="wpaic-output">
            <?php if ( ! empty( $last ) && is_array( $last ) ) : ?>
                <div class="notice notice-info">
                    <pre><?php echo esc_html( wp_json_encode( $last, JSON_PRETTY_PRINT ) ); ?></pre>
                </div>
            <?php elseif ( ! empty( $last ) ) : ?>
                <div class="notice notice-info">
                    <pre><?php echo esc_html( print_r( $last, true ) ); ?></pre>
                </div>
            <?php else : ?>
                <p>Keine Ergebnisse vorhanden.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// ============================================================
// Admin AJAX-Fallback
// ============================================================
add_action( 'wp_ajax_wpaic_analyze_ajax', function() {
    check_ajax_referer( 'wp_rest', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Keine Berechtigung.', 403 );
    }
    
    if ( ! class_exists( 'WPAIC\\OllamaClient' ) || ! class_exists( 'WPAIC\\HealthCollector' ) ) {
        wp_send_json_error( 'Plugin-Klassen nicht geladen.', 500 );
    }
    
    if ( ! \WPAIC\OllamaClient::is_available() ) {
        wp_send_json_error( 'Ollama ist nicht erreichbar (Host: ' . get_option( 'wpaic_ollama_host', WPAIC_DEFAULT_OLLAMA_HOST ) . ')', 503 );
    }
    
    $state = \WPAIC\HealthCollector::collect();
    $start = microtime( true );
    
    $system = "Du bist ein erfahrener WordPress-Sicherheits-Experte. Analysiere die bereitgestellten Daten.\n"
        . "STRICT RULES:\n"
        . "1. NUR die bereitgestellten Daten verwenden. Keine Annahmen treffen.\n"
        . "2. KEINE Upgrade-Empfehlungen abgeben, es sei denn die Version ist explizit veraltet.\n"
        . "3. KEINE Risiken halluzinieren. Nur erwähnen, was in den Daten sichtbar ist.\n"
        . "4. NUR gültiges JSON zurückgeben. Kein Markdown, kein Text.\n"
        . "5. Schema: { \"summary\": \"Ein Satz\", \"risks\": [{\"level\": \"low|medium|high|critical\", \"title\": \"Titel\", \"detail\": \"Erklärung\"}], \"recommendations\": [\"Liste\"] }\n"
        . "6. Deutsch für alle Textfelder.\n"
        . "7. Max. 8 Risiken, max. 5 Empfehlungen.\n";
    
    $messages = array(
        array( 'role' => 'system', 'content' => $system ),
        array( 'role' => 'user', 'content' => wp_json_encode( $state, JSON_PRETTY_PRINT ) ),
    );
    
    $ai = \WPAIC\OllamaClient::chat( $messages );
    $duration = (int) round( ( microtime( true ) - $start ) * 1000 );
    
    if ( is_wp_error( $ai ) ) {
        wp_send_json_error( 'Ollama-Fehler: ' . $ai->get_error_message(), 502 );
    }
    
    set_transient( 'wpaic_last_result', $ai, DAY_IN_SECONDS );
    update_option( 'wpaic_last_run', current_time( 'mysql' ) );
    
    if ( class_exists( 'WPAIC\\AnalysisStore' ) ) {
        \WPAIC\AnalysisStore::insert( array(
            'run_at'        => current_time( 'mysql' ),
            'model'         => get_option( 'wpaic_ollama_model', '' ),
            'duration_ms'   => $duration,
            'status'        => 'ok',
            'error_message' => null,
            'result_json'   => wp_json_encode( $ai ),
        ) );
    }
    
    wp_send_json_success( array(
        'result' => $ai,
        'duration_ms' => $duration,
    ) );
} );

// ============================================================
// "Fix Permalinks" Admin-Notice (mit Cache)
// ============================================================
add_action( 'admin_notices', function() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    
    $screen = get_current_screen();
    if ( ! $screen || ! in_array( $screen->id, array( 'tools_page_wp-ai-health-check', 'admin_page_wp-ai-health-check' ) ) ) {
        return;
    }
    
    if ( ! wpaic_has_rest_routes() ) {
        ?>
        <div class="notice notice-warning">
            <p>
                <strong>⚠️ WPAIC REST-API nicht gefunden.</strong>
                <?php esc_html_e( 'Bitte speichere die Permalinks unter', 'wp-ai-health-check' ); ?>
                <a href="<?php echo admin_url( 'options-permalink.php' ); ?>">
                    <?php esc_html_e( 'Einstellungen > Permalinks', 'wp-ai-health-check' ); ?>
                </a>
                <?php esc_html_e( 'neu ab.', 'wp-ai-health-check' ); ?>
            </p>
            <p>
                <a href="<?php echo admin_url( 'options-permalink.php' ); ?>" class="button button-primary">
                    <?php esc_html_e( 'Permalinks speichern', 'wp-ai-health-check' ); ?>
                </a>
                <button onclick="location.reload()" class="button">
                    <?php esc_html_e( 'Seite neu laden', 'wp-ai-health-check' ); ?>
                </button>
            </p>
        </div>
        <?php
    }
} );

// ============================================================
// 🆕 FIX: Assets für Admin-Seite mit korrekten URLs
// ============================================================
add_action( 'admin_enqueue_scripts', function ( string $hook ): void {
    if ( 'tools_page_wp-ai-health-check' !== $hook && 'admin_page_wp-ai-health-check' !== $hook ) {
        return;
    }
    wp_enqueue_style( 'wpaic-admin', WPAIC_URL . 'assets/admin.css', array(), WPAIC_VERSION );
    wp_enqueue_script( 'wpaic-admin', WPAIC_URL . 'assets/admin.js', array( 'wp-api', 'wp-i18n' ), WPAIC_VERSION, true );
    
    // 🔥 FIX: Verwende die Hilfsfunktionen für korrekte URLs
    wp_localize_script( 'wpaic-admin', 'WPAIC_CFG', array(
        'restUrl' => esc_url_raw( wpaic_get_rest_url() ),
        'adminAjax' => esc_url_raw( wpaic_get_admin_ajax_url() ),
        'nonce'   => wp_create_nonce( 'wp_rest' ),
        'ajaxNonce' => wp_create_nonce( 'wp_rest' ),
        'debug' => array(
            'routes' => array_keys( rest_get_server()->get_routes() ),
            'ollama_host' => get_option( 'wpaic_ollama_host', WPAIC_DEFAULT_OLLAMA_HOST ),
            'ollama_available' => class_exists( 'WPAIC\\OllamaClient' ) ? \WPAIC\OllamaClient::is_available() : false,
        ),
        'i18n'    => array(
            'running'  => __( 'Analyse läuft…', 'wp-ai-health-check' ),
            'failed'   => __( 'Analyse fehlgeschlagen.', 'wp-ai-health-check' ),
            'noResult' => __( 'Keine Ergebnisse vorhanden.', 'wp-ai-health-check' ),
        ),
    ) );
} );

// Komponenten booten
add_action( 'plugins_loaded', function (): void {
    if ( class_exists( 'WPAIC\\AdminPage' ) ) {
        ( new \WPAIC\AdminPage() )->register_hooks();
    }
    if ( class_exists( 'WPAIC\\Cron' ) ) {
        ( new \WPAIC\Cron() )->register_hooks();
    }
    if ( class_exists( 'WPAIC\\DashboardWidget' ) ) {
        ( new \WPAIC\DashboardWidget() )->register_hooks();
    }
} );

// Cron cleanup
register_deactivation_hook( __FILE__, function (): void {
    if ( class_exists( 'WPAIC\\Cron' ) ) {
        \WPAIC\Cron::clear_scheduled();
    }
} );

// ============================================================
// 🆕 NEU: Uninstall Hook für saubere Deinstallation
// 🔥 FIX: Kein Closure verwenden (WordPress serialisiert)
// ============================================================
register_uninstall_hook( __FILE__, 'wpaic_uninstall_cleanup' );

/**
 * Uninstall-Cleanup-Funktion (muss separater Funktionsname sein,
 * da WordPress register_uninstall_hook serialisiert)
 */
function wpaic_uninstall_cleanup(): void {
    // Optionen löschen
    delete_option( 'wpaic_ollama_host' );
    delete_option( 'wpaic_ollama_model' );
    delete_option( 'wpaic_cron_enabled' );
    delete_option( 'wpaic_last_run' );
    
    // Transient löschen
    delete_transient( 'wpaic_last_result' );
    
    // Cron aufräumen
    wp_clear_scheduled_hook( 'wpaic_daily_check' );
    
    // Datenbanktabelle löschen
    global $wpdb;
    $table = $wpdb->prefix . 'wpaic_analyses';
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}