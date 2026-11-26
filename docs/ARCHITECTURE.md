# Kantina — architecture and technology

Kantina is a campus canteen system for the Lawrence Bunzel Canteen, University of San Carlos.
Students pre-order from several stalls in one tray, choose a pickup time, pay (demo GCash or at the counter),
track their order live and reserve tables. Stall staff run orders, menus, tables and sales from a store console.

It is built as **three separate systems** that integrate over REST APIs and webhooks:

- **Kantina**: the student app, the store console and the main REST API
- **Kantina ERP**: inventory, prices and the sales ledger (Enterprise Resource Planning)
- **Kantina CMS**: stalls, menu content, photos and announcements (Content Management System)

Each system has its own database and its own admin users, and can run on its own server.

---

## 1. System map

```mermaid
flowchart LR
  subgraph Users
    S[Students<br/>phone / laptop browser]
    T[Stall staff<br/>store console]
    A[Canteen admin<br/>ERP + CMS admin pages]
  end

  subgraph School server: PHP + MySQL
    subgraph K[Kantina — kantina.*]
      FE[Frontend<br/>HTML · CSS · JavaScript<br/>js/api.js]
      API[Kantina REST API · PHP<br/>router · JWT auth · validation<br/>outbox · webhook receiver]
      KDB[(MySQL<br/>kantina)]
    end
    subgraph E[Kantina ERP — kantina-erp.*]
      EAPI[ERP REST API · PHP]
      EADM[ERP admin pages]
      EDB[(MySQL<br/>kantina_erp)]
    end
    subgraph C[Kantina CMS — kantina-cms.*]
      CAPI[CMS REST API · PHP]
      CADM[CMS admin pages]
      CMED[/media photos/]
      CDB[(MySQL<br/>kantina_cms)]
    end
  end

  BREVO[Brevo<br/>transactional email API]

  S --> FE
  T --> FE
  FE -- "REST / JSON + Bearer JWT" --> API
  API --- KDB
  API -- "REST + X-Api-Key<br/>sales orders, products, reports" --> EAPI
  EAPI -. "webhooks, HMAC signed<br/>product.updated · stock.low · stock.depleted" .-> API
  API -- "REST + X-Api-Key<br/>stalls, menu content, announcements" --> CAPI
  CAPI -. "webhook, HMAC signed<br/>content.published" .-> API
  API -- "HTTPS + api-key<br/>receipts, order ready, reservations" --> BREVO
  A --> EADM
  A --> CADM
  EAPI --- EDB
  EADM --- EDB
  CAPI --- CDB
  CADM --- CDB
  FE -. "photos" .-> CMED
```

### Who owns which data

| Data | Owner | How the others get it |
|---|---|---|
| Users, accounts, roles | Kantina | — |
| Orders, order status, pickup slots | Kantina | ERP receives each order as a **sales order** |
| Tables, reservations, blocks | Kantina | — |
| Payments (demo GCash / counter) | Kantina | ERP receives *pay* / *cancel* |
| Price, stock, availability, SKU | **ERP** | Kantina caches them, updated by ERP webhooks |
| Sales ledger, revenue, top items | **ERP** | Kantina's seller analytics call ERP reports |
| Stall profiles, hours, counters, photos | **CMS** | Kantina caches them, updated by CMS webhooks |
| Menu names, descriptions, sections, add-ons | **CMS** | same |
| Announcements | **CMS** | same |

The **SKU** (e.g. `LS-TAPSILOG`) links a CMS menu entry to its ERP product.

---

## 2. Integration flows

### 2.1 Placing an order (Kantina → ERP, transactional outbox)

