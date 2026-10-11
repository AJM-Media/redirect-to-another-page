<?php

$command = isset( $args[0] ) ? $args[0] : '';
$rest    = array_slice( $args, 1 );

function rtap_pairs( $list ) {
	$out = array();
	foreach ( $list as $pair ) {
		list( $k, $v ) = array_pad( explode( '=', $pair, 2 ), 2, '' );
		$out[ $k ] = $v;
	}
	return $out;
}

function rtap_admin_user() {
	$admins = get_users(
		array(
			'role__in' => array( 'administrator' ),
			'number'   => 1,
			'blog_id'  => get_current_blog_id(),
		)
	);
	return $admins ? $admins[0]->ID : 1;
}

switch ( $command ) {
	case 'post':
		wp_set_current_user( rtap_admin_user() );
		echo wp_insert_post(
			array(
				'post_title'  => $rest[0],
				'post_name'   => sanitize_title( $rest[0] ),
				'post_type'   => isset( $rest[1] ) ? $rest[1] : 'page',
				'post_status' => 'publish',
			)
		) . "\n";
		break;

	case 'user':
		$id = wp_insert_user(
			array(
				'user_login' => $rest[0],
				'user_pass'  => wp_generate_password(),
				'user_email' => $rest[0] . '@example.com',
				'role'       => $rest[1],
			)
		);
		if ( is_multisite() ) {
			add_user_to_blog( get_current_blog_id(), $id, $rest[1] );
		}
		echo $id . "\n";
		break;

	case 'link':
		echo get_permalink( (int) $rest[0] ) . "\n";
		break;

	case 'save-as':
		$user = get_user_by( 'login', $rest[0] );
		wp_set_current_user( $user ? $user->ID : rtap_admin_user() );
		$post_id = (int) $rest[1];
		$fields  = rtap_pairs( array_slice( $rest, 2 ) );

		$_POST = array( 'rtap_nonce' => wp_create_nonce( RTAP\Editor::NONCE ) );
		foreach ( $fields as $k => $v ) {
			$_POST[ 'rtap_' . $k ] = wp_slash( $v );
		}
		RTAP\Editor::save( $post_id, get_post( $post_id ) );
		$_POST = array();

		$error = get_transient( 'rtap_error_' . get_current_user_id() . '_' . $post_id );
		$saved = get_post_meta( $post_id, RTAP\Redirects::META_URL, true );
		echo ( $error ? 'error:' . $error : ( '' !== $saved ? 'saved:' . $saved : 'none' ) ) . "\n";
		break;

	case 'save-no-nonce':
		wp_set_current_user( rtap_admin_user() );
		$_POST = array(
			'rtap_nonce' => 'bad',
			'rtap_url'   => 'https://example.org/no-nonce',
		);
		RTAP\Editor::save( (int) $rest[0], get_post( (int) $rest[0] ) );
		$_POST = array();
		echo ( get_post_meta( (int) $rest[0], RTAP\Redirects::META_URL, true ) === 'https://example.org/no-nonce' ? 'saved' : 'refused' ) . "\n";
		break;

	case 'qppr':
		$post_id = (int) $rest[0];
		foreach ( rtap_pairs( array_slice( $rest, 1 ) ) as $k => $v ) {
			update_post_meta( $post_id, '_pprredirect_' . $k, wp_slash( $v ) );
		}
		echo "ok\n";
		break;

	case 'qppr-paths':
		$paths = array();
		foreach ( array_slice( $rest, 0 ) as $pair ) {
			list( $from, $to ) = explode( '|', $pair, 2 );
			$paths[ $from ]    = $to;
		}
		update_option( 'quickppr_redirects', $paths );
		echo "ok\n";
		break;

	case 'add-path':
		$from = RTAP\Redirects::clean_from( $rest[0] );
		$to   = RTAP\Redirects::clean_url( $rest[1] );
		if ( '' === $from || '' === $to ) {
			echo "invalid\n";
			break;
		}
		$paths   = RTAP\Redirects::paths();
		$paths[] = array(
			'from' => $from,
			'to'   => $to,
			'type' => (int) ( isset( $rest[2] ) ? $rest[2] : 301 ),
		);
		RTAP\Redirects::save_paths( $paths );
		echo $from . "\n";
		break;

	case 'clean-url':
		echo '[' . RTAP\Redirects::clean_url( $rest[0] ) . "]\n";
		break;

	case 'clean-from':
		echo '[' . RTAP\Redirects::clean_from( $rest[0] ) . "]\n";
		break;

	case 'menu':
		$post_id = (int) $rest[0];
		$menu_id = wp_create_nav_menu( 'RTAP ' . $post_id . ' ' . wp_generate_password( 6, false ) );
		wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-object-id' => $post_id,
				'menu-item-object'    => get_post_type( $post_id ),
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
			)
		);
		$html = wp_nav_menu(
			array(
				'menu'        => $menu_id,
				'echo'        => false,
				'fallback_cb' => false,
			)
		);
		preg_match( '/<a [^>]*>/', (string) $html, $m );
		echo ( $m ? $m[0] : 'no link' ) . "\n";
		break;

	case 'navblock':
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			echo "skip\n";
			break;
		}
		$post_id = (int) $rest[0];
		$html    = do_blocks( '<!-- wp:navigation-link {"label":"Go","type":"page","id":' . $post_id . ',"url":"' . esc_url( get_permalink( $post_id ) ) . '","kind":"post-type"} /-->' );
		preg_match( '/<a [^>]*>/', $html, $m );
		echo ( $m ? $m[0] : 'no link' ) . "\n";
		break;

	case 'scan':
		$state = RTAP\Import::scan();
		echo $state['state'] . ' ' . $state['posts'] . ' ' . $state['paths'] . "\n";
		break;

	case 'import':
		$result = RTAP\Import::run();
		echo $result['posts'] . ' ' . $result['paths'] . ' ' . $result['skipped'] . "\n";
		break;

	case 'meta':
		echo '[' . get_post_meta( (int) $rest[0], $rest[1], true ) . "]\n";
		break;

	case 'notice':
		wp_set_current_user( rtap_admin_user() );
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		set_current_screen( 'dashboard' );
		ob_start();
		RTAP\Import::notice();
		$html = trim( wp_strip_all_tags( ob_get_clean() ) );
		echo ( '' === $html ? 'none' : preg_replace( '/\s+/', ' ', $html ) ) . "\n";
		break;

	case 'admin-check':
		require_once ABSPATH . 'wp-admin/includes/template.php';
		wp_set_current_user( rtap_admin_user() );
		$errors = array();
		set_error_handler(
			function ( $no, $str, $file, $line ) use ( &$errors ) {
				if ( false !== strpos( $file, 'redirect-to-another-page' ) ) {
					$errors[] = "$str ($file:$line)";
				}
				return false;
			}
		);

		ob_start();
		RTAP\Admin::render();
		$page = ob_get_clean();

		ob_start();
		RTAP\Editor::render( get_post( (int) $rest[0] ) );
		$box = ob_get_clean();

		restore_error_handler();

		$problems = $errors;
		foreach ( array( 'name="rtap_from"', 'name="rtap_to"', 'rtap_add_path', '_wpnonce' ) as $needle ) {
			if ( false === strpos( $page, $needle ) ) {
				$problems[] = "tools page missing $needle";
			}
		}
		foreach ( array( 'name="rtap_url"', 'name="rtap_type"', 'name="rtap_nonce"', 'name="rtap_direct"' ) as $needle ) {
			if ( false === strpos( $box, $needle ) ) {
				$problems[] = "edit box missing $needle";
			}
		}
		if ( false !== stripos( $page . $box, '<script>alert' ) ) {
			$problems[] = 'unescaped script in output';
		}
		echo $problems ? implode( ' | ', $problems ) . "\n" : "ok\n";
		break;

	default:
		WP_CLI::error( "Unknown command: $command" );
}
