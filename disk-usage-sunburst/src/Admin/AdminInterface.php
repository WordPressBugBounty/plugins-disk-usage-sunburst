<?php
/**
 * Admin Interface Class
 *
 * @package RaidBoxes\DiskUsageSunburst
 */

namespace RaidBoxes\DiskUsageSunburst\Admin;

use RaidBoxes\DiskUsageSunburst\Scanner\FileScanner;
use RaidBoxes\DiskUsageSunburst\Snapshot\SnapshotManager;
use RaidBoxes\DiskUsageSunburst\Analysis\DataAnalyzer;

/**
 * Handles WordPress admin interface integration
 */
class AdminInterface {

    /**
     * File scanner instance
     *
     * @var FileScanner
     */
    private FileScanner $scanner;

    /**
     * Snapshot manager instance
     *
     * @var SnapshotManager
     */
    private SnapshotManager $snapshot_manager;

    /**
     * Data analyzer instance
     *
     * @var DataAnalyzer
     */
    private DataAnalyzer $analyzer;

    /**
     * Constructor
     *
     * @param FileScanner $scanner File scanner instance.
     */
    public function __construct( FileScanner $scanner ) {
        $this->scanner = $scanner;
        $this->snapshot_manager = new SnapshotManager();
        $this->analyzer = new DataAnalyzer();
    }

    /**
     * Initialize admin interface
     */
    public function init(): void {
        // Only load for users with proper capabilities
        if ( ! $this->current_user_can_view() ) {
            return;
        }

        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_rbdusb_scan', [ $this, 'handle_ajax_scan' ] );
        add_action( 'wp_ajax_rbdusb_data', [ $this, 'handle_ajax_scan' ] ); // Legacy compatibility 
        add_action( 'wp_ajax_rbdusb_clear_cache', [ $this, 'handle_ajax_clear_cache' ] );
        add_action( 'wp_ajax_rbdusb_get_snapshots', [ $this, 'handle_ajax_get_snapshots' ] );
        add_action( 'wp_ajax_rbdusb_load_snapshot', [ $this, 'handle_ajax_load_snapshot' ] );
        add_action( 'wp_ajax_rbdusb_delete_snapshot', [ $this, 'handle_ajax_delete_snapshot' ] );
        add_action( 'wp_ajax_rbdusb_analyze_data', [ $this, 'handle_ajax_analyze_data' ] );
        add_action( 'admin_init', [ $this, 'handle_settings' ] );
    }

    /**
     * Check if current user can view the plugin
     *
     * @return bool
     */
    private function current_user_can_view(): bool {
        // For multisite, require manage_network capability
        if ( is_multisite() ) {
            return current_user_can( 'manage_network' );
        }

        // For single site, require manage_options capability (standard for administrators)
        return current_user_can( 'manage_options' );
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_menu(): void {
        $capability = is_multisite() ? 'manage_network' : 'manage_options';
        $parent_slug = is_multisite() ? 'settings.php' : 'tools.php';

        add_submenu_page(
            $parent_slug,
            __( 'Disk Usage', 'disk-usage-sunburst' ),
            __( 'Disk Usage', 'disk-usage-sunburst' ),
            $capability,
            'disk-usage-sunburst',
            [ $this, 'render_admin_page' ]
        );
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook_suffix Current admin page hook suffix.
     */
    public function enqueue_assets( string $hook_suffix ): void {
        // Only load on our plugin page
        $plugin_pages = [
            'tools_page_disk-usage-sunburst',
            'settings_page_disk-usage-sunburst', // For multisite
        ];

        if ( ! in_array( $hook_suffix, $plugin_pages, true ) ) {
            return;
        }

        $plugin = \RaidBoxes\DiskUsageSunburst\Plugin::get_instance();

        // Enqueue D3.js (we'll upgrade this later)
        wp_enqueue_script(
            'rbdusb-d3',
            $plugin->get_plugin_url( 'assets/js/d3.v7.min.js' ),
            [],
            $plugin->get_version(),
            true
        );

        // Enqueue our main script
        wp_enqueue_script(
            'rbdusb-admin',
            $plugin->get_plugin_url( 'assets/js/admin.js' ),
            [ 'jquery', 'rbdusb-d3' ],
            $plugin->get_version(),
            true
        );

        // Enqueue styles
        wp_enqueue_style(
            'rbdusb-admin',
            $plugin->get_plugin_url( 'assets/css/admin.css' ),
            [],
            $plugin->get_version()
        );

        // Localize script with AJAX data
        wp_localize_script( 'rbdusb-admin', 'rbdusbAjax', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'rbdusb_nonce' ),
            'abspath' => ABSPATH,
            'debug' => defined( 'WP_DEBUG' ) && WP_DEBUG,
            'strings' => [
                'scanning' => __( 'Scanning files and directories...', 'disk-usage-sunburst' ),
                'error' => __( 'An error occurred during scanning.', 'disk-usage-sunburst' ),
                'complete' => __( 'Scan completed successfully.', 'disk-usage-sunburst' ),
                'cache_cleared' => __( 'Cache cleared successfully.', 'disk-usage-sunburst' ),
                'confirm_clear_cache' => __( 'Are you sure you want to clear the scan cache?', 'disk-usage-sunburst' ),
                'svg_not_supported' => __( 'This plugin requires SVG support. Please update to a modern browser.', 'disk-usage-sunburst' ),
                'show_settings' => __( 'Show Advanced Settings', 'disk-usage-sunburst' ),
                'hide_settings' => __( 'Hide Advanced Settings', 'disk-usage-sunburst' ),
                'no_data_export' => __( 'No data available to export.', 'disk-usage-sunburst' ),
                'export_success' => __( 'Export completed successfully.', 'disk-usage-sunburst' ),
            ],
        ] );
    }

