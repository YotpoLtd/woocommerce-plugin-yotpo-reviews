<?php
	require( '../../../wp-load.php' );
	require('classes/class-wc-yotpo-export-reviews.php');
	$export = new Yotpo_Review_Export();
	list($file, $errors) = $export->exportReviews();
	if(is_null($errors)) {
		$export->downloadReviewToBrowser($file);	
	}
?>