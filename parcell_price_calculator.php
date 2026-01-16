<?php
/**
 * WooCommerce "Parcel booking" fields + dynamic tier pricing (HU -> UK)
 * - Category, Description, L/W/H (cm), Weight (kg), Quantity (db), Fragile
 * - AJAX price calculation: smallest tier where BOTH weight<=maxW AND volume<=maxV
 * - Cart line price = tier_price * units
 * - Shows meta in cart/checkout and saves into order items
 *
 * WPCode:
 * - PHP Snippet
 * - Location: Run Everywhere
 * - No closing PHP tag
 */

if (!defined('ABSPATH')) exit;

/** Change this to your product ID */
function wc_parcel_target_product_id(): int {
  return 2898;
}

/** Pricing table: [max_weight_kg, max_volume_m3, base_price] */

function wc_parcel_pricing_table(): array {
  return [
    [ 5.0,  0.03, 31.00],
    [10.0,  0.04, 34.00],
    [15.0,  0.07, 38.00],
    [20.0,  0.10, 43.00],
    [25.0,  0.13, 53.00],
    [30.0,  0.15, 59.00],
    [35.0,  0.20, 67.00],
  ];
}

/** cm -> m^3 */
function wc_parcel_volume_m3(float $l_cm, float $w_cm, float $h_cm): float {
  return max(0.0, ($l_cm * $w_cm * $h_cm) / 1000000.0);
}

/**
 * Smallest tier where BOTH constraints fit:
 * weight <= maxW AND volume <= maxV
 * If none matches => null
 */
function wc_parcel_price_for(float $weight_kg, float $vol_m3): ?float {
  foreach (wc_parcel_pricing_table() as $row) {
    [$maxW, $maxV, $price] = $row;
    if ($weight_kg <= $maxW && $vol_m3 <= $maxV) return (float)$price;
  }
  return null;
}

/** Final unit price = tier base price only (no volume surcharge) */
function wc_parcel_final_unit_price(float $weight_kg, float $vol_m3): ?float {
  return wc_parcel_price_for($weight_kg, $vol_m3);
}

/** Sanitize numeric input (accepts comma decimals too) */
function wc_parcel_float($v): float {
  $v = is_string($v) ? str_replace(',', '.', $v) : $v;
  return (float)preg_replace('/[^0-9\.\-]/', '', (string)$v);
}

/** Check target product page reliably */
function wc_parcel_is_target_product_page(): bool {
  if (!is_product()) return false;
  $id = (int) get_queried_object_id();
  if ($id <= 0) $id = (int) get_the_ID();
  return $id === (int) wc_parcel_target_product_id();
}

const WC_PARCEL_AJAX_ACTION = 'wc_parcel_calc_price';

/** Provide meta nonce for the JS snippet */
add_action('wp_head', function () {
  if (!wc_parcel_is_target_product_page()) return;
  echo '<meta name="wc-parcel-product" content="1">';
  echo '<meta name="wc-parcel-nonce" content="' . esc_attr(wp_create_nonce('wc_parcel_ajax')) . '">';
});

