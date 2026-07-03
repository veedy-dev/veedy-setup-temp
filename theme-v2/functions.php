<?php
/**
 * Veedy Blocksy Child — enqueue + announcement bar.
 *
 * Visual system lives in style.css. Footer markup lives in footer.php.
 * No options framework, no builders.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VEEDY_CHILD_VERSION', '2.0.0' );

/**
 * Styles: Google Fonts + child stylesheet.
 * Late priority so the child CSS prints after Blocksy's bundle and
 * customizer inline styles, letting the :root variable bridge win.
 */
add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style(
		'veedy-fonts',
		'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap',
		array(),
		null
	);

	wp_enqueue_style(
		'veedy-child',
		get_stylesheet_uri(),
		array( 'veedy-fonts' ),
		VEEDY_CHILD_VERSION
	);
}, 50 );

/**
 * Preconnect for the font CDN.
 */
add_filter( 'wp_resource_hints', function ( $urls, $relation_type ) {
	if ( 'preconnect' === $relation_type ) {
		$urls[] = array(
			'href'        => 'https://fonts.gstatic.com',
			'crossorigin' => 'anonymous',
		);
		$urls[] = 'https://fonts.googleapis.com';
	}
	return $urls;
}, 10, 2 );

/**
 * Announcement bar — one line, top of <body>, before the header.
 * Hidden under 420px via CSS (.vd-announcement).
 */
add_action( 'wp_body_open', function () {
	?>
	<div class="vd-announcement">
		<a href="<?php echo esc_url( home_url( '/request-product/' ) ); ?>">
			Open PO produk luar negeri — transfer manual tersedia selama beta.
			<span class="vd-announcement__cta">Request Produk &rarr;</span>
		</a>
	</div>
	<?php
} );
