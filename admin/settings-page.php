<?php
/**
 * InfiniBase - Admin Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function infinibase_add_admin_menu() {
	add_menu_page(
		esc_html__( 'InfiniBase Settings', 'infinibase' ),
		esc_html__( 'InfiniBase', 'infinibase' ),
		'manage_options',
		'infinibase',
		'infinibase_settings_page_html',
		'dashicons-testimonial',
		80
	);
}
add_action( 'admin_menu', 'infinibase_add_admin_menu' );

function infinibase_ajax_test_connection() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'infinibase' ) ), 403 );
	}

	check_ajax_referer( 'infinibase_status_check', 'nonce' );

	$allowed_providers = array( 'wp_core', 'openai', 'anthropic', 'gemini', 'deepseek' );
	$provider          = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';
	if ( ! in_array( $provider, $allowed_providers, true ) ) {
		$provider = get_option( 'infinibase_ai_provider', 'openai' );
	}

	$raw_key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
	$result  = infinibase_test_ai_connection( $provider, $raw_key );

	if ( ! empty( $result['success'] ) ) {
		wp_send_json_success( array( 'message' => $result['message'] ) );
	}

	wp_send_json_error( array( 'message' => $result['message'] ) );
}
add_action( 'wp_ajax_infinibase_test_connection', 'infinibase_ajax_test_connection' );

function infinibase_settings_page_html() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['infinibase_submit'] ) ) {
		$settings_nonce = isset( $_POST['infinibase_settings_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['infinibase_settings_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $settings_nonce, 'infinibase_save_settings' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'infinibase' ) );
		}

		if ( isset( $_POST['infinibase_ai_provider'] ) ) {
			update_option( 'infinibase_ai_provider', sanitize_text_field( wp_unslash( $_POST['infinibase_ai_provider'] ) ) );
		}

		if ( ! empty( $_POST['infinibase_api_key'] ) ) {
			$raw_api_key = sanitize_text_field( wp_unslash( $_POST['infinibase_api_key'] ) );
			if ( false === strpos( $raw_api_key, '*' ) ) {
				$encrypted_key = infinibase_encrypt_data( $raw_api_key );
				update_option( 'infinibase_api_key', $encrypted_key );
			}
		}

		if ( isset( $_POST['infinibase_support_url'] ) ) {
			update_option( 'infinibase_support_url', sanitize_url( wp_unslash( $_POST['infinibase_support_url'] ) ) );
		}

		if ( isset( $_POST['infinibase_answer_length'] ) ) {
			update_option( 'infinibase_answer_length', sanitize_text_field( wp_unslash( $_POST['infinibase_answer_length'] ) ) );
		}

		$keep_data = isset( $_POST['infinibase_keep_data_on_uninstall'] ) ? '1' : '0';
		update_option( 'infinibase_keep_data_on_uninstall', $keep_data );

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved securely.', 'infinibase' ) . '</p></div>';
	}

	$current_provider = get_option( 'infinibase_ai_provider', 'openai' );
	$answer_length    = get_option( 'infinibase_answer_length', 'Brief' );
	$encrypted_key    = get_option( 'infinibase_api_key', '' );
	$decrypted_key    = infinibase_decrypt_data( $encrypted_key );
	$masked_key       = infinibase_mask_api_key( $decrypted_key );
	$support_url      = get_option( 'infinibase_support_url', '' );
	$keep_data        = get_option( 'infinibase_keep_data_on_uninstall', '0' );
	$has_wp_ai_client = function_exists( 'wp_ai_client_prompt' );
	$debug_info = infinibase_get_debug_info_text();
	?>
	<div class="ifaq-app-wrap">
		<?php infinibase_render_admin_topbar( 'settings' ); ?>

		<section class="ifaq-page-hero">
			<div class="ifaq-page-hero-inner">
				<div>
					<h1 class="ifaq-hero-heading"><?php esc_html_e( 'Settings', 'infinibase' ); ?></h1>
					<p class="ifaq-hero-desc"><?php esc_html_e( 'Configure your AI provider, answer style, fallback routing, and diagnostics.', 'infinibase' ); ?></p>
				</div>
			</div>
		</section>

		<div class="ifaq-container">
			<?php infinibase_render_review_banner(); ?>

			<div class="ifaq-kb-layout">
				<div>
					<div class="ifaq-subtabs" data-group="settings">
						<button type="button" class="ifaq-subtab is-active" data-tab="set-panel-provider"><?php esc_html_e( 'AI Provider', 'infinibase' ); ?></button>
						<button type="button" class="ifaq-subtab" data-tab="set-panel-behavior"><?php esc_html_e( 'Behavior & Safety', 'infinibase' ); ?></button>
						<button type="button" class="ifaq-subtab" data-tab="set-panel-support"><?php esc_html_e( 'Support & Diagnostics', 'infinibase' ); ?></button>
					</div>

					<form method="post" action="">
						<?php wp_nonce_field( 'infinibase_save_settings', 'infinibase_settings_nonce' ); ?>
						<input type="hidden" name="infinibase_ai_provider" id="infinibase_ai_provider" value="<?php echo esc_attr( $current_provider ); ?>" />
						<input type="hidden" name="infinibase_answer_length" id="infinibase_answer_length" value="<?php echo esc_attr( $answer_length ); ?>" />

						<!-- Settings Sub-Tab 1: AI Provider -->
						<div class="ifaq-tab-panel is-active" id="set-panel-provider">
							<div class="ifaq-settings-row">
								<div class="ifaq-settings-label">
									<h3><?php esc_html_e( 'Provider & credentials', 'infinibase' ); ?></h3>
									<p><?php esc_html_e( 'Connect via WordPress 7.0 Core AI Connectors or bring your own API key.', 'infinibase' ); ?></p>
								</div>
								<div class="ifaq-settings-body">
									<div class="ifaq-provider-grid" id="infinibase-provider-grid">
										<button type="button" class="ifaq-provider-tile<?php echo ( 'wp_core' === $current_provider ) ? ' is-selected' : ''; ?>" data-provider="wp_core">
											<strong><?php esc_html_e( 'WP Core AI', 'infinibase' ); ?></strong>
											<span><?php esc_html_e( 'Settings → Connectors', 'infinibase' ); ?></span>
										</button>
										<button type="button" class="ifaq-provider-tile<?php echo ( 'openai' === $current_provider ) ? ' is-selected' : ''; ?>" data-provider="openai">
											<strong>OpenAI</strong>
											<span>gpt-4o-mini</span>
										</button>
										<button type="button" class="ifaq-provider-tile<?php echo ( 'anthropic' === $current_provider ) ? ' is-selected' : ''; ?>" data-provider="anthropic">
											<strong>Anthropic</strong>
											<span>Claude Haiku</span>
										</button>
										<button type="button" class="ifaq-provider-tile<?php echo ( 'gemini' === $current_provider ) ? ' is-selected' : ''; ?>" data-provider="gemini">
											<strong>Google Gemini</strong>
											<span>gemini-2.5-flash</span>
										</button>
										<button type="button" class="ifaq-provider-tile<?php echo ( 'deepseek' === $current_provider ) ? ' is-selected' : ''; ?>" data-provider="deepseek">
											<strong>DeepSeek</strong>
											<span>deepseek-flash</span>
										</button>
									</div>

									<div id="infinibase-wp-core-notice" class="ifaq-wp-core-box<?php echo ( 'wp_core' === $current_provider ) ? ' is-visible' : ''; ?>">
										<?php if ( $has_wp_ai_client ) : ?>
											<strong><?php esc_html_e( 'WordPress Core AI Client detected.', 'infinibase' ); ?></strong>
										<?php else : ?>
											<strong><?php esc_html_e( 'WordPress 7.0 or higher is required for the Core AI Client.', 'infinibase' ); ?></strong>
										<?php endif; ?>
										<?php esc_html_e( 'No API key is needed on this page — configure your provider credentials in WordPress Settings → Connectors.', 'infinibase' ); ?>
										<a href="https://wordpress.org/documentation/article/settings-connectors-screen/" target="_blank" rel="noopener noreferrer">
											<?php esc_html_e( 'Learn how to configure Connectors →', 'infinibase' ); ?>
										</a>
									</div>

									<div id="infinibase-api-key-row" class="ifaq-field<?php echo ( 'wp_core' === $current_provider ) ? ' ifaq-row-hidden' : ''; ?>">
										<label for="infinibase_api_key"><?php esc_html_e( 'API Key (BYOK)', 'infinibase' ); ?></label>
										<input type="password" name="infinibase_api_key" id="infinibase_api_key" value="<?php echo esc_attr( $masked_key ); ?>" class="ifaq-input-tonal" autocomplete="off" />
										<span class="ifaq-field-hint"><?php esc_html_e( 'Encrypted at rest in WordPress. Never exposed to visitors.', 'infinibase' ); ?></span>
										<div id="infinibase-connection-status" class="ifaq-connection-status" aria-live="polite"></div>
									</div>

									<div id="infinibase-wp-core-test-status" class="ifaq-connection-status" aria-live="polite"></div>

									<div class="ifaq-action-row">
										<button type="submit" name="infinibase_submit" value="1" class="ifaq-btn ifaq-btn-primary">
											<?php esc_html_e( 'Save Settings', 'infinibase' ); ?>
										</button>
										<button type="button" id="infinibase-test-connection-btn" class="ifaq-btn ifaq-btn-tonal">
											<?php esc_html_e( 'Test Connection', 'infinibase' ); ?>
										</button>
									</div>
								</div>
							</div>
						</div>

						<!-- Settings Sub-Tab 2: Behavior & Safety -->
						<div class="ifaq-tab-panel" id="set-panel-behavior">
							<div class="ifaq-settings-row">
								<div class="ifaq-settings-label">
									<h3><?php esc_html_e( 'Response style', 'infinibase' ); ?></h3>
									<p><?php esc_html_e( 'Choose how concise or detailed visitor answers should be.', 'infinibase' ); ?></p>
								</div>
								<div class="ifaq-settings-body">
									<div class="ifaq-choice-grid" id="infinibase-choice-grid">
										<button type="button" class="ifaq-choice-card<?php echo ( 'Brief' === $answer_length ) ? ' is-selected' : ''; ?>" data-answer-length="Brief">
											<div class="ifaq-choice-head">
												<strong><?php esc_html_e( 'Brief', 'infinibase' ); ?></strong>
												<span><?php esc_html_e( 'Default', 'infinibase' ); ?></span>
											</div>
											<p><?php esc_html_e( 'Short, crisp 2–3 sentence answers strictly grounded in your docs.', 'infinibase' ); ?></p>
										</button>
										<button type="button" class="ifaq-choice-card<?php echo ( 'Standard' === $answer_length ) ? ' is-selected' : ''; ?>" data-answer-length="Standard">
											<div class="ifaq-choice-head">
												<strong><?php esc_html_e( 'Standard', 'infinibase' ); ?></strong>
												<span><?php esc_html_e( 'Detailed', 'infinibase' ); ?></span>
											</div>
											<p><?php esc_html_e( 'Comprehensive explanations including all relevant supporting context.', 'infinibase' ); ?></p>
										</button>
									</div>
								</div>
							</div>

							<div class="ifaq-settings-row">
								<div class="ifaq-settings-label">
									<h3><?php esc_html_e( 'Fallback & retention', 'infinibase' ); ?></h3>
									<p><?php esc_html_e( 'Where visitors go if a question is not in your docs, and uninstall cleanup behavior.', 'infinibase' ); ?></p>
								</div>
								<div class="ifaq-settings-body">
									<div class="ifaq-field">
										<label for="infinibase_support_url"><?php esc_html_e( 'Contact Support URL', 'infinibase' ); ?></label>
										<input type="url" name="infinibase_support_url" id="infinibase_support_url" value="<?php echo esc_url( $support_url ); ?>" class="ifaq-input-tonal" placeholder="https://example.com/contact" />
										<span class="ifaq-field-hint"><?php esc_html_e( 'Shown as a "Contact Support" button when InfiniBase cannot find an answer.', 'infinibase' ); ?></span>
									</div>

									<div class="ifaq-toggle-row">
										<div class="ifaq-toggle-copy">
											<strong><?php esc_html_e( 'Keep data on uninstall', 'infinibase' ); ?></strong>
											<span><?php esc_html_e( 'Preserve uploaded documents and settings if you delete InfiniBase.', 'infinibase' ); ?></span>
										</div>
										<label class="ifaq-switch">
											<input type="checkbox" name="infinibase_keep_data_on_uninstall" id="infinibase_keep_data_on_uninstall" value="1" <?php checked( $keep_data, '1' ); ?> />
											<span class="ifaq-switch-track"></span>
										</label>
									</div>

									<div class="ifaq-action-row">
										<button type="submit" name="infinibase_submit" value="1" class="ifaq-btn ifaq-btn-primary">
											<?php esc_html_e( 'Save Settings', 'infinibase' ); ?>
										</button>
									</div>
								</div>
							</div>
						</div>

						<!-- Settings Sub-Tab 3: Support & Diagnostics -->
						<div class="ifaq-tab-panel" id="set-panel-support">
							<div class="ifaq-settings-row">
								<div class="ifaq-settings-label">
									<h3><?php esc_html_e( 'Support & diagnostics', 'infinibase' ); ?></h3>
									<p><?php esc_html_e( 'Found a bug or have a question? Share this safe diagnostic snapshot on the WordPress.org support forum. Private API keys and document text are never included.', 'infinibase' ); ?></p>
								</div>
								<div class="ifaq-settings-body">
									<div class="ifaq-debug-box"><?php echo esc_html( $debug_info ); ?></div>
									<div class="ifaq-action-row">
										<button type="button" class="ifaq-btn ifaq-btn-tonal ifaq-copy-debug-btn" data-debug-info="<?php echo esc_attr( $debug_info ); ?>">
											<span class="ifaq-debug-copy-label"><?php esc_html_e( 'Copy Debug Info', 'infinibase' ); ?></span>
										</button>
										<a href="https://wordpress.org/support/plugin/infinibase/" target="_blank" rel="noopener noreferrer" class="ifaq-btn ifaq-btn-primary">
											<?php esc_html_e( 'Report a Bug / Support', 'infinibase' ); ?>
										</a>
									</div>
								</div>
							</div>
						</div>
					</form>
				</div>

				<!-- Matching Contextual Right Rail -->
				<aside class="ifaq-rail">
					<?php infinibase_render_resources_rail(); ?>
				</aside>
			</div>
		</div>
	</div>
	<?php
}
