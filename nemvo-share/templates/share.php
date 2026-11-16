<?php
/**
 * The share as recipients see it.
 *
 * Themes can override this file by copying it to <theme>/nemvo-share/share.php.
 *
 * Available variables:
 *   $view       array  state (open, password, confirm, ended, empty), title, and depending on the state:
 *                      facts, note, files, error, preview, action, nonce, reason, message, burned_now.
 *   $show_title bool   Whether to print the title (false when the theme printed it as the page title).
 *
 * @package NemvoShare
 */

use NemvoShare\Frontend;

defined( 'ABSPATH' ) || exit;

$nemvo_share_state = $view['state'];
?>
<div class="nvs-share nvs-share--<?php echo esc_attr( $nemvo_share_state ); ?>">

	<?php if ( 'empty' === $nemvo_share_state ) : ?>
		<div class="nvs-message">
			<?php echo Frontend::icon( 'link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
			<p><?php esc_html_e( 'Shared notes and files open on this page. Use the full link you received to see what was shared with you.', 'nemvo-share' ); ?></p>
		</div>

	<?php elseif ( 'ended' === $nemvo_share_state ) : ?>
		<div class="nvs-message nvs-message--<?php echo esc_attr( $view['reason'] ); ?>">
			<?php echo Frontend::icon( 'missing' === $view['reason'] ? 'link' : ( 'expired' === $view['reason'] ? 'clock' : 'lock' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
			<?php if ( $show_title ) : ?>
				<h2 class="nvs-title"><?php echo esc_html( $view['title'] ); ?></h2>
			<?php endif; ?>
			<p><?php echo esc_html( $view['message'] ); ?></p>
		</div>

	<?php else : ?>

		<?php if ( ! empty( $view['preview'] ) ) : ?>
			<p class="nvs-preview" role="note">
				<?php echo Frontend::icon( 'eye' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
				<span><?php esc_html_e( 'Preview: this is what recipients see once they have opened the share. Previewing does not count as opening it.', 'nemvo-share' ); ?></span>
			</p>
		<?php endif; ?>

		<?php if ( $show_title ) : ?>
			<h2 class="nvs-title"><?php echo esc_html( $view['title'] ); ?></h2>
		<?php endif; ?>

		<ul class="nvs-facts">
			<?php foreach ( $view['facts'] as $nemvo_share_fact ) : ?>
				<li class="nvs-fact nvs-fact--<?php echo esc_attr( $nemvo_share_fact['icon'] ); ?>">
					<?php echo Frontend::icon( $nemvo_share_fact['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
					<span><?php echo esc_html( $nemvo_share_fact['text'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( ! empty( $view['error'] ) ) : ?>
			<p class="nvs-error" role="alert">
				<?php echo Frontend::icon( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
				<span><?php echo esc_html( $view['error'] ); ?></span>
			</p>
		<?php endif; ?>

		<?php if ( 'password' === $nemvo_share_state ) : ?>
			<form class="nvs-form" method="post" action="<?php echo esc_url( $view['action'] ); ?>">
				<p class="nvs-lead"><?php esc_html_e( 'This share is protected with a password. The sender should have given it to you separately.', 'nemvo-share' ); ?></p>
				<?php if ( $view['share']->burn ) : ?>
					<p class="nvs-warning"><?php esc_html_e( 'It can be opened only once: the right password opens it straight away, and nobody can open it after you.', 'nemvo-share' ); ?></p>
				<?php endif; ?>
				<label class="nvs-label" for="nvs-password"><?php esc_html_e( 'Password', 'nemvo-share' ); ?></label>
				<div class="nvs-password">
					<input class="nvs-input" type="password" id="nvs-password" name="nemvo_share_password" required autocomplete="off" autocapitalize="off" spellcheck="false" autofocus>
					<button type="button" class="nvs-reveal" data-nvs-reveal="nvs-password" data-hide="<?php esc_attr_e( 'Hide', 'nemvo-share' ); ?>" aria-pressed="false" hidden><?php esc_html_e( 'Show', 'nemvo-share' ); ?></button>
				</div>
				<input type="hidden" name="nemvo_share_action" value="unlock">
				<input type="hidden" name="nemvo_share_nonce" value="<?php echo esc_attr( $view['nonce'] ); ?>">
				<button type="submit" class="nvs-button wp-element-button">
					<?php echo $view['share']->burn ? esc_html__( 'Open share', 'nemvo-share' ) : esc_html__( 'Unlock', 'nemvo-share' ); ?>
				</button>
			</form>

		<?php elseif ( 'confirm' === $nemvo_share_state ) : ?>
			<form class="nvs-form" method="post" action="<?php echo esc_url( $view['action'] ); ?>">
				<p class="nvs-warning"><?php esc_html_e( 'This share can be opened only once. Open it when you are ready to read and save what is inside: nobody can open it after you.', 'nemvo-share' ); ?></p>
				<?php if ( is_user_logged_in() && current_user_can( \NemvoShare\Plugin::capability() ) ) : ?>
					<p class="nvs-hint"><?php esc_html_e( 'You are signed in as an administrator. Opening the share here uses it up; use Preview in wp-admin to look without opening it.', 'nemvo-share' ); ?></p>
				<?php endif; ?>
				<input type="hidden" name="nemvo_share_action" value="open">
				<input type="hidden" name="nemvo_share_nonce" value="<?php echo esc_attr( $view['nonce'] ); ?>">
				<button type="submit" class="nvs-button wp-element-button"><?php esc_html_e( 'Open share', 'nemvo-share' ); ?></button>
			</form>

		<?php else : ?>

			<?php if ( ! empty( $view['burned_now'] ) ) : ?>
				<p class="nvs-warning" role="status"><?php esc_html_e( 'This note has now been destroyed on the server. Copy what you need before you leave or reload this page.', 'nemvo-share' ); ?></p>
			<?php endif; ?>

			<?php if ( '' !== $view['note'] ) : ?>
				<section class="nvs-note" aria-label="<?php esc_attr_e( 'Note', 'nemvo-share' ); ?>">
					<div class="nvs-note-head">
						<span class="nvs-note-label">
							<?php echo Frontend::icon( 'note' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
							<?php esc_html_e( 'Note', 'nemvo-share' ); ?>
						</span>
						<button type="button" class="nvs-copy" data-nvs-copy="nvs-note-text" data-copied="<?php esc_attr_e( 'Copied', 'nemvo-share' ); ?>" hidden>
							<?php echo Frontend::icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
							<span><?php esc_html_e( 'Copy', 'nemvo-share' ); ?></span>
						</button>
					</div>
					<div class="nvs-note-text" id="nvs-note-text"><?php echo esc_html( $view['note'] ); ?></div>
				</section>
			<?php endif; ?>

			<?php if ( ! empty( $view['files'] ) ) : ?>
				<ul class="nvs-files" aria-label="<?php esc_attr_e( 'Files', 'nemvo-share' ); ?>">
					<?php foreach ( $view['files'] as $nemvo_share_file ) : ?>
						<li class="nvs-file">
							<?php echo Frontend::icon( 'file' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
							<span class="nvs-file-info">
								<span class="nvs-file-name"><?php echo esc_html( $nemvo_share_file['name'] ); ?></span>
								<span class="nvs-file-size"><?php echo esc_html( \NemvoShare\Plugin::size( $nemvo_share_file['size'] ) ); ?></span>
							</span>
							<?php if ( $nemvo_share_file['available'] ) : ?>
								<a class="nvs-button wp-element-button nvs-download" href="<?php echo esc_url( $nemvo_share_file['url'] ); ?>" download>
									<?php echo Frontend::icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
									<span><?php esc_html_e( 'Download', 'nemvo-share' ); ?><span class="screen-reader-text"> <?php echo esc_html( $nemvo_share_file['name'] ); ?></span></span>
								</a>
							<?php else : ?>
								<span class="nvs-unavailable"><?php esc_html_e( 'Removed', 'nemvo-share' ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( '' === $view['note'] && empty( $view['files'] ) ) : ?>
				<p class="nvs-lead"><?php esc_html_e( 'This share is empty.', 'nemvo-share' ); ?></p>
			<?php endif; ?>

		<?php endif; ?>
	<?php endif; ?>
</div>
