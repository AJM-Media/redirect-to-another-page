<?php

namespace RTAP;

defined( 'ABSPATH' ) || exit;

class Editor {
	const NONCE = 'rtap_save_redirect';

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function can( $post ) {
		$type = get_post_type_object( $post->post_type );
		return $type && current_user_can( 'edit_post', $post->ID ) && current_user_can( $type->cap->publish_posts );
	}

	public static function add( $post_type, $post ) {
		if ( ! $post instanceof \WP_Post || ! in_array( $post_type, Redirects::post_types(), true ) || ! self::can( $post ) ) {
			return;
		}
		add_meta_box( 'rtap-redirect', __( 'Redirect', 'redirect-to-another-page' ), array( __CLASS__, 'render' ), $post_type, 'side', 'default' );
	}

	public static function enqueue( $hook ) {
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_style( 'rtap-admin', RTAP_URL . 'assets/admin.css', array(), RTAP_VERSION );
		}
	}

	public static function render( $post ) {
		$own      = metadata_exists( 'post', $post->ID, Redirects::META_URL );
		$redirect = $own ? Redirects::get( $post->ID ) : Import::legacy_raw( $post->ID );
		$values   = $redirect ? $redirect : array(
			'url'      => '',
			'type'     => 301,
			'new_tab'  => false,
			'nofollow' => false,
			'direct'   => false,
		);
		$error    = get_transient( 'rtap_error_' . get_current_user_id() . '_' . $post->ID );

		wp_nonce_field( self::NONCE, 'rtap_nonce' );
		?>
		<div class="rtap-box">
			<?php if ( 'invalid' === $error ) : ?>
				<p class="rtap-error"><?php esc_html_e( 'The redirect wasn\'t saved: enter a full address starting with https:// or http://, or a path on this site starting with /.', 'redirect-to-another-page' ); ?></p>
			<?php elseif ( 'self' === $error ) : ?>
				<p class="rtap-error"><?php esc_html_e( 'The redirect wasn\'t saved: it points at this page.', 'redirect-to-another-page' ); ?></p>
			<?php endif; ?>

			<?php if ( ! $own && $redirect ) : ?>
				<p class="rtap-note"><?php esc_html_e( 'Copied from Quick Page/Post Redirect. It will be saved here when you update this page.', 'redirect-to-another-page' ); ?></p>
			<?php endif; ?>

			<?php if ( $redirect && 'publish' === $post->post_status && '' === Redirects::resolve( $redirect, Frontend::unfiltered_permalink( $post->ID ) ) ) : ?>
				<p class="rtap-error"><?php esc_html_e( 'This redirect leads back to this page through other redirects, so it is switched off. Change one of them to fix the loop.', 'redirect-to-another-page' ); ?></p>
			<?php endif; ?>

			<p>
				<label for="rtap-url"><?php esc_html_e( 'Redirect to', 'redirect-to-another-page' ); ?></label>
				<input type="text" id="rtap-url" name="rtap_url" value="<?php echo esc_attr( $values['url'] ); ?>" placeholder="https://" class="widefat" inputmode="url" autocomplete="off">
				<span class="description"><?php esc_html_e( 'A full address, or a path on this site such as /contact/. Leave empty for no redirect.', 'redirect-to-another-page' ); ?></span>
			</p>
			<p>
				<label for="rtap-type"><?php esc_html_e( 'Type', 'redirect-to-another-page' ); ?></label>
				<select id="rtap-type" name="rtap_type" class="widefat">
					<option value="301" <?php selected( $values['type'], 301 ); ?>><?php esc_html_e( '301: moved permanently', 'redirect-to-another-page' ); ?></option>
					<option value="302" <?php selected( $values['type'], 302 ); ?>><?php esc_html_e( '302: moved temporarily', 'redirect-to-another-page' ); ?></option>
					<option value="307" <?php selected( $values['type'], 307 ); ?>><?php esc_html_e( '307: temporary, keeps the request method', 'redirect-to-another-page' ); ?></option>
				</select>
			</p>
			<p>
				<label><input type="checkbox" name="rtap_direct" value="1" <?php checked( $values['direct'] ); ?>> <?php esc_html_e( 'Link straight to the new address', 'redirect-to-another-page' ); ?></label><br>
				<span class="description"><?php esc_html_e( 'Menus and post lists point at the new address instead of this page.', 'redirect-to-another-page' ); ?></span>
			</p>
			<p>
				<label><input type="checkbox" name="rtap_new_tab" value="1" <?php checked( $values['new_tab'] ); ?>> <?php esc_html_e( 'Open menu links in a new tab', 'redirect-to-another-page' ); ?></label><br>
				<label><input type="checkbox" name="rtap_nofollow" value="1" <?php checked( $values['nofollow'] ); ?>> <?php esc_html_e( 'Add nofollow to menu links', 'redirect-to-another-page' ); ?></label>
			</p>
			<?php if ( $redirect && 'publish' === $post->post_status ) : ?>
				<p><a href="<?php echo esc_url( Frontend::preview_url( Frontend::unfiltered_permalink( $post->ID ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View this page without redirecting', 'redirect-to-another-page' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['rtap_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rtap_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! self::can( $post ) ) {
			return;
		}

		$error_key = 'rtap_error_' . get_current_user_id() . '_' . $post_id;
		delete_transient( $error_key );

		$raw = isset( $_POST['rtap_url'] ) ? trim( wp_unslash( $_POST['rtap_url'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $raw ) {
			Redirects::clear( $post_id, null !== Import::legacy_raw( $post_id ) );
			return;
		}

		$url = Redirects::clean_url( $raw );
		if ( '' === $url ) {
			set_transient( $error_key, 'invalid', 10 * MINUTE_IN_SECONDS );
			return;
		}
		if ( 'publish' === $post->post_status && Redirects::key( Redirects::absolute( $url ) ) === Redirects::key( Frontend::unfiltered_permalink( $post->ID ) ) ) {
			set_transient( $error_key, 'self', 10 * MINUTE_IN_SECONDS );
			return;
		}

		Redirects::save(
			$post_id,
			array(
				'url'      => $url,
				'type'     => isset( $_POST['rtap_type'] ) ? absint( $_POST['rtap_type'] ) : 301,
				'new_tab'  => ! empty( $_POST['rtap_new_tab'] ),
				'nofollow' => ! empty( $_POST['rtap_nofollow'] ),
				'direct'   => ! empty( $_POST['rtap_direct'] ),
			)
		);
	}
}
