<?php
/**
 * Chunked scan job manager.
 *
 * @package RaidBoxes\DiskUsageSunburst
 */

namespace RaidBoxes\DiskUsageSunburst\Scanner;

/**
 * Runs disk scans in small resumable jobs so large installations do not need to
 * finish within one PHP request.
 */
class ScanJobManager {

    /**
     * Default job configuration.
     */
    private const DEFAULT_CONFIG = [
        'time_per_step'         => 2.5,
        'files_per_step'        => 1000,
        'dirs_per_step'         => 50,
        'max_depth'             => 500,
        'max_children_per_node' => 100,
        'max_top_files'         => 200,
        'max_top_folders'       => 200,
        'cache_duration'        => 3600,
    ];

    /**
     * Paths that are skipped by default. Runtime/cache folders are intentionally
     * not excluded because they are real disk usage and should be counted.
     */
    private const DEFAULT_EXCLUDES = [
        '.git',
        '.svn',
        '.hg',
        '.DS_Store',
        'Thumbs.db',
    ];

    /**
     * Job configuration.
     *
     * @var array
     */
    private array $config;

    /**
     * Constructor.
     *
     * @param array $config Optional configuration.
     */
    public function __construct( array $config = [] ) {
        $this->config = wp_parse_args( $this->sanitize_config( $config ), self::DEFAULT_CONFIG );
    }

    /**
     * Start a new scan job or return a cached result.
     *
     * @param string $path      Path to scan.
     * @param bool   $use_cache Whether cached results may be used.
     * @return array|\WP_Error Job information or cached result.
     */
    public function start_job( string $path, bool $use_cache = true ) {
        if ( ! $this->is_valid_path( $path ) ) {
            return new \WP_Error( 'invalid_path', __( 'Invalid or inaccessible path provided.', 'disk-usage-sunburst' ) );
        }

        $this->cleanup_old_jobs();

        if ( $use_cache ) {
            $cached = get_transient( $this->get_cache_key( $path ) );
            if ( is_array( $cached ) && ! empty( $cached['metadata']['complete'] ) ) {
                return [
                    'completed' => true,
                    'cached'    => true,
                    'data'      => $cached,
                    'progress'  => [
                        'percent' => 100,
                        'message' => __( 'Loaded cached scan result.', 'disk-usage-sunburst' ),
                    ],
                ];
            }
        }

        $job_id = $this->generate_job_id();
        $job_dir = $this->get_job_dir( $job_id );

        if ( ! wp_mkdir_p( $job_dir ) ) {
            return new \WP_Error( 'job_directory_failed', __( 'Could not create scan job directory.', 'disk-usage-sunburst' ) );
        }

        $real_path = realpath( $path );
        if ( false === $real_path ) {
            return new \WP_Error( 'invalid_path', __( 'Invalid scan path.', 'disk-usage-sunburst' ) );
        }

        $root_id = '1';
        $now = current_time( 'mysql' );

        $state = [
            'job_id'              => $job_id,
            'status'              => 'running',
            'base_path'           => $real_path,
            'created_at'          => $now,
            'updated_at'          => $now,
            'started_at_micro'    => microtime( true ),
            'completed_at'        => null,
            'next_id'             => 2,
            'queue'               => [ $root_id ],
            'current_dir'         => null,
            'nodes'               => [
                $root_id => $this->create_directory_node( $root_id, null, $real_path, '', 0 ),
            ],
            'files_scanned'       => 0,
            'directories_scanned' => 0,
            'directories_found'   => 1,
            'total_size'          => 0,
            'symlinks_skipped'    => 0,
            'excluded_skipped'    => 0,
            'invalid_skipped'     => 0,
            'errors'              => [],
            'warnings'            => [],
            'top_files'           => [],
            'file_types'          => [],
            'wp_breakdown'        => [],
            'plugins'             => [],
            'themes'              => [],
            'uploads'             => [],
            'step_count'          => 0,
            'config'              => $this->config,
            'snapshot_id'         => null,
            'cache_key'           => $this->get_cache_key( $real_path ),
        ];

        $this->save_state( $job_id, $state );

        return [
            'completed' => false,
            'cached'    => false,
            'job_id'    => $job_id,
            'progress'  => $this->get_progress_data( $state, __( 'Scan job created.', 'disk-usage-sunburst' ) ),
        ];
    }

    /**
     * Run one small scan step.
     *
     * @param string $job_id Job ID.
     * @return array|\WP_Error Step result.
     */
    public function run_step( string $job_id ) {
        $state = $this->load_state( $job_id );
        if ( ! is_array( $state ) ) {
            return new \WP_Error( 'job_not_found', __( 'Scan job not found.', 'disk-usage-sunburst' ) );
        }

        if ( 'completed' === ( $state['status'] ?? '' ) ) {
            $result = $this->load_result( $job_id );
            return [
                'completed' => true,
                'job_id'    => $job_id,
                'data'      => $result,
                'progress'  => $this->get_progress_data( $state, __( 'Scan already completed.', 'disk-usage-sunburst' ) ),
            ];
        }

        if ( 'cancelled' === ( $state['status'] ?? '' ) ) {
            return new \WP_Error( 'job_cancelled', __( 'Scan job was cancelled.', 'disk-usage-sunburst' ) );
        }

        $step_started = microtime( true );
        $files_before = (int) $state['files_scanned'];
        $dirs_before = (int) $state['directories_scanned'];

        try {
            while ( ! $this->is_step_budget_exhausted( $step_started, $files_before, $dirs_before, $state ) ) {
                if ( empty( $state['current_dir'] ) ) {
                    if ( empty( $state['queue'] ) ) {
                        $result = $this->finalize_job( $job_id, $state );

                        return [
                            'completed' => true,
                            'job_id'    => $job_id,
                            'data'      => $result,
                            'progress'  => $this->get_progress_data( $state, __( 'Scan completed.', 'disk-usage-sunburst' ), 100 ),
                        ];
                    }

                    $next_node_id = (string) array_shift( $state['queue'] );
                    $this->open_current_directory( $state, $next_node_id );
                }

                $this->process_current_directory_entries( $state, $step_started, $files_before, $dirs_before );
            }
        } catch ( \Throwable $e ) {
            $state['errors'][] = $this->limit_error_message( $e->getMessage() );
        }

        $state['step_count'] = (int) $state['step_count'] + 1;
        $state['updated_at'] = current_time( 'mysql' );
        $this->save_state( $job_id, $state );

        return [
            'completed' => false,
            'job_id'    => $job_id,
            'progress'  => $this->get_progress_data( $state, __( 'Scanning...', 'disk-usage-sunburst' ) ),
        ];
    }

