<?php
/**
 * Add and edit shares.
 *
 * @package NemvoShare
 */

namespace NemvoShare\Admin;

use NemvoShare\Frontend;
use NemvoShare\Plugin;
use NemvoShare\Settings;
use NemvoShare\Shares;
use NemvoShare\Storage;
use NemvoShare\Uploads;

defined( 'ABSPATH' ) || exit;

/**
 * The share editor.
 */
class Editor {

	/**
	 * Status badge HTML.
	 *
	 * @param object $share Share row.
	 * @return string
	 */
	public static function status_badge( $share ) {
		$labels = array(
			'active'   => __( 'Active', 'nemvo-share' ),
			'expired'  => __( 'Expired', 'nemvo-share' ),
			'burned'   => __( 'Burned', 'nemvo-share' ),
			'disabled' => __( 'Switched off', 'nemvo-share' ),
		);
		$status = isset( $labels[ $share->status ] ) ? $share->status : 'active';
		$label  = $labels[ $status ];
		if ( 'active' === $status && $share->burn && $share->opened_at ) {
			$status = 'opened';
			$label  = __( 'Opened, burning', 'nemvo-share' );
		}
		return '<span class="nvs-status nvs-status--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Human text for a log event.
	 *
	 * @param object $entry Log row.
	 * @return string
	 */
	private static function event_text( $entry ) {
		$events = array(
			'created'         => __( 'Created', 'nemvo-share' ),
			'updated'         => __( 'Changed', 'nemvo-share' ),
			'viewed'          => __( 'Viewed', 'nemvo-share' ),
			'unlocked'        => __( 'Right password entered', 'nemvo-share' ),
			'password_failed' => __( 'Wrong password entered', 'nemvo-share' ),
			'password_try'    => __( 'Password being checked', 'nemvo-share' ),
			'locked'          => __( 'Password attempts paused after too many wrong passwords', 'nemvo-share' ),
			'opened'          => __( 'Opened (burn after reading started)', 'nemvo-share' ),
			/* translators: %s: file name. */
			'downloaded'      => __( 'Downloaded %s', 'nemvo-share' ),
			/* translators: %s: file name. */
			'file_added'      => __( 'Added %s', 'nemvo-share' ),
			/* translators: %s: file name. */
			'file_removed'    => __( 'Removed %s', 'nemvo-share' ),
			'expired'         => __( 'Expired', 'nemvo-share' ),
			'burned'          => __( 'Burned after reading', 'nemvo-share' ),
			'purged'          => __( 'Contents deleted from the server', 'nemvo-share' ),
			'link_changed'    => __( 'New link made, old link stopped working', 'nemvo-share' ),
			'disabled'        => __( 'Switched off', 'nemvo-share' ),
			'enabled'         => __( 'Switched on', 'nemvo-share' ),
		);
		$text   = isset( $events[ $entry->event ] ) ? $events[ $entry->event ] : $entry->event;
		return false !== strpos( $text, '%s' ) ? sprintf( $text, $entry->detail ) : $text;
	}

	/**
	 * Renders the editor.
	 */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation only.
		$id      = isset( $_GET['share'] ) ? absint( $_GET['share'] ) : 0;
		$created = ! empty( $_GET['created'] );
		// phpcs:enable
		$share = null;
		if ( $id ) {
			$share = Shares::get( $id );
			if ( ! $share ) {
				echo '<div class="wrap"><h1>' . esc_html__( 'Share not found', 'nemvo-share' ) . '</h1><p><a href="' . esc_url( Admin::url() ) . '">' . esc_html__( 'Back to all shares', 'nemvo-share' ) . '</a></p></div>';
				return;
			}
			$share = Shares::refresh( $share );
		}

		$is_new   = null === $share;
		$files    = $share ? Shares::files( $share->id ) : array();
		$password = $share ? Shares::password( $share ) : '';
		$access   = $share ? ( '' !== $share->password_hash ? 'password' : 'link' ) : Settings::get( 'default_access' );
		$burn     = $share ? (int) $share->burn : (int) Settings::get( 'default_burn' );
		$auto_del = $share ? (int) $share->auto_delete : (int) Settings::get( 'default_auto_delete' );
		$notify   = $share ? (int) $share->notify : (int) Settings::get( 'default_notify' );
		$note     = $share ? (string) Shares::note( $share ) : '';
		$max      = Settings::max_file_bytes();
		$free     = Storage::free_space();
		$expires  = $share ? Plugin::ts( $share->expires_at ) : 0;
		$editable = ! $share || ! $share->purged_at;
		?>
		<div class="wrap nvs-admin">
			<h1 class="wp-heading-inline"><?php echo $is_new ? esc_html__( 'Add New Share', 'nemvo-share' ) : esc_html__( 'Edit Share', 'nemvo-share' ); ?></h1>
			<?php if ( ! $is_new ) : ?>
				<a href="<?php echo esc_url( Admin::url( Admin::SLUG_NEW ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Share', 'nemvo-share' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php if ( $share ) : ?>
				<?php self::link_panel( $share, $password, $created ); ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" id="nvs-editor" class="nvs-editor" novalidate>
				<input type="hidden" name="action" value="nemvo_share_save">
				<input type="hidden" name="share_id" value="<?php echo (int) $id; ?>">
				<?php wp_nonce_field( 'nemvo_share_save' ); ?>

				<div class="nvs-columns">
					<div class="nvs-main">

						<div class="nvs-card">
							<label class="nvs-field-label" for="nvs-title"><?php esc_html_e( 'Title', 'nemvo-share' ); ?></label>
							<input type="text" id="nvs-title" name="title" class="nvs-title-input" maxlength="200" value="<?php echo esc_attr( $share ? $share->title : '' ); ?>" placeholder="<?php esc_attr_e( 'For example: Signed contract for Acme', 'nemvo-share' ); ?>" <?php disabled( ! $editable ); ?>>
							<p class="description"><?php esc_html_e( 'Shown to anyone with the link, also before the password is entered. Leave out anything secret.', 'nemvo-share' ); ?></p>
						</div>

						<div class="nvs-card">
							<div class="nvs-card-head">
								<h2 class="nvs-card-title"><label for="nvs-note"><?php esc_html_e( 'Note', 'nemvo-share' ); ?></label></h2>
								<span class="nvs-optional"><?php esc_html_e( 'Optional', 'nemvo-share' ); ?></span>
							</div>
							<textarea id="nvs-note" name="note" rows="7" class="nvs-note-input" placeholder="<?php esc_attr_e( 'Text, credentials, instructions…', 'nemvo-share' ); ?>" spellcheck="false" <?php disabled( ! $editable ); ?>><?php echo esc_textarea( $note ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Plain text, shown exactly as written with a copy button. Stored encrypted.', 'nemvo-share' ); ?></p>
						</div>

						<div class="nvs-card">
							<div class="nvs-card-head">
								<h2 class="nvs-card-title"><?php esc_html_e( 'Files', 'nemvo-share' ); ?></h2>
								<span class="nvs-optional"><?php esc_html_e( 'Optional', 'nemvo-share' ); ?></span>
							</div>

							<?php if ( $files ) : ?>
								<ul class="nvs-filelist nvs-filelist--existing">
									<?php foreach ( $files as $file ) : ?>
										<li class="nvs-fileitem<?php echo '' === $file->stored ? ' is-removed' : ''; ?>">
											<span class="dashicons dashicons-media-default" aria-hidden="true"></span>
											<span class="nvs-fileitem-name"><?php echo esc_html( $file->name ); ?></span>
											<span class="nvs-fileitem-meta">
												<?php echo esc_html( Plugin::size( $file->size ) ); ?>
												<?php if ( '' === $file->stored ) : ?>
													· <?php esc_html_e( 'deleted from the server', 'nemvo-share' ); ?>
												<?php else : ?>
													<?php /* translators: %s: number of downloads. */ ?>
													· <?php echo esc_html( sprintf( _n( '%s download', '%s downloads', (int) $file->downloads, 'nemvo-share' ), number_format_i18n( (int) $file->downloads ) ) ); ?>
												<?php endif; ?>
											</span>
											<?php if ( '' !== $file->stored && $editable ) : ?>
												<label class="nvs-remove-toggle">
													<input type="checkbox" name="remove_files[]" value="<?php echo (int) $file->id; ?>">
													<span><?php esc_html_e( 'Remove', 'nemvo-share' ); ?></span>
												</label>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>

							<?php if ( $editable ) : ?>
								<div class="nvs-dropzone" id="nvs-dropzone">
									<span class="dashicons dashicons-upload nvs-dropzone-icon" aria-hidden="true"></span>
									<p class="nvs-dropzone-text">
										<?php esc_html_e( 'Drop files here or', 'nemvo-share' ); ?>
										<label class="nvs-dropzone-button button" for="nvs-file-input"><?php esc_html_e( 'Choose files', 'nemvo-share' ); ?></label>
									</p>
									<input type="file" id="nvs-file-input" name="nemvo_share_files[]" multiple class="nvs-file-input">
									<p class="nvs-limits" id="nvs-limits">
										<?php
										$limits = array();
										if ( $max > 0 ) {
											/* translators: %s: size such as "2 GB". */
											$limits[] = sprintf( __( 'Up to %s per file', 'nemvo-share' ), size_format( $max ) );
										} else {
											$limits[] = __( 'No size limit per file', 'nemvo-share' );
										}
										if ( null !== $free ) {
											/* translators: %s: size such as "40 GB". */
											$limits[] = sprintf( __( '%s free on the server', 'nemvo-share' ), Plugin::size( $free ) );
										}
										$limits[] = __( 'any file type', 'nemvo-share' );
										echo esc_html( implode( ' · ', $limits ) );
										?>
									</p>
									<p class="nvs-limits-detail">
										<?php
										printf(
											/* translators: 1: chunk size, 2: PHP upload limit. */
											esc_html__( 'Files are sent in pieces of %1$s, so the server\'s upload limit of %2$s per request does not apply. Without JavaScript, that limit applies.', 'nemvo-share' ),
											esc_html( size_format( Uploads::chunk_size() ) ),
											esc_html( size_format( wp_max_upload_size() ) )
										);
										?>
									</p>
								</div>
								<ul class="nvs-filelist nvs-filelist--uploads" id="nvs-uploads" aria-live="polite"></ul>
							<?php endif; ?>
						</div>

						<?php if ( $share ) : ?>
							<?php self::activity( $share ); ?>
						<?php endif; ?>
					</div>

					<div class="nvs-side">
						<div class="nvs-card">
							<h2 class="nvs-card-title"><?php esc_html_e( 'Who can open it', 'nemvo-share' ); ?></h2>
							<fieldset class="nvs-choices">
								<legend class="screen-reader-text"><?php esc_html_e( 'Who can open it', 'nemvo-share' ); ?></legend>
								<label class="nvs-choice">
									<input type="radio" name="access" value="link" <?php checked( 'link', $access ); ?> <?php disabled( ! $editable ); ?>>
									<span class="nvs-choice-body">
										<strong><?php esc_html_e( 'Anyone with the link', 'nemvo-share' ); ?></strong>
										<span><?php esc_html_e( 'The link alone opens the share.', 'nemvo-share' ); ?></span>
									</span>
								</label>
								<label class="nvs-choice">
									<input type="radio" name="access" value="password" <?php checked( 'password', $access ); ?> <?php disabled( ! $editable ); ?>>
									<span class="nvs-choice-body">
										<strong><?php esc_html_e( 'Link and password', 'nemvo-share' ); ?></strong>
										<span><?php esc_html_e( 'Send the password another way, for example by text message.', 'nemvo-share' ); ?></span>
									</span>
								</label>
							</fieldset>
							<div class="nvs-password-field" id="nvs-password-field">
								<label class="nvs-field-label" for="nvs-password"><?php esc_html_e( 'Password', 'nemvo-share' ); ?></label>
								<div class="nvs-inline">
									<input type="text" id="nvs-password" name="password" class="nvs-mono" value="<?php echo esc_attr( (string) $password ); ?>" autocomplete="new-password" spellcheck="false" placeholder="<?php esc_attr_e( 'Generated when left empty', 'nemvo-share' ); ?>" <?php disabled( ! $editable ); ?>>
									<button type="button" class="button nvs-icon-button" id="nvs-generate" title="<?php esc_attr_e( 'Generate a new password', 'nemvo-share' ); ?>" <?php disabled( ! $editable ); ?>>
										<span class="dashicons dashicons-randomize" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Generate a new password', 'nemvo-share' ); ?></span>
									</button>
									<button type="button" class="button nvs-icon-button" data-nvs-copy-from="nvs-password" title="<?php esc_attr_e( 'Copy password', 'nemvo-share' ); ?>">
										<span class="dashicons dashicons-admin-page" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Copy password', 'nemvo-share' ); ?></span>
									</button>
								</div>
								<p class="nvs-strength" id="nvs-strength" aria-live="polite"></p>
							</div>
						</div>

						<div class="nvs-card">
							<h2 class="nvs-card-title"><label for="nvs-expiry"><?php esc_html_e( 'Available for', 'nemvo-share' ); ?></label></h2>
							<select id="nvs-expiry" name="expiry" class="nvs-select" <?php disabled( ! $editable ); ?>>
								<?php if ( $share ) : ?>
									<option value="keep" selected>
										<?php
										if ( $expires ) {
											/* translators: %s: date and time. */
											echo esc_html( sprintf( $expires > time() ? __( 'Keep: until %s', 'nemvo-share' ) : __( 'Keep: ended %s', 'nemvo-share' ), Plugin::format_datetime( $expires ) ) );
										} else {
											esc_html_e( 'Keep: no expiry', 'nemvo-share' );
										}
										?>
									</option>
								<?php endif; ?>
								<?php foreach ( Settings::expiry_choices() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( ! $share && Settings::get( 'default_expiry' ) === $value ); ?>>
										<?php
										/* translators: %s: duration such as "7 days". */
										echo esc_html( in_array( $value, array( 'never', 'custom' ), true ) ? $label : sprintf( __( '%s from now', 'nemvo-share' ), $label ) );
										?>
									</option>
								<?php endforeach; ?>
							</select>
							<div class="nvs-expiry-date" id="nvs-expiry-date-wrap" hidden>
								<label class="screen-reader-text" for="nvs-expiry-date"><?php esc_html_e( 'Expiry date and time', 'nemvo-share' ); ?></label>
								<input type="datetime-local" id="nvs-expiry-date" name="expiry_date" value="<?php echo esc_attr( $expires ? wp_date( 'Y-m-d\TH:i', $expires ) : wp_date( 'Y-m-d\TH:i', time() + WEEK_IN_SECONDS ) ); ?>" min="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i' ) ); ?>">
								<?php /* translators: %s: time zone name. */ ?>
								<p class="description"><?php echo esc_html( sprintf( __( 'Time zone: %s', 'nemvo-share' ), wp_timezone_string() ) ); ?></p>
							</div>

							<label class="nvs-check">
								<input type="checkbox" name="burn" value="1" id="nvs-burn" <?php checked( 1, $burn ); ?> <?php disabled( ! $editable ); ?>>
								<span>
									<strong><?php esc_html_e( 'Burn after reading', 'nemvo-share' ); ?></strong>
									<span class="nvs-check-help">
										<?php
										printf(
											/* translators: %d: minutes. */
											esc_html__( 'Can be opened once. The recipient then has %d minutes to read the note and download the files; after every file has been downloaded it is destroyed.', 'nemvo-share' ),
											(int) Settings::get( 'burn_window' )
										);
										?>
									</span>
								</span>
							</label>
							<label class="nvs-check">
								<input type="checkbox" name="auto_delete" value="1" <?php checked( 1, $auto_del ); ?> <?php disabled( ! $editable ); ?>>
								<span>
									<strong><?php esc_html_e( 'Delete contents when it ends', 'nemvo-share' ); ?></strong>
									<span class="nvs-check-help"><?php esc_html_e( 'Files and note are deleted from the server when the share expires or is burned. Otherwise they are kept, so you can extend the share.', 'nemvo-share' ); ?></span>
								</span>
							</label>
							<label class="nvs-check">
								<input type="checkbox" name="notify" value="1" <?php checked( 1, $notify ); ?> <?php disabled( ! $editable ); ?>>
								<span>
									<strong><?php esc_html_e( 'Email me when it is opened', 'nemvo-share' ); ?></strong>
									<span class="nvs-check-help">
										<?php
										$user = wp_get_current_user();
										/* translators: %s: email address. */
										echo esc_html( sprintf( __( 'Sent to %s the first time the share is opened.', 'nemvo-share' ), $share && get_userdata( (int) $share->created_by ) ? get_userdata( (int) $share->created_by )->user_email : $user->user_email ) );
										?>
									</span>
								</span>
							</label>
						</div>

						<div class="nvs-card nvs-publish">
							<?php if ( $editable ) : ?>
								<button type="submit" class="button button-primary button-large nvs-submit" id="nvs-submit">
									<?php echo $is_new ? esc_html__( 'Create share', 'nemvo-share' ) : esc_html__( 'Save changes', 'nemvo-share' ); ?>
								</button>
								<p class="nvs-submit-status" id="nvs-submit-status" aria-live="polite"></p>
							<?php else : ?>
								<p><?php esc_html_e( 'The contents of this share have been deleted from the server, so it can no longer be changed. The record stays for your overview.', 'nemvo-share' ); ?></p>
							<?php endif; ?>
							<?php if ( $share ) : ?>
								<a class="nvs-delete" href="<?php echo esc_url( Admin::action_url( 'delete', $share->id ) ); ?>" data-nvs-confirm="<?php esc_attr_e( 'Delete this share and its files? The link stops working.', 'nemvo-share' ); ?>"><?php esc_html_e( 'Delete share', 'nemvo-share' ); ?></a>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * The link panel at the top of the edit screen.
	 *
	 * @param object      $share    Share row.
	 * @param string|null $password Password ('' without one, null when unreadable).
	 * @param bool        $created  Whether the share was just created.
	 */
	private static function link_panel( $share, $password, $created ) {
		$url     = Shares::url( $share );
		$active  = 'active' === $share->status;
		$classes = 'nvs-linkpanel' . ( $created ? ' is-created' : '' ) . ( $active ? '' : ' is-inactive' );
		?>
		<div class="<?php echo esc_attr( $classes ); ?>">
			<div class="nvs-linkpanel-head">
				<?php if ( $created ) : ?>
					<h2><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e( 'Your share is ready', 'nemvo-share' ); ?></h2>
				<?php else : ?>
					<h2><?php esc_html_e( 'Secret link', 'nemvo-share' ); ?></h2>
				<?php endif; ?>
				<?php echo self::status_badge( $share ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in status_badge(). ?>
			</div>

			<?php if ( ! $active ) : ?>
				<p class="nvs-linkpanel-note">
					<?php
					if ( 'expired' === $share->status ) {
						esc_html_e( 'This share has expired. Choose a new expiry below and save to make the link work again.', 'nemvo-share' );
					} elseif ( 'burned' === $share->status ) {
						esc_html_e( 'This share was opened and burned after reading. The link no longer works.', 'nemvo-share' );
					} else {
						esc_html_e( 'This share is switched off. The link shows that it is not available.', 'nemvo-share' );
					}
					?>
				</p>
			<?php elseif ( $created ) : ?>
				<p class="nvs-linkpanel-note">
					<?php
					echo '' !== (string) $password
						? esc_html__( 'Send the link to the recipient, and the password another way, for example by text message or phone.', 'nemvo-share' )
						: esc_html__( 'Send the link to the recipient. Anyone with the link can open the share, so send it only to them.', 'nemvo-share' );
					?>
				</p>
			<?php endif; ?>

			<?php if ( null === $url ) : ?>
				<p class="nvs-error-text"><?php esc_html_e( 'The link can not be shown because the encryption key changed. Make a new link to share it again.', 'nemvo-share' ); ?></p>
			<?php else : ?>
				<div class="nvs-copyrow">
					<label class="nvs-copyrow-label" for="nvs-link"><?php esc_html_e( 'Link', 'nemvo-share' ); ?></label>
					<input type="text" id="nvs-link" class="nvs-mono" value="<?php echo esc_attr( $url ); ?>" readonly>
					<button type="button" class="button<?php echo $created ? ' button-primary' : ''; ?>" data-nvs-copy-from="nvs-link"><?php esc_html_e( 'Copy link', 'nemvo-share' ); ?></button>
				</div>
				<?php if ( '' !== (string) $password ) : ?>
					<div class="nvs-copyrow">
						<label class="nvs-copyrow-label" for="nvs-link-password"><?php esc_html_e( 'Password', 'nemvo-share' ); ?></label>
						<input type="text" id="nvs-link-password" class="nvs-mono" value="<?php echo esc_attr( (string) $password ); ?>" readonly>
						<button type="button" class="button" data-nvs-copy-from="nvs-link-password"><?php esc_html_e( 'Copy password', 'nemvo-share' ); ?></button>
					</div>
				<?php endif; ?>
				<div class="nvs-linkpanel-actions">
					<?php if ( $active ) : ?>
						<a class="button" href="<?php echo esc_url( Frontend::preview_url( $share ) ); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><?php esc_html_e( 'Preview', 'nemvo-share' ); ?></a>
						<?php
						$mail_body = $url;
						$subject   = '' !== $share->title ? $share->title : get_bloginfo( 'name' );
						?>
						<a class="button" href="<?php echo esc_url( 'mailto:?subject=' . rawurlencode( $subject ) . '&body=' . rawurlencode( $mail_body ) ); ?>"><span class="dashicons dashicons-email" aria-hidden="true"></span><?php esc_html_e( 'Email link', 'nemvo-share' ); ?></a>
						<a class="button-link" href="<?php echo esc_url( Admin::action_url( 'disable', $share->id ) ); ?>"><?php esc_html_e( 'Switch off', 'nemvo-share' ); ?></a>
					<?php elseif ( 'disabled' === $share->status && ! $share->purged_at ) : ?>
						<a class="button" href="<?php echo esc_url( Admin::action_url( 'enable', $share->id ) ); ?>"><?php esc_html_e( 'Switch on', 'nemvo-share' ); ?></a>
					<?php elseif ( 'burned' === $share->status && ! $share->purged_at ) : ?>
						<a class="button" href="<?php echo esc_url( Admin::action_url( 'enable', $share->id ) ); ?>" data-nvs-confirm="<?php esc_attr_e( 'Reopen this share? The link works again and it can be opened once more.', 'nemvo-share' ); ?>"><?php esc_html_e( 'Reopen', 'nemvo-share' ); ?></a>
					<?php endif; ?>
					<?php if ( ! $share->purged_at ) : ?>
						<a class="button-link" href="<?php echo esc_url( Admin::action_url( 'link', $share->id ) ); ?>" data-nvs-confirm="<?php esc_attr_e( 'Make a new link? The current link stops working straight away.', 'nemvo-share' ); ?>"><?php esc_html_e( 'Make a new link', 'nemvo-share' ); ?></a>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php
			$facts = array();
			/* translators: %s: number of views. */
			$facts[] = sprintf( _n( '%s view', '%s views', (int) $share->views, 'nemvo-share' ), number_format_i18n( (int) $share->views ) );
			/* translators: %s: number of downloads. */
			$facts[] = sprintf( _n( '%s download', '%s downloads', (int) $share->downloads, 'nemvo-share' ), number_format_i18n( (int) $share->downloads ) );
			if ( $share->last_access ) {
				/* translators: %s: relative time. */
				$facts[] = sprintf( __( 'last opened %s', 'nemvo-share' ), Plugin::relative( Plugin::ts( $share->last_access ) ) );
			}
			if ( $share->burn && $share->opened_at && 'active' === $share->status ) {
				/* translators: %s: time. */
				$facts[] = sprintf( __( 'burns at %s at the latest', 'nemvo-share' ), wp_date( get_option( 'time_format' ), Plugin::ts( $share->burn_deadline ) ) );
			}
			?>
			<p class="nvs-linkpanel-facts"><?php echo esc_html( implode( ' · ', $facts ) ); ?></p>
		</div>
		<?php
	}

	/**
	 * The activity card.
	 *
	 * @param object $share Share row.
	 */
	private static function activity( $share ) {
		$entries = Shares::log_entries( $share->id, 30 );
		?>
		<div class="nvs-card">
			<h2 class="nvs-card-title"><?php esc_html_e( 'Activity', 'nemvo-share' ); ?></h2>
			<?php if ( ! $entries ) : ?>
				<p class="nvs-muted"><?php esc_html_e( 'Nothing yet.', 'nemvo-share' ); ?></p>
			<?php else : ?>
				<ol class="nvs-activity">
					<?php foreach ( $entries as $entry ) : ?>
						<?php $ts = Plugin::ts( $entry->created_at ); ?>
						<li class="nvs-activity-item nvs-activity-item--<?php echo esc_attr( $entry->event ); ?>">
							<span class="nvs-activity-text"><?php echo esc_html( self::event_text( $entry ) ); ?></span>
							<time datetime="<?php echo esc_attr( gmdate( 'c', $ts ) ); ?>" title="<?php echo esc_attr( Plugin::format_datetime( $ts ) ); ?>"><?php echo esc_html( Plugin::relative( $ts ) ); ?></time>
						</li>
					<?php endforeach; ?>
				</ol>
				<p class="description"><?php esc_html_e( 'Who opened a share is not recorded, only when.', 'nemvo-share' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
