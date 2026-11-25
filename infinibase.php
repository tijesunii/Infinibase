<?php
/**
 * Plugin Name: InfiniBase: AI FAQ & Knowledge Base
 * Plugin URI: https://allmytools.xyz/
 * Description: AI-powered FAQ knowledge base using your own API key (BYOK) or WordPress Core AI Client to generate accurate answers from your Markdown docs without hallucinations.
 * Version: 1.0.2
 * Author: THEWPBRO
 * Author URI: https://eben.allmytools.xyz
 * License: GPLv2 or later
 * Text Domain: infinibase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'INFINIBASE_VERSION', '1.0.2' );
define( 'INFINIBASE_DB_VERSION', '1.1' );
define( 'INFINIBASE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'INFINIBASE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

function infinibase_install_or_upgrade_db() {
	global $wpdb;
	$table_name      = $wpdb->prefix . 'infinibase_docs';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		upload_batch_id varchar(100) NOT NULL,
		file_name varchar(255) NOT NULL,
		page_slug varchar(255) NOT NULL,
		anchor_id varchar(255) NOT NULL,
		chunk_text longtext NOT NULL,
		keywords longtext NOT NULL,
		created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
		PRIMARY KEY  (id),
		KEY file_name (file_name(191)),
		FULLTEXT KEY keywords (keywords),
		FULLTEXT KEY search_content (keywords,chunk_text)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'infinibase_db_version', INFINIBASE_DB_VERSION );
}

function infinibase_maybe_upgrade_db() {
	if ( false === get_option( 'infinibase_installed_at' ) ) {
		add_option( 'infinibase_installed_at', time() );
	}
	if ( get_option( 'infinibase_db_version', '' ) !== INFINIBASE_DB_VERSION ) {
		infinibase_install_or_upgrade_db();
	}
}
add_action( 'plugins_loaded', 'infinibase_maybe_upgrade_db' );

function infinibase_activate() {
	infinibase_install_or_upgrade_db();

	if ( false === get_option( 'infinibase_installed_at' ) ) {
		add_option( 'infinibase_installed_at', time() );
	}

	if ( false === get_option( 'infinibase_support_url' ) ) {
		add_option( 'infinibase_support_url', '' );
	}
	if ( false === get_option( 'infinibase_api_key' ) ) {
		add_option( 'infinibase_api_key', '' );
	}
	if ( false === get_option( 'infinibase_keep_data_on_uninstall' ) ) {
		add_option( 'infinibase_keep_data_on_uninstall', '0' );
	}
	if ( false === get_option( 'infinibase_ai_provider' ) ) {
		add_option( 'infinibase_ai_provider', 'openai' );
	}
	if ( false === get_option( 'infinibase_answer_length' ) ) {
		add_option( 'infinibase_answer_length', 'Brief' );
	}
	if ( false === get_option( 'infinibase_shortcode_heading' ) ) {
		add_option( 'infinibase_shortcode_heading', 'Questions' );
	}
	if ( false === get_option( 'infinibase_shortcode_subtitle' ) ) {
		add_option( 'infinibase_shortcode_subtitle', 'The short ones are here. For anything else, ask our AI below.' );
	}
	if ( false === get_option( 'infinibase_static_faqs' ) ) {
		add_option( 'infinibase_static_faqs', infinibase_get_default_static_faqs() );
	}
}
register_activation_hook( __FILE__, 'infinibase_activate' );

function infinibase_get_default_static_faqs() {
	return array(
		array( 'question' => '', 'answer' => '' ),
		array( 'question' => '', 'answer' => '' ),
		array( 'question' => '', 'answer' => '' ),
		array( 'question' => '', 'answer' => '' ),
	);
}

function infinibase_deactivate() {
	wp_clear_scheduled_hook( 'infinibase_enrich_keywords_batch' );
	delete_option( 'infinibase_pending_kw_ids' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'infinibase_deactivate' );

function infinibase_extract_fallback_keywords( $chunk_text ) {
	$stopwords = array(
		'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'had', 'her', 'was',
		'our', 'out', 'get', 'has', 'him', 'his', 'how', 'now', 'see', 'way', 'who', 'did',
		'its', 'let', 'put', 'say', 'she', 'too', 'use', 'what', 'when', 'where', 'which',
		'while', 'with', 'your', 'from', 'have', 'this', 'that', 'they', 'will', 'would',
		'there', 'their', 'about', 'could', 'into', 'than', 'then', 'them', 'these', 'some',
		'such', 'only', 'very', 'just', 'does', 'section', 'please', 'must', 'also', 'more',
	);

	$plain  = wp_strip_all_tags( (string) $chunk_text );
	$tokens = preg_split( '/[^a-z0-9]+/i', strtolower( $plain ) );
	$picked = array();

	if ( is_array( $tokens ) ) {
		foreach ( $tokens as $token ) {
			if ( strlen( $token ) < 3 || in_array( $token, $stopwords, true ) ) {
				continue;
			}
			$picked[ $token ] = true;
			if ( count( $picked ) >= 12 ) {
				break;
			}
		}
	}

	if ( empty( $picked ) ) {
		return sanitize_text_field( mb_substr( $plain, 0, 120 ) );
	}

	return sanitize_text_field( implode( ', ', array_keys( $picked ) ) );
}

function infinibase_generate_keywords_batch( $indexed_texts ) {
	if ( empty( $indexed_texts ) || ! is_array( $indexed_texts ) ) {
		return array();
	}

	$entries = array();
	foreach ( $indexed_texts as $idx => $text ) {
		$snippet   = mb_substr( trim( (string) $text ), 0, 1200 );
		$entries[] = '[' . $idx . "]\n" . $snippet;
	}

	$system_prompt = 'You are a documentation indexing assistant. For each numbered documentation chunk, generate 5 to 10 highly relevant search keywords (including common synonyms a customer might search). Return ONLY a valid JSON object mapping each string index to its comma-separated keywords string, e.g. {"0":"keyword1, keyword2","1":"keyword3, keyword4"}. Do not include markdown code fences or extra commentary.';
	$user_prompt   = "Documentation Chunks:\n\n" . implode( "\n\n---\n\n", $entries );

	$raw_output = infinibase_request_ai_text( $user_prompt, $system_prompt, 1200, 18 );

	if ( is_wp_error( $raw_output ) || ! is_string( $raw_output ) || '' === trim( $raw_output ) ) {
		return array();
	}

	$cleaned = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', trim( $raw_output ) );
	$decoded = json_decode( $cleaned, true );

	if ( ! is_array( $decoded ) && preg_match( '/\{.*\}/s', $raw_output, $matches ) ) {
		$decoded = json_decode( $matches[0], true );
	}

	if ( ! is_array( $decoded ) ) {
		return array();
	}

	$results = array();
	foreach ( $indexed_texts as $idx => $unused ) {
		$key = (string) $idx;
		if ( isset( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) && '' !== trim( $decoded[ $key ] ) ) {
			$results[ $idx ] = sanitize_text_field( $decoded[ $key ] );
		} elseif ( isset( $decoded[ $idx ] ) && is_string( $decoded[ $idx ] ) && '' !== trim( $decoded[ $idx ] ) ) {
			$results[ $idx ] = sanitize_text_field( $decoded[ $idx ] );
		}
	}

	return $results;
}

function infinibase_enqueue_background_keywords( $chunk_ids ) {
	if ( empty( $chunk_ids ) || ! is_array( $chunk_ids ) ) {
		return;
	}

	$existing = get_option( 'infinibase_pending_kw_ids', array() );
	if ( ! is_array( $existing ) ) {
		$existing = array();
	}

	$merged = array_values( array_unique( array_filter( array_map( 'absint', array_merge( $existing, $chunk_ids ) ) ) ) );
	if ( empty( $merged ) ) {
		return;
	}

	update_option( 'infinibase_pending_kw_ids', $merged, false );

	if ( ! wp_next_scheduled( 'infinibase_enrich_keywords_batch' ) ) {
		wp_schedule_single_event( time() + 5, 'infinibase_enrich_keywords_batch' );
	}
}

function infinibase_process_background_keyword_batch() {
	$queue = get_option( 'infinibase_pending_kw_ids', array() );
	if ( ! is_array( $queue ) || empty( $queue ) ) {
		delete_option( 'infinibase_pending_kw_ids' );
		return;
	}

	$batch_ids = array_slice( $queue, 0, 20 );
	$remaining = array_slice( $queue, 20 );

	if ( empty( $remaining ) ) {
		delete_option( 'infinibase_pending_kw_ids' );
	} else {
		update_option( 'infinibase_pending_kw_ids', $remaining, false );
	}

	if ( ! infinibase_is_ai_connected() ) {
		return;
	}

	global $wpdb;
	$table_name    = $wpdb->prefix . 'infinibase_docs';
	$indexed_texts = array();

	foreach ( $batch_ids as $row_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$chunk_text = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT chunk_text FROM %i WHERE id = %d',
				$table_name,
				absint( $row_id )
			)
		);
		if ( ! empty( $chunk_text ) ) {
			$indexed_texts[ (int) $row_id ] = $chunk_text;
		}
	}

	if ( ! empty( $indexed_texts ) ) {
		$ai_keywords = infinibase_generate_keywords_batch( $indexed_texts );
		foreach ( $ai_keywords as $row_id => $keywords ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table_name,
				array( 'keywords' => $keywords ),
				array( 'id' => absint( $row_id ) ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	if ( ! empty( $remaining ) && ! wp_next_scheduled( 'infinibase_enrich_keywords_batch' ) ) {
		wp_schedule_single_event( time() + 5, 'infinibase_enrich_keywords_batch' );
	}
}
add_action( 'infinibase_enrich_keywords_batch', 'infinibase_process_background_keyword_batch' );

function infinibase_encrypt_data( $data ) {
	if ( empty( $data ) ) {
		return $data;
	}
	$encryption_key = defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'fallback-infinibase-key-789';
	$iv_length      = openssl_cipher_iv_length( 'aes-256-cbc' );
	$iv             = openssl_random_pseudo_bytes( $iv_length );
	$encrypted      = openssl_encrypt( $data, 'aes-256-cbc', $encryption_key, 0, $iv );
	return base64_encode( $encrypted . '::' . $iv );
}

function infinibase_decrypt_data( $data ) {
	if ( empty( $data ) ) {
		return $data;
	}
	$encryption_key = defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'fallback-infinibase-key-789';
	$decoded        = base64_decode( $data );
	if ( false === strpos( $decoded, '::' ) ) {
		return $data;
	}
	list( $encrypted_data, $iv ) = explode( '::', $decoded, 2 );
	return openssl_decrypt( $encrypted_data, 'aes-256-cbc', $encryption_key, 0, $iv );
}

function infinibase_mask_api_key( $api_key ) {
	if ( empty( $api_key ) ) {
		return '';
	}
	$length = strlen( $api_key );
	if ( $length <= 8 ) {
		return str_repeat( '*', 8 );
	}
	return substr( $api_key, 0, 4 ) . str_repeat( '*', 8 ) . substr( $api_key, -4 );
}

function infinibase_is_ai_connected() {
	$provider = get_option( 'infinibase_ai_provider', 'openai' );

	if ( 'wp_core' === $provider ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}
		$builder = wp_ai_client_prompt( 'ping' );
		if ( method_exists( $builder, 'is_supported_for_text_generation' ) ) {
			return (bool) $builder->is_supported_for_text_generation();
		}
		return false;
	}

	$encrypted_key = get_option( 'infinibase_api_key', '' );
	$api_key       = infinibase_decrypt_data( $encrypted_key );

	return ! empty( trim( (string) $api_key ) );
}

function infinibase_request_ai_text( $user_prompt, $system_prompt = '', $max_tokens = 300, $timeout = 20 ) {
	$provider = get_option( 'infinibase_ai_provider', 'openai' );

	if ( 'wp_core' === $provider ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'wp_ai_client_unavailable',
				__( 'WordPress Core AI Client requires WordPress 7.0 or higher.', 'infinibase' )
			);
		}

		$builder = wp_ai_client_prompt( $user_prompt );

		if ( ! empty( $system_prompt ) && method_exists( $builder, 'using_system_instruction' ) ) {
			$builder = $builder->using_system_instruction( $system_prompt );
		}
		if ( method_exists( $builder, 'using_max_tokens' ) ) {
			$builder = $builder->using_max_tokens( (int) $max_tokens );
		}
		if ( method_exists( $builder, 'using_model_preference' ) ) {
			$builder = $builder->using_model_preference(
				'gpt-5-mini',
				'claude-haiku-4-5',
				'gemini-2.5-flash'
			);
		}

		if ( method_exists( $builder, 'is_supported_for_text_generation' ) && ! $builder->is_supported_for_text_generation() ) {
			return new WP_Error(
				'wp_ai_client_unconfigured',
				__( 'No active AI provider is configured in WordPress Settings → Connectors.', 'infinibase' )
			);
		}

		$result = $builder->generate_text();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return is_string( $result ) ? trim( $result ) : '';
	}

	$encrypted_key = get_option( 'infinibase_api_key', '' );
	$api_key       = infinibase_decrypt_data( $encrypted_key );

	if ( empty( $api_key ) ) {
		return new WP_Error(
			'missing_api_key',
			__( 'Please configure a valid AI Provider API Key in InfiniBase Settings.', 'infinibase' )
		);
	}

	$output = '';

	if ( 'openai' === $provider ) {
		$messages = array();
		if ( ! empty( $system_prompt ) ) {
			$messages[] = array( 'role' => 'system', 'content' => $system_prompt );
		}
		$messages[] = array( 'role' => 'user', 'content' => $user_prompt );

		// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration
		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'    => 'gpt-5-mini',
						'messages' => $messages,
					)
				),
				'timeout' => $timeout,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $body['choices'][0]['message']['content'] ) ) {
			$output = trim( $body['choices'][0]['message']['content'] );
		}
	} elseif ( 'anthropic' === $provider ) {
		$payload = array(
			'model'      => 'claude-haiku-4-5',
			'max_tokens' => (int) $max_tokens,
			'messages'   => array(
				array( 'role' => 'user', 'content' => $user_prompt ),
			),
		);
		if ( ! empty( $system_prompt ) ) {
			$payload['system'] = $system_prompt;
		}

		// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration
		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => $timeout,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $body['content'][0]['text'] ) ) {
			$output = trim( $body['content'][0]['text'] );
		}
	} elseif ( 'gemini' === $provider ) {
		$payload = array(
			'contents' => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $user_prompt ) ),
				),
			),
		);
		if ( ! empty( $system_prompt ) ) {
			$payload['systemInstruction'] = array(
				'parts' => array( array( 'text' => $system_prompt ) ),
			);
		}

		// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration
		$response = wp_remote_post(
			'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',
			array(
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => $timeout,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
			$output = trim( $body['candidates'][0]['content']['parts'][0]['text'] );
		}
	} elseif ( 'deepseek' === $provider ) {
		$messages = array();
		if ( ! empty( $system_prompt ) ) {
			$messages[] = array( 'role' => 'system', 'content' => $system_prompt );
		}
		$messages[] = array( 'role' => 'user', 'content' => $user_prompt );

		// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration
		$response = wp_remote_post(
			'https://api.deepseek.com/chat/completions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'    => 'deepseek-flash',
						'messages' => $messages,
					)
				),
				'timeout' => $timeout,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $body['choices'][0]['message']['content'] ) ) {
			$output = trim( $body['choices'][0]['message']['content'] );
		}
	}

	return $output;
}

function infinibase_test_ai_connection( $provider = '', $raw_key = '' ) {
	if ( '' === $provider ) {
		$provider = get_option( 'infinibase_ai_provider', 'openai' );
	}

	$provider_labels = array(
		'wp_core'   => __( 'WordPress Core AI Client', 'infinibase' ),
		'openai'    => 'OpenAI',
		'anthropic' => 'Anthropic Claude',
		'gemini'    => 'Google Gemini',
		'deepseek'  => 'DeepSeek',
	);
	$label = isset( $provider_labels[ $provider ] ) ? $provider_labels[ $provider ] : ucfirst( $provider );

	if ( 'wp_core' === $provider ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return array(
				'success' => false,
				'message' => __( 'WordPress 7.0 or higher is required for the Core AI Client.', 'infinibase' ),
			);
		}
		$builder = wp_ai_client_prompt( 'Reply with OK.' );
		if ( ! method_exists( $builder, 'is_supported_for_text_generation' ) || ! $builder->is_supported_for_text_generation() ) {
			return array(
				'success' => false,
				'message' => __( 'No active AI Connector found in WordPress Settings → Connectors.', 'infinibase' ),
			);
		}
		$result = $builder->generate_text();
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}
		return array(
			'success' => true,
			/* translators: %s: AI provider display name. */
			'message' => sprintf( __( 'Connected to %s!', 'infinibase' ), $label ),
		);
	}

	$api_key = trim( (string) $raw_key );
	if ( '' === $api_key || false !== strpos( $api_key, '*' ) ) {
		$encrypted_key = get_option( 'infinibase_api_key', '' );
		$api_key       = trim( (string) infinibase_decrypt_data( $encrypted_key ) );
	}

	if ( '' === $api_key ) {
		return array(
			'success' => false,
			'message' => __( 'No API key provided. Please enter your API key to test the connection.', 'infinibase' ),
		);
	}

	$response = null;
	if ( 'openai' === $provider ) {
		// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration
		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => 'gpt-4o-mini',
						'max_tokens' => 5,
						'messages'   => array( array( 'role' => 'user', 'content' => 'Reply OK.' ) ),
					)
				),
				'timeout' => 12,
			)
		);
	} elseif ( 'anthropic' === $provider ) {
		// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration
		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => 'claude-haiku-4-5-20251001',
						'max_tokens' => 5,
						'messages'   => array( array( 'role' => 'user', 'content' => 'Reply OK.' ) ),
					)
				),
				'timeout' => 12,
			)
		);
	} elseif ( 'gemini' === $provider ) {
		// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration
		$response = wp_remote_post(
			'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',
			array(
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'    => wp_json_encode(
					array(
						'contents' => array(
							array(
								'role'  => 'user',
								'parts' => array( array( 'text' => 'Reply OK.' ) ),
							),
						),
					)
				),
				'timeout' => 12,
			)
		);
	} elseif ( 'deepseek' === $provider ) {
		// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration
		$response = wp_remote_post(
			'https://api.deepseek.com/chat/completions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => 'deepseek-flash',
						'max_tokens' => 5,
						'messages'   => array( array( 'role' => 'user', 'content' => 'Reply OK.' ) ),
					)
				),
				'timeout' => 12,
			)
		);
	}

	if ( is_wp_error( $response ) ) {
		return array(
			'success' => false,
			'message' => $response->get_error_message(),
		);
	}

	$http_code = (int) wp_remote_retrieve_response_code( $response );
	$raw_body  = wp_remote_retrieve_body( $response );
	$body      = json_decode( $raw_body, true );

	if ( $http_code >= 200 && $http_code < 300 ) {
		return array(
			'success' => true,
			/* translators: %s: AI provider display name. */
			'message' => sprintf( __( 'Connected to %s!', 'infinibase' ), $label ),
		);
	}

	$provider_error = '';
	if ( isset( $body['error']['message'] ) && is_string( $body['error']['message'] ) ) {
		$provider_error = trim( $body['error']['message'] );
	} elseif ( isset( $body['message'] ) && is_string( $body['message'] ) ) {
		$provider_error = trim( $body['message'] );
	}

	if ( '' === $provider_error ) {
		/* translators: 1: AI provider name, 2: HTTP status code. */
		$provider_error = sprintf( __( '%1$s returned HTTP %2$d. Please verify your API key and account billing status.', 'infinibase' ), $label, $http_code );
	}

	return array(
		'success' => false,
		'message' => sanitize_text_field( $provider_error ),
	);
}

