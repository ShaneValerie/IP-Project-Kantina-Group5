// ── Toast ──
let toastTimer;
function showToast(message) {
  const toast = document.getElementById('toast');
  toast.textContent = message;
  toast.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toast.classList.remove('show'), 1800);
}

// ── Checkout page ──
const allSlots = () => document.querySelectorAll('.pickup-grid .slot');
document.querySelectorAll('.pickup-grid').forEach(grid => {
  grid.addEventListener('click', (e) => {
    const btn = e.target.closest('.slot');
    if (!btn || btn.disabled) return;
    allSlots().forEach(s => s.setAttribute('aria-pressed', 'false'));
    btn.setAttribute('aria-pressed', 'true');
  });
});
if (document.querySelector('.pickup-grid')) {

  const paymentGroup = document.getElementById('payment-group');
  const payOptions = Array.from(paymentGroup.querySelectorAll('.pay-option'));

  function selectPayment(el) {
    payOptions.forEach(o => o.setAttribute('aria-checked', 'false'));
    el.setAttribute('aria-checked', 'true');
    const payBtn = document.getElementById('pay-btn');
    payBtn.textContent = el.dataset.value === 'counter' ? 'Confirm order · ₱150' : 'Pay ₱150 →';
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
    showToast('Order placed — pay ₱150 at the counter');
  } else {
    window.location.href = 'gcash.html';
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
function trackOrder() {
  window.location.href = 'tracking.html';
}

function backToHome() {
  showToast('Would return to Home');
}

// ── Tracking page ──
function messageStall() {
  showToast('Would open a chat with Lola\'s Silog');
}

// ── Orders page ──
const orderTabs = document.getElementById('order-tabs');
if (orderTabs) {
  const tabButtons   = orderTabs.querySelectorAll('.tab');
  const activePanel  = document.getElementById('active-panel');
  const earlierHeading = document.getElementById('earlier-heading');
  const earlierCard  = document.getElementById('earlier-card');
  const historyPanel = document.getElementById('history-panel');

  tabButtons.forEach(tab => {
    tab.addEventListener('click', () => {
      tabButtons.forEach(t => t.setAttribute('aria-selected', 'false'));
      tab.setAttribute('aria-selected', 'true');
      const showActive = tab.dataset.tab === 'active';
      activePanel.hidden = !showActive;
      if (earlierHeading) earlierHeading.hidden = !showActive;
      if (earlierCard)    earlierCard.hidden    = !showActive;
      historyPanel.hidden = showActive;
    });
  });

  document.querySelectorAll('.order-card-title').forEach(title => {
    title.closest('.order-card').addEventListener('click', (e) => {
      if (e.target.closest('.card-link')) return;
      showToast('Would open Order tracking (' + title.textContent + ')');
    });
  });
}


function rsvPickOne(group, selector, onPick) {
  if (!group) return;
  group.addEventListener('click', (e) => {
    const btn = e.target.closest(selector);
    if (!btn || btn.disabled || !group.contains(btn)) return;
    group.querySelectorAll(selector).forEach(b => {
      if (!b.disabled) b.setAttribute('aria-pressed', 'false');
    });
    btn.setAttribute('aria-pressed', 'true');
    if (onPick) onPick(btn);
  });
}

function rsvSelected(group, selector, attr, fallback) {
  const btn = group ? group.querySelector(selector + '[aria-pressed="true"]') : null;
  return btn ? btn.dataset[attr] : fallback;
}

// Adds minutes to a "HH:MM" string and returns "HH:MM"
function rsvAddMinutes(time, minutes) {
  const parts = String(time).split(':');
  const total = (parseInt(parts[0], 10) * 60) + parseInt(parts[1], 10) + minutes;
  const h = Math.floor(total / 60) % 24;
  const m = total % 60;
  return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
}

// ── 11 · Reserve a table ──
const reserveScreen = document.getElementById('screen-reserve');
if (reserveScreen) {
  const dayGroup   = document.getElementById('day-group');
  const partyGroup = document.getElementById('party-group');
  const slotGroup  = document.getElementById('slot-group');
  const tableGroup = document.getElementById('table-group');
  const holdBtn    = document.getElementById('hold-btn');

  function refreshHoldBtn() {
    const table = rsvSelected(tableGroup, '.rsv-table', 'table', null);
    const time  = rsvSelected(slotGroup, '.rsv-slot', 'time', null);
    if (table && time) {
      holdBtn.disabled = false;
      holdBtn.textContent = 'Hold ' + table + ' · ' + time;
    } else {
      holdBtn.disabled = true;
      holdBtn.textContent = table ? 'Pick a time slot' : 'Pick a table';
    }
  }

  rsvPickOne(dayGroup, '.rsv-chip');
  rsvPickOne(partyGroup, 'button');
  rsvPickOne(slotGroup, '.rsv-slot', refreshHoldBtn);
  rsvPickOne(tableGroup, '.rsv-table', refreshHoldBtn);
  refreshHoldBtn();

  holdBtn.addEventListener('click', () => {
    const params = new URLSearchParams({
      table: rsvSelected(tableGroup, '.rsv-table', 'table', 'T-12'),
      time:  rsvSelected(slotGroup, '.rsv-slot', 'time', '12:00'),
      day:   rsvSelected(dayGroup, '.rsv-chip', 'day', 'Today'),
      party: rsvSelected(partyGroup, 'button', 'party', '2')
    });
    showToast('Holding your table…');
    setTimeout(() => {
      window.location.href = 'reservation-confirmed.html?' + params.toString();
    }, 700);
  });
}

// ── 12 · Reservation confirmed ──
const confirmedScreen = document.getElementById('screen-reservation-confirmed');
if (confirmedScreen) {
  const params = new URLSearchParams(window.location.search);
  const table  = params.get('table') || 'T-12';
  const time   = params.get('time')  || '12:00';
  const day    = params.get('day')   || 'Today';
  const party  = params.get('party') || '2';
  const people = party + (party === '1' ? ' person' : ' people');

  const setText = (id, value) => {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  };

  setText('rc-table', table);
  setText('rc-table-2', table + ' · Main hall');
  setText('rc-window', day + ' ' + time + ' – ' + rsvAddMinutes(time, 45));
  setText('rc-daytime', day + ' · ' + time);
  setText('rc-party', people);
  setText('rc-party-2', people);

  document.getElementById('rc-calendar').addEventListener('click', () => {
    showToast('Would add ' + table + ' at ' + time + ' to your calendar');
  });

  document.getElementById('rc-cancel').addEventListener('click', () => {
    showToast('Reservation cancelled');
    setTimeout(() => { window.location.href = 'reservations.html'; }, 1000);
  });
}

// ── 13 · My reservations ──
const reservationsScreen = document.getElementById('screen-reservations');
if (reservationsScreen) {
  reservationsScreen.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;
    const card = btn.closest('.rsv-card');
    const name = card.querySelector('strong').textContent;

    if (btn.dataset.action === 'checkin') {
      showToast('Checked in · ' + name);
      card.querySelector('.rsv-badge').textContent = 'Checked in';
      btn.disabled = true;
      btn.textContent = 'Checked in ✓';
    }

    if (btn.dataset.action === 'view') {
      showToast('Would open reservation details');
    }

    if (btn.dataset.action === 'cancel') {
      const badge = card.querySelector('.rsv-badge');
      badge.textContent = 'Cancelled';
      badge.className = 'rsv-badge muted';
      card.classList.remove('current');
      card.classList.add('past');
      card.querySelector('.rsv-card-actions').remove();
      showToast('Reservation cancelled · ' + name);
    }
  });
}

// ── 14 · Profile ──
const profileScreen = document.getElementById('screen-profile');
if (profileScreen) {
  const notifToggle = document.getElementById('notif-toggle');
  notifToggle.addEventListener('click', () => {
    const on = notifToggle.classList.toggle('on');
    notifToggle.setAttribute('aria-pressed', String(on));
    showToast(on ? 'Notifications on' : 'Notifications off');
  });

  profileScreen.querySelectorAll('[data-profile-row]').forEach(row => {
    row.addEventListener('click', () => {
      showToast('Would open ' + row.dataset.profileRow);
    });
  });
}