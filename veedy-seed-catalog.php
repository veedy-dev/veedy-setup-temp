<?php
/**
 * Veedy Store catalog seeder (GoRetroid products).
 *
 * Run:      wp eval-file setup/veedy-seed-catalog.php
 * Dry run:  wp eval-file setup/veedy-seed-catalog.php dry
 *           (or set env/constant VEEDY_SEED_DRY_RUN)
 *
 * Idempotent: products keyed by slug (existing products are updated, not
 * duplicated). Images are sideloaded once, deduped via attachment meta
 * `_veedy_source_image_url`. Data researched 2026-07-03 from goretroid.com.
 * Pricing: USD x 16300 x 1.12, rounded UP to nearest Rp 50.000.
 */

if (!defined('ABSPATH')) {
    echo "Run via: wp eval-file setup/veedy-seed-catalog.php [dry]\n";
    exit(1);
}

$GLOBALS['veedy_seed_cli_args'] = array_merge(
    isset($args) && is_array($args) ? $args : array(),
    isset($argv) && is_array($argv) ? $argv : array()
);

function veedy_seed_log(string $msg): void {
    echo $msg . "\n";
}

function veedy_seed_is_dry(): bool {
    if (defined('VEEDY_SEED_DRY_RUN') && VEEDY_SEED_DRY_RUN) {
        return true;
    }
    $env = getenv('VEEDY_SEED_DRY_RUN');
    if ($env !== false && $env !== '' && $env !== '0') {
        return true;
    }
    return in_array('dry', (array) $GLOBALS['veedy_seed_cli_args'], true);
}

/** Ensure a product_cat term exists; returns term_id (0 in dry-run if missing). */
function veedy_seed_term(string $slug, string $name, bool $dry): int {
    $term = get_term_by('slug', $slug, 'product_cat');
    if (!$term) {
        $term = get_term_by('name', $name, 'product_cat');
    }
    if ($term) {
        return (int) $term->term_id;
    }
    if ($dry) {
        veedy_seed_log("[dry] would create category: $name ($slug)");
        return 0;
    }
    $r = wp_insert_term($name, 'product_cat', array('slug' => $slug));
    if (is_wp_error($r)) {
        veedy_seed_log("category ERROR $name: " . $r->get_error_message());
        return 0;
    }
    veedy_seed_log("category created: $name (" . $r['term_id'] . ')');
    return (int) $r['term_id'];
}

/** Find an already-sideloaded attachment for a source URL. */
function veedy_seed_find_attachment(string $url): int {
    $ids = get_posts(array(
        'post_type' => 'attachment',
        'post_status' => 'any',
        'numberposts' => 1,
        'fields' => 'ids',
        'meta_key' => '_veedy_source_image_url',
        'meta_value' => $url,
    ));
    return $ids ? (int) $ids[0] : 0;
}

/** Sideload one image URL (deduped); returns attachment ID or 0. */
function veedy_seed_sideload(string $url, int $product_id, string $desc, bool $dry): int {
    $existing = veedy_seed_find_attachment($url);
    if ($existing) {
        veedy_seed_log("  image reused (attachment $existing): $url");
        return $existing;
    }
    if ($dry) {
        veedy_seed_log("  [dry] would sideload image: $url");
        return 0;
    }
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $att_id = media_sideload_image($url, $product_id, $desc, 'id');
    if (is_wp_error($att_id)) {
        veedy_seed_log('  image ERROR: ' . $att_id->get_error_message() . " ($url)");
        return 0;
    }
    update_post_meta((int) $att_id, '_veedy_source_image_url', $url);
    veedy_seed_log("  image sideloaded (attachment $att_id): $url");
    return (int) $att_id;
}

/** Featured + gallery from URL list. Keeps existing images if a thumbnail is set. */
function veedy_seed_apply_images(int $product_id, array $urls, string $name, bool $dry): void {
    if ($product_id && has_post_thumbnail($product_id)) {
        veedy_seed_log('  images: product already has a featured image, keeping as-is');
        return;
    }
    $att_ids = array();
    foreach ($urls as $url) {
        $id = veedy_seed_sideload($url, $product_id, $name, $dry);
        if ($id) {
            $att_ids[] = $id;
        }
    }
    if ($dry || !$att_ids) {
        return;
    }
    set_post_thumbnail($product_id, $att_ids[0]);
    update_post_meta($product_id, '_product_image_gallery', implode(',', array_slice($att_ids, 1)));
    veedy_seed_log('  images set: featured=' . $att_ids[0] . ' gallery=' . implode(',', array_slice($att_ids, 1)));
}