/** Render fields on product page */
add_action('woocommerce_before_add_to_cart_button', function () {
  if (!wc_parcel_is_target_product_page()) return;

  wp_nonce_field('wc_parcel_fields', 'wc_parcel_nonce');

  // Categories (edit as you like)
  $categories = [
    ''            => 'Válassz kategóriát',
    'household'   => 'Háztartási eszköz',
    'clothing'    => 'Ruházat',
    'books'       => 'Könyvek',
    'electronics' => 'Elektronika',
    'gift'        => 'Ajándék',
    'other'       => 'Egyéb',
  ];

  echo '<div class="wc-parcel-box" style="margin:16px 0; padding:12px; border:1px solid #ddd; border-radius:8px;">';
  echo '<p style="margin:0 0 10px;"><strong>1. csomag</strong></p>';

  echo '<div class="wc-parcel-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">';

  // Category
  echo '<div>';
  echo '<label for="wc_parcel_category" style="display:block; font-weight:600; margin-bottom:4px;">Csomag kategória <span style="color:#d00;">*</span></label>';
  echo '<select id="wc_parcel_category" name="wc_parcel_category" required style="width:100%; max-width:100%; padding:8px;">';
  foreach ($categories as $k => $label) {
    echo '<option value="' . esc_attr($k) . '">' . esc_html($label) . '</option>';
  }
  echo '</select>';
  echo '</div>';

  // Description
  echo '<div>';
  echo '<label for="wc_parcel_description" style="display:block; font-weight:600; margin-bottom:4px;">Csomag leírása <span style="color:#d00;">*</span></label>';
  echo '<input type="text" id="wc_parcel_description" name="wc_parcel_description" placeholder="Pl. Háztartási eszközök, ruhák, könyvek..." required style="width:100%; max-width:100%; padding:8px;">';
  echo '</div>';

  // Length
  echo '<div>';
  echo '<label for="wc_parcel_length" style="display:block; font-weight:600; margin-bottom:4px;">Hosszúság (cm) <span style="color:#d00;">*</span></label>';
  echo '<input type="number" id="wc_parcel_length" name="wc_parcel_length" min="1" step="0.1" value="1" required style="width:100%; max-width:100%; padding:8px;">';
  echo '</div>';

  // Width
  echo '<div>';
  echo '<label for="wc_parcel_width" style="display:block; font-weight:600; margin-bottom:4px;">Szélesség (cm) <span style="color:#d00;">*</span></label>';
  echo '<input type="number" id="wc_parcel_width" name="wc_parcel_width" min="1" step="0.1" value="1" required style="width:100%; max-width:100%; padding:8px;">';
  echo '</div>';

  // Height
  echo '<div>';
  echo '<label for="wc_parcel_height" style="display:block; font-weight:600; margin-bottom:4px;">Magasság (cm) <span style="color:#d00;">*</span></label>';
  echo '<input type="number" id="wc_parcel_height" name="wc_parcel_height" min="1" step="0.1" value="1" required style="width:100%; max-width:100%; padding:8px;">';
  echo '</div>';

  // Weight
  echo '<div>';
  echo '<label for="wc_parcel_weight" style="display:block; font-weight:600; margin-bottom:4px;">Súly (kg) <span style="color:#d00;">*</span></label>';
  echo '<input type="number" id="wc_parcel_weight" name="wc_parcel_weight" min="0.1" step="0.1" value="1" required style="width:100%; max-width:100%; padding:8px;">';
  echo '</div>';

  // Units
  echo '<div>';
  echo '<label for="wc_parcel_qty" style="display:block; font-weight:600; margin-bottom:4px;">Mennyiség (db)</label>';
  echo '<input type="number" id="wc_parcel_qty" name="wc_parcel_qty" min="1" step="1" value="1" style="width:100%; max-width:100%; padding:8px;">';
  echo '</div>';

  // Fragile
  echo '<div style="display:flex; align-items:flex-end;">';
  echo '<label style="display:flex; gap:8px; align-items:center; font-weight:600;">';
  echo '<input type="checkbox" id="wc_parcel_fragile" name="wc_parcel_fragile" value="1"> Törékeny csomag';
  echo '</label>';
  echo '</div>';

  echo '</div>'; // grid

  // Live price output target for JS
  echo '<p style="margin:12px 0 0;">';
  echo '<span style="font-weight:600;">Calculated shipping price:</span> ';
  echo '<span id="wc_parcel_live_price">—</span>';
  echo '</p>';
  echo '<p id="wc_parcel_live_note" style="margin:6px 0 0; color:#666; font-size:0.95em;"></p>';

  echo '</div>'; // box
});

/** AJAX calc handler (used by your JS snippet) */
add_action('wp_ajax_' . WC_PARCEL_AJAX_ACTION, 'wc_parcel_ajax_calc');
add_action('wp_ajax_nopriv_' . WC_PARCEL_AJAX_ACTION, 'wc_parcel_ajax_calc');

