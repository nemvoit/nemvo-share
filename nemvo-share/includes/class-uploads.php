<?php
/**
 * Chunked uploads from the share editor.
 *
 * @package NemvoShare
 */

namespace NemvoShare;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoints that receive files in chunks.
 *
 * Each chunk is smaller than PHP's upload limit, so files of any size can be shared
 * without changing php.ini. Chunks are encrypted as they arrive; nothing plain is kept
 * on disk beyond PHP's own temporary upload file for one chunk.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions -- appending encrypted blocks needs fopen/fwrite.
 */
class Uploads {

	const NS        = 'nemvo-share/v1';
	const MAX_CHUNK = 8388608;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * REST routes.
	 */
	public static function routes() {
		$permission = array( Plugin::class, 'can_manage' );
		register_rest_route(
			self::NS,
			'/uploads',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'start' ),
				'permission_callback' => $permission,
				'args'                => array(
					'name' => array(
						'type'     => 'string',
						'required' => true,
					),
					'size' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 0,
					),
					'type' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/uploads/(?P<id>[a-f0-9]{32})',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'chunk' ),
					'permission_callback' => $permission,
					'args'                => array(
						'index' => array(
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 0,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'cancel' ),
					'permission_callback' => $permission,
				),
			)
		);
	}

	/**
	 * Bytes per chunk: as large as the server accepts in one request, in whole
	 * encryption blocks, at most 8 MB.
	 *
	 * @return int
	 */
	public static function chunk_size() {
		$limit = (int) wp_max_upload_size() - 65536;
		$size  = (int) floor( min( self::MAX_CHUNK, $limit ) / Storage::BLOCK ) * Storage::BLOCK;
		return max( Storage::BLOCK, $size );
	}

	/**
	 * Paths of an upload's state and data files.
	 *
	 * @param string $id Upload ID.
	 * @return array{0:string,1:string}
	 */
	private static function paths( $id ) {
		$id = preg_replace( '/[^a-f0-9]/', '', (string) $id );
		return array( Storage::tmp_dir() . '/' . $id . '.json', Storage::tmp_dir() . '/' . $id . '.part' );
	}

	/**
	 * Reads an upload's state.
	 *
	 * @param string $id Upload ID.
	 * @return array|null
	 */
	private static function state( $id ) {
		list( $json ) = self::paths( $id );
		if ( ! is_file( $json ) ) {
			return null;
		}
		$state = json_decode( (string) file_get_contents( $json ), true );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Writes an upload's state.
	 *
	 * @param string $id    Upload ID.
	 * @param array  $state State.
	 */
	private static function save_state( $id, array $state ) {
		list( $json ) = self::paths( $id );
		file_put_contents( $json, wp_json_encode( $state ), LOCK_EX );
	}

	/**
	 * Starts an upload.
	 *
	 * @param \WP_REST_Request $request Request with name, size and type.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function start( \WP_REST_Request $request ) {
		$size = (int) $request['size'];
		$max  = Settings::max_file_bytes();
		if ( $max > 0 && $size > $max ) {
			/* translators: %s: size such as "2 GB". */
			return new \WP_Error( 'nemvo_share_too_large', sprintf( __( 'This file is larger than the limit of %s.', 'nemvo-share' ), size_format( $max ) ), array( 'status' => 413 ) );
		}
		if ( ! Storage::ensure() ) {
			return new \WP_Error( 'nemvo_share_storage', __( 'The storage folder is not writable.', 'nemvo-share' ), array( 'status' => 500 ) );
		}
		$free = Storage::free_space();
		if ( null !== $free && Storage::stored_size( $size ) + 50 * MB_IN_BYTES > $free ) {
			return new \WP_Error( 'nemvo_share_disk_full', __( 'There is not enough free disk space on the server for this file.', 'nemvo-share' ), array( 'status' => 507 ) );
		}

		$id    = bin2hex( random_bytes( 16 ) );
		$key   = Storage::new_key();
		$state = array(
			'user'     => get_current_user_id(),
			'name'     => Shares::clean_name( $request['name'] ),
			'size'     => $size,
			'type'     => (string) $request['type'],
			'chunk'    => self::chunk_size(),
			'received' => 0,
			'key_enc'  => $key[2],
			'created'  => time(),
		);
		list( , $part ) = self::paths( $id );
		if ( false === file_put_contents( $part, '' ) ) {
			return new \WP_Error( 'nemvo_share_storage', __( 'The storage folder is not writable.', 'nemvo-share' ), array( 'status' => 500 ) );
		}
		self::save_state( $id, $state );

		return rest_ensure_response(
			array(
				'id'       => $id,
				'chunk'    => $state['chunk'],
				'received' => 0,
				'complete' => 0 === $size,
			)
		);
	}

	/**
	 * Receives one chunk.
	 *
	 * @param \WP_REST_Request $request Request with index and a "chunk" file.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function chunk( \WP_REST_Request $request ) {
		$id                 = (string) $request['id'];
		list( $json, $part ) = self::paths( $id );
		if ( ! is_file( $json ) ) {
			return new \WP_Error( 'nemvo_share_no_upload', __( 'This upload was not found. Please try again.', 'nemvo-share' ), array( 'status' => 404 ) );
		}

		// One chunk at a time per upload: the lock covers reading the state, writing the
		// data and saving the new state, so retries and duplicates can't interleave.
		$lock = fopen( $json, 'c+' );
		if ( ! $lock || ! flock( $lock, LOCK_EX ) ) {
			return new \WP_Error( 'nemvo_share_storage', __( 'The storage folder is not writable.', 'nemvo-share' ), array( 'status' => 500 ) );
		}
		$result = self::write_chunk( $request, $id, $lock, $part );
		flock( $lock, LOCK_UN );
		fclose( $lock );
		return $result;
	}

	/**
	 * Writes one chunk while the upload's lock is held.
	 *
	 * @param \WP_REST_Request $request Request with index and a "chunk" file.
	 * @param string           $id      Upload ID.
	 * @param resource         $lock    Locked handle of the state file.
	 * @param string           $part    Path of the encrypted data.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function write_chunk( \WP_REST_Request $request, $id, $lock, $part ) {
		$state = json_decode( (string) stream_get_contents( $lock, -1, 0 ), true );
		if ( ! is_array( $state ) || (int) $state['user'] !== get_current_user_id() ) {
			return new \WP_Error( 'nemvo_share_no_upload', __( 'This upload was not found. Please try again.', 'nemvo-share' ), array( 'status' => 404 ) );
		}
		$index  = (int) $request['index'];
		$chunk  = (int) $state['chunk'];
		$size   = (int) $state['size'];
		$offset = $index * $chunk;
		$next   = (int) ( $state['received'] / $chunk );

		if ( $offset < $state['received'] ) {
			// A retry of a chunk that already arrived.
			return self::progress( $id, $state );
		}
		if ( $index !== $next || $offset >= $size ) {
			return new \WP_Error( 'nemvo_share_chunk_order', __( 'A piece of the file arrived out of order. Please try again.', 'nemvo-share' ), array( 'status' => 409 ) );
		}

		$files = $request->get_file_params();
		$tmp   = isset( $files['chunk']['tmp_name'] ) ? $files['chunk']['tmp_name'] : '';
		if ( ! $tmp || ! is_uploaded_file( $tmp ) || ! empty( $files['chunk']['error'] ) ) {
			return new \WP_Error( 'nemvo_share_chunk_missing', __( 'A piece of the file did not arrive. Please try again.', 'nemvo-share' ), array( 'status' => 400 ) );
		}
		$expected = (int) min( $chunk, $size - $offset );
		$data     = file_get_contents( $tmp );
		wp_delete_file( $tmp );
		if ( false === $data || strlen( $data ) !== $expected ) {
			return new \WP_Error( 'nemvo_share_chunk_size', __( 'A piece of the file arrived incomplete. Please try again.', 'nemvo-share' ), array( 'status' => 400 ) );
		}

		$key = Storage::open_key( $state['key_enc'] );
		if ( ! $key ) {
			return new \WP_Error( 'nemvo_share_key', __( 'The encryption key for this upload is missing.', 'nemvo-share' ), array( 'status' => 500 ) );
		}
		$handle = fopen( $part, 'c' );
		if ( ! $handle ) {
			return new \WP_Error( 'nemvo_share_storage', __( 'The storage folder is not writable.', 'nemvo-share' ), array( 'status' => 500 ) );
		}
		// Write at the chunk's own position, dropping anything a broken earlier attempt left.
		$position = Storage::stored_size( $offset );
		ftruncate( $handle, $position );
		fseek( $handle, $position );
		$block = (int) ( $offset / Storage::BLOCK );
		for ( $pos = 0; $pos < $expected; $pos += Storage::BLOCK ) {
			fwrite( $handle, Storage::encrypt_block( substr( $data, $pos, Storage::BLOCK ), $block, $size, $key[0], $key[1] ) );
			++$block;
		}
		fflush( $handle );
		fclose( $handle );

		$state['received'] = $offset + $expected;
		ftruncate( $lock, 0 );
		rewind( $lock );
		fwrite( $lock, (string) wp_json_encode( $state ) );
		fflush( $lock );
		return self::progress( $id, $state );
	}

	/**
	 * Progress answer.
	 *
	 * @param string $id    Upload ID.
	 * @param array  $state State.
	 * @return \WP_REST_Response
	 */
	private static function progress( $id, array $state ) {
		return rest_ensure_response(
			array(
				'id'       => $id,
				'received' => (int) $state['received'],
				'complete' => (int) $state['received'] >= (int) $state['size'],
			)
		);
	}

	/**
	 * Cancels an upload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function cancel( \WP_REST_Request $request ) {
		$state = self::state( $request['id'] );
		if ( $state && (int) $state['user'] === get_current_user_id() ) {
			self::discard( $request['id'] );
		}
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Removes an upload's files.
	 *
	 * @param string $id Upload ID.
	 */
	private static function discard( $id ) {
		foreach ( self::paths( $id ) as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Hands a finished upload over to a share: moves it into the files folder.
	 *
	 * @param string $id      Upload ID.
	 * @param int    $user_id The user who uploaded it.
	 * @return array|null name, size, mime, stored, key_enc.
	 */
	public static function claim( $id, $user_id ) {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $id ) ) {
			return null;
		}
		$state = self::state( $id );
		if ( ! $state || (int) $state['user'] !== (int) $user_id || (int) $state['received'] < (int) $state['size'] ) {
			return null;
		}
		list( , $part ) = self::paths( $id );
		$expected       = Storage::stored_size( (int) $state['size'] );
		clearstatcache( true, $part );
		if ( ! is_file( $part ) || filesize( $part ) !== $expected ) {
			self::discard( $id );
			return null;
		}
		$stored = Storage::new_name();
		if ( ! rename( $part, Storage::path( $stored ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			return null;
		}
		self::discard( $id );
		return array(
			'name'    => $state['name'],
			'size'    => (int) $state['size'],
			'mime'    => $state['type'],
			'stored'  => $stored,
			'key_enc' => $state['key_enc'],
		);
	}

	/**
	 * Removes uploads that were never saved into a share.
	 *
	 * @param int $age Seconds after which an unfinished upload is removed.
	 */
	public static function cleanup( $age = DAY_IN_SECONDS ) {
		$dir = Storage::tmp_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) glob( $dir . '/*' ) as $path ) {
			if ( $path && preg_match( '/\/[a-f0-9]{32}\.(json|part)$/', $path ) && is_file( $path ) && filemtime( $path ) < time() - $age ) {
				wp_delete_file( $path );
			}
		}
	}
}
