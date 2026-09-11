(()=>{
  'use strict';
  const root=document.querySelector('[data-local-concierge-root]');
  if(!root)return;
  const form=root.querySelector('[data-local-concierge-form]');
  if(!form)return;
  const useLocation=form.querySelector('[data-use-location]');
  const destinationButton=form.querySelector('[data-destination-refresh]');
  const mode=form.querySelector('[data-anchor-mode]');
  const lat=form.querySelector('[data-latitude]');
  const lng=form.querySelector('[data-longitude]');
  const status=form.querySelector('[data-location-status]');

  const clearDevice=()=>{mode.value='destination';lat.value='';lng.value='';};
  if(destinationButton)destinationButton.addEventListener('click',clearDevice);
  if(!useLocation)return;

  useLocation.addEventListener('click',()=>{
    if(!navigator.geolocation){
      if(status)status.textContent='This browser does not provide device location. Use the trip destination instead.';
      return;
    }
    useLocation.disabled=true;
    useLocation.textContent='Getting location…';
    if(status)status.textContent='Your coordinates will be used for this refresh only and will not be saved.';
    navigator.geolocation.getCurrentPosition(position=>{
      mode.value='device';
      lat.value=String(position.coords.latitude);
      lng.value=String(position.coords.longitude);
      if(status)status.textContent='Location received for this one refresh. Coordinates will not be stored.';
      form.submit();
    },error=>{
      clearDevice();
      useLocation.disabled=false;
      useLocation.textContent='Use my location once';
      if(status)status.textContent=error.code===1?'Location permission was not granted. You can still use the trip destination.':'Vacation Brain could not read your location. Try again or use the trip destination.';
    },{enableHighAccuracy:false,timeout:10000,maximumAge:300000});
  });
})();