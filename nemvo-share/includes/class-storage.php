<?php
/**
 * Where shared files live on disk, and how they are encrypted.
 *
 * @package NemvoShare
 */

namespace NemvoShare;

defined( 'ABSPATH' ) || exit;

/**
 * File storage.
 *
 * Files are kept in a folder with an unguessable name inside wp-content/uploads (or the
 * folder named by the NEMVO_SHARE_STORAGE constant). The folder refuses web requests on
 * Apache and IIS, every file has a random name without an extension, and the contents
 * are encrypted in 256 KiB blocks with XChaCha20-Poly1305 and a key per file. A file
 * fetched past the web server's rules is therefore useless, and deleting the file's key
 * from the database makes the file unreadable even if a copy survives in a backup.
 *
 * Blocks are encrypted independently, so downloads can resume (HTTP Range) without
 * decrypting from the start.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions -- files are streamed in blocks, which WP_Filesystem can't do.
 */
class Storage {

	const BLOCK         = 262144;
	const TAG           = 16;
	const SUFFIX_OPTION = 'nemvo_share_storage_suffix';
	const PROBE_OPTION  = 'nemvo_share_probe';

	/**
	 * The storage folder, without a trailing slash.
	 *
	 * @return string
	 */
	public static function base_dir() {
		if ( defined( 'NEMVO_SHARE_STORAGE' ) && '' !== (string) NEMVO_SHARE_STORAGE ) {
			return untrailingslashit( (string) NEMVO_SHARE_STORAGE );
		}
		$suffix = get_option( self::SUFFIX_OPTION );
		if ( ! $suffix ) {
			$suffix = strtolower( Crypto::random_string( 16, 'abcdefghijklmnopqrstuvwxyz0123456789' ) );
			add_option( self::SUFFIX_OPTION, $suffix, '', false );
		}
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( $uploads['basedir'] ) . '/nemvo-share-' . $suffix;
	}

	/**
	 * Folder for finished files.
	 *
	 * @return string
	 */
	public static function files_dir() {
		return self::base_dir() . '/files';
	}

	/**
	 * Folder for uploads in progress.
	 *
	 * @return string
	 */
	public static function tmp_dir() {
		return self::base_dir() . '/tmp';
	}

