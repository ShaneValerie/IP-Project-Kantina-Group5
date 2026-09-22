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

const ACTIVE_ORDERS = [
  { ref: 'KTN-90214', stall: "Lola's Silog", detail: 'pickup 11:30', badge: 'Preparing', badgeClass: 'badge-preparing', progress: 2, progressTotal: 4 },
  { ref: 'KTN-90213', stall: 'Kape Kanto',   detail: 'counter 2',    badge: 'Ready ✓',   badgeClass: 'badge-ready' }
];

const EARLIER_ORDERS = [
  { ref: 'KTN-90180', stall: "Lola's Silog", detail: 'Table 12', badge: 'Done', badgeClass: 'badge-completed' }
];

const HISTORY_ORDERS = [
  { ref: 'KTN-90180', stall: "Lola's Silog", detail: '₱150', badge: 'Done', badgeClass: 'badge-completed' },
  { ref: 'KTN-90155', stall: 'Kape Kanto',   detail: '₱60',  badge: 'Done', badgeClass: 'badge-completed' }
];

const NAV_LINKS = [
  { label: 'Home',    href: 'home.html',   activeOn: ['home'] },
  { label: 'Reserve', href: '#',           activeOn: [] },
  { label: 'Orders',  href: 'orders.html', activeOn: ['orders'] },
  { label: 'Profile', href: '#',           activeOn: ['profile'] }
];

// ── Topnav ──
(function renderTopnav() {
  const nav = document.querySelector('.topnav-links');
  if (!nav) return;
  const page = document.body.className.replace('page-', '');
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

function renderCheckoutInfo() {
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
  setText('receipt-ref', 'Ref #' + ORDER.ref + ' · Pickup at ' + ORDER.pickupTime);
  const summaryBox = document.getElementById('receipt-summary');
  if (summaryBox) {
    summaryBox.innerHTML = ORDER.items.map(it => `
      <div class="summary-row"><span${it.soft ? ' style="color:var(--ink-soft);"' : ''}>${it.name}</span><span${it.soft ? ' style="color:var(--ink-soft);"' : ''}>₱${it.price}</span></div>
    `).join('') + `<div class="summary-row total"><span>Total paid</span><span>₱${ORDER.total}</span></div>`;
  }
}

function renderTracking() {
  setText('tracking-ref', '#' + ORDER.ref);
  setText('tracking-status', ORDER.status);
  setText('tracking-eta', ORDER.statusSub);
  setText('tracking-counter', ORDER.counter);
  const progress = document.getElementById('tracking-progress');
  if (progress) {
    progress.setAttribute('aria-label', `Order progress: step ${ORDER.statusStep} of ${ORDER.statusTotal}`);
    progress.innerHTML = Array.from({ length: ORDER.statusTotal }, (_, i) =>
      `<span${i < ORDER.statusStep ? ' class="filled"' : ''}></span>`
    ).join('');
  }
  const timeline = document.getElementById('tracking-timeline');
  if (timeline) {
    timeline.innerHTML = ORDER.timeline.map(t => `
      <div class="timeline-step${t.pending ? ' pending' : ''}">
        <span class="timeline-dot" aria-hidden="true"></span>
        <div class="timeline-title">${t.title}</div>
        ${t.sub ? `<div class="timeline-sub">${t.sub}</div>` : ''}
      </div>
    `).join('');
  }
}

function renderOrderCard(o, muted) {
  const prog = o.progress ? `<div class="order-progress" aria-label="Order progress: step ${o.progress} of ${o.progressTotal}">${Array.from({ length: o.progressTotal }, (_, i) => `<span${i < o.progress ? ' class="filled"' : ''}></span>`).join('')}</div>` : '';
  const rate = muted ? `<button class="link-btn card-link" type="button" onclick="showToast('Would open order rating')">Rate this order →</button>` : '';
  return `<div class="order-card${muted ? ' muted' : ''}">
    <div class="order-card-top"><div><div class="order-card-title">#${o.ref}</div><div class="order-card-sub">${o.stall} · ${o.detail}</div></div><span class="badge ${o.badgeClass}">${o.badge}</span></div>${prog}${rate}</div>`;
}

function renderOrders() {
  const active = document.getElementById('active-panel');
  const history = document.getElementById('history-panel');
  if (active) active.innerHTML = ACTIVE_ORDERS.map(o => renderOrderCard(o, false)).join('') +
    (EARLIER_ORDERS.length ? `<p class="section-label" id="earlier-heading" style="margin-top:22px;">Earlier today</p>` + EARLIER_ORDERS.map(o => renderOrderCard(o, true)).join('') : '');
  if (history) history.innerHTML = HISTORY_ORDERS.map(o => renderOrderCard(o, true)).join('');
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
    showToast('Order placed — pay ₱' + ORDER.total + ' at the counter');
  } else {
    window.location.href = 'payment.html';
  }
}

// ── GCash page ──
function handleAuthorize() {
  const mobile = document.getElementById('mobile')?.value.trim();
  const mpin   = document.getElementById('mpin')?.value.trim();
  if (!mobile || !mpin) { showToast('Enter your mobile number and MPIN'); return; }
  if (mpin.length < 4)  { showToast('MPIN looks too short'); return; }
  const btn = document.getElementById('authorize-btn');
  btn.disabled = true;
  btn.textContent = 'Authorizing…';
  setTimeout(() => { window.location.href = 'receipt.html'; }, 900);
}

function handleCancel() {
  showToast('Payment cancelled');
  setTimeout(() => history.back(), 900);
}

// ── Receipt page ──
function trackOrder() { window.location.href = 'tracking.html'; }
function backToHome() { window.location.href = 'home.html'; }

// ── Tracking page ──

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
