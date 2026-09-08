(() => {
  const quiz = document.querySelector('[data-quiz]');
  if (quiz) {
    const cards = [...quiz.querySelectorAll('.quiz-card')];
    const input = quiz.querySelector('input[name="answers_json"]');
    const bar = quiz.querySelector('[data-progress-bar]');
    const label = quiz.querySelector('[data-progress-label]');
    const answers = {};
    let index = 0;
    const render = () => {
      cards.forEach((c,i) => c.classList.toggle('active', i === index));
      const pct = Math.round((index / cards.length) * 100);
      if (bar) bar.style.width = `${pct}%`;
      if (label) label.textContent = `${Math.min(index + 1, cards.length)} of ${cards.length}`;
    };
    cards.forEach((card, cardIndex) => {
      card.querySelectorAll('[data-choice]').forEach(button => {
        button.addEventListener('click', () => {
          card.querySelectorAll('[data-choice]').forEach(b => b.classList.remove('selected'));
          button.classList.add('selected');
          answers[card.dataset.question] = Number(button.dataset.choice);
          input.value = JSON.stringify(Object.values(answers));
          setTimeout(() => {
            if (cardIndex < cards.length - 1) {
              index = cardIndex + 1;
              render();
            } else {
              quiz.requestSubmit();
            }
          }, 160);
        });
      });
    });
    render();
  }

  document.querySelectorAll('[data-copy]').forEach(btn => {
    btn.addEventListener('click', async () => {
      try {
        const source = btn.dataset.copy || '';
        const value = source.startsWith('#') ? (document.querySelector(source)?.innerText || '') : source;
        await navigator.clipboard.writeText(value);
        const old = btn.textContent;
        btn.textContent = 'Copied';
        setTimeout(() => btn.textContent = old, 1300);
      } catch (_) {}
    });
  });
})();

(() => {
  document.querySelectorAll('[data-native-share]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const text = btn.dataset.nativeShare || '';
      try {
        if (navigator.share) await navigator.share({title:'My Vacation Brain', text});
        else { await navigator.clipboard.writeText(text); btn.textContent='Copied'; setTimeout(()=>btn.textContent='Share result',1300); }
      } catch (_) {}
    });
  });
})();

