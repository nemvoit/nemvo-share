<?php
/**
 * Visitor access: unlocked shares, burn-after-reading claims and password rate limits.
 *
 * @package NemvoShare
 */

namespace NemvoShare;

defined( 'ABSPATH' ) || exit;

/**
 * Access cookies and password attempts.
 *
 * After the right password (or after opening a burn-after-reading share) the browser gets
 * an HttpOnly cookie scoped to the share's path. The cookie is signed with the site's
 * secret keys and bound to the current password and claim, so changing the password or
 * reopening the share logs everyone out of it.
 */
class Access {

	const ATTEMPTS      = 5;
	const LOCK_MINUTES  = 15;
	const SHARE_LIMIT   = 30;
	const SHARE_PACE    = 3;
	const BUSY          = -1;
	const COOKIE_PREFIX = 'nemvo_share_';

	/**
	 * Cookie name for a share.
	 *
	 * @param object $share Share row.
	 * @return string
	 */
	private static function cookie_name( $share ) {
		return self::COOKIE_PREFIX . (int) $share->id;
	}

	/**
	 * Signature of a cookie value.
	 *
	 * @param object $share   Share row.
	 * @param int    $expires Expiry timestamp.
	 * @param string $claim   Claim secret or '-'.
	 * @return string
	 */
	private static function sign( $share, $expires, $claim ) {
		return hash_hmac( 'sha256', implode( '|', array( $share->id, $expires, $claim, $share->password_hash, $share->claim_hash, $share->token_hash ) ), wp_salt( 'auth' ) . 'nemvo-share-access' );
	}

	/**
	 * Gives this browser access to a share.
	 *
	 * @param object $share Share row (with claim_hash already set for a claim).
	 * @param string $claim Claim secret for burn after reading, or ''.
	 */
	public static function grant( $share, $claim = '' ) {
		$expires = time() + (int) Settings::get( 'unlock_hours' ) * HOUR_IN_SECONDS;
		$ends    = Plugin::ts( $share->expires_at );
		if ( $ends && $ends < $expires ) {
			$expires = $ends;
		}
		$deadline = Plugin::ts( $share->burn_deadline );
		if ( $deadline && $deadline < $expires ) {
			$expires = $deadline;
		}
		$claim = '' === $claim ? '-' : $claim;
		$value = $expires . '.' . $claim . '.' . self::sign( $share, $expires, $claim );
		self::set_cookie( self::cookie_name( $share ), $value, $expires, $share );
		$_COOKIE[ self::cookie_name( $share ) ] = $value;
	}

