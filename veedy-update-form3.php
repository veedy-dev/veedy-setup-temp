<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

$form_id = 3;
$forms_table = $wpdb->prefix . 'fluentform_forms';
$meta_table = $wpdb->prefix . 'fluentform_form_meta';
$confirmation = 'Request diterima. Kami akan cek detail produk dan menghubungi kamu dalam 1x24 jam kerja.';

function veedy_form3_log(string $message): void {
    echo '[veedy-form3] ' . $message . "\n";
    if (function_exists('flush')) {
        flush();
    }
}

function veedy_form3_field(string $element, string $name, string $label, bool $required = false, array $extra = array()): array {
    $field = array(
        'element' => $element,
        'attributes' => array('name' => $name, 'class' => '', 'value' => ''),
        'settings' => array(
            'label' => $label,
            'admin_field_label' => '',
            'placeholder' => '',
            'container_class' => '',
            'validation_rules' => array(
                'required' => array('value' => $required, 'message' => $label . ' wajib diisi.'),
            ),
        ),
        'editor_options' => array('title' => $label),
    );

    return array_replace_recursive($field, $extra);
}

function veedy_form3_field_names(array $form_fields): array {
    $names = array();
    foreach (($form_fields['fields'] ?? array()) as $field) {
        $name = $field['attributes']['name'] ?? '';
        if ($name !== '') {
            $names[] = (string) $name;
        }
    }
    return $names;
}

function veedy_form3_find_get_produk_value(array $form_fields): string {
    foreach (($form_fields['fields'] ?? array()) as $field) {
        $name = $field['attributes']['name'] ?? '';
        if (!in_array($name, array('nama_produk', 'link_produk'), true)) {
            continue;
        }

        foreach (array($field['attributes']['value'] ?? '', $field['settings']['default_value'] ?? '') as $value) {
            $value = (string) $value;
            if (strpos($value, '{get.produk}') !== false) {
                return $value;
            }
        }
    }

    return '{get.produk}';
}

if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $forms_table)) !== $forms_table) {
    veedy_form3_log('Fluent Forms table not found: ' . $forms_table);
    exit(1);
}

$form = $wpdb->get_row($wpdb->prepare("SELECT id, title, form_fields FROM {$forms_table} WHERE id = %d LIMIT 1", $form_id));
if (!$form) {
    veedy_form3_log('Form ID 3 not found. No update performed.');
    exit(1);
}

$before_fields = json_decode((string) $form->form_fields, true);
if (!is_array($before_fields)) {
    $before_fields = array('fields' => array());
}

veedy_form3_log('updating form ' . $form->id . ': ' . $form->title);
veedy_form3_log('before fields: ' . implode(', ', veedy_form3_field_names($before_fields)));

$produk_default = veedy_form3_find_get_produk_value($before_fields);

$fields = array(
    veedy_form3_field('input_text', 'nama_lengkap', 'Nama lengkap', true),
    veedy_form3_field('input_text', 'whatsapp', 'No. WhatsApp', true, array(
        'settings' => array('placeholder' => '08xxxxxxxxxx'),
    )),
    veedy_form3_field('input_email', 'email', 'Email', true),
    veedy_form3_field('input_text', 'discord', 'Username Discord (opsional)', false),
    veedy_form3_field('input_url', 'link_produk', 'Link produk', true),
    veedy_form3_field('input_text', 'nama_produk', 'Nama produk', true, array(
        'attributes' => array('value' => $produk_default),
    )),
    veedy_form3_field('input_text', 'varian', 'Varian yang diinginkan (warna/model)', false),
    veedy_form3_field('input_number', 'jumlah', 'Jumlah', true, array(
        'attributes' => array('value' => '1', 'min' => '1'),
    )),
    veedy_form3_field('input_text', 'budget', 'Budget (opsional)', false, array(
        'settings' => array('placeholder' => 'contoh: Rp4.000.000'),
    )),
    veedy_form3_field('textarea', 'catatan', 'Catatan tambahan', false),
    veedy_form3_field('terms_and_condition', 'agreement', 'Persetujuan', true, array(
        'attributes' => array('value' => '1'),
        'settings' => array('tnc_html' => 'Saya paham bahwa request ini belum menjadi pesanan aktif. Harga, stok, dan estimasi akan dicek terlebih dahulu oleh Veedy Store.'),
    )),
);

$form_fields = array(
    'fields' => $fields,
    'submitButton' => array(
        'type' => 'button',
        'element' => 'button',
        'attributes' => array('type' => 'submit', 'class' => 'ff-btn ff-btn-submit ff-btn-md'),
        'settings' => array('align' => 'left', 'button_style' => 'default', 'container_class' => '', 'button_size' => 'md', 'button_text' => 'Kirim Request'),
        'editor_options' => array('title' => 'Submit Button'),
        'label' => 'Kirim Request',
    ),
);

$appearance = array(
    'layout' => 'default',
    'labelPlacement' => 'top',
    'asteriskPlacement' => 'asterisk-right',
    'helpMessagePlacement' => 'with_label',
    'cssClassName' => 'veedy-form',
);

$update = array(
    'status' => 'published',
    'appearance_settings' => wp_json_encode($appearance),
    'form_fields' => wp_json_encode($form_fields),
    'has_payment' => 0,
    'type' => 'form',
    'conditions' => wp_json_encode(array()),
);
$form_columns = $wpdb->get_col("DESC {$forms_table}", 0);
$update = array_intersect_key($update, array_flip($form_columns));
if (in_array('updated_at', $form_columns, true)) {
    $update['updated_at'] = current_time('mysql');
}

$result = $wpdb->update($forms_table, $update, array('id' => $form_id));
if ($result === false) {
    veedy_form3_log('failed updating form_fields: ' . $wpdb->last_error);
    exit(1);
}

if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $meta_table)) === $meta_table) {
    $settings = array(
        'redirectTo' => 'samePage',
        'messageToShow' => $confirmation,
        'customPage' => '',
        'samePageFormBehavior' => 'hide_form',
    );

    $wpdb->delete($meta_table, array('form_id' => $form_id, 'meta_key' => 'formSettings'));
    $meta_result = $wpdb->insert($meta_table, array(
        'form_id' => $form_id,
        'meta_key' => 'formSettings',
        'value' => wp_json_encode($settings),
    ));

    if ($meta_result === false) {
        veedy_form3_log('failed updating formSettings: ' . $wpdb->last_error);
        exit(1);
    }
} else {
    veedy_form3_log('form_meta table not found; confirmation message not updated.');
}

veedy_form3_log('after fields: ' . implode(', ', veedy_form3_field_names($form_fields)));
echo "FORM3_UPDATE_DONE\n";
