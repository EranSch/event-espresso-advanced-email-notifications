<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class EE_AdvancedEmailDispatch{

	public function drip(){

		global $wpdb;

		$emails = $wpdb->get_results(
				"SELECT DISTINCT ev.id,
					ev.start_date,
					em.pre_existing_email,
					em.email_subject,
					em.email_body,
					a.id as attendee_id,
					concat(a.fname,' ', a.lname) as name, 
					a.email
				FROM wp_events_attendee a
					INNER JOIN wp_events_email_advanced em ON em.event_id=a.event_id
					INNER JOIN wp_events_detail ev ON ev.id=em.event_id
				WHERE em.is_active = true
					AND ev.event_status not in ('D','R')
					AND TIMESTAMPDIFF(DAY, CURDATE(), ev.start_date ) = em.send_offset
			");

		/*?><pre><?php print_r($emails); ?></pre><?php*/

		foreach ($emails as $email) {
			$this->send_email( $email );
		}		

	}

	public function send_email( $email ){

		require_once(EVENT_ESPRESSO_PLUGINFULLPATH . 'includes/functions/email.php');
		require_once(EVENT_ESPRESSO_PLUGINFULLPATH . 'gateways/process_payments.php');

		global $wpdb;

		// Headers for outgoing emails
		$headers = "MIME-Version: 1.0" . "\r\n";
		$headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";

		$cached_emails = array();

		$to		= $email->email;
		$name 	= $email->name;
		$att_id = $email->attendee_id;
		$e_id	= $email->id;

		$email_id = $email->pre_existing_email;
		$email_contents = (object)array(
				"email_subject" 	=> null,
				"email_text" 		=> null
			);

		if( $email_id != "0" && $email_id != '' ){			
			// Cache the email in case it will be needed several times.
			$email_contents = ( !array_key_exists($email_id, $cached_emails) ?
					$cached_emails[$email_id] = $wpdb->get_row($wpdb->prepare("SELECT email_subject, email_text FROM wp_events_email WHERE id=%s", $email_id)) :
					$cached_emails[$email_id]
				);	
		}else{
			$email_contents->email_subject	= $email->email_subject;
			$email_contents->email_text		= $email->email_body;
		}

		//Collect Data for event 
		$data =	espresso_prepare_email_data( $att_id, true);
		
		//Email Subject
		$subject = stripslashes_deep(html_entity_decode($email_contents->email_subject, ENT_QUOTES, "UTF-8"));
		$subject = replace_shortcodes( $subject, $data);
	    $subject = str_replace('[event_id]', $e_id, $subject);

		//Perform the replacement
		$final_message = replace_shortcodes( $email_contents->email_text, $data);
		$final_message = str_replace('[event_id]', $e_id, $final_message);
		$final_message = str_replace('[attendee_id]', $att_id, $final_message);

		// Convert line breaks into HTML
		$final_message = str_replace("\n", "<br />", $final_message);

		// Strip slashes to fix escaped HTML stuff in email...
		$final_message = stripslashes_deep(html_entity_decode($final_message, ENT_QUOTES, "UTF-8"));

		//Send Email
		wp_mail($to, $subject, $final_message, $headers);

	}
}