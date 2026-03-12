<?php
add_action('init',function(){
register_post_type('kolibri_object',[
'label'=>'Kolibri Objecten',
'public'=>true,
'has_archive'=>true,
'rewrite'=>['slug'=>'aanbod'],
'supports'=>['title','editor','thumbnail','excerpt']
]);
});
