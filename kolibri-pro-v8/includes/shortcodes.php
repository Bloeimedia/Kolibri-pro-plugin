<?php
function kolibri_is_likely_image_url($url){
$url=(string)$url;
if($url===''){ return false; }
$path=(string)parse_url($url,PHP_URL_PATH);
if(preg_match('/\.(jpg|jpeg|png|gif|webp|avif|heic)$/i',$path)){ return true; }
if(strpos($path,'/wp-content/uploads/')!==false){ return true; }
return false;
}

function kolibri_image_base_key($url){
$url=(string)$url;
$path=(string)parse_url($url,PHP_URL_PATH);
$path=preg_replace('/\/+/','/',$path);
$dirname=strtolower((string)pathinfo($path,PATHINFO_DIRNAME));
$dirname=trim((string)$dirname,'/');
$filename=(string)pathinfo($path,PATHINFO_FILENAME);
$ext=strtolower((string)pathinfo($path,PATHINFO_EXTENSION));
$name=strtolower($filename);
$name=preg_replace('/([_-](?:thumb|thumbnail|small|medium|large|orig|original|full|xl|xxl|hq|lq|hd|sd|\d{2,4}x\d{2,4}|w\d{2,4}|h\d{2,4}))+$/i','',$name);
$name=preg_replace('/([_-]\d{2,4}x\d{2,4})+/i','',$name);
$name=preg_replace('/[_-]+/','-',$name);
$name=trim((string)$name,'-');
if($name===''){ $name=strtolower($filename); }

$query=(string)parse_url($url,PHP_URL_QUERY);
$query_suffix='';
if($query!==''){
parse_str($query,$qparts);
if(is_array($qparts) && !empty($qparts)){
unset($qparts['w'],$qparts['h'],$qparts['width'],$qparts['height'],$qparts['fit'],$qparts['crop'],$qparts['quality'],$qparts['q']);
ksort($qparts);
if(!empty($qparts)){
$query_suffix='?'.http_build_query($qparts,'','&',PHP_QUERY_RFC3986);
}
}
}

if($dirname==='.' || $dirname===''){ $dirname=''; }
return trim($dirname.'/'.$name.'.'.$ext,'/').$query_suffix;
}

function kolibri_image_quality_score($url){
$score=0;
$url_lc=strtolower((string)$url);

if(preg_match_all('/(\d{2,4})x(\d{2,4})/',$url_lc,$matches,PREG_SET_ORDER)){
foreach($matches as $m){
$score=max($score,((int)$m[1])*((int)$m[2]));
}
}
if(preg_match('/[?&](?:w|width)=(\d{2,4})/i',$url_lc,$mw) && preg_match('/[?&](?:h|height)=(\d{2,4})/i',$url_lc,$mh)){
$score=max($score,((int)$mw[1])*((int)$mh[1]));
}

if(strpos($url_lc,'thumb')!==false || strpos($url_lc,'thumbnail')!==false || strpos($url_lc,'small')!==false){ $score-=300000; }
if(strpos($url_lc,'medium')!==false){ $score-=100000; }
if(strpos($url_lc,'large')!==false || strpos($url_lc,'original')!==false || strpos($url_lc,'full')!==false){ $score+=200000; }

return $score;
}

function kolibri_deduplicate_image_urls($urls){
$clean_urls=[];
foreach((array)$urls as $url){
$normalized=esc_url_raw((string)$url);
if($normalized!==''){ $clean_urls[]=$normalized; }
}

$ranked=[];
foreach($clean_urls as $url){
$key=kolibri_image_base_key($url);
$score=kolibri_image_quality_score($url);
if(!isset($ranked[$key]) || $score>$ranked[$key]['score']){
$ranked[$key]=['url'=>$url,'score'=>$score];
}
}
$result=[];
foreach($ranked as $item){ $result[]=$item['url']; }
return array_values(array_unique($result));
}

function kolibri_format_price_pm($value){
$raw=trim((string)$value);
if($raw===''){ return ''; }

$digits=preg_replace('/[^\d]/','',$raw);
if($digits===''){ return $raw; }

$amount=(int)$digits;
if($amount<=0){ return $raw; }

return '€ '.number_format($amount,0,',','.').' p/m';
}

