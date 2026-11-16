<?php
/**
 * The list of shares.
 *
 * @package NemvoShare
 */

namespace NemvoShare\Admin;

use NemvoShare\Frontend;
use NemvoShare\Plugin;
use NemvoShare\Shares;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table for shares.
 */
class List_Table extends \WP_List_Table {

	/**
	 * File counts per share on this page.
	 *
	 * @var array
	 */
	private $stats = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'share',
				'plural'   => 'shares',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Renders the whole screen.
	 */
	public static function render_page() {
		Shares::sweep();
		$table = new self();
		$table->prepare_items();
		?>
		<div class="wrap nvs-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Shares', 'nemvo-share' ); ?></h1>
			<a href="<?php echo esc_url( Admin::url( Admin::SLUG_NEW ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Share', 'nemvo-share' ); ?></a>
			<hr class="wp-header-end">

			<?php if ( ! $table->has_items() && ! $table->filtered() ) : ?>
				<div class="nvs-empty">
					<span class="dashicons dashicons-lock" aria-hidden="true"></span>
					<h2><?php esc_html_e( 'Share notes and files through secret links', 'nemvo-share' ); ?></h2>
					<p><?php esc_html_e( 'Add a note, files or both, choose who can open it and for how long, and send the link. Files are encrypted on the server and can only be downloaded through the link.', 'nemvo-share' ); ?></p>
					<p><a class="button button-primary button-hero" href="<?php echo esc_url( Admin::url( Admin::SLUG_NEW ) ); ?>"><?php esc_html_e( 'Create your first share', 'nemvo-share' ); ?></a></p>
				</div>
			<?php else : ?>
				<?php $table->views(); ?>
				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG ); ?>">
					<?php
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- keeps the filter while searching.
					if ( ! empty( $_GET['status'] ) ) :
						?>
						<input type="hidden" name="status" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['status'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
					<?php endif; ?>
					<?php $table->search_box( __( 'Search shares', 'nemvo-share' ), 'nvs-search' ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="nemvo_share_bulk">
					<?php $table->display(); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Whether a filter or search is active.
	 *
	 * @return bool
	 */
	public function filtered() {
		return ! empty( $_GET['status'] ) || ! empty( $_GET['s'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Loads the rows.
	 */
	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters.
		$per_page = $this->get_items_per_page( 'nemvo_share_per_page', 20 );
		$result   = Shares::query(
			array(
				'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
				'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
				'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '',
				'order'    => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '',
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
			)
		);
		// phpcs:enable
		$this->items = $result['items'];
		$this->stats = Shares::file_stats( wp_list_pluck( $this->items, 'id' ) );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox">',
			'title'    => __( 'Title', 'nemvo-share' ),
			'contents' => __( 'Contents', 'nemvo-share' ),
			'access'   => __( 'Access', 'nemvo-share' ),
			'status'   => __( 'Status', 'nemvo-share' ),
			'expires'  => __( 'Expires', 'nemvo-share' ),
			'activity' => __( 'Opened', 'nemvo-share' ),
			'created'  => __( 'Created', 'nemvo-share' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'title'    => array( 'title', false ),
			'status'   => array( 'status', false ),
			'expires'  => array( 'expires_at', false ),
			'activity' => array( 'views', true ),
			'created'  => array( 'created_at', true ),
		);
	}

	/**
	 * Status filter links.
	 *
	 * @return array
	 */
	protected function get_views() {
		$counts  = Shares::counts();
		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$labels  = array(
			'all'      => __( 'All', 'nemvo-share' ),
			'active'   => __( 'Active', 'nemvo-share' ),
			'expired'  => __( 'Expired', 'nemvo-share' ),
			'burned'   => __( 'Burned', 'nemvo-share' ),
			'disabled' => __( 'Switched off', 'nemvo-share' ),
		);
		$views   = array();
		foreach ( $labels as $status => $label ) {
			if ( 'all' !== $status && empty( $counts[ $status ] ) ) {
				continue;
			}
			$url              = 'all' === $status ? Admin::url() : Admin::url( Admin::SLUG, array( 'status' => $status ) );
			$views[ $status ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $url ),
				$current === $status ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $counts[ $status ] ) )
			);
		}
		return $views;
	}

	/**
	 * Bulk actions. Their names are prefixed so they don't clash with admin-post.php's "action".
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'disable' => __( 'Switch off', 'nemvo-share' ),
			'delete'  => __( 'Delete', 'nemvo-share' ),
		);
	}

	/**
	 * Bulk action selector with our own field names.
	 *
	 * @param string $which top or bottom.
	 */
	protected function bulk_actions( $which = '' ) {
		$name = 'top' === $which ? 'bulk_action' : 'bulk_action2';
		?>
		<label for="nvs-<?php echo esc_attr( $name ); ?>" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'nemvo-share' ); ?></label>
		<select name="<?php echo esc_attr( $name ); ?>" id="nvs-<?php echo esc_attr( $name ); ?>">
			<option value="-1"><?php esc_html_e( 'Bulk actions', 'nemvo-share' ); ?></option>
			<?php foreach ( $this->get_bulk_actions() as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
		submit_button( __( 'Apply', 'nemvo-share' ), 'action', '', false, array( 'id' => 'nvs-do' . $name ) );
	}

	/**
	 * Message when nothing matches.
	 */
	public function no_items() {
		esc_html_e( 'No shares found.', 'nemvo-share' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param object $item Share.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<label class="screen-reader-text" for="nvs-cb-%1$d">%2$s</label><input type="checkbox" id="nvs-cb-%1$d" name="share[]" value="%1$d">',
			(int) $item->id,
			/* translators: %s: share title. */
			esc_html( sprintf( __( 'Select %s', 'nemvo-share' ), $item->title ) )
		);
	}

	/**
	 * Title with row actions.
	 *
	 * @param object $item Share.
	 * @return string
	 */
	protected function column_title( $item ) {
		$title = '' !== $item->title ? $item->title : __( '(no title)', 'nemvo-share' );
		$out   = sprintf( '<strong><a class="row-title" href="%1$s">%2$s</a></strong>', esc_url( Admin::edit_url( $item->id ) ), esc_html( $title ) );

		$url     = 'active' === $item->status ? Shares::url( $item ) : null;
		$actions = array(
			'edit' => sprintf( '<a href="%1$s">%2$s</a>', esc_url( Admin::edit_url( $item->id ) ), esc_html__( 'Edit', 'nemvo-share' ) ),
		);
		if ( $url ) {
			$actions['copy']    = sprintf( '<button type="button" class="button-link nvs-copy-link" data-nvs-copy="%1$s">%2$s</button>', esc_attr( $url ), esc_html__( 'Copy link', 'nemvo-share' ) );
			$actions['preview'] = sprintf( '<a href="%1$s" target="_blank" rel="noopener">%2$s</a>', esc_url( Frontend::preview_url( $item ) ), esc_html__( 'Preview', 'nemvo-share' ) );
		}
		if ( 'active' === $item->status ) {
			$actions['disable'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( add_query_arg( 'from', 'list', Admin::action_url( 'disable', $item->id ) ) ), esc_html__( 'Switch off', 'nemvo-share' ) );
		} elseif ( ! $item->purged_at ) {
			$actions['enable'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( add_query_arg( 'from', 'list', Admin::action_url( 'enable', $item->id ) ) ), esc_html__( 'Switch on', 'nemvo-share' ) );
		}
		$actions['delete'] = sprintf(
			'<a href="%1$s" class="submitdelete" data-nvs-confirm="%2$s">%3$s</a>',
			esc_url( Admin::action_url( 'delete', $item->id ) ),
			esc_attr__( 'Delete this share and its files? The link stops working.', 'nemvo-share' ),
			esc_html__( 'Delete', 'nemvo-share' )
		);
		return $out . $this->row_actions( $actions );
	}

	/**
	 * Note and files.
	 *
	 * @param object $item Share.
	 * @return string
	 */
	protected function column_contents( $item ) {
		$parts = array();
		if ( $item->has_note ) {
			$parts[] = '<span class="nvs-chip"><span class="dashicons dashicons-media-text" aria-hidden="true"></span>' . esc_html__( 'Note', 'nemvo-share' ) . '</span>';
		}
		$stats = isset( $this->stats[ $item->id ] ) ? $this->stats[ $item->id ] : null;
		if ( $stats ) {
			$parts[] = '<span class="nvs-chip"><span class="dashicons dashicons-media-default" aria-hidden="true"></span>'
				/* translators: 1: number of files, 2: total size such as "4 MB". */
				. esc_html( sprintf( _n( '%1$d file, %2$s', '%1$d files, %2$s', $stats['count'], 'nemvo-share' ), $stats['count'], Plugin::size( $stats['size'] ) ) ) . '</span>';
		}
		if ( $item->purged_at ) {
			return '<span class="nvs-muted">' . esc_html__( 'Contents removed', 'nemvo-share' ) . '</span>';
		}
		return $parts ? implode( ' ', $parts ) : '<span class="nvs-muted">' . esc_html__( 'Empty', 'nemvo-share' ) . '</span>';
	}

	/**
	 * Password and burn after reading.
	 *
	 * @param object $item Share.
	 * @return string
	 */
	protected function column_access( $item ) {
		$out = '' !== $item->password_hash
			? '<span class="nvs-chip"><span class="dashicons dashicons-lock" aria-hidden="true"></span>' . esc_html__( 'Password', 'nemvo-share' ) . '</span>'
			: '<span class="nvs-chip"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span>' . esc_html__( 'Link only', 'nemvo-share' ) . '</span>';
		if ( $item->burn ) {
			$out .= ' <span class="nvs-chip nvs-chip--burn"><span class="dashicons dashicons-hourglass" aria-hidden="true"></span>' . esc_html__( 'Burn after reading', 'nemvo-share' ) . '</span>';
		}
		return $out;
	}

	/**
	 * Status badge.
	 *
	 * @param object $item Share.
	 * @return string
	 */
	protected function column_status( $item ) {
		return Editor::status_badge( $item );
	}

	/**
	 * Expiry.
	 *
	 * @param object $item Share.
	 * @return string
	 */
	protected function column_expires( $item ) {
		$ended = Plugin::ts( $item->ended_at );
		if ( 'active' !== $item->status && $ended ) {
			/* translators: %s: relative time such as "2 hours ago". */
			return sprintf( '<span class="nvs-muted" title="%1$s">%2$s</span>', esc_attr( Plugin::format_datetime( $ended ) ), esc_html( sprintf( __( 'Ended %s', 'nemvo-share' ), Plugin::relative( $ended ) ) ) );
		}
		$ts = Plugin::ts( $item->expires_at );
		if ( ! $ts ) {
			return '<span class="nvs-muted">' . esc_html__( 'Never', 'nemvo-share' ) . '</span>';
		}
		return sprintf( '<span title="%1$s">%2$s</span>', esc_attr( Plugin::format_datetime( $ts ) ), esc_html( Plugin::relative( $ts ) ) );
	}

	/**
	 * Views and downloads.
	 *
	 * @param object $item Share.
	 * @return string
	 */
	protected function column_activity( $item ) {
		if ( ! $item->views && ! $item->downloads ) {
			return '<span class="nvs-muted">' . esc_html__( 'Not yet', 'nemvo-share' ) . '</span>';
		}
		$counts = implode(
			', ',
			array(
				/* translators: %s: number of views. */
				sprintf( _n( '%s view', '%s views', (int) $item->views, 'nemvo-share' ), number_format_i18n( $item->views ) ),
				/* translators: %s: number of downloads. */
				sprintf( _n( '%s download', '%s downloads', (int) $item->downloads, 'nemvo-share' ), number_format_i18n( $item->downloads ) ),
			)
		);
		$last   = Plugin::ts( $item->last_access );
		/* translators: %s: relative time such as "2 hours ago". */
		return esc_html( $counts ) . ( $last ? '<br><span class="nvs-muted">' . esc_html( sprintf( __( 'Last opened %s', 'nemvo-share' ), Plugin::relative( $last ) ) ) . '</span>' : '' );
	}

	/**
	 * Creation date and author.
	 *
	 * @param object $item Share.
	 * @return string
	 */
	protected function column_created( $item ) {
		$ts   = Plugin::ts( $item->created_at );
		$user = get_userdata( (int) $item->created_by );
		$out  = sprintf( '<span title="%1$s">%2$s</span>', esc_attr( Plugin::format_datetime( $ts ) ), esc_html( wp_date( get_option( 'date_format' ), $ts ) ) );
		if ( $user ) {
			$out .= '<br><span class="nvs-muted">' . esc_html( $user->display_name ) . '</span>';
		}
		return $out;
	}
}