function infinibase_plugin_action_links( $links ) {
	$custom_links = array(
		'<a href="' . esc_url( admin_url( 'admin.php?page=infinibase' ) ) . '">' . esc_html__( 'Settings', 'infinibase' ) . '</a>',
		'<a href="' . esc_url( admin_url( 'admin.php?page=infinibase-ingestion' ) ) . '">' . esc_html__( 'Knowledge Base', 'infinibase' ) . '</a>',
	);
	return array_merge( $custom_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'infinibase_plugin_action_links' );

function infinibase_plugin_row_meta( $links, $file ) {
	if ( plugin_basename( __FILE__ ) === $file ) {
		$links[] = '<a href="https://www.youtube.com/@thewpbro" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Video Tutorials', 'infinibase' ) . '</a>';
		$links[] = '<a href="https://wordpress.org/support/plugin/infinibase/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Report a Bug', 'infinibase' ) . '</a>';
		$links[] = '<a href="https://wordpress.org/support/plugin/infinibase/reviews/#new-post" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Rate ★★★★★', 'infinibase' ) . '</a>';
	}
	return $links;
}
add_filter( 'plugin_row_meta', 'infinibase_plugin_row_meta', 10, 2 );

function infinibase_admin_footer_text( $footer_text ) {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || false === strpos( (string) $screen->id, 'infinibase' ) ) {
		return $footer_text;
	}

	return sprintf(
		/* translators: 1: WordPress.org review URL, 2: Creator URL. */
		__( 'Enjoying <strong>InfiniBase</strong>? Leave a <a href="%1$s" target="_blank" rel="noopener noreferrer">★★★★★ review</a> on WordPress.org &bull; Built by <a href="%2$s" target="_blank" rel="noopener noreferrer">THEWPBRO</a>.', 'infinibase' ),
		esc_url( 'https://wordpress.org/support/plugin/infinibase/reviews/#new-post' ),
		esc_url( 'https://eben.allmytools.xyz' )
	);
}
add_filter( 'admin_footer_text', 'infinibase_admin_footer_text' );

