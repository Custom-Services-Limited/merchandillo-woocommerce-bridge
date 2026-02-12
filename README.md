# Merchandillo Bridge for WooCommerce

Standalone WooCommerce plugin that syncs order changes to the Merchandillo platform.

## What It Does

- Adds a plugin settings page in `Settings > Merchandillo Sync`
- Stores Merchandillo credentials (`API base URL`, `API key`, `API secret`)
- Watches WooCommerce order lifecycle events:
  - new order created
  - order updated
  - order status changed
- Queues syncs asynchronously via WP-Cron and sends order payloads to:
  - `POST /api/woocommerce/orders`
- Never blocks checkout: failures are logged, not thrown

## Local Development Environment

This repository includes a full local WordPress + WooCommerce stack with this plugin mounted and enabled.

### Prerequisites

- Docker Desktop (or Docker Engine with Compose)

### Start Environment

```bash
./scripts/dev-up.sh
```

This script will:

1. Start MariaDB + WordPress containers
2. Install WordPress (first run)
3. Install and activate WooCommerce
4. Activate this plugin
5. Seed sample products and sample orders

Default URL and credentials:

- URL: [http://localhost:8088](http://localhost:8088)
- Admin user: `admin`
- Admin password: `admin123!`

### Stop Environment

```bash
./scripts/dev-down.sh
```

## Configure Merchandillo Credentials

Go to:

- `WordPress Admin > Settings > Merchandillo Sync`

Set:

- `API Base URL` (example: `https://data.merchandillo.com`)
- `API Key`
- `API Secret`

## Logs

If sync fails, check:

- `WooCommerce > Status > Logs`
- Select log source: `merchandillo-woocommerce-bridge`

## API Contract

The plugin currently sends one order per request to:

- `POST {API_BASE_URL}/api/woocommerce/orders`

Headers:

- `X-API-Key: <api_key>`
- `X-API-Secret: <api_secret>`

The payload includes customer details, addresses, totals, status, and line items.
