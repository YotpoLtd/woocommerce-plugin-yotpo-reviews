<?php

defined( 'ABSPATH' ) || exit;

/**
 * Reads the terms of a product (tags, brands) as plain text. Shared by the always-on brand mapping
 * (Yotpo_Product_Payload), the Y-tag rules (Yotpo_Product_Tags) and the tag jobs (Yotpo_Sync_Jobs).
 */
class Yotpo_Product_Terms {

	/**
	 * The names of the product's terms in a taxonomy, in alphabetical order (byte order, so
	 * case-sensitive and stable whatever the database collation), as plain text.
	 *
	 * @return string[]
	 */
	public static function names( $product_id, $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$terms = get_the_terms( $product_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$names = array();
		foreach ( $terms as $term ) {
			$names[] = self::name( $term );
		}
		sort( $names, SORT_STRING );
		return $names;
	}

	/**
	 * A term's name as plain text: WordPress stores it with HTML entities (Summer &amp; Sun).
	 */
	public static function name( $term ) {
		return html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
	}
}
