<?php
/**
 * Plugin Name: Veedy Setup Runner
 * Description: Temporary setup automation for Veedy Store. Triggered via secret URL. Remove after setup (step=cleanup self-destructs).
 * Version: 1.0.0
 * Author: Veedy
 */

if (!defined('ABSPATH')) { exit; }

define('VEEDY_SETUP_SECRET', 'x7k2m9s3cr3t');

add_action('init', function () {
    if (!isset($_GET['veedy_setup']) || $_GET['veedy_setup'] !== VEEDY_SETUP_SECRET) { return; }
    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    @set_time_limit(300);
    ignore_user_abort(true);
    $step = isset($_GET['step']) ? preg_replace('/[^a-z_]/', '', $_GET['step']) : 'status';
    echo "VEEDY STEP: $step\n\n";
    try {
        $fn = 'veedy_step_' . $step;
        if (function_exists($fn)) { $fn(); } else { echo "unknown step: $step\n"; }
        echo "\nVEEDY_STEP_DONE\n";
    } catch (Throwable $e) {
        echo "\nVEEDY_ERROR: " . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
    }
    exit;
}, 1);

function veedy_out($msg) { echo $msg . "\n"; }

function veedy_step_status() {
    global $wp_version;
    veedy_out('wordpress_version: ' . $wp_version);
    veedy_out('php_version: ' . PHP_VERSION);
    $theme = wp_get_theme();
    veedy_out('active_theme: ' . $theme->get_stylesheet() . ' (' . $theme->get('Name') . ' ' . $theme->get('Version') . ')');
    veedy_out('woocommerce_active: ' . (class_exists('WooCommerce') ? 'yes ' . (defined('WC_VERSION') ? WC_VERSION : '?') : 'NO'));
    veedy_out('site_title: ' . get_option('blogname'));
    veedy_out('tagline: ' . get_option('blogdescription'));
    veedy_out('timezone: ' . get_option('timezone_string'));
    veedy_out('permalink: ' . get_option('permalink_structure'));
    veedy_out('blog_public: ' . get_option('blog_public'));
    if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
    foreach (get_plugins() as $file => $data) {
        veedy_out('plugin: ' . $file . ' | ' . $data['Name'] . ' ' . $data['Version'] . ' | ' . (is_plugin_active($file) ? 'active' : 'inactive'));
    }
}

function veedy_step_settings() {
    update_option('blogname', 'Veedy Store');
    update_option('blogdescription', 'Jasa Titip & Assisted Purchase Service');
    update_option('timezone_string', 'Asia/Jakarta');
    update_option('blog_public', 0);
    veedy_out('title/tagline/timezone/visibility set');

    global $wp_rewrite;
    $wp_rewrite->set_permalink_structure('/%postname%/');
    flush_rewrite_rules(false);
    veedy_out('permalink structure: /%postname%/ (flushed)');

    $p = get_page_by_path('hello-world', OBJECT, 'post');
    if ($p) { wp_delete_post($p->ID, true); veedy_out("deleted hello-world (ID {$p->ID})"); } else { veedy_out('hello-world not found'); }
    $s = get_page_by_path('sample-page', OBJECT, 'page');
    if ($s) { wp_delete_post($s->ID, true); veedy_out("deleted sample-page (ID {$s->ID})"); } else { veedy_out('sample-page not found'); }
}

function veedy_install_theme_from_org($slug) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/theme.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    add_filter('filesystem_method', function () { return 'direct'; });
    $api = themes_api('theme_information', array('slug' => $slug, 'fields' => array('sections' => false)));
    if (is_wp_error($api)) { veedy_out("themes_api error for $slug: " . $api->get_error_message()); return false; }
    $skin = new WP_Ajax_Upgrader_Skin();
    $upgrader = new Theme_Upgrader($skin);
    $result = $upgrader->install($api->download_link);
    if (is_wp_error($result)) { veedy_out("install error for $slug: " . $result->get_error_message()); return false; }
    if (!$result) { veedy_out("install failed for $slug: " . implode(' | ', $skin->get_error_messages())); return false; }
    veedy_out("theme installed: $slug");
    return true;
}

