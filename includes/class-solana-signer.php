<?php
/**
 * Solana transaction signing and SPL token airdrop.
 *
 * Handles:
 *  - Base58 encode / decode (BCMath or GMP)
 *  - AES-256-GCM encryption of the treasury private key at rest
 *  - SPL Token transfer transaction construction (Solana legacy format)
 *  - Ed25519 signing via libsodium
 *  - RPC submission via sendTransaction
 *
 * Requirements: PHP 7.4+ (64-bit), ext-sodium, ext-openssl, ext-bcmath or ext-gmp
 */
class STL_Solana_Signer {

	/** Solana SPL Token Program ID (base58). */
	const TOKEN_PROGRAM_ID = 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA';

	/** Maximum recipients per transaction (stays well under 1232-byte limit). */
	const BATCH_SIZE = 10;

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Check that required PHP extensions are available.
	 *
	 * @return true|WP_Error
	 */
	public function check_requirements() {
		$missing = [];
		if ( ! extension_loaded( 'sodium' ) ) {
			$missing[] = 'sodium';
		}
		if ( ! extension_loaded( 'openssl' ) ) {
			$missing[] = 'openssl';
		}
		if ( ! extension_loaded( 'bcmath' ) && ! extension_loaded( 'gmp' ) ) {
			$missing[] = 'bcmath or gmp';
		}
		if ( $missing ) {
			return new WP_Error(
				'missing_ext',
				'Missing PHP extensions required for airdrop: ' . implode( ', ', $missing ) . '.'
			);
		}
		return true;
	}

	/**
	 * Decode a base58-encoded Solana private key (64 bytes: seed || public key).
	 *
	 * @param  string $b58  Base58 string as exported by Phantom / Solflare.
	 * @return array  ['secret' => string(64), 'public' => string(32)]
	 * @throws \Exception on invalid input.
	 */
	public function decode_private_key( string $b58 ): array {
		$bytes = $this->b58_decode( trim( $b58 ) );

		if ( strlen( $bytes ) !== 64 ) {
			throw new \Exception( 'Private key must be 64 bytes (got ' . strlen( $bytes ) . '). Export your full keypair from Phantom/Solflare.' );
		}

		$seed       = substr( $bytes, 0, 32 );
		$kp         = sodium_crypto_sign_seed_keypair( $seed );
		$public_key = sodium_crypto_sign_publickey( $kp );  // 32 bytes
		$secret_key = sodium_crypto_sign_secretkey( $kp );  // 64 bytes: seed || public

		return [ 'secret' => $secret_key, 'public' => $public_key ];
	}

