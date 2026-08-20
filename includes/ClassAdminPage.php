<?php

declare(strict_types=1);

namespace WPAIC;

defined('ABSPATH') || exit;

class AdminPage
{

    public function register_hooks(): void
    {
        \add_action('admin_menu', array($this, 'add_menu'));
        \add_action('admin_init', array($this, 'register_settings'));
        \add_action('admin_init', array(Cron::class, 'maybe_schedule'));
        \add_action('wp_ajax_wpaic_delete_analysis', array($this, 'ajax_delete_analysis'));

        // 🔥 NEU: AJAX für sofortigen Modellwechsel ohne Speichern
        \add_action('wp_ajax_wpaic_set_model', array($this, 'ajax_set_model'));

        // 🔥 NEU: AJAX für Historie-Bulk-Aktionen
        \add_action('wp_ajax_wpaic_delete_all_analyses', array($this, 'ajax_delete_all_analyses'));
        \add_action('wp_ajax_wpaic_delete_old_analyses', array($this, 'ajax_delete_old_analyses'));
    }

    public function add_menu(): void
    {
        add_management_page(
            __('KI-Zustandsanalyse', 'wp-ai-health-check'),
            __('KI Health Check', 'wp-ai-health-check'),
            'manage_options',
            'wp-ai-health-check',
            array($this, 'render_page')
        );
    }

    public function register_settings(): void
    {
        register_setting('wpaic_settings', 'wpaic_ollama_host', array(
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => WPAIC_DEFAULT_OLLAMA_HOST,
        ));

        register_setting('wpaic_settings', 'wpaic_ollama_model', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'qwen2.5:1.5b',
        ));

