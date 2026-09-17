<?php
/**
 * "Support & service" dashboard widget (MultiDigital branding).
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds a branded support widget to the wp-admin dashboard.
 */
class Kivun_Support {

	/**
	 * Support contact email.
	 */
	const EMAIL = 'service@multidigital.co.il';

	/**
	 * Hook into the dashboard.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widget' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Load the admin stylesheet on the dashboard (where the widget renders).
	 *
	 * @param string $hook The current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( string $hook ): void {
		if ( 'index.php' === $hook ) {
			wp_enqueue_style( 'kivun-admin', KIVUN_URL . 'assets/css/' . Kivun_Core::asset( 'admin', 'css' ), array(), KIVUN_VERSION );
		}
	}

	/**
	 * Register the dashboard widget.
	 *
	 * @return void
	 */
	public static function register_widget(): void {
		wp_add_dashboard_widget( 'kivun_support_widget', __( 'תמיכה ושירות', 'kivun' ), array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the widget content.
	 *
	 * @return void
	 */
	public static function render(): void {
		$email   = sanitize_email( (string) apply_filters( 'kivun_support_email', self::EMAIL ) );
		$socials = array(
			'Facebook'  => 'https://www.facebook.com/multidigitalltd',
			'WhatsApp'  => 'https://www.whatsapp.com/channel/0029VaKs05JInlqRWEMgLn0B',
			'Instagram' => 'https://www.instagram.com/multi_digital_ltd/',
			'YouTube'   => 'https://www.youtube.com/channel/UC1HbeK10vkhrfGvoHnNPrfA',
		);
		?>
		<div class="kivun-support" dir="rtl">
			<p class="kivun-support__lead">
				<?php
				printf(
					/* translators: %s: MultiDigital website link. */
					esc_html__( 'האתר שלך פותח על ידי %s — ואנחנו כאן בשבילך.', 'kivun' ),
					'<a href="https://m-d.co.il" target="_blank" rel="noopener">' . esc_html__( 'מולטי דיגיטל', 'kivun' ) . '</a>'
				);
				?>
			</p>

			<a class="kivun-support__cta" href="<?php echo esc_attr( 'mailto:' . $email ); ?>">
				<?php esc_html_e( 'לקבלת שירות', 'kivun' ); ?> ›
			</a>

			<p class="kivun-support__sub">
				<?php esc_html_e( 'או במייל:', 'kivun' ); ?>
				<a href="<?php echo esc_attr( 'mailto:' . $email ); ?>"><?php echo esc_html( $email ); ?></a>
			</p>

			<p class="kivun-support__social">
				<?php
				$links = array();
				foreach ( $socials as $label => $url ) {
					$links[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each link is individually escaped above.
				echo implode( ' · ', $links );
				?>
			</p>
		</div>
		<?php
	}
}