/** Build WC_Product_Attribute list from ['Color' => [...], 'Model' => [...]]. */
function veedy_seed_attributes(array $defs): array {
    $out = array();
    $pos = 0;
    foreach ($defs as $name => $options) {
        $attr = new WC_Product_Attribute();
        $attr->set_name($name);
        $attr->set_options($options);
        $attr->set_position($pos++);
        $attr->set_visible(true);
        $attr->set_variation(true);
        $out[] = $attr;
    }
    return $out;
}

function veedy_seed_variation_signature(array $attrs): string {
    $norm = array();
    foreach ($attrs as $k => $v) {
        $norm[strtolower((string) $k)] = strtolower(trim((string) $v));
    }
    ksort($norm);
    $parts = array();
    foreach ($norm as $k => $v) {
        $parts[] = "$k=$v";
    }
    return implode('|', $parts);
}

/** Create/update variations to match $variations: [['attrs' => [...], 'price' => int], ...]. */
function veedy_seed_sync_variations(int $product_id, array $variations, bool $dry): void {
    $existing = array();
    $defined = array();
    $parent = wc_get_product($product_id);
    if ($parent && $parent->is_type('variable')) {
        foreach ($parent->get_children() as $child_id) {
            $child = wc_get_product($child_id);
            if ($child) {
                $sig = veedy_seed_variation_signature($child->get_attributes());
                $existing[$sig][] = $child;
            }
        }
    }
    foreach ($variations as $v) {
        $sig = veedy_seed_variation_signature($v['attrs']);
        $defined[$sig] = true;
        $label = implode(' / ', $v['attrs']);
        $price = (string) $v['price'];
        if (!empty($existing[$sig])) {
            $child = array_shift($existing[$sig]);
            if ($child->get_regular_price() === $price) {
                veedy_seed_log("  variation ok (ID " . $child->get_id() . "): $label = Rp$price");
                continue;
            }
            if ($dry) {
                veedy_seed_log("  [dry] would update variation (ID " . $child->get_id() . "): $label -> Rp$price");
                continue;
            }
            $child->set_regular_price($price);
            $child->set_manage_stock(false);
            $child->set_stock_status('instock');
            $child->save();
            veedy_seed_log("  variation updated (ID " . $child->get_id() . "): $label = Rp$price");
            continue;
        }
        if ($dry) {
            veedy_seed_log("  [dry] would create variation: $label = Rp$price");
            continue;
        }
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($product_id);
        $variation->set_attributes($v['attrs']);
        $variation->set_regular_price($price);
        $variation->set_manage_stock(false);
        $variation->set_stock_status('instock');
        $vid = $variation->save();
        veedy_seed_log("  variation created (ID $vid): $label = Rp$price");
    }
    foreach ($existing as $sig => $obsolete_variations) {
        if (isset($defined[$sig])) {
            continue;
        }
        foreach ($obsolete_variations as $variation) {
            $label = implode(' / ', $variation->get_attributes());
            if ($dry) {
                veedy_seed_log("  [dry] would delete obsolete variation (ID " . $variation->get_id() . "): $label");
                continue;
            }
            $id = $variation->get_id();
            $variation->delete(true);
            veedy_seed_log("  variation deleted (ID $id): $label");
        }
    }
}

/** Force product type term when converting an existing product. */
function veedy_seed_ensure_type(int $product_id, string $type): void {
    $current = WC_Product_Factory::get_product_type($product_id);
    if ($current !== $type) {
        wp_set_object_terms($product_id, $type, 'product_type');
        veedy_seed_log("  product type changed: $current -> $type");
    }
}