function veedy_step_theme() {
    $target = 'blocksy';
    if (!wp_get_theme($target)->exists()) {
        if (!veedy_install_theme_from_org($target)) {
            $target = 'kadence';
            if (!wp_get_theme($target)->exists() && !veedy_install_theme_from_org($target)) {
                veedy_out('ERROR: could not install blocksy or kadence');
                return;
            }
        }
    } else {
        veedy_out("$target already installed");
    }
    if (get_stylesheet() !== $target) {
        switch_theme($target);
        veedy_out("theme activated: $target");
    } else {
        veedy_out("theme already active: $target");
    }
}

function veedy_step_woo() {
    if (!class_exists('WooCommerce')) {
        if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        $installed = array_keys(get_plugins());
        $woo = null;
        foreach ($installed as $f) { if (strpos($f, 'woocommerce/') === 0) { $woo = $f; } }
        if ($woo) {
            activate_plugin($woo);
            veedy_out('woocommerce activated (was inactive) - RE-RUN this step to apply settings');
            return;
        }
        veedy_out('ERROR: WooCommerce not installed - install it first');
        return;
    }
    $opts = array(
        'woocommerce_store_city' => 'Jakarta',
        'woocommerce_default_country' => 'ID:JK',
        'woocommerce_currency' => 'IDR',
        'woocommerce_currency_pos' => 'left',
        'woocommerce_price_thousand_sep' => '.',
        'woocommerce_price_decimal_sep' => ',',
        'woocommerce_price_num_decimals' => '0',
        'woocommerce_allowed_countries' => 'specific',
        'woocommerce_ship_to_countries' => 'specific',
        'woocommerce_enable_guest_checkout' => 'yes',
        'woocommerce_enable_signup_and_login_from_checkout' => 'yes',
        'woocommerce_enable_myaccount_registration' => 'yes',
        'woocommerce_calc_taxes' => 'no',
        'woocommerce_coming_soon' => 'no',
    );
    foreach ($opts as $k => $v) { update_option($k, $v); }
    update_option('woocommerce_specific_allowed_countries', array('ID'));
    update_option('woocommerce_specific_ship_to_countries', array('ID'));
    $profile = get_option('woocommerce_onboarding_profile', array());
    if (!is_array($profile)) { $profile = array(); }
    $profile['skipped'] = true;
    $profile['product_types'] = array('physical');
    update_option('woocommerce_onboarding_profile', $profile);
    veedy_out('woocommerce core options set (ID/IDR/guest checkout/no taxes/coming soon off)');
}

function veedy_step_pay() {
    update_option('woocommerce_bacs_settings', array(
        'enabled' => 'yes',
        'title' => 'Direct Bank Transfer',
        'description' => 'Pay via manual bank transfer. Your order will be processed after payment is verified.',
        'instructions' => 'Please transfer to the bank account listed below. Your order will be processed after payment is verified. Include your order number in the transfer note.',
    ));
    update_option('woocommerce_bacs_accounts', array(array(
        'account_name' => '[ACCOUNT_NAME]',
        'account_number' => '[ACCOUNT_NUMBER]',
        'bank_name' => '[BANK_NAME]',
        'sort_code' => '',
        'iban' => '',
        'bic' => '',
    )));
    veedy_out('BACS enabled with placeholder account');
    foreach (array('cod', 'cheque', 'paypal') as $g) {
        $k = "woocommerce_{$g}_settings";
        $s = get_option($k, array());
        if (!is_array($s)) { $s = array(); }
        $s['enabled'] = 'no';
        update_option($k, $s);
        veedy_out("gateway disabled: $g");
    }
    if (class_exists('WC_Shipping_Zones')) {
        $exists = false;
        foreach (WC_Shipping_Zones::get_zones() as $z) {
            if ($z['zone_name'] === 'Indonesia') { $exists = true; }
        }
        if (!$exists) {
            $zone = new WC_Shipping_Zone();
            $zone->set_zone_name('Indonesia');
            $zone->save();
            $zone->add_location('ID', 'country');
            $zone->save();
            $iid = $zone->add_shipping_method('flat_rate');
            update_option('woocommerce_flat_rate_' . $iid . '_settings', array(
                'title' => 'Standard Shipping (rate finalized per order)',
                'tax_status' => 'none',
                'cost' => '0',
            ));
            veedy_out("shipping zone Indonesia created (flat_rate instance $iid, cost 0 placeholder)");
        } else {
            veedy_out('shipping zone Indonesia already exists');
        }
    } else {
        veedy_out('ERROR: WC_Shipping_Zones class missing');
    }
}

