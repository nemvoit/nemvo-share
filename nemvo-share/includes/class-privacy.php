<?php
/**
 * Privacy policy text.
 *
 * @package NemvoShare
 */

namespace NemvoShare;

defined( 'ABSPATH' ) || exit;

/**
 * Suggests text for the site's privacy policy.
 */
class Privacy {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
	}

	/**
	 * Adds the suggested text under Settings > Privacy.
	 */
	public static function policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p class="privacy-policy-tutorial">' . esc_html__( 'Nemvo Share stores no personal data about the people who open shared links. You may want to mention the cookie below.', 'nemvo-share' ) . '</p>'
			. '<strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'nemvo-share' ) . ' </strong>'
			. '<p>' . esc_html__( 'When you open a shared note or file with a password, or open a share that can be read only once, we set a cookie that lets your browser see that share for a limited time. It contains no personal data and is removed when it expires. We count how often a share is opened and downloaded, without recording who opened it. To limit wrong password attempts, a one-way hash of your IP address is kept for 15 minutes.', 'nemvo-share' ) . '</p>';
		wp_add_privacy_policy_content( __( 'Nemvo Share', 'nemvo-share' ), wp_kses_post( $text ) );
	}
}
