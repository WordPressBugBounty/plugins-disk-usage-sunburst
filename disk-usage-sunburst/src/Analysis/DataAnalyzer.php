<?php
/**
 * Data Analyzer Class
 *
 * @package RaidBoxes\DiskUsageSunburst
 */

namespace RaidBoxes\DiskUsageSunburst\Analysis;

/**
 * Analyzes scan data to provide detailed insights
 */
class DataAnalyzer {

    /**
     * Analyze scan data and generate detailed reports
     *
     * @param array $scan_data Complete scan data.
     * @return array Analysis results.
     */
    public function analyze( array $scan_data ): array {
        if ( ! empty( $scan_data['metadata']['analysis'] ) && is_array( $scan_data['metadata']['analysis'] ) ) {
            return $scan_data['metadata']['analysis'];
        }

        return [
            'largest_files' => $this->get_largest_files( $scan_data ),
            'largest_folders' => $this->get_largest_folders( $scan_data ),
            'folders_most_files' => $this->get_folders_with_most_files( $scan_data ),
            'file_types' => $this->get_file_type_breakdown( $scan_data ),
            'wordpress_breakdown' => $this->get_wordpress_breakdown( $scan_data ),
            'plugins_analysis' => $this->get_plugins_analysis( $scan_data ),
            'themes_analysis' => $this->get_themes_analysis( $scan_data ),
            'largest_plugin_folders' => $this->get_largest_plugin_folders( $scan_data ),
            'largest_theme_folders' => $this->get_largest_theme_folders( $scan_data ),
            'uploads_summary' => $this->get_uploads_summary( $scan_data ),
            'summary' => $this->get_summary_stats( $scan_data ),
        ];
    }

    /**
     * Get largest files from scan data
     *
     * @param array $scan_data Scan data.
     * @param int   $limit Number of files to return.
     * @return array Largest files.
     */
    public function get_largest_files( array $scan_data, int $limit = 200 ): array {
        $files = [];
        $this->collect_files( $scan_data, $files );
        
        // Sort by size (descending)
        usort( $files, function( $a, $b ) {
            return $b['size'] - $a['size'];
        });

        // Calculate total size for percentages
        $total_size = max( 1, (int) ( $scan_data['size'] ?? 0 ) );

        return array_slice( array_map( function( $file ) use ( $total_size ) {
            return [
                'name' => basename( $file['path'] ),
                'path' => $file['path'],
                'relative_path' => $file['relative_path'] ?? '',
                'size' => $file['size'],
                'human_size' => $this->format_bytes( $file['size'] ),
                'percentage' => round( ( $file['size'] / $total_size ) * 100, 2 ),
                'extension' => $file['extension'] ?? '',
                'mime_type' => $file['mime_type'] ?? '',
                'modified_time' => $file['modified_time'] ?? null,
                'file_type_category' => $this->get_file_category( $file['extension'] ?? '' ),
                'directory_depth' => substr_count( $file['relative_path'] ?? '', '/' ),
            ];
        }, $files ), 0, $limit );
    }

    /**
     * Get largest folders from scan data
     *
     * @param array $scan_data Scan data.
     * @param int   $limit Number of folders to return.
     * @return array Largest folders.
     */
    public function get_largest_folders( array $scan_data, int $limit = 100 ): array {
        $folders = [];
        $this->collect_folders( $scan_data, $folders );

        // Sort by size (descending)
        usort( $folders, function( $a, $b ) {
            return $b['size'] - $a['size'];
        });

        // Calculate total size for percentages
        $total_size = max( 1, (int) ( $scan_data['size'] ?? 0 ) );

        return array_slice( array_map( function( $folder ) use ( $total_size ) {
            return [
                'name' => basename( $folder['path'] ),
                'path' => $folder['path'],
                'relative_path' => $folder['relative_path'] ?? '',
                'size' => $folder['size'],
                'human_size' => $this->format_bytes( $folder['size'] ),
                'percentage' => round( ( $folder['size'] / $total_size ) * 100, 2 ),
                'files_count' => $folder['files_count'] ?? 0,
                'subdirs_count' => $folder['subdirs_count'] ?? 0,
                'directory_depth' => substr_count( $folder['relative_path'] ?? '', '/' ),
                'average_file_size' => $folder['files_count'] > 0 ? $folder['size'] / $folder['files_count'] : 0,
                'average_file_size_human' => $folder['files_count'] > 0 ? $this->format_bytes( $folder['size'] / $folder['files_count'] ) : '0 B',
            ];
        }, $folders ), 0, $limit );
    }

