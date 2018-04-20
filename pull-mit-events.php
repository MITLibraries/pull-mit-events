<?php
/**
 * Plugin Name: Pull MIT Events
 * Description: Pulls Events from calendar.mit.edu for the Libraries news site
 * Author: Hattie Llavina
 * Version 1.1.0
 *
 * @package Pull MIT Events
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || die();


/**
 * Fetch only library events and exclude exhibits.
 * If no days specified, only current day returned.
 * If no  record count specified, only 10 records returned.
 * See https://developer.localist.com/doc/api
 *
 * Reference URL: https://calendar.mit.edu/api/2/events?pp=500&group_id=11497&exclude_type=102763&days=365
 */
define( 'EVENTS_URL', get_option( 'pull_url_field' ) );


/**
 * Pull_Events_Plugin is the class that controls everything.
 */
class Pull_Events_Plugin {


	/**
	 * The constructor defines the admin screens, settings, and hooks.
	 */
	public function __construct() {
		// Hook into the admin menu.
		add_action( 'admin_menu', array( $this, 'create_plugin_settings_page' ) );

		add_action( 'daily_event_pull', 'pull_events' );

		add_action( 'admin_init', array( $this, 'setup_sections' ) );
		add_action( 'admin_init', array( $this, 'setup_fields' ) );
		register_setting( 'pull_mit_events', 'pull_url_field' );

		register_activation_hook( __FILE__, 'my_activation' );
		register_deactivation_hook( __FILE__, 'my_deactivation' );

	}

	/**
	 * Implements the deactivation hook.
	 *
	 * @link https://codex.wordpress.org/Function_Reference/wp_clear_scheduled_hook
	 */
	public function my_deactivation() {
		wp_clear_scheduled_hook( 'daily_event_pull' );
	}

	/**
	 * Implements the plugin activation hook.
	 *
	 * @link https://codex.wordpress.org/Function_Reference/wp_schedule_event
	 */
	public function my_activation() {
		wp_schedule_event( time(), 'hourly', 'daily_event_pull' );
	}

	/**
	 * Defines the plugin's settings page and menu item.
	 */
	public function create_plugin_settings_page() {
		$page_title = 'Pull Events Settings Page';
		$menu_title = 'Pull MIT Events';
		$capability = 'manage_options';
		$slug = 'pull_mit_events';
		$callback = array( $this, 'plugin_settings_page_content' );
		$icon = 'dashicons-admin-plugins';
		$position = 100;

		add_menu_page( $page_title, $menu_title, $capability, $slug, $callback, $icon, $position );
	}

	/**
	 * Defines a section within the settings page.
	 */
	public function setup_sections() {
		add_settings_section( 'url_section', 'Configure Events Pull', false, 'pull_mit_events' );
	}

	/**
	 * Defines the settings field that stores the URL we poll for calendar events.
	 */
	public function setup_fields() {
		$field = array(
			'id' => 'pull_url_field',
		);
		add_settings_field( $field['id'], 'Pull Events URL:', array( $this, 'field_callback' ), 'pull_mit_events', 'url_section', $field );
	}

	/**
	 * Rendering callback for the settings field.
	 *
	 * @param Array $arguments Arguments sent by setup_fields().
	 */
	public function field_callback( $arguments ) {
		echo '<input name="' . esc_attr( $arguments['id'] ) . '" id="' . esc_attr( $arguments['id'] ) . '" type="text" size="100" value="' . esc_attr( get_option( 'pull_url_field' ) ) . '" />';
	}