(() => {
  const modal = document.querySelector('[data-match-modal]');
  if (modal) {
    modal.classList.add('open');
    const close = () => modal.classList.remove('open');
    modal.querySelectorAll('[data-match-modal-close]').forEach(btn => btn.addEventListener('click', close));
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
  }

  const chat = document.querySelector('[data-match-chat]');
  if (!chat) return;
  const stream = chat.querySelector('[data-message-stream]');
  const form = chat.querySelector('[data-match-composer]');
  const textarea = form?.querySelector('textarea[name="body"]');
  const parentInput = form?.querySelector('[data-parent-message]');
  const replyPreview = chat.querySelector('[data-reply-preview]');
  const replyCopy = chat.querySelector('[data-reply-preview-copy]');
  const errorBox = chat.querySelector('[data-chat-error]');
  const api = chat.dataset.api;
  const matchId = Number(chat.dataset.matchId || 0);
  let lastId = Number(chat.dataset.lastId || 0);
  let polling = false;
  const csrf = form?.querySelector('input[name="_csrf"]')?.value || '';
  const reactionLabels = {same:'Same',laugh:'😂',love:'♥',red_flag:'Red flag'};
  const showError = text => { if (!errorBox) return; errorBox.textContent = text || 'Something went wrong.'; errorBox.hidden = false; };
  const clearError = () => { if (errorBox) errorBox.hidden = true; };
  const scrollBottom = smooth => { if (!stream) return; stream.scrollTo({top: stream.scrollHeight, behavior: smooth ? 'smooth' : 'auto'}); };
  const formatTime = raw => { const d = new Date(String(raw).replace(' ', 'T')); return Number.isNaN(d.getTime()) ? raw : d.toLocaleString([], {month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}); };
  const setReply = (id, copy='') => { if(parentInput) parentInput.value=String(id||0); if(replyPreview){replyPreview.hidden=!id;} if(replyCopy) replyCopy.textContent=copy; textarea?.focus(); };
  const reactionButtons = (m) => Object.entries(reactionLabels).map(([key,label]) => { const r=m.reactions?.[key]||{}; return `<button type="button" class="message-reaction${r.mine?' active':''}" data-react-message="${Number(m.id)}" data-reaction="${key}">${label}${r.count?`<b>${Number(r.count)}</b>`:''}</button>`; }).join('');
  const appendMessage = m => {
    if (!stream || stream.querySelector(`[data-message-id="${Number(m.id)}"]`)) return;
    stream.querySelector('[data-chat-empty]')?.remove();
    const article = document.createElement('article'); article.className=`match-message ${m.mine?'mine':'theirs'}`; article.dataset.messageId=String(m.id);
    if(m.parent_message_id){ const rp=document.createElement('div');rp.className='message-reply-preview';const span=document.createElement('span');span.textContent=`Replying to ${m.parent_sender_name||'message'}`;const p=document.createElement('p');p.textContent=String(m.parent_body||'').slice(0,120);rp.append(span,p);article.append(rp); }
    const bubble=document.createElement('div');bubble.className='bubble';bubble.textContent=String(m.body||'');article.append(bubble);
    const footer=document.createElement('div');footer.className='message-footer';const time=document.createElement('time');time.textContent=formatTime(m.created_at||'');const tools=document.createElement('div');tools.className='message-tools';const reply=document.createElement('button');reply.type='button';reply.dataset.replyMessage=String(Number(m.id));reply.dataset.replyCopy=String(m.body||'').slice(0,130);reply.textContent='Reply';tools.append(reply);const reactionWrap=document.createElement('span');reactionWrap.innerHTML=reactionButtons(m);[...reactionWrap.children].forEach(x=>tools.append(x));if(!m.mine){const report=document.createElement('button');report.className='message-report-link';report.type='button';report.dataset.reportMessage=String(Number(m.id));report.textContent='Report';tools.append(report);}footer.append(time,tools);article.append(footer);stream.append(article);lastId=Math.max(lastId,Number(m.id)||0);
  };
  const replaceMessage = m => { const old=stream?.querySelector(`[data-message-id="${Number(m.id)}"]`); if(!old){appendMessage(m);return;} const tools=old.querySelector('.message-tools'); if(tools){tools.querySelectorAll('[data-react-message]').forEach(x=>x.remove()); const report=tools.querySelector('[data-report-message]'); const wrap=document.createElement('span'); wrap.innerHTML=reactionButtons(m); [...wrap.children].forEach(x=>tools.insertBefore(x,report||null));} };
  const poll = async () => { if(polling||document.hidden)return;polling=true;try{const res=await fetch(`${api}?match=${encodeURIComponent(matchId)}&after=${encodeURIComponent(lastId)}`,{headers:{'Accept':'application/json'},credentials:'same-origin'});const data=await res.json();if(!res.ok||!data.ok)throw new Error(data.error||'Conversation unavailable.');if(Array.isArray(data.messages)&&data.messages.length){data.messages.forEach(appendMessage);scrollBottom(true);}clearError();}catch(e){showError(e.message);}finally{polling=false;} };
  form?.addEventListener('submit', async e => { e.preventDefault();const body=textarea?.value.trim()||'';if(!body)return;const submit=form.querySelector('button[type="submit"]');if(submit)submit.disabled=true;clearError();const fd=new FormData();fd.set('_csrf',csrf);fd.set('action','send');fd.set('match_id',String(matchId));fd.set('body',body);fd.set('parent_message_id',parentInput?.value||'0');try{const res=await fetch(api,{method:'POST',body:fd,credentials:'same-origin',headers:{'Accept':'application/json'}});const data=await res.json();if(!res.ok||!data.ok)throw new Error(data.error||'Message could not be sent.');appendMessage(data.message);if(textarea)textarea.value='';setReply(0,'');scrollBottom(true);}catch(err){showError(err.message);}finally{if(submit)submit.disabled=false;textarea?.focus();} });
  textarea?.addEventListener('keydown', e => { if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();form?.requestSubmit();} });
  document.querySelectorAll('[data-chat-starter]').forEach(btn=>btn.addEventListener('click',()=>{if(textarea){textarea.value=btn.dataset.chatStarter||'';textarea.focus();}}));
  chat.addEventListener('click', async e => {
    const reply=e.target.closest('[data-reply-message]');if(reply){setReply(Number(reply.dataset.replyMessage||0),reply.dataset.replyCopy||'');return;}
    if(e.target.closest('[data-reply-cancel]')){setReply(0,'');return;}
    const react=e.target.closest('[data-react-message]');if(react){const fd=new FormData();fd.set('_csrf',csrf);fd.set('action','react');fd.set('match_id',String(matchId));fd.set('message_id',react.dataset.reactMessage||'0');fd.set('reaction',react.dataset.reaction||'');try{const res=await fetch(api,{method:'POST',body:fd,credentials:'same-origin',headers:{'Accept':'application/json'}});const data=await res.json();if(!res.ok||!data.ok)throw new Error(data.error||'Reaction failed.');replaceMessage(data.message);}catch(err){showError(err.message);}return;}
  });
  const dialog=document.querySelector('[data-report-dialog]');let reportId=0;
  document.addEventListener('click', e => { const btn=e.target.closest('[data-report-message]');if(!btn||!dialog)return;reportId=Number(btn.dataset.reportMessage||0);dialog.querySelector('[data-report-message-id]').value=String(reportId);dialog.showModal(); });
  dialog?.querySelector('[data-submit-report]')?.addEventListener('click', async () => { if(!reportId)return;const fd=new FormData();fd.set('_csrf',csrf);fd.set('action','report');fd.set('match_id',String(matchId));fd.set('message_id',String(reportId));fd.set('reason',dialog.querySelector('[data-report-reason]')?.value||'other');fd.set('details',dialog.querySelector('[data-report-details]')?.value||'');try{const res=await fetch(api,{method:'POST',body:fd,credentials:'same-origin',headers:{'Accept':'application/json'}});const data=await res.json();if(!res.ok||!data.ok)throw new Error(data.error||'Report failed.');window.location.href='match-contacts.php';}catch(err){dialog.close();showError(err.message);} });
  scrollBottom(false);setInterval(poll,4000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)poll();});
})();