```mermaid
sequenceDiagram
  participant B as Student browser
  participant K as Kantina API
  participant KD as Kantina DB
  participant E as ERP API
  B->>K: POST /orders (tray, pickup time, payment method)
  K->>K: validate items, add-ons, stock, pickup slot (prices recomputed on the server)
  K->>KD: BEGIN — checkout + one order per stall + outbox message per order — COMMIT
  K->>E: POST /sales-orders {external_ref: KTN-90214, lines}
  alt ERP accepts
    E-->>K: 201 SO-002670 + new stock levels
    K->>KD: outbox = sent, order.erp_status = synced, update cached stock
    K-->>B: 201 checkout (go to payment / receipt)
  else ERP says sold out
    E-->>K: 409 insufficient_stock
    K->>KD: cancel the checkout's orders, refresh that item from the ERP
    K-->>B: 409 "Only 12 left of Pork sinigang" — tray not charged
  else ERP offline
    E--xK: connection refused
    K->>KD: outbox = failed, retry in 30 s / 2 min / 10 min / 30 min
    K-->>B: 201 checkout — the order is safe and reaches the ERP later
  end
```

- **Payment**: `POST /payments/{ref}/confirm` → orders become *paid* → outbox `erp.sales_order.pay` → ERP marks the sales order paid (it now counts as revenue).
- **Counter payment**: the stall presses **Accept** → same *pay* message.
- **Cancel** (student, stall or expired payment) → outbox `erp.sales_order.cancel` → ERP puts the stock back.
- ERP calls are **idempotent**: a repeated `POST /sales-orders` with the same `external_ref` returns the existing sales order, so retries never double-count.
- Messages for one order are delivered **in order** (create → pay → cancel).

### 2.2 Price and stock changes (ERP → Kantina, webhooks)

1. The canteen manager restocks or changes a price in the ERP admin. Or stock hits zero because of sales.
2. The ERP saves the change, then POSTs a signed webhook to `kantina/api/integrations/erp/webhook`:
   `product.updated`, `stock.low` or `stock.depleted`, with the full product.
3. Kantina verifies the HMAC signature, ignores duplicates (event id), and updates its cached price/stock.
4. Students immediately see the new price or "Sold out today". Failed deliveries can be resent from the ERP admin.

### 2.3 Content publishing (CMS → Kantina, thin webhook + pull)

```mermaid
sequenceDiagram
  participant A as Canteen admin
  participant C as CMS
  participant K as Kantina API
  A->>C: edit Tapsilog description → Save & publish
  C->>K: POST /integrations/cms/webhook {event: content.published, data: {type: menu_item, id: LS-TAPSILOG}} + signature
  K->>K: verify signature, de-duplicate
  K->>C: GET /api/v1/menu-content/LS-TAPSILOG (X-Api-Key)
  C-->>K: latest content
  K->>K: update cached menu item
  K-->>C: 200 processed
```

The webhook only says *what* changed, and Kantina fetches the current version, so stale content can never arrive late.
A full resync is available: `POST /integrations/cms/sync`.

### 2.4 Store console menu edits (Kantina → ERP + CMS)

`PATCH /seller/menu/{sku}` splits the change:
- price, stock and availability go to `PATCH erp/api/v1/products/{sku}`
- name, description and section go to `PATCH cms/api/v1/menu-content/{sku}`

New items (`POST /seller/menu`) are created in the ERP first (it generates the SKU) and then in the CMS.

### 2.5 Sales analytics (Kantina → ERP reports)

`GET /seller/analytics?range=7` calls `erp/api/v1/reports/sales` and `reports/top-items` for the staff member's stall.
Revenue includes both Kantina app orders and walk-in counter sales recorded in the ERP.

### 2.6 Email (Kantina → Brevo)

Order received, order ready and table reserved emails are sent through Brevo's transactional email REST API.
Every email is stored in the `notifications` table. Without an API key, the email is only logged.

---

## 3. Middleware and cross-cutting concerns

