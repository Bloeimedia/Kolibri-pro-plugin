<?php
add_action('admin_post_kolibri_sync_objects','kolibri_handle_manual_sync');
add_action('init','kolibri_schedule_auto_sync');
add_action('kolibri_auto_sync_event','kolibri_run_auto_sync');

function kolibri_sitelink_rate_limit_seconds(){
return DAY_IN_SECONDS;
}

function kolibri_get_sitelink_blocked_until_timestamp(){
return (int)get_option('kolibri_sitelink_blocked_until_ts',0);
}

function kolibri_set_sitelink_blocked_until_timestamp($timestamp){
$ts=max(0,(int)$timestamp);
update_option('kolibri_sitelink_blocked_until_ts',$ts,false);
return $ts;
}

function kolibri_clear_sitelink_blocked_until_timestamp(){
delete_option('kolibri_sitelink_blocked_until_ts');
}

function kolibri_get_next_sitelink_allowed_timestamp(){
$blocked_until=kolibri_get_sitelink_blocked_until_timestamp();
if($blocked_until<=time()){ return 0; }
return $blocked_until;
}

function kolibri_is_sitelink_rate_limited(){
$next=kolibri_get_next_sitelink_allowed_timestamp();
return $next>0 && time()<$next;
}

function kolibri_get_next_sitelink_allowed_local_text(){
$next=kolibri_get_next_sitelink_allowed_timestamp();
if($next<=0){ return ''; }
return wp_date('d-m-Y H:i',$next,wp_timezone());
}

function kolibri_next_auto_sync_timestamp($from_timestamp=null){
$timezone=wp_timezone();
if($from_timestamp===null){
$now=new DateTimeImmutable('now',$timezone);
}else{
$now=(new DateTimeImmutable('@'.(int)$from_timestamp))->setTimezone($timezone);
}

$next=$now->setTime(3,0,0);
if($next<=$now){
$next=$next->modify('+1 day');
}
return $next->getTimestamp();
}

function kolibri_schedule_auto_sync(){
$next=wp_next_scheduled('kolibri_auto_sync_event');
if($next){
$hour=(int)wp_date('G',$next,wp_timezone());
$minute=(int)wp_date('i',$next,wp_timezone());
if($hour===3 && $minute===0){ return; }
wp_clear_scheduled_hook('kolibri_auto_sync_event');
}
wp_schedule_single_event(kolibri_next_auto_sync_timestamp(),'kolibri_auto_sync_event');
}

function kolibri_run_auto_sync(){
kolibri_run_sync();
wp_clear_scheduled_hook('kolibri_auto_sync_event');
wp_schedule_single_event(kolibri_next_auto_sync_timestamp(),'kolibri_auto_sync_event');
}

function kolibri_handle_manual_sync(){
if(!current_user_can('manage_options')){ wp_die('Onvoldoende rechten.'); }
check_admin_referer('kolibri_sync_objects','kolibri_sync_nonce');

$result=kolibri_run_sync();
$message=kolibri_build_sync_message($result);
$redirect=add_query_arg(
['page'=>'kolibri-settings','kolibri_sync_message'=>$message],
admin_url('admin.php')
);
wp_safe_redirect($redirect);
exit;
}

function kolibri_build_sync_message($result){
if(!empty($result['error'])){ return 'Sync mislukt: '.$result['error']; }
	$message=sprintf(
'Sync klaar. Nieuw: %d, bijgewerkt: %d, verwijderd: %d, overgeslagen: %d.',
(int)$result['created'],
(int)$result['updated'],
(int)$result['deleted'],
(int)$result['skipped']
);

if(isset($result['source_count'])){
$source_count=(int)$result['source_count'];
$source_titles=[];
if(!empty($result['source_titles']) && is_array($result['source_titles'])){
$source_titles=array_values(array_filter(array_map('trim',$result['source_titles'])));
}
if(!empty($source_titles)){
$message.=' Bron: '.$source_count.' object(en) - '.implode(' | ',array_slice($source_titles,0,3)).'.';
}else{
$message.=' Bron: '.$source_count.' object(en).';
}
}

if(!empty($result['source_type'])){
$label=(string)$result['source_type']==='json' ? 'JSON' : 'SiteLink';
$message.=' Bron type: '.$label.'.';
}

if(!empty($result['source_warning'])){
$message.=' Let op: '.trim((string)$result['source_warning']);
}

return $message;
}

function kolibri_media_folder_name_from_external_id($external_id){
$name=strtolower(trim((string)$external_id));
$name=preg_replace('/[^a-z0-9_-]+/','-',$name);
$name=trim((string)$name,'-');
if($name===''){
$name='obj-'.substr(md5((string)$external_id),0,12);
}
return $name;
}

