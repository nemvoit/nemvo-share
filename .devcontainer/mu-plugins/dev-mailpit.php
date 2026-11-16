<?php
/**
 * Plugin Name: Dev – send mail to Mailpit
 * Description: Local development only. Delivers all email to the Mailpit container (http://localhost:8075).
 */

if ( getenv( 'NEMVO_DEV_MAILPIT' ) ) {
	add_action(
		'phpmailer_init',
		function ( $phpmailer ) {
			$phpmailer->isSMTP();
			$phpmailer->Host     = 'mailpit';
			$phpmailer->Port     = 1025;
			$phpmailer->SMTPAuth = false;
		}
	);
	add_filter(
		'wp_mail_from',
		function () {
			return 'wordpress@example.com';
		}
	);
}
