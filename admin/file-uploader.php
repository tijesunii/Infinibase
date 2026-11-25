<?php
/**
 * InfiniBase - Knowledge Base Management & Markdown Ingestion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function infinibase_add_ingestion_menu() {
	add_submenu_page(
		'infinibase',
		esc_html__( 'Manage Knowledge Base', 'infinibase' ),
		esc_html__( 'Knowledge Base', 'infinibase' ),
		'manage_options',
		'infinibase-ingestion',
		'infinibase_ingestion_page_html'
	);
}
add_action( 'admin_menu', 'infinibase_add_ingestion_menu' );

function infinibase_admin_enqueue_scripts( $hook_suffix ) {
	if ( false === strpos( $hook_suffix, 'infinibase' ) ) {
		return;
	}

	wp_enqueue_style(
		'infinibase-admin-style',
		INFINIBASE_PLUGIN_URL . 'assets/css/infinibase-admin.css',
		array(),
		INFINIBASE_VERSION
	);

	wp_enqueue_script(
		'infinibase-admin-script',
		INFINIBASE_PLUGIN_URL . 'assets/js/infinibase-admin.js',
		array(),
		INFINIBASE_VERSION,
		true
	);

	wp_localize_script(
		'infinibase-admin-script',
		'infinibaseAdminData',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'infinibase_status_check' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'infinibase_admin_enqueue_scripts' );

function infinibase_get_processing_file_names() {
	$pending_ids = get_option( 'infinibase_pending_kw_ids', array() );
	if ( ! is_array( $pending_ids ) || empty( $pending_ids ) ) {
		return array();
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'infinibase_docs';
	$processing = array();

	foreach ( $pending_ids as $row_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$fname = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT file_name FROM %i WHERE id = %d',
				$table_name,
				absint( $row_id )
			)
		);
		if ( ! empty( $fname ) ) {
			$processing[ $fname ] = true;
		}
	}

	return array_keys( $processing );
}

function infinibase_ajax_check_status() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
	}

	check_ajax_referer( 'infinibase_status_check', 'nonce' );

	$pending_ids = get_option( 'infinibase_pending_kw_ids', array() );
	if ( is_array( $pending_ids ) && ! empty( $pending_ids ) && function_exists( 'infinibase_process_background_keyword_batch' ) ) {
		infinibase_process_background_keyword_batch();
	}

	$still_processing = infinibase_get_processing_file_names();

	wp_send_json_success(
		array(
			'processingFiles' => $still_processing,
			'allReady'        => empty( $still_processing ),
		)
	);
}
add_action( 'wp_ajax_infinibase_check_status', 'infinibase_ajax_check_status' );

function infinibase_make_anchor_id( $heading ) {
	$text = trim( (string) $heading );
	if ( function_exists( 'remove_accents' ) ) {
		$text = remove_accents( $text );
	}
	$text = preg_replace( '/[\x{2010}-\x{2015}\x{2212}]/u', '-', $text );
	$text = preg_replace( '/[^a-zA-Z0-9\s\-]/', ' ', $text );
	$text = strtolower( trim( preg_replace( '/[\s\-]+/', '-', $text ), '-' ) );

	if ( '' === $text ) {
		return 'section';
	}

	return substr( $text, 0, 120 );
}

function infinibase_extract_node_text( DOMNode $node ) {
	$tag = strtolower( $node->nodeName );

	if ( 'ul' === $tag || 'ol' === $tag ) {
		$lines = array();
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType && 'li' === strtolower( $child->nodeName ) ) {
				$item_text = trim( preg_replace( '/\s+/u', ' ', $child->textContent ) );
				if ( '' !== $item_text ) {
					$lines[] = '- ' . $item_text;
				}
			}
		}
		return implode( "\n", $lines );
	}

	if ( 'table' === $tag ) {
		$rows = array();
		$tr_nodes = $node->getElementsByTagName( 'tr' );
		foreach ( $tr_nodes as $tr ) {
			$cells = array();
			foreach ( $tr->childNodes as $cell ) {
				if ( XML_ELEMENT_NODE === $cell->nodeType && in_array( strtolower( $cell->nodeName ), array( 'th', 'td' ), true ) ) {
					$cell_text = trim( preg_replace( '/\s+/u', ' ', $cell->textContent ) );
					if ( '' !== $cell_text ) {
						$cells[] = $cell_text;
					}
				}
			}
			if ( ! empty( $cells ) ) {
				$rows[] = implode( ' | ', $cells );
			}
		}
		return implode( "\n", $rows );
	}

	return trim( preg_replace( '/[ \t]+/u', ' ', $node->textContent ) );
}

function infinibase_parse_markdown_chunks( $markdown_content, $max_chunk_chars = 1500 ) {
	if ( ! class_exists( '\InfiniBase\Vendor\Parsedown' ) ) {
		require_once INFINIBASE_PLUGIN_DIR . 'includes/class-infinibase-parsedown.php';
	}

	$markdown_content = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $markdown_content );
	$markdown_content = str_replace( array( "\r\n", "\r" ), "\n", $markdown_content );

	$parsedown    = new \InfiniBase\Vendor\Parsedown();
	$html_content = $parsedown->text( $markdown_content );

	$wrapped_html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="infinibase-root">' . $html_content . '</div></body></html>';

	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	@$dom->loadHTML( $wrapped_html );
	libxml_clear_errors();

	$root = $dom->getElementById( 'infinibase-root' );
	if ( ! $root ) {
		return array();
	}

	$chunks             = array();
	$heading_stack      = array();
	$current_anchor     = 'introduction';
	$current_breadcrumb = '';
	$current_blocks     = array();
	$current_len        = 0;

	$flush_chunk = static function () use ( &$chunks, &$current_anchor, &$current_breadcrumb, &$current_blocks, &$current_len ) {
		$body = trim( implode( "\n\n", $current_blocks ) );
		if ( '' === $body ) {
			$current_blocks = array();
			$current_len    = 0;
			return;
		}

		$full_text = ( '' !== $current_breadcrumb ) ? "Section: {$current_breadcrumb}\n{$body}" : $body;
		$chunks[]  = array(
			'anchor' => $current_anchor,
			'text'   => $full_text,
		);

		$current_blocks = array();
		$current_len    = 0;
	};

	foreach ( $root->childNodes as $node ) {
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			continue;
		}

		$tag = strtolower( $node->nodeName );

		if ( 'hr' === $tag ) {
			continue;
		}

		if ( preg_match( '/^h([1-6])$/', $tag, $matches ) ) {
			$flush_chunk();

			$level        = (int) $matches[1];
			$heading_text = trim( preg_replace( '/\s+/u', ' ', $node->textContent ) );

			foreach ( array_keys( $heading_stack ) as $existing_level ) {
				if ( $existing_level >= $level ) {
					unset( $heading_stack[ $existing_level ] );
				}
			}
			if ( '' !== $heading_text ) {
				$heading_stack[ $level ] = $heading_text;
			}
			ksort( $heading_stack );

			$breadcrumb_parts = array();
			foreach ( $heading_stack as $lvl => $lbl ) {
				if ( $lvl > 1 || 1 === count( $heading_stack ) ) {
					$breadcrumb_parts[] = $lbl;
				}
			}

			$current_breadcrumb = implode( ' > ', $breadcrumb_parts );
			$current_anchor     = infinibase_make_anchor_id( $heading_text );
			continue;
		}

		$block_text = infinibase_extract_node_text( $node );
		if ( '' === $block_text ) {
			continue;
		}

		$block_len = strlen( $block_text );
		if ( $current_len > 0 && ( $current_len + $block_len ) > $max_chunk_chars ) {
			$flush_chunk();
		}

		$current_blocks[] = $block_text;
		$current_len     += $block_len + 2;
	}

	$flush_chunk();

	return $chunks;
}

function infinibase_generate_keywords( $chunk_text ) {
	$prompt = "Generate 5 to 10 highly relevant search keywords for the following documentation text. Return ONLY the keywords separated by commas. Do not include any other text, formatting, or explanation.\n\nText:\n" . $chunk_text;
	$result = infinibase_request_ai_text( $prompt, '', 100, 15 );

	if ( is_wp_error( $result ) || empty( $result ) ) {
		return '';
	}

	return sanitize_text_field( $result );
}

function infinibase_handle_upload() {
	$upload_nonce = isset( $_POST['infinibase_upload_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['infinibase_upload_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $upload_nonce, 'infinibase_upload_file' ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! infinibase_is_ai_connected() ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Please connect an AI Provider in InfiniBase → Settings (either via WordPress Settings → Connectors or a BYOK API Key) before uploading documentation.', 'infinibase' ) . '</p></div>';
		return;
	}

	if ( ! isset( $_FILES['infinibase_md_file']['tmp_name'], $_FILES['infinibase_md_file']['name'] ) || empty( $_FILES['infinibase_md_file']['tmp_name'] ) ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Please select a file.', 'infinibase' ) . '</p></div>';
		return;
	}

	$uploaded_file_name = sanitize_file_name( wp_unslash( $_FILES['infinibase_md_file']['name'] ) );
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$uploaded_tmp_name  = $_FILES['infinibase_md_file']['tmp_name'];

	if ( ! is_uploaded_file( $uploaded_tmp_name ) ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Invalid file upload.', 'infinibase' ) . '</p></div>';
		return;
	}

	$filetype = wp_check_filetype( $uploaded_file_name, array( 'md' => 'text/markdown' ) );
	if ( 'md' !== $filetype['ext'] ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Only .md files are allowed.', 'infinibase' ) . '</p></div>';
		return;
	}

	$base_page_url = '';
	if ( ! empty( $_POST['infinibase_base_page_url'] ) ) {
		$base_page_url = esc_url_raw( wp_unslash( $_POST['infinibase_base_page_url'] ) );
	} else {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Base Page URL is required.', 'infinibase' ) . '</p></div>';
		return;
	}

	$file_name    = $uploaded_file_name;
	$file_content = file_get_contents( $uploaded_tmp_name );
	$chunks       = infinibase_parse_markdown_chunks( $file_content );

	global $wpdb;
	$table_name = $wpdb->prefix . 'infinibase_docs';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( $table_name, array( 'file_name' => $file_name ), array( '%s' ) );

	$upload_batch_id     = uniqid( 'batch_', true );
	$inserted_count      = 0;
	$pending_bg_ids      = array();
	$upload_start        = microtime( true );
	$time_budget_seconds = 18;
	$chunk_batches       = array_chunk( $chunks, 20 );

	foreach ( $chunk_batches as $batch ) {
		$ai_keywords_map = array();
		$within_budget   = ( microtime( true ) - $upload_start ) < $time_budget_seconds;

		if ( $within_budget ) {
			$indexed_texts = array();
			foreach ( $batch as $local_idx => $chunk ) {
				$indexed_texts[ $local_idx ] = $chunk['text'];
			}
			$ai_keywords_map = infinibase_generate_keywords_batch( $indexed_texts );
		}

		foreach ( $batch as $local_idx => $chunk ) {
			$has_ai_keywords = isset( $ai_keywords_map[ $local_idx ] ) && '' !== trim( $ai_keywords_map[ $local_idx ] );
			$keywords        = $has_ai_keywords ? $ai_keywords_map[ $local_idx ] : infinibase_extract_fallback_keywords( $chunk['text'] );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$inserted = $wpdb->insert(
				$table_name,
				array(
					'upload_batch_id' => $upload_batch_id,
					'file_name'       => $file_name,
					'page_slug'       => $base_page_url,
					'anchor_id'       => $chunk['anchor'],
					'chunk_text'      => $chunk['text'],
					'keywords'        => $keywords,
					'created_at'      => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( $inserted ) {
				$inserted_count++;
				if ( ! $has_ai_keywords && ! empty( $wpdb->insert_id ) ) {
					$pending_bg_ids[] = (int) $wpdb->insert_id;
				}
			}
		}
	}

	if ( ! empty( $pending_bg_ids ) ) {
		infinibase_enqueue_background_keywords( $pending_bg_ids );
	}

	if ( file_exists( $uploaded_tmp_name ) ) {
		wp_delete_file( $uploaded_tmp_name );
	}

	if ( ! empty( $pending_bg_ids ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
			/* translators: %s: Uploaded Markdown file name. */
			esc_html__( 'Uploaded "%s" — your docs are live now and finishing optimization in the background.', 'infinibase' ),
			esc_html( $file_name )
		) . '</p></div>';
	} else {
		echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
			/* translators: %s: Uploaded Markdown file name. */
			esc_html__( 'Uploaded "%s" — your knowledge base is ready!', 'infinibase' ),
			esc_html( $file_name )
		) . '</p></div>';
	}
}

