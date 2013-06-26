<?php

class Yotpo_Review_Export
{
    const ENCLOSURE = '"';
    const DELIMITER = ',';
	
    public function downloadReviewToBrowser($file) {
    	$file_absoulute_path = plugin_dir_path( __FILE__ ).DIRECTORY_SEPARATOR.$file;
    	try 
    	{
    		if (file_exists($file_absoulute_path)) {
			    header('Content-Description: File Transfer');
			    header('Content-Type: application/octet-stream');
			    header('Content-Disposition: attachment; filename='.($file));
			    header('Content-Transfer-Encoding: binary');
			    header('Expires: 0');
			    header('Cache-Control: must-revalidate');
			    header('Pragma: public');
			    header('Content-Length: ' . filesize($file_absoulute_path));
			    ob_clean();
			    flush();
			    readfile($file_absoulute_path);
			    //delete the file after it was downloaded.
			    unlink($file_absoulute_path);
			    return null;
			}
    	}
        catch (Exception $e) 
        {
        	error_log($e->getMessage());
        	return $e->getMessage();              
        }
    }
    
    /**
     * export given reviews to csv file in var/export.
     */
	public function exportReviews()
    {
        try 
        {
            $fileName = 'review_export_'.date("Ymd_His").'.csv';
            $fp = fopen(plugin_dir_path( __FILE__ ).'/'.$fileName, 'w');                
            $this->writeHeadRow($fp);

            # Load all reviews with thier votes
            $allReviews = $this->getAllReviews();

            foreach ($allReviews as $fullReview) 
            {   
	            $this->writeReview($fullReview, $fp);
            }
            fclose($fp);
            return array($fileName, null);
        } 
        catch (Exception $e) 
        {
        	error_log($e->getMessage());
        	return array(null, $e->getMessage());              
        }
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
    	$review = (array) $review;    
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

    protected function getAllReviews() {   
    	global $wpdb;
		$query = "SELECT comment_post_ID AS product_id, 
						 comment_author AS display_name, 
						 comment_date AS date,
						 comment_author_email AS user_email, 
						 comment_content AS review_content, 
						 meta_value AS review_score,
						 post_content AS product_description,
						 post_title AS product_title,
						 user_id
				  FROM `".$wpdb->prefix."comments` 
				  INNER JOIN `".$wpdb->prefix."posts` ON `".$wpdb->prefix."posts`.`ID` = `".$wpdb->prefix."comments`.`comment_post_ID` 
				  INNER JOIN `".$wpdb->prefix."commentmeta` ON `".$wpdb->prefix."commentmeta`.`comment_id` = `".$wpdb->prefix."comments`.`comment_ID` 
				  WHERE `post_type` = 'product' AND meta_key='rating'";
		$results = $wpdb->get_results($query);
		$all_reviews = array();
		foreach ($results as $value) {
			$product_instance = get_product($value->product_id);
			$current_review = array();		
			$current_review['review_title'] = $value->product_title;
			$current_review['review_content'] = $value->review_content;
			$current_review['display_name'] = $value->display_name;
			$current_review['user_email'] = $value->user_email;
			$current_review['user_type'] = woocommerce_customer_bought_product($value->user_email, $value->user_id, $value->product_id) ? 'verified_buyer' : '';
			$current_review['review_score'] = $value->review_score;
			$current_review['date'] = $value->date;
			$current_review['sku'] = $value->product_id;
			$current_review['product_title'] = $value->product_title;
			$current_review['product_description'] = strip_tags($product_instance->get_post_data()->post_excerpt);
			$current_review['product_url'] = get_permalink($value->product_id);
			$current_review['product_image_url'] = wc_yotpo_get_product_image_url($value->product_id);
			$all_reviews[] = $current_review;
		}
		return $all_reviews;
    }        
}
?>