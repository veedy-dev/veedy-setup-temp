<?php
/**
 * Child override of Blocksy footer.php.
 *
 * Keeps the parent's wrapper/hook structure (verified against Blocksy
 * 2.1.47: close </main>, run blocksy:content/footer hooks, close the
 * canvas div, wp_footer) but replaces blocksy_output_footer() with the
 * Veedy footer per DESIGN.md §3.2. No theme credit.
 *
 * @package veedy-blocksy-child
 */

blocksy_after_current_template();
do_action( 'blocksy:content:bottom' );

?>
	</main>

	<?php
		do_action( 'blocksy:content:after' );
		do_action( 'blocksy:footer:before' );
	?>

	<footer id="footer" class="vd-site-footer" itemscope itemtype="https://schema.org/WPFooter">
		<div class="vd-site-footer__inner">
			<div class="vd-site-footer__cols">

				<div class="vd-site-footer__col">
					<p class="vd-site-footer__brand">Veedy Store</p>
					<p class="vd-site-footer__desc">Jasa titip produk luar negeri untuk pelanggan Indonesia. Open PO, ready stock, dan request produk — dicek dulu sebelum kamu bayar.</p>
					<p class="vd-site-footer__disclaimer">Veedy Store adalah layanan jasa titip / assisted purchase, bukan official distributor brand mana pun kecuali tertulis eksplisit.</p>
				</div>

				<div class="vd-site-footer__col">
					<p class="vd-site-footer__heading">Shop</p>
					<ul class="vd-site-footer__menu">
						<li><a href="<?php echo esc_url( home_url( '/shop/' ) ); ?>">All Products</a></li>
						<li><a href="<?php echo esc_url( home_url( '/product-category/open-po/' ) ); ?>">Open PO</a></li>
						<li><a href="<?php echo esc_url( home_url( '/product-category/ready-stock/' ) ); ?>">Ready Stock</a></li>
						<li><a href="<?php echo esc_url( home_url( '/product-category/gaming-handhelds/' ) ); ?>">Gaming Handhelds</a></li>
						<li><a href="<?php echo esc_url( home_url( '/product-category/accessories/' ) ); ?>">Accessories</a></li>
					</ul>
				</div>

				<div class="vd-site-footer__col">
					<p class="vd-site-footer__heading">Support</p>
					<ul class="vd-site-footer__menu">
						<li><a href="<?php echo esc_url( home_url( '/how-it-works/' ) ); ?>">How It Works</a></li>
						<li><a href="<?php echo esc_url( home_url( '/request-product/' ) ); ?>">Request Product</a></li>
						<li><a href="<?php echo esc_url( home_url( '/track-order/' ) ); ?>">Track Order</a></li>
						<li><a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>">Contact</a></li>
					</ul>
				</div>

				<div class="vd-site-footer__col">
					<p class="vd-site-footer__heading">Policies</p>
					<ul class="vd-site-footer__menu">
						<li><a href="<?php echo esc_url( home_url( '/terms-and-conditions/' ) ); ?>">Terms &amp; Conditions</a></li>
						<li><a href="<?php echo esc_url( home_url( '/refund-policy/' ) ); ?>">Refund Policy</a></li>
						<li><a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>">Privacy Policy</a></li>
						<li><a href="<?php echo esc_url( home_url( '/faq/' ) ); ?>">FAQ</a></li>
					</ul>
				</div>

			</div>

			<div class="vd-site-footer__bottom">
				<p>
					Email <a href="mailto:storeveedy@gmail.com">storeveedy@gmail.com</a>
					&nbsp;&middot;&nbsp; Discord veedy
					&nbsp;&middot;&nbsp; Instagram <a href="https://instagram.com/realveedy" target="_blank" rel="noopener">@realveedy</a>
					&nbsp;&middot;&nbsp; Jam operasional 09.00&ndash;17.00 WIB
				</p>
				<p>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> Veedy Store</p>
			</div>
		</div>
	</footer>

	<?php do_action( 'blocksy:footer:after' ); ?>
</div>

<?php wp_footer(); ?>

</body>
</html>
