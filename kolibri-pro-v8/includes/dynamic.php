<?php
add_shortcode('kolibri_object_dynamic','kolibri_object_dynamic');
function kolibri_object_dynamic(){
return '<div id="kolibri-dynamic-object"></div>';
}

add_action('wp_ajax_kolibri_load_object','kolibri_load_object');
add_action('wp_ajax_nopriv_kolibri_load_object','kolibri_load_object');

function kolibri_load_object(){
$id=intval($_POST['id']);
global $post;
$post=get_post($id);
if(!$post){ wp_die(); }
setup_postdata($post);
echo '<h2>'.esc_html($post->post_title).'</h2>';
echo do_shortcode('[kolibri_slider]');
echo do_shortcode('[kolibri_features]');
echo apply_filters('the_content',$post->post_content);
echo do_shortcode('[kolibri_map]');
wp_reset_postdata();
wp_die();
}