	/**
	 * Pulls events and either updates or inserts based on calendar ID field.
	 *
	 * @param Boolean $confirm A variable checked to make sure we should run the import.
	 */
	static function pull_events( $confirm = false ) {

		/**
		 * Before we do anything, make sure our timezone is set correctly based on
		 * the site settings. Ideally we would store times and dates in their proper
		 * format, but it was a legacy decision that they would be stored as
		 * strings, rather than datetimes.
		 */
		date_default_timezone_set( get_option( 'timezone_string' ) );

		$url = EVENTS_URL;
		$result = file_get_contents( $url );
		$events = json_decode( $result, true );
		foreach ( $events['events'] as $val ) {
			if ( is_array( $val ) ) {
				if ( isset( $val['event']['title'] ) ) {
					$title = $val['event']['title'];
					$slug = str_replace( ' ', '-', $title );
				}
				if ( isset( $val['event']['description_text'] ) ) {
					$description = $val['event']['description_text'];
				}
				if ( isset( $val['event']['event_instances'][0]['event_instance'] ) ) {
					$calendar_id = $val['event']['event_instances'][0]['event_instance']['id'];
					$start = strtotime( $val['event']['event_instances'][0]['event_instance']['start'] );
					$startdate = date( 'Ymd', $start );
					$starttime = date( 'h:i A', $start );
					$end = '';
					$enddate = '';
					$endtime = '';
					if ( isset( $val['event']['event_instances'][0]['event_instance']['end'] ) ) {
						$end = strtotime( $val['event']['event_instances'][0]['event_instance']['end'] );
						$enddate = date( 'Ymd', $end );
						$endtime = date( 'h:i A', $end );
					}
				}
				if ( isset( $val['event']['localist_url'] ) ) {
					$calendar_url = $val['event']['localist_url'];
				}
				if ( isset( $val['event']['photo_url'] ) ) {
					$photo_url = $val['event']['photo_url'];
				}
				$category = 43;  // 43 is the value for "All News".

				if ( isset( $calendar_id ) ) {

					$args = array(
						'post_status'     => 'publish',
						'numberposts'   => -1,
						'post_type'     => 'post',
						'meta_key'      => 'calendar_id',
						'meta_value'    => $calendar_id,
					);
					query_posts( $args );

					if ( have_posts() ) {

						the_post();
						$post_id = wp_update_post(
							array(
								'ID'  => get_the_ID(),
								'comment_status'  => 'closed',
								'ping_status'   => 'closed',
								'post_title'    => $title,
								'post_description'    => $description,
							), true
						);
						if ( is_wp_error( $post_id ) ) {
							$errors = $post_id->get_error_messages();
							foreach ( $errors as $error ) {
								error_log( $error );
							}
						} else {
							if ( $confirm ) {
								echo esc_html( $title ) . ': Updated<br/>';
							}
							error_log( $title . ': Updated' );
						}
					} else {

						$post_id = wp_insert_post(
							array(
								'comment_status'  => 'closed',
								'ping_status'   => 'closed',
								'post_name'   => $slug,
								'post_title'    => $title,
								'post_description'    => $description,
								'post_status'   => 'publish',
								'post_type'   => 'post',
								'post_category' => array( $category ),
							), true
						);

						if ( is_wp_error( $post_id ) ) {
							$errors = $post_id->get_error_messages();
							foreach ( $errors as $error ) {
								error_log( $error );
							}
						} else {
							if ( $confirm ) {
								echo esc_html( $title ) . ': Inserted<br/>';
							}
							error_log( $title . ': Inserted' );
						}
					}
					Pull_Events_Plugin::__update_post_meta( $post_id, 'event_date', $startdate );
					Pull_Events_Plugin::__update_post_meta( $post_id, 'event_start_time', $starttime );
					if ( isset( $val['event']['event_instances'][0]['event_instance']['end'] ) ) {
						Pull_Events_Plugin::__update_post_meta( $post_id, 'event_end_time', $endtime );
					}
					Pull_Events_Plugin::__update_post_meta( $post_id, 'is_event', '1' );
					Pull_Events_Plugin::__update_post_meta( $post_id, 'calendar_url', $calendar_url );
					Pull_Events_Plugin::__update_post_meta( $post_id, 'calendar_id', $calendar_id );
					Pull_Events_Plugin::__update_post_meta( $post_id, 'calendar_image', $photo_url );

				}
			}
		}
	}

	/**
	 * Method to update a single meta field for a single post.
	 *
	 * @param Integer $post_id The Post ID.
	 * @param String  $field_name The name of the meta field being updated.
	 * @param String  $value The value of the meta field to be stored.
	 */
	public static function __update_post_meta( $post_id, $field_name, $value = '' ) {
		if ( empty( $value ) || ! $value ) {
			delete_post_meta( $post_id, $field_name );
		} elseif ( ! get_post_meta( $post_id, $field_name ) ) {
			add_post_meta( $post_id, $field_name, $value );
		} else {
			update_post_meta( $post_id, $field_name, $value );
		}
	}

	/**
	 * Defines the settings page content.
	 */
	public function plugin_settings_page_content() {

		if ( isset( $_GET['action'] ) ) {

			if ( 'pull_mit_events' == $_GET['page'] && 'pull-events' == $_GET['action'] ) {
				 echo '<h2>Pull MIT Library Events</h2>';
				Pull_Events_Plugin::pull_events( true );
				exit;
			}
		}

		?>

		<div>

		<h2>Pull MIT Library Events</h2>

		<p>
		Example: https://calendar.mit.edu/api/2/events?pp=500&group_id=11497&exclude_type=102763&days=365<br/>
		pp – record count  . If you don’t specify a count, by default it returns only 10<br/>
		group_id – the id for MIT Libraries<br/>
		exclude_type – excluding exhibits<br/>
		days – 365 , number of days to return (always in future) . If you don’t specify this parameter, by default it returns only today
		</p>
		<form method="POST" action="options.php">

		<?php
		settings_fields( 'pull_mit_events' );
		do_settings_sections( 'pull_mit_events' );
		submit_button();
		?>

		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=pull_mit_events&action=pull-events' ) ); ?>">
			
		<h2>Do it now:</h2> 

		<input type="hidden" name="action" value="pull-events" />
		<input type="submit" value="Pull Events" class="button button-primary" />
		</form>	
		<hr/>

		</div>


	<?php
	}

}

new Pull_Events_Plugin();
?>
