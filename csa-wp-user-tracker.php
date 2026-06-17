<?php
/**
 * Plugin Name: CSA WP User Tracker
 * Plugin URI: https://github.com/ashburn2k/csa-wp-user-tracker
 * Description: Tracks activity for logged-in WordPress users whose roles are not limited to subscriber.
 * Version: 0.1.17
 * Author: Hui Zhang
 * Text Domain: csa-wp-user-tracker
 * Update URI: https://github.com/ashburn2k/csa-wp-user-tracker
 *
 * @package CSA_WP_User_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CSA_WP_USER_TRACKER_VERSION', '0.1.17' );
define( 'CSA_WP_USER_TRACKER_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-csa-wp-user-tracker-github-updater.php';

/**
 * Role-aware activity logger.
 */
final class CSA_WP_User_Tracker {
	const OPTION_VERSION              = 'csa_wp_user_tracker_version';
	const CLEANUP_HOOK                = 'csa_wp_user_tracker_daily_cleanup';
	const DEFAULT_RETENTION           = 180;
	const ADMIN_PAGE_SLUG             = 'csa-wp-user-tracker-log';
	const EXPORT_QUERY_ARG            = 'csa_wp_user_tracker_export';
	const EXPORT_NONCE_ACTION         = 'csa_wp_user_tracker_export';
	const LEGACY_OPTION_VERSION       = 'esnet_activity_tracker_version';
	const LEGACY_CLEANUP_HOOK         = 'esnet_activity_tracker_daily_cleanup';
	const OPTION_EMAIL_SETTINGS       = 'csa_wp_user_tracker_email_settings';
	const OPTION_EMAIL_QUEUE          = 'csa_wp_user_tracker_email_queue';
	const OPTION_EMAIL_LAST_SENT      = 'csa_wp_user_tracker_email_last_sent';
	const EMAIL_DIGEST_HOOK           = 'csa_wp_user_tracker_email_digest';
	const EMAIL_WEEKLY_RECURRENCE     = 'csa_wp_user_tracker_weekly';
	const EMAIL_SETTINGS_NONCE_ACTION = 'csa_wp_user_tracker_email_settings';
	const EMAIL_QUEUE_LIMIT           = 1000;

	/**
	 * Avoid recursive option logging while this plugin writes.
	 *
	 * @var bool
	 */
	private static $writing = false;

	/**
	 * Bootstrap hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 1 );
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_export_csv' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_save_email_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_log_ajax_request' ), 1 );
		add_action( 'current_screen', array( __CLASS__, 'log_admin_screen' ) );
		add_action( 'template_redirect', array( __CLASS__, 'log_frontend_view' ), 999 );
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup_old_logs' ) );
		add_action( self::EMAIL_DIGEST_HOOK, array( __CLASS__, 'send_scheduled_email_digest' ) );
		add_action( 'init', array( __CLASS__, 'ensure_email_digest_schedule' ), 20 );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedules' ) );

		add_action( 'wp_login', array( __CLASS__, 'log_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( __CLASS__, 'log_failed_login' ) );
		add_action( 'wp_logout', array( __CLASS__, 'log_logout' ) );

		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'log_rest_request' ), 10, 3 );

		add_action( 'save_post', array( __CLASS__, 'log_save_post' ), 10, 3 );
		add_action( 'transition_post_status', array( __CLASS__, 'log_post_status_transition' ), 10, 3 );
		add_action( 'trashed_post', array( __CLASS__, 'log_trashed_post' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'log_untrashed_post' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'log_deleted_post' ) );
		add_action( 'add_attachment', array( __CLASS__, 'log_added_attachment' ) );
		add_action( 'edit_attachment', array( __CLASS__, 'log_edited_attachment' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'log_deleted_attachment' ) );

		add_action( 'created_term', array( __CLASS__, 'log_created_term' ), 10, 3 );
		add_action( 'edited_term', array( __CLASS__, 'log_edited_term' ), 10, 3 );
		add_action( 'delete_term', array( __CLASS__, 'log_deleted_term' ), 10, 5 );

		add_action( 'wp_insert_comment', array( __CLASS__, 'log_inserted_comment' ), 10, 2 );
		add_action( 'edit_comment', array( __CLASS__, 'log_edited_comment' ) );
		add_action( 'trashed_comment', array( __CLASS__, 'log_comment_status' ) );
		add_action( 'untrashed_comment', array( __CLASS__, 'log_comment_status' ) );
		add_action( 'spam_comment', array( __CLASS__, 'log_comment_status' ) );
		add_action( 'unspam_comment', array( __CLASS__, 'log_comment_status' ) );
		add_action( 'deleted_comment', array( __CLASS__, 'log_deleted_comment' ) );

		add_action( 'user_register', array( __CLASS__, 'log_registered_user' ) );
		add_action( 'profile_update', array( __CLASS__, 'log_updated_user' ), 10, 2 );
		add_action( 'delete_user', array( __CLASS__, 'log_deleted_user' ), 10, 3 );
		add_action( 'set_user_role', array( __CLASS__, 'log_set_user_role' ), 10, 3 );
		add_action( 'add_user_role', array( __CLASS__, 'log_added_user_role' ), 10, 2 );
		add_action( 'remove_user_role', array( __CLASS__, 'log_removed_user_role' ), 10, 2 );

		add_action( 'added_option', array( __CLASS__, 'log_added_option' ), 10, 2 );
		add_action( 'updated_option', array( __CLASS__, 'log_updated_option' ), 10, 3 );
		add_action( 'deleted_option', array( __CLASS__, 'log_deleted_option' ) );

		add_action( 'activated_plugin', array( __CLASS__, 'log_activated_plugin' ), 10, 2 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'log_deactivated_plugin' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'log_switched_theme' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'log_upgrader_process' ), 10, 2 );
	}

	/**
	 * Create the log table and schedule retention cleanup.
	 */
	public static function activate() {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		self::migrate_legacy_storage();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			occurred_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_login varchar(191) NOT NULL DEFAULT '',
			display_name varchar(191) NOT NULL DEFAULT '',
			roles text NOT NULL,
			action varchar(80) NOT NULL DEFAULT '',
			object_type varchar(80) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned DEFAULT NULL,
			object_name text NULL,
			request_method varchar(20) NOT NULL DEFAULT '',
			request_uri text NULL,
			ip_hash varchar(64) NOT NULL DEFAULT '',
			context longtext NULL,
			PRIMARY KEY  (id),
			KEY occurred_at (occurred_at),
			KEY user_id (user_id),
			KEY action (action),
			KEY object_type (object_type),
			KEY object_id (object_id)
		) {$charset_collate};";

