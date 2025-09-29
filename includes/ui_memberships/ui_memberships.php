<?php
/**
* File Name: ui-memberships.php
* Version: 1.0
* Plugin URI:  https://github.com/askinne2/ui-memberships
* Description: This class interfaces between the JetEngine/WP User custom fields & settings
* Author URI: http://github.com/askinne2
*/


if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

define('UI_MEMBERS_PLUGIN_DEBUG', TRUE);

//Load Composer's autoloader
require 'vendor/autoload.php';

define('UI_MEMBERSHIPS_FILE_PATH', plugin_dir_path( __FILE__ ));
require_once UI_MEMBERSHIPS_FILE_PATH . '../../lgl-api.php';

require_once 'includes/ui-memberships-wp-users.php';

if (!class_exists("UI_Memberships")) {
	/**
	* class:   UI_Memberships
	* desc:    Sets up a WP Cron Job to send emails to users to renew their memberships annual
	*/
	class UI_Memberships
	{
		/**
		* Class instance
		*
		* @var null|UI_Memberships
		*/
		private static $instance = null;
		const CRON_HOOK = 'ui_memberships_cron_hook';
		const UI_MEMBERSHIPS_CRON_HOOK = 'ui_memberships_daily_cron_hook';
		
		var $lgl;
		
		/**
		* Get instance
		*
		* @return UI_Memberships_
		*/
		public static function get_instance() {
			if (is_null(self::$instance)) {
				self::$instance = new self();
			}
			return self::$instance;
		}		
		
		
		public function __construct()
		{			
			function wpse27856_set_content_type(){
				return "text/html";
			}
			add_filter( 'wp_mail_content_type','wpse27856_set_content_type' );
			add_action(self::UI_MEMBERSHIPS_CRON_HOOK, array($this, 'run_daily_update'));
		}

		public function shortcode_init() {
			add_shortcode('ui_memberships', array($this, 'run_update'));
		}
		
		public function run_daily_update() {
			// This function will be executed daily
			$this->run_update();
		}

		public function run_update()
		{			
			$this->lgl = LGL_API::get_instance();
			$this->lgl->helper->debug('Running Memberships');
			$this->lgl->helper->debug('ui_memberships CRON HOOK', self::UI_MEMBERSHIPS_CRON_HOOK);
			
			$ui_users = UI_Memberships_WP_Users::get_instance();
			$ui_users->list_ui_members();
		}
	}
}