function kolibri_extract_image_urls_from_mixed($value,&$urls){
if(is_array($value)){
foreach($value as $item){ kolibri_extract_image_urls_from_mixed($item,$urls); }
return;
}
if(is_object($value)){
foreach((array)$value as $item){ kolibri_extract_image_urls_from_mixed($item,$urls); }
return;
}
$value=(string)$value;
if($value===''){ return; }
preg_match_all('/https?:\/\/[^\s"\']+/i',$value,$matches);
if(!empty($matches[0])){
foreach($matches[0] as $url){
$url=rtrim($url,',.)]');
if(kolibri_is_likely_image_url($url)){ $urls[]=$url; }
}
}
}

function kolibri_collect_image_urls($post_id){
$post_id=(int)$post_id;
if(!$post_id){ return []; }

$remote_images=get_post_meta($post_id,'kolibri_remote_images',true);
if(is_array($remote_images) && !empty($remote_images)){
return kolibri_deduplicate_image_urls($remote_images);
}

$urls=[];
$featured_id=(int)get_post_thumbnail_id($post_id);
if($featured_id){
$featured_url=wp_get_attachment_image_url($featured_id,'large');
if($featured_url){ $urls[]=$featured_url; }
}

// Pick up image-like data from image-related meta and parse IDs from image-related keys.
$meta=get_post_meta($post_id);
foreach($meta as $key=>$values){
$is_image_key=(bool)preg_match('/gallery|images?|fotos?|photos?|media|afbeeld|remote_images?/i',$key);
if(!$is_image_key){ continue; }
foreach((array)$values as $value){
if(is_serialized($value)){ $value=maybe_unserialize($value); }

$meta_urls=[];
kolibri_extract_image_urls_from_mixed($value,$meta_urls);
foreach($meta_urls as $meta_url){ $urls[]=$meta_url; }

$flat_value=is_scalar($value) ? (string)$value : wp_json_encode($value);
preg_match_all('/\d+/',(string)$flat_value,$matches);
if(!empty($matches[0])){
foreach($matches[0] as $id){
$img_url=wp_get_attachment_image_url((int)$id,'large');
if($img_url){ $urls[]=$img_url; }
}
}
}
}

$post=get_post($post_id);
$content=$post ? (string)$post->post_content : '';
if($content!==''){
preg_match_all('/ids=["\']([\d,\s]+)["\']/i',$content,$gallery_matches);
if(!empty($gallery_matches[1])){
foreach($gallery_matches[1] as $csv){
foreach(explode(',',$csv) as $id){
$img_url=wp_get_attachment_image_url((int)trim($id),'large');
if($img_url){ $urls[]=$img_url; }
}
}
}
preg_match_all('/wp-image-(\d+)/i',$content,$img_matches);
if(!empty($img_matches[1])){
foreach($img_matches[1] as $id){
$img_url=wp_get_attachment_image_url((int)$id,'large');
if($img_url){ $urls[]=$img_url; }
}
}
preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i',$content,$img_src_matches);
if(!empty($img_src_matches[1])){
foreach($img_src_matches[1] as $img_src){ $urls[]=$img_src; }
}
}

$attached=get_attached_media('image',$post_id);
foreach($attached as $img){
$img_url=wp_get_attachment_image_url((int)$img->ID,'large');
if($img_url){ $urls[]=$img_url; }
}

return kolibri_deduplicate_image_urls($urls);
}

function kolibri_extract_value_from_mixed($value){
if(is_scalar($value)){ return trim((string)$value); }
if(is_array($value)){
foreach($value as $entry){
$found=kolibri_extract_value_from_mixed($entry);
if($found!==''){ return $found; }
}
}
if(is_object($value)){ return kolibri_extract_value_from_mixed((array)$value); }
return '';
}

function kolibri_extract_value_by_keys($item,$keys){
if(!is_array($item)){ return ''; }
$lower_index=[];
foreach($item as $k=>$v){
$lk=strtolower((string)$k);
if($lk!=='' && !array_key_exists($lk,$lower_index)){
$lower_index[$lk]=$v;
}
}
foreach($keys as $key){
$raw=null;
if(array_key_exists($key,$item)){
$raw=$item[$key];
}else{
$lk=strtolower((string)$key);
if($lk!=='' && array_key_exists($lk,$lower_index)){
$raw=$lower_index[$lk];
}
}
if($raw===null){ continue; }
$value=kolibri_extract_value_from_mixed($raw);
if($value!==''){ return $value; }
}
return '';
}

