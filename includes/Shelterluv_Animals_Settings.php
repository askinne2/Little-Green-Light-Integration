<?php

if (!class_exists('Shelterluv_Animals_Settings')) {
	class Shelterluv_Animals_Settings
	{

		const SLUG = "Shelterluv-Animals-options";
		const UPDATESLUG = "Shelterluv-Animals-update";

		/**
		 * Construct the plugin object
		 */
		public function __construct($plugin)
		{
			// register actions
			//$this->add_my_options_page();
			acf_add_options_page(array(
				'page_title' => __('Shelterluv Settings', 'custom'),
				'menu_title' => __('Shelterluv', 'custom'),
				'menu_slug' => self::SLUG,
				'capability' => 'manage_options',
				'redirect' => false,
			));
			add_action('init', array(&$this, "init"));
			add_action('admin_menu', array(&$this, 'admin_menu'), 20);
			add_action('admin_menu', array(&$this, 'update_menu'), 20);
			//add_filter("plugin_action_links_$plugin", array(&$this, 'plugin_settings_link'));
			//add_filter("plugin_action_links_$plugin", array(&$this, 'update_settings_link'));
			add_action( 'admin_init', array( &$this, 'add_acf_variables' ) );

			//$this->init();
		} // END public function __construct

		/**
		 * Add options page
		 */
		public function admin_menu()
		{
			// Duplicate link into properties mgmt
			add_submenu_page(
				self::SLUG,
				__('Settings', 'custom'),
				__('Settings', 'custom'),
				'manage_options',
				self::SLUG,
				1
			);
		}
		public function update_menu()
		{
			// Duplicate link into properties mgmt
			add_submenu_page(
				self::UPDATESLUG,
				__('Options', 'custom'),
				__('Options', 'custom'),
				'manage_options',
				self::UPDATESLUG,
				1
			);
		}

		public function add_acf_variables() {
			acf_form_head();
		}
		/**
		 * Add settings fields via ACF
		 */
		public function init()
		{
			if (function_exists('acf_add_local_field_group')) :

				acf_add_local_field_group(
					array(
					'key' => 'group_6069369c504fd',
					'title' => 'Connection Settings',
					'fields' => array(
						array(
							'key' => 'field_633749f3f4480',
							'label' => 'API Key',
							'name' => 'api_key',
							'type' => 'password',
							'instructions' => 'Please enter your API Key from Shelterluv',
							'required' => 1,
							'conditional_logic' => 0,
							'wrapper' => array(
								'width' => '',
								'class' => '',
								'id' => '',
							),
							'default_value' => '',
							'placeholder' => '',
							'prepend' => '',
							'append' => '',
							'maxlength' => '',
						),
					),
					
					'location' => array(
						array(
							array(
								'param' => 'options_page',
								'operator' => '==',
								'value' => self::SLUG,
							),
						),
					),
					'menu_order' => 0,
					'position' => 'normal',
					'style' => 'default',
					'label_placement' => 'top',
					'instruction_placement' => 'label',
					'hide_on_screen' => '',
					'active' => true,
					'description' => '',
				));

				acf_add_local_field_group(
					array(
					'key' => 'animal_status',
					'title' => 'Animal Statuses',
					'fields' => array(
						array(
							'key' => 'animal_status1',
							'label' => 'Animal Status 1',
							'name' => 'animal_status1',
							'type' => 'text',
							'instructions' => 'Enter adoptable animal\'s status',
							'required' => 0,
							'conditional_logic' => 0,
							'wrapper' => array(
								'width' => '',
								'class' => '',
								'id' => '',
							),
							'default_value' => 'Available For Adoption',
							'placeholder' => '',
							'prepend' => '',
							'append' => '',
							'maxlength' => '',
						),
						array(
							'key' => 'animal_status2',
							'label' => 'Animal Status 2',
							'name' => 'animal_status2',
							'type' => 'text',
							'instructions' => 'Enter adoptable animal\'s status',
							'required' => 0,
							'conditional_logic' => 0,
							'wrapper' => array(
								'width' => '',
								'class' => '',
								'id' => '',
							),
							'default_value' => 'Available for Adoption - Awaiting Spay/Neuter',
							'placeholder' => '',
							'prepend' => '',
							'append' => '',
							'maxlength' => '',
						),
						array(
							'key' => 'animal_status3',
							'label' => 'Animal Status 3',
							'name' => 'animal_status3',
							'type' => 'text',
							'instructions' => 'Enter adoptable animal\'s status',
							'required' => 0,
							'conditional_logic' => 0,
							'wrapper' => array(
								'width' => '',
								'class' => '',
								'id' => '',
							),
							'default_value' => 'Available for Adoption - In Foster',
							'placeholder' => '',
							'prepend' => '',
							'append' => '',
							'maxlength' => '',
						),
						array(
							'key' => 'animal_status4',
							'label' => 'Animal Status 4',
							'name' => 'animal_status4',
							'type' => 'text',
							'instructions' => 'Enter adoptable animal\'s status',
							'required' => 0,
							'conditional_logic' => 0,
							'wrapper' => array(
								'width' => '',
								'class' => '',
								'id' => '',
							),
							'default_value' => 'Awaiting Spay/Neuter - In Foster',
							'placeholder' => '',
							'prepend' => '',
							'append' => '',
							'maxlength' => '',
						),
					),
					
					'location' => array(
						array(
							array(
								'param' => 'options_page',
								'operator' => '==',
								'value' => self::SLUG,
							),
						),
					),
					'menu_order' => 1,
					'position' => 'normal',
					'style' => 'default',
					'label_placement' => 'top',
					'instruction_placement' => 'label',
					'hide_on_screen' => '',
					'active' => true,
					'description' => '',
				));				

			endif;
			
		}

		/**
		 * 
		 * modify the plugin settings page
		 */
		public function plugin_settings_page_content() {
			do_action('acf/input/admin_head'); // Add ACF admin head hooks
			do_action('acf/input/admin_enqueue_scripts'); // Add ACF scripts
		
			$options = array(
				'id' => 'acf-form',
				'post_id' => 'options',
				'new_post' => false,
				'field_groups' => array( 'group_6069369c504fd' ),
				'return' => admin_url(sprintf('admin.php?page=%s', self::SLUG)),
				'submit_value' => 'Update',
			);
			acf_form( $options );
		}
		/**
		 * Add the settings link to the plugins page
		 */
	/*	public function plugin_settings_link($links)
		{
			$settings_link = sprintf('<a href="admin.php?page=%s">Settings</a>', self::SLUG);
			array_unshift($links, $settings_link);
			return $links;
		} // END public function plugin_settings_link($links)
		public function update_settings_link($links)
		{
			$update_link = sprintf('<a href="admin.php?page=%s">Update</a>', self::UPDATESLUG);
			array_unshift($links, $update_link);
			return $links;
		} // END public function plugin_settings_link($links)
		*/
	} // END class Shelterluv_Animals_Settings

} // END if(!class_exists('Shelterluv_Animals_Settings'))