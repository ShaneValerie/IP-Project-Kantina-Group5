const NAV_LINKS = [
  { label: 'Home',    href: 'home.html',   activeOn: ['home'] },
  { label: 'Reserve', href: 'reservations.html', activeOn: ['reserve', 'reservations', 'reservation-confirmed'] },
  { label: 'Orders',  href: 'orders.html',       activeOn: ['orders', 'tracking'] },
  { label: 'Profile', href: 'profile.html',      activeOn: ['profile'] }
];

// ── Topnav ──
(function renderTopnav() {
  const nav = document.querySelector('.topnav-links');
  if (!nav) return;
  const pageClass = Array.from(document.body.classList).find(c => c.startsWith('page-')) || '';
  const page = pageClass.replace('page-', '');
  nav.innerHTML = NAV_LINKS.map(l =>
    `<a href="${l.href}"${l.activeOn.includes(page) ? ' class="active"' : ''}>${l.label}</a>`
  ).join('');
})();

// ── Toast ──
let toastTimer;
function showToast(message) {
  const toast = document.getElementById('toast');
  toast.textContent = message;
  toast.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toast.classList.remove('show'), 1800);
}

// ── Back buttons ──
// Go back if we came from another Kantina page, otherwise open a sensible page
// (so the back button still works when a page is opened directly).
function goBack(fallback) {
  let sameSite = false;
  try { sameSite = document.referrer && new URL(document.referrer).origin === location.origin; } catch (e) {}
  if (sameSite && history.length > 1) history.back();
  else window.location.href = fallback;
}

// ── Render helpers ──
function setText(id, val) { const el = document.getElementById(id); if (el) el.textContent = val; }

// ── Checkout page ──
// Pickup slots: GET /pickup-slots for the stalls in the tray. Items and totals come from the tray (Cart).
let checkoutSlots = null;

function selectedSlot() {
  const btn = document.querySelector('.pickup-grid .slot[aria-pressed="true"]');
  return btn ? { date: checkoutSlots.date, time: btn.dataset.time } : null;
}

function renderPickupSlots(data) {
  const container = document.getElementById('pickup-slots');
  checkoutSlots = data;
  const prefs = Cart.prefs();
  const all = data.periods.flatMap(p => p.slots);
  // keep the student's earlier choice if it's still free, otherwise the first free slot
  const keep = prefs.pickup_date === data.date && all.find(s => s.time === prefs.pickup_time && s.available);
  const chosen = keep ? keep.time : (all.find(s => s.available) || {}).time;
  const openPeriod = Math.max(0, data.periods.findIndex(p => p.slots.some(s => s.time === chosen)));
  const days = (data.days || []).map(d =>
    `<button class="chip${d.date === data.date ? ' active' : ''}" type="button" data-date="${escapeHtml(d.date)}" aria-pressed="${d.date === data.date}">${escapeHtml(d.label)}</button>`
  ).join('');
  container.innerHTML = `
    ${days ? `<div class="chip-row pickup-days" role="group" aria-label="Pickup day">${days}</div>` : ''}
    <div class="pickup-tabs" role="tablist">
      ${data.periods.map((p, i) => `<button class="pickup-tab" role="tab" data-period="${i}" aria-selected="${i === openPeriod}">${escapeHtml(p.name)}</button>`).join('')}
    </div>
    <div class="pickup-panels">
      ${data.periods.map((p, i) => `
        <div class="pickup-panel" data-period="${i}"${i !== openPeriod ? ' hidden' : ''}>
          <div class="slot-grid pickup-grid" role="group">
            ${p.slots.map(s => s.available
              ? `<button class="slot" type="button" data-time="${escapeHtml(s.time)}" aria-pressed="${s.time === chosen}">${escapeHtml(s.label)}</button>`
              : `<button class="slot" type="button" disabled>${escapeHtml(s.label)}</button>`
            ).join('')}
          </div>
        </div>
      `).join('')}
    </div>`;
  if (chosen) Cart.setPrefs({ pickup_date: data.date, pickup_time: chosen });
  if (!chosen) showToast('No pickup times left for ' + (data.date_label || 'this day') + '. Try another day.');
  container.querySelectorAll('.pickup-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      container.querySelectorAll('.pickup-tab').forEach(t => t.setAttribute('aria-selected', 'false'));
      tab.setAttribute('aria-selected', 'true');
      container.querySelectorAll('.pickup-panel').forEach(p => p.hidden = p.dataset.period !== tab.dataset.period);
    });
  });
  container.querySelectorAll('.pickup-days .chip').forEach(chip => {
    chip.addEventListener('click', () => loadPickupSlots(chip.dataset.date));
  });
}

