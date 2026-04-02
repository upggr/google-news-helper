<?php
/**
 * Ad/Nosnippet Handler - DEPRECATED
 *
 * This class previously contained multiple attempts to control Google News thumbnail
 * selection through:
 * - Hidden featured image injection with display:none
 * - Output buffering to add data-nosnippet to competing images
 * - Serving minimal HTML to non-Greek IPs
 *
 * All approaches have been removed because Google News uses a proprietary algorithm
 * that ignores standard signals (og:image, data-nosnippet, hidden images, JSON-LD
 * primaryImageOfPage, fetchpriority, image order). Google News cannot be
 * programmatically controlled to select a specific thumbnail.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GNH_Ad_Nosnippet {

	public function __construct() {
		// This class is kept for backward compatibility but does nothing.
		// All broken thumbnail selection approaches have been removed.
	}
}
