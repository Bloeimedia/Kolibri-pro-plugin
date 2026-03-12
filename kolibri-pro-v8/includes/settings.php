<?php
add_action('admin_post_kolibri_clear_cache','kolibri_handle_clear_cache');

function kolibri_handle_clear_cache(){
if(!current_user_can('manage_options')){ wp_die('Onvoldoende rechten.'); }
check_admin_referer('kolibri_clear_cache','kolibri_clear_cache_nonce');

kolibri_clear_known_caches();

$redirect=add_query_arg(
['page'=>'kolibri-settings','kolibri_cache_message'=>'Cache is geleegd.'],
admin_url('admin.php')
);
wp_safe_redirect($redirect);
exit;
}

function kolibri_clear_known_caches(){
if(function_exists('wp_cache_flush')){
wp_cache_flush();
}

global $wpdb;
if(isset($wpdb) && $wpdb){
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");
}

if(function_exists('opcache_reset')){
@opcache_reset();
}

if(function_exists('w3tc_flush_all')){ @w3tc_flush_all(); }
if(function_exists('rocket_clean_domain')){ @rocket_clean_domain(); }
if(function_exists('wpfc_clear_all_cache')){ @wpfc_clear_all_cache(true); }
if(function_exists('sg_cachepress_purge_cache')){ @sg_cachepress_purge_cache(); }
if(function_exists('litespeed_purge_all')){ @litespeed_purge_all(); }
if(class_exists('autoptimizeCache') && method_exists('autoptimizeCache','clearall')){ @autoptimizeCache::clearall(); }
if(function_exists('fusion_reset_all_caches')){ @fusion_reset_all_caches(); }
if(function_exists('fusion_clear_all_caches')){ @fusion_clear_all_caches(); }
if(function_exists('avada_clear_all_caches')){ @avada_clear_all_caches(); }

do_action('litespeed_purge_all');
do_action('w3tc_flush_all');
do_action('wpfc_clear_all_cache');
do_action('rocket_clean_domain');
do_action('fusion_reset_all_caches');
do_action('avada_clear_all_caches');
}

function kolibri_get_options(){
$defaults=[
'slider_height'=>360,
'show_price_overlay'=>1,
'sitelink_token'=>'',
'sync_source_url'=>'',
'lite_enabled'=>0,
'lite_source_url'=>'',
'lite_source_auth_token'=>'',
'lite_pararius_url'=>'https://www.pararius.nl/makelaars/woltersum/vici-vastgoed',
'lite_manual_json'=>'',
'lite_cache_minutes'=>30,
'lite_use_for_list'=>0,
];
$saved=get_option('kolibri_options',[]);
if(!is_array($saved)){ $saved=[]; }
return wp_parse_args($saved,$defaults);
}

function kolibri_get_option($key,$default=''){
$options=kolibri_get_options();
return array_key_exists($key,$options) ? $options[$key] : $default;
}

add_action('admin_init',function(){
register_setting('kolibri_settings_group','kolibri_options','kolibri_sanitize_options');
});

function kolibri_sanitize_options($input){
$input=is_array($input) ? $input : [];
$clean=kolibri_get_options();

if(array_key_exists('slider_height',$input)){
$clean['slider_height']=(int)$input['slider_height'];
if($clean['slider_height']<200){ $clean['slider_height']=200; }
if($clean['slider_height']>1200){ $clean['slider_height']=1200; }
}

if(array_key_exists('show_price_overlay',$input)){
$clean['show_price_overlay']=!empty($input['show_price_overlay']) ? 1 : 0;
}

if(array_key_exists('sitelink_token',$input)){
$clean['sitelink_token']=trim((string)$input['sitelink_token']);
}

if(array_key_exists('sync_source_url',$input)){
$clean['sync_source_url']=esc_url_raw(trim((string)$input['sync_source_url']));
}

if(array_key_exists('lite_enabled',$input)){
$clean['lite_enabled']=!empty($input['lite_enabled']) ? 1 : 0;
}

if(array_key_exists('lite_source_url',$input)){
$clean['lite_source_url']=esc_url_raw(trim((string)$input['lite_source_url']));
}

if(array_key_exists('lite_source_auth_token',$input)){
$clean['lite_source_auth_token']=trim((string)$input['lite_source_auth_token']);
}

if(array_key_exists('lite_pararius_url',$input)){
$clean['lite_pararius_url']=esc_url_raw(trim((string)$input['lite_pararius_url']));
}

if(array_key_exists('lite_manual_json',$input)){
$clean['lite_manual_json']=trim((string)$input['lite_manual_json']);
}

if(array_key_exists('lite_cache_minutes',$input)){
$clean['lite_cache_minutes']=(int)$input['lite_cache_minutes'];
if($clean['lite_cache_minutes']<5){ $clean['lite_cache_minutes']=5; }
if($clean['lite_cache_minutes']>1440){ $clean['lite_cache_minutes']=1440; }
}

if(array_key_exists('lite_use_for_list',$input)){
$clean['lite_use_for_list']=!empty($input['lite_use_for_list']) ? 1 : 0;
}

return $clean;
}

