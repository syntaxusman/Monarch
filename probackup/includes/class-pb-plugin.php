<?php
/**
 * Admin page, assets and AJAX router.
 *
 * @package ProBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin controller.
 */
class PB_Plugin {

	const ACTIVE_JOB = 'probackup_active_job';

	/**
	 * Singleton.
	 *
	 * @var PB_Plugin
	 */
	private static $instance;

	/**
	 * Admin page hook.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Allowed AJAX sub-actions.
	 *
	 * @var array
	 */
	private $actions = array(
		'export_start',
		'step',
		'cancel',
		'import_inspect',
		'import_start',
		'import_resolve',
		'backups',
		'delete_backup',
		'upload_status',
		'upload_chunk',
		'croc_info',
		'croc_install',
		'croc_send',
		'croc_receive',
		'croc_poll',
		'croc_cancel',
		'save_settings',
	);

	/**
	 * Get instance.
	 *
	 * @return PB_Plugin
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_probackup_step', array( $this, 'ajax' ) );
		add_action( 'admin_post_probackup_download', array( $this, 'download' ) );
		add_filter( 'plugin_action_links_' . PROBACKUP_BASENAME, array( $this, 'links' ) );
	}

	/**
	 * Plugin list link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=probackup' ) ) . '">' . esc_html__( 'Migrate', 'probackup' ) . '</a>' );
		return $links;
	}

	/**
	 * Admin menu.
	 */
	public function menu() {
		$this->hook = add_menu_page(
			__( 'ProBackup', 'probackup' ),
			__( 'ProBackup', 'probackup' ),
			'manage_options',
			'probackup',
			array( $this, 'page' ),
			'dashicons-migrate',
			76
		);
	}

