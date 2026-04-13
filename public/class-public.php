<?php
/**
 * Handles frontend: OAuth callback, shortcodes, and AJAX endpoints.
 *
 * Shortcodes:
 *   [stl_login_button]   — Login/profile card for X (Twitter)
 *   [stl_wallet_form]    — Wallet link/manage form
 *   [stl_token_balance]  — Display current token balance
 *   [stl_leaderboard]    — Dashboard ranking all users by token balance
 *     Attributes:
 *       limit="25"        Max rows to show (default 25, max 200)
 *       show_wallet="yes" Show truncated wallet address (default yes)
 *       show_updated="yes" Show last-updated timestamp (default yes)
 *       highlight="yes"   Highlight the current user's row (default yes)
 */
class STL_Public {

	public function __construct() {
		// OAuth callback (runs early, before headers are sent).
		add_action( 'init', [ $this, 'handle_oauth_callback' ], 5 );

		// Assets.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_head',            [ $this, 'output_color_vars' ], 20 );

		// Shortcodes.
		add_shortcode( 'stl_login_button',  [ $this, 'sc_login_button' ] );
		add_shortcode( 'stl_wallet_form',   [ $this, 'sc_wallet_form' ] );
		add_shortcode( 'stl_token_balance', [ $this, 'sc_token_balance' ] );
		add_shortcode( 'stl_leaderboard',   [ $this, 'sc_leaderboard' ] );

		// AJAX — logged-in users only.
		add_action( 'wp_ajax_stl_save_wallet',       [ $this, 'ajax_save_wallet' ] );
		add_action( 'wp_ajax_stl_remove_wallet',     [ $this, 'ajax_remove_wallet' ] );
		add_action( 'wp_ajax_stl_refresh_balance',   [ $this, 'ajax_refresh_balance' ] );
		add_action( 'wp_ajax_stl_disconnect_twitter',[ $this, 'ajax_disconnect_twitter' ] );
	}

	// -------------------------------------------------------------------------
	// OAuth callback
	// -------------------------------------------------------------------------

	public function handle_oauth_callback() {
		if (
			! isset( $_GET['stl_action'] ) ||
			$_GET['stl_action'] !== 'twitter_callback'
		) {
			return;
		}

		// Rate limit: max 10 OAuth callbacks per IP per 5 minutes (#6).
		$ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
		$rate_key = 'stl_oauth_rate_' . md5( $ip );
		$attempts = (int) get_transient( $rate_key );
		if ( $attempts >= 10 ) {
			wp_die( 'Too many login attempts. Please try again in a few minutes.', 'Rate Limited', [ 'response' => 429 ] );
		}
		set_transient( $rate_key, $attempts + 1, 5 * MINUTE_IN_SECONDS );

		// Error returned by Twitter (e.g. user denied access).
		if ( ! empty( $_GET['error'] ) ) {
			wp_die( 'Twitter login was cancelled or denied. Please try again.' );
		}

		if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
			wp_die( 'Invalid OAuth callback — missing parameters.' );
		}

		$auth   = new STL_Twitter_Auth();
		$result = $auth->handle_callback(
			sanitize_text_field( wp_unslash( $_GET['code'] ) ),
			sanitize_text_field( wp_unslash( $_GET['state'] ) )
		);

		if ( is_wp_error( $result ) ) {
			wp_die( 'Twitter login failed. Please try again. If the issue persists, contact the site administrator.' );
		}

		// Log the user in if not already.
		if ( ! is_user_logged_in() || get_current_user_id() !== $result ) {
			wp_set_current_user( $result );
			wp_set_auth_cookie( $result, true );
		}

		$redirect = get_option( 'stl_redirect_after_login', home_url( '/' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public function enqueue_assets() {
		wp_enqueue_style(
			'stl-style',
			STL_PLUGIN_URL . 'assets/css/style.css',
			[],
			STL_VERSION
		);

		wp_enqueue_script(
			'stl-wallet',
			STL_PLUGIN_URL . 'assets/js/wallet.js',
			[ 'jquery' ],
			STL_VERSION,
			true
		);

		wp_localize_script( 'stl-wallet', 'stlVars', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'stl_public' ),
		] );
	}