    /**
     * Get file type breakdown
     *
     * @param array $scan_data Scan data.
     * @return array File type statistics.
     */
    public function get_file_type_breakdown( array $scan_data ): array {
        $types = [];
        $this->collect_file_types( $scan_data, $types );

        // Sort by size (descending)
        arsort( $types );

        $total_size = max( 1, (int) ( $scan_data['size'] ?? 0 ) );

        $breakdown = [];
        foreach ( $types as $extension => $size ) {
            $breakdown[] = [
                'extension' => $extension ?: 'No extension',
                'size' => $size,
                'human_size' => $this->format_bytes( $size ),
                'percentage' => round( ( $size / $total_size ) * 100, 2 ),
            ];
        }

        return array_slice( $breakdown, 0, 50 );
    }

    /**
     * Get WordPress-specific breakdown
     *
     * @param array $scan_data Scan data.
     * @return array WordPress breakdown.
     */
    public function get_wordpress_breakdown( array $scan_data ): array {
        $categories = [
            'wp-content/plugins' => 0,
            'wp-content/themes' => 0,
            'wp-content/uploads' => 0,
            'wp-admin' => 0,
            'wp-includes' => 0,
            'wp-content/cache' => 0,
            'wp-content/backup' => 0,
            'core' => 0,
            'other' => 0,
        ];

        $this->categorize_wordpress_files( $scan_data, $categories );

        $total_size = max( 1, (int) ( $scan_data['size'] ?? 0 ) );

        $breakdown = [];
        foreach ( $categories as $category => $size ) {
            if ( $size > 0 ) {
                $breakdown[] = [
                    'category' => $this->get_category_name( $category ),
                    'path' => $category,
                    'size' => $size,
                    'human_size' => $this->format_bytes( $size ),
                    'percentage' => round( ( $size / $total_size ) * 100, 2 ),
                ];
            }
        }

        // Sort by size (descending)
        usort( $breakdown, function( $a, $b ) {
            return $b['size'] - $a['size'];
        });

        return $breakdown;
    }

    /**
     * Get plugins analysis
     *
     * @param array $scan_data Scan data.
     * @return array Plugins analysis.
     */
    public function get_plugins_analysis( array $scan_data ): array {
        $plugins = [];
        $this->analyze_plugins( $scan_data, $plugins );

        // Sort by size (descending)
        usort( $plugins, function( $a, $b ) {
            return $b['size'] - $a['size'];
        });

        return $plugins;
    }

    /**
     * Get themes analysis
     *
     * @param array $scan_data Scan data.
     * @return array Themes analysis.
     */
    public function get_themes_analysis( array $scan_data ): array {
        $themes = [];
        $this->analyze_themes( $scan_data, $themes );

        // Sort by size (descending)
        usort( $themes, function( $a, $b ) {
            return $b['size'] - $a['size'];
        });

        return $themes;
    }

    /**
     * Get summary statistics
     *
     * @param array $scan_data Scan data.
     * @return array Summary stats.
     */
    public function get_summary_stats( array $scan_data ): array {
        $total_size = $scan_data['size'] ?? 0;
        $files_count = $scan_data['metadata']['files_count'] ?? 0;
        $dirs_count = $scan_data['metadata']['directories_count'] ?? 0;

        return [
            'total_size' => $total_size,
            'total_size_human' => $this->format_bytes( $total_size ),
            'files_count' => $files_count,
            'directories_count' => $dirs_count,
            'average_file_size' => $files_count > 0 ? $total_size / $files_count : 0,
            'average_file_size_human' => $files_count > 0 ? $this->format_bytes( $total_size / $files_count ) : '0 B',
        ];
    }