	/**
	 * Creates the folders and the files that keep the web server out.
	 *
	 * @return bool Whether the folders are usable.
	 */
	public static function ensure() {
		$base = self::base_dir();
		foreach ( array( $base, $base . '/files', $base . '/tmp' ) as $dir ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return false;
			}
			if ( ! file_exists( $dir . '/index.php' ) ) {
				file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			}
			if ( ! file_exists( $dir . '/.htaccess' ) ) {
				file_put_contents(
					$dir . '/.htaccess',
					"# Nemvo Share: these files are only handed out through WordPress.\n"
					. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
					. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"
				);
			}
			if ( ! file_exists( $dir . '/web.config' ) ) {
				file_put_contents(
					$dir . '/web.config',
					"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n"
				);
			}
		}
		return is_writable( $base . '/files' ) && is_writable( $base . '/tmp' );
	}

	/**
	 * Full path of a stored file.
	 *
	 * @param string $stored Stored name.
	 * @return string
	 */
	public static function path( $stored ) {
		// Only names made by new_name(); anything else points at a file that never exists.
		$stored = (string) $stored;
		return self::files_dir() . '/' . ( preg_match( '/^[a-z0-9]{32}\.bin$/', $stored ) ? $stored : 'invalid.bin' );
	}

	/**
	 * A new random stored name.
	 *
	 * @return string
	 */
	public static function new_name() {
		return strtolower( Crypto::random_string( 32, 'abcdefghijklmnopqrstuvwxyz0123456789' ) ) . '.bin';
	}

	/**
	 * Deletes a stored file.
	 *
	 * @param string $stored Stored name.
	 */
	public static function delete( $stored ) {
		$path = self::path( $stored );
		if ( '' !== (string) $stored && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Free space on the storage disk, if PHP may tell.
	 *
	 * @return int|null Bytes.
	 */
	public static function free_space() {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}
		$free = @disk_free_space( self::base_dir() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir may forbid it.
		return false === $free ? null : (int) $free;
	}

	/**
	 * Total size of the stored files.
	 *
	 * @return int Bytes.
	 */
	public static function used_space() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(size), 0) FROM %i', Install::table( 'files' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/*
	 * Encryption
	 */

	/**
	 * A new key for one file.
	 *
	 * @return array{0:string,1:string,2:string} Key, nonce prefix, and both encrypted for the database.
	 */
	public static function new_key() {
		$key    = random_bytes( 32 );
		$prefix = random_bytes( 16 );
		return array( $key, $prefix, Crypto::encrypt( $key . $prefix, 'file-key' ) );
	}

	/**
	 * Opens a file key from the database.
	 *
	 * @param string $key_enc Encrypted key.
	 * @return array{0:string,1:string}|null Key and nonce prefix.
	 */
	public static function open_key( $key_enc ) {
		$raw = Crypto::decrypt( $key_enc, 'file-key' );
		if ( ! is_string( $raw ) || 48 !== strlen( $raw ) ) {
			return null;
		}
		return array( substr( $raw, 0, 32 ), substr( $raw, 32 ) );
	}

	/**
	 * A 64-bit big-endian number.
	 *
	 * @param int $n Number.
	 * @return string
	 */
	private static function u64( $n ) {
		$n = (int) $n;
		return pack( 'NN', (int) floor( $n / 4294967296 ), $n % 4294967296 );
	}

	/**
	 * Encrypts one block. The block number and the file size are authenticated, so blocks
	 * can't be reordered, swapped between files or cut off.
	 *
	 * @param string $plain  Plain block (BLOCK bytes, the last one may be shorter).
	 * @param int    $index  Block number.
	 * @param int    $size   Plain file size.
	 * @param string $key    File key.
	 * @param string $prefix Nonce prefix.
	 * @return string
	 */
	public static function encrypt_block( $plain, $index, $size, $key, $prefix ) {
		Crypto::load();
		return sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plain, 'nvs1' . self::u64( $size ) . self::u64( $index ), $prefix . self::u64( $index ), $key );
	}

	/**
	 * Decrypts one block.
	 *
	 * @param string $cipher Encrypted block.
	 * @param int    $index  Block number.
	 * @param int    $size   Plain file size.
	 * @param string $key    File key.
	 * @param string $prefix Nonce prefix.
	 * @return string|false
	 */
	public static function decrypt_block( $cipher, $index, $size, $key, $prefix ) {
		Crypto::load();
		try {
			return sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $cipher, 'nvs1' . self::u64( $size ) . self::u64( $index ), $prefix . self::u64( $index ), $key );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Size of a file on disk for a plain size.
	 *
	 * @param int $size Plain size.
	 * @return int
	 */
	public static function stored_size( $size ) {
		return (int) $size + (int) ceil( $size / self::BLOCK ) * self::TAG;
	}

	/**
	 * Encrypts a plain file into the storage folder.
	 *
	 * @param string $source Plain file path.
	 * @param string $target Encrypted file path.
	 * @param string $key    File key.
	 * @param string $prefix Nonce prefix.
	 * @return int|false Plain size, or false on failure.
	 */
	public static function encrypt_file( $source, $target, $key, $prefix ) {
		$size = filesize( $source );
		$in   = fopen( $source, 'rb' );
		$out  = fopen( $target, 'wb' );
		if ( false === $size || ! $in || ! $out ) {
			return false;
		}
		$index = 0;
		$done  = 0;
		while ( $done < $size ) {
			$plain = fread( $in, (int) min( self::BLOCK, $size - $done ) );
			if ( false === $plain || '' === $plain ) {
				break;
			}
			// fread() on a plain file returns the whole block, but don't rely on it.
			$want = (int) min( self::BLOCK, $size - $done );
			$have = strlen( $plain );
			while ( $have < $want && ! feof( $in ) ) {
				$more = fread( $in, $want - $have );
				if ( false === $more || '' === $more ) {
					break;
				}
				$plain .= $more;
				$have  += strlen( $more );
			}
			fwrite( $out, self::encrypt_block( $plain, $index, $size, $key, $prefix ) );
			$done += strlen( $plain );
			++$index;
		}
		fclose( $in );
		fclose( $out );
		return $done === $size ? (int) $size : false;
	}

	/**
	 * Sends plain bytes $start..$end (inclusive) of an encrypted file to the browser.
	 *
	 * @param string $path   Encrypted file path.
	 * @param int    $size   Plain size.
	 * @param string $key    File key.
	 * @param string $prefix Nonce prefix.
	 * @param int    $start  First byte.
	 * @param int    $end    Last byte.
	 * @return bool Whether everything was sent (false when decryption failed or the visitor left).
	 */
	public static function stream( $path, $size, $key, $prefix, $start, $end ) {
		if ( $size <= 0 ) {
			return true;
		}
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return false;
		}
		$first = (int) floor( $start / self::BLOCK );
		$last  = (int) floor( $end / self::BLOCK );
		fseek( $handle, $first * ( self::BLOCK + self::TAG ) );
		for ( $index = $first; $index <= $last; $index++ ) {
			$plain_len = (int) min( self::BLOCK, $size - $index * self::BLOCK );
			$want      = $plain_len + self::TAG;
			$cipher    = '';
			$have      = 0;
			while ( $have < $want && ! feof( $handle ) ) {
				$chunk = fread( $handle, $want - $have );
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				$cipher .= $chunk;
				$have   += strlen( $chunk );
			}
			$plain = self::decrypt_block( $cipher, $index, $size, $key, $prefix );
			if ( false === $plain ) {
				fclose( $handle );
				return false;
			}
			$from = $index === $first ? $start - $index * self::BLOCK : 0;
			$to   = $index === $last ? $end - $index * self::BLOCK : $plain_len - 1;
			echo substr( $plain, $from, $to - $from + 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file contents sent as a download.
			if ( ob_get_level() > 0 ) {
				ob_flush();
			}
			flush();
			if ( connection_aborted() ) {
				fclose( $handle );
				return false;
			}
		}
		fclose( $handle );
		return true;
	}

	/*
	 * Protection check
	 */

	/**
	 * URL of the storage folder, when it is inside the web root.
	 *
	 * @return string|null
	 */
	public static function base_url() {
		$base    = wp_normalize_path( self::base_dir() );
		$uploads = wp_upload_dir( null, false );
		$updir   = wp_normalize_path( untrailingslashit( $uploads['basedir'] ) );
		if ( 0 === strpos( $base, $updir . '/' ) ) {
			return untrailingslashit( $uploads['baseurl'] ) . substr( $base, strlen( $updir ) );
		}
		$root = wp_normalize_path( untrailingslashit( ABSPATH ) );
		if ( 0 === strpos( $base, $root . '/' ) ) {
			return untrailingslashit( site_url() ) . substr( $base, strlen( $root ) );
		}
		return null;
	}

	/**
	 * Checks whether the web server hands out files from the storage folder directly.
	 *
	 * @param bool $force Check now instead of using the result from the last day.
	 * @return array{status:string,checked:int,detail:string} status is protected, outside, exposed or unknown.
	 */
	public static function probe( $force = false ) {
		$cached = get_option( self::PROBE_OPTION );
		if ( ! $force && is_array( $cached ) && isset( $cached['checked'] ) && $cached['checked'] > time() - DAY_IN_SECONDS ) {
			return $cached;
		}

		$result = array(
			'status'  => 'unknown',
			'checked' => time(),
			'detail'  => '',
		);
		$url    = self::base_url();
		if ( null === $url ) {
			$result['status'] = 'outside';
		} elseif ( self::ensure() ) {
			$secret = Crypto::token();
			$file   = self::files_dir() . '/probe.txt';
			file_put_contents( $file, $secret );
			// A request to this site itself. Like core's own loopback checks, it skips TLS
			// verification for local certificates unless https_local_ssl_verify says otherwise.
			$response = wp_remote_get(
				$url . '/files/probe.txt',
				array(
					'timeout'     => 10,
					'redirection' => 0,
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
				)
			);
			wp_delete_file( $file );
			if ( is_wp_error( $response ) ) {
				$result['detail'] = $response->get_error_message();
			} else {
				$code             = (int) wp_remote_retrieve_response_code( $response );
				$exposed          = 200 === $code && false !== strpos( (string) wp_remote_retrieve_body( $response ), $secret );
				$result['status'] = $exposed ? 'exposed' : 'protected';
				/* translators: %d: HTTP status code. */
				$result['detail'] = sprintf( __( 'HTTP %d', 'nemvo-share' ), $code );
			}
		}
		update_option( self::PROBE_OPTION, $result, false );
		return $result;
	}

	/**
	 * Deletes stored files that no share refers to (left behind by an interrupted save).
	 * Only files older than a day are touched, so a save in progress is never affected.
	 */
	public static function sweep_orphans() {
		global $wpdb;
		$dir = self::files_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$known = array_flip( $wpdb->get_col( $wpdb->prepare( "SELECT stored FROM %i WHERE stored <> ''", Install::table( 'files' ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) glob( $dir . '/*.bin' ) as $path ) {
			if ( $path && ! isset( $known[ basename( $path ) ] ) && filemtime( $path ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Removes the storage folder and everything in it (uninstall).
	 */
	public static function remove_all() {
		$base = self::base_dir();
		if ( ! is_dir( $base ) ) {
			return;
		}
		// Only what this plugin created, in case NEMVO_SHARE_STORAGE points at a shared folder.
		foreach ( array( 'files', 'tmp' ) as $sub ) {
			foreach ( (array) glob( $base . '/' . $sub . '/*' ) as $path ) {
				if ( $path && is_file( $path ) ) {
					wp_delete_file( $path );
				}
			}
			foreach ( array( '.htaccess', 'web.config' ) as $hidden ) {
				if ( is_file( $base . '/' . $sub . '/' . $hidden ) ) {
					wp_delete_file( $base . '/' . $sub . '/' . $hidden );
				}
			}
			if ( is_dir( $base . '/' . $sub ) ) {
				rmdir( $base . '/' . $sub ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
		}
		foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $own ) {
			if ( is_file( $base . '/' . $own ) ) {
				wp_delete_file( $base . '/' . $own );
			}
		}
		if ( ! defined( 'NEMVO_SHARE_STORAGE' ) && ! glob( $base . '/*' ) ) {
			rmdir( $base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}
}
