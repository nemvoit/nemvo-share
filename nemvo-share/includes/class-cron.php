<?php
/**
 * Scheduled clean-up.
 *
 * @package NemvoShare
 */

namespace NemvoShare;

defined( 'ABSPATH' ) || exit;

/**
 * Hourly clean-up: ends expired shares and finished burn windows (removing contents
 * where the share says so), removes IP hashes from old password attempts, and deletes
 * abandoned uploads and old records.
 *
 * Expiry and burn after reading are also enforced on every request, so a late cron run
 * never makes a share available for longer. Cron only makes the clean-up happen when
 * nobody visits the link.
 */
class Cron {

	const HOOK = 'nemvo_share_cleanup';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
	}

	/**
	 * Schedules the hourly event.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		}
	}

	/**
	 * Removes the event.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Runs the clean-up.
	 */
	public static function run() {
		Shares::sweep();
		Access::forget_visitors();
		Uploads::cleanup();
		Storage::sweep_orphans();
		Shares::delete_old_records();
	}
}
