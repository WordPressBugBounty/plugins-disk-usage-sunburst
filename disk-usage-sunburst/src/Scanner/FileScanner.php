<?php
/**
 * File Scanner Class
 *
 * @package RaidBoxes\DiskUsageSunburst
 */

namespace RaidBoxes\DiskUsageSunburst\Scanner;

/**
 * Handles file system scanning with performance optimizations
 */
class FileScanner {

    /**
     * Default configuration
     */
    private const DEFAULT_CONFIG = [
        'max_execution_time' => 300,
        'memory_limit' => '256M',
        'max_depth' => 50,
        'max_files' => 100000,
        'chunk_size' => 100,
        'cache_duration' => 3600, // 1 hour
    ];

    /**
     * Excluded directories and files
     */
    private const DEFAULT_EXCLUDES = [
        '.git',
        '.svn',
        '.DS_Store',
        'Thumbs.db',
        'node_modules',
        '.sass-cache',
        'cache',
        'logs',
    ];

    /**
     * Configuration options
     *
     * @var array
     */
    private array $config;

    /**
     * Scan statistics
     *
     * @var array
     */
    private array $stats;

    /**
     * Constructor
     *
     * @param array $config Optional configuration override.
     */
    public function __construct( array $config = [] ) {
        $this->config = wp_parse_args( $config, self::DEFAULT_CONFIG );
        $this->stats = [
            'files_scanned' => 0,
            'directories_scanned' => 0,
            'total_size' => 0,
            'scan_time' => 0,
            'memory_peak' => 0,
        ];
    }

    /**
     * Scan directory structure
     *
     * @param string $path Path to scan.
     * @param bool   $use_cache Whether to use cached results.
     * @return array|WP_Error Scan results or error.
     */
    public function scan( string $path, bool $use_cache = true ) {
        // Validate path
        if ( ! $this->is_valid_path( $path ) ) {
            return new \WP_Error( 'invalid_path', __( 'Invalid or inaccessible path provided.', 'disk-usage-sunburst' ) );
        }

        // Check for cached results
        if ( $use_cache ) {
            $cache_key = $this->get_cache_key( $path );
            $cached_result = get_transient( $cache_key );
            if ( false !== $cached_result ) {
                return $cached_result;
            }
        }

        // Setup performance monitoring
        $start_time = microtime( true );
        $this->setup_performance_limits();

        try {
            // Perform the scan
            $result = $this->perform_scan( $path );
            
            // Update statistics
            $this->stats['scan_time'] = microtime( true ) - $start_time;
            $this->stats['memory_peak'] = memory_get_peak_usage( true );

            // Add metadata to result
            $result['metadata'] = [
                'scan_time' => $this->stats['scan_time'],
                'files_count' => $this->stats['files_scanned'],
                'directories_count' => $this->stats['directories_scanned'],
                'total_size' => $this->stats['total_size'],
                'memory_peak' => $this->stats['memory_peak'],
                'scanned_at' => current_time( 'mysql' ),
            ];

            // Cache the result
            if ( $use_cache ) {
                set_transient( $cache_key, $result, $this->config['cache_duration'] );
            }

            do_action( 'rbdusb_scan_completed', $result, $this->stats );

            return $result;

        } catch ( \Exception $e ) {
            do_action( 'rbdusb_scan_error', $e->getMessage(), $path );
            return new \WP_Error( 'scan_failed', $e->getMessage() );
        }
    }

