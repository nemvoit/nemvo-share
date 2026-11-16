<?php
/**
 * wp-admin: menus, assets and form handlers.
 *
 * @package NemvoShare
 */

namespace NemvoShare\Admin;

use NemvoShare\Crypto;
use NemvoShare\Frontend;
use NemvoShare\Plugin;
use NemvoShare\Settings;
use NemvoShare\Shares;
use NemvoShare\Storage;
use NemvoShare\Uploads;

defined( 'ABSPATH' ) || exit;

/**
 * Admin controller.
 */
class Admin {

	const SLUG          = 'nemvo-share';
	const SLUG_NEW      = 'nemvo-share-new';
	const SLUG_SETTINGS = 'nemvo-share-settings';

	/**
	 * Page hooks of our screens.
	 *
	 * @var string[]
	 */
	private static $hooks = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_nemvo_share_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_nemvo_share_action', array( __CLASS__, 'row_action' ) );
		add_action( 'admin_post_nemvo_share_bulk', array( __CLASS__, 'bulk' ) );
		add_action( 'admin_post_nemvo_share_probe', array( __CLASS__, 'probe' ) );
		add_action( 'admin_post_nemvo_share_create_page', array( __CLASS__, 'create_page' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NEMVO_SHARE_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'post_states' ), 10, 2 );
		Settings_Page::init();
	}

	/**
	 * Menu entries.
	 */
	public static function menu() {
		$cap = Plugin::capability();

		self::$hooks[] = add_menu_page(
			__( 'Shares', 'nemvo-share' ),
			__( 'Shares', 'nemvo-share' ),
			$cap,
			self::SLUG,
			array( __CLASS__, 'page_list' ),
			'dashicons-lock',
			11
		);
		self::$hooks[] = add_submenu_page( self::SLUG, __( 'All Shares', 'nemvo-share' ), __( 'All Shares', 'nemvo-share' ), $cap, self::SLUG, array( __CLASS__, 'page_list' ) );
		self::$hooks[] = add_submenu_page( self::SLUG, __( 'Add Share', 'nemvo-share' ), __( 'Add New Share', 'nemvo-share' ), $cap, self::SLUG_NEW, array( Editor::class, 'render' ) );
		self::$hooks[] = add_submenu_page( self::SLUG, __( 'Share Settings', 'nemvo-share' ), __( 'Settings', 'nemvo-share' ), $cap, self::SLUG_SETTINGS, array( Settings_Page::class, 'render' ) );

		foreach ( self::$hooks as $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'help' ) );
		}
	}

	/**
	 * The list, or the editor for ?action=edit.
	 */
	public static function page_list() {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		if ( 'edit' === $action ) {
			Editor::render();
			return;
		}
		List_Table::render_page();
	}

	/**
	 * URL of an admin screen.
	 *
	 * @param string $slug Page slug.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function url( $slug = self::SLUG, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Edit URL of a share.
	 *
	 * @param int   $id   Share ID.
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public static function edit_url( $id, array $args = array() ) {
		return self::url(
			self::SLUG,
			array_merge(
				array(
					'action' => 'edit',
					'share'  => (int) $id,
				),
				$args
			)
		);
	}

	/**
	 * Link for a row action (disable, enable, delete, new link).
	 *
	 * @param string $do Action.
	 * @param int    $id Share ID.
	 * @return string
	 */
	public static function action_url( $do, $id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'nemvo_share_action',
					'do'     => $do,
					'share'  => (int) $id,
				),
				admin_url( 'admin-post.php' )
			),
			'nemvo_share_' . $do . '_' . (int) $id
		);
	}

	/**
	 * Whether the current screen is one of ours.
	 *
	 * @param string $hook Page hook.
	 * @return bool
	 */
	private static function ours( $hook ) {
		return in_array( $hook, self::$hooks, true );
	}

	/**
	 * Styles and scripts for our screens.
	 *
	 * @param string $hook Page hook.
	 */
	public static function assets( $hook ) {
		if ( ! self::ours( $hook ) ) {
			return;
		}
		$settings = false !== strpos( $hook, self::SLUG_SETTINGS );
		wp_enqueue_style( 'nemvo-share-admin', NEMVO_SHARE_URL . 'assets/css/admin.css', $settings ? array( 'wp-color-picker' ) : array(), NEMVO_SHARE_VERSION );
		wp_enqueue_script( 'nemvo-share-admin', NEMVO_SHARE_URL . 'assets/js/admin.js', $settings ? array( 'wp-i18n', 'wp-color-picker' ) : array( 'wp-i18n' ), NEMVO_SHARE_VERSION, true );
		wp_set_script_translations( 'nemvo-share-admin', 'nemvo-share', NEMVO_SHARE_DIR . 'languages' );

		$free = Storage::free_space();
		wp_add_inline_script(
			'nemvo-share-admin',
			'window.nemvoShare = ' . wp_json_encode(
				array(
					'rest'     => esc_url_raw( rest_url( Uploads::NS . '/uploads' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'maxBytes' => Settings::max_file_bytes(),
					'free'     => $free,
					'alphabet' => Crypto::PASSWORD_ALPHABET,
				)
			) . ';',
			'before'
		);

	}

	/**
	 * Help tabs.
	 */
	public static function help() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$screen->add_help_tab(
			array(
				'id'      => 'nemvo-share-overview',
				'title'   => __( 'Overview', 'nemvo-share' ),
				'content' => '<p>' . esc_html__( 'A share is a note, one or more files, or both, behind a secret link. Send the link to the recipient; if the share has a password, send the password another way, for example by text message.', 'nemvo-share' ) . '</p>'
					. '<p>' . esc_html__( 'Links look like your-site/share/… and open inside a normal page of your site, so they use your theme. Search engines are told not to index them, and they are never cached.', 'nemvo-share' ) . '</p>',
			)
		);
		$screen->add_help_tab(
			array(
				'id'      => 'nemvo-share-burn',
				'title'   => __( 'Burn after reading', 'nemvo-share' ),
				'content' => '<p>' . esc_html__( 'A share with burn after reading can be opened by one person, once. The recipient first sees an Open button, so link previews in chat and email apps do not use it up. After opening, that browser can read the note and download the files for the time set in the settings. The share is destroyed as soon as every file has been downloaded, or when that time is up. A share with only a note is destroyed the moment it is shown.', 'nemvo-share' ) . '</p>'
					. '<p>' . esc_html__( 'Use Preview to look at a share yourself without opening it.', 'nemvo-share' ) . '</p>',
			)
		);
		$screen->add_help_tab(
			array(
				'id'      => 'nemvo-share-security',
				'title'   => __( 'Security', 'nemvo-share' ),
				'content' => '<p>' . esc_html__( 'Files are encrypted on disk with a key per file, stored under random names in a folder the web server refuses to serve, and only handed out through the share page. Notes, links and passwords are encrypted in the database. Wrong passwords are limited to 5 per 15 minutes per visitor.', 'nemvo-share' ) . '</p>',
			)
		);
		$screen->set_help_sidebar( '<p><strong>' . esc_html__( 'More', 'nemvo-share' ) . '</strong></p><p><a href="https://github.com/fpoller/nemvo-share">' . esc_html__( 'Documentation on GitHub', 'nemvo-share' ) . '</a></p>' );
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( self::url( self::SLUG_SETTINGS ) ) . '">' . esc_html__( 'Settings', 'nemvo-share' ) . '</a>',
			'<a href="' . esc_url( self::url( self::SLUG_NEW ) ) . '">' . esc_html__( 'Add share', 'nemvo-share' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Marks the share page in the pages list.
	 *
	 * @param string[] $states States.
	 * @param \WP_Post $post   Post.
	 * @return string[]
	 */
	public static function post_states( $states, $post ) {
		if ( (int) $post->ID === (int) Settings::get( 'page_id' ) ) {
			$states['nemvo_share'] = __( 'Share page', 'nemvo-share' );
		}
		return $states;
	}

	/**
	 * Notices: the result of an action, and a missing share page.
	 */
	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen || ! self::ours( $screen->id ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$message = isset( $_GET['nvs_msg'] ) ? sanitize_key( wp_unslash( $_GET['nvs_msg'] ) ) : '';
		$count   = isset( $_GET['nvs_count'] ) ? absint( $_GET['nvs_count'] ) : 0;
		// phpcs:enable
		$messages = array(
			'updated'     => array( 'success', __( 'Share updated.', 'nemvo-share' ) ),
			'deleted'     => array( 'success', __( 'Share deleted, with its files.', 'nemvo-share' ) ),
			/* translators: %d: number of shares. */
			'bulk'        => array( 'success', sprintf( _n( '%d share deleted.', '%d shares deleted.', $count, 'nemvo-share' ), $count ) ),
			'disabled'    => array( 'success', __( 'Share switched off. The link shows that the share is not available.', 'nemvo-share' ) ),
			'enabled'     => array( 'success', __( 'Share switched on again.', 'nemvo-share' ) ),
			'link'        => array( 'success', __( 'The share has a new link. The old link no longer works.', 'nemvo-share' ) ),
			'empty'       => array( 'error', __( 'Add a note or at least one file.', 'nemvo-share' ) ),
			'past'        => array( 'error', __( 'The expiry date must be in the future.', 'nemvo-share' ) ),
			'failed'      => array( 'error', __( 'The share could not be saved.', 'nemvo-share' ) ),
			'files'       => array( 'warning', __( 'The share was saved, but some files could not be stored. Please add them again.', 'nemvo-share' ) ),
			'page'        => array( 'success', __( 'The share page has been created.', 'nemvo-share' ) ),
			'probed'      => array( 'success', __( 'Storage protection checked again.', 'nemvo-share' ) ),
			'notfound'    => array( 'error', __( 'That share does not exist any more.', 'nemvo-share' ) ),
			'purged_edit' => array( 'error', __( 'The contents of this share have been removed, so it can not be switched on again.', 'nemvo-share' ) ),
		);
		if ( isset( $messages[ $message ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $message ][0] ), esc_html( $messages[ $message ][1] ) );
		}

		if ( ! Frontend::page_id() ) {
			printf(
				'<div class="notice notice-error"><p>%1$s</p><p><a class="button button-primary" href="%2$s">%3$s</a></p></div>',
				esc_html__( 'Secret links open on the share page, which is missing, unpublished or in the trash. Links do not work until there is one.', 'nemvo-share' ),
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nemvo_share_create_page' ), 'nemvo_share_create_page' ) ),
				esc_html__( 'Create the share page', 'nemvo-share' )
			);
		}
	}

	/**
	 * Stops unless the user may manage shares.
	 */
	private static function guard() {
		if ( ! Plugin::can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage shares.', 'nemvo-share' ), 403 );
		}
	}

	/**
	 * Saves the editor form.
	 */
	public static function save() {
		self::guard();
		check_admin_referer( 'nemvo_share_save' );

		$id    = isset( $_POST['share_id'] ) ? absint( $_POST['share_id'] ) : 0;
		$share = $id ? Shares::get( $id ) : null;
		if ( $id && ! $share ) {
			wp_safe_redirect( self::url( self::SLUG, array( 'nvs_msg' => 'notfound' ) ) );
			exit;
		}
		$back = $id ? self::edit_url( $id ) : self::url( self::SLUG_NEW );

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$note  = isset( $_POST['note'] ) ? self::sanitize_note( wp_unslash( $_POST['note'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_note().

		$access = isset( $_POST['access'] ) && 'password' === $_POST['access'] ? 'password' : 'link';
		// Passwords are kept exactly as typed (apart from surrounding spaces), so they are not sanitized further.
		$password = isset( $_POST['password'] ) ? trim( (string) wp_unslash( $_POST['password'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$password = wp_check_invalid_utf8( $password, true );
		if ( 'password' === $access && '' === $password ) {
			$password = Crypto::password();
		}
		if ( 'link' === $access ) {
			$password = '';
		}
		if ( $share && '' !== $password && Shares::password( $share ) === $password ) {
			// Unchanged: keep the hash, so visitors who already entered it stay signed in.
			$password = null;
		}

		$expiry  = isset( $_POST['expiry'] ) ? sanitize_key( wp_unslash( $_POST['expiry'] ) ) : 'keep';
		$expires = false; // False: keep the current value.
		if ( 'never' === $expiry ) {
			$expires = null;
		} elseif ( 'custom' === $expiry ) {
			$local = isset( $_POST['expiry_date'] ) ? sanitize_text_field( wp_unslash( $_POST['expiry_date'] ) ) : '';
			$local = str_replace( 'T', ' ', $local );
			$utc   = preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $local ) ? get_gmt_from_date( $local ) : '';
			if ( ! $utc || \NemvoShare\Plugin::ts( $utc ) <= time() ) {
				wp_safe_redirect( add_query_arg( 'nvs_msg', 'past', $back ) );
				exit;
			}
			$expires = $utc;
		} elseif ( Settings::expiry_seconds( $expiry ) ) {
			$expires = Plugin::now( Settings::expiry_seconds( $expiry ) );
		} elseif ( ! $share ) {
			$expires = null;
		}

		$upload_ids = isset( $_POST['upload_ids'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['upload_ids'] ) ) : array();
		$remove     = isset( $_POST['remove_files'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['remove_files'] ) ) : array();
		$classic    = self::classic_uploads();

		$kept_files = 0;
		if ( $share ) {
			foreach ( Shares::files( $share->id ) as $file ) {
				if ( ! in_array( (int) $file->id, $remove, true ) ) {
					++$kept_files;
				}
			}
		}
		if ( '' === trim( $note ) && ! $upload_ids && ! $classic && ! $kept_files ) {
			wp_safe_redirect( add_query_arg( 'nvs_msg', 'empty', $back ) );
			exit;
		}

		$data = array(
			'title'    => $title,
			'note'     => $note,
			'password' => $password,
			'burn'     => ! empty( $_POST['burn'] ),
			'auto_delete' => ! empty( $_POST['auto_delete'] ),
			'notify'   => ! empty( $_POST['notify'] ),
		);
		if ( false !== $expires ) {
			$data['expires_at'] = $expires;
		}

		if ( $share ) {
			Shares::update( $share->id, $data );
			foreach ( $remove as $file_id ) {
				Shares::remove_file( $share->id, $file_id );
			}
			// An expired share comes back when it gets a new expiry. Burned shares only
			// come back through the explicit Reopen action.
			if ( 'expired' === $share->status && ! $share->purged_at && false !== $expires ) {
				Shares::set_status( $share->id, 'active' );
			}
			Shares::log( $share->id, 'updated' );
			$id = (int) $share->id;
		} else {
			$id = Shares::create( $data );
			if ( ! $id ) {
				wp_safe_redirect( add_query_arg( 'nvs_msg', 'failed', $back ) );
				exit;
			}
		}

		$failed = false;
		foreach ( $upload_ids as $upload_id ) {
			$failed = ! Shares::attach_upload( $id, $upload_id ) || $failed;
		}
		foreach ( $classic as $file ) {
			$failed = ! Shares::add_file_from_path( $id, $file['tmp_name'], $file['name'], $file['type'] ) || $failed;
		}

		$args = array( 'nvs_msg' => $failed ? 'files' : ( $share ? 'updated' : 'created' ) );
		if ( ! $share ) {
			$args['created'] = 1;
		}
		wp_safe_redirect( self::edit_url( $id, $args ) );
		exit;
	}

	/**
	 * Notes are stored encrypted and always escaped when shown, so they are kept as
	 * written (passwords and code may contain < and >); only invalid UTF-8 and control
	 * characters are removed.
	 *
	 * @param string $note Submitted note.
	 * @return string
	 */
	public static function sanitize_note( $note ) {
		$note = wp_check_invalid_utf8( (string) $note, true );
		$note = str_replace( array( "\r\n", "\r" ), "\n", $note );
		$note = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $note );
		return rtrim( (string) $note );
	}

	/**
	 * Files sent with the form itself (browsers without JavaScript).
	 *
	 * @return array[] name, type, tmp_name.
	 */
	private static function classic_uploads() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- nonce checked in save(); tmp_name is validated with is_uploaded_file().
		if ( empty( $_FILES['nemvo_share_files']['name'] ) || ! is_array( $_FILES['nemvo_share_files']['name'] ) ) {
			return array();
		}
		$files = array();
		foreach ( $_FILES['nemvo_share_files']['name'] as $i => $name ) {
			$tmp   = isset( $_FILES['nemvo_share_files']['tmp_name'][ $i ] ) ? $_FILES['nemvo_share_files']['tmp_name'][ $i ] : '';
			$error = isset( $_FILES['nemvo_share_files']['error'][ $i ] ) ? (int) $_FILES['nemvo_share_files']['error'][ $i ] : UPLOAD_ERR_NO_FILE;
			if ( UPLOAD_ERR_OK !== $error || ! $tmp || ! is_uploaded_file( $tmp ) ) {
				continue;
			}
			$files[] = array(
				'name'     => Shares::clean_name( $name ),
				'type'     => isset( $_FILES['nemvo_share_files']['type'][ $i ] ) ? sanitize_mime_type( $_FILES['nemvo_share_files']['type'][ $i ] ) : '',
				'tmp_name' => $tmp,
			);
		}
		// phpcs:enable
		return $files;
	}

	/**
	 * Single-share actions: disable, enable, new link, delete.
	 */
	public static function row_action() {
		self::guard();
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$id = isset( $_GET['share'] ) ? absint( $_GET['share'] ) : 0;
		check_admin_referer( 'nemvo_share_' . $do . '_' . $id );

		$share = Shares::get( $id );
		if ( ! $share ) {
			wp_safe_redirect( self::url( self::SLUG, array( 'nvs_msg' => 'notfound' ) ) );
			exit;
		}
		switch ( $do ) {
			case 'delete':
				Shares::delete( $id );
				wp_safe_redirect( self::url( self::SLUG, array( 'nvs_msg' => 'deleted' ) ) );
				exit;
			case 'disable':
				Shares::set_status( $id, 'disabled' );
				$msg = 'disabled';
				break;
			case 'enable':
				if ( $share->purged_at ) {
					$msg = 'purged_edit';
					break;
				}
				Shares::set_status( $id, 'active' );
				if ( $share->expires_at && \NemvoShare\Plugin::ts( $share->expires_at ) <= time() ) {
					Shares::update( $id, array( 'expires_at' => Plugin::now( Settings::expiry_seconds( '7d' ) ) ) );
				}
				$msg = 'enabled';
				break;
			case 'link':
				Shares::regenerate_token( $id );
				$msg = 'link';
				break;
			default:
				$msg = '';
		}
		$back = isset( $_GET['from'] ) && 'list' === $_GET['from'] ? self::url() : self::edit_url( $id );
		wp_safe_redirect( add_query_arg( 'nvs_msg', $msg, $back ) );
		exit;
	}

	/**
	 * Bulk delete from the list.
	 */
	public static function bulk() {
		self::guard();
		check_admin_referer( 'bulk-shares' );
		$ids   = isset( $_POST['share'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['share'] ) ) : array();
		$count = 0;
		$do    = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		if ( '-1' === $do || '' === $do ) {
			$do = isset( $_POST['bulk_action2'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action2'] ) ) : '';
		}
		foreach ( $ids as $id ) {
			if ( ! $id || ! Shares::get( $id ) ) {
				continue;
			}
			if ( 'delete' === $do ) {
				Shares::delete( $id );
				++$count;
			} elseif ( 'disable' === $do ) {
				Shares::set_status( $id, 'disabled' );
				++$count;
			}
		}
		$msg = 'delete' === $do ? 'bulk' : ( 'disable' === $do ? 'disabled' : '' );
		wp_safe_redirect(
			self::url(
				self::SLUG,
				array(
					'nvs_msg'   => $msg,
					'nvs_count' => $count,
				)
			)
		);
		exit;
	}

	/**
	 * Checks the storage folder's protection again.
	 */
	public static function probe() {
		self::guard();
		check_admin_referer( 'nemvo_share_probe' );
		Storage::probe( true );
		wp_safe_redirect( self::url( self::SLUG_SETTINGS, array( 'nvs_msg' => 'probed' ) ) . '#nvs-storage' );
		exit;
	}

	/**
	 * Creates the share page.
	 */
	public static function create_page() {
		self::guard();
		check_admin_referer( 'nemvo_share_create_page' );
		Settings::update( array( 'page_id' => 0 ) );
		Frontend::ensure_page();
		wp_safe_redirect( self::url( self::SLUG_SETTINGS, array( 'nvs_msg' => 'page' ) ) );
		exit;
	}
}