function infinibase_generate_faqs_from_doc( $selected_file ) {
	global $wpdb;
	$table_name = $wpdb->prefix . 'infinibase_docs';

	if ( '__all__' === $selected_file || empty( $selected_file ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$chunks = $wpdb->get_col( $wpdb->prepare( 'SELECT chunk_text FROM %i ORDER BY id ASC LIMIT 20', $table_name ) );
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$chunks = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT chunk_text FROM %i WHERE file_name = %s ORDER BY id ASC',
				$table_name,
				$selected_file
			)
		);
	}

	if ( empty( $chunks ) ) {
		return new WP_Error( 'empty_doc', __( 'No documentation chunks found for the selected document.', 'infinibase' ) );
	}

	$combined_context = implode( "\n\n---\n\n", $chunks );
	$prompt           = "You are a technical documentation assistant. Based ONLY on the documentation text provided below, generate exactly 4 common Frequently Asked Questions and their concise, accurate answers.\n\nReturn ONLY a valid JSON array containing 4 objects, where each object has 'question' and 'answer' string keys. Do NOT wrap the JSON in markdown code fences or include any extra commentary.\n\nExample format:\n[{\"question\":\"Question 1?\",\"answer\":\"Answer 1.\"},{\"question\":\"Question 2?\",\"answer\":\"Answer 2.\"},{\"question\":\"Question 3?\",\"answer\":\"Answer 3.\"},{\"question\":\"Question 4?\",\"answer\":\"Answer 4.\"}]\n\nDocumentation Text:\n" . $combined_context;

	$raw_output = infinibase_request_ai_text( $prompt, '', 800, 25 );

	if ( is_wp_error( $raw_output ) ) {
		return $raw_output;
	}

	if ( empty( $raw_output ) ) {
		return new WP_Error( 'ai_empty', __( 'The AI Provider did not return a valid response. Check your AI configuration and try again.', 'infinibase' ) );
	}

	$cleaned_json = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', trim( $raw_output ) );
	$decoded      = json_decode( $cleaned_json, true );

	if ( ! is_array( $decoded ) && preg_match( '/\[.*\]/s', $raw_output, $matches ) ) {
		$decoded = json_decode( $matches[0], true );
	}

	if ( ! is_array( $decoded ) || empty( $decoded ) ) {
		return new WP_Error( 'json_parse_error', __( 'Could not parse the AI response into FAQ cards. Please try again.', 'infinibase' ) );
	}

	$generated_faqs = array();
	for ( $i = 0; $i < 4; $i++ ) {
		$q                = isset( $decoded[ $i ]['question'] ) ? sanitize_text_field( $decoded[ $i ]['question'] ) : '';
		$a                = isset( $decoded[ $i ]['answer'] ) ? sanitize_textarea_field( $decoded[ $i ]['answer'] ) : '';
		$generated_faqs[] = array(
			'question' => $q,
			'answer'   => $a,
		);
	}

	return $generated_faqs;
}

