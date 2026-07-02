<?php
/**
 * Plugin Name: Veedy Jastip Core
 * Description: Lightweight WooCommerce extensions for Veedy Store assisted purchase workflow.
 * Version: 1.0.0
 * Author: Veedy Store
 * Text Domain: veedy-jastip-core
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Veedy_Jastip_Core {
    private const PRODUCT_META = [
        '_veedy_source_store' => 'Source Store',
        '_veedy_source_url' => 'Source URL',
        '_veedy_order_type' => 'Order Type',
        '_veedy_estimated_arrival' => 'Estimated Arrival',
        '_veedy_last_price_checked' => 'Last Price Checked Date',
        '_veedy_price_includes' => 'Price Includes',
        '_veedy_price_excludes' => 'Price Excludes',
        '_veedy_warranty_note' => 'Warranty Note',
        '_veedy_cancellation_note' => 'Cancellation Note',
        '_veedy_supplier_availability' => 'Supplier Availability Note',
    ];

    private const ORDER_META = [
        '_veedy_supplier_store' => 'Supplier store',
        '_veedy_supplier_order_number' => 'Supplier order number',
        '_veedy_supplier_purchase_date' => 'Supplier purchase date',
        '_veedy_supplier_tracking_courier' => 'Supplier tracking courier',
        '_veedy_supplier_tracking_number' => 'Supplier tracking number',
        '_veedy_supplier_tracking_url' => 'Supplier tracking URL',
        '_veedy_forwarder_name' => 'Forwarder name',
        '_veedy_warehouse_arrival_date' => 'Warehouse arrival date',
        '_veedy_domestic_courier' => 'Domestic courier',
        '_veedy_domestic_tracking_number' => 'Domestic tracking number/resi',
        '_veedy_domestic_tracking_url' => 'Domestic tracking URL',
        '_veedy_customer_status_note' => 'Customer-visible status note',
        '_veedy_internal_admin_note' => 'Internal admin note',
    ];

    public static function init(): void {
        add_filter('body_class', [__CLASS__, 'body_class']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('init', [__CLASS__, 'register_statuses']);
        add_filter('wc_order_statuses', [__CLASS__, 'order_statuses']);
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'product_fields']);
        add_action('woocommerce_process_product_meta', [__CLASS__, 'save_product_fields']);
        add_action('woocommerce_single_product_summary', [__CLASS__, 'quote_cta'], 31);
        add_action('woocommerce_after_single_product_summary', [__CLASS__, 'product_jastip_details'], 9);
        add_filter('woocommerce_product_tabs', [__CLASS__, 'rename_reviews_tab'], 20);
        add_filter('woocommerce_get_price_html', [__CLASS__, 'price_html'], 20, 2);
        add_filter('woocommerce_loop_add_to_cart_link', [__CLASS__, 'loop_cta'], 20, 3);
        add_filter('woocommerce_checkout_fields', [__CLASS__, 'checkout_fields']);
        add_action('woocommerce_review_order_before_submit', [__CLASS__, 'checkout_agreement']);
        add_action('woocommerce_after_checkout_validation', [__CLASS__, 'validate_checkout'], 10, 2);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'save_checkout_order_meta'], 20, 2);
        add_action('woocommerce_admin_order_data_after_billing_address', [__CLASS__, 'admin_order_contact_meta']);
        add_action('add_meta_boxes', [__CLASS__, 'order_metabox']);
        add_action('woocommerce_process_shop_order_meta', [__CLASS__, 'save_order_meta']);
        add_action('woocommerce_before_account_orders', [__CLASS__, 'account_tracking_hint']);
        add_action('wp_footer', [__CLASS__, 'footer']);
        add_shortcode('veedy_order_tracking', [__CLASS__, 'tracking_shortcode']);
        add_filter('woocommerce_gateway_title', [__CLASS__, 'gateway_title'], 10, 2);
    }

    public static function body_class(array $classes): array {
        $classes[] = 'veedy-store';
        return array_values(array_unique($classes));
    }

    public static function assets(): void {
        wp_enqueue_style('veedy-store-visual-direction', plugin_dir_url(__FILE__) . 'assets/veedy-store-visual-direction.css', [], '1.0.0');
        wp_register_style('veedy-jastip-core', false, [], '1.0.0');
        wp_enqueue_style('veedy-jastip-core');
        wp_add_inline_style('veedy-jastip-core', self::css());
    }

    private static function css(): string {
        return <<<'CSS'
:root {
  --veedy-ink: #080b12;
  --veedy-muted: #536070;
  --veedy-line: rgba(8, 11, 18, .1);
  --veedy-soft: #f5f7fb;
  --veedy-accent: #111827;
  --veedy-blue: #2563eb;
  --veedy-glass: rgba(255, 255, 255, .74);
}
body { color: var(--veedy-ink); }
body .site-main, body .ct-container { max-width: 1180px; }
.veedy-shell, .entry-content .veedy-shell {
  position: relative;
  overflow: hidden;
  border: 1px solid rgba(8, 11, 18, .08);
  background: linear-gradient(145deg, rgba(255,255,255,.86), rgba(247,249,253,.62));
  box-shadow: 0 18px 50px rgba(15, 23, 42, .08);
  border-radius: 24px;
}
.veedy-glass {
  border: 1px solid rgba(255,255,255,.7);
  background:
    radial-gradient(circle at 12% 10%, rgba(37, 99, 235, .12), transparent 34%),
    linear-gradient(145deg, rgba(255,255,255,.88), rgba(246,248,252,.62));
  box-shadow: inset 0 1px 0 rgba(255,255,255,.9), 0 18px 40px rgba(15,23,42,.08);
  backdrop-filter: blur(18px) saturate(130%);
}
.veedy-hero {
  padding: clamp(64px, 9vw, 128px) clamp(22px, 6vw, 78px);
  margin: 18px auto 64px;
}
.veedy-hero h1, .veedy-display {
  max-width: 1120px;
  font-size: clamp(42px, 6vw, 86px);
  line-height: .96;
  letter-spacing: -.035em;
  text-wrap: balance;
  margin: 0 auto 22px;
}
.veedy-lead {
  max-width: 780px;
  color: var(--veedy-muted);
  font-size: clamp(17px, 1.8vw, 22px);
  line-height: 1.65;
  margin-left: auto;
  margin-right: auto;
}
.veedy-buttons { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; justify-content: center; margin-top: 30px; }
.veedy-button, .entry-content a.veedy-button {
  display: inline-flex;
  min-height: 48px;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  padding: 13px 22px;
  text-decoration: none;
  font-weight: 700;
  transition: transform .32s cubic-bezier(.2,.8,.2,1), background .32s cubic-bezier(.2,.8,.2,1);
}
.veedy-button.primary { color: #fff; background: #090d16; }
.veedy-button.secondary { color: #090d16; background: rgba(255,255,255,.82); border: 1px solid var(--veedy-line); }
.veedy-button:hover { transform: translateY(-2px); }
.veedy-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 18px; grid-auto-flow: dense; }
.veedy-span-6 { grid-column: span 6; }
.veedy-span-4 { grid-column: span 4; }
.veedy-span-8 { grid-column: span 8; }
.veedy-card { padding: clamp(22px, 3vw, 34px); border-radius: 22px; }
.veedy-card h2, .veedy-card h3 { margin-top: 0; text-wrap: balance; }
.veedy-kicker { color: var(--veedy-muted); font-size: 14px; line-height: 1.7; }
.veedy-section { margin: clamp(70px, 9vw, 132px) 0; }
.veedy-product-meta {
  margin: 36px 0;
  padding: clamp(22px, 3vw, 32px);
  border-radius: 22px;
  color: var(--veedy-ink);
}
.veedy-meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-top: 18px; }
.veedy-meta-item { padding: 15px; border-radius: 16px; background: rgba(255,255,255,.68); border: 1px solid rgba(8,11,18,.08); }
.veedy-meta-item strong { display: block; margin-bottom: 4px; }
.veedy-warning { border-color: rgba(180, 83, 9, .22); background: rgba(255, 251, 235, .8); }
.veedy-tracking-form, .veedy-tracking-result { max-width: 760px; }
.veedy-tracking-form input { width: 100%; margin: 5px 0 14px; }
.veedy-order-admin-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.veedy-order-admin-fields label { font-weight: 600; }
.veedy-order-admin-fields input, .veedy-order-admin-fields textarea { width: 100%; }
.veedy-order-admin-fields .wide { grid-column: 1 / -1; }
.woocommerce .veedy-quote-box { margin: 20px 0; }
.woocommerce .veedy-quote-box .button { margin-top: 12px; }
@media (max-width: 782px) {
  .veedy-grid { grid-template-columns: 1fr; }
  .veedy-span-4, .veedy-span-6, .veedy-span-8 { grid-column: 1 / -1; }
  .veedy-hero { border-radius: 20px; padding: 54px 22px; }
  .veedy-hero h1, .veedy-display { font-size: clamp(38px, 12vw, 58px); }
  .veedy-buttons { justify-content: stretch; }
  .veedy-button { width: 100%; }
  .veedy-order-admin-fields { grid-template-columns: 1fr; }
}
@media (prefers-reduced-motion: reduce) {
  .veedy-button { transition: none; }
  .veedy-button:hover { transform: none; }
}
CSS;
    }

    public static function register_statuses(): void {
        $statuses = [
            'wc-payment-verification' => 'Verifikasi Pembayaran',
            'wc-preparing-purchase' => 'Persiapan Pembelian',
            'wc-ordered-supplier' => 'Dipesan ke Supplier',
            'wc-waiting-supplier' => 'Menunggu Pengiriman Supplier',
            'wc-international-ship' => 'Pengiriman Internasional',
            'wc-arrived-warehouse' => 'Tiba di Warehouse',
            'wc-domestic-shipping' => 'Pengiriman Lokal',
            'wc-action-needed' => 'Perlu Tindakan',
        ];

        foreach ($statuses as $slug => $label) {
            register_post_status($slug, [
                'label' => $label,
                'public' => true,
                'exclude_from_search' => false,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => _n_noop($label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', 'veedy-jastip-core'),
            ]);
        }
    }

    public static function order_statuses(array $statuses): array {
        $insert = [
            'wc-payment-verification' => 'Verifikasi Pembayaran',
            'wc-preparing-purchase' => 'Persiapan Pembelian',
            'wc-ordered-supplier' => 'Dipesan ke Supplier',
            'wc-waiting-supplier' => 'Menunggu Pengiriman Supplier',
            'wc-international-ship' => 'Pengiriman Internasional',
            'wc-arrived-warehouse' => 'Tiba di Warehouse',
            'wc-domestic-shipping' => 'Pengiriman Lokal',
            'wc-action-needed' => 'Perlu Tindakan',
        ];
        $new = [];
        foreach ($statuses as $key => $label) {
            $new[$key] = $label;
            if ('wc-processing' === $key) {
                foreach ($insert as $i_key => $i_label) {
                    $new[$i_key] = $i_label;
                }
            }
        }
        return $new;
    }

    public static function product_fields(): void {
        echo '<div class="options_group">';
        woocommerce_wp_text_input(['id' => '_veedy_source_store', 'label' => 'Source Store']);
        woocommerce_wp_text_input(['id' => '_veedy_source_url', 'label' => 'Source URL']);
        woocommerce_wp_select(['id' => '_veedy_order_type', 'label' => 'Order Type', 'options' => [
            '' => 'Select', 'Ready Stock' => 'Ready Stock', 'Open PO' => 'Open PO', 'Jasa Titip' => 'Jasa Titip', 'Request Only' => 'Request Only'
        ]]);
        woocommerce_wp_text_input(['id' => '_veedy_estimated_arrival', 'label' => 'Estimated Arrival']);
        woocommerce_wp_text_input(['id' => '_veedy_last_price_checked', 'label' => 'Last Price Checked Date', 'type' => 'date']);
        woocommerce_wp_textarea_input(['id' => '_veedy_price_includes', 'label' => 'Price Includes']);
        woocommerce_wp_textarea_input(['id' => '_veedy_price_excludes', 'label' => 'Price Excludes']);
        woocommerce_wp_textarea_input(['id' => '_veedy_warranty_note', 'label' => 'Warranty Note']);
        woocommerce_wp_textarea_input(['id' => '_veedy_cancellation_note', 'label' => 'Cancellation Note']);
        woocommerce_wp_textarea_input(['id' => '_veedy_supplier_availability', 'label' => 'Supplier Availability Note']);
        echo '</div>';
    }

    public static function save_product_fields(int $post_id): void {
        foreach (self::PRODUCT_META as $key => $label) {
            $value = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
            update_post_meta($post_id, $key, is_array($value) ? '' : sanitize_textarea_field($value));
        }
    }

    public static function quote_cta(): void {
        global $product;
        if (!$product instanceof WC_Product) {
            return;
        }
        $order_type = (string) get_post_meta($product->get_id(), '_veedy_order_type', true);
        if (!in_array($order_type, ['Open PO', 'Request Only'], true) && $product->get_price() !== '') {
            return;
        }
        $request_url = home_url('/request-product/?produk=' . rawurlencode($product->get_name()));
        echo '<div class="veedy-quote-box veedy-glass veedy-card"><strong>Produk Open PO</strong><p>Harga final dan ketersediaan akan dikonfirmasi sebelum menjadi pesanan aktif.</p><a class="button" href="' . esc_url($request_url) . '">Request Produk</a></div>';
    }

    public static function product_jastip_details(): void {
        if (!is_product()) {
            return;
        }
        global $product;
        if (!$product instanceof WC_Product) {
            return;
        }
        $rows = [];
        foreach (self::PRODUCT_META as $key => $label) {
            $value = trim((string) get_post_meta($product->get_id(), $key, true));
            if ($value !== '') {
                $rows[$label] = $value;
            }
        }
        if (!$rows) {
            return;
        }
        echo '<section class="veedy-product-meta veedy-glass"><h2>Detail Jasa Titip</h2><div class="veedy-meta-grid">';
        foreach ($rows as $label => $value) {
            $is_url = filter_var($value, FILTER_VALIDATE_URL);
            echo '<div class="veedy-meta-item"><strong>' . esc_html($label) . '</strong>';
            if ($is_url) {
                echo '<a href="' . esc_url($value) . '" target="_blank" rel="nofollow noopener">Lihat sumber produk</a>';
            } else {
                echo '<span>' . nl2br(esc_html($value)) . '</span>';
            }
            echo '</div>';
        }
        echo '</div><div class="veedy-meta-item veedy-warning" style="margin-top:16px"><strong>Catatan status brand</strong><span>Veedy Store adalah jasa titip / assisted purchase service. Kami bukan official distributor, official reseller, atau perwakilan resmi brand apa pun kecuali tertulis secara eksplisit.</span></div></section>';
    }

    public static function rename_reviews_tab(array $tabs): array {
        if (isset($tabs['reviews'])) {
            global $product;
            if ($product instanceof WC_Product && (int) $product->get_review_count() === 0) {
                unset($tabs['reviews']);
                return $tabs;
            }
            $tabs['reviews']['title'] = 'Review';
        }
        return $tabs;
    }

    public static function footer(): void {
        if (is_admin()) {
            return;
        }
        echo '<footer class="vd-footer" role="contentinfo"><div class="vd-wrap"><p><strong>Veedy Store</strong> membantu pembelian produk luar negeri untuk pelanggan Indonesia melalui proses jasa titip yang lebih rapi dan terlacak.</p><p>Email: <a href="mailto:storeveedy@gmail.com">storeveedy@gmail.com</a> · Discord: veedy · Instagram: <a href="https://instagram.com/realveedy" target="_blank" rel="noopener">@realveedy</a> · Jam operasional: 09.00-17.00 WIB</p><p>Veedy Store adalah jasa titip / assisted purchase service, bukan official distributor brand kecuali tertulis.</p></div></footer>';
    }

    public static function price_html(string $price_html, WC_Product $product): string {
        $order_type = (string) get_post_meta($product->get_id(), '_veedy_order_type', true);
        if (in_array($order_type, ['Open PO', 'Request Only'], true) && trim($price_html) === '') {
            return '<span class="price veedy-quote-price">Open PO - minta quote</span>';
        }
        return $price_html;
    }

    public static function loop_cta(string $html, WC_Product $product, array $args): string {
        $order_type = (string) get_post_meta($product->get_id(), '_veedy_order_type', true);
        if (in_array($order_type, ['Open PO', 'Request Only'], true) && trim((string) $product->get_price()) === '') {
            $url = home_url('/request-product/?produk=' . rawurlencode($product->get_name()));
            return '<a href="' . esc_url($url) . '" class="button">Request Produk</a>';
        }
        return $html;
    }

    public static function checkout_fields(array $fields): array {
        if (isset($fields['billing']['billing_phone'])) {
            $fields['billing']['billing_phone']['label'] = 'Nomor telepon untuk pengiriman';
            $fields['billing']['billing_phone']['required'] = true;
        }
        $fields['billing']['billing_discord'] = [
            'type' => 'text',
            'label' => 'Username Discord',
            'placeholder' => 'contoh: namauser',
            'required' => true,
            'priority' => 45,
            'class' => ['form-row-wide'],
        ];
        $fields['billing']['billing_kecamatan'] = [
            'type' => 'text', 'label' => 'Kecamatan', 'required' => true, 'priority' => 82, 'class' => ['form-row-wide']
        ];
        $fields['billing']['billing_kelurahan'] = [
            'type' => 'text', 'label' => 'Kelurahan / Desa', 'required' => false, 'priority' => 83, 'class' => ['form-row-wide']
        ];
        return $fields;
    }

    public static function checkout_agreement(): void {
        echo '<p class="form-row veedy-checkout-agreement"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="veedy_jastip_agreement" value="1" /> <span>Saya paham bahwa pesanan ini adalah jasa titip / assisted purchase. Pesanan akan dibeli ke supplier setelah pembayaran diverifikasi. Estimasi pengiriman dapat berubah karena supplier, forwarder, bea cukai, atau kurir lokal.</span> <abbr class="required" title="required">*</abbr></label></p>';
    }

    public static function validate_checkout($data, WP_Error $errors): void {
        if (empty($_POST['veedy_jastip_agreement'])) {
            $errors->add('veedy_jastip_agreement', 'Kamu perlu menyetujui ketentuan jasa titip sebelum membuat pesanan.');
        }
    }

    public static function save_checkout_order_meta(WC_Order $order, array $data): void {
        foreach (['billing_discord', 'billing_kecamatan', 'billing_kelurahan'] as $field) {
            if (isset($_POST[$field])) {
                $order->update_meta_data('_' . $field, sanitize_text_field(wp_unslash($_POST[$field])));
            }
        }
        if (!empty($_POST['veedy_jastip_agreement'])) {
            $order->update_meta_data('_veedy_jastip_agreement', 'yes');
        }
    }

    public static function admin_order_contact_meta(WC_Order $order): void {
        $discord = $order->get_meta('_billing_discord');
        $kecamatan = $order->get_meta('_billing_kecamatan');
        $kelurahan = $order->get_meta('_billing_kelurahan');
        if ($discord || $kecamatan || $kelurahan) {
            echo '<p><strong>Discord:</strong> ' . esc_html($discord ?: '-') . '<br><strong>Kecamatan:</strong> ' . esc_html($kecamatan ?: '-') . '<br><strong>Kelurahan/Desa:</strong> ' . esc_html($kelurahan ?: '-') . '</p>';
        }
    }

    public static function order_metabox(): void {
        add_meta_box('veedy_jastip_order', 'Veedy Jastip Tracking', [__CLASS__, 'order_metabox_html'], 'shop_order', 'normal', 'high');
        add_meta_box('veedy_jastip_order', 'Veedy Jastip Tracking', [__CLASS__, 'order_metabox_html'], 'woocommerce_page_wc-orders', 'normal', 'high');
    }

    public static function order_metabox_html($post_or_order): void {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID ?? 0);
        if (!$order) { return; }
        wp_nonce_field('veedy_save_order_meta', 'veedy_order_meta_nonce');
        echo '<div class="veedy-order-admin-fields">';
        foreach (self::ORDER_META as $key => $label) {
            $value = $order->get_meta($key);
            $wide = in_array($key, ['_veedy_customer_status_note', '_veedy_internal_admin_note'], true) ? ' wide' : '';
            echo '<p class="' . esc_attr($wide) . '"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label>';
            if ($wide) {
                echo '<textarea id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" rows="3">' . esc_textarea($value) . '</textarea>';
            } else {
                echo '<input id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
            }
            echo '</p>';
        }
        echo '</div>';
    }

    public static function save_order_meta(int $order_id): void {
        if (empty($_POST['veedy_order_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['veedy_order_meta_nonce'])), 'veedy_save_order_meta')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) { return; }
        foreach (self::ORDER_META as $key => $label) {
            if (isset($_POST[$key])) {
                $order->update_meta_data($key, sanitize_textarea_field(wp_unslash($_POST[$key])));
            }
        }
        $order->save();
    }

    public static function account_tracking_hint(): void {
        echo '<div class="veedy-glass veedy-card" style="margin-bottom:24px">Untuk pertanyaan status jasa titip, sertakan nomor pesanan dan email/Discord yang digunakan saat checkout.</div>';
    }

    public static function tracking_shortcode(): string {
        $output = '';
        $order = null;
        $message = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['veedy_tracking_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['veedy_tracking_nonce'])), 'veedy_tracking')) {
            $order_number = sanitize_text_field(wp_unslash($_POST['veedy_order_number'] ?? ''));
            $identity = sanitize_text_field(wp_unslash($_POST['veedy_identity'] ?? ''));
            $order = $order_number ? wc_get_order(absint($order_number)) : null;
            if (!$order) {
                $message = 'Pesanan tidak ditemukan.';
            } else {
                $email = strtolower((string) $order->get_billing_email());
                $discord = strtolower((string) $order->get_meta('_billing_discord'));
                $check = strtolower($identity);
                if (!$check || ($check !== $email && $check !== $discord)) {
                    $order = null;
                    $message = 'Data verifikasi tidak cocok. Masukkan email billing atau username Discord yang digunakan saat checkout.';
                }
            }
        }
        ob_start();
        echo '<form class="veedy-tracking-form veedy-glass veedy-card" method="post">';
        wp_nonce_field('veedy_tracking', 'veedy_tracking_nonce');
        echo '<h2>Lacak Pesanan</h2><p>Masukkan nomor pesanan dan email billing atau username Discord. Detail pesanan tidak dapat dibuka hanya dengan nomor pesanan.</p>';
        if ($message) { echo '<p class="veedy-warning veedy-card">' . esc_html($message) . '</p>'; }
        echo '<label>Nomor pesanan<input name="veedy_order_number" required inputmode="numeric"></label>';
        echo '<label>Email billing atau username Discord<input name="veedy_identity" required></label>';
        echo '<button class="button" type="submit">Cek Status</button></form>';
        if ($order) {
            echo '<div class="veedy-tracking-result veedy-glass veedy-card" style="margin-top:24px"><h2>Status Pesanan #' . esc_html($order->get_order_number()) . '</h2>';
            echo '<p><strong>Status WooCommerce:</strong> ' . esc_html(wc_get_order_status_name($order->get_status())) . '</p>';
            echo '<p><strong>Metode pembayaran:</strong> ' . esc_html($order->get_payment_method_title()) . '</p><ul>';
            foreach ($order->get_items() as $item) { echo '<li>' . esc_html($item->get_name()) . ' x ' . esc_html((string) $item->get_quantity()) . '</li>'; }
            echo '</ul>';
            $public_keys = ['_veedy_customer_status_note', '_veedy_supplier_tracking_courier', '_veedy_supplier_tracking_number', '_veedy_supplier_tracking_url', '_veedy_domestic_courier', '_veedy_domestic_tracking_number', '_veedy_domestic_tracking_url'];
            foreach ($public_keys as $key) {
                $value = trim((string) $order->get_meta($key));
                if ($value !== '') {
                    echo '<p><strong>' . esc_html(self::ORDER_META[$key] ?? $key) . ':</strong> ' . (filter_var($value, FILTER_VALIDATE_URL) ? '<a href="' . esc_url($value) . '" rel="nofollow noopener" target="_blank">Buka link tracking</a>' : esc_html($value)) . '</p>';
                }
            }
            $notes = wc_get_order_notes(['order_id' => $order->get_id(), 'type' => 'customer', 'limit' => 1]);
            if ($notes) { echo '<p><strong>Catatan terbaru:</strong> ' . esc_html(wp_strip_all_tags($notes[0]->content)) . '</p>'; }
            echo '</div>';
        }
        $output .= ob_get_clean();
        return $output;
    }

    public static function gateway_title(string $title, string $id): string {
        return $id === 'bacs' ? 'Transfer Bank Manual' : $title;
    }
}

Veedy_Jastip_Core::init();