function kolibri_media_paths_for_external_id($external_id){
$uploads=wp_upload_dir();
$base_dir=trailingslashit((string)$uploads['basedir']).'kolibri';
$base_url=trailingslashit((string)$uploads['baseurl']).'kolibri';
$folder=kolibri_media_folder_name_from_external_id($external_id);
return [
'base_dir'=>$base_dir,
'base_url'=>$base_url,
'dir'=>trailingslashit($base_dir).$folder,
'url'=>trailingslashit($base_url).$folder,
];
}

function kolibri_delete_dir_recursive($dir){
$dir=(string)$dir;
if($dir==='' || !is_dir($dir)){ return; }
$items=scandir($dir);
if(!is_array($items)){ return; }
foreach($items as $item){
if($item==='.' || $item==='..'){ continue; }
$path=$dir.DIRECTORY_SEPARATOR.$item;
if(is_dir($path)){
kolibri_delete_dir_recursive($path);
continue;
}
@unlink($path);
}
@rmdir($dir);
}

function kolibri_delete_local_media_for_external_id($external_id){
$paths=kolibri_media_paths_for_external_id($external_id);
kolibri_delete_dir_recursive($paths['dir']);
}

function kolibri_cleanup_orphan_media_dirs($active_external_ids){
$uploads=wp_upload_dir();
$root=trailingslashit((string)$uploads['basedir']).'kolibri';
if(!is_dir($root)){ return; }

$allowed=[];
foreach((array)$active_external_ids as $external_id){
$allowed[kolibri_media_folder_name_from_external_id((string)$external_id)]=true;
}

$entries=scandir($root);
if(!is_array($entries)){ return; }
foreach($entries as $entry){
if($entry==='.' || $entry==='..'){ continue; }
$full=$root.DIRECTORY_SEPARATOR.$entry;
if(!is_dir($full)){ continue; }
if(isset($allowed[$entry])){ continue; }
kolibri_delete_dir_recursive($full);
}
}

function kolibri_is_likely_image_url_remote($url){
$url=trim((string)$url);
if($url==='' || !wp_http_validate_url($url)){ return false; }

$path=(string)parse_url($url,PHP_URL_PATH);
if($path==='' || $path==='/' || $path==='.') { return false; }
if(preg_match('/\.(php|asp|aspx|jsp|html?)$/i',$path)){ return false; }

if(preg_match('/\.(jpg|jpeg|png|gif|webp|avif|bmp|heic|heif|jfif)$/i',$path)){ return true; }
if(strpos($path,'/media/')!==false || strpos($path,'/image/')!==false || strpos($path,'/img/')!==false){ return true; }

return true;
}

function kolibri_guess_image_extension($url,$content_type=''){
$path=(string)parse_url((string)$url,PHP_URL_PATH);
$ext=strtolower((string)pathinfo($path,PATHINFO_EXTENSION));
if($ext!==''){ return '.'.$ext; }

$ct=strtolower(trim((string)$content_type));
if($ct!==''){
if(strpos($ct,'image/jpeg')!==false){ return '.jpg'; }
if(strpos($ct,'image/png')!==false){ return '.png'; }
if(strpos($ct,'image/webp')!==false){ return '.webp'; }
if(strpos($ct,'image/gif')!==false){ return '.gif'; }
if(strpos($ct,'image/avif')!==false){ return '.avif'; }
}
return '.jpg';
}

function kolibri_download_image_body($url,$referer=''){
$headers=[
'Accept'=>'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
];
$referer=trim((string)$referer);
if($referer!=='' && wp_http_validate_url($referer)){
$headers['Referer']=$referer;
}

$response=wp_remote_get($url,[
'timeout'=>30,
'redirection'=>5,
'headers'=>$headers,
'user-agent'=>'Mozilla/5.0 (Kolibri Sync)',
]);
if(is_wp_error($response)){ return null; }
if((int)wp_remote_retrieve_response_code($response)!==200){ return null; }

$content_type=(string)wp_remote_retrieve_header($response,'content-type');
if($content_type!=='' && stripos($content_type,'image/')!==0){ return null; }

$body=wp_remote_retrieve_body($response);
if(!is_string($body) || $body===''){ return null; }

return [
'body'=>$body,
'content_type'=>$content_type,
];
}

