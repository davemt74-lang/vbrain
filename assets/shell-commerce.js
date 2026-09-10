(() => {
  const root = document.querySelector('[data-shell-actions]');
  if (!root) return;

  const api = root.dataset.shellApi || '';
  const csrf = root.dataset.shellCsrf || '';
  const cartButton = root.querySelector('[data-cart-toggle]');
  const notificationButton = root.querySelector('[data-notification-toggle]');
  const cartBadge = root.querySelector('[data-cart-count]');
  const notificationBadge = root.querySelector('[data-notification-count]');
  const notificationMenu = root.querySelector('[data-notification-menu]');
  const notificationList = root.querySelector('[data-notification-list]');
  const markAllButton = root.querySelector('[data-notification-read-all]');
  const drawer = document.querySelector('[data-cart-drawer]');
  const drawerBody = drawer?.querySelector('[data-cart-body]');
  const drawerSubtotal = drawer?.querySelector('[data-cart-subtotal]');
  const checkout = drawer?.querySelector('[data-cart-checkout]');
  const backdrop = document.querySelector('[data-shell-backdrop]');
  const closeButton = drawer?.querySelector('[data-cart-close]');
  let state = null;
  let loading = false;

  const money = value => new Intl.NumberFormat(undefined, {style:'currency', currency:'USD'}).format(Number(value || 0));
  const setBadge = (el, count) => {
    if (!el) return;
    const n = Math.max(0, Number(count || 0));
    el.textContent = n > 99 ? '99+' : String(n);
    el.classList.toggle('is-zero', n === 0);
  };
  const closeNotifications = () => {
    if (!notificationMenu) return;
    notificationMenu.classList.remove('open');
    notificationMenu.setAttribute('aria-hidden', 'true');
    notificationButton?.setAttribute('aria-expanded', 'false');
  };
  const openNotifications = async () => {
    closeCart();
    if (!notificationMenu) return;
    notificationMenu.classList.add('open');
    notificationMenu.setAttribute('aria-hidden', 'false');
    notificationButton?.setAttribute('aria-expanded', 'true');
    await loadState();
  };
  const openCart = async () => {
    closeNotifications();
    drawer?.classList.add('open');
    backdrop?.classList.add('open');
    drawer?.setAttribute('aria-hidden', 'false');
    cartButton?.setAttribute('aria-expanded', 'true');
    document.body.classList.add('vb-cart-open');
    await loadState();
  };
  const closeCart = () => {
    drawer?.classList.remove('open');
    backdrop?.classList.remove('open');
    drawer?.setAttribute('aria-hidden', 'true');
    cartButton?.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('vb-cart-open');
  };

  const renderNotifications = data => {
    setBadge(notificationBadge, data?.unread_count || 0);
    if (!notificationList) return;
    notificationList.innerHTML = '';

    if (data?.guest) {
      const box = document.createElement('div');
      box.className = 'vb-notification-empty';
      box.innerHTML = 'No personal alerts yet. <a href="' + (data.login_url || '#') + '">Log in</a> to receive match, message, trip, and Vacation Brain notifications.';
      notificationList.appendChild(box);
      if (markAllButton) markAllButton.hidden = true;
      return;
    }

    const items = Array.isArray(data?.items) ? data.items : [];
    if (markAllButton) markAllButton.hidden = Number(data?.unread_count || 0) === 0;
    if (!items.length) {
      const empty = document.createElement('div');
      empty.className = 'vb-notification-empty';
      empty.textContent = 'You are all caught up.';
      notificationList.appendChild(empty);
      return;
    }

    items.forEach(item => {
      const link = document.createElement('a');
      link.className = 'vb-notification-item' + (item.unread ? ' unread' : '');
      link.href = item.url || '#';
      const title = document.createElement('strong');
      title.textContent = item.title || 'Vacation Brain';
      const copy = document.createElement('small');
      copy.textContent = item.body || item.created_at || '';
      link.append(title, copy);
      notificationList.appendChild(link);
    });
  };

  const renderCart = data => {
    setBadge(cartBadge, data?.count || 0);
    if (!drawerBody) return;
    drawerBody.innerHTML = '';
    const items = Array.isArray(data?.items) ? data.items : [];

    if (!items.length) {
      const empty = document.createElement('div');
      empty.className = 'vb-cart-empty';
      empty.innerHTML = '<strong>Your cart is empty.</strong><span>Financially responsible. Emotionally questionable.</span>';
      drawerBody.appendChild(empty);
    } else {
      const wrap = document.createElement('div');
      wrap.className = 'vb-cart-items';
      items.forEach(item => {
        const article = document.createElement('article');
        article.className = 'vb-cart-item';
        let thumb;
        if (item.image_url) {
          thumb = document.createElement('img');
          thumb.src = item.image_url;
          thumb.alt = '';
        } else {
          thumb = document.createElement('div');
          thumb.className = 'vb-cart-thumb-empty';
          thumb.textContent = 'VB';
        }
        const copy = document.createElement('div');
        const h4 = document.createElement('h4');
        const productLink = document.createElement('a');
        productLink.href = item.product_url || '#';
        productLink.textContent = item.name || 'Vacation Brain merch';
        h4.appendChild(productLink);
        const price = document.createElement('p');
        price.textContent = money(item.price) + ' each';
        const row = document.createElement('div');
        row.className = 'vb-cart-item-row';
        const qty = document.createElement('div');
        qty.className = 'vb-cart-qty';
        const minus = document.createElement('button');
        minus.type = 'button'; minus.textContent = '−'; minus.dataset.cartQty = String(Math.max(0, Number(item.qty || 1) - 1)); minus.dataset.productId = String(item.id);
        minus.setAttribute('aria-label','Decrease quantity');
        const qtyText = document.createElement('span'); qtyText.textContent = String(item.qty || 1);
        const plus = document.createElement('button');
        plus.type = 'button'; plus.textContent = '+'; plus.dataset.cartQty = String(Math.min(10, Number(item.qty || 1) + 1)); plus.dataset.productId = String(item.id);
        plus.setAttribute('aria-label','Increase quantity');
        qty.append(minus, qtyText, plus);
        const remove = document.createElement('button');
        remove.type = 'button'; remove.className = 'vb-cart-remove'; remove.textContent = 'Remove'; remove.dataset.cartRemove = ''; remove.dataset.productId = String(item.id);
        row.append(qty, remove);
        copy.append(h4, price, row);
        article.append(thumb, copy);
        wrap.appendChild(article);
      });
      drawerBody.appendChild(wrap);
    }

    if (drawerSubtotal) drawerSubtotal.textContent = money(data?.subtotal || 0);
    if (checkout) checkout.hidden = !items.length;
  };

  const render = payload => {
    state = payload;
    renderCart(payload?.cart || {});
    renderNotifications(payload?.notifications || {});
  };

  const loadState = async () => {
    if (!api || loading) return state;
    loading = true;
    try {
      const res = await fetch(api, {credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'}});
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.error || 'Could not load Vacation Brain controls.');
      render(data);
      return data;
    } catch (_) {
      return state;
    } finally {
      loading = false;
    }
  };

  const mutate = async (action, fields = {}) => {
    if (!api) return;
    const body = new FormData();
    body.set('_csrf', csrf);
    body.set('action', action);
    Object.entries(fields).forEach(([key, value]) => body.set(key, String(value)));
    const res = await fetch(api, {method:'POST', body, credentials:'same-origin', headers:{'Accept':'application/json'}});
    let data = null;
    try { data = await res.json(); } catch (_) {}
    if (!res.ok || !data?.ok) throw new Error(data?.error || 'Vacation Brain could not update that.');
    render(data);
  };

  cartButton?.addEventListener('click', () => drawer?.classList.contains('open') ? closeCart() : openCart());
  closeButton?.addEventListener('click', closeCart);
  backdrop?.addEventListener('click', closeCart);
  notificationButton?.addEventListener('click', () => notificationMenu?.classList.contains('open') ? closeNotifications() : openNotifications());
  document.addEventListener('click', event => {
    if (notificationMenu?.classList.contains('open') && !event.target.closest('[data-notification-wrap]')) closeNotifications();
  });
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    closeCart(); closeNotifications();
  });

  drawerBody?.addEventListener('click', async event => {
    const qty = event.target.closest('[data-cart-qty]');
    const remove = event.target.closest('[data-cart-remove]');
    try {
      if (qty) await mutate('cart_qty', {product_id:qty.dataset.productId || 0, qty:qty.dataset.cartQty || 0});
      if (remove) await mutate('cart_remove', {product_id:remove.dataset.productId || 0});
    } catch (_) {}
  });
  markAllButton?.addEventListener('click', async () => {
    try { await mutate('notifications_read_all'); } catch (_) {}
  });

  window.addEventListener('vb:cart-changed', loadState);
  loadState();
  window.setInterval(() => { if (!document.hidden) loadState(); }, 30000);
})();