    /**
     * Recursively collect all files from scan data
     *
     * @param array $data Current data node.
     * @param array &$files Reference to files array.
     */
    private function collect_files( array $data, array &$files ): void {
        if ( $data['type'] === 'file' ) {
            $files[] = $data;
        } elseif ( isset( $data['children'] ) ) {
            foreach ( $data['children'] as $child ) {
                $this->collect_files( $child, $files );
            }
        }
    }

    /**
     * Recursively collect all folders from scan data
     *
     * @param array $data Current data node.
     * @param array &$folders Reference to folders array.
     */
    private function collect_folders( array $data, array &$folders ): void {
        if ( $data['type'] === 'directory' && isset( $data['children'] ) ) {
            $files_count = 0;
            $subdirs_count = 0;
            
            foreach ( $data['children'] as $child ) {
                if ( $child['type'] === 'file' ) {
                    $files_count++;
                } else {
                    $subdirs_count++;
                }
            }

            $folder_data = $data;
            $folder_data['files_count'] = $files_count;
            $folder_data['subdirs_count'] = $subdirs_count;
            
            $folders[] = $folder_data;

            foreach ( $data['children'] as $child ) {
                $this->collect_folders( $child, $folders );
            }
        }
    }

    /**
     * Collect file types and their sizes
     *
     * @param array $data Current data node.
     * @param array &$types Reference to types array.
     */
    private function collect_file_types( array $data, array &$types ): void {
        if ( $data['type'] === 'file' ) {
            $extension = $data['extension'] ?? '';
            $extension = strtolower( $extension );
            
            if ( ! isset( $types[ $extension ] ) ) {
                $types[ $extension ] = 0;
            }
            $types[ $extension ] += $data['size'];
        } elseif ( isset( $data['children'] ) ) {
            foreach ( $data['children'] as $child ) {
                $this->collect_file_types( $child, $types );
            }
        }
    }

    /**
     * Categorize WordPress files
     *
     * @param array $data Current data node.
     * @param array &$categories Reference to categories array.
     */
    private function categorize_wordpress_files( array $data, array &$categories ): void {
        $path = $data['relative_path'] ?? $data['path'] ?? '';
        $path = ltrim( $path, '/' );

        $category = $this->determine_wordpress_category( $path );
        // Only count files, not directories (to avoid double counting)
        if ( $data['type'] === 'file' ) {
            $categories[ $category ] += $data['size'];
        }

        if ( isset( $data['children'] ) ) {
            foreach ( $data['children'] as $child ) {
                $this->categorize_wordpress_files( $child, $categories );
            }
        }
    }

    /**
     * Determine WordPress category for a path
     *
     * @param string $path File/folder path.
     * @return string Category name.
     */
    private function determine_wordpress_category( string $path ): string {
        if ( strpos( $path, 'wp-content/plugins' ) === 0 ) {
            return 'wp-content/plugins';
        } elseif ( strpos( $path, 'wp-content/themes' ) === 0 ) {
            return 'wp-content/themes';
        } elseif ( strpos( $path, 'wp-content/uploads' ) === 0 ) {
            return 'wp-content/uploads';
        } elseif ( strpos( $path, 'wp-content/cache' ) === 0 ) {
            return 'wp-content/cache';
        } elseif ( strpos( $path, 'wp-content/backup' ) === 0 ) {
            return 'wp-content/backup';
        } elseif ( strpos( $path, 'wp-admin' ) === 0 ) {
            return 'wp-admin';
        } elseif ( strpos( $path, 'wp-includes' ) === 0 ) {
            return 'wp-includes';
        } elseif ( preg_match( '/^wp-[a-z]+\.php$/', basename( $path ) ) ) {
            return 'core';
        } else {
            return 'other';
        }
    }

