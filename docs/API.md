# Kantina API reference

Three REST APIs, all JSON over HTTP:

| System | Base URL (local XAMPP) | Auth |
|---|---|---|
| **Kantina API** (used by the student app and store console) | `http://localhost/IP-Project-Kantina-Group5/kantina/api` | Bearer token (JWT) from `/auth/login` |
| **Kantina ERP API** | `…/erp/api/v1` | `X-Api-Key` header |
| **Kantina CMS API** | `…/cms/api/v1` | `X-Api-Key` header |

With `./dev.sh` the bases are `http://localhost:8000/api`, `http://localhost:8001/api/v1` and `http://localhost:8002/api/v1`.

From the browser, always go through `js/api.js` (`Api.get('/stalls')`). It adds the base URL and token for you.

---

## Endpoint summary

### Kantina API

| Method | Endpoint | Description | Auth required? |
|---|---|---|---|
| GET | `/health` | Health check (API + database) | No |
| POST | `/auth/login` | Student sign-in with student ID or email; returns a JWT | No |
| POST | `/auth/register` | Create a student account | No |
| POST | `/auth/staff/login` | Stall staff / admin sign-in | No |
| GET | `/auth/me` | The signed-in user | Yes |
| GET | `/stalls` | Stalls with open/closed status (filter `?category=`) | No |
| GET | `/stalls/{id}` | One stall | No |
| GET | `/stalls/{id}/menu` | A stall's menu grouped by section (CMS content + ERP price/stock) | No |
| GET | `/menu-items/{sku}` | One menu item with its add-ons | No |
| GET | `/announcements` | Home page announcements from the CMS | No |
| GET | `/pickup-slots` | Pickup times for a date and the stalls in the tray | No |
| POST | `/orders` | Check out the tray (one order per stall, synced to the ERP) | Student |
| GET | `/orders` | My orders (`?status=active` or `history`) | Student |
| GET | `/orders/{ref}` | Order details + tracking timeline | Student |
| POST | `/orders/{ref}/cancel` | Cancel before the stall starts cooking | Student |
| GET | `/checkouts/{ref}` | A checkout (receipt) | Student |
| POST | `/payments/{ref}/confirm` | Demo GCash payment: authorize or cancel | Student |
| GET | `/tables/availability` | Tables free/taken for a date, time and party size | No |
| POST | `/reservations` | Reserve a table | Student |
| GET | `/reservations` | My upcoming and past reservations | Student |
| GET | `/reservations/{ref}` | One reservation | Student |
| POST | `/reservations/{ref}/check-in` | Check in at the table | Student |
| DELETE | `/reservations/{ref}` | Cancel a reservation | Student |
| GET | `/me` | Profile, stats and favourite stalls | Student |
| PATCH | `/me` | Update name, email or pickup alerts | Student |
| POST / DELETE | `/me/favourites/{stallId}` | Add / remove a favourite stall | Student |
| GET | `/seller/orders` | Orders board for the staff member's stall | Staff |
| PATCH | `/seller/orders/{id}/status` | Move an order: paid → preparing → ready → completed (or cancelled) | Staff |
| GET | `/seller/menu` | The stall's menu with price and stock | Staff |
| POST | `/seller/menu` | Add an item (created in the ERP and the CMS) | Staff |
| PATCH | `/seller/menu/{sku}` | Edit price/stock/availability (ERP) or name/description (CMS) | Staff |
| GET | `/seller/tables` | Floor map and schedule for a day | Staff |
| POST / DELETE | `/seller/tables/{code}/block` | Block / unblock a table slot | Staff |
| GET | `/seller/analytics` | Revenue, orders, top items, peak hours from the ERP (`?range=7` or `30`) | Staff |
| POST | `/integrations/erp/webhook` | Receives ERP events (HMAC-signed) | Signature |
| POST | `/integrations/cms/webhook` | Receives CMS events (HMAC-signed) | Signature |
| POST | `/integrations/cms/sync` | Full catalog sync from the CMS and ERP | Staff |
| POST | `/integrations/outbox/retry` | Retry ERP messages that are waiting | Staff |
| GET | `/integrations/status` | ERP/CMS health, outbox, webhook and email logs | Staff |

