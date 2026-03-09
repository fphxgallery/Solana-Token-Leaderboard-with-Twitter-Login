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

	private $client_id;
	private $client_secret;
	private $redirect_uri;

	public function __construct() {
		$this->client_id     = get_option( 'stl_twitter_client_id', '' );
		$this->client_secret = get_option( 'stl_twitter_client_secret', '' );
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

		set_transient( 'stl_oauth_' . $state, $code_verifier, 10 * MINUTE_IN_SECONDS );

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
		$code_verifier = get_transient( 'stl_oauth_' . $state );
		if ( ! $code_verifier ) {
			return new WP_Error( 'invalid_state', 'OAuth state mismatch. Please try logging in again.' );
		}
		delete_transient( 'stl_oauth_' . $state );

		$token_data = $this->exchange_code( $code, $code_verifier );
		if ( is_wp_error( $token_data ) ) {
			return $token_data;
		}

		$twitter_user = $this->get_user_info( $token_data['access_token'] );
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
		$max_net_attempts = 3;
		for ( $attempt = 1; $attempt <= $max_net_attempts; $attempt++ ) {
			$response = wp_remote_post( self::TOKEN_URL, $args );

			if ( ! is_wp_error( $response ) ) {
				break; // Got an HTTP response (good or bad) — don't retry.
			}

			if ( $attempt < $max_net_attempts ) {
				sleep( 1 << ( $attempt - 1 ) ); // 1 s, 2 s
			}
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['access_token'] ) ) {
			$http_code = (int) wp_remote_retrieve_response_code( $response );
			// Surface a friendly message for transient token-endpoint outages.
			if ( in_array( $http_code, self::RETRYABLE_CODES, true ) ) {
				return new WP_Error(
					'token_error',
					$this->extract_twitter_error( $data, $http_code )
				);
			}
			$msg = $data['error_description'] ?? $data['error'] ?? 'Unknown token error.';
			return new WP_Error( 'token_error', $msg . ' (HTTP ' . $http_code . ')' );
		}

		return $data;
	}

	/**
	 * HTTP status codes that indicate a transient server-side problem.
	 * These are safe to retry without any changes to the request.
	 */
	const RETRYABLE_CODES = [ 429, 500, 502, 503, 504 ];

	private function get_user_info( $access_token ) {
		// 5 attempts with exponential back-off: 1 s → 2 s → 4 s → 8 s between tries.
		// Total worst-case sleep: 15 s, well within default PHP execution limits.
		$max_attempts   = 5;
		$last_http_code = 0;
		$last_data      = null;

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$response = wp_remote_get(
				self::USER_URL . '?user.fields=profile_image_url,name,username',
				[
					'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
					'timeout' => 10, // Slightly shorter so retries fit in budget.
				]
			);

			// Network-level failure — retry if attempts remain.
			if ( is_wp_error( $response ) ) {
				if ( $attempt < $max_attempts ) {
					sleep( 1 << ( $attempt - 1 ) ); // 1 s, 2 s, 4 s, 8 s
					continue;
				}
				return $response;
			}

			$last_http_code = (int) wp_remote_retrieve_response_code( $response );
			$last_data      = json_decode( wp_remote_retrieve_body( $response ), true );

			// Success.
			if ( ! empty( $last_data['data'] ) ) {
				return $last_data['data'];
			}

			// Transient server error — wait and retry.
			if ( in_array( $last_http_code, self::RETRYABLE_CODES, true ) && $attempt < $max_attempts ) {
				sleep( 1 << ( $attempt - 1 ) ); // 1 s, 2 s, 4 s, 8 s
				continue;
			}

			// Non-retryable error — stop immediately.
			break;
		}

		return new WP_Error( 'user_error', $this->extract_twitter_error( $last_data, $last_http_code ) );
	}

	/**
	 * Pull a human-readable message out of a Twitter API v2 error response.
	 * Twitter can return several different error shapes depending on the endpoint
	 * and tier, so we check each one in order of specificity.
	 *
	 * @param  array|null $body       Decoded JSON body (may be null on parse failure).
	 * @param  int        $http_code  HTTP status code.
	 * @return string
	 */
	private function extract_twitter_error( $body, $http_code ) {
		// Transient infrastructure errors — user-friendly message, no config hints.
		if ( $http_code === 503 || $http_code === 502 ) {
			return 'X/Twitter\'s API is temporarily unavailable (HTTP ' . $http_code . '). Please try logging in again in a moment.';
		}
		if ( $http_code === 429 ) {
			return 'X/Twitter rate limit reached (HTTP 429). Please wait a minute and try again.';
		}

		if ( is_array( $body ) ) {
			// OAuth / app-level errors: { "error": "...", "error_description": "..." }
			if ( ! empty( $body['error_description'] ) ) {
				return $body['error_description'] . ' (HTTP ' . $http_code . ')';
			}
			// API v2 problem details: { "title": "...", "detail": "..." }
			if ( ! empty( $body['detail'] ) ) {
				return $body['detail'] . ' (HTTP ' . $http_code . ')';
			}
			if ( ! empty( $body['title'] ) ) {
				return $body['title'] . ' (HTTP ' . $http_code . ')';
			}
			// API v2 errors array: { "errors": [{ "message": "..." }] }
			if ( ! empty( $body['errors'][0]['message'] ) ) {
				return $body['errors'][0]['message'] . ' (HTTP ' . $http_code . ')';
			}
		}

		// Fallback — include the HTTP code so at least something actionable is shown.
		return 'Could not retrieve user data from X/Twitter (HTTP ' . $http_code . '). '
			. 'Common causes: app missing "Read" permission, incorrect OAuth 2.0 setup, '
			. 'or callback URL mismatch in the developer portal.';
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
		update_user_meta( $user_id, 'stl_access_token',     $token_data['access_token'] );

		if ( ! empty( $token_data['refresh_token'] ) ) {
			update_user_meta( $user_id, 'stl_refresh_token', $token_data['refresh_token'] );
		}

		return $user_id;
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