function wc_parcel_ajax_calc() {
  // Nonce is optional (safe endpoint: returns only a tier price)
  if (isset($_POST['nonce']) && !wp_verify_nonce($_POST['nonce'], 'wc_parcel_ajax')) {
    wp_send_json_error(['message' => 'Security check failed.']);
  }

  // Single, consistent parameter names
  $l  = wc_parcel_float($_POST['wc_parcel_length'] ?? 0);
  $w  = wc_parcel_float($_POST['wc_parcel_width']  ?? 0);
  $h  = wc_parcel_float($_POST['wc_parcel_height'] ?? 0);
  $wt = wc_parcel_float($_POST['wc_parcel_weight'] ?? 0);

  if ($l <= 0 || $w <= 0 || $h <= 0 || $wt <= 0) {
    wp_send_json_error(['message' => 'Please enter valid dimensions and weight.']);
  }

  $vol   = wc_parcel_volume_m3($l, $w, $h);
  $price = wc_parcel_final_unit_price($wt, $vol);

  if ($price === null) {
    wp_send_json_error(['message' => 'This parcel is outside the allowed size/weight limits.']);
  }

  $formatted = html_entity_decode(wc_price($price), ENT_QUOTES, 'UTF-8');

  wp_send_json_success([
    'price' => $price,
    'formatted_price' => $formatted,
    'volume_m3' => $vol,
  ]);
}

/** Validate fields on add-to-cart */
add_filter('woocommerce_add_to_cart_validation', function ($passed, $product_id) {
  if ((int)$product_id !== wc_parcel_target_product_id()) return $passed;

  if (!isset($_POST['wc_parcel_nonce']) || !wp_verify_nonce($_POST['wc_parcel_nonce'], 'wc_parcel_fields')) {
    wc_add_notice('Please refresh the page and try again.', 'error');
    return false;
  }

  $cat  = sanitize_text_field($_POST['wc_parcel_category'] ?? '');
  $desc = sanitize_text_field($_POST['wc_parcel_description'] ?? '');

  if ($cat === '') {
    wc_add_notice('Válassz csomag kategóriát.', 'error');
    return false;
  }

  if ($desc === '') {
    wc_add_notice('Írd be a csomag leírását.', 'error');
    return false;
  }

  $l  = wc_parcel_float($_POST['wc_parcel_length'] ?? 0);
  $w  = wc_parcel_float($_POST['wc_parcel_width'] ?? 0);
  $h  = wc_parcel_float($_POST['wc_parcel_height'] ?? 0);
  $wt = wc_parcel_float($_POST['wc_parcel_weight'] ?? 0);

  if ($l <= 0 || $w <= 0 || $h <= 0 || $wt <= 0) {
    wc_add_notice('Please enter valid parcel dimensions and weight.', 'error');
    return false;
  }

  $vol   = wc_parcel_volume_m3($l, $w, $h);
  $price = wc_parcel_final_unit_price($wt, $vol);

  if ($price === null) {
    wc_add_notice('Ez a csomag méret/súly alapján nem feladható ebben a rendszerben.', 'error');
    return false;
  }

  return $passed;
}, 10, 2);

/** Store parcel data + force unique line items */
add_filter('woocommerce_add_cart_item_data', function ($cart_item_data, $product_id) {
  if ((int)$product_id !== wc_parcel_target_product_id()) return $cart_item_data;

  $l  = wc_parcel_float($_POST['wc_parcel_length'] ?? 0);
  $w  = wc_parcel_float($_POST['wc_parcel_width'] ?? 0);
  $h  = wc_parcel_float($_POST['wc_parcel_height'] ?? 0);
  $wt = wc_parcel_float($_POST['wc_parcel_weight'] ?? 0);

  $vol   = wc_parcel_volume_m3($l, $w, $h);
  $price = wc_parcel_final_unit_price($wt, $vol);

  $cat     = sanitize_text_field($_POST['wc_parcel_category'] ?? '');
  $desc    = sanitize_text_field($_POST['wc_parcel_description'] ?? '');
  $units   = max(1, (int)($_POST['wc_parcel_qty'] ?? 1));
  $fragile = !empty($_POST['wc_parcel_fragile']) ? 1 : 0;

  $cart_item_data['wc_parcel'] = [
    'category'    => $cat,
    'description' => $desc,
    'fragile'     => $fragile,
    'units'       => $units,

    'length_cm'   => $l,
    'width_cm'    => $w,
    'height_cm'   => $h,
    'weight_kg'   => $wt,
    'volume_m3'   => $vol,
    'price'       => $price,
  ];

  // Always unique cart row (even if identical)
  $cart_item_data['wc_parcel_unique_key'] = md5(microtime(true) . wp_rand());

  return $cart_item_data;
}, 10, 2);

