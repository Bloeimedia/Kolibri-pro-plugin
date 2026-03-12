<?php
/*
Plugin Name: Kolibri Pro
Version: 8.0
*/
if(!defined('ABSPATH')) exit;

register_deactivation_hook(__FILE__,function(){
wp_clear_scheduled_hook('kolibri_auto_sync_event');
});

require_once plugin_dir_path(__FILE__).'includes/posttype.php';
require_once plugin_dir_path(__FILE__).'includes/settings.php';
require_once plugin_dir_path(__FILE__).'includes/sync.php';
require_once plugin_dir_path(__FILE__).'includes/shortcodes.php';
require_once plugin_dir_path(__FILE__).'includes/lite-proxy.php';
require_once plugin_dir_path(__FILE__).'includes/dynamic.php';

add_action('wp_enqueue_scripts',function(){
wp_enqueue_style('kolibri-style',plugin_dir_url(__FILE__).'assets/style.css');
wp_enqueue_script('kolibri-js',plugin_dir_url(__FILE__).'assets/script.js',['jquery'],false,true);
wp_localize_script('kolibri-js','kolibri_ajax',['ajaxurl'=>admin_url('admin-ajax.php')]);
});
