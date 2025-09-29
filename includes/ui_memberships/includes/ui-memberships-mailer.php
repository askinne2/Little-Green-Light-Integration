<?php
/**
* File Name: ui-memberships-mailer.php
* Version: 1.0
* Description: This class interfaces between the JetEngine/WP User custom fields & settings
* Author URI: http://github.com/askinne2
*/



class UI_Memberships_Mailer {
	
	/**
	* Class instance
	*
	* @var null|UI_Memberships_Mailer
	*/
	private static $instance = null;
	/**
	* Get instance
	*
	* @return UI_Memberships_Mailer
	*/
	public static function get_instance() {
		if (is_null(self::$instance)) {
			self::$instance = new self();
		}
		
		return self::$instance;
	}
	
	public $recipient;
	public $subject;
	public $email_header;
	public $email_body;
	public $email_footer;
	public $email;
	
	public function __construct() {
		$this->email_header = file_get_contents( plugin_dir_path( __FILE__ ) . '../email_templates/renewal_notice_header.php', true);
		$this->email_footer = file_get_contents( plugin_dir_path( __FILE__ ) . '../email_templates/renewal_notice_footer.php', true);
	}
	
	public function set_content($days) {
		if ($days == -30) {
			$this->email_body = '<h1>There\'s an issue with your membership subscription.</h1>
			<h2>Your membership renewal date has passed and your one month grace period to renew your membership has expired.</h2>
			<p><b>Your membership account has been marked as inactive.</b></p>
			<p>If your membership plan includes family members, their accounts have also been marked as inactive.</p>
			<p>After a 60 day period of inactivity, all user data for your account and family members\' accounts will be permanently removed from the Upstate International website.</p>
			<h3>To reactivate your account</h3>
			<p>Please follow the following steps:</p>
			<ol>
			<li>Reset your account password using the <a href="' . esc_url( get_site_url() ) . '/my-account/lost-password">Login & Reset Password form.<a/></li>
			<li>Make a new password and login into your account.</li>
			<li>Add a Membership Level to your cart & complete your online checkout</li>
			</ol>
			<p>If you need to make changes to your membership, please feel free to stop by the office or contact us at:</p>
			<ul>
			<li><a href="+18646312188">864-631-2188</a></li>
			<li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li>
			<ul>';	
		} else if ($days < 0 && $days >= -29) {
			$this->email_body = '<h1>Please renew your membership - it means the World to UI!</h1>
			<h2>Your membership renewal date has passed.</h2>
			<p>Please login to your <a href="' . esc_url( get_site_url() ) . '/my-account/">Upstate International online account</a> or register for one today. Add your preferred level of membership to your cart and complete your checkout online. You may choose to pay by credit card or check, <strong>but you completing an online order is the most convenient to retain your Upstate International subscription.</strong></p>
			<p>After 30 days of inactivity past your membership renewal date, your account will be marked inactive on our website, and all data will be deleted after 60 days.</p>
			<p>If you experience issues or difficulties, please feel free to stop by the office or contact us at:</p>
			<ul>
			<li><a href="+18646312188">864-631-2188</a></li>
			<li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li>
			<ul>';	
		} else if ($days == 0) {
			$this->email_body = ' <h1>Today is the day!</h1>
			<h2>Your Upstate International Membership renewal date is today.</h2>
			<p>Please login to your <a href="' . esc_url( get_site_url() ) . '/my-account/">Upstate International online account</a> or register for one today. Add your preferred level of membership to your cart and complete your checkout online. You may choose to pay by credit card or check, <strong>but you completing an online order is the most convenient to retain your Upstate International subscription.</strong></p>
			<p>After 30 days of inactivity past your membership renewal date, your account will be marked inactive on our website, and all data will be deleted after 60 days.</p>
			<p>If you experience issues or difficulties, please feel free to stop by the office or contact us at:</p>
			<ul>
			<li><a href="+18646312188">864-631-2188</a></li>
			<li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li>
			<ul>';
		} else if ($days == 7) {
			$this->email_body = ' <h1>One more week!</h1>
			<h2>Your Upstate International Membership renewal date is in 7 days.</h2>
			<p>Please login to your <a href="' . esc_url( get_site_url() ) . '/my-account/">Upstate International online account</a> or register for one today. Add your preferred level of membership to your cart and complete your checkout online. You may choose to pay by credit card or check, <strong>but you completing an online order is the most convenient to retain your Upstate International subscription.</strong></p>
			<p>After 30 days of inactivity past your membership renewal date, your account will be marked inactive on our website, and all data will be deleted after 60 days.</p>			
			<p>If you experience issues or difficulties, please feel free to stop by the office or contact us at:</p>
			<ul>
			<li><a href="+18646312188">864-631-2188</a></li>
			<li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li>
			<ul>';
		} else if ($days == 14) {
			$this->email_body = ' <h1>Two more weeks!</h1>
			<h2>Your Upstate International Membership renewal date is in 14 days.</h2>
			<p>Please login to your <a href="' . esc_url( get_site_url() ) . '/my-account/">Upstate International online account</a> or register for one today. Add your preferred level of membership to your cart and complete your checkout online.</p>
			<p>If you experience issues or difficulties, please feel free to stop by the office or contact us at:</p>
			<ul>
			<li><a href="+18646312188">864-631-2188</a></li>
			<li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li>
			<ul>';
		} else if ($days == 30) {
			$this->email_body = ' <h1>One more month!</h1>
			<h2>Your Upstate International Membership renewal date is in 30 days.</h2>
			<p>Please login to your <a href="' . esc_url( get_site_url() ) . '/my-account/">Upstate International online account</a> or register for one today. Add your preferred level of membership to your cart and complete your checkout online.</p>
			<p>If you experience issues or difficulties, please feel free to stop by the office or contact us at:</p>
			<ul>
			<li><a href="+18646312188">864-631-2188</a></li>
			<li><a href="mailto:info@upstateinternational.org">info@upstateinternational.org</a></li>
			<ul>';
		}
		
		//$this->email = $this->email_header . $this->email_body . $this->email_footer;
		$this->email = $this->email_body; 
		
	}
	
	
	public function send() {
		
		$headers = 'From: Upstate International <info@upstateinternational.org>' . "\r\n";		
		wp_mail($this->recipient, $this->subject, $this->email, $headers);
	}

}