function infinibase_handle_delete_file() {
	$delete_nonce = isset( $_POST['infinibase_delete_file_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['infinibase_delete_file_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $delete_nonce, 'infinibase_delete_indexed_file' ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$file_to_delete = isset( $_POST['infinibase_delete_file_name'] ) ? sanitize_file_name( wp_unslash( $_POST['infinibase_delete_file_name'] ) ) : '';
	if ( empty( $file_to_delete ) ) {
		return;
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'infinibase_docs';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$deleted    = $wpdb->delete( $table_name, array( 'file_name' => $file_to_delete ), array( '%s' ) );

	if ( false !== $deleted ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
			/* translators: 1: Deleted Markdown file name, 2: Number of chunks removed. */
			esc_html__( 'Deleted "%1$s" (%2$d chunks removed) from the knowledge base.', 'infinibase' ),
			esc_html( $file_to_delete ),
			absint( $deleted )
		) . '</p></div>';
	}
}

function infinibase_handle_static_faqs_actions() {
	$static_nonce = isset( $_POST['infinibase_static_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['infinibase_static_nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $static_nonce, 'infinibase_save_static_faqs' ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['infinibase_shortcode_heading'] ) ) {
		$raw_heading   = sanitize_text_field( wp_unslash( $_POST['infinibase_shortcode_heading'] ) );
		$clean_heading = mb_substr( trim( $raw_heading ), 0, 9 );
		if ( '' === $clean_heading ) {
			$clean_heading = 'Questions';
		}
		update_option( 'infinibase_shortcode_heading', $clean_heading );
	}

	if ( isset( $_POST['infinibase_shortcode_subtitle'] ) ) {
		$raw_subtitle   = sanitize_text_field( wp_unslash( $_POST['infinibase_shortcode_subtitle'] ) );
		$clean_subtitle = trim( $raw_subtitle );
		if ( '' === $clean_subtitle ) {
			$clean_subtitle = 'The short ones are here. For anything else, ask our AI below.';
		}
		update_option( 'infinibase_shortcode_subtitle', $clean_subtitle );
	}

	if ( isset( $_POST['infinibase_ai_generate_faqs_submit'] ) ) {
		$selected_doc = isset( $_POST['infinibase_source_doc'] ) ? sanitize_text_field( wp_unslash( $_POST['infinibase_source_doc'] ) ) : '__all__';
		$result       = infinibase_generate_faqs_from_doc( $selected_doc );

		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		} else {
			update_option( 'infinibase_static_faqs', $result );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Successfully generated and saved 4 FAQs using AI! You can review or edit them in the cards below.', 'infinibase' ) . '</p></div>';
		}
		return;
	}

	if ( isset( $_POST['infinibase_delete_all_cards_submit'] ) ) {
		$empty_cards = array(
			array( 'question' => '', 'answer' => '' ),
			array( 'question' => '', 'answer' => '' ),
			array( 'question' => '', 'answer' => '' ),
			array( 'question' => '', 'answer' => '' ),
		);
		update_option( 'infinibase_static_faqs', $empty_cards );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All 4 predefined FAQ cards have been cleared.', 'infinibase' ) . '</p></div>';
		return;
	}

	$questions = isset( $_POST['infinibase_card_q'] ) && is_array( $_POST['infinibase_card_q'] )
		? array_map( 'sanitize_text_field', wp_unslash( $_POST['infinibase_card_q'] ) )
		: array();
	$answers   = isset( $_POST['infinibase_card_a'] ) && is_array( $_POST['infinibase_card_a'] )
		? array_map( 'sanitize_textarea_field', wp_unslash( $_POST['infinibase_card_a'] ) )
		: array();

	if ( isset( $_POST['infinibase_delete_single_card'] ) && '' !== $_POST['infinibase_delete_single_card'] ) {
		$card_idx = intval( $_POST['infinibase_delete_single_card'] );

		$updated_cards = array();
		for ( $i = 0; $i < 4; $i++ ) {
			if ( $i === $card_idx ) {
				$updated_cards[] = array( 'question' => '', 'answer' => '' );
			} else {
				$updated_cards[] = array(
					'question' => isset( $questions[ $i ] ) ? $questions[ $i ] : '',
					'answer'   => isset( $answers[ $i ] ) ? $answers[ $i ] : '',
				);
			}
		}

		update_option( 'infinibase_static_faqs', $updated_cards );
		echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
			/* translators: %d: FAQ card number (1-4). */
			esc_html__( 'FAQ Card #%d cleared and saved.', 'infinibase' ),
			absint( $card_idx + 1 )
		) . '</p></div>';
		return;
	}

	if ( isset( $_POST['infinibase_save_static_submit'] ) ) {
		$saved_cards = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$saved_cards[] = array(
				'question' => isset( $questions[ $i ] ) ? $questions[ $i ] : '',
				'answer'   => isset( $answers[ $i ] ) ? $answers[ $i ] : '',
			);
		}

		update_option( 'infinibase_static_faqs', $saved_cards );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Shortcode heading, subtitle, and 4 FAQ cards saved.', 'infinibase' ) . '</p></div>';
	}
}

