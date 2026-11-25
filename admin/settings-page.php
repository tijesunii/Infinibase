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
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'InfiniBase Settings', 'infinibase' ); ?></h1>
		<?php infinibase_render_review_banner(); ?>
		<form method="post" action="">
			<?php wp_nonce_field( 'infinibase_save_settings', 'infinibase_settings_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row"><label for="infinibase_ai_provider"><?php esc_html_e( 'AI Provider', 'infinibase' ); ?></label></th>
					<td>
						<select name="infinibase_ai_provider" id="infinibase_ai_provider">
							<option value="wp_core" <?php selected( $current_provider, 'wp_core' ); ?>><?php esc_html_e( 'WordPress Core AI Client (Connectors)', 'infinibase' ); ?></option>
							<option value="openai" <?php selected( $current_provider, 'openai' ); ?>>OpenAI (BYOK)</option>
							<option value="anthropic" <?php selected( $current_provider, 'anthropic' ); ?>>Anthropic (BYOK)</option>
							<option value="gemini" <?php selected( $current_provider, 'gemini' ); ?>>Google Gemini (BYOK)</option>
							<option value="deepseek" <?php selected( $current_provider, 'deepseek' ); ?>>DeepSeek (BYOK)</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Choose WordPress Core AI Client to use site-wide credentials from Settings → Connectors, or select a direct BYOK provider below.', 'infinibase' ); ?>
						</p>
						<div id="infinibase-wp-core-notice" class="ifaq-wp-core-notice<?php echo ( 'wp_core' === $current_provider ) ? ' is-visible' : ''; ?>">
							<p>
								<?php if ( $has_wp_ai_client ) : ?>
									<strong><?php esc_html_e( 'WordPress Core AI Client detected.', 'infinibase' ); ?></strong>
								<?php else : ?>
									<strong><?php esc_html_e( 'Note: WordPress 7.0 or higher is required for the Core AI Client.', 'infinibase' ); ?></strong>
								<?php endif; ?>
								<?php esc_html_e( 'When using the WordPress Core AI Client, no API key is needed on this page. Instead, install a provider connector plugin and configure your credentials in WordPress Settings → Connectors.', 'infinibase' ); ?>
								<a href="https://wordpress.org/documentation/article/settings-connectors-screen/" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Learn how to configure WordPress Settings → Connectors →', 'infinibase' ); ?>
								</a>
							</p>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="infinibase_answer_length"><?php esc_html_e( 'Answer Length', 'infinibase' ); ?></label></th>
					<td>
						<select name="infinibase_answer_length" id="infinibase_answer_length">
							<option value="Brief" <?php selected( $answer_length, 'Brief' ); ?>><?php esc_html_e( 'Brief (Concise)', 'infinibase' ); ?></option>
							<option value="Standard" <?php selected( $answer_length, 'Standard' ); ?>><?php esc_html_e( 'Standard (Detailed)', 'infinibase' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Select your preferred verbosity for generated answers.', 'infinibase' ); ?></p>
					</td>
				</tr>
				<tr id="infinibase-api-key-row" class="<?php echo ( 'wp_core' === $current_provider ) ? 'ifaq-row-hidden' : ''; ?>">
					<th scope="row"><label for="infinibase_api_key"><?php esc_html_e( 'API Key (BYOK)', 'infinibase' ); ?></label></th>
					<td>
						<input type="password" name="infinibase_api_key" id="infinibase_api_key" value="<?php echo esc_attr( $masked_key ); ?>" class="regular-text" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'Your API Key is stored encrypted at rest. Used when OpenAI, Anthropic, Google Gemini, or DeepSeek is selected above.', 'infinibase' ); ?></p>
						<div id="infinibase-connection-status" class="ifaq-connection-status" aria-live="polite"></div>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="infinibase_support_url"><?php esc_html_e( 'Contact Support URL', 'infinibase' ); ?></label></th>
					<td>
						<input type="url" name="infinibase_support_url" id="infinibase_support_url" value="<?php echo esc_url( $support_url ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'The fallback URL users are directed to if the AI cannot find an answer in your documentation.', 'infinibase' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Data Retention', 'infinibase' ); ?></th>
					<td>
						<label for="infinibase_keep_data_on_uninstall">
							<input type="checkbox" name="infinibase_keep_data_on_uninstall" id="infinibase_keep_data_on_uninstall" value="1" <?php checked( $keep_data, '1' ); ?> />
							<?php esc_html_e( 'Keep data on uninstall', 'infinibase' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'If checked, your custom knowledge base tables and settings will NOT be deleted when you uninstall the plugin.', 'infinibase' ); ?></p>
					</td>
				</tr>
			</table>

			<div class="ifaq-settings-actions">
				<?php submit_button( esc_html__( 'Save Settings', 'infinibase' ), 'primary', 'infinibase_submit', false ); ?>
				<button type="button" id="infinibase-test-connection-btn" class="button">
					<?php esc_html_e( 'Test Connection', 'infinibase' ); ?>
				</button>
			</div>
			<div id="infinibase-wp-core-test-status" class="ifaq-connection-status" aria-live="polite"></div>
		</form>

		<?php infinibase_render_help_bar(); ?>
	</div>
	<?php
}