    /**
     * Cancel a scan job.
     *
     * @param string $job_id Job ID.
     * @return bool Whether the job could be cancelled.
     */
    public function cancel_job( string $job_id ): bool {
        $state = $this->load_state( $job_id );
        if ( ! is_array( $state ) ) {
            return false;
        }

        $state['status'] = 'cancelled';
        $state['updated_at'] = current_time( 'mysql' );
        $this->save_state( $job_id, $state );

        return true;
    }

    /**
     * Get current job status.
     *
     * @param string $job_id Job ID.
     * @return array|\WP_Error Status response.
     */
    public function get_status( string $job_id ) {
        $state = $this->load_state( $job_id );
        if ( ! is_array( $state ) ) {
            return new \WP_Error( 'job_not_found', __( 'Scan job not found.', 'disk-usage-sunburst' ) );
        }

        return [
            'job_id'    => $job_id,
            'status'    => $state['status'] ?? 'unknown',
            'completed' => 'completed' === ( $state['status'] ?? '' ),
            'progress'  => $this->get_progress_data( $state ),
        ];
    }

    /**
     * Attach a snapshot ID to a completed job result.
     *
     * @param string $job_id      Job ID.
     * @param string $snapshot_id Snapshot ID.
     */
    public function set_snapshot_id( string $job_id, string $snapshot_id ): void {
        $state = $this->load_state( $job_id );
        if ( is_array( $state ) ) {
            $state['snapshot_id'] = $snapshot_id;
            $this->save_state( $job_id, $state );
        }

        $result = $this->load_result( $job_id );
        if ( is_array( $result ) ) {
            $result['snapshot_id'] = $snapshot_id;
            $result['metadata']['snapshot_id'] = $snapshot_id;
            $this->save_result( $job_id, $result );
        }
    }

    /**
     * Get snapshot ID attached to a job.
     *
     * @param string $job_id Job ID.
     * @return string|null Snapshot ID.
     */
    public function get_snapshot_id( string $job_id ): ?string {
        $state = $this->load_state( $job_id );
        if ( is_array( $state ) && ! empty( $state['snapshot_id'] ) ) {
            return (string) $state['snapshot_id'];
        }

        $result = $this->load_result( $job_id );
        if ( is_array( $result ) && ! empty( $result['snapshot_id'] ) ) {
            return (string) $result['snapshot_id'];
        }

        return null;
    }

    /**
     * Sanitize config.
     *
     * @param array $config Raw config.
     * @return array Sanitized config.
     */
    private function sanitize_config( array $config ): array {
        $sanitized = [];

        $integer_limits = [
            'files_per_step'        => [ 100, 5000 ],
            'dirs_per_step'         => [ 1, 250 ],
            'max_depth'             => [ 10, 1000 ],
            'max_children_per_node' => [ 25, 500 ],
            'max_top_files'         => [ 50, 1000 ],
            'max_top_folders'       => [ 50, 1000 ],
            'cache_duration'        => [ 300, 86400 ],
        ];

        foreach ( $integer_limits as $key => $limits ) {
            if ( array_key_exists( $key, $config ) ) {
                $value = absint( $config[ $key ] );
                $sanitized[ $key ] = max( $limits[0], min( $value, $limits[1] ) );
            }
        }

        if ( array_key_exists( 'time_per_step', $config ) ) {
            $value = (float) $config['time_per_step'];
            $sanitized['time_per_step'] = max( 0.5, min( $value, 10.0 ) );
        }

        return $sanitized;
    }

    /**
     * Create a directory node.
     */
    private function create_directory_node( string $id, ?string $parent, string $path, string $relative_path, int $depth ): array {
        return [
            'id'                => $id,
            'parent'            => $parent,
            'name'              => '' === $relative_path ? basename( $path ) : basename( $relative_path ),
            'path'              => $path,
            'relative_path'     => $relative_path,
            'type'              => 'directory',
            'depth'             => $depth,
            'direct_file_size'  => 0,
            'size'              => 0,
            'direct_file_count' => 0,
            'file_count'        => 0,
            'dirs_count'        => 0,
            'children'          => [],
            'error'             => null,
        ];
    }

