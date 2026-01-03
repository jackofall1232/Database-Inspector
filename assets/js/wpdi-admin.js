/**
 * WP Database Inspector Admin JavaScript
 *
 * @package WP_Database_Inspector
 */

( function( $ ) {
	'use strict';

	var WPDI = {
		/**
		 * Initialize.
		 */
		init: function() {
			this.bindEvents();
			this.initGauge();
			this.checkReadOnly();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			$( '.wpdi-cleanup-btn' ).on( 'click', this.handleCleanup.bind( this ) );
			$( '#wpdi-refresh' ).on( 'click', this.refreshStats.bind( this ) );
		},

		/**
		 * Check and handle read-only mode.
		 */
		checkReadOnly: function() {
			if ( wpdiData.readOnly ) {
				$( '.wpdi-cleanup-btn' ).prop( 'disabled', true );
			}
		},

		/**
		 * Initialize the gauge needle position.
		 */
		initGauge: function() {
			var $scoreEl = $( '.wpdi-health-score' );
			var score = parseInt( $scoreEl.data( 'score' ), 10 ) || 0;
			
			// S-003: Set transform-origin explicitly via JS for cross-browser support.
			var $needle = $( '.wpdi-gauge-needle' );
			$needle.css( {
				'transform-origin': '100px 100px',
				'transition': 'transform 0.8s cubic-bezier(0.4, 0, 0.2, 1)'
			} );
			
			// Delay initial animation slightly for smoother load.
			setTimeout( function() {
				WPDI.updateGauge( score );
			}, 100 );
		},

		/**
		 * Update gauge needle position.
		 *
		 * @param {number} score Health score (0-100).
		 */
		updateGauge: function( score ) {
			var $needle = $( '.wpdi-gauge-needle' );
			// Convert score (0-100) to angle (-90 to 90 degrees).
			var angle = ( score / 100 ) * 180 - 90;
			$needle.css( 'transform', 'rotate(' + angle + 'deg)' );

			// Update score color based on value.
			var $scoreValue = $( '.wpdi-score-value' );
			$scoreValue.text( score );
			
			if ( score <= 40 ) {
				$scoreValue.css( 'color', '#22c55e' );
			} else if ( score <= 70 ) {
				$scoreValue.css( 'color', '#eab308' );
			} else {
				$scoreValue.css( 'color', '#ef4444' );
			}
		},

		/**
		 * Handle cleanup button click.
		 *
		 * @param {Event} e Click event.
		 */
		handleCleanup: function( e ) {
			e.preventDefault();

			var $btn = $( e.currentTarget );
			var action = $btn.data( 'action' );

			if ( $btn.prop( 'disabled' ) || $btn.hasClass( 'loading' ) ) {
				return;
			}

			// S-005: Check read-only mode.
			if ( wpdiData.readOnly ) {
				WPDI.showToast( wpdiData.i18n.readOnlyMode, 'error' );
				return;
			}

			// S-006: Two-step confirmation - first backup, then proceed.
			if ( ! confirm( wpdiData.i18n.confirmBackup ) ) {
				return;
			}

			if ( ! confirm( wpdiData.i18n.confirmProceed ) ) {
				return;
			}

			$btn.addClass( 'loading' );

			$.ajax( {
				url: wpdiData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wpdi_cleanup',
					cleanup_action: action,
					nonce: wpdiData.nonce
				},
				success: function( response ) {
					$btn.removeClass( 'loading' );

					if ( response.success ) {
						WPDI.showToast( response.data.message, 'success' );
						WPDI.refreshStats();
					} else {
						// S-004: Normalize error message handling.
						var errorMsg = WPDI.extractErrorMessage( response );
						WPDI.showToast( errorMsg, 'error' );
					}
				},
				error: function( jqXHR, textStatus, errorThrown ) {
					$btn.removeClass( 'loading' );
					// S-004: Include more context on network errors.
					var errorMsg = wpdiData.i18n.error;
					if ( textStatus === 'timeout' ) {
						errorMsg = 'Request timed out. Please try again.';
					}
					WPDI.showToast( errorMsg, 'error' );
				}
			} );
		},

		/**
		 * S-004: Extract error message from various response formats.
		 *
		 * @param {Object} response AJAX response object.
		 * @return {string} Error message.
		 */
		extractErrorMessage: function( response ) {
			if ( ! response ) {
				return wpdiData.i18n.error;
			}

			// Check for response.data.message (object format).
			if ( response.data && typeof response.data === 'object' && response.data.message ) {
				return response.data.message;
			}

			// Check for response.data as string.
			if ( response.data && typeof response.data === 'string' ) {
				return response.data;
			}

			// Check for response.message.
			if ( response.message && typeof response.message === 'string' ) {
				return response.message;
			}

			return wpdiData.i18n.error;
		},

		/**
		 * Refresh statistics via AJAX.
		 *
		 * @param {Event} e Click event (optional).
		 */
		refreshStats: function( e ) {
			if ( e ) {
				e.preventDefault();
			}

			var $btn = $( '#wpdi-refresh' );
			$btn.prop( 'disabled', true );

			$.ajax( {
				url: wpdiData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wpdi_get_stats',
					nonce: wpdiData.nonce
				},
				success: function( response ) {
					$btn.prop( 'disabled', false );

					if ( response.success ) {
						WPDI.updateUI( response.data );
					}
				},
				error: function() {
					$btn.prop( 'disabled', false );
					WPDI.showToast( wpdiData.i18n.error, 'error' );
				}
			} );
		},

		/**
		 * Update UI with new stats.
		 *
		 * @param {Object} stats Database statistics.
		 */
		updateUI: function( stats ) {
			// Update gauge.
			this.updateGauge( stats.health_score );
			$( '.wpdi-health-score' ).attr( 'data-score', stats.health_score );

			// Update stat values (order matches template).
			var statValues = [
				this.formatBytes( stats.total_db_size ),
				this.formatBytes( stats.autoload_size ),
				stats.autoload_count.toLocaleString(),
				stats.object_cache_enabled ? 'Yes' : 'No'
			];

			$( '.wpdi-stat-value' ).each( function( index ) {
				if ( statValues[ index ] !== undefined ) {
					$( this ).text( statValues[ index ] );
				}
			} );

			// Update cleanup counts.
			var countMap = {
				'expired_transients': stats.expired_transients,
				'all_transients': stats.transient_count,
				'revisions': stats.revisions_count,
				'auto_drafts': stats.auto_drafts_count,
				'trashed_posts': stats.trashed_posts_count,
				'orphaned_postmeta': stats.orphaned_postmeta,
				'orphaned_commentmeta': stats.orphaned_commentmeta,
				'spam_comments': stats.spam_comments,
				'trashed_comments': stats.trashed_comments
			};

			$( '.wpdi-cleanup-btn' ).each( function() {
				var $btn = $( this );
				var action = $btn.data( 'action' );
				var count = countMap[ action ];

				if ( count !== undefined ) {
					$btn.closest( '.wpdi-cleanup-item' ).find( '.wpdi-count' ).text( count.toLocaleString() );
					// Don't re-enable if read-only mode is active.
					if ( ! wpdiData.readOnly ) {
						$btn.prop( 'disabled', count === 0 );
					}
				}
			} );
		},

		/**
		 * Format bytes to human readable.
		 *
		 * @param {number} bytes Number of bytes.
		 * @return {string} Formatted string.
		 */
		formatBytes: function( bytes ) {
			if ( bytes >= 1073741824 ) {
				return ( bytes / 1073741824 ).toFixed( 2 ) + ' GB';
			} else if ( bytes >= 1048576 ) {
				return ( bytes / 1048576 ).toFixed( 2 ) + ' MB';
			} else if ( bytes >= 1024 ) {
				return ( bytes / 1024 ).toFixed( 2 ) + ' KB';
			}
			return bytes + ' B';
		},

		/**
		 * Show toast notification.
		 *
		 * @param {string} message Message to display.
		 * @param {string} type    Type (success or error).
		 */
		showToast: function( message, type ) {
			var $toast = $( '<div class="wpdi-toast"></div>' ).text( message );
			
			if ( type ) {
				$toast.addClass( type );
			}

			$( 'body' ).append( $toast );

			setTimeout( function() {
				$toast.fadeOut( 300, function() {
					$toast.remove();
				} );
			}, 3000 );
		}
	};

	$( document ).ready( function() {
		WPDI.init();
	} );

} )( jQuery );
