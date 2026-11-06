<?php if (!empty($isAdminArea)): ?>

</main>
</div><!-- /.admin-wrap -->
</div><!-- /.admin-shell -->
<?php elseif (!empty($isCustomerArea)): ?>
</main>
</div><!-- /.admin-wrap -->
</div><!-- /.customer-shell -->
<nav class="bottom-tabs" aria-label="Quick navigation">
  <a href="<?= BASE_URL ?>/"<?= (in_array($cur, ['index.php','product.php'], true)) ? ' class="on" aria-current="page"' : '' ?>><?= icon('store') ?><span>Shop</span></a>
  <a href="<?= BASE_URL ?>/cart.php"<?= in_array($cur, ['cart.php','checkout.php'], true) ? ' class="on" aria-current="page"' : '' ?>><span class="tab-ico"><?= icon('cart') ?><b class="dot-badge cart-badge"<?= !empty($cartN) ? '' : ' hidden' ?>><?= (int)($cartN ?? 0) ?></b></span><span>Cart</span></a>
  <a href="<?= BASE_URL ?>/my_orders.php"<?= in_array($cur, ['my_orders.php','order_detail.php','receipt.php'], true) ? ' class="on" aria-current="page"' : '' ?>><?= icon('receipt') ?><span>Orders</span></a>
  <a href="<?= BASE_URL ?>/account.php"<?= $cur === 'account.php' ? ' class="on" aria-current="page"' : '' ?>><?= icon('user') ?><span>Account</span></a>
</nav>
<?php else: ?>
</main>
<footer class="footer"><div class="container">
  <div class="foot-grid">
    <div class="footer-about"><a class="brand footer-brand" href="<?= BASE_URL ?>/"><?= $logo ?>Build<span>Smart</span></a><p>Practical hardware shopping made simpler. Browse building materials, compare products, and keep your orders organized in one place.</p></div>
    <div><h4>Explore</h4><a href="<?= BASE_URL ?>/">All products</a><a href="<?= BASE_URL ?>/#categories">Shop by category</a><a href="<?= BASE_URL ?>/cart.php">Shopping cart</a></div>
    <div><h4>Your account</h4><a href="<?= BASE_URL ?>/login.php">Sign in</a><a href="<?= BASE_URL ?>/register.php">Create account</a><a href="<?= BASE_URL ?>/my_orders.php">Order history</a><a href="<?= BASE_URL ?>/account.php">Account details</a></div>
  </div>
  <div class="copy">© <?= date('Y') ?> BuildSmart · Online Hardware Sales and Order Management System</div>
</div></footer>
<?php endif; ?>
<div class="logout-modal" id="logoutModal" aria-hidden="true">
 <div class="logout-backdrop" data-logout-cancel></div>
 <section class="logout-dialog" role="dialog" aria-modal="true" aria-labelledby="logoutTitle" aria-describedby="logoutMessage" tabindex="-1">
  <div class="logout-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M10 4H5.8A1.8 1.8 0 0 0 4 5.8v12.4A1.8 1.8 0 0 0 5.8 20H10M15 16l4-4-4-4m4 4H9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
  <h2 id="logoutTitle">Log out?</h2>
  <p id="logoutMessage">Are you sure you want to log out?</p>
  <div class="logout-actions"><button type="button" class="logout-cancel" data-logout-cancel>Cancel</button><a class="logout-confirm" href="<?= BASE_URL ?>/logout.php?t=<?= e(csrf_token()) ?>">Yes, log out</a></div>
 </section>
</div>
<div class="bs-modal" id="actionSuccessModal" aria-hidden="true">
 <div class="bs-modal-backdrop" data-action-close></div>
 <section class="bs-modal-card" role="dialog" aria-modal="true" aria-labelledby="actionSuccessTitle" tabindex="-1">
  <div class="bs-modal-icon green"><span>✓</span></div><h2 id="actionSuccessTitle">Added to cart!</h2>
  <p id="actionSuccessMessage">Your product has been added to your shopping cart.</p>
  <div class="bs-modal-actions"><button type="button" class="bs-modal-secondary" data-action-close>Continue shopping</button><a class="bs-modal-primary" href="<?= BASE_URL ?>/cart.php">View cart</a></div>
 </section>
