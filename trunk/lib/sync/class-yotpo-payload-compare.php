<?php

defined( 'ABSPATH' ) || exit;

/**
 * The matching rules of the full sync's comparison with Yotpo: whether the copy Yotpo returned has
 * the same data as the payload the plugin would send. Pure functions of the two values, so they can
 * be tested without a Yotpo catalog.
 *
 * Allows for the formats Yotpo answers in: a price as a string, flags as strings, lists in another order.
 */
class Yotpo_Payload_Compare {

	const PRICE_EPSILON = 0.000001; // Below any price a store can charge, above float noise.

	/**
	 * Whether Yotpo's copy has every field the payload would send, with the same value. Fields
	 * Yotpo has and the payload doesn't send are ignored: an update wouldn't change them either.
	 * When in doubt it answers no, which only costs an update.
	 *
	 * @param array $sent   The payload that would be sent.
	 * @param mixed $stored Yotpo's copy (an object of a lookup answer).
	 * @return bool
	 */
	public static function same( $sent, $stored ) {
		foreach ( $sent as $key => $value ) {
			if ( ! self::same_value( $value, is_array( $stored ) && isset( $stored[ $key ] ) ? $stored[ $key ] : null ) ) {
				return false;
			}
		}
		return true;
	}

	private static function same_value( $sent, $stored ) {
		if ( is_array( $sent ) ) {
			if ( ! is_array( $stored ) ) {
				return array() === $sent;
			}
			if ( array_values( $sent ) === $sent ) {
				// A list (gtins, options): the same entries in any order.
				return self::normalized_entries( $sent ) === self::normalized_entries( $stored );
			}
			return self::same( $sent, $stored );
		}
		if ( null !== $stored && ! is_scalar( $stored ) ) {
			return false; // An array or object where a single value was sent.
		}
		if ( is_bool( $sent ) ) {
			// Yotpo may answer a flag as a string ("false", "0"): read it as a flag, and a missing or
			// unreadable one as different.
			$flag = null === $stored ? null : filter_var( $stored, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			return null !== $flag && $flag === $sent;
		}
		if ( is_int( $sent ) ) {
			return is_numeric( $stored ) && (float) $stored === (float) $sent; // A count: any difference is a change.
		}
		if ( is_float( $sent ) ) {
			// A price: the payload sends the raw value, so equal means equal on that value; the epsilon
			// only absorbs float representation ("18.00" against 18.0), never a real price difference.
			return is_numeric( $stored ) && abs( (float) $stored - $sent ) < self::PRICE_EPSILON;
		}
		return trim( (string) $stored ) === trim( (string) $sent );
	}

	/**
	 * The entries of a list as sorted, normalised JSON strings, so two lists with the same entries
	 * in any order (and scalars in any type) give the same result.
	 *
	 * @return string[]
	 */
	private static function normalized_entries( $list ) {
		$entries = array();
		foreach ( $list as $entry ) {
			if ( is_array( $entry ) ) {
				ksort( $entry );
				$entry = array_map(
					function ( $value ) {
						return is_scalar( $value ) || null === $value ? (string) $value : wp_json_encode( $value );
					},
					$entry
				);
			}
			$entries[] = wp_json_encode( $entry );
		}
		sort( $entries );
		return $entries;
	}
}
