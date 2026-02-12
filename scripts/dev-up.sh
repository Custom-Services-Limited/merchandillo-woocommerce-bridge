#!/usr/bin/env bash

if [ -z "${BASH_VERSION:-}" ]; then
  exec bash "$0" "$@"
fi

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

WP_URL="${WP_URL:-http://localhost:8088}"
WP_TITLE="${WP_TITLE:-Merchandillo Woo Dev}"
WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
WP_ADMIN_PASSWORD="${WP_ADMIN_PASSWORD:-admin123!}"
WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@example.com}"

compose() {
  docker compose "$@"
}

wp_cli() {
  compose run --rm --user 33:33 -e HOME=/tmp wpcli wp --allow-root "$@"
}

prepare_wordpress_fs() {
  compose exec -T wordpress sh -lc '
    mkdir -p /var/www/html/wp-content/uploads /var/www/html/wp-content/upgrade &&
    chown -R www-data:www-data /var/www/html/wp-content/uploads /var/www/html/wp-content/upgrade
  '
}

if ! docker info >/dev/null 2>&1; then
  echo "Docker daemon is not running. Start Docker Desktop/Engine and rerun ./scripts/dev-up.sh" >&2
  exit 1
fi

echo "Starting WordPress and MariaDB containers..."
compose up -d db wordpress

echo "Waiting for WordPress files to become available..."
until wp_cli core version >/dev/null 2>&1; do
  sleep 2
done

prepare_wordpress_fs

if ! wp_cli core is-installed >/dev/null 2>&1; then
  echo "Installing WordPress..."
  if ! compose exec -T wordpress test -f /var/www/html/wp-config.php; then
    wp_cli config create \
      --dbname=wordpress \
      --dbuser=wordpress \
      --dbpass=wordpress \
      --dbhost=db:3306 \
      --skip-check
  else
    echo "wp-config.php already exists, skipping config create."
  fi

  wp_cli core install \
    --url="$WP_URL" \
    --title="$WP_TITLE" \
    --admin_user="$WP_ADMIN_USER" \
    --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="$WP_ADMIN_EMAIL"
fi

if ! wp_cli plugin is-installed woocommerce >/dev/null 2>&1; then
  echo "Installing WooCommerce..."
  wp_cli plugin install woocommerce
fi

wp_cli plugin activate woocommerce
wp_cli plugin activate merchandillo-woocommerce-bridge

# Keep defaults sane for local testing.
if ! wp_cli option patch update merchandillo_sync_options api_base_url "${MERCHANDILLO_API_BASE_URL:-http://host.docker.internal:8787}" >/dev/null 2>&1; then
  wp_cli option update merchandillo_sync_options "{\"enabled\":\"1\",\"api_base_url\":\"${MERCHANDILLO_API_BASE_URL:-http://host.docker.internal:8787}\",\"api_key\":\"\",\"api_secret\":\"\",\"log_errors\":\"1\"}" --format=json
fi

# Seed products and orders to validate synchronization.
wp_cli eval-file /scripts/seed-sample-data.php

echo ""
echo "Environment ready:"
echo "- WordPress: $WP_URL"
echo "- Admin user: $WP_ADMIN_USER"
echo "- Admin password: $WP_ADMIN_PASSWORD"
echo ""
echo "To inspect plugin logs in WooCommerce: WooCommerce > Status > Logs > source=merchandillo-woocommerce-bridge"