(() => {



  document.querySelectorAll('[data-card-gallery]').forEach(gallery => {
    const photos = Array.from(gallery.querySelectorAll('[data-card-photo]'));
    const dots = Array.from(gallery.querySelectorAll('[data-card-dot]'));
    const counter = gallery.querySelector('[data-card-counter]');
    if (photos.length < 2) return;
    let index = Math.max(0, Math.min(photos.length - 1, Number(gallery.dataset.index || 0)));
    const render = () => {
      photos.forEach((img, i) => { img.hidden = i !== index; });
      dots.forEach((dot, i) => dot.classList.toggle('active', i === index));
      if (counter) counter.textContent = `${index + 1} / ${photos.length}`;
      gallery.dataset.index = String(index);
    };
    gallery.querySelector('[data-card-prev]')?.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); index = (index - 1 + photos.length) % photos.length; render(); });
    gallery.querySelector('[data-card-next]')?.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); index = (index + 1) % photos.length; render(); });
    render();
  });

  const profileModal = document.querySelector('[data-profile-full-modal]');
  const profileFrame = profileModal?.querySelector('[data-profile-full-frame]');
  if (profileModal && profileFrame) {
    const closeProfile = () => {
      profileModal.classList.remove('open');
      profileModal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('profile-modal-open');
      window.setTimeout(() => { profileFrame.src = 'about:blank'; }, 180);
    };
    document.querySelectorAll('[data-profile-modal-url]').forEach(btn => btn.addEventListener('click', () => {
      profileFrame.src = btn.dataset.profileModalUrl || 'about:blank';
      profileModal.classList.add('open');
      profileModal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('profile-modal-open');
    }));
    profileModal.querySelector('[data-profile-full-close]')?.addEventListener('click', closeProfile);
    profileModal.addEventListener('click', e => { if (e.target === profileModal) closeProfile(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && profileModal.classList.contains('open')) closeProfile(); });
  }

})();