### Kantina ERP API (`/api/v1`, header `X-Api-Key`)

| Method | Endpoint | Description |
|---|---|---|
| GET | `/health` | Health check (no key needed) |
| GET | `/products` | Item master: price, stock, availability (`?stall=`, `?low_stock=1`) |
| GET | `/products/{sku}` | One product |
| POST | `/products` | Create a product (SKU generated from the stall and name) |
| PATCH | `/products/{sku}` | Update price, name, availability or set the stock count |
| GET | `/products/{sku}/movements` | Stock movement history |
| POST | `/stock-adjustments` | Restock or correct stock |
| POST | `/sales-orders` | Record a sale and deduct stock (idempotent by `external_ref`; 409 if sold out) |
| GET | `/sales-orders` | Sales ledger (filters: stall, status, channel, from, to) |
| GET | `/sales-orders/{ref}` | One sales order with lines |
| POST | `/sales-orders/{ref}/pay` | Mark paid (counts as revenue) |
| POST | `/sales-orders/{ref}/cancel` | Cancel and return the stock |
| GET | `/reports/sales` | Revenue/orders totals, by day, by hour, by channel |
| GET | `/reports/top-items` | Best sellers |

Webhooks sent to Kantina: `product.updated`, `stock.low`, `stock.depleted`.

### Kantina CMS API (`/api/v1`, header `X-Api-Key`)

| Method | Endpoint | Description |
|---|---|---|
| GET | `/health` | Health check (no key needed) |
| GET | `/content` | All stalls, menu content and announcements in one call |
| GET | `/stalls`, `/stalls/{id}` | Stall profiles, hours, photos |
| GET | `/menu-content`, `/menu-content/{sku}` | Menu names, descriptions, photos, sections, add-ons |
| POST | `/menu-content` | Create menu content (used when staff add an item) |
| PATCH | `/menu-content/{sku}` | Update menu content |
| GET | `/announcements` | Live announcements |

Webhook sent to Kantina: `content.published` (`{type: stall | menu_item | announcement, id, action}`).

---

## Conventions

**Errors** always look like this (HTTP status 4xx/5xx):
```json
{ "error": { "code": "sold_out", "message": "Bangsilog is sold out today.", "fields": { "sku": "LS-BANGSILOG" } } }
```
`message` is written for the user, so show it in a toast. Common statuses: `401` not signed in, `403` wrong role, `404` not found, `409` conflict (sold out, slot full, table taken, wrong order step), `422` invalid input (`fields` says which), `502` the ERP or CMS is not responding.

**Money** is a number in pesos (`85`, `99.5`). **Times** are `"HH:MM"` 24-hour (`"13:00"`); `label` fields hold display text. **Dates** are `"YYYY-MM-DD"` and timestamps are ISO-8601 with `+08:00`.

**Webhook signatures**: header `X-Kantina-Signature: t=<unix time>,v1=<HMAC-SHA256 hex of "<t>.<raw body>">` using the shared secret. Requests older than 5 minutes or with a wrong signature get `401`.

---

## Auth

### `POST /auth/login`
```json
// request
{ "login": "21-0942", "password": "kantina123" }
// 200
{ "token": "eyJhbGciOi…",
  "user": { "id": 1, "role": "student", "name": "Ana Reyes", "first_name": "Ana", "initials": "AR",
            "student_id": "21-0942", "email": "ana.reyes@example.com", "program": "BS CS", "grad_year": 2027, "notify_ready": true } }
```
Wrong password → `401 invalid_credentials`. Five wrong tries → `429 too_many_attempts` for 10 minutes.

### `POST /auth/staff/login`
```json
{ "account": "stall-01", "password": "kantina123" }
// 200
{ "token": "…", "user": { "id": 4, "role": "staff", "name": "Lola's Silog staff", "account": "stall-01",
                           "stall": { "id": "lolas-silog", "name": "Lola's Silog" } } }
```