function kolibri_sync_local_media_for_post($post_id,$external_id,$remote_images,$referer=''){
$post_id=(int)$post_id;
$external_id=(string)$external_id;
$clean_remote=[];
foreach((array)$remote_images as $img){
$url=esc_url_raw(trim((string)$img));
if($url==='' || !kolibri_is_likely_image_url_remote($url)){ continue; }
$clean_remote[]=$url;
}
$clean_remote=array_values(array_unique($clean_remote));

if(empty($clean_remote)){
kolibri_delete_local_media_for_external_id($external_id);
delete_post_meta($post_id,'kolibri_remote_images_localized');
return [];
}

$paths=kolibri_media_paths_for_external_id($external_id);
wp_mkdir_p($paths['dir']);

$local_urls=[];
$keep_files=[];
$index=1;
foreach($clean_remote as $remote_url){
$download=kolibri_download_image_body($remote_url,$referer);
if(!$download){ continue; }

$ext=kolibri_guess_image_extension($remote_url,(string)$download['content_type']);
$filename=sprintf('%02d-%s%s',$index,substr(md5($remote_url),0,10),$ext);
$filepath=trailingslashit($paths['dir']).$filename;

if(@file_put_contents($filepath,$download['body'])===false){ continue; }
$keep_files[$filename]=true;
$local_urls[]=trailingslashit($paths['url']).$filename;
$index++;
}

if(is_dir($paths['dir'])){
$existing=scandir($paths['dir']);
if(is_array($existing)){
foreach($existing as $file){
if($file==='.' || $file==='..'){ continue; }
if(isset($keep_files[$file])){ continue; }
@unlink(trailingslashit($paths['dir']).$file);
}
}
}

if(empty($local_urls)){
return $clean_remote;
}

update_post_meta($post_id,'kolibri_remote_images_localized',1);
return $local_urls;
}

function kolibri_get_remote_items_from_payload($payload){
if(!is_array($payload)){ return []; }
if(array_keys($payload)===range(0,count($payload)-1)){ return $payload; }
foreach(['cards','items','objects','results','data','listings','properties'] as $key){
if(isset($payload[$key]) && is_array($payload[$key])){ return $payload[$key]; }
}
return [];
}

function kolibri_extract_images_from_remote_item($item){
$images=[];
if(!is_array($item)){ return $images; }

foreach(['images','photos','gallery','fotos','media'] as $key){
if(empty($item[$key])){ continue; }
$value=$item[$key];
if(is_string($value) && is_serialized($value)){ $value=maybe_unserialize($value); }

$queue=is_array($value) ? $value : [$value];
foreach($queue as $entry){
if(is_array($entry)){
foreach(['url','src','image','photo'] as $image_key){
if(!empty($entry[$image_key])){ $images[]=(string)$entry[$image_key]; }
}
continue;
}
if(is_string($entry)){ $images[]=$entry; }
}
}

foreach(['image','image_url','photo','thumbnail','cover'] as $key){
if(empty($item[$key])){ continue; }
$single=esc_url_raw((string)$item[$key]);
if($single!==''){ $images[]=$single; }
}

$clean=array_values(array_unique(array_filter(array_map('esc_url_raw',$images))));
return $clean;
}

function kolibri_normalize_remote_item($item){
if(!is_array($item)){ return null; }

$detail_url='';
foreach(['url','link','permalink','detail_url','detailUrl','external_url','object_url'] as $url_key){
if(empty($item[$url_key])){ continue; }
$maybe=esc_url_raw((string)$item[$url_key]);
if($maybe==='' || !wp_http_validate_url($maybe)){ continue; }
$detail_url=$maybe;
break;
}

$external_id='';
foreach(['id','external_id','object_id','uuid','reference','ref'] as $key){
if(!empty($item[$key])){ $external_id=(string)$item[$key]; break; }
}
if($external_id===''){
if($detail_url!==''){
$external_id='url_'.substr(md5(strtolower($detail_url)),0,16);
}
}
if($external_id===''){ return null; }

$title='';
foreach(['title','naam','name','adres','address','street'] as $key){
if(!empty($item[$key])){ $title=(string)$item[$key]; break; }
}
if($title===''){ $title='Object '.$external_id; }

$content='';
foreach(['content','description','omschrijving','body','text','summary','details'] as $key){
if(!empty($item[$key])){ $content=(string)$item[$key]; break; }
}

$meta_map=[
'kolibri_prijs'=>['prijs','price'],
'kolibri_plaats'=>['plaats','city','location'],
'kolibri_kamers'=>['kamers','rooms'],
'kolibri_oppervlakte'=>['oppervlakte','area','living_area','surface'],
];
$meta=[];
foreach($meta_map as $meta_key=>$candidates){
foreach($candidates as $candidate){
if(isset($item[$candidate]) && $item[$candidate]!==''){
$meta[$meta_key]=(string)$item[$candidate];
break;
}
}
}

$images=kolibri_extract_images_from_remote_item($item);
if(!empty($images)){ $meta['kolibri_remote_images']=$images; }
if($detail_url!==''){ $meta['kolibri_external_url']=$detail_url; }
if($title!==''){ $meta['kolibri_adres']=$title; }

if($content===''){
$pairs=[];
if(!empty($meta['kolibri_prijs'])){ $pairs['Prijs']=kolibri_sync_format_price_pm((string)$meta['kolibri_prijs']); }
if(!empty($meta['kolibri_plaats'])){ $pairs['Plaats']=(string)$meta['kolibri_plaats']; }
if(!empty($meta['kolibri_oppervlakte'])){ $pairs['Oppervlakte']=(string)$meta['kolibri_oppervlakte'].' m2'; }
if(!empty($meta['kolibri_kamers'])){ $pairs['Kamers']=(string)$meta['kolibri_kamers']; }
$description='';
if($detail_url!==''){
$description='Bekijk de volledige advertentie via de externe link.';
}
$content=kolibri_build_property_content_html($description,$pairs);
}

return [
'external_id'=>$external_id,
'title'=>$title,
'content'=>$content,
'meta'=>$meta,
];
}