    /**
     * Get human-readable category name
     *
     * @param string $category Category key.
     * @return string Human-readable name.
     */
    private function get_category_name( string $category ): string {
        $names = [
            'wp-content/plugins' => 'Plugins',
            'wp-content/themes' => 'Themes',
            'wp-content/uploads' => 'Media/Uploads',
            'wp-admin' => 'WordPress Admin',
            'wp-includes' => 'WordPress Core Files',
            'wp-content/cache' => 'Cache Files',
            'wp-content/backup' => 'Backup Files',
            'core' => 'WordPress Core',
            'other' => 'Other Files',
        ];

        return $names[ $category ] ?? $category;
    }

    /**
     * Analyze individual plugins using the same approach as WordPress breakdown
     *
     * @param array $data Current data node.
     * @param array &$plugins Reference to plugins array.
     */
    private function analyze_plugins( array $data, array &$plugins ): void {
        $this->collect_plugins_recursive( $data, $plugins );
    }
    
    /**
     * Recursively collect plugin data
     *
     * @param array $data Current data node.
     * @param array &$plugins Reference to plugins array.
     */
    private function collect_plugins_recursive( array $data, array &$plugins ): void {
        $path = $data['relative_path'] ?? $data['path'] ?? '';
        $path = ltrim( $path, '/' );
        
        // Check if this is the plugins directory
        if ( $path === 'wp-content/plugins' && isset( $data['children'] ) ) {
            // Process each plugin directory
            foreach ( $data['children'] as $child ) {
                $child_path = $child['relative_path'] ?? $child['path'] ?? '';
                $child_path = ltrim( $child_path, '/' );
                
                // Make sure this is a plugin directory (not index.php or other files)
                if ( isset( $child['children'] ) && strpos( $child_path, 'wp-content/plugins/' ) === 0 ) {
                    $plugin_name = basename( $child_path );
                    
                    if ( $plugin_name && $plugin_name !== 'index.php' && $plugin_name !== '.' && $plugin_name !== '..' ) {
                        $plugin_size = $child['size'] ?? 0;
                        $files_count = $this->count_files_in_node( $child );
                        
                        $plugins[] = [
                            'name' => $plugin_name,
                            'path' => $child_path,
                            'size' => $plugin_size,
                            'files_count' => $files_count,
                        ];
                    }
                }
            }
        }
        
        // Continue recursively through children
        if ( isset( $data['children'] ) ) {
            foreach ( $data['children'] as $child ) {
                $this->collect_plugins_recursive( $child, $plugins );
            }
        }
    }
    
    /**
     * Analyze individual themes using the same approach as WordPress breakdown
     *
     * @param array $data Current data node.
     * @param array &$themes Reference to themes array.
     */
    private function analyze_themes( array $data, array &$themes ): void {
        $this->collect_themes_recursive( $data, $themes );
    }
    
    /**
     * Recursively collect theme data
     *
     * @param array $data Current data node.
     * @param array &$themes Reference to themes array.
     */
    private function collect_themes_recursive( array $data, array &$themes ): void {
        $path = $data['relative_path'] ?? $data['path'] ?? '';
        $path = ltrim( $path, '/' );
        
        // Check if this is the themes directory
        if ( $path === 'wp-content/themes' && isset( $data['children'] ) ) {
            // Process each theme directory
            foreach ( $data['children'] as $child ) {
                $child_path = $child['relative_path'] ?? $child['path'] ?? '';
                $child_path = ltrim( $child_path, '/' );
                
                // Make sure this is a theme directory (not index.php or other files)
                if ( isset( $child['children'] ) && strpos( $child_path, 'wp-content/themes/' ) === 0 ) {
                    $theme_name = basename( $child_path );
                    
                    if ( $theme_name && $theme_name !== 'index.php' && $theme_name !== '.' && $theme_name !== '..' ) {
                        $theme_size = $child['size'] ?? 0;
                        $files_count = $this->count_files_in_node( $child );
                        
                        $themes[] = [
                            'name' => $theme_name,
                            'path' => $child_path,
                            'size' => $theme_size,
                            'files_count' => $files_count,
                        ];
                    }
                }
            }
        }
        
        // Continue recursively through children
        if ( isset( $data['children'] ) ) {
            foreach ( $data['children'] as $child ) {
                $this->collect_themes_recursive( $child, $themes );
            }
        }
    }
    
