<?php
if (!defined('ABSPATH')) {
    exit;
}

@set_time_limit(300);

function vm_log($message) {
    echo '[veedy-mvp] ' . $message . "\n";
    if (function_exists('flush')) { flush(); }
}

function vm_page($slug, $title, $content) {
    $page = get_page_by_path($slug, OBJECT, 'page');
    if ($page) {
        wp_update_post([
            'ID' => $page->ID,
            'post_title' => $title,
            'post_name' => $slug,
            'post_content' => $content,
            'post_status' => 'publish',
        ]);
        vm_log("updated page {$slug} ({$page->ID})");
        return (int) $page->ID;
    }
    $id = wp_insert_post([
        'post_type' => 'page',
        'post_name' => $slug,
        'post_title' => $title,
        'post_content' => $content,
        'post_status' => 'publish',
    ]);
    vm_log("created page {$slug} ({$id})");
    return (int) $id;
}

function vm_term($name, $taxonomy = 'product_cat') {
    $term = get_term_by('name', $name, $taxonomy);
    if ($term) {
        return (int) $term->term_id;
    }
    $created = wp_insert_term($name, $taxonomy);
    if (is_wp_error($created)) {
        vm_log('term error ' . $name . ': ' . $created->get_error_message());
        return 0;
    }
    vm_log('created category ' . $name);
    return (int) $created['term_id'];
}