</div>
<script>
(()=>{
 // Theme toggle: remembers the choice (the saved value is applied in <head> before first paint).
 const root=document.documentElement;
 function paintTheme(){const dark=root.getAttribute('data-theme')==='dark';document.querySelectorAll('[data-theme-toggle]').forEach(b=>{b.setAttribute('aria-pressed',dark?'true':'false');b.setAttribute('aria-label',dark?'Switch to light mode':'Switch to dark mode');});}
 document.querySelectorAll('[data-theme-toggle]').forEach(b=>b.addEventListener('click',()=>{const next=root.getAttribute('data-theme')==='dark'?'light':'dark';root.setAttribute('data-theme',next);try{localStorage.setItem('bs-theme',next);}catch(e){}paintTheme();}));
 paintTheme();
 // Search icon that expands a search field on small screens.
 document.querySelectorAll('[data-search-toggle]').forEach(btn=>{const box=document.querySelector(btn.dataset.searchToggle);if(!box)return;btn.addEventListener('click',()=>{const open=box.classList.toggle('open');btn.setAttribute('aria-expanded',open?'true':'false');if(open)box.querySelector('input')?.focus();});});
 // Sidebar highlight for #hash links (Sales Analytics, Categories) which the server cannot see.
 const nav=document.querySelector('.side-nav');
 if(nav){const norm=p=>p.replace(/index\.php$/,'').replace(/\/$/,'');const links=[...nav.querySelectorAll('a')];links.forEach(a=>a.dataset.was=a.classList.contains('on')?'1':'');
  const sync=()=>{const hit=links.find(a=>a.hash&&norm(a.pathname)===norm(location.pathname)&&a.hash===location.hash);links.forEach(a=>{let on=a.dataset.was==='1';if(hit){if(a===hit)on=true;else if(!a.hash&&norm(a.pathname)===norm(hit.pathname))on=false;}a.classList.toggle('on',on);if(on)a.setAttribute('aria-current','page');else a.removeAttribute('aria-current');});};
  sync();window.addEventListener('hashchange',sync);}
 // In the 901-1100px icon-rail layout the collapsible "System" group must stay open (labels are hidden).
 const rail=window.matchMedia('(min-width:901px) and (max-width:1100px)');
 const openMore=()=>{if(rail.matches)document.querySelectorAll('.side-more').forEach(d=>d.open=true);};openMore();rail.addEventListener('change',openMore);
})();
</script>
<script>
(()=>{
 const customerBtn=document.getElementById('customerMenuBtn'),customerNav=document.getElementById('customerNav');
 if(customerBtn&&customerNav){const close=()=>{customerNav.classList.remove('mobile-open');customerBtn.setAttribute('aria-expanded','false');};customerBtn.addEventListener('click',()=>{const open=customerNav.classList.toggle('mobile-open');customerBtn.setAttribute('aria-expanded',open?'true':'false');});customerNav.querySelectorAll('a').forEach(a=>a.addEventListener('click',close));document.addEventListener('keydown',e=>{if(e.key==='Escape')close();});}
 const toast=document.getElementById('toast');let toastTimer;
 function showToast(message,ok=true){if(!toast)return;toast.textContent=message;toast.className='toast show '+(ok?'success':'error');toast.setAttribute('role',ok?'status':'alert');clearTimeout(toastTimer);toastTimer=setTimeout(()=>toast.classList.remove('show'),3800);}const flashSource=document.querySelector('[data-flash-message]');if(flashSource)showToast(flashSource.dataset.flashMessage,true);
 const successModal=document.getElementById('actionSuccessModal');function showAddedModal(message){if(!successModal)return;const msg=document.getElementById('actionSuccessMessage');if(msg)msg.textContent=message||'Your product has been added to your shopping cart.';successModal.classList.add('is-open');successModal.setAttribute('aria-hidden','false');document.body.classList.add('bs-modal-open');successModal.querySelector('.bs-modal-card')?.focus();}function closeAddedModal(){if(!successModal)return;successModal.classList.remove('is-open');successModal.setAttribute('aria-hidden','true');document.body.classList.remove('bs-modal-open');}successModal?.querySelectorAll('[data-action-close]').forEach(el=>el.addEventListener('click',closeAddedModal));document.addEventListener('keydown',e=>{if(e.key==='Escape')closeAddedModal();});
 // Mobile admin tables become readable label/value cards.
 document.querySelectorAll('.admin-body table').forEach(table=>{const heads=[...table.querySelectorAll('thead th')].map(th=>th.textContent.trim());table.querySelectorAll('tbody tr').forEach(row=>[...row.cells].forEach((cell,i)=>{if(heads[i]&&!cell.hasAttribute('data-label'))cell.setAttribute('data-label',heads[i]);}));});
 // Keep keyboard focus inside any open accessible dialog.
 document.addEventListener('keydown',e=>{if(e.key!=='Tab')return;const dlg=[...document.querySelectorAll('[aria-modal="true"]')].find(d=>d.getClientRects().length>0&&!d.closest('[hidden]'));if(!dlg)return;const items=[...dlg.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')].filter(x=>x.getClientRects().length>0);if(!items.length)return;const first=items[0],last=items[items.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}});
 // Connect adjacent form labels to their controls when legacy markup omitted for/id.
 document.querySelectorAll('form label:not([for])').forEach((label)=>{let control=label.nextElementSibling;while(control && !/^(INPUT|SELECT|TEXTAREA)$/.test(control.tagName)) control=control.nextElementSibling;if(control){if(!control.id)control.id='bs-field-'+Math.random().toString(36).slice(2,10);label.htmlFor=control.id;}});
 // Lock ordinary submit buttons while a request is in flight to prevent duplicate orders.
 document.addEventListener('submit',e=>{const form=e.target;if(!(form instanceof HTMLFormElement))return;if(e.defaultPrevented)return;if(form.classList.contains('ajax-add-form'))return;if(form.dataset.submitting==='1'){e.preventDefault();return;}form.dataset.submitting='1';const btn=form.querySelector('button[type="submit"],button:not([type])');if(btn && !btn.disabled){btn.dataset.originalText=btn.textContent.trim();btn.disabled=true;btn.classList.add('is-loading');btn.setAttribute('aria-busy','true');btn.textContent=(form.id==='checkout-form'?'Placing order…':'Please wait…');}});
 window.addEventListener('pageshow',e=>{if(!e.persisted)return;document.querySelectorAll('form[data-submitting="1"]').forEach(f=>{f.dataset.submitting='0';const b=f.querySelector('button.is-loading');if(b){b.disabled=false;b.classList.remove('is-loading');b.removeAttribute('aria-busy');b.textContent=b.dataset.originalText||b.textContent;}});});
 document.addEventListener('submit',async e=>{const form=e.target.closest('.ajax-add-form');if(!form)return;e.preventDefault();if(form.dataset.submitting==='1')return;form.dataset.submitting='1';const btn=form.querySelector('button[type="submit"],button:not([type])');if(btn){btn.disabled=true;btn.classList.add('is-loading');btn.setAttribute('aria-busy','true');btn.dataset.old=btn.textContent;btn.textContent='Adding…';}try{const res=await fetch(form.action,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});const raw=await res.text();let data;try{data=JSON.parse(raw);}catch(parseError){throw new Error('The server returned an unexpected response. Refresh the page and try again.');}if(data.ok&&res.ok){document.querySelectorAll('.cart-badge').forEach(b=>{b.textContent=data.count;b.hidden=!Number(data.count);});showAddedModal(data.message||'Your product has been added to your shopping cart.');}else showToast(data.message||'Could not add this item.',false);}catch(err){showToast('Unable to add item. Please try again.',false);}finally{form.dataset.submitting='0';if(btn){btn.disabled=false;btn.classList.remove('is-loading');btn.removeAttribute('aria-busy');btn.textContent=btn.dataset.old||'Add to Cart';}}});
 const qty=document.getElementById('product-quantity');if(qty){const price=<?=json_encode((float)($p['price']??0))?>;const max=Number(qty.max)||999;const fmt=n=>'₱'+n.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});function update(){let n=Math.max(1,Math.min(max,Number(qty.value)||1));qty.value=n;const buyQty=document.getElementById('buy-now-quantity');if(buyQty)buyQty.value=n;const total=n*price;const line=document.getElementById('line-total');if(line)line.innerHTML=n+' × '+fmt(price)+' = <strong>'+fmt(total)+'</strong>';const mobile=document.getElementById('mobile-line-total');if(mobile)mobile.textContent=fmt(total);}document.querySelector('.qty-minus')?.addEventListener('click',()=>{qty.value=Math.max(1,Number(qty.value)-1);update()});document.querySelector('.qty-plus')?.addEventListener('click',()=>{qty.value=Math.min(max,Number(qty.value)+1);update()});qty.addEventListener('input',update);update();}
})();
</script>
<script>
(()=>{
 const body=document.body,menuBtn=document.getElementById('menuBtn');
 function side(open){body.classList.toggle('side-open',open);if(menuBtn)menuBtn.setAttribute('aria-expanded',open?'true':'false');}
 menuBtn?.addEventListener('click',()=>side(!body.classList.contains('side-open')));
 document.querySelectorAll('[data-side-close]').forEach(el=>el.addEventListener('click',()=>side(false)));
 document.querySelectorAll('.side-nav a,.side-foot a').forEach(a=>a.addEventListener('click',()=>side(false)));
 const dds=[...document.querySelectorAll('[data-dd]')];
 function closeDd(except){dds.forEach(d=>{if(d!==except){d.classList.remove('open');d.querySelector('[data-dd-btn]')?.setAttribute('aria-expanded','false');}});}
 dds.forEach(d=>d.querySelector('[data-dd-btn]').addEventListener('click',e=>{e.stopPropagation();const o=!d.classList.contains('open');closeDd(d);d.classList.toggle('open',o);e.currentTarget.setAttribute('aria-expanded',o?'true':'false');}));
 document.addEventListener('click',e=>{if(!e.target.closest('[data-dd]'))closeDd();});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeDd();side(false);}});
 window.matchMedia('(min-width:901px)').addEventListener('change',e=>{if(e.matches)side(false);});
})();
</script>
<script>
(()=>{
 const modal=document.getElementById('logoutModal');if(!modal)return;
 const dialog=modal.querySelector('.logout-dialog');let returnFocus=null;
 function openModal(e){e.preventDefault();returnFocus=e.currentTarget;modal.classList.add('is-open');modal.setAttribute('aria-hidden','false');document.body.classList.add('logout-modal-open');dialog.focus();}
 function closeModal(){modal.classList.remove('is-open');modal.setAttribute('aria-hidden','true');document.body.classList.remove('logout-modal-open');returnFocus?.focus();}
 document.querySelectorAll('[data-logout-trigger]').forEach(link=>link.addEventListener('click',openModal));
 modal.querySelectorAll('[data-logout-cancel]').forEach(el=>el.addEventListener('click',closeModal));
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&modal.classList.contains('is-open'))closeModal();});
})();
</script>
</body></html>
