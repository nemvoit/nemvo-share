<?php
/**
 * Activation, deactivation and database schema.
 *
 * @package NemvoShare
 */

namespace NemvoShare;

defined( 'ABSPATH' ) || exit;

/**
 * Install and upgrade routines.
 */
class Install {

	const DB_VERSION        = 1;
	const DB_VERSION_OPTION = 'nemvo_share_db_version';

	/**
	 * Activation: tables, encryption key, storage folder, share page, cron.
	 */
	public static function activate() {
		self::create_tables();
		Crypto::ensure_key();
		Storage::ensure();
		Frontend::ensure_page();
		Cron::schedule();
		Frontend::add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	/**
	 * Deactivation: stop cron and remove the rewrite rules. Data stays.
	 */
	public static function deactivate() {
		Cron::unschedule();
		flush_rewrite_rules( false );
	}

	/**
	 * Runs the schema update after a plugin update (activation hooks don't run then).
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_tables();
			Crypto::ensure_key();
			Storage::ensure();
		}
	}

	/**
	 * Table names.
	 *
	 * @param string $name shares, files or log.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'nemvo_share_' . $name;
	}

	/**
	 * Creates or updates the tables with dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$shares  = self::table( 'shares' );
		$files   = self::table( 'files' );
		$log     = self::table( 'log' );

		dbDelta(
			"CREATE TABLE {$shares} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token_hash char(64) NOT NULL,
				token_enc text NOT NULL,
				title varchar(255) NOT NULL DEFAULT '',
				note_enc longtext NOT NULL,
				has_note tinyint(1) NOT NULL DEFAULT 0,
				password_hash varchar(255) NOT NULL DEFAULT '',
				password_enc text NOT NULL,
				expires_at datetime DEFAULT NULL,
				burn tinyint(1) NOT NULL DEFAULT 0,
				auto_delete tinyint(1) NOT NULL DEFAULT 1,
				notify tinyint(1) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'active',
				opened_at datetime DEFAULT NULL,
				claim_hash char(64) NOT NULL DEFAULT '',
				burn_deadline datetime DEFAULT NULL,
				ended_at datetime DEFAULT NULL,
				purged_at datetime DEFAULT NULL,
				views int(10) unsigned NOT NULL DEFAULT 0,
				downloads int(10) unsigned NOT NULL DEFAULT 0,
				last_access datetime DEFAULT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY status (status),
				KEY expires_at (expires_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$files} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				share_id bigint(20) unsigned NOT NULL,
				name varchar(255) NOT NULL DEFAULT '',
				size bigint(20) unsigned NOT NULL DEFAULT 0,
				mime varchar(150) NOT NULL DEFAULT '',
				stored varchar(80) NOT NULL DEFAULT '',
				key_enc text NOT NULL,
				downloads int(10) unsigned NOT NULL DEFAULT 0,
				last_download datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY share_id (share_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				share_id bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				event varchar(30) NOT NULL DEFAULT '',
				detail varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY share_id (share_id)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}
}
