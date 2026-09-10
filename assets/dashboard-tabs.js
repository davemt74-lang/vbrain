(function(){
  'use strict';

  var scriptUrl=(document.currentScript&&document.currentScript.src)||'';
  function ensureStylesheet(filename,marker){
    if(document.querySelector('link[data-'+marker+']'))return;
    try{
      var link=document.createElement('link');
      link.rel='stylesheet';
      link.href=new URL(filename,scriptUrl||window.location.href).toString();
      link.setAttribute('data-'+marker,'1');
      document.head.appendChild(link);
    }catch(e){}
  }
  ensureStylesheet('dashboard-tabs.css','dashboard-tabs-style');
  ensureStylesheet('dashboard-recent.css','dashboard-recent-style');

  var workspace=document.querySelector('[data-agent-workspace]');
  if(!workspace)return;

  var apiUrl=workspace.getAttribute('data-api-url')||'';
  var pageUrl=workspace.getAttribute('data-page-url')||window.location.pathname;
  var csrf=(workspace.querySelector('[data-agent-csrf]')||{}).value||'';
  var backdrop=document.querySelector('[data-tab-drawer-backdrop]');
  var drawer=document.querySelector('[data-tab-settings-drawer]');
  var drawerTitle=drawer&&drawer.querySelector('[data-tab-drawer-title]');
  var drawerIntro=drawer&&drawer.querySelector('[data-tab-drawer-intro]');
  var form=drawer&&drawer.querySelector('[data-tab-settings-form]');
  var nameInput=form&&form.querySelector('[name="name"]');
  var purposeInput=form&&form.querySelector('[name="purpose"]');
  var idInput=form&&form.querySelector('[name="tab_id"]');
  var actionInput=form&&form.querySelector('[name="action"]');
  var saveButton=form&&form.querySelector('[data-tab-save]');
  var deleteButton=drawer&&drawer.querySelector('[data-tab-delete]');
  var mainNote=drawer&&drawer.querySelector('[data-main-tab-note]');
  var errorBox=drawer&&drawer.querySelector('[data-tab-error]');
  var activeKey='main';

  function showError(message){
    if(!errorBox)return;
    errorBox.textContent=message||'';
    errorBox.hidden=!message;
  }

  function openDrawer(){
    if(!drawer)return;
    drawer.classList.add('open');
    drawer.setAttribute('aria-hidden','false');
    if(backdrop)backdrop.classList.add('open');
    document.body.classList.add('vb-tab-drawer-open');
    window.setTimeout(function(){if(nameInput&&!nameInput.disabled&&form&&!form.hidden)nameInput.focus();},60);
  }

  function closeDrawer(){
    if(!drawer)return;
    drawer.classList.remove('open');
    drawer.setAttribute('aria-hidden','true');
    if(backdrop)backdrop.classList.remove('open');
    document.body.classList.remove('vb-tab-drawer-open');
    showError('');
  }

  function selectTab(key,updateUrl){
    activeKey=String(key||'main');
    document.querySelectorAll('[data-agent-tab]').forEach(function(tab){
      var active=String(tab.getAttribute('data-tab-key'))===activeKey;
      tab.classList.toggle('active',active);
      var select=tab.querySelector('[data-agent-tab-select]');
      if(select)select.setAttribute('aria-selected',active?'true':'false');
    });
    document.querySelectorAll('[data-agent-pane]').forEach(function(pane){
      pane.hidden=String(pane.getAttribute('data-agent-pane'))!==activeKey;
    });
    if(activeKey==='main')document.dispatchEvent(new CustomEvent('vb:dashboard-main-shown'));
    if(updateUrl!==false&&window.history&&history.replaceState){
      try{
        var url=new URL(pageUrl,window.location.href);
        if(activeKey==='main')url.searchParams.delete('agent_tab');
        else url.searchParams.set('agent_tab',activeKey);
        history.replaceState({},'',url.pathname+url.search+url.hash);
      }catch(e){}
    }
  }

  function openMainSettings(){
    if(!drawer)return;
    if(drawerTitle)drawerTitle.textContent='Main tab settings';
    if(drawerIntro)drawerIntro.textContent='The Main tab is your permanent Vacation Brain dashboard.';
    if(mainNote){mainNote.hidden=false;mainNote.textContent='The Main tab is permanent and contains your default dashboard. It cannot be deleted.';}
    if(form)form.hidden=true;
    if(deleteButton)deleteButton.hidden=true;
    openDrawer();
  }

  function openAgentSettings(tab){
    if(!drawer||!tab)return;
    var key=tab.getAttribute('data-tab-key')||'';
    var name=tab.getAttribute('data-tab-name')||'Agent';
    var purpose=tab.getAttribute('data-tab-purpose')||'';
    if(drawerTitle)drawerTitle.textContent='Tab settings';
    if(drawerIntro)drawerIntro.textContent='Rename this agent tab or give it a short working purpose.';
    if(mainNote)mainNote.hidden=true;
    if(form)form.hidden=false;
    if(actionInput)actionInput.value='update';
    if(idInput)idInput.value=key;
    if(nameInput){nameInput.disabled=false;nameInput.value=name;}
    if(purposeInput){purposeInput.disabled=false;purposeInput.value=purpose;}
    if(saveButton)saveButton.textContent='Save settings';
    if(deleteButton){deleteButton.hidden=false;deleteButton.setAttribute('data-tab-id',key);deleteButton.setAttribute('data-tab-name',name);}
    openDrawer();
  }

  function openCreate(){
    if(!drawer)return;
    if(workspace.getAttribute('data-tabs-ready')!=='1'){
      if(drawerTitle)drawerTitle.textContent='Agent tabs need an upgrade';
      if(drawerIntro)drawerIntro.textContent='Run System Upgrade once to enable persistent agent tabs for this account.';
      if(mainNote){mainNote.hidden=false;mainNote.textContent='The Main dashboard will continue to work normally until the database upgrade is applied.';}
      if(form)form.hidden=true;
      if(deleteButton)deleteButton.hidden=true;
      openDrawer();
      return;
    }
    if(drawerTitle)drawerTitle.textContent='Create a new agent';
    if(drawerIntro)drawerIntro.textContent='Each new tab is a separate Vacation Brain agent workspace.';
    if(mainNote)mainNote.hidden=true;
    if(form)form.hidden=false;
    if(actionInput)actionInput.value='create';
    if(idInput)idInput.value='';
    if(nameInput){nameInput.disabled=false;nameInput.value='New Agent';}
    if(purposeInput){purposeInput.disabled=false;purposeInput.value='';}
    if(saveButton)saveButton.textContent='Create agent';
    if(deleteButton)deleteButton.hidden=true;
    openDrawer();
  }

  function postForm(payload){
    return fetch(apiUrl,{method:'POST',body:payload,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(response){return response.json().catch(function(){return{ok:false,error:'Unexpected server response.'};}).then(function(data){if(!response.ok||!data.ok)throw new Error(data.error||'Could not save this agent tab.');return data;});});
  }

  function hashToken(value){
    var h=2166136261;
    value=String(value||'user');
    for(var i=0;i<value.length;i++){h^=value.charCodeAt(i);h+=(h<<1)+(h<<4)+(h<<7)+(h<<8)+(h<<24);}
    return (h>>>0).toString(36);
  }

  var accountText=((document.querySelector('.sidebar-user-copy small')||{}).textContent||'user').trim().toLowerCase();
  var recentStorageKey='vacationBrain.recentTrips.v1.'+hashToken(accountText);
  var recentLimit=8;

  function safeInternalUrl(value){
    try{
      var url=new URL(String(value||''),window.location.href);
      if(url.origin!==window.location.origin)return '';
      return url.pathname+url.search+url.hash;
    }catch(e){return '';}
  }

  function safeImageUrl(value){
    if(!value)return '';
    try{
      var url=new URL(String(value),window.location.href);
      if(url.protocol!=='http:'&&url.protocol!=='https:')return '';
      return url.toString();
    }catch(e){return '';}
  }

  function loadRecent(){
    try{
      var parsed=JSON.parse(localStorage.getItem(recentStorageKey)||'[]');
      if(!Array.isArray(parsed))return [];
      return parsed.filter(function(item){return item&&item.title&&item.type&&safeInternalUrl(item.url);}).slice(0,recentLimit);
    }catch(e){return [];}
  }

  function saveRecent(item){
    item=item||{};
    var clean={
      type:String(item.type||''),
      title:String(item.title||'').trim().slice(0,160),
      subtitle:String(item.subtitle||'').trim().slice(0,220),
      image:safeImageUrl(item.image||''),
      url:safeInternalUrl(item.url||''),
      viewedAt:Date.now()
    };
    if(!clean.type||!clean.title||!clean.url)return;
    var items=loadRecent().filter(function(existing){return !(existing.type===clean.type&&safeInternalUrl(existing.url)===clean.url);});
    items.unshift(clean);
    try{localStorage.setItem(recentStorageKey,JSON.stringify(items.slice(0,recentLimit)));}catch(e){}
    renderRecent();
  }

  function labelForType(type){
    if(type==='day-trip')return 'Day Trip';
    if(type==='weekend')return 'Weekend';
    return 'Multi-Day';
  }

  function extractBackgroundImage(node){
    if(!node)return '';
    var raw=node.style.backgroundImage||window.getComputedStyle(node).backgroundImage||'';
    var match=raw.match(/^url\(["']?(.*?)["']?\)$/i);
    return match?safeImageUrl(match[1]):'';
  }

  function recentTypeForCard(card){
    if(!card)return '';
    if(card.closest('.vb-local-section'))return 'day-trip';
    var section=card.closest('.vb-scroll-section');
    var heading=section&&section.querySelector('h2');
    var text=(heading&&heading.textContent||'').trim();
    if(text==='Weekend Getaways')return 'weekend';
    if(text==='Multi-Day Excursions')return 'multi-day';
    return '';
  }

  function rememberTripCard(card,link){
    var type=recentTypeForCard(card);
    if(!type||!link)return;
    var titleNode=card.querySelector('h2,h3');
    var subtitleNode=card.querySelector('.vb-trip-card-body p,.vb-wide-meta');
    var imageNode=card.querySelector('.vb-trip-image,.vb-wide-trip-image');
    saveRecent({
      type:type,
      title:titleNode?titleNode.textContent:'',
      subtitle:subtitleNode?subtitleNode.textContent:'',
      image:extractBackgroundImage(imageNode),
      url:link.href
    });
  }

  function buildRecentSection(){
    var mainPane=document.querySelector('[data-agent-pane="main"]');
    if(!mainPane)return null;
    var existing=mainPane.querySelector('[data-recently-viewed]');
    if(existing)return existing;
    var section=document.createElement('section');
    section.className='vb-dashboard-section vb-recent-section';
    section.setAttribute('data-recently-viewed','');
    section.innerHTML='<div class="vb-recent-head"><div><h2>Recently Viewed</h2><p>Pick up where you left off.</p></div></div><div class="vb-recent-grid" data-recent-grid></div>';
    mainPane.insertBefore(section,mainPane.firstChild);
    return section;
  }

  function renderRecent(){
    var section=buildRecentSection();
    if(!section)return;
    var grid=section.querySelector('[data-recent-grid]');
    if(!grid)return;
    grid.textContent='';
    var items=loadRecent();
    if(!items.length){
      var empty=document.createElement('div');
      empty.className='vb-recent-empty';
      empty.textContent='Trips you open from the dashboard will appear here.';
      grid.appendChild(empty);
      return;
    }
    items.forEach(function(item){
      var href=safeInternalUrl(item.url);
      if(!href)return;
      var card=document.createElement('a');
      card.className='vb-recent-card';
      card.href=href;
      card.setAttribute('data-recent-card','');

      var media=document.createElement('span');
      media.className='vb-recent-media';
      var image=safeImageUrl(item.image);
      if(image)media.style.backgroundImage='url("'+image.replace(/"/g,'%22')+'")';

      var tag=document.createElement('span');
      tag.className='vb-recent-tag type-'+item.type;
      tag.textContent=labelForType(item.type);
      media.appendChild(tag);

      var body=document.createElement('span');
      body.className='vb-recent-body';
      var title=document.createElement('strong');
      title.textContent=item.title;
      var subtitle=document.createElement('small');
      subtitle.textContent=item.subtitle||'View details';
      body.appendChild(title);
      body.appendChild(subtitle);
      card.appendChild(media);
      card.appendChild(body);
      grid.appendChild(card);
    });
  }

  document.addEventListener('click',function(event){
    var select=event.target.closest('[data-agent-tab-select]');
    if(select){var tab=select.closest('[data-agent-tab]');if(tab)selectTab(tab.getAttribute('data-tab-key'),true);return;}

    var settings=event.target.closest('[data-agent-tab-settings]');
    if(settings){
      var settingsTab=settings.closest('[data-agent-tab]');
      if(!settingsTab){
        var target=settings.getAttribute('data-agent-settings-for')||'';
        if(target)settingsTab=document.querySelector('[data-agent-tab][data-tab-key="'+target.replace(/"/g,'')+'"]');
      }
      if(!settingsTab)return;
      if(settingsTab.getAttribute('data-tab-key')==='main')openMainSettings();else openAgentSettings(settingsTab);
      return;
    }

    if(event.target.closest('[data-agent-add]')){openCreate();return;}
    if(event.target.closest('[data-tab-drawer-close]')){closeDrawer();return;}

    var tripLink=event.target.closest('.vb-trip-card a,.vb-wide-trip-card a');
    if(tripLink){
      var tripCard=tripLink.closest('.vb-trip-card,.vb-wide-trip-card');
      rememberTripCard(tripCard,tripLink);
      return;
    }

    var mapLink=event.target.closest('#vb-daytrip-map a');
    if(mapLink){
      var popup=mapLink.closest('.vb-map-popup');
      var mapTitle=popup&&popup.querySelector('strong');
      var mapSubtitle=popup&&popup.querySelector('span');
      var mapData=[];
      try{mapData=JSON.parse((document.getElementById('vb-daytrip-data')||{}).textContent||'[]')||[];}catch(e){mapData=[];}
      var title=(mapTitle&&mapTitle.textContent||'').trim();
      var match=mapData.filter(function(item){return String(item.name||'').trim()===title;})[0]||{};
      saveRecent({type:'day-trip',title:title,subtitle:mapSubtitle?mapSubtitle.textContent:'',image:match.image||'',url:mapLink.href});
    }
  });

  if(backdrop)backdrop.addEventListener('click',closeDrawer);
  document.addEventListener('keydown',function(event){if(event.key==='Escape')closeDrawer();});

  if(form)form.addEventListener('submit',function(event){
    event.preventDefault();showError('');
    if(!apiUrl)return;
    var payload=new FormData(form);
    payload.set('_csrf',csrf);
    if(saveButton){saveButton.disabled=true;saveButton.textContent=actionInput&&actionInput.value==='create'?'Creating…':'Saving…';}
    postForm(payload).then(function(data){
      if(actionInput&&actionInput.value==='create'&&data.tab&&data.tab.id){window.location.href=pageUrl+'?agent_tab='+encodeURIComponent(data.tab.id);return;}
      window.location.reload();
    }).catch(function(error){showError(error.message);if(saveButton){saveButton.disabled=false;saveButton.textContent=actionInput&&actionInput.value==='create'?'Create agent':'Save settings';}});
  });

  if(deleteButton)deleteButton.addEventListener('click',function(){
    var id=deleteButton.getAttribute('data-tab-id')||'';
    var name=deleteButton.getAttribute('data-tab-name')||'this agent';
    if(!id||!window.confirm('Delete '+name+'? This removes the tab and its saved tab settings.'))return;
    var payload=new FormData();payload.set('_csrf',csrf);payload.set('action','delete');payload.set('tab_id',id);
    deleteButton.disabled=true;deleteButton.textContent='Deleting…';showError('');
    postForm(payload).then(function(){window.location.href=pageUrl;}).catch(function(error){showError(error.message);deleteButton.disabled=false;deleteButton.textContent='Delete tab';});
  });

  renderRecent();
  selectTab(workspace.getAttribute('data-active-tab')||'main',false);
})();
