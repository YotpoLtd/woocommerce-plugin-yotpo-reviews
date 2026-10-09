<?php

defined( 'ABSPATH' ) || exit;

class Yotpo_Review_Export
{
	const ENCLOSURE = '"';
	const DELIMITER = ',';

	/**
	 * Streams all native WooCommerce reviews to the browser as a CSV download.
	 *
	 * Nothing is written to disk: the export contains reviewer emails, and files written
	 * inside the plugin folder were publicly reachable by URL.
	 */
	public function streamReviewsCsv()
	{
		$reviews = $this->getAllReviews();
		ytdbg(count($reviews) . ' reviews', 'Reviews Export:');

		// Drop anything already buffered so it does not end up inside the CSV.
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		nocache_headers();
		header('Content-Description: File Transfer');
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=review_export_' . gmdate('Ymd_His') . '.csv');

		// php://output is the response body, not a file on disk.
		$output = fopen('php://output', 'w'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv($output, $this->getHeadRowValues(), self::DELIMITER, self::ENCLOSURE, '');
		foreach ($reviews as $review) {
			fputcsv($output, $this->getReviewRowValues($review), self::DELIMITER, self::ENCLOSURE, '');
		}
		fclose($output); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	protected function getHeadRowValues()
	{
		return array(
			'review_title',
			'review_content',
			'display_name',
			'user_email',
			'user_type',
			'review_score',
			'date',
			'sku',
			'product_title',
			'product_description',
			'product_url',
			'product_image_url',
		);
	}

	protected function getReviewRowValues($review)
	{
		$row = array();
		foreach ($this->getHeadRowValues() as $column) {
			$row[] = $this->neutralizeFormula(isset($review[$column]) ? $review[$column] : '');
		}
		return $row;
	}

	/**
	 * Prefixes cells starting with "=" with a single quote, so a reviewer-written formula does not
	 * run when an admin opens the export in Excel or Sheets.
	 *
	 * Deliberately limited to "=": the file is meant for import into Yotpo, which keeps a leading
	 * quote, so guarding "+", "-" and "@" turned reviews like "- Great quality" into
	 * "'- Great quality" on the storefront.
	 */
	protected function neutralizeFormula($value)
	{
		if (is_string($value) && $value !== '' && $value[0] === '=') {
			return "'" . $value;
		}
		return $value;
	}

	protected function getAllReviews()
	{
		global $wpdb;
		$results = $wpdb->get_results(
			$wpdb->prepare("SELECT comment_post_ID AS product_id,
						 comment_author AS display_name,
						 comment_date AS date,
						 comment_author_email AS user_email,
						 comment_content AS review_content,
						 meta_value AS review_score,
						 post_content AS product_description,
						 post_title AS product_title,
						 user_id
				 FROM {$wpdb->prefix}comments
				 INNER JOIN {$wpdb->prefix}posts ON {$wpdb->prefix}posts.ID = {$wpdb->prefix}comments.comment_post_ID
				 INNER JOIN {$wpdb->prefix}commentmeta ON {$wpdb->prefix}commentmeta.comment_id = {$wpdb->prefix}comments.comment_ID
				 WHERE post_type = %s AND meta_key = %s",
				'product',
				'rating'
			)
		);

		$all_reviews = array();
		foreach ($results as $value) {
			$current_review = array();
			$review_content = $this->cleanContent($value->review_content);
			$current_review['review_title'] = $this->getFirstWords($review_content);
			$current_review['review_content'] = $review_content;
			$current_review['display_name'] = $this->cleanContent($value->display_name);
			$current_review['user_email'] = $value->user_email;
			$current_review['user_type'] = wc_customer_bought_product($value->user_email, $value->user_id, $value->product_id) ? 'verified_buyer' : '';
			$current_review['review_score'] = $value->review_score;
			$current_review['date'] = $value->date;
			$current_review['sku'] = $value->product_id;
			$current_review['product_title'] = $this->cleanContent($value->product_title);
			$current_review['product_description'] = $this->cleanContent(get_post($value->product_id)->post_excerpt);
			$current_review['product_url'] = get_permalink($value->product_id);
			$current_review['product_image_url'] = wc_yotpo_get_product_image_url($value->product_id);
			$all_reviews[] = $current_review;
		}
		return $all_reviews;
	}

	private function cleanContent($content)
	{
		$content = preg_replace('/\<br(\s*)?\/?\>/i', "\n", $content);
		return html_entity_decode(wp_strip_all_tags(strip_shortcodes($content)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	private function getFirstWords($content = '', $number_of_words = 5)
	{
		$words = str_word_count($content, 1);
		if (count($words) > $number_of_words) {
			return join(" ", array_slice($words, 0, $number_of_words));
		} else {
			return join(" ", $words);
		}
	}
}