add_action('admin_menu',function(){
add_menu_page('Kolibri','Kolibri','manage_options','kolibri-settings','kolibri_settings_page','dashicons-admin-home',25);
});

function kolibri_settings_page(){
$options=kolibri_get_options();
?>
<div class="wrap">
<h1>Kolibri instellingen</h1>
<form method="post" action="options.php" style="margin:20px 0;">
<?php settings_fields('kolibri_settings_group'); ?>
<table class="form-table">
<tr>
<th scope="row"><label for="kolibri-slider-height">Slider hoogte (px)</label></th>
<td>
<input type="number" min="200" max="1200" step="10" id="kolibri-slider-height" name="kolibri_options[slider_height]" value="<?php echo esc_attr((int)$options['slider_height']); ?>">
<p class="description">Wordt gebruikt voor de object-slider op objectpagina's en dynamische weergave.</p>
</td>
</tr>
<tr>
<th scope="row">Prijs overlay op kaart</th>
<td>
<label>
<input type="hidden" name="kolibri_options[show_price_overlay]" value="0">
<input type="checkbox" name="kolibri_options[show_price_overlay]" value="1" <?php checked(!empty($options['show_price_overlay'])); ?>>
Toon prijs over de foto op de object-kaarten in [kolibri_list]
</label>
</td>
</tr>
<tr>
<th scope="row"><label for="kolibri-sitelink-token">Kolibri SiteLink token</label></th>
<td>
<input type="text" class="regular-text" id="kolibri-sitelink-token" name="kolibri_options[sitelink_token]" value="<?php echo esc_attr((string)$options['sitelink_token']); ?>" autocomplete="off" spellcheck="false">
<p class="description">Token uit Kolibri CRM voor SiteLink v3. Als dit veld is ingevuld, gebruikt de sync automatisch SiteLink: https://sitelink.kolibri24.com/v3/&lt;TOKEN&gt;/zip</p>
</td>
</tr>
<tr>
<th scope="row"><label for="kolibri-sync-source-url">Bron URL voor sync</label></th>
<td>
<input type="url" class="regular-text" id="kolibri-sync-source-url" name="kolibri_options[sync_source_url]" value="<?php echo esc_attr((string)$options['sync_source_url']); ?>" placeholder="https://voorbeeld.nl/objecten.json">
<p class="description">Optioneel JSON endpoint met objectenlijst. Wordt alleen gebruikt als SiteLink token leeg is.</p>
</td>
</tr>
</table>
<?php submit_button('Instellingen opslaan'); ?>
</form>

<hr>
<h2>Lite modus</h2>
<form method="post" action="options.php" style="margin:20px 0;">
<?php settings_fields('kolibri_settings_group'); ?>
<table class="form-table">
<tr>
<th scope="row">Lite modus actief</th>
<td>
<label>
<input type="hidden" name="kolibri_options[lite_enabled]" value="0">
<input type="checkbox" name="kolibri_options[lite_enabled]" value="1" <?php checked(!empty($options['lite_enabled'])); ?>>
Activeer externe kaartdata voor Lite weergave
</label>
</td>
</tr>
<tr>
<th scope="row">Gebruik Lite voor [kolibri_list]</th>
<td>
<label>
<input type="hidden" name="kolibri_options[lite_use_for_list]" value="0">
<input type="checkbox" name="kolibri_options[lite_use_for_list]" value="1" <?php checked(!empty($options['lite_use_for_list'])); ?>>
Toon externe Lite-kaarten in bestaande [kolibri_list] shortcode
</label>
</td>
</tr>
<tr>
<th scope="row"><label for="kolibri-lite-source-url">Lite bron URL (JSON)</label></th>
<td>
<input type="url" class="regular-text" id="kolibri-lite-source-url" name="kolibri_options[lite_source_url]" value="<?php echo esc_attr((string)$options['lite_source_url']); ?>" placeholder="https://example.com/listings.json">
<p class="description">Externe bron met objecten. Verwachte velden per item: title, price, image, url (of vergelijkbare namen). Laat leeg om Pararius bridge te gebruiken.</p>
<p class="description">Plugin JSON output endpoint: <code><?php echo esc_html(rest_url('kolibri/v1/lite-feed')); ?></code> (voor extern gebruik, niet als bron invullen).</p>
<p class="description">Test endpoint: <code><?php echo esc_html(rest_url('kolibri/v1/lite-sample')); ?></code></p>
</td>
</tr>
<tr>
<th scope="row"><label for="kolibri-lite-source-auth-token">Lite bron Bearer token</label></th>
<td>
<input type="text" class="regular-text code" id="kolibri-lite-source-auth-token" name="kolibri_options[lite_source_auth_token]" value="<?php echo esc_attr((string)$options['lite_source_auth_token']); ?>" autocomplete="off" spellcheck="false" placeholder="Optioneel">
<p class="description">Optioneel token voor beveiligde externe scraper-feed. Wordt gestuurd als header: <code>Authorization: Bearer &lt;token&gt;</code>.</p>
</td>
</tr>
<tr>
<th scope="row"><label for="kolibri-lite-pararius-url">Pararius pagina URL (bridge)</label></th>
<td>
<input type="url" class="regular-text" id="kolibri-lite-pararius-url" name="kolibri_options[lite_pararius_url]" value="<?php echo esc_attr((string)$options['lite_pararius_url']); ?>" placeholder="https://www.pararius.nl/makelaars/woltersum/vici-vastgoed">
<p class="description">Wordt gebruikt als Lite bron URL leeg is. Bridge endpoint: <code><?php echo esc_html(rest_url('kolibri/v1/pararius-feed')); ?></code></p>
</td>
</tr>
<tr>
<th scope="row"><label for="kolibri-lite-cache-minutes">Lite cache (minuten)</label></th>
<td>
<input type="number" min="5" max="1440" step="5" id="kolibri-lite-cache-minutes" name="kolibri_options[lite_cache_minutes]" value="<?php echo esc_attr((int)$options['lite_cache_minutes']); ?>">
</td>
</tr>
<tr>
<th scope="row"><label for="kolibri-lite-manual-json">Handmatige Lite kaarten (JSON)</label></th>
<td>
<textarea id="kolibri-lite-manual-json" name="kolibri_options[lite_manual_json]" rows="8" class="large-text code"><?php echo esc_textarea((string)$options['lite_manual_json']); ?></textarea>
<p class="description">Optioneel. Gebruik array of object met <code>cards</code>. Velden per kaart: <code>title</code>, <code>url</code>, <code>price</code>, <code>image</code>.</p>
</td>
</tr>
</table>
<?php submit_button('Lite instellingen opslaan'); ?>
</form>

<hr>
<h2>Synchronisatie</h2>
<?php if(isset($_GET['kolibri_sync_message'])): ?>
<div class="notice notice-info"><p><?php echo esc_html(wp_unslash($_GET['kolibri_sync_message'])); ?></p></div>
<?php endif; ?>
<?php if(function_exists('kolibri_is_sitelink_rate_limited') && kolibri_is_sitelink_rate_limited()): ?>
<div class="notice notice-warning"><p><?php echo esc_html('SiteLink daglimiet actief. Volgende sync mogelijk na '.kolibri_get_next_sitelink_allowed_local_text().'.'); ?></p></div>
<?php endif; ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<input type="hidden" name="action" value="kolibri_sync_objects">
<?php wp_nonce_field('kolibri_sync_objects','kolibri_sync_nonce'); ?>
<?php submit_button('Controleer nieuwe objecten en synchroniseer','secondary','submit',false); ?>
</form>

<hr>
<h2>Cache</h2>
<?php if(isset($_GET['kolibri_cache_message'])): ?>
<div class="notice notice-info"><p><?php echo esc_html(wp_unslash($_GET['kolibri_cache_message'])); ?></p></div>
<?php endif; ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<input type="hidden" name="action" value="kolibri_clear_cache">
<?php wp_nonce_field('kolibri_clear_cache','kolibri_clear_cache_nonce'); ?>
<?php submit_button('Leeg cache','secondary','submit',false); ?>
</form>
<h2>Shortcode Cheat Sheet</h2>
<pre>
[kolibri_list]
[kolibri_list_lite]
[kolibri_slider]
[kolibri_object]
[kolibri_object_dynamic]
[kolibri_gallery]
[kolibri_map]
[kolibri_features]
[kolibri_field field="prijs"]
[kolibri_field field="plaats"]
[kolibri_field field="kamers"]
[kolibri_field field="oppervlakte"]
</pre>
</div>
<?php
}
