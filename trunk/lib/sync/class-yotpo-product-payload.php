<?php

defined( 'ABSPATH' ) || exit;

/**
 * The WooCommerce → Yotpo field mapping for products and variants (Core API V3).
 * Same values the widgets send today.
 */
class Yotpo_Product_Payload {

	/**
	 * Product fields for create and update.
	 */
	public static function product( $product ) {
		$payload = array(
			'name'              => self::limit( $product->get_name(), 255 ),
			'description'       => wp_strip_all_tags( $product->get_description() ),
			'status'            => 'active',
			'url'               => self::limit( get_permalink( $product->get_id() ), 2048 ),
			'currency'          => get_woocommerce_currency(),
		);
		if ( Yotpo_Product_Sync::tags_on() ) {
			// The merchant manages the blocklist, groups and form tags with Y-tags: they are the truth.
			$payload = array_merge( $payload, Yotpo_Product_Tags::fields( $product->get_id() ) );
		} elseif ( get_post_meta( $product->get_id(), Yotpo_Catalog_Api::META_BLOCKLISTED, true ) ) {
			// Otherwise the blocklist flag is only sent to undo the plugin's own blocklisting, of a product
			// that was unpublished and is published again. A product the merchant blocklisted in Yotpo
			// (catalog CSV, Yotpo admin) keeps that: 1.10.0 always sent false here, which took such
			// products off the blocklist.
			$payload['custom_properties'] = array( 'is_blocklisted' => false );
		}
		$image = wc_yotpo_get_product_image_url( $product->get_id() );
		if ( $image ) {
			$payload['image_url'] = self::limit( $image, 2048 );
		}
		if ( '' !== (string) $product->get_price() ) {
			$payload['price'] = (float) $product->get_price();
		}
		if ( $product->managing_stock() && null !== $product->get_stock_quantity() ) {
			$payload['inventory_quantity'] = (int) $product->get_stock_quantity();
		}
		$sku = self::valid_sku( $product->get_sku() );
		if ( $sku ) {
			$payload['sku'] = $sku;
		}
		$brand = self::brand( $product );
		if ( '' !== $brand ) {
			$payload['brand'] = self::limit( $brand, 255 );
		}
		$mpn = trim( (string) $product->get_attribute( 'mpn' ) );
		if ( '' !== $mpn ) {
			$payload['mpn'] = self::limit( $mpn, 255 );
		}
		$gtins = self::gtins( $product );
		if ( $gtins ) {
			$payload['gtins'] = $gtins;
		}
		/**
		 * Filters the product fields sent to Yotpo (for example, to point image URLs at a CDN).
		 * The external ID is added separately and can't be changed here.
		 */
		return (array) apply_filters( 'wc_yotpo_product_sync_product_payload', $payload, $product );
	}

	public static function variant( $variation, $parent ) {
		$payload = array(
			'name'            => self::limit( $variation->get_name(), 255 ),
			'url'             => self::limit( $variation->get_permalink(), 2048 ),
			'currency'        => get_woocommerce_currency(),
			// A disabled variation ("private") gets no review requests and is hidden from widgets.
			'is_discontinued' => 'publish' !== $variation->get_status(),
		);
		$image_id = $variation->get_image_id() ? $variation->get_image_id() : $parent->get_image_id();
		$image    = $image_id ? wp_get_attachment_url( $image_id ) : '';
		if ( $image ) {
			$payload['image_url'] = self::limit( $image, 2048 );
		}
		if ( '' !== (string) $variation->get_price() ) {
			$payload['price'] = (float) $variation->get_price();
		}
		if ( $variation->managing_stock() && null !== $variation->get_stock_quantity() ) {
			$payload['inventory_quantity'] = (int) $variation->get_stock_quantity();
		}
		$sku = self::valid_sku( $variation->get_sku() );
		if ( $sku && $sku !== self::valid_sku( $parent->get_sku() ) ) {
			// A variation without its own SKU inherits the parent's; don't send that as the variant's.
			$payload['sku'] = $sku;
		}
		$options = array();
		foreach ( $variation->get_variation_attributes( false ) as $taxonomy => $value ) {
			if ( '' === (string) $value ) {
				continue; // "Any" value: not a fixed option.
			}
			$name = wc_attribute_label( $taxonomy, $parent );
			if ( taxonomy_exists( $taxonomy ) ) {
				$term  = get_term_by( 'slug', $value, $taxonomy );
				$value = $term ? $term->name : $value;
			}
			$options[] = array( 'name' => (string) $name, 'value' => (string) $value );
		}
		if ( $options ) {
			$payload['options'] = array_slice( $options, 0, 20 );
		}
		/** Filters the variant fields sent to Yotpo. */
		return (array) apply_filters( 'wc_yotpo_product_sync_variant_payload', $payload, $variation, $parent );
	}