	/**
	 * Encrypt a private key string for database storage using AES-256-GCM.
	 * The encryption key is derived from WordPress's AUTH_KEY + AUTH_SALT constants.
	 *
	 * @param  string $plaintext  Raw private key string (base58 or bytes).
	 * @return string  base64-encoded: IV(12) || tag(16) || ciphertext
	 */
	public function encrypt_key( string $plaintext ): string {
		$enc_key = hash( 'sha256', AUTH_KEY . AUTH_SALT, true ); // 32 bytes
		$iv      = random_bytes( 12 );
		$tag     = '';
		$cipher  = openssl_encrypt( $plaintext, 'aes-256-gcm', $enc_key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
		return base64_encode( $iv . $tag . $cipher );
	}

	/**
	 * Decrypt a stored private key.
	 *
	 * @param  string $stored  Value previously returned by encrypt_key().
	 * @return string  Plaintext private key string.
	 * @throws \Exception on decryption failure.
	 */
	public function decrypt_key( string $stored ): string {
		$raw    = base64_decode( $stored );
		$iv     = substr( $raw, 0, 12 );
		$tag    = substr( $raw, 12, 16 );
		$cipher = substr( $raw, 28 );
		$enc_key = hash( 'sha256', AUTH_KEY . AUTH_SALT, true );
		$plain   = openssl_decrypt( $cipher, 'aes-256-gcm', $enc_key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( $plain === false ) {
			throw new \Exception( 'Failed to decrypt treasury key — AUTH_KEY/AUTH_SALT may have changed.' );
		}
		return $plain;
	}

	/**
	 * Base58-encode binary bytes.
	 */
	public function b58_encode( string $bytes ): string {
		$alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
		$result   = '';

		if ( function_exists( 'bcadd' ) ) {
			$n = '0';
			for ( $i = 0; $i < strlen( $bytes ); $i++ ) {
				$n = bcadd( bcmul( $n, '256' ), (string) ord( $bytes[ $i ] ) );
			}
			while ( bccomp( $n, '0' ) > 0 ) {
				$rem    = (int) bcmod( $n, '58' );
				$n      = bcdiv( $n, '58', 0 );
				$result = $alphabet[ $rem ] . $result;
			}
		} else {
			$n = gmp_init( 0 );
			for ( $i = 0; $i < strlen( $bytes ); $i++ ) {
				$n = gmp_add( gmp_mul( $n, 256 ), ord( $bytes[ $i ] ) );
			}
			$zero = gmp_init( 0 );
			while ( gmp_cmp( $n, $zero ) > 0 ) {
				[ $n, $rem ] = gmp_div_qr( $n, 58 );
				$result = $alphabet[ gmp_intval( $rem ) ] . $result;
			}
		}

		// Preserve leading zero bytes as '1's.
		for ( $i = 0; $i < strlen( $bytes ) && ord( $bytes[ $i ] ) === 0; $i++ ) {
			$result = '1' . $result;
		}

		return $result;
	}

	/**
	 * Sign and send SPL token transfers to a batch of recipients.
	 *
	 * Each batch of up to BATCH_SIZE recipients is grouped into one Solana
	 * transaction. A fresh blockhash is fetched per batch.
	 *
	 * @param  string $private_key_b58  Treasury 64-byte keypair in base58.
	 * @param  array  $recipients       [{wallet, token_account, amount_raw, amount_ui, twitter}]
	 * @return array  [{twitter, wallet, amount_ui, signature|null, error|null, status}]
	 */
	public function airdrop( string $private_key_b58, array $recipients ): array {
		$kp         = $this->decode_private_key( $private_key_b58 );
		$secret_key = $kp['secret'];
		$signer_pub = $kp['public'];
		$mint       = get_option( 'stl_token_mint', STL_TOKEN_MINT );
		$signer_b58 = $this->b58_encode( $signer_pub );

		// Locate the treasury's token account for this mint.
		$source_ata = $this->get_token_account( $signer_b58, $mint );
		if ( ! $source_ata ) {
			sodium_memzero( $secret_key );
			$err = 'Treasury wallet has no token account for this mint. It must hold tokens before sending.';
			return array_map(
				fn( $r ) => $this->make_result( $r, null, $err ),
				$recipients
			);
		}

		$results = [];

		foreach ( array_chunk( $recipients, self::BATCH_SIZE ) as $chunk ) {
			$blockhash = $this->get_latest_blockhash();
			if ( is_wp_error( $blockhash ) ) {
				foreach ( $chunk as $r ) {
					$results[] = $this->make_result( $r, null, $blockhash->get_error_message() );
				}
				continue;
			}

			$message   = $this->build_message( $blockhash, $signer_pub, $source_ata, $chunk );
			$signed_tx = $this->sign_message( $message, $secret_key );
			$sig       = $this->send_transaction( $signed_tx );

			foreach ( $chunk as $r ) {
				$results[] = $this->make_result(
					$r,
					is_wp_error( $sig ) ? null : $sig,
					is_wp_error( $sig ) ? $sig->get_error_message() : null
				);
			}
		}

		sodium_memzero( $secret_key );

		return $results;
	}

	// -------------------------------------------------------------------------
	// RPC helpers
	// -------------------------------------------------------------------------

	/**
	 * Get the latest confirmed blockhash from the Solana RPC.
	 *
	 * @return string|WP_Error  Base58 blockhash string.
	 */
	public function get_latest_blockhash() {
		$response = wp_remote_post(
			get_option( 'stl_solana_rpc', STL_SOLANA_RPC ),
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'getLatestBlockhash',
					'params'  => [ [ 'commitment' => 'confirmed' ] ],
				] ),
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['result']['value']['blockhash'] ) ) {
			return $body['result']['value']['blockhash'];
		}

