<?php
/**
 * REST API Controller
 *
 * @package RaidBoxes\DiskUsageSunburst
 */

namespace RaidBoxes\DiskUsageSunburst\Rest;

use RaidBoxes\DiskUsageSunburst\Scanner\FileScanner;

/**
 * Handles REST API endpoints for the plugin
 */
class RestController {

    /**
     * API namespace
     */
    public const NAMESPACE = 'disk-usage/v1';

    /**
     * File scanner instance
     *
     * @var FileScanner
     */
    private FileScanner $scanner;

    /**
     * Constructor
     *
     * @param FileScanner $scanner File scanner instance.
     */
    public function __construct( FileScanner $scanner ) {
        $this->scanner = $scanner;
    }

    /**
     * Initialize REST API endpoints
     */
    public function init(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * Register REST API routes
     */
    public function register_routes(): void {
        // GET /wp-json/disk-usage/v1/scan
        register_rest_route( self::NAMESPACE, '/scan', [
            'methods' => 'GET',
            'callback' => [ $this, 'handle_scan_request' ],
            'permission_callback' => [ $this, 'check_permissions' ],
            'args' => [
                'path' => [
                    'description' => __( 'Path to scan', 'disk-usage-sunburst' ),
                    'type' => 'string',
                    'default' => ABSPATH,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'use_cache' => [
                    'description' => __( 'Whether to use cached results', 'disk-usage-sunburst' ),
                    'type' => 'boolean',
                    'default' => true,
                ],
                'max_depth' => [
                    'description' => __( 'Maximum scan depth', 'disk-usage-sunburst' ),
                    'type' => 'integer',
                    'default' => 50,
                    'minimum' => 1,
                    'maximum' => 100,
                ],
                'max_files' => [
                    'description' => __( 'Maximum files to scan', 'disk-usage-sunburst' ),
                    'type' => 'integer',
                    'default' => 10000,
                    'minimum' => 100,
                    'maximum' => 50000,
                ],
            ],
        ] );

        // POST /wp-json/disk-usage/v1/scan
        register_rest_route( self::NAMESPACE, '/scan', [
            'methods' => 'POST',
            'callback' => [ $this, 'handle_scan_request' ],
            'permission_callback' => [ $this, 'check_permissions' ],
            'args' => [
                'path' => [
                    'description' => __( 'Path to scan', 'disk-usage-sunburst' ),
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'options' => [
                    'description' => __( 'Scan options', 'disk-usage-sunburst' ),
                    'type' => 'object',
                    'default' => [],
                ],
            ],
        ] );

        // DELETE /wp-json/disk-usage/v1/cache
        register_rest_route( self::NAMESPACE, '/cache', [
            'methods' => 'DELETE',
            'callback' => [ $this, 'handle_clear_cache' ],
            'permission_callback' => [ $this, 'check_permissions' ],
            'args' => [
                'path' => [
                    'description' => __( 'Specific path to clear cache for', 'disk-usage-sunburst' ),
                    'type' => 'string',
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        // GET /wp-json/disk-usage/v1/stats
        register_rest_route( self::NAMESPACE, '/stats', [
            'methods' => 'GET',
            'callback' => [ $this, 'handle_stats_request' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        // GET /wp-json/disk-usage/v1/config
        register_rest_route( self::NAMESPACE, '/config', [
            'methods' => 'GET',
            'callback' => [ $this, 'handle_config_request' ],
            'permission_callback' => [ $this, 'check_permissions' ],
        ] );

        // POST /wp-json/disk-usage/v1/config
        register_rest_route( self::NAMESPACE, '/config', [
            'methods' => 'POST',
            'callback' => [ $this, 'handle_config_update' ],
            'permission_callback' => [ $this, 'check_permissions' ],
            'args' => [
                'config' => [
                    'description' => __( 'Configuration options', 'disk-usage-sunburst' ),
                    'type' => 'object',
                    'required' => true,
                ],
            ],
        ] );
        
        // DEBUG: Add temporary capability check endpoint
        register_rest_route( self::NAMESPACE, '/debug/capabilities', [
            'methods' => 'GET',
            'callback' => [ $this, 'debug_capabilities' ],
            'permission_callback' => '__return_true', // Allow anyone to check (temporary)
        ] );
    }

    /**
     * Check API permissions
     *
     * @param \WP_REST_Request $request Request object.
     * @return bool|WP_Error Permission check result.
     */
    public function check_permissions( \WP_REST_Request $request ) {
        // For multisite, require manage_network capability
        if ( is_multisite() ) {
            if ( ! current_user_can( 'manage_network' ) ) {
                return new \WP_Error(
                    'rest_forbidden',
                    __( 'You do not have permission to access disk usage information on this multisite network.', 'disk-usage-sunburst' ),
                    [ 'status' => 403 ]
                );
            }
        } else {
            // For single site, require manage_options capability (standard for administrators)
            if ( ! current_user_can( 'manage_options' ) ) {
                return new \WP_Error(
                    'rest_forbidden',
                    __( 'You do not have permission to access disk usage information.', 'disk-usage-sunburst' ),
                    [ 'status' => 403 ]
                );
            }
        }

        return true;
    }

    /**
     * Handle scan request
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|WP_Error Response object or error.
     */
    public function handle_scan_request( \WP_REST_Request $request ) {
        $path = $request->get_param( 'path' );
        $use_cache = $request->get_param( 'use_cache' );
        
        // Update scanner configuration if provided
        if ( 'POST' === $request->get_method() ) {
            $options = $request->get_param( 'options' );
            if ( is_array( $options ) && ! empty( $options ) ) {
                $this->scanner->update_config( $options );
            }
        } else {
            // For GET requests, use query parameters for configuration
            $config = [];
            if ( $request->has_param( 'max_depth' ) ) {
                $config['max_depth'] = $request->get_param( 'max_depth' );
            }
            if ( $request->has_param( 'max_files' ) ) {
                $config['max_files'] = $request->get_param( 'max_files' );
            }
            if ( ! empty( $config ) ) {
                $this->scanner->update_config( $config );
            }
        }

        // Perform the scan
        $result = $this->scanner->scan( $path, $use_cache );

        if ( is_wp_error( $result ) ) {
            return new \WP_Error(
                'scan_failed',
                $result->get_error_message(),
                [ 'status' => 500 ]
            );
        }

        return new \WP_REST_Response( [
            'success' => true,
            'data' => $result,
            'stats' => $this->scanner->get_stats(),
        ], 200 );
    }

    /**
     * Handle cache clearing request
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response Response object.
     */
    public function handle_clear_cache( \WP_REST_Request $request ): \WP_REST_Response {
        $path = $request->get_param( 'path' );
        $cleared = $this->scanner->clear_cache( $path );

        return new \WP_REST_Response( [
            'success' => $cleared,
            'message' => $cleared 
                ? __( 'Cache cleared successfully.', 'disk-usage-sunburst' )
                : __( 'Failed to clear cache.', 'disk-usage-sunburst' ),
        ], $cleared ? 200 : 500 );
    }

    /**
     * Handle stats request
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response Response object.
     */
    public function handle_stats_request( \WP_REST_Request $request ): \WP_REST_Response {
        $stats = $this->scanner->get_stats();
        
        // Limit system information exposure for security
        $system_info = [
            'is_multisite' => is_multisite(),
            'plugin_version' => '2.0.0', // Safe to expose plugin version
            // Removed sensitive system details like PHP version, memory limits, etc.
        ];

        return new \WP_REST_Response( [
            'success' => true,
            'scan_stats' => $stats,
            'system_info' => $system_info,
        ], 200 );
    }

    /**
     * Handle configuration request
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response Response object.
     */
    public function handle_config_request( \WP_REST_Request $request ): \WP_REST_Response {
        $config = get_option( 'rbdusb_options', [
            'max_execution_time' => 120,
            'max_files' => 10000,
            'cache_duration' => 3600,
            'max_depth' => 50,
            'memory_limit' => '256M',
        ] );

        return new \WP_REST_Response( [
            'success' => true,
            'config' => $config,
            'defaults' => [
                'max_execution_time' => 120,
                'max_files' => 10000,
                'cache_duration' => 3600,
                'max_depth' => 50,
                'memory_limit' => '256M',
            ],
        ], 200 );
    }

    /**
     * Handle configuration update
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response Response object.
     */
    public function handle_config_update( \WP_REST_Request $request ): \WP_REST_Response {
        $new_config = $request->get_param( 'config' );
        
        // Sanitize configuration
        $sanitized_config = $this->sanitize_config( $new_config );
        
        // Update options
        $updated = update_option( 'rbdusb_options', $sanitized_config );

        if ( ! $updated ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => __( 'Failed to update configuration.', 'disk-usage-sunburst' ),
            ], 500 );
        }

        // Update scanner configuration
        $this->scanner->update_config( $sanitized_config );

        return new \WP_REST_Response( [
            'success' => true,
            'message' => __( 'Configuration updated successfully.', 'disk-usage-sunburst' ),
            'config' => $sanitized_config,
        ], 200 );
    }

    /**
     * Sanitize configuration array
     *
     * @param array $config Raw configuration.
     * @return array Sanitized configuration.
     */
    private function sanitize_config( array $config ): array {
        $sanitized = [];
        $validation_rules = [
            'max_execution_time' => [
                'type' => 'int',
                'min' => 30,
                'max' => 600,
                'default' => 120
            ],
            'max_files' => [
                'type' => 'int', 
                'min' => 100,
                'max' => 50000,
                'default' => 10000
            ],
            'cache_duration' => [
                'type' => 'int',
                'min' => 300,
                'max' => 86400,
                'default' => 3600
            ],
            'max_depth' => [
                'type' => 'int',
                'min' => 5,
                'max' => 100,
                'default' => 50
            ],
            'memory_limit' => [
                'type' => 'string',
                'pattern' => '/^\d+[MG]?$/',
                'default' => '256M'
            ]
        ];

        foreach ( $validation_rules as $key => $rules ) {
            if ( isset( $config[ $key ] ) ) {
                $value = $config[ $key ];
                
                if ( $rules['type'] === 'int' ) {
                    $value = absint( $value );
                    // Apply bounds checking
                    if ( $value < $rules['min'] || $value > $rules['max'] ) {
                        $value = $rules['default'];
                    }
                    $sanitized[ $key ] = $value;
                } elseif ( $rules['type'] === 'string' ) {
                    $value = sanitize_text_field( $value );
                    // Validate against pattern if provided
                    if ( isset( $rules['pattern'] ) && ! preg_match( $rules['pattern'], $value ) ) {
                        $value = $rules['default'];
                    }
                    $sanitized[ $key ] = $value;
                }
            }
        }

        return $sanitized;
    }

    /**
     * Get API namespace
     *
     * @return string API namespace.
     */
    public function get_namespace(): string {
        return self::NAMESPACE;
    }

    /**
     * DEBUG: Handle capability check request
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response Response object.
     */
    public function debug_capabilities( \WP_REST_Request $request ): \WP_REST_Response {
        $capabilities = [
            'manage_network' => current_user_can( 'manage_network' ),
            'update_core' => current_user_can( 'update_core' ),
            'manage_options' => current_user_can( 'manage_options' ),
            'is_admin' => current_user_can( 'administrator' ),
            'user_login' => wp_get_current_user()->user_login,
            'user_roles' => wp_get_current_user()->roles,
        ];

        return new \WP_REST_Response( [
            'success' => true,
            'capabilities' => $capabilities,
        ], 200 );
    }
}