function kolibri_collect_generic_urls_from_mixed($value,&$urls){
if(is_array($value)){
foreach($value as $entry){ kolibri_collect_generic_urls_from_mixed($entry,$urls); }
return;
}
if(is_object($value)){
foreach((array)$value as $entry){ kolibri_collect_generic_urls_from_mixed($entry,$urls); }
return;
}
$value=(string)$value;
if($value===''){ return; }
preg_match_all('/https?:\/\/[^\s"\']+/i',$value,$matches);
if(empty($matches[0])){ return; }
foreach($matches[0] as $url){
$url=esc_url_raw(rtrim($url,',.)]'));
if($url!==''){ $urls[]=$url; }
}
}

function kolibri_extract_first_image_from_item($item){
if(!is_array($item)){ return ''; }
$urls=[];
foreach(['image','image_url','img','photo','thumbnail','cover','pictures','images','photos','media'] as $key){
if(isset($item[$key])){ kolibri_extract_image_urls_from_mixed($item[$key],$urls); }
}
if(empty($urls)){ kolibri_extract_image_urls_from_mixed($item,$urls); }
$urls=kolibri_deduplicate_image_urls($urls);
return !empty($urls[0]) ? $urls[0] : '';
}

function kolibri_extract_first_non_image_url_from_item($item){
$urls=[];
kolibri_collect_generic_urls_from_mixed($item,$urls);
if(empty($urls)){ return ''; }
$urls=array_values(array_unique($urls));
foreach($urls as $url){
if(!kolibri_is_likely_image_url($url)){ return $url; }
}
return '';
}

function kolibri_lite_collect_candidate_items($node,&$items){
if(is_array($node)){
$has_url=array_key_exists('url',$node) || array_key_exists('link',$node) || array_key_exists('permalink',$node) || array_key_exists('@id',$node);
$has_title=array_key_exists('name',$node) || array_key_exists('title',$node) || array_key_exists('headline',$node) || array_key_exists('adres',$node) || array_key_exists('address',$node);
if($has_url && $has_title){ $items[]=$node; }
foreach($node as $value){ kolibri_lite_collect_candidate_items($value,$items); }
}
}

function kolibri_extract_lite_items_from_html($html){
$items=[];
if(!is_string($html) || $html===''){ return $items; }

preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',$html,$matches);
if(empty($matches[1])){ return $items; }

foreach($matches[1] as $json_block){
$data=json_decode(trim($json_block),true);
if(!is_array($data)){ continue; }
kolibri_lite_collect_candidate_items($data,$items);
}

return $items;
}

function kolibri_extract_lite_source_items($payload){
if(!is_array($payload)){ return []; }
if(array_keys($payload)===range(0,count($payload)-1)){ return $payload; }
foreach(['cards','items','objects','results','data','listings','properties'] as $key){
if(isset($payload[$key]) && is_array($payload[$key])){ return $payload[$key]; }
}
return [];
}

function kolibri_normalize_lite_item($item){
if(!is_array($item)){ return null; }

$title=kolibri_extract_value_by_keys($item,['title','name','naam','headline','adres','address','street']);
$url=kolibri_extract_value_by_keys($item,['url','link','permalink','detail_url','detailUrl','external_url','object_url','@id']);
if($url===''){ $url=kolibri_extract_first_non_image_url_from_item($item); }
if($url==='' || !wp_http_validate_url($url)){ return null; }

if($title===''){
$path=(string)parse_url($url,PHP_URL_PATH);
$title=trim((string)basename($path),'/');
if($title===''){ $title='Object'; }
}

$price=kolibri_extract_value_by_keys($item,['price','prijs','rent','rent_price','rental_price']);
$image=kolibri_extract_first_image_from_item($item);

return [
'title'=>$title,
'url'=>esc_url_raw($url),
'price'=>$price,
'image'=>$image!=='' ? esc_url_raw($image) : '',
];
}