/** Show parcel details in cart/checkout */
add_filter('woocommerce_get_item_data', function ($item_data, $cart_item) {
  if (empty($cart_item['wc_parcel'])) return $item_data;

  $p = $cart_item['wc_parcel'];

  if (!empty($p['category'])) {
    $item_data[] = ['name' => 'Kategória', 'value' => wc_clean($p['category'])];
  }
  if (!empty($p['description'])) {
    $item_data[] = ['name' => 'Leírás', 'value' => wc_clean($p['description'])];
  }

  $item_data[] = ['name' => 'Mennyiség (db)', 'value' => wc_clean((string)($p['units'] ?? 1))];
  $item_data[] = ['name' => 'Törékeny', 'value' => !empty($p['fragile']) ? 'Igen' : 'Nem'];

  $item_data[] = ['name' => 'Méretek (cm)', 'value' => wc_clean($p['length_cm'] . ' × ' . $p['width_cm'] . ' × ' . $p['height_cm'])];
  $item_data[] = ['name' => 'Súly (kg)', 'value' => wc_clean($p['weight_kg'])];
  $item_data[] = ['name' => 'Térfogat (m³)', 'value' => wc_clean(number_format((float)$p['volume_m3'], 3))];

  return $item_data;
}, 10, 2);

/** Set cart item price = tier_price * units */
add_action('woocommerce_before_calculate_totals', function ($cart) {
  if (is_admin() && !defined('DOING_AJAX')) return;
  if (!$cart || $cart->is_empty()) return;

  foreach ($cart->get_cart() as $cart_item) {
    if (empty($cart_item['wc_parcel']['price'])) continue;

    $price = (float)$cart_item['wc_parcel']['price'];
    $units = !empty($cart_item['wc_parcel']['units']) ? (int)$cart_item['wc_parcel']['units'] : 1;

    if ($price > 0 && isset($cart_item['data']) && is_object($cart_item['data'])) {
      $cart_item['data']->set_price($price * max(1, $units));
    }
  }
}, 20);

/** Save parcel meta into the order line item */
add_action('woocommerce_checkout_create_order_line_item', function ($item, $cart_item_key, $values) {
  if (empty($values['wc_parcel'])) return;

  $p = $values['wc_parcel'];

  $item->add_meta_data('Kategória', $p['category'] ?? '', true);
  $item->add_meta_data('Leírás', $p['description'] ?? '', true);
  $item->add_meta_data('Mennyiség (db)', (string)($p['units'] ?? 1), true);
  $item->add_meta_data('Törékeny', !empty($p['fragile']) ? 'Igen' : 'Nem', true);

  $item->add_meta_data('Hosszúság (cm)', $p['length_cm'] ?? '', true);
  $item->add_meta_data('Szélesség (cm)',  $p['width_cm'] ?? '', true);
  $item->add_meta_data('Magasság (cm)', $p['height_cm'] ?? '', true);
  $item->add_meta_data('Súly (kg)', $p['weight_kg'] ?? '', true);
  $item->add_meta_data('Térfogat (m³)', number_format((float)($p['volume_m3'] ?? 0), 3), true);
  $item->add_meta_data('Díj (tier)', number_format((float)($p['price'] ?? 0), 2), true);
}, 10, 3);
