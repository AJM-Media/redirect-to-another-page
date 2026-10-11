<?php

namespace RTAP;

defined( 'ABSPATH' ) || exit;

class Admin {
	const PAGE         = 'redirect-to-another-page';
	const DISMISS_META = 'rtap_donate_dismissed';
	const LIST_LIMIT   = 500;

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_rtap_add_path', array( __CLASS__, 'handle_add_path' ) );
		add_action( 'admin_post_rtap_delete_path', array( __CLASS__, 'handle_delete_path' ) );
		add_action( 'admin_post_rtap_dismiss_donate', array( __CLASS__, 'handle_dismiss' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RTAP_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
	}

	public static function page_url() {
		return admin_url( 'tools.php?page=' . self::PAGE );
	}

	public static function menu() {
		add_management_page(
			__( 'Redirect to Another Page', 'redirect-to-another-page' ),
			__( 'Redirects', 'redirect-to-another-page' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function enqueue( $hook ) {
		if ( 'tools_page_' . self::PAGE === $hook ) {
			wp_enqueue_style( 'rtap-admin', RTAP_URL . 'assets/admin.css', array(), RTAP_VERSION );
		}
	}

	private static function deny() {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'redirect-to-another-page' ) );
	}

	private static function done( $message ) {
		set_transient( 'rtap_message_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = Import::scan();
		?>
		<div class="wrap rtap">
			<h1><?php esc_html_e( 'Redirect to Another Page', 'redirect-to-another-page' ); ?></h1>

			<?php self::render_messages(); ?>
			<?php self::render_import( $state ); ?>

			<h2><?php esc_html_e( 'Redirects for old addresses', 'redirect-to-another-page' ); ?></h2>
			<p><?php esc_html_e( 'Send visitors from an address on this site to another page or site. The old address doesn\'t need a page.', 'redirect-to-another-page' ); ?></p>
			<?php self::render_paths(); ?>

			<h2><?php esc_html_e( 'Page and post redirects', 'redirect-to-another-page' ); ?></h2>
			<p><?php esc_html_e( 'Set these in the Redirect box on each page\'s or post\'s edit screen.', 'redirect-to-another-page' ); ?></p>
			<?php self::render_posts(); ?>

			<?php self::render_donate(); ?>
		</div>
		<?php
	}

	private static function render_messages() {
		$key     = 'rtap_message_' . get_current_user_id();
		$message = get_transient( $key );
		if ( $message ) {
			delete_transient( $key );
			$messages = array(
				'added'   => array( 'success', __( 'Redirect added.', 'redirect-to-another-page' ) ),
				'deleted' => array( 'success', __( 'Redirect deleted.', 'redirect-to-another-page' ) ),
				'exists'  => array( 'error', __( 'There is already a redirect for that address.', 'redirect-to-another-page' ) ),
				'from'    => array( 'error', __( 'Enter the old address as a path on this site, such as /old-page/. The home page can\'t be redirected here.', 'redirect-to-another-page' ) ),
				'to'      => array( 'error', __( 'Enter the new address as a full address starting with https:// or http://, or a path on this site starting with /.', 'redirect-to-another-page' ) ),
				'loop'    => array( 'error', __( 'That redirect would lead back to the old address, so it wasn\'t added.', 'redirect-to-another-page' ) ),
			);
			if ( isset( $messages[ $message ] ) ) {
				echo '<div class="notice notice-' . esc_attr( $messages[ $message ][0] ) . ' is-dismissible"><p>' . esc_html( $messages[ $message ][1] ) . '</p></div>';
			}
		}

		$key    = 'rtap_import_result_' . get_current_user_id();
		$result = get_transient( $key );
		if ( is_array( $result ) ) {
			delete_transient( $key );
			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
				/* translators: 1: number of page and post redirects, 2: number of other redirects */
				esc_html__( 'Imported %1$s page and post redirects and %2$s redirects for old addresses.', 'redirect-to-another-page' ),
				esc_html( number_format_i18n( $result['posts'] ) ),
				esc_html( number_format_i18n( $result['paths'] ) )
			);
			if ( $result['skipped'] > 0 ) {
				echo ' ';
				printf(
					/* translators: %s: number of redirects */
					esc_html( _n( '%s redirect was skipped because its address isn\'t valid.', '%s redirects were skipped because their addresses aren\'t valid.', $result['skipped'], 'redirect-to-another-page' ) ),
					esc_html( number_format_i18n( $result['skipped'] ) )
				);
			}
			echo ' ' . esc_html__( 'You can now deactivate and delete Quick Page/Post Redirect.', 'redirect-to-another-page' ) . '</p></div>';
		}
	}

	private static function render_import( $state ) {
		if ( ! in_array( $state['state'], array( 'pending', 'dismissed' ), true ) ) {
			return;
		}
		?>
		<div class="rtap-import">
			<h2><?php esc_html_e( 'Import from Quick Page/Post Redirect', 'redirect-to-another-page' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: number of page and post redirects, 2: number of other redirects */
					esc_html__( 'Found %1$s page and post redirects and %2$s redirects for old addresses saved by Quick Page/Post Redirect.', 'redirect-to-another-page' ),
					esc_html( number_format_i18n( $state['posts'] ) ),
					esc_html( number_format_i18n( $state['paths'] ) )
				);
				echo ' ';
				if ( Import::qppr_active() ) {
					esc_html_e( 'Quick Page/Post Redirect is still active, so it is handling them for now.', 'redirect-to-another-page' );
				} else {
					esc_html_e( 'This plugin is already following them. Import them so they are kept as this plugin\'s own redirects.', 'redirect-to-another-page' );
				}
				?>
			</p>
			<p><?php esc_html_e( 'Redirects of the "meta" type become 302 redirects. Nothing is deleted from Quick Page/Post Redirect\'s data.', 'redirect-to-another-page' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rtap_import">
				<?php wp_nonce_field( 'rtap_import' ); ?>
				<?php submit_button( __( 'Import redirects', 'redirect-to-another-page' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	private static function render_paths() {
		$paths = Redirects::paths();
		?>
		<table class="widefat striped rtap-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Old address', 'redirect-to-another-page' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Redirects to', 'redirect-to-another-page' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'redirect-to-another-page' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'redirect-to-another-page' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $paths ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No redirects yet.', 'redirect-to-another-page' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $paths as $index => $item ) : ?>
					<?php
					$delete = wp_nonce_url(
						add_query_arg(
							array(
								'action' => 'rtap_delete_path',
								'index'  => $index,
								'from'   => rawurlencode( $item['from'] ),
							),
							admin_url( 'admin-post.php' )
						),
						'rtap_delete_path'
					);
					?>
					<tr>
						<td><code><?php echo esc_html( $item['from'] ); ?></code></td>
						<td><?php echo esc_html( $item['to'] ); ?></td>
						<td><?php echo esc_html( Redirects::type( $item['type'] ) ); ?></td>
						<td><a href="<?php echo esc_url( $delete ); ?>" class="rtap-delete"><?php esc_html_e( 'Delete', 'redirect-to-another-page' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rtap-add">
			<input type="hidden" name="action" value="rtap_add_path">
			<?php wp_nonce_field( 'rtap_add_path' ); ?>
			<p>
				<label for="rtap-from"><?php esc_html_e( 'Old address', 'redirect-to-another-page' ); ?></label>
				<input type="text" id="rtap-from" name="rtap_from" class="regular-text" placeholder="/old-page/" required>
			</p>
			<p>
				<label for="rtap-to"><?php esc_html_e( 'Redirect to', 'redirect-to-another-page' ); ?></label>
				<input type="text" id="rtap-to" name="rtap_to" class="regular-text" placeholder="https://" required>
			</p>
			<p>
				<label for="rtap-path-type"><?php esc_html_e( 'Type', 'redirect-to-another-page' ); ?></label>
				<select id="rtap-path-type" name="rtap_type">
					<option value="301"><?php esc_html_e( '301: moved permanently', 'redirect-to-another-page' ); ?></option>
					<option value="302"><?php esc_html_e( '302: moved temporarily', 'redirect-to-another-page' ); ?></option>
					<option value="307"><?php esc_html_e( '307: temporary, keeps the request method', 'redirect-to-another-page' ); ?></option>
				</select>
			</p>
			<?php submit_button( __( 'Add redirect', 'redirect-to-another-page' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	private static function render_posts() {
		$ids = get_posts(
			array(
				'post_type'        => Redirects::post_types(),
				'post_status'      => 'any',
				'posts_per_page'   => self::LIST_LIMIT,
				'fields'           => 'ids',
				'orderby'          => 'title',
				'order'            => 'ASC',
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Redirects::META_URL,
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);
		if ( ! $ids ) {
			echo '<p><em>' . esc_html__( 'No page or post redirects yet.', 'redirect-to-another-page' ) . '</em></p>';
			return;
		}
		update_meta_cache( 'post', $ids );
		?>
		<table class="widefat striped rtap-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Page or post', 'redirect-to-another-page' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Redirects to', 'redirect-to-another-page' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'redirect-to-another-page' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $ids as $post_id ) : ?>
					<?php
					$redirect = Redirects::get( $post_id );
					if ( ! $redirect ) {
						continue;
					}
					$title = get_the_title( $post_id );
					$edit  = get_edit_post_link( $post_id );
					?>
					<tr>
						<td>
							<?php if ( $edit ) : ?>
								<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( '' !== $title ? $title : __( '(no title)', 'redirect-to-another-page' ) ); ?></a>
							<?php else : ?>
								<?php echo esc_html( '' !== $title ? $title : __( '(no title)', 'redirect-to-another-page' ) ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $redirect['url'] ); ?></td>
						<td><?php echo esc_html( $redirect['type'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function handle_add_path() {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::deny();
		}
		check_admin_referer( 'rtap_add_path' );

		$from = Redirects::clean_from( isset( $_POST['rtap_from'] ) ? wp_unslash( $_POST['rtap_from'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$to   = Redirects::clean_url( isset( $_POST['rtap_to'] ) ? wp_unslash( $_POST['rtap_to'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$type = Redirects::type( isset( $_POST['rtap_type'] ) ? absint( $_POST['rtap_type'] ) : 301 );

		if ( '' === $from ) {
			self::done( 'from' );
		}
		if ( '' === $to ) {
			self::done( 'to' );
		}

		$paths   = Redirects::paths();
		$old_url = Redirects::origin() . $from;
		foreach ( $paths as $item ) {
			if ( Redirects::key( Redirects::origin() . $item['from'] ) === Redirects::key( $old_url ) ) {
				self::done( 'exists' );
			}
		}

		$new = array(
			'from' => $from,
			'to'   => $to,
			'type' => $type,
		);
		$paths[] = $new;
		Redirects::save_paths( $paths );

		if ( '' === Redirects::resolve( $new, $old_url ) ) {
			array_pop( $paths );
			Redirects::save_paths( $paths );
			self::done( 'loop' );
		}

		self::done( 'added' );
	}

	public static function handle_delete_path() {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::deny();
		}
		check_admin_referer( 'rtap_delete_path' );

		$index = isset( $_GET['index'] ) ? absint( $_GET['index'] ) : -1;
		$from  = isset( $_GET['from'] ) ? wp_unslash( $_GET['from'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$paths = Redirects::paths();

		if ( isset( $paths[ $index ] ) && $paths[ $index ]['from'] === $from ) {
			unset( $paths[ $index ] );
			Redirects::save_paths( $paths );
		}

		self::done( 'deleted' );
	}

	private static function render_donate() {
		if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=rtap_dismiss_donate' ), 'rtap_dismiss_donate' );
		?>
		<div class="rtap-donate">
			<p><?php esc_html_e( 'This plugin is free. If it saved your redirects, you can buy me a coffee.', 'redirect-to-another-page' ); ?></p>
			<p>
				<a class="button" href="<?php echo esc_url( RTAP_DONATE_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Buy me a coffee', 'redirect-to-another-page' ); ?></a>
				<a class="rtap-dismiss" href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Hide this', 'redirect-to-another-page' ); ?></a>
			</p>
		</div>
		<?php
	}

	public static function handle_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::deny();
		}
		check_admin_referer( 'rtap_dismiss_donate' );

		update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );

		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Redirects', 'redirect-to-another-page' ) . '</a>' );
		return $links;
	}

	public static function row_meta( $meta, $file ) {
		if ( plugin_basename( RTAP_FILE ) === $file ) {
			$meta[] = '<a href="' . esc_url( RTAP_DONATE_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Buy me a coffee', 'redirect-to-another-page' ) . '</a>';
		}
		return $meta;
	}
}