    /**
     * Open a directory for chunked processing.
     */
    private function open_current_directory( array &$state, string $node_id ): void {
        if ( empty( $state['nodes'][ $node_id ] ) ) {
            return;
        }

        $node = $state['nodes'][ $node_id ];
        $path = (string) $node['path'];

        if ( ! is_dir( $path ) || ! is_readable( $path ) ) {
            $state['nodes'][ $node_id ]['error'] = __( 'Directory is not readable.', 'disk-usage-sunburst' );
            $state['directories_scanned'] = (int) $state['directories_scanned'] + 1;
            return;
        }

        if ( $this->should_exclude_path( $path ) && 0 !== (int) $node['depth'] ) {
            $state['excluded_skipped'] = (int) $state['excluded_skipped'] + 1;
            $state['directories_scanned'] = (int) $state['directories_scanned'] + 1;
            unset( $state['nodes'][ $node_id ] );
            return;
        }

        $entries = [];
        $handle = opendir( $path );
        if ( false === $handle ) {
            $state['nodes'][ $node_id ]['error'] = __( 'Directory cannot be opened.', 'disk-usage-sunburst' );
            $state['directories_scanned'] = (int) $state['directories_scanned'] + 1;
            return;
        }

        try {
            while ( false !== ( $entry = readdir( $handle ) ) ) {
                if ( '.' === $entry || '..' === $entry ) {
                    continue;
                }
                $entries[] = $entry;
            }
        } finally {
            closedir( $handle );
        }

        $state['current_dir'] = [
            'node_id' => $node_id,
            'entries' => $entries,
            'offset'  => 0,
        ];
    }

    /**
     * Process entries in the currently opened directory until the step budget is reached.
     */
    private function process_current_directory_entries( array &$state, float $step_started, int $files_before, int $dirs_before ): void {
        if ( empty( $state['current_dir'] ) ) {
            return;
        }

        $current = &$state['current_dir'];
        $node_id = (string) $current['node_id'];
        $entries = $current['entries'];
        $offset = (int) $current['offset'];
        $total_entries = count( $entries );

        if ( empty( $state['nodes'][ $node_id ] ) ) {
            $state['current_dir'] = null;
            return;
        }

        while ( $offset < $total_entries ) {
            if ( $this->is_step_budget_exhausted( $step_started, $files_before, $dirs_before, $state ) ) {
                $current['offset'] = $offset;
                return;
            }

            $entry = (string) $entries[ $offset ];
            $offset++;
            $this->process_entry( $state, $node_id, $entry );
        }

        $state['directories_scanned'] = (int) $state['directories_scanned'] + 1;
        $state['current_dir'] = null;
    }

    /**
     * Process one filesystem entry.
     */
    private function process_entry( array &$state, string $parent_id, string $entry ): void {
        if ( empty( $state['nodes'][ $parent_id ] ) ) {
            return;
        }

        $parent_path = (string) $state['nodes'][ $parent_id ]['path'];
        $path = $parent_path . DIRECTORY_SEPARATOR . $entry;

        if ( is_link( $path ) ) {
            $state['symlinks_skipped'] = (int) $state['symlinks_skipped'] + 1;
            return;
        }

        if ( ! $this->is_valid_path( $path ) ) {
            $state['invalid_skipped'] = (int) $state['invalid_skipped'] + 1;
            return;
        }

        if ( is_dir( $path ) ) {
            $parent_depth = (int) $state['nodes'][ $parent_id ]['depth'];
            if ( $parent_depth + 1 > (int) $this->config['max_depth'] ) {
                $state['warnings'][] = sprintf(
                    /* translators: %s: directory path */
                    __( 'Maximum directory depth reached. Skipped: %s', 'disk-usage-sunburst' ),
                    $this->relative_path( $path, (string) $state['base_path'] )
                );
                return;
            }

            if ( $this->should_exclude_path( $path ) ) {
                $state['excluded_skipped'] = (int) $state['excluded_skipped'] + 1;
                return;
            }

            $child_id = (string) $state['next_id'];
            $state['next_id'] = (int) $state['next_id'] + 1;
            $relative_path = $this->relative_path( $path, (string) $state['base_path'] );
            $state['nodes'][ $child_id ] = $this->create_directory_node( $child_id, $parent_id, $path, $relative_path, $parent_depth + 1 );
            $state['nodes'][ $parent_id ]['children'][] = $child_id;
            $state['nodes'][ $parent_id ]['dirs_count'] = (int) $state['nodes'][ $parent_id ]['dirs_count'] + 1;
            $state['directories_found'] = (int) $state['directories_found'] + 1;
            $state['queue'][] = $child_id;
            return;
        }

        if ( is_file( $path ) ) {
            $size = $this->get_safe_filesize( $path );
            $relative_path = $this->relative_path( $path, (string) $state['base_path'] );
            $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

            $state['nodes'][ $parent_id ]['direct_file_size'] = (int) $state['nodes'][ $parent_id ]['direct_file_size'] + $size;
            $state['nodes'][ $parent_id ]['direct_file_count'] = (int) $state['nodes'][ $parent_id ]['direct_file_count'] + 1;
            $state['files_scanned'] = (int) $state['files_scanned'] + 1;
            $state['total_size'] = (int) $state['total_size'] + $size;

            $this->add_file_type( $state, $extension, $size );
            $this->add_top_file( $state, [
                'name'          => basename( $path ),
                'path'          => $path,
                'relative_path' => $relative_path,
                'size'          => $size,
                'human_size'    => $this->format_bytes( $size ),
                'extension'     => $extension,
                'mime_type'     => '',
                'type'          => 'file',
            ] );
            $this->add_wordpress_stats( $state, $relative_path, $size );
        }
    }