function infinibase_should_show_review_banner() {
	if ( 'dismissed' === get_option( 'infinibase_review_status', '' ) ) {
		return false;
	}

	$snooze_until = (int) get_option( 'infinibase_review_snooze_until', 0 );
	if ( $snooze_until > time() ) {
		return false;
	}

	$installed_at = (int) get_option( 'infinibase_installed_at', 0 );
	if ( $installed_at <= 0 || ( time() - $installed_at ) < ( 7 * DAY_IN_SECONDS ) ) {
		return false;
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'infinibase_docs';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$doc_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(id) FROM %i', $table_name ) );

	return $doc_count > 0;
}

function infinibase_render_admin_topbar( $active_page = 'kb' ) {
	$kb_url       = admin_url( 'admin.php?page=infinibase-ingestion' );
	$settings_url = admin_url( 'admin.php?page=infinibase' );
	?>
	<header class="ifaq-top-nav">
		<div class="ifaq-top-nav-inner">
			<div class="ifaq-brand">
				<div class="ifaq-brand-mark" aria-hidden="true">
					<svg width="17" height="17" viewBox="0 0 24 24" fill="none">
						<rect x="3" y="5" width="18" height="4.5" rx="2.25" fill="#ffffff"/>
						<rect x="3" y="11.5" width="13.5" height="4.5" rx="2.25" fill="#ffffff"/>
						<path d="M19 15.5L19.8 17.7L22 18.5L19.8 19.3L19 21.5L18.2 19.3L16 18.5L18.2 17.7L19 15.5Z" fill="#ffffff"/>
					</svg>
				</div>
				<span class="ifaq-brand-title">InfiniBase</span>
				<span class="ifaq-brand-ver"><?php echo esc_html( INFINIBASE_VERSION ); ?></span>
			</div>

			<nav class="ifaq-pill-nav" aria-label="<?php esc_attr_e( 'InfiniBase navigation', 'infinibase' ); ?>">
				<a href="<?php echo esc_url( $kb_url ); ?>" class="ifaq-pill-nav-btn<?php echo ( 'kb' === $active_page ) ? ' is-active' : ''; ?>">
					<?php esc_html_e( 'Knowledge Base', 'infinibase' ); ?>
				</a>
				<a href="<?php echo esc_url( $settings_url ); ?>" class="ifaq-pill-nav-btn<?php echo ( 'settings' === $active_page ) ? ' is-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'infinibase' ); ?>
				</a>
			</nav>

			<div class="ifaq-top-links">
				<a class="ifaq-top-link" href="https://www.youtube.com/@thewpbro" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Tutorials', 'infinibase' ); ?></a>
				<a class="ifaq-top-link" href="https://wordpress.org/support/plugin/infinibase/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Support', 'infinibase' ); ?></a>
			</div>
		</div>
	</header>
	<?php
}

function infinibase_render_resources_rail() {
	?>
	<div class="ifaq-rail-card">
		<div class="ifaq-rail-title"><?php esc_html_e( 'Resources & Feedback', 'infinibase' ); ?></div>
		<p class="ifaq-rail-desc"><?php esc_html_e( 'Watch setup walkthroughs, report bugs, or leave a review on WordPress.org.', 'infinibase' ); ?></p>
		<div class="ifaq-rail-links">
			<a class="ifaq-rail-link" href="https://www.youtube.com/@thewpbro" target="_blank" rel="noopener noreferrer">
				<span><?php esc_html_e( 'Video Tutorials (@thewpbro)', 'infinibase' ); ?></span>
				<span aria-hidden="true">&rarr;</span>
			</a>
			<a class="ifaq-rail-link" href="https://wordpress.org/support/plugin/infinibase/" target="_blank" rel="noopener noreferrer">
				<span><?php esc_html_e( 'Report a Bug / Support', 'infinibase' ); ?></span>
				<span aria-hidden="true">&rarr;</span>
			</a>
			<a class="ifaq-rail-link" href="https://wordpress.org/support/plugin/infinibase/reviews/#new-post" target="_blank" rel="noopener noreferrer">
				<span><?php esc_html_e( 'Rate on WordPress.org', 'infinibase' ); ?></span>
				<span aria-hidden="true">&rarr;</span>
			</a>
			<a class="ifaq-rail-link" href="https://eben.allmytools.xyz" target="_blank" rel="noopener noreferrer">
				<span><?php esc_html_e( 'Built by THEWPBRO', 'infinibase' ); ?></span>
				<span aria-hidden="true">&rarr;</span>
			</a>
		</div>
	</div>
	<?php
}

function infinibase_render_review_banner() {
	if ( ! infinibase_should_show_review_banner() ) {
		return;
	}
	?>
	<div id="infinibase-review-banner" class="ifaq-review-banner">
		<div class="ifaq-review-banner-copy">
			<strong><?php esc_html_e( 'Enjoying InfiniBase?', 'infinibase' ); ?></strong>
			<span><?php esc_html_e( 'If it is helping your visitors find answers faster, a quick 5-star review on WordPress.org helps other site owners discover it.', 'infinibase' ); ?></span>
		</div>
		<div class="ifaq-review-actions">
			<a href="https://wordpress.org/support/plugin/infinibase/reviews/#new-post" target="_blank" rel="noopener noreferrer" class="ifaq-btn ifaq-btn-primary ifaq-review-btn" data-review-action="dismiss">
				<?php esc_html_e( 'Leave a 5-Star Review', 'infinibase' ); ?>
			</a>
			<button type="button" class="ifaq-btn ifaq-btn-tonal ifaq-review-btn" data-review-action="snooze">
				<?php esc_html_e( 'Maybe Later', 'infinibase' ); ?>
			</button>
			<button type="button" class="ifaq-btn-ghost ifaq-review-btn" data-review-action="dismiss">
				<?php esc_html_e( 'Don’t show again', 'infinibase' ); ?>
			</button>
		</div>
	</div>
	<?php
}

function infinibase_ajax_review_action() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
	}

	check_ajax_referer( 'infinibase_status_check', 'nonce' );

	$action = isset( $_POST['review_action'] ) ? sanitize_key( wp_unslash( $_POST['review_action'] ) ) : '';
	if ( 'snooze' === $action ) {
		update_option( 'infinibase_review_snooze_until', time() + ( 14 * DAY_IN_SECONDS ) );
	} else {
		update_option( 'infinibase_review_status', 'dismissed' );
	}

	wp_send_json_success();
}
add_action( 'wp_ajax_infinibase_review_action', 'infinibase_ajax_review_action' );

