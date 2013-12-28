<?php 

/**
 * Plugin Name: Event Espresso - Advanced Email Notifications
 * Plugin URI: http://eran.sh
 * Description: Configure cron-fired emails leading up to and/or after an event.
 * Version: 0.1.2
 * Author: Eran Schoellhorn
 * Author URI: http://eran.sh
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Remove wp_cron hook on plugin activation, create plugin for table if needed
 */
register_activation_hook( __FILE__, 'ee_adv_email_activation' );
function ee_adv_email_activation() {

	// Register cron hook
	wp_schedule_event( time(), 'daily', 'ee_adv_email_notification' );

	// Create DB table
	global $wpdb;
	$table = $wpdb->prefix . "events_email_advanced"; 
	$ee_advanced_email_notifications_db_version = "1.0";

	if($wpdb->get_var("show tables like '$table'") != $table){

		$sql = "CREATE TABLE $table (
				  `id` mediumint(9) NOT NULL AUTO_INCREMENT,
				  `event_id` INT NOT NULL,
				  `email_id` INT NOT NULL,
				  `pre_existing_email` INT NULL,
				  `email_subject` VARCHAR(250) NULL,
				  `email_body` TEXT NULL,
				  `is_active` tinyint(1),
				  `send_offset` INT NULL,
				  UNIQUE KEY id (id)
				  ) DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
		add_option( "ee_advanced_email_notifications_db_version", $ee_advanced_email_notifications_db_version );

	}
}

/**
 * Remove wp_cron hook on plugin deactivation
 */
register_deactivation_hook( __FILE__, 'ee_adv_email_deactivation' );
function ee_adv_email_deactivation() {
	wp_clear_scheduled_hook( 'ee_adv_email_notification' );
}

/**
 * Instantiate plugin on init
 */
add_action( 'init', 'call_EE_AdvancedEmailNotifications' );
function call_EE_AdvancedEmailNotifications() {
    new EE_AdvancedEmailNotifications();
}


class EE_AdvancedEmailNotifications {

	public static $number_of_tabs = 7;

	/**
	 * Build appropriate hooks.
	 */
	public function __construct() {
		// Add meta box
		add_action( 'action_hook_espresso_edit_event_left_column_advanced_options_top', array( $this, 'add_meta_box' ) );
		add_action( 'action_hook_espresso_new_event_left_column_advanced_options_top', 	array( $this, 'add_meta_box' ) );

		// DB logic
		add_action( 'action_hook_espresso_update_event_success', 						array( $this, 'update_database' ) );
		add_action( 'action_hook_espresso_insert_event_success', 						array( $this, 'update_database' ) );

		// Additional functions...
		add_action( 'action_hook_espresso_update_event_success', 						array( $this, 'send_test_emails' ) );
		add_action( 'ee_adv_email_notification', 										array( $this, 'daily_email_dispatch' ) );
	}

