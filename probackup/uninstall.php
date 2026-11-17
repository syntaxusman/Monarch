<?php
/**
 * Uninstall: remove options; remove backups only if the user asked for it.
 *
 * @package ProBackup
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$probackup_settings = get_option( 'probackup_settings', array() );
$probackup_dir      = get_option( 'probackup_storage_dir' );

if ( ! empty( $probackup_settings['delete_on_remove'] ) && $probackup_dir && preg_match( '/^probackup-storage-[a-z0-9]{12}$/', $probackup_dir ) ) {
	$probackup_root = untrailingslashit( WP_CONTENT_DIR ) . '/' . $probackup_dir;
	if ( is_dir( $probackup_root ) ) {
		$probackup_it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $probackup_root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $probackup_it as $probackup_f ) {
			if ( $probackup_f->isDir() && ! $probackup_f->isLink() ) {
				@rmdir( $probackup_f->getPathname() ); // phpcs:ignore
			} else {
				@unlink( $probackup_f->getPathname() ); // phpcs:ignore
			}
		}
		@rmdir( $probackup_root ); // phpcs:ignore
	}
	delete_option( 'probackup_storage_dir' );
}

delete_option( 'probackup_settings' );
delete_option( 'probackup_active_job' );
