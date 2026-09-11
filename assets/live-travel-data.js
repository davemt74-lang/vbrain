(function(){
  const refresh=document.querySelector('[data-refresh-intelligence]');
  if(refresh){const types=(refresh.getAttribute('data-refresh-intelligence')||'').split(',').map(v=>v.trim()).filter(Boolean);if(!types.includes('lodging'))types.splice(2,0,'lodging');refresh.setAttribute('data-refresh-intelligence',types.join(','));}
})();
