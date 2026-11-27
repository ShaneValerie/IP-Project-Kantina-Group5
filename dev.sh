#!/usr/bin/env bash
# Local development without Apache (Mac / Linux).
# Starts the three apps with PHP's built-in server:
#   Kantina  http://localhost:8000      ERP  http://localhost:8001/admin/      CRM  http://localhost:8002/admin/
#
# MySQL settings come from environment variables (defaults: 127.0.0.1:3306, root, no password):
#   DB_PORT=3310 ./dev.sh
#
# Windows / XAMPP Apache users don't need this — see README → Local setup.
set -e
cd "$(dirname "$0")"

export KANTINA_URL="http://localhost:8000"
export ERP_URL="http://localhost:8001"
export CRM_URL="http://localhost:8002"
# Several workers so the apps can call each other (and send webhooks back) at the same time.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"

php -S localhost:8000 -t kantina tools/dev-router.php &
php -S localhost:8001 -t erp tools/dev-router.php &
php -S localhost:8002 -t crm tools/dev-router.php &
trap 'kill $(jobs -p) 2>/dev/null' EXIT INT TERM

echo ""
echo "  Kantina student app   http://localhost:8000/"
echo "  Kantina store console http://localhost:8000/seller/login.html"
echo "  Kantina ERP admin     http://localhost:8001/admin/"
echo "  Kantina CRM admin     http://localhost:8002/admin/"
echo "  Press Ctrl+C to stop."
echo ""
wait
