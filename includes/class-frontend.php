<?php

namespace RTAP;

defined( 'ABSPATH' ) || exit;

class Frontend {
	const PREVIEW_ARG = 'rtap_preview';

	private static $in_link = false;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'redirect' ), 1 );
		add_filter( 'post_link', array( __CLASS__, 'link' ), 20, 2 );
		add_filter( 'page_link', array( __CLASS__, 'link' ), 20, 2 );
		add_filter( 'post_type_link', array( __CLASS__, 'link' ), 20, 2 );
		add_filter( 'nav_menu_link_attributes', array( __CLASS__, 'menu_attributes' ), 20, 2 );
		add_filter( 'render_block_core/navigation-link', array( __CLASS__, 'navigation_link' ), 20, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_args' ) );
	}

	public static function redirect() {
		if ( is_preview() || is_robots() || is_favicon() ) {
			return;
		}

		$current  = Redirects::current_url();
		$redirect = Redirects::match_path( $current );
		$post_id  = 0;

		if ( ! $redirect && is_singular() ) {
			$post_id  = (int) get_queried_object_id();
			$redirect = Redirects::get( $post_id );
		}
		if ( ! $redirect || self::previewing( $post_id ) ) {
			return;
		}

		$target = Redirects::resolve( $redirect, $current );
		if ( '' === $target ) {
			return;
		}

		if ( wp_redirect( $target, $redirect['type'], 'Redirect to Another Page' ) ) { // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}
	}

	private static function previewing( $post_id ) {
		if ( ! isset( $_GET[ self::PREVIEW_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		return $post_id ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'manage_options' );
	}

	public static function preview_url( $url ) {
		return add_query_arg( self::PREVIEW_ARG, '1', $url );
	}

	private static function skip_links() {
		return is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || self::$in_link;
	}

	public static function unfiltered_permalink( $post_id ) {
		$previous      = self::$in_link;
		self::$in_link = true;
		$link          = get_permalink( $post_id );
		self::$in_link = $previous;
		return $link;
	}

	public static function link( $link, $post ) {
		if ( self::skip_links() ) {
			return $link;
		}

		self::$in_link = true;
		$redirect      = Redirects::get( $post instanceof \WP_Post ? $post->ID : (int) $post );
		self::$in_link = false;

		if ( $redirect && $redirect['direct'] ) {
			return Redirects::absolute( $redirect['url'] );
		}
		return $link;
	}

	private static function rel( $rel, $redirect ) {
		$values = preg_split( '/\s+/', trim( (string) $rel ), -1, PREG_SPLIT_NO_EMPTY );
		if ( $redirect['new_tab'] ) {
			$values[] = 'noopener';
		}
		if ( $redirect['nofollow'] ) {
			$values[] = 'nofollow';
		}
		return implode( ' ', array_unique( $values ) );
	}

	public static function menu_attributes( $atts, $item ) {
		if ( ! isset( $item->type, $item->object_id ) || 'post_type' !== $item->type ) {
			return $atts;
		}
		$redirect = Redirects::get( (int) $item->object_id );
		if ( ! $redirect ) {
			return $atts;
		}

		if ( $redirect['direct'] ) {
			$atts['href'] = Redirects::absolute( $redirect['url'] );
		}
		if ( $redirect['new_tab'] ) {
			$atts['target'] = '_blank';
		}
		if ( $redirect['new_tab'] || $redirect['nofollow'] ) {
			$atts['rel'] = self::rel( isset( $atts['rel'] ) ? $atts['rel'] : '', $redirect );
		}
		return $atts;
	}

	public static function navigation_link( $content, $block ) {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) || empty( $block['attrs']['id'] ) || ( isset( $block['attrs']['kind'] ) && 'post-type' !== $block['attrs']['kind'] ) ) {
			return $content;
		}
		$redirect = Redirects::get( (int) $block['attrs']['id'] );
		if ( ! $redirect || ( ! $redirect['new_tab'] && ! $redirect['nofollow'] ) ) {
			return $content;
		}

		$tags = new \WP_HTML_Tag_Processor( $content );
		if ( $tags->next_tag( 'a' ) ) {
			if ( $redirect['new_tab'] ) {
				$tags->set_attribute( 'target', '_blank' );
			}
			$tags->set_attribute( 'rel', self::rel( $tags->get_attribute( 'rel' ), $redirect ) );
			return $tags->get_updated_html();
		}
		return $content;
	}

	public static function sitemap_args( $args ) {
		$without = array(
			'relation' => 'OR',
			array(
				'key'     => Redirects::META_URL,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'   => Redirects::META_URL,
				'value' => '',
			),
		);
		$args['meta_query'] = empty( $args['meta_query'] ) ? $without : array( 'relation' => 'AND', $args['meta_query'], $without ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		return $args;
	}
}