    /**
     * Count files in a given node recursively
     *
     * @param array $data Node data.
     * @return int Number of files.
     */
    private function count_files_in_node( array $data ): int {
        $count = 0;
        
        if ( isset( $data['type'] ) && $data['type'] === 'file' ) {
            $count = 1;
        }
        
        if ( isset( $data['children'] ) ) {
            foreach ( $data['children'] as $child ) {
                $count += $this->count_files_in_node( $child );
            }
        }
        
        return $count;
    }

    /**
     * Format bytes to human readable format
     *
     * @param int $bytes Size in bytes.
     * @return string Formatted size.
     */
    private function format_bytes( int $bytes ): string {
        $units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
        
        for ( $i = 0; $bytes >= 1024 && $i < count( $units ) - 1; $i++ ) {
            $bytes /= 1024;
        }

        return round( $bytes, 2 ) . ' ' . $units[ $i ];
    }
    
    /**
     * Get folders with most files
     *
     * @param array $scan_data Scan data.
     * @param int   $limit Number of folders to return.
     * @return array Folders with most files.
     */
    public function get_folders_with_most_files( array $scan_data, int $limit = 100 ): array {
        $folders = [];
        $this->collect_folders( $scan_data, $folders );

        // Sort by file count (descending)
        usort( $folders, function( $a, $b ) {
            return $b['files_count'] - $a['files_count'];
        });

        // Calculate total size for percentages
        $total_size = max( 1, (int) ( $scan_data['size'] ?? 0 ) );

        return array_slice( array_map( function( $folder ) use ( $total_size ) {
            return [
                'name' => basename( $folder['path'] ),
                'path' => $folder['path'],
                'relative_path' => $folder['relative_path'] ?? '',
                'size' => $folder['size'],
                'human_size' => $this->format_bytes( $folder['size'] ),
                'percentage' => round( ( $folder['size'] / $total_size ) * 100, 2 ),
                'files_count' => $folder['files_count'] ?? 0,
                'subdirs_count' => $folder['subdirs_count'] ?? 0,
                'directory_depth' => substr_count( $folder['relative_path'] ?? '', '/' ),
                'average_file_size' => $folder['files_count'] > 0 ? $folder['size'] / $folder['files_count'] : 0,
                'average_file_size_human' => $folder['files_count'] > 0 ? $this->format_bytes( $folder['size'] / $folder['files_count'] ) : '0 B',
            ];
        }, $folders ), 0, $limit );
    }

    /**
     * Get largest plugin folders
     *
     * @param array $scan_data Scan data.
     * @return array Largest plugin folders.
     */
    public function get_largest_plugin_folders( array $scan_data ): array {
        $plugins = [];
        $this->analyze_plugins( $scan_data, $plugins );


        // Sort by size (descending)
        usort( $plugins, function( $a, $b ) {
            return $b['size'] - $a['size'];
        });

        return array_map( function( $plugin ) {
            return [
                'name' => $plugin['name'],
                'path' => $plugin['path'],
                'size' => $plugin['size'],
                'human_size' => $this->format_bytes( $plugin['size'] ),
                'files_count' => $plugin['files_count'],
                'average_file_size' => $plugin['files_count'] > 0 ? $plugin['size'] / $plugin['files_count'] : 0,
                'average_file_size_human' => $plugin['files_count'] > 0 ? $this->format_bytes( $plugin['size'] / $plugin['files_count'] ) : '0 B',
            ];
        }, $plugins );
    }

