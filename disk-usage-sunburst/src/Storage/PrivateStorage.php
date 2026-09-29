<?php
/**
 * Private, non-web-accessible storage for scan jobs and snapshots.
 *
 * @package RaidBoxes\DiskUsageSunburst
 */
namespace RaidBoxes\DiskUsageSunburst\Storage;

final class PrivateStorage {
    /**
     * Return a private directory or an empty string (fail closed).
     * Define RBDUSB_PRIVATE_DIR in wp-config.php if the automatic location is not writable.
     */
    public static function directory( string $subdirectory ): string {
        if ( ! in_array( $subdirectory, [ 'jobs', 'snapshots' ], true ) ) {
            return '';
        }

        $wp_root = realpath( ABSPATH );
        if ( false === $wp_root ) {
            return '';
        }

        $document_root = ! empty( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( $_SERVER['DOCUMENT_ROOT'] ) : false;
        // Without the HTTP document root, only an explicitly configured private path is trusted.
        if ( ! $document_root && ! defined( 'RBDUSB_PRIVATE_DIR' ) ) {
            return '';
        }
        $public_root = $document_root ?: $wp_root;
        // Use the parent of the broader public root (e.g. /srv/site, not /srv/site/public).
        if ( $document_root && self::is_within( $wp_root, $document_root ) ) {
            $public_root = $document_root;
        } elseif ( $document_root && self::is_within( $document_root, $wp_root ) ) {
            $public_root = $wp_root;
        }

        $base = defined( 'RBDUSB_PRIVATE_DIR' ) && is_string( RBDUSB_PRIVATE_DIR )
            ? RBDUSB_PRIVATE_DIR
            : dirname( $public_root ) . '/.rbdusb-private-' . substr( hash( 'sha256', $wp_root ), 0, 16 );
        $site_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
        $dir = rtrim( $base, '/\\' ) . '/site-' . $site_id . '/' . $subdirectory;

        if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
            return '';
        }

        // Resolve after creation: reject symlinks and any location reachable through a webroot.
        $real_dir = realpath( $dir );
        if ( false === $real_dir || ! is_writable( $real_dir ) ||
            self::is_within( $real_dir, $wp_root ) ||
            ( $document_root && self::is_within( $real_dir, $document_root ) ) ) {
            return '';
        }

        return $real_dir;
    }

    private static function is_within( string $path, string $root ): bool {
        $root = rtrim( $root, '/\\' );
        return $path === $root || 0 === strpos( $path, $root . DIRECTORY_SEPARATOR );
    }
}