	/**
	 * Sets a cookie on the share's own path.
	 *
	 * @param string $name    Cookie name.
	 * @param string $value   Value.
	 * @param int    $expires Expiry timestamp.
	 * @param object $share   Share row.
	 */
	private static function set_cookie( $name, $value, $expires, $share ) {
		if ( headers_sent() ) {
			return;
		}
		$url  = Shares::url( $share );
		$path = $url ? wp_parse_url( $url, PHP_URL_PATH ) : '';
		if ( ! $path || false !== strpos( $url, '?' ) ) {
			$path = COOKIEPATH ? COOKIEPATH : '/';
		}
		setcookie(
			$name,
			$value,
			array(
				'expires'  => $expires,
				'path'     => $path,
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Reads and checks this browser's cookie for a share.
	 *
	 * @param object $share Share row.
	 * @return array{claim:string}|null Null when there is no valid cookie.
	 */
	private static function cookie( $share ) {
		$name = self::cookie_name( $share );
		if ( empty( $_COOKIE[ $name ] ) ) {
			return null;
		}
		$parts = explode( '.', sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		list( $expires, $claim, $signature ) = $parts;
		if ( (int) $expires < time() || ! hash_equals( self::sign( $share, (int) $expires, $claim ), $signature ) ) {
			return null;
		}
		return array( 'claim' => '-' === $claim ? '' : $claim );
	}

	/**
	 * Whether this browser entered the share's password.
	 *
	 * @param object $share Share row.
	 * @return bool
	 */
	public static function unlocked( $share ) {
		return null !== self::cookie( $share );
	}

	/**
	 * Whether this browser is the one that opened a burn-after-reading share.
	 *
	 * @param object $share Share row.
	 * @return bool
	 */
	public static function holds_claim( $share ) {
		$cookie = self::cookie( $share );
		return $cookie && '' !== $cookie['claim'] && '' !== $share->claim_hash && hash_equals( $share->claim_hash, hash( 'sha256', $cookie['claim'] ) );
	}

	/**
	 * A new claim secret.
	 *
	 * @return string
	 */
	public static function new_claim() {
		return Crypto::random_string( 32, Crypto::TOKEN_ALPHABET );
	}

	/*
	 * Password attempts
	 */

	/*
	 * Attempts are rows in the share's activity log (event password_try, then
	 * password_failed when wrong), keyed by a salted hash of the visitor's IP address.
	 * Each attempt is inserted before it is checked and counted afterwards, so parallel
	 * requests can't slip past the limit. Too many wrong passwords from all visitors
	 * together only slow attempts down (one every few seconds per share, others get 429),
	 * so nobody can lock the real recipient out. The IP hash is removed from these rows
	 * once they are older than the lock window (forget_visitors()), so it is kept for
	 * 15 minutes and no longer than the next hourly clean-up after that.
	 *
	 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own table; counts must not be cached.
	 */

	/**
	 * Most wrong passwords per visitor per 15 minutes.
	 *
	 * @return int
	 */
	private static function limit() {
		/**
		 * Filters how many wrong passwords a visitor may enter before a 15 minute pause.
		 *
		 * @param int $attempts Default 5.
		 */
		return max( 1, (int) apply_filters( 'nemvo_share_password_attempts', self::ATTEMPTS ) );
	}

	/**
	 * Salted hash of the visitor's IP address (the address itself is never stored).
	 *
	 * @return string
	 */
	private static function visitor() {
		return substr( hash_hmac( 'sha256', Plugin::client_ip(), wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * Attempts by this visitor in the lock window, wrong ones and ones in progress.
	 *
	 * @param object $share Share row.
	 * @return int
	 */
	private static function attempts( $share ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE share_id = %d AND event IN (%s, %s) AND detail = %s AND created_at > %s',
				Install::table( 'log' ),
				$share->id,
				'password_try',
				'password_failed',
				self::visitor(),
				Plugin::now( -self::LOCK_MINUTES * MINUTE_IN_SECONDS )
			)
		);
	}

	/**
	 * Whether password attempts are paused for this visitor.
	 *
	 * @param object $share Share row.
	 * @return bool
	 */
	public static function locked( $share ) {
		return self::attempts( $share ) >= self::limit();
	}

	/**
	 * Starts an attempt: records it, then checks it is within the limit.
	 *
	 * @param object $share Share row.
	 * @return int Attempt ID; 0 when attempts are paused for this visitor; BUSY when the
	 *             share gets many wrong passwords and another attempt came in just now.
	 */
	public static function begin( $share ) {
		global $wpdb;
		$table = Install::table( 'log' );
		self::forget_visitors( (int) $share->id );
		$wpdb->insert(
			$table,
			array(
				'share_id'   => $share->id,
				'created_at' => Plugin::now(),
				'event'      => 'password_try',
				'detail'     => self::visitor(),
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( self::attempts( $share ) > self::limit() ) {
			$wpdb->delete( $table, array( 'id' => $id ) );
			return 0;
		}
		// Many wrong passwords for this share from everyone together: allow one attempt every
		// few seconds and answer the rest with 429, so nobody locks the real recipient out.
		$recent = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE share_id = %d AND event = %s AND created_at > %s', $table, $share->id, 'password_failed', Plugin::now( -HOUR_IN_SECONDS ) ) );
		if ( $recent >= self::SHARE_LIMIT ) {
			$just_now = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE share_id = %d AND event IN (%s, %s) AND id <> %d AND created_at >= %s', $table, $share->id, 'password_try', 'password_failed', $id, Plugin::now( -self::SHARE_PACE ) ) );
			if ( $just_now > 0 ) {
				$wpdb->delete( $table, array( 'id' => $id ) );
				return self::BUSY;
			}
		}
		return $id;
	}

	/**
	 * Removes the IP hash from password attempts older than the lock window. The rows
	 * stay (without it) for the share's activity log and the per-share limit.
	 *
	 * @param int $share_id Only this share, or 0 for all shares.
	 */
	public static function forget_visitors( $share_id = 0 ) {
		global $wpdb;
		$table  = Install::table( 'log' );
		$cutoff = Plugin::now( -self::LOCK_MINUTES * MINUTE_IN_SECONDS );
		if ( $share_id ) {
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET detail = '' WHERE share_id = %d AND event IN (%s, %s) AND detail <> '' AND created_at <= %s", $table, $share_id, 'password_try', 'password_failed', $cutoff ) );
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET detail = '' WHERE event IN (%s, %s) AND detail <> '' AND created_at <= %s", $table, 'password_try', 'password_failed', $cutoff ) );
		}
	}

	/**
	 * Finishes an attempt.
	 *
	 * @param int  $id    Attempt ID from begin().
	 * @param bool $right Whether the password was right.
	 * @return int Wrong attempts this visitor has left.
	 */
	public static function finish( $id, $right ) {
		global $wpdb;
		$table = Install::table( 'log' );
		if ( $right ) {
			$wpdb->delete( $table, array( 'id' => $id ) );
			return self::limit();
		}
		$wpdb->update( $table, array( 'event' => 'password_failed' ), array( 'id' => $id ) );
		$share_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT share_id FROM %i WHERE id = %d', $table, $id ) );
		return max( 0, self::limit() - self::attempts( (object) array( 'id' => $share_id ) ) );
	}
}
