<?php
/*
Plugin Name: Delete old ActionScheduler logs
Plugin URI: http://www.damiencarbery.com
Description: Automatically delete old completed and failed ActionScheduler actions and logs.
Author: Damien Carbery
Version: 0.1.20250820
Requires Plugins: woocommerce
*/

defined( 'ABSPATH' ) || exit;


class DeleteOldActionSchedulerLogs {
	private static $instance = null;
	private $query_limit = 20;
	private $enable_debugging = false;
	private $days_in_past = 7;  // Delete logs older than this many days ago.

	// Cron hook names.
	private const cronDelOldLogsHookName = 'doal_DeleteOldLogs';


	// Returns an instance of this class to only allow one instance.
	public static function get_instance() {
		if ( null == self::$instance ) {
			self::$instance = new self;
		} 
		return self::$instance;
	}


	public function __construct() {
		//$this->plugin_dir = plugin_dir_path( __FILE__ );

		$this->init();
	}

	public function init() {
		register_activation_hook( __FILE__, array( $this, 'install_delete_as_logs_cron' ) );
		register_deactivation_hook( __FILE__, array( $this, 'uninstall_delete_as_logs_cron' ) );
		
		// Run delete_old_logs() in cron.
		add_action( self::cronDelOldLogsHookName, array( $this, 'delete_old_logs' ) );

		// Debug shortcode to see settings and test queries.
		add_shortcode( 'del_as_logs', array( $this, 'del_as_logs_shortcode' ) );
	}


	public function schedule_cron_events() {
		// Note: If local debugging is enabled ($this->enable_debugging) then set cron frequency
		// to weekly. This results in minimal code changes for debugging and allows for
		// manual execution of each event without other events running at inopportune times.

		// ToDo: Consider creating settings page (or adding filter) to set cron frequencies, primarily for temporary overrides.
		if ( ! wp_next_scheduled( self::cronDelOldLogsHookName ) ) {
			$frequency = $this->enable_debugging ? 'weekly' : 'daily';

			if ( wp_schedule_event( time(), $frequency, self::cronDelOldLogsHookName ) ) {
				if ( $this->enable_debugging ) {
					error_log( sprintf( 'Schedule "%s" to run %s.', self::cronDelOldLogsHookName, $frequency ) );
				}
			}
			else {
				if ( $this->enable_debugging ) {
					error_log( sprintf( 'Error scheduling "%s" to run %s.', self::cronDelOldLogsHookName, $frequency ) );
				}
			}
		}
	}


	public function clear_cron_events() {
		// Disable cron jobs.
		wp_clear_scheduled_hook( self::cronDelOldLogsHookName );
	}


	public function install_delete_as_logs_cron() {
		// Initialise cron jobs.
		$this->clear_cron_events();
		$this->schedule_cron_events();
	}


	public function uninstall_delete_as_logs_cron() {
		// Disable cron jobs.
		$this->clear_cron_events();
	}


	public function delete_old_logs() {
		global $wpdb;

		// Ensure a schedule has been created.
		$this->schedule_cron_events();

		// Allow changing how old logs must be.
		$this->days_in_past = absint( apply_filters( 'doal_logs_min_age', $this->days_in_past ) );
		// Allow changing the query limit to handle more or less rows.
		$this->query_limit = absint( apply_filters( 'doal_query_limit', $this->query_limit ) );

		$log_max_date = date( 'Y-m-d H:i:s', time() - ( $this->days_in_past * 60 * 60 * 24 ) );

		// Delete from actions table.
		$actions_rows_deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM `{$wpdb->prefix}actionscheduler_actions` WHERE `scheduled_date_local` <= %s AND (`status` = %s OR `status` = %s) ORDER BY `scheduled_date_local` ASC LIMIT %d",
			array( $log_max_date, 'complete', 'failed', $this->query_limit )
		) );
		// Delete from logs table.
		$logs_rows_deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM `{$wpdb->prefix}actionscheduler_logs` WHERE `log_date_local` <= %s ORDER BY `log_date_local` ASC LIMIT %d",
			array( $log_max_date, $this->query_limit )
		) );

		if ( $this->enable_debugging ) {
			error_log( sprintf( 'Actions rows deleted: %d, logs rows deleted %d.', $actions_rows_deleted, $logs_rows_deleted ) );
		}

		if ( ! doing_action( self::cronDelOldLogsHookName ) ) {
			if ( $this->enable_debugging ) {
				error_log( 'ActionScheduler actions/logs rows deleted: ' . $actions_rows_deleted + $logs_rows_deleted );
			}
			return $actions_rows_deleted + $logs_rows_deleted;
		}
	}


	// Debug shortcode to see settings and test queries.
	public function del_as_logs_shortcode() {
		$rows_deleted = $this->delete_old_logs();

		return '<pre>' . var_export( $rows_deleted, true ) . ' rows deleted.</pre>';
	}
}

$DeleteOldActionSchedulerLogs = new DeleteOldActionSchedulerLogs();