	/**
	 * Enqueue assets on our page only.
	 *
	 * @param string $hook Current hook.
	 */
	public function assets( $hook ) {
		if ( $hook !== $this->hook ) {
			return;
		}
		PB_Storage::ensure();
		PB_Storage::cleanup_stale();
		$ver = PROBACKUP_VERSION . '.' . (int) max( @filemtime( PROBACKUP_DIR . 'assets/admin.css' ), @filemtime( PROBACKUP_DIR . 'assets/admin.js' ) ); // phpcs:ignore -- cache-bust on every build
		wp_enqueue_style( 'probackup', PROBACKUP_URL . 'assets/admin.css', array(), $ver );
		wp_enqueue_script( 'probackup', PROBACKUP_URL . 'assets/admin.js', array(), $ver, true );

		$max_upload = (int) wp_max_upload_size();
		$post_max   = PB_Util::to_bytes( ini_get( 'post_max_size' ) );
		$limit      = min( 8 * 1048576, $max_upload > 0 ? $max_upload : 8 * 1048576, $post_max > 0 ? $post_max : 8 * 1048576 );
		$chunk      = max( 262144, (int) ( $limit - 131072 ) );

		$active = get_option( self::ACTIVE_JOB );
		$resume = null;
		if ( $active ) {
			$job = PB_Storage::load_job( $active );
			if ( $job && 'done' !== $job['stage'] ) {
				$resume = array(
					'id'   => $job['id'],
					'type' => $job['type'],
				);
			} else {
				delete_option( self::ACTIVE_JOB );
			}
		}

		wp_localize_script(
			'probackup',
			'ProBackup',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'probackup' ),
				'download'  => admin_url( 'admin-post.php?action=probackup_download&_wpnonce=' . wp_create_nonce( 'probackup_download' ) . '&name=' ),
				'chunk'     => $chunk,
				'home'      => home_url(),
				'multisite' => is_multisite(),
				'backups'   => PB_Storage::backups(),
				'settings'  => PB_Storage::settings(),
				'resume'    => $resume,
				'version'   => PROBACKUP_VERSION,
				'diskFree'  => function_exists( 'disk_free_space' ) ? size_format( (float) @disk_free_space( WP_CONTENT_DIR ), 1 ) : '', // phpcs:ignore
				'env'       => $this->env(),
			)
		);
	}

	/**
	 * Server facts for the overview panel.
	 *
	 * @return array
	 */
	private function env() {
		global $wpdb, $wp_version;
		$server = (string) $wpdb->db_server_info();
		return array(
			array( 'WordPress', $wp_version ),
			array( 'PHP', PHP_VERSION ),
			array( 'Database', ( false !== stripos( $server, 'maria' ) ? 'MariaDB ' : 'MySQL ' ) . $wpdb->db_version() ),
			array( 'Table prefix', $wpdb->prefix ),
			array( 'Memory limit', ini_get( 'memory_limit' ) ),
			array( 'Max upload', size_format( wp_max_upload_size() ) ),
			array( 'Free disk', function_exists( 'disk_free_space' ) ? size_format( (float) @disk_free_space( WP_CONTENT_DIR ), 1 ) : 'unknown' ), // phpcs:ignore
			array( 'Run programs (croc)', PB_Croc::can_exec() ? 'allowed' : 'disabled by host' ),
		);
	}

	/**
	 * Page shell (the UI is rendered by admin.js).
	 */
	public function page() {
		echo '<div class="wrap pb-wrap"><h1 class="screen-reader-text">ProBackup</h1><div id="probackup-app" class="pb-app" aria-live="polite"><div class="pb-loading">' . esc_html__( 'Loading…', 'probackup' ) . '</div></div>';
		echo '<noscript>' . esc_html__( 'ProBackup needs JavaScript enabled.', 'probackup' ) . '</noscript></div>';
	}

	/**
	 * AJAX entry point.
	 */
	public function ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'probackup' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'probackup', 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Your session expired. Please log in again.', 'probackup' ),
					'login'   => wp_login_url( admin_url( 'admin.php?page=probackup' ) ),
				),
				403
			);
		}
		$sub = isset( $_POST['sub'] ) ? sanitize_key( wp_unslash( $_POST['sub'] ) ) : '';
		if ( ! in_array( $sub, $this->actions, true ) ) {
			wp_send_json_error( array( 'message' => 'Unknown action.' ), 400 );
		}
		PB_Storage::ensure();
		PB_Log::$since = isset( $_POST['since'] ) ? max( 0, (int) $_POST['since'] ) : 0; // phpcs:ignore
		try {
			$method = 'do_' . $sub;
			$result = $this->$method();
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Read a POST field.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private function post( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore
	}

	/**
	 * Start an export.
	 *
	 * @return array|WP_Error
	 */
	private function do_export_start() {
		if ( is_multisite() ) {
			return new WP_Error( 'pb_ms', __( 'Multisite networks are not supported yet.', 'probackup' ) );
		}
		$raw  = isset( $_POST['opts'] ) ? json_decode( wp_unslash( $_POST['opts'] ), true ) : array(); // phpcs:ignore
		$opts = array();
		foreach ( array( 'database', 'uploads', 'plugins', 'themes', 'muplugins', 'other', 'no_spam', 'no_revisions', 'no_transients' ) as $k ) {
			$opts[ $k ] = ! empty( $raw[ $k ] );
		}
		PB_Util::start_timer( PB_Storage::settings()['time_budget'] );
		$job = PB_Export::start( $opts );
		update_option( self::ACTIVE_JOB, $job['id'], false );
		return PB_Export::progress( $job );
	}

	/**
	 * Run one step of the active job.
	 *
	 * @return array|WP_Error
	 */
	private function do_step() {
		$id  = preg_replace( '/[^a-z0-9]/', '', $this->post( 'id' ) );
		$job = PB_Storage::load_job( $id );
		if ( ! $job ) {
			return new WP_Error( 'pb_job', __( 'This job no longer exists.', 'probackup' ) );
		}
		$lock = PB_Storage::lock( $id );
		if ( ! $lock ) {
			// Another request is still working on it; just report progress.
			$p         = 'import' === $job['type'] ? PB_Import::progress( $job ) : PB_Export::progress( $job );
			$p['busy'] = true;
			return $p;
		}
		$budget = isset( $job['budget'] ) ? $job['budget'] : PB_Storage::settings()['time_budget'];
		PB_Util::start_timer( $budget );
		try {
			if ( 'import' === $job['type'] ) {
				$p = PB_Import::step( $job );
			} else {
				$p = PB_Export::step( $job );
			}
		} catch ( Throwable $e ) {
			PB_Storage::unlock( $lock );
			if ( 'import' === $job['type'] ) {
				$job['error'] = array(
					'stage'   => $job['stage'],
					'message' => $e->getMessage(),
				);
				PB_Storage::save_job( $job );
				return PB_Import::progress( $job );
			}
			return new WP_Error( 'pb_step', $e->getMessage() );
		}
		if ( $p['done'] ) {
			delete_option( self::ACTIVE_JOB );
			PB_Storage::unlock( $lock );
			PB_Storage::delete_job( $id );
			@unlink( PB_Storage::job_file( $id ) . '.lock' ); // phpcs:ignore
		} else {
			PB_Storage::save_job( $job );
			PB_Storage::unlock( $lock );
		}
		return $p;
	}

	/**
	 * Cancel a job (import: drop temp tables).
	 *
	 * @return array
	 */
	private function do_cancel() {
		$id  = preg_replace( '/[^a-z0-9]/', '', $this->post( 'id' ) );
		$job = PB_Storage::load_job( $id );
		if ( $job && 'import' === $job['type'] ) {
			PB_Import::rollback( $job );
		}
		PB_Storage::delete_job( $id );
		@unlink( PB_Storage::job_file( $id ) . '.lock' ); // phpcs:ignore
		delete_option( self::ACTIVE_JOB );
		return array( 'cancelled' => true );
	}

	/**
	 * Inspect a backup before import.
	 *
	 * @return array|WP_Error
	 */
	private function do_import_inspect() {
		$path = PB_Storage::backup_path( $this->post( 'name' ) );
		if ( ! $path || ! is_file( $path ) ) {
			return new WP_Error( 'pb_nofile', __( 'Backup not found.', 'probackup' ) );
		}
		return PB_Import::inspect( $path );
	}

	/**
	 * Start an import.
	 *
	 * @return array|WP_Error
	 */
	private function do_import_start() {
		$path = PB_Storage::backup_path( $this->post( 'name' ) );
		if ( ! $path || ! is_file( $path ) ) {
			return new WP_Error( 'pb_nofile', __( 'Backup not found.', 'probackup' ) );
		}
		$extra = array();
		$raw   = isset( $_POST['extra'] ) ? json_decode( wp_unslash( $_POST['extra'] ), true ) : array(); // phpcs:ignore
		foreach ( (array) $raw as $pair ) {
			if ( is_array( $pair ) && isset( $pair[0], $pair[1] ) && is_string( $pair[0] ) && is_string( $pair[1] ) && '' !== trim( $pair[0] ) ) {
				$extra[] = array( trim( $pair[0] ), trim( $pair[1] ) );
			}
		}
		PB_Util::start_timer( PB_Storage::settings()['time_budget'] );
		$job = PB_Import::start( $path, $extra );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		update_option( self::ACTIVE_JOB, $job['id'], false );
		return PB_Import::progress( $job );
	}

	/**
	 * Resolve an import error (retry or skip).
	 *
	 * @return array|WP_Error
	 */
	private function do_import_resolve() {
		$id  = preg_replace( '/[^a-z0-9]/', '', $this->post( 'id' ) );
		$job = PB_Storage::load_job( $id );
		if ( ! $job ) {
			return new WP_Error( 'pb_job', __( 'This job no longer exists.', 'probackup' ) );
		}
		PB_Import::resolve( $job, 'skip' === $this->post( 'decision' ) ? 'skip' : 'retry' );
		PB_Storage::save_job( $job );
		return PB_Import::progress( $job );
	}

	/**
	 * Backups list.
	 *
	 * @return array
	 */
	private function do_backups() {
		return array(
			'backups' => PB_Storage::backups(),
		);
	}

	/**
	 * Delete a backup.
	 *
	 * @return array|WP_Error
	 */
	private function do_delete_backup() {
		$path = PB_Storage::backup_path( $this->post( 'name' ) );
		if ( ! $path || ! is_file( $path ) ) {
			return new WP_Error( 'pb_nofile', __( 'Backup not found.', 'probackup' ) );
		}
		@unlink( $path ); // phpcs:ignore
		return $this->do_backups();
	}

	/**
	 * Name for an upload's partial file.
	 *
	 * @return string|WP_Error
	 */
	private function upload_part() {
		$name = PB_Storage::valid_name( $this->post( 'name' ) );
		if ( ! $name ) {
			return new WP_Error( 'pb_name', __( 'Please choose a .pbk file created by ProBackup.', 'probackup' ) );
		}
		return PB_Storage::dir( 'incoming' ) . '/' . $name . '.part';
	}

	/**
	 * How many bytes of an upload have arrived (for resuming).
	 *
	 * @return array|WP_Error
	 */
	private function do_upload_status() {
		$part = $this->upload_part();
		if ( is_wp_error( $part ) ) {
			return $part;
		}
		return array( 'received' => is_file( $part ) ? PB_Util::filesize( $part ) : 0 );
	}

	/**
	 * Receive one upload chunk.
	 *
	 * @return array|WP_Error
	 */
	private function do_upload_chunk() {
		$part = $this->upload_part();
		if ( is_wp_error( $part ) ) {
			return $part;
		}
		$offset = (int) $this->post( 'offset' );
		$total  = (int) $this->post( 'total' );
		$have   = is_file( $part ) ? PB_Util::filesize( $part ) : 0;
		if ( $offset !== $have ) {
			return array(
				'received' => $have,
				'resync'   => true,
			);
		}
		if ( empty( $_FILES['chunk']['tmp_name'] ) || ! is_uploaded_file( $_FILES['chunk']['tmp_name'] ) ) { // phpcs:ignore
			return new WP_Error( 'pb_chunk', __( 'The upload chunk did not arrive. Your server may limit upload size.', 'probackup' ) );
		}
		$in  = fopen( $_FILES['chunk']['tmp_name'], 'rb' ); // phpcs:ignore
		$out = fopen( $part, 'ab' ); // phpcs:ignore
		if ( ! $in || ! $out ) {
			return new WP_Error( 'pb_chunk', __( 'Cannot write the uploaded file. Check free disk space.', 'probackup' ) );
		}
		stream_copy_to_stream( $in, $out );
		fclose( $in ); // phpcs:ignore
		fclose( $out ); // phpcs:ignore
		$have = PB_Util::filesize( $part );
		if ( $total > 0 && $have >= $total ) {
			$name = basename( $part, '.part' );
			$dest = PB_Storage::dir( 'backups' ) . '/' . $name;
			if ( file_exists( $dest ) ) {
				$name = preg_replace( '/\.pbk$/', '', $name ) . '-' . PB_Util::rand_id( 4 ) . '.pbk';
				$dest = PB_Storage::dir( 'backups' ) . '/' . $name;
			}
			$check = PB_Archive::validate( $part );
			if ( is_wp_error( $check ) ) {
				@unlink( $part ); // phpcs:ignore
				return $check;
			}
			rename( $part, $dest ); // phpcs:ignore
			return array(
				'received' => $have,
				'complete' => true,
				'name'     => $name,
			);
		}
		return array( 'received' => $have );
	}

	/**
	 * croc environment.
	 *
	 * @return array
	 */
	private function do_croc_info() {
		return PB_Croc::status_info();
	}

	/**
	 * Install croc.
	 *
	 * @return array|WP_Error
	 */
	private function do_croc_install() {
		@set_time_limit( 300 ); // phpcs:ignore
		$r = PB_Croc::install();
		return is_wp_error( $r ) ? $r : PB_Croc::status_info();
	}

	/**
	 * Send a backup with croc.
	 *
	 * @return array|WP_Error
	 */
	private function do_croc_send() {
		$s = PB_Croc::send( $this->post( 'name' ), $this->post( 'code' ) );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		return array(
			'id'     => $s['id'],
			'code'   => $s['code'],
			'manual' => PB_Croc::manual( $s['code'], $s['file'] ),
		);
	}

	/**
	 * Receive a backup with croc.
	 *
	 * @return array|WP_Error
	 */
	private function do_croc_receive() {
		$s = PB_Croc::receive( $this->post( 'code' ) );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		return array( 'id' => $s['id'] );
	}

	/**
	 * Poll croc.
	 *
	 * @return array|WP_Error
	 */
	private function do_croc_poll() {
		return PB_Croc::poll( preg_replace( '/[^a-z0-9]/', '', $this->post( 'id' ) ) );
	}

	/**
	 * Cancel croc.
	 *
	 * @return array
	 */
	private function do_croc_cancel() {
		PB_Croc::cancel( preg_replace( '/[^a-z0-9]/', '', $this->post( 'id' ) ) );
		return array( 'cancelled' => true );
	}

	/**
	 * Save settings.
	 *
	 * @return array
	 */
	private function do_save_settings() {
		$raw = isset( $_POST['settings'] ) ? json_decode( wp_unslash( $_POST['settings'] ), true ) : array(); // phpcs:ignore
		return array( 'settings' => PB_Storage::save_settings( is_array( $raw ) ? $raw : array() ) );
	}

	/**
	 * Stream a backup download (supports HTTP Range for resumable downloads).
	 */
	public function download() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'probackup_download' ) ) {
			wp_die( esc_html__( 'Link expired. Reload the ProBackup page and try again.', 'probackup' ), 403 );
		}
		$path = PB_Storage::backup_path( isset( $_GET['name'] ) ? sanitize_text_field( wp_unslash( $_GET['name'] ) ) : '' );
		if ( ! $path || ! is_file( $path ) ) {
			wp_die( esc_html__( 'Backup not found.', 'probackup' ), 404 );
		}
		$size  = PB_Util::filesize( $path );
		$start = 0;
		$end   = $size - 1;
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		@set_time_limit( 0 ); // phpcs:ignore
		if ( isset( $_SERVER['HTTP_RANGE'] ) && preg_match( '/bytes=(\d*)-(\d*)/', sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ), $m ) ) {
			$start = '' === $m[1] ? max( 0, $size - (int) $m[2] ) : (int) $m[1];
			$end   = ( '' === $m[1] || '' === $m[2] ) ? $size - 1 : min( $size - 1, (int) $m[2] );
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		header( 'X-Accel-Buffering: no' );
		$fh = fopen( $path, 'rb' ); // phpcs:ignore
		fseek( $fh, $start );
		$left = $end - $start + 1;
		while ( $left > 0 && ! feof( $fh ) && ! connection_aborted() ) {
			$buf   = fread( $fh, (int) min( 1048576, $left ) ); // phpcs:ignore
			$left -= strlen( $buf );
			echo $buf; // phpcs:ignore
			flush();
		}
		fclose( $fh ); // phpcs:ignore
		exit;
	}
}