function veedy_seed_meta_for(array $def): array {
    $order_type = $def['order_type'];
    $arrival = $order_type === 'Ready Stock'
        ? 'Siap kirim 1-2 hari kerja'
        : '2-8 minggu setelah pembelian supplier';
    $meta = array(
        '_veedy_order_type' => $order_type,
        '_veedy_source_store' => 'GoRetroid Official Store',
        '_veedy_source_url' => $def['source_url'],
        '_veedy_estimated_arrival' => $arrival,
        '_veedy_supplier_availability' => 'Stok, warna, harga, dan bonus supplier dapat berubah.',
        '_veedy_warranty_note' => 'Garansi supplier saja kecuali tertulis lain. Garansi resmi lokal umumnya tidak berlaku untuk barang impor.',
        '_veedy_cancellation_note' => 'Sebelum pembelian ke supplier: pembatalan biasanya bisa dengan refund penuh. Setelah pembelian ke supplier: mengikuti kebijakan supplier.',
        '_veedy_last_price_checked' => '2026-07-03',
        '_veedy_price_includes' => 'Harga produk supplier + fee jasa titip.',
        '_veedy_price_excludes' => 'Ongkir internasional, bea masuk/pajak impor, dan ongkir lokal (dikonfirmasi saat proses pesanan).',
    );
    if (!empty($def['badge'])) {
        $meta['_veedy_badge'] = $def['badge'];
    }
    return $meta;
}

/** Create or update a single product definition. Returns 'created'|'updated'|'skipped'. */
function veedy_seed_upsert(array $def, array $cat_ids, bool $dry): string {
    $slug = $def['slug'];
    $existing_post = get_page_by_path($slug, OBJECT, 'product');
    $is_update = (bool) $existing_post;
    $pid = $is_update ? (int) $existing_post->ID : 0;
    $is_variable = $def['type'] === 'variable';

    veedy_seed_log(($is_update ? "UPDATE" : "CREATE") . ": {$def['name']} [$slug]" . ($is_update ? " (ID $pid)" : ''));

    if ($dry) {
        veedy_seed_log('  [dry] would set: type=' . $def['type'] . ', order_type=' . $def['order_type']
            . ', categories=' . implode(',', $def['categories'])
            . (isset($def['price']) ? ', price=Rp' . $def['price'] : ''));
        if ($is_variable) {
            veedy_seed_sync_variations($pid, $def['variations'], true);
        }
        veedy_seed_apply_images($pid, $def['images'], $def['name'], true);
        return $is_update ? 'updated' : 'created';
    }

    if ($is_update) {
        veedy_seed_ensure_type($pid, $def['type']);
        $product = wc_get_product($pid);
        if (!$product) {
            veedy_seed_log("  ERROR: could not load product $pid, skipping");
            return 'skipped';
        }
    } else {
        $product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
        $product->set_slug($slug);
    }

    $product->set_name($def['name']);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_short_description($def['short_description']);
    if (!empty($def['description'])) {
        $product->set_description($def['description']);
    }

    $term_ids = array();
    foreach ($def['categories'] as $key) {
        if (!empty($cat_ids[$key])) {
            $term_ids[] = $cat_ids[$key];
        }
    }
    $product->set_category_ids($term_ids);

    if ($is_variable) {
        $product->set_attributes(veedy_seed_attributes($def['attributes']));
        if (!empty($def['default_attributes'])) {
            $product->set_default_attributes($def['default_attributes']);
        }
    } else {
        $product->set_regular_price(isset($def['price']) ? (string) $def['price'] : '');
        $product->set_manage_stock(false);
        $product->set_stock_status('instock');
    }

    $pid = $product->save();
    veedy_seed_log('  saved product ID ' . $pid);

    foreach (veedy_seed_meta_for($def) as $key => $value) {
        update_post_meta($pid, $key, $value);
    }
    veedy_seed_log('  jastip meta set (order_type=' . $def['order_type'] . ')');

    if ($is_variable) {
        veedy_seed_sync_variations($pid, $def['variations'], false);
    }
    veedy_seed_apply_images($pid, $def['images'], $def['name'], false);

    return $is_update ? 'updated' : 'created';
}