		return new WP_Error( 'rpc_error', 'Could not fetch latest blockhash from RPC.' );
	}

	/**
	 * Find the first token account a wallet holds for a given mint.
	 *
	 * @param  string $wallet  Base58 wallet address.
	 * @param  string $mint    Base58 mint address.
	 * @return string|null     Token account public key, or null if none found.
	 */
	public function get_token_account( string $wallet, string $mint ): ?string {
		$response = wp_remote_post(
			get_option( 'stl_solana_rpc', STL_SOLANA_RPC ),
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'getTokenAccountsByOwner',
					'params'  => [
						$wallet,
						[ 'mint' => $mint ],
						[ 'encoding' => 'jsonParsed' ],
					],
				] ),
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return $body['result']['value'][0]['pubkey'] ?? null;
	}

	// -------------------------------------------------------------------------
	// Transaction construction
	// -------------------------------------------------------------------------

	/**
	 * Build an unsigned Solana legacy transaction message.
	 *
	 * Account layout:
	 *   [0]      signer / authority (writable, signer)
	 *   [1]      source ATA        (writable)
	 *   [2..N+1] destination ATAs  (writable, one per recipient)
	 *   [N+2]    Token Program     (read-only)
	 *
	 * @param  string $blockhash_b58   Recent blockhash (base58).
	 * @param  string $signer_pub      32-byte signer public key (raw bytes).
	 * @param  string $source_ata_b58  Source token account (base58).
	 * @param  array  $recipients      [{token_account (base58), amount_raw (int)}]
	 * @return string Binary message bytes (unsigned).
	 */
	private function build_message(
		string $blockhash_b58,
		string $signer_pub,
		string $source_ata_b58,
		array $recipients
	): string {
		$token_prog = $this->b58_decode( self::TOKEN_PROGRAM_ID );
		$source_ata = $this->b58_decode( $source_ata_b58 );
		$blockhash  = $this->b58_decode( $blockhash_b58 );

		$accounts  = [ $signer_pub, $source_ata ];
		$dest_idxs = [];
		foreach ( $recipients as $i => $r ) {
			$accounts[]  = $this->b58_decode( $r['token_account'] );
			$dest_idxs[] = 2 + $i;
		}
		$accounts[] = $token_prog;
		$prog_idx   = count( $accounts ) - 1;

		// Header: 1 required signer, 0 read-only signers, 1 read-only unsigned (Token Program).
		$header = chr( 1 ) . chr( 0 ) . chr( 1 );

		$accounts_bin = $this->compact_u16( count( $accounts ) ) . implode( '', $accounts );

		$instructions = '';
		foreach ( $recipients as $i => $r ) {
			$dest_idx = $dest_idxs[ $i ];
			// SPL Token Transfer: discriminant=3, then u64-LE amount (9 bytes total).
			$data = chr( 3 ) . $this->pack_u64_le( (int) $r['amount_raw'] );

			$instructions .= chr( $prog_idx )
				. $this->compact_u16( 3 ) . chr( 1 ) . chr( $dest_idx ) . chr( 0 ) // source, dest, authority
				. $this->compact_u16( strlen( $data ) ) . $data;
		}

		return $header
			. $accounts_bin
			. $blockhash
			. $this->compact_u16( count( $recipients ) )
			. $instructions;
	}

	/**
	 * Sign a message with Ed25519 and return the complete serialized transaction.
	 *
	 * @param  string $message     Binary message bytes.
	 * @param  string $secret_key  64-byte Ed25519 secret key.
	 * @return string  Binary signed transaction (compact_u16(1) || sig(64) || message).
	 */
	private function sign_message( string $message, string $secret_key ): string {
		$sig = sodium_crypto_sign_detached( $message, $secret_key ); // 64 bytes
		return chr( 1 ) . $sig . $message;                           // compact_u16(1) = chr(1)
	}

	/**
	 * Submit a signed transaction to the Solana RPC.
	 *
	 * @param  string $signed_tx  Binary signed transaction.
	 * @return string|WP_Error    Transaction signature on success.
	 */
	private function send_transaction( string $signed_tx ) {
		$response = wp_remote_post(
			get_option( 'stl_solana_rpc', STL_SOLANA_RPC ),
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'sendTransaction',
					'params'  => [
						base64_encode( $signed_tx ),
						[
							'encoding'            => 'base64',
							'skipPreflight'       => false,
							'preflightCommitment' => 'confirmed',
						],
					],
				] ),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['error'] ) ) {
			$msg  = $body['error']['message'] ?? 'Transaction failed.';
			$logs = $body['error']['data']['logs'] ?? [];
			if ( $logs ) {
				// Surface the most relevant program log lines.
				$msg .= ' — ' . implode( ' | ', array_slice( $logs, -3 ) );
			}
			return new WP_Error( 'tx_error', $msg );
		}

		if ( ! empty( $body['result'] ) ) {
			return $body['result'];
		}

		return new WP_Error( 'tx_error', 'No transaction signature returned by RPC.' );
	}

	// -------------------------------------------------------------------------
	// Binary encoding helpers
	// -------------------------------------------------------------------------

	/**
	 * Encode an unsigned integer as Solana compact-u16 (1–3 bytes).
	 */
	private function compact_u16( int $n ): string {
		if ( $n < 0x80 ) {
			return chr( $n );
		}
		if ( $n < 0x4000 ) {
			return chr( ( $n & 0x7f ) | 0x80 ) . chr( $n >> 7 );
		}
		return chr( ( $n & 0x7f ) | 0x80 )
			. chr( ( ( $n >> 7 ) & 0x7f ) | 0x80 )
			. chr( $n >> 14 );
	}

	/**
	 * Pack an integer as unsigned little-endian 64-bit (8 bytes).
	 * Requires 64-bit PHP (standard on all modern hosts).
	 */
	private function pack_u64_le( int $n ): string {
		return pack( 'VV', $n & 0xFFFFFFFF, ( $n >> 32 ) & 0xFFFFFFFF );
	}

	// -------------------------------------------------------------------------
	// Base58 decode
	// -------------------------------------------------------------------------

	/**
	 * Decode a base58 string to raw binary bytes (BCMath or GMP).
	 *
	 * @throws \Exception on invalid characters.
	 */
	private function b58_decode( string $input ): string {
		$alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
		$result   = '';

		if ( function_exists( 'bcadd' ) ) {
			$n = '0';
			for ( $i = 0; $i < strlen( $input ); $i++ ) {
				$p = strpos( $alphabet, $input[ $i ] );
				if ( $p === false ) {
					throw new \Exception( "Invalid base58 character: '{$input[$i]}'" );
				}
				$n = bcadd( bcmul( $n, '58' ), (string) $p );
			}
			while ( bccomp( $n, '0' ) > 0 ) {
				$result = chr( (int) bcmod( $n, '256' ) ) . $result;
				$n      = bcdiv( $n, '256', 0 );
			}
		} else {
			$n = gmp_init( 0 );
			for ( $i = 0; $i < strlen( $input ); $i++ ) {
				$p = strpos( $alphabet, $input[ $i ] );
				if ( $p === false ) {
					throw new \Exception( "Invalid base58 character: '{$input[$i]}'" );
				}
				$n = gmp_add( gmp_mul( $n, 58 ), $p );
			}
			$zero = gmp_init( 0 );
			while ( gmp_cmp( $n, $zero ) > 0 ) {
				[ $n, $rem ] = gmp_div_qr( $n, 256 );
				$result = chr( gmp_intval( $rem ) ) . $result;
			}
		}

		// Leading '1's in base58 represent null bytes.
		for ( $i = 0; $i < strlen( $input ) && $input[ $i ] === '1'; $i++ ) {
			$result = chr( 0 ) . $result;
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	private function make_result( array $r, ?string $sig, ?string $error ): array {
		return [
			'twitter'    => $r['twitter']    ?? '',
			'wallet'     => $r['wallet']     ?? '',
			'amount_ui'  => $r['amount_ui']  ?? null,
			'amount_raw' => $r['amount_raw'] ?? 0,
			'signature'  => $sig,
			'error'      => $error,
			'status'     => $sig ? 'success' : 'error',
		];
	}
}
