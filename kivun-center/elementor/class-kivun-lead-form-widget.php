<?php
/**
 * Elementor widget: the lead form a page was given.
 *
 * A landing page can be given questions of its own in the publishing
 * console, but the plugin draws the built-in landing layout only where a
 * site asked for it — and most sites design the landing page in Elementor
 * instead, where none of the plugin's own render sites are reached. The form
 * had nowhere to appear. This widget is the way in: drag it onto the design
 * and it draws whichever form that page carries.
 *
 * @package Kivun
 */

defined( 'ABSPATH' ) || exit;

/**
 * Draws a page's lead form inside an Elementor design.
 */
class Kivun_Lead_Form_Widget extends \Elementor\Widget_Base {

	/**
	 * The widget's machine name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'kivun_lead_form';
	}

	/**
	 * The name shown in the editor's panel.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return __( 'טופס ליד — מרכז כיוון', 'kivun' );
	}

	/**
	 * The panel icon.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return 'eicon-form-horizontal';
	}

	/**
	 * The panel category.
	 *
	 * @return array<int,string>
	 */
	public function get_categories(): array {
		return array( 'general' );
	}

	/**
	 * Words that find this widget in the panel's search.
	 *
	 * @return array<int,string>
	 */
	public function get_keywords(): array {
		return array( 'kivun', 'כיוון', 'טופס', 'ליד', 'לידים', 'form', 'lead' );
	}

	/**
	 * The one setting: which page's form, if not the one being viewed.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'kivun_lead_form_section',
			array( 'label' => __( 'טופס ליד', 'kivun' ) )
		);

		$this->add_control(
			'kivun_notice',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'הטופס נלקח מדף הנחיתה עצמו. את השאלות מרכיבים בניהול תוכן, בלשונית "דפי נחיתה בהתאמה אישית".', 'kivun' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->add_control(
			'post_id',
			array(
				'label'       => __( 'מזהה הדף', 'kivun' ),
				'description' => __( 'להשאיר ריק כדי להציג את הטופס של הדף שנצפה — כך תבנית אחת משרתת את כל דפי הנחיתה.', 'kivun' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 0,
				'default'     => 0,
			)
		);

		$this->add_control(
			'only',
			array(
				'label'       => __( 'מתי להציג', 'kivun' ),
				'description' => __( 'תבנית אחת משרתת את כל דפי הנחיתה, אז אפשר להניח כאן את שני הטפסים: זה, שמוצג רק כשלדף יש שאלות משלו, ולצידו הטופס שעיצבתם — עם המחלקה kivun-hide-if-custom-form, שמסתירה אותו באותם דפים בדיוק.', 'kivun' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'custom',
				'options'     => array(
					'custom' => __( 'רק כשלדף יש טופס מותאם', 'kivun' ),
					''       => __( 'תמיד (נופל לטופס הקבוע)', 'kivun' ),
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Draw the form.
	 *
	 * Rendered through the shortcode rather than by calling the template
	 * here, so the editor and the live page cannot drift apart: there is one
	 * place that decides which form a page shows.
	 *
	 * @return void
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$id       = absint( $settings['post_id'] ?? 0 );
		$only     = (string) ( $settings['only'] ?? 'custom' );

		$html = Kivun_Shortcodes::render_lead_form(
			array(
				'id'   => $id,
				'only' => $only,
			)
		);

		if ( '' === $html ) {
			// Silent on the live page: drawing nothing is the whole point on
			// a page this widget was told to stay out of, and a gap left
			// behind would be as wrong as a second form. In the editor the
			// silence would look like a broken widget, so it says why.
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				printf(
					'<p class="elementor-descriptor">%s</p>',
					esc_html__( 'כאן לא מוצג טופס: או שהעמוד אינו דף נחיתה או קורס, או שהוגדר להציג רק כשלדף יש טופס מותאם ולדף הזה אין. בעמוד אמיתי לא יישאר כאן רווח.', 'kivun' )
				);
			}
			return;
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built by the plugin's own form templates, which escape as they go.
	}
}
