# Kantina CRM

Customer relationship management for the Kantina canteen system: student customer records, order history, loyalty points and feedback tickets. See the main README and the CRM section of `docs/ARCHITECTURE.md`.

| Part | Where |
|---|---|
| API (`/api/v1`, `X-Api-Key` header) | `api/index.php` (routes), `api/src/Customers.php` (customers + loyalty), `api/src/Tickets.php` (feedback tickets), `api/src/Reports.php` (summary) |
| Webhooks to Kantina | `api/src/Events.php` → `customer.updated`, `ticket.updated` |
| Admin pages | `admin/index.php` (dashboard), `admin/customers.php` (search, timeline, adjust points), `admin/tickets.php` (reply + status), `admin/webhooks.php` (resend) |
| Database | `../database/kantina_crm.sql` (`crm_customers`, `crm_interactions`, `crm_tickets`, `crm_webhook_deliveries`, `crm_admins`) |

**Loyalty:** 1 point per ₱10 of a completed order (rounded down). Bronze below 100 points, Silver 100–299, Gold 300+.

Endpoints, request/response examples and webhooks: `docs/API.md` → *Kantina CRM API*.
Demo admin: `admin` / `kantina123`.
