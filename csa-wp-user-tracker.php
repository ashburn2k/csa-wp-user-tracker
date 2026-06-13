<?php
/**
 * Plugin Name: CSA WP User Tracker
 * Plugin URI: https://github.com/ashburn2k/csa-wp-user-tracker
 * Description: Tracks activity for logged-in WordPress users whose roles are not limited to subscriber.
 * Version: 0.1.2
 * Author: Hui Zhang
 * Text Domain: esnet-activity-tracker
 * Update URI: https://github.com/ashburn2k/csa-wp-user-tracker
 *
 * @package ESnet_Activity_Tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ESNET_ACTIVITY_TRACKER_VERSION', '0.1.2' );
define( 'ESNET_ACTIVITY_TRACKER_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-csa-wp-user-tracker-github-updater.php';

/**
 * Role-aware activity logger.
 */
final class ESnet_Activity_Tracker {
	const OPTION_VERSION      = 'esnet_activity_tracker_version';
	const CLEANUP_HOOK        = 'esnet_activity_tracker_daily_cleanup';
	const DEFAULT_RETENTION   = 180;
	const EXPORT_NONCE_ACTION = 'esnet_activity_tracker_export';
	const UPDATE_NONCE_ACTION = 'csa_wp_user_tracker_refresh_update';

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
		add_action( 'admin_init', array( __CLASS__, 'maybe_refresh_update_status' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_log_ajax_request' ), 1 );
		add_action( 'current_screen', array( __CLASS__, 'log_admin_screen' ) );
		add_action( 'template_redirect', array( __CLASS__, 'log_frontend_view' ), 999 );
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup_old_logs' ) );

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
		self::write_option( self::OPTION_VERSION, ESNET_ACTIVITY_TRACKER_VERSION );
		self::schedule_cleanup();
	}

	/**
	 * Unschedule cleanup on deactivation. Logs are intentionally retained.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::CLEANUP_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CLEANUP_HOOK );
		}
	}

	/**
	 * Repair/create tables when code is deployed before activation routines run.
	 */
	public static function maybe_upgrade() {
		$installed_version = get_option( self::OPTION_VERSION );

		if ( ESNET_ACTIVITY_TRACKER_VERSION !== $installed_version ) {
			self::activate();
		}
	}

	/**
	 * Get the plugin table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'esnet_activity_log';
	}

	/**
	 * Add admin UI.
	 */
	public static function register_admin_page() {
		add_management_page(
			__( 'CSA WP User Tracker', 'esnet-activity-tracker' ),
			__( 'CSA WP User Tracker', 'esnet-activity-tracker' ),
			self::admin_capability(),
			'esnet-activity-log',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Render admin log table.
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( self::admin_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view activity logs.', 'esnet-activity-tracker' ) );
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
		$base_url    = remove_query_arg( array( 'paged', 'esnet_activity_export', '_wpnonce' ) );
		$export_url  = wp_nonce_url(
			add_query_arg( 'esnet_activity_export', '1', $base_url ),
			self::EXPORT_NONCE_ACTION
		);
		$update_status = class_exists( 'CSA_WP_User_Tracker_GitHub_Updater' ) ? CSA_WP_User_Tracker_GitHub_Updater::diagnostic_status() : array();
		$refresh_url   = wp_nonce_url(
			add_query_arg( 'csa_tracker_refresh_update', '1', menu_page_url( 'esnet-activity-log', false ) ),
			self::UPDATE_NONCE_ACTION
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CSA WP User Tracker', 'esnet-activity-tracker' ); ?></h1>
			<p><?php esc_html_e( 'Tracks logged-in activity for users whose roles are not limited to subscriber.', 'esnet-activity-tracker' ); ?></p>
			<?php if ( ! empty( $_GET['csa_tracker_refreshed'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'GitHub update check refreshed.', 'esnet-activity-tracker' ); ?></p></div>
			<?php endif; ?>
			<?php self::render_update_status( $update_status, $refresh_url ); ?>
			<form method="get" style="margin: 16px 0 20px;">
				<input type="hidden" name="page" value="esnet-activity-log">
				<label>
					<?php esc_html_e( 'User', 'esnet-activity-tracker' ); ?>
					<input type="text" name="activity_user" value="<?php echo esc_attr( $filters['user'] ); ?>" placeholder="<?php esc_attr_e( 'ID or login', 'esnet-activity-tracker' ); ?>">
				</label>
				<label>
					<?php esc_html_e( 'Action', 'esnet-activity-tracker' ); ?>
					<input type="text" name="activity_action" value="<?php echo esc_attr( $filters['action'] ); ?>" placeholder="post_updated">
				</label>
				<label>
					<?php esc_html_e( 'Object Type', 'esnet-activity-tracker' ); ?>
					<input type="text" name="activity_object_type" value="<?php echo esc_attr( $filters['object_type'] ); ?>" placeholder="post">
				</label>
				<label>
					<?php esc_html_e( 'From', 'esnet-activity-tracker' ); ?>
					<input type="date" name="activity_from" value="<?php echo esc_attr( $filters['from'] ); ?>">
				</label>
				<label>
					<?php esc_html_e( 'To', 'esnet-activity-tracker' ); ?>
					<input type="date" name="activity_to" value="<?php echo esc_attr( $filters['to'] ); ?>">
				</label>
				<?php submit_button( __( 'Filter', 'esnet-activity-tracker' ), 'secondary', '', false ); ?>
				<a class="button" href="<?php echo esc_url( menu_page_url( 'esnet-activity-log', false ) ); ?>"><?php esc_html_e( 'Reset', 'esnet-activity-tracker' ); ?></a>
				<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'esnet-activity-tracker' ); ?></a>
			</form>
			<p>
				<?php
				printf(
					/* translators: 1: total rows, 2: current page, 3: total pages */
					esc_html__( '%1$d logged activities. Page %2$d of %3$d.', 'esnet-activity-tracker' ),
					absint( $total ),
					absint( $page ),
					absint( $total_pages )
				);
				?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'esnet-activity-tracker' ); ?></th>
						<th><?php esc_html_e( 'User', 'esnet-activity-tracker' ); ?></th>
						<th><?php esc_html_e( 'Roles', 'esnet-activity-tracker' ); ?></th>
						<th><?php esc_html_e( 'Action', 'esnet-activity-tracker' ); ?></th>
						<th><?php esc_html_e( 'Object', 'esnet-activity-tracker' ); ?></th>
						<th><?php esc_html_e( 'Request', 'esnet-activity-tracker' ); ?></th>
						<th><?php esc_html_e( 'Context', 'esnet-activity-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No activity found.', 'esnet-activity-tracker' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( mysql2date( 'Y-m-d H:i:s', $row->occurred_at, true ) ); ?></td>
								<td>
									<strong><?php echo esc_html( $row->display_name ? $row->display_name : $row->user_login ); ?></strong><br>
									<code><?php echo esc_html( $row->user_login ); ?></code> #<?php echo absint( $row->user_id ); ?>
								</td>
								<td><?php echo esc_html( $row->roles ); ?></td>
								<td><code><?php echo esc_html( $row->action ); ?></code></td>
								<td>
									<?php echo esc_html( $row->object_type ); ?>
									<?php if ( $row->object_id ) : ?>
										#<?php echo absint( $row->object_id ); ?>
									<?php endif; ?>
									<?php if ( $row->object_name ) : ?>
										<br><?php echo esc_html( wp_trim_words( $row->object_name, 12 ) ); ?>
									<?php endif; ?>
								</td>
								<td>
									<code><?php echo esc_html( $row->request_method ); ?></code><br>
									<small><?php echo esc_html( $row->request_uri ); ?></small>
								</td>
								<td>
									<?php if ( $row->context ) : ?>
										<details>
											<summary><?php esc_html_e( 'View', 'esnet-activity-tracker' ); ?></summary>
											<pre style="max-width: 360px; white-space: pre-wrap;"><?php echo esc_html( self::pretty_json( $row->context ) ); ?></pre>
										</details>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%', $base_url ),
									'format'    => '',
									'current'   => $page,
									'total'     => $total_pages,
									'prev_text' => __( '&laquo;', 'esnet-activity-tracker' ),
									'next_text' => __( '&raquo;', 'esnet-activity-tracker' ),
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Refresh GitHub update status from the admin page.
	 */
	public static function maybe_refresh_update_status() {
		if ( empty( $_GET['page'] ) || 'esnet-activity-log' !== sanitize_key( wp_unslash( $_GET['page'] ) ) || empty( $_GET['csa_tracker_refresh_update'] ) ) {
			return;
		}

		if ( ! current_user_can( self::admin_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to refresh plugin updates.', 'esnet-activity-tracker' ) );
		}

		check_admin_referer( self::UPDATE_NONCE_ACTION );

		if ( class_exists( 'CSA_WP_User_Tracker_GitHub_Updater' ) ) {
			CSA_WP_User_Tracker_GitHub_Updater::clear_cache();
		}

		wp_update_plugins();

		wp_safe_redirect(
			add_query_arg(
				'csa_tracker_refreshed',
				'1',
				remove_query_arg( array( 'csa_tracker_refresh_update', '_wpnonce' ), menu_page_url( 'esnet-activity-log', false ) )
			)
		);
		exit;
	}

	/**
	 * Render safe updater diagnostics.
	 *
	 * @param array  $status Update status.
	 * @param string $refresh_url Refresh URL.
	 */
	private static function render_update_status( $status, $refresh_url ) {
		if ( empty( $status ) ) {
			return;
		}
		?>
		<div class="postbox" style="max-width: 900px; padding: 12px 16px; margin: 16px 0;">
			<h2 style="margin-top: 0;"><?php esc_html_e( 'GitHub Update Status', 'esnet-activity-tracker' ); ?></h2>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<tr><th><?php esc_html_e( 'Installed version', 'esnet-activity-tracker' ); ?></th><td><?php echo esc_html( $status['installed_version'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Latest GitHub release', 'esnet-activity-tracker' ); ?></th><td><?php echo esc_html( $status['latest_version'] ? $status['latest_version'] : 'Not detected' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Update available', 'esnet-activity-tracker' ); ?></th><td><?php echo $status['update_available'] ? esc_html__( 'Yes', 'esnet-activity-tracker' ) : esc_html__( 'No', 'esnet-activity-tracker' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'GitHub HTTP status', 'esnet-activity-tracker' ); ?></th><td><?php echo esc_html( $status['github_status'] ? $status['github_status'] : 'No response' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'GitHub token detected', 'esnet-activity-tracker' ); ?></th><td><?php echo $status['token_available'] ? esc_html__( 'Yes', 'esnet-activity-tracker' ) : esc_html__( 'No', 'esnet-activity-tracker' ); ?> <code><?php echo esc_html( $status['token_source'] ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'Package URL type', 'esnet-activity-tracker' ); ?></th><td><?php echo esc_html( $status['package_type'] ? $status['package_type'] : 'Not available' ); ?></td></tr>
					<?php if ( ! empty( $status['github_error'] ) ) : ?>
						<tr><th><?php esc_html_e( 'GitHub error', 'esnet-activity-tracker' ); ?></th><td><?php echo esc_html( $status['github_error'] ); ?></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
			<p><a class="button button-secondary" href="<?php echo esc_url( $refresh_url ); ?>"><?php esc_html_e( 'Refresh GitHub update check', 'esnet-activity-tracker' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Export filtered rows as CSV.
	 */
	public static function maybe_export_csv() {
		if ( empty( $_GET['page'] ) || 'esnet-activity-log' !== sanitize_key( wp_unslash( $_GET['page'] ) ) || empty( $_GET['esnet_activity_export'] ) ) {
			return;
		}

		if ( ! current_user_can( self::admin_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to export activity logs.', 'esnet-activity-tracker' ) );
		}

		check_admin_referer( self::EXPORT_NONCE_ACTION );

		global $wpdb;

		$table_name = self::table_name();
		$where      = self::build_where_sql( self::get_admin_filters() );
		$rows       = $wpdb->get_results( "SELECT * FROM {$table_name} {$where['sql']} ORDER BY occurred_at DESC, id DESC LIMIT 5000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=esnet-activity-log-' . gmdate( 'Ymd-His' ) . '.csv' );

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
			array( 'status' => $post->post_status )
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
			array(
				'from' => $old_status,
				'to'   => $new_status,
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
			self::log( 'post_trashed', $post->post_type, $post_id, get_the_title( $post_id ) );
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
			self::log( 'post_untrashed', $post->post_type, $post_id, get_the_title( $post_id ) );
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
			self::log( 'post_deleted', $post->post_type, $post_id, $post->post_title );
		}
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
		$wpdb->insert( self::table_name(), $data, $formats );
		self::$writing = false;
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
		return (bool) apply_filters( 'esnet_activity_tracker_should_track_user', $track, $user, $roles );
	}

	/**
	 * Get capability needed to view/export logs.
	 *
	 * @return string
	 */
	private static function admin_capability() {
		return (string) apply_filters( 'esnet_activity_tracker_admin_capability', 'manage_options' );
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

		return (bool) apply_filters( 'esnet_activity_tracker_ignore_option', false, $option );
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
	 * Delete old log rows.
	 */
	public static function cleanup_old_logs() {
		global $wpdb;

		$retention_days = (int) apply_filters( 'esnet_activity_tracker_retention_days', self::DEFAULT_RETENTION );
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
		update_option( $option, $value, false );
		self::$writing = false;
	}
}

register_activation_hook( __FILE__, array( 'ESnet_Activity_Tracker', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ESnet_Activity_Tracker', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'CSA_WP_User_Tracker_GitHub_Updater', 'init' ) );
add_action( 'plugins_loaded', array( 'ESnet_Activity_Tracker', 'init' ) );
