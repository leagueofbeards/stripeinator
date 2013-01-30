<?php
/*
Plugin Name: The Stripeinator!
Plugin URI: https://github.com/leagueofbeards/stripeinator
Description: Curse you Perry the Platypus! Also integrates Stripe with WordPress.
Version: 1.1
Author: Chris J. Davis
Author URI: http://leagueofbeards.com
*/

class Stripeinator
{
	const STRIPE_SEKRET = 'uwBf79xWeyeeOgVOLydNZV9ddR0t8GOL';

	public function __construct() {		
		add_action( 'init', array( &$this, 'init' ) );
	}

	public function init() {
		wp_enqueue_script( 'jquery.form', plugin_dir_url( __FILE__ ) . 'jquery.form.js', array( 'jquery' ) );
		wp_localize_script( 'stripeinator', 'Stripeinator', array('ajaxurl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('stripeinator-nonce')) );
	}

	public static function install() {
		global $wpdb;
		$table_name = $wpdb->prefix . "subscriptions";
		
		$sql = "CREATE TABLE $table_name (
			`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
			  `user_id` int(10) unsigned NOT NULL,
			  `object_type` varchar(255) COLLATE utf8_bin DEFAULT NULL,
			  `created` varchar(255) COLLATE utf8_bin DEFAULT NULL,
			  `plan_id` varchar(255) COLLATE utf8_bin DEFAULT NULL,
			  `plan_status` varchar(255) COLLATE utf8_bin NOT NULL DEFAULT '',
			  `plan_interval` varchar(255) COLLATE utf8_bin DEFAULT NULL,
			  `current_period_start` varchar(255) COLLATE utf8_bin DEFAULT NULL,
			  `current_period_end` varchar(255) COLLATE utf8_bin DEFAULT NULL,
			  `canceled` int(10) unsigned NOT NULL,
			  `discounted` int(10) unsigned DEFAULT NULL,
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `plan_id` (`plan_id`)
			) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8 COLLATE=utf8_bin;";
		
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}

	private static function insert($vars) {
		global $wpdb;
		$table_name = $wpdb->prefix . "subscriptions";
		$rows_affected = $wpdb->insert( $table_name, $vars );
	}

	private static function create_account($vars) {
		$user_data = array(
			'ID'			=> '',
			'user_pass'		=> wp_generate_password(),
			'user_login'	=> $vars['email'],
			'display_name'	=> $vars['name'],
			'role'			=> get_option('default_role'),
		);
		
		$user_id = wp_insert_user( $user_data );
		return $user_id;
	}

	public function header_js() {
		echo '<script src="' . plugin_dir_url( __FILE__ ) . 'jquery.form.js' . '"></script>' . "\n";
	}

	public static function ajax_url() {
		$url = admin_url('admin-ajax.php') . '?action=create_charge&nonce=' . wp_create_nonce('stripeinator-nonce');
		return $url;
	}
	
	private static function setup_stripe() {
		include_once( 'libs/Stripe.php' );
		Stripe::setApiKey(self::STRIPE_SEKRET);
	}

	public function create_charge($vars) {
		global $user_ID;
		$chrg = array();
		$status = false;

		if ( ! isset( $_REQUEST['nonce'] ) || ! wp_verify_nonce( $_REQUEST['nonce'], 'stripeinator-nonce' ) ) {
			die ( 'Invalid Nonce' );
		} else {
			header( "Content-Type: application/json" );
			self::setup_stripe();
			$token  = $_REQUEST['stripeToken'];
			$email	= $_REQUEST['email'];
				
			if( $token != NULL && $email != NULL ) {
				$customer = Stripe_Customer::create( array('email' => $email, 'card'  => $token) );
				$r = $customer->updateSubscription( array('prorate' => true, 'plan' => $_REQUEST['plan_id']) );
	
				$charge = json_decode( $r );
				$new_user = self::create_account( $_REQUEST );
				
				add_user_meta( $new_user, 'stripe_id', $customer->id );
				add_user_meta( $new_user, 'membership_plan', $_REQUEST['plan_id'] );
				add_user_meta( $new_user, 'membership_address', $_REQUEST['address'] );
				add_user_meta( $new_user, 'membership_goodies', array('hoodie' => $_REQUEST['hoodie'], 'tshirt' => $_REQUEST['tshirt']) );
				
				$chrg['discounted'] = 0;
				$chrg['plan_id'] = $charge->plan->id;
				$chrg['user_id'] = $new_user;
				$chrg['created'] = current_time('mysql');
				$chrg['plan_status'] = $charge->status;
				$chrg['current_period_start'] = $charge->current_period_start;
				$chrg['current_period_end'] = $charge->current_period_end;
				$chrg['plan_interval'] = $charge->plan->interval;
				$chrg['object_type'] = $charge->object;
				
				self::insert($chrg);
				$message = 'forward';
				$status = true;
			} else {
				if( $email == NULL ) {
					$status = false;
					$message = 'Looks like you forgot to provide us with your email, please go back and make sure you provide it.';
				} else {
					if( $token == NULL ) {
						$message = 'It seems we didn\'t get your payment information, please go back and try again.';
						$status = false;
					}
				}
			}
			
			echo json_encode( array('success' => $status, 'message' => $message ) );
			exit();
		}
	}
}

add_action( 'wp_head', array( 'Stripeinator', 'header_js') );
add_action( 'wp_ajax_nopriv_create_charge', array( 'Stripeinator', 'create_charge' ) );
add_action( 'wp_ajax_create_charge', array( 'Stripeinator', 'create_charge' ) );
register_activation_hook(__FILE__, array( 'Stripeinator', 'install') );
?>