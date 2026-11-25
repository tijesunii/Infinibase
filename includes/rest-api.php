<?php
/**
 * InfiniBase - REST API Endpoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'infinibase_register_rest_routes' );
function infinibase_register_rest_routes() {
	register_rest_route(
		'infinibase/v1',
		'/ask',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'infinibase_process_question',
			'permission_callback' => '__return_true',
		)
	);
}

function infinibase_is_same_origin( $candidate_url ) {
	if ( empty( $candidate_url ) || ! is_string( $candidate_url ) ) {
		return false;
	}

	$parsed_candidate = wp_parse_url( $candidate_url );
	if ( empty( $parsed_candidate['host'] ) || empty( $parsed_candidate['scheme'] ) ) {
		return false;
	}

	$candidate_scheme = strtolower( $parsed_candidate['scheme'] );
	$candidate_host   = strtolower( $parsed_candidate['host'] );
	$candidate_port   = isset( $parsed_candidate['port'] ) ? (int) $parsed_candidate['port'] : null;

	$allowed_urls = array( home_url(), site_url() );

	foreach ( $allowed_urls as $allowed_url ) {
		$parsed_allowed = wp_parse_url( $allowed_url );
		if ( empty( $parsed_allowed['host'] ) || empty( $parsed_allowed['scheme'] ) ) {
			continue;
		}

		$allowed_scheme = strtolower( $parsed_allowed['scheme'] );
		$allowed_host   = strtolower( $parsed_allowed['host'] );
		$allowed_port   = isset( $parsed_allowed['port'] ) ? (int) $parsed_allowed['port'] : null;

		if ( $candidate_scheme === $allowed_scheme && $candidate_host === $allowed_host && $candidate_port === $allowed_port ) {
			return true;
		}
	}

	return false;
}

function infinibase_validate_public_request( WP_REST_Request $request ) {
	$origin  = $request->get_header( 'origin' );
	$referer = $request->get_header( 'referer' );

	$valid_origin = false;
	if ( ! empty( $origin ) ) {
		$valid_origin = infinibase_is_same_origin( $origin );
	} elseif ( ! empty( $referer ) ) {
		$valid_origin = infinibase_is_same_origin( $referer );
	}

	if ( ! $valid_origin ) {
		return new WP_Error(
			'rest_forbidden',
			__( 'Invalid origin. Requests must originate from this site.', 'infinibase' ),
			array( 'status' => 403 )
		);
	}

	$nonce = $request->get_param( 'nonce' );
	if ( empty( $nonce ) ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
	}

	if ( empty( $nonce ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), 'infinibase_ask' ) ) {
		return new WP_Error(
			'rest_forbidden',
			__( 'Invalid or expired security nonce. Please refresh the page and try again.', 'infinibase' ),
			array( 'status' => 403 )
		);
	}

	return true;
}

function infinibase_extract_search_tokens( $question ) {
	$stopwords = array(
		'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'had', 'her', 'was',
		'our', 'out', 'get', 'has', 'him', 'his', 'how', 'now', 'see', 'way', 'who', 'did',
		'its', 'let', 'put', 'say', 'she', 'too', 'use', 'what', 'when', 'where', 'which',
		'while', 'with', 'your', 'from', 'have', 'this', 'that', 'they', 'will', 'would',
		'there', 'their', 'about', 'could', 'into', 'than', 'then', 'them', 'these', 'some',
		'such', 'only', 'very', 'just', 'does', 'dont', 'cant', 'wont', 'should', 'please',
		'tell', 'know', 'need', 'want', 'does', 'isnt', 'arent', 'whats', 'heres',
	);

	$raw_tokens = preg_split( '/[^a-z0-9]+/i', strtolower( (string) $question ) );
	$tokens     = array();

	if ( is_array( $raw_tokens ) ) {
		foreach ( $raw_tokens as $token ) {
			if ( strlen( $token ) < 2 || in_array( $token, $stopwords, true ) ) {
				continue;
			}
			$tokens[ $token ] = true;
		}
	}

	return array_slice( array_keys( $tokens ), 0, 10 );
}

function infinibase_find_relevant_chunks( $question, $table_name, $limit = 3 ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$chunks = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT id, page_slug, anchor_id, chunk_text, keywords,
				(MATCH(keywords, chunk_text) AGAINST(%s IN NATURAL LANGUAGE MODE) + (MATCH(keywords) AGAINST(%s IN NATURAL LANGUAGE MODE) * 0.5)) AS relevance_score
			 FROM %i
			 WHERE MATCH(keywords, chunk_text) AGAINST(%s IN NATURAL LANGUAGE MODE)
			 ORDER BY relevance_score DESC
			 LIMIT %d',
			$question,
			$question,
			$table_name,
			$question,
			$limit
		)
	);

	if ( ! empty( $chunks ) ) {
		return $chunks;
	}

	$tokens = infinibase_extract_search_tokens( $question );
	if ( empty( $tokens ) ) {
		return array();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$candidates = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT id, page_slug, anchor_id, chunk_text, keywords FROM %i ORDER BY id ASC LIMIT 200',
			$table_name
		)
	);

	if ( empty( $candidates ) ) {
		return array();
	}

	$scored = array();
	foreach ( $candidates as $row ) {
		$haystack_kw   = strtolower( (string) $row->keywords );
		$haystack_text = strtolower( (string) $row->chunk_text );
		$score         = 0;

		foreach ( $tokens as $token ) {
			if ( false !== strpos( $haystack_kw, $token ) ) {
				$score += 2;
			}
			if ( false !== strpos( $haystack_text, $token ) ) {
				$score += 1;
			}
		}

		if ( $score > 0 ) {
			$scored[] = array(
				'score' => $score,
				'row'   => $row,
			);
		}
	}

	if ( empty( $scored ) ) {
		return array();
	}

	usort(
		$scored,
		static function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		}
	);

	return array_map(
		static function ( $item ) {
			return $item['row'];
		},
		array_slice( $scored, 0, $limit )
	);
}

function infinibase_process_question( WP_REST_Request $request ) {
	$validation = infinibase_validate_public_request( $request );
	if ( is_wp_error( $validation ) ) {
		return $validation;
	}

	$honeypot = $request->get_param( '_ifaq_email_check' );
	if ( ! empty( $honeypot ) ) {
		return rest_ensure_response(
			array(
				'success'  => true,
				'answer'   => 'Thank you for your question.',
				'citation' => '',
			)
		);
	}

	$question = $request->get_param( 'question' );

	if ( empty( $question ) || mb_strlen( $question ) < 5 || mb_strlen( $question ) > 250 ) {
		return new WP_Error( 'rest_invalid_param', __( 'Your question must be between 5 and 250 characters.', 'infinibase' ), array( 'status' => 400 ) );
	}

	if ( preg_match( '/https?:\/\//i', $question ) || preg_match( '/<[^>]*>/', $question ) ) {
		return new WP_Error( 'rest_invalid_param', __( 'Links and HTML markup are not permitted in questions.', 'infinibase' ), array( 'status' => 400 ) );
	}

	$sanitized_question = sanitize_text_field( $question );

	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

	$ip_transient_key = 'infinibase_rl_' . md5( $ip );
	$ip_attempts      = (int) get_transient( $ip_transient_key );

	if ( $ip_attempts >= 10 ) {
		return new WP_Error(
			'rest_too_many_requests',
			__( 'You are asking questions too quickly. Please wait a moment before asking another.', 'infinibase' ),
			array( 'status' => 429 )
		);
	}

	$global_transient_key = 'infinibase_rl_global';
	$global_attempts      = (int) get_transient( $global_transient_key );

	if ( $global_attempts >= 60 ) {
		return new WP_Error(
			'rest_too_many_requests',
			__( 'The AI assistant is currently busy. Please try again in a minute.', 'infinibase' ),
			array( 'status' => 429 )
		);
	}

	set_transient( $ip_transient_key, $ip_attempts + 1, MINUTE_IN_SECONDS );
	set_transient( $global_transient_key, $global_attempts + 1, MINUTE_IN_SECONDS );

	global $wpdb;
	$table_name  = $wpdb->prefix . 'infinibase_docs';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$kb_count    = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(id) FROM %i', $table_name ) );
	$support_url = get_option( 'infinibase_support_url', '' );

	if ( ! $kb_count || 0 == $kb_count ) {
		return rest_ensure_response(
			array(
				'success'  => false,
				'answer'   => 'I couldn\'t find that information in the available documentation.',
				'citation' => $support_url,
			)
		);
	}

	$top_chunks = infinibase_find_relevant_chunks( $sanitized_question, $table_name, 3 );

	if ( empty( $top_chunks ) ) {
		return rest_ensure_response(
			array(
				'success'  => false,
				'answer'   => 'I couldn\'t find that information in the available documentation.',
				'citation' => $support_url,
			)
		);
	}

	$chunk_ids_sig = implode( '-', wp_list_pluck( $top_chunks, 'id' ) );
	$cache_key     = 'infinibase_ans_' . md5( strtolower( $sanitized_question ) . '_' . $chunk_ids_sig . '_' . get_option( 'infinibase_answer_length', 'Brief' ) );
	$cached_answer = get_transient( $cache_key );

	if ( false !== $cached_answer && is_array( $cached_answer ) ) {
		return rest_ensure_response( $cached_answer );
	}

	$daily_limit         = absint( apply_filters( 'infinibase_daily_rate_limit', 200 ) );
	$daily_transient_key = 'infinibase_rl_daily';
	$daily_attempts      = (int) get_transient( $daily_transient_key );

	if ( $daily_limit > 0 && $daily_attempts >= $daily_limit ) {
		return new WP_Error(
			'rest_too_many_requests',
			__( 'The daily AI question limit has been reached. Please contact support or try again tomorrow.', 'infinibase' ),
			array( 'status' => 429 )
		);
	}

	set_transient( $daily_transient_key, $daily_attempts + 1, DAY_IN_SECONDS );

	$context_parts = array();
	foreach ( $top_chunks as $idx => $chunk ) {
		$source_num      = $idx + 1;
		$context_parts[] = "[Source {$source_num}]\n" . $chunk->chunk_text;
	}
	$combined_context = implode( "\n\n---\n\n", $context_parts );

	$verbosity          = get_option( 'infinibase_answer_length', 'Brief' );
	$length_instruction = ( 'Brief' === $verbosity ) ? 'Keep your answer concise and strictly to the point.' : 'Provide a detailed, comprehensive answer based on the context.';

	$system_prompt = "You are a helpful support assistant. Answer the user's question using ONLY the provided documentation sources below. Do NOT infer, guess, or use outside knowledge. $length_instruction If the provided sources contain the answer, start your reply with [SOURCE:N] (where N is the source number that best answers the question) followed by a space and your answer. If the provided sources do not contain the exact answer, reply EXACTLY with the phrase: 'I couldn't find that information in the available documentation.'\n\nDocumentation Sources:\n" . $combined_context;
	$user_prompt   = $sanitized_question;

	$ai_response = infinibase_request_ai_text( $user_prompt, $system_prompt, 300, 20 );

	if ( is_wp_error( $ai_response ) ) {
		if ( in_array( $ai_response->get_error_code(), array( 'missing_api_key', 'wp_ai_client_unavailable', 'wp_ai_client_unconfigured' ), true ) ) {
			return rest_ensure_response(
				array(
					'success'  => false,
					'answer'   => 'The AI Knowledge Base is currently unconfigured. Please contact support.',
					'citation' => $support_url,
				)
			);
		}
		return new WP_Error( 'rest_api_error', __( 'Failed to communicate with the AI Provider.', 'infinibase' ), array( 'status' => 500 ) );
	}

	if ( empty( $ai_response ) ) {
		return new WP_Error( 'rest_api_error', __( 'Failed to communicate with the AI Provider.', 'infinibase' ), array( 'status' => 500 ) );
	}

	$cited_chunk = $top_chunks[0];
	if ( preg_match( '/^\s*\[SOURCE:(\d+)\]\s*/i', $ai_response, $source_match ) ) {
		$source_idx = max( 0, (int) $source_match[1] - 1 );
		if ( isset( $top_chunks[ $source_idx ] ) ) {
			$cited_chunk = $top_chunks[ $source_idx ];
		}
		$ai_response = trim( substr( $ai_response, strlen( $source_match[0] ) ) );
	}

	if ( false !== stripos( $ai_response, "I couldn't find that information" ) ) {
		$fallback_payload = array(
			'success'  => false,
			'answer'   => 'I couldn\'t find that information in the available documentation.',
			'citation' => $support_url,
		);
		set_transient( $cache_key, $fallback_payload, HOUR_IN_SECONDS );
		return rest_ensure_response( $fallback_payload );
	}

	$success_payload = array(
		'success'  => true,
		'answer'   => sanitize_textarea_field( $ai_response ),
		'citation' => esc_url( $cited_chunk->page_slug . '#' . $cited_chunk->anchor_id ),
	);

	set_transient( $cache_key, $success_payload, HOUR_IN_SECONDS );

	return rest_ensure_response( $success_payload );
}