	// -------------------------------------------------------------------------
	// Leaderboard color CSS variables
	// -------------------------------------------------------------------------

	public function output_color_vars() {
		$defaults = [
			'stl_color_lb_from'   => '#9945ff',
			'stl_color_lb_to'     => '#6d28d9',
			'stl_color_lb_accent' => '#9945ff',
			'stl_color_lb_rank'   => '#14f195',
			'stl_color_lb_you_bg' => '#f5f3ff',
		];

		// Only emit a <style> tag if at least one value differs from the default
		// (avoids an unnecessary tag on fresh installs).
		$vars = [];
		$map  = [
			'stl_color_lb_from'   => '--stl-lb-from',
			'stl_color_lb_to'     => '--stl-lb-to',
			'stl_color_lb_accent' => '--stl-lb-accent',
			'stl_color_lb_rank'   => '--stl-lb-rank',
			'stl_color_lb_you_bg' => '--stl-lb-you-bg',
		];

		foreach ( $map as $option => $var ) {
			$value = sanitize_hex_color( get_option( $option, $defaults[ $option ] ) );
			if ( $value ) {
				$vars[ $var ] = $value;
			}
		}

		if ( empty( $vars ) ) {
			return;
		}

		echo "<style id=\"stl-color-vars\">\n:root {\n";
		foreach ( $vars as $var => $value ) {
			echo "\t" . esc_attr( $var ) . ': ' . esc_attr( $value ) . ";\n";
		}
		echo "}\n</style>\n";
	}

	// -------------------------------------------------------------------------
	// Shortcode: [stl_login_button]
	// -------------------------------------------------------------------------

	public function sc_login_button( $atts ) {
		$auth = new STL_Twitter_Auth();

		if ( ! $auth->is_configured() ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="stl-notice stl-notice-warn">⚠ Twitter API credentials not configured. Visit <a href="' . esc_url( admin_url( 'options-general.php?page=solana-twitter-login' ) ) . '">Settings › Solana Twitter Login</a>.</p>';
			}
			return '';
		}

		ob_start();

		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
			$twitter = get_user_meta( $user_id, 'stl_twitter_username', true );
			$name    = get_user_meta( $user_id, 'stl_twitter_name',     true );
			$avatar  = get_user_meta( $user_id, 'stl_twitter_avatar',   true );

