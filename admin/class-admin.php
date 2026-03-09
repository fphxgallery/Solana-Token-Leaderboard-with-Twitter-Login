<?php
/**
 * Adds the plugin settings page and admin tools.
 */
class STL_Admin {

	public function __construct() {
		add_action( 'admin_menu',  [ $this, 'add_menu' ] );
		add_action( 'admin_init',  [ $this, 'register_settings' ] );
		add_action( 'wp_ajax_stl_admin_balance_check', [ $this, 'ajax_balance_check' ] );
		add_action( 'wp_ajax_stl_preview_airdrop',     [ $this, 'ajax_preview_airdrop' ] );
		add_action( 'wp_ajax_stl_execute_airdrop',     [ $this, 'ajax_execute_airdrop' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_menu() {
		add_options_page(
			'Solana Twitter Login',
			'Solana Twitter Login',
			'manage_options',
			'solana-twitter-login',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Default leaderboard color palette (Solana brand).
	 */
	public static function color_defaults() {
		return [
			'stl_color_lb_from'   => '#9945ff', // header gradient start
			'stl_color_lb_to'     => '#6d28d9', // header gradient end
			'stl_color_lb_accent' => '#9945ff', // balance text, You-badge, link hovers
			'stl_color_lb_rank'   => '#14f195', // "Your rank" number in header
			'stl_color_lb_you_bg' => '#f5f3ff', // highlighted "You" row background
		];
	}

	public function register_settings() {
		$text  = [ 'sanitize_callback' => 'sanitize_text_field' ];
		$url   = [ 'sanitize_callback' => 'esc_url_raw' ];
		$color = [ 'sanitize_callback' => 'sanitize_hex_color' ];

		register_setting( 'stl_settings', 'stl_twitter_client_id',     $text );
		register_setting( 'stl_settings', 'stl_twitter_client_secret', $text );
		register_setting( 'stl_settings', 'stl_solana_rpc',            $url );
		register_setting( 'stl_settings', 'stl_token_mint',            $text );
		register_setting( 'stl_settings', 'stl_redirect_after_login',  $url );

		// Leaderboard color settings.
		foreach ( array_keys( self::color_defaults() ) as $key ) {
			register_setting( 'stl_settings', $key, $color );
		}

		// Treasury wallet (encrypted private key).
		register_setting( 'stl_settings', 'stl_treasury_private_key', [
			'sanitize_callback' => [ $this, 'sanitize_treasury_key' ],
		] );
	}

	public function enqueue_assets( $hook ) {
		if ( $hook !== 'settings_page_solana-twitter-login' ) {
			return;
		}
		// WordPress built-in color picker.
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		// Init script — inlined to avoid an extra file.
		wp_add_inline_script( 'wp-color-picker', '
			jQuery(function($){
				$(".stl-color-picker").wpColorPicker({
					change: function(event, ui) {
						// Live-preview: update the CSS variable on the demo swatch.
						var varName = $(this).data("css-var");
						if (varName) {
							document.documentElement.style.setProperty(varName, ui.color.toString());
						}
					}
				});
			});
		' );
		// Airdrop UI script.
		wp_enqueue_script( 'stl-airdrop', STL_PLUGIN_URL . 'assets/js/airdrop.js', [], STL_VERSION, true );
		wp_localize_script( 'stl-airdrop', 'stlAirdrop', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'stl_airdrop' ),
		] );
	}

	public function render_page() {
		$callback_url = home_url( '/?stl_action=twitter_callback' );
		$mint_default = STL_TOKEN_MINT;
		$rpc_default  = STL_SOLANA_RPC;

		$users_with_wallets = get_users( [
			'meta_key'     => 'stl_solana_wallet',
			'meta_compare' => '!=',
			'meta_value'   => '',
			'number'       => 500,
		] );
		?>
		<div class="wrap">
			<h1>Solana Twitter Login</h1>

			<!-- Settings form -->
			<form method="post" action="options.php">
				<?php settings_fields( 'stl_settings' ); ?>

				<h2>Twitter / X API Credentials</h2>
				<p>
					Create an app at <a href="https://developer.twitter.com/en/portal/dashboard" target="_blank">developer.twitter.com</a>.
					Enable <strong>OAuth 2.0</strong> with <em>Type: Confidential client</em>.<br>
					Add this exact callback URL: <code><?php echo esc_url( $callback_url ); ?></code><br>
					Required scopes: <code>tweet.read users.read offline.access</code>
				</p>

				<table class="form-table">
					<tr>
						<th scope="row"><label for="stl_client_id">Client ID</label></th>
						<td>
							<input type="text" id="stl_client_id" name="stl_twitter_client_id"
								value="<?php echo esc_attr( get_option( 'stl_twitter_client_id' ) ); ?>"
								class="regular-text" autocomplete="off" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stl_client_secret">Client Secret</label></th>
						<td>
							<input type="password" id="stl_client_secret" name="stl_twitter_client_secret"
								value="<?php echo esc_attr( get_option( 'stl_twitter_client_secret' ) ); ?>"
								class="regular-text" autocomplete="new-password" />
						</td>
					</tr>
				</table>

				<h2>Solana Settings</h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="stl_token_mint">Token Mint Address</label></th>
						<td>
							<input type="text" id="stl_token_mint" name="stl_token_mint"
								value="<?php echo esc_attr( get_option( 'stl_token_mint', $mint_default ) ); ?>"
								class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="stl_solana_rpc">Solana RPC Endpoint</label></th>
						<td>
							<input type="url" id="stl_solana_rpc" name="stl_solana_rpc"
								value="<?php echo esc_attr( get_option( 'stl_solana_rpc', $rpc_default ) ); ?>"
								class="regular-text" />
							<p class="description">
								Default is the public mainnet RPC (rate-limited).
								For production use a dedicated provider like <a href="https://www.helius.dev/" target="_blank">Helius</a>
								or <a href="https://www.quicknode.com/" target="_blank">QuickNode</a>.
							</p>
						</td>
					</tr>
				</table>

				<h2>Redirect</h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="stl_redirect">After Login Redirect URL</label></th>
						<td>
							<input type="url" id="stl_redirect" name="stl_redirect_after_login"
								value="<?php echo esc_attr( get_option( 'stl_redirect_after_login', home_url( '/' ) ) ); ?>"
								class="regular-text" />
							<p class="description">Where to send users after a successful Twitter login.</p>
						</td>
					</tr>
				</table>

				<h2>Leaderboard Colors</h2>
				<p>Customize the <code>[stl_leaderboard]</code> color scheme. Changes are reflected site-wide after saving.</p>

				<?php
				$defaults = self::color_defaults();
				$labels   = [
					'stl_color_lb_from'   => [ 'Header gradient — start', 'Left side of the banner gradient.' ],
					'stl_color_lb_to'     => [ 'Header gradient — end',   'Right side of the banner gradient.' ],
					'stl_color_lb_accent' => [ 'Accent color',             'Balance amounts, "You" badge, and link hover highlights.' ],
					'stl_color_lb_rank'   => [ '"Your rank" highlight',    'The number shown next to "Your rank" in the header.' ],
					'stl_color_lb_you_bg' => [ '"You" row background',     'Background tint applied to the current user\'s row.' ],
				];
				?>
				<table class="form-table" id="stl-color-table">
					<?php foreach ( $labels as $key => [ $label, $desc ] ) :
						$saved   = get_option( $key, $defaults[ $key ] );
						$css_var = '--stl-lb-' . str_replace( [ 'stl_color_lb_', '_' ], [ '', '-' ], $key );
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $label ); ?></th>
						<td>
							<input
								type="text"
								name="<?php echo esc_attr( $key ); ?>"
								value="<?php echo esc_attr( $saved ); ?>"
								class="stl-color-picker"
								data-default-color="<?php echo esc_attr( $defaults[ $key ] ); ?>"
								data-css-var="<?php echo esc_attr( $css_var ); ?>"
							/>
							<p class="description"><?php echo esc_html( $desc ); ?></p>
						</td>
					</tr>
					<?php endforeach; ?>
				</table>

				<!-- Live preview swatch -->
				<div style="margin:16px 0 4px;">
					<strong>Preview</strong> <span style="font-size:12px;color:#6b7280;">(updates live as you pick colors above)</span>
				</div>
				<div style="max-width:540px;border-radius:12px;overflow:hidden;border:1.5px solid #e5e7eb;font-family:sans-serif;">
					<div id="stl-preview-header" style="
						padding:14px 18px;
						background:linear-gradient(135deg,<?php echo esc_attr( get_option( 'stl_color_lb_from', $defaults['stl_color_lb_from'] ) ); ?> 0%,<?php echo esc_attr( get_option( 'stl_color_lb_to', $defaults['stl_color_lb_to'] ) ); ?> 100%);
						color:#fff;display:flex;align-items:center;justify-content:space-between;">
						<span style="font-weight:700;font-size:15px;">🏆 Token Leaderboard</span>
						<span style="font-size:13px;opacity:.9;">Your rank:
							<strong id="stl-preview-rank" style="color:<?php echo esc_attr( get_option( 'stl_color_lb_rank', $defaults['stl_color_lb_rank'] ) ); ?>;">#3</strong>
						</span>
					</div>
					<table style="width:100%;border-collapse:collapse;font-size:13px;">
						<tbody>
							<?php
							$preview_rows = [
								[ '🥇', 'Alice', '@alice', '1.25M' ],
								[ '🥈', 'Bob',   '@bob',   '890K' ],
								[ '#3', 'You',   '@you',   '420K' ],
								[ '#4', 'Dave',  '@dave',  '10.5K' ],
							];
							foreach ( $preview_rows as $i => $r ) :
								$is_you = $r[1] === 'You';
								$bg     = $is_you
									? get_option( 'stl_color_lb_you_bg', $defaults['stl_color_lb_you_bg'] )
									: ( $i % 2 === 0 ? '#fff' : '#f9fafb' );
								$accent = get_option( 'stl_color_lb_accent', $defaults['stl_color_lb_accent'] );
							?>
							<tr style="background:<?php echo esc_attr( $bg ); ?>;" class="<?php echo $is_you ? 'stl-preview-you-row' : ''; ?>">
								<td style="padding:9px 14px;text-align:center;width:40px;font-weight:700;"><?php echo esc_html( $r[0] ); ?></td>
								<td style="padding:9px 14px;">
									<strong><?php echo esc_html( $r[1] ); ?></strong>
									<?php if ( $is_you ) : ?>
									<span class="stl-preview-badge" style="margin-left:6px;padding:2px 7px;border-radius:99px;background:<?php echo esc_attr( $accent ); ?>;color:#fff;font-size:11px;font-weight:700;">You</span>
									<?php endif; ?>
									<br><span style="font-size:11px;color:#6b7280;"><?php echo esc_html( $r[2] ); ?></span>
								</td>
								<td style="padding:9px 14px;text-align:right;font-weight:700;color:<?php echo esc_attr( $accent ); ?>;" class="stl-preview-bal"><?php echo esc_html( $r[3] ); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<script>
				jQuery(function($) {
					var defaults = <?php echo wp_json_encode( $defaults ); ?>;

					// Refresh the preview whenever any color picker changes.
					$(document).on('change', '.stl-color-picker', function() {
						updatePreview();
					});

					function getColor(key) {
						var $input = $('input[name="' + key + '"]');
						// wpColorPicker stores the selected value in iris.
						var iris = $input.data('iris');
						return (iris && iris.color()) ? iris.color().toString() : ($input.val() || defaults[key]);
					}

					function updatePreview() {
						var from   = getColor('stl_color_lb_from');
						var to     = getColor('stl_color_lb_to');
						var accent = getColor('stl_color_lb_accent');
						var rank   = getColor('stl_color_lb_rank');
						var youBg  = getColor('stl_color_lb_you_bg');

						$('#stl-preview-header').css('background', 'linear-gradient(135deg,' + from + ' 0%,' + to + ' 100%)');
						$('#stl-preview-rank').css('color', rank);
						$('.stl-preview-you-row').css('background', youBg);
						$('.stl-preview-bal').css('color', accent);
						$('.stl-preview-badge').css('background', accent);
					}
				});
				</script>

				<h2>Treasury Wallet</h2>
				<p>
					Import a dedicated Solana wallet used to fund token airdrops.
					<strong>Use a separate treasury wallet — never your main wallet.</strong>
				</p>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="stl_treasury_key_input">Private Key (Base58)</label></th>
						<td>
							<input type="password" id="stl_treasury_key_input"
								name="stl_treasury_private_key"
								value=""
								placeholder="<?php echo get_option( 'stl_treasury_private_key' ) ? '(key saved — paste a new key to replace)' : 'Paste 64-byte Base58 keypair from Phantom / Solflare'; ?>"
								class="regular-text" autocomplete="new-password" />
							<p class="description">
								The key is encrypted with AES-256-GCM before being stored.
								It never appears in plain text after saving.
							</p>
						</td>
					</tr>
					<?php $pub = get_option( 'stl_treasury_public_key' ); if ( $pub ) : ?>
					<tr>
						<th scope="row">Derived Public Key</th>
						<td>
							<code><?php echo esc_html( $pub ); ?></code>
							<p class="description">
								Verify this matches your treasury wallet address before sending any airdrop.
							</p>
						</td>
					</tr>
					<?php endif; ?>
				</table>

			<?php submit_button(); ?>
			</form>

			<hr>

			<!-- Airdrop -->
			<h2>Airdrop Tokens</h2>
			<?php if ( ! get_option( 'stl_treasury_private_key' ) ) : ?>
			<p>Configure a <strong>Treasury Wallet</strong> above to enable token airdrops.</p>
			<?php else : ?>
			<p>
				Distribute <code><?php echo esc_html( get_option( 'stl_token_mint', STL_TOKEN_MINT ) ); ?></code> tokens
				to leaderboard users. Preview the distribution first, then confirm to send.
			</p>
			<table class="form-table" style="max-width:680px;">
				<tr>
					<th scope="row"><label for="stl-airdrop-total">Total tokens to send</label></th>
					<td>
						<input type="number" id="stl-airdrop-total" min="1" step="any"
							class="regular-text" placeholder="e.g. 1000000" />
					</td>
				</tr>
				<tr>
					<th scope="row">Asset</th>
					<td>
						<label>
							<input type="radio" name="stl_airdrop_asset" value="token" checked />
							SPL Token <span style="color:#6b7280;">(mint from settings)</span>
						</label><br>
						<label>
							<input type="radio" name="stl_airdrop_asset" value="sol" />
							Native SOL
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">Distribution method</th>
					<td>
						<label>
							<input type="radio" name="stl_airdrop_method" value="balance" checked />
							By current token balance <span style="color:#6b7280;">(proportional to holdings)</span>
						</label><br>
						<label>
							<input type="radio" name="stl_airdrop_method" value="rank" />
							By leaderboard rank <span style="color:#6b7280;">(rank 1 gets the largest share)</span>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="stl-airdrop-top-n">Limit to top</label></th>
					<td>
						<input type="number" id="stl-airdrop-top-n" min="1" max="200" value="25" class="small-text" />
						<span class="description"> users</span>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" id="stl-airdrop-preview" class="button button-secondary">Preview Distribution</button>
				<button type="button" id="stl-airdrop-confirm" class="button button-primary" style="display:none;margin-left:8px;">Confirm Airdrop</button>
			</p>
			<div id="stl-airdrop-msg"></div>
			<div id="stl-airdrop-preview-area"></div>
			<div id="stl-airdrop-result-area"></div>
			<?php endif; ?>

			<hr>

			<!-- Shortcode reference -->
			<h2>Shortcodes</h2>
			<p>Place any of these shortcodes on a page or post. All four can be used together or independently.</p>

			<table class="widefat" style="max-width:900px;border-radius:6px;overflow:hidden;">
				<thead style="background:#f9fafb;">
					<tr>
						<th style="padding:10px 14px;width:260px;">Shortcode</th>
						<th style="padding:10px 14px;">What it displays</th>
						<th style="padding:10px 14px;width:220px;">Attributes</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td style="padding:10px 14px;vertical-align:top;">
							<code>[stl_login_button]</code>
						</td>
						<td style="padding:10px 14px;vertical-align:top;">
							A <strong>Login with X</strong> button for guests. Once connected, shows the user's
							X avatar, display name, handle, and a <em>Disconnect X</em> button.
						</td>
						<td style="padding:10px 14px;vertical-align:top;color:#6b7280;font-size:12px;">
							None
						</td>
					</tr>
					<tr style="background:#f9fafb;">
						<td style="padding:10px 14px;vertical-align:top;">
							<code>[stl_wallet_form]</code>
						</td>
						<td style="padding:10px 14px;vertical-align:top;">
							A form that lets the logged-in user <strong>link, change, or remove</strong> their
							Solana wallet address. Fetches the token balance immediately on save.
						</td>
						<td style="padding:10px 14px;vertical-align:top;color:#6b7280;font-size:12px;">
							None
						</td>
					</tr>
					<tr>
						<td style="padding:10px 14px;vertical-align:top;">
							<code>[stl_token_balance]</code>
						</td>
						<td style="padding:10px 14px;vertical-align:top;">
							A card showing the <strong>current user's token balance</strong> and when it was
							last updated. Hidden for guests and users without a linked wallet.
						</td>
						<td style="padding:10px 14px;vertical-align:top;color:#6b7280;font-size:12px;">
							None
						</td>
					</tr>
					<tr style="background:#f9fafb;">
						<td style="padding:10px 14px;vertical-align:top;">
							<code>[stl_leaderboard]</code>
						</td>
						<td style="padding:10px 14px;vertical-align:top;">
							A <strong>ranked dashboard</strong> of all users sorted by token balance (highest first).
							Displays X avatar, name, handle, wallet address (linked to Solscan), balance, and
							last-updated time. 🥇🥈🥉 medals on the top 3. The logged-in user's row is highlighted
							with a <em>You</em> badge and their rank is shown in the header even if they fall
							outside the displayed limit.
						</td>
						<td style="padding:10px 14px;vertical-align:top;font-size:12px;">
							<code>limit</code> — max rows shown<br>
							<em style="color:#6b7280;">default: 25, max: 200</em><br><br>
							<code>show_wallet</code> — <code>yes</code> / <code>no</code><br>
							<em style="color:#6b7280;">default: yes</em><br><br>
							<code>show_updated</code> — <code>yes</code> / <code>no</code><br>
							<em style="color:#6b7280;">default: yes</em><br><br>
							<code>highlight</code> — <code>yes</code> / <code>no</code><br>
							<em style="color:#6b7280;">highlight current user's row</em>
						</td>
					</tr>
				</tbody>
			</table>

			<h3 style="margin-top:20px;">Example page layouts</h3>
			<div style="display:flex;flex-wrap:wrap;gap:16px;max-width:900px;margin-bottom:4px;">
				<div style="flex:1;min-width:220px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;">
					<strong>Profile / Account page</strong>
					<pre style="margin:10px 0 0;font-size:12px;background:#fff;padding:8px 10px;border:1px solid #e5e7eb;border-radius:4px;line-height:1.6;">[stl_login_button]
[stl_wallet_form]
[stl_token_balance]</pre>
				</div>
				<div style="flex:1;min-width:220px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;">
					<strong>Leaderboard page (top 10, no wallet)</strong>
					<pre style="margin:10px 0 0;font-size:12px;background:#fff;padding:8px 10px;border:1px solid #e5e7eb;border-radius:4px;line-height:1.6;">[stl_leaderboard limit="10"
  show_wallet="no"]</pre>
				</div>
				<div style="flex:1;min-width:220px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;">
					<strong>Login + full leaderboard</strong>
					<pre style="margin:10px 0 0;font-size:12px;background:#fff;padding:8px 10px;border:1px solid #e5e7eb;border-radius:4px;line-height:1.6;">[stl_login_button]
[stl_leaderboard limit="50"]</pre>
				</div>
			</div>

			<hr>

			<!-- Manual cron trigger -->
			<h2>Balance Check</h2>
			<p>The plugin checks balances automatically once per day via WP-Cron.</p>
			<button id="stl-run-check" class="button button-secondary">Run Balance Check Now</button>
			<span id="stl-check-msg" style="margin-left:10px;"></span>

			<hr>

			<!-- User table -->
			<h2>Users with Linked Wallets (<?php echo count( $users_with_wallets ); ?>)</h2>

			<?php if ( $users_with_wallets ) : ?>
			<table class="wp-list-table widefat fixed striped" style="max-width:1000px;">
				<thead>
					<tr>
						<th>WordPress User</th>
						<th>X (Twitter)</th>
						<th>Wallet Address</th>
						<th>Token Balance</th>
						<th>Last Checked</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $users_with_wallets as $u ) :
					$wallet   = get_user_meta( $u->ID, 'stl_solana_wallet',         true );
					$balance  = get_user_meta( $u->ID, 'stl_token_balance_ui',      true );
					$twitter  = get_user_meta( $u->ID, 'stl_twitter_username',      true );
					$checked  = get_user_meta( $u->ID, 'stl_balance_last_updated',  true );
				?>
					<tr>
						<td>
							<a href="<?php echo esc_url( get_edit_user_link( $u->ID ) ); ?>">
								<?php echo esc_html( $u->display_name ); ?>
							</a>
						</td>
						<td><?php echo $twitter ? '@' . esc_html( $twitter ) : '—'; ?></td>
						<td>
							<code title="<?php echo esc_attr( $wallet ); ?>">
								<?php echo esc_html( substr( $wallet, 0, 8 ) . '…' . substr( $wallet, -8 ) ); ?>
							</code>
						</td>
						<td><?php echo $balance !== '' ? number_format( (float) $balance ) : '—'; ?></td>
						<td>
							<?php
							if ( $checked ) {
								echo esc_html( human_time_diff( $checked, time() ) . ' ago' );
							} else {
								echo 'Never';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php else : ?>
			<p>No users have linked a Solana wallet yet.</p>
			<?php endif; ?>
		</div>

		<script>
		(function() {
			var btn = document.getElementById('stl-run-check');
			var msg = document.getElementById('stl-check-msg');
			if (!btn) return;

			btn.addEventListener('click', function() {
				btn.disabled = true;
				msg.textContent = 'Running…';

				var fd = new FormData();
				fd.append('action', 'stl_admin_balance_check');
				fd.append('nonce',  '<?php echo wp_create_nonce( 'stl_admin_balance_check' ); ?>');

				fetch(ajaxurl, { method: 'POST', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(d) {
						msg.textContent = d.success ? d.data : ('Error: ' + d.data);
						btn.disabled = false;
					})
					.catch(function() {
						msg.textContent = 'Request failed.';
						btn.disabled = false;
					});
			});
		})();
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// Settings sanitizers
	// -------------------------------------------------------------------------

	/**
	 * Sanitize the treasury private key field.
	 *
	 * If the field is empty, the existing encrypted key is preserved.
	 * Otherwise the key is validated, the derived public key stored, and the
	 * base58 private key string encrypted before being written to the DB.
	 *
	 * @param  string $input  Raw value from the settings form.
	 * @return string  Encrypted key (or existing value on error / empty input).
	 */
	public function sanitize_treasury_key( $input ) {
		$input = trim( (string) $input );

		// Empty submission — keep existing encrypted key.
		if ( $input === '' ) {
			return get_option( 'stl_treasury_private_key', '' );
		}

		if ( ! class_exists( 'STL_Solana_Signer' ) ) {
			add_settings_error( 'stl_treasury_private_key', 'no_signer', 'Signer class not loaded.' );
			return get_option( 'stl_treasury_private_key', '' );
		}

		$signer = new STL_Solana_Signer();
		$req    = $signer->check_requirements();
		if ( is_wp_error( $req ) ) {
			add_settings_error( 'stl_treasury_private_key', 'req', $req->get_error_message() );
			return get_option( 'stl_treasury_private_key', '' );
		}

		try {
			$kp = $signer->decode_private_key( $input );
		} catch ( \Exception $e ) {
			add_settings_error( 'stl_treasury_private_key', 'invalid', 'Invalid private key: ' . $e->getMessage() );
			return get_option( 'stl_treasury_private_key', '' );
		}

		// Persist the derived public key for display.
		update_option( 'stl_treasury_public_key', $signer->b58_encode( $kp['public'] ) );

		// Encrypt and return the base58 private key string.
		return $signer->encrypt_key( $input );
	}

	// -------------------------------------------------------------------------
	// AJAX: manual balance check
	// -------------------------------------------------------------------------

	/**
	 * AJAX handler: manually trigger the daily balance check.
	 */
	public function ajax_balance_check() {
		check_ajax_referer( 'stl_admin_balance_check', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$cron = new STL_Cron();
		$cron->run();
		wp_send_json_success( 'Balance check complete.' );
	}

	// -------------------------------------------------------------------------
	// AJAX: airdrop preview
	// -------------------------------------------------------------------------

	/**
	 * AJAX handler: calculate the proposed distribution without sending anything.
	 *
	 * POST params: total_amount (float), method (balance|rank), top_n (int)
	 */
	public function ajax_preview_airdrop() {
		check_ajax_referer( 'stl_airdrop', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$total  = floatval( $_POST['total_amount'] ?? 0 );
		$method = sanitize_key( $_POST['method'] ?? 'balance' );
		$top_n  = max( 1, min( 200, intval( $_POST['top_n'] ?? 25 ) ) );
		$asset  = in_array( sanitize_key( $_POST['asset'] ?? 'token' ), [ 'token', 'sol' ], true )
			? sanitize_key( $_POST['asset'] ?? 'token' )
			: 'token';
		$is_sol = $asset === 'sol';

		if ( $total <= 0 ) {
			wp_send_json_error( 'Total amount must be greater than 0.' );
		}
		if ( ! in_array( $method, [ 'balance', 'rank' ], true ) ) {
			wp_send_json_error( 'Invalid distribution method.' );
		}

		// Load users that have a linked wallet.
		$users = get_users( [
			'meta_key'     => 'stl_solana_wallet',
			'meta_compare' => '!=',
			'meta_value'   => '',
			'number'       => 500,
		] );

		$rows = [];
		foreach ( $users as $u ) {
			$wallet  = get_user_meta( $u->ID, 'stl_solana_wallet',    true );
			$balance = get_user_meta( $u->ID, 'stl_token_balance_ui', true );
			$twitter = get_user_meta( $u->ID, 'stl_twitter_username', true );

			if ( ! $wallet || $balance === '' || $balance === false ) {
				continue;
			}
			$rows[] = [
				'uid'     => $u->ID,
				'wallet'  => $wallet,
				'balance' => (float) $balance,
				'twitter' => $twitter ?: '',
			];
		}

		if ( ! $rows ) {
			wp_send_json_error( 'No eligible users found (users need a linked wallet and a recorded balance).' );
		}

		// Sort highest balance first, assign ranks before slicing.
		usort( $rows, fn( $a, $b ) => $b['balance'] <=> $a['balance'] );
		foreach ( $rows as $i => &$row ) {
			$row['rank'] = $i + 1;
		}
		unset( $row );

		// Trim to top_n.
		$rows = array_slice( $rows, 0, $top_n );

		// Compute weights.
		if ( $method === 'balance' ) {
			$total_weight = array_sum( array_column( $rows, 'balance' ) );
			foreach ( $rows as &$row ) {
				$row['weight'] = $total_weight > 0 ? $row['balance'] / $total_weight : 0;
			}
		} else {
			// Rank-based: weight = 1/rank, then normalise.
			foreach ( $rows as &$row ) {
				$row['weight'] = 1.0 / $row['rank'];
			}
			$total_weight = array_sum( array_column( $rows, 'weight' ) );
			foreach ( $rows as &$row ) {
				$row['weight'] = $total_weight > 0 ? $row['weight'] / $total_weight : 0;
			}
		}
		unset( $row );

		$mint     = get_option( 'stl_token_mint', STL_TOKEN_MINT );
		$signer   = new STL_Solana_Signer();
		$skipped  = 0;
		$recipients = [];

		if ( $is_sol ) {
			// Native SOL: 1 SOL = 1,000,000,000 lamports. No token account lookup needed.
			$decimals = 9;
			foreach ( $rows as $row ) {
				$amount_ui  = round( $row['weight'] * $total, $decimals );
				$amount_raw = (int) round( $amount_ui * 1e9 );
				$recipients[] = [
					'rank'          => $row['rank'],
					'twitter'       => $row['twitter'],
					'wallet'        => $row['wallet'],
					'token_account' => $row['wallet'], // System Program transfers wallet → wallet
					'amount_ui'     => $amount_ui,
					'amount_raw'    => $amount_raw,
				];
			}
		} else {
			// SPL token: resolve each recipient's associated token account (ATA).
			$decimals = $this->get_token_decimals( $mint );
			foreach ( $rows as $row ) {
				$ta = $signer->get_token_account( $row['wallet'], $mint );
				if ( ! $ta ) {
					$skipped++;
					continue;
				}
				$amount_ui  = round( $row['weight'] * $total, $decimals );
				$amount_raw = (int) round( $amount_ui * pow( 10, $decimals ) );
				$recipients[] = [
					'rank'          => $row['rank'],
					'twitter'       => $row['twitter'],
					'wallet'        => $row['wallet'],
					'token_account' => $ta,
					'amount_ui'     => $amount_ui,
					'amount_raw'    => $amount_raw,
				];
			}
		}

		if ( ! $recipients ) {
			$err = $is_sol
				? 'No eligible recipients found.'
				: 'No recipients have a token account for this mint.';
			wp_send_json_error( $err );
		}

		wp_send_json_success( [
			'recipients' => $recipients,
			'skipped'    => $skipped,
			'total_ui'   => array_sum( array_column( $recipients, 'amount_ui' ) ),
			'asset'      => $asset,
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX: airdrop execute
	// -------------------------------------------------------------------------

	/**
	 * AJAX handler: sign and submit SPL token transfers to confirmed recipients.
	 *
	 * POST params: recipients (JSON array from the preview response)
	 */
	public function ajax_execute_airdrop() {
		check_ajax_referer( 'stl_airdrop', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}

		$stored_key = get_option( 'stl_treasury_private_key', '' );
		if ( ! $stored_key ) {
			wp_send_json_error( 'No treasury key configured.' );
		}

		$asset  = in_array( sanitize_key( $_POST['asset'] ?? 'token' ), [ 'token', 'sol' ], true )
			? sanitize_key( $_POST['asset'] ?? 'token' )
			: 'token';
		$mint   = $asset === 'sol' ? STL_Solana_Signer::SOL_MINT : '';

		$recipients_raw = json_decode( wp_unslash( $_POST['recipients'] ?? '[]' ), true );
		if ( ! is_array( $recipients_raw ) || empty( $recipients_raw ) ) {
			wp_send_json_error( 'No recipients provided.' );
		}

		// Accept only the fields we need; sanitize each.
		$recipients = [];
		foreach ( $recipients_raw as $r ) {
			if ( empty( $r['wallet'] ) || empty( $r['token_account'] ) || ! isset( $r['amount_raw'] ) ) {
				continue;
			}
			$recipients[] = [
				'wallet'        => sanitize_text_field( $r['wallet'] ),
				'token_account' => sanitize_text_field( $r['token_account'] ),
				'amount_raw'    => (int) $r['amount_raw'],
				'amount_ui'     => (float) ( $r['amount_ui'] ?? 0 ),
				'twitter'       => sanitize_text_field( $r['twitter'] ?? '' ),
			];
		}

		if ( ! $recipients ) {
			wp_send_json_error( 'No valid recipients.' );
		}

		$signer = new STL_Solana_Signer();
		$req    = $signer->check_requirements();
		if ( is_wp_error( $req ) ) {
			wp_send_json_error( $req->get_error_message() );
		}

		try {
			$private_key_b58 = $signer->decrypt_key( $stored_key );
		} catch ( \Exception $e ) {
			wp_send_json_error( 'Failed to decrypt treasury key: ' . $e->getMessage() );
		}

		$results = $signer->airdrop( $private_key_b58, $recipients, $mint );

		// Zero out the key variable immediately after use.
		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $private_key_b58 );
		}

		wp_send_json_success( [ 'results' => $results ] );
	}

	// -------------------------------------------------------------------------
	// RPC helpers
	// -------------------------------------------------------------------------

	/**
	 * Fetch the number of decimal places for a token mint via getTokenSupply.
	 * Falls back to 6 (common for SPL tokens) on any failure.
	 *
	 * @param  string $mint  Base58 mint address.
	 * @return int
	 */
	private function get_token_decimals( string $mint ): int {
		$response = wp_remote_post(
			get_option( 'stl_solana_rpc', STL_SOLANA_RPC ),
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'getTokenSupply',
					'params'  => [ $mint ],
				] ),
				'timeout' => 10,
			]
		);
		if ( is_wp_error( $response ) ) {
			return 6;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return (int) ( $body['result']['value']['decimals'] ?? 6 );
	}
}