function kolibri_run_sync(){
$sitelink_token=(string)kolibri_get_option('sitelink_token','');
$source_url=(string)kolibri_get_option('sync_source_url','');
$sync_payload=kolibri_get_normalized_items_for_sync($sitelink_token,$source_url);
if(!empty($sync_payload['error'])){ return ['error'=>$sync_payload['error']]; }
$source_type=isset($sync_payload['source_type']) ? (string)$sync_payload['source_type'] : '';
$source_warning=isset($sync_payload['source_warning']) ? (string)$sync_payload['source_warning'] : '';
$normalized=$sync_payload['items'];
$source_count=count($normalized);
$source_titles=[];
foreach($normalized as $item){
$title=isset($item['title']) ? trim((string)$item['title']) : '';
if($title!==''){ $source_titles[]=$title; }
if(count($source_titles)>=3){ break; }
}

$created=0;
$updated=0;
$skipped=0;
$deleted=0;

$existing=get_posts([
'post_type'=>'kolibri_object',
'post_status'=>'any',
'posts_per_page'=>-1,
'fields'=>'ids',
'meta_key'=>'_kolibri_external_id',
]);
$existing_by_external_id=[];
foreach($existing as $post_id){
$ext_id=(string)get_post_meta($post_id,'_kolibri_external_id',true);
if($ext_id!==''){ $existing_by_external_id[$ext_id]=(int)$post_id; }
}

foreach($normalized as $external_id=>$item){
$post_id=isset($existing_by_external_id[$external_id]) ? (int)$existing_by_external_id[$external_id] : 0;
$postarr=[
'post_type'=>'kolibri_object',
'post_title'=>$item['title'],
'post_name'=>sanitize_title($item['title']),
'post_content'=>$item['content'],
'post_status'=>'publish',
];

if($post_id>0){
$postarr['ID']=$post_id;
$result=wp_update_post($postarr,true);
if(is_wp_error($result)){ $skipped++; continue; }
$updated++;
}else{
$result=wp_insert_post($postarr,true);
if(is_wp_error($result)){ $skipped++; continue; }
$post_id=(int)$result;
$created++;
}

update_post_meta($post_id,'_kolibri_external_id',$external_id);
if(isset($item['meta']['kolibri_remote_images']) && is_array($item['meta']['kolibri_remote_images'])){
$referer=isset($item['meta']['kolibri_external_url']) ? (string)$item['meta']['kolibri_external_url'] : '';
$item['meta']['kolibri_remote_images']=kolibri_sync_local_media_for_post(
$post_id,
$external_id,
$item['meta']['kolibri_remote_images'],
$referer
);
}else{
kolibri_delete_local_media_for_external_id($external_id);
delete_post_meta($post_id,'kolibri_remote_images');
delete_post_meta($post_id,'kolibri_remote_images_localized');
}
foreach($item['meta'] as $meta_key=>$meta_value){
update_post_meta($post_id,$meta_key,$meta_value);
}
}

$remote_ids=array_keys($normalized);
foreach($existing_by_external_id as $external_id=>$post_id){
if(in_array($external_id,$remote_ids,true)){ continue; }
kolibri_delete_local_media_for_external_id($external_id);
wp_delete_post((int)$post_id,true);
$deleted++;
}
kolibri_cleanup_orphan_media_dirs($remote_ids);

if(function_exists('kolibri_clear_known_caches')){
kolibri_clear_known_caches();
}

return [
'created'=>$created,
'updated'=>$updated,
'deleted'=>$deleted,
'skipped'=>$skipped,
'source_count'=>$source_count,
'source_titles'=>$source_titles,
'source_type'=>$source_type,
'source_warning'=>$source_warning,
];
}