function veedy_upsert_page($slug, $title, $content) {
    $page = get_page_by_path($slug, OBJECT, 'page');
    if ($page) {
        wp_update_post(array('ID' => $page->ID, 'post_title' => $title, 'post_content' => $content, 'post_status' => 'publish'));
        veedy_out("updated page $slug (ID {$page->ID})");
        return $page->ID;
    }
    $id = wp_insert_post(array('post_type' => 'page', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content, 'post_status' => 'publish'));
    veedy_out("created page $slug (ID $id)");
    return $id;
}

function veedy_step_pages() {
    $home = <<<'EOT'
<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Buy Overseas Products with a Cleaner Jasa Titip Process</h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Browse curated overseas products, place your order, pay through a clear checkout process, and track your order from supplier purchase to local delivery in Indonesia.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/shop/">Shop Now</a></div>
<!-- /wp:button --><!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/request-product/">Request Product</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->

<!-- wp:heading -->
<h2 class="wp-block-heading">How It Works</h2>
<!-- /wp:heading -->

<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list"><!-- wp:list-item -->
<li>Choose a product from the shop or send us a request</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Place your order and complete payment by bank transfer</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>We purchase from the overseas supplier and inspect the item</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>We ship the item to your address in Indonesia</li>
<!-- /wp:list-item --></ol>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p><a href="/how-it-works/">See the full process</a></p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Featured Products</h2>
<!-- /wp:heading -->

<!-- wp:shortcode -->
[products limit="4" columns="4" orderby="date" order="DESC"]
<!-- /wp:shortcode -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Why Order With Us</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>A clear checkout process instead of chat-based ordering</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Status updates from supplier purchase to local delivery</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Items are inspected before we ship them to you</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Written terms for cancellation, refunds, and warranty</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Testimonials</h2>
<!-- /wp:heading -->

<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p>Ordering was straightforward and I could follow every step until the package arrived in Bandung. (Sample testimonial)</p>
<!-- /wp:paragraph --><cite>R., Bandung</cite></blockquote>
<!-- /wp:quote -->

<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p>Clear pricing and honest estimates. The item was checked before local shipping. (Sample testimonial)</p>
<!-- /wp:paragraph --><cite>D., Jakarta</cite></blockquote>
<!-- /wp:quote -->

<!-- wp:paragraph -->
<p><a href="/testimonials/">Read more testimonials</a></p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Frequently Asked Questions</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><strong>How long does delivery take?</strong><br>Most orders arrive in 2 to 8 weeks depending on the supplier and shipping route.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><strong>How do I pay?</strong><br>By manual bank transfer during checkout. More payment options are coming.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><strong>Can I request an item that is not in the shop?</strong><br>Yes. Use the Request Product page and we will check price and availability.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p><a href="/faq/">See all FAQs</a></p>
<!-- /wp:paragraph -->
EOT;

    $how = <<<'EOT'
<!-- wp:paragraph -->
<p>Veedy Store is a jasa titip / assisted purchase service. Here is exactly what happens after you order.</p>
<!-- /wp:paragraph -->

<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list"><!-- wp:list-item -->
<li><strong>Choose product</strong> - pick an item from the shop or send a request for something you want.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>Place order</strong> - add the item to your cart and check out. Guest checkout is available.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>Complete payment</strong> - transfer to our bank account and include your order number in the transfer note.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>We purchase from the overseas supplier</strong> - once payment is verified, we buy the item from the source store.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>Product ships to our warehouse or forwarder</strong> - the supplier sends the item to our receiving point.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>We process and inspect the item</strong> - we check the condition and repack it for the trip to Indonesia.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>We ship to your Indonesian address</strong> - the package is handed to a local courier with a tracking number.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>You receive the product</strong> - confirm receipt and contact us if anything is wrong.</li>
<!-- /wp:list-item --></ol>
<!-- /wp:list -->

<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p>We are a jasa titip / assisted purchase service. We are not the official distributor, official reseller, or official representative of any brand unless explicitly stated. Product availability, supplier price, and shipping time may change.</p>
<!-- /wp:paragraph --></blockquote>
<!-- /wp:quote -->
EOT;

    $request = <<<'EOT'
<!-- wp:paragraph -->
<p>Want something that is not listed in the shop? Send us a product request and we will check the supplier price, availability, and estimated arrival time before you commit.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">What to include in your request</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Product name and variant (color, size, model)</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Link to the product on the source store</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Quantity</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Your target budget (optional)</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2 class="wp-block-heading">How to send it</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>For now, send your request through the details on our <a href="/contact/">Contact page</a>. A request form will be added to this page soon. We usually reply within 1x24 hours on working days.</p>
<!-- /wp:paragraph -->
EOT;

    $testimonials = <<<'EOT'
<!-- wp:paragraph -->
<p>These are sample testimonials used while the store is under construction. They will be replaced with real customer reviews.</p>
<!-- /wp:paragraph -->

<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p>Ordering was straightforward and I could follow every step until the package arrived in Bandung. (Sample testimonial)</p>
<!-- /wp:paragraph --><cite>R., Bandung</cite></blockquote>
<!-- /wp:quote -->

<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p>Clear pricing and honest estimates. The item was checked before local shipping. (Sample testimonial)</p>
<!-- /wp:paragraph --><cite>D., Jakarta</cite></blockquote>
<!-- /wp:quote -->

<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p>My requested handheld arrived safely and matched the listing. Communication was quick. (Sample testimonial)</p>
<!-- /wp:paragraph --><cite>A., Surabaya</cite></blockquote>
<!-- /wp:quote -->
EOT;

    $faq = <<<'EOT'
<!-- wp:heading -->
<h2 class="wp-block-heading">What is jasa titip / assisted purchase?</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>We buy products from overseas stores on your behalf, receive and inspect them, then forward them to your address in Indonesia. We are not the official distributor or reseller of any brand unless explicitly stated.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">How long does delivery take?</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Most orders arrive within 2 to 8 weeks. The estimate depends on the supplier, the shipping route, and customs processing. Each product listing shows its own estimated arrival time.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">How do I pay?</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>By manual bank transfer during checkout. Include your order number in the transfer note so we can verify your payment quickly. More payment options are planned.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Can I cancel my order?</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>It depends on the supplier purchase status. If we have not bought the item yet, cancellation is usually possible. After the supplier purchase is made, cancellation follows the supplier's rules. See the <a href="/refund-policy/">Refund Policy</a>.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Is there a warranty?</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Products carry the supplier warranty only unless stated otherwise on the listing. Official local brand warranty usually does not apply to imported items.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Can I request a product that is not listed?</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Yes. Use the <a href="/request-product/">Request Product</a> page and we will check price and availability for you.</p>
<!-- /wp:paragraph -->
EOT;

    $track = <<<'EOT'
<!-- wp:paragraph -->
<p>Every order gets an order number when checkout is completed. You can use it to check your order status.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">How to check your order status</h2>
<!-- /wp:heading -->

<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list"><!-- wp:list-item -->
<li>Find your order number in the confirmation email.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Contact us through the <a href="/contact/">Contact page</a> with your order number.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>We will reply with the current stage: payment verification, supplier purchase, in transit to warehouse, inspection, or local delivery.</li>
<!-- /wp:list-item --></ol>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>A self-service tracking tool is planned for this page. Once local shipping starts, you will also receive a courier tracking number.</p>
<!-- /wp:paragraph -->
EOT;

    $terms = <<<'EOT'
<!-- wp:paragraph -->
<p>By placing an order at Veedy Store you agree to the terms below. These terms are a working draft while the store is under construction.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">1. Nature of the service</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Veedy Store is a jasa titip / assisted purchase service. We are not the official distributor, official reseller, or official representative of any brand unless explicitly stated. Product availability, supplier price, and shipping time may change.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">2. Orders and pricing</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Listed prices include the product cost and our service fee unless stated otherwise. International shipping, import duties, and local delivery may be charged separately and will be communicated before or during order processing.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">3. Payment</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Payment is made by bank transfer to the account shown at checkout. Orders are processed only after payment is verified. Orders unpaid after 2x24 hours may be cancelled.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">4. Estimated arrival</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Arrival estimates (typically 2 to 8 weeks) are estimates, not guarantees. Delays caused by suppliers, freight, or customs are outside our control, and we will keep you informed.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">5. Cancellation</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Cancellation depends on the supplier purchase status. Before the supplier purchase, orders can usually be cancelled with a full refund. After the supplier purchase, cancellation follows the supplier's policy and may not be possible.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">6. Warranty and condition</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Items carry the supplier warranty only unless stated otherwise. We inspect items before local shipping and document their condition.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">7. Liability</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Our liability is limited to the amount you paid for the affected order. We are not liable for indirect losses.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">8. Contact</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Questions about these terms can be sent through the <a href="/contact/">Contact page</a>.</p>
<!-- /wp:paragraph -->
EOT;

    $refund = <<<'EOT'
<!-- wp:paragraph -->
<p>This policy explains when refunds are possible for assisted purchase orders. It is a working draft while the store is under construction.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Before the supplier purchase</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>If we have not yet purchased your item from the overseas supplier, you can cancel and receive a full refund to your bank account.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">After the supplier purchase</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Once the supplier purchase is made, cancellation follows the supplier's rules. If the supplier accepts a return, we refund what we recover, minus non-refundable fees. If the supplier does not accept cancellation, the order continues as normal.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Damaged, wrong, or missing items</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Report problems within 2x24 hours after the package is received, with an unboxing video where possible. Verified claims are resolved by repair, replacement, or refund depending on the case.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">How refunds are paid</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Refunds are sent by bank transfer to the account used for payment, normally within 3 to 7 working days after approval.</p>
<!-- /wp:paragraph -->
EOT;

    $privacy = <<<'EOT'
<!-- wp:paragraph -->
<p>This policy describes how Veedy Store handles your personal data. It is a working draft while the store is under construction.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Data we collect</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>When you order, we collect your name, address, phone number, email, and order details. Payment is made by bank transfer; we do not store card data.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">How we use it</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>We use your data to process orders, arrange shipping, verify payments, respond to questions, and meet legal obligations. We share it only with parties needed to fulfil your order, such as couriers and forwarders.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Storage and security</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Order data is stored in our store system with access limited to store operators. We keep it as long as needed for records and legal requirements.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Your rights</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>You may ask us to show, correct, or delete your personal data through the <a href="/contact/">Contact page</a>, subject to record-keeping requirements.</p>
<!-- /wp:paragraph -->
EOT;

    $contact = <<<'EOT'
<!-- wp:paragraph -->
<p>Reach us through any channel below. We usually reply within 1x24 hours on working days.</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Email: [EMAIL_PLACEHOLDER]</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>WhatsApp: [WHATSAPP_PLACEHOLDER]</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Instagram: [INSTAGRAM_PLACEHOLDER]</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>Business hours: Monday to Saturday, 09.00 to 18.00 WIB.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>For order status questions, include your order number. For product requests, see the <a href="/request-product/">Request Product</a> page.</p>
<!-- /wp:paragraph -->
EOT;

    $home_id = veedy_upsert_page('home', 'Home', $home);
    veedy_upsert_page('how-it-works', 'How It Works', $how);
    veedy_upsert_page('request-product', 'Request Product', $request);
    veedy_upsert_page('testimonials', 'Testimonials', $testimonials);
    veedy_upsert_page('faq', 'FAQ', $faq);
    veedy_upsert_page('track-order', 'Track Order', $track);
    veedy_upsert_page('terms-and-conditions', 'Terms and Conditions', $terms);
    veedy_upsert_page('refund-policy', 'Refund Policy', $refund);
    $privacy_id = veedy_upsert_page('privacy-policy', 'Privacy Policy', $privacy);
    veedy_upsert_page('contact', 'Contact', $contact);

    $shop = get_page_by_path('shop', OBJECT, 'page');
    if (!$shop) {
        $shop_id = wp_insert_post(array('post_type' => 'page', 'post_name' => 'shop', 'post_title' => 'Shop', 'post_status' => 'publish', 'post_content' => ''));
        update_option('woocommerce_shop_page_id', $shop_id);
        veedy_out("created shop page (ID $shop_id)");
    } else {
        if ($shop->post_status !== 'publish') { wp_update_post(array('ID' => $shop->ID, 'post_status' => 'publish')); }
        if (!get_option('woocommerce_shop_page_id')) { update_option('woocommerce_shop_page_id', $shop->ID); }
        veedy_out("shop page exists (ID {$shop->ID})");
    }

    update_option('show_on_front', 'page');
    update_option('page_on_front', $home_id);
    update_option('page_for_posts', 0);
    update_option('wp_page_for_privacy_policy', $privacy_id);
    veedy_out("front page set to $home_id, privacy page $privacy_id");
}