function veedy_seed_products(): array {
    $short_suffix = ' Harga estimasi, dikonfirmasi saat quote/checkout final.';
    return array(
        // ---- Handhelds (variable, Open PO) ----
        array(
            'slug' => 'retroid-pocket-5-handheld',
            'name' => 'Retroid Pocket 5 Handheld',
            'type' => 'variable',
            'order_type' => 'Open PO',
            'categories' => array('gaming-handhelds', 'open-po', 'jasa-titip'),
            'source_url' => 'https://www.goretroid.com/products/retroid-pocket-5-handheld',
            'short_description' => 'Handheld Android layar AMOLED 5,5 inci dengan chipset Snapdragon 865 untuk emulasi hingga kelas PS2/GameCube.' . $short_suffix,
            'attributes' => array('Color' => array('GC', '16Bit', 'Black', 'Yellow', 'Turquoise', 'White')),
            'default_attributes' => array('color' => 'Black'),
            'variations' => array(
                array('attrs' => array('color' => 'GC'), 'price' => 3650000),
                array('attrs' => array('color' => '16Bit'), 'price' => 3650000),
                array('attrs' => array('color' => 'Black'), 'price' => 3650000),
                array('attrs' => array('color' => 'Yellow'), 'price' => 3650000),
                array('attrs' => array('color' => 'Turquoise'), 'price' => 3650000),
                array('attrs' => array('color' => 'White'), 'price' => 3650000),
            ),
            'images' => array(
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/1_b253d353-a734-4102-afdf-1c531cdcbc12.jpg?v=1725602614',
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/2_7adde3bf-360d-40c8-982e-57512570d0bd.jpg?v=1725602644',
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/14_5350e89d-6168-4813-98df-4971946535b2.jpg?v=1725888745',
            ),
        ),
        array(
            'slug' => 'retroid-pocket-flip-2',
            'name' => 'Retroid Pocket Flip 2 Handheld',
            'type' => 'variable',
            'order_type' => 'Open PO',
            'categories' => array('gaming-handhelds', 'open-po', 'jasa-titip'),
            'source_url' => 'https://www.goretroid.com/products/retroid-pocket-flip-2',
            'short_description' => 'Handheld lipat gaya clamshell dengan pilihan chipset Snapdragon 865 atau Dimensity 1100.' . $short_suffix,
            'attributes' => array(
                'Model' => array('SD865', 'D1100'),
                'Color' => array('Black', 'GC', 'Ice Blue', '16Bit US'),
            ),
            'default_attributes' => array('model' => 'SD865', 'color' => 'Black'),
            'variations' => array(
                array('attrs' => array('model' => 'SD865', 'color' => 'Black'), 'price' => 3850000),
                array('attrs' => array('model' => 'SD865', 'color' => 'GC'), 'price' => 3850000),
                array('attrs' => array('model' => 'SD865', 'color' => 'Ice Blue'), 'price' => 3850000),
                array('attrs' => array('model' => 'SD865', 'color' => '16Bit US'), 'price' => 3850000),
                array('attrs' => array('model' => 'D1100', 'color' => 'Black'), 'price' => 3300000),
                array('attrs' => array('model' => 'D1100', 'color' => 'GC'), 'price' => 3300000),
                array('attrs' => array('model' => 'D1100', 'color' => 'Ice Blue'), 'price' => 3300000),
                array('attrs' => array('model' => 'D1100', 'color' => '16Bit US'), 'price' => 3300000),
            ),
            'images' => array(
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/2_7f320e76-03a9-4ba2-8036-a099a7089245.jpg?v=1741931343',
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/4_ba0d5be9-d80f-46f4-aee1-6c5c019d7363.jpg?v=1741931343',
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/5_ca5a42ca-8b82-43e2-92ae-a181f8132102.jpg?v=1741931343',
            ),
        ),
        array(
            'slug' => 'retroid-pocket-classic',
            'name' => 'Retroid Pocket Classic Handheld',
            'type' => 'variable',
            'order_type' => 'Open PO',
            'categories' => array('gaming-handhelds', 'open-po', 'jasa-titip'),
            'source_url' => 'https://www.goretroid.com/products/retroid-pocket-classic',
            'short_description' => 'Handheld vertikal bergaya retro dengan layar AMOLED 3,92 inci dan chipset Snapdragon G1 Gen 2.' . $short_suffix,
            'attributes' => array(
                'Model' => array('6+128GB', '4+64GB'),
                'Color' => array('Classic 6 SG', 'Classic 6', 'Retro', 'Berry', 'Teal', 'Kiwi', 'PKM Yellow', 'Atomic Purple'),
            ),
            'default_attributes' => array('model' => '6+128GB', 'color' => 'Classic 6'),
            'variations' => array(
                array('attrs' => array('model' => '6+128GB', 'color' => 'Classic 6 SG'), 'price' => 2750000),
                array('attrs' => array('model' => '6+128GB', 'color' => 'Classic 6'), 'price' => 2750000),
                array('attrs' => array('model' => '6+128GB', 'color' => 'Retro'), 'price' => 2750000),
                array('attrs' => array('model' => '6+128GB', 'color' => 'Berry'), 'price' => 2750000),
                array('attrs' => array('model' => '6+128GB', 'color' => 'Teal'), 'price' => 2750000),
                array('attrs' => array('model' => '6+128GB', 'color' => 'Kiwi'), 'price' => 2750000),
                array('attrs' => array('model' => '6+128GB', 'color' => 'PKM Yellow'), 'price' => 2750000),
                array('attrs' => array('model' => '6+128GB', 'color' => 'Atomic Purple'), 'price' => 2750000),
                array('attrs' => array('model' => '4+64GB', 'color' => 'Classic 6'), 'price' => 1850000),
                array('attrs' => array('model' => '4+64GB', 'color' => 'Retro'), 'price' => 1850000),
            ),
            'images' => array(
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/1_f433ef8a-8d86-4aac-9210-fade2c8adba3.jpg?v=1742814661',
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/2_2df46d6f-2a7d-493f-a19d-dc1af6e1a7a0.jpg?v=1742814661',
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/3_f5a7fb55-e311-4eaf-8ca9-dde480103176.jpg?v=1742814661',
            ),
        ),
        array(
            'slug' => 'retroid-pocket-6-handheld',
            'name' => 'Retroid Pocket 6 Handheld',
            'type' => 'variable',
            'order_type' => 'Open PO',
            'categories' => array('gaming-handhelds', 'open-po', 'jasa-titip'),
            'source_url' => 'https://www.goretroid.com/products/retroid-pocket-6-handheld',
            'short_description' => 'Handheld flagship terbaru Retroid dengan Snapdragon 8 Gen 2, layar 120 Hz, dan pilihan layout D-pad atau stick di atas.' . $short_suffix,
            'attributes' => array(
                'Color' => array('Silver', '16Bit', 'Black', 'Orange', 'Light Purple'),
                'Model' => array('8G_DPAD_TOP', '8G_STICK_TOP', '12G_STICK_TOP'),
            ),
            'default_attributes' => array('color' => 'Black', 'model' => '8G_STICK_TOP'),
            'variations' => array(
                array('attrs' => array('color' => 'Silver', 'model' => '8G_DPAD_TOP'), 'price' => 4500000),
                array('attrs' => array('color' => 'Silver', 'model' => '8G_STICK_TOP'), 'price' => 4500000),
                array('attrs' => array('color' => 'Silver', 'model' => '12G_STICK_TOP'), 'price' => 5100000),
                array('attrs' => array('color' => '16Bit', 'model' => '8G_DPAD_TOP'), 'price' => 4500000),
                array('attrs' => array('color' => '16Bit', 'model' => '8G_STICK_TOP'), 'price' => 4500000),
                array('attrs' => array('color' => '16Bit', 'model' => '12G_STICK_TOP'), 'price' => 5100000),
                array('attrs' => array('color' => 'Black', 'model' => '8G_DPAD_TOP'), 'price' => 4500000),
                array('attrs' => array('color' => 'Black', 'model' => '8G_STICK_TOP'), 'price' => 4500000),
                array('attrs' => array('color' => 'Black', 'model' => '12G_STICK_TOP'), 'price' => 5100000),
                array('attrs' => array('color' => 'Orange', 'model' => '8G_STICK_TOP'), 'price' => 4500000),
                array('attrs' => array('color' => 'Light Purple', 'model' => '8G_STICK_TOP'), 'price' => 4500000),
            ),
            'images' => array(
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/bbe4c9ab94f9be237637709704cd2056.jpg?v=1761899314',
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/5_519f4e46-c71f-409d-8e79-18193cbf4f1c.jpg?v=1762132051',
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/3_f06c4e94-eccc-4883-ad75-983b0a08ffd3.jpg?v=1762132051',
            ),
        ),
        // ---- FIX existing product ID 55 (matched by slug) ----
        array(
            'slug' => 'retroid-pocket-nova-handheld-open-po',
            'name' => 'Retroid Pocket Nova Handheld',
            'type' => 'variable',
            'order_type' => 'Open PO',
            'categories' => array('gaming-handhelds', 'open-po', 'jasa-titip'),
            'source_url' => 'https://www.goretroid.com/products/retroid-pocket-nova-handheld',
            'short_description' => 'Handheld horizontal dengan Snapdragon 865, layar 5 inci 16:9, dan active cooling.' . $short_suffix,
            'attributes' => array(
                'Color' => array('GC', '16Bit', 'Black', 'Crystal', 'Ice Blue', 'Clear Purple', 'Watermelon'),
                'Model' => array('8+128GB', '12+128GB'),
            ),
            'default_attributes' => array('color' => 'Black', 'model' => '8+128GB'),
            'variations' => array(
                array('attrs' => array('color' => 'GC', 'model' => '8+128GB'), 'price' => 4200000),
                array('attrs' => array('color' => 'GC', 'model' => '12+128GB'), 'price' => 4950000),
                array('attrs' => array('color' => '16Bit', 'model' => '8+128GB'), 'price' => 4200000),
                array('attrs' => array('color' => '16Bit', 'model' => '12+128GB'), 'price' => 4950000),
                array('attrs' => array('color' => 'Black', 'model' => '8+128GB'), 'price' => 4200000),
                array('attrs' => array('color' => 'Black', 'model' => '12+128GB'), 'price' => 4950000),
                array('attrs' => array('color' => 'Crystal', 'model' => '8+128GB'), 'price' => 4300000),
                array('attrs' => array('color' => 'Crystal', 'model' => '12+128GB'), 'price' => 5050000),
                array('attrs' => array('color' => 'Ice Blue', 'model' => '8+128GB'), 'price' => 4300000),
                array('attrs' => array('color' => 'Ice Blue', 'model' => '12+128GB'), 'price' => 5050000),
                array('attrs' => array('color' => 'Clear Purple', 'model' => '8+128GB'), 'price' => 4300000),
                array('attrs' => array('color' => 'Clear Purple', 'model' => '12+128GB'), 'price' => 5050000),
                array('attrs' => array('color' => 'Watermelon', 'model' => '8+128GB'), 'price' => 4300000),
                array('attrs' => array('color' => 'Watermelon', 'model' => '12+128GB'), 'price' => 5050000),
            ),
            // Only used if product 55 somehow has no featured image (existing images are kept).
            'images' => array(
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/2_e1c341c8-3804-4151-bbaa-c4ba9c063f9b.jpg?v=1782382557',
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/1_0ab3b9bf-beae-4d9e-abdc-7c90a8b1a799.jpg?v=1782382557',
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/4_36c914da-b58f-426c-8054-52c25f129c5b.jpg?v=1782382557',
            ),
        ),
        // ---- Accessories (simple) ----
        array(
            'slug' => 'retroid-official-dock',
            'name' => 'Retroid Official Dock',
            'type' => 'simple',
            'order_type' => 'Open PO',
            'price' => 550000,
            'categories' => array('accessories', 'open-po'),
            'source_url' => 'https://www.goretroid.com/products/retroid-official-dock',
            'short_description' => 'Dock resmi Retroid dengan output HDMI 4K@60, LAN gigabit, dan port USB untuk main di TV.' . $short_suffix,
            'images' => array(
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/MC-C1241A71.jpg?v=1721993372',
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/4e657a200bce6adbce024b721e65e8f1_dbc8b91a-aa35-4247-9f64-78c75d59382f.jpg?v=1722045846',
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/8fddfc966ccb5d5178bd49d3de715386.jpg?v=1721993391',
            ),
        ),
        array(
            'slug' => 'rp5-carrying-case',
            'name' => 'Retroid Pocket 5 Carrying Case',
            'type' => 'simple',
            'order_type' => 'Ready Stock',
            'price' => 200000,
            'categories' => array('accessories', 'ready-stock'),
            'source_url' => 'https://www.goretroid.com/products/rp5-carrying-case',
            'short_description' => 'Tas hardcase resmi untuk Retroid Pocket 5 agar aman dibawa bepergian.' . $short_suffix,
            'images' => array(
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/rp5_6c7540a3-d4b8-4759-8bfe-a139d1baa914.jpg?v=1725624743',
            ),
        ),
        array(
            'slug' => 'rp5-screen-protector',
            'name' => 'Retroid Pocket 5 Screen Protector',
            'type' => 'simple',
            'order_type' => 'Ready Stock',
            'price' => 150000,
            'categories' => array('accessories', 'ready-stock'),
            'source_url' => 'https://www.goretroid.com/products/rp5-screen-protector',
            'short_description' => 'Tempered glass resmi untuk layar AMOLED Retroid Pocket 5.' . $short_suffix,
            'images' => array(
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/7cf09a3b50e8e64d88b18b54fb5ea883.jpg?v=1735176581',
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/3afefdef53e57cd68a16953c3dd83517.jpg?v=1735176602',
            ),
        ),
        array(
            'slug' => 'retroid-official-grip-for-rp5',
            'name' => 'Retroid Official Grip for RP5',
            'type' => 'simple',
            'order_type' => 'Open PO',
            'price' => 300000,
            'categories' => array('accessories', 'open-po'),
            'source_url' => 'https://www.goretroid.com/products/retroid-official-grip-for-rp5',
            'short_description' => 'Grip resmi Retroid Pocket 5 untuk genggaman lebih nyaman, pilihan warna dikonfirmasi saat quote.' . $short_suffix,
            'images' => array(
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/black2.jpg?v=1747130586',
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/2_6e222749-fae3-430e-a958-ff1fdfed0968.jpg?v=1747712853',
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/gc2.jpg?v=1747712853',
            ),
        ),
        array(
            'slug' => 'retroid-pocket-flip2-carrying-case',
            'name' => 'Retroid Pocket Flip 2 Carrying Case',
            'type' => 'simple',
            'order_type' => 'Open PO',
            'price' => 200000,
            'categories' => array('accessories', 'open-po'),
            'source_url' => 'https://www.goretroid.com/products/retroid-pocket-flip2-carrying-case',
            'short_description' => 'Tas hardcase resmi yang pas untuk bodi clamshell Retroid Pocket Flip 2.' . $short_suffix,
            'images' => array(
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/be8738f86f56d3fdb4b5ee44f91afc6.jpg?v=1742010667',
            ),
        ),
        array(
            'slug' => 'retroid-pocket-classic-screen-protector',
            'name' => 'Retroid Pocket Classic Screen Protector',
            'type' => 'simple',
            'order_type' => 'Ready Stock',
            'price' => 100000,
            'categories' => array('accessories', 'ready-stock'),
            'source_url' => 'https://www.goretroid.com/products/retroid-pocket-classic-screen-protector',
            'short_description' => 'Tempered glass resmi untuk layar 3,92 inci Retroid Pocket Classic.' . $short_suffix,
            'images' => array(
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/4c532d98338b173ea94c53de35cd2c83.jpg?v=1741946052',
            ),
        ),
        array(
            'slug' => '35w-charger',
            'name' => 'Retroid Official 35W Charger',
            'type' => 'simple',
            'order_type' => 'Request Only',
            // No price: request-only product (sold out at supplier at research time).
            'badge' => 'request',
            'categories' => array('accessories', 'request-only'),
            'source_url' => 'https://www.goretroid.com/products/35w-charger',
            'short_description' => 'Charger resmi Retroid 35W, saat ini habis di supplier sehingga hanya bisa via request.' . $short_suffix,
            'images' => array(
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/f61005e0dd4ae8c1efd06599ac8dff0b.jpg?v=1725626094',
            ),
        ),
        array(
            'slug' => 'retroid-pocket-bluetooth-earbuds',
            'name' => 'Retroid Pocket Bluetooth Earbuds',
            'type' => 'simple',
            'order_type' => 'Open PO',
            'price' => 300000,
            'categories' => array('accessories', 'open-po'),
            'source_url' => 'https://www.goretroid.com/products/retroid-pocket-bluetooth-earbuds',
            'short_description' => 'Earbuds Bluetooth resmi Retroid dengan mode low-latency untuk gaming, warna Black/White dikonfirmasi saat quote.' . $short_suffix,
            'images' => array(
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/1_c9c67e21-300b-44c4-b9e6-088c66b2ff62.jpg?v=1736318128',
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/3_ea95372d-57ce-4cb3-aa33-e0ba1e16a354.jpg?v=1736318128',
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/2_260bb2cd-a849-4a7c-ac30-0e5bdde159de.jpg?v=1736318208',
            ),
        ),
        array(
            'slug' => 'retroid-pocket-nova-carrying-case',
            'name' => 'Retroid Pocket Nova Carrying Case',
            'type' => 'simple',
            'order_type' => 'Open PO',
            'price' => 200000,
            'badge' => 'limited',
            'categories' => array('accessories', 'open-po'),
            'source_url' => 'https://www.goretroid.com/products/retroid-pocket-nova-carrying-case',
            'short_description' => 'Tas hardcase resmi untuk Retroid Pocket Nova, stok supplier terbatas.' . $short_suffix,
            'images' => array(
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/1_4df60e06-b216-4164-84c0-ae0af3af28d8.jpg?v=1782458218',
                'https://cdn.shopify.com/s/files/1/0321/7516/1479/files/2_1ce055a5-9801-4bef-b205-8f7fd6b5e14f.jpg?v=1782458238',
            ),
        ),
    );
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

if (!class_exists('WooCommerce')) {
    veedy_seed_log('VEEDY_SEED_ERROR: WooCommerce is not active.');
    return;
}

$veedy_dry = veedy_seed_is_dry();
veedy_seed_log('VEEDY SEED CATALOG ' . ($veedy_dry ? '(DRY RUN - no writes)' : '(live)'));

$veedy_cats = array(
    'gaming-handhelds' => 'Gaming Handhelds',
    'accessories' => 'Accessories',
    'open-po' => 'Open PO',
    'ready-stock' => 'Ready Stock',
    'jasa-titip' => 'Jasa Titip',
    'request-only' => 'Request Only',
);
$veedy_cat_ids = array();
foreach ($veedy_cats as $slug => $name) {
    $veedy_cat_ids[$slug] = veedy_seed_term($slug, $name, $veedy_dry);
}

$veedy_counts = array('created' => 0, 'updated' => 0, 'skipped' => 0);
$veedy_variable_ids = array();

foreach (veedy_seed_products() as $def) {
    try {
        $result = veedy_seed_upsert($def, $veedy_cat_ids, $veedy_dry);
        $veedy_counts[$result]++;
        if (!$veedy_dry && $def['type'] === 'variable') {
            $post = get_page_by_path($def['slug'], OBJECT, 'product');
            if ($post) {
                $veedy_variable_ids[] = (int) $post->ID;
            }
        }
    } catch (Throwable $e) {
        $veedy_counts['skipped']++;
        veedy_seed_log('  ERROR (' . $def['slug'] . '): ' . $e->getMessage());
    }
}

if (!$veedy_dry) {
    foreach ($veedy_variable_ids as $vid) {
        WC_Product_Variable::sync($vid);
        wc_delete_product_transients($vid);
        veedy_seed_log("synced variable product $vid");
    }
    wc_delete_product_transients();
    veedy_seed_log('product transients cleared');
} else {
    veedy_seed_log('[dry] would sync variable products + clear transients');
}

veedy_seed_log(sprintf('VEEDY_SEED_DONE created=%d updated=%d skipped=%d', $veedy_counts['created'], $veedy_counts['updated'], $veedy_counts['skipped']));