(() => {
  const loader = document.querySelector('[data-travel-match-entry-loader]');
  if (!loader) return;

  const messages = [
    'Looking for people whose vacation opinions are worth investigating.',
    'Checking who also thinks 6 AM is not a vacation hour.',
    'Comparing beach priorities and questionable airport decisions.',
    'Preparing your next round of potentially compatible bad ideas.'
  ];
  const copy = loader.querySelector('[data-travel-match-loader-copy]');
  let messageTimer = 0;
  const cycleMessages = () => {
    if (!copy) return;
    let i = 0;
    messageTimer = window.setInterval(() => { i = (i + 1) % messages.length; copy.textContent = messages[i]; }, 760);
  };
  const stopMessages = () => { if (messageTimer) window.clearInterval(messageTimer); messageTimer = 0; };

  const showLoader = () => {
    loader.classList.add('open');
    loader.setAttribute('aria-hidden', 'false');
    cycleMessages();
  };
  const hideLoader = () => {
    stopMessages();
    loader.classList.remove('open', 'initial');
    loader.setAttribute('aria-hidden', 'true');
  };

  // Show the branded transition before navigating into Travel Matching so the
  // browser has something intentional to paint while the server builds discovery.
  document.addEventListener('click', event => {
    const link = event.target.closest('a[href]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    let url;
    try { url = new URL(link.href, window.location.href); } catch (_) { return; }
    if (url.origin !== window.location.origin || !/\/matching\.php$/.test(url.pathname) || document.body.dataset.page === 'matching.php') return;
    event.preventDefault();
    try { sessionStorage.setItem('vbMatchLoadStarted', String(Date.now())); } catch (_) {}
    showLoader();
    window.setTimeout(() => { window.location.href = url.href; }, 90);
  });

  if (document.body.dataset.page !== 'matching.php') return;

  // The matching page arrives server-rendered. Keep the entry animation visible
  // only until the first visible profile images are decoded, with a short minimum
  // display so the transition feels deliberate rather than flashing.
  showLoader();
  let started = Date.now();
  try { started = Number(sessionStorage.getItem('vbMatchLoadStarted')) || started; sessionStorage.removeItem('vbMatchLoadStarted'); } catch (_) {}
  const minimumTotal = 720;
  const maxImageWait = 1500;
  const images = Array.from(document.querySelectorAll('.discovery-person-card [data-card-photo]')).filter((img, index, all) => {
    const card = img.closest('.discovery-person-card');
    return card && all.filter(x => x.closest('.discovery-person-card') === card).indexOf(img) === 0;
  }).slice(0, 4);
  const decodeOne = img => {
    if (img.complete && img.naturalWidth > 0) return Promise.resolve();
    if (typeof img.decode === 'function') return img.decode().catch(() => {});
    return new Promise(resolve => { const done=()=>resolve(); img.addEventListener('load',done,{once:true}); img.addEventListener('error',done,{once:true}); });
  };
  const imageReady = Promise.all(images.map(decodeOne));
  const deadline = new Promise(resolve => window.setTimeout(resolve, maxImageWait));
  Promise.race([imageReady, deadline]).finally(() => {
    const remaining = Math.max(0, minimumTotal - (Date.now() - started));
    window.setTimeout(hideLoader, remaining);
  });
})();

(() => {
  const form = document.querySelector('[data-onboarding-form]');
  if (!form) return;
  const steps = Array.from(form.querySelectorAll('[data-onboard-step]'));
  const bar = document.querySelector('[data-onboard-progress]');
  let index = 0;
  const render = () => {
    steps.forEach((step, i) => step.classList.toggle('active', i === index));
    if (bar) bar.style.width = `${((index + 1) / Math.max(1, steps.length)) * 100}%`;
    steps[index]?.scrollIntoView({behavior:'smooth',block:'start'});
  };
  const validStep = () => {
    const required = Array.from(steps[index].querySelectorAll('[required]'));
    for (const field of required) { if (!field.checkValidity()) { field.reportValidity(); return false; } }
    return true;
  };
  form.addEventListener('click', e => {
    const next = e.target.closest('[data-onboard-next]');
    if (next) { if (!validStep()) return; index = Math.min(steps.length - 1, index + 1); render(); return; }
    const prev = e.target.closest('[data-onboard-prev]');
    if (prev) { index = Math.max(0, index - 1); render(); }
  });
  render();
})();

// v1.12 application shell
(function(){
  const sidebar=document.querySelector('[data-app-sidebar]');
  const backdrop=document.querySelector('[data-sidebar-backdrop]');
  const openBtn=document.querySelector('[data-sidebar-toggle]');
  const closeBtn=document.querySelector('[data-sidebar-close]');
  const setSidebar=(open)=>{if(!sidebar)return;sidebar.classList.toggle('open',open);if(backdrop)backdrop.classList.toggle('open',open);document.body.classList.toggle('sidebar-open',open);};
  if(openBtn)openBtn.addEventListener('click',()=>setSidebar(true));
  if(closeBtn)closeBtn.addEventListener('click',()=>setSidebar(false));
  if(backdrop)backdrop.addEventListener('click',()=>setSidebar(false));
  const menuBtn=document.querySelector('[data-user-menu-toggle]');
  const menu=document.querySelector('[data-user-menu]');
  if(menuBtn&&menu){menuBtn.addEventListener('click',(e)=>{e.stopPropagation();const open=!menu.classList.contains('open');menu.classList.toggle('open',open);menuBtn.setAttribute('aria-expanded',open?'true':'false');});document.addEventListener('click',(e)=>{if(!menu.contains(e.target)&&!menuBtn.contains(e.target)){menu.classList.remove('open');menuBtn.setAttribute('aria-expanded','false');}});}
})();


// v1.16 — open the Vacation Brain agent at the newest message while the composer stays fixed.
(() => {
  const chat = document.querySelector('[data-agent-chat]');
  if (!chat) return;
  requestAnimationFrame(() => window.scrollTo({top: document.documentElement.scrollHeight, behavior: 'auto'}));
  const input = document.querySelector('.agent-composer-dock input[name="message"]');
  if (input && window.matchMedia('(min-width: 761px)').matches) input.focus({preventScroll:true});
})();
