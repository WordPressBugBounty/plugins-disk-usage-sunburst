<?php
/**
 * Snapshot Manager Class
 *
 * @package RaidBoxes\DiskUsageSunburst
 */

namespace RaidBoxes\DiskUsageSunburst\Snapshot;

/**
 * Handles snapshot creation, storage, and management
 */
class SnapshotManager {

    /**
     * Maximum number of snapshots to keep
     */
    private const MAX_SNAPSHOTS = 20;

    /**
     * Legacy option name for snapshots from older versions.
     */
    private const LEGACY_SNAPSHOTS_OPTION = 'rbdusb_snapshots';

    /**
     * Relative directory for file-based snapshot storage.
     */
    private const SNAPSHOT_DIR = 'disk-usage-sunburst/snapshots';

    /**
     * Save a new snapshot
     *
     * @param array $scan_data Scan data to save.
     * @return string|\WP_Error Snapshot ID or error.
     */
    public function save_snapshot( array $scan_data ) {
        try {
            if ( ! $this->ensure_storage_directory() ) {
                return new \WP_Error( 'storage_unavailable', __( 'Snapshot storage directory is not writable.', 'disk-usage-sunburst' ) );
            }

            $this->migrate_legacy_snapshots();

            $snapshot_id = $this->generate_snapshot_id();

            // Prepare snapshot data.
            $snapshot = [
                'id' => $snapshot_id,
                'created_at' => current_time( 'mysql' ),
                'created_timestamp' => time(),
                'size' => $this->calculate_snapshot_size( $scan_data ),
                'metadata' => $scan_data['metadata'] ?? [],
                'path' => $scan_data['path'] ?? ABSPATH,
                'data' => $scan_data,
            ];

            if ( ! $this->write_snapshot_file( $snapshot_id, $snapshot ) ) {
                return new \WP_Error( 'save_failed', __( 'Failed to save snapshot.', 'disk-usage-sunburst' ) );
            }

            $this->cleanup_old_snapshots();

            do_action( 'rbdusb_snapshot_saved', $snapshot_id, $snapshot );

            return $snapshot_id;

        } catch ( \Exception $e ) {
            return new \WP_Error( 'snapshot_error', $e->getMessage() );
        }
    }

    /**
     * Get all snapshots
     *
     * @return array Array of snapshots.
     */
    public function get_snapshots(): array {
        $this->migrate_legacy_snapshots();

        $snapshots = [];
        foreach ( $this->get_snapshot_files() as $file ) {
            $snapshot = $this->read_snapshot_file( $file );
            if ( is_array( $snapshot ) && ! empty( $snapshot['id'] ) ) {
                $snapshots[ $snapshot['id'] ] = $snapshot;
            }
        }

        uasort( $snapshots, function( $a, $b ) {
            return (int) ( $b['created_timestamp'] ?? 0 ) - (int) ( $a['created_timestamp'] ?? 0 );
        } );

        return $snapshots;
    }

    /**
     * Get a specific snapshot
     *
     * @param string $snapshot_id Snapshot ID.
     * @return array|null Snapshot data or null if not found.
     */
    public function get_snapshot( string $snapshot_id ) {
        if ( ! $this->is_valid_snapshot_id( $snapshot_id ) ) {
            return null;
        }

        $file = $this->get_snapshot_file_path( $snapshot_id );
        if ( ! is_readable( $file ) ) {
            return null;
        }

        $snapshot = $this->read_snapshot_file( $file );
        return is_array( $snapshot ) ? $snapshot : null;
    }

    /**
     * Delete a snapshot
     *
     * @param string $snapshot_id Snapshot ID.
     * @return bool Success status.
     */
    public function delete_snapshot( string $snapshot_id ): bool {
        if ( ! $this->is_valid_snapshot_id( $snapshot_id ) ) {
            return false;
        }

        $file = $this->get_snapshot_file_path( $snapshot_id );
        if ( ! is_file( $file ) ) {
            return false;
        }

        $deleted = @unlink( $file );

        if ( $deleted ) {
            do_action( 'rbdusb_snapshot_deleted', $snapshot_id );
        }

        return $deleted;
    }

    /**
     * Get snapshots list for display
     *
     * @return array Formatted snapshots list.
     */
    public function get_snapshots_list(): array {
        $snapshots = $this->get_snapshots();
        $formatted = [];

        foreach ( $snapshots as $id => $snapshot ) {
            $formatted[] = [
                'id' => $id,
                'filename' => $this->generate_filename( $snapshot ),
                'size' => (int) ( $snapshot['size'] ?? 0 ),
                'human_size' => $this->format_bytes( (int) ( $snapshot['size'] ?? 0 ) ),
                'created_at' => $snapshot['created_at'] ?? '',
                'created_timestamp' => (int) ( $snapshot['created_timestamp'] ?? 0 ),
                'files_count' => (int) ( $snapshot['metadata']['files_count'] ?? 0 ),
                'directories_count' => (int) ( $snapshot['metadata']['directories_count'] ?? 0 ),
                'scan_time' => (float) ( $snapshot['metadata']['scan_time'] ?? 0 ),
            ];
        }

        // Sort by creation time (newest first).
        usort( $formatted, function( $a, $b ) {
            return $b['created_timestamp'] - $a['created_timestamp'];
        } );

        return $formatted;
    }