function veedy_step_product() {
    $cat_ids = array();
    foreach (array('Gaming Handhelds', 'Accessories', 'Open PO', 'Ready Stock', 'Jasa Titip', 'Request Only') as $c) {
        $existing = get_term_by('name', $c, 'product_cat');
        if ($existing) {
            $cat_ids[$c] = (int) $existing->term_id;
            veedy_out("category exists: $c ({$existing->term_id})");
        } else {
            $r = wp_insert_term($c, 'product_cat');
            if (is_wp_error($r)) { veedy_out("category ERROR $c: " . $r->get_error_message()); }
            else { $cat_ids[$c] = (int) $r['term_id']; veedy_out("category created: $c ({$r['term_id']})"); }
        }
    }

    $existing_product = get_page_by_path('sample-overseas-gaming-handheld', OBJECT, 'product');
    if ($existing_product) {
        veedy_out("sample product already exists (ID {$existing_product->ID}), skipping create");
        veedy_out('product permalink: ' . get_permalink($existing_product->ID));
        return;
    }

    $desc = <<<'EOT'
<!-- wp:paragraph -->
<p>This is a sample jasa titip product listing. Final products will include source URL, estimated arrival time, available variants, and assisted purchase terms.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Assisted Purchase Details</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Order type: Jasa Titip / Open PO</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Estimated arrival: 2-8 weeks</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Source store: Placeholder</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Source URL: Placeholder</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Warranty: Supplier warranty only unless stated</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Cancellation: Depends on supplier purchase status</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->
EOT;

    $attr_color = new WC_Product_Attribute();
    $attr_color->set_name('Color');
    $attr_color->set_options(array('Black', 'White', 'Transparent Blue'));
    $attr_color->set_position(0);
    $attr_color->set_visible(true);
    $attr_color->set_variation(true);

    $attr_model = new WC_Product_Attribute();
    $attr_model->set_name('Model');
    $attr_model->set_options(array('8GB + 128GB', '12GB + 256GB'));
    $attr_model->set_position(1);
    $attr_model->set_visible(true);
    $attr_model->set_variation(true);

    $product = new WC_Product_Variable();
    $product->set_name('Sample Overseas Gaming Handheld');
    $product->set_status('publish');
    $product->set_description($desc);
    $product->set_short_description('Sample jasa titip listing. Estimated arrival 2-8 weeks. Supplier warranty only unless stated.');
    $product->set_category_ids(array_values(array_intersect_key($cat_ids, array_flip(array('Gaming Handhelds', 'Jasa Titip', 'Open PO')))));
    $product->set_attributes(array($attr_color, $attr_model));
    $product->set_default_attributes(array('color' => 'Black', 'model' => '8GB + 128GB'));
    $pid = $product->save();
    veedy_out("product created (ID $pid)");

    update_post_meta($pid, '_veedy_order_type', 'Jasa Titip / Open PO');
    update_post_meta($pid, '_veedy_estimated_arrival', '2-8 weeks');
    update_post_meta($pid, '_veedy_source_store', 'Placeholder');
    update_post_meta($pid, '_veedy_source_url', 'Placeholder');
    update_post_meta($pid, '_veedy_warranty', 'Supplier warranty only unless stated');
    update_post_meta($pid, '_veedy_cancellation', 'Depends on supplier purchase status');

    $variants = array(
        array('Black', '8GB + 128GB', '3500000'),
        array('Black', '12GB + 256GB', '4200000'),
        array('White', '8GB + 128GB', '3500000'),
        array('Transparent Blue', '12GB + 256GB', '4200000'),
    );
    foreach ($variants as $v) {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($pid);
        $variation->set_attributes(array('color' => $v[0], 'model' => $v[1]));
        $variation->set_regular_price($v[2]);
        $variation->set_manage_stock(false);
        $variation->set_stock_status('instock');
        $vid = $variation->save();
        veedy_out("variation created (ID $vid): {$v[0]} / {$v[1]} = Rp{$v[2]}");
    }
    WC_Product_Variable::sync($pid);
    wc_delete_product_transients($pid);
    flush_rewrite_rules(false);
    veedy_out('product permalink: ' . get_permalink($pid));
}