| Concern | How |
|---|---|
| Routing | Apache `.htaccess` rewrites `/api/*` to a front controller (`api/index.php`) → `Router` matches method + path |
| Authentication | Students and staff sign in → **JWT** (HS256, 7 days) → `Authorization: Bearer` header |
| Authorization | Role middleware: `Auth::student()`, `Auth::staff()`, `Auth::admin()`; staff see only their own stall |
| Validation | `Validator` checks every input; errors return `422` with per-field messages |
| Errors | One JSON error format `{error: {code, message, fields}}` with proper HTTP status codes |
| Logging | Every request is logged (`request_log`: method, path, status, user, time taken) |
| Brute force | 5 wrong passwords lock that login for 10 minutes (`429`) |
| System-to-system auth | `X-Api-Key` header on ERP/CMS APIs |
| Webhook security | HMAC-SHA256 signature with timestamp (`X-Kantina-Signature`), 5-minute replay window |
| Reliability | Transactional outbox with retries and backoff; idempotent ERP endpoints; webhook de-duplication |
| Consistency | Database transactions; row locks for table bookings and stock changes |
| Passwords | `password_hash` / `password_verify` (bcrypt) |
| Admin pages | PHP sessions, CSRF tokens on every form, output escaping |
| Uploads | CMS accepts images only (checked with `getimagesize`), random file names, scripts blocked in `media/` |

---

## 4. Technology stack

| Layer | Technology | Why we used it |
|---|---|---|
| Frontend | **HTML5, CSS3, vanilla JavaScript** (`fetch`, `localStorage`) | Lightweight pages that load fast on phones; no build step, so every member can edit them |
| Frontend ↔ API | **REST + JSON**, `js/api.js` helper | One place for the base URL, tokens, errors and the cart |
| Backend / API | **PHP 8** (no framework): front controller, router, middleware | Runs on XAMPP and on the school's PHP hosting with nothing to install |
| Database | **MySQL / MariaDB** (three databases: `kantina`, `kantina_erp`, `kantina_cms`) | Relational data (orders, stock, ledger) with transactions and row locking; each system owns its own data |
| Data access | **PDO** with prepared statements | Protects against SQL injection |
| ERP integration | **Kantina ERP** REST API + signed webhooks | Inventory and the sales ledger live in the ERP, not in the ordering app |
| CMS integration | **Kantina CMS** REST API + signed webhooks | Content is edited by the canteen admin without touching code |
| Authentication | **JWT** (HS256), bcrypt passwords | Stateless API sessions for the student app and console |
| Integration security | API keys, **HMAC-SHA256** webhook signatures | Systems trust only each other |
| Email (third-party cloud API) | **Brevo transactional email API** | Order and reservation emails over HTTPS; free tier |
| Payments | Simulated GCash step (PayMongo test mode as a stretch goal) | Shows the payment flow without real money |
| API documentation | **OpenAPI 3** + Swagger UI (`kantina/api/docs/`) | Interactive docs to try every endpoint |
| Hosting | **School server** PHP subdomains + MySQL; Apache `.htaccess` | One subdomain per system |
| Dev tools | XAMPP, PHP built-in server (`dev.sh`), Git + GitHub (issues, pull requests, milestones), VS Code | Team workflow and local testing |

---

## 5. Databases

| Database | Main tables |
|---|---|
| `kantina` | `users`, `stalls`*, `menu_items`*, `announcements`*, `favourites`, `checkouts`, `orders`, `order_items`, `dining_tables`, `reservations`, `table_blocks`, `integration_outbox`, `webhook_log`, `notifications`, `request_log`, `kv_store` (* = cache of CMS/ERP data) |
| `kantina_erp` | `erp_stalls`, `erp_products`, `erp_stock_movements`, `erp_sales_orders`, `erp_sales_order_lines`, `erp_webhook_deliveries`, `erp_admins` |
| `kantina_cms` | `cms_stalls`, `cms_menu_entries`, `cms_announcements`, `cms_webhook_deliveries`, `cms_admins` |

Schemas and demo data: `database/*.sql`.

---

## 6. Deployment layout

```
kantina.<school-domain>       → kantina/   (student app, seller/, api/, api/docs/)
kantina-erp.<school-domain>   → erp/       (api/, admin/)
kantina-cms.<school-domain>   → cms/       (api/, admin/, media/)
MySQL: kantina, kantina_erp, kantina_cms
```
If only one subdomain is available, the three folders can sit side by side under it (`/kantina`, `/erp`, `/cms`).
See the README, section **Deployment**.
