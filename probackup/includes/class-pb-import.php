<?php
/**
 * Import step machine:
 * validate -> database (temp tables) -> replace URLs -> extract files -> swap tables -> done.
 *
 * The live site keeps running on its own tables until the final, atomic RENAME.
 *
 * @package ProBackup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Importer.
 */
class PB_Import {

	/**
	 * Inspect an archive for the confirmation screen.
	 *
	 * @param string $file Archive path.
	 * @return array|WP_Error
	 */
	public static function inspect( $file ) {
		$m = PB_Archive::validate( $file );
		if ( is_wp_error( $m ) ) {
			return $m;
		}
		return array(
			'source'     => $m['home'],
			'target'     => get_option( 'home' ),
			'created'    => isset( $m['created'] ) ? $m['created'] : '',
			'wp_version' => isset( $m['wp_version'] ) ? $m['wp_version'] : '',
			'php'        => isset( $m['php_version'] ) ? $m['php_version'] : '',
			'mysql'      => isset( $m['mysql_version'] ) ? $m['mysql_version'] : '',
			'tables'     => isset( $m['tables'] ) ? count( $m['tables'] ) : 0,
			'files'      => isset( $m['files'] ) ? (int) $m['files'] : 0,
			'size'       => size_format( $m['archive_size'], 1 ),
			'database'   => ! empty( $m['has_database'] ),
			'multisite'  => ! empty( $m['multisite'] ),
		);
	}

	/**
	 * Start an import job.
	 *
	 * @param string $file  Archive path.
	 * @param array  $extra Extra find/replace pairs.
	 * @return array|WP_Error
	 */
	public static function start( $file, $extra ) {
		global $wpdb;
		if ( is_multisite() ) {
			return new WP_Error( 'pb_ms', __( 'Multisite networks are not supported yet.', 'probackup' ) );
		}
		$m = PB_Archive::validate( $file );
		if ( is_wp_error( $m ) ) {
			return $m;
		}
		if ( ! empty( $m['multisite'] ) ) {
			return new WP_Error( 'pb_ms', __( 'This backup comes from a multisite network, which is not supported yet.', 'probackup' ) );
		}
		$fh = fopen( $file, 'rb' ); // phpcs:ignore
		fseek( $fh, 4 );
		$h = PB_Archive::read_header( $fh );
		fseek( $fh, ftell( $fh ) + $h['size'] );
		$hdr_pos = ftell( $fh );
		$h2      = PB_Archive::read_header( $fh );
		$db      = null;
		$content = $hdr_pos;
		if ( $h2 && 'database.sql' === $h2['name'] ) {
			$start   = ftell( $fh );
			$db      = array(
				'pos'   => $start,
				'end'   => $start + $h2['size'],
				'index' => 0,
				'total' => max( 1, (int) $m['statements'] ),
			);
			$content = $start + $h2['size'];
		}
		fclose( $fh ); // phpcs:ignore

		$id  = PB_Util::rand_id( 16 );
		$job = array(
			'id'         => $id,
			'type'       => 'import',
			'archive'    => $file,
			'manifest'   => $m,
			'stage'      => $db ? 'db' : 'files',
			'tmp_prefix' => 'pbt' . PB_Util::rand_id( 4 ) . '_',
			'live'       => $wpdb->prefix,
			'caps'       => PB_DB_Import::server_caps(),
			'budget'     => (int) PB_Storage::settings()['time_budget'],
			'db'         => $db,
			'extra'      => $extra,
			'replace'    => array(
				'tables'  => null,
				'ti'      => 0,
				'cols'    => null,
				'last'    => null,
				'offset'  => 0,
				'batch'   => 200,
				'updated' => 0,
			),
			'files'      => array(
				'pos'     => $content,
				'cur'     => null,
				'count'   => 0,
				'bytes'   => 0,
				'start'   => $content,
				'skipped' => 0,
			),
			'skipped'    => array(),
			'error'      => null,
			'log'        => array(),
			'started'    => time(),
		);
		/* translators: %s: source URL */
		PB_Export::log( $job, sprintf( __( 'Importing backup of %s', 'probackup' ), $m['home'] ) );
		if ( $db ) {
			/* translators: %s: statement count */
			PB_Export::log( $job, sprintf( __( 'Database: %s statements to import into temporary tables.', 'probackup' ), number_format_i18n( $db['total'] ) ) );
		}
		PB_Storage::save_job( $job );
		return $job;
	}

