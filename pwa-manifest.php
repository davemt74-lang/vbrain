<?php
require __DIR__.'/app/bootstrap.php';
header('Content-Type: application/manifest+json; charset=utf-8');
$icon=site_setting('pwa.icon_url','');$manifest=['name'=>site_setting('pwa.app_name','Vacation Brain'),'short_name'=>site_setting('pwa.short_name','Vacation Brain'),'description'=>site_setting('pwa.description','Vacation Brain'),'start_url'=>app_url('today.php'),'scope'=>app_url(''),'display'=>site_setting('pwa.display','standalone'),'theme_color'=>site_setting('pwa.theme_color','#ffffff'),'background_color'=>site_setting('pwa.background_color','#ffffff')];if($icon)$manifest['icons']=[['src'=>$icon,'sizes'=>'any','type'=>'image/png','purpose'=>'any maskable']];echo json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
