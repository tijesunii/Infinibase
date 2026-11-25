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
}
add_action( 'admin_enqueue_scripts', 'infinibase_admin_enqueue_scripts' );

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

	$upload_batch_id = uniqid( 'batch_', true );
	$inserted_count  = 0;

	foreach ( $chunks as $chunk ) {
		$keywords = infinibase_generate_keywords( $chunk['text'] );
		if ( empty( $keywords ) ) {
			$keywords = wp_strip_all_tags( $chunk['text'] );
		}

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
		}
	}

	if ( file_exists( $uploaded_tmp_name ) ) {
		wp_delete_file( $uploaded_tmp_name );
	}

	echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
		/* translators: 1: Uploaded Markdown file name, 2: Number of chunks created. */
		esc_html__( 'Successfully processed "%1$s" into %2$d chunks and generated keywords.', 'infinibase' ),
		esc_html( $file_name ),
		absint( $inserted_count )
	) . '</p></div>';
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

	$ai_connected = infinibase_is_ai_connected();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Manage Knowledge Base', 'infinibase' ); ?></h1>

		<div class="card ifaq-admin-shortcodes-card">
			<div class="ifaq-admin-shortcodes-row">
				<div>
					<h2 class="ifaq-admin-shortcodes-title"><?php esc_html_e( 'Available Shortcodes', 'infinibase' ); ?></h2>
					<p class="description ifaq-admin-shortcodes-desc"><?php esc_html_e( 'Click any shortcode below to copy it to your clipboard, then paste it into Gutenberg or Elementor.', 'infinibase' ); ?></p>
				</div>
				<div class="ifaq-admin-shortcodes-actions">
					<button type="button" class="button ifaq-copy-shortcode-btn" data-shortcode="[infinibase_light]">
						<span><?php esc_html_e( 'Light Mode:', 'infinibase' ); ?></span>
						<code>[infinibase_light]</code>
						<span class="ifaq-copy-status"><?php esc_html_e( 'Copy', 'infinibase' ); ?></span>
					</button>

					<button type="button" class="button ifaq-copy-shortcode-btn" data-shortcode="[infinibase_dark]">
						<span><?php esc_html_e( 'Dark Mode:', 'infinibase' ); ?></span>
						<code>[infinibase_dark]</code>
						<span class="ifaq-copy-status"><?php esc_html_e( 'Copy', 'infinibase' ); ?></span>
					</button>
				</div>
			</div>
		</div>

		<div class="ifaq-admin-grid-top">
			<div class="card ifaq-admin-card">
				<h2><?php esc_html_e( 'Upload Markdown Documentation', 'infinibase' ); ?></h2>
				<p><?php esc_html_e( 'Upload your .md files and the system will automatically generate search keywords using your selected AI Provider.', 'infinibase' ); ?></p>

				<?php if ( ! $ai_connected ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php esc_html_e( 'AI Provider not connected. You must configure your AI Provider in Settings before uploading documentation so keywords can be generated for each chunk.', 'infinibase' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=infinibase' ) ); ?>">
								<?php esc_html_e( 'Go to InfiniBase Settings →', 'infinibase' ); ?>
							</a>
						</p>
					</div>
				<?php endif; ?>

				<form method="post" enctype="multipart/form-data" action="">
					<?php wp_nonce_field( 'infinibase_upload_file', 'infinibase_upload_nonce' ); ?>

					<table class="form-table ifaq-upload-table">
						<tr>
							<th scope="row"><label for="infinibase_base_page_url"><?php esc_html_e( 'Base Page URL', 'infinibase' ); ?></label></th>
							<td>
								<input type="url" name="infinibase_base_page_url" id="infinibase_base_page_url" class="large-text" placeholder="https://example.com/pricing" value="<?php echo esc_attr( $retained_url ); ?>" <?php disabled( ! $ai_connected ); ?> required />
								<p class="description"><?php esc_html_e( 'The live URL where this document will live. Essential for generating "Read more" links.', 'infinibase' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="infinibase_md_file"><?php esc_html_e( 'Markdown File', 'infinibase' ); ?></label></th>
							<td>
								<input type="file" name="infinibase_md_file" id="infinibase_md_file" accept=".md" <?php disabled( ! $ai_connected ); ?> required />
							</td>
						</tr>
					</table>

					<?php
					submit_button(
						esc_html__( 'Upload & Process', 'infinibase' ),
						'primary',
						'infinibase_upload_submit',
						false,
						$ai_connected ? array() : array( 'disabled' => 'disabled' )
					);
					?>
				</form>
			</div>

			<div class="card ifaq-admin-card">
				<h2><?php esc_html_e( 'Indexed Files', 'infinibase' ); ?></h2>
				<p><?php esc_html_e( 'Documents currently chunked and indexed in your WordPress database.', 'infinibase' ); ?></p>
				<table class="wp-list-table widefat fixed striped ifaq-indexed-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'File Name', 'infinibase' ); ?></th>
							<th><?php esc_html_e( 'Base URL', 'infinibase' ); ?></th>
							<th class="ifaq-col-chunks"><?php esc_html_e( 'Chunks', 'infinibase' ); ?></th>
							<th><?php esc_html_e( 'Last Updated', 'infinibase' ); ?></th>
							<th class="ifaq-col-action"><?php esc_html_e( 'Action', 'infinibase' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						if ( ! empty( $files ) ) {
							foreach ( $files as $file ) {
								/* translators: %s: Markdown file name to delete. */
								$delete_confirm_msg = sprintf( __( 'Delete "%s" from your indexed knowledge base?', 'infinibase' ), $file->file_name );
								?>
								<tr>
									<td><?php echo esc_html( $file->file_name ); ?></td>
									<td><a href="<?php echo esc_url( $file->page_slug ); ?>" target="_blank"><?php echo esc_html( $file->page_slug ); ?></a></td>
									<td><?php echo esc_html( $file->chunks ); ?></td>
									<td><?php echo esc_html( $file->last_updated ); ?></td>
									<td class="ifaq-col-action">
										<form method="post" action="" class="ifaq-inline-form">
											<?php wp_nonce_field( 'infinibase_delete_indexed_file', 'infinibase_delete_file_nonce' ); ?>
											<input type="hidden" name="infinibase_delete_file_name" value="<?php echo esc_attr( $file->file_name ); ?>" />
											<button type="submit" name="infinibase_delete_file_submit" value="1" class="button button-small button-link-delete ifaq-delete-btn" data-confirm="<?php echo esc_attr( $delete_confirm_msg ); ?>">
												<?php esc_html_e( 'Delete', 'infinibase' ); ?>
											</button>
										</form>
									</td>
								</tr>
								<?php
							}
						} else {
							echo '<tr><td colspan="5">' . esc_html__( 'No files indexed yet.', 'infinibase' ) . '</td></tr>';
						}
						?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="ifaq-admin-section-divider">
			<h2><?php esc_html_e( 'Shortcode Display & Predefined FAQs', 'infinibase' ); ?></h2>
			<p><?php esc_html_e( 'Customize the left-column heading, subtitle, and the 4 predefined FAQ accordion cards displayed by the [infinibase_light] and [infinibase_dark] shortcodes.', 'infinibase' ); ?></p>

			<form method="post" action="">
				<?php wp_nonce_field( 'infinibase_save_static_faqs', 'infinibase_static_nonce' ); ?>

				<div class="ifaq-admin-grid-config">
					<div class="card ifaq-admin-card">
						<h3><?php esc_html_e( '1. Shortcode Heading & Subtitle', 'infinibase' ); ?></h3>
						<p class="ifaq-field-group">
							<label for="infinibase_shortcode_heading" class="ifaq-field-label">
								<?php esc_html_e( 'Section Heading (Max 9 chars)', 'infinibase' ); ?>
							</label>
							<input
								type="text"
								name="infinibase_shortcode_heading"
								id="infinibase_shortcode_heading"
								value="<?php echo esc_attr( $shortcode_heading ); ?>"
								maxlength="9"
								class="regular-text ifaq-heading-input"
								placeholder="Questions"
								required
							/>
							<span class="description ifaq-field-hint"><?php esc_html_e( 'e.g., "Questions" or "FAQs".', 'infinibase' ); ?></span>
						</p>
						<p class="ifaq-field-group-last">
							<label for="infinibase_shortcode_subtitle" class="ifaq-field-label">
								<?php esc_html_e( 'Section Subtitle', 'infinibase' ); ?>
							</label>
							<input
								type="text"
								name="infinibase_shortcode_subtitle"
								id="infinibase_shortcode_subtitle"
								value="<?php echo esc_attr( $shortcode_subtitle ); ?>"
								class="large-text"
								placeholder="The short ones are here. For anything else, ask our AI below."
								required
							/>
						</p>
					</div>

					<div class="card ifaq-admin-card">
						<h3><?php esc_html_e( '2. Auto-Generate 4 FAQs with AI', 'infinibase' ); ?></h3>
						<p class="ifaq-ai-gen-desc"><?php esc_html_e( 'Select an uploaded document below and let your configured AI Provider automatically generate 4 questions and answers from its content.', 'infinibase' ); ?></p>

						<div class="ifaq-ai-gen-controls">
							<div>
								<label for="infinibase_source_doc" class="ifaq-field-label"><?php esc_html_e( 'Source Document', 'infinibase' ); ?></label>
								<select name="infinibase_source_doc" id="infinibase_source_doc" class="ifaq-full-select">
									<option value="__all__" <?php selected( $selected_source_doc, '__all__' ); ?>><?php esc_html_e( 'All Indexed Documents', 'infinibase' ); ?></option>
									<?php if ( ! empty( $files ) ) : ?>
										<?php foreach ( $files as $file ) : ?>
											<option value="<?php echo esc_attr( $file->file_name ); ?>" <?php selected( $selected_source_doc, $file->file_name ); ?>>
												<?php echo esc_html( $file->file_name . ' (' . $file->chunks . ' chunks)' ); ?>
											</option>
										<?php endforeach; ?>
									<?php endif; ?>
								</select>
							</div>

							<div>
								<button type="submit" name="infinibase_ai_generate_faqs_submit" value="1" class="button button-secondary" <?php disabled( empty( $files ) || ! $ai_connected ); ?>>
									<?php esc_html_e( '✨ Generate 4 FAQs with AI', 'infinibase' ); ?>
								</button>
							</div>
						</div>
						<?php if ( empty( $files ) ) : ?>
							<p class="description ifaq-notice-text"><?php esc_html_e( 'Upload at least one .md document above to enable AI FAQ generation.', 'infinibase' ); ?></p>
						<?php endif; ?>
					</div>
				</div>

				<div class="ifaq-cards-header-bar">
					<h3><?php esc_html_e( '3. Predefined Question & Answer Cards (4 Slots)', 'infinibase' ); ?></h3>
					<button type="submit" name="infinibase_delete_all_cards_submit" value="1" class="button button-link-delete ifaq-delete-btn" data-confirm="<?php echo esc_attr__( 'Are you sure you want to clear all 4 predefined FAQ cards?', 'infinibase' ); ?>">
						<?php esc_html_e( 'Delete All Predefined FAQs', 'infinibase' ); ?>
					</button>
				</div>

				<div class="ifaq-admin-grid-cards">
					<?php for ( $i = 0; $i < 4; $i++ ) : ?>
						<div class="card ifaq-admin-card">
							<div class="ifaq-card-item-header">
								<h4>
									<?php
									echo sprintf(
										/* translators: %d: FAQ card number (1-4). */
										esc_html__( 'FAQ Card #%d', 'infinibase' ),
										absint( $i + 1 )
									);
									?>
								</h4>
								<button type="submit" name="infinibase_delete_single_card" value="<?php echo esc_attr( $i ); ?>" class="button-link button-link-delete ifaq-card-delete-link">
									<?php esc_html_e( 'Delete Card', 'infinibase' ); ?>
								</button>
							</div>
							<p class="ifaq-field-group">
								<label for="infinibase_card_q_<?php echo esc_attr( $i ); ?>" class="ifaq-field-label">
									<?php esc_html_e( 'Question', 'infinibase' ); ?>
								</label>
								<input
									type="text"
									name="infinibase_card_q[<?php echo esc_attr( $i ); ?>]"
									id="infinibase_card_q_<?php echo esc_attr( $i ); ?>"
									value="<?php echo esc_attr( $static_faqs[ $i ]['question'] ); ?>"
									class="large-text"
									placeholder="<?php echo esc_attr__( 'e.g., What is your refund policy?', 'infinibase' ); ?>"
								/>
							</p>
							<p class="ifaq-field-group-last">
								<label for="infinibase_card_a_<?php echo esc_attr( $i ); ?>" class="ifaq-field-label">
									<?php esc_html_e( 'Answer', 'infinibase' ); ?>
								</label>
								<textarea
									name="infinibase_card_a[<?php echo esc_attr( $i ); ?>]"
									id="infinibase_card_a_<?php echo esc_attr( $i ); ?>"
									rows="4"
									class="large-text"
									placeholder="<?php echo esc_attr__( 'Enter the concise answer displayed when this FAQ is expanded...', 'infinibase' ); ?>"
								><?php echo esc_textarea( $static_faqs[ $i ]['answer'] ); ?></textarea>
							</p>
						</div>
					<?php endfor; ?>
				</div>

				<p class="submit ifaq-submit-row">
					<button type="submit" name="infinibase_save_static_submit" value="1" class="button button-primary button-large">
						<?php esc_html_e( 'Save Heading & FAQ Cards', 'infinibase' ); ?>
					</button>
				</p>
			</form>
		</div>
	</div>
	<?php
}
