<?php
/**
 * WP-CLI commands.
 *
 * @package NemvoShare
 */

namespace NemvoShare;

defined( 'ABSPATH' ) || exit;

/**
 * Create and manage shares from the command line.
 */
class CLI {

	/**
	 * Creates a share and prints its link (and password).
	 *
	 * ## OPTIONS
	 *
	 * [--title=<title>]
	 * : Title shown to the recipient.
	 *
	 * [--note=<note>]
	 * : Text of the note. Use - to read it from standard input.
	 *
	 * [--file=<path>]
	 * : A file to share. Repeat for more files.
	 *
	 * [--password=<password>]
	 * : Password, or "auto" to generate one. Without it, anyone with the link can open the share.
	 *
	 * [--expires=<when>]
	 * : 1h, 1d, 7d, 30d, never, or a date and time in the site's time zone. Default: the setting.
	 *
	 * [--burn]
	 * : Burn after reading.
	 *
	 * [--keep]
	 * : Keep the contents when the share expires or is burned (they are removed by default).
	 *
	 * [--porcelain]
	 * : Print only the link.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nemvo-share create --title="Contract" --file=contract.pdf --password=auto --expires=7d
	 *     echo "s3cret" | wp nemvo-share create --title="Wi-Fi" --note=- --burn
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function create( $args, $assoc_args ) {
		$note = isset( $assoc_args['note'] ) ? (string) $assoc_args['note'] : '';
		if ( '-' === $note ) {
			$note = (string) file_get_contents( 'php://stdin' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		$files = array();
		if ( isset( $assoc_args['file'] ) ) {
			// WP-CLI gives the last value of a repeated option; read all of them from argv.
			global $argv;
			foreach ( (array) $argv as $arg ) {
				if ( 0 === strpos( $arg, '--file=' ) ) {
					$files[] = substr( $arg, 7 );
				}
			}
			if ( ! $files ) {
				$files[] = $assoc_args['file'];
			}
		}
		foreach ( $files as $path ) {
			if ( ! is_readable( $path ) ) {
				\WP_CLI::error( sprintf( 'Cannot read %s.', $path ) );
			}
		}

		$password = isset( $assoc_args['password'] ) ? (string) $assoc_args['password'] : '';
		if ( 'auto' === $password ) {
			$password = Crypto::password();
		}

		$expires = self::expires( isset( $assoc_args['expires'] ) ? (string) $assoc_args['expires'] : (string) Settings::get( 'default_expiry' ) );

		$id = Shares::create(
			array(
				'title'       => isset( $assoc_args['title'] ) ? sanitize_text_field( $assoc_args['title'] ) : '',
				'note'        => $note,
				'password'    => $password,
				'expires_at'  => $expires,
				'burn'        => ! empty( $assoc_args['burn'] ),
				'auto_delete' => empty( $assoc_args['keep'] ),
			)
		);
		if ( ! $id ) {
			\WP_CLI::error( 'Could not create the share.' );
		}
		foreach ( $files as $path ) {
			$mime = wp_check_filetype( $path );
			if ( ! Shares::add_file_from_path( $id, $path, wp_basename( $path ), (string) $mime['type'] ) ) {
				\WP_CLI::warning( sprintf( 'Could not add %s.', $path ) );
			}
		}

		$share = Shares::get( $id );
		if ( ! empty( $assoc_args['porcelain'] ) ) {
			\WP_CLI::line( (string) Shares::url( $share ) );
			return;
		}
		\WP_CLI::success( sprintf( 'Created share %d.', $id ) );
		\WP_CLI::line( 'Link:     ' . Shares::url( $share ) );
		if ( '' !== $password ) {
			\WP_CLI::line( 'Password: ' . $password );
		}
	}

	/**
	 * Lists shares.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : active, expired, burned or disabled.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated fields. Default: id,title,status,expires,files,views,downloads,url
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function list_( $args, $assoc_args ) {
		Shares::sweep();
		$result = Shares::query(
			array(
				'status'   => isset( $assoc_args['status'] ) ? $assoc_args['status'] : '',
				'per_page' => 1000,
			)
		);
		$stats  = Shares::file_stats( wp_list_pluck( $result['items'], 'id' ) );
		$rows   = array();
		foreach ( $result['items'] as $share ) {
			$rows[] = array(
				'id'        => (int) $share->id,
				'title'     => $share->title,
				'status'    => $share->status,
				'expires'   => $share->expires_at ? get_date_from_gmt( $share->expires_at ) : 'never',
				'burn'      => $share->burn ? 'yes' : 'no',
				'files'     => isset( $stats[ $share->id ] ) ? $stats[ $share->id ]['count'] : 0,
				'views'     => (int) $share->views,
				'downloads' => (int) $share->downloads,
				'url'       => (string) Shares::url( $share ),
				'password'  => (string) Shares::password( $share ),
			);
		}
		$fields = isset( $assoc_args['fields'] ) ? $assoc_args['fields'] : 'id,title,status,expires,files,views,downloads,url';
		\WP_CLI\Utils\format_items( $assoc_args['format'], $rows, $fields );
	}

	/**
	 * Deletes shares with their files.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Share IDs.
	 *
	 * @param array $args Share IDs.
	 */
	public function delete( $args ) {
		foreach ( $args as $id ) {
			if ( ! Shares::get( (int) $id ) ) {
				\WP_CLI::warning( sprintf( 'Share %d does not exist.', $id ) );
				continue;
			}
			Shares::delete( (int) $id );
			\WP_CLI::log( sprintf( 'Deleted share %d.', $id ) );
		}
	}

	/**
	 * Runs the clean-up now: ends expired shares, removes contents and abandoned uploads.
	 */
	public function cleanup() {
		Cron::run();
		\WP_CLI::success( 'Clean-up done.' );
	}

	/**
	 * Expiry argument to a UTC datetime.
	 *
	 * @param string $value 1h, 1d, 7d, 30d, never or a local date.
	 * @return string|null
	 */
	private static function expires( $value ) {
		if ( 'never' === $value || '' === $value ) {
			return null;
		}
		$seconds = Settings::expiry_seconds( $value );
		if ( $seconds ) {
			return Plugin::now( $seconds );
		}
		$utc = get_gmt_from_date( $value );
		// Four-digit years only: the database can't store later dates. WordPress answers an
		// unreadable date with the Unix epoch.
		if ( ! is_string( $utc ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $utc ) || in_array( $utc, array( '0000-00-00 00:00:00', '1970-01-01 00:00:00' ), true ) ) {
			\WP_CLI::error( sprintf( 'Unknown expiry: %s', $value ) );
		}
		if ( Plugin::ts( $utc ) <= time() ) {
			\WP_CLI::error( sprintf( 'The expiry must be in the future: %s', $value ) );
		}
		return $utc;
	}
}
