#!/usr/bin/env bash
# Seed WooCommerce products + calculator shortcode pages for Playwright E2E.
# Run from the repo root so wp-env resolves THIS repo's environment; its
# bare `npx wp-env` calls hit whatever environment matches the cwd.
set -euo pipefail

cli() {
  npx wp-env run cli "$@"
}

create_product() {
  local title="$1"
  local slug="$2"
  local option_key="$3"
  local product_id
  product_id=$(cli wp post create \
    --post_type=product \
    --post_status=publish \
    --post_title="$title" \
    --post_name="$slug" \
    --porcelain 2>/dev/null | tr -cd '0-9')
  if [ -z "$product_id" ]; then
    echo "ERROR: could not create product ${slug}" >&2
    exit 1
  fi
  echo "Created product ${slug} #${product_id}"
  # A priceless product is not purchasable; the calculator sets the real price.
  cli wp post meta update "$product_id" _price 0
  cli wp post meta update "$product_id" _regular_price 0
  cli wp option update "$option_key" "${product_id}"
}

create_page() {
  local title="$1"
  local slug="$2"
  local content="$3"
  cli wp post create \
    --post_type=page \
    --post_status=publish \
    --post_title="$title" \
    --post_name="$slug" \
    --post_content="$content"
}

cli wp rewrite structure '/%postname%/' --hard
cli wp option update woocommerce_coming_soon no
cli wp wc tool run install_pages --user=admin

create_product 'Archival Print' 'archival-print' 'fac_woocommerce_product_id'
create_page 'Fine Art Calculator' 'fine-art-calculator' '[fine_art_calculator_embed]'

create_product 'Inkjet Print' 'inkjet-print' 'fac_inkjet_woocommerce_product_id'
create_page 'Inkjet Calculator' 'inkjet-calculator' '[inkjet_calculator_embed]'

echo "E2E seed complete."