    /**
     * Finalize job, aggregate sizes, build compact result and cache it.
     */
    private function finalize_job( string $job_id, array &$state ): array {
        $this->aggregate_node( $state, '1' );

        $result = $this->build_result( $state );
        $state['status'] = 'completed';
        $state['completed_at'] = current_time( 'mysql' );
        $state['updated_at'] = $state['completed_at'];

        $this->save_result( $job_id, $result );
        $this->save_state( $job_id, $state );

        if ( ! empty( $state['cache_key'] ) ) {
            set_transient( (string) $state['cache_key'], $result, (int) $this->config['cache_duration'] );
        }

        return $result;
    }

    /**
     * Aggregate node sizes bottom-up.
     */
    private function aggregate_node( array &$state, string $node_id ): int {
        if ( empty( $state['nodes'][ $node_id ] ) ) {
            return 0;
        }

        $node = &$state['nodes'][ $node_id ];
        $size = (int) $node['direct_file_size'];
        $file_count = (int) $node['direct_file_count'];

        foreach ( (array) $node['children'] as $child_id ) {
            if ( empty( $state['nodes'][ (string) $child_id ] ) ) {
                continue;
            }
            $size += $this->aggregate_node( $state, (string) $child_id );
            $file_count += (int) $state['nodes'][ (string) $child_id ]['file_count'];
        }

        $node['size'] = $size;
        $node['file_count'] = $file_count;

        return $size;
    }

    /**
     * Build final scan data.
     */
    private function build_result( array $state ): array {
        $root = $this->build_compact_node( $state, '1' );
        $total_size = max( 1, (int) ( $root['size'] ?? 0 ) );
        $analysis = $this->build_analysis( $state, $total_size );

        $root['metadata'] = [
            'complete'              => true,
            'scanner'               => 'chunked',
            'scan_time'             => max( 0, microtime( true ) - (float) $state['started_at_micro'] ),
            'files_count'           => (int) $state['files_scanned'],
            'directories_count'     => count( $state['nodes'] ),
            'directories_scanned'   => (int) $state['directories_scanned'],
            'total_size'            => (int) $root['size'],
            'memory_peak'           => memory_get_peak_usage( true ),
            'scanned_at'            => current_time( 'mysql' ),
            'jobs_steps'            => (int) $state['step_count'],
            'symlinks_skipped'      => (int) $state['symlinks_skipped'],
            'excluded_skipped'      => (int) $state['excluded_skipped'],
            'invalid_skipped'       => (int) $state['invalid_skipped'],
            'max_children_per_node' => (int) $this->config['max_children_per_node'],
            'errors'                => array_slice( (array) $state['errors'], -25 ),
            'warnings'              => array_slice( array_unique( (array) $state['warnings'] ), -25 ),
            'analysis'              => $analysis,
        ];

        return $root;
    }

    /**
     * Build a compact visual tree with exact aggregate sizes.
     */
    private function build_compact_node( array $state, string $node_id ): array {
        $node = $state['nodes'][ $node_id ];

        $result = [
            'name'          => (string) $node['name'],
            'path'          => (string) $node['path'],
            'relative_path' => (string) $node['relative_path'],
            'size'          => (int) $node['size'],
            'human_size'    => $this->format_bytes( (int) $node['size'] ),
            'type'          => 'directory',
            'files_count'   => (int) $node['file_count'],
        ];

        $children = [];
        foreach ( (array) $node['children'] as $child_id ) {
            if ( ! empty( $state['nodes'][ (string) $child_id ] ) ) {
                $children[] = $state['nodes'][ (string) $child_id ];
            }
        }

        usort( $children, static function( $a, $b ) {
            return (int) $b['size'] <=> (int) $a['size'];
        } );

        $visual_children = [];

        if ( (int) $node['direct_file_size'] > 0 ) {
            $visual_children[] = [
                'name'          => __( 'Files in this folder', 'disk-usage-sunburst' ),
                'path'          => (string) $node['path'],
                'relative_path' => (string) $node['relative_path'],
                'size'          => (int) $node['direct_file_size'],
                'human_size'    => $this->format_bytes( (int) $node['direct_file_size'] ),
                'type'          => 'files_aggregate',
                'files_count'   => (int) $node['direct_file_count'],
            ];
        }

        $max_children = (int) $this->config['max_children_per_node'];
        $shown = array_slice( $children, 0, $max_children );
        $hidden = array_slice( $children, $max_children );

        foreach ( $shown as $child ) {
            $visual_children[] = $this->build_compact_node( $state, (string) $child['id'] );
        }

        if ( ! empty( $hidden ) ) {
            $other_size = 0;
            $other_file_count = 0;
            foreach ( $hidden as $child ) {
                $other_size += (int) $child['size'];
                $other_file_count += (int) $child['file_count'];
            }

            $visual_children[] = [
                'name'          => sprintf(
                    /* translators: %d: number of grouped items */
                    __( 'Other (%d items)', 'disk-usage-sunburst' ),
                    count( $hidden )
                ),
                'path'          => (string) $node['path'],
                'relative_path' => (string) $node['relative_path'],
                'size'          => $other_size,
                'human_size'    => $this->format_bytes( $other_size ),
                'type'          => 'grouped_directories',
                'files_count'   => $other_file_count,
            ];
        }

        if ( ! empty( $visual_children ) ) {
            $result['children'] = $visual_children;
        }

        return $result;
    }

