/* global stlAirdrop */
(function () {
	'use strict';

	var nonce    = stlAirdrop.nonce;
	var ajaxUrl  = stlAirdrop.ajaxUrl;
	var preview  = null; // last successful preview payload

	var previewBtn  = document.getElementById( 'stl-airdrop-preview' );
	var confirmBtn  = document.getElementById( 'stl-airdrop-confirm' );
	var previewArea = document.getElementById( 'stl-airdrop-preview-area' );
	var resultArea  = document.getElementById( 'stl-airdrop-result-area' );
	var msgEl       = document.getElementById( 'stl-airdrop-msg' );

	if ( ! previewBtn ) return;

	// -------------------------------------------------------------------------
	// Preview
	// -------------------------------------------------------------------------
	previewBtn.addEventListener( 'click', function () {
		var total  = parseFloat( document.getElementById( 'stl-airdrop-total' ).value );
		var method = document.querySelector( 'input[name="stl_airdrop_method"]:checked' ).value;
		var topN   = parseInt( document.getElementById( 'stl-airdrop-top-n' ).value, 10 );
		var asset  = document.querySelector( 'input[name="stl_airdrop_asset"]:checked' ).value;

		if ( isNaN( total ) || total <= 0 ) {
			showMsg( 'Please enter a valid total amount greater than 0.', 'error' );
			return;
		}

		previewBtn.disabled    = true;
		previewBtn.textContent = 'Loading\u2026';
		previewArea.innerHTML  = '';
		resultArea.innerHTML   = '';
		confirmBtn.style.display = 'none';
		msgEl.innerHTML = '';

		var fd = new FormData();
		fd.append( 'action',       'stl_preview_airdrop' );
		fd.append( 'nonce',        nonce );
		fd.append( 'total_amount', total );
		fd.append( 'method',       method );
		fd.append( 'top_n',        topN );
		fd.append( 'asset',        asset );

		fetch( ajaxUrl, { method: 'POST', body: fd } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( ! data.success ) {
					showMsg( data.data || 'Preview failed.', 'error' );
					return;
				}
				preview = data.data;
				renderPreview( preview );
				confirmBtn.style.display = 'inline-block';
				if ( preview.skipped > 0 ) {
					showMsg( preview.skipped + ' user(s) skipped (no wallet or no token account).', 'warn' );
				}
			} )
			.catch( function ( e ) {
				showMsg( 'Request failed: ' + e.message, 'error' );
			} )
			.finally( function () {
				previewBtn.disabled    = false;
				previewBtn.textContent = 'Preview Distribution';
			} );
	} );

	// -------------------------------------------------------------------------
	// Confirm / Execute
	// -------------------------------------------------------------------------
	confirmBtn.addEventListener( 'click', function () {
		if ( ! preview || ! preview.recipients.length ) return;

		var count      = preview.recipients.length;
		var total      = preview.total_ui;
		var assetLabel = ( preview.asset === 'sol' ) ? 'SOL' : 'tokens';

		if ( ! window.confirm(
			'Send ' + Number( total ).toLocaleString() + ' ' + assetLabel + ' to ' + count + ' user(s)?\n\nThis transaction cannot be undone.'
		) ) return;

		confirmBtn.disabled    = true;
		confirmBtn.textContent = 'Sending\u2026';
		resultArea.innerHTML   = '';
		msgEl.innerHTML        = '';

		var fd = new FormData();
		fd.append( 'action',     'stl_execute_airdrop' );
		fd.append( 'nonce',      nonce );
		fd.append( 'asset',      preview.asset || 'token' );
		fd.append( 'recipients', JSON.stringify( preview.recipients ) );

		fetch( ajaxUrl, { method: 'POST', body: fd } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( ! data.success ) {
					showMsg( data.data || 'Airdrop failed.', 'error' );
					return;
				}
				renderResults( data.data.results );
				previewArea.innerHTML    = '';
				confirmBtn.style.display = 'none';
				preview                  = null;
			} )
			.catch( function ( e ) {
				showMsg( 'Request failed: ' + e.message, 'error' );
			} )
			.finally( function () {
				confirmBtn.disabled    = false;
				confirmBtn.textContent = 'Confirm Airdrop';
			} );
	} );

	// -------------------------------------------------------------------------
	// Render helpers
	// -------------------------------------------------------------------------
	function renderPreview( data ) {
		var recipients = data.recipients;
		var totalUi    = recipients.reduce( function ( s, r ) { return s + ( r.amount_ui || 0 ); }, 0 );
		var amtLabel   = ( data.asset === 'sol' ) ? 'SOL' : 'Tokens';

		var html = '<table class="wp-list-table widefat fixed striped" style="max-width:900px;margin-top:14px">'
			+ '<thead><tr>'
			+ '<th style="width:50px">#</th>'
			+ '<th>X Handle</th>'
			+ '<th>Wallet</th>'
			+ '<th style="text-align:right">' + amtLabel + '</th>'
			+ '</tr></thead><tbody>';

		recipients.forEach( function ( r ) {
			html += '<tr>'
				+ '<td>' + esc( r.rank ) + '</td>'
				+ '<td>' + ( r.twitter ? '@' + esc( r.twitter ) : '&mdash;' ) + '</td>'
				+ '<td><code title="' + esc( r.wallet ) + '">'
				+   esc( r.wallet.slice( 0, 8 ) ) + '&hellip;' + esc( r.wallet.slice( -8 ) )
				+ '</code></td>'
				+ '<td style="text-align:right;font-weight:600">' + Number( r.amount_ui ).toLocaleString() + '</td>'
				+ '</tr>';
		} );

		html += '<tr style="background:#f0f4ff;font-weight:700">'
			+ '<td colspan="3" style="text-align:right;padding-right:8px">Total to send</td>'
			+ '<td style="text-align:right">' + Number( totalUi.toFixed( 2 ) ).toLocaleString() + '</td>'
			+ '</tr></tbody></table>';

		previewArea.innerHTML = html;
	}

	function renderResults( results ) {
		var success = 0;
		var errors  = 0;

		var html = '<h4 style="margin:20px 0 8px">Airdrop Results</h4>'
			+ '<table class="wp-list-table widefat fixed striped" style="max-width:900px">'
			+ '<thead><tr><th>X Handle</th><th>Wallet</th><th style="text-align:right">Amount</th><th>Status</th></tr></thead>'
			+ '<tbody>';

		results.forEach( function ( r ) {
			var ok = r.status === 'success';
			if ( ok ) { success++; } else { errors++; }

			var statusCell = ok
				? '<td style="color:#16a34a">\u2713 <a href="https://solscan.io/tx/' + esc( r.signature ) + '" target="_blank" rel="noopener noreferrer">View on Solscan</a></td>'
				: '<td style="color:#dc2626" title="' + esc( r.error || '' ) + '">\u2717 ' + esc( r.error || 'Error' ) + '</td>';

			html += '<tr>'
				+ '<td>' + ( r.twitter ? '@' + esc( r.twitter ) : '&mdash;' ) + '</td>'
				+ '<td><code>' + esc( r.wallet.slice( 0, 8 ) ) + '&hellip;' + esc( r.wallet.slice( -8 ) ) + '</code></td>'
				+ '<td style="text-align:right">' + ( r.amount_ui !== null ? Number( r.amount_ui ).toLocaleString() : '&mdash;' ) + '</td>'
				+ statusCell
				+ '</tr>';
		} );

		html += '</tbody></table>';
		html += '<p style="margin-top:10px;font-weight:600;color:' + ( errors ? '#dc2626' : '#16a34a' ) + '">'
			+ success + ' succeeded, ' + errors + ' failed.</p>';

		resultArea.innerHTML = html;
	}

	function showMsg( msg, type ) {
		var colors = { error: '#dc2626', warn: '#b45309', info: '#2563eb' };
		msgEl.innerHTML = '<p style="margin:8px 0;color:' + ( colors[ type ] || '#374151' ) + '">' + esc( msg ) + '</p>';
	}

	function esc( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}
} )();
