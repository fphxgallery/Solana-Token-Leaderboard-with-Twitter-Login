<?php
/**
 * Handles Twitter / X OAuth 2.0 with PKCE + confidential client.
 *
 * Twitter developer portal setup required:
 *  - OAuth 2.0 enabled
 *  - Callback URL: {site_url}/?stl_action=twitter_callback
 *  - Scopes: tweet.read users.read offline.access
 */
class STL_Twitter_Auth {

	const AUTH_URL  = 'https://x.com/i/oauth2/authorize';
	const TOKEN_URL = 'https://api.x.com/2/oauth2/token';
	const USER_URL  = 'https://api.x.com/2/users/me';

	/**
	 * HTTP status codes that indicate a transient server-side problem.
	 * These are safe to retry without any changes to the request.
	 */
	const RETRYABLE_CODES = [ 429, 500, 502, 503, 504 ];

	private $client_id;
	private $client_secret;
	private $redirect_uri;

	public function __construct() {
		$this->client_id     = get_option( 'stl_twitter_client_id', '' );
		$this->client_secret = $this->decrypt_stored( get_option( 'stl_twitter_client_secret', '' ) );
		$this->redirect_uri  = home_url( '/?stl_action=twitter_callback' );
	}

	public function is_configured() {
		return ! empty( $this->client_id ) && ! empty( $this->client_secret );
	}

	/**
	 * Build the Twitter authorization URL and store PKCE/state in a transient.
	 */
	public function get_auth_url() {
		$code_verifier  = $this->generate_code_verifier();
		$code_challenge = $this->generate_code_challenge( $code_verifier );
		$state          = wp_generate_password( 32, false );

		// Store code verifier + IP hash for session binding (#9 / #10).
		set_transient( 'stl_oauth_' . $state, [
			'verifier' => $code_verifier,
			'ip_hash'  => $this->hash_ip(),
		], 10 * MINUTE_IN_SECONDS );

		$params = [
			'response_type'         => 'code',
			'client_id'             => $this->client_id,
			'redirect_uri'          => $this->redirect_uri,
			'scope'                 => 'tweet.read users.read offline.access',
			'state'                 => $state,
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => 'S256',
		];

		return self::AUTH_URL . '?' . http_build_query( $params );
	}

	/**
	 * Handle the OAuth callback: verify state, exchange code, create/update WP user.
	 *
	 * @return int|WP_Error  WordPress user ID on success.
	 */
	public function handle_callback( $code, $state ) {
		$stored = get_transient( 'stl_oauth_' . $state );

		// Legacy format: stored value may be a plain string (code verifier) from before
		// the IP-binding change. Handle both formats.
		if ( is_array( $stored ) ) {
			$code_verifier = $stored['verifier'] ?? '';
			$stored_ip     = $stored['ip_hash'] ?? '';
		} else {
			$code_verifier = $stored;
			$stored_ip     = '';
		}

		if ( ! $code_verifier ) {
			return new WP_Error( 'invalid_state', 'OAuth session expired or invalid. Please try logging in again.' );
		}

		// Verify IP binding if available.
		if ( $stored_ip && $stored_ip !== $this->hash_ip() ) {
			delete_transient( 'stl_oauth_' . $state );
			return new WP_Error( 'ip_mismatch', 'OAuth session originated from a different network. Please try logging in again.' );
		}

		delete_transient( 'stl_oauth_' . $state );

		$token_data = $this->exchange_code( $code, $code_verifier );
		if ( is_wp_error( $token_data ) ) {
			return $token_data;
		}

		// Cap retries to 2 in the synchronous callback path to avoid blocking
		// PHP workers for 15+ seconds during Twitter API outages.
		$twitter_user = $this->get_user_info( $token_data['access_token'], 2 );
		if ( is_wp_error( $twitter_user ) ) {
			return $twitter_user;
		}

		return $this->create_or_update_user( $twitter_user, $token_data );
	}