function infinibase_get_debug_info_text() {
	global $wpdb, $wp_version;
	$table_name = $wpdb->prefix . 'infinibase_docs';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$stats      = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(DISTINCT file_name) AS doc_count, COUNT(id) AS section_count FROM %i', $table_name ) );
	$provider   = get_option( 'infinibase_ai_provider', 'openai' );
	$connected  = infinibase_is_ai_connected() ? 'Yes' : 'No';

	$lines = array(
		'InfiniBase Version: ' . INFINIBASE_VERSION,
		'WordPress Version: ' . $wp_version,
		'PHP Version: ' . PHP_VERSION,
		'AI Provider: ' . $provider . ' (Connected: ' . $connected . ')',
		'Uploaded Documents: ' . ( $stats ? (int) $stats->doc_count : 0 ) . ' (' . ( $stats ? (int) $stats->section_count : 0 ) . ' sections)',
		'DB Schema Version: ' . get_option( 'infinibase_db_version', '1.0' ),
	);

	return implode( "\n", $lines );
}

function infinibase_render_help_bar() {
	$debug_info = infinibase_get_debug_info_text();
	?>
	<div class="card ifaq-help-bar">
		<div class="ifaq-help-bar-inner">
			<div class="ifaq-help-bar-text">
				<strong><?php esc_html_e( 'Need Help or Found a Bug?', 'infinibase' ); ?></strong>
				<span><?php esc_html_e( 'Report issues on the WordPress.org support forum or watch setup tutorials on YouTube.', 'infinibase' ); ?></span>
			</div>
			<div class="ifaq-help-bar-actions">
				<a href="https://wordpress.org/support/plugin/infinibase/" target="_blank" rel="noopener noreferrer" class="button">
					<?php esc_html_e( 'Report a Bug / Support', 'infinibase' ); ?>
				</a>
				<a href="https://www.youtube.com/@thewpbro" target="_blank" rel="noopener noreferrer" class="button">
					<?php esc_html_e( 'Watch Tutorials (@thewpbro)', 'infinibase' ); ?>
				</a>
				<button type="button" class="button ifaq-copy-debug-btn" data-debug-info="<?php echo esc_attr( $debug_info ); ?>">
					<span class="ifaq-debug-copy-label"><?php esc_html_e( 'Copy Debug Info', 'infinibase' ); ?></span>
				</button>
			</div>
		</div>
	</div>
	<?php
}

require_once INFINIBASE_PLUGIN_DIR . 'includes/rest-api.php';
require_once INFINIBASE_PLUGIN_DIR . 'includes/shortcode.php';

if ( is_admin() ) {
	require_once INFINIBASE_PLUGIN_DIR . 'admin/settings-page.php';
	require_once INFINIBASE_PLUGIN_DIR . 'admin/file-uploader.php';
}
