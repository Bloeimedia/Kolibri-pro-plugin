jQuery(function($){
function initKolibriSliders(context){
$(context).find(".kolibri-slider").each(function(){
let $slider=$(this);
if($slider.data("kolibri-ready")){ return; }

let $slides=$slider.find(".slide");
let $thumbs=$slider.find(".kolibri-thumb");
if(!$slides.length){ return; }

let index=0;
function render(){
$slides.removeClass("is-active");
$slides.eq(index).addClass("is-active");
$thumbs.removeClass("is-active");
$thumbs.filter('[data-index="'+index+'"]').addClass("is-active");
}
render();

$slider.on("click",".kolibri-prev",function(event){
event.preventDefault();
index=(index-1+$slides.length)%$slides.length;
render();
});

$slider.on("click",".kolibri-next",function(event){
event.preventDefault();
index=(index+1)%$slides.length;
render();
});

$slider.on("click",".kolibri-thumb",function(event){
event.preventDefault();
let nextIndex=parseInt($(this).data("index"),10);
if(Number.isNaN(nextIndex)){ return; }
index=((nextIndex%$slides.length)+$slides.length)%$slides.length;
render();
});

$slider.data("kolibri-ready",true);
});
}

initKolibriSliders(document);

$(document).on("click",".kolibri-open",function(event){
event.preventDefault();
let id=$(this).data("object");
let url=$(this).data("url");
let openMode=$(this).data("open-mode");
let target=$("#kolibri-dynamic-object");

if(openMode!=="ajax" && url){
window.location.href=url;
return;
}

if(!target.length || typeof kolibri_ajax==="undefined" || !kolibri_ajax.ajaxurl){
if(url){ window.location.href=url; }
return;
}

$.post(kolibri_ajax.ajaxurl,{action:"kolibri_load_object",id:id},function(data){
target.html(data);
initKolibriSliders(target);
});
});
});