function vm_fluent_form($title, array $fields, $submit_text, $confirmation) {
    global $wpdb;
    $forms_table = $wpdb->prefix . 'fluentform_forms';
    $meta_table = $wpdb->prefix . 'fluentform_form_meta';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $forms_table)) !== $forms_table) {
        vm_log('Fluent Forms tables not found; skipped form ' . $title);
        return 0;
    }

    $existing_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$forms_table} WHERE title = %s LIMIT 1", $title));
    $form_fields = [
        'fields' => $fields,
        'submitButton' => [
            'type' => 'button',
            'element' => 'button',
            'attributes' => ['type' => 'submit', 'class' => 'ff-btn ff-btn-submit ff-btn-md'],
            'settings' => ['align' => 'left', 'button_style' => 'default', 'container_class' => '', 'button_size' => 'md'],
            'editor_options' => ['title' => 'Submit Button'],
            'label' => $submit_text,
        ],
    ];
    $appearance = [
        'layout' => 'default',
        'labelPlacement' => 'top',
        'asteriskPlacement' => 'asterisk-right',
        'helpMessagePlacement' => 'with_label',
        'cssClassName' => 'veedy-form',
    ];

    $data = [
        'title' => $title,
        'status' => 'published',
        'appearance_settings' => wp_json_encode($appearance),
        'form_fields' => wp_json_encode($form_fields),
        'has_payment' => 0,
        'type' => 'form',
        'conditions' => wp_json_encode([]),
        'created_by' => get_current_user_id() ?: 1,
        'updated_at' => current_time('mysql'),
    ];
    if ($existing_id) {
        $wpdb->update($forms_table, $data, ['id' => $existing_id]);
        $form_id = $existing_id;
        vm_log('updated Fluent Form ' . $title . ' (' . $form_id . ')');
    } else {
        $data['created_at'] = current_time('mysql');
        $wpdb->insert($forms_table, $data);
        $form_id = (int) $wpdb->insert_id;
        vm_log('created Fluent Form ' . $title . ' (' . $form_id . ')');
    }

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $meta_table)) === $meta_table) {
        $settings = [
            'redirectTo' => 'samePage',
            'messageToShow' => $confirmation,
            'customPage' => '',
            'samePageFormBehavior' => 'hide_form',
        ];
        $wpdb->delete($meta_table, ['form_id' => $form_id, 'meta_key' => 'formSettings']);
        $wpdb->insert($meta_table, [
            'form_id' => $form_id,
            'meta_key' => 'formSettings',
            'value' => wp_json_encode($settings),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
    }
    return $form_id;
}

function vm_field($element, $name, $label, $required = false, $extra = []) {
    $field = [
        'element' => $element,
        'attributes' => ['name' => $name, 'class' => '', 'value' => ''],
        'settings' => [
            'label' => $label,
            'admin_field_label' => '',
            'placeholder' => '',
            'container_class' => '',
            'validation_rules' => [
                'required' => ['value' => $required, 'message' => $label . ' wajib diisi.'],
            ],
        ],
        'editor_options' => ['title' => $label],
    ];
    return array_replace_recursive($field, $extra);
}

vm_log('start');

update_option('blogname', 'Veedy Store');
update_option('blogdescription', 'Jasa Titip Produk Luar Negeri');
update_option('timezone_string', 'Asia/Jakarta');
update_option('blog_public', 0);

if (function_exists('WC')) {
    update_option('woocommerce_default_country', 'ID:JK');
    update_option('woocommerce_currency', 'IDR');
    update_option('woocommerce_allowed_countries', 'specific');
    update_option('woocommerce_specific_allowed_countries', ['ID']);
    update_option('woocommerce_ship_to_countries', 'specific');
    update_option('woocommerce_specific_ship_to_countries', ['ID']);
    update_option('woocommerce_enable_guest_checkout', 'yes');
    update_option('woocommerce_enable_signup_and_login_from_checkout', 'yes');
    update_option('woocommerce_enable_myaccount_registration', 'yes');
    update_option('woocommerce_calc_taxes', 'no');
    update_option('woocommerce_bacs_settings', [
        'enabled' => 'yes',
        'title' => 'Transfer Bank Manual',
        'description' => 'Transfer sesuai total invoice. Cantumkan nomor pesanan pada berita transfer. Pesanan diproses setelah pembayaran diverifikasi.',
        'instructions' => 'Transfer sesuai total invoice. Cantumkan nomor pesanan pada berita transfer. Pesanan diproses setelah pembayaran diverifikasi.',
    ]);
    update_option('woocommerce_bacs_accounts', [[
        'account_name' => 'Muhammad Vidi Fadilakbar',
        'account_number' => '002220010720',
        'bank_name' => 'BANK BCA DIGITAL / BLU BCA',
        'sort_code' => '',
        'iban' => '',
        'bic' => '',
    ]]);
}
vm_log('settings updated');

foreach (['Gaming Handhelds', 'Accessories', 'Open PO', 'Ready Stock', 'Jasa Titip', 'Request Only'] as $category) {
    vm_term($category);
}

$request_fields = [
    vm_field('input_text', 'nama_lengkap', 'Nama lengkap', true),
    vm_field('input_text', 'discord', 'Username Discord', true),
    vm_field('input_email', 'email', 'Email', false),
    vm_field('input_url', 'link_produk', 'Link produk', true),
    vm_field('input_text', 'nama_produk', 'Nama produk', false),
    vm_field('input_text', 'varian', 'Varian yang diinginkan', false),
    vm_field('input_number', 'quantity', 'Quantity', true),
    vm_field('input_text', 'budget', 'Budget', false),
    vm_field('textarea', 'catatan', 'Catatan tambahan', false),
    vm_field('terms_and_condition', 'persetujuan', 'Persetujuan', true, ['settings' => ['tnc_html' => 'Saya paham bahwa harga dan ketersediaan produk akan dicek terlebih dahulu dan belum menjadi pesanan aktif sampai saya menerima quote dan melakukan pembayaran.']]),
];
$confirm_fields = [
    vm_field('input_text', 'order_number', 'Nomor pesanan', true),
    vm_field('input_text', 'name', 'Nama', true),
    vm_field('input_text', 'discord', 'Username Discord', true),
    vm_field('input_text', 'bank_sender_name', 'Nama pemilik rekening pengirim', true),
    vm_field('input_number', 'transfer_amount', 'Jumlah transfer', true),
    vm_field('input_date', 'transfer_date', 'Tanggal transfer', true),
    vm_field('input_file', 'payment_proof', 'Bukti transfer', true),
    vm_field('textarea', 'notes', 'Catatan', false),
];
$request_form_id = vm_fluent_form('Veedy Request Product', $request_fields, 'Kirim Request', 'Terima kasih. Request produk sudah masuk. Kami biasanya membalas dalam 1x24 jam kerja.');
$payment_form_id = vm_fluent_form('Veedy Payment Confirmation', $confirm_fields, 'Kirim Konfirmasi', 'Terima kasih. Konfirmasi pembayaran sudah diterima. Verifikasi biasanya membutuhkan 1x24 jam kerja.');

$home = <<<'HTML'
<main class="vd-home">
  <section class="vd-wrap vd-hero" aria-labelledby="vd-hero-title">
    <h1 id="vd-hero-title" class="vd-title">Jasa Titip Produk Luar Negeri, Lebih Rapi dan Terlacak</h1>
    <p class="vd-lede">Pilih produk dari katalog kami atau kirim link produk yang kamu inginkan. Kami bantu proses pembelian dari supplier luar negeri sampai pengiriman ke alamatmu di Indonesia.</p>
    <div class="vd-actions wp-block-buttons"><div class="wp-block-button"><a class="wp-block-button__link" href="/shop/">Belanja Sekarang</a></div><div class="wp-block-button is-style-outline"><a class="wp-block-button__link" href="/request-product/">Request Produk</a></div></div>
    <div class="vd-chip-row" aria-label="Kepercayaan layanan"><span class="vd-chip">Checkout jelas, tidak hanya lewat chat</span><span class="vd-chip">Update status pesanan</span><span class="vd-chip">Bisa request produk overseas</span><span class="vd-chip">Bukan official distributor kecuali tertulis</span></div>
  </section>
  <section class="vd-wrap vd-section"><div class="vd-section-head"><h2>Cara kerja singkat.</h2><p>Dari request sampai barang dikirim ke alamatmu, alurnya dibuat jelas sejak awal.</p></div><div class="vd-grid vd-steps"><article class="vd-card vd-step"><h3>Pilih produk atau kirim request</h3><p>Pilih dari katalog atau kirim link dari toko luar negeri.</p></article><article class="vd-card vd-step"><h3>Bayar pesanan</h3><p>Transfer manual sesuai invoice setelah detail dan estimasi disetujui.</p></article><article class="vd-card vd-step"><h3>Kami beli dari supplier</h3><p>Pesanan diproses setelah pembayaran diverifikasi.</p></article><article class="vd-card vd-step"><h3>Dikirim ke alamatmu</h3><p>Kami update proses sampai pengiriman lokal di Indonesia.</p></article></div><p><a href="/how-it-works/">Lihat alur lengkap</a></p></section>
  <section class="vd-wrap vd-section"><div class="vd-section-head"><h2>Kategori utama.</h2><p>Kurasi dibuat untuk pembeli yang ingin proses open PO dan jasa titip lebih rapi.</p></div><div class="vd-grid"><article class="vd-card vd-product-card"><h3>Gaming Handhelds</h3><p>Retroid, handheld Android, dan perangkat gaming portable pilihan.</p><a class="button vd-ghost" href="/product-category/gaming-handhelds/">Lihat kategori</a></article><article class="vd-card vd-product-card"><h3>Accessories</h3><p>Case, grip, tempered glass, dock, dan aksesori pendukung.</p><a class="button vd-ghost" href="/product-category/accessories/">Lihat kategori</a></article><article class="vd-card vd-product-card"><h3>Open PO</h3><p>Produk pre-order dengan estimasi dan catatan supplier.</p><a class="button vd-ghost" href="/product-category/open-po/">Lihat kategori</a></article><article class="vd-card vd-product-card"><h3>Ready Stock</h3><p>Produk yang tersedia lokal bila stok sudah ada.</p><a class="button vd-ghost" href="/product-category/ready-stock/">Lihat kategori</a></article><article class="vd-card vd-product-card"><h3>Request Only</h3><p>Belum ada di katalog? Kirim link untuk kami cek.</p><a class="button vd-ghost" href="/request-product/">Request Produk</a></article></div></section>
  <section class="vd-wrap vd-section"><div class="vd-section-head"><h2>Produk Open PO.</h2><p>Produk asli dari katalog WooCommerce. Harga final akan dikonfirmasi sebelum pesanan aktif bila produk berstatus request quote.</p></div>[products limit="4" columns="4" orderby="date" order="DESC"]</section>
  <section class="vd-wrap vd-section"><div class="vd-section-head"><h2>Kenapa order lewat Veedy Store?</h2><p>Kami menjaga informasi produk, varian, estimasi, dan kebijakan tetap tertulis.</p></div><div class="vd-grid"><article class="vd-card"><h3>Lebih jelas dari chat manual</h3><p>Checkout, invoice, dan status pesanan tercatat.</p></article><article class="vd-card"><h3>Detail produk tertulis</h3><p>Varian, source URL, estimasi tiba, dan catatan supplier ditampilkan.</p></article><article class="vd-card"><h3>Update status pesanan</h3><p>Mulai dari verifikasi pembayaran, pembelian supplier, sampai pengiriman lokal.</p></article><article class="vd-card"><h3>Support via Discord/email</h3><p>Hubungi kami lewat Discord veedy atau email storeveedy@gmail.com.</p></article><article class="vd-card"><h3>Kebijakan tertulis</h3><p>Refund, pembatalan, dan batasan garansi dijelaskan sebelum launch.</p></article></div></section>
  <section class="vd-wrap vd-section"><div class="vd-card vd-note"><h2>Tidak menemukan produk yang kamu cari?</h2><p>Kirim link produk dari AliExpress, website brand, atau toko luar negeri lainnya. Kami akan cek harga, varian, ketersediaan, dan estimasi waktu.</p><div class="vd-actions wp-block-buttons"><div class="wp-block-button"><a class="wp-block-button__link" href="/request-product/">Request Produk</a></div></div></div></section>
  <section class="vd-wrap vd-section"><div class="vd-card"><h2>Review pelanggan</h2><p>Testimoni pelanggan akan ditampilkan setelah toko resmi dibuka. Bukti transaksi dan update sosial juga akan diarahkan ke kanal resmi Veedy Store setelah tersedia.</p></div></section>
  <section class="vd-wrap vd-section"><div class="vd-section-head"><h2>Pertanyaan penting.</h2><p>Empat hal yang perlu dipahami sebelum membuat order jasa titip.</p></div><div class="vd-grid"><article class="vd-card"><h3>Apa itu jasa titip?</h3><p>Kami membantu membeli produk dari supplier luar negeri atas permintaan customer.</p></article><article class="vd-card"><h3>Kapan dibeli ke supplier?</h3><p>Setelah pembayaran diverifikasi dan detail pesanan sudah jelas.</p></article><article class="vd-card"><h3>Bisa cancel?</h3><p>Bisa sebelum pembelian supplier. Setelah dibeli, mengikuti aturan supplier.</p></article><article class="vd-card"><h3>Apakah official distributor?</h3><p>Tidak, kecuali tertulis secara eksplisit pada produk tertentu.</p></article></div><p><a href="/faq/">Baca FAQ lengkap</a></p></section>
</main>
HTML;

$how = <<<'HTML'
<section class="vd-wrap vd-section"><h1>How It Works</h1><p>Veedy Store membantu proses pembelian produk dari supplier luar negeri dengan alur yang jelas dan terdokumentasi.</p><div class="vd-grid vd-steps"><article class="vd-card vd-step"><h3>Pilih produk</h3><p>Pilih produk dari katalog atau kirim link produk yang kamu inginkan.</p></article><article class="vd-card vd-step"><h3>Checkout</h3><p>Masukkan varian, data kontak, dan alamat pengiriman dengan benar.</p></article><article class="vd-card vd-step"><h3>Pembayaran</h3><p>Lakukan transfer manual sesuai total invoice.</p></article><article class="vd-card vd-step"><h3>Verifikasi pembayaran</h3><p>Kami memeriksa pembayaran sebelum pesanan diproses.</p></article><article class="vd-card vd-step"><h3>Pembelian ke supplier</h3><p>Kami membeli produk dari toko atau supplier yang tercantum.</p></article><article class="vd-card vd-step"><h3>Pengiriman ke warehouse/forwarder</h3><p>Produk dikirim menuju titik penerimaan atau forwarder.</p></article><article class="vd-card vd-step"><h3>Pemeriksaan barang</h3><p>Kami melakukan pemeriksaan dasar sebelum pengiriman lokal.</p></article><article class="vd-card vd-step"><h3>Pengiriman lokal</h3><p>Produk dikirim ke alamat customer di Indonesia.</p></article></div><div class="vd-card vd-note"><p>Veedy Store adalah jasa titip / assisted purchase service. Kami bukan official distributor, official reseller, atau perwakilan resmi brand apa pun kecuali tertulis secara eksplisit.</p></div></section>
HTML;

$faq = <<<'HTML'
<section class="vd-wrap vd-section"><h1>FAQ</h1><div class="vd-grid"><article class="vd-card"><h3>Apa itu jasa titip?</h3><p>Layanan bantuan pembelian produk dari toko luar negeri atas permintaan customer.</p></article><article class="vd-card"><h3>Apakah Veedy Store official distributor?</h3><p>Tidak. Kami bukan official distributor, official reseller, atau perwakilan resmi brand kecuali tertulis secara eksplisit.</p></article><article class="vd-card"><h3>Berapa lama estimasi pengiriman?</h3><p>Estimasi tergantung supplier, forwarder, bea cukai, dan kurir lokal. Setiap produk memiliki catatan estimasi sendiri.</p></article><article class="vd-card"><h3>Bagaimana cara pembayaran?</h3><p>Saat ini pembayaran menggunakan transfer bank manual ke rekening yang tertera di invoice.</p></article><article class="vd-card"><h3>Kapan pesanan dibeli ke supplier?</h3><p>Setelah pembayaran diverifikasi dan detail pesanan sudah jelas.</p></article><article class="vd-card"><h3>Bisa cancel?</h3><p>Bisa sebelum produk dibeli ke supplier. Setelah pembelian supplier, pembatalan mengikuti aturan supplier.</p></article><article class="vd-card"><h3>Bagaimana kalau supplier out of stock?</h3><p>Kami akan menghubungi customer untuk pilihan refund, ganti varian, atau menunggu stok.</p></article><article class="vd-card"><h3>Bagaimana kalau harga supplier berubah?</h3><p>Kami akan meminta persetujuan customer sebelum melanjutkan bila ada perubahan biaya penting.</p></article><article class="vd-card"><h3>Apakah ada garansi?</h3><p>Garansi mengikuti supplier, kecuali tertulis lain. Garansi resmi lokal biasanya tidak berlaku untuk produk import jasa titip.</p></article><article class="vd-card"><h3>Bagaimana tracking pengiriman?</h3><p>Gunakan halaman Track Order dengan nomor pesanan dan email atau Discord yang dipakai saat checkout.</p></article><article class="vd-card"><h3>Apakah bisa request produk?</h3><p>Bisa. Kirim link produk melalui halaman Request Product.</p></article><article class="vd-card"><h3>Apakah harga sudah termasuk ongkir lokal?</h3><p>Tergantung produk dan invoice. Biaya yang termasuk dan belum termasuk akan ditulis pada detail produk atau quote.</p></article></div></section>
HTML;

$terms = <<<'HTML'
<section class="vd-wrap vd-section"><h1>Terms and Conditions</h1><div class="vd-card"><h2>Sifat layanan</h2><p>Veedy Store adalah jasa titip / assisted purchase service. Kami membantu pembelian produk dari supplier luar negeri atas permintaan customer.</p><h2>Bukan official distributor</h2><p>Kami bukan official distributor, official reseller, atau perwakilan resmi brand apa pun kecuali tertulis secara eksplisit.</p><h2>Tanggung jawab customer</h2><p>Customer bertanggung jawab memilih produk, varian, warna, model, dan alamat pengiriman dengan benar.</p><h2>Harga dan stok supplier</h2><p>Harga, stok, kurs, ongkir, dan promo supplier dapat berubah sebelum pembelian dilakukan. Perubahan penting akan dikonfirmasi sebelum pesanan dilanjutkan.</p><h2>Verifikasi pembayaran</h2><p>Pesanan diproses setelah pembayaran diterima dan diverifikasi. Cantumkan nomor pesanan pada berita transfer.</p><h2>Waktu pembelian supplier</h2><p>Pembelian ke supplier dilakukan setelah pembayaran diverifikasi dan detail pesanan jelas.</p><h2>Pembatalan</h2><p>Sebelum pembelian supplier, pembatalan biasanya dapat diproses. Setelah produk dibeli ke supplier, pembatalan mengikuti aturan supplier dan kondisi proses pengiriman.</p><h2>Keterlambatan pengiriman</h2><p>Estimasi dapat berubah karena supplier, forwarder, bea cukai, atau kurir lokal. Kami akan memberi update bila ada informasi penting.</p><h2>Customs dan risiko import</h2><p>Biaya, pemeriksaan, atau penundaan yang terkait proses import dapat terjadi dan akan dikomunikasikan bila relevan.</p><h2>Garansi</h2><p>Garansi mengikuti supplier kecuali tertulis lain. Garansi resmi lokal biasanya tidak berlaku untuk produk import jasa titip.</p><h2>Refund</h2><p>Refund mengikuti Refund Policy. Biaya yang sudah tidak dapat dikembalikan oleh supplier atau pihak ketiga mungkin tidak dapat direfund penuh.</p><h2>Kontak dan penyelesaian</h2><p>Hubungi storeveedy@gmail.com atau Discord veedy dengan nomor pesanan untuk penyelesaian kendala.</p></div></section>
HTML;

$refund = <<<'HTML'
<section class="vd-wrap vd-section"><h1>Refund Policy</h1><div class="vd-card"><h2>Sebelum pembelian supplier</h2><p>Jika produk belum dibeli ke supplier, refund dapat diproses penuh ke rekening customer.</p><h2>Setelah pembelian supplier</h2><p>Setelah produk dibeli, refund mengikuti aturan supplier. Jika supplier menolak pembatalan, pesanan akan tetap diproses.</p><h2>Supplier cancel atau out of stock</h2><p>Jika supplier membatalkan atau stok habis, customer dapat memilih refund, ganti varian, atau menunggu stok bila tersedia.</p><h2>Kenaikan harga</h2><p>Jika harga supplier berubah sebelum pembelian, kami akan meminta persetujuan customer. Jika tidak setuju, refund dapat diproses.</p><h2>Barang rusak, salah, atau kurang</h2><p>Laporkan maksimal 2x24 jam setelah paket diterima. Sertakan foto dan video unboxing tanpa jeda bila memungkinkan.</p><h2>Bukti yang dibutuhkan</h2><p>Klaim membutuhkan nomor pesanan, foto paket, foto produk, dan video unboxing untuk membantu pemeriksaan.</p><h2>Waktu proses refund</h2><p>Refund yang disetujui biasanya diproses dalam 3 sampai 7 hari kerja.</p><h2>Biaya non-refundable</h2><p>Biaya transfer, admin, payment, atau biaya pihak ketiga yang tidak dapat dikembalikan mungkin dipotong bila berlaku.</p></div></section>
HTML;

$privacy = <<<'HTML'
<section class="vd-wrap vd-section"><h1>Privacy Policy</h1><div class="vd-card"><h2>Data yang kami kumpulkan</h2><p>Kami mengumpulkan nama, email, nomor telepon, Discord, alamat pengiriman, data pesanan, dan catatan komunikasi yang diperlukan untuk memproses pesanan.</p><h2>Data order dan kontak</h2><p>Data digunakan untuk checkout, update pesanan, verifikasi, dukungan customer, dan pengiriman.</p><h2>Bukti pembayaran</h2><p>Bukti transfer yang dikirim melalui form konfirmasi digunakan untuk verifikasi pembayaran.</p><h2>Alamat pengiriman</h2><p>Alamat digunakan untuk mengatur pengiriman lokal dan dapat dibagikan ke kurir atau pihak logistik yang diperlukan.</p><h2>Komunikasi</h2><p>Kami dapat menghubungi customer melalui email atau Discord untuk kebutuhan pesanan.</p><h2>Layanan pihak ketiga</h2><p>Data dapat diproses oleh WordPress, WooCommerce, layanan hosting, form plugin, email, kurir, supplier, atau forwarder sesuai kebutuhan pesanan.</p><h2>Retensi data</h2><p>Data disimpan selama diperlukan untuk operasional, catatan transaksi, dan kewajiban hukum.</p><h2>Hak customer</h2><p>Customer dapat meminta koreksi atau penghapusan data tertentu melalui storeveedy@gmail.com, selama tidak bertentangan dengan kebutuhan catatan transaksi.</p></div></section>
HTML;

$contact = <<<'HTML'
<section class="vd-wrap vd-section"><h1>Contact</h1><div class="vd-grid"><article class="vd-card"><h2>Email</h2><p><a href="mailto:storeveedy@gmail.com">storeveedy@gmail.com</a></p></article><article class="vd-card"><h2>Discord</h2><p>veedy</p></article><article class="vd-card"><h2>Instagram</h2><p><a href="https://instagram.com/realveedy" target="_blank" rel="noopener">instagram.com/realveedy</a></p></article><article class="vd-card"><h2>Jam operasional</h2><p>09.00-17.00 WIB</p></article></div><div class="vd-card vd-note"><p>Untuk pertanyaan pesanan, sertakan nomor pesanan agar kami bisa membantu lebih cepat.</p></div></section>
HTML;

$review = '<section class="vd-wrap vd-section"><h1>Review</h1><div class="vd-card"><p>Testimoni pelanggan akan ditampilkan setelah toko resmi dibuka. Bukti transaksi dan informasi sosial media akan diarahkan ke kanal resmi Veedy Store setelah tersedia.</p></div></section>';
$request = '<section class="vd-wrap vd-section"><h1>Request Product</h1><p>Kirim link produk dari website brand, marketplace, atau toko luar negeri. Kami akan cek harga, varian, ketersediaan, dan estimasi waktu sebelum menjadi pesanan aktif.</p>' . ($request_form_id ? '[fluentform id="' . $request_form_id . '"]' : '<div class="vd-card vd-note">Form sedang disiapkan. Hubungi storeveedy@gmail.com atau Discord veedy.</div>') . '</section>';
$payment = '<section class="vd-wrap vd-section"><h1>Konfirmasi Pembayaran</h1><p>Kirim bukti transfer setelah melakukan pembayaran. Verifikasi biasanya membutuhkan 1x24 jam kerja.</p>' . ($payment_form_id ? '[fluentform id="' . $payment_form_id . '"]' : '<div class="vd-card vd-note">Form sedang disiapkan. Hubungi storeveedy@gmail.com atau Discord veedy.</div>') . '</section>';
$track = '<section class="vd-wrap vd-section"><h1>Track Order</h1><p>Lacak pesanan dengan nomor pesanan dan email billing atau username Discord. Detail tidak dapat dibuka hanya dengan nomor pesanan.</p>[veedy_order_tracking]</section>';

$home_id = vm_page('home', 'Home', $home);
vm_page('how-it-works', 'How It Works', $how);
vm_page('request-product', 'Request Product', $request);
vm_page('payment-confirmation', 'Konfirmasi Pembayaran', $payment);
vm_page('track-order', 'Track Order', $track);
vm_page('review', 'Review', $review);
vm_page('faq', 'FAQ', $faq);
vm_page('terms-and-conditions', 'Terms and Conditions', $terms);
vm_page('refund-policy', 'Refund Policy', $refund);
$privacy_id = vm_page('privacy-policy', 'Privacy Policy', $privacy);
vm_page('contact', 'Contact', $contact);
update_option('show_on_front', 'page');
update_option('page_on_front', $home_id);
update_option('wp_page_for_privacy_policy', $privacy_id);

foreach ([['cart', 'Cart', '[woocommerce_cart]'], ['checkout', 'Checkout', '[woocommerce_checkout]'], ['my-account', 'My Account', '[woocommerce_my_account]']] as $p) {
    vm_page($p[0], $p[1], $p[2]);
}
vm_log('pages updated');

$sample = get_page_by_path('sample-overseas-gaming-handheld', OBJECT, 'product');
if ($sample) {
    wp_update_post(['ID' => $sample->ID, 'post_status' => 'draft']);
    vm_log('sample product drafted ' . $sample->ID);
}

$existing = get_page_by_path('retroid-pocket-nova-handheld-open-po', OBJECT, 'product');
$product_id = $existing ? (int) $existing->ID : 0;
if (!$product_id) {
    $product = new WC_Product_Simple();
    $product->set_name('Retroid Pocket Nova Handheld - Open PO');
    $product->set_slug('retroid-pocket-nova-handheld-open-po');
    $product->set_status('publish');
} else {
    $product = wc_get_product($product_id);
}
$desc = '<p>Retroid Pocket Nova adalah handheld Android compact dengan QCS8550, layar AMOLED 4.5 inch 120Hz, pilihan RAM 8GB/12GB, storage UFS 3.1 128GB, Android 13, active cooling, Hall sticks, Wi-Fi 7, Bluetooth 5.3, dan baterai 5000mAh.</p><p>Produk ini dibuka sebagai Open PO / request quote. Harga final dalam IDR akan dikonfirmasi setelah pengecekan kurs, stok supplier, opsi varian, estimasi ongkir, dan biaya yang berlaku.</p><p>Bonus pre-order dari supplier yang tercantum: bumped back shell dan tempered glass screen protector. Bonus mengikuti ketersediaan dan kebijakan supplier.</p>';
$short = 'Open PO Retroid Pocket Nova. Request quote untuk cek harga final, varian, dan estimasi tiba.';
$product->set_description($desc);
$product->set_short_description($short);
$product->set_catalog_visibility('visible');
$product->set_regular_price('');
$product->set_price('');
$product->set_manage_stock(false);
$product->set_stock_status('instock');
$product->set_sold_individually(true);
$product->set_category_ids(array_filter([vm_term('Gaming Handhelds'), vm_term('Jasa Titip'), vm_term('Open PO')]));
$product_id = $product->save();
update_post_meta($product_id, '_veedy_source_store', 'GoRetroid Official Store');
update_post_meta($product_id, '_veedy_source_url', 'https://www.goretroid.com/products/retroid-pocket-nova-handheld');
update_post_meta($product_id, '_veedy_order_type', 'Open PO');
update_post_meta($product_id, '_veedy_estimated_arrival', 'Supplier mulai kirim akhir Juli; estimasi Indonesia dikonfirmasi saat quote.');
update_post_meta($product_id, '_veedy_last_price_checked', date('Y-m-d'));
update_post_meta($product_id, '_veedy_price_includes', 'Harga produk dan jasa titip sesuai quote final.');
update_post_meta($product_id, '_veedy_price_excludes', 'Ongkir lokal, bea masuk/pajak, biaya tambahan supplier/forwarder bila ada, kecuali tertulis dalam quote final.');
update_post_meta($product_id, '_veedy_warranty_note', 'Garansi mengikuti supplier. Veedy Store bukan official distributor Retroid kecuali tertulis eksplisit.');
update_post_meta($product_id, '_veedy_cancellation_note', 'Bisa dibatalkan sebelum pembelian supplier. Setelah dibeli, mengikuti kebijakan supplier.');
update_post_meta($product_id, '_veedy_supplier_availability', 'Open PO. Stok, warna, harga, dan bonus supplier dapat berubah.');
vm_log('retroid product saved ' . $product_id);

if (!has_post_thumbnail($product_id)) {
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $images = [
        'https://www.goretroid.com/cdn/shop/files/2_e1c341c8-3804-4151-bbaa-c4ba9c063f9b_1200x1200.jpg?v=1782382557',
        'https://www.goretroid.com/cdn/shop/files/1_0ab3b9bf-beae-4d9e-abdc-7c90a8b1a799_1200x1200.jpg?v=1782382557',
        'https://www.goretroid.com/cdn/shop/files/4_36c914da-b58f-426c-8054-52c25f129c5b_1200x1200.jpg?v=1782382557',
    ];
    $gallery = [];
    foreach ($images as $index => $image_url) {
        vm_log('sideload image ' . ($index + 1));
        $att_id = media_sideload_image($image_url, $product_id, 'Retroid Pocket Nova product image', 'id');
        if (!is_wp_error($att_id)) {
            if ($index === 0) { set_post_thumbnail($product_id, $att_id); } else { $gallery[] = $att_id; }
        } else {
            vm_log('image error: ' . $att_id->get_error_message());
        }
    }
    if ($gallery) { update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery)); }
}