let slotRequest = 0;
function loadPickupSlots(date) {
  const id = ++slotRequest;
  return Api.get('/pickup-slots', { stalls: Cart.stallIds().join(','), date: date })
    .then(data => { if (id === slotRequest) renderPickupSlots(data); })
    .catch(err => {
      if (id !== slotRequest) return;
      // a day saved earlier may be in the past now: fall back to today (or tomorrow)
      if (date && err.status === 422) { Cart.setPrefs({ pickup_date: null, pickup_time: null }); return loadPickupSlots(); }
      showToast(err.message);
    });
}

// Item rows used by the checkout and receipt summaries: [{name, qty, addons, line_total}]
function summaryRows(items, fee, total, totalLabel) {
  const soft = ' style="color:var(--ink-soft);"';
  return items.map(it => {
    const extras = (it.addons || []).map(a => a.name).join(', ');
    return `<div class="summary-row"><span>${it.qty}× ${escapeHtml(it.name)}${extras ? `<span${soft}> + ${escapeHtml(extras)}</span>` : ''}</span><span>${Fmt.peso(it.line_total)}</span></div>`;
  }).join('')
    + `<div class="summary-row"><span${soft}>Service fee</span><span${soft}>${Fmt.peso(fee)}</span></div>`
    + `<div class="summary-row total"><span>${totalLabel}</span><span>${Fmt.peso(total)}</span></div>`;
}

function paymentMethod() {
  const selected = document.querySelector('#payment-group [aria-checked="true"]');
  return selected ? selected.dataset.value : 'gcash';
}

function updatePayButton() {
  const payBtn = document.getElementById('pay-btn');
  if (payBtn) payBtn.textContent = paymentMethod() === 'counter'
    ? 'Confirm order · ' + Fmt.peso(Cart.total())
    : 'Pay ' + Fmt.peso(Cart.total()) + ' →';
}

function renderCheckoutInfo() {
  const itemsEl = document.getElementById('checkout-items');
  if (!itemsEl) return;
  const user = Auth.requireStudent();
  if (!Cart.count()) { location.replace('cart.html'); return; }
  const pending = Cart.prefs().pending_checkout;
  if (pending) {
    Api.get('/checkouts/' + encodeURIComponent(pending))
      .then(res => {
        if (res.checkout.payment_status === 'unpaid') {
          showToast('Finish or cancel your GCash payment first');
          setTimeout(() => { location.replace('payment.html?ref=' + encodeURIComponent(pending)); }, 900);
        } else {
          Cart.setPrefs({ pending_checkout: null });
        }
      })
      .catch(() => Cart.setPrefs({ pending_checkout: null }));
  }
  setText('checkout-title', 'Almost there, ' + user.first_name + '!');
  const items = Cart.items().map(i => Object.assign({}, i, { line_total: Cart.lineTotal(i) }));
  itemsEl.innerHTML = summaryRows(items, Cart.SERVICE_FEE, Cart.total(), 'Total');
  setText('checkout-total', Fmt.peso(Cart.total()));
  updatePayButton();
  loadPickupSlots(Cart.prefs().pickup_date);

  // Table: the reservation saved from reserve.html?from=checkout, otherwise takeout
  const ref = Cart.prefs().reservation_ref;
  const takeout = () => {
    setText('table-name', 'Takeout · no table');
    setText('table-detail', 'Reserve a table if you’re eating here');
    setText('checkout-summary', Cart.count() + (Cart.count() === 1 ? ' item' : ' items') + ' · takeout');
  };
  if (!ref) { takeout(); return; }
  Api.get('/reservations/' + encodeURIComponent(ref))
    .then(res => {
      const r = res.reservation;
      if (!['booked', 'checked_in'].includes(r.status)) { Cart.setPrefs({ reservation_ref: null }); takeout(); return; }
      setText('table-name', 'Table ' + r.table);
      setText('table-detail', r.day_label + ' ' + r.window + ' · ' + r.party_size + (r.party_size === 1 ? ' person' : ' people'));
      setText('checkout-summary', Cart.count() + (Cart.count() === 1 ? ' item' : ' items') + ' · table ' + r.table);
    })
    .catch(() => { Cart.setPrefs({ reservation_ref: null }); takeout(); });
}

