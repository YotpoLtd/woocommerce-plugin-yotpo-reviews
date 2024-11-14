<?php

class Yotpo_Review_Export
{
	const ENCLOSURE = '"';
	const DELIMITER = ',';

	public function downloadReviewToBrowser($file)
	{
		$file_absolute_path = plugin_dir_path(__FILE__) . DIRECTORY_SEPARATOR . $file;
		global $wp_filesystem;

		if (!function_exists('WP_Filesystem')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();

		try {
			if ($wp_filesystem->exists($file_absolute_path)) {
				header('Content-Description: File Transfer');
				header('Content-Type: application/csv');
				header('Content-Disposition: attachment; filename=' . basename($file));
				header('Content-Transfer-Encoding: binary');
				header('Expires: 0');
				header('Cache-Control: must-revalidate');
				header('Pragma: public');
				header('Content-Length: ' . $wp_filesystem->size($file_absolute_path));
				ob_clean();
				flush();
				echo esc_html($wp_filesystem->get_contents($file_absolute_path));
				// Delete the file after it was downloaded.
				$wp_filesystem->delete($file_absolute_path);
				exit;
			} else {
				throw new Exception('File does not exist.');
			}
		} catch (Exception $e) {
			error_log($e->getMessage());
			return $e->getMessage();
		}
	}

	/**
	 * export given reviews to csv file in var/export.
	 */
	public function exportReviews()
	{
		try {
			$fileName = 'review_export_' . gmdate("Ymd_His") . '.csv';
			$filePath = plugin_dir_path(__FILE__) . $fileName;

			// Initialize WP_Filesystem
			global $wp_filesystem;
			if (!function_exists('WP_Filesystem')) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			WP_Filesystem();

			// Create or open the file
			if (!$wp_filesystem->put_contents($filePath, '', FS_CHMOD_FILE)) {
				throw new Exception('Failed to create the file for export.');
			}

			// Write the header row
			$headRow = $this->generateHeadRow(); // Assume this method returns the CSV header row as a string.
			$wp_filesystem->put_contents($filePath, $headRow, FS_CHMOD_FILE);

			// Load all reviews with their votes
			$allReviews = $this->getAllReviews();

			// Append each review to the file
			foreach ($allReviews as $fullReview) {
				$reviewRow = $this->generateReviewRow($fullReview); // Assume this method formats a review row as a string.
				$existingContent = $wp_filesystem->get_contents($filePath);
				$wp_filesystem->put_contents($filePath, $existingContent . $reviewRow, FS_CHMOD_FILE);
			}

			return array($fileName, null);
		} catch (Exception $e) {
			error_log($e->getMessage());
			return array(null, $e->getMessage());
		}
	}

	protected function generateReviewRow($fullReview)
	{
		$review_row_string = array(
			$fullReview['review_title'],
			$fullReview['review_content'],
			$fullReview['display_name'],
			$fullReview['user_email'],
			$fullReview['user_type'],
			$fullReview['review_score'],
			$fullReview['date'],
			$fullReview['sku'],
			$fullReview['product_title'],
			$fullReview['product_description'],
			$fullReview['product_url'],
			$fullReview['product_image_url'],
		);

		return implode(self::DELIMITER, $review_row_string) . "\n";
	}

	protected function generateHeadRow()
	{
		return implode(self::DELIMITER, $this->getHeadRowValues()) . "\n";
	}

	/**
	 * Writes the head row with the column names in the csv file.
	 */
	protected function writeHeadRow($fp)
	{
		fputcsv($fp, $this->getHeadRowValues(), self::DELIMITER, self::ENCLOSURE);
	}

	/**
	 * Writes the row(s) for the given review in the csv file.
	 * A row is added to the csv file for each reviewed item.
	 */
	protected function writeReview($review, $fp)
	{
		$review = (array)$review;
		fputcsv($fp, $review, self::DELIMITER, self::ENCLOSURE);
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
			$product_instance = get_product($value->product_id);
			$current_review = array();
			$review_content = $this->cleanContent($value->review_content);
			$current_review['review_title'] = $this->getFirstWords($review_content);
			$current_review['review_content'] = $review_content;
			$current_review['display_name'] = $this->cleanContent($value->display_name);
			$current_review['user_email'] = $value->user_email;
			$current_review['user_type'] = woocommerce_customer_bought_product($value->user_email, $value->user_id, $value->product_id) ? 'verified_buyer' : '';
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
		return html_entity_decode(wp_strip_all_tags(strip_shortcodes($content)));
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

?>