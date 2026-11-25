<?php
/**
 * InfiniBase - Uninstall Handler
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$infinibase_keep_data = get_option( 'infinibase_keep_data_on_uninstall' );

if ( ! $infinibase_keep_data || '0' === $infinibase_keep_data ) {
	global $wpdb;

	$infinibase_table_name = $wpdb->prefix . 'infinibase_docs';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $infinibase_table_name ) );

	delete_option( 'infinibase_api_key' );
	delete_option( 'infinibase_support_url' );
	delete_option( 'infinibase_ai_provider' );
	delete_option( 'infinibase_answer_length' );
	delete_option( 'infinibase_shortcode_heading' );
	delete_option( 'infinibase_shortcode_subtitle' );
	delete_option( 'infinibase_static_faqs' );
	delete_option( 'infinibase_db_version' );
	delete_option( 'infinibase_keep_data_on_uninstall' );
}