function veedy_step_menus() {
    $build = function ($name, $items) {
        $existing = wp_get_nav_menu_object($name);
        if ($existing) { $menu_id = (int) $existing->term_id; veedy_out("menu exists: $name ($menu_id)"); }
        else { $menu_id = wp_create_nav_menu($name); veedy_out("menu created: $name ($menu_id)"); }
        $current = wp_get_nav_menu_items($menu_id);
        if (!empty($current)) { veedy_out("menu $name already has items, skipping"); return $menu_id; }
        foreach ($items as $slug => $title) {
            $page = get_page_by_path($slug, OBJECT, 'page');
            if (!$page) { veedy_out("menu WARN: page $slug missing"); continue; }
            wp_update_nav_menu_item($menu_id, 0, array(
                'menu-item-title' => $title,
                'menu-item-object-id' => $page->ID,
                'menu-item-object' => 'page',
                'menu-item-type' => 'post_type',
                'menu-item-status' => 'publish',
            ));
        }
        veedy_out("menu $name populated");
        return $menu_id;
    };
    $main = $build('Main Menu', array(
        'home' => 'Home',
        'shop' => 'Shop',
        'how-it-works' => 'How It Works',
        'request-product' => 'Request Product',
        'testimonials' => 'Testimonials',
        'faq' => 'FAQ',
        'track-order' => 'Track Order',
        'contact' => 'Contact',
    ));
    $footer = $build('Footer Menu', array(
        'terms-and-conditions' => 'Terms and Conditions',
        'refund-policy' => 'Refund Policy',
        'privacy-policy' => 'Privacy Policy',
        'contact' => 'Contact',
    ));
    $locations = get_registered_nav_menus();
    $assign = get_theme_mod('nav_menu_locations');
    if (!is_array($assign)) { $assign = array(); }
    foreach ($locations as $slug => $label) {
        $l = strtolower($slug . ' ' . $label);
        if (strpos($l, 'footer') !== false) { $assign[$slug] = $footer; } else { $assign[$slug] = $main; }
        veedy_out("location $slug => menu {$assign[$slug]}");
    }
    set_theme_mod('nav_menu_locations', $assign);
    veedy_out('menu locations assigned');
}