	/**
	 * Add the meta box container.
	 */
	public function add_meta_box( $event_id) {

		global $wpdb;
		$table = $wpdb->prefix . "events_email_advanced"; 

		$data = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM $table WHERE event_id = %d ORDER BY email_id ASC",
			$event_id
			));

		/*?> <pre> <h1> DATA </h1> <?php print_r($data); ?> </pre> <?php*/

		?>
		
		<div id="ee-advanced-email-notifications" class="postbox">
		<div class="handlediv" title="Click to toggle"><br></div>
		<h3 class="hndle"><span>Advanced Email Notifications</span></h3>
		<div class="inside">
			<div id="ee-email-tabs">
			  <ul>

			  <?php
			  for ($tabNumber=0; $tabNumber < EE_AdvancedEmailNotifications::$number_of_tabs; $tabNumber++) { 
			  ?>
			    <li><a href="#ee-email-tabs-<?php echo $tabNumber; ?>">Email #<?php echo ($tabNumber + 1); ?></a></li>
			  <?php
			  }
			  ?>

			  </ul>

			  <?php 

			  	for ($tabNumber=0; $tabNumber < EE_AdvancedEmailNotifications::$number_of_tabs; $tabNumber++) { 
			  		
			  		?>

			  		<div id="ee-email-tabs-<?php echo $tabNumber; ?>">
			  			<div id="emaildescriptiondivrich" class="postarea">
				  			<div class="email-conf-opts">	

								<div class="ee-test-send" style="overflow: hidden;">
								<p style="margin: 0;">Send test email</p>
								<input type="text" name="ee-test-send-to-<?php echo $tabNumber; ?>" style="float:left;">
								<input type="submit" name="e-test-send-<?php echo $tabNumber; ?>" value="Send" class="button" style="margin-left: 8px;position: relative;float:left;">
								</div>

								<p>Auto-drip Email: 
									<select name="ee-email-<?php echo $tabNumber; ?>-active">
									<option value="0">Off</option>
									<option value="1" <?php if($data[$tabNumber]->is_active) echo "selected=\"selected\""; ?> >On</option>
									</select>
								</p>

								<p>
									Send email <input type="number" name="ee-email-<?php echo $tabNumber; ?>-send-offset" style="width: 60px;" value="<?php echo $data[$tabNumber]->send_offset ?>" /> days before event.
								</p>

				  				<p>
				  				Use a <a href="admin.php?page=event_emails" target="_blank">pre-existing email</a>? 
				  				<?php echo espresso_db_dropdown('id', 'email_name', EVENTS_EMAIL_TABLE, 'email_name', $data[$tabNumber]->pre_existing_email, 'desc', 'ee-email-' . $tabNumber . '-pre-existing-email') ?>
								

							</div>
							
							<div class="custom-editor">
								<p>Subject: <input type="text" maxlength="250" name="ee-email-<?php echo $tabNumber; ?>-subject" value="<?php echo stripslashes_deep(html_entity_decode($data[$tabNumber]->email_subject, ENT_QUOTES, "UTF-8")); ?>" /></p>

					  			<div class="postbox">
						  			<?php
						  			//echo '<p>version_compare ='.(version_compare($wp_version, $wp_min_version) >= 0).'</p>';
						  			if (function_exists('wp_editor')) {
						  				$args = array("textarea_rows" => 5, "textarea_name" => "ee-email-" . $tabNumber . "-body", "editor_class" => "my_editor_custom");
						  				wp_editor(espresso_admin_format_content( $data[$tabNumber]->email_body ), "ee-email-" . $tabNumber . "-body", $args);
						  			} else {
						  				echo '<textarea name="conf_mail" class="theEditor" id="conf_mail">' . espresso_admin_format_content($conf_mail) . '</textarea>';
						  				espresso_tiny_mce();
						  			}
						  			?>

							  		<table id="email-confirmation-form" cellspacing="0">
							  		<tbody><tr>
							  			<td class="aer-word-count"></td>
							  			<td class="autosave-info"><span><a class="thickbox" href="#TB_inline?height=300&amp;inlineId=custom_email_info&amp;width=640&amp;height=335">
							  			View Custom Email Tags</a> | <a class="thickbox" href="#TB_inline?height=300&amp;inlineId=custom_email_example&amp;width=640&amp;height=335">
							  			Email Example</a></span></td>
							  		</tr></tbody></table>
								</div>
							</div>

  						</div>
  					</div>

			  		<?php

			  	}

			  ?>


			</div>

		</div>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
		    $("#ee-email-tabs").tabs();		  
		    $("select[name$='pre-existing-email']").each(function(){
				var s = jQuery(this);
		    	s.children()[0].innerText = "Custom (Write one below)";

		    	if( s.val() != "" ){
		    		s.parent().parent().next().hide();
		    	}else{
		    		s.parent().parent().next().show();
		    	}
			});  
		});

		jQuery("select[name$='pre-existing-email']").on("change", function(){
			var s = jQuery(this);
	    	s.children()[0].innerText = "Custom (Write one below)";

	    	if( s.val() != "" ){
	    		s.parent().parent().next().hide();
	    	}else{
	    		s.parent().parent().next().show();
	    	}
		});
		</script>

		<style type="text/css">
		#ee-advanced-email-notifications .ui-tabs-nav {
		    background: transparent;
		    border-bottom: 1px solid rgb(214, 214, 214);
		    border-radius: 0px;
		}
		#ee-email-tabs {
		    border: none;
		    padding: 0;
		}
		#ee-advanced-email-notifications .ui-tabs .ui-tabs-nav li {
		    border-radius: 0px;
		}
		#ee-advanced-email-notifications .ui-state-default, .ui-widget-content .ui-state-default, .ui-widget-header .ui-state-default {
		    background: rgb(241, 241, 241);
		}
		#ee-advanced-email-notifications .ui-state-active, .ui-widget-content .ui-state-active, .ui-widget-header .ui-state-active {
		    background: white;
		}
		.ee-test-send {
		    float: right;
		}
		</style>

		<?php
	}

	/**
	 * Update and/or insert email data on event update.
	 */
	public function update_database( $req ) {

		global $wpdb;
		$table = $wpdb->prefix . "events_email_advanced"; 

		$event_id = $req["event_id"];

		for ($i=0; $i < EE_AdvancedEmailNotifications::$number_of_tabs; $i++) { 

			if ( $wpdb->get_row("SELECT * FROM $table WHERE event_id = $event_id AND email_id = $i") ) {
				$wpdb->update( 
					$table, 
					array( 
						'is_active' => $req["ee-email-$i-active"],
						'send_offset' => $req["ee-email-$i-send-offset"],
						'pre_existing_email' => $req["ee-email-$i-pre-existing-email"],
						'email_subject' => esc_html($req["ee-email-$i-subject"]),
						'email_body' => esc_html($req["ee-email-$i-body"])
					), 
					array( 
						'event_id' => $event_id,
						'email_id' => $i
					), 
					array( 
						'%d',
						'%d',
						'%d',
						'%s',
						'%s'
					), 
					array( 
						'%d',
						'%d'
					) 
				);
			}else{
				$wpdb->insert( 
					$table, 
					array( 
						'event_id' => $event_id,
						'email_id' => $i,
						'is_active' => $req["ee-email-$i-active"],
						'send_offset' => $req["ee-email-$i-send-offset"],
						'pre_existing_email' => $req["ee-email-$i-pre-existing-email"],
						'email_subject' => $req["ee-email-$i-subject"],
						'email_body' => esc_html($req["ee-email-$i-body"])
					), 
					array( 
						'%d',
						'%d',
						'%d',
						'%d',
						'%d',
						'%s',
						'%s'
					)
				);
			}
		}
	}

	/**
	 * Send test emails if test recipient is specified.
	 */
	public function send_test_emails( $req ){

		for ($i=0; $i < EE_AdvancedEmailNotifications::$number_of_tabs; $i++) { 
			if( $req["ee-test-send-to-$i"] != '' ){

				$email = (object) array(
						'email' 				=> $req["ee-test-send-to-$i"],
						'id' 					=> $req["event_id"],
						'name'					=> "**FULL NAME HERE**",
						'attendee_id'			=> '1',
						'start_date'			=> $req["start_date"],
						'pre_existing_email' 	=> $req["ee-email-$i-pre-existing-email"],
						'email_subject' 		=> $req["ee-email-$i-subject"],
						'email_body' 			=> esc_html($req["ee-email-$i-body"])
					);

				/*?> <pre> <h1> Email <?php echo $i; ?> </h1> <?php print_r($email); ?> </pre> <?php*/

				require_once('dispatch.php');
				$emailer = new EE_AdvancedEmailDispatch();
				$emailer -> send_email($email);
			}
		}
	}

	/**
	 * Instantiate the dispatch class, invoke the query function
	 */
	public function daily_email_dispatch() {
		require_once('dispatch.php');
		$emailer = new EE_AdvancedEmailDispatch();
		$emailer -> drip();
	}
}