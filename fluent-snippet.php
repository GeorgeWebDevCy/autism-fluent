/**
 * Plugin Name: GN Forminator → WooCommerce Subscriptions Bridge (Debug)
 * Description: Forminator form (ID 3406) collects onboarding questions and saves answers to WooCommerce Order + Subscription. Includes robust AJAX-safe debugging.
 * Version: 1.0.1
 * Author: GN
 */

if (!defined('ABSPATH')) exit;

define('GN_FM_FORM_ID', 3403);

// Turn this to false when done debugging
if (!defined('GN_FM_DEBUG')) define('GN_FM_DEBUG', true);

/**
 * ------------------------------------------------------------
 * Logging helper
 * ------------------------------------------------------------
 */
function gn_fm_log($msg, $data = null) {
  if (!GN_FM_DEBUG) return;

  if ($data !== null) {
    error_log('[GN_FM] ' . $msg . ' ' . print_r($data, true));
  } else {
    error_log('[GN_FM] ' . $msg);
  }
}

/**
 * ------------------------------------------------------------
 * Sanitizers / value helpers
 * ------------------------------------------------------------
 */
function gn_fm_clean_value($val) {
  if (is_array($val) || is_object($val)) {
    return wp_json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  return sanitize_text_field((string) $val);
}

/**
 * Forminator $field_data formats vary by version.
 * This tries to locate a field by element_id like "name-1" and return a readable value.
 */
function gn_fm_get_field_value($field_data, $element_id) {
  if (empty($field_data) || !is_array($field_data)) return '';

  foreach ($field_data as $row) {
    if (!is_array($row)) continue;

    $id = $row['name'] ?? $row['element_id'] ?? $row['field_name'] ?? $row['slug'] ?? '';
    if ($id !== $element_id) continue;

    $value = $row['value'] ?? $row['field_value'] ?? $row['submitted_value'] ?? '';

    // Some fields return arrays (e.g., address)
    if (is_array($value)) {
      $parts = [];
      foreach ($value as $v) {
        if (is_string($v) && trim($v) !== '') $parts[] = trim($v);
      }
      return implode(', ', $parts);
    }

    return (string) $value;
  }

  return '';
}

/**
 * ------------------------------------------------------------
 * 1) Capture Forminator submission safely (AJAX-friendly)
 * ------------------------------------------------------------
 *
 * We:
 * - Save payload to WC session (if available)
 * - Save payload to transient as a reliable fallback (2 hours)
 * - Avoid setcookie() during AJAX (cookies can break JSON responses if headers sent)
 *
 * Hook arg signatures vary across Forminator versions, so we accept ...$args and infer.
 */
function gn_fm_store_submission_compat(...$args) {

  $entry_id   = 0;
  $form_id    = 0;
  $field_data = [];

  // Infer args
  foreach ($args as $a) {
    if (is_numeric($a) && !$entry_id) {
      $entry_id = (int) $a;
      continue;
    }
    if (is_numeric($a) && $entry_id && !$form_id) {
      $form_id = (int) $a;
      continue;
    }
    if (is_array($a)) {
      $field_data = $a;
    }
  }

  gn_fm_log('Forminator hook fired', [
    'entry_id'    => $entry_id,
    'form_id'     => $form_id,
    'doing_ajax'  => function_exists('wp_doing_ajax') ? wp_doing_ajax() : null,
  ]);

  if ((int)$form_id !== (int) GN_FM_FORM_ID) return;
  if (!$entry_id) return;

  // Build payload (field IDs from your export)
  $payload = [
    'form_id'      => (int) $form_id,
    'entry_id'     => (int) $entry_id,

    'first_name'   => gn_fm_clean_value(gn_fm_get_field_value($field_data, 'name-1')),
    'last_name'    => gn_fm_clean_value(gn_fm_get_field_value($field_data, 'name-2')),
    'dob'          => gn_fm_clean_value(gn_fm_get_field_value($field_data, 'date-1')),
    'birth_place'  => gn_fm_clean_value(gn_fm_get_field_value($field_data, 'text-1')),
    'profession'   => gn_fm_clean_value(gn_fm_get_field_value($field_data, 'text-2')),
    'address'      => gn_fm_clean_value(gn_fm_get_field_value($field_data, 'address-1')),
    'email'        => gn_fm_clean_value(gn_fm_get_field_value($field_data, 'email-1')),
    'phone'        => gn_fm_clean_value(gn_fm_get_field_value($field_data, 'phone-1')),
    'radio'        => gn_fm_clean_value(gn_fm_get_field_value($field_data, 'radio-1')),
    'signature'    => gn_fm_clean_value(gn_fm_get_field_value($field_data, 'text-3')),
    'consent'      => gn_fm_clean_value(gn_fm_get_field_value($field_data, 'consent-1')),
  ];

  // Save transient fallback (always)
  set_transient('gn_fm_payload_' . $entry_id, $payload, 2 * HOUR_IN_SECONDS);
  gn_fm_log('Saved payload to transient', 'gn_fm_payload_' . $entry_id);

  // Save into WC session if ready
  if (function_exists('WC') && WC()->session) {
    WC()->session->set('gn_fm_payload', $payload);
    gn_fm_log('Saved payload to WC session');
  } else {
    gn_fm_log('WC session NOT available at submit time');
  }

  // Avoid cookies during AJAX submit
  if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
    gn_fm_log('Skipping cookies (AJAX submit)');
    return;
  }

  // If not AJAX, cookies are ok (when headers not sent)
  if (!headers_sent()) {
    setcookie('gn_fm_entry_id', (string) $entry_id, time() + 2 * HOUR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
    setcookie('gn_fm_form_id',  (string) $form_id,  time() + 2 * HOUR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
    gn_fm_log('Cookies set (non-AJAX)');
  } else {
    gn_fm_log('Headers already sent -> cookies not set');
  }
}

// Register hooks (both, for compatibility)
add_action('forminator_custom_form_after_save_entry', 'gn_fm_store_submission_compat', 10, 99);
add_action('forminator_form_after_save_entry',        'gn_fm_store_submission_compat', 10, 99);

/**
 * ------------------------------------------------------------
 * 2) Restore payload into WC session if we only have cookies+transient
 * ------------------------------------------------------------
 */
add_action('wp_loaded', function () {
  if (!function_exists('WC') || !WC()->session) return;

  $payload = WC()->session->get('gn_fm_payload');
  if (!empty($payload)) return;

  $entry_id = isset($_COOKIE['gn_fm_entry_id']) ? (int) $_COOKIE['gn_fm_entry_id'] : 0;
  $form_id  = isset($_COOKIE['gn_fm_form_id'])  ? (int) $_COOKIE['gn_fm_form_id']  : 0;

  if ($entry_id && (int)$form_id === (int) GN_FM_FORM_ID) {
    $t = get_transient('gn_fm_payload_' . $entry_id);
    if (is_array($t) && !empty($t['entry_id'])) {
      WC()->session->set('gn_fm_payload', $t);
      gn_fm_log('Restored payload from transient (via cookies)', $t);
    } else {
      gn_fm_log('No transient found for entry_id', $entry_id);
    }
  }
}, 20);

/**
 * ------------------------------------------------------------
 * 3) (Recommended) Guard checkout: if cart has subscription but no form payload, block it
 * ------------------------------------------------------------
 */
add_action('woocommerce_checkout_process', function () {
  if (!function_exists('WC') || !WC()->cart) return;

  $has_subscription = function_exists('wcs_cart_has_subscription') ? wcs_cart_has_subscription() : false;
  if (!$has_subscription) return;

  $payload = (function_exists('WC') && WC()->session) ? WC()->session->get('gn_fm_payload') : [];
  $entry_id = isset($payload['entry_id']) ? (int) $payload['entry_id'] : 0;

  if (!$entry_id) {
    wc_add_notice(__('Please complete the membership form before checking out.', 'gn'), 'error');
    gn_fm_log('Checkout blocked: missing payload');
  }
});

/**
 * ------------------------------------------------------------
 * 4) Save to ORDER meta on checkout
 * ------------------------------------------------------------
 */
add_action('woocommerce_checkout_create_order', function ($order, $data) {
  if (!function_exists('WC') || !WC()->session) return;

  $payload = WC()->session->get('gn_fm_payload');

  gn_fm_log('checkout_create_order payload', $payload);

  if (empty($payload) || empty($payload['entry_id']) || (int)($payload['form_id'] ?? 0) !== (int) GN_FM_FORM_ID) {
    return;
  }

  // Store identifiers
  $order->update_meta_data('_gn_forminator_form_id', (int) $payload['form_id']);
  $order->update_meta_data('_gn_forminator_entry_id', (int) $payload['entry_id']);

  // Store mapped answers as order meta
  $map = [
    '_gn_member_first_name'  => 'first_name',
    '_gn_member_last_name'   => 'last_name',
    '_gn_member_dob'         => 'dob',
    '_gn_member_birth_place' => 'birth_place',
    '_gn_member_profession'  => 'profession',
    '_gn_member_address'     => 'address',
    '_gn_member_email'       => 'email',
    '_gn_member_phone'       => 'phone',
    '_gn_member_radio'       => 'radio',
    '_gn_member_signature'   => 'signature',
    '_gn_member_consent'     => 'consent',
  ];

  foreach ($map as $meta_key => $payload_key) {
    if (isset($payload[$payload_key]) && $payload[$payload_key] !== '') {
      $order->update_meta_data($meta_key, $payload[$payload_key]);
    }
  }

}, 20, 2);

/**
 * ------------------------------------------------------------
 * 5) Copy to SUBSCRIPTION meta when subscription is created
 * ------------------------------------------------------------
 */
add_action('woocommerce_checkout_subscription_created', function ($subscription, $order, $recurring_cart) {

  $form_id  = (int) $order->get_meta('_gn_forminator_form_id');
  $entry_id = (int) $order->get_meta('_gn_forminator_entry_id');

  gn_fm_log('subscription_created', ['form_id' => $form_id, 'entry_id' => $entry_id]);

  if ($form_id !== (int) GN_FM_FORM_ID || !$entry_id) return;

  // Copy identifiers
  $subscription->update_meta_data('_gn_forminator_form_id', $form_id);
  $subscription->update_meta_data('_gn_forminator_entry_id', $entry_id);

  // Copy mapped answers from order → subscription
  $keys = [
    '_gn_member_first_name',
    '_gn_member_last_name',
    '_gn_member_dob',
    '_gn_member_birth_place',
    '_gn_member_profession',
    '_gn_member_address',
    '_gn_member_email',
    '_gn_member_phone',
    '_gn_member_radio',
    '_gn_member_signature',
    '_gn_member_consent',
  ];

  foreach ($keys as $k) {
    $v = $order->get_meta($k);
    if ($v !== '') $subscription->update_meta_data($k, $v);
  }

  $subscription->save();

}, 20, 3);

/**
 * ------------------------------------------------------------
 * 6) Show details in admin (Order screen)
 * ------------------------------------------------------------
 */
add_action('woocommerce_admin_order_data_after_billing_address', function ($order) {

  $entry_id = $order->get_meta('_gn_forminator_entry_id');
  $form_id  = $order->get_meta('_gn_forminator_form_id');

  if (!$entry_id || (int)$form_id !== (int) GN_FM_FORM_ID) return;

  $lines = [
    'Όνομα'           => $order->get_meta('_gn_member_first_name'),
    'Επίθετο'         => $order->get_meta('_gn_member_last_name'),
    'Ημ. Γέννησης'    => $order->get_meta('_gn_member_dob'),
    'Τόπος Γέννησης'  => $order->get_meta('_gn_member_birth_place'),
    'Επάγγελμα'       => $order->get_meta('_gn_member_profession'),
    'Διεύθυνση'       => $order->get_meta('_gn_member_address'),
    'Email'           => $order->get_meta('_gn_member_email'),
    'Τηλέφωνο'        => $order->get_meta('_gn_member_phone'),
    'Σημείωση'        => $order->get_meta('_gn_member_radio'),
    'Υπογραφή'        => $order->get_meta('_gn_member_signature'),
    'Consent'         => $order->get_meta('_gn_member_consent'),
  ];

  echo '<div style="margin-top:12px;padding-top:12px;border-top:1px solid #e5e5e5">';
  echo '<p><strong>Forminator (Μέλη) Submission</strong><br>';
  echo 'Form ID: ' . esc_html($form_id) . ' — Entry ID: ' . esc_html($entry_id) . '</p>';

  echo '<p style="margin:0">';
  foreach ($lines as $label => $val) {
    if ($val === '') continue;
    echo '<strong>' . esc_html($label) . ':</strong> ' . esc_html($val) . '<br>';
  }
  echo '</p>';
  echo '</div>';

});

/**
 * ------------------------------------------------------------
 * 7) Cleanup after successful checkout
 * ------------------------------------------------------------
 */
add_action('woocommerce_thankyou', function ($order_id) {
  if (!function_exists('WC') || !WC()->session) return;

  $payload = WC()->session->get('gn_fm_payload');
  WC()->session->__unset('gn_fm_payload');

  // Expire cookies (if set)
  setcookie('gn_fm_entry_id', '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN);
  setcookie('gn_fm_form_id',  '', time() - 3600, COOKIEPATH ?: '/', COOKIE_DOMAIN);

  // Optionally delete transient too
  if (is_array($payload) && !empty($payload['entry_id'])) {
    delete_transient('gn_fm_payload_' . (int)$payload['entry_id']);
  }

  gn_fm_log('Cleanup completed on thankyou', ['order_id' => $order_id]);
}, 20);

/**
 * ------------------------------------------------------------
 * 8) Optional: Add a tiny debug marker to the frontend console
 * ------------------------------------------------------------
 */
add_action('wp_footer', function () {
  if (!GN_FM_DEBUG) return;
  echo "<script>console.log('GN_FM debug plugin active (form " . (int)GN_FM_FORM_ID . ").');</script>";
}, 999);