    /**
     * Perform the actual directory scan
     *
     * @param string $path      Path to scan.
     * @param string $base_path Base path for relative calculations.
     * @param int    $depth     Current depth level.
     * @return array Scan results.
     * @throws \Exception If scan fails.
     */
    private function perform_scan( string $path, string $base_path = '', int $depth = 0 ): array {
        if ( empty( $base_path ) ) {
            $base_path = $path;
        }

        // Check depth limit
        if ( $depth > $this->config['max_depth'] ) {
            throw new \Exception( sprintf( 'Maximum scan depth (%d) exceeded', $this->config['max_depth'] ) );
        }

        // Check file limit
        if ( $this->stats['files_scanned'] > $this->config['max_files'] ) {
            throw new \Exception( sprintf( 'Maximum file limit (%d) exceeded', $this->config['max_files'] ) );
        }

        // Check execution time
        if ( $this->is_time_limit_approaching() ) {
            throw new \Exception( 'Execution time limit approaching' );
        }

        $result = [
            'name' => basename( $path ),
            'path' => $path,
            'relative_path' => ltrim( str_replace( $base_path, '', $path ), '/' ),
            'size' => 0,
            'type' => is_dir( $path ) ? 'directory' : 'file',
        ];

        if ( ! is_dir( $path ) ) {
            // Handle file
            $filesize = $this->get_safe_filesize( $path );
            $result['size'] = $filesize;
            $result['extension'] = pathinfo( $path, PATHINFO_EXTENSION );
            $result['mime_type'] = $this->get_mime_type( $path );
            
            $this->stats['files_scanned']++;
            $this->stats['total_size'] += $filesize;

        } else {
            // Handle directory
            $this->stats['directories_scanned']++;
            
            if ( $this->should_exclude_path( $path ) ) {
                $result['excluded'] = true;
                return $result;
            }

            $children = [];
            $directory_size = 0;

            try {
                $files = $this->scan_directory( $path );
                
                foreach ( $files as $file ) {
                    $file_path = $path . DIRECTORY_SEPARATOR . $file;
                    
                    if ( $file === '.' || $file === '..' ) {
                        continue;
                    }

                    $child = $this->perform_scan( $file_path, $base_path, $depth + 1 );
                    $children[] = $child;
                    $directory_size += $child['size'];
                }

            } catch ( \Exception $e ) {
                // Log the error but continue scanning
                error_log( sprintf( 'Disk Usage Sunburst: Error scanning directory %s: %s', $path, $e->getMessage() ) );
                $result['error'] = $e->getMessage();
            }

            if ( ! empty( $children ) ) {
                $result['children'] = $children;
            }
            
            $result['size'] = $directory_size;
        }

        // Add human readable size
        $result['human_size'] = $this->format_bytes( $result['size'] );

        return $result;
    }

    /**
     * Setup performance limits
     */
    private function setup_performance_limits(): void {
        // Increase time limit if possible
        if ( function_exists( 'set_time_limit' ) && ! ini_get( 'safe_mode' ) ) {
            set_time_limit( $this->config['max_execution_time'] );
        }

        // Increase memory limit if possible
        if ( function_exists( 'ini_set' ) ) {
            ini_set( 'memory_limit', $this->config['memory_limit'] );
        }
    }

    /**
     * Check if time limit is approaching
     *
     * @return bool
     */
    private function is_time_limit_approaching(): bool {
        $max_execution_time = ini_get( 'max_execution_time' );
        if ( 0 === $max_execution_time ) {
            return false; // No time limit
        }

        $runtime = microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'];
        return $runtime > ( $max_execution_time * 0.8 ); // 80% of time limit
    }

    /**
     * Validate if path is accessible and safe
     *
     * @param string $path Path to validate.
     * @return bool
     */
    private function is_valid_path( string $path ): bool {
        // Check if path exists and is readable
        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            return false;
        }

        // Enhanced security checks for path traversal prevention
        $real_path = realpath( $path );
        $wp_path = realpath( ABSPATH );
        
        // Ensure paths are resolved correctly
        if ( ! $real_path || ! $wp_path ) {
            return false;
        }
        
        // Normalize paths to prevent bypass attempts
        $real_path = rtrim( $real_path, DIRECTORY_SEPARATOR );
        $wp_path = rtrim( $wp_path, DIRECTORY_SEPARATOR );
        
        // Strict check: path must be equal to or within WordPress directory
        if ( $real_path !== $wp_path && strpos( $real_path . DIRECTORY_SEPARATOR, $wp_path . DIRECTORY_SEPARATOR ) !== 0 ) {
            return false;
        }
        
        // Block access to sensitive areas within WordPress (but not if they are the main scan target)
        if ( $real_path !== $wp_path ) {
            $sensitive_paths = [
                $wp_path . DIRECTORY_SEPARATOR . 'wp-config.php',
                $wp_path . DIRECTORY_SEPARATOR . '.htaccess', 
                $wp_path . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'debug.log',
            ];
            
            foreach ( $sensitive_paths as $sensitive_path ) {
                $sensitive_real = realpath( $sensitive_path );
                if ( $sensitive_real && strpos( $real_path, $sensitive_real ) === 0 ) {
                    return false;
                }
            }
        }
        