    /**
     * Build analysis data from aggregated job state.
     */
    private function build_analysis( array $state, int $total_size ): array {
        $top_files = $this->prepare_top_files( (array) $state['top_files'], $total_size );
        $folders = $this->prepare_folders( $state, $total_size );
        $file_types = $this->prepare_file_types( (array) $state['file_types'], $total_size );
        $wp_breakdown = $this->prepare_wp_breakdown( (array) $state['wp_breakdown'], $total_size );
        $plugins = $this->prepare_named_breakdown( (array) $state['plugins'], $total_size, 'plugin' );
        $themes = $this->prepare_named_breakdown( (array) $state['themes'], $total_size, 'theme' );
        $uploads = $this->prepare_uploads( (array) $state['uploads'], $total_size );

        return [
            'largest_files'          => $top_files,
            'largest_folders'        => array_slice( $folders, 0, (int) $this->config['max_top_folders'] ),
            'folders_most_files'     => array_slice( $this->sort_by_key_desc( $folders, 'files_count' ), 0, (int) $this->config['max_top_folders'] ),
            'file_types'             => $file_types,
            'wordpress_breakdown'    => $wp_breakdown,
            'plugins_analysis'       => $plugins,
            'themes_analysis'        => $themes,
            'largest_plugin_folders' => $plugins,
            'largest_theme_folders'  => $themes,
            'uploads_summary'        => $uploads,
            'summary'                => [
                'total_size'              => (int) $state['total_size'],
                'total_size_human'        => $this->format_bytes( (int) $state['total_size'] ),
                'files_count'             => (int) $state['files_scanned'],
                'directories_count'       => count( $state['nodes'] ),
                'average_file_size'       => (int) $state['files_scanned'] > 0 ? (int) $state['total_size'] / (int) $state['files_scanned'] : 0,
                'average_file_size_human' => (int) $state['files_scanned'] > 0 ? $this->format_bytes( (int) $state['total_size'] / (int) $state['files_scanned'] ) : '0 B',
            ],
        ];
    }

    /**
     * Prepare top files.
     */
    private function prepare_top_files( array $files, int $total_size ): array {
        usort( $files, static function( $a, $b ) {
            return (int) $b['size'] <=> (int) $a['size'];
        } );

        $files = array_slice( $files, 0, (int) $this->config['max_top_files'] );

        foreach ( $files as &$file ) {
            $file['percentage'] = round( ( (int) $file['size'] / $total_size ) * 100, 2 );
            $file['human_size'] = $this->format_bytes( (int) $file['size'] );
            $file['file_type_category'] = $this->get_file_category( (string) ( $file['extension'] ?? '' ) );
            $file['directory_depth'] = substr_count( (string) ( $file['relative_path'] ?? '' ), '/' );
        }
        unset( $file );

        return $files;
    }

    /**
     * Prepare folder analysis.
     */
    private function prepare_folders( array $state, int $total_size ): array {
        $folders = [];
        foreach ( (array) $state['nodes'] as $node ) {
            if ( empty( $node['parent'] ) ) {
                continue;
            }
            $files_count = (int) $node['file_count'];
            $size = (int) $node['size'];
            $folders[] = [
                'name'                    => (string) $node['name'],
                'path'                    => (string) $node['path'],
                'relative_path'           => (string) $node['relative_path'],
                'size'                    => $size,
                'human_size'              => $this->format_bytes( $size ),
                'percentage'              => round( ( $size / $total_size ) * 100, 2 ),
                'files_count'             => $files_count,
                'subdirs_count'           => count( (array) $node['children'] ),
                'directory_depth'         => (int) $node['depth'],
                'average_file_size'       => $files_count > 0 ? $size / $files_count : 0,
                'average_file_size_human' => $files_count > 0 ? $this->format_bytes( $size / $files_count ) : '0 B',
            ];
        }

        return $this->sort_by_key_desc( $folders, 'size' );
    }

    /**
     * Prepare file types.
     */
    private function prepare_file_types( array $types, int $total_size ): array {
        $items = [];
        foreach ( $types as $extension => $data ) {
            $size = (int) ( $data['size'] ?? 0 );
            $items[] = [
                'extension'   => '' === (string) $extension ? __( 'No extension', 'disk-usage-sunburst' ) : (string) $extension,
                'size'        => $size,
                'human_size'  => $this->format_bytes( $size ),
                'percentage'  => round( ( $size / $total_size ) * 100, 2 ),
                'files_count' => (int) ( $data['files_count'] ?? 0 ),
            ];
        }

        return array_slice( $this->sort_by_key_desc( $items, 'size' ), 0, 50 );
    }

    /**
     * Prepare WordPress breakdown.
     */
    private function prepare_wp_breakdown( array $breakdown, int $total_size ): array {
        $items = [];
        foreach ( $breakdown as $path => $size ) {
            $size = (int) $size;
            if ( $size <= 0 ) {
                continue;
            }
            $items[] = [
                'category'   => $this->get_category_name( (string) $path ),
                'path'       => (string) $path,
                'size'       => $size,
                'human_size' => $this->format_bytes( $size ),
                'percentage' => round( ( $size / $total_size ) * 100, 2 ),
            ];
        }

        return $this->sort_by_key_desc( $items, 'size' );
    }

    /**
     * Prepare named plugin/theme breakdown.
     */
    private function prepare_named_breakdown( array $data, int $total_size, string $type ): array {
        $items = [];
        foreach ( $data as $name => $stats ) {
            $size = (int) ( $stats['size'] ?? 0 );
            $files_count = (int) ( $stats['files_count'] ?? 0 );
            $items[] = [
                'name'                    => (string) $name,
                'path'                    => (string) ( $stats['path'] ?? '' ),
                'size'                    => $size,
                'human_size'              => $this->format_bytes( $size ),
                'percentage'              => round( ( $size / $total_size ) * 100, 2 ),
                'files_count'             => $files_count,
                'average_file_size'       => $files_count > 0 ? $size / $files_count : 0,
                'average_file_size_human' => $files_count > 0 ? $this->format_bytes( $size / $files_count ) : '0 B',
                'type'                    => $type,
            ];
        }

        return $this->sort_by_key_desc( $items, 'size' );
    }

