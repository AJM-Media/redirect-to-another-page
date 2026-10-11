<?php

namespace RTAP;

defined( 'ABSPATH' ) || exit;

class Redirects {
	const META_URL      = '_rtap_url';
	const META_TYPE     = '_rtap_type';
	const META_NEW_TAB  = '_rtap_new_tab';
	const META_NOFOLLOW = '_rtap_nofollow';
	const META_DIRECT   = '_rtap_direct';
	const PATHS         = 'rtap_path_redirects';
	const TYPES         = array( 301, 302, 307 );
	const MAX_HOPS      = 10;

	public static function meta_keys() {
		return array( self::META_URL, self::META_TYPE, self::META_NEW_TAB, self::META_NOFOLLOW, self::META_DIRECT );
	}

	public static function post_types() {
		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );
		return array_values( (array) apply_filters( 'rtap_post_types', array_values( $types ) ) );
	}

	public static function type( $type ) {
		$type = (int) $type;
		return in_array( $type, self::TYPES, true ) ? $type : 301;
	}

	public static function clean_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || preg_match( '/[\s<>"]/', $url ) ) {
			return '';
		}

		if ( '/' === $url[0] ) {
			if ( isset( $url[1] ) && '/' === $url[1] ) {
				return '';
			}
			$clean = esc_url_raw( $url );
			return ( '' !== $clean && '/' === $clean[0] ) ? $clean : '';
		}

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		$clean = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $clean || ! wp_parse_url( $clean, PHP_URL_HOST ) ) {
			return '';
		}
		return $clean;
	}

	public static function origin() {
		$parts = wp_parse_url( home_url() );
		$port  = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		return $parts['scheme'] . '://' . $parts['host'] . $port;
	}

	public static function home_path() {
		return untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
	}

	public static function site_path( $path ) {
		$home = self::home_path();
		if ( '' !== $home && 0 !== stripos( $path . '/', $home . '/' ) ) {
			$path = $home . $path;
		}
		return $path;
	}

	public static function absolute( $url ) {
		if ( '' !== $url && '/' === $url[0] ) {
			return self::origin() . self::site_path( $url );
		}
		return $url;
	}

	public static function get( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return null;
		}
		if ( ! metadata_exists( 'post', $post_id, self::META_URL ) ) {
			return Import::legacy_post( $post_id );
		}

		$url = self::clean_url( get_post_meta( $post_id, self::META_URL, true ) );
		if ( '' === $url ) {
			return null;
		}

		return array(
			'url'      => $url,
			'type'     => self::type( get_post_meta( $post_id, self::META_TYPE, true ) ),
			'new_tab'  => '1' === get_post_meta( $post_id, self::META_NEW_TAB, true ),
			'nofollow' => '1' === get_post_meta( $post_id, self::META_NOFOLLOW, true ),
			'direct'   => '1' === get_post_meta( $post_id, self::META_DIRECT, true ),
			'source'   => 'rtap',
		);
	}

	public static function save( $post_id, $redirect ) {
		update_post_meta( $post_id, self::META_URL, $redirect['url'] );
		update_post_meta( $post_id, self::META_TYPE, (string) self::type( $redirect['type'] ) );
		foreach ( array(
			self::META_NEW_TAB  => 'new_tab',
			self::META_NOFOLLOW => 'nofollow',
			self::META_DIRECT   => 'direct',
		) as $key => $field ) {
			if ( ! empty( $redirect[ $field ] ) ) {
				update_post_meta( $post_id, $key, '1' );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}
	}

	public static function clear( $post_id, $keep_marker ) {
		foreach ( self::meta_keys() as $key ) {
			delete_post_meta( $post_id, $key );
		}
		if ( $keep_marker ) {
			update_post_meta( $post_id, self::META_URL, '' );
		}
	}

	public static function clean_from( $from ) {
		$from = trim( (string) $from );
		if ( '' === $from || preg_match( '/[\s<>"]/', $from ) ) {
			return '';
		}

		if ( preg_match( '#^https?://#i', $from ) ) {
			$host = wp_parse_url( $from, PHP_URL_HOST );
			if ( ! $host || strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
				return '';
			}
			$path  = (string) wp_parse_url( $from, PHP_URL_PATH );
			$query = (string) wp_parse_url( $from, PHP_URL_QUERY );
			$from  = ( '' === $path ? '/' : $path ) . ( '' !== $query ? '?' . $query : '' );
		}

		$from  = '/' . ltrim( $from, '/' );
		$clean = esc_url_raw( $from );
		if ( '' === $clean || '/' !== $clean[0] || 0 === strpos( $clean, '//' ) ) {
			return '';
		}

		$parts = explode( '?', $clean, 2 );
		$path  = self::site_path( $parts[0] );
		if ( '' === self::path_key( $path ) || self::path_key( $path ) === self::path_key( self::home_path() ) ) {
			return '';
		}
		return $path . ( isset( $parts[1] ) && '' !== $parts[1] ? '?' . $parts[1] : '' );
	}

	public static function paths() {
		$paths = get_option( self::PATHS, array() );
		return is_array( $paths ) ? array_values( $paths ) : array();
	}

	public static function save_paths( $paths ) {
		update_option( self::PATHS, array_values( $paths ) );
	}

	public static function path_key( $path ) {
		return untrailingslashit( strtolower( rawurldecode( (string) $path ) ) );
	}

	public static function query_key( $query ) {
		$args = array();
		parse_str( (string) $query, $args );
		ksort( $args );
		return http_build_query( $args );
	}

	public static function matches( $from, $url ) {
		$from_query = (string) wp_parse_url( $from, PHP_URL_QUERY );
		if ( self::path_key( wp_parse_url( $from, PHP_URL_PATH ) ) !== self::path_key( wp_parse_url( $url, PHP_URL_PATH ) ) ) {
			return false;
		}
		return '' === $from_query || self::query_key( $from_query ) === self::query_key( wp_parse_url( $url, PHP_URL_QUERY ) );
	}

	public static function match_path( $url ) {
		$paths = self::paths();
		if ( Import::honouring() ) {
			$paths = array_merge( $paths, Import::legacy_paths() );
		}

		foreach ( $paths as $item ) {
			if ( empty( $item['from'] ) || empty( $item['to'] ) ) {
				continue;
			}
			if ( self::matches( $item['from'], $url ) ) {
				return array(
					'url'      => $item['to'],
					'type'     => self::type( isset( $item['type'] ) ? $item['type'] : 301 ),
					'new_tab'  => false,
					'nofollow' => false,
					'direct'   => false,
					'source'   => 'path',
				);
			}
		}
		return null;
	}

	public static function is_local( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return $host && strtolower( $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	public static function key( $url ) {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) . self::path_key( wp_parse_url( $url, PHP_URL_PATH ) ) . ( '' !== $query ? '?' . self::query_key( $query ) : '' );
	}

	public static function lookup( $url ) {
		$redirect = self::match_path( $url );
		if ( $redirect ) {
			return $redirect;
		}
		$post_id = url_to_postid( $url );
		return $post_id ? self::get( $post_id ) : null;
	}

	public static function resolve( $redirect, $from_url ) {
		$target = self::absolute( $redirect['url'] );
		$seen   = array( self::key( $from_url ) );
		$next   = $target;

		for ( $i = 0; $i < self::MAX_HOPS; $i++ ) {
			$key = self::key( $next );
			if ( in_array( $key, $seen, true ) ) {
				return '';
			}
			$seen[] = $key;

			if ( ! self::is_local( $next ) ) {
				return $target;
			}
			$hop = self::lookup( $next );
			if ( ! $hop ) {
				return $target;
			}
			$next = self::absolute( $hop['url'] );
		}

		return '';
	}

	public static function current_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return self::origin() . '/' . ltrim( $uri, '/' );
	}
}
