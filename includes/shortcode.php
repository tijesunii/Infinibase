<?php
/**
 * InfiniBase - Shortcode Renderer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function infinibase_register_shortcodes() {
	add_shortcode( 'infinibase', 'infinibase_render_shortcode' );
	add_shortcode( 'infinibase_light', 'infinibase_render_light_shortcode' );
	add_shortcode( 'infinibase_dark', 'infinibase_render_dark_shortcode' );
}
add_action( 'init', 'infinibase_register_shortcodes' );

function infinibase_register_frontend_assets() {
	wp_register_style(
		'infinibase-style',
		INFINIBASE_PLUGIN_URL . 'assets/css/infinibase.css',
		array(),
		INFINIBASE_VERSION
	);

	wp_register_script(
		'infinibase-script',
		INFINIBASE_PLUGIN_URL . 'assets/js/infinibase.js',
		array(),
		INFINIBASE_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'infinibase_register_frontend_assets' );
add_action( 'elementor/frontend/after_enqueue_styles', 'infinibase_enqueue_frontend_assets' );
add_action( 'elementor/preview/enqueue_styles', 'infinibase_enqueue_frontend_assets' );

function infinibase_get_frontend_config() {
	return array(
		'restUrl'     => esc_url_raw( rest_url( 'infinibase/v1/ask' ) ),
		'nonce'       => wp_create_nonce( 'infinibase_ask' ),
		'wpRestNonce' => wp_create_nonce( 'wp_rest' ),
		'supportUrl'  => esc_url_raw( get_option( 'infinibase_support_url', '' ) ),
		'i18n'        => array(
			'emptyError'   => __( 'Please enter a question (at least 5 characters).', 'infinibase' ),
			'genericError' => __( 'We are unable to process your question at this time.', 'infinibase' ),
			'readMore'     => __( 'Read more →', 'infinibase' ),
			'contactBtn'   => __( 'Contact Support →', 'infinibase' ),
		),
	);
}

function infinibase_enqueue_frontend_assets() {
	if ( ! wp_style_is( 'infinibase-style', 'registered' ) ) {
		infinibase_register_frontend_assets();
	}

	wp_enqueue_style( 'infinibase-style' );

	if ( ! wp_script_is( 'infinibase-script', 'enqueued' ) ) {
		wp_enqueue_script( 'infinibase-script' );

		wp_localize_script(
			'infinibase-script',
			'infinibaseData',
			infinibase_get_frontend_config()
		);
	}
}

function infinibase_render_light_shortcode( $atts = array() ) {
	$atts          = is_array( $atts ) ? $atts : array();
	$atts['theme'] = 'light';
	return infinibase_render_shortcode( $atts );
}

function infinibase_render_dark_shortcode( $atts = array() ) {
	$atts          = is_array( $atts ) ? $atts : array();
	$atts['theme'] = 'dark';
	return infinibase_render_shortcode( $atts );
}

function infinibase_render_shortcode( $atts = array() ) {
	infinibase_enqueue_frontend_assets();

	$saved_heading = get_option( 'infinibase_shortcode_heading', 'Questions' );
	if ( empty( $saved_heading ) ) {
		$saved_heading = 'Questions';
	}

	$saved_subtitle = get_option( 'infinibase_shortcode_subtitle', 'The short ones are here. For anything else, ask our AI below.' );
	if ( '' === trim( $saved_subtitle ) ) {
		$saved_subtitle = 'The short ones are here. For anything else, ask our AI below.';
	}

	$atts = shortcode_atts(
		array(
			'theme'    => 'light',
			'title'    => $saved_heading,
			'subtitle' => $saved_subtitle,
		),
		$atts,
		'infinibase'
	);

	$heading_text = mb_substr( trim( $atts['title'] ), 0, 9 );
	if ( '' === $heading_text ) {
		$heading_text = 'Questions';
	}

	$theme_mode  = ( 'dark' === strtolower( $atts['theme'] ) ) ? 'dark' : 'light';
	$theme_class = 'infinite-faqs-theme-' . $theme_mode;
	$config_json = wp_json_encode( infinibase_get_frontend_config() );
	$hp_field_id = wp_unique_id( 'ifaq_email_check_' );

	$static_faqs = get_option( 'infinibase_static_faqs', array() );
	if ( ! is_array( $static_faqs ) ) {
		$static_faqs = array();
	}

	ob_start();
	?>
	<div class="infinite-faqs-wrapper <?php echo esc_attr( $theme_class ); ?>" data-theme="<?php echo esc_attr( $theme_mode ); ?>" data-config="<?php echo esc_attr( $config_json ); ?>">
		<div class="infinite-faqs-container">
			<div class="infinite-faqs-left-col">
				<h2 class="infinite-faqs-title"><?php echo esc_html( $heading_text ); ?></h2>
				<p class="infinite-faqs-subtitle"><?php echo esc_html( $atts['subtitle'] ); ?></p>
			</div>

			<div class="infinite-faqs-right-col">
				<div class="infinite-faqs-accordion-list">
					<?php
					foreach ( $static_faqs as $faq ) :
						$q_text = isset( $faq['question'] ) ? trim( $faq['question'] ) : '';
						$a_text = isset( $faq['answer'] ) ? trim( $faq['answer'] ) : '';
						if ( '' === $q_text && '' === $a_text ) {
							continue;
						}
						?>
						<div class="infinite-faqs-accordion-item">
							<button type="button" class="infinite-faqs-accordion-header" aria-expanded="false">
								<span class="infinite-faqs-question-text"><?php echo esc_html( $q_text ); ?></span>
								<span class="infinite-faqs-icon-container" aria-hidden="true">
									<svg class="infinite-faqs-icon infinite-faqs-icon-inactive" viewBox="0 0 24 24">
										<path d="M6 9l6 6 6-6" />
									</svg>
									<svg class="infinite-faqs-icon infinite-faqs-icon-active" viewBox="0 0 24 24">
										<path d="M5 12h14M12 5l7 7-7 7" />
									</svg>
								</span>
							</button>
							<div class="infinite-faqs-accordion-content">
								<p><?php echo esc_html( $a_text ); ?></p>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="infinite-faqs-ask-section">
					<form class="infinite-faqs-ask-form" novalidate>
						<div class="infinite-faqs-hp-wrap" aria-hidden="true">
							<label for="<?php echo esc_attr( $hp_field_id ); ?>">Do not fill this field</label>
							<input type="text" name="_ifaq_email_check" id="<?php echo esc_attr( $hp_field_id ); ?>" class="infinite-faqs-honeypot" value="" tabindex="-1" autocomplete="off" />
						</div>

						<div class="infinite-faqs-input-wrapper">
							<input
								type="text"
								name="question"
								class="infinite-faqs-input"
								placeholder="<?php echo esc_attr__( 'Ask a question about our product...', 'infinibase' ); ?>"
								aria-label="<?php echo esc_attr__( 'Ask a question about our product', 'infinibase' ); ?>"
								maxlength="250"
								required
							/>
							<button type="submit" class="infinite-faqs-submit-btn" aria-label="<?php echo esc_attr__( 'Submit question', 'infinibase' ); ?>">
								<span class="infinite-faqs-icon-container">
									<svg class="infinite-faqs-icon" viewBox="0 0 24 24">
										<path d="M5 12h14M12 5l7 7-7 7" />
									</svg>
								</span>
							</button>
						</div>
					</form>

					<div class="infinite-faqs-loading" aria-live="polite">
						<span class="infinite-faqs-spinner"></span>
						<span class="infinite-faqs-loading-text"><?php esc_html_e( 'Searching docs...', 'infinibase' ); ?></span>
					</div>

					<div class="infinite-faqs-response-container" aria-live="polite">
						<p class="infinite-faqs-ai-thought"></p>
						<div class="infinite-faqs-answer-text"></div>
						<div class="infinite-faqs-action-wrapper"></div>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
