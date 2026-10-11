<?php

namespace RTAP;

defined( 'ABSPATH' ) || exit;

class Import {
	const STATE     = 'rtap_legacy';
	const QPPR_FILE = 'quick-pagepost-redirect-plugin/page_post_redirect_plugin.php';
	const OLD_PATHS = 'quickppr_redirects';

	private static $legacy_paths = null;

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_init', array( __CLASS__, 'maybe_scan' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_rtap_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_rtap_dismiss_import', array( __CLASS__, 'handle_dismiss' ) );
	}

	public static function state() {
		$state = get_option( self::STATE );
		return is_array( $state ) && isset( $state['state'] ) ? $state : null;
	}

	public static function qppr_active() {
		if ( in_array( self::QPPR_FILE, (array) get_option( 'active_plugins', array() ), true ) ) {
			return true;
		}
		return is_multisite() && array_key_exists( self::QPPR_FILE, (array) get_site_option( 'active_sitewide_plugins', array() ) );
	}

	public static function honouring() {
		if ( self::qppr_active() ) {
			return false;
		}
		$state = self::state();
		return ! $state || in_array( $state['state'], array( 'pending', 'dismissed' ), true );
	}

	public static function pending_post_ids() {
		return get_posts(
			array(
				'post_type'        => array_values( get_post_types() ),
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => '_pprredirect_active',
						'value' => '1',
					),
					array(
						'key'     => Redirects::META_URL,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
	}

	public static function pending_paths() {
		$existing = array();
		foreach ( Redirects::paths() as $item ) {
			$existing[ Redirects::key( 'http://x' . $item['from'] ) ] = true;
		}

		$pending = array();
		foreach ( self::read_legacy_paths() as $item ) {
			if ( ! isset( $existing[ Redirects::key( 'http://x' . $item['from'] ) ] ) ) {
				$pending[] = $item;
			}
		}
		return $pending;
	}

	public static function scan() {
		$posts = 0;
		foreach ( self::pending_post_ids() as $post_id ) {
			if ( self::legacy_raw( $post_id ) ) {
				++$posts;
			}
		}
		$paths    = count( self::pending_paths() );
		$previous = self::state();

		if ( $posts + $paths > 0 ) {
			$state = ( $previous && 'dismissed' === $previous['state'] ) ? 'dismissed' : 'pending';
		} elseif ( $previous && 'imported' === $previous['state'] ) {
			$state = 'imported';
		} else {
			$state = 'none';
		}

		$data = array(
			'state' => $state,
			'posts' => $posts,
			'paths' => $paths,
		);
		update_option( self::STATE, $data );
		return $data;
	}

	public static function maybe_scan() {
		if ( null === self::state() && current_user_can( 'manage_options' ) ) {
			self::scan();
		}
	}

	public static function legacy_raw( $post_id ) {
		if ( '1' !== (string) get_post_meta( $post_id, '_pprredirect_active', true ) ) {
			return null;
		}
		$url = self::legacy_url( get_post_meta( $post_id, '_pprredirect_url', true ) );
		if ( '' === $url ) {
			return null;
		}

		$type       = (string) get_post_meta( $post_id, '_pprredirect_type', true );
		$new_window = (string) get_post_meta( $post_id, '_pprredirect_newwindow', true );

		return array(
			'url'      => $url,
			'type'     => 'meta' === $type ? 302 : Redirects::type( $type ),
			'new_tab'  => '' !== $new_window && '0' !== $new_window,
			'nofollow' => '1' === (string) get_post_meta( $post_id, '_pprredirect_relnofollow', true ),
			'direct'   => '1' === (string) get_post_meta( $post_id, '_pprredirect_rewritelink', true ),
			'source'   => 'qppr',
		);
	}

	public static function legacy_post( $post_id ) {
		return self::honouring() ? self::legacy_raw( $post_id ) : null;
	}

	public static function legacy_url( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || in_array( strtolower( $value ), array( 'http://', 'https://' ), true ) ) {
			return '';
		}
		if ( ctype_digit( $value ) ) {
			$link = get_post( (int) $value ) ? Frontend::unfiltered_permalink( (int) $value ) : '';
			return $link ? Redirects::clean_url( $link ) : '';
		}

		$url = Redirects::clean_url( $value );
		if ( '' === $url && preg_match( '#^[a-z0-9-]+(\.[a-z0-9-]+)+(/|$)#i', $value ) ) {
			$url = Redirects::clean_url( 'https://' . $value );
		}
		return $url;
	}

	public static function legacy_from( $value ) {
		$value = trim( (string) $value );
		if ( ctype_digit( $value ) ) {
			$link = get_post( (int) $value ) ? Frontend::unfiltered_permalink( (int) $value ) : '';
			return $link ? Redirects::clean_from( $link ) : '';
		}
		return Redirects::clean_from( $value );
	}

	private static function read_legacy_paths() {
		$raw   = get_option( self::OLD_PATHS, array() );
		$paths = array();
		if ( ! is_array( $raw ) ) {
			return $paths;
		}
		foreach ( $raw as $from => $to ) {
			if ( ! is_scalar( $to ) ) {
				continue;
			}
			$from = self::legacy_from( $from );
			$to   = self::legacy_url( $to );
			if ( '' !== $from && '' !== $to ) {
				$paths[] = array(
					'from' => $from,
					'to'   => $to,
					'type' => 301,
				);
			}
		}
		return $paths;
	}

	public static function legacy_paths() {
		if ( null === self::$legacy_paths ) {
			self::$legacy_paths = self::read_legacy_paths();
		}
		return self::$legacy_paths;
	}

	public static function run() {
		$result = array(
			'posts'   => 0,
			'paths'   => 0,
			'skipped' => 0,
		);

		foreach ( self::pending_post_ids() as $post_id ) {
			$redirect = self::legacy_raw( $post_id );
			if ( $redirect ) {
				Redirects::save( $post_id, $redirect );
				++$result['posts'];
			} else {
				++$result['skipped'];
			}
		}

		$pending = self::pending_paths();
		if ( $pending ) {
			Redirects::save_paths( array_merge( Redirects::paths(), $pending ) );
			$result['paths'] = count( $pending );
		}

		$raw = get_option( self::OLD_PATHS, array() );
		if ( is_array( $raw ) ) {
			$result['skipped'] += max( 0, count( $raw ) - count( self::read_legacy_paths() ) );
		}

		update_option(
			self::STATE,
			array(
				'state' => 'imported',
				'posts' => 0,
				'paths' => 0,
			)
		);
		self::$legacy_paths = null;

		return $result;
	}

	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}
		$state = self::state();
		if ( ! $state || 'pending' !== $state['state'] ) {
			return;
		}
		$count = (int) $state['posts'] + (int) $state['paths'];
		if ( $count < 1 ) {
			return;
		}

		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=rtap_dismiss_import' ), 'rtap_dismiss_import' );
		?>
		<div class="notice notice-info rtap-import-notice">
			<p>
				<?php
				printf(
					/* translators: %s: number of redirects */
					esc_html( _n( 'Redirect to Another Page found %s redirect from Quick Page/Post Redirect. It already works; import it to keep it working once that plugin is removed.', 'Redirect to Another Page found %s redirects from Quick Page/Post Redirect. They already work; import them to keep them working once that plugin is removed.', $count, 'redirect-to-another-page' ) ),
					esc_html( number_format_i18n( $count ) )
				);
				?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( Admin::page_url() ); ?>"><?php esc_html_e( 'Review and import', 'redirect-to-another-page' ); ?></a>
				<a class="button-link" href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Dismiss', 'redirect-to-another-page' ); ?></a>
			</p>
		</div>
		<?php
	}

	public static function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'redirect-to-another-page' ) );
		}
		check_admin_referer( 'rtap_import' );

		set_transient( 'rtap_import_result_' . get_current_user_id(), self::run(), 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( Admin::page_url() );
		exit;
	}

	public static function handle_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'redirect-to-another-page' ) );
		}
		check_admin_referer( 'rtap_dismiss_import' );

		$state = self::state();
		if ( $state && 'pending' === $state['state'] ) {
			$state['state'] = 'dismissed';
			update_option( self::STATE, $state );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
