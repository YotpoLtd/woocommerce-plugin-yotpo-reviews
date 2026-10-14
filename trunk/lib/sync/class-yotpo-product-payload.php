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
		// The blocklist flag is only sent to undo the plugin's own blocklisting, of a product that was
		// unpublished and is published again. A product the merchant blocklisted in Yotpo (catalog CSV,
		// Yotpo admin) keeps that: 1.10.0 always sent false here, which took such products off the blocklist.
		if ( get_post_meta( $product->get_id(), Yotpo_Catalog_Api::META_BLOCKLISTED, true ) ) {
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
		foreach ( array( 'brand', 'mpn' ) as $field ) {
			$value = trim( (string) $product->get_attribute( $field ) );
			if ( '' !== $value ) {
				$payload[ $field ] = self::limit( $value, 255 );
			}
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

	private static function gtins( $product ) {
		$gtins = array();
		foreach ( array( 'upc' => 'UPC', 'isbn' => 'ISBN', 'ean' => 'EAN' ) as $attribute => $type ) {
			$value = trim( (string) $product->get_attribute( $attribute ) );
			if ( '' !== $value ) {
				$gtins[] = array( 'declared_type' => $type, 'value' => $value );
			}
		}
		return $gtins;
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