        register_setting('wpaic_settings', 'wpaic_cron_enabled', array(
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ));
    }

    /**
     * AJAX-Handler für sofortigen Modellwechsel (kein Speichern nötig)
     */
    public function ajax_set_model(): void
    {
        // 🔥 FIX: _wpnonce statt nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'wp_rest')) {
            wp_send_json_error('Ungültige Nonce.', 403);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung.', 403);
        }

        $model = sanitize_text_field($_POST['model'] ?? '');
        if (empty($model)) {
            wp_send_json_error('Kein Modell übergeben.', 400);
        }

        // Prüfe ob Modell auf Ollama existiert
        $models = \WPAIC\OllamaClient::get_models();
        if (!in_array($model, $models, true)) {
            wp_send_json_error('Modell nicht auf Ollama verfügbar.', 404);
        }

        update_option('wpaic_ollama_model', $model);

        // 🔥 Cache zurücksetzen nach Modellwechsel
        \WPAIC\OllamaClient::reset_cache();

        wp_send_json_success(array('model' => $model));
    }

    /**
     * AJAX-Handler zum Löschen einzelner Analysen.
     */
    public function ajax_delete_analysis(): void
    {
        // 🔥 FIX: _wpnonce statt nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'wp_rest')) {
            wp_send_json_error('Ungültige Nonce.', 403);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Keine Berechtigung.', 'wp-ai-health-check'), 403);
        }

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            wp_send_json_error(__('Ungültige ID.', 'wp-ai-health-check'), 400);
        }

        global $wpdb;
        $table = AnalysisStore::get_table_name();
        $deleted = $wpdb->delete($table, array('id' => $id));

        if ($deleted) {
            wp_send_json_success(array('id' => $id));
        } else {
            wp_send_json_error(__('Löschen fehlgeschlagen.', 'wp-ai-health-check'), 500);
        }
    }

    /**
     * 🔥 AJAX-Handler: Alle Analysen löschen
     * 🔥 FIX: TRUNCATE statt DELETE mit leerem WHERE
     */
    public function ajax_delete_all_analyses(): void
    {
        // 🔥 FIX: _wpnonce statt nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'wp_rest')) {
            wp_send_json_error('Ungültige Nonce.', 403);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung.', 403);
        }

        global $wpdb;
        $table = AnalysisStore::get_table_name();

        // 🔥 FIX: TRUNCATE statt DELETE mit leerem WHERE
        $deleted = $wpdb->query("TRUNCATE TABLE {$table}");

        // Fallback: Wenn TRUNCATE nicht funktioniert, DELETE mit WHERE 1=1
        if (false === $deleted) {
            $deleted = $wpdb->query("DELETE FROM {$table} WHERE 1=1");
        }

        wp_send_json_success(array('deleted' => (int) $deleted));
    }

    /**
     * 🔥 AJAX-Handler: Analysen älter als X Tage löschen
     */
    public function ajax_delete_old_analyses(): void
    {
        // 🔥 FIX: _wpnonce statt nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'wp_rest')) {
            wp_send_json_error('Ungültige Nonce.', 403);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung.', 403);
        }

        $days = (int) ($_POST['days'] ?? 30);
        if ($days < 1) {
            wp_send_json_error('Ungültige Anzahl Tage.', 400);
        }

        global $wpdb;
        $table = AnalysisStore::get_table_name();
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE run_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );

        wp_send_json_success(array('deleted' => (int) $deleted));
    }

    /**
     * 🔥 Holt dynamisch alle Modelle von Ollama und filtert inkompatible
     */
    private function get_compatible_models(): array
    {
        $all_models = \WPAIC\OllamaClient::get_models();
        if (empty($all_models)) {
            return array();
        }

        $compatible = array();
        foreach ($all_models as $model) {
            $name = strtolower($model);

            // ❌ Inkompatible Modelle ausschließen
            if (strpos($name, 'ornith') !== false) continue;
            if (strpos($name, 'glm') !== false) continue;
            if (strpos($name, 'llava') !== false) continue;
            if (strpos($name, 'vision') !== false) continue;
            if (strpos($name, 'embed') !== false) continue;
            if (strpos($name, 'minilm') !== false) continue;
            if (strpos($name, 'bge') !== false) continue;
            if (strpos($name, 'claude') !== false) continue;
            if (strpos($name, 'tesleum') !== false) continue;
            if (strpos($name, 'sqlcoder') !== false) continue;
            if (strpos($name, 'functiongemma') !== false) continue;
            if (strpos($name, 'arctic-embed') !== false) continue;
            if (strpos($name, 'nomic-embed') !== false) continue;

            // ✅ Alle anderen sind kompatibel
            $compatible[] = $model;
        }

        // 🔥 Sortiere nach Priorität (schnelle/kleine Modelle zuerst)
        usort($compatible, function ($a, $b) {
            $priority_a = $this->get_model_priority($a);
            $priority_b = $this->get_model_priority($b);
            return $priority_a <=> $priority_b;
        });

        return $compatible;
    }

    /**
     * 🔥 Priorität eines Modells (kleiner = besser)
     */
    private function get_model_priority(string $model): int
    {
        $model_lower = strtolower($model);

        // 🏆 Top-Priorität (sehr kleine Modelle)
        if (strpos($model_lower, '1.5b') !== false) return 1;
        if (strpos($model_lower, '1.2b') !== false) return 2;
        if (strpos($model_lower, '3b') !== false) return 3;

        // ⭐ Gute Balance
        if (strpos($model_lower, '7b') !== false) return 5;
        if (strpos($model_lower, '8b') !== false) return 6;
        if (strpos($model_lower, '9b') !== false) return 7;

        // ✅ Größere Modelle
        if (strpos($model_lower, '13b') !== false) return 10;
        if (strpos($model_lower, '14b') !== false) return 11;

        // 📦 Der Rest
        return 50;
    }

    /**
     * 🔥 Gibt Emoji basierend auf Priorität zurück
     */
    private function get_priority_emoji(string $model): string
    {
        $priority = $this->get_model_priority($model);
        if ($priority <= 3) return '🏆';
        if ($priority <= 6) return '⭐';
        if ($priority <= 10) return '✅';
        return '📦';
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Letzte Analyse aus der Datenbank holen
        $last_db = AnalysisStore::latest();
        $last_run = $last_db ? $last_db['run_at'] : null;

        $last = null;
        if ($last_db && !empty($last_db['result_json'])) {
            $last = $last_db['result_json'];
        } elseif ($last_db && is_string($last_db['result_json'])) {
            $last = json_decode($last_db['result_json'], true);
        }

        if (empty($last)) {
            $last_raw = get_transient('wpaic_last_result');
            if (!empty($last_raw)) {
                $last = $this->parse_last_result($last_raw);
            }
        }

        $history = AnalysisStore::find(20);

        // 🔥 Modelle dynamisch von Ollama holen
        $current_model = get_option('wpaic_ollama_model', 'qwen2.5:1.5b');
        $compatible_models = $this->get_compatible_models();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('KI-Zustandsanalyse', 'wp-ai-health-check'); ?></h1>

            <?php if ($last_run) : ?>
                <div class="notice notice-info">
                    <p>
                        <?php
                        printf(
                            esc_html__('Letzte Analyse: %s', 'wp-ai-health-check'),
                            esc_html($last_run)
                        );
                        if ($last_db) {
                            echo ' ' . esc_html__('(ID:', 'wp-ai-health-check');
                            echo ' #' . esc_html((string) $last_db['id']);
                            echo ', ';
                            echo esc_html__('Modell:', 'wp-ai-health-check');
                            echo ' ' . esc_html($last_db['model']);
                            echo ', ';
                            echo esc_html__('Dauer:', 'wp-ai-health-check');
                            echo ' ' . esc_html((string) $last_db['duration_ms']) . ' ms';
                            echo ')';
                        }
                        ?>
                    </p>
                </div>
            <?php else : ?>
                <div class="notice notice-warning">
                    <p><?php esc_html_e('Noch keine Analyse durchgeführt.', 'wp-ai-health-check'); ?></p>
                </div>
            <?php endif; ?>

            <h2><?php esc_html_e('Ollama-Konfiguration', 'wp-ai-health-check'); ?></h2>
            <form method="post" action="options.php">
                <?php settings_fields('wpaic_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="wpaic_ollama_host">
                                <?php esc_html_e('Ollama HTTP-URL', 'wp-ai-health-check'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="url" id="wpaic_ollama_host"
                                name="wpaic_ollama_host"
                                value="<?php echo esc_attr(get_option('wpaic_ollama_host')); ?>"
                                class="regular-text">
                            <p class="description">
                                <?php esc_html_e('Die URL unter der Ollama erreichbar ist.', 'wp-ai-health-check'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="wpaic_ollama_model">
                                <?php esc_html_e('Modell', 'wp-ai-health-check'); ?>
                            </label>
                        </th>
                        <td>
                            <select id="wpaic_ollama_model" name="wpaic_ollama_model" class="regular-text">
                                <?php if (empty($compatible_models)) : ?>
                                    <option value="<?php echo esc_attr($current_model); ?>">
                                        <?php echo esc_html($current_model); ?>
                                    </option>
                                    <option value="" disabled>
                                        <?php esc_html_e('⚠️ Keine kompatiblen Modelle gefunden', 'wp-ai-health-check'); ?>
                                    </option>
                                <?php else : ?>
                                    <?php foreach ($compatible_models as $model) :
                                        $emoji = $this->get_priority_emoji($model);
                                    ?>
                                        <option value="<?php echo esc_attr($model); ?>"
                                            <?php selected($model, $current_model); ?>>
                                            <?php echo esc_html($emoji . ' ' . $model); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Wähle ein kompatibles Ollama-Modell. Die Liste wird automatisch aktualisiert.', 'wp-ai-health-check'); ?>
                            </p>
                            <p class="description" id="wpaic-model-status" style="color: #46b450;">
                                ✅ <?php echo count($compatible_models); ?> kompatible Modelle gefunden
                            </p>
                            <p class="description" style="color: #666; font-size: 12px;">
                                💡 Modell wird sofort aktiviert – kein Speichern nötig.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php esc_html_e('Automatische Analyse', 'wp-ai-health-check'); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpaic_cron_enabled" value="1"
                                    <?php checked(get_option('wpaic_cron_enabled')); ?>>
                                <?php esc_html_e('Täglich automatisch analysieren', 'wp-ai-health-check'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Führt einmal täglich eine automatische Analyse durch.', 'wp-ai-health-check'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Speichern', 'wp-ai-health-check')); ?>
            </form>

            <h2><?php esc_html_e('Analyse', 'wp-ai-health-check'); ?></h2>
            <p>
                <button type="button" class="button button-primary" id="wpaic-run">
                    <?php esc_html_e('Jetzt analysieren', 'wp-ai-health-check'); ?>
                </button>
                <span class="spinner" id="wpaic-spinner"></span>
            </p>

            <div id="wpaic-output">
                <?php if (!empty($last) && is_array($last)) : ?>

                    <?php if (isset($last['summary'])) : ?>
                        <div class="notice notice-info" style="margin: 10px 0; border-left-color: #2271b1;">
                            <h3 style="margin-top: 0;">📋 Zusammenfassung</h3>
                            <p style="font-size: 14px;"><?php echo wp_kses_post($last['summary']); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($last['risks'])) : ?>
                        <div style="margin: 20px 0;">
                            <h3>⚠️ Risiken</h3>
                            <ul style="list-style-type: none; padding-left: 0;">
                                <?php foreach ($last['risks'] as $risk) :
                                    $level = strtolower($risk['level'] ?? 'low');
                                    $color = match ($level) {
                                        'critical', 'high' => '#d63638',
                                        'medium' => '#dba617',
                                        default => '#007cba'
                                    };
                                    $bg_color = match ($level) {
                                        'critical', 'high' => '#fcf0f0',
                                        'medium' => '#fcf9f0',
                                        default => '#f0f6fc'
                                    };
                                ?>
                                    <li style="margin: 8px 0; padding: 12px 16px; background: <?php echo esc_attr($bg_color); ?>; border-radius: 4px; border-left: 4px solid <?php echo esc_attr($color); ?>;">
                                        <span style="color: <?php echo esc_attr($color); ?>; font-weight: bold; display: block; margin-bottom: 4px;">
                                            <?php echo esc_html($risk['title'] ?? ''); ?>
                                        </span>
                                        <span style="display: block; color: #444; font-size: 13px;">
                                            <?php echo esc_html($risk['detail'] ?? ''); ?>
                                        </span>
                                        <span style="display: inline-block; font-size: 10px; text-transform: uppercase; color: #fff; font-weight: bold; background: <?php echo esc_attr($color); ?>; padding: 1px 10px; border-radius: 10px; margin-top: 4px;">
                                            <?php echo esc_html($level); ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($last['recommendations'])) : ?>
                        <div style="margin: 20px 0;">
                            <h3>💡 Empfehlungen</h3>
                            <ol style="padding-left: 25px; margin: 10px 0;">
                                <?php foreach ($last['recommendations'] as $rec) : ?>
                                    <li style="margin: 6px 0; padding: 4px 0;"><?php echo esc_html($rec); ?></li>
                                <?php endforeach; ?>
                            </ol>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($last['uncertainties'])) : ?>
                        <div style="margin: 20px 0; padding: 12px 16px; background: #fcf9f0; border-radius: 4px; border-left: 4px solid #dba617;">
                            <h3>🤔 Unsicherheiten</h3>
                            <ul style="padding-left: 20px; margin: 5px 0;">
                                <?php foreach ($last['uncertainties'] as $uncertainty) : ?>
                                    <li style="margin: 4px 0;"><?php echo esc_html($uncertainty); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                <?php else : ?>
                    <div class="notice notice-warning">
                        <p><?php esc_html_e('Keine Ergebnisse vorhanden. Starten Sie eine Analyse.', 'wp-ai-health-check'); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Historie -->
            <h2><?php esc_html_e('Analyse-Historie', 'wp-ai-health-check'); ?></h2>
            <?php if (!empty($history)) : ?>
                <div style="margin-bottom: 15px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                    <button type="button" class="button" id="wpaic-delete-all" style="color: #d63638; border-color: #d63638;">
                        🗑️ <?php esc_html_e('Alle löschen', 'wp-ai-health-check'); ?>
                    </button>
                    <button type="button" class="button" id="wpaic-delete-old">
                        🕐 <?php esc_html_e('Älter als 30 Tage löschen', 'wp-ai-health-check'); ?>
                    </button>
                    <span id="wpaic-delete-status" style="margin-left: 10px; font-size: 13px;"></span>
                </div>

                <table class="wp-list-table widefat fixed striped" id="wpaic-history-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('ID', 'wp-ai-health-check'); ?></th>
                            <th><?php esc_html_e('Datum', 'wp-ai-health-check'); ?></th>
                            <th><?php esc_html_e('Modell', 'wp-ai-health-check'); ?></th>
                            <th><?php esc_html_e('Dauer', 'wp-ai-health-check'); ?></th>
                            <th><?php esc_html_e('Status', 'wp-ai-health-check'); ?></th>
                            <th><?php esc_html_e('Aktionen', 'wp-ai-health-check'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $entry) : ?>
                            <tr id="wpaic-history-row-<?php echo esc_attr((string) $entry['id']); ?>">
                                <td><?php echo esc_html((string) $entry['id']); ?></td>
                                <td><?php echo esc_html($entry['run_at']); ?></td>
                                <td><?php echo esc_html($entry['model']); ?></td>
                                <td><?php echo esc_html((string) $entry['duration_ms']); ?> ms</td>
                                <td>
                                    <?php if ('ok' === $entry['status']) : ?>
                                        <span class="wpaic-status-ok">✅ OK</span>
                                    <?php else : ?>
                                        <span class="wpaic-status-error">❌ Fehler</span>
                                        <?php if (!empty($entry['error_message'])) : ?>
                                            <br><small style="color:#d63638;"><?php echo esc_html($entry['error_message']); ?></small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="button wpaic-delete-btn" data-id="<?php echo esc_attr((string) $entry['id']); ?>">
                                        <?php esc_html_e('Löschen', 'wp-ai-health-check'); ?>
                                    </button>
                                    <?php if ($entry['result_json']) : ?>
                                        <button type="button" class="button wpaic-view-btn" data-id="<?php echo esc_attr((string) $entry['id']); ?>">
                                            <?php esc_html_e('Anzeigen', 'wp-ai-health-check'); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="wpaic-no-data">
                    <?php esc_html_e('Noch keine Analysen in der Datenbank.', 'wp-ai-health-check'); ?>
                </p>
            <?php endif; ?>

            <!-- Detail-Ansicht (modal) -->
            <div id="wpaic-detail-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:100000; align-items:center; justify-content:center;">
                <div style="background:#fff; border-radius:6px; padding:20px; max-width:800px; width:90%; max-height:80vh; overflow:auto; position:relative;">
                    <button type="button" class="wpaic-modal-close" style="position:absolute; top:10px; right:10px; background:none; border:none; font-size:20px; cursor:pointer;">&times;</button>
                    <div id="wpaic-detail-content"></div>
                </div>
            </div>
        </div>

        <script>
            (function() {
                const cfg = window.WPAIC_CFG || {};

                if (!cfg.restUrl) {
                    cfg.restUrl = '<?php echo esc_url_raw(rest_url('wpaic/v1')); ?>';
                }
                if (!cfg.nonce) {
                    // 🔥 FIX: WPAIC_REST_NONCE existiert nicht mehr → dynamisch generieren
                    cfg.nonce = '<?php echo wp_create_nonce('wp_rest'); ?>';
                }
                if (!cfg.i18n) {
                    cfg.i18n = {
                        running: 'Analyse läuft…',
                        failed: 'Analyse fehlgeschlagen.',
                        noResult: 'Keine Ergebnisse vorhanden.'
                    };
                }

                console.log('🔍 Inline Script Config:', {
                    restUrl: cfg.restUrl,
                    nonce: cfg.nonce ? cfg.nonce.substring(0, 10) + '...' : '❌ MISSING'
                });

                // 🔥 FIX: Verwende admin-ajax.php für alle AJAX-Requests
                const ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';

                const runBtn = document.getElementById('wpaic-run');
                const output = document.getElementById('wpaic-output');
                const spinner = document.getElementById('wpaic-spinner');
                const deleteStatus = document.getElementById('wpaic-delete-status');

                // ============================================================
                // 🔥 FIX: Modellwechsel ohne Speichern (AJAX)
                // ============================================================
                const modelSelect = document.getElementById('wpaic_ollama_model');
                const modelStatus = document.getElementById('wpaic-model-status');

                if (modelSelect) {
                    modelSelect.addEventListener('change', function() {
                        const model = this.value;
                        const previousModel = this.dataset.previousValue || this.value;

                        if (!model) return;

                        if (modelStatus) {
                            modelStatus.innerHTML = '⏳ Modell wird aktiviert...';
                            modelStatus.style.color = '#dba617';
                        }

                        fetch(ajaxUrl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: new URLSearchParams({
                                    action: 'wpaic_set_model',
                                    _wpnonce: cfg.nonce, // 🔥 FIX: nonce → _wpnonce
                                    model: model
                                })
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    if (modelStatus) {
                                        modelStatus.innerHTML = '✅ Modell gewechselt zu: <strong>' + data.data.model + '</strong>';
                                        modelStatus.style.color = '#46b450';
                                    }
                                    this.dataset.previousValue = model;
                                    console.log('✅ Modell gewechselt zu:', data.data.model);

                                    modelStatus.style.transition = 'opacity 0.3s';
                                    modelStatus.style.opacity = '0.5';
                                    setTimeout(() => {
                                        modelStatus.style.opacity = '1';
                                    }, 300);
                                } else {
                                    if (modelStatus) {
                                        modelStatus.innerHTML = '❌ Fehler: ' + (data.data || 'Unbekannt');
                                        modelStatus.style.color = '#d63638';
                                    }
                                    this.value = previousModel;
                                }
                            })
                            .catch(err => {
                                console.error('❌ Modellwechsel fehlgeschlagen:', err);
                                if (modelStatus) {
                                    modelStatus.innerHTML = '❌ Netzwerkfehler beim Modellwechsel';
                                    modelStatus.style.color = '#d63638';
                                }
                                this.value = previousModel;
                            });
                    });

                    modelSelect.dataset.previousValue = modelSelect.value;
                }

                // ============================================================
                // 🔥 FIX: Alle löschen
                // ============================================================
                document.getElementById('wpaic-delete-all')?.addEventListener('click', function() {
                    if (!confirm('⚠️ Alle Analysen wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden!')) return;

                    this.disabled = true;
                    if (deleteStatus) {
                        deleteStatus.innerHTML = '⏳ Lösche alle...';
                        deleteStatus.style.color = '#dba617';
                    }

                    fetch(ajaxUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: new URLSearchParams({
                                action: 'wpaic_delete_all_analyses',
                                _wpnonce: cfg.nonce // 🔥 FIX: nonce → _wpnonce
                            })
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                if (deleteStatus) {
                                    deleteStatus.innerHTML = '✅ ' + data.data.deleted + ' Einträge gelöscht! Seite wird neu geladen...';
                                    deleteStatus.style.color = '#46b450';
                                }
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                if (deleteStatus) {
                                    deleteStatus.innerHTML = '❌ Fehler: ' + (data.data || 'Unbekannt');
                                    deleteStatus.style.color = '#d63638';
                                }
                                this.disabled = false;
                            }
                        })
                        .catch(err => {
                            console.error('❌ Fehler:', err);
                            if (deleteStatus) {
                                deleteStatus.innerHTML = '❌ Netzwerkfehler: ' + err.message;
                                deleteStatus.style.color = '#d63638';
                            }
                            this.disabled = false;
                        });
                });

                // ============================================================
                // 🔥 FIX: Älter als 30 Tage löschen
                // ============================================================
                document.getElementById('wpaic-delete-old')?.addEventListener('click', function() {
                    if (!confirm('⚠️ Analysen älter als 30 Tage wirklich löschen?')) return;

                    this.disabled = true;
                    if (deleteStatus) {
                        deleteStatus.innerHTML = '⏳ Lösche alte Einträge...';
                        deleteStatus.style.color = '#dba617';
                    }

                    fetch(ajaxUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: new URLSearchParams({
                                action: 'wpaic_delete_old_analyses',
                                _wpnonce: cfg.nonce, // 🔥 FIX: nonce → _wpnonce
                                days: 30
                            })
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                if (deleteStatus) {
                                    deleteStatus.innerHTML = '✅ ' + data.data.deleted + ' alte Einträge gelöscht! Seite wird neu geladen...';
                                    deleteStatus.style.color = '#46b450';
                                }
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                if (deleteStatus) {
                                    deleteStatus.innerHTML = '❌ Fehler: ' + (data.data || 'Unbekannt');
                                    deleteStatus.style.color = '#d63638';
                                }
                                this.disabled = false;
                            }
                        })
                        .catch(err => {
                            console.error('❌ Fehler:', err);
                            if (deleteStatus) {
                                deleteStatus.innerHTML = '❌ Netzwerkfehler: ' + err.message;
                                deleteStatus.style.color = '#d63638';
                            }
                            this.disabled = false;
                        });
                });

                // ============================================================
                // Analyse starten
                // ============================================================
                if (!runBtn) return;

                runBtn.addEventListener('click', async function() {
                    const url = cfg.restUrl + '/analyze';
                    console.log('🚀 Inline: Starting analysis at:', url);

                    spinner.classList.add('is-active');
                    runBtn.disabled = true;
                    output.innerHTML = '<p>' + (cfg.i18n?.running || 'Analyse läuft...') + '</p>';

                    try {
                        const res = await fetch(url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': cfg.nonce,
                            },
                            body: JSON.stringify({
                                force: true
                            }),
                        });

                        console.log('📡 Inline Response Status:', res.status);

                        if (!res.ok) {
                            let errorMsg = 'HTTP ' + res.status + ': ' + res.statusText;
                            if (res.status === 404) {
                                errorMsg = '❌ REST-Endpunkt nicht gefunden!<br>' +
                                    'Bitte speichere die Permalinks unter ' +
                                    '<a href="' + window.location.origin + '/wp-admin/options-permalink.php">' +
                                    'Einstellungen > Permalinks</a> und lade die Seite neu.';
                            } else if (res.status === 504) {
                                errorMsg = '⏳ Ollama antwortet zu langsam (Gateway Timeout).<br>' +
                                    'Wähle ein kleineres Modell (z.B. qwen2.5:1.5b)';
                            } else if (res.status === 403) {
                                errorMsg = '❌ Keine Berechtigung. Bitte als Admin anmelden.';
                            }
                            output.innerHTML = '<div class="notice notice-error"><p>' + errorMsg + '</p></div>';
                            return;
                        }

                        const contentType = res.headers.get('content-type');
                        if (!contentType || !contentType.includes('application/json')) {
                            const text = await res.text();
                            console.error('❌ Kein JSON erhalten:', text.substring(0, 200));
                            output.innerHTML = '<div class="notice notice-error"><p>' +
                                '❌ Server antwortete mit HTML statt JSON.<br>' +
                                'Status: ' + res.status + '<br>' +
                                'URL: ' + url +
                                '</p></div>';
                            return;
                        }

                        const data = await res.json();
                        console.log('📊 Inline Response Data:', data);

                        if (!data.success) {
                            output.innerHTML = '<div class="notice notice-error"><p>' +
                                (data.error || cfg.i18n?.failed || 'Analyse fehlgeschlagen.') + '</p></div>';
                            return;
                        }

                        renderResult(data.result);

                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);

                    } catch (e) {
                        console.error('❌ Inline Fetch Error:', e);
                        output.innerHTML = '<div class="notice notice-error"><p>' +
                            (cfg.i18n?.failed || 'Analyse fehlgeschlagen.') + ': ' + escHtml(e.message) +
                            '<br><small>Siehe Browser-Konsole (F12) für Details.</small></p></div>';
                    } finally {
                        spinner.classList.remove('is-active');
                        runBtn.disabled = false;
                    }
                });

                function renderResult(r) {
                    if (!r || typeof r !== 'object') {
                        output.innerHTML = '<pre>' + escHtml(String(r)) + '</pre>';
                        return;
                    }

                    let html = '';
                    if (r.summary) {
                        html += '<div class="notice notice-info" style="margin:10px 0; border-left-color:#2271b1;"><h3 style="margin-top:0;">📋 Zusammenfassung</h3><p>' + escHtml(r.summary) + '</p></div>';
                    }
                    if (r.risks && Array.isArray(r.risks) && r.risks.length > 0) {
                        html += '<h3>⚠️ Risiken</h3><ul style="list-style:none;padding-left:0;">';
                        r.risks.forEach(function(risk) {
                            const level = risk.level || 'low';
                            const color = level === 'high' || level === 'critical' ? '#d63638' : level === 'medium' ? '#dba617' : '#007cba';
                            const bgColor = level === 'high' || level === 'critical' ? '#fcf0f0' : level === 'medium' ? '#fcf9f0' : '#f0f6fc';
                            html += '<li style="margin:8px 0;padding:12px 16px;background:' + bgColor + ';border-radius:4px;border-left:4px solid ' + color + ';">' +
                                '<span style="color:' + color + ';font-weight:bold;display:block;margin-bottom:4px;">' + escHtml(risk.title || '') + '</span>' +
                                '<span style="display:block;color:#444;font-size:13px;">' + escHtml(risk.detail || '') + '</span>' +
                                '<span style="display:inline-block;font-size:10px;text-transform:uppercase;color:#fff;font-weight:bold;background:' + color + ';padding:1px 10px;border-radius:10px;margin-top:4px;">' + escHtml(level) + '</span></li>';
                        });
                        html += '</ul>';
                    }
                    if (r.recommendations && Array.isArray(r.recommendations) && r.recommendations.length > 0) {
                        html += '<h3>💡 Empfehlungen</h3><ol style="padding-left:25px;margin:10px 0;">';
                        r.recommendations.forEach(function(rec) {
                            html += '<li style="margin:6px 0;padding:4px 0;">' + escHtml(rec) + '</li>';
                        });
                        html += '</ol>';
                    }
                    if (r.uncertainties && Array.isArray(r.uncertainties) && r.uncertainties.length > 0) {
                        html += '<div style="margin:20px 0;padding:12px 16px;background:#fcf9f0;border-radius:4px;border-left:4px solid #dba617;">' +
                            '<h3 style="margin-top:0;">🤔 Unsicherheiten</h3><ul style="padding-left:20px;margin:5px 0;">';
                        r.uncertainties.forEach(function(u) {
                            html += '<li style="margin:4px 0;">' + escHtml(u) + '</li>';
                        });
                        html += '</ul></div>';
                    }
                    output.innerHTML = html || '<p>' + (cfg.i18n?.noResult || 'Keine Ergebnisse.') + '</p>';
                }

                function escHtml(s) {
                    var d = document.createElement('div');
                    d.textContent = String(s);
                    return d.innerHTML;
                }

                // Historie: Löschen-Button
                document.querySelectorAll('.wpaic-delete-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        if (!confirm('Analyse wirklich löschen?')) return;
                        var id = this.getAttribute('data-id');
                        var row = document.getElementById('wpaic-history-row-' + id);

                        var url = cfg.restUrl + '/history';

                        fetch(url, {
                                method: 'DELETE',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-WP-Nonce': cfg.nonce,
                                },
                                body: JSON.stringify({
                                    id: parseInt(id)
                                }),
                            })
                            .then(function(r) {
                                if (!r.ok) throw new Error('HTTP ' + r.status);
                                return r.json();
                            })
                            .then(function(data) {
                                if (data.success) {
                                    if (row) row.remove();
                                    window.location.reload();
                                } else {
                                    alert('Löschen fehlgeschlagen: ' + (data.error || 'Unbekannt'));
                                }
                            })
                            .catch(function(err) {
                                alert('Fehler beim Löschen: ' + err.message);
                            });
                    });
                });

                // Historie: View-Button (Modal)
                document.querySelectorAll('.wpaic-view-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var id = this.getAttribute('data-id');
                        var modal = document.getElementById('wpaic-detail-modal');
                        var content = document.getElementById('wpaic-detail-content');

                        content.innerHTML = '<p>Lädt...</p>';
                        modal.style.display = 'flex';

                        var url = cfg.restUrl + '/history?limit=100';

                        fetch(url, {
                                headers: {
                                    'X-WP-Nonce': cfg.nonce
                                },
                            })
                            .then(function(r) {
                                if (!r.ok) throw new Error('HTTP ' + r.status);
                                return r.json();
                            })
                            .then(function(data) {
                                if (data.entries) {
                                    var entry = data.entries.find(function(e) {
                                        return e.id == id;
                                    });
                                    if (entry && entry.result_json) {
                                        var r = entry.result_json;
                                        var html = '<h3>Analyse #' + id + '</h3>';
                                        html += '<p><strong>Datum:</strong> ' + escHtml(entry.run_at) + '</p>';
                                        html += '<p><strong>Modell:</strong> ' + escHtml(entry.model) + '</p>';
                                        html += '<p><strong>Dauer:</strong> ' + escHtml(String(entry.duration_ms)) + ' ms</p>';
                                        if (r.summary) html += '<div class="notice notice-info"><p>' + escHtml(r.summary) + '</p></div>';
                                        if (r.risks && r.risks.length) {
                                            html += '<h4>Risiken</h4><ul>';
                                            r.risks.forEach(function(risk) {
                                                html += '<li><strong>' + escHtml(risk.title || '') + '</strong>: ' + escHtml(risk.detail || '') + ' <em>(' + escHtml(risk.level || '') + ')</em></li>';
                                            });
                                            html += '</ul>';
                                        }
                                        if (r.recommendations && r.recommendations.length) {
                                            html += '<h4>Empfehlungen</h4><ul>';
                                            r.recommendations.forEach(function(rec) {
                                                html += '<li>' + escHtml(rec) + '</li>';
                                            });
                                            html += '</ul>';
                                        }
                                        if (r.uncertainties && r.uncertainties.length) {
                                            html += '<h4>🤔 Unsicherheiten</h4><ul>';
                                            r.uncertainties.forEach(function(u) {
                                                html += '<li>' + escHtml(u) + '</li>';
                                            });
                                            html += '</ul>';
                                        }
                                        content.innerHTML = html;
                                    } else {
                                        content.innerHTML = '<p>Keine Details verfügbar.</p>';
                                    }
                                }
                            })
                            .catch(function(err) {
                                content.innerHTML = '<p>Fehler beim Laden: ' + err.message + '</p>';
                            });
                    });
                });

                // Modal schließen
                document.querySelectorAll('.wpaic-modal-close').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        document.getElementById('wpaic-detail-modal').style.display = 'none';
                    });
                });
                document.getElementById('wpaic-detail-modal').addEventListener('click', function(e) {
                    if (e.target === this) this.style.display = 'none';
                });

                console.log('✅ Inline Script loaded with cfg.restUrl:', cfg.restUrl);
            })();
        </script>
<?php
    }

    /**
     * Hilfstfunktion: Versucht, das letzte Ergebnis zu parsen.
     */
    private function parse_last_result(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw)) {
            return null;
        }

        $clean = preg_replace('/^```json\s*/', '', $raw);
        $clean = preg_replace('/\s*```$/', '', $clean);
        $clean = trim($clean);

        $decoded = json_decode($clean, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return null;
    }
}