<?php
/**
 * Schedules a daily WP-Cron job to refresh Solana token balances for all
 * users who have a linked wallet address.
 *
 * Note: WP-Cron fires on page load, so the job may run slightly late on
 * low-traffic sites. For reliable scheduling add a real server cron:
 *   * * * * * wget -q -O - https://yoursite.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1
 */
class STL_Cron {

	const HOOK = 'stl_daily_balance_check';

	public function __construct() {
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Iterate over every user with a linked wallet and refresh their balance.
	 */
	public function run() {
		$user_ids = get_users( [
			'meta_key'     => 'stl_solana_wallet',
			'meta_compare' => '!=',
			'meta_value'   => '',
			'fields'       => 'ID',
			'number'       => 500, // safety cap; increase for large sites
		] );

		if ( empty( $user_ids ) ) {
			return;
		}

		$solana   = new STL_Solana();
		$twitter  = new STL_Twitter_Auth();
		$has_creds = $twitter->is_configured();

		foreach ( $user_ids as $user_id ) {
			$solana->update_user_balance( (int) $user_id );

			// Refresh Twitter avatar / display name / handle using the stored
			// access token so profile pictures stay current without requiring
			// users to reconnect their account.
			if ( $has_creds ) {
				$twitter->refresh_user_profile( (int) $user_id );
			}

			// Brief pause to be courteous to external APIs.
			usleep( 200000 ); // 200 ms
		}
	}
}