    /**
     * Generate unique snapshot ID
     *
     * @return string Snapshot ID.
     */
    private function generate_snapshot_id(): string {
        // Use cryptographically secure random ID generation.
        try {
            $random_bytes = random_bytes( 16 );
            $random_hex = bin2hex( $random_bytes );

            return gmdate( 'Ymd' ) . '_' . $random_hex;
        } catch ( \Exception $e ) {
            return gmdate( 'Ymd' ) . '_' . wp_generate_password( 32, false, false );
        }
    }

    /**
     * Generate display filename for snapshot
     *
     * @param array $snapshot Snapshot data.
     * @return string Display filename.
     */
    private function generate_filename( array $snapshot ): string {
        $timestamp = (int) ( $snapshot['created_timestamp'] ?? time() );
        $date = date( 'Y-m-d H:i:s', $timestamp );
        return sprintf( 'Snapshot %s', $date );
    }

    /**
     * Calculate total size from scan data
     *
     * @param array $scan_data Scan data.
     * @return int Total size in bytes.
     */
    private function calculate_snapshot_size( array $scan_data ): int {
        return (int) ( $scan_data['metadata']['total_size'] ?? $scan_data['size'] ?? 0 );
    }

    /**
     * Cleanup old snapshots to stay within limit.
     */
    private function cleanup_old_snapshots(): void {
        $snapshots = $this->get_snapshots();

        if ( count( $snapshots ) <= self::MAX_SNAPSHOTS ) {
            return;
        }

        uasort( $snapshots, function( $a, $b ) {
            return (int) ( $b['created_timestamp'] ?? 0 ) - (int) ( $a['created_timestamp'] ?? 0 );
        } );

        $old_snapshots = array_slice( $snapshots, self::MAX_SNAPSHOTS, null, true );
        foreach ( $old_snapshots as $snapshot_id => $snapshot ) {
            $this->delete_snapshot( (string) $snapshot_id );
        }
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
     * Get snapshot statistics
     *
     * @return array Snapshot statistics.
     */
    public function get_statistics(): array {
        $snapshots = $this->get_snapshots();

        return [
            'total_snapshots' => count( $snapshots ),
            'oldest_snapshot' => $this->get_oldest_snapshot_date( $snapshots ),
            'newest_snapshot' => $this->get_newest_snapshot_date( $snapshots ),
            'total_storage_used' => $this->calculate_total_storage(),
        ];
    }

    /**
     * Get oldest snapshot date
     *
     * @param array $snapshots Snapshots array.
     * @return string|null Oldest snapshot date.
     */
    private function get_oldest_snapshot_date( array $snapshots ) {
        if ( empty( $snapshots ) ) {
            return null;
        }

        $oldest = min( array_map( 'intval', array_column( $snapshots, 'created_timestamp' ) ) );
        return date( 'Y-m-d H:i:s', $oldest );
    }

    /**
     * Get newest snapshot date
     *
     * @param array $snapshots Snapshots array.
     * @return string|null Newest snapshot date.
     */
    private function get_newest_snapshot_date( array $snapshots ) {
        if ( empty( $snapshots ) ) {
            return null;
        }

        $newest = max( array_map( 'intval', array_column( $snapshots, 'created_timestamp' ) ) );
        return date( 'Y-m-d H:i:s', $newest );
    }

    /**
     * Calculate total storage used by snapshot files.
     *
     * @return int Total storage in bytes.
     */
    private function calculate_total_storage(): int {
        $total = 0;

        foreach ( $this->get_snapshot_files() as $file ) {
            $filesize = filesize( $file );
            if ( false !== $filesize ) {
                $total += (int) $filesize;
            }
        }

        return $total;
    }

    /**
     * Clear all snapshots
     *
     * @return bool Success status.
     */
    public function clear_all_snapshots(): bool {
        $success = true;

        foreach ( $this->get_snapshot_files() as $file ) {
            if ( is_file( $file ) && ! @unlink( $file ) ) {
                $success = false;
            }
        }

        delete_option( self::LEGACY_SNAPSHOTS_OPTION );

        if ( $success ) {
            do_action( 'rbdusb_all_snapshots_cleared' );
        }

        return $success;
    }

    /**
     * Get the directory where snapshots are stored.
     *
     * @return string Absolute directory path.
     */
    private function get_storage_dir(): string {
        $upload_dir = wp_upload_dir( null, false );

        if ( empty( $upload_dir['error'] ) && ! empty( $upload_dir['basedir'] ) ) {
            $base_dir = $upload_dir['basedir'];
        } else {
            $base_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : dirname( ABSPATH ) . DIRECTORY_SEPARATOR . 'wp-content';
        }

        return trailingslashit( $base_dir ) . self::SNAPSHOT_DIR;
    }

    /**
     * Create snapshot storage directory and basic web-access protection files.
     *
     * @return bool Whether the directory is writable.
     */
    private function ensure_storage_directory(): bool {
        $dir = $this->get_storage_dir();

        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return false;
        }

        if ( ! is_writable( $dir ) ) {
            return false;
        }

        $parent_dir = dirname( $dir );
        $protection_files = [ $parent_dir, $dir ];

        foreach ( $protection_files as $protected_dir ) {
            $index_file = trailingslashit( $protected_dir ) . 'index.php';
            if ( ! file_exists( $index_file ) ) {
                file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
            }

            $htaccess_file = trailingslashit( $protected_dir ) . '.htaccess';
            if ( ! file_exists( $htaccess_file ) ) {
                file_put_contents(
                    $htaccess_file,
                    "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
                );
            }
        }

        return true;
    }

