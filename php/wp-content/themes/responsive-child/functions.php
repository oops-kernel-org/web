<?php
/**
 * Function template
 *
 * @file           functions.php
 * @theme          oops.kernel.org, child of Responsive
 * @author         Petr Oros, Anton Arapov
 * @version        Release: 0.1
 * @filesource     wp-content/themes/responsive-child/functions.php
 * @description    kernel oops register scripts
 */

function register_custom_jquery() {
	wp_enqueue_script('oops_functions', get_stylesheet_directory_uri() . '/core/js/oops-functions.js', array('jquery'));
	wp_enqueue_script('auto_complete', get_stylesheet_directory_uri() . '/core/js/jquery.autocomplete.js', array('jquery'));
}
add_action('init', 'register_custom_jquery');

function add_js_to_page() {
	wp_enqueue_script( 'jquery' );
}
add_action('wp_enqueue_scripts', 'add_js_to_page');

?>
