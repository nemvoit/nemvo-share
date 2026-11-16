<?php
/**
 * Site Health tests and debug information.
 *
 * @package NemvoShare
 */

namespace NemvoShare;

defined( 'ABSPATH' ) || exit;

/**
 * Site Health integration.
 */
class Health {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'site_status_tests', array( __CLASS__, 'tests' ) );
		add_filter( 'debug_information', array( __CLASS__, 'debug_information' ) );
	}

	/**
	 * Registers the tests.
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public static function tests( $tests ) {
		$tests['direct']['nemvo_share_storage'] = array(
			'label' => __( 'Shared files are protected', 'nemvo-share' ),
			'test'  => array( __CLASS__, 'test_storage' ),
		);
		$tests['direct']['nemvo_share_page']    = array(
			'label' => __( 'The share page works', 'nemvo-share' ),
			'test'  => array( __CLASS__, 'test_page' ),
		);
		$tests['direct']['nemvo_share_key']     = array(
			'label' => __( 'Share encryption key', 'nemvo-share' ),
			'test'  => array( __CLASS__, 'test_key' ),
		);
		return $tests;
	}

	/**
	 * Result skeleton.
	 *
	 * @param string $test        Test name.
	 * @param string $status      good, recommended or critical.
	 * @param string $label       Label.
	 * @param string $description HTML description.
	 * @return array
	 */
	private static function result( $test, $status, $label, $description ) {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Security', 'nemvo-share' ),
				'color' => 'good' === $status ? 'blue' : ( 'critical' === $status ? 'red' : 'orange' ),
			),
			'description' => $description,
			'actions'     => '<a href="' . esc_url( admin_url( 'admin.php?page=nemvo-share-settings' ) ) . '">' . esc_html__( 'Share settings', 'nemvo-share' ) . '</a>',
			'test'        => $test,
		);
	}

	/**
	 * Is the storage folder closed to direct web requests?
	 *
	 * @return array
	 */
	public static function test_storage() {
		$probe = Storage::probe( true );
		if ( 'protected' === $probe['status'] || 'outside' === $probe['status'] ) {
			return self::result(
				'nemvo_share_storage',
				'good',
				__( 'Shared files are protected', 'nemvo-share' ),
				'<p>' . esc_html__( 'The web server refuses direct requests for shared files. They are only handed out through the share page, after its checks.', 'nemvo-share' ) . '</p>'
			);
		}
		if ( 'exposed' === $probe['status'] ) {
			return self::result(
				'nemvo_share_storage',
				'recommended',
				__( 'The web server would serve shared files directly', 'nemvo-share' ),
				'<p>' . esc_html__( 'The storage folder does not refuse web requests, which usually means the server is nginx (it ignores .htaccess files). Files are encrypted and have random names, so they stay unreadable, but you should block the folder too.', 'nemvo-share' ) . '</p>'
				. '<p><code>location ~* /uploads/nemvo-share-[a-z0-9]+/ { deny all; }</code></p>'
			);
		}
		return self::result(
			'nemvo_share_storage',
			'recommended',
			__( 'Could not check whether shared files are protected', 'nemvo-share' ),
			'<p>' . esc_html__( 'WordPress could not make a test request to itself, or the storage folder is not writable. Check the storage section of the share settings.', 'nemvo-share' ) . '</p>'
		);
	}

	/**
	 * Is the share page published, and are links pretty?
	 *
	 * @return array
	 */
	public static function test_page() {
		if ( ! Frontend::page_id() ) {
			return self::result(
				'nemvo_share_page',
				'critical',
				__( 'The share page is missing', 'nemvo-share' ),
				'<p>' . esc_html__( 'Secret links open on a published page with the share block. That page is missing, unpublished or in the trash, so links do not work.', 'nemvo-share' ) . '</p>'
			);
		}
		return self::result(
			'nemvo_share_page',
			'good',
			__( 'The share page works', 'nemvo-share' ),
			/* translators: %s: URL. */
			'<p>' . esc_html( sprintf( __( 'Secret links open under %s.', 'nemvo-share' ), get_permalink( Frontend::page_id() ) ) ) . '</p>'
		);
	}

	/**
	 * Is the encryption key in wp-config.php?
	 *
	 * @return array
	 */
	public static function test_key() {
		if ( Crypto::key_in_config() ) {
			return self::result(
				'nemvo_share_key',
				'good',
				__( 'The share encryption key is in wp-config.php', 'nemvo-share' ),
				'<p>' . esc_html__( 'Shares created since the key was added can not be read from a copy of the database alone.', 'nemvo-share' ) . '</p>'
			);
		}
		return self::result(
			'nemvo_share_key',
			'recommended',
			__( 'Keep the share encryption key in wp-config.php', 'nemvo-share' ),
			'<p>' . esc_html__( 'Shared notes and files are encrypted with a key stored in the database. Defining NEMVO_SHARE_KEY in wp-config.php means a leaked database backup alone can not decrypt them. The settings page has a key ready to copy.', 'nemvo-share' ) . '</p>'
		);
	}

	/**
	 * Adds a section to Site Health > Info.
	 *
	 * @param array $info Debug information.
	 * @return array
	 */
	public static function debug_information( $info ) {
		$free                 = Storage::free_space();
		$info['nemvo-share'] = array(
			'label'  => __( 'Nemvo Share', 'nemvo-share' ),
			'fields' => array(
				'version'    => array(
					'label' => __( 'Version', 'nemvo-share' ),
					'value' => NEMVO_SHARE_VERSION,
				),
				'storage'    => array(
					'label'   => __( 'Storage folder', 'nemvo-share' ),
					'value'   => Storage::base_dir(),
					'private' => true,
				),
				'used'       => array(
					'label' => __( 'Space used by shared files', 'nemvo-share' ),
					'value' => size_format( Storage::used_space() ),
				),
				'free'       => array(
					'label' => __( 'Free disk space', 'nemvo-share' ),
					'value' => null === $free ? __( 'Unknown', 'nemvo-share' ) : size_format( $free ),
				),
				'chunk'      => array(
					'label' => __( 'Upload chunk size', 'nemvo-share' ),
					'value' => size_format( Uploads::chunk_size() ),
				),
				'key'        => array(
					'label' => __( 'Encryption key', 'nemvo-share' ),
					'value' => Crypto::key_in_config() ? 'wp-config.php' : __( 'Database', 'nemvo-share' ),
				),
				'sodium'     => array(
					'label' => __( 'Encryption library', 'nemvo-share' ),
					'value' => Crypto::native() ? 'PHP sodium' : 'sodium_compat',
				),
				'share_page' => array(
					'label' => __( 'Share page', 'nemvo-share' ),
					'value' => Frontend::page_id() ? get_permalink( Frontend::page_id() ) : __( 'Missing', 'nemvo-share' ),
				),
			),
		);
		return $info;
	}
}
