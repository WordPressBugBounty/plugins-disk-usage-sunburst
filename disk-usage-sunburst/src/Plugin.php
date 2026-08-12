<?php
/**
 * Main Plugin Class
 *
 * @package RaidBoxes\DiskUsageSunburst
 */

namespace RaidBoxes\DiskUsageSunburst;

use RaidBoxes\DiskUsageSunburst\Admin\AdminInterface;
use RaidBoxes\DiskUsageSunburst\Scanner\FileScanner;
use RaidBoxes\DiskUsageSunburst\Rest\RestController;

/**
 * Main plugin class that orchestrates all components
 */
class Plugin {

    /**
     * Plugin version
     */
    public const VERSION = RBDUSB_VERSION;

    /**
     * Plugin slug
     */
    public const SLUG = 'disk-usage-sunburst';

    /**
     * Plugin text domain
     */
    public const TEXT_DOMAIN = 'disk-usage-sunburst';

    /**
     * Minimum WordPress version
     */
    public const MIN_WP_VERSION = '5.0';

    /**
     * Minimum PHP version
     */
    public const MIN_PHP_VERSION = '7.4';

    /**
     * Plugin instance
     *
     * @var Plugin|null
     */
    private static ?Plugin $instance = null;

    /**
     * Admin interface instance
     *
     * @var AdminInterface
     */
    private AdminInterface $admin;

    /**
     * File scanner instance
     *
     * @var FileScanner
     */
    private FileScanner $scanner;

    /**
     * REST controller instance
     *
     * @var RestController
     */
    private RestController $rest_controller;

    /**
     * Plugin file path
     *
     * @var string
     */
    private string $plugin_file;

    /**
     * Constructor
     *
     * @param string $plugin_file Main plugin file path.
     */
    private function __construct( string $plugin_file ) {
        $this->plugin_file = $plugin_file;
        $this->init_components();
    }

    /**
     * Get plugin instance (singleton)
     *
     * @param string $plugin_file Main plugin file path.
     * @return Plugin
     */
    public static function get_instance( string $plugin_file = '' ): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self( $plugin_file );
        }
        return self::$instance;
    }

    /**
     * Initialize plugin components
     */
    private function init_components(): void {
        $this->scanner = new FileScanner();
        $this->admin = new AdminInterface( $this->scanner );
        $this->rest_controller = new RestController( $this->scanner );
    }

    /**
     * Initialize the plugin
     */
    public function init(): void {
        // Check system requirements
        if ( ! $this->check_requirements() ) {
            return;
        }

        // Load text domain
        add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );

        // Initialize components
        $this->admin->init();
        $this->rest_controller->init();

        // Register activation/deactivation hooks
        register_activation_hook( $this->plugin_file, [ $this, 'activate' ] );
        register_deactivation_hook( $this->plugin_file, [ $this, 'deactivate' ] );
    }

    /**
     * Check system requirements
     *
     * @return bool
     */
    private function check_requirements(): bool {
        global $wp_version;

        $requirements_met = true;
        $notices = [];

        // Check WordPress version
        if ( version_compare( $wp_version, self::MIN_WP_VERSION, '<' ) ) {
            $notices[] = sprintf(
                /* translators: 1: Required WordPress version, 2: Current WordPress version */
                __( 'Disk Usage Sunburst requires WordPress %1$s or higher. You are running %2$s.', self::TEXT_DOMAIN ),
                self::MIN_WP_VERSION,
                $wp_version
            );
            $requirements_met = false;
        }

        // Check PHP version
        if ( version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '<' ) ) {
            $notices[] = sprintf(
                /* translators: 1: Required PHP version, 2: Current PHP version */
                __( 'Disk Usage Sunburst requires PHP %1$s or higher. You are running %2$s.', self::TEXT_DOMAIN ),
                self::MIN_PHP_VERSION,
                PHP_VERSION
            );
            $requirements_met = false;
        }

        // Display admin notices if requirements not met
        if ( ! $requirements_met ) {
            add_action( 'admin_notices', function() use ( $notices ) {
                foreach ( $notices as $notice ) {
                    printf(
                        '<div class="notice notice-error"><p>%s</p></div>',
                        esc_html( $notice )
                    );
                }
            });
        }

        return $requirements_met;
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            self::TEXT_DOMAIN,
            false,
            dirname( plugin_basename( $this->plugin_file ) ) . '/languages'
        );
    }

    /**
     * Plugin activation
     */
    public function activate(): void {
        // Clear any cached data
        delete_transient( 'rbdusb_scan_cache' );
        
        // Set activation flag
        update_option( 'rbdusb_activated', time() );

        do_action( 'rbdusb_activated' );
    }

    /**
     * Plugin deactivation
     */
    public function deactivate(): void {
        // Clear scheduled events
        wp_clear_scheduled_hook( 'rbdusb_scheduled_scan' );
        
        // Clear cached data
        delete_transient( 'rbdusb_scan_cache' );

        do_action( 'rbdusb_deactivated' );
    }

    /**
     * Get plugin URL
     *
     * @param string $path Optional path to append.
     * @return string
     */
    public function get_plugin_url( string $path = '' ): string {
        return plugin_dir_url( $this->plugin_file ) . ltrim( $path, '/' );
    }

    /**
     * Get plugin path
     *
     * @param string $path Optional path to append.
     * @return string
     */
    public function get_plugin_path( string $path = '' ): string {
        return plugin_dir_path( $this->plugin_file ) . ltrim( $path, '/' );
    }

    /**
     * Get plugin version
     *
     * @return string
     */
    public function get_version(): string {
        return self::VERSION;
    }
}