function infinibase_ingestion_page_html() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	infinibase_handle_upload();
	infinibase_handle_delete_file();
	infinibase_handle_static_faqs_actions();

	$retained_url = '';
	$upload_nonce = isset( $_POST['infinibase_upload_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['infinibase_upload_nonce'] ) ) : '';
	if ( wp_verify_nonce( $upload_nonce, 'infinibase_upload_file' ) && isset( $_POST['infinibase_base_page_url'] ) ) {
		$retained_url = esc_url_raw( wp_unslash( $_POST['infinibase_base_page_url'] ) );
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'infinibase_docs';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$files      = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT file_name, page_slug, COUNT(id) as chunks, MAX(created_at) as last_updated FROM %i GROUP BY file_name, page_slug ORDER BY last_updated DESC',
			$table_name
		)
	);

	$shortcode_heading  = get_option( 'infinibase_shortcode_heading', 'Questions' );
	$shortcode_subtitle = get_option( 'infinibase_shortcode_subtitle', 'The short ones are here. For anything else, ask our AI below.' );
	$static_faqs        = get_option( 'infinibase_static_faqs', array() );
	if ( ! is_array( $static_faqs ) ) {
		$static_faqs = array();
	}

	for ( $i = 0; $i < 4; $i++ ) {
		if ( ! isset( $static_faqs[ $i ] ) || ! is_array( $static_faqs[ $i ] ) ) {
			$static_faqs[ $i ] = array(
				'question' => '',
				'answer'   => '',
			);
		}
	}

	$selected_source_doc = '__all__';
	$static_nonce        = isset( $_POST['infinibase_static_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['infinibase_static_nonce'] ) ) : '';
	if ( wp_verify_nonce( $static_nonce, 'infinibase_save_static_faqs' ) && isset( $_POST['infinibase_source_doc'] ) ) {
		$selected_source_doc = sanitize_text_field( wp_unslash( $_POST['infinibase_source_doc'] ) );
	}

	$ai_connected     = infinibase_is_ai_connected();
	$processing_files = infinibase_get_processing_file_names();
	$active_kb_tab    = wp_verify_nonce( $static_nonce, 'infinibase_save_static_faqs' ) ? 'panel-faqs' : 'panel-docs';

	$total_docs     = is_array( $files ) ? count( $files ) : 0;
	$total_sections = 0;
	if ( ! empty( $files ) ) {
		foreach ( $files as $file_item ) {
			$total_sections += (int) $file_item->chunks;
		}
	}
	$filled_faqs = 0;
	foreach ( $static_faqs as $faq_item ) {
		if ( '' !== trim( (string) $faq_item['question'] ) || '' !== trim( (string) $faq_item['answer'] ) ) {
			$filled_faqs++;
		}
	}
	?>
	<div class="ifaq-app-wrap">
		<?php infinibase_render_admin_topbar( 'kb' ); ?>

		<section class="ifaq-page-hero">
			<div class="ifaq-page-hero-inner">
				<div>
					<h1 class="ifaq-hero-heading"><?php esc_html_e( 'Knowledge Base', 'infinibase' ); ?></h1>
					<p class="ifaq-hero-desc"><?php esc_html_e( 'Upload Markdown docs and curate starter FAQ cards for your AI assistant.', 'infinibase' ); ?></p>
				</div>
				<span class="ifaq-hero-meta-mono">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: Number of uploaded documents. */
							_n( '%d document', '%d documents', $total_docs, 'infinibase' ),
							$total_docs
						) . ' · ' . sprintf(
							/* translators: %d: Number of searchable sections. */
							_n( '%d section', '%d sections', $total_sections, 'infinibase' ),
							$total_sections
						)
					);
					?>
				</span>
			</div>
		</section>

		<div class="ifaq-container">
			<?php infinibase_render_review_banner(); ?>

			<div class="ifaq-kb-layout">
				<div>
					<div class="ifaq-subtabs" data-group="kb">
						<button type="button" class="ifaq-subtab<?php echo ( 'panel-docs' === $active_kb_tab ) ? ' is-active' : ''; ?>" data-tab="panel-docs">
							<?php esc_html_e( 'Documents', 'infinibase' ); ?>
							<span class="ifaq-subtab-badge"><?php echo esc_html( $total_docs ); ?></span>
						</button>
						<button type="button" class="ifaq-subtab<?php echo ( 'panel-faqs' === $active_kb_tab ) ? ' is-active' : ''; ?>" data-tab="panel-faqs">
							<?php esc_html_e( 'FAQ Cards & Display', 'infinibase' ); ?>
							<span class="ifaq-subtab-badge"><?php echo esc_html( $filled_faqs ); ?></span>
						</button>
					</div>

					<!-- Sub-tab 1: Documents -->
					<div class="ifaq-tab-panel<?php echo ( 'panel-docs' === $active_kb_tab ) ? ' is-active' : ''; ?>" id="panel-docs">
						<?php if ( ! $ai_connected ) : ?>
							<div class="ifaq-inline-alert is-warning">
								<p>
									<?php esc_html_e( 'AI Provider not connected. Please connect your AI Provider in Settings before uploading documentation.', 'infinibase' ); ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=infinibase' ) ); ?>">
										<?php esc_html_e( 'Go to Settings →', 'infinibase' ); ?>
									</a>
								</p>
							</div>
						<?php endif; ?>

						<form method="post" enctype="multipart/form-data" action="" class="ifaq-upload-surface">
							<?php wp_nonce_field( 'infinibase_upload_file', 'infinibase_upload_nonce' ); ?>
							<div class="ifaq-upload-grid">
								<div class="ifaq-field">
									<label for="infinibase_base_page_url"><?php esc_html_e( 'Base Page URL', 'infinibase' ); ?></label>
									<input type="url" name="infinibase_base_page_url" id="infinibase_base_page_url" class="ifaq-input-tonal" placeholder="https://example.com/docs" value="<?php echo esc_attr( $retained_url ); ?>" <?php disabled( ! $ai_connected ); ?> required />
								</div>
								<div class="ifaq-field">
									<label for="infinibase_md_file"><?php esc_html_e( 'Documentation File', 'infinibase' ); ?></label>
									<label class="ifaq-drop-trigger<?php echo ! $ai_connected ? ' is-disabled' : ''; ?>">
										<span class="ifaq-drop-trigger-left">
											<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
											<span class="ifaq-drop-trigger-name" id="infinibase-file-label"><?php esc_html_e( 'Choose Markdown file...', 'infinibase' ); ?></span>
										</span>
										<span class="ifaq-drop-ext">.md</span>
										<input type="file" name="infinibase_md_file" id="infinibase_md_file" accept=".md" class="ifaq-file-input-hidden" <?php disabled( ! $ai_connected ); ?> required />
									</label>
								</div>
							</div>
							<div class="ifaq-upload-footer">
								<span class="ifaq-field-hint"><?php esc_html_e( 'Used to generate deep-link "Read more" citations for every AI answer.', 'infinibase' ); ?></span>
								<button type="submit" name="infinibase_upload_submit" value="1" class="ifaq-btn ifaq-btn-primary" <?php disabled( ! $ai_connected ); ?>>
									<?php esc_html_e( 'Upload & Process', 'infinibase' ); ?>
								</button>
							</div>
						</form>

						<?php if ( ! empty( $files ) ) : ?>
							<div class="ifaq-doc-list-clean">
								<?php
								foreach ( $files as $file ) :
									/* translators: %s: Markdown file name to delete. */
									$delete_confirm_msg = sprintf( __( 'Delete "%s" from your knowledge base?', 'infinibase' ), $file->file_name );
									$is_processing      = in_array( $file->file_name, $processing_files, true );
									$formatted_date     = mysql2date( get_option( 'date_format' ), $file->last_updated );
									?>
									<div class="ifaq-doc-row">
										<div class="ifaq-doc-row-main">
											<div class="ifaq-doc-row-title">
												<span class="ifaq-doc-filename"><?php echo esc_html( $file->file_name ); ?></span>
												<span class="ifaq-status-badge <?php echo $is_processing ? 'is-processing' : 'is-ready'; ?>" data-file-status="<?php echo esc_attr( $file->file_name ); ?>">
													<span class="ifaq-status-label"><?php echo $is_processing ? esc_html__( 'Optimizing...', 'infinibase' ) : esc_html__( 'Ready', 'infinibase' ); ?></span>
												</span>
											</div>
											<div class="ifaq-doc-meta-line">
												<a href="<?php echo esc_url( $file->page_slug ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $file->page_slug ); ?></a>
												<span aria-hidden="true">&middot;</span>
												<span class="ifaq-doc-meta-mono">
													<?php
													/* translators: %d: Number of sections in the document. */
													echo esc_html( sprintf( _n( '%d section', '%d sections', (int) $file->chunks, 'infinibase' ), (int) $file->chunks ) );
													?>
												</span>
												<span aria-hidden="true">&middot;</span>
												<span>
													<?php
													/* translators: %s: Formatted last updated date. */
													echo esc_html( sprintf( __( 'Updated %s', 'infinibase' ), $formatted_date ) );
													?>
												</span>
											</div>
										</div>
										<form method="post" action="" class="ifaq-inline-form">
											<?php wp_nonce_field( 'infinibase_delete_indexed_file', 'infinibase_delete_file_nonce' ); ?>
											<input type="hidden" name="infinibase_delete_file_name" value="<?php echo esc_attr( $file->file_name ); ?>" />
											<button type="submit" name="infinibase_delete_file_submit" value="1" class="ifaq-btn-icon-del" title="<?php esc_attr_e( 'Delete document', 'infinibase' ); ?>" aria-label="<?php esc_attr_e( 'Delete document', 'infinibase' ); ?>" data-confirm="<?php echo esc_attr( $delete_confirm_msg ); ?>">
												<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
											</button>
										</form>
									</div>
								<?php endforeach; ?>
							</div>
						<?php else : ?>
							<div class="ifaq-doc-empty"><?php esc_html_e( 'No documents uploaded yet. Upload your first Markdown (.md) file above to get started.', 'infinibase' ); ?></div>
						<?php endif; ?>
					</div>

					<!-- Sub-tab 2: FAQ Cards & Display -->
					<div class="ifaq-tab-panel<?php echo ( 'panel-faqs' === $active_kb_tab ) ? ' is-active' : ''; ?>" id="panel-faqs">
						<form method="post" action="" class="ifaq-faq-stack">
							<?php wp_nonce_field( 'infinibase_save_static_faqs', 'infinibase_static_nonce' ); ?>

							<div class="ifaq-faq-top-grid">
								<div class="ifaq-field">
									<label for="infinibase_shortcode_heading"><?php esc_html_e( 'Section Heading (max 9 chars)', 'infinibase' ); ?></label>
									<input
										type="text"
										name="infinibase_shortcode_heading"
										id="infinibase_shortcode_heading"
										value="<?php echo esc_attr( $shortcode_heading ); ?>"
										maxlength="9"
										class="ifaq-input-tonal"
										placeholder="Questions"
										required
									/>
								</div>

								<div class="ifaq-field">
									<label for="infinibase_source_doc"><?php esc_html_e( 'Generate 4 Starter FAQs', 'infinibase' ); ?></label>
									<div class="ifaq-action-row-inline">
										<select name="infinibase_source_doc" id="infinibase_source_doc" class="ifaq-select-tonal">
											<option value="__all__" <?php selected( $selected_source_doc, '__all__' ); ?>><?php esc_html_e( 'All Documents', 'infinibase' ); ?></option>
											<?php if ( ! empty( $files ) ) : ?>
												<?php foreach ( $files as $file ) : ?>
													<option value="<?php echo esc_attr( $file->file_name ); ?>" <?php selected( $selected_source_doc, $file->file_name ); ?>>
														<?php
														echo esc_html(
															sprintf(
																/* translators: 1: File name, 2: Section count. */
																__( '%1$s (%2$d sections)', 'infinibase' ),
																$file->file_name,
																(int) $file->chunks
															)
														);
														?>
													</option>
												<?php endforeach; ?>
											<?php endif; ?>
										</select>
										<button type="submit" name="infinibase_ai_generate_faqs_submit" value="1" class="ifaq-btn ifaq-btn-tonal" <?php disabled( empty( $files ) || ! $ai_connected ); ?>>
											<?php esc_html_e( 'Generate', 'infinibase' ); ?>
										</button>
									</div>
								</div>

								<div class="ifaq-field ifaq-span-full">
									<label for="infinibase_shortcode_subtitle"><?php esc_html_e( 'Section Subtitle', 'infinibase' ); ?></label>
									<input
										type="text"
										name="infinibase_shortcode_subtitle"
										id="infinibase_shortcode_subtitle"
										value="<?php echo esc_attr( $shortcode_subtitle ); ?>"
										class="ifaq-input-tonal"
										placeholder="The short ones are here. For anything else, ask our AI below."
										required
									/>
								</div>
							</div>

							<div class="ifaq-cards-toolbar">
								<span class="ifaq-cards-toolbar-label"><?php esc_html_e( 'Predefined FAQ Cards (4 Slots)', 'infinibase' ); ?></span>
								<button type="submit" name="infinibase_delete_all_cards_submit" value="1" class="ifaq-btn-ghost-danger" data-confirm="<?php echo esc_attr__( 'Are you sure you want to clear all 4 predefined FAQ cards?', 'infinibase' ); ?>">
									<?php esc_html_e( 'Clear All Cards', 'infinibase' ); ?>
								</button>
							</div>

							<div class="ifaq-faq-cards-grid">
								<?php for ( $i = 0; $i < 4; $i++ ) : ?>
									<div class="ifaq-faq-card">
										<div class="ifaq-faq-card-head">
											<span class="ifaq-faq-card-idx">
												<?php
												echo esc_html(
													sprintf(
														/* translators: %d: FAQ slot number (1-4). */
														__( 'FAQ 0%d', 'infinibase' ),
														absint( $i + 1 )
													)
												);
												?>
											</span>
											<button type="submit" name="infinibase_delete_single_card" value="<?php echo esc_attr( $i ); ?>" class="ifaq-faq-clear-btn">
												<?php esc_html_e( 'Clear', 'infinibase' ); ?>
											</button>
										</div>
										<input
											type="text"
											name="infinibase_card_q[<?php echo esc_attr( $i ); ?>]"
											id="infinibase_card_q_<?php echo esc_attr( $i ); ?>"
											value="<?php echo esc_attr( $static_faqs[ $i ]['question'] ); ?>"
											class="ifaq-input-tonal"
											placeholder="<?php echo esc_attr__( 'Question (e.g., What is your refund policy?)', 'infinibase' ); ?>"
										/>
										<textarea
											name="infinibase_card_a[<?php echo esc_attr( $i ); ?>]"
											id="infinibase_card_a_<?php echo esc_attr( $i ); ?>"
											rows="4"
											class="ifaq-textarea-tonal"
											placeholder="<?php echo esc_attr__( 'Concise answer displayed when this FAQ is expanded...', 'infinibase' ); ?>"
										><?php echo esc_textarea( $static_faqs[ $i ]['answer'] ); ?></textarea>
									</div>
								<?php endfor; ?>
							</div>

							<div class="ifaq-action-row">
								<button type="submit" name="infinibase_save_static_submit" value="1" class="ifaq-btn ifaq-btn-primary">
									<?php esc_html_e( 'Save Heading & FAQ Cards', 'infinibase' ); ?>
								</button>
							</div>
						</form>
					</div>
				</div>

				<!-- Contextual Right Rail -->
				<aside class="ifaq-rail">
					<div class="ifaq-rail-card">
						<div class="ifaq-rail-title"><?php esc_html_e( 'Embed Shortcodes', 'infinibase' ); ?></div>
						<p class="ifaq-rail-desc"><?php esc_html_e( 'Click any theme card to copy and paste into Gutenberg or Elementor.', 'infinibase' ); ?></p>

						<button type="button" class="ifaq-embed-tile ifaq-copy-shortcode-btn" data-shortcode="[infinibase_light]">
							<div class="ifaq-mini-preview is-light" aria-hidden="true">
								<div class="ifaq-mini-bar"></div>
								<div class="ifaq-mini-bar-2"></div>
							</div>
							<div class="ifaq-embed-tile-foot">
								<span class="ifaq-embed-code">[infinibase_light]</span>
								<span class="ifaq-embed-copy ifaq-copy-status"><?php esc_html_e( 'Copy', 'infinibase' ); ?></span>
							</div>
						</button>

						<button type="button" class="ifaq-embed-tile ifaq-copy-shortcode-btn" data-shortcode="[infinibase_dark]">
							<div class="ifaq-mini-preview is-dark" aria-hidden="true">
								<div class="ifaq-mini-bar"></div>
								<div class="ifaq-mini-bar-2"></div>
							</div>
							<div class="ifaq-embed-tile-foot">
								<span class="ifaq-embed-code">[infinibase_dark]</span>
								<span class="ifaq-embed-copy ifaq-copy-status"><?php esc_html_e( 'Copy', 'infinibase' ); ?></span>
							</div>
						</button>
					</div>

					<?php infinibase_render_resources_rail(); ?>
				</aside>
			</div>
		</div>
	</div>
	<?php
}