    /**
     * Get all snapshot files.
     *
     * @return array Snapshot file paths.
     */
    private function get_snapshot_files(): array {
        if ( ! $this->ensure_storage_directory() ) {
            return [];
        }

        $files = glob( trailingslashit( $this->get_storage_dir() ) . '*.json' );
        return is_array( $files ) ? $files : [];
    }

    /**
     * Build an absolute file path for a snapshot ID.
     *
     * @param string $snapshot_id Snapshot ID.
     * @return string Snapshot file path.
     */
    private function get_snapshot_file_path( string $snapshot_id ): string {
        return trailingslashit( $this->get_storage_dir() ) . sanitize_file_name( $snapshot_id ) . '.json';
    }

    /**
     * Persist a snapshot to disk.
     *
     * @param string $snapshot_id Snapshot ID.
     * @param array  $snapshot Snapshot data.
     * @return bool Whether the file was written.
     */
    private function write_snapshot_file( string $snapshot_id, array $snapshot ): bool {
        if ( ! $this->is_valid_snapshot_id( $snapshot_id ) || ! $this->ensure_storage_directory() ) {
            return false;
        }

        $json = wp_json_encode( $snapshot, JSON_UNESCAPED_SLASHES );
        if ( false === $json ) {
            return false;
        }

        $file = $this->get_snapshot_file_path( $snapshot_id );
        $tmp_file = $file . '.tmp';

        if ( false === file_put_contents( $tmp_file, $json . "\n", LOCK_EX ) ) {
            return false;
        }

        if ( ! @rename( $tmp_file, $file ) ) {
            @unlink( $tmp_file );
            return false;
        }

        return true;
    }

    /**
     * Read and decode a snapshot file.
     *
     * @param string $file Snapshot file path.
     * @return array|null Snapshot data.
     */
    private function read_snapshot_file( string $file ) {
        if ( ! is_readable( $file ) ) {
            return null;
        }

        $contents = file_get_contents( $file );
        if ( false === $contents ) {
            return null;
        }

        $snapshot = json_decode( $contents, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $snapshot ) ) {
            return null;
        }

        if ( empty( $snapshot['id'] ) || ! $this->is_valid_snapshot_id( (string) $snapshot['id'] ) ) {
            return null;
        }

        return $snapshot;
    }

    /**
     * Migrate legacy option-based snapshots to file storage.
     */
    private function migrate_legacy_snapshots(): void {
        $legacy_snapshots = get_option( self::LEGACY_SNAPSHOTS_OPTION, null );

        if ( empty( $legacy_snapshots ) || ! is_array( $legacy_snapshots ) ) {
            return;
        }

        if ( ! $this->ensure_storage_directory() ) {
            return;
        }

        foreach ( $legacy_snapshots as $snapshot_id => $snapshot ) {
            if ( ! is_array( $snapshot ) ) {
                continue;
            }

            $snapshot_id = (string) ( $snapshot['id'] ?? $snapshot_id );
            if ( ! $this->is_valid_snapshot_id( $snapshot_id ) ) {
                continue;
            }

            $snapshot['id'] = $snapshot_id;
            $file = $this->get_snapshot_file_path( $snapshot_id );

            if ( ! is_file( $file ) ) {
                $this->write_snapshot_file( $snapshot_id, $snapshot );
            }
        }

        delete_option( self::LEGACY_SNAPSHOTS_OPTION );
        $this->cleanup_old_snapshots();
    }

    /**
     * Validate snapshot IDs before using them in file paths.
     *
     * @param string $snapshot_id Snapshot ID.
     * @return bool Whether the ID is safe.
     */
    private function is_valid_snapshot_id( string $snapshot_id ): bool {
        return 1 === preg_match( '/^\d{8}_[A-Za-z0-9]{32}$/', $snapshot_id );
    }
}