	/**
	 * Run one slice.
	 *
	 * @param array $job Job (modified).
	 * @return array Progress.
	 */
	public static function step( &$job ) {
		if ( $job['error'] ) {
			return self::progress( $job );
		}
		switch ( $job['stage'] ) {
			case 'db':
				$fh = fopen( $job['archive'], 'rb' ); // phpcs:ignore
				$r  = PB_DB_Import::step( $job, $fh );
				fclose( $fh ); // phpcs:ignore
				if ( 'done' === $r ) {
					/* translators: %s: count */
					PB_Export::log( $job, sprintf( __( 'Database imported (%s statements).', 'probackup' ), number_format_i18n( $job['db']['index'] ) ) );
					$job['stage'] = 'replace';
				} elseif ( 'error' === $r ) {
					/* translators: %d: statement number */
					PB_Export::log( $job, sprintf( __( 'Database error at statement #%d — waiting for your decision.', 'probackup' ), $job['error']['statement'] ) );
				}
				break;
			case 'replace':
				if ( self::replace( $job ) ) {
					/* translators: %s: count */
					PB_Export::log( $job, sprintf( __( 'Updated URLs and paths in %s rows.', 'probackup' ), number_format_i18n( $job['replace']['updated'] ) ) );
					$job['stage'] = 'files';
				}
				break;
			case 'files':
				if ( self::files( $job ) ) {
					/* translators: %s: count */
					PB_Export::log( $job, sprintf( __( 'Restored %s files.', 'probackup' ), number_format_i18n( $job['files']['count'] ) ) );
					$job['stage'] = $job['db'] ? 'swap' : 'done';
				}
				break;
			case 'swap':
				self::swap( $job );
				break;
		}
		return self::progress( $job );
	}