function veedy_step_verify() {
    foreach (array('blogname', 'blogdescription', 'timezone_string', 'permalink_structure', 'blog_public',
        'woocommerce_currency', 'woocommerce_default_country', 'woocommerce_enable_guest_checkout',
        'woocommerce_calc_taxes', 'show_on_front', 'page_on_front', 'wp_page_for_privacy_policy') as $o) {
        veedy_out("$o: " . print_r(get_option($o), true));
    }
    veedy_out('active_theme: ' . get_stylesheet());
    foreach (get_pages(array('post_status' => 'publish')) as $pg) {
        veedy_out('page: ' . $pg->ID . ' | ' . $pg->post_name . ' | ' . $pg->post_title);
    }
    foreach (get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false)) as $t) {
        veedy_out('category: ' . $t->term_id . ' | ' . $t->name . ' (' . $t->count . ')');
    }
    $products = wc_get_products(array('limit' => 10));
    foreach ($products as $p) {
        veedy_out('product: ' . $p->get_id() . ' | ' . $p->get_name() . ' | ' . $p->get_type() . ' | ' . $p->get_price_html());
        if ($p->is_type('variable')) {
            foreach ($p->get_children() as $cid) {
                $v = wc_get_product($cid);
                if ($v) { veedy_out('  variation: ' . $cid . ' | ' . implode(' / ', $v->get_attributes()) . ' | ' . $v->get_regular_price()); }
            }
        }
    }
    foreach (wp_get_nav_menus() as $menu) {
        veedy_out('menu: ' . $menu->term_id . ' | ' . $menu->name . ' | items: ' . $menu->count);
        foreach ((array) wp_get_nav_menu_items($menu->term_id) as $item) {
            if ($item) { veedy_out('  item: ' . $item->title . ' -> ' . $item->url); }
        }
    }
    veedy_out('menu_locations: ' . print_r(get_theme_mod('nav_menu_locations'), true));
    $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
    foreach ($gateways as $id => $g) {
        veedy_out('gateway: ' . $id . ' | enabled: ' . $g->enabled);
    }
    if (class_exists('WC_Shipping_Zones')) {
        foreach (WC_Shipping_Zones::get_zones() as $z) {
            $methods = array();
            foreach ($z['shipping_methods'] as $m) { $methods[] = $m->id . ':' . $m->title; }
            veedy_out('shipping_zone: ' . $z['zone_name'] . ' | ' . implode(', ', $methods));
        }
    }
}

