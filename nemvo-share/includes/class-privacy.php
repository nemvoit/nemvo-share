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
		$text = '<p class="privacy-policy-tutorial">' . esc_html__( 'Nemvo Share does not store the names, email addresses or IP addresses of the people who open shared links. To limit password guessing it keeps a keyed one-way hash of the visitor\'s IP address for a short time, which counts as pseudonymous personal data. You may want to mention that and the cookie below.', 'nemvo-share' ) . '</p>'
			. '<strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'nemvo-share' ) . ' </strong>'
			. '<p>' . esc_html__( 'When you open a shared note or file with a password, or open a share that can be read only once, we set a cookie that lets your browser see that share for a limited time. It contains no personal data and is removed when it expires. We record when a share is opened and downloaded, but not who opened it.', 'nemvo-share' ) . '</p>'
			. '<p>' . esc_html__( 'When you enter the password for a share, we store a one-way hash of your IP address with the attempt, to limit wrong guesses. It is deleted after 15 minutes (at the latest one hour later, with our regular clean-up).', 'nemvo-share' ) . '</p>';
		wp_add_privacy_policy_content( __( 'Nemvo Share', 'nemvo-share' ), wp_kses_post( $text ) );
	}
}
