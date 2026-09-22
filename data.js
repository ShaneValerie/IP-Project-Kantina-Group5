// ── Temporary app data ──

const ORDER = {
  ref: 'KTN-90214',
  stall: "Lola's Silog",
  pickupTime: '11:30',
  table: 'Table 12',
  tableDetail: '12:00 – 12:45 · Dine-in',
  counter: 'Counter 2',
  total: 150,
  items: [
    { name: "Lola's Silog", price: 85 },
    { name: 'Kape Kanto',   price: 60 },
    { name: 'Service fee',  price: 5, soft: true },
  ],
};

const PICKUP_SLOTS = [
  { label: 'Morning', slots: [
    { time: '7:30' }, { time: '7:45' }, { time: '8:00' }, { time: '8:15' },
    { time: '8:30', disabled: true },
    { time: '8:45' }, { time: '9:00' }, { time: '9:15' },
  ]},
  { label: 'Lunch', slots: [
    { time: '11:30', selected: true }, { time: '11:45' }, { time: '12:00' },
    { time: '12:15' }, { time: '12:30', disabled: true }, { time: '12:45' },
  ]},
  { label: 'Afternoon', slots: [
    { time: '2:00' }, { time: '2:15' }, { time: '2:30' },
    { time: '2:45', disabled: true },
    { time: '3:00' }, { time: '3:15' }, { time: '3:30' }, { time: '3:45' },
    { time: '4:00' }, { time: '4:15' }, { time: '4:30' }, { time: '4:45' },
    { time: '5:00' },
  ]},
];

const PAYMENT_OPTIONS = [
  { value: 'gcash',   label: 'Pay instantly via GCash', sub: 'Confirmed the moment you pay', checked: true },
  { value: 'counter', label: 'Pay at counter',          sub: 'Cash or card when you pick up' },
];

const GCASH = {
  ref: ORDER.ref,
  amount: ORDER.total,
};

const TRACKING = {
  ref: ORDER.ref,
  stall: ORDER.stall,
  status: 'Preparing',
  eta: 'Ready in about 6 minutes',
  progress: { filled: 3, total: 5 },
  counter: ORDER.counter,
  timeline: [
    { title: 'Order placed',       sub: '11:04' },
    { title: 'Payment confirmed',  sub: '11:04 · GCash' },
    { title: 'Preparing',          sub: "11:06 · Lola's Silog" },
    { title: 'Ready for pickup',   pending: true },
    { title: 'Completed',          pending: true },
  ],
};

const ACTIVE_ORDERS = [
  { ref: 'KTN-90214', stall: "Lola's Silog", detail: 'pickup 11:30', badge: 'Preparing', badgeClass: 'badge-preparing', progress: { filled: 2, total: 4 } },
  { ref: 'KTN-90213', stall: 'Kape Kanto',   detail: 'counter 2',    badge: 'Ready ✓',  badgeClass: 'badge-ready' },
];

const EARLIER_ORDERS = [
  { ref: 'KTN-90180', stall: "Lola's Silog", detail: 'Table 12', badge: 'Done', badgeClass: 'badge-completed', rateable: true },
];

const HISTORY_ORDERS = [
  { ref: 'KTN-90180', stall: "Lola's Silog", detail: '₱150', badge: 'Done', badgeClass: 'badge-completed', rateable: true },
  { ref: 'KTN-90155', stall: 'Kape Kanto',   detail: '₱60',  badge: 'Done', badgeClass: 'badge-completed', rateable: true },
];