    /**
     * Prepare uploads summary.
     */
    private function prepare_uploads( array $data, int $total_size ): array {
        $items = [];
        foreach ( $data as $period => $stats ) {
            $size = (int) ( $stats['size'] ?? 0 );
            $files_count = (int) ( $stats['files_count'] ?? 0 );
            $items[] = [
                'period'                  => (string) $period,
                'size'                    => $size,
                'human_size'              => $this->format_bytes( $size ),
                'percentage'              => round( ( $size / $total_size ) * 100, 2 ),
                'files_count'             => $files_count,
                'average_file_size'       => $files_count > 0 ? $size / $files_count : 0,
                'average_file_size_human' => $files_count > 0 ? $this->format_bytes( $size / $files_count ) : '0 B',
            ];
        }

        usort( $items, static function( $a, $b ) {
            return strcmp( (string) $b['period'], (string) $a['period'] );
        } );

        return $items;
    }

    /**
     * Sort array of arrays descending by a key.
     */
    private function sort_by_key_desc( array $items, string $key ): array {
        usort( $items, static function( $a, $b ) use ( $key ) {
            return (int) ( $b[ $key ] ?? 0 ) <=> (int) ( $a[ $key ] ?? 0 );
        } );

        return $items;
    }

    /**
     * Add file type stats.
     */
    private function add_file_type( array &$state, string $extension, int $size ): void {
        if ( ! isset( $state['file_types'][ $extension ] ) ) {
            $state['file_types'][ $extension ] = [ 'size' => 0, 'files_count' => 0 ];
        }
        $state['file_types'][ $extension ]['size'] = (int) $state['file_types'][ $extension ]['size'] + $size;
        $state['file_types'][ $extension ]['files_count'] = (int) $state['file_types'][ $extension ]['files_count'] + 1;
    }

    /**
     * Keep only the largest files in memory.
     */
    private function add_top_file( array &$state, array $file ): void {
        $state['top_files'][] = $file;
        $limit = (int) $this->config['max_top_files'];
        if ( count( $state['top_files'] ) > $limit * 2 ) {
            usort( $state['top_files'], static function( $a, $b ) {
                return (int) $b['size'] <=> (int) $a['size'];
            } );
            $state['top_files'] = array_slice( $state['top_files'], 0, $limit );
        }
    }

    /**
     * Add WordPress-specific aggregate stats.
     */
    private function add_wordpress_stats( array &$state, string $relative_path, int $size ): void {
        $relative_path = trim( str_replace( '\\', '/', $relative_path ), '/' );
        $category = $this->get_wp_category_key( $relative_path );

        if ( ! isset( $state['wp_breakdown'][ $category ] ) ) {
            $state['wp_breakdown'][ $category ] = 0;
        }
        $state['wp_breakdown'][ $category ] = (int) $state['wp_breakdown'][ $category ] + $size;

        if ( 0 === strpos( $relative_path, 'wp-content/plugins/' ) ) {
            $parts = explode( '/', $relative_path );
            $plugin = $parts[2] ?? '';
            if ( '' !== $plugin ) {
                $this->add_named_stat( $state['plugins'], $plugin, 'wp-content/plugins/' . $plugin, $size );
            }
        }

        if ( 0 === strpos( $relative_path, 'wp-content/themes/' ) ) {
            $parts = explode( '/', $relative_path );
            $theme = $parts[2] ?? '';
            if ( '' !== $theme ) {
                $this->add_named_stat( $state['themes'], $theme, 'wp-content/themes/' . $theme, $size );
            }
        }

        if ( 0 === strpos( $relative_path, 'wp-content/uploads/' ) ) {
            $parts = explode( '/', $relative_path );
            $period = __( 'Other uploads', 'disk-usage-sunburst' );
            if ( isset( $parts[2] ) && preg_match( '/^\d{4}$/', $parts[2] ) ) {
                $period = $parts[2];
                if ( isset( $parts[3] ) && preg_match( '/^\d{2}$/', $parts[3] ) ) {
                    $period .= '/' . $parts[3];
                }
            }
            $this->add_upload_stat( $state['uploads'], $period, $size );
        }
    }

    /**
     * Add named aggregate stat.
     */
    private function add_named_stat( array &$target, string $name, string $path, int $size ): void {
        if ( ! isset( $target[ $name ] ) ) {
            $target[ $name ] = [
                'path'        => $path,
                'size'        => 0,
                'files_count' => 0,
            ];
        }
        $target[ $name ]['size'] = (int) $target[ $name ]['size'] + $size;
        $target[ $name ]['files_count'] = (int) $target[ $name ]['files_count'] + 1;
    }

    /**
     * Add upload aggregate stat.
     */
    private function add_upload_stat( array &$target, string $period, int $size ): void {
        if ( ! isset( $target[ $period ] ) ) {
            $target[ $period ] = [
                'size'        => 0,
                'files_count' => 0,
            ];
        }
        $target[ $period ]['size'] = (int) $target[ $period ]['size'] + $size;
        $target[ $period ]['files_count'] = (int) $target[ $period ]['files_count'] + 1;
    }