### `POST /auth/register`
`{ "student_id": "24-10001", "name": "Juan Dela Cruz", "email": "juan@example.com", "password": "at-least-8", "program": "BS IT", "grad_year": 2028 }` → `201` with `token` + `user`.

---

## Catalog

### `GET /stalls?category=Drinks`
```json
{ "stalls": [
    { "id": "lolas-silog", "name": "Lola's Silog", "category": "Rice meals", "description": "…",
      "photo_url": "http://…/cms/media/stalls/lolas-silog.jpg", "counter": "Counter 2", "prep_minutes": 10,
      "opens_at": "07:00", "closes_at": "18:00",
      "is_open": true, "accepts_orders": true, "temporarily_closed": false, "status_text": "Open" } ],
  "open_count": 2,
  "categories": ["Rice meals", "Drinks", "Noodles", "Snacks"] }
```
- `is_open`: open right now.
- `accepts_orders`: students can order or pre-order, which also works outside opening hours for a later pickup slot. Only a stall the CMS marks as *temporarily closed* has `accepts_orders: false`, so that's the one card to grey out.
- `status_text`: `"Open"`, `"Closed until 2 PM"`, `"Opens tomorrow 7 AM"`, or the CMS's closed note.

### `GET /stalls/{id}/menu`
```json
{ "stall": { …Stall… },
  "sections": [
    { "name": "Silog", "items": [
        { "sku": "LS-TAPSILOG", "name": "Tapsilog", "description": "Marinated beef tapa, …", "type": "menu", "section": "Silog",
          "price": 85, "photo_url": "http://…/cms/media/food/tapsilog.jpg",
          "available": true, "sold_out": false, "stock": 23, "low_stock": false },
        { "sku": "LS-BANGSILOG", "name": "Bangsilog", "price": 90, "available": false, "sold_out": true, … } ] },
    { "name": "Ulam", "items": [ … ] },
    { "name": "Add-ons", "items": [ { "sku": "LS-ADD-RICE", "name": "Extra rice", "price": 15, "stock": null, … } ] } ] }
```
`photo_url` can be `null`, so show a placeholder. `stock` is `null` for made-to-order items.

### `GET /menu-items/{sku}`
```json
{ "item": { …MenuItem…, "stall": { "id": "lolas-silog", "name": "Lola's Silog", "accepts_orders": true, "is_open": true } },
  "addons": [ { "sku": "LS-ADD-RICE", "name": "Extra rice", "price": 15, "available": true },
              { "sku": "LS-ADD-EGG",  "name": "Fried egg",  "price": 12, "available": true } ] }
```

### `GET /announcements`
`{ "announcements": [ { "id": 1, "title": "Skip the lunch rush", "body": "…", "image_url": null, "link_url": "home.html" } ] }`

---

## Ordering

### `GET /pickup-slots?date=2026-11-28&stalls=lolas-silog,kape-kanto`
`date` is optional: without it you get today, or tomorrow if today is over. `stalls` = the stalls in the tray (from `Cart.stallIds()`), so slots outside their hours or already full come back unavailable.
```json
{ "date": "2026-11-28", "date_label": "Today",
  "days": [ { "date": "2026-11-28", "label": "Today" }, { "date": "2026-11-29", "label": "Tomorrow" }, { "date": "2026-11-30", "label": "Mon 30" } ],
  "periods": [
    { "name": "Morning",   "slots": [ { "time": "07:00", "label": "7:00", "available": false }, … ] },
    { "name": "Lunch",     "slots": [ { "time": "12:00", "label": "12:00", "available": true }, … ] },
    { "name": "Afternoon", "slots": [ { "time": "13:00", "label": "1:00", "available": true }, … ] } ] }
```

