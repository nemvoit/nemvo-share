<?php
/**
 * Wires the plugin's parts into WordPress.
 *
 * @package NemvoShare
 */

namespace NemvoShare;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
class Plugin {

	/**
	 * Registers every hook. Runs on plugins_loaded.
	 */
	public static function init() {
		Install::maybe_upgrade();

		Frontend::init();
		Uploads::init();
		Cron::init();
		Health::init();
		Privacy::init();

		if ( is_admin() ) {
			Admin\Admin::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'nemvo-share', __NAMESPACE__ . '\\CLI' );
		}
	}

	/**
	 * The capability needed to manage shares and settings.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability needed to create and manage shares.
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		return (string) apply_filters( 'nemvo_share_capability', 'manage_options' );
	}

	/**
	 * Whether the current user may manage shares.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( self::capability() );
	}

	/**
	 * Current time as a MySQL UTC datetime.
	 *
	 * @param int $offset Seconds to add.
	 * @return string
	 */
	public static function now( $offset = 0 ) {
		return gmdate( 'Y-m-d H:i:s', time() + (int) $offset );
	}

	/**
	 * Converts a MySQL UTC datetime to a timestamp.
	 *
	 * @param string|null $datetime Datetime or null.
	 * @return int 0 for empty values.
	 */
	public static function ts( $datetime ) {
		if ( empty( $datetime ) || '0000-00-00 00:00:00' === $datetime ) {
			return 0;
		}
		$ts = strtotime( $datetime . ' UTC' );
		return false === $ts ? 0 : $ts;
	}

	/**
	 * A date and time in the site's format and time zone.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public static function format_datetime( $timestamp ) {
		/* translators: 1: date, 2: time. */
		return sprintf( _x( '%1$s %2$s', 'date and time', 'nemvo-share' ), wp_date( get_option( 'date_format' ), $timestamp ), wp_date( get_option( 'time_format' ), $timestamp ) );
	}

	/**
	 * "in 3 days" / "2 hours ago".
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public static function relative( $timestamp ) {
		if ( abs( $timestamp - time() ) < MINUTE_IN_SECONDS ) {
			return __( 'just now', 'nemvo-share' );
		}
		$diff = human_time_diff( time(), $timestamp );
		/* translators: %s: a time span such as "3 days". */
		return $timestamp >= time() ? sprintf( __( 'in %s', 'nemvo-share' ), $diff ) : sprintf( __( '%s ago', 'nemvo-share' ), $diff );
	}

	/**
	 * A file size such as "305 KB" or "9.4 MB" (no decimals for bytes).
	 *
	 * @param int $bytes Size.
	 * @return string
	 */
	public static function size( $bytes ) {
		$bytes = (int) $bytes;
		return (string) size_format( $bytes, $bytes >= MB_IN_BYTES ? 1 : 0 );
	}

	/**
	 * The visitor's IP address, for rate limiting only (never stored as such).
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the visitor IP address used for password rate limiting. Sites behind a
		 * reverse proxy can return the forwarded address here.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		return (string) apply_filters( 'nemvo_share_client_ip', $ip );
	}
}