		dbDelta( $sql );
		self::write_option( self::OPTION_VERSION, CSA_WP_USER_TRACKER_VERSION );
		self::schedule_cleanup();
		self::ensure_email_digest_schedule();
	}

	/**
	 * Unschedule cleanup on deactivation. Logs are intentionally retained.
	 */
	public static function deactivate() {
		self::unschedule_cleanup_hook( self::CLEANUP_HOOK );
		self::unschedule_cleanup_hook( self::LEGACY_CLEANUP_HOOK );
		wp_clear_scheduled_hook( self::EMAIL_DIGEST_HOOK );
	}

	/**
	 * Repair/create tables when code is deployed before activation routines run.
	 */
	public static function maybe_upgrade() {
		$installed_version = get_option( self::OPTION_VERSION );

		if ( CSA_WP_USER_TRACKER_VERSION !== $installed_version ) {
			self::activate();
			delete_site_transient( 'update_plugins' );
			wp_clean_plugins_cache( true );
		}
	}

	/**
	 * Get the plugin table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'csa_wp_user_tracker_log';
	}

	/**
	 * Get the pre-rename table name.
	 *
	 * @return string
	 */
	private static function legacy_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'esnet_activity_log';
	}

	/**
	 * Move legacy storage to CSA names during the first renamed update.
	 */
	private static function migrate_legacy_storage() {
		global $wpdb;

		$table_name   = self::table_name();
		$legacy_table = self::legacy_table_name();

		if ( $table_name !== $legacy_table && ! self::table_exists( $table_name ) && self::table_exists( $legacy_table ) ) {
			$wpdb->query( 'RENAME TABLE ' . self::quoted_table_name( $legacy_table ) . ' TO ' . self::quoted_table_name( $table_name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$legacy_version = get_option( self::LEGACY_OPTION_VERSION, false );
		if ( false === get_option( self::OPTION_VERSION, false ) && false !== $legacy_version ) {
			self::write_option( self::OPTION_VERSION, $legacy_version );
		}

		self::delete_option_without_log( self::LEGACY_OPTION_VERSION );
		self::unschedule_cleanup_hook( self::LEGACY_CLEANUP_HOOK );
	}

	/**
	 * Check if a database table exists.
	 *
	 * @param string $table_name Table name.
	 * @return bool
	 */
	private static function table_exists( $table_name ) {
		global $wpdb;

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) );

		return $table_name === $found;
	}

	/**
	 * Escape a table identifier for SQL.
	 *
	 * @param string $table_name Table name.
	 * @return string
	 */
	private static function quoted_table_name( $table_name ) {
		return '`' . str_replace( '`', '``', $table_name ) . '`';
	}

	/**
	 * Add admin UI.
	 */
	public static function register_admin_page() {
		add_management_page(
			__( 'CSA WP User Tracker', 'csa-wp-user-tracker' ),
			__( 'CSA WP User Tracker', 'csa-wp-user-tracker' ),
			self::admin_capability(),
			self::ADMIN_PAGE_SLUG,
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Render admin log table.
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( self::admin_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view activity logs.', 'csa-wp-user-tracker' ) );
		}

		global $wpdb;

		$table_name = self::table_name();
		$filters    = self::get_admin_filters();
		$page       = max( 1, absint( isset( $_GET['paged'] ) ? wp_unslash( $_GET['paged'] ) : 1 ) );
		$per_page   = 50;
		$offset     = ( $page - 1 ) * $per_page;
		$where      = self::build_where_sql( $filters );
		$total      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} {$where['sql']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows       = $wpdb->get_results( "SELECT * FROM {$table_name} {$where['sql']} ORDER BY occurred_at DESC, id DESC LIMIT " . absint( $per_page ) . ' OFFSET ' . absint( $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$range_start = $total ? $offset + 1 : 0;
		$range_end   = $total ? min( $offset + $per_page, $total ) : 0;
		$base_url    = remove_query_arg( array( 'paged', self::EXPORT_QUERY_ARG, '_wpnonce' ) );
		$export_url  = wp_nonce_url(
			add_query_arg( self::EXPORT_QUERY_ARG, '1', $base_url ),
			self::EXPORT_NONCE_ACTION
		);
		?>
		<div class="wrap csa-wp-user-tracker-admin">
			<style>
				.csa-wp-user-tracker-admin { color: #1f2933; max-width: 1240px; }
				.csa-wp-user-tracker-admin * { box-sizing: border-box; }
				.csa-wp-user-tracker-admin h1,
				.csa-wp-user-tracker-admin h2,
				.csa-wp-user-tracker-admin p { margin-top: 0; }
				.csa-wp-user-tracker-admin input[type="text"],
				.csa-wp-user-tracker-admin input[type="date"],
				.csa-wp-user-tracker-admin select { border-color: #cbd5e1; border-radius: 6px; min-height: 36px; }
				.csa-wp-user-tracker-admin input[type="text"]:focus,
				.csa-wp-user-tracker-admin input[type="date"]:focus,
				.csa-wp-user-tracker-admin select:focus { border-color: #0f6b78; box-shadow: 0 0 0 1px #0f6b78; }
				.csa-wp-user-tracker-hero { align-items: flex-start; background: #0f172a; border: 1px solid #1e293b; border-radius: 8px; color: #f8fafc; display: flex; gap: 20px; justify-content: space-between; margin: 20px 0 16px; padding: 22px 24px; }
				.csa-wp-user-tracker-hero h1 { color: #fff; font-size: 26px; line-height: 1.2; margin: 0 0 8px; }
				.csa-wp-user-tracker-hero p { color: #cbd5e1; font-size: 14px; margin: 0; max-width: 660px; }
				.csa-wp-user-tracker-eyebrow { color: #0f6b78; font-size: 11px; font-weight: 700; letter-spacing: 0; margin: 0 0 6px; text-transform: uppercase; }
				.csa-wp-user-tracker-hero .csa-wp-user-tracker-eyebrow { color: #93e3ec; }
				.csa-wp-user-tracker-version-pill,
				.csa-wp-user-tracker-status-pill { align-items: center; border-radius: 999px; display: inline-flex; font-size: 12px; font-weight: 700; gap: 6px; line-height: 1; white-space: nowrap; }
				.csa-wp-user-tracker-version-pill { background: #f8fafc; color: #0f172a; padding: 8px 10px; }
				.csa-wp-user-tracker-version-pill code { background: transparent; color: inherit; font-size: 12px; padding: 0; }
				.csa-wp-user-tracker-status-pill { background: #e2e8f0; color: #334155; padding: 7px 9px; }
				.csa-wp-user-tracker-status-pill.is-on { background: #d1fae5; color: #065f46; }
				.csa-wp-user-tracker-status-pill.is-off { background: #e2e8f0; color: #475569; }
				.csa-wp-user-tracker-stat-grid { display: grid; gap: 12px; grid-template-columns: repeat(4, minmax(0, 1fr)); margin: 0 0 16px; }
				.csa-wp-user-tracker-stat { background: #fff; border: 1px solid #dbe3ec; border-radius: 8px; padding: 14px 16px; }
				.csa-wp-user-tracker-stat span { color: #64748b; display: block; font-size: 12px; font-weight: 700; margin-bottom: 5px; text-transform: uppercase; }
				.csa-wp-user-tracker-stat strong { color: #0f172a; display: block; font-size: 22px; line-height: 1.2; }
				.csa-wp-user-tracker-panel { background: #fff; border: 1px solid #dbe3ec; border-radius: 8px; margin: 0 0 16px; padding: 18px; }
				.csa-wp-user-tracker-panel-heading { align-items: flex-start; display: flex; gap: 12px; justify-content: space-between; margin-bottom: 14px; }
				.csa-wp-user-tracker-panel-heading h2 { color: #0f172a; font-size: 18px; line-height: 1.3; margin: 0; }
				.csa-wp-user-tracker-panel-subtitle { color: #64748b; margin: 5px 0 0; max-width: 680px; }
				.csa-wp-user-tracker-filter-bar { align-items: flex-end; display: grid; gap: 12px; grid-template-columns: minmax(150px, 1fr) minmax(150px, 1fr) minmax(150px, 1fr) 150px 150px auto; margin: 0; }
				.csa-wp-user-tracker-filter-bar label { color: #475569; display: flex; flex-direction: column; font-size: 12px; font-weight: 700; gap: 5px; }
				.csa-wp-user-tracker-filter-bar input[type="text"],
				.csa-wp-user-tracker-filter-bar input[type="date"] { width: 100%; }
				.csa-wp-user-tracker-filter-actions { align-items: center; display: flex; flex-wrap: wrap; gap: 8px; }
				.csa-wp-user-tracker-focus-toggle { align-items: center !important; background: #fff7ed; border: 1px solid #fdba74; border-radius: 6px; color: #1d2327; flex-direction: row !important; font-size: 13px !important; gap: 7px !important; grid-column: 1 / -1; padding: 9px 10px; }
				.csa-wp-user-tracker-focus-note { background: #fffbeb; border-left: 4px solid #d97706; margin: 12px 0 0; padding: 9px 12px; }
				.csa-wp-user-tracker-email-details { margin: 0; }
				.csa-wp-user-tracker-email-summary { align-items: center; cursor: pointer; display: flex; gap: 14px; justify-content: space-between; list-style: none; }
				.csa-wp-user-tracker-email-summary::-webkit-details-marker { display: none; }
				.csa-wp-user-tracker-email-summary h2 { color: #0f172a; font-size: 18px; line-height: 1.3; margin: 0; }
				.csa-wp-user-tracker-email-summary-meta { align-items: center; display: inline-flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; }
				.csa-wp-user-tracker-toggle-pill { border: 1px solid #cbd5e1; border-radius: 999px; color: #334155; display: inline-flex; font-size: 12px; font-weight: 700; line-height: 1; padding: 7px 9px; }
				.csa-wp-user-tracker-email-details[open] .csa-wp-user-tracker-toggle-open,
				.csa-wp-user-tracker-email-details:not([open]) .csa-wp-user-tracker-toggle-close { display: none; }
				.csa-wp-user-tracker-email-details-body { border-top: 1px solid #e2e8f0; margin-top: 14px; padding-top: 2px; }
				.csa-wp-user-tracker-email-form { margin: 0; }
				.csa-wp-user-tracker-settings-grid { display: grid; gap: 0 24px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
				.csa-wp-user-tracker-setting { border-top: 1px solid #e2e8f0; padding: 14px 0; }
				.csa-wp-user-tracker-setting-title { color: #0f172a; display: block; font-weight: 700; margin: 0 0 7px; }
				.csa-wp-user-tracker-setting input.regular-text,
				.csa-wp-user-tracker-setting input[type="text"],
				.csa-wp-user-tracker-setting select { max-width: none; width: 100%; }
				.csa-wp-user-tracker-setting .description { display: block; margin-top: 6px; }
				.csa-wp-user-tracker-choice-row { align-items: center; color: #334155; display: inline-flex; gap: 7px; margin: 0 16px 8px 0; }
				.csa-wp-user-tracker-role-grid { display: grid; gap: 8px 12px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
				.csa-wp-user-tracker-form-actions,
				.csa-wp-user-tracker-email-actions { align-items: center; border-top: 1px solid #e2e8f0; display: flex; flex-wrap: wrap; gap: 8px; padding-top: 14px; }
				.csa-wp-user-tracker-email-actions { margin-top: 14px; }
				.csa-wp-user-tracker-form-actions .submit { margin: 0; padding: 0; }
				.csa-wp-user-tracker-email-actions .submit { margin: 0; padding: 0; }
				.csa-wp-user-tracker-form-note { margin: 10px 0 0; }
				.csa-wp-user-tracker-table-wrap { border: 1px solid #dbe3ec; border-radius: 8px; overflow: auto; }
				.csa-wp-user-tracker-table { border: 0; border-collapse: separate; border-spacing: 0; margin: 0; }
				.csa-wp-user-tracker-table thead th { background: #f8fafc; border-bottom: 1px solid #dbe3ec; color: #334155; font-size: 12px; font-weight: 700; padding: 11px 12px; text-transform: uppercase; }
				.csa-wp-user-tracker-table tbody td { border-bottom: 1px solid #edf2f7; padding: 12px; vertical-align: top; }
				.csa-wp-user-tracker-table tbody tr:last-child td { border-bottom: 0; }
				.csa-wp-user-tracker-table code { background: #eef2f7; border-radius: 4px; color: #334155; padding: 2px 5px; }
				.csa-wp-user-tracker-table small { color: #64748b; }
				.csa-wp-user-tracker-context-preview { background: #f8fafc; border: 1px solid #dbe3ec; border-radius: 6px; max-width: 360px; padding: 10px; white-space: pre-wrap; }
				.csa-wp-user-tracker-pagination { height: auto; margin: 14px 0 0; }
				.csa-wp-user-tracker-pagination .tablenav-pages { display: flex; flex-wrap: wrap; gap: 4px; justify-content: flex-end; float: none; width: 100%; }
				.csa-wp-user-tracker-pagination .tablenav-pages span { align-items: center; display: inline-flex; }
				.csa-wp-user-tracker-pagination .page-numbers { border-radius: 6px; min-width: 30px; text-align: center; }
				.csa-wp-user-tracker-pagination .page-numbers.current { background: #0f172a; border-color: #0f172a; color: #fff; }
				.widefat tbody tr.csa-wp-user-tracker-focus-edit td { background: #fff8e5; }
				.widefat tbody tr.csa-wp-user-tracker-focus-delete td { background: #fcf0f1; }
				.csa-wp-user-tracker-focus-edit td:first-child { border-left: 4px solid #dba617; }
				.csa-wp-user-tracker-focus-delete td:first-child { border-left: 4px solid #d63638; }
				.csa-wp-user-tracker-badge { border-radius: 999px; display: inline-block; font-size: 11px; font-weight: 600; line-height: 1; margin-bottom: 4px; padding: 4px 7px; text-transform: uppercase; }
				.csa-wp-user-tracker-badge-edit { background: #f0b849; color: #1d2327; }
				.csa-wp-user-tracker-badge-delete { background: #d63638; color: #fff; }
				@media (max-width: 1100px) {
					.csa-wp-user-tracker-filter-bar { grid-template-columns: repeat(2, minmax(0, 1fr)); }
					.csa-wp-user-tracker-filter-actions { grid-column: 1 / -1; }
				}
				@media (max-width: 782px) {
					.csa-wp-user-tracker-hero,
					.csa-wp-user-tracker-panel-heading { display: block; }
					.csa-wp-user-tracker-email-summary { align-items: flex-start; display: block; }
					.csa-wp-user-tracker-email-summary-meta { justify-content: flex-start; margin-top: 12px; }
					.csa-wp-user-tracker-version-pill,
					.csa-wp-user-tracker-status-pill { margin-top: 12px; }
					.csa-wp-user-tracker-stat-grid,
					.csa-wp-user-tracker-settings-grid,
					.csa-wp-user-tracker-filter-bar { grid-template-columns: 1fr; }
					.csa-wp-user-tracker-role-grid { grid-template-columns: 1fr; }
				}
			</style>
			<header class="csa-wp-user-tracker-hero">
				<div>
					<p class="csa-wp-user-tracker-eyebrow"><?php esc_html_e( 'Activity Monitor', 'csa-wp-user-tracker' ); ?></p>
					<h1><?php esc_html_e( 'CSA WP User Tracker', 'csa-wp-user-tracker' ); ?></h1>
					<p><?php esc_html_e( 'Tracks logged-in activity for users whose roles are not limited to subscriber.', 'csa-wp-user-tracker' ); ?></p>
				</div>
				<div class="csa-wp-user-tracker-version-pill">
					<?php esc_html_e( 'Loaded version', 'csa-wp-user-tracker' ); ?>
					<code><?php echo esc_html( CSA_WP_USER_TRACKER_VERSION ); ?></code>
				</div>
			</header>
			<div class="csa-wp-user-tracker-stat-grid">
				<div class="csa-wp-user-tracker-stat">
					<span><?php esc_html_e( 'Showing', 'csa-wp-user-tracker' ); ?></span>
					<strong><?php echo esc_html( sprintf( '%1$d-%2$d', absint( $range_start ), absint( $range_end ) ) ); ?></strong>
				</div>
				<div class="csa-wp-user-tracker-stat">
					<span><?php esc_html_e( 'Total matching', 'csa-wp-user-tracker' ); ?></span>
					<strong><?php echo absint( $total ); ?></strong>
				</div>
				<div class="csa-wp-user-tracker-stat">
					<span><?php esc_html_e( 'Page', 'csa-wp-user-tracker' ); ?></span>
					<strong><?php echo esc_html( sprintf( '%1$d / %2$d', absint( $page ), absint( $total_pages ) ) ); ?></strong>
				</div>
				<div class="csa-wp-user-tracker-stat">
					<span><?php esc_html_e( 'Mode', 'csa-wp-user-tracker' ); ?></span>
					<strong><?php echo esc_html( $filters['focus_content_edits'] ? __( 'Focused', 'csa-wp-user-tracker' ) : __( 'All activity', 'csa-wp-user-tracker' ) ); ?></strong>
				</div>
			</div>
			<?php self::render_email_settings_notices(); ?>
			<section class="csa-wp-user-tracker-panel">
				<?php self::render_email_settings_form(); ?>
			</section>
			<section class="csa-wp-user-tracker-panel">
				<div class="csa-wp-user-tracker-panel-heading">
					<div>
						<h2><?php esc_html_e( 'Activity Filters', 'csa-wp-user-tracker' ); ?></h2>
						<p class="csa-wp-user-tracker-panel-subtitle"><?php esc_html_e( 'Narrow the log by user, stored action, object type, or date range.', 'csa-wp-user-tracker' ); ?></p>
					</div>
				</div>
				<form method="get" class="csa-wp-user-tracker-filter-bar">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::ADMIN_PAGE_SLUG ); ?>">
					<label>
						<?php esc_html_e( 'User', 'csa-wp-user-tracker' ); ?>
						<input type="text" name="activity_user" value="<?php echo esc_attr( $filters['user'] ); ?>" placeholder="<?php esc_attr_e( 'ID or login', 'csa-wp-user-tracker' ); ?>">
					</label>
					<label>
						<?php esc_html_e( 'Action', 'csa-wp-user-tracker' ); ?>
						<input type="text" name="activity_action" value="<?php echo esc_attr( $filters['action'] ); ?>" placeholder="post_updated">
					</label>
					<label>
						<?php esc_html_e( 'Object Type', 'csa-wp-user-tracker' ); ?>
						<input type="text" name="activity_object_type" value="<?php echo esc_attr( $filters['object_type'] ); ?>" placeholder="post">
					</label>
					<label>
						<?php esc_html_e( 'From', 'csa-wp-user-tracker' ); ?>
						<input type="date" name="activity_from" value="<?php echo esc_attr( $filters['from'] ); ?>">
					</label>
					<label>
						<?php esc_html_e( 'To', 'csa-wp-user-tracker' ); ?>
						<input type="date" name="activity_to" value="<?php echo esc_attr( $filters['to'] ); ?>">
					</label>
					<div class="csa-wp-user-tracker-filter-actions">
						<?php submit_button( __( 'Filter', 'csa-wp-user-tracker' ), 'secondary', '', false ); ?>
						<a class="button" href="<?php echo esc_url( menu_page_url( self::ADMIN_PAGE_SLUG, false ) ); ?>"><?php esc_html_e( 'Reset', 'csa-wp-user-tracker' ); ?></a>
						<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'csa-wp-user-tracker' ); ?></a>
					</div>
					<label class="csa-wp-user-tracker-focus-toggle">
						<input type="checkbox" name="activity_focus_content_edits" value="1" <?php checked( $filters['focus_content_edits'] ); ?>>
						<?php esc_html_e( 'Focus page/post edits and deletes', 'csa-wp-user-tracker' ); ?>
					</label>
				</form>
				<?php if ( $filters['focus_content_edits'] ) : ?>
					<p class="description csa-wp-user-tracker-focus-note"><?php esc_html_e( 'Showing only page/post edit, status-change, trash, and permanent-delete activity.', 'csa-wp-user-tracker' ); ?></p>
				<?php endif; ?>
			</section>
			<section class="csa-wp-user-tracker-panel">
				<div class="csa-wp-user-tracker-panel-heading">
					<div>
						<h2><?php esc_html_e( 'Activity List', 'csa-wp-user-tracker' ); ?></h2>
						<p class="csa-wp-user-tracker-panel-subtitle">
							<?php
							printf(
								/* translators: 1: first row number, 2: last row number, 3: total rows */
								esc_html__( 'Showing %1$d-%2$d of %3$d logged activities.', 'csa-wp-user-tracker' ),
								absint( $range_start ),
								absint( $range_end ),
								absint( $total )
							);
							?>
						</p>
					</div>
				</div>
				<div class="csa-wp-user-tracker-table-wrap">
					<table class="widefat csa-wp-user-tracker-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Time', 'csa-wp-user-tracker' ); ?></th>
								<th><?php esc_html_e( 'User', 'csa-wp-user-tracker' ); ?></th>
								<th><?php esc_html_e( 'Roles', 'csa-wp-user-tracker' ); ?></th>
								<th><?php esc_html_e( 'Action', 'csa-wp-user-tracker' ); ?></th>
								<th><?php esc_html_e( 'Object', 'csa-wp-user-tracker' ); ?></th>
								<th><?php esc_html_e( 'Request', 'csa-wp-user-tracker' ); ?></th>
								<th><?php esc_html_e( 'Context', 'csa-wp-user-tracker' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $rows ) ) : ?>
								<tr><td colspan="7"><?php esc_html_e( 'No activity found.', 'csa-wp-user-tracker' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $rows as $row ) : ?>
									<?php
									$action_label  = self::admin_action_label( $row );
									$object_label  = self::admin_object_label( $row );
									$request_label = self::admin_request_label( $row );
									$focus_kind    = self::admin_focus_kind( $row );
									?>
									<tr class="<?php echo $focus_kind ? esc_attr( 'csa-wp-user-tracker-focus-' . $focus_kind ) : ''; ?>">
										<td><?php echo esc_html( mysql2date( 'Y-m-d H:i:s', $row->occurred_at, true ) ); ?></td>
										<td>
											<strong><?php echo esc_html( $row->display_name ? $row->display_name : $row->user_login ); ?></strong><br>
											<code><?php echo esc_html( $row->user_login ); ?></code> #<?php echo absint( $row->user_id ); ?>
										</td>
										<td><?php echo esc_html( $row->roles ); ?></td>
										<td>
											<?php if ( $focus_kind ) : ?>
												<span class="<?php echo esc_attr( 'csa-wp-user-tracker-badge csa-wp-user-tracker-badge-' . $focus_kind ); ?>"><?php echo esc_html( self::admin_focus_badge( $focus_kind ) ); ?></span><br>
											<?php endif; ?>
											<strong><?php echo esc_html( $action_label ); ?></strong><br>
											<small><code><?php echo esc_html( $row->action ); ?></code></small>
										</td>
										<td>
											<?php echo esc_html( $object_label ); ?><br>
											<small><code><?php echo esc_html( $row->object_type ); ?></code><?php echo $row->object_id ? ' #' . absint( $row->object_id ) : ''; ?></small>
										</td>
										<td>
											<?php echo esc_html( $request_label ); ?><br>
											<small><?php echo esc_html( $row->request_uri ); ?></small>
										</td>
										<td>
											<?php if ( $row->context ) : ?>
												<details>
													<summary><?php esc_html_e( 'View', 'csa-wp-user-tracker' ); ?></summary>
													<pre class="csa-wp-user-tracker-context-preview"><?php echo esc_html( self::pretty_json( $row->context ) ); ?></pre>
												</details>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
				<?php if ( $total_pages > 1 ) : ?>
					<?php self::render_admin_pagination( $page, $total_pages, $base_url ); ?>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Render activity-list pagination with stable admin layout.
	 *
	 * @param int    $page Current page.
	 * @param int    $total_pages Total pages.
	 * @param string $base_url Base URL.
	 */
	private static function render_admin_pagination( $page, $total_pages, $base_url ) {
		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%', $base_url ),
				'format'    => '',
				'current'   => $page,
				'total'     => $total_pages,
				'prev_text' => __( '&laquo;', 'csa-wp-user-tracker' ),
				'next_text' => __( '&raquo;', 'csa-wp-user-tracker' ),
				'type'      => 'array',
			)
		);

		if ( empty( $links ) || ! is_array( $links ) ) {
			return;
		}
		?>
		<div class="tablenav csa-wp-user-tracker-pagination">
			<div class="tablenav-pages">
				<?php foreach ( $links as $link ) : ?>
					<span><?php echo wp_kses_post( $link ); ?></span>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Human-readable action label for the activity table.
	 *
	 * @param object $row Log row.
	 * @return string
	 */
	private static function admin_action_label( $row ) {
		$object_type = self::lowercase_first( self::admin_object_type_label( $row->object_type ) );
		$context     = self::row_context_array( $row );

		switch ( $row->action ) {
			case 'login_success':
				return __( 'Logged in', 'csa-wp-user-tracker' );
			case 'login_failed':
				return __( 'Tried to log in but failed', 'csa-wp-user-tracker' );
			case 'logout':
				return __( 'Logged out', 'csa-wp-user-tracker' );
			case 'admin_screen_viewed':
				return __( 'Opened a WordPress admin screen', 'csa-wp-user-tracker' );
			case 'ajax_request':
				return __( 'Ran a background admin request', 'csa-wp-user-tracker' );
			case 'frontend_view':
				return __( 'Viewed content on the public site', 'csa-wp-user-tracker' );
			case 'rest_request':
				return __( 'Used the WordPress REST API', 'csa-wp-user-tracker' );
			case 'post_created':
				return sprintf( __( 'Created %s', 'csa-wp-user-tracker' ), $object_type );
			case 'post_updated':
				return sprintf( __( 'Updated %s', 'csa-wp-user-tracker' ), $object_type );
			case 'post_status_changed':
				if ( ! empty( $context['from'] ) && ! empty( $context['to'] ) ) {
					return sprintf(
						__( 'Changed %1$s status from %2$s to %3$s', 'csa-wp-user-tracker' ),
						$object_type,
						self::slug_to_label( $context['from'] ),
						self::slug_to_label( $context['to'] )
					);
				}
				return sprintf( __( 'Changed %s status', 'csa-wp-user-tracker' ), $object_type );
			case 'post_trashed':
				return sprintf( __( 'Deleted %s (moved to Trash)', 'csa-wp-user-tracker' ), $object_type );
			case 'post_untrashed':
				return sprintf( __( 'Restored %s from Trash', 'csa-wp-user-tracker' ), $object_type );
			case 'post_deleted':
				return sprintf( __( 'Deleted %s permanently', 'csa-wp-user-tracker' ), $object_type );
			case 'attachment_added':
				return __( 'Uploaded media file', 'csa-wp-user-tracker' );
			case 'attachment_updated':
				return __( 'Updated media file', 'csa-wp-user-tracker' );
			case 'attachment_deleted':
				return __( 'Deleted media file permanently', 'csa-wp-user-tracker' );
			case 'term_created':
				return sprintf( __( 'Created %s', 'csa-wp-user-tracker' ), $object_type );
			case 'term_updated':
				return sprintf( __( 'Updated %s', 'csa-wp-user-tracker' ), $object_type );
			case 'term_deleted':
				return sprintf( __( 'Deleted %s', 'csa-wp-user-tracker' ), $object_type );
			case 'comment_created':
				return __( 'Added comment', 'csa-wp-user-tracker' );
			case 'comment_updated':
				return __( 'Updated comment', 'csa-wp-user-tracker' );
			case 'comment_deleted':
			case 'deleted_comment':
				return __( 'Deleted comment permanently', 'csa-wp-user-tracker' );
			case 'trashed_comment':
				return __( 'Deleted comment (moved to Trash)', 'csa-wp-user-tracker' );
			case 'untrashed_comment':
				return __( 'Restored comment from Trash', 'csa-wp-user-tracker' );
			case 'spam_comment':
				return __( 'Marked comment as spam', 'csa-wp-user-tracker' );
			case 'unspam_comment':
				return __( 'Unmarked comment as spam', 'csa-wp-user-tracker' );
			case 'user_created':
				return __( 'Created user account', 'csa-wp-user-tracker' );
			case 'user_updated':
				return __( 'Updated user account', 'csa-wp-user-tracker' );
			case 'user_deleted':
				return __( 'Deleted user account', 'csa-wp-user-tracker' );
			case 'user_role_set':
				return __( 'Changed user role', 'csa-wp-user-tracker' );
			case 'user_role_added':
				return __( 'Added user role', 'csa-wp-user-tracker' );
			case 'user_role_removed':
				return __( 'Removed user role', 'csa-wp-user-tracker' );
			case 'option_added':
				return __( 'Added site setting', 'csa-wp-user-tracker' );
			case 'option_updated':
				return __( 'Updated site setting', 'csa-wp-user-tracker' );
			case 'option_deleted':
				return __( 'Deleted site setting', 'csa-wp-user-tracker' );
			case 'plugin_activated':
				return __( 'Activated plugin', 'csa-wp-user-tracker' );
			case 'plugin_deactivated':
				return __( 'Deactivated plugin', 'csa-wp-user-tracker' );
			case 'theme_switched':
				return __( 'Changed site theme', 'csa-wp-user-tracker' );
			case 'upgrader_process_complete':
				return __( 'Completed a WordPress update', 'csa-wp-user-tracker' );
			case 'email_update_sent':
				return __( 'Sent email update', 'csa-wp-user-tracker' );
		}

		return self::slug_to_label( $row->action );
	}

	/**
	 * Human-readable object label for the activity table.
	 *
	 * @param object $row Log row.
	 * @return string
	 */
	private static function admin_object_label( $row ) {
		$type  = self::admin_object_type_label( $row->object_type );
		$name  = trim( wp_strip_all_tags( (string) $row->object_name ) );
		$label = $type;

		if ( $row->object_id ) {
			$label .= ' #' . absint( $row->object_id );
		}

		if ( '' !== $name ) {
			$label .= ': ' . wp_trim_words( wp_specialchars_decode( $name, ENT_QUOTES ), 12, '...' );
		}

		return $label;
	}

	/**
	 * Human-readable request label for the activity table.
	 *
	 * @param object $row Log row.
	 * @return string
	 */
	private static function admin_request_label( $row ) {
		$method = strtoupper( (string) $row->request_method );
		$uri    = (string) $row->request_uri;

		if ( 'email_update_sent' === $row->action ) {
			return __( 'Sent through WordPress mail', 'csa-wp-user-tracker' );
		}

		if ( '' === $method && '' === $uri ) {
			return __( 'No browser request recorded', 'csa-wp-user-tracker' );
		}

		if ( false !== strpos( $uri, '/wp-admin/admin-ajax.php' ) ) {
			return __( 'Background admin request', 'csa-wp-user-tracker' );
		}

		if ( 'rest_request' === $row->action ) {
			return '' !== $method ? sprintf( __( 'REST API %s request', 'csa-wp-user-tracker' ), $method ) : __( 'REST API request', 'csa-wp-user-tracker' );
		}

		$area = false !== strpos( $uri, '/wp-admin/' ) ? __( 'WordPress admin', 'csa-wp-user-tracker' ) : __( 'public site', 'csa-wp-user-tracker' );

		if ( in_array( $row->action, array( 'post_trashed', 'post_deleted', 'attachment_deleted', 'comment_deleted', 'deleted_comment', 'term_deleted', 'user_deleted', 'option_deleted' ), true ) ) {
			return sprintf( __( 'Submitted a delete action in %s', 'csa-wp-user-tracker' ), $area );
		}

		switch ( $method ) {
			case 'GET':
				return sprintf( __( 'Opened a page in %s', 'csa-wp-user-tracker' ), $area );
			case 'POST':
				return sprintf( __( 'Submitted changes in %s', 'csa-wp-user-tracker' ), $area );
			case 'DELETE':
				return sprintf( __( 'Sent a delete request to %s', 'csa-wp-user-tracker' ), $area );
			case 'PUT':
			case 'PATCH':
				return sprintf( __( 'Submitted an update request to %s', 'csa-wp-user-tracker' ), $area );
		}

		return '' !== $method ? sprintf( __( '%1$s request in %2$s', 'csa-wp-user-tracker' ), $method, $area ) : sprintf( __( 'Request in %s', 'csa-wp-user-tracker' ), $area );
	}

	/**
	 * Page/post actions emphasized in the activity table.
	 *
	 * @return array
	 */
	private static function admin_focus_actions() {
		return array( 'post_updated', 'post_status_changed', 'post_trashed', 'post_deleted' );
	}

	/**
	 * Return edit/delete focus type for a row.
	 *
	 * @param array|object $row Log row.
	 * @return string
	 */
	private static function admin_focus_kind( $row ) {
		$row         = is_object( $row ) ? get_object_vars( $row ) : (array) $row;
		$object_type = isset( $row['object_type'] ) ? sanitize_key( $row['object_type'] ) : '';
		$action      = isset( $row['action'] ) ? sanitize_key( $row['action'] ) : '';

		if ( ! in_array( $object_type, array( 'post', 'page' ), true ) || ! in_array( $action, self::admin_focus_actions(), true ) ) {
			return '';
		}

		return in_array( $action, array( 'post_trashed', 'post_deleted' ), true ) ? 'delete' : 'edit';
	}

	/**
	 * Badge label for focused rows.
	 *
	 * @param string $kind Focus kind.
	 * @return string
	 */
	private static function admin_focus_badge( $kind ) {
		return 'delete' === $kind ? __( 'Delete', 'csa-wp-user-tracker' ) : __( 'Edit', 'csa-wp-user-tracker' );
	}

	/**
	 * Human-readable object type label.
	 *
	 * @param string $object_type Object type.
	 * @return string
	 */
	private static function admin_object_type_label( $object_type ) {
		$object_type = sanitize_key( $object_type );

		$labels = array(
			'404'          => __( '404 page', 'csa-wp-user-tracker' ),
			'admin_screen' => __( 'Admin screen', 'csa-wp-user-tracker' ),
			'ajax'         => __( 'Background request', 'csa-wp-user-tracker' ),
			'attachment'   => __( 'Media file', 'csa-wp-user-tracker' ),
			'comment'      => __( 'Comment', 'csa-wp-user-tracker' ),
			'email_update' => __( 'Email update', 'csa-wp-user-tracker' ),
			'frontend'     => __( 'Public page', 'csa-wp-user-tracker' ),
			'home'         => __( 'Home page', 'csa-wp-user-tracker' ),
			'option'       => __( 'Site setting', 'csa-wp-user-tracker' ),
			'plugin'       => __( 'Plugin', 'csa-wp-user-tracker' ),
			'post'         => __( 'Post', 'csa-wp-user-tracker' ),
			'page'         => __( 'Page', 'csa-wp-user-tracker' ),
			'rest_route'   => __( 'REST API route', 'csa-wp-user-tracker' ),
			'search'       => __( 'Search results', 'csa-wp-user-tracker' ),
			'theme'        => __( 'Theme', 'csa-wp-user-tracker' ),
			'user'         => __( 'User', 'csa-wp-user-tracker' ),
		);

		if ( isset( $labels[ $object_type ] ) ) {
			return $labels[ $object_type ];
		}

		$post_type = get_post_type_object( $object_type );
		if ( $post_type && ! empty( $post_type->labels->singular_name ) ) {
			return $post_type->labels->singular_name;
		}

		$taxonomy = get_taxonomy( $object_type );
		if ( $taxonomy && ! empty( $taxonomy->labels->singular_name ) ) {
			return $taxonomy->labels->singular_name;
		}

		return self::slug_to_label( $object_type );
	}

	/**
	 * Decode row context safely.
	 *
	 * @param object $row Log row.
	 * @return array
	 */
	private static function row_context_array( $row ) {
		return self::context_to_array( isset( $row->context ) ? $row->context : '' );
	}

	/**
	 * Decode context data safely.
	 *
	 * @param mixed $context Context data.
	 * @return array
	 */
	private static function context_to_array( $context ) {
		if ( is_array( $context ) ) {
			return $context;
		}

		if ( empty( $context ) || ! is_string( $context ) ) {
			return array();
		}

		$decoded = json_decode( $context, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Convert a stored slug to title-case words.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	private static function slug_to_label( $slug ) {
		$label = trim( preg_replace( '/[-_]+/', ' ', (string) $slug ) );
		return '' !== $label ? ucwords( strtolower( $label ) ) : __( 'Unknown', 'csa-wp-user-tracker' );
	}

	/**
	 * Lowercase the first character in an ASCII label.
	 *
	 * @param string $label Label.
	 * @return string
	 */
	private static function lowercase_first( $label ) {
		return '' === $label ? $label : strtolower( substr( $label, 0, 1 ) ) . substr( $label, 1 );
	}

	/**
	 * Render email settings notices.
	 */
	private static function render_email_settings_notices() {
		$notice = isset( $_GET['csa_email_notice'] ) ? sanitize_key( wp_unslash( $_GET['csa_email_notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'saved'         => __( 'Email update settings saved.', 'csa-wp-user-tracker' ),
			'digest_sent'   => __( 'Pending email digest sent.', 'csa-wp-user-tracker' ),
			'digest_empty'  => __( 'There are no pending email updates to send.', 'csa-wp-user-tracker' ),
			'digest_failed' => __( 'The pending email digest could not be sent.', 'csa-wp-user-tracker' ),
			'test_sent'     => __( 'Test email sent.', 'csa-wp-user-tracker' ),
			'test_failed'   => __( 'The test email could not be sent.', 'csa-wp-user-tracker' ),
			'test_empty'    => __( 'Add and save at least one email recipient before sending a test.', 'csa-wp-user-tracker' ),
		);

		if ( empty( $messages[ $notice ] ) ) {
			return;
		}

		$class = in_array( $notice, array( 'digest_failed', 'test_failed', 'test_empty' ), true ) ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible';
		?>
		<div class="<?php echo esc_attr( $class ); ?>">
			<p><?php echo esc_html( $messages[ $notice ] ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render email update settings.
	 */
	private static function render_email_settings_form() {
		$settings       = self::email_settings();
		$roles          = wp_roles();
		$editable_roles = $roles ? $roles->roles : array();
		$queue_count    = count( self::email_queue() );
		$next_digest    = wp_next_scheduled( self::EMAIL_DIGEST_HOOK );
		$details_open   = isset( $_GET['csa_email_notice'] );
		?>
		<details class="csa-wp-user-tracker-email-details" <?php echo $details_open ? 'open' : ''; ?>>
			<summary class="csa-wp-user-tracker-email-summary">
				<div>
					<p class="csa-wp-user-tracker-eyebrow"><?php esc_html_e( 'Notifications', 'csa-wp-user-tracker' ); ?></p>
					<h2><?php esc_html_e( 'Email Updates', 'csa-wp-user-tracker' ); ?></h2>
					<p class="csa-wp-user-tracker-panel-subtitle"><?php esc_html_e( 'Expand to edit recipients, watched events, actor filters, and timing.', 'csa-wp-user-tracker' ); ?></p>
				</div>
				<span class="csa-wp-user-tracker-email-summary-meta">
					<span class="<?php echo esc_attr( $settings['enabled'] ? 'csa-wp-user-tracker-status-pill is-on' : 'csa-wp-user-tracker-status-pill is-off' ); ?>">
						<?php echo esc_html( $settings['enabled'] ? __( 'Enabled', 'csa-wp-user-tracker' ) : __( 'Off', 'csa-wp-user-tracker' ) ); ?>
					</span>
					<span class="csa-wp-user-tracker-toggle-pill">
						<span class="csa-wp-user-tracker-toggle-open"><?php esc_html_e( 'Expand', 'csa-wp-user-tracker' ); ?></span>
						<span class="csa-wp-user-tracker-toggle-close"><?php esc_html_e( 'Collapse', 'csa-wp-user-tracker' ); ?></span>
					</span>
				</span>
			</summary>
			<div class="csa-wp-user-tracker-email-details-body">
				<form method="post" class="csa-wp-user-tracker-email-form">
			<?php wp_nonce_field( self::EMAIL_SETTINGS_NONCE_ACTION ); ?>
			<input type="hidden" name="csa_wp_user_tracker_email_settings_action" value="save">
			<div class="csa-wp-user-tracker-settings-grid">
				<div class="csa-wp-user-tracker-setting">
					<span class="csa-wp-user-tracker-setting-title"><?php esc_html_e( 'Email updates', 'csa-wp-user-tracker' ); ?></span>
					<label class="csa-wp-user-tracker-choice-row">
						<input type="checkbox" name="csa_email_enabled" value="1" <?php checked( $settings['enabled'] ); ?>>
						<?php esc_html_e( 'Send email updates for matching content updates.', 'csa-wp-user-tracker' ); ?>
					</label>
				</div>
				<label class="csa-wp-user-tracker-setting" for="csa-email-recipients">
					<span class="csa-wp-user-tracker-setting-title"><?php esc_html_e( 'Recipients', 'csa-wp-user-tracker' ); ?></span>
					<input type="text" class="regular-text" id="csa-email-recipients" name="csa_email_recipients" value="<?php echo esc_attr( implode( ', ', $settings['recipients'] ) ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
					<span class="description"><?php esc_html_e( 'Separate multiple email addresses with commas.', 'csa-wp-user-tracker' ); ?></span>
				</label>
				<div class="csa-wp-user-tracker-setting">
					<span class="csa-wp-user-tracker-setting-title"><?php esc_html_e( 'Events', 'csa-wp-user-tracker' ); ?></span>
					<label class="csa-wp-user-tracker-choice-row">
						<input type="checkbox" name="csa_email_post_types[]" value="post" <?php checked( in_array( 'post', $settings['post_types'], true ) ); ?>>
						<?php esc_html_e( 'Post changes', 'csa-wp-user-tracker' ); ?>
					</label>
					<label class="csa-wp-user-tracker-choice-row">
						<input type="checkbox" name="csa_email_post_types[]" value="page" <?php checked( in_array( 'page', $settings['post_types'], true ) ); ?>>
						<?php esc_html_e( 'Page changes', 'csa-wp-user-tracker' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Includes create, update, status, trash, restore, and delete events.', 'csa-wp-user-tracker' ); ?></p>
				</div>
				<label class="csa-wp-user-tracker-setting" for="csa-email-scope">
					<span class="csa-wp-user-tracker-setting-title"><?php esc_html_e( 'Actor filter', 'csa-wp-user-tracker' ); ?></span>
					<select id="csa-email-scope" name="csa_email_scope">
						<option value="any" <?php selected( $settings['scope'], 'any' ); ?>><?php esc_html_e( 'Any tracked user', 'csa-wp-user-tracker' ); ?></option>
						<option value="user" <?php selected( $settings['scope'], 'user' ); ?>><?php esc_html_e( 'One user', 'csa-wp-user-tracker' ); ?></option>
						<option value="roles" <?php selected( $settings['scope'], 'roles' ); ?>><?php esc_html_e( 'Selected roles', 'csa-wp-user-tracker' ); ?></option>
					</select>
				</label>
				<label class="csa-wp-user-tracker-setting" for="csa-email-actor-user">
					<span class="csa-wp-user-tracker-setting-title"><?php esc_html_e( 'One user', 'csa-wp-user-tracker' ); ?></span>
					<input type="text" class="regular-text" id="csa-email-actor-user" name="csa_email_actor_user" value="<?php echo esc_attr( $settings['actor_user'] ); ?>" placeholder="<?php esc_attr_e( 'User ID, login, or email', 'csa-wp-user-tracker' ); ?>">
					<span class="description"><?php esc_html_e( 'Used only when Actor filter is set to One user.', 'csa-wp-user-tracker' ); ?></span>
				</label>
				<div class="csa-wp-user-tracker-setting">
					<span class="csa-wp-user-tracker-setting-title"><?php esc_html_e( 'Roles', 'csa-wp-user-tracker' ); ?></span>
					<div class="csa-wp-user-tracker-role-grid">
						<?php foreach ( $editable_roles as $role_key => $role_data ) : ?>
							<label class="csa-wp-user-tracker-choice-row">
								<input type="checkbox" name="csa_email_roles[]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, $settings['roles'], true ) ); ?>>
								<?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="description"><?php esc_html_e( 'Used only when Actor filter is set to Selected roles.', 'csa-wp-user-tracker' ); ?></p>
				</div>
				<label class="csa-wp-user-tracker-setting" for="csa-email-cadence">
					<span class="csa-wp-user-tracker-setting-title"><?php esc_html_e( 'Send timing', 'csa-wp-user-tracker' ); ?></span>
					<select id="csa-email-cadence" name="csa_email_cadence">
						<option value="immediate" <?php selected( $settings['cadence'], 'immediate' ); ?>><?php esc_html_e( 'Once triggered', 'csa-wp-user-tracker' ); ?></option>
						<option value="daily" <?php selected( $settings['cadence'], 'daily' ); ?>><?php esc_html_e( 'Daily digest', 'csa-wp-user-tracker' ); ?></option>
						<option value="weekly" <?php selected( $settings['cadence'], 'weekly' ); ?>><?php esc_html_e( 'Weekly digest', 'csa-wp-user-tracker' ); ?></option>
					</select>
					<span class="description">
						<?php
						if ( $next_digest ) {
							printf(
								/* translators: %s: scheduled datetime */
								esc_html__( 'Next digest run: %s.', 'csa-wp-user-tracker' ),
								esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_digest ), 'Y-m-d H:i:s' ) )
							);
						} else {
							esc_html_e( 'No digest is scheduled unless daily or weekly timing is enabled.', 'csa-wp-user-tracker' );
						}
						?>
					</span>
				</label>
			</div>
			<div class="csa-wp-user-tracker-form-actions">
				<?php submit_button( __( 'Save Email Updates', 'csa-wp-user-tracker' ), 'primary', 'submit', false ); ?>
			</div>
				</form>
				<div class="csa-wp-user-tracker-email-actions">
					<form method="post">
						<?php wp_nonce_field( self::EMAIL_SETTINGS_NONCE_ACTION ); ?>
						<input type="hidden" name="csa_wp_user_tracker_email_settings_action" value="send_digest_now">
						<?php submit_button( sprintf( __( 'Send Pending Digest Now (%d)', 'csa-wp-user-tracker' ), absint( $queue_count ) ), 'secondary', 'submit', false ); ?>
					</form>
					<form method="post">
						<?php wp_nonce_field( self::EMAIL_SETTINGS_NONCE_ACTION ); ?>
						<input type="hidden" name="csa_wp_user_tracker_email_settings_action" value="send_test_email">
						<?php submit_button( __( 'Send Test Email', 'csa-wp-user-tracker' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>
				<p class="description csa-wp-user-tracker-form-note"><?php esc_html_e( 'The test email uses the saved recipients above. Save changes before testing new recipients.', 'csa-wp-user-tracker' ); ?></p>
			</div>
		</details>
		<?php
	}

	/**
	 * Save email settings or send a pending digest from the admin UI.
	 */
	public static function maybe_save_email_settings() {
		if ( empty( $_POST['csa_wp_user_tracker_email_settings_action'] ) ) {
			return;
		}

		if ( ! current_user_can( self::admin_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage email updates.', 'csa-wp-user-tracker' ) );
		}

		check_admin_referer( self::EMAIL_SETTINGS_NONCE_ACTION );

		$action = sanitize_key( wp_unslash( $_POST['csa_wp_user_tracker_email_settings_action'] ) );
		if ( 'save' === $action ) {
			$raw_settings = array(
				'csa_email_enabled'    => isset( $_POST['csa_email_enabled'] ) ? wp_unslash( $_POST['csa_email_enabled'] ) : '',
				'csa_email_recipients' => isset( $_POST['csa_email_recipients'] ) ? wp_unslash( $_POST['csa_email_recipients'] ) : '',
				'csa_email_post_types' => isset( $_POST['csa_email_post_types'] ) ? wp_unslash( $_POST['csa_email_post_types'] ) : array(),
				'csa_email_scope'      => isset( $_POST['csa_email_scope'] ) ? wp_unslash( $_POST['csa_email_scope'] ) : '',
				'csa_email_actor_user' => isset( $_POST['csa_email_actor_user'] ) ? wp_unslash( $_POST['csa_email_actor_user'] ) : '',
				'csa_email_roles'      => isset( $_POST['csa_email_roles'] ) ? wp_unslash( $_POST['csa_email_roles'] ) : array(),
				'csa_email_cadence'    => isset( $_POST['csa_email_cadence'] ) ? wp_unslash( $_POST['csa_email_cadence'] ) : '',
			);

			$settings = self::sanitize_email_settings( $raw_settings );
			self::write_option( self::OPTION_EMAIL_SETTINGS, $settings );
			if ( ! $settings['enabled'] || 'immediate' === $settings['cadence'] ) {
				self::write_option( self::OPTION_EMAIL_QUEUE, array() );
			}
			self::reschedule_email_digest();
			self::redirect_to_admin_page( array( 'csa_email_notice' => 'saved' ) );
		}

		if ( 'send_digest_now' === $action ) {
			$result = self::send_queued_email_digest();
			if ( ! empty( $result['sent'] ) ) {
				self::redirect_to_admin_page( array( 'csa_email_notice' => 'digest_sent' ) );
			}

			self::redirect_to_admin_page( array( 'csa_email_notice' => empty( $result['failed'] ) ? 'digest_empty' : 'digest_failed' ) );
		}

		if ( 'send_test_email' === $action ) {
			$settings = self::email_settings();
			if ( empty( $settings['recipients'] ) ) {
				self::redirect_to_admin_page( array( 'csa_email_notice' => 'test_empty' ) );
			}

			self::redirect_to_admin_page( array( 'csa_email_notice' => self::send_test_email_update( $settings ) ? 'test_sent' : 'test_failed' ) );
		}
	}

	/**
	 * Redirect back to the tracker admin page.
	 *
	 * @param array $args Query args.
	 */
	private static function redirect_to_admin_page( $args = array() ) {
		$url = add_query_arg( $args, admin_url( 'tools.php?page=' . self::ADMIN_PAGE_SLUG ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Export filtered rows as CSV.
	 */
	public static function maybe_export_csv() {
		if ( empty( $_GET['page'] ) || self::ADMIN_PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) || empty( $_GET[ self::EXPORT_QUERY_ARG ] ) ) {
			return;
		}

		if ( ! current_user_can( self::admin_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to export activity logs.', 'csa-wp-user-tracker' ) );
		}

		check_admin_referer( self::EXPORT_NONCE_ACTION );

		global $wpdb;

		$table_name = self::table_name();
		$where      = self::build_where_sql( self::get_admin_filters() );
		$rows       = $wpdb->get_results( "SELECT * FROM {$table_name} {$where['sql']} ORDER BY occurred_at DESC, id DESC LIMIT 5000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=csa-wp-user-tracker-log-' . gmdate( 'Ymd-His' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'id', 'occurred_at', 'user_id', 'user_login', 'display_name', 'roles', 'action', 'object_type', 'object_id', 'object_name', 'request_method', 'request_uri', 'ip_hash', 'context' ) );

		foreach ( $rows as $row ) {
			fputcsv( $output, $row );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Log successful login.
	 *
	 * @param string  $user_login Login.
	 * @param WP_User $user User object.
	 */
	public static function log_login( $user_login, $user ) {
		self::log_as_user( $user, 'login_success', 'user', $user->ID, $user_login );
	}

	/**
	 * Log failed logins for protected roles only when the username maps to a user.
	 *
	 * @param string $username Username.
	 */
	public static function log_failed_login( $username ) {
		$user = get_user_by( 'login', $username );
		if ( ! $user ) {
			$user = get_user_by( 'email', $username );
		}

		if ( $user ) {
			self::log_as_user( $user, 'login_failed', 'user', $user->ID, $username );
		}
	}

	/**
	 * Log logout.
	 *
	 * @param int $user_id User ID.
	 */
	public static function log_logout( $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		$user    = $user_id ? get_user_by( 'id', $user_id ) : null;
		if ( $user ) {
			self::log_as_user( $user, 'logout', 'user', $user->ID, $user->user_login );
		}
	}

	/**
	 * Log normal admin screen loads.
	 *
	 * @param WP_Screen $screen Screen.
	 */
	public static function log_admin_screen( $screen ) {
		if ( ! $screen || wp_doing_ajax() ) {
			return;
		}

		self::log(
			'admin_screen_viewed',
			'admin_screen',
			0,
			$screen->id,
			array(
				'base'      => $screen->base,
				'post_type' => $screen->post_type,
				'taxonomy'  => $screen->taxonomy,
			)
		);
	}

	/**
	 * Log admin AJAX requests.
	 */
	public static function maybe_log_ajax_request() {
		if ( ! wp_doing_ajax() ) {
			return;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( in_array( $action, array( 'heartbeat', 'closed-postboxes', 'meta-box-order' ), true ) ) {
			return;
		}

		self::log( 'ajax_request', 'ajax', 0, $action );
	}

	/**
	 * Log front-end page views for tracked users.
	 */
	public static function log_frontend_view() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$object_type = 'frontend';
		$object_id   = 0;
		$object_name = '';
		$context     = array();

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post ) {
				$object_type = $post->post_type;
				$object_id   = $post->ID;
				$object_name = get_the_title( $post );
				$context     = array( 'view' => 'singular' );
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$object_type = $term->taxonomy;
				$object_id   = $term->term_id;
				$object_name = $term->name;
				$context     = array( 'view' => 'term_archive' );
			}
		} elseif ( is_post_type_archive() ) {
			$post_type   = get_query_var( 'post_type' );
			$object_type = is_array( $post_type ) ? implode( ',', array_map( 'sanitize_key', $post_type ) ) : sanitize_key( (string) $post_type );
			$object_name = $object_type;
			$context     = array( 'view' => 'post_type_archive' );
		} elseif ( is_search() ) {
			$object_type = 'search';
			$object_name = get_search_query( false );
			$context     = array( 'view' => 'search' );
		} elseif ( is_404() ) {
			$object_type = '404';
			$context     = array( 'view' => 'not_found' );
		} elseif ( is_home() || is_front_page() ) {
			$object_type = 'home';
			$object_name = get_bloginfo( 'name' );
			$context     = array( 'view' => 'home' );
		}

		self::log( 'frontend_view', $object_type, $object_id, $object_name, $context );
	}

	/**
	 * Log authenticated REST requests.
	 *
	 * @param mixed           $response Response.
	 * @param array           $handler Handler.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function log_rest_request( $response, $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		$status = is_wp_error( $response ) ? 'error' : null;
		if ( $response instanceof WP_HTTP_Response || $response instanceof WP_REST_Response ) {
			$status = $response->get_status();
		}

		self::log(
			'rest_request',
			'rest_route',
			0,
			$request->get_route(),
			array(
				'method' => $request->get_method(),
				'status' => $status,
			)
		);

		return $response;
	}

	/**
	 * Log post creation and updates.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post.
	 * @param bool    $update Whether update.
	 */
	public static function log_save_post( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		self::log(
			$update ? 'post_updated' : 'post_created',
			$post->post_type,
			$post_id,
			get_the_title( $post_id ),
			self::post_email_context( $post_id, array( 'status' => $post->post_status ) )
		);
	}

	/**
	 * Log status changes.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post Post.
	 */
	public static function log_post_status_transition( $new_status, $old_status, $post ) {
		if ( ! $post || $new_status === $old_status || wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		self::log(
			'post_status_changed',
			$post->post_type,
			$post->ID,
			get_the_title( $post->ID ),
			self::post_email_context(
				$post->ID,
				array(
				'from' => $old_status,
				'to'   => $new_status,
				)
			)
		);
	}

	/**
	 * Log trashed post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function log_trashed_post( $post_id ) {
		$post = get_post( $post_id );
		if ( $post ) {
			self::log( 'post_trashed', $post->post_type, $post_id, get_the_title( $post_id ), self::post_email_context( $post_id ) );
		}
	}

	/**
	 * Log untrashed post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function log_untrashed_post( $post_id ) {
		$post = get_post( $post_id );
		if ( $post ) {
			self::log( 'post_untrashed', $post->post_type, $post_id, get_the_title( $post_id ), self::post_email_context( $post_id ) );
		}
	}

	/**
	 * Log deleted post before it disappears.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function log_deleted_post( $post_id ) {
		$post = get_post( $post_id );
		if ( $post ) {
			self::log( 'post_deleted', $post->post_type, $post_id, $post->post_title, self::post_email_context( $post_id ) );
		}
	}

	/**
	 * Context used by email updates for post/page events.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $context Extra context.
	 * @return array
	 */
	private static function post_email_context( $post_id, $context = array() ) {
		$permalink = get_permalink( $post_id );
		if ( $permalink && ! is_wp_error( $permalink ) ) {
			$context['permalink'] = $permalink;
		}

		return $context;
	}

	/**
	 * Log attachment add.
	 *
	 * @param int $post_id Attachment ID.
	 */
	public static function log_added_attachment( $post_id ) {
		self::log( 'attachment_added', 'attachment', $post_id, get_the_title( $post_id ) );
	}

	/**
	 * Log attachment edit.
	 *
	 * @param int $post_id Attachment ID.
	 */
	public static function log_edited_attachment( $post_id ) {
		self::log( 'attachment_updated', 'attachment', $post_id, get_the_title( $post_id ) );
	}

	/**
	 * Log attachment delete.
	 *
	 * @param int $post_id Attachment ID.
	 */
	public static function log_deleted_attachment( $post_id ) {
		self::log( 'attachment_deleted', 'attachment', $post_id, get_the_title( $post_id ) );
	}

	/**
	 * Log created term.
	 *
	 * @param int    $term_id Term ID.
	 * @param int    $tt_id Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function log_created_term( $term_id, $tt_id, $taxonomy ) {
		$term = get_term( $term_id, $taxonomy );
		self::log( 'term_created', $taxonomy, $term_id, $term && ! is_wp_error( $term ) ? $term->name : '' );
	}

	/**
	 * Log edited term.
	 *
	 * @param int    $term_id Term ID.
	 * @param int    $tt_id Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function log_edited_term( $term_id, $tt_id, $taxonomy ) {
		$term = get_term( $term_id, $taxonomy );
		self::log( 'term_updated', $taxonomy, $term_id, $term && ! is_wp_error( $term ) ? $term->name : '' );
	}

	/**
	 * Log deleted term.
	 *
	 * @param int     $term_id Term ID.
	 * @param int     $tt_id Term taxonomy ID.
	 * @param string  $taxonomy Taxonomy.
	 * @param WP_Term $deleted_term Deleted term.
	 * @param array   $object_ids Object IDs.
	 */
	public static function log_deleted_term( $term_id, $tt_id, $taxonomy, $deleted_term, $object_ids ) {
		self::log(
			'term_deleted',
			$taxonomy,
			$term_id,
			$deleted_term instanceof WP_Term ? $deleted_term->name : '',
			array( 'affected_objects' => count( (array) $object_ids ) )
		);
	}

	/**
	 * Log inserted comment.
	 *
	 * @param int        $comment_id Comment ID.
	 * @param WP_Comment $comment Comment object.
	 */
	public static function log_inserted_comment( $comment_id, $comment ) {
		self::log( 'comment_created', 'comment', $comment_id, self::comment_label( $comment ) );
	}

	/**
	 * Log edited comment.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public static function log_edited_comment( $comment_id ) {
		self::log( 'comment_updated', 'comment', $comment_id, self::comment_label( get_comment( $comment_id ) ) );
	}

	/**
	 * Log comment status changes.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public static function log_comment_status( $comment_id ) {
		$hook = current_action();
		self::log( $hook, 'comment', $comment_id, self::comment_label( get_comment( $comment_id ) ) );
	}

	/**
	 * Log deleted comment.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public static function log_deleted_comment( $comment_id ) {
		self::log( 'comment_deleted', 'comment', $comment_id, self::comment_label( get_comment( $comment_id ) ) );
	}

	/**
	 * Log user registration.
	 *
	 * @param int $user_id User ID.
	 */
	public static function log_registered_user( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		self::log( 'user_created', 'user', $user_id, $user ? $user->user_login : '' );
	}

	/**
	 * Log user update.
	 *
	 * @param int     $user_id User ID.
	 * @param WP_User $old_user_data Old user data.
	 */
	public static function log_updated_user( $user_id, $old_user_data ) {
		$user = get_user_by( 'id', $user_id );
		self::log(
			'user_updated',
			'user',
			$user_id,
			$user ? $user->user_login : '',
			array( 'previous_login' => $old_user_data instanceof WP_User ? $old_user_data->user_login : '' )
		);
	}

	/**
	 * Log user deletion.
	 *
	 * @param int      $user_id User ID.
	 * @param int|null $reassign Reassign target.
	 * @param WP_User  $user Deleted user.
	 */
	public static function log_deleted_user( $user_id, $reassign, $user ) {
		self::log(
			'user_deleted',
			'user',
			$user_id,
			$user instanceof WP_User ? $user->user_login : '',
			array( 'reassign_to' => $reassign )
		);
	}

	/**
	 * Log role replacement.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role New role.
	 * @param array  $old_roles Old roles.
	 */
	public static function log_set_user_role( $user_id, $role, $old_roles ) {
		$user = get_user_by( 'id', $user_id );
		self::log(
			'user_role_set',
			'user',
			$user_id,
			$user ? $user->user_login : '',
			array(
				'new_role'  => $role,
				'old_roles' => array_values( (array) $old_roles ),
			)
		);
	}

	/**
	 * Log added user role.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role Role.
	 */
	public static function log_added_user_role( $user_id, $role ) {
		$user = get_user_by( 'id', $user_id );
		self::log( 'user_role_added', 'user', $user_id, $user ? $user->user_login : '', array( 'role' => $role ) );
	}

	/**
	 * Log removed user role.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role Role.
	 */
	public static function log_removed_user_role( $user_id, $role ) {
		$user = get_user_by( 'id', $user_id );
		self::log( 'user_role_removed', 'user', $user_id, $user ? $user->user_login : '', array( 'role' => $role ) );
	}

	/**
	 * Log option add.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value Value.
	 */
	public static function log_added_option( $option, $value ) {
		self::log_option_change( 'option_added', $option );
	}

	/**
	 * Log option update.
	 *
	 * @param string $option Option name.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $value New value.
	 */
	public static function log_updated_option( $option, $old_value, $value ) {
		self::log_option_change( 'option_updated', $option );
	}

	/**
	 * Log option deletion.
	 *
	 * @param string $option Option name.
	 */
	public static function log_deleted_option( $option ) {
		self::log_option_change( 'option_deleted', $option );
	}

	/**
	 * Log activated plugin.
	 *
	 * @param string $plugin Plugin file.
	 * @param bool   $network_wide Network-wide.
	 */
	public static function log_activated_plugin( $plugin, $network_wide ) {
		self::log( 'plugin_activated', 'plugin', 0, $plugin, array( 'network_wide' => (bool) $network_wide ) );
	}

	/**
	 * Log deactivated plugin.
	 *
	 * @param string $plugin Plugin file.
	 * @param bool   $network_wide Network-wide.
	 */
	public static function log_deactivated_plugin( $plugin, $network_wide ) {
		self::log( 'plugin_deactivated', 'plugin', 0, $plugin, array( 'network_wide' => (bool) $network_wide ) );
	}

	/**
	 * Log theme switch.
	 *
	 * @param string   $new_name New theme name.
	 * @param WP_Theme $new_theme New theme.
	 * @param WP_Theme $old_theme Old theme.
	 */
	public static function log_switched_theme( $new_name, $new_theme, $old_theme ) {
		self::log(
			'theme_switched',
			'theme',
			0,
			$new_name,
			array(
				'new_stylesheet' => $new_theme instanceof WP_Theme ? $new_theme->get_stylesheet() : '',
				'old_stylesheet' => $old_theme instanceof WP_Theme ? $old_theme->get_stylesheet() : '',
			)
		);
	}

	/**
	 * Log update/install operations.
	 *
	 * @param WP_Upgrader $upgrader Upgrader.
	 * @param array       $hook_extra Hook context.
	 */
	public static function log_upgrader_process( $upgrader, $hook_extra ) {
		self::log(
			'upgrader_process_complete',
			isset( $hook_extra['type'] ) ? sanitize_key( $hook_extra['type'] ) : 'upgrader',
			0,
			isset( $hook_extra['action'] ) ? sanitize_key( $hook_extra['action'] ) : '',
			self::sanitize_context( $hook_extra )
		);
	}

	/**
	 * Insert a log row for the current user.
	 *
	 * @param string $action Action.
	 * @param string $object_type Object type.
	 * @param int    $object_id Object ID.
	 * @param string $object_name Object name.
	 * @param array  $context Context.
	 */
	private static function log( $action, $object_type = '', $object_id = 0, $object_name = '', $context = array() ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );
		self::log_as_user( $user, $action, $object_type, $object_id, $object_name, $context );
	}

	/**
	 * Insert a log row for a specific actor.
	 *
	 * @param WP_User|false|null $user User.
	 * @param string             $action Action.
	 * @param string             $object_type Object type.
	 * @param int                $object_id Object ID.
	 * @param string             $object_name Object name.
	 * @param array              $context Context.
	 */
	private static function log_as_user( $user, $action, $object_type = '', $object_id = 0, $object_name = '', $context = array() ) {
		if ( ! $user instanceof WP_User || ! self::should_track_user( $user ) || self::$writing ) {
			return;
		}

		global $wpdb;

		$context = self::sanitize_context( $context );
		$roles   = array_values( (array) $user->roles );
		$data    = array(
			'occurred_at'    => current_time( 'mysql', true ),
			'user_id'        => (int) $user->ID,
			'user_login'     => $user->user_login,
			'display_name'   => $user->display_name,
			'roles'          => implode( ',', $roles ),
			'action'         => sanitize_key( $action ),
			'object_type'    => sanitize_key( $object_type ),
			'object_id'      => $object_id ? absint( $object_id ) : null,
			'object_name'    => sanitize_text_field( (string) $object_name ),
			'request_method' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
			'request_uri'    => self::sanitized_request_uri(),
			'ip_hash'        => self::ip_hash(),
			'context'        => ! empty( $context ) ? wp_json_encode( $context, JSON_UNESCAPED_SLASHES ) : null,
		);

		$formats = array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' );

		self::$writing = true;
		try {
			$inserted = $wpdb->insert( self::table_name(), $data, $formats );
		} finally {
			self::$writing = false;
		}

		if ( false === $inserted ) {
			return;
		}

		$data['id'] = (int) $wpdb->insert_id;
		self::maybe_send_or_queue_email_update( $data );
	}

	/**
	 * Decide if a user should be tracked.
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	private static function should_track_user( $user ) {
		$roles = array_values( (array) $user->roles );
		if ( empty( $roles ) ) {
			return false;
		}

		$track = ! empty( array_diff( $roles, array( 'subscriber' ) ) );

		/**
		 * Filter whether a user activity should be tracked.
		 *
		 * @param bool    $track Whether to track.
		 * @param WP_User $user User.
		 * @param array   $roles User roles.
		 */
		return (bool) apply_filters( 'csa_wp_user_tracker_should_track_user', $track, $user, $roles );
	}

	/**
	 * Get capability needed to view/export logs.
	 *
	 * @return string
	 */
	private static function admin_capability() {
		return (string) apply_filters( 'csa_wp_user_tracker_admin_capability', 'manage_options' );
	}

	/**
	 * Default email notification settings.
	 *
	 * @return array
	 */
	private static function default_email_settings() {
		$admin_email = sanitize_email( (string) get_option( 'admin_email' ) );

		return array(
			'enabled'    => false,
			'recipients' => is_email( $admin_email ) ? array( $admin_email ) : array(),
			'post_types' => array( 'post', 'page' ),
			'scope'      => 'any',
			'actor_user' => '',
			'roles'      => array(),
			'cadence'    => 'immediate',
		);
	}

	/**
	 * Get normalized email notification settings.
	 *
	 * @return array
	 */
	private static function email_settings() {
		$settings = get_option( self::OPTION_EMAIL_SETTINGS, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return self::sanitize_email_settings( wp_parse_args( $settings, self::default_email_settings() ) );
	}

	/**
	 * Sanitize email notification settings.
	 *
	 * @param array $raw Raw settings.
	 * @return array
	 */
	private static function sanitize_email_settings( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();

		$recipients = array();
		if ( isset( $raw['csa_email_recipients'] ) ) {
			$recipient_source = $raw['csa_email_recipients'];
		} elseif ( isset( $raw['recipients'] ) ) {
			$recipient_source = $raw['recipients'];
		} else {
			$recipient_source = array();
		}

		if ( is_array( $recipient_source ) ) {
			$recipient_source = implode( ',', $recipient_source );
		}

		foreach ( preg_split( '/[\s,;]+/', (string) $recipient_source ) as $email ) {
			$email = sanitize_email( $email );
			if ( is_email( $email ) ) {
				$recipients[] = $email;
			}
		}
		$recipients = array_values( array_unique( $recipients ) );

		$post_types_source = isset( $raw['csa_email_post_types'] ) ? $raw['csa_email_post_types'] : ( isset( $raw['post_types'] ) ? $raw['post_types'] : array() );
		$post_types        = array();
		foreach ( (array) $post_types_source as $post_type ) {
			$post_type = sanitize_key( $post_type );
			if ( in_array( $post_type, array( 'post', 'page' ), true ) ) {
				$post_types[] = $post_type;
			}
		}
		$post_types = array_values( array_unique( $post_types ) );
		if ( empty( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}

		$scope = isset( $raw['csa_email_scope'] ) ? sanitize_key( $raw['csa_email_scope'] ) : ( isset( $raw['scope'] ) ? sanitize_key( $raw['scope'] ) : 'any' );
		if ( ! in_array( $scope, array( 'any', 'user', 'roles' ), true ) ) {
			$scope = 'any';
		}

		$actor_user = isset( $raw['csa_email_actor_user'] ) ? sanitize_text_field( $raw['csa_email_actor_user'] ) : ( isset( $raw['actor_user'] ) ? sanitize_text_field( $raw['actor_user'] ) : '' );
		$actor_user = self::resolve_actor_user_match( $actor_user );

		$roles_source = isset( $raw['csa_email_roles'] ) ? $raw['csa_email_roles'] : ( isset( $raw['roles'] ) ? $raw['roles'] : array() );
		$roles        = array();
		foreach ( (array) $roles_source as $role ) {
			$role = sanitize_key( $role );
			if ( '' !== $role ) {
				$roles[] = $role;
			}
		}
		$roles = array_values( array_unique( $roles ) );

		$cadence = isset( $raw['csa_email_cadence'] ) ? sanitize_key( $raw['csa_email_cadence'] ) : ( isset( $raw['cadence'] ) ? sanitize_key( $raw['cadence'] ) : 'immediate' );
		if ( ! in_array( $cadence, array( 'immediate', 'daily', 'weekly' ), true ) ) {
			$cadence = 'immediate';
		}

		return array(
			'enabled'    => ! empty( $raw['csa_email_enabled'] ) || ! empty( $raw['enabled'] ),
			'recipients' => $recipients,
			'post_types' => $post_types,
			'scope'      => $scope,
			'actor_user' => $actor_user,
			'roles'      => $roles,
			'cadence'    => $cadence,
		);
	}

	/**
	 * Resolve a user filter to a stable user ID when possible.
	 *
	 * @param string $actor_user User ID, login, or email.
	 * @return string
	 */
	private static function resolve_actor_user_match( $actor_user ) {
		$actor_user = trim( sanitize_text_field( (string) $actor_user ) );
		if ( '' === $actor_user || ctype_digit( $actor_user ) ) {
			return $actor_user;
		}

		$user = get_user_by( 'login', $actor_user );
		if ( ! $user && is_email( $actor_user ) ) {
			$user = get_user_by( 'email', $actor_user );
		}

		return $user instanceof WP_User ? (string) $user->ID : $actor_user;
	}

	/**
	 * Add the weekly recurrence used by email digests.
	 *
	 * @param array $schedules Cron schedules.
	 * @return array
	 */
	public static function add_cron_schedules( $schedules ) {
		if ( empty( $schedules[ self::EMAIL_WEEKLY_RECURRENCE ] ) ) {
			$schedules[ self::EMAIL_WEEKLY_RECURRENCE ] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'csa-wp-user-tracker' ),
			);
		}

		return $schedules;
	}

	/**
	 * Ensure the email digest schedule matches current settings.
	 */
	public static function ensure_email_digest_schedule() {
		$settings     = self::email_settings();
		$needs_digest = $settings['enabled'] && in_array( $settings['cadence'], array( 'daily', 'weekly' ), true );

		if ( ! $needs_digest ) {
			if ( wp_next_scheduled( self::EMAIL_DIGEST_HOOK ) ) {
				wp_clear_scheduled_hook( self::EMAIL_DIGEST_HOOK );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::EMAIL_DIGEST_HOOK ) ) {
			self::schedule_email_digest( $settings['cadence'] );
		}
	}

	/**
	 * Clear and recreate the email digest schedule.
	 */
	private static function reschedule_email_digest() {
		wp_clear_scheduled_hook( self::EMAIL_DIGEST_HOOK );
		self::ensure_email_digest_schedule();
	}

	/**
	 * Schedule the email digest cron event.
	 *
	 * @param string $cadence Daily or weekly.
	 */
	private static function schedule_email_digest( $cadence ) {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedules' ) );

		$recurrence = 'weekly' === $cadence ? self::EMAIL_WEEKLY_RECURRENCE : 'daily';
		$delay      = 'weekly' === $cadence ? WEEK_IN_SECONDS : DAY_IN_SECONDS;
		wp_schedule_event( time() + $delay, $recurrence, self::EMAIL_DIGEST_HOOK );
	}

	/**
	 * Send an email digest from WP-Cron.
	 */
	public static function send_scheduled_email_digest() {
		self::send_queued_email_digest();
	}

	/**
	 * Maybe notify for a matching content update.
	 *
	 * @param array $row Log row data.
	 */
	private static function maybe_send_or_queue_email_update( $row ) {
		$settings = self::email_settings();
		if ( ! $settings['enabled'] || empty( $settings['recipients'] ) || ! self::log_row_matches_email_settings( $row, $settings ) ) {
			return;
		}

		if ( 'immediate' === $settings['cadence'] ) {
			self::send_email_update( array( $row ), $settings, false );
			return;
		}

		if ( ! empty( $row['id'] ) ) {
			self::append_email_queue( (int) $row['id'] );
		}
	}

	/**
	 * Check if a log row matches the email settings.
	 *
	 * @param array|object $row Log row.
	 * @param array        $settings Email settings.
	 * @return bool
	 */
	private static function log_row_matches_email_settings( $row, $settings ) {
		$row = self::normalize_log_row( $row );

		if ( ! in_array( $row['action'], array_keys( self::email_content_actions() ), true ) || ! in_array( $row['object_type'], $settings['post_types'], true ) ) {
			return false;
		}

		if ( 'any' === $settings['scope'] ) {
			return true;
		}

		if ( 'user' === $settings['scope'] ) {
			$actor_user = strtolower( trim( (string) $settings['actor_user'] ) );
			if ( '' === $actor_user ) {
				return false;
			}

			if ( ctype_digit( $actor_user ) ) {
				return (int) $actor_user === (int) $row['user_id'];
			}

			return $actor_user === strtolower( $row['user_login'] ) || $actor_user === strtolower( $row['display_name'] );
		}

		if ( 'roles' === $settings['scope'] ) {
			$row_roles = array_filter( array_map( 'trim', explode( ',', $row['roles'] ) ) );
			return ! empty( array_intersect( $row_roles, $settings['roles'] ) );
		}

		return false;
	}

	/**
	 * Content actions that can trigger email updates.
	 *
	 * @return array
	 */
	private static function email_content_actions() {
		return array(
			'post_created'        => __( 'created', 'csa-wp-user-tracker' ),
			'post_updated'        => __( 'updated', 'csa-wp-user-tracker' ),
			'post_status_changed' => __( 'status changed', 'csa-wp-user-tracker' ),
			'post_trashed'        => __( 'trashed', 'csa-wp-user-tracker' ),
			'post_untrashed'      => __( 'restored', 'csa-wp-user-tracker' ),
			'post_deleted'        => __( 'deleted', 'csa-wp-user-tracker' ),
		);
	}

	/**
	 * Human label for an email content action.
	 *
	 * @param string $action Log action.
	 * @return string
	 */
	private static function email_action_label( $action ) {
		$actions = self::email_content_actions();
		return isset( $actions[ $action ] ) ? $actions[ $action ] : $action;
	}

	/**
	 * Get queued log IDs for digest emails.
	 *
	 * @return array
	 */
	private static function email_queue() {
		$queue = get_option( self::OPTION_EMAIL_QUEUE, array() );
		if ( ! is_array( $queue ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', $queue ) ) );
	}

	/**
	 * Add a log ID to the digest queue.
	 *
	 * @param int $log_id Log ID.
	 */
	private static function append_email_queue( $log_id ) {
		$log_id = absint( $log_id );
		if ( ! $log_id ) {
			return;
		}

		$queue   = self::email_queue();
		$queue[] = $log_id;
		$queue   = array_values( array_unique( array_filter( array_map( 'absint', $queue ) ) ) );
		$queue   = array_slice( $queue, -1 * self::EMAIL_QUEUE_LIMIT );

		self::write_option( self::OPTION_EMAIL_QUEUE, $queue );
		self::ensure_email_digest_schedule();
	}

	/**
	 * Send pending digest email rows.
	 *
	 * @return array
	 */
	private static function send_queued_email_digest() {
		$settings = self::email_settings();
		$queue    = self::email_queue();

		if ( empty( $queue ) || ! $settings['enabled'] || empty( $settings['recipients'] ) || 'immediate' === $settings['cadence'] ) {
			return array(
				'sent'   => 0,
				'failed' => false,
			);
		}

		$rows = self::get_log_rows_by_ids( $queue );
		if ( empty( $rows ) ) {
			self::write_option( self::OPTION_EMAIL_QUEUE, array() );
			return array(
				'sent'   => 0,
				'failed' => false,
			);
		}

		$matched = array();
		foreach ( $rows as $row ) {
			if ( self::log_row_matches_email_settings( $row, $settings ) ) {
				$matched[] = self::normalize_log_row( $row );
			}
		}

		if ( empty( $matched ) ) {
			self::remove_email_queue_ids( $queue );
			return array(
				'sent'   => 0,
				'failed' => false,
			);
		}

		if ( ! self::send_email_update( $matched, $settings, true ) ) {
			return array(
				'sent'   => 0,
				'failed' => true,
			);
		}

		self::remove_email_queue_ids( $queue );
		self::write_option( self::OPTION_EMAIL_LAST_SENT, current_time( 'mysql', true ) );

		return array(
			'sent'   => count( $matched ),
			'failed' => false,
		);
	}

	/**
	 * Remove sent or stale IDs from the digest queue.
	 *
	 * @param array $ids Log IDs.
	 */
	private static function remove_email_queue_ids( $ids ) {
		$remove = array_map( 'absint', (array) $ids );
		$queue  = array_values( array_diff( self::email_queue(), $remove ) );
		self::write_option( self::OPTION_EMAIL_QUEUE, $queue );
	}

	/**
	 * Load log rows by ID.
	 *
	 * @param array $ids Log IDs.
	 * @return array
	 */
	private static function get_log_rows_by_ids( $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$id_sql = implode( ',', $ids );
		return $wpdb->get_results( 'SELECT * FROM ' . self::table_name() . " WHERE id IN ({$id_sql}) ORDER BY occurred_at ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Send an immediate or digest email update.
	 *
	 * @param array $rows Log rows.
	 * @param array $settings Email settings.
	 * @param bool  $digest Whether this is a digest.
	 * @return bool
	 */
	private static function send_email_update( $rows, $settings, $digest ) {
		$rows = array_map( array( __CLASS__, 'normalize_log_row' ), $rows );
		$rows = array_filter( $rows );
		if ( empty( $rows ) ) {
			return false;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$count     = count( $rows );
		$subject   = $digest
			? sprintf(
				/* translators: 1: site name, 2: count */
				__( '[%1$s] CSA user tracker digest (%2$d changes)', 'csa-wp-user-tracker' ),
				$site_name,
				$count
			)
			: sprintf(
				/* translators: 1: site name, 2: object type, 3: action label, 4: object name */
				__( '[%1$s] %2$s %3$s: %4$s', 'csa-wp-user-tracker' ),
				$site_name,
				ucfirst( $rows[0]['object_type'] ),
				self::email_action_label( $rows[0]['action'] ),
				$rows[0]['object_name']
			);

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		$sent = (bool) wp_mail( $settings['recipients'], $subject, self::build_email_body( $rows, $digest ), $headers );
		if ( $sent ) {
			self::log_email_update_sent( $rows, $settings, $digest );
		}

		return $sent;
	}

	/**
	 * Log a successful email update delivery.
	 *
	 * @param array $rows Log rows included in the email.
	 * @param array $settings Email settings.
	 * @param bool  $digest Whether this is a digest.
	 */
	private static function log_email_update_sent( $rows, $settings, $digest ) {
		$first_row = reset( $rows );
		if ( ! is_array( $first_row ) ) {
			return;
		}

		$user_id = $digest ? get_current_user_id() : absint( $first_row['user_id'] );
		if ( ! $user_id ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$source_log_ids = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['id'] ) ) {
				$source_log_ids[] = absint( $row['id'] );
			}
		}

		self::log_as_user(
			$user,
			'email_update_sent',
			'email_update',
			0,
			$digest ? __( 'Digest email sent', 'csa-wp-user-tracker' ) : __( 'Once triggered email sent', 'csa-wp-user-tracker' ),
			array(
				'delivery'           => $digest ? 'digest' : 'once_triggered',
				'matched_count'      => count( $rows ),
				'recipient_count'    => count( $settings['recipients'] ),
				'source_log_ids'     => $source_log_ids,
				'source_action'      => $first_row['action'],
				'source_object_type' => $first_row['object_type'],
				'source_object_id'   => $first_row['object_id'],
				'source_object_name' => $first_row['object_name'],
			)
		);
	}

	/**
	 * Send a test email to the saved notification recipients.
	 *
	 * @param array $settings Email settings.
	 * @return bool
	 */
	private static function send_test_email_update( $settings ) {
		if ( empty( $settings['recipients'] ) ) {
			return false;
		}

		$site_name    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$current_user = wp_get_current_user();
		$body         = array(
			__( 'This is a test email from CSA WP User Tracker.', 'csa-wp-user-tracker' ),
			'',
			sprintf( __( 'Site: %s', 'csa-wp-user-tracker' ), home_url( '/' ) ),
			sprintf( __( 'Sent by: %s', 'csa-wp-user-tracker' ), $current_user instanceof WP_User && $current_user->user_login ? $current_user->user_login : __( 'Unknown user', 'csa-wp-user-tracker' ) ),
			sprintf( __( 'Send timing: %s', 'csa-wp-user-tracker' ), $settings['cadence'] ),
			sprintf( __( 'Watching: %s', 'csa-wp-user-tracker' ), implode( ', ', $settings['post_types'] ) ),
			'',
			__( 'If you received this message, WordPress mail is working for the saved recipients.', 'csa-wp-user-tracker' ),
		);
		$subject      = sprintf(
			/* translators: %s: site name */
			__( '[%s] CSA WP User Tracker test email', 'csa-wp-user-tracker' ),
			$site_name
		);

		return (bool) wp_mail( $settings['recipients'], $subject, implode( "\n", $body ), array( 'Content-Type: text/plain; charset=UTF-8' ) );
	}

	/**
	 * Build a plain-text email body.
	 *
	 * @param array $rows Log rows.
	 * @param bool  $digest Whether this is a digest.
	 * @return string
	 */
	private static function build_email_body( $rows, $digest ) {
		$lines   = array();
		$lines[] = sprintf(
			/* translators: %d: update count */
			_n( '%d content change matched your CSA WP User Tracker rule.', '%d content changes matched your CSA WP User Tracker rule.', count( $rows ), 'csa-wp-user-tracker' ),
			count( $rows )
		);
		$lines[] = sprintf( __( 'Site: %s', 'csa-wp-user-tracker' ), home_url( '/' ) );
		$lines[] = sprintf( __( 'Delivery: %s', 'csa-wp-user-tracker' ), $digest ? __( 'digest', 'csa-wp-user-tracker' ) : __( 'once triggered', 'csa-wp-user-tracker' ) );
		$lines[] = '';

		foreach ( $rows as $row ) {
			$object_type = self::admin_object_type_label( $row['object_type'] );
			$object_name = trim( wp_specialchars_decode( $row['object_name'], ENT_QUOTES ) );
			$object_url  = self::email_object_url( $row );

			$lines[] = sprintf(
				/* translators: 1: object type, 2: object title */
				__( '%1$s title: %2$s', 'csa-wp-user-tracker' ),
				$object_type,
				'' !== $object_name ? $object_name : __( '(no title)', 'csa-wp-user-tracker' )
			);
			if ( $object_url ) {
				$lines[] = sprintf(
					/* translators: 1: object type, 2: public URL */
					__( '%1$s link: %2$s', 'csa-wp-user-tracker' ),
					$object_type,
					$object_url
				);
			}
			$lines[] = sprintf( __( 'Action: %s', 'csa-wp-user-tracker' ), self::email_action_label( $row['action'] ) );
			$lines[] = sprintf( __( 'Time: %s', 'csa-wp-user-tracker' ), mysql2date( 'Y-m-d H:i:s', $row['occurred_at'], true ) );
			$lines[] = sprintf( __( 'Updated by: %1$s (%2$s #%3$d)', 'csa-wp-user-tracker' ), $row['display_name'] ? $row['display_name'] : $row['user_login'], $row['user_login'], absint( $row['user_id'] ) );
			$lines[] = sprintf( __( 'Roles: %s', 'csa-wp-user-tracker' ), $row['roles'] );

			$edit_url = self::edit_object_url( $row );
			if ( $edit_url ) {
				$lines[] = sprintf( __( 'Edit in WordPress: %s', 'csa-wp-user-tracker' ), $edit_url );
			}

			if ( $row['request_uri'] ) {
				$lines[] = sprintf( __( 'Request: %s', 'csa-wp-user-tracker' ), home_url( $row['request_uri'] ) );
			}

			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Get the public URL for a tracked post/page row.
	 *
	 * @param array $row Log row.
	 * @return string
	 */
	private static function email_object_url( $row ) {
		if ( ! in_array( $row['object_type'], array( 'post', 'page' ), true ) || empty( $row['object_id'] ) ) {
			return '';
		}

		$context = self::context_to_array( isset( $row['context'] ) ? $row['context'] : '' );
		if ( ! empty( $context['permalink'] ) ) {
			return esc_url_raw( $context['permalink'] );
		}

		$permalink = get_permalink( absint( $row['object_id'] ) );
		return $permalink && ! is_wp_error( $permalink ) ? esc_url_raw( $permalink ) : '';
	}

	/**
	 * Get an edit URL for the tracked object.
	 *
	 * @param array $row Log row.
	 * @return string
	 */
	private static function edit_object_url( $row ) {
		if ( ! in_array( $row['object_type'], array( 'post', 'page' ), true ) || empty( $row['object_id'] ) ) {
			return '';
		}

		$url = get_edit_post_link( absint( $row['object_id'] ), '' );
		return $url ? esc_url_raw( $url ) : '';
	}

	/**
	 * Normalize row arrays and objects.
	 *
	 * @param array|object $row Log row.
	 * @return array
	 */
	private static function normalize_log_row( $row ) {
		$row = is_object( $row ) ? get_object_vars( $row ) : (array) $row;

		return wp_parse_args(
			$row,
			array(
				'id'             => 0,
				'occurred_at'    => '',
				'user_id'        => 0,
				'user_login'     => '',
				'display_name'   => '',
				'roles'          => '',
				'action'         => '',
				'object_type'    => '',
				'object_id'      => 0,
				'object_name'    => '',
				'request_method' => '',
				'request_uri'    => '',
				'ip_hash'        => '',
				'context'        => '',
			)
		);
	}

	/**
	 * Log an option name, not its value.
	 *
	 * @param string $action Action.
	 * @param string $option Option.
	 */
	private static function log_option_change( $action, $option ) {
		if ( self::is_ignored_option( $option ) ) {
			return;
		}

		self::log( $action, 'option', 0, $option );
	}

	/**
	 * Determine noisy/internal options to skip.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private static function is_ignored_option( $option ) {
		$ignored_exact = array(
			self::OPTION_VERSION,
			self::LEGACY_OPTION_VERSION,
			self::OPTION_EMAIL_SETTINGS,
			self::OPTION_EMAIL_QUEUE,
			self::OPTION_EMAIL_LAST_SENT,
			'cron',
			'rewrite_rules',
			'recently_edited',
			'auto_core_update_failed',
		);
		$ignored_prefixes = array(
			'_transient_',
			'_site_transient_',
			'_wp_session_',
		);

		if ( in_array( $option, $ignored_exact, true ) ) {
			return true;
		}

		foreach ( $ignored_prefixes as $prefix ) {
			if ( 0 === strpos( $option, $prefix ) ) {
				return true;
			}
		}

		return (bool) apply_filters( 'csa_wp_user_tracker_ignore_option', false, $option );
	}

	/**
	 * Comment label.
	 *
	 * @param WP_Comment|null|false $comment Comment.
	 * @return string
	 */
	private static function comment_label( $comment ) {
		if ( ! $comment instanceof WP_Comment ) {
			return '';
		}

		return sprintf(
			'%s on post #%d',
			$comment->comment_author,
			(int) $comment->comment_post_ID
		);
	}

	/**
	 * Sanitize request URI with sensitive values redacted.
	 *
	 * @return string
	 */
	private static function sanitized_request_uri() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$uri   = wp_unslash( $_SERVER['REQUEST_URI'] );
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$path  = isset( $parts['path'] ) ? $parts['path'] : '';
		$query = array();

		if ( ! empty( $parts['query'] ) ) {
			wp_parse_str( $parts['query'], $query );
			foreach ( $query as $key => $value ) {
				if ( preg_match( '/(pass|pwd|nonce|token|secret|key|auth|session)/i', (string) $key ) ) {
					$query[ $key ] = '[redacted]';
				} else {
					$query[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '[complex]';
				}
			}
		}

		$out = $path;
		if ( ! empty( $query ) ) {
			$out .= '?' . http_build_query( $query, '', '&' );
		}

		return esc_url_raw( $out );
	}

	/**
	 * Hash the client IP instead of storing raw IP.
	 *
	 * @return string
	 */
	private static function ip_hash() {
		$ip = '';

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$ip        = trim( $forwarded[0] );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = wp_unslash( $_SERVER['REMOTE_ADDR'] );
		}

		if ( '' === $ip ) {
			return '';
		}

		return hash_hmac( 'sha256', sanitize_text_field( $ip ), wp_salt( 'auth' ) );
	}

	/**
	 * Recursively sanitize context data.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function sanitize_context( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && preg_match( '/(pass|pwd|nonce|token|secret|key|auth|session|cookie)/i', $key ) ) {
					$out[ sanitize_key( $key ) ] = '[redacted]';
					continue;
				}
				$out[ is_int( $key ) ? $key : sanitize_key( $key ) ] = self::sanitize_context( $item );
			}
			return $out;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		if ( is_object( $value ) ) {
			return get_class( $value );
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Pretty-print stored JSON when possible.
	 *
	 * @param string $json JSON.
	 * @return string
	 */
	private static function pretty_json( $json ) {
		$decoded = json_decode( $json, true );
		if ( null === $decoded ) {
			return $json;
		}

		return (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Get sanitized admin filters.
	 *
	 * @return array
	 */
	private static function get_admin_filters() {
		return array(
			'user'        => isset( $_GET['activity_user'] ) ? sanitize_text_field( wp_unslash( $_GET['activity_user'] ) ) : '',
			'action'      => isset( $_GET['activity_action'] ) ? sanitize_key( wp_unslash( $_GET['activity_action'] ) ) : '',
			'object_type' => isset( $_GET['activity_object_type'] ) ? sanitize_key( wp_unslash( $_GET['activity_object_type'] ) ) : '',
			'from'        => isset( $_GET['activity_from'] ) ? sanitize_text_field( wp_unslash( $_GET['activity_from'] ) ) : '',
			'to'          => isset( $_GET['activity_to'] ) ? sanitize_text_field( wp_unslash( $_GET['activity_to'] ) ) : '',
			'focus_content_edits' => isset( $_GET['activity_focus_content_edits'] ) && '1' === wp_unslash( $_GET['activity_focus_content_edits'] ),
		);
	}

	/**
	 * Build filtered WHERE SQL.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private static function build_where_sql( $filters ) {
		global $wpdb;

		$where = array( '1=1' );

		if ( '' !== $filters['user'] ) {
			if ( ctype_digit( $filters['user'] ) ) {
				$where[] = $wpdb->prepare( 'user_id = %d', absint( $filters['user'] ) );
			} else {
				$like    = '%' . $wpdb->esc_like( $filters['user'] ) . '%';
				$where[] = $wpdb->prepare( '(user_login LIKE %s OR display_name LIKE %s)', $like, $like );
			}
		}

		if ( '' !== $filters['action'] ) {
			$where[] = $wpdb->prepare( 'action = %s', $filters['action'] );
		}

		if ( '' !== $filters['object_type'] ) {
			$where[] = $wpdb->prepare( 'object_type = %s', $filters['object_type'] );
		}

		if ( ! empty( $filters['focus_content_edits'] ) ) {
			$actions      = self::admin_focus_actions();
			$placeholders = implode( ',', array_fill( 0, count( $actions ), '%s' ) );
			$where[]      = $wpdb->prepare( "object_type IN ('post','page') AND action IN ({$placeholders})", $actions ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['from'] ) ) {
			$where[] = $wpdb->prepare( 'occurred_at >= %s', $filters['from'] . ' 00:00:00' );
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['to'] ) ) {
			$where[] = $wpdb->prepare( 'occurred_at <= %s', $filters['to'] . ' 23:59:59' );
		}

		return array( 'sql' => 'WHERE ' . implode( ' AND ', $where ) );
	}

	/**
	 * Schedule cleanup event.
	 */
	private static function schedule_cleanup() {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Unschedule all cleanup events for a hook.
	 *
	 * @param string $hook Cron hook.
	 */
	private static function unschedule_cleanup_hook( $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	/**
	 * Delete old log rows.
	 */
	public static function cleanup_old_logs() {
		global $wpdb;

		$retention_days = (int) apply_filters( 'csa_wp_user_tracker_retention_days', self::DEFAULT_RETENTION );
		if ( $retention_days < 1 ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table_name() . ' WHERE occurred_at < %s', $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Write an option without generating a log row.
	 *
	 * @param string $option Option.
	 * @param mixed  $value Value.
	 */
	private static function write_option( $option, $value ) {
		self::$writing = true;
		try {
			update_option( $option, $value, false );
		} finally {
			self::$writing = false;
		}
	}

	/**
	 * Delete an option without generating a log row.
	 *
	 * @param string $option Option.
	 */
	private static function delete_option_without_log( $option ) {
		self::$writing = true;
		try {
			delete_option( $option );
		} finally {
			self::$writing = false;
		}
	}
}

register_activation_hook( __FILE__, array( 'CSA_WP_User_Tracker', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CSA_WP_User_Tracker', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'CSA_WP_User_Tracker_GitHub_Updater', 'init' ) );
add_action( 'plugins_loaded', array( 'CSA_WP_User_Tracker', 'init' ) );