function kolibri_get_lite_cards_from_html($html,$limit=30){
$source_items=kolibri_extract_lite_items_from_html($html);
if(empty($source_items)){ return []; }

$cards=[];
foreach($source_items as $raw_item){
$item=kolibri_normalize_lite_item($raw_item);
if(!$item){ continue; }
$cards[]=$item;
}
if(empty($cards)){ return []; }
return array_slice($cards,0,$limit);
}

function kolibri_get_lite_cards_from_page_url($page_url,$limit=30){
if($page_url==='' || !wp_http_validate_url($page_url)){ return []; }

$response=kolibri_lite_remote_get($page_url,'html');
if(is_wp_error($response)){ return []; }
if((int)wp_remote_retrieve_response_code($response)!==200){ return []; }

$body=(string)wp_remote_retrieve_body($response);
if($body===''){ return []; }

return kolibri_get_lite_cards_from_html($body,$limit);
}

function kolibri_lite_remote_get($url,$mode='json'){
$args=[
'timeout'=>20,
'user-agent'=>'Mozilla/5.0 (Kolibri-Lite)',
];

$headers=[];
if($mode==='json'){
$headers['Accept']='application/json, text/plain;q=0.9, */*;q=0.8';
$token=trim((string)kolibri_get_option('lite_source_auth_token',''));
if($token!==''){
$headers['Authorization']='Bearer '.$token;
}
}
if(!empty($headers)){
$args['headers']=$headers;
}

return wp_remote_get($url,$args);
}

function kolibri_get_lite_manual_cards($limit=30){
$raw=(string)kolibri_get_option('lite_manual_json','');
if(trim($raw)===''){ return []; }

$decoded=json_decode($raw,true);
if(!is_array($decoded)){ return []; }

$items=kolibri_extract_lite_source_items($decoded);
if(empty($items) && array_keys($decoded)===range(0,count($decoded)-1)){ $items=$decoded; }
if(empty($items)){ return []; }

$cards=[];
foreach($items as $raw_item){
$item=kolibri_normalize_lite_item($raw_item);
if(!$item){ continue; }
$cards[]=$item;
}
if(empty($cards)){ return []; }

return array_slice($cards,0,$limit);
}

function kolibri_get_lite_cards($force_refresh=false){
$manual_cards=kolibri_get_lite_manual_cards(30);
if(!empty($manual_cards)){ return $manual_cards; }

$source_url=(string)kolibri_get_option('lite_source_url','');
$pararius_url=(string)kolibri_get_option('lite_pararius_url','');

// Never use the sample endpoint as production source.
$normalized_source=strtolower((string)$source_url);
if(strpos($normalized_source,'/wp-json/kolibri/v1/lite-sample')!==false){
$source_url='';
}

$mode='json';
$active_url=$source_url;
if($active_url==='' || !wp_http_validate_url($active_url)){
$mode='html';
$active_url=$pararius_url;
}
if($active_url==='' || !wp_http_validate_url($active_url)){ return []; }
if($mode==='json'){
$self_feed=rest_url('kolibri/v1/lite-feed');
if(rtrim($active_url,'/')===rtrim($self_feed,'/')){ return []; }
}

$ttl=(int)kolibri_get_option('lite_cache_minutes',30);
if($ttl<5){ $ttl=5; }
if($ttl>1440){ $ttl=1440; }
$cache_key='kolibri_lite_cards_'.md5($mode.'|'.$active_url);
if(!$force_refresh){
$cached=get_transient($cache_key);
if(is_array($cached)){ return $cached; }
}

if($mode==='html'){
$cards=kolibri_get_lite_cards_from_page_url($active_url,30);
if(empty($cards)){ return []; }
set_transient($cache_key,$cards,$ttl*MINUTE_IN_SECONDS);
return $cards;
}

$response=kolibri_lite_remote_get($active_url,'json');
if(is_wp_error($response)){ return []; }
if((int)wp_remote_retrieve_response_code($response)!==200){ return []; }

$body=(string)wp_remote_retrieve_body($response);
$source_items=[];
$decoded=json_decode($body,true);
if(is_array($decoded)){
$source_items=kolibri_extract_lite_source_items($decoded);
}else{
$source_items=kolibri_extract_lite_items_from_html($body);
}
if(empty($source_items)){ return []; }

$cards=[];
foreach($source_items as $raw_item){
$item=kolibri_normalize_lite_item($raw_item);
if(!$item){ continue; }
$cards[]=$item;
}
if(empty($cards)){ return []; }

$cards=array_slice($cards,0,30);
set_transient($cache_key,$cards,$ttl*MINUTE_IN_SECONDS);
return $cards;
}

