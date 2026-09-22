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
