(function(){
  'use strict';

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

  selectTab(workspace.getAttribute('data-active-tab')||'main',false);
})();
