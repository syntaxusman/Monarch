<?php
/**
 * Export step machine: database -> scan files -> build archive.
 *
 * @package ProBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exporter.
 */
class PB_Export {

	/**
	 * Top level wp-content entries that are never exported.
	 *
	 * @var array
	 */
	private static $always_skip = array(
		'cache',
		'upgrade',
		'upgrade-temp-backup',
		'ai1wm-backups',
		'updraft',
		'backups-dup-lite',
		'backups-dup-pro',
		'wpvividbackups',
		'backup-guard',
		'backwpup-logs',
		'et-cache',
		'litespeed',
		'wflogs',
		'debug.log',
		'object-cache.php',
		'advanced-cache.php',
		'db.php',
		'db-error.php',
	);

	/**
	 * Start a new export job.
	 *
	 * @param array $opts Options from the UI.
	 * @return array Job.
	 */
	public static function start( $opts ) {
		$o = array(
			'database'      => ! empty( $opts['database'] ),
			'uploads'       => ! empty( $opts['uploads'] ),
			'plugins'       => ! empty( $opts['plugins'] ),
			'themes'        => ! empty( $opts['themes'] ),
			'muplugins'     => ! empty( $opts['muplugins'] ),
			'other'         => ! empty( $opts['other'] ),
			'no_spam'       => ! empty( $opts['no_spam'] ),
			'no_revisions'  => ! empty( $opts['no_revisions'] ),
			'no_transients' => ! empty( $opts['no_transients'] ),
		);
		$host = preg_replace( '/[^a-z0-9.-]/', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$name = sanitize_file_name( $host . '-' . gmdate( 'Ymd-His' ) . '-' . PB_Util::rand_id( 4 ) . '.pbk' );
		$id   = PB_Util::rand_id( 16 );
		$job  = array(
			'id'      => $id,
			'type'    => 'export',
			'opts'    => $o,
			'stage'   => $o['database'] ? 'db' : 'scan',
			'name'    => $name,
			'started' => time(),
			'log'     => array(),
			'db'      => $o['database'] ? PB_DB_Dump::init( $o ) : null,
			'scan'    => array(
				'stack'     => array( '' ),
				'list_size' => 0,
				'files'     => 0,
				'bytes'     => 0,
			),
			'arc'     => array(
				'size'   => 0,
				'stage'  => 'meta',
				'pos'    => 0,
				'cursor' => null,
				'files'  => 0,
				'bytes'  => 0,
			),
		);
		self::log( $job, __( 'Export started.', 'probackup' ) );
		if ( $o['database'] ) {
			/* translators: %d: table count */
			self::log( $job, sprintf( __( 'Found %d database tables.', 'probackup' ), count( $job['db']['tables'] ) ) );
		}
		PB_Storage::save_job( $job );
		return $job;
	}

	/**
	 * Append to the job log (keeps the last 60 lines).
	 *
	 * @param array  $job Job.
	 * @param string $msg Message.
	 */
	public static function log( &$job, $msg ) {
		$job['log'][] = gmdate( 'H:i:s' ) . '  ' . $msg;
		if ( count( $job['log'] ) > 60 ) {
			$job['log'] = array_slice( $job['log'], -60 );
		}
	}

	/**
	 * Run one slice.
	 *
	 * @param array $job Job (modified).
	 * @return array Progress payload.
	 */
	public static function step( &$job ) {
		$dir = PB_Storage::job_dir( $job['id'] );
		switch ( $job['stage'] ) {
			case 'db':
				if ( PB_DB_Dump::step( $job['db'], $dir . '/database.sql' ) ) {
					/* translators: 1: rows, 2: statements */
					self::log( $job, sprintf( __( 'Database exported: %1$s rows in %2$s statements.', 'probackup' ), number_format_i18n( $job['db']['rows_done'] ), number_format_i18n( $job['db']['stmts'] ) ) );
					$job['stage'] = 'scan';
				}
				break;
			case 'scan':
				if ( self::scan( $job, $dir . '/files.txt' ) ) {
					/* translators: 1: file count, 2: size */
					self::log( $job, sprintf( __( 'Found %1$s files (%2$s).', 'probackup' ), number_format_i18n( $job['scan']['files'] ), size_format( $job['scan']['bytes'], 1 ) ) );
					$job['stage'] = 'archive';
				}
				break;
			case 'archive':
				if ( self::archive( $job, $dir ) ) {
					$job['stage'] = 'done';
					rename( $dir . '/' . $job['name'] . '.part', PB_Storage::dir( 'backups' ) . '/' . $job['name'] ); // phpcs:ignore
					/* translators: %s: size */
					self::log( $job, sprintf( __( 'Archive ready (%s).', 'probackup' ), size_format( $job['arc']['size'], 1 ) ) );
					PB_Storage::prune();
				}
				break;
		}
		return self::progress( $job );
	}

	/**
	 * Build progress payload for the UI.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	public static function progress( $job ) {
		$pct   = 0;
		$label = '';
		$o     = $job['opts'];
		$w_db  = $o['database'] ? 40 : 0;
		$w_sc  = 5;
		$w_ar  = 100 - $w_db - $w_sc;
		switch ( $job['stage'] ) {
			case 'db':
				$p     = $job['db']['rows_done'] / PB_DB_Dump::total_rows( $job['db'] );
				$pct   = $w_db * min( 1, $p );
				$table = isset( $job['db']['tables'][ $job['db']['ti'] ] ) ? $job['db']['tables'][ $job['db']['ti'] ]['name'] : '';
				/* translators: %s: table name */
				$label = sprintf( __( 'Exporting database — %s', 'probackup' ), $table );
				break;
			case 'scan':
				$pct = $w_db + 2;
				/* translators: %s: count */
				$label = sprintf( __( 'Scanning files — %s found', 'probackup' ), number_format_i18n( $job['scan']['files'] ) );
				break;
			case 'archive':
				$total = max( 1, $job['scan']['bytes'] + ( $o['database'] ? $job['db']['sql_size'] : 0 ) );
				$pct   = $w_db + $w_sc + $w_ar * min( 1, $job['arc']['bytes'] / $total );
				/* translators: 1: done count, 2: total count */
				$label = sprintf( __( 'Packing files — %1$s / %2$s', 'probackup' ), number_format_i18n( $job['arc']['files'] ), number_format_i18n( $job['scan']['files'] ) );
				break;
			case 'done':
				$pct   = 100;
				$label = __( 'Export complete', 'probackup' );
				break;
		}
		return array(
			'id'      => $job['id'],
			'stage'   => $job['stage'],
			'percent' => round( $pct, 1 ),
			'label'   => $label,
			'log'     => $job['log'],
			'done'    => 'done' === $job['stage'],
			'file'    => 'done' === $job['stage'] ? $job['name'] : '',
			'size'    => 'done' === $job['stage'] ? size_format( $job['arc']['size'], 1 ) : '',
		);
	}

	/**
	 * Is a wp-content relative path included by the options?
	 *
	 * @param string $rel  Relative path (no leading slash).
	 * @param bool   $is_dir Directory.
	 * @param array  $o    Options.
	 * @return bool
	 */
	private static function included( $rel, $is_dir, $o ) {
		$parts = explode( '/', $rel );
		$top   = $parts[0];
		if ( in_array( $top, self::$always_skip, true ) ) {
			return false;
		}
		$storage = basename( PB_Storage::root() );
		if ( $top === $storage || 0 === strpos( $top, 'probackup-storage' ) ) {
			return false;
		}
		if ( ! $is_dir && preg_match( '/\.(log|pbk|wpress|tmp)$/i', $rel ) ) {
			return false;
		}
		$base = end( $parts );
		if ( in_array( $base, array( '.git', '.svn', 'node_modules', '.DS_Store', 'error_log' ), true ) ) {
			return false;
		}
		switch ( $top ) {
			case 'uploads':
				return $o['uploads'];
			case 'plugins':
				if ( isset( $parts[1] ) && dirname( PROBACKUP_BASENAME ) === $parts[1] ) {
					return false; // The destination already has ProBackup.
				}
				return $o['plugins'];
			case 'themes':
				return $o['themes'];
			case 'mu-plugins':
				return $o['muplugins'];
			default:
				if ( 'index.php' === $rel ) {
					return false;
				}
				return $o['other'];
		}
	}

	/**
	 * Walk wp-content incrementally, writing "size\trelpath" lines to the list file.
	 *
	 * @param array  $job  Job.
	 * @param string $list List file.
	 * @return bool Done.
	 */
	private static function scan( &$job, $list ) {
		$root = PB_Util::norm( WP_CONTENT_DIR );
		$fh   = PB_Archive::open_append( $list, $job['scan']['list_size'] );
		$sc   = &$job['scan'];
		while ( ! empty( $sc['stack'] ) && ! PB_Util::out_of_time() ) {
			$rel  = array_pop( $sc['stack'] );
			$abs  = '' === $rel ? $root : $root . '/' . $rel;
			$list_items = @scandir( $abs ); // phpcs:ignore
			if ( false === $list_items ) {
				continue;
			}
			foreach ( $list_items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$r = '' === $rel ? $item : $rel . '/' . $item;
				$a = $abs . '/' . $item;
				if ( is_link( $a ) && is_dir( $a ) ) {
					continue;
				}
				$is_dir = is_dir( $a );
				if ( ! self::included( $r, $is_dir, $job['opts'] ) ) {
					continue;
				}
				if ( $is_dir ) {
					$sc['stack'][] = $r;
				} elseif ( is_file( $a ) && is_readable( $a ) ) {
					$size = PB_Util::filesize( $a );
					fwrite( $fh, $size . "\t" . $r . "\n" ); // phpcs:ignore
					$sc['files']++;
					$sc['bytes'] += $size;
				}
			}
		}
		fflush( $fh );
		$sc['list_size'] = ftell( $fh );
		fclose( $fh ); // phpcs:ignore
		return empty( $sc['stack'] );
	}

	/**
	 * Build the manifest.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	private static function manifest( $job ) {
		global $wpdb, $wp_version;
		$tables = array();
		if ( $job['db'] ) {
			foreach ( $job['db']['tables'] as $t ) {
				$tables[] = array(
					'name' => $t['short'],
					'rows' => $t['rows'],
				);
			}
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $p ) {
			$plugins[] = $p;
		}
		return array(
			'generator'     => 'ProBackup ' . PROBACKUP_VERSION,
			'created'       => gmdate( 'c' ),
			'siteurl'       => get_option( 'siteurl' ),
			'home'          => get_option( 'home' ),
			'abspath'       => PB_Util::norm( ABSPATH ),
			'content_dir'   => PB_Util::norm( WP_CONTENT_DIR ),
			'uploads_url'   => wp_upload_dir( null, false )['baseurl'],
			'wp_version'    => $wp_version,
			'php_version'   => PHP_VERSION,
			'mysql_version' => $wpdb->db_version(),
			'prefix'        => $wpdb->prefix,
			'charset'       => $wpdb->charset,
			'multisite'     => is_multisite(),
			'template'      => get_option( 'template' ),
			'stylesheet'    => get_option( 'stylesheet' ),
			'plugins'       => $plugins,
			'options'       => $job['opts'],
			'has_database'  => (bool) $job['db'],
			'tables'        => $tables,
			'statements'    => $job['db'] ? $job['db']['stmts'] : 0,
			'db_size'       => $job['db'] ? $job['db']['sql_size'] : 0,
			'files'         => $job['scan']['files'],
			'files_size'    => $job['scan']['bytes'],
		);
	}

	/**
	 * Write the archive: package.json, database.sql, then content files.
	 *
	 * @param array  $job Job.
	 * @param string $dir Job folder.
	 * @return bool Done.
	 */
	private static function archive( &$job, $dir ) {
		$file = $dir . '/' . $job['name'] . '.part';
		$a    = &$job['arc'];
		if ( 'meta' === $a['stage'] ) {
			PB_Archive::create( $file );
			$fh = PB_Archive::open_append( $file, 4 );
			PB_Archive::add_string( $fh, 'package.json', wp_json_encode( self::manifest( $job ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			fflush( $fh );
			$a['size']  = ftell( $fh );
			fclose( $fh ); // phpcs:ignore
			$a['stage'] = $job['db'] ? 'sql' : 'files';
			return false;
		}
		$fh = PB_Archive::open_append( $file, $a['size'] );
		if ( ! $fh ) {
			throw new Exception( 'Cannot write the archive. Check free disk space.' );
		}
		if ( 'sql' === $a['stage'] ) {
			$before = $a['cursor'] ? $a['cursor']['done'] : 0;
			$r      = PB_Archive::add_file_piece( $fh, $dir . '/database.sql', 'database.sql', $a['cursor'] );
			if ( true === $r ) {
				$a['bytes'] += $job['db']['sql_size'] - $before;
				$a['cursor'] = null;
				$a['stage']  = 'files';
				@unlink( $dir . '/database.sql' ); // phpcs:ignore
			} else {
				$a['bytes'] += $r['done'] - $before;
				$a['cursor'] = $r;
			}
		}
		if ( 'files' === $a['stage'] ) {
			$root = PB_Util::norm( WP_CONTENT_DIR );
			$list = @fopen( $dir . '/files.txt', 'rb' ); // phpcs:ignore
			if ( $list ) {
				fseek( $list, $a['pos'] );
				while ( ! PB_Util::out_of_time() ) {
					$line_start = ftell( $list );
					$line       = fgets( $list );
					if ( false === $line ) {
						$a['stage'] = 'end';
						break;
					}
					$line = rtrim( $line, "\n" );
					$tab  = strpos( $line, "\t" );
					if ( false === $tab ) {
						$a['pos'] = ftell( $list );
						continue;
					}
					$rel    = substr( $line, $tab + 1 );
					$before = $a['cursor'] ? $a['cursor']['done'] : 0;
					$r      = PB_Archive::add_file_piece( $fh, $root . '/' . $rel, 'content/' . $rel, $a['cursor'] );
					if ( true === $r ) {
						$a['bytes'] += max( 0, (int) substr( $line, 0, $tab ) - $before );
						$a['cursor'] = null;
						$a['files']++;
						$a['pos'] = ftell( $list );
					} else {
						$a['bytes'] += $r['done'] - $before;
						$a['cursor'] = $r;
						$a['pos']    = $line_start;
						break;
					}
				}
				fclose( $list ); // phpcs:ignore
			} else {
				$a['stage'] = 'end';
			}
		}
		if ( 'end' === $a['stage'] ) {
			PB_Archive::finish( $fh );
		}
		fflush( $fh );
		$a['size'] = ftell( $fh );
		fclose( $fh ); // phpcs:ignore
		return 'end' === $a['stage'];
	}
}
