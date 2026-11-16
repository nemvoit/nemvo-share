<?php
/**
 * Settings screen.
 *
 * @package NemvoShare
 */

namespace NemvoShare\Admin;

use NemvoShare\Crypto;
use NemvoShare\Frontend;
use NemvoShare\Settings;
use NemvoShare\Storage;
use NemvoShare\Uploads;

defined( 'ABSPATH' ) || exit;

/**
 * Settings page built on the Settings API.
 */
class Settings_Page {

	const GROUP = 'nemvo_share';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'update_option_' . Settings::OPTION, array( Settings::class, 'flush' ) );
	}

	/**
	 * Registers the option.
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Field name for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function name( $key ) {
		return Settings::OPTION . '[' . $key . ']';
	}

	/**
	 * Renders the page.
	 */
	public static function render() {
		$s       = Settings::all();
		$page_id = Frontend::page_id();
		$probe   = Storage::probe();
		$free    = Storage::free_space();
		?>
		<div class="wrap nvs-admin nvs-settings">
			<h1><?php esc_html_e( 'Share Settings', 'nemvo-share' ); ?></h1>
			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<div class="nvs-card">
					<h2 class="nvs-card-title"><?php esc_html_e( 'Share page', 'nemvo-share' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Secret links open inside this page, so they look like the rest of your site. Edit the page to add text around the share, or pick another page that contains the "Shared notes and files" block.', 'nemvo-share' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="nvs-page"><?php esc_html_e( 'Page', 'nemvo-share' ); ?></label></th>
							<td>
								<?php
								wp_dropdown_pages(
									array(
										'name'              => esc_attr( self::name( 'page_id' ) ),
										'id'                => 'nvs-page',
										'selected'          => (int) $s['page_id'],
										'show_option_none'  => esc_html__( '— Select —', 'nemvo-share' ),
										'option_none_value' => '0',
										'post_status'       => array( 'publish' ),
									)
								);
								?>
								<?php if ( $page_id ) : ?>
									<a class="button-link" href="<?php echo esc_url( get_edit_post_link( $page_id ) ); ?>"><?php esc_html_e( 'Edit page', 'nemvo-share' ); ?></a>
									<p class="description">
										<?php
										/* translators: %s: example URL. */
										echo esc_html( sprintf( __( 'Links look like %s', 'nemvo-share' ), Frontend::share_url( 'Abc123…' ) ) );
										?>
									</p>
									<?php if ( ! get_option( 'permalink_structure' ) ) : ?>
										<p class="description"><?php esc_html_e( 'Your site uses plain permalinks, so links contain ?page_id=…. Choose another structure under Settings > Permalinks for /share/… links.', 'nemvo-share' ); ?></p>
									<?php endif; ?>
								<?php else : ?>
									<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nemvo_share_create_page' ), 'nemvo_share_create_page' ) ); ?>"><?php esc_html_e( 'Create the share page', 'nemvo-share' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					</table>
				</div>

				<div class="nvs-card">
					<h2 class="nvs-card-title"><?php esc_html_e( 'New shares start with', 'nemvo-share' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Who can open it', 'nemvo-share' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Who can open it', 'nemvo-share' ); ?></legend>
									<label><input type="radio" name="<?php echo esc_attr( self::name( 'default_access' ) ); ?>" value="password" <?php checked( 'password', $s['default_access'] ); ?>> <?php esc_html_e( 'Link and a generated password', 'nemvo-share' ); ?></label><br>
									<label><input type="radio" name="<?php echo esc_attr( self::name( 'default_access' ) ); ?>" value="link" <?php checked( 'link', $s['default_access'] ); ?>> <?php esc_html_e( 'Anyone with the link', 'nemvo-share' ); ?></label>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nvs-default-expiry"><?php esc_html_e( 'Available for', 'nemvo-share' ); ?></label></th>
							<td>
								<select id="nvs-default-expiry" name="<?php echo esc_attr( self::name( 'default_expiry' ) ); ?>">
									<?php foreach ( Settings::expiry_choices() as $value => $label ) : ?>
										<?php
										if ( 'custom' === $value ) {
											continue;
										}
										?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['default_expiry'], $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Options', 'nemvo-share' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Options', 'nemvo-share' ); ?></legend>
									<label><input type="checkbox" name="<?php echo esc_attr( self::name( 'default_burn' ) ); ?>" value="1" <?php checked( 1, (int) $s['default_burn'] ); ?>> <?php esc_html_e( 'Burn after reading', 'nemvo-share' ); ?></label><br>
									<label><input type="checkbox" name="<?php echo esc_attr( self::name( 'default_auto_delete' ) ); ?>" value="1" <?php checked( 1, (int) $s['default_auto_delete'] ); ?>> <?php esc_html_e( 'Delete contents from the server when the share ends', 'nemvo-share' ); ?></label><br>
									<label><input type="checkbox" name="<?php echo esc_attr( self::name( 'default_notify' ) ); ?>" value="1" <?php checked( 1, (int) $s['default_notify'] ); ?>> <?php esc_html_e( 'Email me when it is opened', 'nemvo-share' ); ?></label>
								</fieldset>
							</td>
						</tr>
					</table>
				</div>

				<div class="nvs-card">
					<h2 class="nvs-card-title"><?php esc_html_e( 'Limits', 'nemvo-share' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="nvs-max"><?php esc_html_e( 'Largest file', 'nemvo-share' ); ?></label></th>
							<td>
								<input type="number" id="nvs-max" class="small-text" min="0" step="1" name="<?php echo esc_attr( self::name( 'max_file_mb' ) ); ?>" value="<?php echo esc_attr( (string) $s['max_file_mb'] ); ?>"> <?php esc_html_e( 'MB', 'nemvo-share' ); ?>
								<p class="description">
									<?php
									printf(
										/* translators: 1: chunk size, 2: PHP upload limit. */
										esc_html__( '0 means no limit. Uploads are sent in pieces of %1$s, so PHP\'s upload limit (%2$s per request) does not restrict the file size.', 'nemvo-share' ),
										esc_html( size_format( Uploads::chunk_size() ) ),
										esc_html( size_format( wp_max_upload_size() ) )
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nvs-burn-window"><?php esc_html_e( 'Burn after reading', 'nemvo-share' ); ?></label></th>
							<td>
								<input type="number" id="nvs-burn-window" class="small-text" min="5" max="1440" name="<?php echo esc_attr( self::name( 'burn_window' ) ); ?>" value="<?php echo esc_attr( (string) $s['burn_window'] ); ?>"> <?php esc_html_e( 'minutes', 'nemvo-share' ); ?>
								<p class="description"><?php esc_html_e( 'How long the person who opened a burn-after-reading share can download its files. It is destroyed earlier once every file has been downloaded.', 'nemvo-share' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nvs-unlock"><?php esc_html_e( 'Password remembered for', 'nemvo-share' ); ?></label></th>
							<td>
								<input type="number" id="nvs-unlock" class="small-text" min="1" max="720" name="<?php echo esc_attr( self::name( 'unlock_hours' ) ); ?>" value="<?php echo esc_attr( (string) $s['unlock_hours'] ); ?>"> <?php esc_html_e( 'hours', 'nemvo-share' ); ?>
								<p class="description"><?php esc_html_e( 'After the right password, the recipient\'s browser can open the share without it for this long.', 'nemvo-share' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nvs-keep"><?php esc_html_e( 'Keep ended shares', 'nemvo-share' ); ?></label></th>
							<td>
								<input type="number" id="nvs-keep" class="small-text" min="0" max="3650" name="<?php echo esc_attr( self::name( 'keep_days' ) ); ?>" value="<?php echo esc_attr( (string) $s['keep_days'] ); ?>"> <?php esc_html_e( 'days', 'nemvo-share' ); ?>
								<p class="description"><?php esc_html_e( 'Expired and burned shares are deleted completely this many days after they ended. 0 keeps them until you delete them.', 'nemvo-share' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="nvs-card">
					<h2 class="nvs-card-title"><?php esc_html_e( 'Appearance', 'nemvo-share' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="nvs-accent"><?php esc_html_e( 'Button colour', 'nemvo-share' ); ?></label></th>
							<td>
								<input type="text" id="nvs-accent" class="nvs-color" name="<?php echo esc_attr( self::name( 'accent' ) ); ?>" value="<?php echo esc_attr( (string) $s['accent'] ); ?>" data-default-color="">
								<p class="description"><?php esc_html_e( 'Leave empty to use your theme\'s colours. The share page otherwise follows your theme\'s fonts, colours and button style.', 'nemvo-share' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="nvs-card">
					<h2 class="nvs-card-title"><?php esc_html_e( 'When the plugin is deleted', 'nemvo-share' ); ?></h2>
					<label><input type="checkbox" name="<?php echo esc_attr( self::name( 'delete_on_uninstall' ) ); ?>" value="1" <?php checked( 1, (int) $s['delete_on_uninstall'] ); ?>> <?php esc_html_e( 'Also delete all shares, their files and these settings', 'nemvo-share' ); ?></label>
				</div>

				<?php submit_button(); ?>
			</form>

			<div class="nvs-card" id="nvs-storage">
				<h2 class="nvs-card-title"><?php esc_html_e( 'Storage and encryption', 'nemvo-share' ); ?></h2>
				<table class="form-table nvs-facts-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Direct access', 'nemvo-share' ); ?></th>
						<td>
							<?php
							$probe_labels = array(
								'protected' => array( 'good', __( 'Blocked: the web server refuses direct requests for shared files.', 'nemvo-share' ) ),
								'outside'   => array( 'good', __( 'Blocked: the storage folder is outside the website.', 'nemvo-share' ) ),
								'exposed'   => array( 'warn', __( 'Not blocked by the web server (probably nginx, which ignores .htaccess). Files stay unreadable because they are encrypted and have random names, but add a deny rule:', 'nemvo-share' ) ),
								'unknown'   => array( 'warn', __( 'Could not be checked: WordPress could not make a request to itself.', 'nemvo-share' ) ),
							);
							$probe_info   = isset( $probe_labels[ $probe['status'] ] ) ? $probe_labels[ $probe['status'] ] : $probe_labels['unknown'];
							?>
							<span class="nvs-health nvs-health--<?php echo esc_attr( $probe_info[0] ); ?>"><?php echo esc_html( $probe_info[1] ); ?></span>
							<?php if ( 'exposed' === $probe['status'] ) : ?>
								<pre class="nvs-code">location ~* /uploads/nemvo-share-[a-z0-9]+/ { deny all; }</pre>
							<?php endif; ?>
							<p class="description">
								<?php
								/* translators: %s: relative time. */
								echo esc_html( sprintf( __( 'Checked %s.', 'nemvo-share' ), \NemvoShare\Plugin::relative( (int) $probe['checked'] ) ) );
								?>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nemvo_share_probe' ), 'nemvo_share_probe' ) ); ?>"><?php esc_html_e( 'Check again', 'nemvo-share' ); ?></a>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Folder', 'nemvo-share' ); ?></th>
						<td>
							<code><?php echo esc_html( Storage::base_dir() ); ?></code>
							<p class="description"><?php esc_html_e( 'To keep files outside the website, define NEMVO_SHARE_STORAGE in wp-config.php with a writable folder (move existing files there yourself).', 'nemvo-share' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Space', 'nemvo-share' ); ?></th>
						<td>
							<?php
							/* translators: %s: size. */
							echo esc_html( sprintf( __( '%s used by shared files', 'nemvo-share' ), \NemvoShare\Plugin::size( Storage::used_space() ) ) );
							if ( null !== $free ) {
								/* translators: %s: size. */
								echo ' · ' . esc_html( sprintf( __( '%s free', 'nemvo-share' ), \NemvoShare\Plugin::size( $free ) ) );
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Encryption key', 'nemvo-share' ); ?></th>
						<td>
							<?php if ( Crypto::key_in_config() ) : ?>
								<span class="nvs-health nvs-health--good"><?php esc_html_e( 'In wp-config.php (NEMVO_SHARE_KEY). Shares created since then can not be decrypted from a copy of the database alone.', 'nemvo-share' ); ?></span>
							<?php else : ?>
								<span class="nvs-health nvs-health--warn"><?php esc_html_e( 'Stored in the database. For better protection, add this line to wp-config.php:', 'nemvo-share' ); ?></span>
								<div class="nvs-copyrow nvs-copyrow--plain">
									<input type="text" id="nvs-key-line" class="nvs-mono" readonly value="<?php echo esc_attr( "define( 'NEMVO_SHARE_KEY', '" . Crypto::random_string( 48, Crypto::TOKEN_ALPHABET ) . "' );" ); ?>">
									<button type="button" class="button" data-nvs-copy-from="nvs-key-line"><?php esc_html_e( 'Copy', 'nemvo-share' ); ?></button>
								</div>
								<p class="description"><?php esc_html_e( 'New shares then use this key. Existing shares stay readable with the key in the database, so they keep that weaker protection until they are deleted. Keep a copy of the line: shares made with it can not be opened without it.', 'nemvo-share' ); ?></p>
							<?php endif; ?>
							<p class="description">
								<?php
								echo Crypto::native()
									? esc_html__( 'Encryption: XChaCha20-Poly1305 with the PHP sodium extension.', 'nemvo-share' )
									: esc_html__( 'Encryption: XChaCha20-Poly1305 with sodium_compat. Large files encrypt faster with the PHP sodium extension.', 'nemvo-share' );
								?>
							</p>
						</td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}
}