    /**
     * Get WordPress category key for a relative path.
     */
    private function get_wp_category_key( string $relative_path ): string {
        if ( 0 === strpos( $relative_path, 'wp-content/plugins/' ) ) {
            return 'wp-content/plugins';
        }
        if ( 0 === strpos( $relative_path, 'wp-content/themes/' ) ) {
            return 'wp-content/themes';
        }
        if ( 0 === strpos( $relative_path, 'wp-content/uploads/' ) ) {
            return 'wp-content/uploads';
        }
        if ( 0 === strpos( $relative_path, 'wp-content/cache/' ) ) {
            return 'wp-content/cache';
        }
        if ( 0 === strpos( $relative_path, 'wp-content/backup' ) || 0 === strpos( $relative_path, 'wp-content/backups' ) ) {
            return 'wp-content/backup';
        }
        if ( 0 === strpos( $relative_path, 'wp-admin/' ) ) {
            return 'wp-admin';
        }
        if ( 0 === strpos( $relative_path, 'wp-includes/' ) ) {
            return 'wp-includes';
        }
        if ( false === strpos( $relative_path, '/' ) ) {
            return 'core';
        }

        return 'other';
    }

    /**
     * Get display category name.
     */
    private function get_category_name( string $category ): string {
        $names = [
            'wp-content/plugins' => __( 'Plugins', 'disk-usage-sunburst' ),
            'wp-content/themes'  => __( 'Themes', 'disk-usage-sunburst' ),
            'wp-content/uploads' => __( 'Uploads', 'disk-usage-sunburst' ),
            'wp-admin'           => __( 'WordPress Admin', 'disk-usage-sunburst' ),
            'wp-includes'        => __( 'WordPress Includes', 'disk-usage-sunburst' ),
            'wp-content/cache'   => __( 'Cache', 'disk-usage-sunburst' ),
            'wp-content/backup'  => __( 'Backups', 'disk-usage-sunburst' ),
            'core'               => __( 'WordPress Root Files', 'disk-usage-sunburst' ),
            'other'              => __( 'Other', 'disk-usage-sunburst' ),
        ];

        return $names[ $category ] ?? $category;
    }

