<?php
/*
Plugin Name: Pull MIT Events
Description: Pulls Events from calendar.mit.edu for the Libraries news site 
Author: Hattie Llavina and Matt Bernhardt
Version: 1.0.2
*/


defined( 'ABSPATH' ) or die();


/* Fetch only library events and exclude exhibits.
If no days specified, only current day returned.  
If no  record count specified, only 10 records returned. 
See https://developer.localist.com/doc/api 
*/
define( 'EVENTS_URL', get_option( 'pull_url_field' ) );




class Pull_Events_Plugin {



public function __construct() {
    // Hook into the admin menu
    add_action( 'admin_menu', array( $this, 'create_plugin_settings_page' ) );

	add_action( 'daily_event_pull', 'pull_events' );

	add_action( 'admin_init', array( $this, 'setup_sections' ) );
	add_action( 'admin_init', array( $this, 'setup_fields' ) );
	register_setting( 'pull_mit_events', 'pull_url_field' );

	register_activation_hook( __FILE__, 'my_activation' );
	register_deactivation_hook( __FILE__, 'my_deactivation');

}

function my_deactivation() {
	wp_clear_scheduled_hook( 'daily_event_pull' );
}

function my_activation() {
	wp_schedule_event( time(), 'hourly', 'daily_event_pull' );
}


public function create_plugin_settings_page() {
    // Add the menu item and page
    $page_title = 'Pull Events Settings Page';
    $menu_title = 'Pull MIT Events';
    $capability = 'manage_options';
    $slug = 'pull_mit_events';
    $callback = array( $this, 'plugin_settings_page_content' );
    $icon = 'dashicons-admin-plugins';
    $position = 100;

    add_menu_page( $page_title, $menu_title, $capability, $slug, $callback, $icon, $position );
}



function setup_sections() {
	add_settings_section( 'url_section', 'Configure Events Pull', false, 'pull_mit_events' );
}

public function setup_fields() {
	add_settings_field( 'pull_url_field', 'Pull Events URL:', array( $this, 'field_callback' ), 'pull_mit_events', 'url_section' );
}


public function field_callback( $arguments ) {
	echo '<input name="pull_url_field" id="pull_url_field" type="text" size="100" value="' . esc_url ( get_option( 'pull_url_field' ) ) . '" />';
  
}
/* Pulls events and either updates or inserts based on calendar ID field */

static function pull_events( $confirm = false ) {

	/**
	 * Before we do anything, make sure our timezone is set correctly based on
	 * the site settings. Ideally we would store times and dates in their proper
	 * format, but it was a legacy decision that they would be stored as
	 * strings, rather than datetimes.
	 */
	date_default_timezone_set( get_option( 'timezone_string' ) );

	$url = EVENTS_URL; 
	$result = file_get_contents($url);
	$events = json_decode($result, TRUE);
	foreach ($events['events'] as $val) {
		if(is_array($val)) {  
			if (isset($val["event"]["title"])) { 
				$title =  $val["event"]["title"];
				$slug = str_replace(" ", "-", $title);
			}
			if (isset($val["event"]["description_text"])) { 
				$description = $val["event"]["description_text"];
			}
			if (isset($val["event"]["event_instances"][0]["event_instance"])) { 
				$calendar_id =  $val["event"]["event_instances"][0]["event_instance"]["id"];
				$start =  strtotime($val["event"]["event_instances"][0]["event_instance"]["start"]);
				$startdate = date('Ymd', $start);
				$starttime = date('h:i A', $start);
				$end = '';
				$enddate = '';
				$endtime = '';
				if ( isset( $val["event"]["event_instances"][0]["event_instance"]["end"] ) ) {
					$end =  strtotime($val["event"]["event_instances"][0]["event_instance"]["end"]);
					$enddate = date('Ymd', $end);
					$endtime = date('h:i A', $end);
				}
			}
			if (isset($val["event"]["localist_url"])) { 
				$calendar_url =  $val["event"]["localist_url"];
			}
			if (isset($val["event"]["photo_url"])) { 
				$photo_url =  $val["event"]["photo_url"];
			} 
			$category = 43;  //all news

			if (isset($calendar_id)) { 
		
				$args = array(
					'post_status'     => 'publish',
					'numberposts'	=> -1,
					'post_type'		=> 'post',
					'meta_key'		=> 'calendar_id',
					'meta_value'	=> $calendar_id,
			   		);
				query_posts( $args );

				if  ( have_posts() ) {

					the_post();
					$post_id = wp_update_post(
						array(
							'ID'  => get_the_ID(),
							'comment_status'  => 'closed',
							'ping_status'   => 'closed',
							'post_title'    => $title,
							'post_description'    => $description,
						), True
					);
					if (is_wp_error($post_id)) {
						$errors = $post_id->get_error_messages();
						foreach ($errors as $error) {
							error_log($error);
						}
					} else { 
						if ( $confirm ) { 
							echo esc_html( $title ) . ": Updated<br/>";
						}
						error_log($title . ": Updated");
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
							'post_category' => array($category),
						), True
					);

					if (is_wp_error($post_id)) {
						$errors = $post_id->get_error_messages();
						foreach ($errors as $error) {
							error_log($error);
						}
					} else { 
						if ( $confirm ) { 
							echo esc_html( $title ) . ": Inserted<br/>";
						}
						error_log($title . ": Inserted");
			  		}
				}
				Pull_Events_Plugin::__update_post_meta( $post_id, 'event_date' , $startdate );
				Pull_Events_Plugin::__update_post_meta( $post_id, 'event_start_time' , $starttime );	
				if ( isset( $val["event"]["event_instances"][0]["event_instance"]["end"] ) ) {
					Pull_Events_Plugin::__update_post_meta( $post_id,  'event_end_time' , $endtime );
				}
				Pull_Events_Plugin::__update_post_meta( $post_id,  'is_event' , '1' );
				Pull_Events_Plugin::__update_post_meta( $post_id,  'calendar_url' , $calendar_url );
				Pull_Events_Plugin::__update_post_meta( $post_id,  'calendar_id' , $calendar_id );
				Pull_Events_Plugin::__update_post_meta( $post_id,  'calendar_image' , $photo_url );

			}

		}
	}
}


static function __update_post_meta( $post_id, $field_name, $value = '' )
{
    if ( empty( $value ) OR ! $value )
    {
        delete_post_meta( $post_id, $field_name );
    }
    elseif ( ! get_post_meta( $post_id, $field_name ) )
    {
        add_post_meta( $post_id, $field_name, $value );
    }
    else
    {
        update_post_meta( $post_id, $field_name, $value );
    }
}


	function plugin_settings_page_content() {

		if ( isset( $_GET['page'] ) && isset( $_GET['action'] ) ) {

			if ($_GET['page'] == "pull_mit_events" && $_GET['action'] == "pull-events" ) {
				 echo "<h2>Pull MIT Library Events</h2>";
				Pull_Events_Plugin::pull_events(true);
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

		<form method="post" action="<?php echo esc_url( admin_url( "admin.php?page=pull_mit_events&action=pull-events" ) ); ?>">
			
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
