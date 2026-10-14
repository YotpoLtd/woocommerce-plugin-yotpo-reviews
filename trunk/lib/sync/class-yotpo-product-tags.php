<?php

defined( 'ABSPATH' ) || exit;

/**
 * Y-tags: WooCommerce product tags that set a product's blocklist, group and custom question form
 * in Yotpo:
 *
 * - YBlocklist           the product is on the Yotpo blocklist.
 * - YGroup_<group name>  the product shares reviews with the products of that group.
 * - YCRF_<form tag>      review requests for the product use the custom question form with that tag.
 *
 * Tag names are case-sensitive, the prefix is never part of the value sent (YGroup_shirts is the
 * group "shirts"), and a product with more than one group or form tag gets the first one in
 * alphabetical byte order (tag_names() sorts them explicitly).
 *
 * Only used when the merchant turned on "Use product tags for the blocklist, groups and custom
 * questions" (Yotpo_Product_Sync::tags_on()); then these tags are what Yotpo gets, tag or no tag.
 */
class Yotpo_Product_Tags {

	const BLOCKLIST    = 'YBlocklist';
	const GROUP_PREFIX = 'YGroup_';
	const FORM_PREFIX  = 'YCRF_';

	const GROUP_MAX = 100; // Yotpo: up to 100 characters, a-z, 0-9, "_" and "-".
	const FORM_MAX  = 255; // Yotpo: up to 255 characters, no commas.

	/**
	 * Whether a tag name is a Y-tag, so that changing it changes what Yotpo gets.
	 */
	public static function is_y_tag( $name ) {
		$name = (string) $name;
		return self::BLOCKLIST === $name || 0 === strpos( $name, self::GROUP_PREFIX ) || 0 === strpos( $name, self::FORM_PREFIX );
	}

	/**
	 * The Yotpo fields the product's Y-tags set: group_name, and is_blocklisted and review_form_tag
	 * under custom_properties. A field without its tag is sent empty (false for the blocklist), which
	 * takes the product out of its group, off the blocklist or back to the default form.
	 *
	 * @return array{group_name:string, custom_properties:array{is_blocklisted:bool, review_form_tag:string}}
	 */
	public static function fields( $product_id ) {
		$names = self::tag_names( $product_id );
		return array(
			'group_name'        => self::group( $names ),
			'custom_properties' => array(
				'is_blocklisted'  => in_array( self::BLOCKLIST, $names, true ),
				'review_form_tag' => self::form_tag( $names ),
			),
		);
	}

	/**
	 * A group name as Yotpo accepts it: lowercase, spaces as "_", any other character Yotpo doesn't
	 * accept as "-", at most GROUP_MAX characters. Empty when nothing usable is left.
	 */
	public static function group_name( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = preg_replace( '/\s+/', '_', $value );
		$value = preg_replace( '/[^a-z0-9_-]/', '-', $value );
		$value = substr( $value, 0, self::GROUP_MAX );
		return '' === trim( $value, '_-' ) ? '' : $value;
	}

	/**
	 * A form tag as Yotpo accepts it: no commas, at most FORM_MAX characters. Case is kept: Yotpo
	 * matches it to the form's tag case-sensitively.
	 */
	public static function form_tag_name( $value ) {
		$value = trim( str_replace( ',', '', (string) $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::FORM_MAX ) : substr( $value, 0, self::FORM_MAX );
	}

	private static function group( array $names ) {
		foreach ( $names as $name ) {
			if ( 0 === strpos( $name, self::GROUP_PREFIX ) ) {
				$group = self::group_name( substr( $name, strlen( self::GROUP_PREFIX ) ) );
				if ( '' !== $group ) {
					return $group;
				}
			}
		}
		return '';
	}

	private static function form_tag( array $names ) {
		foreach ( $names as $name ) {
			if ( 0 === strpos( $name, self::FORM_PREFIX ) ) {
				$tag = self::form_tag_name( substr( $name, strlen( self::FORM_PREFIX ) ) );
				if ( '' !== $tag ) {
					return $tag;
				}
			}
		}
		return '';
	}

	/**
	 * The product's tag names, in alphabetical order (byte order, so case-sensitive and stable
	 * whatever the database collation).
	 *
	 * @return string[]
	 */
	private static function tag_names( $product_id ) {
		$terms = get_the_terms( $product_id, 'product_tag' );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$names = array();
		foreach ( $terms as $term ) {
			$names[] = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
		}
		sort( $names, SORT_STRING );
		return $names;
	}
}