    /**
     * Get file category.
     */
    private function get_file_category( string $extension ): string {
        $extension = strtolower( $extension );
        $categories = [
            'Images'      => [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp' ],
            'Videos'      => [ 'mp4', 'mov', 'avi', 'webm', 'mkv', 'm4v' ],
            'Audio'       => [ 'mp3', 'wav', 'ogg', 'm4a', 'flac' ],
            'Documents'   => [ 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf' ],
            'Archives'    => [ 'zip', 'tar', 'gz', 'rar', '7z', 'bz2' ],
            'Code'        => [ 'php', 'js', 'css', 'html', 'htm', 'json', 'xml', 'scss', 'less' ],
            'Databases'   => [ 'sql', 'sqlite', 'db' ],
        ];

        foreach ( $categories as $category => $extensions ) {
            if ( in_array( $extension, $extensions, true ) ) {
                return $category;
            }
        }

        return __( 'Other', 'disk-usage-sunburst' );
    }

    /**
     * Check whether the current step should yield back to the browser.
     */
    private function is_step_budget_exhausted( float $step_started, int $files_before, int $dirs_before, array $state ): bool {
        $elapsed = microtime( true ) - $step_started;
        if ( $elapsed >= (float) $this->config['time_per_step'] ) {
            return true;
        }

        if ( (int) $state['files_scanned'] - $files_before >= (int) $this->config['files_per_step'] ) {
            return true;
        }

        if ( (int) $state['directories_scanned'] - $dirs_before >= (int) $this->config['dirs_per_step'] ) {
            return true;
        }

        return false;
    }

    /**
     * Build progress data.
     */
    private function get_progress_data( array $state, string $message = '', ?float $forced_percent = null ): array {
        $found = max( 1, (int) ( $state['directories_found'] ?? count( (array) ( $state['nodes'] ?? [] ) ) ) );
        $scanned = (int) ( $state['directories_scanned'] ?? 0 );
        $percent = null !== $forced_percent ? $forced_percent : min( 99.0, round( ( $scanned / $found ) * 100, 1 ) );

        $current_entries = 0;
        $current_offset = 0;
        if ( ! empty( $state['current_dir']['entries'] ) ) {
            $current_entries = count( (array) $state['current_dir']['entries'] );
            $current_offset = (int) $state['current_dir']['offset'];
        }

        return [
            'percent'              => $percent,
            'message'              => $message,
            'files_scanned'        => (int) ( $state['files_scanned'] ?? 0 ),
            'directories_scanned'  => $scanned,
            'directories_found'    => $found,
            'queue_remaining'      => count( (array) ( $state['queue'] ?? [] ) ),
            'current_dir_offset'   => $current_offset,
            'current_dir_entries'  => $current_entries,
            'total_size'           => (int) ( $state['total_size'] ?? 0 ),
            'total_size_human'     => $this->format_bytes( (int) ( $state['total_size'] ?? 0 ) ),
            'step_count'           => (int) ( $state['step_count'] ?? 0 ),
            'symlinks_skipped'     => (int) ( $state['symlinks_skipped'] ?? 0 ),
            'excluded_skipped'     => (int) ( $state['excluded_skipped'] ?? 0 ),
            'invalid_skipped'      => (int) ( $state['invalid_skipped'] ?? 0 ),
        ];
    }

    /**
     * Validate a path.
     */
    private function is_valid_path( string $path ): bool {
        if ( false !== strpos( $path, '..' ) ) {
            return false;
        }

        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            return false;
        }

        $real_path = realpath( $path );
        $wp_path = realpath( ABSPATH );

        if ( ! $real_path || ! $wp_path ) {
            return false;
        }

        $real_path = rtrim( $real_path, DIRECTORY_SEPARATOR );
        $wp_path = rtrim( $wp_path, DIRECTORY_SEPARATOR );

        return $real_path === $wp_path || 0 === strpos( $real_path . DIRECTORY_SEPARATOR, $wp_path . DIRECTORY_SEPARATOR );
    }

    /**
     * Check excludes.
     */
    private function should_exclude_path( string $path ): bool {
        $basename = basename( $path );
        $excludes = apply_filters( 'rbdusb_chunked_exclude_paths', self::DEFAULT_EXCLUDES, $path );

        return in_array( $basename, $excludes, true );
    }

    /**
     * Make path relative to base.
     */
    private function relative_path( string $path, string $base_path ): string {
        $path = str_replace( '\\', '/', $path );
        $base_path = rtrim( str_replace( '\\', '/', $base_path ), '/' );

        if ( 0 === strpos( $path, $base_path ) ) {
            return ltrim( substr( $path, strlen( $base_path ) ), '/' );
        }

        return basename( $path );
    }

    /**
     * Get file size safely.
     */
    private function get_safe_filesize( string $path ): int {
        $size = @filesize( $path );
        return false !== $size ? (int) $size : 0;
    }

    /**
     * Format bytes.
     */
    public function format_bytes( $bytes, int $precision = 2 ): string {
        $bytes = (float) $bytes;
        $units = [ 'B', 'KB', 'MB', 'GB', 'TB', 'PB' ];

        for ( $i = 0; $bytes >= 1024 && $i < count( $units ) - 1; $i++ ) {
            $bytes /= 1024;
        }

        return round( $bytes, $precision ) . ' ' . $units[ $i ];
    }

    /**
     * Get cache key.
     */
    private function get_cache_key( string $path ): string {
        return 'rbdusb_chunked_scan_' . md5( $path . '|' . ( defined( 'RBDUSB_VERSION' ) ? RBDUSB_VERSION : '0' ) . '|' . wp_json_encode( $this->config ) );
    }

    /**
     * Generate safe job ID.
     */
    private function generate_job_id(): string {
        if ( function_exists( 'wp_generate_uuid4' ) ) {
            return sanitize_key( wp_generate_uuid4() );
        }

        return sanitize_key( uniqid( 'rbdusb_', true ) );
    }

    /**
     * Get jobs base dir.
     */
    private function get_jobs_base_dir(): string {
        $upload_dir = wp_upload_dir();
        $base_dir = ! empty( $upload_dir['basedir'] ) ? $upload_dir['basedir'] : WP_CONTENT_DIR . '/uploads';

        return trailingslashit( $base_dir ) . 'disk-usage-sunburst/jobs';
    }

    /**
     * Get job dir.
     */
    private function get_job_dir( string $job_id ): string {
        return trailingslashit( $this->get_jobs_base_dir() ) . sanitize_key( $job_id );
    }

    /**
     * Load state.
     */
    private function load_state( string $job_id ): ?array {
        return $this->read_json_file( $this->get_job_dir( $job_id ) . '/state.json' );
    }

    /**
     * Save state.
     */
    private function save_state( string $job_id, array $state ): void {
        $this->write_json_file( $this->get_job_dir( $job_id ) . '/state.json', $state );
    }

    /**
     * Load result.
     */
    private function load_result( string $job_id ): ?array {
        return $this->read_json_file( $this->get_job_dir( $job_id ) . '/result.json' );
    }

    /**
     * Save result.
     */
    private function save_result( string $job_id, array $result ): void {
        $this->write_json_file( $this->get_job_dir( $job_id ) . '/result.json', $result );
    }

    /**
     * Read JSON file.
     */
    private function read_json_file( string $file ): ?array {
        if ( ! is_readable( $file ) ) {
            return null;
        }

        $json = file_get_contents( $file );
        if ( false === $json || '' === $json ) {
            return null;
        }

        $data = json_decode( $json, true );

        return is_array( $data ) ? $data : null;
    }

    /**
     * Write JSON file atomically.
     */
    private function write_json_file( string $file, array $data ): void {
        $dir = dirname( $file );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        $tmp = $file . '.tmp';
        file_put_contents( $tmp, wp_json_encode( $data ) );
        rename( $tmp, $file );
    }

    /**
     * Limit error message length.
     */
    private function limit_error_message( string $message ): string {
        return substr( sanitize_text_field( $message ), 0, 300 );
    }

    /**
     * Cleanup old job directories.
     */
    private function cleanup_old_jobs(): void {
        $base = $this->get_jobs_base_dir();
        if ( ! is_dir( $base ) ) {
            return;
        }

        $max_age = DAY_IN_SECONDS;
        $now = time();
        $handle = opendir( $base );
        if ( false === $handle ) {
            return;
        }

        try {
            while ( false !== ( $entry = readdir( $handle ) ) ) {
                if ( '.' === $entry || '..' === $entry ) {
                    continue;
                }
                $dir = trailingslashit( $base ) . $entry;
                if ( ! is_dir( $dir ) ) {
                    continue;
                }
                if ( $now - (int) filemtime( $dir ) > $max_age ) {
                    $this->delete_directory( $dir );
                }
            }
        } finally {
            closedir( $handle );
        }
    }

    /**
     * Delete directory recursively.
     */
    private function delete_directory( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $items = scandir( $dir );
        if ( false === $items ) {
            return;
        }

        foreach ( $items as $item ) {
            if ( '.' === $item || '..' === $item ) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if ( is_dir( $path ) && ! is_link( $path ) ) {
                $this->delete_directory( $path );
            } else {
                @unlink( $path );
            }
        }

        @rmdir( $dir );
    }
}
