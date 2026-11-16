<?php
/**
 * Plugin settings: defaults, access and sanitizing.
 *
 * @package NemvoShare
 */

namespace NemvoShare;

defined( 'ABSPATH' ) || exit;

/**
 * Settings stored in one option.
 */
class Settings {

	const OPTION = 'nemvo_share_settings';

	/**
	 * Cached settings for this request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'page_id'             => 0,
			'default_access'      => 'password',
			'default_expiry'      => '7d',
			'default_burn'        => 0,
			'default_auto_delete'       => 1,
			'default_notify'      => 0,
			'burn_window'         => 60,
			'max_file_mb'         => 2048,
			'unlock_hours'        => 12,
			'accent'              => '',
			'keep_days'           => 30,
			'delete_on_uninstall' => 0,
		);
	}

	/**
	 * Expiry choices for new shares.
	 *
	 * @return array Key => label.
	 */
	public static function expiry_choices() {
		return array(
			'1h'     => __( '1 hour', 'nemvo-share' ),
			'1d'     => __( '1 day', 'nemvo-share' ),
			'7d'     => __( '7 days', 'nemvo-share' ),
			'30d'    => __( '30 days', 'nemvo-share' ),
			'never'  => __( 'Never', 'nemvo-share' ),
			'custom' => __( 'Pick a date…', 'nemvo-share' ),
		);
	}

	/**
	 * Seconds for an expiry choice, or 0 for never.
	 *
	 * @param string $choice A key from expiry_choices().
	 * @return int
	 */
	public static function expiry_seconds( $choice ) {
		$map = array(
			'1h'  => HOUR_IN_SECONDS,
			'1d'  => DAY_IN_SECONDS,
			'7d'  => 7 * DAY_IN_SECONDS,
			'30d' => 30 * DAY_IN_SECONDS,
		);
		return isset( $map[ $choice ] ) ? $map[ $choice ] : 0;
	}

	/**
	 * All settings, merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Updates some settings.
	 *
	 * @param array $values Key => value.
	 */
	public static function update( array $values ) {
		$all = array_merge( self::all(), $values );
		update_option( self::OPTION, $all, true );
		self::$cache = null;
	}

	/**
	 * Clears the per-request cache (after the option changes through the Settings API).
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Sanitizes the settings form.
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$current  = self::all();
		$defaults = self::defaults();
		$out      = $current;

		$out['page_id']        = isset( $input['page_id'] ) ? absint( $input['page_id'] ) : $current['page_id'];
		$out['default_access'] = ( isset( $input['default_access'] ) && 'link' === $input['default_access'] ) ? 'link' : 'password';
		$expiry                = isset( $input['default_expiry'] ) ? sanitize_key( $input['default_expiry'] ) : $defaults['default_expiry'];
		$choices               = self::expiry_choices();
		unset( $choices['custom'] );
		$out['default_expiry'] = isset( $choices[ $expiry ] ) ? $expiry : $defaults['default_expiry'];

		foreach ( array( 'default_burn', 'default_auto_delete', 'default_notify', 'delete_on_uninstall' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$out['burn_window']  = isset( $input['burn_window'] ) ? min( 1440, max( 5, absint( $input['burn_window'] ) ) ) : $defaults['burn_window'];
		$out['max_file_mb']  = isset( $input['max_file_mb'] ) ? absint( $input['max_file_mb'] ) : $defaults['max_file_mb'];
		$out['unlock_hours'] = isset( $input['unlock_hours'] ) ? min( 720, max( 1, absint( $input['unlock_hours'] ) ) ) : $defaults['unlock_hours'];
		$out['keep_days']    = isset( $input['keep_days'] ) ? min( 3650, absint( $input['keep_days'] ) ) : $defaults['keep_days'];
		$accent              = isset( $input['accent'] ) ? sanitize_hex_color( $input['accent'] ) : '';
		$out['accent']       = $accent ? $accent : '';

		if ( (int) $out['page_id'] !== (int) $current['page_id'] ) {
			// The share page decides the URL, so the rewrite rules change with it.
			update_option( 'nemvo_share_flush_rewrite', 1, true );
		}

		self::$cache = null;
		return $out;
	}

	/**
	 * Largest file a share accepts, in bytes. 0 means no limit of our own.
	 *
	 * @return int
	 */
	public static function max_file_bytes() {
		return (int) self::get( 'max_file_mb' ) * MB_IN_BYTES;
	}
}