### `POST /orders`
```json
// request (Cart.orderItems() builds "items")
{ "items": [ { "sku": "LS-TAPSILOG", "qty": 1, "addons": ["LS-ADD-RICE"], "note": "no onions" },
             { "sku": "KK-ICED-COFFEE", "qty": 1 } ],
  "pickup_date": "2026-11-28", "pickup_time": "12:00",
  "payment_method": "gcash",                 // or "counter"
  "reservation_ref": "RSV-1042" }            // optional, dine-in table
// 201
{ "checkout": Checkout }
```
The server recalculates every price and checks stock in the ERP before saying yes. Errors:
- `409 sold_out`: `fields.sku` is the item. The tray was not charged, so remove the item or lower the quantity.
- `409 slot_unavailable` / `slot_full`: pick another pickup time.
- `409 stall_closed`, `409 not_available`, `422 invalid_addon`, `409 reservation_mismatch`.

GCash orders stay `pending` until `POST /payments/{ref}/confirm`. Counter orders stay `pending` until the stall presses **Accept**. Unpaid GCash checkouts are cancelled automatically after 15 minutes.

**Checkout** (also returned by `GET /checkouts/{ref}`):
```json
{ "ref": "CHK-10023", "payment_method": "gcash", "payment_status": "unpaid",      // unpaid | paid | cancelled
  "subtotal": 160, "service_fee": 5, "total": 165,
  "pickup_date": "2026-11-28", "pickup_time": "12:00", "pickup_label": "Today · 12:00",
  "table": { "code": "T-12", "ref": "RSV-1042", "window": "12:00 – 12:45" },     // or null
  "orders": [ { "ref": "KTN-90214", "id": 90214, "stall": { "id": "lolas-silog", "name": "Lola's Silog" },
                "counter": "Counter 2", "status": "pending", "status_label": "Awaiting payment", "subtotal": 100, "cancel_reason": null },
              { "ref": "KTN-90215", "stall": { "id": "kape-kanto", "name": "Kape Kanto" }, … } ],
  "items": [ { "sku": "LS-TAPSILOG", "name": "Tapsilog", "qty": 1, "unit_price": 85,
               "addons": [ { "name": "Extra rice", "price": 15 } ], "note": "no onions", "line_total": 100, "stall_name": "Lola's Silog" }, … ] }
```

### `POST /payments/{checkoutRef}/confirm`
`{ "result": "authorized" }` → `{ "checkout": Checkout }` with `payment_status: "paid"` and orders `paid`.
`{ "result": "cancelled" }` → the orders are cancelled and the stock goes back. The cart in the browser is kept.

Only orders that are still going ahead are charged: if one order of the checkout was cancelled first, the checkout `total` already excludes it.
Errors:
- `409 payment_expired`: more than 15 minutes have passed. The order was cancelled and nothing was charged.
- `409 checkout_cancelled`: every order in it was cancelled.
- `409 already_paid`: sent `cancelled` for a paid checkout.

### `GET /orders?status=active` (or `history`)
```json
{ "orders": [
  { "ref": "KTN-90214", "id": 90214, "checkout_ref": "CHK-10023", "stall": { "id": "lolas-silog", "name": "Lola's Silog" },
    "status": "preparing", "status_label": "Preparing", "progress": { "step": 3, "total": 5 },
    "pickup_date": "2026-11-28", "pickup_day": "Today", "pickup_time": "12:00", "table": "T-12",
    "total": 100, "items_count": 1, "items_text": "1× Tapsilog", "payment_method": "gcash",
    "placed_at": "2026-11-28T11:04:00+08:00" } ] }
```
`status`: `pending` → `paid` → `preparing` → `ready` → `completed`, or `cancelled`.
`refund_due` is `true` when an order was cancelled after it was paid (by the student, the stall, or because the ERP ran out of stock); `cancel_reason` then ends with "· ₱100 to be refunded".
`status_label`: `Awaiting payment` / `Pay at counter` / `Paid` / `Preparing` / `Ready` / `Done` / `Cancelled`.

