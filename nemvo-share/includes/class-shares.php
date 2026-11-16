<?php
/**
 * Shares: storage, lookup and life cycle.
 *
 * @package NemvoShare
 */

namespace NemvoShare;

defined( 'ABSPATH' ) || exit;

/**
 * Data access for shares, their files and their activity log.
 *
 * Statuses: active, disabled, expired, burned. A share with burn after reading that has
 * been opened but not finished yet is still active, with opened_at set.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own tables; results that matter for access are never cached.
 */
class Shares {

	/**
	 * Most activity entries kept per share.
	 */
	const LOG_LIMIT = 200;

	/**
	 * One share.
	 *
	 * @param int $id Share ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Install::table( 'shares' ), $id ) );
		return $row ? $row : null;
	}

	/**
	 * Finds a share by its link token.
	 *
	 * @param string $token Token from the URL.
	 * @return object|null
	 */
	public static function find_by_token( $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[A-Za-z0-9]{16,64}$/', (string) $token ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s', Install::table( 'shares' ), Crypto::token_hash( $token ) ) );
		return $row ? $row : null;
	}

	/**
	 * Files of a share.
	 *
	 * @param int $share_id Share ID.
	 * @return object[]
	 */
	public static function files( $share_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE share_id = %d ORDER BY id', Install::table( 'files' ), $share_id ) );
	}

	/**
	 * One file of a share.
	 *
	 * @param int $share_id Share ID.
	 * @param int $file_id  File ID.
	 * @return object|null
	 */
	public static function file( $share_id, $file_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND share_id = %d', Install::table( 'files' ), $file_id, $share_id ) );
		return $row ? $row : null;
	}

	/**
	 * Creates a share.
	 *
	 * @param array $data title, note, password, expires_at (UTC datetime or null), burn, auto_delete, notify.
	 * @return int Share ID, 0 on failure.
	 */
	public static function create( array $data ) {
		global $wpdb;
		$token    = Crypto::token();
		$now      = Plugin::now();
		$password = isset( $data['password'] ) ? (string) $data['password'] : '';
		$note     = isset( $data['note'] ) ? (string) $data['note'] : '';
		$ok       = $wpdb->insert(
			Install::table( 'shares' ),
			array(
				'token_hash'    => Crypto::token_hash( $token ),
				'token_enc'     => Crypto::encrypt( $token, 'token' ),
				'title'         => isset( $data['title'] ) ? (string) $data['title'] : '',
				'note_enc'      => Crypto::encrypt( $note, 'note' ),
				'has_note'      => '' !== trim( $note ) ? 1 : 0,
				'password_hash' => '' !== $password ? wp_hash_password( $password ) : '',
				'password_enc'  => Crypto::encrypt( $password, 'password' ),
				'expires_at'    => empty( $data['expires_at'] ) ? null : $data['expires_at'],
				'burn'          => empty( $data['burn'] ) ? 0 : 1,
				'auto_delete'   => empty( $data['auto_delete'] ) ? 0 : 1,
				'notify'        => empty( $data['notify'] ) ? 0 : 1,
				'status'        => 'active',
				'created_by'    => get_current_user_id(),
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		if ( ! $ok ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		self::log( $id, 'created' );
		return $id;
	}

	/**
	 * Updates a share's settings and contents.
	 *
	 * @param int   $id   Share ID.
	 * @param array $data Any of title, note, password (null = keep), expires_at, burn, auto_delete, notify.
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$row = array( 'updated_at' => Plugin::now() );
		foreach ( array( 'title', 'burn', 'auto_delete', 'notify' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$row[ $key ] = 'title' === $key ? (string) $data[ $key ] : ( empty( $data[ $key ] ) ? 0 : 1 );
			}
		}
		if ( array_key_exists( 'expires_at', $data ) ) {
			$row['expires_at'] = empty( $data['expires_at'] ) ? null : $data['expires_at'];
		}
		if ( array_key_exists( 'note', $data ) ) {
			$row['note_enc'] = Crypto::encrypt( (string) $data['note'], 'note' );
			$row['has_note'] = '' !== trim( (string) $data['note'] ) ? 1 : 0;
		}
		if ( array_key_exists( 'password', $data ) && null !== $data['password'] ) {
			$password            = (string) $data['password'];
			$row['password_hash'] = '' !== $password ? wp_hash_password( $password ) : '';
			$row['password_enc']  = Crypto::encrypt( $password, 'password' );
		}
		$wpdb->update( Install::table( 'shares' ), $row, array( 'id' => $id ) );
	}

	/**
	 * Gives a share a new link. The old link stops working at once.
	 *
	 * @param int $id Share ID.
	 */
	public static function regenerate_token( $id ) {
		global $wpdb;
		$token = Crypto::token();
		$wpdb->update(
			Install::table( 'shares' ),
			array(
				'token_hash' => Crypto::token_hash( $token ),
				'token_enc'  => Crypto::encrypt( $token, 'token' ),
				'updated_at' => Plugin::now(),
			),
			array( 'id' => $id )
		);
		self::log( $id, 'link_changed' );
	}

	/**
	 * Sets the status (disabled, active).
	 *
	 * @param int    $id     Share ID.
	 * @param string $status New status.
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		$row = array(
			'status'     => $status,
			'updated_at' => Plugin::now(),
		);
		if ( 'active' === $status ) {
			// Reactivating starts over: burn after reading can be opened once more.
			$row['ended_at']      = null;
			$row['opened_at']     = null;
			$row['claim_hash']    = '';
			$row['burn_deadline'] = null;
		}
		$wpdb->update( Install::table( 'shares' ), $row, array( 'id' => $id ) );
		self::log( $id, 'active' === $status ? 'enabled' : 'disabled' );
	}

	/**
	 * Deletes a share with its files and log.
	 *
	 * @param int $id Share ID.
	 */
	public static function delete( $id ) {
		global $wpdb;
		foreach ( self::files( $id ) as $file ) {
			Storage::delete( $file->stored );
		}
		$wpdb->delete( Install::table( 'files' ), array( 'share_id' => $id ) );
		$wpdb->delete( Install::table( 'log' ), array( 'share_id' => $id ) );
		$wpdb->delete( Install::table( 'shares' ), array( 'id' => $id ) );
	}

	/**
	 * Adds a finished chunked upload to a share.
	 *
	 * @param int    $share_id  Share ID.
	 * @param string $upload_id Upload ID.
	 * @return bool
	 */
	public static function attach_upload( $share_id, $upload_id ) {
		if ( ! self::get( $share_id ) ) {
			return false;
		}
		$file = Uploads::claim( $upload_id, get_current_user_id() );
		if ( ! $file ) {
			return false;
		}
		return self::insert_file( $share_id, $file );
	}

	/**
	 * Encrypts a plain file (a classic form upload or a WP-CLI file) into a share.
	 *
	 * @param int    $share_id Share ID.
	 * @param string $path     Plain file.
	 * @param string $name     File name to show.
	 * @param string $mime     MIME type.
	 * @return bool
	 */
	public static function add_file_from_path( $share_id, $path, $name, $mime = '' ) {
		if ( ! self::get( $share_id ) || ! Storage::ensure() || ! is_readable( $path ) ) {
			return false;
		}
		$plain = (int) filesize( $path );
		$max   = Settings::max_file_bytes();
		$free  = Storage::free_space();
		if ( ( $max > 0 && $plain > $max ) || ( null !== $free && Storage::stored_size( $plain ) + 50 * MB_IN_BYTES > $free ) ) {
			return false;
		}
		list( $key, $prefix, $key_enc ) = Storage::new_key();
		$stored                         = Storage::new_name();
		$size                           = Storage::encrypt_file( $path, Storage::path( $stored ), $key, $prefix );
		if ( false === $size ) {
			Storage::delete( $stored );
			return false;
		}
		return self::insert_file(
			$share_id,
			array(
				'name'    => $name,
				'size'    => $size,
				'mime'    => $mime,
				'stored'  => $stored,
				'key_enc' => $key_enc,
			)
		);
	}

	/**
	 * Inserts a file row.
	 *
	 * @param int   $share_id Share ID.
	 * @param array $file     name, size, mime, stored, key_enc.
	 * @return bool
	 */
	private static function insert_file( $share_id, array $file ) {
		global $wpdb;
		$ok = $wpdb->insert(
			Install::table( 'files' ),
			array(
				'share_id'   => $share_id,
				'name'       => self::clean_name( $file['name'] ),
				'size'       => (int) $file['size'],
				'mime'       => substr( sanitize_mime_type( (string) $file['mime'] ), 0, 150 ),
				'stored'     => $file['stored'],
				'key_enc'    => $file['key_enc'],
				'created_at' => Plugin::now(),
			)
		);
		if ( ! $ok ) {
			Storage::delete( $file['stored'] );
			return false;
		}
		self::log( $share_id, 'file_added', self::clean_name( $file['name'] ) );
		return true;
	}

	/**
	 * Removes one file from a share.
	 *
	 * @param int $share_id Share ID.
	 * @param int $file_id  File ID.
	 */
	public static function remove_file( $share_id, $file_id ) {
		global $wpdb;
		$file = self::file( $share_id, $file_id );
		if ( ! $file ) {
			return;
		}
		Storage::delete( $file->stored );
		$wpdb->delete( Install::table( 'files' ), array( 'id' => $file->id ) );
		self::log( $share_id, 'file_removed', $file->name );
	}

	/**
	 * A file name that is safe to show and to send in a header, close to the original.
	 *
	 * @param string $name Original name.
	 * @return string
	 */
	public static function clean_name( $name ) {
		$name = wp_basename( str_replace( '\\', '/', (string) $name ) );
		$name = preg_replace( '/[\x00-\x1F\x7F"]+/u', '', $name );
		$name = trim( wp_check_invalid_utf8( $name, true ) );
		if ( function_exists( 'mb_substr' ) ) {
			$name = mb_substr( $name, 0, 200 );
		}
		return '' === $name ? 'file' : $name;
	}

	/* ------------------------------------------------------------------ */
	/* Secrets                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * The link token of a share.
	 *
	 * @param object $share Share row.
	 * @return string|null Null when it can't be decrypted (encryption key lost).
	 */
	public static function token( $share ) {
		$token = Crypto::decrypt( $share->token_enc, 'token' );
		return ( is_string( $token ) && '' !== $token ) ? $token : null;
	}

	/**
	 * The secret link of a share.
	 *
	 * @param object $share Share row.
	 * @return string|null
	 */
	public static function url( $share ) {
		$token = self::token( $share );
		return null === $token ? null : Frontend::share_url( $token );
	}

	/**
	 * The password, for showing to the administrator.
	 *
	 * @param object $share Share row.
	 * @return string|null '' without a password, null when it can't be decrypted.
	 */
	public static function password( $share ) {
		if ( '' === $share->password_hash ) {
			return '';
		}
		$password = Crypto::decrypt( $share->password_enc, 'password' );
		return ( is_string( $password ) && '' !== $password ) ? $password : null;
	}

	/**
	 * The note.
	 *
	 * @param object $share Share row.
	 * @return string|null
	 */
	public static function note( $share ) {
		return Crypto::decrypt( $share->note_enc, 'note' );
	}

	/* ------------------------------------------------------------------ */
	/* Life cycle                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Applies expiry and the burn window, and returns the up-to-date share.
	 *
	 * @param object $share Share row.
	 * @return object
	 */
	public static function refresh( $share ) {
		if ( 'active' !== $share->status ) {
			return $share;
		}
		$now     = time();
		$expires = Plugin::ts( $share->expires_at );
		if ( $expires && $expires <= $now ) {
			self::end( $share, 'expired' );
			return self::get( $share->id );
		}
		$deadline = Plugin::ts( $share->burn_deadline );
		if ( $share->burn && $deadline && $deadline <= $now ) {
			self::end( $share, 'burned' );
			return self::get( $share->id );
		}
		return $share;
	}

	/**
	 * Ends a share (expired or burned) and removes its contents when the share says so.
	 *
	 * @param object $share  Share row.
	 * @param string $status expired or burned.
	 */
	public static function end( $share, $status ) {
		global $wpdb;
		$changed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, ended_at = %s WHERE id = %d AND status = %s',
				Install::table( 'shares' ),
				$status,
				Plugin::now(),
				$share->id,
				'active'
			)
		);
		if ( ! $changed ) {
			return; // Someone else ended it first.
		}
		self::log( $share->id, $status );

		/**
		 * Fires when a share expires or is burned after reading.
		 *
		 * @param object $share  The share as it was before it ended.
		 * @param string $status expired or burned.
		 */
		do_action( 'nemvo_share_ended', $share, $status );

		if ( $share->auto_delete ) {
			self::purge( $share->id );
		}
	}

	/**
	 * Removes a share's contents: files, note and password. The record stays, so the
	 * administrator can see what happened.
	 *
	 * @param int $id Share ID.
	 */
	public static function purge( $id ) {
		global $wpdb;
		foreach ( self::files( $id ) as $file ) {
			Storage::delete( $file->stored );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET stored = '', key_enc = '' WHERE share_id = %d", Install::table( 'files' ), $id ) );
		$wpdb->update(
			Install::table( 'shares' ),
			array(
				'note_enc'     => '',
				'password_enc' => '',
				'purged_at'    => Plugin::now(),
			),
			array( 'id' => $id )
		);
		self::log( $id, 'purged' );
	}

	/**
	 * Marks a burn-after-reading share as opened by one browser.
	 *
	 * @param object $share Share row.
	 * @param string $claim Secret kept in that browser's cookie.
	 * @return bool False if another browser opened it first.
	 */
	public static function claim( $share, $claim ) {
		global $wpdb;
		$deadline = Plugin::now( (int) Settings::get( 'burn_window' ) * MINUTE_IN_SECONDS );
		$expires  = Plugin::ts( $share->expires_at );
		if ( $expires && $expires < Plugin::ts( $deadline ) ) {
			$deadline = $share->expires_at;
		}
		$changed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET opened_at = %s, claim_hash = %s, burn_deadline = %s WHERE id = %d AND opened_at IS NULL AND status = %s',
				Install::table( 'shares' ),
				Plugin::now(),
				hash( 'sha256', $claim ),
				$deadline,
				$share->id,
				'active'
			)
		);
		if ( $changed ) {
			self::log( $share->id, 'opened' );
		}
		return (bool) $changed;
	}

	/**
	 * Counts a view of the contents.
	 *
	 * @param object $share Share row.
	 */
	public static function count_view( $share ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET views = views + 1, last_access = %s WHERE id = %d', Install::table( 'shares' ), Plugin::now(), $share->id ) );
		self::log( $share->id, 'viewed' );
		if ( 0 === (int) $share->views ) {
			self::notify( $share );
		}
	}

	/**
	 * Counts a completed download, and burns the share once every file has been downloaded.
	 *
	 * @param object $share Share row.
	 * @param object $file  File row.
	 */
	public static function count_download( $share, $file ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET downloads = downloads + 1, last_download = %s WHERE id = %d', Install::table( 'files' ), Plugin::now(), $file->id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET downloads = downloads + 1, last_access = %s WHERE id = %d', Install::table( 'shares' ), Plugin::now(), $share->id ) );
		self::log( $share->id, 'downloaded', $file->name );

		/**
		 * Fires after a file has been downloaded completely.
		 *
		 * @param object $share Share row.
		 * @param object $file  File row.
		 */
		do_action( 'nemvo_share_downloaded', $share, $file );

		if ( $share->burn && $share->opened_at ) {
			// Only downloads since this opening count, so a reopened share starts over.
			$left = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE share_id = %d AND stored <> '' AND ( last_download IS NULL OR last_download < %s )",
					Install::table( 'files' ),
					$share->id,
					$share->opened_at
				)
			);
			if ( 0 === $left ) {
				self::end( $share, 'burned' );
			}
		}
	}

	/**
	 * Emails the creator the first time a share is opened, when the share asks for it.
	 *
	 * @param object $share Share row.
	 */
	public static function notify( $share ) {
		if ( ! $share->notify ) {
			return;
		}
		$user = get_userdata( (int) $share->created_by );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: 1: site name, 2: share title. */
		$subject = sprintf( __( '[%1$s] "%2$s" was opened', 'nemvo-share' ), $site, $share->title );
		$lines   = array(
			/* translators: %s: share title. */
			sprintf( __( 'Your share "%s" was just opened for the first time.', 'nemvo-share' ), $share->title ),
			'',
			/* translators: %s: date and time. */
			sprintf( __( 'Opened: %s', 'nemvo-share' ), Plugin::format_datetime( time() ) ),
			/* translators: %s: URL. */
			sprintf( __( 'Details: %s', 'nemvo-share' ), admin_url( 'admin.php?page=nemvo-share&action=edit&share=' . (int) $share->id ) ),
		);
		wp_mail( $user->user_email, $subject, implode( "\n", $lines ) );
	}

	/* ------------------------------------------------------------------ */
	/* Activity log                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Records an event.
	 *
	 * @param int    $share_id Share ID.
	 * @param string $event    Event name.
	 * @param string $detail   Detail, such as a file name.
	 */
	public static function log( $share_id, $event, $detail = '' ) {
		global $wpdb;
		$table = Install::table( 'log' );
		$wpdb->insert(
			$table,
			array(
				'share_id'   => $share_id,
				'created_at' => Plugin::now(),
				'event'      => $event,
				'detail'     => function_exists( 'mb_substr' ) ? mb_substr( (string) $detail, 0, 255 ) : substr( (string) $detail, 0, 255 ),
			)
		);
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE share_id = %d', $table, $share_id ) );
		if ( $count > self::LOG_LIMIT ) {
			$cutoff = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE share_id = %d ORDER BY id DESC LIMIT 1 OFFSET %d', $table, $share_id, self::LOG_LIMIT - 1 ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE share_id = %d AND id < %d', $table, $share_id, $cutoff ) );
		}
	}

	/**
	 * Activity of a share, newest first.
	 *
	 * @param int $share_id Share ID.
	 * @param int $limit    Most entries.
	 * @return object[]
	 */
	public static function log_entries( $share_id, $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE share_id = %d ORDER BY id DESC LIMIT %d', Install::table( 'log' ), $share_id, $limit ) );
	}

	/* ------------------------------------------------------------------ */
	/* Lists                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Ends every share whose time is up. Runs from cron and before admin lists.
	 */
	public static function sweep() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s AND ( ( expires_at IS NOT NULL AND expires_at <= %s ) OR ( burn = 1 AND burn_deadline IS NOT NULL AND burn_deadline <= %s ) ) LIMIT 500',
				Install::table( 'shares' ),
				'active',
				Plugin::now(),
				Plugin::now()
			)
		);
		foreach ( $rows as $row ) {
			self::refresh( $row );
		}
	}

	/**
	 * Shares for the admin list.
	 *
	 * @param array $args status, search, orderby, order, per_page, page.
	 * @return array{items:object[],total:int}
	 */
	public static function query( array $args ) {
		global $wpdb;
		$table     = Install::table( 'shares' );
		$status    = ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) ? (string) $args['status'] : '';
		$search    = ! empty( $args['search'] ) ? '%' . $wpdb->esc_like( (string) $args['search'] ) . '%' : '';
		$orderable = array( 'title', 'created_at', 'expires_at', 'views', 'downloads', 'status' );
		$orderby   = in_array( $args['orderby'] ?? '', $orderable, true ) ? $args['orderby'] : 'created_at';
		$per_page  = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$offset    = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per_page );

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE ( %s = '' OR status = %s ) AND ( %s = '' OR title LIKE %s )",
				$table,
				$status,
				$status,
				$search,
				$search
			)
		);
		if ( 'asc' === strtolower( $args['order'] ?? '' ) ) {
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE ( %s = '' OR status = %s ) AND ( %s = '' OR title LIKE %s ) ORDER BY %i ASC, id ASC LIMIT %d OFFSET %d",
					$table,
					$status,
					$status,
					$search,
					$search,
					$orderby,
					$per_page,
					$offset
				)
			);
		} else {
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE ( %s = '' OR status = %s ) AND ( %s = '' OR title LIKE %s ) ORDER BY %i DESC, id DESC LIMIT %d OFFSET %d",
					$table,
					$status,
					$status,
					$search,
					$search,
					$orderby,
					$per_page,
					$offset
				)
			);
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Number of shares per status.
	 *
	 * @return array Status => count, plus 'all'.
	 */
	public static function counts() {
		global $wpdb;
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', Install::table( 'shares' ) ) );
		$counts = array(
			'all'      => 0,
			'active'   => 0,
			'expired'  => 0,
			'burned'   => 0,
			'disabled' => 0,
		);
		foreach ( $rows as $row ) {
			$counts[ $row->status ] = (int) $row->n;
			$counts['all']         += (int) $row->n;
		}
		return $counts;
	}

	/**
	 * File counts and total sizes for several shares at once.
	 *
	 * @param int[] $ids Share IDs.
	 * @return array Share ID => array{count:int,size:int,removed:bool}.
	 */
	public static function file_stats( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a list of %d.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT share_id, COUNT(*) AS n, SUM(size) AS bytes, SUM(stored = '') AS removed FROM %i WHERE share_id IN ({$placeholders}) GROUP BY share_id", array_merge( array( Install::table( 'files' ) ), $ids ) ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->share_id ] = array(
				'count'   => (int) $row->n,
				'size'    => (int) $row->bytes,
				'removed' => (int) $row->removed > 0,
			);
		}
		return $out;
	}

	/**
	 * Deletes records of shares that ended long ago (Settings: keep records for N days).
	 */
	public static function delete_old_records() {
		global $wpdb;
		$days = (int) Settings::get( 'keep_days' );
		if ( $days <= 0 ) {
			return;
		}
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE status IN (%s, %s) AND ended_at IS NOT NULL AND ended_at < %s LIMIT 500',
				Install::table( 'shares' ),
				'expired',
				'burned',
				Plugin::now( -$days * DAY_IN_SECONDS )
			)
		);
		foreach ( $ids as $id ) {
			self::delete( (int) $id );
		}
	}
}