        // Additional check: prevent access to parent directories using ../ 
        if ( strpos( $path, '..' ) !== false ) {
            return false;
        }
        
        return true;
    }

    /**
     * Check if path should be excluded from scanning
     *
     * @param string $path Path to check.
     * @return bool
     */
    private function should_exclude_path( string $path ): bool {
        $basename = basename( $path );
        
        // Check against default excludes
        foreach ( self::DEFAULT_EXCLUDES as $exclude ) {
            if ( $basename === $exclude ) {
                return true;
            }
        }

        // Allow filtering of excluded paths
        $excludes = apply_filters( 'rbdusb_exclude_paths', self::DEFAULT_EXCLUDES, $path );
        
        return in_array( $basename, $excludes, true );
    }

    /**
     * Safely scan directory contents
     *
     * @param string $path Directory path.
     * @return array File list.
     * @throws \Exception If directory cannot be read.
     */
    private function scan_directory( string $path ): array {
        $handle = opendir( $path );
        if ( false === $handle ) {
            throw new \Exception( sprintf( 'Cannot read directory: %s', $path ) );
        }

        $files = [];
        while ( false !== ( $file = readdir( $handle ) ) ) {
            $files[] = $file;
        }
        closedir( $handle );

        return $files;
    }

    /**
     * Get file size safely
     *
     * @param string $path File path.
     * @return int File size in bytes.
     */
    private function get_safe_filesize( string $path ): int {
        $size = filesize( $path );
        return false !== $size ? $size : 0;
    }

    /**
     * Get MIME type of file
     *
     * @param string $path File path.
     * @return string MIME type.
     */
    private function get_mime_type( string $path ): string {
        if ( function_exists( 'mime_content_type' ) ) {
            $mime = mime_content_type( $path );
            if ( false !== $mime ) {
                return $mime;
            }
        }

        // Fallback to WordPress function
        if ( function_exists( 'wp_check_filetype' ) ) {
            $filetype = wp_check_filetype( $path );
            return $filetype['type'] ?? 'application/octet-stream';
        }

        return 'application/octet-stream';
    }

    /**
     * Format bytes to human readable format
     *
     * @param int $bytes     Size in bytes.
     * @param int $precision Decimal precision.
     * @return string Formatted size.
     */
    public function format_bytes( int $bytes, int $precision = 2 ): string {
        $units = [ 'B', 'KB', 'MB', 'GB', 'TB', 'PB' ];
        
        for ( $i = 0; $bytes >= 1024 && $i < count( $units ) - 1; $i++ ) {
            $bytes /= 1024;
        }

        return round( $bytes, $precision ) . ' ' . $units[ $i ];
    }

    /**
     * Get cache key for path
     *
     * @param string $path Path to generate key for.
     * @return string Cache key.
     */
    private function get_cache_key( string $path ): string {
        return 'rbdusb_scan_' . md5( $path . serialize( $this->config ) );
    }

    /**
     * Clear scan cache
     *
     * @param string $path Optional specific path to clear.
     * @return bool Success status.
     */
    public function clear_cache( string $path = '' ): bool {
        if ( empty( $path ) ) {
            // Clear all scan caches - use prepared statements to prevent SQL injection
            global $wpdb;
            
            // Use prepared statements with wildcards for security
            $wpdb->query( $wpdb->prepare( 
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_rbdusb_scan_%'
            ));
            
            $wpdb->query( $wpdb->prepare( 
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_timeout_rbdusb_scan_%'
            ));
            
            return true;
        }

        return delete_transient( $this->get_cache_key( $path ) );
    }

    /**
     * Get scan statistics
     *
     * @return array Statistics array.
     */
    public function get_stats(): array {
        return $this->stats;
    }

    /**
     * Update configuration
     *
     * @param array $config New configuration.
     */
    public function update_config( array $config ): void {
        $this->config = wp_parse_args( $config, $this->config );
    }
}