	/**
	 * The brand: the product's brand in WooCommerce Brands (part of WooCommerce since 9.6; the first
	 * in alphabetical order if it has several), else a product attribute named "brand".
	 */
	private static function brand( $product ) {
		$names = Yotpo_Product_Terms::names( $product->get_id(), 'product_brand' );
		return trim( $names ? $names[0] : (string) $product->get_attribute( 'brand' ) );
	}

	/**
	 * Barcodes: WooCommerce's "GTIN, UPC, EAN or ISBN" field (since WooCommerce 9.1), then product
	 * attributes named upc, isbn and ean. The same value is sent once.
	 */
	private static function gtins( $product ) {
		$candidates = array();
		$global_id  = method_exists( $product, 'get_global_unique_id' ) ? trim( (string) $product->get_global_unique_id() ) : '';
		if ( '' !== $global_id ) {
			$candidates[] = array( self::gtin_type( $global_id ), $global_id );
		}
		foreach ( array( 'upc' => 'UPC', 'isbn' => 'ISBN', 'ean' => 'EAN' ) as $attribute => $type ) {
			$candidates[] = array( $type, trim( (string) $product->get_attribute( $attribute ) ) );
		}
		$gtins = array();
		$seen  = array();
		foreach ( $candidates as $candidate ) {
			list( $type, $value ) = $candidate;
			$key                  = self::gtin_digits( $value );
			if ( '' === $value || in_array( $key, $seen, true ) ) {
				continue;
			}
			$seen[]  = $key;
			$gtins[] = $type ? array( 'declared_type' => $type, 'value' => $value ) : array( 'value' => $value );
		}
		return $gtins;
	}

	/**
	 * The standard of a barcode, from its length: UPC (12 digits), ISBN (10, or 13 starting with
	 * 978 or 979), EAN (8 or 13). Empty for anything else (a GTIN-14, say): Yotpo then gets the value
	 * without a declared type.
	 */
	private static function gtin_type( $value ) {
		$digits = self::gtin_digits( $value );
		if ( preg_match( '/^\d{12}$/', $digits ) ) {
			return 'UPC';
		}
		if ( preg_match( '/^97[89]\d{10}$/', $digits ) || preg_match( '/^\d{9}[\dX]$/', $digits ) ) {
			return 'ISBN';
		}
		if ( preg_match( '/^(\d{8}|\d{13})$/', $digits ) ) {
			return 'EAN';
		}
		return '';
	}

	private static function gtin_digits( $value ) {
		return strtoupper( preg_replace( '/[\s-]/', '', (string) $value ) );
	}

	/**
	 * Yotpo rejects SKUs with spaces or "/"; such a SKU is left out rather than failing the product.
	 */
	private static function valid_sku( $sku ) {
		$sku = trim( (string) $sku );
		if ( '' === $sku || preg_match( '#[\s/]#', $sku ) ) {
			return '';
		}
		return self::limit( $sku, 255 );
	}

	private static function limit( $text, $max ) {
		$text = (string) $text;
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}
}
