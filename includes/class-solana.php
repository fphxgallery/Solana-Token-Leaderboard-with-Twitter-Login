<?php
/**
 * Handles Solana JSON-RPC calls to fetch SPL token balances.
 *
 * Uses getTokenAccountsByOwner filtered by mint address to find how many
 * tokens a given wallet holds.
 */
class STL_Solana {

	private $rpc_url;

	public function __construct() {
		$this->rpc_url = get_option( 'stl_solana_rpc', STL_SOLANA_RPC );
	}

	/**
	 * Fetch the token balance for a wallet address.
	 *
	 * @param  string $wallet_address  Base58 Solana public key.
	 * @return array|WP_Error  ['raw' => string, 'ui' => float, 'decimals' => int, 'accounts' => int]
	 */
	public function get_token_balance( $wallet_address ) {
		$mint = get_option( 'stl_token_mint', STL_TOKEN_MINT );

		$payload = wp_json_encode( [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'getTokenAccountsByOwner',
			'params'  => [
				$wallet_address,
				[ 'mint' => $mint ],
				[ 'encoding' => 'jsonParsed' ],
			],
		] );

		$response = wp_remote_post( $this->rpc_url, [
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => $payload,
			'timeout' => 20,
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error'] ) ) {
			return new WP_Error(
				'rpc_error',
				sanitize_text_field( $body['error']['message'] ?? 'RPC error' )
			);
		}

		if ( $http_code !== 200 || ! isset( $body['result']['value'] ) ) {
			return new WP_Error( 'rpc_bad_response', 'Unexpected Solana RPC response.' );
		}

		$accounts   = $body['result']['value'];
		$raw_total  = '0';
		$ui_total   = 0.0;
		$decimals   = 0;

		foreach ( $accounts as $account ) {
			$token_amount = $account['account']['data']['parsed']['info']['tokenAmount'] ?? null;
			if ( $token_amount ) {
				// Use bcadd for precision with large raw amounts.
				$raw_total = function_exists( 'bcadd' )
					? bcadd( $raw_total, (string) $token_amount['amount'] )
					: (string) ( (float) $raw_total + (float) $token_amount['amount'] );
				$ui_total += (float) $token_amount['uiAmount'];
				$decimals  = (int) $token_amount['decimals'];
			}
		}

		return [
			'raw'      => $raw_total,
			'ui'       => $ui_total,
			'decimals' => $decimals,
			'accounts' => count( $accounts ),
		];
	}

	/**
	 * Fetch the balance for a user's linked wallet and persist to user meta.
	 *
	 * @param  int  $user_id
	 * @return array|false|WP_Error
	 */
	public function update_user_balance( $user_id ) {
		$wallet = get_user_meta( $user_id, 'stl_solana_wallet', true );
		if ( empty( $wallet ) ) {
			return false;
		}

		$balance = $this->get_token_balance( $wallet );
		if ( is_wp_error( $balance ) ) {
			return $balance;
		}

		update_user_meta( $user_id, 'stl_token_balance_raw',     $balance['raw'] );
		update_user_meta( $user_id, 'stl_token_balance_ui',      $balance['ui'] );
		update_user_meta( $user_id, 'stl_token_decimals',        $balance['decimals'] );
		update_user_meta( $user_id, 'stl_balance_last_updated',  time() );

		return $balance;
	}
}
