<?php
/**
 * Plugin Name: InfiniBase: AI FAQ & Knowledge Base
 * Plugin URI: https://allmytools.xyz/
 * Description: AI-powered FAQ knowledge base using your own API key (BYOK) or WordPress Core AI Client to generate accurate answers from your Markdown docs without hallucinations.
 * Version: 1.0.0
 * Author: THEWPBRO
 * License: GPLv2 or later
 * Text Domain: infinibase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'INFINIBASE_VERSION', '1.0.0' );
define( 'INFINIBASE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'INFINIBASE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

function infinibase_activate() {
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
		FULLTEXT KEY keywords (keywords)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

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
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'infinibase_deactivate' );

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
			'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $api_key,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
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

require_once INFINIBASE_PLUGIN_DIR . 'includes/rest-api.php';
require_once INFINIBASE_PLUGIN_DIR . 'includes/shortcode.php';

if ( is_admin() ) {
	require_once INFINIBASE_PLUGIN_DIR . 'admin/settings-page.php';
	require_once INFINIBASE_PLUGIN_DIR . 'admin/file-uploader.php';
}