add_shortcode('kolibri_list_lite','kolibri_list_lite_shortcode');
function kolibri_list_lite_shortcode(){
if((int)kolibri_get_option('lite_enabled',0)!==1){ return ''; }
$cards=kolibri_get_lite_cards();
if(empty($cards)){ return ''; }

$show_price_overlay=(int)kolibri_get_option('show_price_overlay',1)===1;
$out='<div class="kolibri-grid kolibri-grid-lite">';
foreach($cards as $card){
$title=(string)$card['title'];
$url=(string)$card['url'];
$img=(string)$card['image'];
$price_label=kolibri_format_price_pm((string)$card['price']);

$out.='<a class="kolibri-card kolibri-card-link" href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer">';
if($img!==''){
$out.='<div class="kolibri-card-media">';
$out.='<img src="'.esc_url($img).'" alt="'.esc_attr($title).'">';
if($show_price_overlay && $price_label!==''){
$out.='<div class="kolibri-price-overlay">'.esc_html($price_label).'</div>';
}
$out.='</div>';
}
$out.='<h3>'.esc_html($title).'</h3>';
$out.='</a>';
}
$out.='</div>';
return $out;
}

add_shortcode('kolibri_list','kolibri_list_shortcode');
function kolibri_list_shortcode(){
if((int)kolibri_get_option('lite_use_for_list',0)===1){
return kolibri_list_lite_shortcode();
}
$q=new WP_Query(['post_type'=>'kolibri_object','posts_per_page'=>-1]);
$show_price_overlay=(int)kolibri_get_option('show_price_overlay',1)===1;
$out='<div class="kolibri-grid">';
while($q->have_posts()){ $q->the_post();
$img=get_the_post_thumbnail_url(get_the_ID(),'large');
if(!$img){
$remote_images=get_post_meta(get_the_ID(),'kolibri_remote_images',true);
if(is_array($remote_images) && !empty($remote_images[0])){
$img=esc_url_raw((string)$remote_images[0]);
}
}
$permalink=get_permalink(get_the_ID());
$price=get_post_meta(get_the_ID(),'kolibri_prijs',true);
$price_label=kolibri_format_price_pm($price);
$out.='<div class="kolibri-card kolibri-open" data-object="'.(int)get_the_ID().'" data-url="'.esc_url($permalink).'" data-open-mode="link">';
if($img){
$out.='<div class="kolibri-card-media">';
$out.='<img src="'.esc_url($img).'" alt="'.esc_attr(get_the_title()).'">';
if($show_price_overlay && $price_label!==''){
$out.='<div class="kolibri-price-overlay">'.esc_html($price_label).'</div>';
}
$out.='</div>';
}
$out.='<h3>'.esc_html(get_the_title()).'</h3>';
$out.='</div>';
}
$out.='</div>';
wp_reset_postdata();
return $out;
}

