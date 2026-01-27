<?php
/**
 * Admin functionality for Database Inspector.
 *
 * @package Database_Inspector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin class.
 */
class WPDI_Admin {

	/**
	 * Single instance.
	 *
	 * @var WPDI_Admin|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return WPDI_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wpdi_get_stats', array( $this, 'ajax_get_stats' ) );
		add_action( 'wp_ajax_wpdi_cleanup', array( $this, 'ajax_cleanup' ) );
	}

	/**
	 * Add admin menu page.
	 */
	public function add_menu_page() {
		add_management_page(
			__( 'Database Inspector', 'sac-database-inspector' ),
			__( 'DB Inspector', 'sac-database-inspector' ),
			'manage_options',
			'database-inspector',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'tools_page_database-inspector' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wpdi-admin',
			WPDI_PLUGIN_URL . 'assets/css/wpdi-admin.css',
			array(),
			WPDI_VERSION
		);

		wp_enqueue_script(
			'wpdi-admin',
			WPDI_PLUGIN_URL . 'assets/js/wpdi-admin.js',
			array( 'jquery' ),
			WPDI_VERSION,
			true
		);

		wp_localize_script(
			'wpdi-admin',
			'wpdiData',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wpdi_nonce' ),
				'readOnly' => $this->is_read_only(),
				'i18n'     => array(
					'confirmBackup'    => __( 'Have you backed up your database? This action cannot be undone.', 'sac-database-inspector' ),
					'confirmProceed'   => __( 'Are you sure you want to proceed with this cleanup?', 'sac-database-inspector' ),
					'confirmRevisions' => __( 'Delete all post revisions? This cannot be undone.', 'sac-database-inspector' ),
					'cleaning'         => __( 'Cleaning...', 'sac-database-inspector' ),
					'success'          => __( 'Cleanup complete!', 'sac-database-inspector' ),
					'error'            => __( 'An error occurred.', 'sac-database-inspector' ),
					'readOnlyMode'     => __( 'Read-only mode is enabled.', 'sac-database-inspector' ),
				),
			)
		);
	}

	/**
	 * Get database statistics.
	 *
	 * @return array
	 */
	public function get_database_stats() {
		global $wpdb;

		$stats = array();

		// Total database size (with fallback for restricted hosts).
		$db_size      = 0;
		$options_size = 0;

		// Suppress errors for hosts that restrict information_schema access.
		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time database metrics require direct queries.
		$db_size_result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s",
				DB_NAME
			)
		);

		if ( null !== $db_size_result && '' === $wpdb->last_error ) {
			$db_size = (int) $db_size_result;

			// Options table size (only attempt if first query succeeded).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time database metrics require direct queries.
			$options_size_result = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT data_length + index_length FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s",
					DB_NAME,
					$wpdb->options
				)
			);
			$options_size = $options_size_result ? (int) $options_size_result : 0;
		}
		$wpdb->suppress_errors( false );

		$stats['total_db_size']         = $db_size;
		$stats['options_table_size']    = $options_size;
		$stats['info_schema_available'] = ( $db_size > 0 );

		// Autoloaded options.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time database metrics require direct queries.
		$autoload_result = $wpdb->get_row(
			"SELECT COUNT(*) as count, SUM(LENGTH(option_value)) as size FROM {$wpdb->options} WHERE autoload = 'yes'"
		);
		$stats['autoload_count'] = $autoload_result ? (int) $autoload_result->count : 0;
		$stats['autoload_size']  = $autoload_result ? (int) $autoload_result->size : 0;

		// LIKE patterns (PluginCheck wants wildcards passed as parameters).
		$like_transient         = '%' . $wpdb->esc_like( '_transient_' ) . '%';
		$like_site_transient    = '%' . $wpdb->esc_like( '_site_transient_' ) . '%';
		$like_transient_timeout = '%' . $wpdb->esc_like( '_transient_timeout_' ) . '%';

		// Transients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time database metrics require direct queries.
		$transient_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$like_transient,
				$like_site_transient
			)
		);
		$stats['transient_count'] = (int) $transient_count;

		// Expired transients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time database metrics require direct queries.
		$expired_transients = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$like_transient_timeout,
				time()
			)
		);
		$stats['expired_transients'] = (int) $expired_transients;

		// Post revisions.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time database metrics require direct queries.
		$revisions = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"
		);
		$stats['revisions_count'] = (int) $revisions;

		// Auto-drafts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time database metrics require direct queries.
		$auto_drafts = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'"
		);
		$stats['auto_drafts_count'] = (int) $auto_drafts;

		// Trashed posts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time database metrics require direct queries.
		$trashed = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'"
		);
		$stats['trashed_posts_count'] = (int) $trashed;

		// Orphaned postmeta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time database metrics require direct queries.
		$orphaned_postmeta = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL"
		);
		$stats['orphaned_postmeta'] = (int) $orphaned_postmeta;

		// Orphaned commentmeta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time database metrics require direct queries.
		$orphaned_commentmeta = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL"
		);
		$stats['orphaned_commentmeta'] = (int) $orphaned_commentmeta;

		// Spam comments.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time database metrics require direct queries.
		$spam_comments = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
		);
		$stats['spam_comments'] = (int) $spam_comments;

		// Trashed comments.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time database metrics require direct queries.
		$trashed_comments = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'"
		);
		$stats['trashed_comments'] = (int) $trashed_comments;

		// Object cache status.
		$stats['object_cache_enabled'] = wp_using_ext_object_cache();

		// Top autoloaded options (for detailed view).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Real-time database metrics require direct queries.
		$top_autoload = $wpdb->get_results(
			"SELECT option_name, LENGTH(option_value) as size FROM {$wpdb->options} WHERE autoload = 'yes' ORDER BY size DESC LIMIT 20"
		);
		$stats['top_autoload'] = $top_autoload ? $top_autoload : array();

		// Calculate health score.
		$stats['health_score'] = $this->calculate_health_score( $stats );

		return $stats;
	}

	/**
	 * Calculate database health score (0-100, lower is better).
	 *
	 * @param array $stats Database statistics.
	 * @return int
	 */
	private function calculate_health_score( $stats ) {
		$score = 0;

		// Autoload size penalty (over 1MB is concerning).
		$autoload_mb = $stats['autoload_size'] / 1048576;
		if ( $autoload_mb > 1 ) {
			$score += min( 30, ( $autoload_mb - 1 ) * 10 );
		}

		// Expired transients penalty.
		if ( $stats['expired_transients'] > 10 ) {
			$score += min( 15, $stats['expired_transients'] / 10 );
		}

		// Revisions penalty (over 100 is concerning).
		if ( $stats['revisions_count'] > 100 ) {
			$score += min( 20, ( $stats['revisions_count'] - 100 ) / 50 );
		}

		// Orphaned meta penalty.
		$orphaned_total = $stats['orphaned_postmeta'] + $stats['orphaned_commentmeta'];
		if ( $orphaned_total > 50 ) {
			$score += min( 15, $orphaned_total / 100 );
		}

		// Spam/trash penalty.
		$spam_trash = $stats['spam_comments'] + $stats['trashed_comments'] + $stats['trashed_posts_count'];
		if ( $spam_trash > 50 ) {
			$score += min( 10, $spam_trash / 50 );
		}

		// Auto-drafts penalty.
		if ( $stats['auto_drafts_count'] > 5 ) {
			$score += min( 10, $stats['auto_drafts_count'] );
		}

		/**
		 * Filter the health score.
		 *
		 * @param int   $score Health score (0-100).
		 * @param array $stats Database statistics.
		 */
		return (int) apply_filters( 'wpdi_health_score', min( 100, $score ), $stats );
	}

	/**
	 * AJAX handler for getting stats.
	 */
	public function ajax_get_stats() {
		check_ajax_referer( 'wpdi_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$stats = $this->get_database_stats();
		wp_send_json_success( $stats );
	}

	/**
	 * Check if read-only mode is enabled.
	 *
	 * @return bool
	 */
	public function is_read_only() {
		/**
		 * Filter to enable read-only mode (disable all cleanup actions).
		 *
		 * @param bool $read_only Whether read-only mode is enabled.
		 */
		return apply_filters( 'wpdi_read_only', false );
	}

	/**
	 * AJAX handler for cleanup actions.
	 */
	public function ajax_cleanup() {
		check_ajax_referer( 'wpdi_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'sac-database-inspector' ) ) );
		}

		if ( $this->is_read_only() ) {
			wp_send_json_error( array( 'message' => __( 'Read-only mode is enabled. Cleanup actions are disabled.', 'sac-database-inspector' ) ) );
		}

		$action = isset( $_POST['cleanup_action'] ) ? sanitize_text_field( wp_unslash( $_POST['cleanup_action'] ) ) : '';

		/**
		 * Fires before a cleanup action is performed.
		 *
		 * @param string $action The cleanup action being performed.
		 */
		do_action( 'wpdi_before_cleanup', $action );

		$result = $this->perform_cleanup( $action );

		/**
		 * Fires after a cleanup action is performed.
		 *
		 * @param string $action The cleanup action that was performed.
		 * @param array  $result The result of the cleanup.
		 */
		do_action( 'wpdi_after_cleanup', $action, $result );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result['message'] );
		}
	}

	/**
	 * Perform cleanup action.
	 *
	 * @param string $action Cleanup action to perform.
	 * @return array
	 */
	private function perform_cleanup( $action ) {
		global $wpdb;

		/**
		 * Filter available cleanup actions.
		 *
		 * @param array $actions Available cleanup actions.
		 */
		$allowed_actions = apply_filters(
			'wpdi_cleanup_actions',
			array(
				'expired_transients',
				'all_transients',
				'revisions',
				'auto_drafts',
				'trashed_posts',
				'orphaned_postmeta',
				'orphaned_commentmeta',
				'spam_comments',
				'trashed_comments',
				'object_cache',
			)
		);

		if ( ! in_array( $action, $allowed_actions, true ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid action.', 'sac-database-inspector' ),
			);
		}

		$deleted = 0;

		switch ( $action ) {
			case 'expired_transients':
				// Handle regular transients.
				$like_timeout = '%' . $wpdb->esc_like( '_transient_timeout_' ) . '%';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation requires direct query.
				$expired = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
						$like_timeout,
						time()
					)
				);
				foreach ( $expired as $transient ) {
					$key = str_replace( '_transient_timeout_', '', $transient );
					delete_transient( $key );
					++$deleted;
				}

				// Handle site transients on multisite.
				if ( is_multisite() ) {
					$like_site_timeout = '%' . $wpdb->esc_like( '_site_transient_timeout_' ) . '%';
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation requires direct query.
					$expired_site = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s AND meta_value < %d",
							$like_site_timeout,
							time()
						)
					);
					foreach ( $expired_site as $transient ) {
						$key = str_replace( '_site_transient_timeout_', '', $transient );
						delete_site_transient( $key );
						++$deleted;
					}
				}
				break;

			case 'all_transients':
				$like_transient = '%' . $wpdb->esc_like( '_transient_' ) . '%';
				$like_site_tran = '%' . $wpdb->esc_like( '_site_transient_' ) . '%';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cleanup requires direct query.
				$deleted = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
						$like_transient,
						$like_site_tran
					)
				);
				break;

			case 'revisions':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation requires direct query.
				$revision_ids = $wpdb->get_col(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'"
				);
				foreach ( $revision_ids as $id ) {
					wp_delete_post_revision( $id );
					++$deleted;
				}
				break;

			case 'auto_drafts':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation requires direct query.
				$draft_ids = $wpdb->get_col(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft'"
				);
				foreach ( $draft_ids as $id ) {
					wp_delete_post( $id, true );
					++$deleted;
				}
				break;

			case 'trashed_posts':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation requires direct query.
				$trashed_ids = $wpdb->get_col(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash'"
				);
				foreach ( $trashed_ids as $id ) {
					wp_delete_post( $id, true );
					++$deleted;
				}
				break;

			case 'orphaned_postmeta':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cleanup requires direct query.
				$deleted = $wpdb->query(
					"DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL"
				);
				break;

			case 'orphaned_commentmeta':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cleanup requires direct query.
				$deleted = $wpdb->query(
					"DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL"
				);
				break;

			case 'spam_comments':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation requires direct query.
				$spam_ids = $wpdb->get_col(
					"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
				);
				foreach ( $spam_ids as $id ) {
					wp_delete_comment( $id, true );
					++$deleted;
				}
				break;

			case 'trashed_comments':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation requires direct query.
				$trashed_ids = $wpdb->get_col(
					"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'trash'"
				);
				foreach ( $trashed_ids as $id ) {
					wp_delete_comment( $id, true );
					++$deleted;
				}
				break;

			case 'object_cache':
				if ( wp_using_ext_object_cache() ) {
					wp_cache_flush();
					$deleted = 1;
				}
				break;
		}

		return array(
			'success' => true,
			'deleted' => $deleted,
			'message' => sprintf(
				/* translators: %d: number of items deleted */
				__( 'Cleaned up %d items.', 'sac-database-inspector' ),
				$deleted
			),
		);
	}

	/**
	 * Infer option source from option name.
	 *
	 * @param string $option_name Option name to analyze.
	 * @return string Source label.
	 */
	private function get_option_source( $option_name ) {
		if ( empty( $option_name ) || ! is_string( $option_name ) ) {
			return __( 'Unknown / custom', 'sac-database-inspector' );
		}

		$name_lower = strtolower( $option_name );

		// Exact matches (WordPress Core).
		$core_options = array(
			'active_plugins', 'admin_email', 'blogdescription', 'blogname', 'siteurl', 'home',
			'permalink_structure', 'default_role', 'users_can_register', 'timezone_string',
			'date_format', 'time_format', 'blog_public', 'posts_per_page', 'db_version',
			'wp_user_roles', 'current_theme', 'stylesheet', 'template', 'sidebars_widgets',
		);
		if ( in_array( $name_lower, $core_options, true ) ) {
			return __( 'WordPress Core', 'sac-database-inspector' );
		}

		// Multisite exact matches.
		if ( is_multisite() ) {
			$multisite_options = array( 'site_admins', 'active_sitewide_plugins' );
			if ( in_array( $name_lower, $multisite_options, true ) ) {
				return __( 'Multisite Network', 'sac-database-inspector' );
			}
		}

		// Pattern matching (ordered by specificity).
		$patterns = array(
			// E-commerce.
			'woocommerce_'      => 'WooCommerce',
			'wc_'               => 'WooCommerce',
			'edd_'              => 'Easy Digital Downloads',
			// SEO.
			'wpseo_'            => 'Yoast SEO',
			'_yoast_'           => 'Yoast SEO',
			'aioseop_'          => 'All in One SEO',
			'rank_math_'        => 'Rank Math',
			// Page Builders.
			'elementor_'        => 'Elementor',
			'_elementor_'       => 'Elementor',
			'fl_builder_'       => 'Beaver Builder',
			'fusion_'           => 'Avada',
			'vc_'               => 'WPBakery',
			'divi_'             => 'Divi',
			// Performance.
			'w3tc_'             => 'W3 Total Cache',
			'wp_rocket_'        => 'WP Rocket',
			'autoptimize_'      => 'Autoptimize',
			'litespeed_'        => 'LiteSpeed Cache',
			// Security.
			'wordfence_'        => 'Wordfence',
			'itsec_'            => 'iThemes Security',
			'sucuri_'           => 'Sucuri',
			// Backup.
			'updraftplus_'      => 'UpdraftPlus',
			'backwpup_'         => 'BackWPup',
			'duplicator_'       => 'Duplicator',
			// Forms.
			'wpforms_'          => 'WPForms',
			'gf_'               => 'Gravity Forms',
			'ninja_forms_'      => 'Ninja Forms',
			'cf7_'              => 'Contact Form 7',
			'frm_'              => 'Formidable Forms',
			// Membership/LMS.
			'pmpro_'            => 'Paid Memberships Pro',
			'mepr_'             => 'MemberPress',
			'learndash_'        => 'LearnDash',
			'lifterlms_'        => 'LifterLMS',
			// Media.
			'smush_'            => 'Smush',
			'ewww_'             => 'EWWW Image Optimizer',
			'envira_'           => 'Envira Gallery',
			'nextgen_'          => 'NextGEN Gallery',
			// External Services.
			'jetpack_'          => 'Jetpack',
			'akismet_'          => 'Akismet',
			'mailchimp_'        => 'Mailchimp',
			// WordPress Core patterns.
			'_site_transient_'  => __( 'WordPress Core', 'sac-database-inspector' ),
			'_transient_'       => __( 'WordPress Core', 'sac-database-inspector' ),
			'theme_mods_'       => __( 'Theme System', 'sac-database-inspector' ),
			'widget_'           => __( 'WordPress Core', 'sac-database-inspector' ),
			'cron'              => __( 'WordPress Core', 'sac-database-inspector' ),
			'_cron_'            => __( 'WordPress Core', 'sac-database-inspector' ),
			'wp_'               => __( 'WordPress Core', 'sac-database-inspector' ),
		);

		// Multisite patterns.
		if ( is_multisite() ) {
			$patterns['_site_'] = __( 'Multisite Network', 'sac-database-inspector' );
		}

		foreach ( $patterns as $prefix => $source ) {
			if ( 0 === stripos( $name_lower, $prefix ) ) {
				// Check if source is already translated (WordPress Core patterns).
				if ( is_string( $source ) && false === strpos( $source, 'WordPress' ) && false === strpos( $source, 'Theme' ) && false === strpos( $source, 'Multisite' ) ) {
					/* translators: %s: Plugin or service name */
					return sprintf( __( 'Likely: %s', 'sac-database-inspector' ), $source );
				}
				return $source;
			}
		}

		// Contains patterns (less reliable).
		$contains = array(
			'_jetpack_'  => 'Jetpack',
			'_akismet_'  => 'Akismet',
			'_network_'  => __( 'Multisite Network', 'sac-database-inspector' ),
		);

		foreach ( $contains as $substring => $source ) {
			if ( false !== stripos( $name_lower, $substring ) ) {
				if ( is_string( $source ) && false === strpos( $source, 'Multisite' ) ) {
					return sprintf( __( 'Likely: %s', 'sac-database-inspector' ), $source );
				}
				return $source;
			}
		}

		return __( 'Unknown / custom', 'sac-database-inspector' );
	}

	/**
	 * Format bytes to human readable.
	 *
	 * @param int $bytes Number of bytes.
	 * @return string
	 */
	public static function format_bytes( $bytes ) {
		if ( $bytes >= 1073741824 ) {
			return number_format( $bytes / 1073741824, 2 ) . ' GB';
		} elseif ( $bytes >= 1048576 ) {
			return number_format( $bytes / 1048576, 2 ) . ' MB';
		} elseif ( $bytes >= 1024 ) {
			return number_format( $bytes / 1024, 2 ) . ' KB';
		}
		return $bytes . ' B';
	}

	/**
	 * Render admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'sac-database-inspector' ) );
		}

		$stats = $this->get_database_stats();
		?>
		<div class="wrap wpdi-wrap">
			<h1><?php esc_html_e( 'Database Inspector', 'sac-database-inspector' ); ?></h1>
			
			<div class="wpdi-dashboard">
				<!-- Health Gauge -->
				<div class="wpdi-card wpdi-health-card">
					<h2><?php esc_html_e( 'Database Health', 'sac-database-inspector' ); ?></h2>
					<div class="wpdi-gauge-container">
						<svg class="wpdi-gauge" viewBox="0 0 200 120">
							<defs>
								<linearGradient id="gaugeGradient" x1="0%" y1="0%" x2="100%" y2="0%">
									<stop offset="0%" style="stop-color:#22c55e"/>
									<stop offset="50%" style="stop-color:#eab308"/>
									<stop offset="100%" style="stop-color:#ef4444"/>
								</linearGradient>
							</defs>
							<!-- Background arc -->
							<path class="wpdi-gauge-bg" d="M 20 100 A 80 80 0 0 1 180 100" fill="none" stroke="#e5e7eb" stroke-width="16" stroke-linecap="round"/>
							<!-- Colored arc -->
							<path class="wpdi-gauge-fill" d="M 20 100 A 80 80 0 0 1 180 100" fill="none" stroke="url(#gaugeGradient)" stroke-width="16" stroke-linecap="round"/>
							<!-- Needle -->
							<line class="wpdi-gauge-needle" x1="100" y1="100" x2="100" y2="30" stroke="#1f2937" stroke-width="3" stroke-linecap="round"/>
							<circle cx="100" cy="100" r="8" fill="#1f2937"/>
						</svg>
						<div class="wpdi-gauge-labels">
							<span class="wpdi-label-good"><?php esc_html_e( 'Good', 'sac-database-inspector' ); ?></span>
							<span class="wpdi-label-warning"><?php esc_html_e( 'Warning', 'sac-database-inspector' ); ?></span>
							<span class="wpdi-label-critical"><?php esc_html_e( 'Critical', 'sac-database-inspector' ); ?></span>
						</div>
						<div class="wpdi-health-score" data-score="<?php echo esc_attr( $stats['health_score'] ); ?>">
							<span class="wpdi-score-value"><?php echo esc_html( $stats['health_score'] ); ?></span>
							<span class="wpdi-score-label"><?php esc_html_e( '/ 100', 'sac-database-inspector' ); ?></span>
						</div>
					</div>
				</div>

				<!-- Summary Stats -->
				<div class="wpdi-card wpdi-summary-card">
					<h2><?php esc_html_e( 'Overview', 'sac-database-inspector' ); ?></h2>
					<div class="wpdi-stats-grid">
						<div class="wpdi-stat">
							<span class="wpdi-stat-value"><?php echo esc_html( self::format_bytes( $stats['total_db_size'] ) ); ?></span>
							<span class="wpdi-stat-label"><?php esc_html_e( 'Total DB Size', 'sac-database-inspector' ); ?></span>
						</div>
						<div class="wpdi-stat">
							<span class="wpdi-stat-value"><?php echo esc_html( self::format_bytes( $stats['autoload_size'] ) ); ?></span>
							<span class="wpdi-stat-label"><?php esc_html_e( 'Autoload Size', 'sac-database-inspector' ); ?></span>
						</div>
						<div class="wpdi-stat">
							<span class="wpdi-stat-value"><?php echo esc_html( number_format( $stats['autoload_count'] ) ); ?></span>
							<span class="wpdi-stat-label"><?php esc_html_e( 'Autoloaded Options', 'sac-database-inspector' ); ?></span>
						</div>
						<div class="wpdi-stat">
							<span class="wpdi-stat-value"><?php echo esc_html( $stats['object_cache_enabled'] ? __( 'Yes', 'sac-database-inspector' ) : __( 'No', 'sac-database-inspector' ) ); ?></span>
							<span class="wpdi-stat-label"><?php esc_html_e( 'Object Cache', 'sac-database-inspector' ); ?></span>
						</div>
					</div>
				</div>
			</div>

			<!-- Cleanup Actions -->
			<div class="wpdi-card wpdi-cleanup-card">
				<h2><?php esc_html_e( 'Cleanup Actions', 'sac-database-inspector' ); ?></h2>
				
				<?php if ( $this->is_read_only() ) : ?>
				<p class="wpdi-notice wpdi-notice-info">
					<span class="dashicons dashicons-lock"></span>
					<?php esc_html_e( 'Read-only mode is enabled. Cleanup actions are disabled.', 'sac-database-inspector' ); ?>
				</p>
				<?php else : ?>
				<p class="wpdi-warning">
					<span class="dashicons dashicons-warning"></span>
					<?php esc_html_e( 'Warning: These actions cannot be undone. Please ensure you have a database backup before proceeding.', 'sac-database-inspector' ); ?>
				</p>
				<?php endif; ?>
				
				<div class="wpdi-cleanup-grid">
					<div class="wpdi-cleanup-item">
						<div class="wpdi-cleanup-info">
							<strong><?php esc_html_e( 'Expired Transients', 'sac-database-inspector' ); ?></strong>
							<span class="wpdi-count"><?php echo esc_html( number_format( $stats['expired_transients'] ) ); ?></span>
						</div>
						<button class="button wpdi-cleanup-btn" data-action="expired_transients" <?php disabled( $this->is_read_only() || 0 === $stats['expired_transients'] ); ?>>
							<?php esc_html_e( 'Clean', 'sac-database-inspector' ); ?>
						</button>
					</div>

					<div class="wpdi-cleanup-item">
						<div class="wpdi-cleanup-info">
							<strong><?php esc_html_e( 'All Transients', 'sac-database-inspector' ); ?></strong>
							<span class="wpdi-count"><?php echo esc_html( number_format( $stats['transient_count'] ) ); ?></span>
						</div>
						<button class="button wpdi-cleanup-btn" data-action="all_transients" <?php disabled( $this->is_read_only() || 0 === $stats['transient_count'] ); ?>>
							<?php esc_html_e( 'Clean', 'sac-database-inspector' ); ?>
						</button>
					</div>

					<div class="wpdi-cleanup-item">
						<div class="wpdi-cleanup-info">
							<strong><?php esc_html_e( 'Post Revisions', 'sac-database-inspector' ); ?></strong>
							<span class="wpdi-count"><?php echo esc_html( number_format( $stats['revisions_count'] ) ); ?></span>
						</div>
						<button class="button wpdi-cleanup-btn" data-action="revisions" <?php disabled( $this->is_read_only() || 0 === $stats['revisions_count'] ); ?>>
							<?php esc_html_e( 'Clean', 'sac-database-inspector' ); ?>
						</button>
					</div>

					<div class="wpdi-cleanup-item">
						<div class="wpdi-cleanup-info">
							<strong><?php esc_html_e( 'Auto-Drafts', 'sac-database-inspector' ); ?></strong>
							<span class="wpdi-count"><?php echo esc_html( number_format( $stats['auto_drafts_count'] ) ); ?></span>
						</div>
						<button class="button wpdi-cleanup-btn" data-action="auto_drafts" <?php disabled( $this->is_read_only() || 0 === $stats['auto_drafts_count'] ); ?>>
							<?php esc_html_e( 'Clean', 'sac-database-inspector' ); ?>
						</button>
					</div>

					<div class="wpdi-cleanup-item">
						<div class="wpdi-cleanup-info">
							<strong><?php esc_html_e( 'Trashed Posts', 'sac-database-inspector' ); ?></strong>
							<span class="wpdi-count"><?php echo esc_html( number_format( $stats['trashed_posts_count'] ) ); ?></span>
						</div>
						<button class="button wpdi-cleanup-btn" data-action="trashed_posts" <?php disabled( $this->is_read_only() || 0 === $stats['trashed_posts_count'] ); ?>>
							<?php esc_html_e( 'Clean', 'sac-database-inspector' ); ?>
						</button>
					</div>

					<div class="wpdi-cleanup-item">
						<div class="wpdi-cleanup-info">
							<strong><?php esc_html_e( 'Orphaned Post Meta', 'sac-database-inspector' ); ?></strong>
							<span class="wpdi-count"><?php echo esc_html( number_format( $stats['orphaned_postmeta'] ) ); ?></span>
						</div>
						<button class="button wpdi-cleanup-btn" data-action="orphaned_postmeta" <?php disabled( $this->is_read_only() || 0 === $stats['orphaned_postmeta'] ); ?>>
							<?php esc_html_e( 'Clean', 'sac-database-inspector' ); ?>
						</button>
					</div>

					<div class="wpdi-cleanup-item">
						<div class="wpdi-cleanup-info">
							<strong><?php esc_html_e( 'Orphaned Comment Meta', 'sac-database-inspector' ); ?></strong>
							<span class="wpdi-count"><?php echo esc_html( number_format( $stats['orphaned_commentmeta'] ) ); ?></span>
						</div>
						<button class="button wpdi-cleanup-btn" data-action="orphaned_commentmeta" <?php disabled( $this->is_read_only() || 0 === $stats['orphaned_commentmeta'] ); ?>>
							<?php esc_html_e( 'Clean', 'sac-database-inspector' ); ?>
						</button>
					</div>

					<div class="wpdi-cleanup-item">
						<div class="wpdi-cleanup-info">
							<strong><?php esc_html_e( 'Spam Comments', 'sac-database-inspector' ); ?></strong>
							<span class="wpdi-count"><?php echo esc_html( number_format( $stats['spam_comments'] ) ); ?></span>
						</div>
						<button class="button wpdi-cleanup-btn" data-action="spam_comments" <?php disabled( $this->is_read_only() || 0 === $stats['spam_comments'] ); ?>>
							<?php esc_html_e( 'Clean', 'sac-database-inspector' ); ?>
						</button>
					</div>

					<div class="wpdi-cleanup-item">
						<div class="wpdi-cleanup-info">
							<strong><?php esc_html_e( 'Trashed Comments', 'sac-database-inspector' ); ?></strong>
							<span class="wpdi-count"><?php echo esc_html( number_format( $stats['trashed_comments'] ) ); ?></span>
						</div>
						<button class="button wpdi-cleanup-btn" data-action="trashed_comments" <?php disabled( $this->is_read_only() || 0 === $stats['trashed_comments'] ); ?>>
							<?php esc_html_e( 'Clean', 'sac-database-inspector' ); ?>
						</button>
					</div>

					<?php if ( $stats['object_cache_enabled'] ) : ?>
					<div class="wpdi-cleanup-item">
						<div class="wpdi-cleanup-info">
							<strong><?php esc_html_e( 'Object Cache', 'sac-database-inspector' ); ?></strong>
							<span class="wpdi-count"><?php esc_html_e( 'Active', 'sac-database-inspector' ); ?></span>
						</div>
						<button class="button wpdi-cleanup-btn" data-action="object_cache" <?php disabled( $this->is_read_only() ); ?>>
							<?php esc_html_e( 'Flush', 'sac-database-inspector' ); ?>
						</button>
					</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Top Autoloaded Options -->
			<div class="wpdi-card wpdi-autoload-card">
				<h2><?php esc_html_e( 'Top Autoloaded Options', 'sac-database-inspector' ); ?></h2>
				<p class="description"><?php esc_html_e( 'These options load on every page request. Large values here can slow down your site.', 'sac-database-inspector' ); ?></p>
				
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Option Name', 'sac-database-inspector' ); ?></th>
							<th><?php esc_html_e( 'Likely Source', 'sac-database-inspector' ); ?></th>
							<th style="width: 100px;"><?php esc_html_e( 'Size', 'sac-database-inspector' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $stats['top_autoload'] as $option ) : ?>
						<tr>
							<td><code><?php echo esc_html( $option->option_name ); ?></code></td>
							<td><?php echo esc_html( $this->get_option_source( $option->option_name ) ); ?></td>
							<td><?php echo esc_html( self::format_bytes( $option->size ) ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description" style="margin-top: 10px;">
					<?php esc_html_e( 'Source is inferred from option naming patterns and may not be exact.', 'sac-database-inspector' ); ?>
				</p>
			</div>

			<div class="wpdi-footer">
				<button id="wpdi-refresh" class="button button-secondary">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh Stats', 'sac-database-inspector' ); ?>
				</button>
			</div>
		</div>
		<?php
	}
}
