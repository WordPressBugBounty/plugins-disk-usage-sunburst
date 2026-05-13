<?php
/**
 * Plugin Name: Disk Usage Sunburst
 * Plugin URI:  https://raidboxes.io/en/disk-usage-sunburst-plugin/
 * Description: Modern disk usage visualization plugin with enhanced performance, security, and WordPress compatibility.
 * Author:      raidboxes.io
 * Author URI:  https://raidboxes.io
 * Version:     2.0.2
 * License:     GPL-2.0-or-later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Network:     true
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Text Domain: disk-usage-sunburst
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Security check
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'Unexpected error: WordPress constants not defined.' );
}

// Plugin constants
define( 'RBDUSB_VERSION', '2.0.0' );
define( 'RBDUSB_PLUGIN_FILE', __FILE__ );
define( 'RBDUSB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RBDUSB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include Composer autoloader if available
if ( file_exists( RBDUSB_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once RBDUSB_PLUGIN_DIR . 'vendor/autoload.php';
}

// Manual autoloader for our classes
spl_autoload_register( function( $class ) {
    $prefix = 'RaidBoxes\\DiskUsageSunburst\\';
    $base_dir = RBDUSB_PLUGIN_DIR . 'src/';

    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, $len );
    $file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

    if ( file_exists( $file ) ) {
        require $file;
    }
});

/**
 * Initialize the plugin
 */
function rbdusb_init_plugin() {
    $plugin = \RaidBoxes\DiskUsageSunburst\Plugin::get_instance( RBDUSB_PLUGIN_FILE );
    $plugin->init();
}

// Hook plugin initialization
add_action( 'plugins_loaded', 'rbdusb_init_plugin' );

/**
 * Plugin activation hook
 */
function rbdusb_activate_plugin() {
    // Check requirements before activation
    if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( 
            esc_html__( 'Disk Usage Sunburst requires PHP 7.4 or higher.', 'disk-usage-sunburst' ),
            esc_html__( 'Plugin Activation Error', 'disk-usage-sunburst' ),
            [ 'back_link' => true ]
        );
    }

    global $wp_version;
    if ( version_compare( $wp_version, '5.0', '<' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html__( 'Disk Usage Sunburst requires WordPress 5.0 or higher.', 'disk-usage-sunburst' ),
            esc_html__( 'Plugin Activation Error', 'disk-usage-sunburst' ),
            [ 'back_link' => true ]
        );
    }

    // Initialize plugin for activation
    rbdusb_init_plugin();
    
    // Run activation routine
    $plugin = \RaidBoxes\DiskUsageSunburst\Plugin::get_instance( RBDUSB_PLUGIN_FILE );
    $plugin->activate();
}

register_activation_hook( __FILE__, 'rbdusb_activate_plugin' );

// Backward compatibility - keep old functions for existing installations
if ( ! function_exists( 'rbdusb_humanreadablesize' ) ) {
    /**
     * Legacy function for backward compatibility
     * @param int $bytes
     * @param int $decimals
     * @return string
     * @deprecated 2.0.0 Use FileScanner::format_bytes() instead
     */
    function rbdusb_humanreadablesize( $bytes, $decimals = 2 ) {
        $scanner = new \RaidBoxes\DiskUsageSunburst\Scanner\FileScanner();
        return $scanner->format_bytes( $bytes, $decimals );
    }
}

// Legacy AJAX handler - removed, now handled in AdminInterface