// ── GCash page ──
// GET /checkouts/{ref} for the amount; Authorize / Cancel → POST /payments/{ref}/confirm
function renderGcashInfo() {
  if (!document.getElementById('gcash-amount')) return;
  Auth.requireStudent();
  const ref = qs('ref');
  if (!ref) { location.replace('checkout.html'); return; }
  Api.get('/checkouts/' + encodeURIComponent(ref))
    .then(res => {
      const c = res.checkout;
      if (c.payment_status === 'paid') {
        if (Cart.prefs().pending_checkout === c.ref) Cart.clear();
        location.replace('receipt.html?ref=' + encodeURIComponent(c.ref));
        return;
      }
      setText('gcash-amount', Fmt.peso(c.total));
      setText('gcash-ref', 'Ref #' + c.ref);
      if (c.payment_status === 'cancelled') {
        document.getElementById('authorize-btn').disabled = true;
        showToast('This payment was cancelled. Go back to checkout to order again.');
      }
    })
    .catch(err => showToast(err.message));
}

// ── Receipt page ──
// GET /checkouts/{ref}: items, totals, pickup time and the order refs per stall
let receiptCheckout = null;

function renderReceipt() {
  const summaryBox = document.getElementById('receipt-summary');
  if (!summaryBox) return;
  const user = Auth.requireStudent();
  setText('receipt-title', 'You’re all set, ' + user.first_name + '!');
  const ref = qs('ref');
  if (!ref) { location.replace('orders.html'); return; }
  Api.get('/checkouts/' + encodeURIComponent(ref))
    .then(res => {
      const c = receiptCheckout = res.checkout;
      const live = c.orders.filter(o => o.status !== 'cancelled');
      setText('receipt-ref', 'Show this at the counter · Ref #' + c.ref);
      if (!live.length) {
        setText('receipt-title', 'This order was cancelled');
        setText('receipt-sub', c.orders[0] && c.orders[0].cancel_reason ? c.orders[0].cancel_reason : 'Nothing was charged');
      } else {
        setText('receipt-sub', 'Pickup ' + c.pickup_label + ' · ' + (live.length === 1 ? live[0].counter : live.length + ' counters'));
      }
      const label = !live.length ? 'Cancelled'
        : c.payment_status === 'paid' ? 'Total paid'
        : c.payment_method === 'counter' ? 'Pay at the counter' : 'Awaiting payment';
      const liveStalls = live.map(o => o.stall.name);
      const items = live.length ? c.items.filter(it => liveStalls.includes(it.stall_name)) : c.items;
      summaryBox.innerHTML = summaryRows(items, c.service_fee, c.total, label);
    })
    .catch(err => {
      setText('receipt-sub', err.message);
      summaryBox.innerHTML = '';
    });
}

// ── Tracking page ──
// GET /orders/{ref}, refreshed every 5 seconds until the order is completed or cancelled.
let trackedOrder = null;

function renderTrackedOrder(o) {
  trackedOrder = o;
  document.title = '#' + o.ref + ' — Kantina';
  setText('tracking-ref', '#' + o.ref + (o.stall.name ? ' · ' + o.stall.name : ''));
  setText('tracking-status', o.headline);
  setText('tracking-eta', o.status === 'cancelled' && o.cancel_reason ? o.cancel_reason : o.eta_text);
  setText('tracking-counter', o.counter);
  const steps = document.getElementById('tracking-steps');
  const cancelled = o.status === 'cancelled';
  steps.setAttribute('aria-label', cancelled ? 'Order cancelled' : `Order progress: step ${o.progress.step} of ${o.progress.total}`);
  steps.innerHTML = o.timeline.map(t =>
    `<li${t.done && t.key !== 'cancelled' ? ' class="done"' : ''}><span>${escapeHtml(t.title)}</span></li>`
  ).join('');
  document.getElementById('tracking-timeline').innerHTML = o.timeline.map(t => `
      <div class="timeline-step${t.done ? '' : ' pending'}${t.current ? ' current' : ''}">
        <span class="timeline-dot" aria-hidden="true"></span>
        <div class="timeline-title">${escapeHtml(t.title)}</div>
        ${t.sub ? `<div class="timeline-sub">${escapeHtml(t.sub)}</div>` : ''}
      </div>
    `).join('');
  const cancelBtn = document.getElementById('cancel-order-btn');
  if (cancelBtn) cancelBtn.hidden = !o.can_cancel;
}

