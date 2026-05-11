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
     * Option name for storing snapshots
     */
    private const SNAPSHOTS_OPTION = 'rbdusb_snapshots';

    /**
     * Save a new snapshot
     *
     * @param array $scan_data Scan data to save.
     * @return string|\WP_Error Snapshot ID or error.
     */
    public function save_snapshot( array $scan_data ) {
        try {
            $snapshot_id = $this->generate_snapshot_id();
            
            // Prepare snapshot data
            $snapshot = [
                'id' => $snapshot_id,
                'created_at' => current_time( 'mysql' ),
                'created_timestamp' => time(),
                'size' => $this->calculate_snapshot_size( $scan_data ),
                'metadata' => $scan_data['metadata'] ?? [],
                'path' => $scan_data['path'] ?? ABSPATH,
                'data' => $scan_data,
            ];

            // Get existing snapshots
            $snapshots = $this->get_snapshots();
            
            // Add new snapshot
            $snapshots[$snapshot_id] = $snapshot;
            
            // Cleanup old snapshots
            $snapshots = $this->cleanup_old_snapshots( $snapshots );
            
            // Save snapshots
            $saved = update_option( self::SNAPSHOTS_OPTION, $snapshots );
            
            if ( ! $saved ) {
                return new \WP_Error( 'save_failed', __( 'Failed to save snapshot.', 'disk-usage-sunburst' ) );
            }

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
        return get_option( self::SNAPSHOTS_OPTION, [] );
    }

    /**
     * Get a specific snapshot
     *
     * @param string $snapshot_id Snapshot ID.
     * @return array|null Snapshot data or null if not found.
     */
    public function get_snapshot( string $snapshot_id ) {
        $snapshots = $this->get_snapshots();
        return $snapshots[$snapshot_id] ?? null;
    }

    /**
     * Delete a snapshot
     *
     * @param string $snapshot_id Snapshot ID.
     * @return bool Success status.
     */
    public function delete_snapshot( string $snapshot_id ): bool {
        $snapshots = $this->get_snapshots();
        
        if ( ! isset( $snapshots[$snapshot_id] ) ) {
            return false;
        }

        unset( $snapshots[$snapshot_id] );
        
        $result = update_option( self::SNAPSHOTS_OPTION, $snapshots );
        
        if ( $result ) {
            do_action( 'rbdusb_snapshot_deleted', $snapshot_id );
        }
        
        return $result;
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
                'size' => $snapshot['size'],
                'human_size' => $this->format_bytes( $snapshot['size'] ),
                'created_at' => $snapshot['created_at'],
                'created_timestamp' => $snapshot['created_timestamp'],
                'files_count' => $snapshot['metadata']['files_count'] ?? 0,
                'directories_count' => $snapshot['metadata']['directories_count'] ?? 0,
                'scan_time' => $snapshot['metadata']['scan_time'] ?? 0,
            ];
        }

        // Sort by creation time (newest first)
        usort( $formatted, function( $a, $b ) {
            return $b['created_timestamp'] - $a['created_timestamp'];
        });

        return $formatted;
    }

    /**
     * Generate unique snapshot ID
     *
     * @return string Snapshot ID.
     */
    private function generate_snapshot_id(): string {
        // Use cryptographically secure random ID generation
        try {
            // Generate 16 bytes of random data and encode as hex
            $random_bytes = random_bytes( 16 );
            $random_hex = bin2hex( $random_bytes );
            
            // Add timestamp prefix for organization (but random part ensures security)
            return date( 'Ymd' ) . '_' . $random_hex;
        } catch ( \Exception $e ) {
            // Fallback to WordPress's secure password generation if random_bytes fails
            return date( 'Ymd' ) . '_' . wp_generate_password( 32, false, false );
        }
    }

    /**
     * Generate display filename for snapshot
     *
     * @param array $snapshot Snapshot data.
     * @return string Display filename.
     */
    private function generate_filename( array $snapshot ): string {
        $date = date( 'Y-m-d H:i:s', $snapshot['created_timestamp'] );
        return sprintf( 'Snapshot %s', $date );
    }

    /**
     * Calculate total size from scan data
     *
     * @param array $scan_data Scan data.
     * @return int Total size in bytes.
     */
    private function calculate_snapshot_size( array $scan_data ): int {
        return $scan_data['metadata']['total_size'] ?? $scan_data['size'] ?? 0;
    }

    /**
     * Cleanup old snapshots to stay within limit
     *
     * @param array $snapshots Current snapshots.
     * @return array Cleaned snapshots.
     */
    private function cleanup_old_snapshots( array $snapshots ): array {
        if ( count( $snapshots ) <= self::MAX_SNAPSHOTS ) {
            return $snapshots;
        }

        // Sort by creation time
        uasort( $snapshots, function( $a, $b ) {
            return $b['created_timestamp'] - $a['created_timestamp'];
        });

        // Keep only the newest snapshots
        return array_slice( $snapshots, 0, self::MAX_SNAPSHOTS, true );
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
            'total_storage_used' => $this->calculate_total_storage( $snapshots ),
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

        $oldest = min( array_column( $snapshots, 'created_timestamp' ) );
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

        $newest = max( array_column( $snapshots, 'created_timestamp' ) );
        return date( 'Y-m-d H:i:s', $newest );
    }

    /**
     * Calculate total storage used by snapshots
     *
     * @param array $snapshots Snapshots array.
     * @return int Total storage in bytes.
     */
    private function calculate_total_storage( array $snapshots ): int {
        $total = 0;
        foreach ( $snapshots as $snapshot ) {
            // Estimate storage based on JSON size
            $total += strlen( serialize( $snapshot ) );
        }
        return $total;
    }

    /**
     * Clear all snapshots
     *
     * @return bool Success status.
     */
    public function clear_all_snapshots(): bool {
        $result = delete_option( self::SNAPSHOTS_OPTION );
        
        if ( $result ) {
            do_action( 'rbdusb_all_snapshots_cleared' );
        }
        
        return $result;
    }
}