function veedy_step_orders() {
    $orders = wc_get_orders(array('limit' => 5, 'orderby' => 'date', 'order' => 'DESC'));
    if (empty($orders)) { veedy_out('no orders found'); return; }
    foreach ($orders as $o) {
        veedy_out('order: #' . $o->get_order_number() . ' | id: ' . $o->get_id() . ' | status: ' . $o->get_status()
            . ' | total: ' . $o->get_total() . ' ' . $o->get_currency()
            . ' | payment: ' . $o->get_payment_method_title()
            . ' | customer: ' . $o->get_billing_first_name() . ' ' . $o->get_billing_last_name()
            . ' | email: ' . $o->get_billing_email()
            . ' | guest: ' . ($o->get_customer_id() ? 'no' : 'yes'));
        foreach ($o->get_items() as $item) {
            veedy_out('  item: ' . $item->get_name() . ' x' . $item->get_quantity());
        }
    }
}

function veedy_step_cleanup() {
    foreach (array('veedy-ping-x7k2m9.txt', 'veedy-setup-report-x7k2m9.txt') as $f) {
        $path = ABSPATH . $f;
        if (file_exists($path)) { @unlink($path); veedy_out("deleted $f"); }
    }
    if (!function_exists('deactivate_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
    $self = plugin_basename(__FILE__);
    deactivate_plugins($self, true);
    veedy_out("deactivated $self");
    $dir = untrailingslashit(plugin_dir_path(__FILE__));
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($dir);
    veedy_out('plugin directory removed: ' . $dir);
    veedy_out('cleanup complete');
}