function renderTracking() {
  const timeline = document.getElementById('tracking-timeline');
  if (!timeline) return;
  Auth.requireStudent();
  const ref = qs('ref');
  if (!ref) { location.replace('orders.html'); return; }

  const isFinal = o => ['completed', 'cancelled'].includes(o.status);
  let sent = 0;        // id of the latest request sent
  let shown = 0;       // id of the request whose answer is on screen
  let done = false;    // order finished or not found: stop for good
  const timer = setInterval(() => { if (!document.hidden) load(); }, 5000);
  function stop() { done = true; clearInterval(timer); }

  function load() {
    if (done) return Promise.resolve();
    const id = ++sent;
    return Api.get('/orders/' + encodeURIComponent(ref))
      .then(res => {
        if (done || id < shown) return;          // a newer answer is already on screen
        shown = id;
        renderTrackedOrder(res.order);
        if (isFinal(res.order)) stop();
      })
      .catch(err => {
        if (done || id < shown) return;
        if (err.status === 404) {
          stop();
          setText('tracking-status', 'Order not found');
          setText('tracking-eta', err.message);
        } else {
          showToast(err.message);   // keep polling: the connection may come back
        }
      });
  }
  load();
  document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });

  const cancelBtn = document.getElementById('cancel-order-btn');
  if (cancelBtn) cancelBtn.addEventListener('click', () => {
    if (!confirm('Cancel this order?')) return;
    cancelBtn.disabled = true;
    Api.post('/orders/' + encodeURIComponent(ref) + '/cancel')
      .then(() => { showToast('Order cancelled'); return load(); })
      .catch(err => showToast(err.message))
      .finally(() => { cancelBtn.disabled = false; });
  });
}

// ── Orders page ──
// GET /orders?status=active and ?status=history. Active cards open their tracking page.
const BADGE_CLASS = {
  pending: 'badge-preparing', paid: 'badge-preparing', preparing: 'badge-preparing',
  ready: 'badge-ready', completed: 'badge-completed', cancelled: 'badge-completed'
};

function renderOrderCard(o, muted) {
  const prog = !muted ? `<div class="order-progress" aria-label="Order progress: step ${o.progress.step} of ${o.progress.total}">${Array.from({ length: o.progress.total }, (_, i) => `<span${i < o.progress.step ? ' class="filled"' : ''}></span>`).join('')}</div>` : '';
  const badge = o.status === 'ready' ? 'Ready ✓' : o.status_label;
  const detail = muted
    ? escapeHtml(o.items_text) + ' · ' + Fmt.peso(o.total) + (o.refund_due ? ' · refund due' : '')
    : escapeHtml(o.items_text) + ' · pickup ' + escapeHtml(o.pickup_day) + ' ' + Fmt.time12(o.pickup_time);
  const top = `<div class="order-card-top"><div><div class="order-card-title">#${escapeHtml(o.ref)}</div><div class="order-card-sub">${escapeHtml(o.stall.name)} · ${detail}</div></div><span class="badge ${BADGE_CLASS[o.status] || 'badge-completed'}">${escapeHtml(badge)}</span></div>`;
  if (!muted) return `<a class="order-card" href="tracking.html?ref=${encodeURIComponent(o.ref)}" style="display:block;color:inherit;">${top}${prog}</a>`;
  return `<div class="order-card muted">${top}</div>`;
}

function renderOrders() {
  const active = document.getElementById('active-panel');
  const history = document.getElementById('history-panel');
  if (!active || !history) return;
  Auth.requireStudent();
  const fill = (panel, status, muted, empty) =>
    Api.get('/orders', { status: status })
      .then(res => {
        panel.innerHTML = res.orders.length
          ? res.orders.map(o => renderOrderCard(o, muted)).join('')
          : `<p class="load-msg">${empty}</p>`;
      })
      .catch(err => { panel.innerHTML = `<p class="load-msg">${escapeHtml(err.message)}</p>`; });
  fill(active, 'active', false, 'No orders in progress. <a href="home.html">Order something</a>');
  fill(history, 'history', true, 'No past orders yet.');
}

// ── Start the page ──
// Back/forward can restore checkout from the browser's cache without running any code,
// which would skip the unpaid-GCash check and allow a second order: reload it instead.
window.addEventListener('pageshow', (e) => {
  if (e.persisted && document.getElementById('checkout-items')) location.reload();
});

document.addEventListener('DOMContentLoaded', () => {
  renderCheckoutInfo();
  renderGcashInfo();
  renderReceipt();
  renderTracking();
  renderOrders();
});

const allSlots = () => document.querySelectorAll('.pickup-grid .slot');
document.addEventListener('click', (e) => {
  const btn = e.target.closest('.pickup-grid .slot');
  if (!btn || btn.disabled) return;
  allSlots().forEach(s => s.setAttribute('aria-pressed', 'false'));
  btn.setAttribute('aria-pressed', 'true');
  Cart.setPrefs({ pickup_date: checkoutSlots.date, pickup_time: btn.dataset.time });
});