    /**
     * Handle AJAX scan request
     */
    public function handle_ajax_scan(): void {
        // Always verify nonce for security - removed legacy bypass
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'rbdusb_nonce' ) ) {
            wp_die( __( 'Security check failed.', 'disk-usage-sunburst' ), 403 );
        }

        // Check capabilities
        if ( ! $this->current_user_can_view() ) {
            wp_die( __( 'Insufficient permissions.', 'disk-usage-sunburst' ), 403 );
        }

        $path = ABSPATH; // Always scan WordPress root directory
        $use_cache = filter_var( $_POST['use_cache'] ?? true, FILTER_VALIDATE_BOOLEAN );

        // Perform scan
        $result = $this->scanner->scan( $path, $use_cache );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [
                'message' => $result->get_error_message(),
                'code' => $result->get_error_code(),
            ] );
        }

        // Save snapshot if this is a new scan (not using cache)
        $save_snapshot = filter_var( $_POST['save_snapshot'] ?? true, FILTER_VALIDATE_BOOLEAN );
        $snapshot_id = null;
        
        if ( $save_snapshot && ! $use_cache ) {
            $snapshot_result = $this->snapshot_manager->save_snapshot( $result );
            if ( ! is_wp_error( $snapshot_result ) ) {
                $snapshot_id = $snapshot_result;
            }
        }

        // Always use modern response format for security consistency
        $response_data = $result;
        if ( $snapshot_id ) {
            $response_data['snapshot_id'] = $snapshot_id;
        }
        wp_send_json_success( $response_data );
    }

    /**
     * Handle AJAX clear cache request
     */
    public function handle_ajax_clear_cache(): void {
        // Verify nonce
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'rbdusb_nonce' ) ) {
            wp_die( __( 'Security check failed.', 'disk-usage-sunburst' ), 403 );
        }

        // Check capabilities
        if ( ! $this->current_user_can_view() ) {
            wp_die( __( 'Insufficient permissions.', 'disk-usage-sunburst' ), 403 );
        }

        $path = sanitize_text_field( $_POST['path'] ?? '' );
        $cleared = $this->scanner->clear_cache( $path );

        if ( $cleared ) {
            wp_send_json_success( [ 'message' => __( 'Cache cleared successfully.', 'disk-usage-sunburst' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Failed to clear cache.', 'disk-usage-sunburst' ) ] );
        }
    }

    /**
     * Handle plugin settings
     */
    public function handle_settings(): void {
        if ( ! $this->current_user_can_view() ) {
            return;
        }

        // Register settings
        register_setting( 'rbdusb_settings', 'rbdusb_options', [
            'type' => 'array',
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
            'default' => $this->get_default_settings(),
        ] );

        // Add settings section
        add_settings_section(
            'rbdusb_performance',
            __( 'Performance Settings', 'disk-usage-sunburst' ),
            [ $this, 'render_performance_section' ],
            'rbdusb_settings'
        );

        // Add settings fields
        add_settings_field(
            'max_execution_time',
            __( 'Maximum Execution Time (seconds)', 'disk-usage-sunburst' ),
            [ $this, 'render_number_field' ],
            'rbdusb_settings',
            'rbdusb_performance',
            [
                'field' => 'max_execution_time',
                'min' => 30,
                'max' => 600,
                'description' => __( 'Maximum time allowed for scanning operation.', 'disk-usage-sunburst' ),
            ]
        );

        add_settings_field(
            'max_files',
            __( 'Maximum Files to Scan', 'disk-usage-sunburst' ),
            [ $this, 'render_number_field' ],
            'rbdusb_settings',
            'rbdusb_performance',
            [
                'field' => 'max_files',
                'min' => 1000,
                'max' => 50000,
                'description' => __( 'Maximum number of files to scan before stopping.', 'disk-usage-sunburst' ),
            ]
        );

        add_settings_field(
            'cache_duration',
            __( 'Cache Duration (seconds)', 'disk-usage-sunburst' ),
            [ $this, 'render_number_field' ],
            'rbdusb_settings',
            'rbdusb_performance',
            [
                'field' => 'cache_duration',
                'min' => 300,
                'max' => 86400,
                'description' => __( 'How long to cache scan results.', 'disk-usage-sunburst' ),
            ]
        );
    }

    /**
     * Render the main admin page
     */
    public function render_admin_page(): void {
        $settings = get_option( 'rbdusb_options', $this->get_default_settings() );
        
        include __DIR__ . '/templates/admin-page.php';
    }

    /**
     * Render performance settings section
     */
    public function render_performance_section(): void {
        echo '<p>' . esc_html__( 'Configure performance limits for the disk usage scanner.', 'disk-usage-sunburst' ) . '</p>';
    }

    /**
     * Render number field
     *
     * @param array $args Field arguments.
     */
    public function render_number_field( array $args ): void {
        $options = get_option( 'rbdusb_options', $this->get_default_settings() );
        $value = $options[ $args['field'] ] ?? '';
        
        printf(
            '<input type="number" id="%1$s" name="rbdusb_options[%1$s]" value="%2$s" min="%3$d" max="%4$d" class="regular-text" />',
            esc_attr( $args['field'] ),
            esc_attr( $value ),
            $args['min'],
            $args['max']
        );
        
        if ( ! empty( $args['description'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
        }
    }

    /**
     * Sanitize settings
     *
     * @param array $input Raw input data.
     * @return array Sanitized settings.
     */
    public function sanitize_settings( array $input ): array {
        $sanitized = [];
        $defaults = $this->get_default_settings();

        foreach ( $defaults as $key => $default_value ) {
            if ( isset( $input[ $key ] ) ) {
                if ( is_numeric( $default_value ) ) {
                    $sanitized[ $key ] = absint( $input[ $key ] );
                } else {
                    $sanitized[ $key ] = sanitize_text_field( $input[ $key ] );
                }
            } else {
                $sanitized[ $key ] = $default_value;
            }
        }

        return $sanitized;
    }

    /**
     * Get default settings
     *
     * @return array Default settings.
     */
    private function get_default_settings(): array {
        return [
            'max_execution_time' => 120,
            'max_files' => 10000,
            'cache_duration' => 3600,
            'max_depth' => 50,
            'memory_limit' => '256M',
        ];
    }

    /**
     * Handle AJAX get snapshots request
     */
    public function handle_ajax_get_snapshots(): void {
        // Verify nonce
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'rbdusb_nonce' ) ) {
            wp_die( __( 'Security check failed.', 'disk-usage-sunburst' ), 403 );
        }

        // Check capabilities
        if ( ! $this->current_user_can_view() ) {
            wp_die( __( 'Insufficient permissions.', 'disk-usage-sunburst' ), 403 );
        }

        $snapshots = $this->snapshot_manager->get_snapshots_list();
        $statistics = $this->snapshot_manager->get_statistics();

        wp_send_json_success( [
            'snapshots' => $snapshots,
            'statistics' => $statistics,
        ] );
    }

    /**
     * Handle AJAX load snapshot request
     */
    public function handle_ajax_load_snapshot(): void {
        // Verify nonce
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'rbdusb_nonce' ) ) {
            wp_die( __( 'Security check failed.', 'disk-usage-sunburst' ), 403 );
        }

        // Check capabilities
        if ( ! $this->current_user_can_view() ) {
            wp_die( __( 'Insufficient permissions.', 'disk-usage-sunburst' ), 403 );
        }

        $snapshot_id = sanitize_text_field( $_POST['snapshot_id'] ?? '' );
        
        if ( empty( $snapshot_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Snapshot ID is required.', 'disk-usage-sunburst' ) ] );
        }

        $snapshot = $this->snapshot_manager->get_snapshot( $snapshot_id );
        
        if ( ! $snapshot ) {
            wp_send_json_error( [ 'message' => __( 'Snapshot not found.', 'disk-usage-sunburst' ) ] );
        }

        wp_send_json_success( [
            'data' => $snapshot['data'],
            'metadata' => $snapshot['metadata'],
            'created_at' => $snapshot['created_at'],
        ] );
    }

    /**
     * Handle AJAX delete snapshot request
     */
    public function handle_ajax_delete_snapshot(): void {
        // Verify nonce
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'rbdusb_nonce' ) ) {
            wp_die( __( 'Security check failed.', 'disk-usage-sunburst' ), 403 );
        }

        // Check capabilities
        if ( ! $this->current_user_can_view() ) {
            wp_die( __( 'Insufficient permissions.', 'disk-usage-sunburst' ), 403 );
        }

        $snapshot_id = sanitize_text_field( $_POST['snapshot_id'] ?? '' );
        
        if ( empty( $snapshot_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Snapshot ID is required.', 'disk-usage-sunburst' ) ] );
        }

        $deleted = $this->snapshot_manager->delete_snapshot( $snapshot_id );
        
        if ( $deleted ) {
            wp_send_json_success( [ 'message' => __( 'Snapshot deleted successfully.', 'disk-usage-sunburst' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Failed to delete snapshot.', 'disk-usage-sunburst' ) ] );
        }
    }

    /**
     * Handle AJAX analyze data request
     */
    public function handle_ajax_analyze_data(): void {
        // Verify nonce
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'rbdusb_nonce' ) ) {
            wp_die( __( 'Security check failed.', 'disk-usage-sunburst' ), 403 );
        }

        // Check capabilities
        if ( ! $this->current_user_can_view() ) {
            wp_die( __( 'Insufficient permissions.', 'disk-usage-sunburst' ), 403 );
        }

        $snapshot_id = sanitize_text_field( $_POST['snapshot_id'] ?? '' );
        $scan_data = null;
        
        if ( ! empty( $snapshot_id ) ) {
            // Use snapshot data
            $snapshot = $this->snapshot_manager->get_snapshot( $snapshot_id );
            
            if ( ! $snapshot ) {
                wp_send_json_error( [ 'message' => __( 'Snapshot not found.', 'disk-usage-sunburst' ) ] );
            }
            
            $scan_data = $snapshot['data'];
        } else {
            // No snapshot ID provided, perform fresh scan for analysis
            $path = ABSPATH;
            $use_cache = true; // Use cache for better performance
            
            $result = $this->scanner->scan( $path, $use_cache );
            
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => __( 'Failed to get scan data: ', 'disk-usage-sunburst' ) . $result->get_error_message() ] );
            }
            
            $scan_data = $result;
        }

        try {
            $analysis = $this->analyzer->analyze( $scan_data );
            wp_send_json_success( $analysis );
        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'message' => __( 'Analysis failed: ', 'disk-usage-sunburst' ) . $e->getMessage() ] );
        }
    }
}