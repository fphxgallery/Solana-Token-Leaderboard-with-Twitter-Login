<?php
/**
 * Plugin Name: Solana Token Leaderboard with Twitter Login
 * Description: Login with X (Twitter) and link your Solana wallet to track token balances daily.
 * Version:     1.0.0
 * Author:      @fPHXGallery
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License:     GPL v2 or later
 * Text Domain: solana-twitter-login
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STL_VERSION',    '1.0.0' );
define( 'STL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'STL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'STL_TOKEN_MINT', '3dUHMD1HWSqBC8b6P1uVe6UmrrVLvindSvxurdJnpump' );
define( 'STL_SOLANA_RPC', 'https://api.mainnet-beta.solana.com' );

require_once STL_PLUGIN_DIR . 'includes/class-twitter-auth.php';
require_once STL_PLUGIN_DIR . 'includes/class-solana.php';
require_once STL_PLUGIN_DIR . 'includes/class-solana-signer.php';
require_once STL_PLUGIN_DIR . 'includes/class-cron.php';
require_once STL_PLUGIN_DIR . 'public/class-public.php';

if ( is_admin() ) {
	require_once STL_PLUGIN_DIR . 'admin/class-admin.php';
}

add_action( 'plugins_loaded', function () {
	new STL_Public();
	new STL_Cron();

	if ( is_admin() ) {
		new STL_Admin();
	}
} );

register_activation_hook( __FILE__, function () {
	STL_Cron::schedule();
} );

register_deactivation_hook( __FILE__, function () {
	STL_Cron::unschedule();
} );
