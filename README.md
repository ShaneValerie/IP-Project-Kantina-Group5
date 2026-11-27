# Kantina

**Campus canteen pre-ordering and table reservation system** for the Lawrence Bunzel Canteen, University of San Carlos.
IT 3206N Integrative Programming — final project, Group 5.

Students order ahead from several stalls in one cart, choose a pickup time, pay (demo GCash or at the counter), track their order live and reserve tables. Stall staff run their orders, menu, tables and sales from the store console.

Kantina is made of **three separate systems that talk to each other over REST APIs and webhooks**:

| App | Folder | What it owns | Database |
|---|---|---|---|
| **Kantina** (student app + store console + REST API) | `kantina/` | users, orders, pickup slots, tables & reservations, payments, notifications | `kantina` |
| **Kantina ERP** (inventory & sales ledger) | `erp/` | item master (SKU, price, stock), stock movements, sales orders, sales reports | `kantina_erp` |
| **Kantina CMS** (content management) | `cms/` | stall profiles & hours, menu descriptions, photos, add-ons, announcements | `kantina_cms` |

Kantina keeps a local copy of the catalog (CMS content + ERP prices/stock), refreshed by signed webhooks; every order is sent to the ERP as a sales order through a retrying outbox, so orders are never lost when the ERP is offline.

---

## Repository layout

```
kantina/          student pages, seller/ (store console), js/, api/ (Kantina REST API)
erp/              api/ (ERP REST API), admin/ (ERP admin pages)
cms/              api/ (CMS REST API), admin/ (CMS admin pages), media/ (photos)
database/         kantina.sql, kantina_erp.sql, kantina_cms.sql, install.php
docs/             API reference, architecture notes
tools/            dev helpers
dev.sh            run all three apps without Apache (Mac/Linux)
```

> `api/lib/` is identical in all three apps so each one can be deployed on its own.
> Change it in `kantina/api/lib/` and run `php tools/sync-lib.php` to copy it to `erp/` and `cms/`.

---

## Local setup (XAMPP)

You need **XAMPP** (PHP 8.1+ and MySQL/MariaDB) and Git.

1. **Put the project inside `htdocs`**
   - Windows: `C:\xampp\htdocs\`
   - Mac: `/Applications/XAMPP/xamppfiles/htdocs/`
   ```bash
   cd C:\xampp\htdocs          # or the Mac path above
   git clone https://github.com/ShaneValerie/IP-Project-Kantina-Group5.git
   ```
2. **Start Apache and MySQL** in the XAMPP control panel.
3. **Create the databases** (from inside the project folder):
   ```bash
   cd IP-Project-Kantina-Group5
   php database/install.php
   ```
   Windows: if `php` is not recognised, use `C:\xampp\php\php.exe database/install.php`.
   Different MySQL port or password? Add `--port=3307 --password=yourpass` and see *Custom settings* below.
4. **Open the apps**

   | | URL |
   |---|---|
   | Student app | http://localhost/IP-Project-Kantina-Group5/kantina/ |
   | Store console | http://localhost/IP-Project-Kantina-Group5/kantina/seller/login.html |
   | ERP admin | http://localhost/IP-Project-Kantina-Group5/erp/admin/ |
   | CMS admin | http://localhost/IP-Project-Kantina-Group5/cms/admin/ |
   | API health check | http://localhost/IP-Project-Kantina-Group5/kantina/api/health |

   (If you cloned into a different folder name, replace `IP-Project-Kantina-Group5` in the URLs.)

### Demo accounts (password for all: `kantina123`)

| Where | Login |
|---|---|
| Student app | `21-0942` (Ana Reyes) — also `22-1188`, `23-0571` |
| Store console | `stall-01` Lola's Silog · `stall-02` Kape Kanto · `stall-03` Noodle Bar · `stall-04` Grill Station |
| ERP admin / CMS admin | `admin` |

Reset everything back to the demo data any time with `php database/install.php` again.

### Custom settings

The defaults assume MySQL on `127.0.0.1:3306`, user `root`, no password, and the three app folders side by side.
If yours differ, copy `config.sample.php` to `config.local.php` in **each** app folder (`kantina/`, `erp/`, `cms/`) and edit it. `config.local.php` is ignored by Git, so your passwords never get committed.

### Mac/Linux alternative without Apache

```bash
DB_PORT=3306 ./dev.sh
```
Starts Kantina on http://localhost:8000, the ERP on :8001 and the CMS on :8002 using PHP's built-in server.

---

## Documentation

| | |
|---|---|
| **API reference** (every endpoint with example responses) | [`docs/API.md`](docs/API.md) |
| **Interactive API docs** (Swagger UI, try requests) | `kantina/api/docs/` on any running server |
| **Architecture**: system map, integration flows, tech stack | [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) |

---

## Deployment (school server)

Each app is self-contained, so the cleanest layout is **one subdomain per system**:

| Subdomain (example) | Upload the contents of | Database |
|---|---|---|
| `kantina.<domain>` | `kantina/` | `kantina` |
| `kantina-erp.<domain>` | `erp/` | `kantina_erp` |
| `kantina-cms.<domain>` | `cms/` | `kantina_cms` |

Only one subdomain? Upload the three folders side by side (`/kantina`, `/erp`, `/cms`). Only one database? Import all three SQL files into it: the ERP and CMS tables have their own prefixes.

1. **Create the databases** in the hosting panel and import, with phpMyAdmin → *Import*:
   `database/kantina.sql`, `database/kantina_erp.sql`, `database/kantina_cms.sql`.
   (Large file? `kantina_erp.sql` is about 650 KB. If the upload limit is lower, ask for it to be raised or import it from a terminal.)
2. **Generate the config files** on your laptop. The keys and secrets have to match across the three apps:
   ```bash
   php tools/make-config.php \
     --kantina-url=https://kantina.<domain> --erp-url=https://kantina-erp.<domain> --cms-url=https://kantina-cms.<domain> \
     --db-host=localhost --db-user=<db user> --db-pass=<db password>
   ```
   This writes `config.local.php` into `kantina/`, `erp/` and `cms/`. Add `--brevo-key=… --from-email=…` to send real emails.
3. **Upload** each folder's contents, including the hidden `.htaccess` files and `config.local.php`.
4. **Check** `…/api/health` on Kantina and `…/api/v1/health` on the ERP and CMS. Each should say `"ok": true`.
5. **Sync the catalog** so photo links use the live CMS address: sign in to the store console and open `kantina/admin/integrations.html` → *Sync catalog now*.
6. **Smoke test** from a laptop: `php tests/smoke.php https://kantina.<domain> https://kantina-erp.<domain> https://kantina-cms.<domain>`

---

## Team workflow

Tasks are tracked as **GitHub Issues** with a milestone per day (Day 1 – Nov 27 … Day 4 – Nov 30).

1. `git checkout main && git pull`
2. `git checkout -b <issue-number>-<short-name>` (e.g. `13-catalog-pages`)
3. Commit in our style: `ADD: …`, `UPDATE: …`, `DELETE: …`
4. Push and open a pull request with **`Closes #<issue-number>`** in the description
5. Another member reviews and merges it

| Member | GitHub | Focus |
|---|---|---|
| Razel | `rizzerk` | Project lead, backend API, ERP & CMS integrations |
| Dinnesh | `sodiumbicarbonate-29` | Student app pages ↔ API |
| Althea | `cincoalthea` | Store console pages ↔ API, QA |
| Shane | `ShaneValerie` | School server deployment, architecture diagram, paper |
