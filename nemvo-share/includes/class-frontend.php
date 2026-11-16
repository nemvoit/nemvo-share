<?php
/**
 * The share page that recipients see.
 *
 * @package NemvoShare
 */

namespace NemvoShare;

defined( 'ABSPATH' ) || exit;

/**
 * Serves /share/<token>/ inside a normal WordPress page, so it looks like the rest of
 * the site in any theme.
 *
 * The page (created on activation, chosen under Settings) contains the "Share" block.
 * Rewrite rules route /<page>/<token>/ and /<page>/<token>/download/<file>/ to that page,
 * and template_redirect handles passwords, burn after reading and downloads before the
 * theme prints anything.
 */
class Frontend {

	const BLOCK     = 'nemvo-share/share';
	const SHORTCODE = 'nemvo_share';
	const QV        = 'nemvo_share';
	const QV_FILE   = 'nemvo_share_file';
	const PREVIEW   = 'nemvo_share_preview';

	/**
	 * What the share page shows in this request.
	 *
	 * @var array|null
	 */
	private static $view = null;

	/**
	 * Whether the theme printed the share title already.
	 *
	 * @var bool
	 */
	private static $title_shown = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'redirect_canonical' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'the_title', array( __CLASS__, 'filter_title' ), 10, 2 );
		add_filter( 'document_title_parts', array( __CLASS__, 'document_title' ) );
		add_filter( 'the_content', array( __CLASS__, 'ensure_output' ), 20 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_filter( 'get_pages', array( __CLASS__, 'hide_from_page_lists' ) );
		add_filter( 'wp_list_pages_excludes', array( __CLASS__, 'list_pages_excludes' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_args' ), 10, 2 );
		add_action( 'post_updated', array( __CLASS__, 'page_changed' ), 10, 3 );
	}

	/**
	 * The share page ID, when it is published.
	 *
	 * @return int
	 */
	public static function page_id() {
		$id = (int) Settings::get( 'page_id' );
		return ( $id && 'publish' === get_post_status( $id ) && 'page' === get_post_type( $id ) ) ? $id : 0;
	}

	/**
	 * Creates the share page if there is none, and returns its ID.
	 *
	 * @return int
	 */
	public static function ensure_page() {
		$id   = (int) Settings::get( 'page_id' );
		$post = $id ? get_post( $id ) : null;
		if ( $post && 'page' === $post->post_type && 'trash' !== $post->post_status ) {
			return $id;
		}
		$existing = get_page_by_path( 'share' );
		if ( $existing && false !== strpos( $existing->post_content, '<!-- wp:' . self::BLOCK ) ) {
			$id = (int) $existing->ID;
		} else {
			$id = (int) wp_insert_post(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'post_title'     => __( 'Share', 'nemvo-share' ),
					'post_name'      => 'share',
					'post_content'   => '<!-- wp:' . self::BLOCK . ' /-->',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				)
			);
		}
		if ( $id ) {
			Settings::update( array( 'page_id' => $id ) );
			update_option( 'nemvo_share_flush_rewrite', 1, true );
		}
		return $id;
	}

	/**
	 * Registers the block and the shortcode.
	 */
	public static function register() {
		register_block_type(
			NEMVO_SHARE_DIR . 'blocks/share',
			array( 'render_callback' => array( __CLASS__, 'render' ) )
		);
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		wp_register_style( 'nemvo-share', NEMVO_SHARE_URL . 'assets/css/frontend.css', array(), NEMVO_SHARE_VERSION );
		wp_register_script( 'nemvo-share', NEMVO_SHARE_URL . 'assets/js/frontend.js', array(), NEMVO_SHARE_VERSION, true );
	}

	/**
	 * The share page's path, such as "share" or "about/share".
	 *
	 * @return string
	 */
	private static function page_uri() {
		$id = self::page_id();
		return $id ? (string) get_page_uri( $id ) : '';
	}

	/**
	 * Rewrite rules for /share/<token>/ and its downloads.
	 */
	public static function add_rewrite_rules() {
		$id  = self::page_id();
		$uri = self::page_uri();
		if ( ! $id || '' === $uri ) {
			return;
		}
		$base = preg_quote( $uri, '#' );
		add_rewrite_rule( '^' . $base . '/([A-Za-z0-9]{16,64})/download/([0-9]+)/?$', 'index.php?page_id=' . $id . '&' . self::QV . '=$matches[1]&' . self::QV_FILE . '=$matches[2]', 'top' );
		add_rewrite_rule( '^' . $base . '/([A-Za-z0-9]{16,64})/?$', 'index.php?page_id=' . $id . '&' . self::QV . '=$matches[1]', 'top' );
	}

	/**
	 * Flushes rewrite rules after the share page changed, or when ours are missing.
	 */
	public static function maybe_flush() {
		$flush = (bool) get_option( 'nemvo_share_flush_rewrite' );
		if ( ! $flush && get_option( 'permalink_structure' ) && self::page_id() ) {
			$rules = get_option( 'rewrite_rules' );
			$key   = '^' . preg_quote( self::page_uri(), '#' ) . '/([A-Za-z0-9]{16,64})/?$';
			$flush = is_array( $rules ) && ! isset( $rules[ $key ] );
		}
		if ( $flush ) {
			delete_option( 'nemvo_share_flush_rewrite' );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Notices when the share page's address changes.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $after   Post after the update.
	 * @param \WP_Post $before  Post before the update.
	 */
	public static function page_changed( $post_id, $after, $before ) {
		if ( (int) $post_id === (int) Settings::get( 'page_id' ) && ( $after->post_name !== $before->post_name || $after->post_parent !== $before->post_parent || $after->post_status !== $before->post_status ) ) {
			update_option( 'nemvo_share_flush_rewrite', 1, true );
		}
	}

	/**
	 * Query variables.
	 *
	 * @param string[] $vars Public query variables.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::QV;
		$vars[] = self::QV_FILE;
		return $vars;
	}

	/**
	 * Keeps WordPress from "correcting" /share/<token>/ to /share/.
	 *
	 * @param string|false $redirect Redirect URL.
	 * @return string|false
	 */
	public static function redirect_canonical( $redirect ) {
		return get_query_var( self::QV ) ? false : $redirect;
	}

	/**
	 * The secret link for a token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function share_url( $token ) {
		$id = (int) Settings::get( 'page_id' );
		if ( ! $id ) {
			return add_query_arg( self::QV, $token, home_url( '/' ) );
		}
		if ( get_option( 'permalink_structure' ) ) {
			return trailingslashit( get_permalink( $id ) ) . $token . '/';
		}
		return add_query_arg( self::QV, $token, get_permalink( $id ) );
	}

	/**
	 * Download link of a file.
	 *
	 * @param string $token   Share token.
	 * @param int    $file_id File ID.
	 * @param string $preview Preview nonce, or ''.
	 * @return string
	 */
	public static function download_url( $token, $file_id, $preview = '' ) {
		$url = self::share_url( $token );
		if ( get_option( 'permalink_structure' ) && self::page_id() ) {
			$url .= 'download/' . (int) $file_id . '/';
		} else {
			$url = add_query_arg( self::QV_FILE, (int) $file_id, $url );
		}
		return $preview ? add_query_arg( self::PREVIEW, $preview, $url ) : $url;
	}

	/**
	 * Preview link for administrators: shows the contents without asking for the
	 * password and without counting as opening the share.
	 *
	 * @param object $share Share row.
	 * @return string|null
	 */
	public static function preview_url( $share ) {
		$url = Shares::url( $share );
		return $url ? add_query_arg( self::PREVIEW, wp_create_nonce( 'nemvo_share_preview_' . $share->id ), $url ) : null;
	}

	/* ------------------------------------------------------------------ */
	/* Request handling                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Whether this request is for the share page.
	 *
	 * @return bool
	 */
	private static function on_share_page() {
		$id = self::page_id();
		return $id && is_page( $id );
	}

	/**
	 * Headers that keep shares out of caches, search engines and Referer headers.
	 */
	private static function private_headers() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the constant page-cache plugins look for.
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
	}

	/**
	 * Handles the share page before the theme prints it.
	 */
	public static function handle() {
		if ( ! self::on_share_page() ) {
			return;
		}
		self::private_headers();

		$token = (string) get_query_var( self::QV );
		if ( '' === $token ) {
			self::$view = array(
				'state' => 'empty',
				'title' => get_the_title( self::page_id() ),
			);
			return;
		}

		$share = Shares::find_by_token( $token );
		if ( ! $share ) {
			self::ended( 'missing', 404 );
			return;
		}
		$share = Shares::refresh( $share );
		if ( 'active' !== $share->status ) {
			self::ended( $share->status, 410 );
			return;
		}

		$preview = self::is_preview( $share );
		$state   = self::state( $share, $preview );
		if ( 'claimed' === $state ) {
			self::ended( 'claimed', 410 );
			return;
		}

		$error  = '';
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' === $method && isset( $_POST['nemvo_share_action'] ) ) {
			$nonce = isset( $_POST['nemvo_share_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nemvo_share_nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'nemvo_share_' . $share->id ) ) {
				$error = __( 'This page was open for a long time. Please try again.', 'nemvo-share' );
			} else {
				$action = sanitize_key( wp_unslash( $_POST['nemvo_share_action'] ) );
				if ( 'password' === $state && 'unlock' === $action ) {
					$error = self::unlock( $share );
				} elseif ( 'confirm' === $state && 'open' === $action ) {
					self::open( $share );
				}
				if ( null !== self::$view ) {
					return; // open() or unlock() finished the request's view.
				}
			}
		}

		if ( 'open' !== $state ) {
			self::$view = self::base_view( $share, $state, $preview );
			if ( absint( get_query_var( self::QV_FILE ) ) ) {
				// A download link without access: show the form, but don't answer 200.
				status_header( 403 );
			}
			if ( '' === $error && 'password' === $state && Access::locked( $share ) ) {
				$error = __( 'Too many wrong passwords. Please wait 15 minutes and try again.', 'nemvo-share' );
				status_header( 429 );
			}
			self::$view['error'] = $error;
			return;
		}

		$file_id = absint( get_query_var( self::QV_FILE ) );
		if ( $file_id ) {
			self::download( $share, $file_id, $preview, $method );
			return;
		}

		self::$view = self::open_view( $share, $preview );
		if ( ! $preview && 'GET' === $method ) {
			Shares::count_view( $share );
		}
	}

	/**
	 * Whether an administrator is previewing.
	 *
	 * @param object $share Share row.
	 * @return string The preview nonce, or ''.
	 */
	private static function is_preview( $share ) {
		$nonce = isset( $_GET[ self::PREVIEW ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::PREVIEW ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the value is the nonce.
		return ( '' !== $nonce && Plugin::can_manage() && wp_verify_nonce( $nonce, 'nemvo_share_preview_' . $share->id ) ) ? $nonce : '';
	}

	/**
	 * What this visitor may see of an active share.
	 *
	 * @param object $share   Share row.
	 * @param string $preview Preview nonce or ''.
	 * @return string open, password, confirm or claimed.
	 */
	private static function state( $share, $preview ) {
		if ( $preview ) {
			return 'open';
		}
		$has_password = '' !== $share->password_hash;
		if ( $share->burn ) {
			if ( $share->opened_at ) {
				return Access::holds_claim( $share ) ? 'open' : 'claimed';
			}
			// With a password, the right password opens the share in one step.
			return $has_password ? 'password' : 'confirm';
		}
		return ( $has_password && ! Access::unlocked( $share ) ) ? 'password' : 'open';
	}

	/**
	 * Checks a submitted password.
	 *
	 * @param object $share Share row.
	 * @return string Error message, or '' when the request was finished.
	 */
	private static function unlock( $share ) {
		$locked  = __( 'Too many wrong passwords. Please wait 15 minutes and try again.', 'nemvo-share' );
		$attempt = Access::begin( $share );
		if ( ! $attempt ) {
			Shares::log( $share->id, 'locked' );
			status_header( 429 );
			return $locked;
		}
		// Passwords are compared exactly as typed, so they are not sanitized.
		$password = isset( $_POST['nemvo_share_password'] ) ? (string) wp_unslash( $_POST['nemvo_share_password'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in handle().
		$right    = '' !== $password && wp_check_password( $password, $share->password_hash );
		$left     = Access::finish( $attempt, $right );
		if ( ! $right ) {
			if ( 0 === $left ) {
				status_header( 429 );
				return $locked;
			}
			return __( 'That password is not right. Please check it and try again.', 'nemvo-share' );
		}
		if ( $share->burn ) {
			self::open( $share );
			return '';
		}
		Access::grant( $share );
		Shares::log( $share->id, 'unlocked' );
		wp_safe_redirect( Shares::url( $share ), 303 );
		exit;
	}

	/**
	 * Opens a burn-after-reading share for this browser.
	 *
	 * @param object $share Share row.
	 */
	private static function open( $share ) {
		$claim = Access::new_claim();
		if ( ! Shares::claim( $share, $claim ) ) {
			self::ended( 'claimed', 410 );
			return;
		}
		$share = Shares::get( $share->id );
		Access::grant( $share, $claim );

		if ( Shares::files( $share->id ) ) {
			wp_safe_redirect( Shares::url( $share ), 303 );
			exit;
		}

		// Only a note: show it in this response and destroy it straight away.
		self::$view = self::open_view( $share, '' );
		Shares::count_view( $share );
		Shares::end( $share, 'burned' );
		self::$view['burned_now'] = true;
	}

	/**
	 * Sends a file.
	 *
	 * @param object $share   Share row.
	 * @param int    $file_id File ID.
	 * @param string $preview Preview nonce or ''.
	 * @param string $method  HTTP method.
	 */
	private static function download( $share, $file_id, $preview, $method ) {
		$file = Shares::file( $share->id, $file_id );
		$key  = ( $file && '' !== $file->stored ) ? Storage::open_key( $file->key_enc ) : null;
		$path = $file ? Storage::path( $file->stored ) : '';
		if ( ! $file || ! $key || ! is_file( $path ) ) {
			self::$view          = self::open_view( $share, $preview );
			self::$view['error'] = __( 'This file is no longer available.', 'nemvo-share' );
			status_header( 404 );
			return;
		}

		$size   = (int) $file->size;
		$start  = 0;
		$end    = max( 0, $size - 1 );
		$status = 200;
		$range  = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( $size > 0 && preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $range ), $m ) && ( '' !== $m[1] || '' !== $m[2] ) ) {
			if ( '' === $m[1] ) {
				$start = max( 0, $size - (int) $m[2] );
			} else {
				$start = (int) $m[1];
				$end   = '' !== $m[2] ? min( (int) $m[2], $size - 1 ) : $size - 1;
			}
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			$status = 206;
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		status_header( $status );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: ' . self::disposition( $file->name ) );
		header( 'Content-Length: ' . ( $size > 0 ? $end - $start + 1 : 0 ) );
		header( 'Accept-Ranges: bytes' );
		header( "Content-Security-Policy: default-src 'none'; sandbox" );
		if ( 206 === $status ) {
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}
		if ( 'HEAD' === $method ) {
			exit;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- large downloads.
		}

		$complete = Storage::stream( $path, $size, $key[0], $key[1], $start, $end );
		// Only a response with the whole file counts: a client reading the end of a file
		// first (players, download managers) must not burn the share.
		if ( $complete && 0 === $start && ( 0 === $size || $end === $size - 1 ) && ! $preview ) {
			Shares::count_download( $share, $file );
		}
		exit;
	}

	/**
	 * Content-Disposition header value for a file name.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private static function disposition( $name ) {
		$ascii = preg_replace( '/[^\x20-\x7E]+/', '_', $name );
		$ascii = str_replace( array( '"', '\\' ), '_', $ascii );
		return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $name );
	}

	/**
	 * Sets the view for a share that can't be shown.
	 *
	 * @param string $state  missing, expired, burned, claimed or disabled.
	 * @param int    $status HTTP status.
	 */
	private static function ended( $state, $status ) {
		$titles      = array(
			'missing'  => __( 'Share not found', 'nemvo-share' ),
			'expired'  => __( 'This share has expired', 'nemvo-share' ),
			'burned'   => __( 'This share is gone', 'nemvo-share' ),
			'claimed'  => __( 'This share has already been opened', 'nemvo-share' ),
			'disabled' => __( 'This share is not available', 'nemvo-share' ),
		);
		$messages    = array(
			'missing'  => __( 'This link does not lead to a share. Check that you copied the whole link, or ask the sender for a new one.', 'nemvo-share' ),
			'expired'  => __( 'The time for opening this share has run out. Ask the sender to share it again if you still need it.', 'nemvo-share' ),
			'burned'   => __( 'It could only be opened once and has been destroyed after reading. Ask the sender to share it again if you still need it.', 'nemvo-share' ),
			'claimed'  => __( 'It could only be opened once, and that has happened. If it was not you, let the sender know.', 'nemvo-share' ),
			'disabled' => __( 'The sender has switched this share off.', 'nemvo-share' ),
		);
		self::$view  = array(
			'state'   => 'ended',
			'reason'  => $state,
			'title'   => $titles[ $state ],
			'message' => $messages[ $state ],
		);
		status_header( $status );
	}

	/**
	 * View data shared by every state of an active share.
	 *
	 * @param object $share   Share row.
	 * @param string $state   open, password or confirm.
	 * @param string $preview Preview nonce or ''.
	 * @return array
	 */
	private static function base_view( $share, $state, $preview ) {
		$files = Shares::files( $share->id );
		$bytes = 0;
		foreach ( $files as $file ) {
			$bytes += (int) $file->size;
		}
		return array(
			'state'   => $state,
			'title'   => '' !== $share->title ? $share->title : __( 'Shared with you', 'nemvo-share' ),
			'share'   => $share,
			'preview' => (bool) $preview,
			'action'  => Shares::url( $share ),
			'nonce'   => wp_create_nonce( 'nemvo_share_' . $share->id ),
			'facts'   => self::facts( $share, count( $files ), $bytes, 'open' === $state ),
			'error'   => '',
		);
	}

	/**
	 * View data for an opened share.
	 *
	 * @param object $share   Share row.
	 * @param string $preview Preview nonce or ''.
	 * @return array
	 */
	private static function open_view( $share, $preview ) {
		$view  = self::base_view( $share, 'open', $preview );
		$token = Shares::token( $share );
		$note  = $share->has_note ? Shares::note( $share ) : '';
		$files = array();
		foreach ( Shares::files( $share->id ) as $file ) {
			$files[] = array(
				'id'        => (int) $file->id,
				'name'      => $file->name,
				'size'      => (int) $file->size,
				'url'       => self::download_url( $token, $file->id, $preview ),
				'available' => '' !== $file->stored,
			);
		}
		$view['note']  = null === $note ? '' : $note;
		$view['files'] = $files;
		return $view;
	}

	/**
	 * The share's settings, as short lines for the recipient.
	 *
	 * @param object $share  Share row.
	 * @param int    $count  Number of files.
	 * @param int    $bytes  Total size.
	 * @param bool   $opened Whether the visitor sees the contents.
	 * @return array[] Each with icon and text.
	 */
	private static function facts( $share, $count, $bytes, $opened ) {
		$facts   = array();
		$expires = Plugin::ts( $share->expires_at );
		if ( $expires ) {
			$facts[] = array(
				'icon' => 'clock',
				/* translators: 1: date and time, 2: relative time such as "in 3 days". */
				'text' => sprintf( __( 'Available until %1$s (%2$s)', 'nemvo-share' ), Plugin::format_datetime( $expires ), Plugin::relative( $expires ) ),
			);
		} else {
			$facts[] = array(
				'icon' => 'clock',
				'text' => __( 'No expiry date', 'nemvo-share' ),
			);
		}
		if ( $share->burn ) {
			$deadline = Plugin::ts( $share->burn_deadline );
			if ( $opened && $deadline && $count ) {
				$text = sprintf(
					/* translators: %s: time, such as 15:42. */
					__( 'Burn after reading: destroyed once every file has been downloaded, or at %s at the latest', 'nemvo-share' ),
					wp_date( get_option( 'time_format' ), $deadline )
				);
			} else {
				$text = __( 'Burn after reading: can be opened only once', 'nemvo-share' );
			}
			$facts[] = array(
				'icon' => 'flame',
				'text' => $text,
			);
		}
		if ( '' !== $share->password_hash ) {
			$facts[] = array(
				'icon' => 'lock',
				'text' => __( 'Password protected', 'nemvo-share' ),
			);
		}
		if ( $count ) {
			$facts[] = array(
				'icon' => 'file',
				/* translators: 1: number of files, 2: total size such as "4 MB". */
				'text' => sprintf( _n( '%1$d file, %2$s', '%1$d files, %2$s', $count, 'nemvo-share' ), $count, Plugin::size( $bytes ) ),
			);
		}
		if ( $share->auto_delete && ( $expires || $share->burn ) ) {
			$facts[] = array(
				'icon' => 'trash',
				'text' => __( 'Deleted from the server when the share ends', 'nemvo-share' ),
			);
		}
		return $facts;
	}

	/* ------------------------------------------------------------------ */
	/* Output                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Styles and scripts, on the share page only.
	 */
	public static function assets() {
		if ( ! self::on_share_page() ) {
			return;
		}
		wp_enqueue_style( 'nemvo-share' );
		$accent = (string) Settings::get( 'accent' );
		if ( '' !== $accent ) {
			wp_add_inline_style( 'nemvo-share', ':where(.nvs-share){--nvs-accent:' . $accent . '}' );
		}
		wp_enqueue_script( 'nemvo-share' );
	}

	/**
	 * Renders the block or shortcode.
	 *
	 * @return string
	 */
	public static function render() {
		if ( null === self::$view ) {
			if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return '';
			}
			// The block is on a page that isn't the share page.
			self::$view = array(
				'state' => 'empty',
				'title' => '',
			);
		}
		static $rendered = false;
		if ( $rendered ) {
			return ''; // Once per page, even if the block was added twice.
		}
		$rendered = true;

		wp_enqueue_style( 'nemvo-share' );
		wp_enqueue_script( 'nemvo-share' );

		$view       = self::$view;
		$show_title = ! self::$title_shown;
		$template   = locate_template( array( 'nemvo-share/share.php' ) );
		if ( ! $template ) {
			$template = NEMVO_SHARE_DIR . 'templates/share.php';
		}
		ob_start();
		include $template;
		return (string) ob_get_clean();
	}

	/**
	 * Adds the share to the page when its content lacks the block and the shortcode.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function ensure_output( $content ) {
		if ( null === self::$view || ! in_the_loop() || (int) get_the_ID() !== self::page_id() ) {
			return $content;
		}
		$post = get_post( self::page_id() );
		if ( $post && ( has_block( self::BLOCK, $post ) || has_shortcode( $post->post_content, self::SHORTCODE ) ) ) {
			return $content;
		}
		return $content . self::render();
	}

	/**
	 * Shows the share's title as the page title.
	 *
	 * @param string $title   Title.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public static function filter_title( $title, $post_id = 0 ) {
		if ( null === self::$view || is_admin() || (int) $post_id !== self::page_id() || ! in_the_loop() ) {
			return $title;
		}
		self::$title_shown = true;
		return esc_html( self::$view['title'] );
	}

	/**
	 * Uses the share's title in the browser tab.
	 *
	 * @param array $parts Title parts.
	 * @return array
	 */
	public static function document_title( $parts ) {
		if ( null !== self::$view && '' !== (string) self::$view['title'] ) {
			$parts['title'] = self::$view['title'];
		}
		return $parts;
	}

	/**
	 * Keeps the share page out of search engines.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public static function robots( $robots ) {
		if ( self::on_share_page() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			$robots['noarchive'] = true;
			unset( $robots['max-image-preview'] );
		}
		return $robots;
	}

	/**
	 * Leaves the share page out of automatic page lists and menus on the site.
	 *
	 * @param \WP_Post[] $pages Pages.
	 * @return \WP_Post[]
	 */
	public static function hide_from_page_lists( $pages ) {
		if ( is_admin() || ! is_array( $pages ) ) {
			return $pages;
		}
		$id = (int) Settings::get( 'page_id' );
		return array_values(
			array_filter(
				$pages,
				static function ( $page ) use ( $id ) {
					return ! ( $page instanceof \WP_Post ) || (int) $page->ID !== $id;
				}
			)
		);
	}

	/**
	 * Leaves the share page out of wp_list_pages().
	 *
	 * @param int[] $exclude Page IDs.
	 * @return int[]
	 */
	public static function list_pages_excludes( $exclude ) {
		$exclude[] = (int) Settings::get( 'page_id' );
		return $exclude;
	}

	/**
	 * Leaves the share page out of the XML sitemap.
	 *
	 * @param array  $args      Query arguments.
	 * @param string $post_type Post type.
	 * @return array
	 */
	public static function sitemap_args( $args, $post_type ) {
		if ( 'page' === $post_type && Settings::get( 'page_id' ) ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- leaves out a single page; core's documented way to drop it from the sitemap.
			$args['post__not_in']   = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
			$args['post__not_in'][] = (int) Settings::get( 'page_id' );
		}
		return $args;
	}

	/**
	 * Inline SVG icon for the template.
	 *
	 * @param string $name Icon name.
	 * @return string Safe SVG markup.
	 */
	public static function icon( $name ) {
		$paths = array(
			'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
			'flame'    => '<path d="M12 3c1 3.5 5 5.5 5 10a5 5 0 0 1-10 0c0-2 1-3.5 2-4.5.3 1.6 1.2 2.6 2.2 2.8C10.5 8.6 11 5.5 12 3z"/>',
			'lock'     => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
			'file'     => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
			'trash'    => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
			'download' => '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
			'copy'     => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h8"/>',
			'note'     => '<path d="M5 4h14v16H5z"/><path d="M9 9h6M9 13h6M9 17h3"/>',
			'eye'      => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
			'alert'    => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.5"/>',
			'link'     => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
		);
		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}
		return '<svg class="nvs-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths[ $name ] . '</svg>';
	}
}