function kolibri_get_normalized_items_for_sync($sitelink_token,$source_url){
if($source_url!==''){
	$json_result=kolibri_get_normalized_items_from_json_endpoint($source_url);
	if(empty($json_result['error'])){
		$json_result['source_type']='json';
		return $json_result;
	}

	if($sitelink_token!==''){
		$sitelink_result=kolibri_get_normalized_items_from_sitelink($sitelink_token);
		if(empty($sitelink_result['error'])){
			$sitelink_result['source_type']='sitelink';
			$sitelink_result['source_warning']='JSON bron mislukte ('.$json_result['error'].'). Teruggevallen op SiteLink.';
			return $sitelink_result;
		}
	}

	return $json_result;
}
if($sitelink_token!==''){
	$sitelink_result=kolibri_get_normalized_items_from_sitelink($sitelink_token);
	if(empty($sitelink_result['error'])){
		$sitelink_result['source_type']='sitelink';
	}
	return $sitelink_result;
}
return ['error'=>'Geen SiteLink token of bron-URL ingesteld in Kolibri instellingen.'];
}

function kolibri_get_normalized_items_from_json_endpoint($source_url){
$response=wp_remote_get($source_url,['timeout'=>25]);
if(is_wp_error($response)){
return ['error'=>$response->get_error_message()];
}

$status=(int)wp_remote_retrieve_response_code($response);
if($status<200 || $status>=300){
return ['error'=>'Bron antwoordde met HTTP '.$status.'.'];
}

$body=(string)wp_remote_retrieve_body($response);
$payload=json_decode($body,true);
if(!is_array($payload)){
return ['error'=>'Bron bevat geen geldig JSON-object of JSON-array.'];
}

$raw_items=kolibri_get_remote_items_from_payload($payload);
if(empty($raw_items)){
return ['error'=>'Geen objecten gevonden in bron JSON.'];
}

$normalized=[];
foreach($raw_items as $raw_item){
$item=kolibri_normalize_remote_item($raw_item);
if(!$item){ continue; }
$normalized[$item['external_id']]=$item;
}
if(empty($normalized)){
return ['error'=>'Geen geldige objecten met externe ID gevonden.'];
}

return ['items'=>$normalized];
}

function kolibri_get_normalized_items_from_sitelink($token){
$url=kolibri_build_sitelink_url($token);
if($url===''){
return ['error'=>'SiteLink token ontbreekt.'];
}

if(kolibri_is_sitelink_rate_limited()){
$next_text=kolibri_get_next_sitelink_allowed_local_text();
return ['error'=>'SiteLink sync is tijdelijk geblokkeerd wegens daglimiet. Volgende poging mogelijk na '.$next_text.'.'];
}

$request_url=add_query_arg('_kts',gmdate('YmdHis'),$url);
$response=wp_remote_get($request_url,[
'timeout'=>40,
'headers'=>[
'Accept'=>'application/zip, application/octet-stream;q=0.9, */*;q=0.8',
'Cache-Control'=>'no-cache',
'Pragma'=>'no-cache',
],
]);
if(is_wp_error($response)){
return ['error'=>$response->get_error_message()];
}

$status=(int)wp_remote_retrieve_response_code($response);
if($status<200 || $status>=300){
if($status===403){
$blocked_until=time()+kolibri_sitelink_rate_limit_seconds();
kolibri_set_sitelink_blocked_until_timestamp($blocked_until);
$next_text=kolibri_get_next_sitelink_allowed_local_text();
return ['error'=>'SiteLink antwoordde met HTTP 403. Waarschijnlijk daglimiet bereikt. Volgende poging mogelijk na '.$next_text.'.'];
}
return ['error'=>'SiteLink antwoordde met HTTP '.$status.'. Controleer token en rechten.'];
}

kolibri_clear_sitelink_blocked_until_timestamp();

$body=wp_remote_retrieve_body($response);
if(!is_string($body) || $body===''){
return ['error'=>'SiteLink gaf een lege response.'];
}

if(!class_exists('ZipArchive')){
return ['error'=>'PHP extensie ZipArchive ontbreekt op de server.'];
}

$tmp=wp_tempnam('kolibri-sitelink.zip');
if(!$tmp){
return ['error'=>'Kon geen tijdelijk bestand maken voor SiteLink zip.'];
}

file_put_contents($tmp,$body);
$zip=new ZipArchive();
$open_result=$zip->open($tmp);
if($open_result!==true){
@unlink($tmp);
return ['error'=>'Kon SiteLink zip niet openen (code '.$open_result.').'];
}

$normalized=[];
for($i=0;$i<$zip->numFiles;$i++){
$name=(string)$zip->getNameIndex($i);
if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='xml'){ continue; }
$xml_content=$zip->getFromIndex($i);
if(!is_string($xml_content) || trim($xml_content)===''){ continue; }
$items=kolibri_normalize_sitelink_xml($xml_content);
if(empty($items)){ continue; }
	$fallback_base=sanitize_title((string)pathinfo($name,PATHINFO_FILENAME));
	if($fallback_base===''){ $fallback_base='object-'.(int)$i; }

foreach($items as $item_index=>$item){
$raw_external_id=isset($item['external_id']) ? (string)$item['external_id'] : '';
$raw_external_id=trim($raw_external_id);
if($raw_external_id===''){
$raw_external_id=$fallback_base.(count($items)>1 ? '-'.((int)$item_index+1) : '');
}

$key=$raw_external_id;
if(isset($normalized[$key])){
$suffix=sanitize_title((string)$item['title']);
if($suffix===''){ $suffix=$fallback_base; }
$candidate=$raw_external_id.'-'.$suffix;
$n=2;
while(isset($normalized[$candidate])){
$candidate=$raw_external_id.'-'.$suffix.'-'.$n;
$n++;
}
$key=$candidate;
}

$item['external_id']=$key;
$normalized[$key]=$item;
}
}

