# Solana Token Leaderboard with Twitter Login

A WordPress plugin that lets users log in with their X (Twitter) account, link a Solana wallet, and appear on a ranked token balance leaderboard. Includes a built-in airdrop tool for sending SPL tokens or native SOL directly to leaderboard holders from a treasury wallet.

## Features

- **Twitter / X OAuth 2.0 login** — PKCE + confidential client; no passwords stored
- **Solana wallet linking** — users paste their wallet address after logging in
- **Daily balance sync** — WP-Cron fetches each user's SPL token balance once per day; can also be triggered manually from the admin panel
- **Daily profile refresh** — avatars, display names, and handles are re-fetched alongside the balance check so leaderboard photos stay current
- **Leaderboard shortcode** — ranked table with medals, avatars, Solscan wallet links, customizable color scheme
- **Airdrop tool** — send SPL tokens or native SOL to leaderboard holders from an encrypted treasury wallet; preview distribution before sending
- **Four shortcodes** — login button, wallet form, token balance card, leaderboard

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 5.8 |
| PHP | 7.4 (64-bit) |
| PHP extensions | `sodium`, `openssl`, `bcmath` or `gmp` (airdrop only) |
| Twitter Developer App | OAuth 2.0, confidential client, `tweet.read users.read offline.access` scopes |

## Installation

1. Upload the `solana-twitter-login` folder to `wp-content/plugins/`
2. Activate the plugin in **Plugins → Installed Plugins**
3. Go to **Settings → Solana Twitter Login** and fill in your credentials

## Twitter / X App Setup

1. Create an app at [developer.twitter.com](https://developer.twitter.com/en/portal/dashboard)
2. Enable **OAuth 2.0** — set the client type to **Confidential client**
3. Add the callback URL shown on the settings page: `https://yoursite.com/?stl_action=twitter_callback`
4. Enable scopes: `tweet.read`, `users.read`, `offline.access`
5. Copy the **Client ID** and **Client Secret** into the plugin settings

## Shortcodes

### `[stl_login_button]`
A **Login with X** button for guests. Once connected, shows the user's avatar, display name, handle, and a Disconnect button.

### `[stl_wallet_form]`
A form for logged-in users to link, change, or remove their Solana wallet. Fetches the token balance immediately on save.

### `[stl_token_balance]`
A card showing the current user's token balance and last-updated time. Hidden for guests and users without a wallet.

### `[stl_leaderboard]`
A ranked table of all users sorted by token balance.

| Attribute | Default | Description |
|---|---|---|
| `limit` | `25` | Max rows shown (max 200) |
| `show_wallet` | `yes` | Show truncated wallet address linked to Solscan |
| `show_updated` | `yes` | Show "last updated" time per row |
| `highlight` | `yes` | Highlight the logged-in user's row with a "You" badge |

**Example layouts:**

```
[stl_login_button]
[stl_wallet_form]
[stl_token_balance]
```

```
[stl_leaderboard limit="10" show_wallet="no"]
```

## Airdrop Tool

Found under **Settings → Solana Twitter Login → Airdrop Tokens**.

### Treasury wallet setup

1. Export your treasury wallet's **64-byte Base58 keypair** from Phantom or Solflare
2. Paste it into the **Private Key** field and save — the key is encrypted with AES-256-GCM before being written to the database (key derived from WordPress's `AUTH_KEY` + `AUTH_SALT`)
3. Verify the derived **Public Key** displayed matches your wallet address

> **Use a dedicated treasury wallet — never your main wallet.**

### Sending an airdrop

1. Choose **Asset**: SPL Token (the configured mint) or **Native SOL**
2. Enter the **total amount** to distribute
3. Choose **Distribution method**:
   - *By current token balance* — each recipient's share is proportional to their holdings
   - *By leaderboard rank* — rank 1 receives the largest share (weight = 1/rank)
4. Set **Limit to top N** users
5. Click **Preview Distribution** — a table shows each recipient's wallet and calculated amount; users without a token account for the mint are skipped and listed as "skipped"
6. Click **Confirm Airdrop** — transactions are signed server-side with libsodium and submitted in batches of up to 10 recipients per transaction
7. Results show per-recipient status with links to each transaction on Solscan

### How signing works

- Ed25519 signing via PHP's built-in `libsodium` — no external libraries required
- SPL token transfers use the **SPL Token Program** (`TokenkegQfe...`)
- Native SOL transfers use the **System Program** (`1111...`) with `Transfer` instruction (discriminant 2)
- Up to 10 recipients per transaction (well within the 1232-byte transaction size limit)
- The decrypted private key is zeroed from memory with `sodium_memzero()` immediately after signing

## Settings Reference

| Setting | Description |
|---|---|
| Twitter Client ID | OAuth 2.0 client ID from the developer portal |
| Twitter Client Secret | Encrypted at rest — paste to set, leave blank to keep existing |
| Token Mint Address | SPL token mint (defaults to the plugin constant) |
| Solana RPC Endpoint | Mainnet RPC URL (default is public; use Helius/QuickNode for production) |
| After Login Redirect URL | Where to send users after a successful Twitter login |
| Leaderboard Colors | Five color pickers for gradient, accent, rank highlight, and "You" row background |
| Treasury Private Key | Encrypted 64-byte Base58 keypair for the airdrop treasury wallet |

## Security

- **OAuth tokens encrypted at rest** — Twitter access and refresh tokens are stored encrypted (AES-256-GCM) in the database, not as plaintext
- **Client secret encrypted at rest** — stored the same way as the treasury key; never echoed back to the page
- **Server-side airdrop preview** — recipients are stored in a server-side transient after preview; the confirm step loads from there, preventing client-side tampering
- **OAuth state IP binding** — the OAuth state token is HMAC-bound to the initiating IP address, preventing session fixation and CSRF
- **Rate limiting** — OAuth callback is limited to 10 attempts per IP per 5 minutes
- **Strict wallet validation** — addresses are base58-decoded and verified to produce exactly 32 bytes before acceptance
- **No raw SQL** — all data access goes through the WordPress meta API

## Changelog

### 2.1.3
- Security: encrypt OAuth access/refresh tokens in `wp_usermeta` (AES-256-GCM)
- Security: encrypt Twitter client secret at rest; settings form uses placeholder instead of echoing value
- Security: airdrop execute loads recipients from server-side transient, not client-submitted JSON
- Security: OAuth state token is HMAC-bound to client IP to prevent CSRF/session fixation
- Security: rate limit OAuth callback to 10 requests per IP per 5 minutes
- Security: wallet validation now base58-decodes and checks for 32 bytes
- Security: generic user-facing error messages; detailed errors logged server-side only
- Security: cap OAuth callback retry sleep to 1s (was up to 15s) to prevent PHP worker exhaustion

### 2.1.2
- Fix: Twitter avatars and profile data now refresh during the daily balance check cron so leaderboard photos stay current without users needing to reconnect

### 2.1.1
- Fix: native SOL airdrop was returning invalid JSON due to `error_log()` output bleeding into the HTTP response in some PHP environments

### 2.1.0
- Add native SOL airdrop support — choose between SPL Token or Native SOL in the Airdrop panel; uses System Program `Transfer` instruction

### 2.0.0
- Add airdrop feature: treasury wallet import (AES-256-GCM encrypted), preview distribution, batch SPL token transfers signed with libsodium Ed25519
- Two distribution methods: proportional by balance, or by leaderboard rank

### 1.0.0
- Initial release: Twitter/X OAuth login, Solana wallet linking, daily balance sync, leaderboard shortcode with color customization