			if ( $twitter ) {
				// User already connected to X.
				?>
				<div class="stl-profile-card">
					<?php if ( $avatar ) : ?>
					<img src="<?php echo esc_url( $avatar ); ?>"
						 alt="<?php echo esc_attr( $name ); ?>"
						 class="stl-avatar" />
					<?php endif; ?>
					<div class="stl-profile-info">
						<strong><?php echo esc_html( $name ); ?></strong>
						<span>@<?php echo esc_html( $twitter ); ?></span>
					</div>
					<button class="stl-btn stl-btn-outline stl-btn-danger" id="stl-disconnect-twitter">
						Disconnect X
					</button>
				</div>
				<?php
			} else {
				// Logged-in WP user but not yet connected to X.
				$auth_url = $auth->get_auth_url();
				?>
				<a href="<?php echo esc_url( $auth_url ); ?>" class="stl-btn stl-btn-twitter">
					<?php echo self::x_logo_svg(); ?>
					Connect X Account
				</a>
				<?php
			}
		} else {
			// Guest — show login button.
			$auth_url = $auth->get_auth_url();
			?>
			<a href="<?php echo esc_url( $auth_url ); ?>" class="stl-btn stl-btn-twitter">
				<?php echo self::x_logo_svg(); ?>
				Login with X
			</a>
			<?php
		}

		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Shortcode: [stl_wallet_form]
	// -------------------------------------------------------------------------

	public function sc_wallet_form() {
		if ( ! is_user_logged_in() ) {
			return '<p class="stl-notice">Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">log in</a> to link your Solana wallet.</p>';
		}

		$user_id = get_current_user_id();
		$wallet  = (string) get_user_meta( $user_id, 'stl_solana_wallet', true );

		ob_start();
		?>
		<div class="stl-wallet-form" id="stl-wallet-section">
			<h3 class="stl-form-title">Solana Wallet</h3>

			<!-- Shown when wallet IS linked -->
			<div id="stl-wallet-linked" class="<?php echo $wallet ? '' : 'stl-hidden'; ?>">
				<p class="stl-label">Linked Wallet</p>
				<code class="stl-wallet-address"><?php echo esc_html( $wallet ); ?></code>
				<div class="stl-wallet-actions">
					<button class="stl-btn stl-btn-secondary" id="stl-refresh-balance">Refresh Balance</button>
					<button class="stl-btn stl-btn-outline"   id="stl-change-wallet">Change Wallet</button>
					<button class="stl-btn stl-btn-outline stl-btn-danger" id="stl-remove-wallet">Remove</button>
				</div>
			</div>

			<!-- Shown when wallet is NOT linked, or user wants to change it -->
			<div id="stl-wallet-input-area" class="<?php echo $wallet ? 'stl-hidden' : ''; ?>">
				<label for="stl-wallet-address" class="stl-label">Solana Wallet Address</label>
				<input
					type="text"
					id="stl-wallet-address"
					class="stl-input"
					placeholder="e.g. 7xKXtg2CW87d97TXJSDpbD5jBkheTqA83TZRuJosgAsU"
					autocomplete="off"
					value="<?php echo esc_attr( $wallet ); ?>"
				/>
				<div class="stl-wallet-input-actions">
					<button class="stl-btn stl-btn-primary" id="stl-save-wallet">Link Wallet</button>
					<?php if ( $wallet ) : ?>
					<button class="stl-btn stl-btn-outline" id="stl-cancel-change">Cancel</button>
					<?php endif; ?>
				</div>
			</div>

			<div id="stl-wallet-message" role="alert"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Shortcode: [stl_token_balance]
	// -------------------------------------------------------------------------

	public function sc_token_balance() {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$user_id = get_current_user_id();
		$wallet  = get_user_meta( $user_id, 'stl_solana_wallet',        true );
		$balance = get_user_meta( $user_id, 'stl_token_balance_ui',     true );
		$checked = get_user_meta( $user_id, 'stl_balance_last_updated', true );
		$mint    = get_option( 'stl_token_mint', STL_TOKEN_MINT );

		if ( ! $wallet ) {
			return '<p class="stl-notice">Link your Solana wallet to view your token balance.</p>';
		}

		ob_start();
		?>
		<div class="stl-balance-card">
			<div class="stl-balance-label">Token Balance</div>
			<div class="stl-balance-amount" id="stl-balance-display">
				<?php echo $balance !== '' ? number_format( (float) $balance ) : '—'; ?>
			</div>
			<div class="stl-balance-meta">
				<span class="stl-mint" title="<?php echo esc_attr( $mint ); ?>">
					<?php echo esc_html( substr( $mint, 0, 6 ) . '…' . substr( $mint, -6 ) ); ?>
				</span>
				<?php if ( $checked ) : ?>
				<span class="stl-updated">
					Updated <?php echo esc_html( human_time_diff( $checked, time() ) ); ?> ago
				</span>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Shortcode: [stl_leaderboard]
	// -------------------------------------------------------------------------

	public function sc_leaderboard( $atts ) {
		$atts = shortcode_atts( [
			'limit'        => 25,
			'show_wallet'  => 'yes',
			'show_updated' => 'yes',
			'highlight'    => 'yes',
		], $atts, 'stl_leaderboard' );

		$limit        = min( (int) $atts['limit'], 200 );
		$show_wallet  = $atts['show_wallet']  !== 'no';
		$show_updated = $atts['show_updated'] !== 'no';
		$highlight    = $atts['highlight']    !== 'no';
		$current_uid  = is_user_logged_in() ? get_current_user_id() : 0;

		// ------------------------------------------------------------------
		// 1. Fetch all users who have a balance recorded.
		// ------------------------------------------------------------------
		$users = get_users( [
			'meta_key'     => 'stl_token_balance_ui',
			'meta_compare' => 'EXISTS',
			'number'       => 500, // fetch a large set, then sort in PHP
			'fields'       => 'ID',
		] );

		if ( empty( $users ) ) {
			return '<p class="stl-notice">No token holders found yet. Link a wallet and refresh your balance to appear here.</p>';
		}

		// ------------------------------------------------------------------
		// 2. Build rows with balance data, then sort descending.
		// ------------------------------------------------------------------
		$rows = [];
		foreach ( $users as $uid ) {
			$balance = (float) get_user_meta( $uid, 'stl_token_balance_ui', true );
			// Skip users with zero or missing balance — they don't hold the token.
			if ( $balance <= 0 ) {
				continue;
			}
			$rows[] = [
				'uid'          => (int) $uid,
				'balance'      => $balance,
				'twitter'      => get_user_meta( $uid, 'stl_twitter_username', true ),
				'name'         => get_user_meta( $uid, 'stl_twitter_name',     true ),
				'avatar'       => get_user_meta( $uid, 'stl_twitter_avatar',   true ),
				'wallet'       => get_user_meta( $uid, 'stl_solana_wallet',    true ),
				'last_updated' => (int) get_user_meta( $uid, 'stl_balance_last_updated', true ),
			];
		}

		// Sort highest balance first.
		usort( $rows, function ( $a, $b ) {
			return $b['balance'] <=> $a['balance'];
		} );

		// ------------------------------------------------------------------
		// 3. Find the current user's rank (even if outside the displayed limit).
		// ------------------------------------------------------------------
		$current_rank = 0;
		if ( $current_uid ) {
			foreach ( $rows as $i => $row ) {
				if ( $row['uid'] === $current_uid ) {
					$current_rank = $i + 1;
					break;
				}
			}
		}

		// Trim to requested limit.
		$rows = array_slice( $rows, 0, $limit );

		if ( empty( $rows ) ) {
			return '<p class="stl-notice">No token holders with a positive balance yet.</p>';
		}

		// Medal emoji for top 3.
		$medals = [ 1 => '🥇', 2 => '🥈', 3 => '🥉' ];

		// Mint label for footer.
		$mint = get_option( 'stl_token_mint', STL_TOKEN_MINT );

		// Total holders shown.
		$total_shown = count( $rows );

		ob_start();
		?>
		<div class="stl-leaderboard" id="stl-leaderboard">

			<div class="stl-lb-header">
				<div class="stl-lb-title">
					<span class="stl-lb-trophy">🏆</span> Token Leaderboard
				</div>
				<div class="stl-lb-meta">
					<span class="stl-lb-count"><?php echo esc_html( $total_shown ); ?> holder<?php echo $total_shown !== 1 ? 's' : ''; ?></span>
					<?php if ( $current_rank > 0 ) : ?>
					<span class="stl-lb-myrank">Your rank: <strong>#<?php echo esc_html( $current_rank ); ?></strong></span>
					<?php endif; ?>
				</div>
			</div>

			<div class="stl-lb-table-wrap">
				<table class="stl-lb-table">
					<thead>
						<tr>
							<th class="stl-lb-th stl-lb-rank">#</th>
							<th class="stl-lb-th stl-lb-user">Holder</th>
							<?php if ( $show_wallet ) : ?>
							<th class="stl-lb-th stl-lb-wallet">Wallet</th>
							<?php endif; ?>
							<th class="stl-lb-th stl-lb-bal">Balance</th>
							<?php if ( $show_updated ) : ?>
							<th class="stl-lb-th stl-lb-upd">Updated</th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $rank_idx => $row ) :
						$rank      = $rank_idx + 1;
						$is_me     = $highlight && $current_uid && $row['uid'] === $current_uid;
						$row_class = 'stl-lb-row'
							. ( $rank <= 3  ? ' stl-lb-top3 stl-lb-rank-' . $rank : '' )
							. ( $is_me      ? ' stl-lb-mine' : '' );
						$display   = $row['name'] ?: ( $row['twitter'] ? '@' . $row['twitter'] : 'Anonymous' );
					?>
						<tr class="<?php echo esc_attr( $row_class ); ?>">

							<!-- Rank -->
							<td class="stl-lb-td stl-lb-rank">
								<?php
								if ( isset( $medals[ $rank ] ) ) {
									echo $medals[ $rank ];
								} else {
									echo esc_html( $rank );
								}
								?>
							</td>

							<!-- User / avatar -->
							<td class="stl-lb-td stl-lb-user">
								<div class="stl-lb-user-cell">
									<?php if ( $row['avatar'] ) : ?>
									<img src="<?php echo esc_url( $row['avatar'] ); ?>"
										 alt="<?php echo esc_attr( $display ); ?>"
										 class="stl-lb-avatar"
										 loading="lazy" />
									<?php else : ?>
									<div class="stl-lb-avatar stl-lb-avatar-placeholder">
										<?php echo esc_html( mb_substr( $display, 0, 1 ) ); ?>
									</div>
									<?php endif; ?>
									<div class="stl-lb-user-info">
										<span class="stl-lb-name"><?php echo esc_html( $display ); ?></span>
										<?php if ( $row['twitter'] ) : ?>
										<a class="stl-lb-handle"
										   href="https://x.com/<?php echo esc_attr( $row['twitter'] ); ?>"
										   target="_blank" rel="noopener noreferrer">
											@<?php echo esc_html( $row['twitter'] ); ?>
										</a>
										<?php endif; ?>
									</div>
									<?php if ( $is_me ) : ?>
									<span class="stl-lb-you-badge">You</span>
									<?php endif; ?>
								</div>
							</td>

							<!-- Wallet -->
							<?php if ( $show_wallet ) : ?>
							<td class="stl-lb-td stl-lb-wallet">
								<?php if ( $row['wallet'] ) : ?>
								<a class="stl-lb-wallet-link"
								   href="https://solscan.io/account/<?php echo esc_attr( $row['wallet'] ); ?>"
								   target="_blank" rel="noopener noreferrer"
								   title="<?php echo esc_attr( $row['wallet'] ); ?>">
									<?php echo esc_html( substr( $row['wallet'], 0, 5 ) . '…' . substr( $row['wallet'], -5 ) ); ?>
								</a>
								<?php else : ?>—<?php endif; ?>
							</td>
							<?php endif; ?>

							<!-- Balance -->
							<td class="stl-lb-td stl-lb-bal">
								<?php
								$bal = $row['balance'];
								if ( $bal >= 1_000_000 ) {
									echo esc_html( number_format( $bal / 1_000_000, 2 ) ) . '<span class="stl-lb-unit">M</span>';
								} elseif ( $bal >= 1_000 ) {
									echo esc_html( number_format( $bal / 1_000, 2 ) ) . '<span class="stl-lb-unit">K</span>';
								} else {
									echo esc_html( number_format( $bal, 4 ) );
								}
								?>
							</td>

							<!-- Last updated -->
							<?php if ( $show_updated ) : ?>
							<td class="stl-lb-td stl-lb-upd">
								<?php
								if ( $row['last_updated'] ) {
									echo esc_html( human_time_diff( $row['last_updated'], time() ) . ' ago' );
								} else {
									echo '—';
								}
								?>
							</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div><!-- /.stl-lb-table-wrap -->

			<div class="stl-lb-footer">
				<span class="stl-lb-mint-label">Token:</span>
				<a class="stl-lb-mint-link"
				   href="https://solscan.io/token/<?php echo esc_attr( $mint ); ?>"
				   target="_blank" rel="noopener noreferrer"
				   title="<?php echo esc_attr( $mint ); ?>">
					<?php echo esc_html( substr( $mint, 0, 8 ) . '…' . substr( $mint, -8 ) ); ?>
				</a>
				<span class="stl-lb-footer-note">Balances updated daily</span>
			</div>

		</div><!-- /.stl-leaderboard -->
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// AJAX: save wallet
	// -------------------------------------------------------------------------

	public function ajax_save_wallet() {
		check_ajax_referer( 'stl_public', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Not logged in.' );
		}

		$wallet = sanitize_text_field( wp_unslash( $_POST['wallet'] ?? '' ) );

		if ( ! $this->is_valid_solana_address( $wallet ) ) {
			wp_send_json_error( 'Invalid Solana wallet address. Please check and try again.' );
		}

		$user_id = get_current_user_id();
		update_user_meta( $user_id, 'stl_solana_wallet', $wallet );

		// Eagerly fetch balance so the user sees it right away.
		$solana  = new STL_Solana();
		$balance = $solana->update_user_balance( $user_id );

		$balance_ui = null;
		if ( ! is_wp_error( $balance ) && $balance !== false ) {
			$balance_ui = $balance['ui'];
		}

		wp_send_json_success( [
			'message' => 'Wallet linked successfully.',
			'wallet'  => $wallet,
			'balance' => $balance_ui,
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX: remove wallet
	// -------------------------------------------------------------------------

	public function ajax_remove_wallet() {
		check_ajax_referer( 'stl_public', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Not logged in.' );
		}

		$user_id = get_current_user_id();
		delete_user_meta( $user_id, 'stl_solana_wallet' );
		delete_user_meta( $user_id, 'stl_token_balance_raw' );
		delete_user_meta( $user_id, 'stl_token_balance_ui' );
		delete_user_meta( $user_id, 'stl_token_decimals' );
		delete_user_meta( $user_id, 'stl_balance_last_updated' );

		wp_send_json_success( 'Wallet removed.' );
	}

	// -------------------------------------------------------------------------
	// AJAX: refresh balance on demand
	// -------------------------------------------------------------------------

	public function ajax_refresh_balance() {
		check_ajax_referer( 'stl_public', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Not logged in.' );
		}

		$user_id = get_current_user_id();
		$solana  = new STL_Solana();
		$balance = $solana->update_user_balance( $user_id );

		if ( $balance === false ) {
			wp_send_json_error( 'No wallet linked.' );
		}

		if ( is_wp_error( $balance ) ) {
			wp_send_json_error( $balance->get_error_message() );
		}

		wp_send_json_success( [
			'balance' => $balance['ui'],
			'message' => 'Balance updated.',
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX: disconnect Twitter
	// -------------------------------------------------------------------------

	public function ajax_disconnect_twitter() {
		check_ajax_referer( 'stl_public', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Not logged in.' );
		}

		$user_id = get_current_user_id();
		delete_user_meta( $user_id, 'stl_twitter_id' );
		delete_user_meta( $user_id, 'stl_twitter_username' );
		delete_user_meta( $user_id, 'stl_twitter_name' );
		delete_user_meta( $user_id, 'stl_twitter_avatar' );
		delete_user_meta( $user_id, 'stl_access_token' );
		delete_user_meta( $user_id, 'stl_refresh_token' );

		wp_send_json_success( 'X account disconnected.' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Validate a Solana base58 address: must be valid base58 that decodes to
	 * exactly 32 bytes (an Ed25519 public key) (#7).
	 */
	private function is_valid_solana_address( $addr ) {
		if ( ! preg_match( '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $addr ) ) {
			return false;
		}
		if ( class_exists( 'STL_Solana_Signer' ) ) {
			$signer = new STL_Solana_Signer();
			return $signer->is_valid_address( $addr );
		}
		return true;
	}

	private static function x_logo_svg() {
		return '<svg viewBox="0 0 24 24" class="stl-x-icon" aria-hidden="true">'
			. '<path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.747l7.73-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>'
			. '</svg>';
	}
}