	private function exchange_code( $code, $code_verifier ) {
		$args = [
			'headers' => [
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->client_secret ),
			],
			'body'    => [
				'code'          => $code,
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => $this->redirect_uri,
				'code_verifier' => $code_verifier,
			],
			'timeout' => 15,
		];

		// Retry only on network-level failures (WP_Error).
		// We do NOT retry HTTP 5xx here because the auth code is single-use —
		// if Twitter processed the request but returned a bad status, a retry
		// would fail with "code already consumed".
		$max_net_attempts = 2;
		for ( $attempt = 1; $attempt <= $max_net_attempts; $attempt++ ) {
			$response = wp_remote_post( self::TOKEN_URL, $args );

			if ( ! is_wp_error( $response ) ) {
				break; // Got an HTTP response (good or bad) — don't retry.
			}

			if ( $attempt < $max_net_attempts ) {
				sleep( 1 );
			}
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'token_error', 'Unable to reach X/Twitter. Please try again.' );
		}

		$data      = json_decode( wp_remote_retrieve_body( $response ), true );
		$http_code = (int) wp_remote_retrieve_response_code( $response );

		if ( empty( $data['access_token'] ) ) {
			// Log the detailed error for the site admin.
			$detail = $this->extract_twitter_error( $data, $http_code );
			error_log( '[STL] Token exchange failed: ' . $detail );

			// Return a generic message to the user.
			if ( in_array( $http_code, self::RETRYABLE_CODES, true ) ) {
				return new WP_Error( 'token_error', 'X/Twitter is temporarily unavailable. Please try again in a moment.' );
			}
			return new WP_Error( 'token_error', 'Login failed. Please check the plugin\'s Twitter API configuration.' );
		}

		return $data;
	}

	/**
	 * Fetch the authenticated user's profile from the Twitter API.
	 *
	 * @param  string $access_token  Bearer token.
	 * @param  int    $max_attempts  Number of retries (default 2 for sync, increase for cron).
	 * @return array|WP_Error
	 */
	private function get_user_info( $access_token, $max_attempts = 2 ) {
		$last_http_code = 0;
		$last_data      = null;

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$response = wp_remote_get(
				self::USER_URL . '?user.fields=profile_image_url,name,username',
				[
					'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
					'timeout' => 10,
				]
			);

			// Network-level failure — retry if attempts remain.
			if ( is_wp_error( $response ) ) {
				if ( $attempt < $max_attempts ) {
					sleep( 1 );
					continue;
				}
				return new WP_Error( 'user_error', 'Unable to reach X/Twitter. Please try again.' );
			}

			$last_http_code = (int) wp_remote_retrieve_response_code( $response );
			$last_data      = json_decode( wp_remote_retrieve_body( $response ), true );

			// Success.
			if ( ! empty( $last_data['data'] ) ) {
				return $last_data['data'];
			}

			// Transient server error — wait and retry.
			if ( in_array( $last_http_code, self::RETRYABLE_CODES, true ) && $attempt < $max_attempts ) {
				sleep( 1 );
				continue;
			}

			// Non-retryable error — stop immediately.
			break;
		}

		// Log detailed error for site admin.
		$detail = $this->extract_twitter_error( $last_data, $last_http_code );
		error_log( '[STL] get_user_info failed: ' . $detail );

		// Return generic message to caller.
		return new WP_Error( 'user_error', 'Could not retrieve profile from X/Twitter. Please try again.' );
	}

	/**
	 * Pull a human-readable message out of a Twitter API v2 error response.
	 * Used for logging only — never exposed directly to users.
	 */
	private function extract_twitter_error( $body, $http_code ) {
		if ( is_array( $body ) ) {
			if ( ! empty( $body['error_description'] ) ) {
				return $body['error_description'] . ' (HTTP ' . $http_code . ')';
			}
			if ( ! empty( $body['detail'] ) ) {
				return $body['detail'] . ' (HTTP ' . $http_code . ')';
			}
			if ( ! empty( $body['title'] ) ) {
				return $body['title'] . ' (HTTP ' . $http_code . ')';
			}
			if ( ! empty( $body['errors'][0]['message'] ) ) {
				return $body['errors'][0]['message'] . ' (HTTP ' . $http_code . ')';
			}
		}
		return 'HTTP ' . $http_code;
	}

	/**
	 * Find or create a WordPress user for the authenticated Twitter account.
	 *
	 * Priority:
	 *  1. Existing WP user already linked to this Twitter ID → log them in.
	 *  2. User is already logged into WordPress → link the Twitter account.
	 *  3. Neither → create a new subscriber account.
	 *
	 * @return int|WP_Error
	 */
	private function create_or_update_user( array $twitter_user, array $token_data ) {
		$twitter_id = sanitize_text_field( $twitter_user['id'] );
		$username   = sanitize_text_field( $twitter_user['username'] );
		$name       = sanitize_text_field( $twitter_user['name'] );
		$avatar     = esc_url_raw( $twitter_user['profile_image_url'] ?? '' );
		// Use _400x400 variant for a larger avatar.
		$avatar = str_replace( '_normal', '_400x400', $avatar );

		// 1. Check if any WP user is already linked to this Twitter ID.
		$existing = get_users( [
			'meta_key'   => 'stl_twitter_id',
			'meta_value' => $twitter_id,
			'number'     => 1,
			'fields'     => 'ID',
		] );

		if ( ! empty( $existing ) ) {
			$user_id = (int) $existing[0];
		} elseif ( is_user_logged_in() ) {
			// 2. Link to the currently logged-in WP account.
			$user_id = get_current_user_id();
		} else {
			// 3. Create a new WP user.
			$wp_username = $this->unique_username( $username );
			$user_id     = wp_insert_user( [
				'user_login'   => $wp_username,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => $name,
				// Placeholder email — Twitter API v2 requires special permissions for real emails.
				'user_email'   => $twitter_id . '@twitter-oauth.invalid',
				'role'         => 'subscriber',
			] );

			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
		}

		// Persist Twitter data in user meta.
		update_user_meta( $user_id, 'stl_twitter_id',       $twitter_id );
		update_user_meta( $user_id, 'stl_twitter_username', $username );
		update_user_meta( $user_id, 'stl_twitter_name',     $name );
		update_user_meta( $user_id, 'stl_twitter_avatar',   $avatar );

		// Encrypt OAuth tokens before storage (#1).
		update_user_meta( $user_id, 'stl_access_token',  $this->encrypt_token( $token_data['access_token'] ) );
		if ( ! empty( $token_data['refresh_token'] ) ) {
			update_user_meta( $user_id, 'stl_refresh_token', $this->encrypt_token( $token_data['refresh_token'] ) );
		}

		return $user_id;
	}

	/**
	 * Refresh a user's Twitter profile data (avatar, display name, handle) using
	 * their stored access token. Falls back to refreshing the access token via the
	 * stored refresh token if the first call returns a 401.
	 *
	 * Called by the daily cron so profile pictures stay current without requiring
	 * users to reconnect their account.
	 *
	 * @param  int  $user_id  WordPress user ID.
	 * @return bool  True if the profile was refreshed successfully, false otherwise.
	 */
	public function refresh_user_profile( int $user_id ): bool {
		$access_token_stored = get_user_meta( $user_id, 'stl_access_token', true );
		if ( ! $access_token_stored ) {
			return false;
		}
		$access_token = $this->decrypt_token( $access_token_stored );

		$user_data = $this->get_user_info( $access_token, 2 );

		// Access token may have expired — try a token refresh and retry once.
		if ( is_wp_error( $user_data ) ) {
			$refresh_token_stored = get_user_meta( $user_id, 'stl_refresh_token', true );
			if ( ! $refresh_token_stored ) {
				return false;
			}
			$refresh_token = $this->decrypt_token( $refresh_token_stored );

			$new_tokens = $this->do_refresh_token( $refresh_token );
			if ( is_wp_error( $new_tokens ) ) {
				return false;
			}

			update_user_meta( $user_id, 'stl_access_token', $this->encrypt_token( $new_tokens['access_token'] ) );
			if ( ! empty( $new_tokens['refresh_token'] ) ) {
				update_user_meta( $user_id, 'stl_refresh_token', $this->encrypt_token( $new_tokens['refresh_token'] ) );
			}

			$user_data = $this->get_user_info( $new_tokens['access_token'], 2 );
			if ( is_wp_error( $user_data ) ) {
				return false;
			}
		}

		$avatar = esc_url_raw( $user_data['profile_image_url'] ?? '' );
		$avatar = str_replace( '_normal', '_400x400', $avatar );

		if ( $avatar ) {
			update_user_meta( $user_id, 'stl_twitter_avatar', $avatar );
		}
		if ( ! empty( $user_data['name'] ) ) {
			update_user_meta( $user_id, 'stl_twitter_name', sanitize_text_field( $user_data['name'] ) );
		}
		if ( ! empty( $user_data['username'] ) ) {
			update_user_meta( $user_id, 'stl_twitter_username', sanitize_text_field( $user_data['username'] ) );
		}

		return true;
	}

	/**
	 * Exchange a refresh token for a new access token.
	 *
	 * @return array|WP_Error  Token response array on success.
	 */
	private function do_refresh_token( string $refresh_token ) {
		$response = wp_remote_post(
			self::TOKEN_URL,
			[
				'headers' => [
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->client_secret ),
				],
				'body'    => [
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
				],
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'refresh_failed', 'Token refresh failed.' );
		}

		return $data;
	}

	// -------------------------------------------------------------------------
	// Token encryption (#1) / secret decryption (#3)
	// -------------------------------------------------------------------------

	/**
	 * Encrypt a token string for database storage.
	 * Uses the same AES-256-GCM mechanism as the treasury key.
	 */
	private function encrypt_token( string $plaintext ): string {
		if ( empty( $plaintext ) || ! class_exists( 'STL_Solana_Signer' ) ) {
			return $plaintext;
		}
		$signer = new STL_Solana_Signer();
		return $signer->encrypt_key( $plaintext );
	}

	/**
	 * Decrypt a stored token, handling legacy plaintext values gracefully.
	 *
	 * Before encryption was added, tokens were stored as plain strings.
	 * If decryption fails we assume the stored value is legacy plaintext.
	 */
	private function decrypt_token( string $stored ): string {
		if ( empty( $stored ) || ! class_exists( 'STL_Solana_Signer' ) ) {
			return $stored;
		}
		$signer = new STL_Solana_Signer();
		try {
			return $signer->decrypt_key( $stored );
		} catch ( \Exception $e ) {
			// Legacy plaintext — return as-is.
			return $stored;
		}
	}

	/**
	 * Decrypt a stored option value (client secret, etc).
	 * Falls back to plaintext for backward compatibility.
	 */
	private function decrypt_stored( string $stored ): string {
		return $this->decrypt_token( $stored );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Hash the client IP for session-binding the OAuth state.
	 * Uses HMAC with AUTH_SALT so the hash can't be predicted from a known IP.
	 */
	private function hash_ip(): string {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
		return hash_hmac( 'sha256', $ip, AUTH_SALT );
	}

	private function unique_username( $base ) {
		$username = 'x_' . sanitize_user( $base, true );
		$original = $username;
		$i        = 1;
		while ( username_exists( $username ) ) {
			$username = $original . '_' . $i ++;
		}
		return $username;
	}

	// --- PKCE helpers ---

	private function generate_code_verifier() {
		// 32 random bytes → base64url (RFC 7636).
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	private function generate_code_challenge( $verifier ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}
}