$zip->close();
@unlink($tmp);

if(empty($normalized)){
return ['error'=>'Geen geldige objecten gevonden in SiteLink zip/XML.'];
}

return ['items'=>$normalized];
}

function kolibri_build_sitelink_url($token){
$token=trim((string)$token);
if($token===''){ return ''; }
if(filter_var($token,FILTER_VALIDATE_URL)){
return $token;
}
return 'https://sitelink.kolibri24.com/v3/'.rawurlencode($token).'/zip';
}

function kolibri_simplexml_load_resilient($xml_content){
if(!function_exists('simplexml_load_string')){ return false; }

libxml_use_internal_errors(true);
$xml=simplexml_load_string($xml_content);
if($xml){ return $xml; }

// Some feeds contain control chars or bare ampersands that break XML parsing.
$sanitized=(string)$xml_content;
$sanitized=preg_replace('/^\xEF\xBB\xBF/','',$sanitized);
$sanitized=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/','',$sanitized);
$sanitized=preg_replace('/&(?!#\d+;|#x[0-9a-fA-F]+;|[A-Za-z][A-Za-z0-9]{1,31};)/','&amp;',$sanitized);
if($sanitized===''){ return false; }

libxml_use_internal_errors(true);
$xml=simplexml_load_string($sanitized);
if($xml){ return $xml; }

return false;
}

function kolibri_xml_first_value($xml,$xpaths){
foreach($xpaths as $path){
$nodes=$xml->xpath($path);
if(empty($nodes)){ continue; }
foreach($nodes as $node){
$value=trim((string)$node);
if($value!==''){ return $value; }
}
}
return '';
}

function kolibri_xml_first_value_ci($xml,$xpaths){
$upper='ABCDEFGHIJKLMNOPQRSTUVWXYZ';
$lower='abcdefghijklmnopqrstuvwxyz';
foreach($xpaths as $path){
$query=str_replace(
'{ln}',
"translate(local-name(),'".$upper."','".$lower."')",
$path
);
$nodes=$xml->xpath($query);
if(empty($nodes)){ continue; }
foreach($nodes as $node){
$value=trim((string)$node);
if($value!==''){ return $value; }
}
}
return '';
}

function kolibri_compose_object_title($street,$house_number,$house_number_addition,$city){
$street=trim((string)$street);
$house_number=trim((string)$house_number);
$house_number_addition=trim((string)$house_number_addition);
$city=trim((string)$city);

$number_part=trim($house_number);
if($house_number_addition!==''){
$use_space=preg_match('/^\d/',$house_number_addition)===1;
$number_part=trim($number_part.($use_space ? ' ' : '').$house_number_addition);
}

$address=trim($street.' '.$number_part);
if($address!=='' && $city!==''){
return $address.', '.$city;
}
if($address!==''){ return $address; }
if($city!==''){ return $city; }
return '';
}

function kolibri_normalize_house_number_parts($street,$house_number,$house_number_addition,$address_line=''){
$street=(string)$street;
$house_number=trim((string)$house_number);
$house_number_addition=trim((string)$house_number_addition);
$address_line=trim((string)$address_line);

if($house_number_addition==='' && preg_match('/^(\d+)\s*([A-Za-z]{1,3})$/',$house_number,$m)){
$house_number=$m[1];
$house_number_addition=$m[2];
}

if($house_number_addition==='' && $house_number!=='' && $address_line!==''){
$pattern='/\b'.preg_quote($house_number,'/').'\s*([A-Za-z]{1,3})\b/u';
if(preg_match($pattern,$address_line,$m)){
$house_number_addition=$m[1];
}
}

if($house_number_addition==='' && $house_number!=='' && $street!==''){
$pattern='/'.preg_quote(trim($street),'\/').'\s+'.preg_quote($house_number,'/').'\s*([A-Za-z]{1,3})\b/ui';
if(preg_match($pattern,$address_line,$m)){
$house_number_addition=$m[1];
}
}

return [
'house_number'=>$house_number,
'house_number_addition'=>$house_number_addition,
];
}

