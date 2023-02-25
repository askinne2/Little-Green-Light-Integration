<?php
/* adding for test */
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

function Shelterluv_Animals_stylesheet()
{
	wp_register_style('bootstrap_styles', 'https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta3/dist/css/bootstrap.min.css');
	wp_enqueue_style('bootstrap_styles');

	wp_register_style('Shelterluv_Animals_archive_styles', plugins_url(plugin_basename(__DIR__)) . '/css/taxonomy-adopt-animals.css');
	wp_enqueue_style('Shelterluv_Animals_archive_styles');

	wp_register_style('Shelterluv_Animals_styles', plugins_url(plugin_basename(__DIR__)) . '/css/shelterluv_animals.css');
	wp_enqueue_style('Shelterluv_Animals_styles');
};
add_action('wp_print_styles', 'Shelterluv_Animals_stylesheet');

function Shelterluv_Animals_scripts()
{

	wp_register_script('bootstrap_js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta3/dist/js/bootstrap.min.js');
	wp_enqueue_script('bootstrap_js');
};
add_action('wp_print_scripts', 'Shelterluv_Animals_scripts');