	/**
	 * Progress payload.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	public static function progress( $job ) {
		$stages = array( 'db', 'replace', 'files', 'swap', 'done' );
		$detail = '';
		$pct    = 0;
		switch ( $job['stage'] ) {
			case 'db':
				$p      = ( $job['db']['pos'] - ( $job['db']['end'] - $job['manifest']['db_size'] ) ) / max( 1, $job['manifest']['db_size'] );
				$pct    = 60 * min( 1, max( 0, $p ) );
				/* translators: 1: done, 2: total */
				$detail = sprintf( __( 'Statement %1$s of %2$s', 'probackup' ), number_format_i18n( $job['db']['index'] ), number_format_i18n( $job['db']['total'] ) );
				break;
			case 'replace':
				$t      = is_array( $job['replace']['tables'] ) ? count( $job['replace']['tables'] ) : 1;
				$pct    = 60 + 10 * ( $job['replace']['ti'] / max( 1, $t ) );
				$detail = isset( $job['replace']['tables'][ $job['replace']['ti'] ] ) ? $job['replace']['tables'][ $job['replace']['ti'] ] : '';
				break;
			case 'files':
				$total  = max( 1, $job['manifest']['archive_size'] - $job['files']['start'] );
				$pct    = ( $job['db'] ? 70 : 0 ) + ( $job['db'] ? 27 : 97 ) * min( 1, ( $job['files']['pos'] - $job['files']['start'] ) / $total );
				/* translators: %s: count */
				$detail = sprintf( __( '%s files restored', 'probackup' ), number_format_i18n( $job['files']['count'] ) );
				break;
			case 'swap':
				$pct = 98;
				break;
			case 'done':
				$pct = 100;
				break;
		}
		return array(
			'id'      => $job['id'],
			'stage'   => $job['stage'],
			'stages'  => $stages,
			'percent' => round( $pct, 1 ),
			'label'   => $detail,
			'log'     => $job['log'],
			'error'   => $job['error'],
			'done'    => 'done' === $job['stage'],
			'login'   => wp_login_url( admin_url( 'admin.php?page=probackup' ) ),
			'source'  => $job['manifest']['home'],
		);
	}

	/**
	 * User decision after a DB error.
	 *
	 * @param array  $job    Job.
	 * @param string $action retry|skip.
	 */
	public static function resolve( &$job, $action ) {
		$job['caps'] = PB_DB_Import::server_caps(); // The host may have changed settings (e.g. max_allowed_packet).
		if ( 'skip' === $action && 'db' === $job['stage'] ) {
			$job['db']['skip'] = true;
			/* translators: %d: statement */
			PB_Export::log( $job, sprintf( __( 'Skipped statement #%d.', 'probackup' ), $job['error']['statement'] ) );
		}
		$job['error'] = null;
	}

	/**
	 * Drop temporary tables (rollback before swap).
	 *
	 * @param array $job Job.
	 */
	public static function rollback( $job ) {
		global $wpdb;
		if ( empty( $job['tmp_prefix'] ) || 'done' === $job['stage'] ) {
			return;
		}
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $job['tmp_prefix'] ) . '%' ) ); // phpcs:ignore
		foreach ( (array) $tables as $t ) {
			PB_Util::raw_query( 'DROP TABLE IF EXISTS ' . PB_Util::ident( $t ) );
		}
	}

	/**
	 * Search & replace on the temporary tables, page by page.
	 *
	 * @param array $job Job.
	 * @return bool Done.
	 */
	private static function replace( &$job ) {
		global $wpdb;
		list( $pairs, $needles ) = PB_Replace::build( $job['manifest'], $job['extra'] );
		$rep = new PB_Replace( $pairs, $needles );
		$r   = &$job['replace'];
		if ( ! $rep->active() ) {
			return true;
		}
		if ( null === $r['tables'] ) {
			$r['tables'] = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $job['tmp_prefix'] ) . '%' ) ); // phpcs:ignore
			PB_Export::log( $job, __( 'Replacing old URLs and paths…', 'probackup' ) );
		}
		while ( ! PB_Util::out_of_time() ) {
			if ( $r['ti'] >= count( $r['tables'] ) ) {
				return true;
			}
			$table = $r['tables'][ $r['ti'] ];
			if ( null === $r['cols'] ) {
				$r['cols'] = self::replace_columns( $table );
				$r['last'] = null;
				$r['offset'] = 0;
			}
			$c = $r['cols'];
			if ( empty( $c['text'] ) || empty( $c['pk'] ) ) {
				self::next_table( $r );
				continue;
			}
			$select = array_unique( array_merge( $c['pk'], $c['text'] ) );
			$sql    = 'SELECT ' . implode( ',', array_map( array( 'PB_Util', 'ident' ), $select ) ) . ' FROM ' . PB_Util::ident( $table );
			$limit  = (int) $r['batch'];
			if ( $c['keyset'] ) {
				$pk    = PB_Util::ident( $c['pk'][0] );
				$where = null === $r['last'] ? '' : ' WHERE ' . $pk . " > '" . PB_Util::esc_sql_value( $r['last'] ) . "'";
				$sql  .= $where . ' ORDER BY ' . $pk . ' LIMIT ' . $limit;
			} elseif ( ! empty( $c['fullrow'] ) ) {
				// No key: rely on the table's natural (insertion) order, which UPDATEs don't change.
				$sql .= ' LIMIT ' . (int) $r['offset'] . ', ' . $limit;
			} else {
				$sql .= ' ORDER BY ' . implode( ',', array_map( array( 'PB_Util', 'ident' ), $c['pk'] ) ) . ' LIMIT ' . (int) $r['offset'] . ', ' . $limit;
			}
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
			if ( empty( $rows ) ) {
				self::next_table( $r );
				continue;
			}
			$bytes = 0;
			foreach ( $rows as $row ) {
				$set = array();
				foreach ( $c['text'] as $col ) {
					$v = $row[ $col ];
					if ( null === $v ) {
						continue;
					}
					$bytes += strlen( $v );
					$nv     = $rep->value( $v );
					if ( $nv !== $v ) {
						$set[] = PB_Util::ident( $col ) . " = '" . PB_Util::esc_sql_value( $nv ) . "'";
					}
				}
				if ( $set ) {
					$w = array();
					foreach ( $c['pk'] as $k ) {
						// NULL-safe, byte-exact match (works for binary columns too).
						$w[] = null === $row[ $k ] ? PB_Util::ident( $k ) . ' IS NULL' : PB_Util::ident( $k ) . " = UNHEX('" . bin2hex( $row[ $k ] ) . "')";
					}
					PB_Util::raw_query( 'UPDATE ' . PB_Util::ident( $table ) . ' SET ' . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $w ) . ' LIMIT 1' );
					$r['updated']++;
				}
				if ( $c['keyset'] ) {
					$r['last'] = $row[ $c['pk'][0] ];
				}
			}
			$n            = count( $rows );
			$r['offset'] += $n;
			$r['batch']   = (int) max( 10, min( 1000, 2097152 / max( 1, $bytes / $n ) ) );
			if ( $n < $limit ) {
				self::next_table( $r );
			}
		}
		return false;
	}

	/**
	 * Move to the next table.
	 *
	 * @param array $r Replace state.
	 */
	private static function next_table( &$r ) {
		$r['ti']++;
		$r['cols']   = null;
		$r['last']   = null;
		$r['offset'] = 0;
		$r['batch']  = 200;
	}

	/**
	 * Text columns and key for a table.
	 *
	 * @param string $table Table.
	 * @return array
	 */
	private static function replace_columns( $table ) {
		global $wpdb;
		$text    = array();
		$pk      = array();
		$uniq    = array();
		$numeric = false;
		foreach ( (array) $wpdb->get_results( 'SHOW COLUMNS FROM ' . PB_Util::ident( $table ), ARRAY_A ) as $col ) { // phpcs:ignore
			$type = strtolower( $col['Type'] );
			if ( 'PRI' === $col['Key'] ) {
				$pk[]    = $col['Field'];
				$numeric = (bool) preg_match( '/int/', $type );
			}
			if ( 'guid' === $col['Field'] ) {
				continue;
			}
			if ( preg_match( '/(char|text|json)/', $type ) ) {
				$text[] = $col['Field'];
			}
		}
		$fullrow = false;
		if ( empty( $pk ) ) {
			// Fall back to the first UNIQUE index.
			$keys = $wpdb->get_results( 'SHOW KEYS FROM ' . PB_Util::ident( $table ) . ' WHERE Non_unique = 0', ARRAY_A ); // phpcs:ignore
			foreach ( (array) $keys as $k ) {
				$uniq[ $k['Key_name'] ][] = $k['Column_name'];
			}
			if ( $uniq ) {
				$pk = reset( $uniq );
			}
		}
		if ( empty( $pk ) ) {
			// No key at all: identify rows by every column's original value.
			$fullrow = true;
			foreach ( (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . PB_Util::ident( $table ) ) as $c ) { // phpcs:ignore
				$pk[] = $c;
			}
		}
		return array(
			'text'    => $text,
			'pk'      => $pk,
			'fullrow' => $fullrow,
			'keyset'  => 1 === count( $pk ) && $numeric && empty( $uniq ) && ! $fullrow,
		);
	}

	/**
	 * Extract wp-content files, resumable mid-file.
	 *
	 * @param array $job Job.
	 * @return bool Done.
	 */
	private static function files( &$job ) {
		$f       = &$job['files'];
		$fh      = fopen( $job['archive'], 'rb' ); // phpcs:ignore
		$root    = PB_Util::norm( WP_CONTENT_DIR );
		$self    = 'plugins/' . dirname( PROBACKUP_BASENAME ) . '/';
		$storage = basename( PB_Storage::root() );
		$done    = false;
		$lastdir = '';
		while ( ! PB_Util::out_of_time() ) {
			if ( null === $f['cur'] ) {
				fseek( $fh, $f['pos'] );
				$h = PB_Archive::read_header( $fh );
				if ( ! $h ) {
					throw new Exception( 'The archive is corrupted near byte ' . $f['pos'] . '.' );
				}
				if ( PB_Archive::END === $h['name'] ) {
					$done = true;
					break;
				}
				$target = null;
				$rel    = PB_Archive::safe_path( $h['name'] );
				if ( $rel && 0 === strpos( $rel, 'content/' ) ) {
					$rel = substr( $rel, 8 );
					if ( 0 !== strpos( $rel, $self ) && 0 !== strpos( $rel, $storage ) && 0 !== strpos( $rel, 'probackup-storage' ) ) {
						$target = $root . '/' . $rel;
					}
				}
				if ( null === $target ) {
					$f['skipped']++;
				}
				$f['cur'] = array(
					'target' => $target,
					'size'   => $h['size'],
					'done'   => 0,
					'data'   => ftell( $fh ),
					'mtime'  => $h['mtime'],
				);
			}
			$cur = &$f['cur'];
			if ( $cur['target'] && $cur['size'] >= 0 ) {
				$dir = dirname( $cur['target'] );
				if ( $dir !== $lastdir ) {
					if ( ! is_dir( $dir ) ) {
						wp_mkdir_p( $dir );
					}
					$lastdir = $dir;
				}
				$out = @fopen( $cur['target'], 'c+b' ); // phpcs:ignore
				if ( ! $out ) {
					/* translators: %s: file */
					PB_Export::log( $job, sprintf( __( 'Could not write %s (skipped).', 'probackup' ), str_replace( $root . '/', '', $cur['target'] ) ) );
					$cur['target'] = null;
				} else {
					ftruncate( $out, $cur['done'] );
					fseek( $out, $cur['done'] );
					fseek( $fh, $cur['data'] + $cur['done'] );
					while ( $cur['done'] < $cur['size'] ) {
						$chunk = fread( $fh, (int) min( PB_Archive::PIECE, $cur['size'] - $cur['done'] ) ); // phpcs:ignore
						if ( false === $chunk || '' === $chunk ) {
							fclose( $out ); // phpcs:ignore
							throw new Exception( 'Unexpected end of archive.' );
						}
						fwrite( $out, $chunk ); // phpcs:ignore
						$cur['done']  += strlen( $chunk );
						$f['bytes']   += strlen( $chunk );
						if ( $cur['done'] < $cur['size'] && PB_Util::out_of_time() ) {
							break;
						}
					}
					fclose( $out ); // phpcs:ignore
					if ( $cur['done'] < $cur['size'] ) {
						break; // Resume this file next request.
					}
					if ( $cur['mtime'] ) {
						@touch( $cur['target'], $cur['mtime'] ); // phpcs:ignore
					}
					$f['count']++;
				}
			}
			$f['pos'] = $cur['data'] + $cur['size'];
			unset( $cur );
			$f['cur'] = null;
		}
		fclose( $fh ); // phpcs:ignore
		if ( function_exists( 'opcache_reset' ) && $done ) {
			@opcache_reset(); // phpcs:ignore
		}
		return $done;
	}

	/**
	 * Final step: fix prefix-dependent rows, keep this site's URL and ProBackup
	 * settings, then atomically swap the temporary tables in.
	 *
	 * @param array $job Job.
	 */
	private static function swap( &$job ) {
		global $wpdb;
		$tmp  = $job['tmp_prefix'];
		$live = $job['live'];
		$src  = $job['manifest']['prefix'];
		$opt  = PB_Util::ident( $tmp . 'options' );
		$um   = PB_Util::ident( $tmp . 'usermeta' );
		$has  = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tmp ) . '%' ) ); // phpcs:ignore

		if ( ! in_array( $tmp . 'options', $has, true ) ) {
			$job['error'] = array(
				'stage'   => 'swap',
				'message' => __( 'The backup does not contain an options table, so it cannot be activated.', 'probackup' ),
			);
			return;
		}

		if ( $src !== $live ) {
			PB_Util::raw_query( "UPDATE $opt SET option_name = '" . PB_Util::esc_sql_value( $live . 'user_roles' ) . "' WHERE option_name = '" . PB_Util::esc_sql_value( $src . 'user_roles' ) . "'" );
			if ( in_array( $tmp . 'usermeta', $has, true ) ) {
				PB_Util::raw_query( "UPDATE $um SET meta_key = CONCAT('" . PB_Util::esc_sql_value( $live ) . "', SUBSTRING(meta_key, " . ( strlen( $src ) + 1 ) . ")) WHERE meta_key LIKE '" . PB_Util::esc_sql_value( PB_Util::esc_like( $src ) ) . "%'" );
			}
		}

		// Keep this site's address and ProBackup's own settings.
		$keep = array(
			'siteurl'                    => get_option( 'siteurl' ),
			'home'                       => get_option( 'home' ),
			PB_Storage::OPTION_DIR      => get_option( PB_Storage::OPTION_DIR ),
			PB_Storage::OPTION_SETTINGS => maybe_serialize( PB_Storage::settings() ),
		);
		foreach ( $keep as $name => $value ) {
			PB_Util::raw_query( "DELETE FROM $opt WHERE option_name = '" . PB_Util::esc_sql_value( $name ) . "'" );
			PB_Util::raw_query( "INSERT INTO $opt (option_name, option_value, autoload) VALUES ('" . PB_Util::esc_sql_value( $name ) . "', '" . PB_Util::esc_sql_value( $value ) . "', 'yes')" );
		}
		PB_Util::raw_query( "DELETE FROM $opt WHERE option_name IN ('rewrite_rules','cron') OR option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" );

		// Make sure ProBackup stays active.
		$active = $wpdb->get_var( "SELECT option_value FROM $opt WHERE option_name = 'active_plugins'" ); // phpcs:ignore
		$list   = is_string( $active ) ? @unserialize( $active, array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore
		$list   = is_array( $list ) ? array_values( $list ) : array();
		if ( ! in_array( PROBACKUP_BASENAME, $list, true ) ) {
			$list[] = PROBACKUP_BASENAME;
			PB_Util::raw_query( "DELETE FROM $opt WHERE option_name = 'active_plugins'" );
			PB_Util::raw_query( "INSERT INTO $opt (option_name, option_value, autoload) VALUES ('active_plugins', '" . PB_Util::esc_sql_value( serialize( $list ) ) . "', 'yes')" ); // phpcs:ignore
		}

		// Atomic swap.
		$old_prefix = 'pbo' . PB_Util::rand_id( 4 ) . '_';
		$live_list  = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $live ) . '%' ) ); // phpcs:ignore
		$renames    = array();
		$olds       = array();
		foreach ( $live_list as $t ) {
			$short = substr( $t, strlen( $live ) );
			$new   = $old_prefix . $short;
			if ( strlen( $new ) > 64 ) {
				$new = $old_prefix . substr( md5( $short ), 0, 24 );
			}
			$renames[] = PB_Util::ident( $t ) . ' TO ' . PB_Util::ident( $new );
			$olds[]    = $new;
		}
		foreach ( $has as $t ) {
			$renames[] = PB_Util::ident( $t ) . ' TO ' . PB_Util::ident( $live . substr( $t, strlen( $tmp ) ) );
		}
		list( $ok, $err ) = PB_Util::raw_query( 'RENAME TABLE ' . implode( ', ', $renames ) );
		if ( ! $ok ) {
			$job['error'] = array(
				'stage'   => 'swap',
				'message' => __( 'Could not activate the imported tables: ', 'probackup' ) . $err,
			);
			return;
		}
		foreach ( $olds as $t ) {
			PB_Util::raw_query( 'DROP TABLE IF EXISTS ' . PB_Util::ident( $t ) );
		}
		wp_cache_flush();
		$job['stage'] = 'done';
		PB_Export::log( $job, __( 'Migration complete! Please log in with the username and password of the SOURCE site.', 'probackup' ) );
	}
}