function kolibri_collect_text_values_ci($xml,$xpath){
$values=[];
$nodes=$xml->xpath($xpath);
if(empty($nodes)){ return $values; }
foreach($nodes as $node){
$value=trim(preg_replace('/\s+/',' ',(string)$node));
if($value!==''){ $values[]=$value; }
}
return $values;
}

function kolibri_build_property_content_html($description,$pairs){
$description=trim((string)$description);
$html='';
if($description!==''){
$html.='<p>'.esc_html($description).'</p>';
}

$rows='';
foreach($pairs as $label=>$value){
$value=trim((string)$value);
if($value===''){ continue; }
$rows.='<li><strong>'.esc_html($label).':</strong> '.esc_html($value).'</li>';
}
if($rows!==''){
$html.='<h3>Kenmerken</h3><ul>'.$rows.'</ul>';
}

if($html===''){
$html='<p>Objectinformatie wordt binnenkort aangevuld.</p>';
}
return $html;
}

function kolibri_sync_format_price_pm($value){
$raw=trim((string)$value);
if($raw===''){ return ''; }
$digits=preg_replace('/[^\d]/','',$raw);
if($digits===''){ return $raw; }
$amount=(int)$digits;
if($amount<=0){ return $raw; }
return '€ '.number_format($amount,0,',','.').' p/m';
}

