<?php
/**
 * Plugin Name: WP Admin Performance Watcher
 * Plugin URI: https://github.com/TABARC-Code/wp-admin-performance-watcher
 * Description: Tracks WordPress admin slowness over time so I can stop guessing which plugin made everything feel like mud. Lightweight sampling, rolling history, no auto fixes.
 * Version: 1.0.0.6
 * Author: TABARC-Code
 * Author URI: https://github.com/TABARC-Code
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Why this exists:
 * Admin gets slower. People complain. Nobody knows when it started.
 * Then someone updates a plugin, the dashboard crawls, and we all pretend hosting is the problem.
 * This plugin gives me receipts, not vibes.
 *
 * What it does:
 * - Samples admin requests and logs load time, query count, memory peak.
 * - Keeps rolling history (default 14 days).
 * - Shows slowest admin pages, trends, and outliers.
 * - Optionally logs slow queries if SAVEQUERIES is enabled.
 *
 * What it does not do:
 * - It does not disable plugins.
 * - It does not “optimise” anything.
 * - It does not log front end traffic.
 *
 * TODO: add a simple baseline snapshot, then highlight regressions since baseline.
 * TODO: add an export CSV button because people like spreadsheets when panicking.
 * FIXME: slow query capture requires SAVEQUERIES. I am not forcing that globally, that would be rude.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_Admin_Performance_Watcher' ) ) {

    class WP_Admin_Performance_Watcher {

        private $table_samples;
        private $table_slow_queries;
        private $option_name = 'wapw_settings';
        private $export_action = 'wapw_export_json';
        private $cleanup_hook = 'wapw_cleanup_old_data';

        private $start_time = 0.0;
        private $capturing = false;
        private $request_context = array();

        public function __construct() {
            global $wpdb;
            $this->table_samples = $wpdb->prefix . 'wapw_samples';
            $this->table_slow_queries = $wpdb->prefix . 'wapw_slow_queries';

            register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
            register_deactivation_hook( __FILE__, array( $this, 'on_deactivate' ) );

            add_action( 'admin_menu', array( $this, 'add_tools_page' ) );
            add_action( 'admin_post_' . $this->export_action, array( $this, 'handle_export_json' ) );
            add_action( $this->cleanup_hook, array( $this, 'cleanup_old_data' ) );

            add_action( 'admin_init', array( $this, 'maybe_start_capture' ), 1 );
            add_action( 'shutdown', array( $this, 'maybe_finish_capture' ), 0 );

            add_action( 'admin_head-plugins.php', array( $this, 'inject_plugin_list_icon_css' ) );
        }

        private function get_brand_icon_url() {
            return plugin_dir_url( __FILE__ ) . '.branding/tabarc-icon.png';
        }

        public function inject_plugin_list_icon_css() {
            $icon_url = esc_url( $this->get_brand_icon_url() );
            ?>
            <style>
                .wp-list-table.plugins tr[data-slug="wp-admin-performance-watcher"] .plugin-title strong::before {
                    content: '';
                    display: inline-block;
                    vertical-align: middle;
                    width: 18px;
                    height: 18px;
                    margin-right: 6px;
                    background-image: url('<?php echo $icon_url; ?>');
                    background-repeat: no-repeat;
                    background-size: contain;
                }
            </style>
            <?php
        }

        public function on_activate() {
            $this->create_tables();

            if ( ! wp_next_scheduled( $this->cleanup_hook ) ) {
                wp_schedule_event( time() + 300, 'daily', $this->cleanup_hook );
            }

            $defaults = $this->get_default_settings();
            $existing = get_option( $this->option_name );
            if ( ! is_array( $existing ) ) {
                add_option( $this->option_name, $defaults, '', 'no' );
            }
        }

        public function on_deactivate() {
            $ts = wp_next_scheduled( $this->cleanup_hook );
            if ( $ts ) {
                wp_unschedule_event( $ts, $this->cleanup_hook );
            }
        }

        private function get_default_settings() {
            return array(
                'enabled' => true,
                'retention_days' => 14,
                'sample_rate_percent' => 25,
                'slow_query_ms_threshold' => 250,
                'log_slow_queries_if_available' => true,
                'ignore_ajax' => true,
                'ignore_heartbeat' => true,
            );
        }

        private function get_settings() {
            $defaults = $this->get_default_settings();
            $stored = get_option( $this->option_name );
            if ( ! is_array( $stored ) ) {
                $stored = array();
            }

            $s = array_merge( $defaults, $stored );

            $s['enabled'] = ! empty( $s['enabled'] );

            $s['retention_days'] = (int) $s['retention_days'];
            if ( $s['retention_days'] < 1 ) {
                $s['retention_days'] = 1;
            }
            if ( $s['retention_days'] > 90 ) {
                $s['retention_days'] = 90;
            }

            $s['sample_rate_percent'] = (int) $s['sample_rate_percent'];
            if ( $s['sample_rate_percent'] < 1 ) {
                $s['sample_rate_percent'] = 1;
            }
            if ( $s['sample_rate_percent'] > 100 ) {
                $s['sample_rate_percent'] = 100;
            }

            $s['slow_query_ms_threshold'] = (int) $s['slow_query_ms_threshold'];
            if ( $s['slow_query_ms_threshold'] < 10 ) {
                $s['slow_query_ms_threshold'] = 10;
            }
            if ( $s['slow_query_ms_threshold'] > 5000 ) {
                $s['slow_query_ms_threshold'] = 5000;
            }

            $s['log_slow_queries_if_available'] = ! empty( $s['log_slow_queries_if_available'] );
            $s['ignore_ajax'] = ! empty( $s['ignore_ajax'] );
            $s['ignore_heartbeat'] = ! empty( $s['ignore_heartbeat'] );

            return $s;
        }

        private function create_tables() {
            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $charset = $wpdb->get_charset_collate();

            $sql_samples = "CREATE TABLE {$this->table_samples} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                recorded_at DATETIME NOT NULL,
                url_path TEXT NOT NULL,
                screen_id VARCHAR(191) NOT NULL DEFAULT '',
                hook_suffix VARCHAR(191) NOT NULL DEFAULT '',
                method VARCHAR(10) NOT NULL DEFAULT 'GET',
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                user_roles VARCHAR(255) NOT NULL DEFAULT '',
                load_ms INT UNSIGNED NOT NULL DEFAULT 0,
                query_count INT UNSIGNED NOT NULL DEFAULT 0,
                peak_memory_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                plugins_hash VARCHAR(64) NOT NULL DEFAULT '',
                theme_slug VARCHAR(191) NOT NULL DEFAULT '',
                is_ajax TINYINT(1) NOT NULL DEFAULT 0,
                is_heartbeat TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY recorded_at (recorded_at),
                KEY screen_id (screen_id),
                KEY hook_suffix (hook_suffix),
                KEY plugins_hash (plugins_hash)
            ) $charset;";

            $sql_slow = "CREATE TABLE {$this->table_slow_queries} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                sample_id BIGINT UNSIGNED NOT NULL,
                recorded_at DATETIME NOT NULL,
                query_ms INT UNSIGNED NOT NULL DEFAULT 0,
                query_text LONGTEXT NOT NULL,
                PRIMARY KEY (id),
                KEY sample_id (sample_id),
                KEY recorded_at (recorded_at)
            ) $charset;";

            dbDelta( $sql_samples );
            dbDelta( $sql_slow );
        }

        public function add_tools_page() {
            add_management_page(
                __( 'Admin Performance Watcher', 'wp-admin-performance-watcher' ),
                __( 'Admin Performance', 'wp-admin-performance-watcher' ),
                'manage_options',
                'wp-admin-performance-watcher',
                array( $this, 'render_tools_page' )
            );
        }

        public function render_tools_page() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-admin-performance-watcher' ) );
            }

            $settings = $this->get_settings();
            $this->handle_settings_post( $settings );
            $settings = $this->get_settings();

            $export_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=' . $this->export_action ),
                'wapw_export_json'
            );

            $stats = $this->get_dashboard_stats( $settings );

            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'WP Admin Performance Watcher', 'wp-admin-performance-watcher' ); ?></h1>
                <p>
                    Admin slowness is cumulative and nobody logs it, so the blame gets passed around forever.
                    This is my small attempt at keeping evidence.
                </p>

                <p>
                    <a class="button button-secondary" href="<?php echo esc_url( $export_url ); ?>">
                        <?php esc_html_e( 'Export data as JSON', 'wp-admin-performance-watcher' ); ?>
                    </a>
                </p>

                <h2><?php esc_html_e( 'Settings', 'wp-admin-performance-watcher' ); ?></h2>
                <form method="post">
                    <?php wp_nonce_field( 'wapw_save_settings', 'wapw_nonce' ); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Enabled', 'wp-admin-performance-watcher' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wapw_settings[enabled]" value="1" <?php checked( $settings['enabled'], true ); ?>>
                                    Track admin performance
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Retention days', 'wp-admin-performance-watcher' ); ?></th>
                            <td>
                                <input type="number" min="1" max="90" name="wapw_settings[retention_days]" value="<?php echo esc_attr( (int) $settings['retention_days'] ); ?>">
                                <p class="description">Rolling history. Longer retention means more rows. Nothing is free.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Sample rate percent', 'wp-admin-performance-watcher' ); ?></th>
                            <td>
                                <input type="number" min="1" max="100" name="wapw_settings[sample_rate_percent]" value="<?php echo esc_attr( (int) $settings['sample_rate_percent'] ); ?>">
                                <p class="description">25 means roughly one in four admin requests. If you set 100, you better mean it.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Slow query threshold (ms)', 'wp-admin-performance-watcher' ); ?></th>
                            <td>
                                <input type="number" min="10" max="5000" name="wapw_settings[slow_query_ms_threshold]" value="<?php echo esc_attr( (int) $settings['slow_query_ms_threshold'] ); ?>">
                                <p class="description">Only used if SAVEQUERIES is enabled and slow query logging is on.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Log slow queries if available', 'wp-admin-performance-watcher' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wapw_settings[log_slow_queries_if_available]" value="1" <?php checked( $settings['log_slow_queries_if_available'], true ); ?>>
                                    Capture slow queries when SAVEQUERIES is enabled
                                </label>
                                <p class="description">This does nothing unless SAVEQUERIES is enabled in wp-config.php.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Ignore admin-ajax.php', 'wp-admin-performance-watcher' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wapw_settings[ignore_ajax]" value="1" <?php checked( $settings['ignore_ajax'], true ); ?>>
                                    Ignore AJAX requests
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Ignore Heartbeat', 'wp-admin-performance-watcher' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wapw_settings[ignore_heartbeat]" value="1" <?php checked( $settings['ignore_heartbeat'], true ); ?>>
                                    Ignore Heartbeat traffic
                                </label>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( 'Save settings' ); ?>
                </form>

                <h2><?php esc_html_e( 'What it is seeing', 'wp-admin-performance-watcher' ); ?></h2>
                <?php $this->render_stats_tables( $stats ); ?>

                <?php $this->render_savequeries_note( $settings ); ?>
            </div>
            <?php
        }

        private function handle_settings_post( $settings ) {
            if ( empty( $_POST['wapw_settings'] ) ) {
                return;
            }
            if ( empty( $_POST['wapw_nonce'] ) || ! wp_verify_nonce( $_POST['wapw_nonce'], 'wapw_save_settings' ) ) {
                return;
            }
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $in = (array) $_POST['wapw_settings'];
            $new = $this->get_default_settings();

            $new['enabled'] = ! empty( $in['enabled'] );
            $new['retention_days'] = isset( $in['retention_days'] ) ? (int) $in['retention_days'] : $new['retention_days'];
            $new['sample_rate_percent'] = isset( $in['sample_rate_percent'] ) ? (int) $in['sample_rate_percent'] : $new['sample_rate_percent'];
            $new['slow_query_ms_threshold'] = isset( $in['slow_query_ms_threshold'] ) ? (int) $in['slow_query_ms_threshold'] : $new['slow_query_ms_threshold'];
            $new['log_slow_queries_if_available'] = ! empty( $in['log_slow_queries_if_available'] );
            $new['ignore_ajax'] = ! empty( $in['ignore_ajax'] );
            $new['ignore_heartbeat'] = ! empty( $in['ignore_heartbeat'] );

            update_option( $this->option_name, $new, false );

            echo '<div class="notice notice-success"><p>Saved. Try not to set sample rate to 100 then complain about database rows.</p></div>';
        }

        private function render_savequeries_note( $settings ) {
            $savequeries = defined( 'SAVEQUERIES' ) && SAVEQUERIES;

            if ( $settings['log_slow_queries_if_available'] && ! $savequeries ) {
                echo '<div class="notice notice-info"><p><strong>Slow query capture is currently off</strong> because SAVEQUERIES is not enabled. If you want query text and timings, set <code>define(\'SAVEQUERIES\', true);</code> in wp-config.php. If you do not want extra overhead, ignore this.</p></div>';
            }
        }

        public function handle_export_json() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( 'No.' );
            }
            check_admin_referer( 'wapw_export_json' );

            $settings = $this->get_settings();
            $payload = array(
                'generated_at' => gmdate( 'c' ),
                'site_url' => site_url(),
                'settings' => $settings,
                'stats' => $this->get_dashboard_stats( $settings, true ),
            );

            nocache_headers();
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="admin-performance-watcher.json"' );
            echo wp_json_encode( $payload, JSON_PRETTY_PRINT );
            exit;
        }

        public function maybe_start_capture() {
            if ( ! is_admin() ) {
                return;
            }
            if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
                return;
            }

            $settings = $this->get_settings();
            if ( ! $settings['enabled'] ) {
                return;
            }

            $is_ajax = ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ? 1 : 0;
            $is_heartbeat = 0;

            if ( $is_ajax && isset( $_REQUEST['action'] ) && $_REQUEST['action'] === 'heartbeat' ) {
                $is_heartbeat = 1;
            }

            if ( $settings['ignore_ajax'] && $is_ajax ) {
                return;
            }
            if ( $settings['ignore_heartbeat'] && $is_heartbeat ) {
                return;
            }

            $roll = wp_rand( 1, 100 );
            if ( $roll > (int) $settings['sample_rate_percent'] ) {
                return;
            }

            $this->capturing = true;
            $this->start_time = microtime( true );

            $user_id = get_current_user_id();
            $roles = '';

            if ( $user_id ) {
                $u = get_userdata( $user_id );
                if ( $u && is_array( $u->roles ) ) {
                    $roles = implode( ',', array_map( 'sanitize_key', $u->roles ) );
                }
            }

            $this->request_context = array(
                'recorded_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
                'url_path' => $this->get_request_path(),
                'method' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( $_SERVER['REQUEST_METHOD'] ) : 'GET',
                'user_id' => (int) $user_id,
                'user_roles' => $roles,
                'hook_suffix' => '',
                'screen_id' => '',
                'plugins_hash' => $this->hash_active_plugins(),
                'theme_slug' => $this->get_theme_slug(),
                'is_ajax' => $is_ajax,
                'is_heartbeat' => $is_heartbeat,
            );

            add_action( 'current_screen', array( $this, 'capture_screen_context' ) );
        }

        public function capture_screen_context( $screen ) {
            if ( ! $this->capturing ) {
                return;
            }
            if ( is_object( $screen ) ) {
                $this->request_context['screen_id'] = isset( $screen->id ) ? (string) $screen->id : '';
            }
        }

        public function maybe_finish_capture() {
            if ( ! $this->capturing ) {
                return;
            }

            global $wpdb;

            $elapsed_ms = (int) max( 0, round( ( microtime( true ) - $this->start_time ) * 1000 ) );
            $query_count = isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0;
            $peak_mem = function_exists( 'memory_get_peak_usage' ) ? (int) memory_get_peak_usage( true ) : 0;

            if ( isset( $GLOBALS['hook_suffix'] ) ) {
                $this->request_context['hook_suffix'] = sanitize_text_field( (string) $GLOBALS['hook_suffix'] );
            }

            $sample_id = $this->insert_sample_row(
                $this->request_context,
                $elapsed_ms,
                $query_count,
                $peak_mem
            );

            $settings = $this->get_settings();
            $savequeries = defined( 'SAVEQUERIES' ) && SAVEQUERIES;

            if ( $sample_id && $settings['log_slow_queries_if_available'] && $savequeries && isset( $wpdb->queries ) && is_array( $wpdb->queries ) ) {
                $this->insert_slow_queries( $sample_id, $wpdb->queries, (int) $settings['slow_query_ms_threshold'] );
            }

            $this->capturing = false;
            $this->request_context = array();
        }

        private function insert_sample_row( $ctx, $elapsed_ms, $query_count, $peak_mem ) {
            global $wpdb;

            $ok = $wpdb->insert(
                $this->table_samples,
                array(
                    'recorded_at' => get_date_from_gmt( $ctx['recorded_at_gmt'], 'Y-m-d H:i:s' ),
                    'url_path' => $ctx['url_path'],
                    'screen_id' => $ctx['screen_id'],
                    'hook_suffix' => $ctx['hook_suffix'],
                    'method' => $ctx['method'],
                    'user_id' => (int) $ctx['user_id'],
                    'user_roles' => $ctx['user_roles'],
                    'load_ms' => (int) $elapsed_ms,
                    'query_count' => (int) $query_count,
                    'peak_memory_bytes' => (int) $peak_mem,
                    'plugins_hash' => $ctx['plugins_hash'],
                    'theme_slug' => $ctx['theme_slug'],
                    'is_ajax' => (int) $ctx['is_ajax'],
                    'is_heartbeat' => (int) $ctx['is_heartbeat'],
                ),
                array(
                    '%s','%s','%s','%s','%s','%d','%s','%d','%d','%d','%s','%s','%d','%d'
                )
            );

            if ( ! $ok ) {
                return 0;
            }

            return (int) $wpdb->insert_id;
        }

        private function insert_slow_queries( $sample_id, $queries, $threshold_ms ) {
            global $wpdb;

            foreach ( $queries as $q ) {
                if ( ! is_array( $q ) || count( $q ) < 2 ) {
                    continue;
                }

                $sql = (string) $q[0];
                $time_s = (float) $q[1];

                $ms = (int) round( $time_s * 1000 );
                if ( $ms < $threshold_ms ) {
                    continue;
                }

                $wpdb->insert(
                    $this->table_slow_queries,
                    array(
                        'sample_id' => (int) $sample_id,
                        'recorded_at' => current_time( 'mysql' ),
                        'query_ms' => (int) $ms,
                        'query_text' => $this->trim_query_text( $sql ),
                    ),
                    array( '%d', '%s', '%d', '%s' )
                );
            }
        }

        private function trim_query_text( $sql ) {
            $sql = trim( preg_replace( '/\s+/', ' ', $sql ) );
            if ( strlen( $sql ) > 2000 ) {
                $sql = substr( $sql, 0, 2000 ) . ' [trimmed]';
            }
            return $sql;
        }

        private function get_request_path() {
            $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
            if ( $uri === '' ) {
                return '';
            }
            $parts = explode( '?', $uri, 2 );
            return $parts[0];
        }

        private function hash_active_plugins() {
            $plugins = (array) get_option( 'active_plugins', array() );
            sort( $plugins );
            return hash( 'sha256', wp_json_encode( $plugins ) );
        }

        private function get_theme_slug() {
            $theme = wp_get_theme();
            if ( $theme && $theme->exists() ) {
                return sanitize_key( $theme->get_stylesheet() );
            }
            return '';
        }

        public function cleanup_old_data() {
            $settings = $this->get_settings();
            $days = (int) $settings['retention_days'];

            $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

            global $wpdb;

            $sample_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM {$this->table_samples} WHERE recorded_at < %s LIMIT 5000",
                    get_date_from_gmt( $cutoff, 'Y-m-d H:i:s' )
                )
            );

            if ( ! empty( $sample_ids ) ) {
                $ids_in = implode( ',', array_map( 'intval', $sample_ids ) );
                $wpdb->query( "DELETE FROM {$this->table_slow_queries} WHERE sample_id IN ($ids_in)" );
                $wpdb->query( "DELETE FROM {$this->table_samples} WHERE id IN ($ids_in)" );
            }
        }

        private function get_dashboard_stats( $settings, $include_recent_samples = false ) {
            global $wpdb;

            $days = (int) $settings['retention_days'];
            $since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
            $since_local = get_date_from_gmt( $since, 'Y-m-d H:i:s' );

            $total_samples = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_samples} WHERE recorded_at >= %s",
                    $since_local
                )
            );

            $avg_load = (float) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT AVG(load_ms) FROM {$this->table_samples} WHERE recorded_at >= %s",
                    $since_local
                )
            );

            $p95_load = $this->approx_percentile_ms( $since_local, 95 );

            $slowest_pages = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT screen_id, hook_suffix, COUNT(*) AS samples, AVG(load_ms) AS avg_ms, MAX(load_ms) AS max_ms, AVG(query_count) AS avg_q
                     FROM {$this->table_samples}
                     WHERE recorded_at >= %s
                     GROUP BY screen_id, hook_suffix
                     HAVING samples >= 3
                     ORDER BY avg_ms DESC
                     LIMIT 15",
                    $since_local
                ),
                ARRAY_A
            );

            $outliers = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, recorded_at, screen_id, hook_suffix, load_ms, query_count, peak_memory_bytes
                     FROM {$this->table_samples}
                     WHERE recorded_at >= %s
                     ORDER BY load_ms DESC
                     LIMIT 15",
                    $since_local
                ),
                ARRAY_A
            );

            $slow_queries = array();
            if ( $settings['log_slow_queries_if_available'] ) {
                $slow_queries = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT sq.recorded_at, sq.query_ms, s.screen_id, s.hook_suffix
                         FROM {$this->table_slow_queries} sq
                         INNER JOIN {$this->table_samples} s ON s.id = sq.sample_id
                         WHERE s.recorded_at >= %s
                         ORDER BY sq.query_ms DESC
                         LIMIT 15",
                        $since_local
                    ),
                    ARRAY_A
                );
            }

            $recent_samples = array();
            if ( $include_recent_samples ) {
                $recent_samples = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, recorded_at, url_path, screen_id, hook_suffix, load_ms, query_count, peak_memory_bytes, plugins_hash, theme_slug
                         FROM {$this->table_samples}
                         WHERE recorded_at >= %s
                         ORDER BY recorded_at DESC
                         LIMIT 200",
                        $since_local
                    ),
                    ARRAY_A
                );
            }

            return array(
                'range_days' => $days,
                'since' => $since_local,
                'total_samples' => $total_samples,
                'avg_load_ms' => (int) round( $avg_load ),
                'p95_load_ms_estimate' => (int) $p95_load,
                'slowest_pages' => $this->normalise_rows( $slowest_pages ),
                'worst_outliers' => $this->normalise_rows( $outliers ),
                'slow_queries' => $this->normalise_rows( $slow_queries ),
                'recent_samples' => $this->normalise_rows( $recent_samples ),
            );
        }

        private function approx_percentile_ms( $since_local, $p ) {
            global $wpdb;

            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_samples} WHERE recorded_at >= %s",
                    $since_local
                )
            );

            if ( $count < 20 ) {
                $max = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT MAX(load_ms) FROM {$this->table_samples} WHERE recorded_at >= %s",
                        $since_local
                    )
                );
                return $max;
            }

            $offset = (int) floor( ( $p / 100 ) * $count );
            if ( $offset < 0 ) {
                $offset = 0;
            }
            if ( $offset >= $count ) {
                $offset = $count - 1;
            }

            $val = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT load_ms FROM {$this->table_samples}
                     WHERE recorded_at >= %s
                     ORDER BY load_ms ASC
                     LIMIT 1 OFFSET %d",
                    $since_local,
                    $offset
                )
            );

            return $val;
        }

        private function normalise_rows( $rows ) {
            if ( ! is_array( $rows ) ) {
                return array();
            }
            $out = array();
            foreach ( $rows as $r ) {
                if ( is_array( $r ) ) {
                    $out[] = $r;
                }
            }
            return $out;
        }

        private function human_bytes( $bytes ) {
            $bytes = (float) $bytes;
            if ( $bytes < 1024 ) {
                return $bytes . ' B';
            }
            $kb = $bytes / 1024;
            if ( $kb < 1024 ) {
                return number_format_i18n( $kb, 1 ) . ' KB';
            }
            $mb = $kb / 1024;
            if ( $mb < 1024 ) {
                return number_format_i18n( $mb, 2 ) . ' MB';
            }
            $gb = $mb / 1024;
            return number_format_i18n( $gb, 2 ) . ' GB';
        }

        private function render_stats_tables( $stats ) {
            $total = (int) $stats['total_samples'];
            $avg = (int) $stats['avg_load_ms'];
            $p95 = (int) $stats['p95_load_ms_estimate'];

            echo '<table class="widefat striped" style="max-width:980px;"><tbody>';
            echo '<tr><th>Range</th><td>Last ' . esc_html( (int) $stats['range_days'] ) . ' days</td></tr>';
            echo '<tr><th>Samples</th><td>' . esc_html( $total ) . '</td></tr>';
            echo '<tr><th>Average load</th><td><strong>' . esc_html( $avg ) . ' ms</strong></td></tr>';
            echo '<tr><th>95th percentile estimate</th><td><strong>' . esc_html( $p95 ) . ' ms</strong> <span style="opacity:0.75;font-size:12px;">(rough, but it catches trends)</span></td></tr>';
            echo '</tbody></table>';

            echo '<h3>Slowest admin screens (by average)</h3>';
            $slow = isset( $stats['slowest_pages'] ) ? (array) $stats['slowest_pages'] : array();
            if ( empty( $slow ) ) {
                echo '<p>No grouped results yet. Give it time, or increase sampling.</p>';
            } else {
                echo '<table class="widefat striped"><thead><tr><th>Screen</th><th>Hook</th><th>Samples</th><th>Avg</th><th>Max</th><th>Avg queries</th></tr></thead><tbody>';
                foreach ( $slow as $r ) {
                    $screen = isset( $r['screen_id'] ) ? (string) $r['screen_id'] : '';
                    $hook = isset( $r['hook_suffix'] ) ? (string) $r['hook_suffix'] : '';
                    echo '<tr>';
                    echo '<td><code>' . esc_html( $screen ) . '</code></td>';
                    echo '<td><code style="opacity:0.85;">' . esc_html( $hook ) . '</code></td>';
                    echo '<td>' . esc_html( (int) $r['samples'] ) . '</td>';
                    echo '<td><strong>' . esc_html( (int) round( (float) $r['avg_ms'] ) ) . ' ms</strong></td>';
                    echo '<td>' . esc_html( (int) $r['max_ms'] ) . ' ms</td>';
                    echo '<td>' . esc_html( (int) round( (float) $r['avg_q'] ) ) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }

            echo '<h3>Worst outliers</h3>';
            $out = isset( $stats['worst_outliers'] ) ? (array) $stats['worst_outliers'] : array();
            if ( empty( $out ) ) {
                echo '<p>No outliers yet.</p>';
            } else {
                echo '<table class="widefat striped"><thead><tr><th>When</th><th>Screen</th><th>Hook</th><th>Load</th><th>Queries</th><th>Peak memory</th></tr></thead><tbody>';
                foreach ( $out as $r ) {
                    echo '<tr>';
                    echo '<td>' . esc_html( (string) $r['recorded_at'] ) . '</td>';
                    echo '<td><code>' . esc_html( (string) $r['screen_id'] ) . '</code></td>';
                    echo '<td><code style="opacity:0.85;">' . esc_html( (string) $r['hook_suffix'] ) . '</code></td>';
                    echo '<td><strong>' . esc_html( (int) $r['load_ms'] ) . ' ms</strong></td>';
                    echo '<td>' . esc_html( (int) $r['query_count'] ) . '</td>';
                    echo '<td>' . esc_html( $this->human_bytes( (int) $r['peak_memory_bytes'] ) ) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }

            $sq = isset( $stats['slow_queries'] ) ? (array) $stats['slow_queries'] : array();
            if ( ! empty( $sq ) ) {
                echo '<h3>Slow queries (if available)</h3>';
                echo '<table class="widefat striped"><thead><tr><th>When</th><th>Query ms</th><th>Screen</th><th>Hook</th></tr></thead><tbody>';
                foreach ( $sq as $r ) {
                    echo '<tr>';
                    echo '<td>' . esc_html( (string) $r['recorded_at'] ) . '</td>';
                    echo '<td><strong>' . esc_html( (int) $r['query_ms'] ) . ' ms</strong></td>';
                    echo '<td><code>' . esc_html( (string) $r['screen_id'] ) . '</code></td>';
                    echo '<td><code style="opacity:0.85;">' . esc_html( (string) $r['hook_suffix'] ) . '</code></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
                echo '<p style="font-size:12px;opacity:0.8;">Query text is stored, but not shown here by default. I am not printing SQL in your face unless you export.</p>';
            }
        }
    }

    new WP_Admin_Performance_Watcher();
}
