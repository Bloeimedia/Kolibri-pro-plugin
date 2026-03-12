<?php
add_action('rest_api_init',function(){
register_rest_route('kolibri/v1','/lite-feed',[
'methods'=>'GET',
'callback'=>'kolibri_rest_lite_feed',
'permission_callback'=>'__return_true',
]);
register_rest_route('kolibri/v1','/pararius-feed',[
'methods'=>'GET',
'callback'=>'kolibri_rest_pararius_feed',
'permission_callback'=>'__return_true',
]);
register_rest_route('kolibri/v1','/lite-sample',[
'methods'=>'GET',
'callback'=>'kolibri_rest_lite_sample',
'permission_callback'=>'__return_true',
]);
});

function kolibri_rest_lite_feed($request){
$enabled=(int)kolibri_get_option('lite_enabled',0)===1;
if(!$enabled){
return new WP_Error('kolibri_lite_disabled','Lite modus is niet actief.',['status'=>403]);
}

$force_refresh=$request->get_param('refresh')==='1' && current_user_can('manage_options');
$limit=(int)$request->get_param('limit');
if($limit<=0){ $limit=30; }
if($limit>100){ $limit=100; }

$cards=function_exists('kolibri_get_lite_cards') ? kolibri_get_lite_cards($force_refresh) : [];
$cards=array_slice((array)$cards,0,$limit);

return rest_ensure_response([
'count'=>count($cards),
'generated_at'=>wp_date('c',time(),wp_timezone()),
'source_url'=>(string)kolibri_get_option('lite_source_url',''),
'cards'=>$cards,
]);
}

function kolibri_rest_pararius_feed($request){
$enabled=(int)kolibri_get_option('lite_enabled',0)===1;
if(!$enabled){
return new WP_Error('kolibri_lite_disabled','Lite modus is niet actief.',['status'=>403]);
}

$url=(string)$request->get_param('url');
if($url===''){
$url=(string)kolibri_get_option('lite_pararius_url','');
}
if($url==='' || !wp_http_validate_url($url)){
return new WP_Error('kolibri_pararius_url_missing','Geen geldige Pararius URL ingesteld.',['status'=>400]);
}

$limit=(int)$request->get_param('limit');
if($limit<=0){ $limit=30; }
if($limit>100){ $limit=100; }

$cards=function_exists('kolibri_get_lite_cards_from_page_url') ? kolibri_get_lite_cards_from_page_url($url,$limit) : [];

return rest_ensure_response([
'count'=>count($cards),
'generated_at'=>wp_date('c',time(),wp_timezone()),
'source_url'=>$url,
'cards'=>$cards,
]);
}

function kolibri_rest_lite_sample($request){
$limit=(int)$request->get_param('limit');
if($limit<=0){ $limit=5; }
if($limit>20){ $limit=20; }

$sample=[];
for($i=1;$i<=$limit;$i++){
$sample[]=[
'title'=>'Voorbeeld object '.$i,
'url'=>'https://www.pararius.nl/huurwoningen/groningen/pand-'.$i,
'price'=>'1200',
'image'=>'https://picsum.photos/seed/kolibri-'.$i.'/800/600',
];
}

return rest_ensure_response([
'count'=>count($sample),
'generated_at'=>wp_date('c',time(),wp_timezone()),
'source_url'=>'sample',
'cards'=>$sample,
]);
}
