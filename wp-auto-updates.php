<?php
/**
 * Plugin Name:       WP Auto Updates Manager (Enterprise Edition)
 * Plugin URI:        https://wordpress.org/plugins/wp-auto-updates-manager/
 * Description:       Enterprise-grade, zero-miss automatic background update manager for WordPress plugins and themes. Features dual-engine execution, non-blocking instant triggers, resilient direct fallback upgrader, intelligent retry queue with exponential backoff, external server webhook & crontab triggers, auto-enrollment, pre-update compatibility guards, full audit trail logging, CSV & JSON export, and system health diagnostics.
 * Version:           3.0.0
 * Author:            WordPress Core & Security Expert
 * Text Domain:       wp-auto-updates
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.6
 * Requires PHP:      7.4
 */

if (!defined('ABSPATH')) {
    exit; // Direct access denied.
}

/**
 * Custom Silent Automatic Upgrader Skin.
 * Suppresses UI output while capturing complete update feedback.
 */
if (!class_exists('WP_Auto_Updates_Silent_Skin')) {
    if (file_exists(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php')) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }
    if (file_exists(ABSPATH . 'wp-admin/includes/class-wp-upgrader-skins.php')) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skins.php';
    }

    if (class_exists('Automatic_Upgrader_Skin')) {
        class WP_Auto_Updates_Silent_Skin extends Automatic_Upgrader_Skin {
            public $messages = [];
            public function feedback($string, ...$args) {
                if (!empty($args)) {
                    $string = vsprintf($string, $args);
                }
                $this->messages[] = $string;
            }
            public function header() {}
            public function footer() {}
            public function error($errors) {
                if (is_wp_error($errors)) {
                    $this->messages[] = $errors->get_error_message();
                } elseif (is_string($errors)) {
                    $this->messages[] = $errors;
                }
            }
        }
    } else {
        class WP_Auto_Updates_Silent_Skin {
            public $messages = [];
            public $result = null;
            public function feedback($string, ...$args) {}
            public function header() {}
            public function footer() {}
            public function error($errors) {}
            public function get_upgrade_messages() { return $this->messages; }
        }
    }
}

/**
 * Main Enterprise Auto Updates Manager Class.
 */
final class WP_Auto_Updates_Enterprise_Manager {

    /**
     * Singleton instance.
     * @var WP_Auto_Updates_Enterprise_Manager|null
     */
    private static $instance = null;

    /**
     * Option Keys.
     */
    const SETTINGS_KEY    = 'wp_auto_updates_settings';
    const LOGS_KEY        = 'wp_auto_updates_audit_logs';
    const RETRY_QUEUE_KEY = 'wp_auto_updates_retry_queue';

    /**
     * Maximum audit log entries to retain (FIFO ring buffer).
     */
    const MAX_LOG_ENTRIES = 500;

    /**
     * In-memory cache to prevent duplicate log entries during a single request.
     * @var array
     */
    private static $logged_this_request = [];

    /**
     * Guard to prevent infinite recursion when triggering background updates.
     * @var bool
     */
    private static $is_running_updater = false;

    /**
     * Get Singleton Instance.
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        // Admin Menu & Multisite Network Menu
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('network_admin_menu', [$this, 'register_admin_menu']);

        // Admin POST Handlers
        add_action('admin_post_wp_auto_updates_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_wp_auto_updates_clear_logs', [$this, 'handle_clear_logs']);
        add_action('admin_post_wp_auto_updates_export_logs_csv', [$this, 'handle_export_logs_csv']);
        add_action('admin_post_wp_auto_updates_export_logs_json', [$this, 'handle_export_logs_json']);
        add_action('admin_post_wp_auto_updates_force_run', [$this, 'handle_force_run_updates']);
        add_action('admin_post_wp_auto_updates_retry_now', [$this, 'handle_retry_now']);
        add_action('admin_post_wp_auto_updates_clear_retries', [$this, 'handle_clear_retries']);

        // Core Automatic Updater Filters
        add_filter('auto_update_plugin', [$this, 'filter_plugin_auto_update'], 10, 2);
        add_filter('auto_update_theme', [$this, 'filter_theme_auto_update'], 10, 2);

        // Core Email Notification Filters
        add_filter('auto_plugin_update_send_email', [$this, 'filter_update_emails'], 10, 1);
        add_filter('auto_theme_update_send_email', [$this, 'filter_update_emails'], 10, 1);

        // Audit Logging Hooks
        add_action('automatic_updates_complete', [$this, 'log_automatic_updates_complete'], 10, 1);
        add_action('upgrader_process_complete', [$this, 'log_upgrader_process_complete'], 10, 2);

        // ⚡ NON-BLOCKING INSTANT AUTO-UPDATE ENGINE HOOKS ⚡
        add_action('set_site_transient_update_plugins', [$this, 'trigger_async_instant_plugin_update'], 10, 2);
        add_action('set_site_transient_update_themes', [$this, 'trigger_async_instant_theme_update'], 10, 2);
        add_action('wp_auto_updates_run_instant_background_update', [$this, 'execute_background_updater_routine']);

        // High-frequency cron schedule check & Retry queue runner
        add_filter('cron_schedules', [$this, 'register_cron_schedules']);
        add_action('wp_auto_updates_frequent_check', [$this, 'execute_scheduled_update_check']);
        add_action('wp_auto_updates_process_retries', [$this, 'process_retry_queue']);
        add_action('init', [$this, 'ensure_cron_scheduled']);

        // External Webhook / Crontab Endpoint Listener
        add_action('init', [$this, 'handle_webhook_request']);

        // Real-Time Zero-Stuck Maintenance Auto-Buster (Runs on early init to prevent 503 lockouts)
        add_action('init', [$this, 'auto_heal_maintenance_lock'], 1);
        add_action('admin_init', [$this, 'auto_heal_maintenance_lock'], 1);
        add_action('init', [__CLASS__, 'ensure_mu_plugin_dropin'], 10);

        // Passive Admin Self-Healing (Dispatches async check if pending updates detected)
        add_action('admin_init', [$this, 'passive_admin_health_check']);
    }

    /**
     * User capability required to manage auto updates.
     */
    public static function get_required_capability() {
        $default_cap = (function_exists('is_multisite') && is_multisite()) ? 'manage_network_plugins' : 'manage_options';
        return apply_filters('wp_auto_updates_capability', $default_cap);
    }

    /**
     * Option getter helper (Multisite compatible).
     */
    public static function get_db_option($key, $default = false) {
        if (function_exists('is_multisite') && is_multisite()) {
            return get_site_option($key, $default);
        }
        return get_option($key, $default);
    }

    /**
     * Option updater helper (Multisite compatible).
     */
    public static function update_db_option($key, $value, $autoload = null) {
        if (function_exists('is_multisite') && is_multisite()) {
            return update_site_option($key, $value);
        }
        return update_option($key, $value, $autoload);
    }

    /**
     * Option deleter helper (Multisite compatible).
     */
    public static function delete_db_option($key) {
        if (function_exists('is_multisite') && is_multisite()) {
            return delete_site_option($key);
        }
        return delete_option($key);
    }

    /**
     * Register Admin Menu.
     */
    public function register_admin_menu() {
        add_menu_page(
            __('Auto Updates Manager', 'wp-auto-updates'),
            __('Auto Updates', 'wp-auto-updates'),
            self::get_required_capability(),
            'wp-auto-updates',
            [$this, 'render_admin_dashboard'],
            'dashicons-update',
            65
        );
    }

    /**
     * Generate secure random token for webhook.
     */
    public static function generate_token() {
        if (function_exists('wp_generate_password')) {
            return wp_generate_password(32, false, false);
        }
        return bin2hex(random_bytes(16));
    }

    /**
     * Retrieve settings with safe defaults.
     */
    public static function get_settings() {
        $defaults = [
            'plugins'                 => [],
            'themes'                  => [],
            'disable_emails'          => 0,
            'instant_updates'         => 1, // Non-blocking instant update execution upon detection
            'check_frequency'         => 'hourly', // 15min, 30min, hourly, 2hours, twicedaily, daily
            'max_logs'                => self::MAX_LOG_ENTRIES,
            'auto_enroll_new'         => 1, // Automatically enable auto-updates for newly installed plugins & themes
            'enable_fallback_engine'  => 1, // Direct resilient fallback upgrader if WP_Automatic_Updater skips items
            'enable_retry_queue'      => 1, // Automatic retry queue with exponential backoff
            'pre_update_compat_check' => 1, // Check PHP & WP version compatibility before upgrading
            'webhook_token'           => self::generate_token(),
        ];

        $settings = self::get_db_option(self::SETTINGS_KEY, []);
        $merged = wp_parse_args($settings, $defaults);

        if (empty($merged['webhook_token'])) {
            $merged['webhook_token'] = self::generate_token();
            self::update_db_option(self::SETTINGS_KEY, $merged);
        }

        return $merged;
    }

    /**
     * Register custom cron schedules.
     */
    public function register_cron_schedules($schedules) {
        if (!isset($schedules['15min'])) {
            $schedules['15min'] = [
                'interval' => 900,
                'display'  => __('Every 15 Minutes', 'wp-auto-updates'),
            ];
        }
        if (!isset($schedules['30min'])) {
            $schedules['30min'] = [
                'interval' => 1800,
                'display'  => __('Every 30 Minutes', 'wp-auto-updates'),
            ];
        }
        if (!isset($schedules['hourly'])) {
            $schedules['hourly'] = [
                'interval' => 3600,
                'display'  => __('Once Hourly', 'wp-auto-updates'),
            ];
        }
        if (!isset($schedules['2hours'])) {
            $schedules['2hours'] = [
                'interval' => 7200,
                'display'  => __('Every 2 Hours', 'wp-auto-updates'),
            ];
        }
        return $schedules;
    }

    /**
     * Ensure background cron events are scheduled.
     */
    public function ensure_cron_scheduled() {
        $settings = self::get_settings();
        $freq = !empty($settings['check_frequency']) ? $settings['check_frequency'] : 'hourly';

        if (function_exists('wp_next_scheduled')) {
            if (!wp_next_scheduled('wp_auto_updates_frequent_check')) {
                wp_schedule_event(time(), $freq, 'wp_auto_updates_frequent_check');
            }
            if (!wp_next_scheduled('wp_auto_updates_process_retries')) {
                wp_schedule_event(time(), '15min', 'wp_auto_updates_process_retries');
            }
        }
    }

    /**
     * Scheduled background check callback.
     */
    public function execute_scheduled_update_check() {
        if (function_exists('wp_update_plugins')) {
            wp_update_plugins();
        }
        if (function_exists('wp_update_themes')) {
            wp_update_themes();
        }
        if (function_exists('wp_version_check')) {
            wp_version_check();
        }

        $this->execute_background_updater_routine();
    }

    /**
     * Passive Admin Self-Healing: Dispatches async background update if pending items exist.
     */
    public function passive_admin_health_check() {
        if (self::$is_running_updater || !is_admin()) {
            return;
        }

        $last_passive_check = self::get_db_option('wp_auto_updates_last_passive_check', 0);
        if (time() - $last_passive_check < 1800) { // Check at most once every 30 minutes
            return;
        }
        self::update_db_option('wp_auto_updates_last_passive_check', time());

        $plugins_transient = function_exists('get_site_transient') ? get_site_transient('update_plugins') : null;
        $themes_transient  = function_exists('get_site_transient') ? get_site_transient('update_themes') : null;

        $has_pending = (!empty($plugins_transient->response) && is_array($plugins_transient->response))
                    || (!empty($themes_transient->response) && is_array($themes_transient->response));

        if ($has_pending) {
            $this->schedule_async_instant_updater();
        }
    }

    /**
     * External Webhook & Crontab Request Handler (Hardened against Timing Attacks & DoS Flooding).
     */
    public function handle_webhook_request() {
        if (!isset($_GET['wp_auto_updates_webhook'])) {
            return;
        }

        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $settings = self::get_settings();

        // 1. Timing-safe authentication check
        if (empty($token) || empty($settings['webhook_token']) || !hash_equals($settings['webhook_token'], $token)) {
            status_header(403);
            nocache_headers();
            header('Content-Type: application/json; charset=utf-8');
            echo wp_json_encode([
                'success' => false,
                'error'   => 'Invalid or missing authentication token.',
            ]);
            exit;
        }

        // 2. Anti-DoS Throttling: Rate limit to at most 1 execution every 60 seconds
        $throttle_lock = function_exists('get_transient') ? get_transient('wp_auto_updates_webhook_throttle') : false;
        if ($throttle_lock !== false) {
            status_header(429);
            nocache_headers();
            header('Content-Type: application/json; charset=utf-8');
            echo wp_json_encode([
                'success' => false,
                'error'   => 'Rate limit active: webhook can be executed at most once every 60 seconds to protect server resources.',
                'retry_after_seconds' => max(1, 60 - (time() - (int)$throttle_lock)),
            ]);
            exit;
        }

        if (function_exists('set_transient')) {
            set_transient('wp_auto_updates_webhook_throttle', time(), 60);
        }

        // 3. Execute updater routine safely
        $this->execute_scheduled_update_check();

        status_header(200);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode([
            'success'   => true,
            'message'   => 'WP Auto Updates background routine executed successfully.',
            'timestamp' => time(),
            'datetime'  => gmdate('Y-m-d H:i:s') . ' UTC',
        ]);
        exit;
    }

    /**
     * ⚡ Non-Blocking Trigger: Fires when WordPress discovers new plugin updates.
     */
    public function trigger_async_instant_plugin_update($value, $transient = '') {
        if (self::$is_running_updater || empty($value->response) || !is_array($value->response)) {
            return;
        }

        $settings = self::get_settings();
        if (empty($settings['instant_updates'])) {
            return;
        }

        $has_enabled_pending = false;
        foreach ($value->response as $plugin_file => $item) {
            if ($this->is_plugin_auto_update_enabled($plugin_file)) {
                $has_enabled_pending = true;
                break;
            }
        }

        if ($has_enabled_pending) {
            $this->schedule_async_instant_updater();
        }
    }

    /**
     * ⚡ Non-Blocking Trigger: Fires when WordPress discovers new theme updates.
     */
    public function trigger_async_instant_theme_update($value, $transient = '') {
        if (self::$is_running_updater || empty($value->response) || !is_array($value->response)) {
            return;
        }

        $settings = self::get_settings();
        if (empty($settings['instant_updates'])) {
            return;
        }

        $has_enabled_pending = false;
        foreach ($value->response as $theme_slug => $item) {
            $slug = self::extract_theme_slug($theme_slug);
            if ($this->is_theme_auto_update_enabled($slug)) {
                $has_enabled_pending = true;
                break;
            }
        }

        if ($has_enabled_pending) {
            $this->schedule_async_instant_updater();
        }
    }

    /**
     * Schedules a non-blocking asynchronous background execution without delaying user HTTP requests.
     */
    private function schedule_async_instant_updater() {
        if (function_exists('wp_next_scheduled') && !wp_next_scheduled('wp_auto_updates_run_instant_background_update')) {
            wp_schedule_single_event(time(), 'wp_auto_updates_run_instant_background_update');
            if (function_exists('spawn_cron')) {
                spawn_cron();
            }
        }
    }

    /**
     * Forcefully and completely clears WordPress .maintenance mode lock and updater transients.
     */
    public static function force_clear_maintenance_lock() {
        // 1. Unlink ABSPATH .maintenance file
        $maintenance_file = ABSPATH . '.maintenance';
        if (file_exists($maintenance_file)) {
            @unlink($maintenance_file);
        }

        // 2. Clear auto-updater database locks
        if (function_exists('delete_site_option')) {
            delete_site_option('auto_updater.lock');
            delete_site_option('core_updater.lock');
        }
        if (function_exists('delete_transient')) {
            delete_transient('auto_updater.lock');
            delete_transient('core_updater.lock');
        }
        if (function_exists('delete_site_transient')) {
            delete_site_transient('auto_updater.lock');
            delete_site_transient('core_updater.lock');
        }
    }

    /**
     * Active Self-Healing: Inspects .maintenance file on early init.
     * If the site is locked and either no updater is active or the file is older than 15 seconds,
     * it immediately unlinks the file to guarantee 0 downtime.
     */
    public function auto_heal_maintenance_lock() {
        $maintenance_file = ABSPATH . '.maintenance';
        if (!file_exists($maintenance_file)) {
            return;
        }

        $file_mtime = @filemtime($maintenance_file);
        $age = $file_mtime ? (time() - $file_mtime) : 999;

        // If no updater is running right now in this PHP instance, or the file is > 15 seconds old
        if (!self::$is_running_updater || $age > 15) {
            self::force_clear_maintenance_lock();
            self::add_log_entry([
                'type'         => 'system',
                'item_slug'    => 'maintenance_auto_heal',
                'item_name'    => 'Zero-Downtime Maintenance Buster',
                'from_version' => '-',
                'to_version'   => '-',
                'status'       => 'info',
                'source'       => 'auto_heal_guard',
                'message'      => 'Stuck .maintenance file detected (' . $age . 's old) and cleared immediately to prevent 503 lockout.',
            ]);
        }
    }

    /**
     * Installs an early-boot mu-plugin drop-in to prevent WordPress from ever locking
     * the site in 503 maintenance mode beyond 15 seconds.
     */
    public static function ensure_mu_plugin_dropin() {
        if (!defined('WPMU_PLUGIN_DIR')) {
            define('WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins');
        }

        $mu_dir = WPMU_PLUGIN_DIR;
        if (!is_dir($mu_dir)) {
            if (function_exists('wp_mkdir_p')) {
                wp_mkdir_p($mu_dir);
            } else {
                @mkdir($mu_dir, 0755, true);
            }
        }

        $dropin_file = $mu_dir . '/wp-auto-updates-zero-downtime.php';
        if (!file_exists($dropin_file)) {
            $code = "<?php\n"
                . "/**\n"
                . " * Plugin Name: WP Auto Updates Zero-Downtime Guard (MU)\n"
                . " * Description: Ultra-early boot guard that clears stale .maintenance locks automatically.\n"
                . " * Version: 1.0.0\n"
                . " */\n"
                . "defined('ABSPATH') || exit;\n\n"
                . "(function() {\n"
                . "    \$mfile = ABSPATH . '.maintenance';\n"
                . "    if (file_exists(\$mfile)) {\n"
                . "        \$mtime = @filemtime(\$mfile);\n"
                . "        if (\$mtime && (time() - \$mtime > 20)) {\n"
                . "            @unlink(\$mfile);\n"
                . "        }\n"
                . "    }\n"
                . "})();\n";
            @file_put_contents($dropin_file, $code);
        }
    }

    /**
     * Self-healing: Clean stale updater locks and maintenance files.
     */
    public static function clean_stale_locks() {
        self::force_clear_maintenance_lock();
    }

    /**
     * Executes the background updater routine safely with dual-engine execution.
     */
    public function execute_background_updater_routine() {
        if (self::$is_running_updater) {
            return;
        }
        self::$is_running_updater = true;

        // Register fatal shutdown safeguard to clear .maintenance even if PHP runs out of memory or crashes
        register_shutdown_function([__CLASS__, 'force_clear_maintenance_lock']);

        try {
            if (function_exists('ignore_user_abort')) {
                @ignore_user_abort(true);
            }
            if (function_exists('set_time_limit')) {
                @set_time_limit(600);
            }
            @ini_set('memory_limit', '1024M');

            if (!function_exists('wp_maybe_auto_update') && file_exists(ABSPATH . 'wp-admin/includes/admin.php')) {
                require_once ABSPATH . 'wp-admin/includes/admin.php';
            }
            if (!class_exists('WP_Upgrader') && file_exists(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php')) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            }
            if (!class_exists('WP_Automatic_Updater') && file_exists(ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php')) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
            }
            if (!function_exists('request_filesystem_credentials') && file_exists(ABSPATH . 'wp-admin/includes/file.php')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/template.php';
            }

            // Self-healing locks
            self::force_clear_maintenance_lock();

            // ENGINE 1: WordPress Core Automatic Updater
            if (class_exists('WP_Automatic_Updater')) {
                $updater = new WP_Automatic_Updater();
                $updater->run();
                self::force_clear_maintenance_lock();
            }

            // ENGINE 2: Resilient Direct Fallback Upgrader (Catches any items skipped by Engine 1)
            $settings = self::get_settings();
            if (!empty($settings['enable_fallback_engine'])) {
                $this->execute_direct_fallback_upgrades();
            }

        } catch (\Throwable $e) {
            self::force_clear_maintenance_lock();
            // Self-healing: capture error without breaking WordPress execution
            self::add_log_entry([
                'type'         => 'system',
                'item_slug'    => 'updater_engine',
                'item_name'    => 'Background Updater Engine',
                'from_version' => '-',
                'to_version'   => '-',
                'status'       => 'failed',
                'source'       => 'exception_guard',
                'message'      => 'Background updater caught exception: ' . $e->getMessage(),
            ]);
        } finally {
            self::force_clear_maintenance_lock();
            self::$is_running_updater = false;
        }
    }

    /**
     * Resilient Fallback Engine: Directly upgrades any enabled plugins or themes
     * that still have pending updates available.
     */
    public function execute_direct_fallback_upgrades() {
        if (!function_exists('get_plugins') && file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!class_exists('Plugin_Upgrader') && file_exists(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        if (!class_exists('Theme_Upgrader') && file_exists(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        $plugins_transient = function_exists('get_site_transient') ? get_site_transient('update_plugins') : null;
        $themes_transient  = function_exists('get_site_transient') ? get_site_transient('update_themes') : null;
        $settings          = self::get_settings();
        $installed_plugins = function_exists('get_plugins') ? get_plugins() : [];

        // 1. Direct Fallback for Plugins
        if (!empty($plugins_transient->response) && is_array($plugins_transient->response)) {
            foreach ($plugins_transient->response as $plugin_file => $update_data) {
                // Check if plugin is enabled for updates
                if (!$this->is_plugin_auto_update_enabled($plugin_file)) {
                    continue;
                }

                $plugin_name = isset($installed_plugins[$plugin_file]['Name']) ? $installed_plugins[$plugin_file]['Name'] : $plugin_file;
                $current_ver = isset($installed_plugins[$plugin_file]['Version']) ? $installed_plugins[$plugin_file]['Version'] : '-';
                $target_ver  = is_object($update_data) && !empty($update_data->new_version) ? $update_data->new_version : '-';

                // Pre-Update Compatibility Guard
                if (!empty($settings['pre_update_compat_check']) && is_object($update_data)) {
                    $compat = self::check_version_compatibility($update_data);
                    if ($compat !== true) {
                        self::add_log_entry([
                            'type'         => 'plugin',
                            'item_slug'    => $plugin_file,
                            'item_name'    => $plugin_name,
                            'from_version' => $current_ver,
                            'to_version'   => $target_ver,
                            'status'       => 'failed',
                            'source'       => 'compat_guard',
                            'message'      => 'Update paused for safety: ' . $compat,
                        ]);
                        continue;
                    }
                }

                // Execute direct upgrade
                if (class_exists('Plugin_Upgrader')) {
                    $skin     = new WP_Auto_Updates_Silent_Skin();
                    $upgrader = new Plugin_Upgrader($skin);
                    $result   = $upgrader->upgrade($plugin_file);

                    // Force clear maintenance immediately after each item
                    self::force_clear_maintenance_lock();

                    if (is_wp_error($result)) {
                        $err = $result->get_error_message();
                        self::handle_update_failure('plugin', $plugin_file, $plugin_name, $current_ver, $target_ver, $err);
                    } elseif ($result === false || $result === null) {
                        $err = !empty($skin->messages) ? implode(' ', $skin->messages) : 'Direct upgrader returned false.';
                        self::handle_update_failure('plugin', $plugin_file, $plugin_name, $current_ver, $target_ver, $err);
                    } else {
                        // Success
                        self::add_log_entry([
                            'type'         => 'plugin',
                            'item_slug'    => $plugin_file,
                            'item_name'    => $plugin_name,
                            'from_version' => $current_ver,
                            'to_version'   => $target_ver,
                            'status'       => 'success',
                            'source'       => 'direct_fallback_engine',
                            'message'      => 'Successfully updated via Direct Fallback Engine.',
                        ]);
                        self::remove_from_retry_queue('plugin', $plugin_file);
                        self::probe_site_health_after_update('plugin', $plugin_file, $plugin_name);
                    }
                }
            }
        }

        // 2. Direct Fallback for Themes
        if (!empty($themes_transient->response) && is_array($themes_transient->response)) {
            foreach ($themes_transient->response as $theme_slug => $update_data) {
                if (!$this->is_theme_auto_update_enabled($theme_slug)) {
                    continue;
                }

                $theme_obj   = function_exists('wp_get_theme') ? wp_get_theme($theme_slug) : null;
                $theme_name  = ($theme_obj && is_object($theme_obj) && method_exists($theme_obj, 'exists') && $theme_obj->exists()) ? $theme_obj->get('Name') : $theme_slug;
                $current_ver = ($theme_obj && is_object($theme_obj) && method_exists($theme_obj, 'exists') && $theme_obj->exists()) ? $theme_obj->get('Version') : '-';
                $target_ver  = is_array($update_data) && !empty($update_data['new_version']) ? $update_data['new_version'] : (is_object($update_data) && !empty($update_data->new_version) ? $update_data->new_version : '-');

                if (class_exists('Theme_Upgrader')) {
                    $skin     = new WP_Auto_Updates_Silent_Skin();
                    $upgrader = new Theme_Upgrader($skin);
                    $result   = $upgrader->upgrade($theme_slug);

                    // Force clear maintenance immediately after each item
                    self::force_clear_maintenance_lock();

                    if (is_wp_error($result)) {
                        $err = $result->get_error_message();
                        self::handle_update_failure('theme', $theme_slug, $theme_name, $current_ver, $target_ver, $err);
                    } elseif ($result === false || $result === null) {
                        $err = !empty($skin->messages) ? implode(' ', $skin->messages) : 'Direct upgrader returned false.';
                        self::handle_update_failure('theme', $theme_slug, $theme_name, $current_ver, $target_ver, $err);
                    } else {
                        // Success
                        self::add_log_entry([
                            'type'         => 'theme',
                            'item_slug'    => $theme_slug,
                            'item_name'    => $theme_name,
                            'from_version' => $current_ver,
                            'to_version'   => $target_ver,
                            'status'       => 'success',
                            'source'       => 'direct_fallback_engine',
                            'message'      => 'Successfully updated via Direct Fallback Engine.',
                        ]);
                        self::remove_from_retry_queue('theme', $theme_slug);
                    }
                }
            }
        }
    }

    /**
     * Pre-Update Compatibility Guard: Validates PHP and WordPress versions.
     */
    public static function check_version_compatibility($update_data) {
        if (!is_object($update_data)) {
            return true;
        }

        // Check required PHP version
        if (!empty($update_data->requires_php)) {
            if (version_compare(PHP_VERSION, $update_data->requires_php, '<')) {
                return sprintf(
                    'Requires PHP %s+ (Server runs %s).',
                    $update_data->requires_php,
                    PHP_VERSION
                );
            }
        }

        // Check required WordPress version
        if (!empty($update_data->requires)) {
            global $wp_version;
            $current_wp = !empty($wp_version) ? $wp_version : '5.6';
            if (version_compare($current_wp, $update_data->requires, '<')) {
                return sprintf(
                    'Requires WordPress %s+ (Site runs %s).',
                    $update_data->requires,
                    $current_wp
                );
            }
        }

        return true;
    }

    /**
     * Enterprise Zero-Crash Safety Net: Post-Update Synthetic Loopback Probe.
     * Pings the site root immediately after an update. If a fatal crash or HTTP 500
     * is detected, automatically deactivates the crashing plugin to restore site uptime.
     */
    public static function probe_site_health_after_update($type, $slug, $name) {
        if ($type !== 'plugin') {
            return;
        }

        // Never self-deactivate this auto-updater plugin
        if (strpos($slug, 'wp-auto-updates') !== false) {
            return;
        }

        // Only probe if plugin is active
        if (!function_exists('is_plugin_active') && file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (function_exists('is_plugin_active') && !is_plugin_active($slug)) {
            return;
        }

        // Perform loopback health check
        $probe_url = home_url('/');
        $response  = wp_remote_get($probe_url, [
            'timeout'     => 5,
            'redirection' => 2,
            'sslverify'   => false,
            'headers'     => [
                'Cache-Control' => 'no-cache',
                'User-Agent'    => 'WP-Auto-Updates-Health-Probe/3.0',
            ],
        ]);

        if (is_wp_error($response)) {
            return; // Loopback not supported or temporary network issue
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // Check for HTTP 500 error or WordPress Fatal Error Die Screen
        $is_fatal = ($code >= 500) || (strpos($body, 'wp-die-message') !== false && (strpos($body, 'critical error') !== false || strpos($body, 'There has been a critical error') !== false));

        if ($is_fatal) {
            // Emergency Deactivation to immediately rescue the website
            if (function_exists('deactivate_plugins')) {
                deactivate_plugins($slug, true);

                self::add_log_entry([
                    'type'         => 'plugin',
                    'item_slug'    => $slug,
                    'item_name'    => $name,
                    'from_version' => '-',
                    'to_version'   => '-',
                    'status'       => 'failed',
                    'source'       => 'zero_crash_safety_probe',
                    'message'      => sprintf('CRITICAL SAFETY RESCUE: Updated plugin triggered fatal error (HTTP %d). Plugin was automatically deactivated to restore site uptime!', $code),
                ]);
            }
        }
    }

    /**
     * Intelligent Failure & Retry Queue Handler.
     */
    public static function handle_update_failure($type, $slug, $name, $from_ver, $to_ver, $error_msg) {
        $settings = self::get_settings();

        // Add failure log
        self::add_log_entry([
            'type'         => $type,
            'item_slug'    => $slug,
            'item_name'    => $name,
            'from_version' => $from_ver,
            'to_version'   => $to_ver,
            'status'       => 'failed',
            'source'       => 'upgrader_failure_handler',
            'message'      => $error_msg,
        ]);

        if (empty($settings['enable_retry_queue'])) {
            return;
        }

        // Enqueue to Retry Queue with exponential backoff
        $queue = self::get_db_option(self::RETRY_QUEUE_KEY, []);
        if (!is_array($queue)) {
            $queue = [];
        }

        $item_key = $type . ':' . $slug;
        $existing = $queue[$item_key] ?? null;

        $attempts = ($existing && !empty($existing['attempts'])) ? ((int)$existing['attempts'] + 1) : 1;

        if ($attempts > 3) {
            // Max retries exceeded
            unset($queue[$item_key]);
            self::update_db_option(self::RETRY_QUEUE_KEY, $queue);

            self::add_log_entry([
                'type'         => $type,
                'item_slug'    => $slug,
                'item_name'    => $name,
                'from_version' => $from_ver,
                'to_version'   => $to_ver,
                'status'       => 'failed',
                'source'       => 'retry_queue_exhausted',
                'message'      => sprintf('Max retries (3) reached. Last error: %s', $error_msg),
            ]);
            return;
        }

        // Exponential backoff: 1 -> 5 min, 2 -> 15 min, 3 -> 60 min
        $delay = ($attempts === 1) ? 300 : (($attempts === 2) ? 900 : 3600);
        $next_time = time() + $delay;

        $queue[$item_key] = [
            'type'            => $type,
            'slug'            => $slug,
            'name'            => $name,
            'from_version'    => $from_ver,
            'to_version'      => $to_ver,
            'attempts'        => $attempts,
            'next_retry_time' => $next_time,
            'last_error'      => $error_msg,
            'created_at'      => $existing['created_at'] ?? time(),
        ];

        self::update_db_option(self::RETRY_QUEUE_KEY, $queue);
    }

    /**
     * Remove an item from the retry queue upon success.
     */
    public static function remove_from_retry_queue($type, $slug) {
        $queue = self::get_db_option(self::RETRY_QUEUE_KEY, []);
        if (!is_array($queue)) {
            return;
        }
        $item_key = $type . ':' . $slug;
        if (isset($queue[$item_key])) {
            unset($queue[$item_key]);
            self::update_db_option(self::RETRY_QUEUE_KEY, $queue);
        }
    }

    /**
     * Process Retry Queue via Cron.
     */
    public function process_retry_queue() {
        $queue = self::get_db_option(self::RETRY_QUEUE_KEY, []);
        if (empty($queue) || !is_array($queue)) {
            return;
        }

        $now = time();
        $has_due_items = false;

        foreach ($queue as $item_key => $item) {
            if (!empty($item['next_retry_time']) && $item['next_retry_time'] <= $now) {
                $has_due_items = true;
                break;
            }
        }

        if ($has_due_items) {
            $this->execute_background_updater_routine();
        }
    }

    /**
     * Helper to check if a plugin is enabled for auto-updates.
     */
    public function is_plugin_auto_update_enabled($plugin_file) {
        $settings = self::get_settings();
        $saved    = $settings['plugins'] ?? [];

        if (isset($saved[$plugin_file])) {
            return (bool)$saved[$plugin_file];
        }

        // Directory or slug matching (e.g. 'advanced-custom-fields/acf.php' vs 'advanced-custom-fields')
        foreach ($saved as $saved_file => $enabled) {
            $saved_dir = dirname($saved_file);
            if ($saved_dir === $plugin_file || dirname($plugin_file) === $saved_dir || strpos($saved_file, $plugin_file . '/') === 0 || strpos($plugin_file, $saved_dir . '/') === 0) {
                return (bool)$enabled;
            }
        }

        // Auto-enroll policy fallback for newly discovered plugins
        if (!empty($settings['auto_enroll_new'])) {
            return true;
        }

        return false;
    }

    /**
     * Helper to check if a theme is enabled for auto-updates.
     */
    public function is_theme_auto_update_enabled($theme_slug) {
        $settings = self::get_settings();
        $saved    = $settings['themes'] ?? [];

        if (isset($saved[$theme_slug])) {
            return (bool)$saved[$theme_slug];
        }

        // Auto-enroll policy fallback
        if (!empty($settings['auto_enroll_new'])) {
            return true;
        }

        return false;
    }

    /**
     * Core Filter: Determine if specific plugin should auto-update.
     */
    public function filter_plugin_auto_update($update, $item) {
        $plugin_file = self::extract_plugin_slug($item);
        if (empty($plugin_file)) {
            return $update;
        }
        return $this->is_plugin_auto_update_enabled($plugin_file);
    }

    /**
     * Core Filter: Determine if specific theme should auto-update.
     */
    public function filter_theme_auto_update($update, $item) {
        $theme_slug = self::extract_theme_slug($item);
        if (empty($theme_slug)) {
            return $update;
        }
        return $this->is_theme_auto_update_enabled($theme_slug);
    }

    /**
     * Core Filter: Suppress update notification emails if configured.
     */
    public function filter_update_emails($send_email) {
        $settings = self::get_settings();
        if (!empty($settings['disable_emails'])) {
            return false;
        }
        return $send_email;
    }

    /**
     * Handle Settings Save with strict whitelisting & validation.
     */
    public function handle_save_settings() {
        if (!current_user_can(self::get_required_capability())) {
            wp_die(esc_html__('Unauthorized access.', 'wp-auto-updates'), 403);
        }

        check_admin_referer('wp_auto_updates_save_action', 'wp_auto_updates_nonce');

        if (!function_exists('get_plugins') && file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_installed_plugins = function_exists('get_plugins') ? array_keys(get_plugins()) : [];
        $all_installed_themes  = function_exists('wp_get_themes') ? array_keys(wp_get_themes()) : [];

        $current_settings = self::get_settings();
        $active_tab       = isset($_POST['active_tab']) ? sanitize_text_field($_POST['active_tab']) : 'plugins';

        $valid_plugins = $current_settings['plugins'] ?? [];
        $valid_themes  = $current_settings['themes'] ?? [];

        // When saving from the Plugins tab, explicitly record 1 for checked, 0 for unchecked
        if ($active_tab === 'plugins' || isset($_POST['wp_auto_update_plugins_submitted'])) {
            $submitted_plugins = isset($_POST['wp_auto_update_plugins']) && is_array($_POST['wp_auto_update_plugins'])
                ? $_POST['wp_auto_update_plugins']
                : [];

            $valid_plugins = [];
            foreach ($all_installed_plugins as $plugin_file) {
                $sanitized = sanitize_text_field($plugin_file);
                $valid_plugins[$sanitized] = (!empty($submitted_plugins[$sanitized])) ? 1 : 0;
            }

            // Sync with WordPress Core's auto_update_plugins option
            $enabled_plugins = array_keys(array_filter($valid_plugins));
            self::update_db_option('auto_update_plugins', $enabled_plugins);
        }

        // When saving from the Themes tab, explicitly record 1 for checked, 0 for unchecked
        if ($active_tab === 'themes' || isset($_POST['wp_auto_update_themes_submitted'])) {
            $submitted_themes = isset($_POST['wp_auto_update_themes']) && is_array($_POST['wp_auto_update_themes'])
                ? $_POST['wp_auto_update_themes']
                : [];

            $valid_themes = [];
            foreach ($all_installed_themes as $theme_slug) {
                $sanitized = sanitize_text_field($theme_slug);
                $valid_themes[$sanitized] = (!empty($submitted_themes[$sanitized])) ? 1 : 0;
            }

            // Sync with WordPress Core's auto_update_themes option
            $enabled_themes = array_keys(array_filter($valid_themes));
            self::update_db_option('auto_update_themes', $enabled_themes);
        }

        // Global settings
        $disable_emails          = isset($_POST['wp_auto_update_disable_emails']) ? (!empty($_POST['wp_auto_update_disable_emails']) ? 1 : 0) : ($current_settings['disable_emails'] ?? 0);
        $instant_updates         = isset($_POST['wp_auto_update_instant_updates']) ? (!empty($_POST['wp_auto_update_instant_updates']) ? 1 : 0) : ($current_settings['instant_updates'] ?? 1);
        $auto_enroll_new         = isset($_POST['wp_auto_update_auto_enroll_new']) ? (!empty($_POST['wp_auto_update_auto_enroll_new']) ? 1 : 0) : ($current_settings['auto_enroll_new'] ?? 1);
        $enable_fallback_engine  = isset($_POST['wp_auto_update_enable_fallback_engine']) ? (!empty($_POST['wp_auto_update_enable_fallback_engine']) ? 1 : 0) : ($current_settings['enable_fallback_engine'] ?? 1);
        $enable_retry_queue      = isset($_POST['wp_auto_update_enable_retry_queue']) ? (!empty($_POST['wp_auto_update_enable_retry_queue']) ? 1 : 0) : ($current_settings['enable_retry_queue'] ?? 1);
        $pre_update_compat_check = isset($_POST['wp_auto_update_pre_update_compat_check']) ? (!empty($_POST['wp_auto_update_pre_update_compat_check']) ? 1 : 0) : ($current_settings['pre_update_compat_check'] ?? 1);

        $check_freq = isset($_POST['wp_auto_update_check_frequency']) && in_array($_POST['wp_auto_update_check_frequency'], ['15min', '30min', 'hourly', '2hours', 'twicedaily', 'daily'], true)
            ? sanitize_key($_POST['wp_auto_update_check_frequency'])
            : ($current_settings['check_frequency'] ?? 'hourly');

        $max_logs = isset($_POST['wp_auto_update_max_logs']) ? absint($_POST['wp_auto_update_max_logs']) : ($current_settings['max_logs'] ?? self::MAX_LOG_ENTRIES);
        if ($max_logs < 50) $max_logs = 50;
        if ($max_logs > 2000) $max_logs = 2000;

        $webhook_token = !empty($current_settings['webhook_token']) ? $current_settings['webhook_token'] : self::generate_token();
        if (!empty($_POST['wp_auto_updates_regenerate_token'])) {
            $webhook_token = self::generate_token();
        }

        $new_settings = [
            'plugins'                 => $valid_plugins,
            'themes'                  => $valid_themes,
            'disable_emails'          => $disable_emails,
            'instant_updates'         => $instant_updates,
            'check_frequency'         => $check_freq,
            'max_logs'                => $max_logs,
            'auto_enroll_new'         => $auto_enroll_new,
            'enable_fallback_engine'  => $enable_fallback_engine,
            'enable_retry_queue'      => $enable_retry_queue,
            'pre_update_compat_check' => $pre_update_compat_check,
            'webhook_token'           => $webhook_token,
        ];

        self::update_db_option(self::SETTINGS_KEY, $new_settings);

        // Reschedule cron if frequency changed
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook('wp_auto_updates_frequent_check');
            wp_schedule_event(time(), $check_freq, 'wp_auto_updates_frequent_check');
        }

        $current_user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $username = ($current_user && is_object($current_user) && !empty($current_user->user_login)) ? $current_user->user_login : 'admin';

        self::add_log_entry([
            'type'        => 'system',
            'item_slug'   => 'settings',
            'item_name'   => 'Auto Updates Settings',
            'status'      => 'success',
            'source'      => 'admin_user (' . $username . ')',
            'message'     => sprintf('Updated rules: %d plugins enabled, %d themes enabled. Fallback Engine: %s, Retry Queue: %s, Auto-Enroll: %s (Check Frequency: %s).', count(array_filter($valid_plugins)), count(array_filter($valid_themes)), $enable_fallback_engine ? 'ON' : 'OFF', $enable_retry_queue ? 'ON' : 'OFF', $auto_enroll_new ? 'ON' : 'OFF', $check_freq),
        ]);

        $redirect_url = (function_exists('is_network_admin') && is_network_admin()) ? network_admin_url('admin.php') : admin_url('admin.php');

        wp_safe_redirect(add_query_arg([
            'page'    => 'wp-auto-updates',
            'tab'     => $active_tab,
            'updated' => '1',
        ], $redirect_url));
        exit;
    }

    /**
     * Handle Force Run Auto-Updater.
     */
    public function handle_force_run_updates() {
        if (!current_user_can(self::get_required_capability())) {
            wp_die(esc_html__('Unauthorized.', 'wp-auto-updates'), 403);
        }

        check_admin_referer('wp_auto_updates_force_run_action', 'wp_auto_updates_force_run_nonce');

        if (function_exists('delete_site_transient')) {
            delete_site_transient('update_plugins');
            delete_site_transient('update_themes');
            delete_site_transient('update_core');
        }

        if (function_exists('wp_update_plugins')) {
            wp_update_plugins();
        }
        if (function_exists('wp_update_themes')) {
            wp_update_themes();
        }
        if (function_exists('wp_version_check')) {
            wp_version_check();
        }

        $this->execute_background_updater_routine();

        $redirect_url = (function_exists('is_network_admin') && is_network_admin()) ? network_admin_url('admin.php') : admin_url('admin.php');

        wp_safe_redirect(add_query_arg([
            'page'           => 'wp-auto-updates',
            'tab'            => 'logs',
            'updates_forced' => '1',
        ], $redirect_url));
        exit;
    }

    /**
     * Handle Immediate Retry of All Queued Items.
     */
    public function handle_retry_now() {
        if (!current_user_can(self::get_required_capability())) {
            wp_die(esc_html__('Unauthorized.', 'wp-auto-updates'), 403);
        }

        check_admin_referer('wp_auto_updates_retry_action', 'wp_auto_updates_retry_nonce');

        // Reset retry times to now and trigger routine
        $queue = self::get_db_option(self::RETRY_QUEUE_KEY, []);
        if (is_array($queue)) {
            foreach ($queue as $k => $item) {
                $queue[$k]['next_retry_time'] = time() - 10;
            }
            self::update_db_option(self::RETRY_QUEUE_KEY, $queue);
        }

        $this->execute_background_updater_routine();

        $redirect_url = (function_exists('is_network_admin') && is_network_admin()) ? network_admin_url('admin.php') : admin_url('admin.php');
        wp_safe_redirect(add_query_arg([
            'page'           => 'wp-auto-updates',
            'tab'            => 'retries',
            'retries_forced' => '1',
        ], $redirect_url));
        exit;
    }

    /**
     * Handle Clearing the Retry Queue.
     */
    public function handle_clear_retries() {
        if (!current_user_can(self::get_required_capability())) {
            wp_die(esc_html__('Unauthorized.', 'wp-auto-updates'), 403);
        }

        check_admin_referer('wp_auto_updates_clear_retries_action', 'wp_auto_updates_clear_retries_nonce');
        self::delete_db_option(self::RETRY_QUEUE_KEY);

        $redirect_url = (function_exists('is_network_admin') && is_network_admin()) ? network_admin_url('admin.php') : admin_url('admin.php');
        wp_safe_redirect(add_query_arg([
            'page'            => 'wp-auto-updates',
            'tab'             => 'retries',
            'retries_cleared' => '1',
        ], $redirect_url));
        exit;
    }

    /**
     * Resilient Helper: Extract plugin file path from various data representations.
     */
    public static function extract_plugin_slug($item) {
        if (is_string($item)) {
            return $item;
        }
        if (is_object($item)) {
            if (!empty($item->plugin)) {
                return (string) $item->plugin;
            }
            if (!empty($item->slug)) {
                return (string) $item->slug;
            }
            if (!empty($item->id)) {
                return (string) $item->id;
            }
        }
        if (is_array($item)) {
            if (!empty($item['plugin'])) {
                return (string) $item['plugin'];
            }
            if (!empty($item['slug'])) {
                return (string) $item['slug'];
            }
            if (!empty($item['id'])) {
                return (string) $item['id'];
            }
        }
        return '';
    }

    /**
     * Resilient Helper: Extract theme slug from various data representations.
     */
    public static function extract_theme_slug($item) {
        if (is_string($item)) {
            return $item;
        }
        if (is_object($item)) {
            if (!empty($item->theme)) {
                return (string) $item->theme;
            }
            if (!empty($item->stylesheet)) {
                return (string) $item->stylesheet;
            }
        }
        if (is_array($item)) {
            if (!empty($item['theme'])) {
                return (string) $item['theme'];
            }
            if (!empty($item['stylesheet'])) {
                return (string) $item['stylesheet'];
            }
        }
        return '';
    }

    /**
     * Logging Engine: Add structured log entry with FIFO ring buffer and deduplication.
     */
    public static function add_log_entry(array $entry) {
        $defaults = [
            'id'           => uniqid('log_', true),
            'timestamp'    => current_time('timestamp'),
            'datetime'     => current_time('mysql'),
            'type'         => 'plugin', // plugin, theme, system
            'item_slug'    => '',
            'item_name'    => '',
            'from_version' => '-',
            'to_version'   => '-',
            'status'       => 'success', // success, failed, info
            'source'       => 'instant_auto_updater',
            'message'      => '',
        ];

        $log_entry = wp_parse_args($entry, $defaults);

        // Strict sanitization of all fields against stored XSS / malformed data
        $log_entry = [
            'id'           => sanitize_key($log_entry['id']),
            'timestamp'    => (int) $log_entry['timestamp'],
            'datetime'     => sanitize_text_field($log_entry['datetime']),
            'type'         => sanitize_key($log_entry['type']),
            'item_slug'    => sanitize_text_field($log_entry['item_slug']),
            'item_name'    => sanitize_text_field(wp_strip_all_tags($log_entry['item_name'])),
            'from_version' => sanitize_text_field($log_entry['from_version']),
            'to_version'   => sanitize_text_field($log_entry['to_version']),
            'status'       => sanitize_key($log_entry['status']),
            'source'       => sanitize_text_field($log_entry['source']),
            'message'      => sanitize_text_field(wp_strip_all_tags($log_entry['message'])),
        ];

        // Deduplication fingerprint
        $fingerprint = md5(implode('|', [
            $log_entry['type'],
            $log_entry['item_slug'],
            $log_entry['to_version'],
            $log_entry['status']
        ]));

        if (isset(self::$logged_this_request[$fingerprint])) {
            return;
        }
        self::$logged_this_request[$fingerprint] = true;

        $logs = self::get_db_option(self::LOGS_KEY, []);
        if (!is_array($logs)) {
            $logs = [];
        }

        // Prepend new entry
        array_unshift($logs, $log_entry);

        // Enforce FIFO buffer capacity
        $settings = self::get_settings();
        $max_logs = !empty($settings['max_logs']) ? (int) $settings['max_logs'] : self::MAX_LOG_ENTRIES;
        if (count($logs) > $max_logs) {
            $logs = array_slice($logs, 0, $max_logs);
        }

        self::update_db_option(self::LOGS_KEY, $logs, false);
    }

    /**
     * Audit Hook: automatic_updates_complete.
     */
    public function log_automatic_updates_complete($results) {
        if (empty($results) || !is_array($results)) {
            return;
        }

        if (!function_exists('get_plugins') && file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = function_exists('get_plugins') ? get_plugins() : [];

        // Process Plugin Update Results
        if (!empty($results['plugin']) && is_array($results['plugin'])) {
            foreach ($results['plugin'] as $item) {
                if (!is_object($item)) continue;

                $slug = self::extract_plugin_slug($item->item ?? $item);
                $name = isset($plugins[$slug]['Name']) ? $plugins[$slug]['Name'] : ($item->name ?? $slug);
                $is_success = ($item->result === true);

                $err_msg = 'Update completed.';
                if (!$is_success) {
                    if (is_wp_error($item->result)) {
                        $err_msg = $item->result->get_error_message();
                    } elseif (is_string($item->result)) {
                        $err_msg = $item->result;
                    } else {
                        $err_msg = 'Automatic update failed.';
                    }
                }

                $old_ver = is_object($item->item ?? null) ? ($item->item->old_version ?? '-') : '-';
                $new_ver = is_object($item->item ?? null) ? ($item->item->new_version ?? '-') : '-';

                self::add_log_entry([
                    'type'         => 'plugin',
                    'item_slug'    => $slug,
                    'item_name'    => $name,
                    'from_version' => $old_ver,
                    'to_version'   => $new_ver,
                    'status'       => $is_success ? 'success' : 'failed',
                    'source'       => 'native_wp_engine',
                    'message'      => $is_success ? 'Updated successfully via Core Engine.' : $err_msg,
                ]);

                if ($is_success) {
                    self::remove_from_retry_queue('plugin', $slug);
                } else {
                    self::handle_update_failure('plugin', $slug, $name, $old_ver, $new_ver, $err_msg);
                }
            }
        }

        // Process Theme Update Results
        if (!empty($results['theme']) && is_array($results['theme'])) {
            foreach ($results['theme'] as $item) {
                if (!is_object($item)) continue;

                $slug = self::extract_theme_slug($item->item ?? $item);
                $theme_obj = function_exists('wp_get_theme') ? wp_get_theme($slug) : null;
                $name = ($theme_obj && is_object($theme_obj) && method_exists($theme_obj, 'exists') && $theme_obj->exists()) ? $theme_obj->get('Name') : ($item->name ?? $slug);
                $is_success = ($item->result === true);

                $err_msg = 'Update completed.';
                if (!$is_success) {
                    if (is_wp_error($item->result)) {
                        $err_msg = $item->result->get_error_message();
                    } elseif (is_string($item->result)) {
                        $err_msg = $item->result;
                    } else {
                        $err_msg = 'Automatic update failed.';
                    }
                }

                $old_ver = is_object($item->item ?? null) ? ($item->item->old_version ?? '-') : '-';
                $new_ver = is_object($item->item ?? null) ? ($item->item->new_version ?? '-') : '-';

                self::add_log_entry([
                    'type'         => 'theme',
                    'item_slug'    => $slug,
                    'item_name'    => $name,
                    'from_version' => $old_ver,
                    'to_version'   => $new_ver,
                    'status'       => $is_success ? 'success' : 'failed',
                    'source'       => 'native_wp_engine',
                    'message'      => $is_success ? 'Updated successfully via Core Engine.' : $err_msg,
                ]);

                if ($is_success) {
                    self::remove_from_retry_queue('theme', $slug);
                } else {
                    self::handle_update_failure('theme', $slug, $name, $old_ver, $new_ver, $err_msg);
                }
            }
        }
    }

    /**
     * Audit Hook: upgrader_process_complete.
     */
    public function log_upgrader_process_complete($upgrader_object, $options) {
        if (empty($options['action']) || $options['action'] !== 'update') {
            return;
        }

        $type = $options['type'] ?? '';
        if (!in_array($type, ['plugin', 'theme'], true)) {
            return;
        }

        $source = (function_exists('wp_doing_cron') && wp_doing_cron()) ? 'wp-cron' : 'manual/admin';

        if ($type === 'plugin' && !empty($options['plugins'])) {
            if (!function_exists('get_plugins') && file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $plugins = function_exists('get_plugins') ? get_plugins() : [];

            foreach ($options['plugins'] as $plugin_file) {
                $name = isset($plugins[$plugin_file]['Name']) ? $plugins[$plugin_file]['Name'] : $plugin_file;
                $current_ver = isset($plugins[$plugin_file]['Version']) ? $plugins[$plugin_file]['Version'] : '-';
                self::add_log_entry([
                    'type'         => 'plugin',
                    'item_slug'    => $plugin_file,
                    'item_name'    => $name,
                    'from_version' => '-',
                    'to_version'   => $current_ver,
                    'status'       => 'success',
                    'source'       => $source,
                    'message'      => 'Plugin upgrade completed.',
                ]);
                self::remove_from_retry_queue('plugin', $plugin_file);
                self::probe_site_health_after_update('plugin', $plugin_file, $name);
            }
        } elseif ($type === 'theme' && !empty($options['themes'])) {
            foreach ($options['themes'] as $theme_slug) {
                $theme_obj = function_exists('wp_get_theme') ? wp_get_theme($theme_slug) : null;
                $name = ($theme_obj && is_object($theme_obj) && method_exists($theme_obj, 'exists') && $theme_obj->exists()) ? $theme_obj->get('Name') : $theme_slug;
                $current_ver = ($theme_obj && is_object($theme_obj) && method_exists($theme_obj, 'exists') && $theme_obj->exists()) ? $theme_obj->get('Version') : '-';
                self::add_log_entry([
                    'type'         => 'theme',
                    'item_slug'    => $theme_slug,
                    'item_name'    => $name,
                    'from_version' => '-',
                    'to_version'   => $current_ver,
                    'status'       => 'success',
                    'source'       => $source,
                    'message'      => 'Theme upgrade completed.',
                ]);
                self::remove_from_retry_queue('theme', $theme_slug);
            }
        }
    }

    /**
     * Handle Clear Logs Action.
     */
    public function handle_clear_logs() {
        if (!current_user_can(self::get_required_capability())) {
            wp_die(esc_html__('Unauthorized.', 'wp-auto-updates'), 403);
        }

        check_admin_referer('wp_auto_updates_clear_logs_action', 'wp_auto_updates_clear_nonce');
        self::delete_db_option(self::LOGS_KEY);

        $current_user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $username = ($current_user && is_object($current_user) && !empty($current_user->user_login)) ? $current_user->user_login : 'admin';

        self::add_log_entry([
            'type'      => 'system',
            'item_slug' => 'logs',
            'item_name' => 'Audit Log Purge',
            'status'    => 'info',
            'source'    => 'admin_user (' . $username . ')',
            'message'   => 'Audit logs were manually purged.',
        ]);

        $redirect_url = (function_exists('is_network_admin') && is_network_admin()) ? network_admin_url('admin.php') : admin_url('admin.php');

        wp_safe_redirect(add_query_arg([
            'page'         => 'wp-auto-updates',
            'tab'          => 'logs',
            'logs_cleared' => '1',
        ], $redirect_url));
        exit;
    }

    /**
     * Handle CSV Export of Audit Logs.
     */
    public function handle_export_logs_csv() {
        if (!current_user_can(self::get_required_capability())) {
            wp_die(esc_html__('Unauthorized.', 'wp-auto-updates'), 403);
        }

        check_admin_referer('wp_auto_updates_export_logs_action', 'wp_auto_updates_export_nonce');

        $logs = self::get_db_option(self::LOGS_KEY, []);
        $filename = 'wp-auto-updates-audit-log-' . gmdate('Y-m-d-His') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Date Time', 'Type', 'Slug', 'Item Name', 'From Version', 'To Version', 'Status', 'Source', 'Message']);

        // CSV Formula Injection (DDE) sanitizer
        $sanitize_csv_cell = function($val) {
            $str = (string) $val;
            if (isset($str[0]) && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                return "'" . $str;
            }
            return $str;
        };

        foreach ($logs as $log) {
            fputcsv($output, array_map($sanitize_csv_cell, [
                $log['id'] ?? '',
                $log['datetime'] ?? '',
                $log['type'] ?? '',
                $log['item_slug'] ?? '',
                $log['item_name'] ?? '',
                $log['from_version'] ?? '',
                $log['to_version'] ?? '',
                $log['status'] ?? '',
                $log['source'] ?? '',
                $log['message'] ?? '',
            ]));
        }

        fclose($output);
        exit;
    }

    /**
     * Handle JSON Export of Audit Logs.
     */
    public function handle_export_logs_json() {
        if (!current_user_can(self::get_required_capability())) {
            wp_die(esc_html__('Unauthorized.', 'wp-auto-updates'), 403);
        }

        check_admin_referer('wp_auto_updates_export_logs_json_action', 'wp_auto_updates_export_json_nonce');

        $logs = self::get_db_option(self::LOGS_KEY, []);
        $filename = 'wp-auto-updates-audit-log-' . gmdate('Y-m-d-His') . '.json';

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo wp_json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Render the Unified Enterprise Dashboard.
     */
    public function render_admin_dashboard() {
        if (!current_user_can(self::get_required_capability())) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'wp-auto-updates'));
        }

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'plugins';
        if (!in_array($active_tab, ['plugins', 'themes', 'logs', 'retries', 'settings', 'diagnostics'], true)) {
            $active_tab = 'plugins';
        }

        $settings      = self::get_settings();
        $audit_logs    = self::get_db_option(self::LOGS_KEY, []);
        $retry_queue   = self::get_db_option(self::RETRY_QUEUE_KEY, []);
        $retry_count   = is_array($retry_queue) ? count($retry_queue) : 0;

        $form_action_url = (function_exists('is_network_admin') && is_network_admin()) ? network_admin_url('admin-post.php') : admin_url('admin-post.php');
        $base_url        = (function_exists('is_network_admin') && is_network_admin()) ? network_admin_url('admin.php?page=wp-auto-updates') : admin_url('admin.php?page=wp-auto-updates');

        ?>
        <div class="wrap wau-admin-wrap">
            <h1 class="wau-main-title">
                <span class="dashicons dashicons-update" style="font-size:32px;width:32px;height:32px;margin-right:8px;vertical-align:middle;color:#2271b1;"></span>
                <?php esc_html_e('Auto Updates Manager (Enterprise Edition)', 'wp-auto-updates'); ?>
                <span class="wau-badge-version">v3.0.0</span>
            </h1>

            <?php if (isset($_GET['updated']) && $_GET['updated'] === '1') : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php esc_html_e('Configuration and rules updated successfully.', 'wp-auto-updates'); ?></strong></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['logs_cleared']) && $_GET['logs_cleared'] === '1') : ?>
                <div class="notice notice-info is-dismissible">
                    <p><?php esc_html_e('Audit trail log records have been purged.', 'wp-auto-updates'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['retries_cleared']) && $_GET['retries_cleared'] === '1') : ?>
                <div class="notice notice-info is-dismissible">
                    <p><?php esc_html_e('Retry queue has been cleared.', 'wp-auto-updates'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['retries_forced']) && $_GET['retries_forced'] === '1') : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php esc_html_e('Retry queue processed and background updates executed.', 'wp-auto-updates'); ?></strong></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['updates_forced']) && $_GET['updates_forced'] === '1') : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php esc_html_e('Manual update check completed! Stale locks purged, transients refreshed, and background upgrader executed.', 'wp-auto-updates'); ?></strong></p>
                </div>
            <?php endif; ?>

            <!-- Navigation Tabs -->
            <nav class="nav-tab-wrapper wau-nav-tabs">
                <a href="<?php echo esc_url(add_query_arg('tab', 'plugins', $base_url)); ?>" class="nav-tab <?php echo ($active_tab === 'plugins') ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-plugins"></span> <?php esc_html_e('Plugins', 'wp-auto-updates'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'themes', $base_url)); ?>" class="nav-tab <?php echo ($active_tab === 'themes') ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-appearance"></span> <?php esc_html_e('Themes', 'wp-auto-updates'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'logs', $base_url)); ?>" class="nav-tab <?php echo ($active_tab === 'logs') ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-list-view"></span> <?php esc_html_e('Audit Trail Logs', 'wp-auto-updates'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'retries', $base_url)); ?>" class="nav-tab <?php echo ($active_tab === 'retries') ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-image-rotate"></span> <?php esc_html_e('Retry Queue', 'wp-auto-updates'); ?>
                    <?php if ($retry_count > 0) : ?>
                        <span class="wau-badge-counter"><?php echo (int)$retry_count; ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'settings', $base_url)); ?>" class="nav-tab <?php echo ($active_tab === 'settings') ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('Settings & Webhook', 'wp-auto-updates'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'diagnostics', $base_url)); ?>" class="nav-tab <?php echo ($active_tab === 'diagnostics') ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-heart"></span> <?php esc_html_e('System Diagnostics', 'wp-auto-updates'); ?>
                </a>
            </nav>

            <div class="wau-tab-content" style="margin-top:20px;">
                <?php
                switch ($active_tab) {
                    case 'plugins':
                        $this->render_plugins_tab($settings, $form_action_url);
                        break;
                    case 'themes':
                        $this->render_themes_tab($settings, $form_action_url);
                        break;
                    case 'logs':
                        $this->render_logs_tab($audit_logs, $form_action_url);
                        break;
                    case 'retries':
                        $this->render_retries_tab($retry_queue, $form_action_url);
                        break;
                    case 'settings':
                        $this->render_settings_tab($settings, $form_action_url);
                        break;
                    case 'diagnostics':
                        $this->render_diagnostics_tab();
                        break;
                }
                ?>
            </div>
        </div>

        <style>
            .wau-admin-wrap { max-width: 1250px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, sans-serif; }
            .wau-main-title { font-size: 24px; font-weight: 600; margin-bottom: 15px; }
            .wau-badge-version { font-size: 13px; background: #2271b1; color: #fff; padding: 3px 8px; border-radius: 12px; vertical-align: middle; margin-left: 8px; font-weight: 500; }
            .wau-badge-counter { background: #d63638; color: #fff; padding: 2px 7px; border-radius: 10px; font-size: 11px; margin-left: 5px; font-weight: 600; }
            .wau-nav-tabs .dashicons { font-size: 18px; width: 18px; height: 18px; vertical-align: middle; margin-right: 4px; }
            .wau-card { background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 2px rgba(0,0,0,0.05); border-radius: 4px; padding: 20px; margin-bottom: 20px; }
            .wau-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px; }
            .wau-search-box { padding: 6px 10px; border: 1px solid #8c8f94; border-radius: 4px; width: 280px; }
            .wau-status-pill { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
            .wau-status-active { background: #e7f7ed; color: #007a3d; border: 1px solid #b7e6c9; }
            .wau-status-inactive { background: #f0f0f1; color: #50575e; border: 1px solid #dcdcde; }
            .wau-status-success { background: #e7f7ed; color: #007a3d; }
            .wau-status-failed { background: #fcf0f1; color: #d63638; }
            .wau-status-info { background: #f0f6fc; color: #2271b1; }
            .wau-btn-group button { margin-right: 6px; }
            .wau-code-snippet { background: #f6f7f7; padding: 12px; border-radius: 4px; font-family: Consolas, Monaco, monospace; border: 1px solid #dcdcde; word-break: break-all; margin: 8px 0; }
            .wau-toggle-switch { position: relative; display: inline-block; width: 44px; height: 22px; }
            .wau-toggle-switch input { opacity: 0; width: 0; height: 0; }
            .wau-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .3s; border-radius: 22px; }
            .wau-slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; }
            input:checked + .wau-slider { background-color: #2271b1; }
            input:checked + .wau-slider:before { transform: translateX(22px); }
        </style>
        <?php
    }

    /**
     * Render Plugins Tab.
     */
    private function render_plugins_tab($settings, $form_action_url) {
        if (!function_exists('get_plugins') && file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = function_exists('get_plugins') ? get_plugins() : [];
        $saved_plugins = $settings['plugins'] ?? [];
        $auto_enroll = !empty($settings['auto_enroll_new']);
        ?>
        <div class="wau-card">
            <h2><?php esc_html_e('Plugin Auto-Update Rules', 'wp-auto-updates'); ?></h2>
            <p class="description">
                <?php esc_html_e('Select which plugins should automatically update immediately upon release. Enabled items run non-blocking background upgrades with zero frontend lag.', 'wp-auto-updates'); ?>
            </p>

            <?php if ($auto_enroll) : ?>
                <div style="background:#e8f4fd; border-left:4px solid #2271b1; padding:10px 14px; margin-bottom:15px; border-radius:3px;">
                    <strong><?php esc_html_e('Auto-Enrollment Active:', 'wp-auto-updates'); ?></strong>
                    <?php esc_html_e('Newly installed plugins are automatically enabled for auto-updates. You can explicitly uncheck any plugin below to disable it.', 'wp-auto-updates'); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url($form_action_url); ?>">
                <?php wp_nonce_field('wp_auto_updates_save_action', 'wp_auto_updates_nonce'); ?>
                <input type="hidden" name="action" value="wp_auto_updates_save_settings" />
                <input type="hidden" name="active_tab" value="plugins" />

                <!-- Preserve Other Settings -->
                <input type="hidden" name="wp_auto_update_disable_emails" value="<?php echo esc_attr($settings['disable_emails']); ?>" />
                <input type="hidden" name="wp_auto_update_instant_updates" value="<?php echo esc_attr($settings['instant_updates']); ?>" />
                <input type="hidden" name="wp_auto_update_auto_enroll_new" value="<?php echo esc_attr($settings['auto_enroll_new']); ?>" />
                <input type="hidden" name="wp_auto_update_enable_fallback_engine" value="<?php echo esc_attr($settings['enable_fallback_engine']); ?>" />
                <input type="hidden" name="wp_auto_update_enable_retry_queue" value="<?php echo esc_attr($settings['enable_retry_queue']); ?>" />
                <input type="hidden" name="wp_auto_update_pre_update_compat_check" value="<?php echo esc_attr($settings['pre_update_compat_check']); ?>" />
                <input type="hidden" name="wp_auto_update_check_frequency" value="<?php echo esc_attr($settings['check_frequency']); ?>" />
                <input type="hidden" name="wp_auto_update_max_logs" value="<?php echo esc_attr($settings['max_logs']); ?>" />

                <input type="hidden" name="wp_auto_update_plugins_submitted" value="1" />

                <div class="wau-toolbar">
                    <div class="wau-btn-group">
                        <button type="button" class="button" id="wau-plugins-select-all"><?php esc_html_e('Enable All', 'wp-auto-updates'); ?></button>
                        <button type="button" class="button" id="wau-plugins-deselect-all"><?php esc_html_e('Disable All', 'wp-auto-updates'); ?></button>
                        <button type="button" class="button" id="wau-plugins-select-active"><?php esc_html_e('Enable Active Only', 'wp-auto-updates'); ?></button>
                    </div>
                    <div>
                        <input type="search" id="wau-plugin-search" class="wau-search-box" placeholder="<?php esc_attr_e('Search plugins...', 'wp-auto-updates'); ?>" />
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped table-view-list" id="wau-plugins-table">
                    <thead>
                        <tr>
                            <th style="width: 70px; text-align: center;"><?php esc_html_e('Auto-Update', 'wp-auto-updates'); ?></th>
                            <th><?php esc_html_e('Plugin Name', 'wp-auto-updates'); ?></th>
                            <th style="width: 140px;"><?php esc_html_e('Version', 'wp-auto-updates'); ?></th>
                            <th style="width: 140px;"><?php esc_html_e('State', 'wp-auto-updates'); ?></th>
                            <th><?php esc_html_e('File Path / Slug', 'wp-auto-updates'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_plugins)) : ?>
                            <tr><td colspan="5"><?php esc_html_e('No plugins discovered on this WordPress instance.', 'wp-auto-updates'); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ($all_plugins as $plugin_file => $data) :
                                $is_active = is_plugin_active($plugin_file);
                                if ($auto_enroll) {
                                    $is_checked = !isset($saved_plugins[$plugin_file]) || !empty($saved_plugins[$plugin_file]);
                                } else {
                                    $is_checked = !empty($saved_plugins[$plugin_file]);
                                }
                            ?>
                                <tr class="plugin-row" data-active="<?php echo $is_active ? '1' : '0'; ?>">
                                    <td style="text-align: center;">
                                        <input type="checkbox" name="wp_auto_update_plugins[<?php echo esc_attr($plugin_file); ?>]" class="wau-plugin-checkbox" value="1" <?php checked($is_checked, true); ?> />
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($data['Name']); ?></strong>
                                    </td>
                                    <td><?php echo esc_html($data['Version'] ?? '-'); ?></td>
                                    <td>
                                        <span class="wau-status-pill <?php echo $is_active ? 'wau-status-active' : 'wau-status-inactive'; ?>">
                                            <?php echo $is_active ? esc_html__('Active', 'wp-auto-updates') : esc_html__('Inactive', 'wp-auto-updates'); ?>
                                        </span>
                                    </td>
                                    <td><code><?php echo esc_html($plugin_file); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <p class="submit" style="margin-top:20px;">
                    <button type="submit" class="button button-primary button-large"><?php esc_html_e('Save Plugin Rules', 'wp-auto-updates'); ?></button>
                </p>
            </form>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var search = document.getElementById('wau-plugin-search');
                if (search) {
                    search.addEventListener('input', function() {
                        var q = this.value.toLowerCase().trim();
                        document.querySelectorAll('#wau-plugins-table tbody tr.plugin-row').forEach(function(row) {
                            row.style.display = (row.textContent.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
                        });
                    });
                }
                document.getElementById('wau-plugins-select-all').addEventListener('click', function() {
                    document.querySelectorAll('.wau-plugin-checkbox').forEach(function(cb) { cb.checked = true; });
                });
                document.getElementById('wau-plugins-deselect-all').addEventListener('click', function() {
                    document.querySelectorAll('.wau-plugin-checkbox').forEach(function(cb) { cb.checked = false; });
                });
                document.getElementById('wau-plugins-select-active').addEventListener('click', function() {
                    document.querySelectorAll('#wau-plugins-table tbody tr.plugin-row').forEach(function(row) {
                        var isActive = row.getAttribute('data-active') === '1';
                        var cb = row.querySelector('.wau-plugin-checkbox');
                        if (cb) cb.checked = isActive;
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * Render Themes Tab.
     */
    private function render_themes_tab($settings, $form_action_url) {
        $all_themes = function_exists('wp_get_themes') ? wp_get_themes() : [];
        $saved_themes = $settings['themes'] ?? [];
        $auto_enroll = !empty($settings['auto_enroll_new']);
        $current_theme = function_exists('wp_get_theme') ? wp_get_theme() : null;
        $current_stylesheet = $current_theme ? $current_theme->get_stylesheet() : '';
        ?>
        <div class="wau-card">
            <h2><?php esc_html_e('Theme Auto-Update Rules', 'wp-auto-updates'); ?></h2>
            <p class="description">
                <?php esc_html_e('Select which themes should automatically update upon release.', 'wp-auto-updates'); ?>
            </p>

            <?php if ($auto_enroll) : ?>
                <div style="background:#e8f4fd; border-left:4px solid #2271b1; padding:10px 14px; margin-bottom:15px; border-radius:3px;">
                    <strong><?php esc_html_e('Auto-Enrollment Active:', 'wp-auto-updates'); ?></strong>
                    <?php esc_html_e('Newly installed themes are automatically enabled for auto-updates. You can explicitly uncheck any theme below to disable it.', 'wp-auto-updates'); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url($form_action_url); ?>">
                <?php wp_nonce_field('wp_auto_updates_save_action', 'wp_auto_updates_nonce'); ?>
                <input type="hidden" name="action" value="wp_auto_updates_save_settings" />
                <input type="hidden" name="active_tab" value="themes" />

                <!-- Preserve Other Settings -->
                <input type="hidden" name="wp_auto_update_disable_emails" value="<?php echo esc_attr($settings['disable_emails']); ?>" />
                <input type="hidden" name="wp_auto_update_instant_updates" value="<?php echo esc_attr($settings['instant_updates']); ?>" />
                <input type="hidden" name="wp_auto_update_auto_enroll_new" value="<?php echo esc_attr($settings['auto_enroll_new']); ?>" />
                <input type="hidden" name="wp_auto_update_enable_fallback_engine" value="<?php echo esc_attr($settings['enable_fallback_engine']); ?>" />
                <input type="hidden" name="wp_auto_update_enable_retry_queue" value="<?php echo esc_attr($settings['enable_retry_queue']); ?>" />
                <input type="hidden" name="wp_auto_update_pre_update_compat_check" value="<?php echo esc_attr($settings['pre_update_compat_check']); ?>" />
                <input type="hidden" name="wp_auto_update_check_frequency" value="<?php echo esc_attr($settings['check_frequency']); ?>" />
                <input type="hidden" name="wp_auto_update_max_logs" value="<?php echo esc_attr($settings['max_logs']); ?>" />
                <input type="hidden" name="wp_auto_update_themes_submitted" value="1" />

                <div class="wau-toolbar">
                    <div class="wau-btn-group">
                        <button type="button" class="button" id="wau-themes-select-all"><?php esc_html_e('Enable All', 'wp-auto-updates'); ?></button>
                        <button type="button" class="button" id="wau-themes-deselect-all"><?php esc_html_e('Disable All', 'wp-auto-updates'); ?></button>
                        <button type="button" class="button" id="wau-themes-select-active"><?php esc_html_e('Enable Active Theme Only', 'wp-auto-updates'); ?></button>
                    </div>
                    <div>
                        <input type="search" id="wau-theme-search" class="wau-search-box" placeholder="<?php esc_attr_e('Search themes...', 'wp-auto-updates'); ?>" />
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped table-view-list" id="wau-themes-table">
                    <thead>
                        <tr>
                            <th style="width: 70px; text-align: center;"><?php esc_html_e('Auto-Update', 'wp-auto-updates'); ?></th>
                            <th><?php esc_html_e('Theme Name', 'wp-auto-updates'); ?></th>
                            <th style="width: 140px;"><?php esc_html_e('Version', 'wp-auto-updates'); ?></th>
                            <th style="width: 140px;"><?php esc_html_e('State', 'wp-auto-updates'); ?></th>
                            <th><?php esc_html_e('Theme Slug', 'wp-auto-updates'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_themes)) : ?>
                            <tr><td colspan="5"><?php esc_html_e('No themes discovered.', 'wp-auto-updates'); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ($all_themes as $slug => $theme) :
                                $is_active = ($slug === $current_stylesheet);
                                if ($auto_enroll) {
                                    $is_checked = !isset($saved_themes[$slug]) || !empty($saved_themes[$slug]);
                                } else {
                                    $is_checked = !empty($saved_themes[$slug]);
                                }
                            ?>
                                <tr class="theme-row" data-active="<?php echo $is_active ? '1' : '0'; ?>">
                                    <td style="text-align: center;">
                                        <input type="checkbox" name="wp_auto_update_themes[<?php echo esc_attr($slug); ?>]" class="wau-theme-checkbox" value="1" <?php checked($is_checked, true); ?> />
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($theme->get('Name')); ?></strong>
                                    </td>
                                    <td><?php echo esc_html($theme->get('Version') ?? '-'); ?></td>
                                    <td>
                                        <span class="wau-status-pill <?php echo $is_active ? 'wau-status-active' : 'wau-status-inactive'; ?>">
                                            <?php echo $is_active ? esc_html__('Active Theme', 'wp-auto-updates') : esc_html__('Installed', 'wp-auto-updates'); ?>
                                        </span>
                                    </td>
                                    <td><code><?php echo esc_html($slug); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <p class="submit" style="margin-top:20px;">
                    <button type="submit" class="button button-primary button-large"><?php esc_html_e('Save Theme Rules', 'wp-auto-updates'); ?></button>
                </p>
            </form>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var search = document.getElementById('wau-theme-search');
                if (search) {
                    search.addEventListener('input', function() {
                        var q = this.value.toLowerCase().trim();
                        document.querySelectorAll('#wau-themes-table tbody tr.theme-row').forEach(function(row) {
                            row.style.display = (row.textContent.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
                        });
                    });
                }
                document.getElementById('wau-themes-select-all').addEventListener('click', function() {
                    document.querySelectorAll('.wau-theme-checkbox').forEach(function(cb) { cb.checked = true; });
                });
                document.getElementById('wau-themes-deselect-all').addEventListener('click', function() {
                    document.querySelectorAll('.wau-theme-checkbox').forEach(function(cb) { cb.checked = false; });
                });
                document.getElementById('wau-themes-select-active').addEventListener('click', function() {
                    document.querySelectorAll('#wau-themes-table tbody tr.theme-row').forEach(function(row) {
                        var isActive = row.getAttribute('data-active') === '1';
                        var cb = row.querySelector('.wau-theme-checkbox');
                        if (cb) cb.checked = isActive;
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * Render Audit Logs Tab.
     */
    private function render_logs_tab($audit_logs, $form_action_url) {
        ?>
        <div class="wau-card">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 15px;">
                <div>
                    <h2 style="margin:0;"><?php esc_html_e('Audit Trail Logs', 'wp-auto-updates'); ?></h2>
                    <p class="description"><?php esc_html_e('Granular audit records of background update executions, error captures, and configuration adjustments.', 'wp-auto-updates'); ?></p>
                </div>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <!-- Force Run Trigger -->
                    <form method="post" action="<?php echo esc_url($form_action_url); ?>" style="display:inline;">
                        <?php wp_nonce_field('wp_auto_updates_force_run_action', 'wp_auto_updates_force_run_nonce'); ?>
                        <input type="hidden" name="action" value="wp_auto_updates_force_run" />
                        <button type="submit" class="button button-primary" onclick="return confirm('<?php esc_attr_e('Are you sure? This will clear locks, refresh update transients, and run the background updater immediately.', 'wp-auto-updates'); ?>');">
                            <span class="dashicons dashicons-controls-play" style="vertical-align:middle;margin-top:-2px;"></span> <?php esc_html_e('Force Run Auto-Updates Now', 'wp-auto-updates'); ?>
                        </button>
                    </form>

                    <!-- Export CSV -->
                    <form method="post" action="<?php echo esc_url($form_action_url); ?>" style="display:inline;">
                        <?php wp_nonce_field('wp_auto_updates_export_logs_action', 'wp_auto_updates_export_nonce'); ?>
                        <input type="hidden" name="action" value="wp_auto_updates_export_logs_csv" />
                        <button type="submit" class="button"><span class="dashicons dashicons-download" style="vertical-align:middle;"></span> <?php esc_html_e('Export CSV', 'wp-auto-updates'); ?></button>
                    </form>

                    <!-- Export JSON -->
                    <form method="post" action="<?php echo esc_url($form_action_url); ?>" style="display:inline;">
                        <?php wp_nonce_field('wp_auto_updates_export_logs_json_action', 'wp_auto_updates_export_json_nonce'); ?>
                        <input type="hidden" name="action" value="wp_auto_updates_export_logs_json" />
                        <button type="submit" class="button"><span class="dashicons dashicons-media-code" style="vertical-align:middle;"></span> <?php esc_html_e('Export JSON', 'wp-auto-updates'); ?></button>
                    </form>

                    <!-- Clear Logs -->
                    <form method="post" action="<?php echo esc_url($form_action_url); ?>" style="display:inline;">
                        <?php wp_nonce_field('wp_auto_updates_clear_logs_action', 'wp_auto_updates_clear_nonce'); ?>
                        <input type="hidden" name="action" value="wp_auto_updates_clear_logs" />
                        <button type="submit" class="button button-link-delete" onclick="return confirm('<?php esc_attr_e('Are you sure you want to purge all audit records?', 'wp-auto-updates'); ?>');"><?php esc_html_e('Clear Logs', 'wp-auto-updates'); ?></button>
                    </form>
                </div>
            </div>

            <div class="wau-toolbar">
                <div>
                    <select id="wau-log-status-filter" style="margin-right:8px;">
                        <option value="all"><?php esc_html_e('All Statuses', 'wp-auto-updates'); ?></option>
                        <option value="success"><?php esc_html_e('Success Only', 'wp-auto-updates'); ?></option>
                        <option value="failed"><?php esc_html_e('Failed Only', 'wp-auto-updates'); ?></option>
                        <option value="info"><?php esc_html_e('Info / System', 'wp-auto-updates'); ?></option>
                    </select>
                </div>
                <div>
                    <input type="search" id="wau-log-search" class="wau-search-box" placeholder="<?php esc_attr_e('Search audit logs...', 'wp-auto-updates'); ?>" />
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped table-view-list" id="wau-logs-table">
                <thead>
                    <tr>
                        <th style="width: 155px;"><?php esc_html_e('Timestamp', 'wp-auto-updates'); ?></th>
                        <th style="width: 85px;"><?php esc_html_e('Type', 'wp-auto-updates'); ?></th>
                        <th style="width: 200px;"><?php esc_html_e('Item Name / Slug', 'wp-auto-updates'); ?></th>
                        <th style="width: 120px;"><?php esc_html_e('Versions', 'wp-auto-updates'); ?></th>
                        <th style="width: 95px;"><?php esc_html_e('Status', 'wp-auto-updates'); ?></th>
                        <th style="width: 150px;"><?php esc_html_e('Source', 'wp-auto-updates'); ?></th>
                        <th><?php esc_html_e('Message / Error Diagnostics', 'wp-auto-updates'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($audit_logs)) : ?>
                        <tr><td colspan="7"><?php esc_html_e('No audit records recorded yet.', 'wp-auto-updates'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($audit_logs as $log) :
                            $status = $log['status'] ?? 'info';
                            $status_class = 'wau-status-info';
                            if ($status === 'success') $status_class = 'wau-status-success';
                            if ($status === 'failed')  $status_class = 'wau-status-failed';
                        ?>
                            <tr class="log-row" data-status="<?php echo esc_attr($status); ?>">
                                <td><small><?php echo esc_html($log['datetime'] ?? '-'); ?></small></td>
                                <td><strong><?php echo esc_html(strtoupper($log['type'] ?? 'PLUGIN')); ?></strong></td>
                                <td>
                                    <strong><?php echo esc_html($log['item_name'] ?? ''); ?></strong>
                                    <?php if (!empty($log['item_slug']) && $log['item_slug'] !== $log['item_name']) : ?>
                                        <br><small style="color:#646970;"><?php echo esc_html($log['item_slug']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small><?php echo esc_html($log['from_version'] ?? '-'); ?> &rarr; <?php echo esc_html($log['to_version'] ?? '-'); ?></small>
                                </td>
                                <td>
                                    <span class="wau-status-pill <?php echo esc_attr($status_class); ?>">
                                        <?php echo esc_html(ucfirst($status)); ?>
                                    </span>
                                </td>
                                <td><small><?php echo esc_html($log['source'] ?? '-'); ?></small></td>
                                <td><?php echo esc_html($log['message'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var logSearch = document.getElementById('wau-log-search');
                var statusFilter = document.getElementById('wau-log-status-filter');

                function applyLogFilters() {
                    var query = logSearch ? logSearch.value.toLowerCase().trim() : '';
                    var status = statusFilter ? statusFilter.value : 'all';

                    document.querySelectorAll('#wau-logs-table tbody tr.log-row').forEach(function(row) {
                        var text = row.textContent.toLowerCase();
                        var rowStatus = row.getAttribute('data-status') || '';
                        var matchesText = (query === '' || text.indexOf(query) !== -1);
                        var matchesStatus = (status === 'all' || rowStatus === status || (status === 'info' && (rowStatus === 'info' || rowStatus === 'system')));
                        row.style.display = (matchesText && matchesStatus) ? '' : 'none';
                    });
                }

                if (logSearch) logSearch.addEventListener('input', applyLogFilters);
                if (statusFilter) statusFilter.addEventListener('change', applyLogFilters);
            });
        </script>
        <?php
    }

    /**
     * Render Retry Queue Tab.
     */
    private function render_retries_tab($retry_queue, $form_action_url) {
        $count = is_array($retry_queue) ? count($retry_queue) : 0;
        ?>
        <div class="wau-card">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-bottom: 15px;">
                <div>
                    <h2 style="margin:0;"><?php esc_html_e('Intelligent Retry Queue', 'wp-auto-updates'); ?></h2>
                    <p class="description"><?php esc_html_e('Items that encountered temporary network dropouts or server timeouts are safely queued and retried with exponential backoff (5m, 15m, 60m).', 'wp-auto-updates'); ?></p>
                </div>
                <div style="display: flex; gap: 8px;">
                    <?php if ($count > 0) : ?>
                        <!-- Retry All Now -->
                        <form method="post" action="<?php echo esc_url($form_action_url); ?>" style="display:inline;">
                            <?php wp_nonce_field('wp_auto_updates_retry_action', 'wp_auto_updates_retry_nonce'); ?>
                            <input type="hidden" name="action" value="wp_auto_updates_retry_now" />
                            <button type="submit" class="button button-primary">
                                <span class="dashicons dashicons-controls-play" style="vertical-align:middle;"></span> <?php esc_html_e('Retry All Queued Items Now', 'wp-auto-updates'); ?>
                            </button>
                        </form>

                        <!-- Clear Queue -->
                        <form method="post" action="<?php echo esc_url($form_action_url); ?>" style="display:inline;">
                            <?php wp_nonce_field('wp_auto_updates_clear_retries_action', 'wp_auto_updates_clear_retries_nonce'); ?>
                            <input type="hidden" name="action" value="wp_auto_updates_clear_retries" />
                            <button type="submit" class="button button-link-delete" onclick="return confirm('<?php esc_attr_e('Are you sure you want to clear the retry queue?', 'wp-auto-updates'); ?>');">
                                <?php esc_html_e('Clear Queue', 'wp-auto-updates'); ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped table-view-list">
                <thead>
                    <tr>
                        <th style="width: 80px;"><?php esc_html_e('Type', 'wp-auto-updates'); ?></th>
                        <th><?php esc_html_e('Item Name / Slug', 'wp-auto-updates'); ?></th>
                        <th style="width: 100px;"><?php esc_html_e('Target Ver', 'wp-auto-updates'); ?></th>
                        <th style="width: 90px;"><?php esc_html_e('Attempt', 'wp-auto-updates'); ?></th>
                        <th style="width: 170px;"><?php esc_html_e('Next Retry In', 'wp-auto-updates'); ?></th>
                        <th><?php esc_html_e('Last Recorded Error', 'wp-auto-updates'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($retry_queue)) : ?>
                        <tr>
                            <td colspan="6" style="padding: 20px; text-align: center; color: #50575e;">
                                <span class="dashicons dashicons-yes-alt" style="color:#007a3d; font-size: 24px; vertical-align: middle;"></span>
                                <strong><?php esc_html_e('All systems healthy! Retry queue is currently empty.', 'wp-auto-updates'); ?></strong>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($retry_queue as $item_key => $item) :
                            $next_time = $item['next_retry_time'] ?? time();
                            $diff = $next_time - time();
                            $time_str = ($diff <= 0) ? __('Due Now (Next Cycle)', 'wp-auto-updates') : sprintf(__('%d min (%s)', 'wp-auto-updates'), ceil($diff / 60), gmdate('H:i:s', $next_time) . ' UTC');
                        ?>
                            <tr>
                                <td><strong><?php echo esc_html(strtoupper($item['type'] ?? 'PLUGIN')); ?></strong></td>
                                <td>
                                    <strong><?php echo esc_html($item['name'] ?? ''); ?></strong><br>
                                    <small style="color:#646970;"><code><?php echo esc_html($item['slug'] ?? ''); ?></code></small>
                                </td>
                                <td><code><?php echo esc_html($item['to_version'] ?? '-'); ?></code></td>
                                <td><strong><?php echo (int)($item['attempts'] ?? 1); ?> / 3</strong></td>
                                <td><span class="wau-status-pill wau-status-info"><?php echo esc_html($time_str); ?></span></td>
                                <td><span style="color:#d63638;"><?php echo esc_html($item['last_error'] ?? ''); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render Settings & Webhook Tab.
     */
    private function render_settings_tab($settings, $form_action_url) {
        $webhook_token = $settings['webhook_token'] ?? '';
        $webhook_url = add_query_arg([
            'wp_auto_updates_webhook' => '1',
            'token'                   => $webhook_token,
        ], home_url('/'));
        ?>
        <div class="wau-card">
            <h2><?php esc_html_e('Enterprise Auto-Update Engine Settings', 'wp-auto-updates'); ?></h2>
            
            <form method="post" action="<?php echo esc_url($form_action_url); ?>">
                <?php wp_nonce_field('wp_auto_updates_save_action', 'wp_auto_updates_nonce'); ?>
                <input type="hidden" name="action" value="wp_auto_updates_save_settings" />
                <input type="hidden" name="active_tab" value="settings" />



                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Auto-Enrollment Policy', 'wp-auto-updates'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wp_auto_update_auto_enroll_new" value="1" <?php checked(!empty($settings['auto_enroll_new'])); ?> />
                                <strong><?php esc_html_e('Automatically enable auto-updates for newly installed plugins & themes', 'wp-auto-updates'); ?></strong>
                            </label>
                            <p class="description"><?php esc_html_e('Guarantees that no newly added plugins or themes are ever missed if an administrator forgets to configure them.', 'wp-auto-updates'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Direct Resilient Fallback Engine', 'wp-auto-updates'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wp_auto_update_enable_fallback_engine" value="1" <?php checked(!empty($settings['enable_fallback_engine'])); ?> />
                                <strong><?php esc_html_e('Enable Direct Upgrader Fallback', 'wp-auto-updates'); ?></strong>
                            </label>
                            <p class="description"><?php esc_html_e('If WordPress Core upgrader skips an item (e.g. due to VCS/.git directory structures or third-party upgrader quirks), the Direct Engine executes the upgrade directly.', 'wp-auto-updates'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Intelligent Retry Queue', 'wp-auto-updates'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wp_auto_update_enable_retry_queue" value="1" <?php checked(!empty($settings['enable_retry_queue'])); ?> />
                                <strong><?php esc_html_e('Enable Automatic Retry with Exponential Backoff (5m, 15m, 60m)', 'wp-auto-updates'); ?></strong>
                            </label>
                            <p class="description"><?php esc_html_e('Automatically recovers from temporary network drops, WordPress.org 503 outages, or slow downloads.', 'wp-auto-updates'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Pre-Update Compatibility Guard', 'wp-auto-updates'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wp_auto_update_pre_update_compat_check" value="1" <?php checked(!empty($settings['pre_update_compat_check'])); ?> />
                                <strong><?php esc_html_e('Verify PHP and WordPress version compatibility before upgrading', 'wp-auto-updates'); ?></strong>
                            </label>
                            <p class="description"><?php esc_html_e('Prevents site-breaking errors if a new plugin release requires a newer PHP or WordPress version than your server currently runs.', 'wp-auto-updates'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Instant Update Execution', 'wp-auto-updates'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wp_auto_update_instant_updates" value="1" <?php checked(!empty($settings['instant_updates'])); ?> />
                                <strong><?php esc_html_e('Trigger non-blocking background upgrade the instant a new release is detected', 'wp-auto-updates'); ?></strong>
                            </label>
                            <p class="description"><?php esc_html_e('Fires asynchronous cron events without stalling visitor page loads.', 'wp-auto-updates'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Update Check Frequency', 'wp-auto-updates'); ?></th>
                        <td>
                            <select name="wp_auto_update_check_frequency">
                                <option value="15min" <?php selected($settings['check_frequency'], '15min'); ?>><?php esc_html_e('Every 15 Minutes (High-Frequency Enterprise)', 'wp-auto-updates'); ?></option>
                                <option value="30min" <?php selected($settings['check_frequency'], '30min'); ?>><?php esc_html_e('Every 30 Minutes', 'wp-auto-updates'); ?></option>
                                <option value="hourly" <?php selected($settings['check_frequency'], 'hourly'); ?>><?php esc_html_e('Once Hourly (Recommended)', 'wp-auto-updates'); ?></option>
                                <option value="2hours" <?php selected($settings['check_frequency'], '2hours'); ?>><?php esc_html_e('Every 2 Hours', 'wp-auto-updates'); ?></option>
                                <option value="twicedaily" <?php selected($settings['check_frequency'], 'twicedaily'); ?>><?php esc_html_e('Twice Daily', 'wp-auto-updates'); ?></option>
                                <option value="daily" <?php selected($settings['check_frequency'], 'daily'); ?>><?php esc_html_e('Once Daily', 'wp-auto-updates'); ?></option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Core Notification Emails', 'wp-auto-updates'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wp_auto_update_disable_emails" value="1" <?php checked(!empty($settings['disable_emails'])); ?> />
                                <?php esc_html_e('Silence core auto-update notification emails (Recommended to reduce inbox noise)', 'wp-auto-updates'); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Audit Log Retention Limit', 'wp-auto-updates'); ?></th>
                        <td>
                            <input type="number" name="wp_auto_update_max_logs" value="<?php echo esc_attr($settings['max_logs']); ?>" min="50" max="2000" step="50" style="width:100px;" />
                            <p class="description"><?php esc_html_e('Maximum log records stored in the FIFO ring buffer (50 - 2,000).', 'wp-auto-updates'); ?></p>
                        </td>
                    </tr>
                </table>

                <h3 style="margin-top:30px;"><?php esc_html_e('External Webhook & Server Crontab Setup', 'wp-auto-updates'); ?></h3>
                <p class="description"><?php esc_html_e('If your server has low visitor traffic or has WP-Cron disabled, configure your Linux server crontab or cPanel Cron Job to ping this secure webhook URL directly.', 'wp-auto-updates'); ?></p>

                <div class="wau-code-snippet">
                    <strong><?php esc_html_e('Secure Webhook URL:', 'wp-auto-updates'); ?></strong><br>
                    <code><?php echo esc_html($webhook_url); ?></code>
                </div>

                <div class="wau-code-snippet">
                    <strong><?php esc_html_e('Ready-to-use Server Crontab Command (Every Hour):', 'wp-auto-updates'); ?></strong><br>
                    <code>0 * * * * curl -s -L "<?php echo esc_html($webhook_url); ?>" > /dev/null 2>&1</code>
                </div>

                <p>
                    <label>
                        <input type="checkbox" name="wp_auto_updates_regenerate_token" value="1" />
                        <?php esc_html_e('Regenerate secret webhook authentication token on save', 'wp-auto-updates'); ?>
                    </label>
                </p>

                <p class="submit">
                    <button type="submit" class="button button-primary button-large"><?php esc_html_e('Save All Settings', 'wp-auto-updates'); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Render System Diagnostics Tab.
     */
    private function render_diagnostics_tab() {
        $disk_free = function_exists('disk_free_space') ? @disk_free_space(ABSPATH) : false;
        $disk_free_mb = $disk_free ? round($disk_free / (1024 * 1024), 2) : 'Unknown';

        $plugins_writable = is_writable(WP_PLUGIN_DIR);
        $themes_writable  = is_writable(get_theme_root());
        $cron_disabled    = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $auto_disabled    = defined('AUTOMATIC_UPDATER_DISABLED') && AUTOMATIC_UPDATER_DISABLED;
        $file_mods_disallow = defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS;
        ?>
        <div class="wau-card">
            <h2><?php esc_html_e('System Health & Environment Diagnostics', 'wp-auto-updates'); ?></h2>
            <p class="description"><?php esc_html_e('Real-time inspection of server storage, write permissions, and updater constants.', 'wp-auto-updates'); ?></p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 250px;"><?php esc_html_e('Diagnostic Check', 'wp-auto-updates'); ?></th>
                        <th style="width: 140px;"><?php esc_html_e('Status', 'wp-auto-updates'); ?></th>
                        <th><?php esc_html_e('Details & Recommendation', 'wp-auto-updates'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e('Available Free Disk Space', 'wp-auto-updates'); ?></strong></td>
                        <td>
                            <?php if (is_numeric($disk_free_mb) && $disk_free_mb < 200) : ?>
                                <span class="wau-status-pill wau-status-failed"><?php esc_html_e('CRITICAL', 'wp-auto-updates'); ?></span>
                            <?php else : ?>
                                <span class="wau-status-pill wau-status-success"><?php esc_html_e('HEALTHY', 'wp-auto-updates'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo esc_html($disk_free_mb); ?> MB</strong> free space on drive.
                            <?php if (is_numeric($disk_free_mb) && $disk_free_mb < 200) : ?>
                                <p style="color:#d63638;margin:4px 0 0 0;"><?php esc_html_e('Warning: Low storage may cause archive extraction failures during updates.', 'wp-auto-updates'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <td><strong><?php esc_html_e('Plugins Directory Write Access', 'wp-auto-updates'); ?></strong></td>
                        <td>
                            <span class="wau-status-pill <?php echo $plugins_writable ? 'wau-status-success' : 'wau-status-failed'; ?>">
                                <?php echo $plugins_writable ? esc_html__('WRITABLE', 'wp-auto-updates') : esc_html__('READ-ONLY', 'wp-auto-updates'); ?>
                            </span>
                        </td>
                        <td><code><?php echo esc_html(WP_PLUGIN_DIR); ?></code></td>
                    </tr>

                    <tr>
                        <td><strong><?php esc_html_e('Themes Directory Write Access', 'wp-auto-updates'); ?></strong></td>
                        <td>
                            <span class="wau-status-pill <?php echo $themes_writable ? 'wau-status-success' : 'wau-status-failed'; ?>">
                                <?php echo $themes_writable ? esc_html__('WRITABLE', 'wp-auto-updates') : esc_html__('READ-ONLY', 'wp-auto-updates'); ?>
                            </span>
                        </td>
                        <td><code><?php echo esc_html(get_theme_root()); ?></code></td>
                    </tr>

                    <tr>
                        <td><strong><?php esc_html_e('WP-Cron Status', 'wp-auto-updates'); ?></strong></td>
                        <td>
                            <span class="wau-status-pill <?php echo $cron_disabled ? 'wau-status-info' : 'wau-status-success'; ?>">
                                <?php echo $cron_disabled ? esc_html__('DISABLED', 'wp-auto-updates') : esc_html__('ACTIVE', 'wp-auto-updates'); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($cron_disabled) : ?>
                                <?php esc_html_e('DISABLE_WP_CRON is true. Use the External Webhook feature under Settings for reliable server-side execution.', 'wp-auto-updates'); ?>
                            <?php else : ?>
                                <?php esc_html_e('Built-in WordPress cron scheduler is running normally.', 'wp-auto-updates'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <td><strong><?php esc_html_e('AUTOMATIC_UPDATER_DISABLED Constant', 'wp-auto-updates'); ?></strong></td>
                        <td>
                            <span class="wau-status-pill <?php echo $auto_disabled ? 'wau-status-failed' : 'wau-status-success'; ?>">
                                <?php echo $auto_disabled ? esc_html__('BLOCKED', 'wp-auto-updates') : esc_html__('ALLOWED', 'wp-auto-updates'); ?>
                            </span>
                        </td>
                        <td><?php echo $auto_disabled ? esc_html__('Automatic updates are globally disabled in wp-config.php.', 'wp-auto-updates') : esc_html__('Constant is not disabling updates.', 'wp-auto-updates'); ?></td>
                    </tr>

                    <tr>
                        <td><strong><?php esc_html_e('DISALLOW_FILE_MODS Constant', 'wp-auto-updates'); ?></strong></td>
                        <td>
                            <span class="wau-status-pill <?php echo $file_mods_disallow ? 'wau-status-failed' : 'wau-status-success'; ?>">
                                <?php echo $file_mods_disallow ? esc_html__('BLOCKED', 'wp-auto-updates') : esc_html__('ALLOWED', 'wp-auto-updates'); ?>
                            </span>
                        </td>
                        <td><?php echo $file_mods_disallow ? esc_html__('DISALLOW_FILE_MODS is enabled in wp-config.php.', 'wp-auto-updates') : esc_html__('File modifications are permitted.', 'wp-auto-updates'); ?></td>
                    </tr>

                    <tr>
                        <td><strong><?php esc_html_e('PHP Environment', 'wp-auto-updates'); ?></strong></td>
                        <td><span class="wau-status-pill wau-status-info"><?php echo esc_html(PHP_VERSION); ?></span></td>
                        <td><?php esc_html_e('PHP Version running on web server.', 'wp-auto-updates'); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}

// Instantiate Enterprise Manager
WP_Auto_Updates_Enterprise_Manager::instance();