function kolibri_normalize_sitelink_xml($xml_content){
if(!function_exists('simplexml_load_string')){ return null; }
$xml=kolibri_simplexml_load_resilient($xml_content);
if(!$xml){ return []; }

$property_nodes=$xml->xpath(
"//*[ *[translate(local-name(),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='propertyinfo'] ]"
);
if(empty($property_nodes)){
$property_nodes=$xml->xpath(
"//*[translate(local-name(),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='realestateproperty']"
);
}
if(empty($property_nodes)){
$property_nodes=$xml->xpath(
"//*[ *[translate(local-name(),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='id' or translate(local-name(),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='foreignid'] and *[translate(local-name(),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='location' or translate(local-name(),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='address'] ]"
);
}
if(empty($property_nodes)){ $property_nodes=[$xml]; }

$items=[];
foreach($property_nodes as $property){
$ignore=kolibri_xml_first_value_ci($property,[
".//*[ {ln}='propertyinfo']/*[{ln}='ignore']",
]);
if(strtolower($ignore)==='true'){ continue; }

$external_id=kolibri_xml_first_value_ci($property,[
".//*[ {ln}='propertyinfo']/*[{ln}='foreignid']",
".//*[ {ln}='propertyinfo']/*[{ln}='id']",
".//*[ {ln}='id']",
]);

$street=kolibri_xml_first_value_ci($property,[
".//*[ {ln}='location']//*[ {ln}='streetname']",
".//*[ {ln}='address']//*[ {ln}='streetname']",
".//*[ {ln}='streetname']",
".//*[ {ln}='street']",
".//*[ {ln}='addressline1']",
]);
$address_line=kolibri_xml_first_value_ci($property,[
".//*[ {ln}='location']//*[ {ln}='addressline1']",
".//*[ {ln}='address']//*[ {ln}='addressline1']",
".//*[ {ln}='addressline1']",
".//*[ {ln}='addressline']",
".//*[ {ln}='fulladdress']",
]);
$house_number=kolibri_xml_first_value_ci($property,[
".//*[ {ln}='location']//*[ {ln}='housenumber']",
".//*[ {ln}='address']//*[ {ln}='housenumber']",
".//*[ {ln}='housenumber']",
".//*[ {ln}='number']",
]);
$house_number_addition=kolibri_xml_first_value_ci($property,[
".//*[ {ln}='location']//*[ {ln}='housenumberaddition']",
".//*[ {ln}='address']//*[ {ln}='housenumberaddition']",
".//*[ {ln}='housenumberaddition']",
".//*[ {ln}='numberaddition']",
".//*[ {ln}='houseletter']",
".//*[ {ln}='housenumberextension']",
".//*[ {ln}='housenumbersuffix']",
".//*[ {ln}='numbersuffix']",
".//*[ {ln}='addition']",
".//*[ {ln}='suffix']",
".//*[ {ln}='unit']",
]);
$number_parts=kolibri_normalize_house_number_parts($street,$house_number,$house_number_addition,$address_line);
$house_number=$number_parts['house_number'];
$house_number_addition=$number_parts['house_number_addition'];
$city=kolibri_xml_first_value_ci($property,[
".//*[ {ln}='location']//*[ {ln}='city']",
".//*[ {ln}='address']//*[ {ln}='city']",
".//*[ {ln}='city']",
".//*[ {ln}='town']",
".//*[ {ln}='locality']",
]);
$title=kolibri_compose_object_title($street,$house_number,$house_number_addition,$city);
if($title===''){
$title=kolibri_xml_first_value_ci($property,[
".//*[ {ln}='title']",
".//*[ {ln}='name']",
".//*[ {ln}='objectname']",
]);
}
if($title===''){ $title='Object '.$external_id; }
if($external_id===''){
$external_id=sanitize_title($title);
}

$description=kolibri_xml_first_value_ci($property,[
".//*[ {ln}='descriptions']//*[ {ln}='description']//*[ {ln}='value']",
".//*[ {ln}='descriptions']//*[ {ln}='description']//*[ {ln}='text']",
".//*[ {ln}='descriptions']//*[ {ln}='description']//*[ {ln}='nl']",
".//*[ {ln}='descriptiontext']",
".//*[ {ln}='publicdescription']",
".//*[ {ln}='longdescription']",
".//*[ {ln}='omschrijving']",
".//*[ {ln}='description']",
]);
if($description===''){
$description_nodes=kolibri_collect_text_values_ci(
$property,
".//*[contains(translate(local-name(),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'description')]//*[text()]"
);
$description_nodes=array_filter($description_nodes,function($value){
$len=function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
return $len>=30;
});
if(!empty($description_nodes)){
$description=implode("\n\n",array_slice(array_values(array_unique($description_nodes)),0,3));
}
}
$price=kolibri_xml_first_value_ci($property,[
".//*[ {ln}='financials']//*[ {ln}='purchaseprice']",
".//*[ {ln}='financials']//*[ {ln}='rentprice']",
".//*[ {ln}='price']",
".//*[ {ln}='rent']",
]);
$rooms=kolibri_xml_first_value_ci($property,[
".//*[ {ln}='counts']//*[ {ln}='countofrooms']",
".//*[ {ln}='rooms']",
".//*[ {ln}='numberofrooms']",
]);
$area=kolibri_xml_first_value_ci($property,[
".//*[ {ln}='areatotals']//*[ {ln}='livablearea']",
".//*[ {ln}='livingarea']",
".//*[ {ln}='surfacearea']",
]);
$bedrooms=kolibri_xml_first_value_ci($property,[
".//*[ {ln}='countofbedrooms']",
".//*[ {ln}='bedrooms']",
]);
$energy_label=kolibri_xml_first_value_ci($property,[
".//*[ {ln}='energylabel']",
".//*[ {ln}='energielabel']",
]);
$build_year=kolibri_xml_first_value_ci($property,[
".//*[ {ln}='constructionyear']",
".//*[ {ln}='buildyear']",
".//*[ {ln}='yearbuilt']",
]);

$images=[];
$image_nodes=$property->xpath(".//*[translate(local-name(),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='attachments']//*[contains(translate(local-name(),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'url')]");
if(!empty($image_nodes)){
foreach($image_nodes as $node){
$url=esc_url_raw(trim((string)$node));
if($url===''){ continue; }
$images[]=$url;
}
}
$images=array_values(array_unique(array_filter($images)));

$meta=[];
if($price!==''){ $meta['kolibri_prijs']=$price; }
if($city!==''){ $meta['kolibri_plaats']=$city; }
if($rooms!==''){ $meta['kolibri_kamers']=$rooms; }
if($area!==''){ $meta['kolibri_oppervlakte']=$area; }
if(!empty($images)){ $meta['kolibri_remote_images']=$images; }
if($street!==''){ $meta['kolibri_straat']=$street; }
if($house_number!=='' || $house_number_addition!==''){
$meta['kolibri_huisnummer']=trim($house_number.$house_number_addition);
}
if($street!=='' || $house_number!=='' || $house_number_addition!=='' || $city!==''){
$meta['kolibri_adres']=kolibri_compose_object_title($street,$house_number,$house_number_addition,$city);
}

$content=kolibri_build_property_content_html($description,[
'Prijs'=>kolibri_sync_format_price_pm($price),
'Plaats'=>$city,
'Oppervlakte'=>$area!=='' ? $area.' m2' : '',
'Kamers'=>$rooms,
'Slaapkamers'=>$bedrooms,
'Bouwjaar'=>$build_year,
'Energielabel'=>$energy_label,
]);

$items[]=[
'external_id'=>$external_id,
'title'=>$title,
'content'=>$content,
'meta'=>$meta,
];
}

return $items;
}