if (document.querySelector('#payment-group')) {
  const paymentGroup = document.getElementById('payment-group');
  const payOptions = Array.from(paymentGroup.querySelectorAll('.option-row'));

  function selectPayment(el) {
    payOptions.forEach(o => o.setAttribute('aria-checked', 'false'));
    el.setAttribute('aria-checked', 'true');
    updatePayButton();
  }

  payOptions.forEach(opt => {
    opt.addEventListener('click', () => selectPayment(opt));
    opt.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectPayment(opt); }
    });
  });
}

// POST /orders → GCash: payment page; counter: receipt (the tray is cleared once the order exists)
function handlePay() {
  const slot = selectedSlot();
  if (!slot) { showToast('Pick a pickup time first'); return; }
  const method = paymentMethod();
  const payBtn = document.getElementById('pay-btn');
  payBtn.disabled = true;
  payBtn.textContent = 'Placing order…';
  Api.post('/orders', {
    items: Cart.orderItems(),
    pickup_date: slot.date,
    pickup_time: slot.time,
    payment_method: method,
    reservation_ref: Cart.prefs().reservation_ref || undefined
  })
    .then(res => {
      const ref = encodeURIComponent(res.checkout.ref);
      if (method === 'counter') {
        Cart.clear();
        location.href = 'receipt.html?ref=' + ref;
      } else {
        Cart.setPrefs({ pending_checkout: res.checkout.ref });
        location.href = 'payment.html?ref=' + ref;
      }
    })
    .catch(err => {
      showToast(err.message);
      payBtn.disabled = false;
      updatePayButton();
      if (err.code === 'slot_full') loadPickupSlots(slot.date);
    });
}

// ── GCash page ──
function confirmPayment(result) {
  const ref = qs('ref');
  const buttons = document.querySelectorAll('.footer-stack .btn');
  buttons.forEach(b => b.disabled = true);
  return Api.post('/payments/' + encodeURIComponent(ref) + '/confirm', { result: result })
    .catch(err => {
      showToast(err.message);
      buttons.forEach(b => b.disabled = false);
      // an expired or already-cancelled checkout can't be paid: send them back to order again
      if (['payment_expired', 'checkout_cancelled'].includes(err.code)) {
        Cart.setPrefs({ pending_checkout: null });
        setTimeout(() => { location.href = 'checkout.html'; }, 1500);
      }
      throw err;
    });
}

function handleAuthorize() {
  const btn = document.getElementById('authorize-btn');
  btn.textContent = 'Authorizing…';
  confirmPayment('authorized')
    .then(() => {
      Cart.clear();
      location.href = 'receipt.html?ref=' + encodeURIComponent(qs('ref'));
    })
    .catch(() => { btn.textContent = 'Authorize payment'; });
}

function handleCancel() {
  confirmPayment('cancelled')
    .then(() => {
      Cart.setPrefs({ pending_checkout: null });
      showToast('Payment cancelled');   // the tray is kept so they can try again
      setTimeout(() => { location.href = 'checkout.html'; }, 900);
    })
    .catch(() => {});
}

// ── Receipt page ──
// One stall: its tracking page. Several stalls: the orders list.
function trackOrder() {
  const live = receiptCheckout ? receiptCheckout.orders.filter(o => o.status !== 'cancelled') : [];
  window.location.href = live.length === 1 ? 'tracking.html?ref=' + encodeURIComponent(live[0].ref) : 'orders.html';
}
function backToHome() { window.location.href = 'home.html'; }

// ── Tracking page ──
function messageStall() { showToast('Messaging ' + ((trackedOrder && trackedOrder.stall.name) || 'the stall') + ' is coming soon'); }

// ── Orders page ──
const orderTabs = document.getElementById('order-tabs');
if (orderTabs) {
  const tabButtons = orderTabs.querySelectorAll('.tab-btn');
  const activePanel = document.getElementById('active-panel');
  const historyPanel = document.getElementById('history-panel');

  tabButtons.forEach(tab => {
    tab.addEventListener('click', () => {
      tabButtons.forEach(t => t.setAttribute('aria-selected', 'false'));
      tab.setAttribute('aria-selected', 'true');
      const showActive = tab.dataset.tab === 'active';
      activePanel.hidden = !showActive;
      historyPanel.hidden = showActive;
    });
  });
}