    /**
     * Get largest theme folders
     *
     * @param array $scan_data Scan data.
     * @return array Largest theme folders.
     */
    public function get_largest_theme_folders( array $scan_data ): array {
        $themes = [];
        $this->analyze_themes( $scan_data, $themes );

        // Sort by size (descending)
        usort( $themes, function( $a, $b ) {
            return $b['size'] - $a['size'];
        });

        return array_map( function( $theme ) {
            return [
                'name' => $theme['name'],
                'path' => $theme['path'],
                'size' => $theme['size'],
                'human_size' => $this->format_bytes( $theme['size'] ),
                'files_count' => $theme['files_count'],
                'average_file_size' => $theme['files_count'] > 0 ? $theme['size'] / $theme['files_count'] : 0,
                'average_file_size_human' => $theme['files_count'] > 0 ? $this->format_bytes( $theme['size'] / $theme['files_count'] ) : '0 B',
            ];
        }, $themes );
    }

    /**
     * Get uploads summary (organized by year/month)
     *
     * @param array $scan_data Scan data.
     * @return array Uploads summary.
     */
    public function get_uploads_summary( array $scan_data ): array {
        $uploads_data = [];
        $this->collect_uploads_data( $scan_data, $uploads_data );

        // Sort by date (descending)
        krsort( $uploads_data );

        $summary = [];
        foreach ( $uploads_data as $period => $data ) {
            $summary[] = [
                'period' => $period,
                'size' => $data['size'],
                'human_size' => $this->format_bytes( $data['size'] ),
                'files_count' => $data['files_count'],
                'average_file_size' => $data['files_count'] > 0 ? $data['size'] / $data['files_count'] : 0,
                'average_file_size_human' => $data['files_count'] > 0 ? $this->format_bytes( $data['size'] / $data['files_count'] ) : '0 B',
            ];
        }

        return $summary;
    }

    /**
     * Collect uploads data organized by year/month
     *
     * @param array $data Current data node.
     * @param array &$uploads_data Reference to uploads data array.
     */
    private function collect_uploads_data( array $data, array &$uploads_data ): void {
        $path = $data['relative_path'] ?? $data['path'] ?? '';
        $path = ltrim( $path, '/' );

        // Check if this is the uploads directory
        if ( $path === 'wp-content/uploads' && isset( $data['children'] ) ) {
            // Process uploads directory directly
            foreach ( $data['children'] as $child ) {
                $this->process_uploads_child( $child, $uploads_data );
            }
        }

        // Continue recursively through children for other directories
        if ( isset( $data['children'] ) ) {
            foreach ( $data['children'] as $child ) {
                $this->collect_uploads_data( $child, $uploads_data );
            }
        }
    }

    /**
     * Process a child of the uploads directory
     *
     * @param array $child Child node data.
     * @param array &$uploads_data Reference to uploads data array.
     */
    private function process_uploads_child( array $child, array &$uploads_data ): void {
        $child_path = $child['relative_path'] ?? $child['path'] ?? '';
        $child_path = ltrim( $child_path, '/' );
        
        // Extract the part after wp-content/uploads/
        if ( strpos( $child_path, 'wp-content/uploads/' ) === 0 ) {
            $uploads_path = substr( $child_path, 18 ); // Remove 'wp-content/uploads/'
            $uploads_path = ltrim( $uploads_path, '/' ); // Remove any leading slash
            
            // Check if this matches year pattern (e.g., "2024", "2025")
            if ( preg_match( '/^(\d{4})$/', $uploads_path, $matches ) ) {
                $year = $matches[1];
                
                // This is a year directory, process its month subdirectories
                if ( isset( $child['children'] ) ) {
                    foreach ( $child['children'] as $month_child ) {
                        $this->process_uploads_month( $month_child, $year, $uploads_data );
                    }
                } else {
                    // Year directory with no children, still count it
                    $period = $year;
                    if ( ! isset( $uploads_data[ $period ] ) ) {
                        $uploads_data[ $period ] = [
                            'size' => 0,
                            'files_count' => 0,
                        ];
                    }
                    
                    $uploads_data[ $period ]['size'] += $child['size'] ?? 0;
                    if ( ( $child['type'] ?? '' ) === 'file' ) {
                        $uploads_data[ $period ]['files_count']++;
                    }
                }
            } else {
                // This doesn't match year pattern, put it in "Other"
                $period = 'Other';
                
                if ( ! isset( $uploads_data[ $period ] ) ) {
                    $uploads_data[ $period ] = [
                        'size' => 0,
                        'files_count' => 0,
                    ];
                }
                
                $this->count_files_and_size_in_node( $child, $uploads_data[ $period ] );
            }
        }
    }

