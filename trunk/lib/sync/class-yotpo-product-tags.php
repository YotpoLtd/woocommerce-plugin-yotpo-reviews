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
 * alphabetical byte order (Yotpo_Product_Terms::names() sorts them explicitly).
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
		$names = Yotpo_Product_Terms::names( $product_id, 'product_tag' );
		return array(
			'group_name'        => self::first_value( $names, self::GROUP_PREFIX, array( __CLASS__, 'group_name' ) ),
			'custom_properties' => array(
				'is_blocklisted'  => in_array( self::BLOCKLIST, $names, true ),
				'review_form_tag' => self::first_value( $names, self::FORM_PREFIX, array( __CLASS__, 'form_tag_name' ) ),
			),
		);
	}

	/**
	 * A group name as Yotpo accepts it: lowercase, spaces as "_", any other character Yotpo doesn't
	 * accept as "-", at most GROUP_MAX characters. Empty when nothing usable is left.
	 */
	public static function group_name( $value ) {
		// By character, not by byte: "Café" is "caf-", not "caf--". (Without mbstring, a name that isn't
		// valid UTF-8 is left empty; with it, an invalid byte becomes "-".)
		$value = trim( (string) $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = (string) preg_replace( '/\s+/u', '_', $value );
		$value = (string) preg_replace( '/[^a-z0-9_-]/u', '-', $value );
		$value = substr( $value, 0, self::GROUP_MAX ); // Only ASCII is left.
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

	/**
	 * The value of the first tag (in the order given) that starts with the prefix: the rest of its
	 * name, made valid by the normaliser. A tag whose value is empty after that is skipped. Empty
	 * when no tag has one.
	 *
	 * @param string[] $names     Tag names.
	 * @param string   $prefix    GROUP_PREFIX or FORM_PREFIX.
	 * @param callable $normalise group_name() or form_tag_name().
	 */
	private static function first_value( array $names, $prefix, $normalise ) {
		foreach ( $names as $name ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				$value = call_user_func( $normalise, substr( $name, strlen( $prefix ) ) );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}
		return '';
	}
}
