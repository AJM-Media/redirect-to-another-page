<?php
/**
 * Plugin Name:       Redirect to Another Page
 * Plugin URI:        https://wordpress.org/plugins/redirect-to-another-page/
 * Description:       Redirect any page or post to another URL from its edit screen, and keep old addresses working.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            AJM Media
 * Author URI:        https://ajmmedia.co.uk/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       redirect-to-another-page
 *
 * @package RedirectToAnotherPage
 */

defined( 'ABSPATH' ) || exit;

define( 'RTAP_VERSION', '1.0.0' );
define( 'RTAP_FILE', __FILE__ );
define( 'RTAP_DIR', plugin_dir_path( __FILE__ ) );
define( 'RTAP_URL', plugin_dir_url( __FILE__ ) );
define( 'RTAP_DONATE_URL', 'https://ko-fi.com/ajmmedia' );

require_once RTAP_DIR . 'includes/class-redirects.php';
require_once RTAP_DIR . 'includes/class-import.php';
require_once RTAP_DIR . 'includes/class-frontend.php';
require_once RTAP_DIR . 'includes/class-editor.php';
require_once RTAP_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__, array( RTAP\Import::class, 'scan' ) );

add_action( 'plugins_loaded', array( RTAP\Frontend::class, 'init' ) );
add_action( 'plugins_loaded', array( RTAP\Editor::class, 'init' ) );
add_action( 'plugins_loaded', array( RTAP\Import::class, 'init' ) );
add_action( 'plugins_loaded', array( RTAP\Admin::class, 'init' ) );
