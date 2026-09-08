<?php
require __DIR__.'/app/bootstrap.php';header('Content-Type: application/javascript; charset=utf-8');header('Service-Worker-Allowed: '.parse_url(app_url(''),PHP_URL_PATH));
$base=rtrim(app_url(''),'/');
?>
const CACHE='vacation-brain-shell-v1';
const SHELL=[<?=json_encode($base.'/')?>,<?=json_encode($base.'/assets/app.css')?>,<?=json_encode($base.'/assets/app.js')?>];
self.addEventListener('install',event=>{event.waitUntil(caches.open(CACHE).then(c=>c.addAll(SHELL)).catch(()=>{}));self.skipWaiting();});
self.addEventListener('activate',event=>{event.waitUntil(self.clients.claim());});
self.addEventListener('fetch',event=>{if(event.request.method!=='GET')return;event.respondWith(fetch(event.request).catch(()=>caches.match(event.request)));});