add_shortcode('kolibri_slider','kolibri_slider_shortcode');
function kolibri_slider_shortcode(){
$images=kolibri_collect_image_urls(get_the_ID());
if(empty($images)){ return ''; }

$slider_height=(int)kolibri_get_option('slider_height',360);
if($slider_height<200){ $slider_height=200; }
if($slider_height>1200){ $slider_height=1200; }

$gallery_id='kolibri-object-'.(int)get_the_ID();
$visible=array_slice($images,0,5);
$main=array_shift($visible);
$visible_side=$visible;
$remaining=array_slice($images,5);
$total_count=1+count($visible_side)+count($remaining);

$out='<div class="kolibri-funda-gallery" style="--kolibri-gallery-height:'.$slider_height.'px">';
$out.='<a class="kolibri-funda-main fusion-lightbox" href="'.esc_url($main).'" rel="iLightbox['.esc_attr($gallery_id).']" data-rel="iLightbox['.esc_attr($gallery_id).']">';
$out.='<img src="'.esc_url($main).'" alt="">';
$out.='</a>';

if(!empty($visible_side)){
$out.='<div class="kolibri-funda-side">';
foreach($visible_side as $index=>$url){
$is_last_visible=($index===count($visible_side)-1);
$extra_count=$is_last_visible ? max(0,$total_count-5) : 0;
$out.='<a class="kolibri-funda-thumb fusion-lightbox" href="'.esc_url($url).'" rel="iLightbox['.esc_attr($gallery_id).']" data-rel="iLightbox['.esc_attr($gallery_id).']">';
$out.='<img src="'.esc_url($url).'" alt="">';
if($extra_count>0){
$out.='<span class="kolibri-funda-more">+'.(int)$extra_count.'</span>';
}
$out.='</a>';
}
$out.='</div>';
}

foreach($remaining as $url){
$out.='<a class="kolibri-funda-hidden fusion-lightbox" style="display:none!important;" href="'.esc_url($url).'" rel="iLightbox['.esc_attr($gallery_id).']" data-rel="iLightbox['.esc_attr($gallery_id).']"></a>';
}

$out.='</div>';
return $out;
}

add_shortcode('kolibri_gallery','kolibri_gallery_shortcode');
function kolibri_gallery_shortcode(){
$images=kolibri_collect_image_urls(get_the_ID());
$out='<div class="kolibri-gallery">';
foreach($images as $url){
$out.='<img src="'.esc_url($url).'" alt="">';
}
$out.='</div>';
return $out;
}

add_shortcode('kolibri_features','kolibri_features_shortcode');
function kolibri_features_shortcode(){
$rooms=get_post_meta(get_the_ID(),'kolibri_kamers',true);
$area=get_post_meta(get_the_ID(),'kolibri_oppervlakte',true);
$price=kolibri_format_price_pm(get_post_meta(get_the_ID(),'kolibri_prijs',true));
$parts=[];
if($price!==''){ $parts[]=$price; }
if($area!==''){ $parts[]=$area.' m²'; }
if($rooms!==''){ $parts[]=$rooms.' kamers'; }
return '<div class="kolibri-features">'.implode(' • ',$parts).'</div>';
}

add_shortcode('kolibri_map','kolibri_map_shortcode');
function kolibri_map_shortcode(){
$address=get_post_meta(get_the_ID(),'kolibri_adres',true);
if(!$address){
$street=(string)get_post_meta(get_the_ID(),'kolibri_straat',true);
$number=(string)get_post_meta(get_the_ID(),'kolibri_huisnummer',true);
$city=(string)get_post_meta(get_the_ID(),'kolibri_plaats',true);
$address=trim(trim($street.' '.$number).', '.$city);
}
if(!$address){
$address=(string)get_post_meta(get_the_ID(),'kolibri_plaats',true);
}
return '<iframe class="kolibri-map" src="https://maps.google.com/maps?q='.urlencode($address).'&output=embed"></iframe>';
}

add_shortcode('kolibri_object','kolibri_object_shortcode');
function kolibri_object_shortcode(){
$out='';
$out.=do_shortcode('[kolibri_slider]');
$out.=do_shortcode('[kolibri_features]');
$out.=get_the_content();
$out.=do_shortcode('[kolibri_map]');
return $out;
}

add_shortcode('kolibri_field','kolibri_field_shortcode');
function kolibri_field_shortcode($atts){
$a=shortcode_atts(['field'=>''],$atts);
$value=get_post_meta(get_the_ID(),'kolibri_'.$a['field'],true);
return esc_html($value);
}

add_filter('the_content','kolibri_auto_render_single_object',12);
function kolibri_auto_render_single_object($content){
if(is_admin() || !is_singular('kolibri_object') || !in_the_loop() || !is_main_query()){
return $content;
}

global $post;
if(!$post){ return $content; }

$raw=(string)$post->post_content;
if(has_shortcode($raw,'kolibri_object') || has_shortcode($raw,'kolibri_slider')){
return $content;
}

$rendered=do_shortcode('[kolibri_object]');
return $rendered;
}
