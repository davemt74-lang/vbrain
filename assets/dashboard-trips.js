(function(){
  'use strict';
  var layout=document.querySelector('[data-local-layout]');
  if(!layout)return;
  var toggle=document.querySelector('[data-map-toggle]');
  var mapCard=document.querySelector('[data-map-card]');
  var locationBtn=document.querySelector('[data-use-location]');
  var status=document.querySelector('[data-map-status]');
  var dataNode=document.getElementById('vb-daytrip-data');
  var trips=[];
  try{trips=JSON.parse(dataNode&&dataNode.textContent?dataNode.textContent:'[]')||[];}catch(e){trips=[];}
  var storageKey='vacationBrain.dashboard.mapHidden';
  var map=null,userMarker=null;

  function setStatus(message){if(!status)return;status.textContent=message||'';status.classList.toggle('show',!!message);}
  function applyHidden(hidden){layout.classList.toggle('map-hidden',hidden);if(toggle){toggle.setAttribute('aria-expanded',hidden?'false':'true');toggle.innerHTML=hidden?'Show map <span>⌄</span>':'Hide map <span>⌃</span>';}try{localStorage.setItem(storageKey,hidden?'1':'0');}catch(e){}if(!hidden&&map){setTimeout(function(){map.invalidateSize();},80);}}
  try{applyHidden(localStorage.getItem(storageKey)==='1');}catch(e){applyHidden(false);}
  if(toggle)toggle.addEventListener('click',function(){applyHidden(!layout.classList.contains('map-hidden'));});

  function popupHtml(t){
    var safe=function(v){return String(v||'').replace(/[&<>'"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c];});};
    return '<div class="vb-map-popup"><strong>'+safe(t.name)+'</strong><span>'+safe(t.duration||'Day trip')+'</span><a href="'+safe(t.url)+'">View details →</a></div>';
  }
  function initMap(){
    var el=document.getElementById('vb-daytrip-map');
    if(!el)return;
    if(!window.L){el.innerHTML='<div style="display:grid;place-items:center;height:100%;padding:30px;text-align:center;color:#536f8b">Map tiles could not load. Your trip cards are still available.</div>';return;}
    var center=[33.4484,-112.0740];
    if(trips.length){var slat=0,slng=0,n=0;trips.forEach(function(t){if(Number.isFinite(+t.lat)&&Number.isFinite(+t.lng)){slat+=+t.lat;slng+=+t.lng;n++;}});if(n)center=[slat/n,slng/n];}
    map=L.map(el,{zoomControl:true,scrollWheelZoom:false}).setView(center,8);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:18,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);
    var bounds=[];
    trips.forEach(function(t){if(!Number.isFinite(+t.lat)||!Number.isFinite(+t.lng))return;var marker=L.marker([+t.lat,+t.lng]).addTo(map);marker.bindPopup(popupHtml(t));bounds.push([+t.lat,+t.lng]);});
    if(bounds.length>1)map.fitBounds(bounds,{padding:[32,32],maxZoom:9});
  }
  initMap();

  if(locationBtn){locationBtn.addEventListener('click',function(){
    if(!navigator.geolocation){setStatus('Location is not available in this browser.');return;}
    locationBtn.disabled=true;locationBtn.textContent='Locating…';setStatus('Your location stays in this browser and is only used to position the map.');
    navigator.geolocation.getCurrentPosition(function(pos){
      var lat=pos.coords.latitude,lng=pos.coords.longitude;
      if(map&&window.L){
        if(userMarker)map.removeLayer(userMarker);
        var icon=L.divIcon({className:'',html:'<div class="vb-user-marker" aria-label="You are here"></div>',iconSize:[22,22],iconAnchor:[11,11]});
        userMarker=L.marker([lat,lng],{icon:icon,zIndexOffset:1000}).addTo(map).bindPopup('<strong>You are here</strong>');
        map.setView([lat,lng],9);
      }
      setStatus('Map centered on your current location.');locationBtn.disabled=false;locationBtn.innerHTML='<span>⌖</span> Use my location';
    },function(){setStatus('We could not access your location. Check browser location permission and try again.');locationBtn.disabled=false;locationBtn.innerHTML='<span>⌖</span> Use my location';},{enableHighAccuracy:false,timeout:8000,maximumAge:300000});
  });}
})();