### `GET /orders/{ref}` (tracking page, poll every 5 s)
Order summary plus:
```json
{ "headline": "Your food is cooking", "eta_text": "Ready in about 6 minutes", "counter": "Counter 2",
  "payment_status": "paid", "cancel_reason": null, "can_cancel": false,
  "items": [ … ],
  "timeline": [
    { "key": "placed",    "title": "Order placed",      "at": "11:04 AM", "sub": "11:04 AM",                 "done": true,  "current": false },
    { "key": "paid",      "title": "Payment confirmed", "at": "11:04 AM", "sub": "11:04 AM · GCash",         "done": true,  "current": false },
    { "key": "preparing", "title": "Preparing",         "at": "11:06 AM", "sub": "11:06 AM · Lola's Silog",  "done": true,  "current": true },
    { "key": "ready",     "title": "Ready for pickup",  "at": null,       "sub": null,                       "done": false, "current": false },
    { "key": "completed", "title": "Completed",         "at": null,       "sub": null,                       "done": false, "current": false } ] }
```

### `POST /orders/{ref}/cancel`
Allowed while `pending` or `paid` → `{ "order": OrderDetail }`. Later → `409 cannot_cancel`.

---

## Reservations

### `GET /tables/availability?date=2026-11-28&time=12:00&party=2`
All parameters are optional (default: today, the next slot, 2 people).
```json
{ "date": "2026-11-28", "time": "12:00", "end_time": "12:45", "party": 2,
  "days": [ { "date": "2026-11-28", "label": "Today" }, { "date": "2026-11-29", "label": "Tomorrow" }, … ],
  "slots": ["11:00", "11:30", "12:00", "12:30", "13:00", "13:30", "14:00", "14:30"],
  "slot_states": [ { "time": "11:00", "past": true }, … ],
  "tables": [ { "code": "T-12", "seats": 2, "row": 3, "col": 4, "status": "free", "fits": true } ],   // status: free | taken | blocked
  "free_count": 31 }
```

### `POST /reservations`
`{ "table": "T-12", "date": "2026-11-28", "time": "12:00", "party_size": 2 }` → `201 { "reservation": Reservation }`.
Errors: `409 table_taken`, `table_blocked`, `table_too_small`, `already_booked` (you already have a table then), `slot_passed`.

**Reservation**
```json
{ "ref": "RSV-1042", "table": "T-12", "seats": 2, "date": "2026-11-28", "day_label": "Today",
  "time": "12:00", "end_time": "12:45", "window": "12:00 – 12:45", "party_size": 2,
  "status": "booked", "status_label": "Upcoming",                // booked | checked_in | completed | cancelled | released
  "detail": "2 people · Lawrence Bunzel Canteen",                // past ones: "Checked in · 45 min", "No-show · auto released"
  "hold_until": "12:10", "can_check_in": false, "can_cancel": true }
```

### `GET /reservations` → `{ "upcoming": [Reservation], "past": [Reservation] }`
### `GET /reservations/{ref}` → `{ "reservation": Reservation }`
### `POST /reservations/{ref}/check-in`
Opens 15 minutes before the start time → `{ "reservation": … }`. Too early → `409 too_early`.
### `DELETE /reservations/{ref}` → `{ "reservation": … }` with `status: "cancelled"`

---

## Profile

### `GET /me`
```json
{ "user": { …User… },
  "stats": { "orders_this_term": 12, "active_orders": 1, "upcoming_reservations": 1 },
  "favourites": [ { "id": "lolas-silog", "name": "Lola's Silog", "photo_url": "…" } ] }
```
### `PATCH /me`
Send any of `{ "name": "…", "email": "…", "notify_ready": false }` → `{ "user": … }`.
### `POST /me/favourites/{stallId}` and `DELETE /me/favourites/{stallId}` → `{ "favourites": [ … ] }`

---

## Store console (staff)

