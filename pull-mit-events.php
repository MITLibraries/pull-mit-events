<?php
/*
Plugin Name: Pull MIT Events
Description: Pulls Events from calendar.mit.edu for the Libraries news site 
Version: 1.0
*/



defined( 'ABSPATH' ) or die();

/* Fetch only library events and exclude exhibits.
If no days specified, only current day returned.  
If no  record count specified, only 10 records returned. 
See https://developer.localist.com/doc/api 
*/

define( 'EVENTS_URL', 'https://calendar.mit.edu/api/2/events?pp=500&group_id=11497&exclude_type=102763&days=365' );

register_activation_hook( __FILE__, 'my_activation' );

add_action( 'daily_event_pull', 'pull_events' );

register_deactivation_hook( __FILE__, 'my_deactivation');

function my_deactivation() {
	wp_clear_scheduled_hook( 'daily_event_pull' );
}

function my_activation() {
    wp_schedule_event( time(), 'hourly', 'daily_event_pull' );
}

/* Pulls events and either updates or inserts based on calendar ID field */

function pull_events( $confirm = false ) {

 	$url = EVENTS_URL; 
	$result = file_get_contents($url);
    $events = json_decode($result, TRUE);
    $jsonIterator = new RecursiveIteratorIterator(
    new RecursiveArrayIterator($events),
    RecursiveIteratorIterator::SELF_FIRST);

	foreach ($jsonIterator as $key => $val) {
	    if(is_array($val)) {  
			$title =  $val["title"];
			$description = $val["description_text"];
			$start =  strtotime($val["event_instances"][0]["event_instance"]["start"]);
			$end =  strtotime($val["event_instances"][0]["event_instance"]["end"]);
			$startdate = date('Ymd', $start);
			$starttime = date('h:i A', $start);
			$enddate = date('Ymd', $end);
			$endtime = date('h:i A', $end);

			$calendar_id =  $val["event_instances"][0]["event_instance"]["id"];
			$calendar_url =  $val["localist_url"];
			$photo_url =  $val["photo_url"];
			$category = 43;  //all news
			$slug = str_replace(" ", "-", $title);

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
					if (is_wp_error($post_id)) 
				    {
				    $errors = $post_id->get_error_messages();
				    foreach ($errors as $error) {
				        error_log($error);
				        }
				    } else { 
				    	if ( $confirm ) { 
				    		echo $title . ": Updated<br/>";
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

					if (is_wp_error($post_id)) 
				    {
				    $errors = $post_id->get_error_messages();
				    foreach ($errors as $error) {
				         error_log($error);
				        }
				    } else { 
				    	if ( $confirm ) { 
					    	echo $title . ": Inserted<br/>";
					    }
						error_log($title . ": Inserted");
		    		}
		    }
	    	__update_post_meta( $post_id, 'event_date' , $startdate );
	    	__update_post_meta( $post_id, 'event_start_time' , $starttime );
			__update_post_meta( $post_id,  'event_end_time' , $endtime );
			__update_post_meta( $post_id,  'is_event' , '1' );
			__update_post_meta( $post_id,  'calendar_url' , $calendar_url );
			__update_post_meta( $post_id,  'calendar_id' , $calendar_id );
			__update_post_meta( $post_id,  'calendar_image' , $photo_url );

		}
	}
}


function __update_post_meta( $post_id, $field_name, $value = '' )
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


/* Adds the admin page for pulling events manually */

if ( is_admin() ){


	add_action('admin_menu', 'pull_mit_events_menu');

	function pull_mit_events_menu() {
		add_menu_page('Pull MIT Events', 'Pull MIT Events', 'administrator', 'pull-mit-events', 'pull_mit_events_html_page');
	}

	function pull_mit_events_html_page() {

		if (isset($_GET['action']) ) {

			if ($_GET['page'] == "pull-mit-events" && $_GET['action'] == "pull-events" ) {
				 echo "<h2>Pull MIT Library Events</h2>";
				pull_events(true);
				exit;
			}
		}

		?>

		<div>

		<h2>Pull MIT Library Events</h2>


		<form method="post" action="<?php echo admin_url("admin.php?page=pull-mit-events&action=pull-events"); ?>">
			
		Do it now: 

		<input type="hidden" name="action" value="pull-events" />
		<input type="submit" value="Pull Events" />
		</form>	
		<hr/>

		</div>


	<?php
	}
}
?>
