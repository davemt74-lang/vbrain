<?php
require __DIR__.'/app/bootstrap.php';header('Content-Type: application/javascript; charset=utf-8');header('Service-Worker-Allowed: '.parse_url(app_url(''),PHP_URL_PATH));
$base=rtrim(app_url(''),'/');
?>
const CACHE='vacation-brain-shell-v2';
const BASE=<?=json_encode($base)?>;
const OFFLINE_TRAVEL=BASE+'/travel-mode-offline.html';
const SHELL=[BASE+'/',BASE+'/assets/app.css',BASE+'/assets/app.js',BASE+'/assets/travel-mode.css',BASE+'/assets/travel-mode-offline.js',OFFLINE_TRAVEL];
self.addEventListener('install',event=>{event.waitUntil(caches.open(CACHE).then(c=>c.addAll(SHELL)).catch(()=>{}));self.skipWaiting();});
self.addEventListener('activate',event=>{event.waitUntil(Promise.all([caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith('vacation-brain-shell-')&&k!==CACHE).map(k=>caches.delete(k)))),self.clients.claim()]));});
self.addEventListener('fetch',event=>{if(event.request.method!=='GET')return;const url=new URL(event.request.url);const isTravel=url.pathname.endsWith('/travel-mode.php');if(isTravel){event.respondWith(fetch(event.request).catch(()=>caches.match(OFFLINE_TRAVEL)));return;}event.respondWith(fetch(event.request).catch(()=>caches.match(event.request)));});
