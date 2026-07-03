<?php
/**
 * Plugin Name: Veedy Jastip Core
 * Description: Lightweight WooCommerce extensions for Veedy Store assisted purchase workflow.
 * Version: 2.0.0
 * Author: Veedy Store
 * Text Domain: veedy-jastip-core
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Veedy_Jastip_Core {
    private const BADGE_OPTIONS = [
        '' => '(auto)',
        'ready' => 'READY STOCK',
        'open-po' => 'OPEN PO',
        'request' => 'REQUEST QUOTE',
        'sold-out' => 'SOLD OUT',
        'limited' => 'LIMITED',
    ];

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

    private const TRACKING_STAGES = [
        ['label' => 'Verifikasi Pembayaran', 'statuses' => ['pending', 'on-hold', 'payment-verification']],
        ['label' => 'Pembelian Supplier', 'statuses' => ['processing', 'preparing-purchase', 'ordered-supplier']],
        ['label' => 'Menunggu Supplier', 'statuses' => ['waiting-supplier']],
        ['label' => 'Pengiriman Internasional', 'statuses' => ['international-ship']],
        ['label' => 'Tiba di Warehouse', 'statuses' => ['arrived-warehouse']],
        ['label' => 'Pengiriman Lokal', 'statuses' => ['domestic-shipping']],
        ['label' => 'Perlu Tindakan', 'statuses' => ['action-needed']],
        ['label' => 'Terkirim', 'statuses' => ['completed']],
    ];

    public static function init(): void {
        add_action('init', [__CLASS__, 'register_statuses']);
        add_filter('wc_order_statuses', [__CLASS__, 'order_statuses']);
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'product_fields']);
        add_action('woocommerce_process_product_meta', [__CLASS__, 'save_product_fields']);
        add_action('woocommerce_before_shop_loop_item_title', [__CLASS__, 'loop_badge'], 5);
        add_action('woocommerce_after_shop_loop_item_title', [__CLASS__, 'loop_card_meta'], 6);
        add_action('woocommerce_single_product_summary', [__CLASS__, 'buy_meta'], 25);
        add_action('woocommerce_single_product_summary', [__CLASS__, 'quote_cta'], 31);
        add_action('woocommerce_after_single_product_summary', [__CLASS__, 'product_accordions'], 5);
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
        add_shortcode('veedy_order_tracking', [__CLASS__, 'tracking_shortcode']);
        add_filter('woocommerce_gateway_title', [__CLASS__, 'gateway_title'], 10, 2);
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
        woocommerce_wp_select(['id' => '_veedy_badge', 'label' => 'Status Badge', 'desc_tip' => true, 'description' => 'Kosong = otomatis dari stok/kategori.', 'options' => self::BADGE_OPTIONS]);
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

        $badge = isset($_POST['_veedy_badge']) ? wp_unslash($_POST['_veedy_badge']) : '';
        $badge = is_array($badge) ? '' : sanitize_key($badge);
        if (!array_key_exists($badge, self::BADGE_OPTIONS)) {
            $badge = '';
        }
        update_post_meta($post_id, '_veedy_badge', $badge);
    }

    public static function loop_badge(): void {
        global $product;
        if (!$product instanceof WC_Product) {
            return;
        }

        $badge = self::resolve_product_badge($product);
        if (!$badge) {
            return;
        }

        echo '<span class="vd-badge vd-badge--' . esc_attr($badge['modifier']) . '">' . esc_html($badge['label']) . '</span>';
    }

    private static function resolve_product_badge(WC_Product $product): ?array {
        $explicit = sanitize_key((string) get_post_meta($product->get_id(), '_veedy_badge', true));
        if ($explicit !== '' && isset(self::BADGE_OPTIONS[$explicit])) {
            return ['modifier' => $explicit, 'label' => self::BADGE_OPTIONS[$explicit]];
        }

        $product_id = $product->get_id();
        if (!$product->is_in_stock()) {
            return ['modifier' => 'sold-out', 'label' => 'SOLD OUT'];
        }
        if (has_term('ready-stock', 'product_cat', $product_id)) {
            return ['modifier' => 'ready', 'label' => 'READY STOCK'];
        }
        if (has_term('open-po', 'product_cat', $product_id)) {
            return ['modifier' => 'open-po', 'label' => 'OPEN PO'];
        }
        if (!$product->is_purchasable() || trim((string) $product->get_price()) === '' || has_term('request-only', 'product_cat', $product_id)) {
            return ['modifier' => 'request', 'label' => 'REQUEST QUOTE'];
        }

        return null;
    }

    public static function loop_card_meta(): void {
        global $product;
        if (!$product instanceof WC_Product) {
            return;
        }

        $brand = self::short_text(self::product_meta_value($product, '_veedy_source_store'), 80);
        if ($brand === '') {
            $brand = self::short_text(self::primary_product_category_name($product->get_id()), 80);
        }
        $eta = self::short_text(self::product_meta_value($product, '_veedy_estimated_arrival'), 40);

        if ($brand === '' && $eta === '') {
            return;
        }

        if ($brand !== '') {
            echo '<div class="vd-card-brand">' . esc_html($brand) . '</div>';
        }
        if ($eta !== '') {
            echo '<div class="vd-card-eta">ETA ' . esc_html($eta) . '</div>';
        }
    }

    public static function buy_meta(): void {
        global $product;
        if (!$product instanceof WC_Product) {
            return;
        }

        $source_store = self::product_meta_value($product, '_veedy_source_store');
        $source_url = self::product_meta_value($product, '_veedy_source_url');
        $rows = [
            ['label' => 'Tipe Order', 'value' => self::product_meta_value($product, '_veedy_order_type'), 'url' => ''],
            ['label' => 'Estimasi Tiba', 'value' => self::product_meta_value($product, '_veedy_estimated_arrival'), 'url' => ''],
            ['label' => 'Source Store', 'value' => $source_store, 'url' => $source_url],
            ['label' => 'Harga Dicek', 'value' => self::product_meta_value($product, '_veedy_last_price_checked'), 'url' => ''],
        ];

        $rows = array_values(array_filter($rows, static function (array $row): bool {
            return trim((string) $row['value']) !== '';
        }));

        if (!$rows) {
            return;
        }

        echo '<div class="vd-buy-meta">';
        foreach ($rows as $row) {
            echo '<div class="vd-buy-meta__row"><span class="vd-buy-meta__label">' . esc_html($row['label']) . '</span><span class="vd-buy-meta__value">';
            $url = trim((string) $row['url']) !== '' ? esc_url($row['url']) : '';
            if ($url !== '') {
                echo '<a href="' . $url . '" target="_blank" rel="nofollow noopener">' . esc_html($row['value']) . '</a>';
            } else {
                echo nl2br(esc_html($row['value']));
            }
            echo '</span></div>';
        }
        echo '</div>';
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
        echo '<div><strong>Produk Open PO</strong><p>Harga final dan ketersediaan akan dikonfirmasi sebelum menjadi pesanan aktif.</p><a class="vd-request-btn" href="' . esc_url($request_url) . '">Request Produk</a></div>';
    }

    public static function product_accordions(): void {
        if (!is_product()) {
            return;
        }
        global $product;
        if (!$product instanceof WC_Product) {
            return;
        }

        $detail_rows = self::product_detail_rows($product);
        $eta = self::product_meta_value($product, '_veedy_estimated_arrival');
        $cancellation = self::product_meta_value($product, '_veedy_cancellation_note');
        $warranty = self::product_meta_value($product, '_veedy_warranty_note');

        ob_start();
        if ($detail_rows) {
            self::render_product_meta_table($detail_rows);
        } else {
            echo '<p>Detail tambahan belum tersedia.</p>';
        }
        $detail_body = ob_get_clean();

        $shipping_body = '<p>Estimasi tiba mengikuti jadwal supplier, forwarder, bea cukai, dan kurir lokal.</p>';
        if ($eta !== '') {
            $shipping_body .= '<p><strong>ETA:</strong> ' . esc_html($eta) . '</p>';
        }
        $shipping_body .= '<p><a href="' . esc_url(home_url('/how-it-works/')) . '">Lihat cara kerja jasa titip</a></p>';

        if ($cancellation !== '') {
            $cancellation_body = '<p>' . nl2br(esc_html($cancellation)) . '</p>';
        } else {
            $cancellation_body = '<p>Pembatalan mengikuti status pembelian supplier dan kebijakan refund.</p>';
        }
        $cancellation_body .= '<p><a href="' . esc_url(home_url('/refund-policy/')) . '">Baca kebijakan refund</a></p>';

        $accordions = [
            ['title' => 'Detail Jasa Titip', 'body_html' => $detail_body, 'open' => true],
            ['title' => 'Pengiriman & Estimasi', 'body_html' => $shipping_body, 'open' => false],
            ['title' => 'Pembatalan & Refund', 'body_html' => $cancellation_body, 'open' => false],
            ['title' => 'Garansi', 'body_html' => $warranty !== '' ? '<p>' . nl2br(esc_html($warranty)) . '</p>' : '<p>Ketentuan garansi mengikuti catatan produk dan kebijakan supplier.</p>', 'open' => false],
            ['title' => 'Disclaimer', 'body_html' => '<p>Veedy Store adalah jasa titip / assisted purchase service. Kami bukan official distributor, official reseller, atau perwakilan resmi brand apa pun kecuali tertulis secara eksplisit.</p>', 'open' => false],
        ];

        echo '<div class="vd-accordions">';
        foreach ($accordions as $accordion) {
            echo '<details class="vd-accordion"' . ($accordion['open'] ? ' open' : '') . '><summary class="vd-accordion__title">' . esc_html($accordion['title']) . '</summary><div class="vd-accordion__body">' . $accordion['body_html'] . '</div></details>';
        }
        echo '</div>';
    }

    private static function render_product_meta_table(array $rows): void {
        echo '<table><tbody>';
        foreach ($rows as $row) {
            echo '<tr><th scope="row">' . esc_html($row['label']) . '</th><td>';
            self::render_meta_value($row['key'], $row['value']);
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function product_detail_rows(WC_Product $product): array {
        $rows = [];
        foreach (self::PRODUCT_META as $key => $label) {
            $value = self::product_meta_value($product, $key);
            if ($value !== '') {
                $rows[] = ['key' => $key, 'label' => self::product_meta_label($key, $label), 'value' => $value];
            }
        }

        $badge = sanitize_key((string) get_post_meta($product->get_id(), '_veedy_badge', true));
        if ($badge !== '' && isset(self::BADGE_OPTIONS[$badge])) {
            $rows[] = ['key' => '_veedy_badge', 'label' => 'Status Katalog', 'value' => self::BADGE_OPTIONS[$badge]];
        }

        return $rows;
    }

    private static function product_meta_label(string $key, string $fallback): string {
        $labels = [
            '_veedy_source_store' => 'Source Store',
            '_veedy_source_url' => 'Link Sumber',
            '_veedy_order_type' => 'Tipe Order',
            '_veedy_estimated_arrival' => 'Estimasi Tiba',
            '_veedy_last_price_checked' => 'Harga Dicek',
            '_veedy_price_includes' => 'Harga Termasuk',
            '_veedy_price_excludes' => 'Harga Belum Termasuk',
            '_veedy_warranty_note' => 'Garansi',
            '_veedy_cancellation_note' => 'Pembatalan',
            '_veedy_supplier_availability' => 'Ketersediaan Supplier',
        ];
        return $labels[$key] ?? $fallback;
    }

    private static function render_meta_value(string $key, string $value): void {
        $url = $key === '_veedy_source_url' ? esc_url($value) : '';
        if ($url !== '') {
            echo '<a href="' . $url . '" target="_blank" rel="nofollow noopener">Buka sumber produk</a>';
            return;
        }
        echo nl2br(esc_html($value));
    }

    private static function product_meta_value(WC_Product $product, string $key): string {
        return trim((string) get_post_meta($product->get_id(), $key, true));
    }

    private static function primary_product_category_name(int $product_id): string {
        $primary_id = absint(get_post_meta($product_id, '_yoast_wpseo_primary_product_cat', true));
        if ($primary_id > 0) {
            $primary = get_term($primary_id, 'product_cat');
            if ($primary && !is_wp_error($primary) && isset($primary->name)) {
                return (string) $primary->name;
            }
        }

        $terms = get_the_terms($product_id, 'product_cat');
        if (!$terms || is_wp_error($terms)) {
            return '';
        }

        $fallback = '';
        $availability_slugs = ['ready-stock', 'open-po', 'request-only', 'jasa-titip'];
        foreach ($terms as $term) {
            if ($fallback === '' && isset($term->name)) {
                $fallback = (string) $term->name;
            }
            if (isset($term->slug, $term->name) && !in_array($term->slug, $availability_slugs, true)) {
                return (string) $term->name;
            }
        }

        return $fallback;
    }

    private static function short_text(string $value, int $limit = 40): string {
        $value = wp_strip_all_tags($value);
        $value = trim((string) preg_replace('/\s+/', ' ', $value));
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value) > $limit ? rtrim(mb_substr($value, 0, max(1, $limit - 1))) . '…' : $value;
        }
        return strlen($value) > $limit ? rtrim(substr($value, 0, max(1, $limit - 1))) . '…' : $value;
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

    public static function price_html(string $price_html, WC_Product $product): string {
        $order_type = (string) get_post_meta($product->get_id(), '_veedy_order_type', true);
        if (in_array($order_type, ['Open PO', 'Request Only'], true) && trim($price_html) === '') {
            return '<span class="price">Open PO - minta quote</span>';
        }
        return $price_html;
    }

    public static function loop_cta(string $html, WC_Product $product, array $args): string {
        $order_type = (string) get_post_meta($product->get_id(), '_veedy_order_type', true);
        if (in_array($order_type, ['Open PO', 'Request Only'], true) && trim((string) $product->get_price()) === '') {
            $url = home_url('/request-product/?produk=' . rawurlencode($product->get_name()));
            return '<a href="' . esc_url($url) . '" class="vd-request-btn">Request Produk</a>';
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
            'required' => false,
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
        echo '<p class="vd-track__hint">Untuk pertanyaan status jasa titip, sertakan nomor pesanan dan email atau No. HP billing.</p>';
    }

    public static function tracking_shortcode(): string {
        $order = null;
        $message = '';
        $order_number = '';
        $identity = '';

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['veedy_tracking_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['veedy_tracking_nonce'])), 'veedy_tracking')) {
            $order_number = sanitize_text_field(wp_unslash($_POST['veedy_order_number'] ?? ''));
            $identity = sanitize_text_field(wp_unslash($_POST['veedy_identity'] ?? ''));
            $order_id = absint(preg_replace('/\D+/', '', $order_number));
            $order = $order_id ? wc_get_order($order_id) : null;
            $failure_message = 'Pesanan tidak ditemukan atau data tidak cocok. Periksa kembali nomor pesanan dan email/no. HP billing.';
            if (!$order) {
                $message = $failure_message;
            } elseif (!self::order_identity_matches($order, $identity)) {
                $order = null;
                $message = $failure_message;
            }
        }

        ob_start();
        echo '<form class="vd-track" method="post">';
        wp_nonce_field('veedy_tracking', 'veedy_tracking_nonce');
        echo '<h2>Lacak Pesanan</h2><p>Masukkan nomor pesanan dan email atau No. HP billing. Detail pesanan tidak bisa dibuka hanya dengan nomor pesanan.</p>';
        echo '<p><label for="veedy_order_number">Nomor Pesanan</label><input type="text" id="veedy_order_number" name="veedy_order_number" value="' . esc_attr($order_number) . '" required inputmode="numeric"></p>';
        echo '<p><label for="veedy_identity">Email atau No. HP (billing)</label><input type="text" id="veedy_identity" name="veedy_identity" value="' . esc_attr($identity) . '" required></p>';
        echo '<button type="submit">Cek Status</button></form>';

        if ($message) {
            echo '<div class="vd-track__result" role="status"><p>' . esc_html($message) . '</p></div>';
        }

        if ($order) {
            echo '<div class="vd-track__result"><h2>Status Pesanan #' . esc_html($order->get_order_number()) . '</h2>';
            self::render_tracking_timeline($order);
            echo '<p><strong>Status:</strong> ' . esc_html(wc_get_order_status_name($order->get_status())) . '</p>';
            echo '<p><strong>Metode pembayaran:</strong> ' . esc_html($order->get_payment_method_title()) . '</p><ul>';
            foreach ($order->get_items() as $item) { echo '<li>' . esc_html($item->get_name()) . ' x ' . esc_html((string) $item->get_quantity()) . '</li>'; }
            echo '</ul>';
            $public_keys = ['_veedy_customer_status_note', '_veedy_supplier_tracking_courier', '_veedy_supplier_tracking_number', '_veedy_supplier_tracking_url', '_veedy_domestic_courier', '_veedy_domestic_tracking_number', '_veedy_domestic_tracking_url'];
            foreach ($public_keys as $key) {
                $value = trim((string) $order->get_meta($key));
                if ($value !== '') {
                    $url = in_array($key, ['_veedy_supplier_tracking_url', '_veedy_domestic_tracking_url'], true) ? esc_url($value) : '';
                    echo '<p><strong>' . esc_html(self::public_order_meta_label($key)) . ':</strong> ';
                    echo $url !== '' ? '<a href="' . $url . '" rel="nofollow noopener" target="_blank">Buka link tracking</a>' : nl2br(esc_html($value));
                    echo '</p>';
                }
            }
            $notes = wc_get_order_notes(['order_id' => $order->get_id(), 'type' => 'customer', 'limit' => 1]);
            if ($notes) { echo '<p><strong>Catatan terbaru:</strong> ' . esc_html(wp_strip_all_tags($notes[0]->content)) . '</p>'; }
            echo '</div>';
        }

        return ob_get_clean();
    }

    private static function order_identity_matches(WC_Order $order, string $identity): bool {
        $identity = trim($identity);
        if ($identity === '') {
            return false;
        }

        $check = strtolower($identity);
        $email = strtolower(trim((string) $order->get_billing_email()));
        if ($email !== '' && $check === $email) {
            return true;
        }

        if (self::phone_matches($identity, (string) $order->get_billing_phone())) {
            return true;
        }

        return false;
    }

    private static function phone_matches(string $candidate, string $stored): bool {
        $candidate_digits = self::phone_digits($candidate);
        $stored_digits = self::phone_digits($stored);
        if (strlen($candidate_digits) < 9 || strlen($stored_digits) < 9) {
            return false;
        }

        $common_suffix = 0;
        for ($i = strlen($candidate_digits) - 1, $j = strlen($stored_digits) - 1; $i >= 0 && $j >= 0; $i--, $j--) {
            if ($candidate_digits[$i] !== $stored_digits[$j]) {
                break;
            }
            $common_suffix++;
        }

        return $common_suffix >= 9;
    }

    private static function phone_digits(string $phone): string {
        return (string) preg_replace('/\D+/', '', $phone);
    }

    private static function render_tracking_timeline(WC_Order $order): void {
        $current_index = self::tracking_stage_index($order->get_status());

        echo '<ol class="vd-timeline">';
        foreach (self::TRACKING_STAGES as $index => $stage) {
            $classes = ['vd-timeline__step'];
            if ($current_index !== null) {
                if ($index < $current_index) {
                    $classes[] = 'is-done';
                } elseif ($index === $current_index) {
                    $classes[] = 'is-current';
                }
            }
            echo '<li class="' . esc_attr(implode(' ', $classes)) . '">' . esc_html($stage['label']) . '</li>';
        }
        echo '</ol>';
    }

    private static function tracking_stage_index(string $status): ?int {
        foreach (self::TRACKING_STAGES as $index => $stage) {
            if (in_array($status, $stage['statuses'], true)) {
                return $index;
            }
        }
        return null;
    }

    private static function public_order_meta_label(string $key): string {
        $labels = [
            '_veedy_customer_status_note' => 'Catatan Status',
            '_veedy_supplier_tracking_courier' => 'Kurir Supplier',
            '_veedy_supplier_tracking_number' => 'Resi Supplier',
            '_veedy_supplier_tracking_url' => 'Link Tracking Supplier',
            '_veedy_domestic_courier' => 'Kurir Lokal',
            '_veedy_domestic_tracking_number' => 'Resi Lokal',
            '_veedy_domestic_tracking_url' => 'Link Tracking Lokal',
        ];
        return $labels[$key] ?? $key;
    }

    public static function gateway_title(string $title, string $id): string {
        return $id === 'bacs' ? 'Transfer Bank Manual' : $title;
    }
}

Veedy_Jastip_Core::init();