function vm_menu($name, $items) {
    $menu = wp_get_nav_menu_object($name);
    $menu_id = $menu ? (int) $menu->term_id : (int) wp_create_nav_menu($name);
    foreach ((array) wp_get_nav_menu_items($menu_id) as $item) {
        wp_delete_post($item->ID, true);
    }
    foreach ($items as $slug => $title) {
        $page = get_page_by_path($slug, OBJECT, 'page');
        if (!$page) { continue; }
        wp_update_nav_menu_item($menu_id, 0, [
            'menu-item-title' => $title,
            'menu-item-object-id' => $page->ID,
            'menu-item-object' => 'page',
            'menu-item-type' => 'post_type',
            'menu-item-status' => 'publish',
        ]);
    }
    return $menu_id;
}
$main_menu = vm_menu('Main Menu', ['home' => 'Home', 'shop' => 'Shop', 'how-it-works' => 'How It Works', 'request-product' => 'Request Product', 'track-order' => 'Track Order', 'faq' => 'FAQ', 'contact' => 'Contact']);
$footer_menu = vm_menu('Footer Menu', ['terms-and-conditions' => 'Terms and Conditions', 'refund-policy' => 'Refund Policy', 'privacy-policy' => 'Privacy Policy', 'contact' => 'Contact']);
$locations = get_registered_nav_menus();
$assign = get_theme_mod('nav_menu_locations');
if (!is_array($assign)) { $assign = []; }
foreach ($locations as $slug => $label) {
    $assign[$slug] = stripos($slug . ' ' . $label, 'footer') !== false ? $footer_menu : $main_menu;
}
set_theme_mod('nav_menu_locations', $assign);
vm_log('menus updated');

flush_rewrite_rules(false);
if (function_exists('wc_delete_product_transients')) { wc_delete_product_transients($product_id); }
vm_log('done');
