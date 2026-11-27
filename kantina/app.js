// ── Temporary data ──
const ORDER = {
  ref: 'KTN-90214',
  stall: "Lola's Silog",
  pickupTime: '12:00',
  table: 'Table 12',
  tableDetail: '12:00 – 12:45 · Dine-in',
  total: 150,
  items: [
    { name: "Lola's Silog", price: 85 },
    { name: 'Kape Kanto',   price: 60 },
    { name: 'Service fee',  price: 5, soft: true }
  ],
  status: 'Preparing',
  statusSub: 'Ready in about 6 minutes',
  statusStep: 3,
  statusTotal: 5,
  counter: 'Counter 2',
  paymentMethod: 'GCash',
  timeline: [
    { title: 'Order placed',       sub: '11:04' },
    { title: 'Payment confirmed',  sub: '11:04 · GCash' },
    { title: 'Preparing',          sub: "11:06 · Lola's Silog" },
    { title: 'Ready for pickup',   pending: true },
    { title: 'Completed',          pending: true }
  ]
};

const PICKUP_SLOTS = {
  Morning: [
    { time: '7:00' }, { time: '7:15' }, { time: '7:30' }, { time: '7:45' },
    { time: '8:00' }, { time: '8:15' }, { time: '8:30', disabled: true }, { time: '8:45' },
    { time: '9:00' }, { time: '9:15' }, { time: '9:30' }, { time: '9:45' },
    { time: '10:00', disabled: true }, { time: '10:15' }, { time: '10:30' }, { time: '10:45' }
  ],
  Lunch: [
    { time: '11:00' }, { time: '11:15' }, { time: '11:30' }, { time: '11:45' },
    { time: '12:00', selected: true }, { time: '12:15' }, { time: '12:30', disabled: true }, { time: '12:45' }
  ],
  Afternoon: [
    { time: '1:00' }, { time: '1:15' }, { time: '1:30' }, { time: '1:45' },
    { time: '2:00' }, { time: '2:15' }, { time: '2:30' }, { time: '2:45', disabled: true },
    { time: '3:00' }, { time: '3:15' }, { time: '3:30' }, { time: '3:45' },
    { time: '4:00' }, { time: '4:15' }, { time: '4:30', disabled: true }, { time: '4:45' },
    { time: '5:00' }, { time: '5:15' }, { time: '5:30' }, { time: '5:45' }
  ]
};

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

function renderPickupSlots() {
  const container = document.getElementById('pickup-slots');
  if (!container) return;
  const periods = Object.keys(PICKUP_SLOTS);
  container.innerHTML = `
    <div class="pickup-tabs" role="tablist">
      ${periods.map((p, i) => `<button class="pickup-tab" role="tab" data-period="${p}" aria-selected="${i === 1 ? 'true' : 'false'}">${p}</button>`).join('')}
    </div>
    <div class="pickup-panels">
      ${periods.map((p, i) => `
        <div class="pickup-panel" data-period="${p}"${i !== 1 ? ' hidden' : ''}>
          <div class="slot-grid pickup-grid" role="group">
            ${PICKUP_SLOTS[p].map(s => s.disabled
              ? `<button class="slot" type="button" disabled>${s.time}</button>`
              : `<button class="slot" type="button" data-time="${s.time}" aria-pressed="${s.selected ? 'true' : 'false'}">${s.time}</button>`
            ).join('')}
          </div>
        </div>
      `).join('')}
    </div>`;
  container.querySelectorAll('.pickup-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      container.querySelectorAll('.pickup-tab').forEach(t => t.setAttribute('aria-selected', 'false'));
      tab.setAttribute('aria-selected', 'true');
      container.querySelectorAll('.pickup-panel').forEach(p => p.hidden = p.dataset.period !== tab.dataset.period);
    });
  });
}

// Item rows used by the checkout and receipt summaries
function summaryRows(totalLabel) {
  return ORDER.items.map(it => `
      <div class="summary-row"><span${it.soft ? ' style="color:var(--ink-soft);"' : ''}>${it.name}</span><span${it.soft ? ' style="color:var(--ink-soft);"' : ''}>₱${it.price}</span></div>
    `).join('') + `<div class="summary-row total"><span>${totalLabel}</span><span>₱${ORDER.total}</span></div>`;
}

// Remember how the student paid, so the receipt can say so
function savePaymentMethod(method) {
  try { sessionStorage.setItem('kantinaPayment', method); } catch (e) {}
}
function getPaymentMethod() {
  try { return sessionStorage.getItem('kantinaPayment') || 'gcash'; } catch (e) { return 'gcash'; }
}

function renderCheckoutInfo() {
  const items = document.getElementById('checkout-items');
  if (items) items.innerHTML = summaryRows('Total');
  setText('table-name', ORDER.table);
  setText('table-detail', ORDER.tableDetail);
  setText('checkout-total', '₱' + ORDER.total);
  setText('checkout-summary', ORDER.items.length + ' items · ' + ORDER.table);
  const payBtn = document.getElementById('pay-btn');
  if (payBtn) payBtn.textContent = 'Pay ₱' + ORDER.total + ' →';
}

function renderGcashInfo() {
  setText('gcash-amount', '₱' + ORDER.total + '.00');
  setText('gcash-ref', 'Ref #' + ORDER.ref);
}

function renderReceipt() {
  setText('receipt-sub', 'Pickup at ' + ORDER.pickupTime + ' · ' + ORDER.counter);
  setText('receipt-ref', 'Show this at the counter · Ref #' + ORDER.ref);
  const summaryBox = document.getElementById('receipt-summary');
  if (summaryBox) {
    const label = getPaymentMethod() === 'counter' ? 'Pay at the counter' : 'Total paid';
    summaryBox.innerHTML = summaryRows(label);
  }
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

// ── Checkout page ──
document.addEventListener('DOMContentLoaded', () => {
  renderPickupSlots();
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
});

if (document.querySelector('#payment-group')) {
  const paymentGroup = document.getElementById('payment-group');
  const payOptions = Array.from(paymentGroup.querySelectorAll('.option-row'));

  function selectPayment(el) {
    payOptions.forEach(o => o.setAttribute('aria-checked', 'false'));
    el.setAttribute('aria-checked', 'true');
    const payBtn = document.getElementById('pay-btn');
    payBtn.textContent = el.dataset.value === 'counter' ? 'Confirm order · ₱' + ORDER.total : 'Pay ₱' + ORDER.total + ' →';
  }

  payOptions.forEach(opt => {
    opt.addEventListener('click', () => selectPayment(opt));
    opt.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectPayment(opt); }
    });
  });
}

function handlePay() {
  const paymentGroup = document.getElementById('payment-group');
  const selected = paymentGroup.querySelector('[aria-checked="true"]');
  if (selected && selected.dataset.value === 'counter') {
    savePaymentMethod('counter');
    showToast('Order placed — pay ₱' + ORDER.total + ' at the counter');
    setTimeout(() => { window.location.href = 'receipt.html'; }, 900);
  } else {
    savePaymentMethod('gcash');
    window.location.href = 'payment.html';
  }
}

// ── GCash page ──
function handleAuthorize() {
  const btn = document.getElementById('authorize-btn');
  btn.disabled = true;
  btn.textContent = 'Authorizing…';
  setTimeout(() => { window.location.href = 'receipt.html'; }, 900);
}

function handleCancel() {
  showToast('Payment cancelled');
  setTimeout(() => { window.location.href = 'checkout.html'; }, 900);
}

// ── Receipt page ──
function trackOrder() { window.location.href = 'orders.html'; }   // #14 passes the real order: tracking.html?ref=…
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
