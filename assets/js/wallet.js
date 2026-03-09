/**
 * Solana Twitter Login — frontend interactions.
 * Depends on jQuery (WordPress default) and stlVars (localized).
 */
(function ($) {
	'use strict';

	var ajaxUrl = stlVars.ajaxUrl;
	var nonce   = stlVars.nonce;

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	function showMsg($el, text, type) {
		$el
			.removeClass('stl-msg-success stl-msg-error')
			.addClass('stl-msg-' + type)
			.text(text);
	}

	function post(action, extra, done, fail) {
		$.post(ajaxUrl, $.extend({ action: action, nonce: nonce }, extra))
			.done(function (r) {
				if (r.success) {
					done(r.data);
				} else {
					fail(r.data || 'Something went wrong.');
				}
			})
			.fail(function () {
				fail('Request failed. Please try again.');
			});
	}

	// -------------------------------------------------------------------------
	// Link wallet
	// -------------------------------------------------------------------------

	$(document).on('click', '#stl-save-wallet', function () {
		var $btn    = $(this);
		var $msg    = $('#stl-wallet-message');
		var wallet  = $('#stl-wallet-address').val().trim();

		if (!wallet) {
			showMsg($msg, 'Please enter a wallet address.', 'error');
			return;
		}

		$btn.prop('disabled', true).text('Linking…');

		post(
			'stl_save_wallet',
			{ wallet: wallet },
			function (data) {
				showMsg($msg, data.message, 'success');
				// Reload to reflect the new state (wallet-linked view).
				setTimeout(function () { location.reload(); }, 1000);
			},
			function (err) {
				showMsg($msg, err, 'error');
				$btn.prop('disabled', false).text('Link Wallet');
			}
		);
	});

	// -------------------------------------------------------------------------
	// Refresh balance on demand
	// -------------------------------------------------------------------------

	$(document).on('click', '#stl-refresh-balance', function () {
		var $btn = $(this);
		var $msg = $('#stl-wallet-message');

		$btn.prop('disabled', true).text('Refreshing…');

		post(
			'stl_refresh_balance',
			{},
			function (data) {
				showMsg($msg, 'Balance updated: ' + parseFloat(data.balance).toLocaleString(undefined, { maximumFractionDigits: 4 }), 'success');
				// Update the balance display if it's on the same page.
				var $display = $('#stl-balance-display');
				if ($display.length) {
					$display.text(parseFloat(data.balance).toFixed(4));
				}
				$btn.prop('disabled', false).text('Refresh Balance');
			},
			function (err) {
				showMsg($msg, err, 'error');
				$btn.prop('disabled', false).text('Refresh Balance');
			}
		);
	});

	// -------------------------------------------------------------------------
	// Switch to input form (change wallet)
	// -------------------------------------------------------------------------

	$(document).on('click', '#stl-change-wallet', function () {
		$('#stl-wallet-linked').addClass('stl-hidden');
		$('#stl-wallet-input-area').removeClass('stl-hidden');
		$('#stl-wallet-address').focus().select();
	});

	// -------------------------------------------------------------------------
	// Cancel change (go back to linked view)
	// -------------------------------------------------------------------------

	$(document).on('click', '#stl-cancel-change', function () {
		$('#stl-wallet-input-area').addClass('stl-hidden');
		$('#stl-wallet-linked').removeClass('stl-hidden');
		$('#stl-wallet-message').removeClass('stl-msg-success stl-msg-error').text('').hide();
	});

	// -------------------------------------------------------------------------
	// Remove wallet
	// -------------------------------------------------------------------------

	$(document).on('click', '#stl-remove-wallet', function () {
		if (!window.confirm('Remove your linked Solana wallet?')) {
			return;
		}

		var $btn = $(this);
		$btn.prop('disabled', true).text('Removing…');

		post(
			'stl_remove_wallet',
			{},
			function () {
				location.reload();
			},
			function (err) {
				$('#stl-wallet-message') && showMsg($('#stl-wallet-message'), err, 'error');
				$btn.prop('disabled', false).text('Remove');
			}
		);
	});

	// -------------------------------------------------------------------------
	// Disconnect X / Twitter
	// -------------------------------------------------------------------------

	$(document).on('click', '#stl-disconnect-twitter', function () {
		if (!window.confirm('Disconnect your X (Twitter) account from this site?')) {
			return;
		}

		var $btn = $(this);
		$btn.prop('disabled', true).text('Disconnecting…');

		post(
			'stl_disconnect_twitter',
			{},
			function () {
				location.reload();
			},
			function (err) {
				alert(err);
				$btn.prop('disabled', false).text('Disconnect X');
			}
		);
	});

})(jQuery);