    /**
     * Process a month subdirectory within a year directory
     *
     * @param array $month_child Month directory node.
     * @param string $year Year string.
     * @param array &$uploads_data Reference to uploads data array.
     */
    private function process_uploads_month( array $month_child, string $year, array &$uploads_data ): void {
        $month_path = $month_child['relative_path'] ?? $month_child['path'] ?? '';
        $month_path = ltrim( $month_path, '/' );
        
        // Extract month from path
        if ( strpos( $month_path, "wp-content/uploads/{$year}/" ) === 0 ) {
            $month_part = substr( $month_path, strlen( "wp-content/uploads/{$year}/" ) );
            
            // Check if this is a month directory (01, 02, etc.)
            if ( preg_match( '/^(\d{2})$/', $month_part, $matches ) ) {
                $month = $matches[1];
                $period = "{$year}/{$month}";
                
                if ( ! isset( $uploads_data[ $period ] ) ) {
                    $uploads_data[ $period ] = [
                        'size' => 0,
                        'files_count' => 0,
                    ];
                }
                
                $this->count_files_and_size_in_node( $month_child, $uploads_data[ $period ] );
            } else {
                // Not a standard month directory, add to year total
                $period = $year;
                
                if ( ! isset( $uploads_data[ $period ] ) ) {
                    $uploads_data[ $period ] = [
                        'size' => 0,
                        'files_count' => 0,
                    ];
                }
                
                $this->count_files_and_size_in_node( $month_child, $uploads_data[ $period ] );
            }
        }
    }

    /**
     * Count files and size in a node recursively
     *
     * @param array $node Node data.
     * @param array &$counter Reference to counter array with 'size' and 'files_count' keys.
     */
    private function count_files_and_size_in_node( array $node, array &$counter ): void {
        $counter['size'] += $node['size'] ?? 0;
        
        if ( ( $node['type'] ?? '' ) === 'file' ) {
            $counter['files_count']++;
        }
        
        if ( isset( $node['children'] ) ) {
            foreach ( $node['children'] as $child ) {
                $this->count_files_and_size_in_node( $child, $counter );
            }
        }
    }

    /**
     * Get file category based on extension
     *
     * @param string $extension File extension.
     * @return string File category.
     */
    private function get_file_category( string $extension ): string {
        $extension = strtolower( $extension );
        
        $categories = [
            'image' => [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'tiff' ],
            'video' => [ 'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv', 'm4v' ],
            'audio' => [ 'mp3', 'wav', 'aac', 'ogg', 'wma', 'flac', 'm4a' ],
            'document' => [ 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt' ],
            'archive' => [ 'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz' ],
            'code' => [ 'php', 'js', 'css', 'html', 'htm', 'xml', 'json', 'sql', 'py', 'java', 'cpp', 'c' ],
            'font' => [ 'ttf', 'otf', 'woff', 'woff2', 'eot' ],
            'data' => [ 'csv', 'tsv', 'xml', 'json', 'yaml', 'yml' ],
        ];
        
        foreach ( $categories as $category => $extensions ) {
            if ( in_array( $extension, $extensions, true ) ) {
                return $category;
            }
        }
        
        return 'other';
    }
}