### `GET /seller/orders`
```json
{ "stall": { "id": "lolas-silog", "name": "Lola's Silog", "counter": "Counter 2" },
  "active_count": 6, "completed_today": 14,
  "orders": [
    { "id": 90214, "ref": "KTN-90214", "status": "preparing", "customer": "Ana R.",
      "items_text": "1× Tapsilog", "items": [ { "name": "Tapsilog", "qty": 1, "addons": ["Extra rice"], "note": "no onions" } ],
      "total": 100, "payment_method": "gcash", "paid": true, "can_accept": false,
      "pickup_date": "2026-11-28", "pickup_day": "Today", "pickup_time": "11:30", "table": "T-12",
      "placed_at": "…", "status_changed_at": "…", "minutes_in_status": 3, "minutes_until_pickup": 12 } ] }
```
Includes orders completed today (`status: "completed"`) so the board can show them. Hide them if you don't need them.

### `PATCH /seller/orders/{id}/status`
`{ "status": "paid" }`: **Accept** (cash received; counter orders only, so `can_accept: true`).
Then `"preparing"` (**Start**), `"ready"` (**Mark ready**, which emails the student), `"completed"` (**Complete**), or `"cancelled"`.
Wrong order of steps → `409 invalid_transition`. Accepting an unpaid GCash order → `409 awaiting_online_payment`.

### `GET /seller/menu`
```json
{ "stall": { … }, "items": [
  { "sku": "LS-TAPSILOG", "name": "Tapsilog", "description": "…", "section": "Silog", "type": "menu",
    "price": 85, "stock": 23, "track_stock": true, "available": true, "sold_out": false, "published": true, "photo_url": "…" } ] }
```
### `POST /seller/menu`
`{ "name": "Bistek with rice", "price": 95, "stock": 10, "available": true, "description": "…", "section": "Ulam" }` → `201 { "item": … }`.
The SKU is generated by the ERP (e.g. `LS-BISTEK-WITH-RICE`).
### `PATCH /seller/menu/{sku}`
Any of `{ "price": 99, "stock": 12, "available": false, "name": "…", "description": "…", "section": "…" }` → `{ "item": … }`.
If the ERP or CMS is offline → `502` with a message saying what was and wasn't saved.

### `GET /seller/tables?date=2026-11-28`
```json
{ "date": "2026-11-28", "date_label": "Today", "days": [ … ], "slots": ["11:00", …, "14:30"],
  "tables": [ { "code": "T-12", "seats": 2, "row": 3, "col": 4,
                "slots": [ { "time": "12:00", "status": "booked", "label": "Ana R.", "reservation_ref": "RSV-1042", "reason": null },
                           { "time": "13:00", "status": "blocked", "label": null, "reservation_ref": null, "reason": "Cleaning" } ] } ],
  "no_shows": [ { "ref": "RSV-1031", "table": "T-03", "time": "11:00", "name": "Leo M." } ],
  "summary": { "free": 300, "booked": 12, "held": 1, "seated": 2, "blocked": 4 } }
```
Slot `status`:
- `free`
- `booked`: a future reservation
- `held`: its start time has passed and it's waiting for check-in (up to 10 minutes)
- `seated`: checked in
- `blocked`

### `POST /seller/tables/{code}/block`
`{ "date": "2026-11-28", "time": "12:00", "reason": "Cleaning" }` → `201`. If the slot is reserved → `409 slot_reserved`.
### `DELETE /seller/tables/{code}/block?date=2026-11-28&time=12:00` → `{ "unblocked": true }`

### `GET /seller/analytics?range=7`
```json
{ "stall": { … }, "range": 7, "from": "2026-11-22", "to": "2026-11-28", "source": "erp",
  "revenue": 20982, "orders": 172, "avg_ticket": 122,
  "by_day": [ { "date": "2026-11-22", "label": "Sat", "revenue": 3804, "orders": 31 }, … ],
  "by_channel": { "kantina": { "orders": 3, "revenue": 350 }, "walk_in": { "orders": 169, "revenue": 20632 } },
  "top_items": [ { "name": "Tapsilog", "qty": 67, "revenue": 5695 }, … ],
  "peak_hours": [ { "label": "12:00–13:00", "hour": 12, "orders": 44, "share": 26 }, … ] }
```
Numbers come from the ERP sales ledger (paid sales only), covering both Kantina app orders and walk-in counter sales.
