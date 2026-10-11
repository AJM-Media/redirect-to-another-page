<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

function rtap_uninstall_site() {
	delete_option( 'rtap_path_redirects' );
	delete_option( 'rtap_legacy' );
	foreach ( array( '_rtap_url', '_rtap_type', '_rtap_new_tab', '_rtap_nofollow', '_rtap_direct' ) as $rtap_key ) {
		delete_post_meta_by_key( $rtap_key );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $rtap_site_id ) {
		switch_to_blog( $rtap_site_id );
		rtap_uninstall_site();
		restore_current_blog();
	}
} else {
	rtap_uninstall_site();
}
delete_metadata( 'user', 0, 'rtap_donate_dismissed', '', true );
