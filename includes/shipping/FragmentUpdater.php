<?php
/**
 * This file is part of the Outcomer package.
 *
 * (c) David Evdoshchenko <773021792e@gmail.com>
 *
 * @author David Evdoshchenko <773021792e@gmail.com>
 *
 * @package Outcomer\DeliveryDistance
 */

declare(strict_types = 1);

namespace OutcomerDelivery\shipping;

use DOMDocument;
use DOMElement;
use DomXPath;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Handles checkout fragment updates to force UI refresh
 */
class FragmentUpdater
{
	/**
	 * Initialize fragment update hooks
	 */
	public function init(): void
	{
		add_filter('woocommerce_update_order_review_fragments', [$this, 'addTimestampToFragments']);
	}

	/**
	 * Add timestamp to fragments to force update
	 */
	public function addTimestampToFragments(array $fragments): array
	{
		// Add timestamp to order review table to force update
		if (isset($fragments['.woocommerce-checkout-review-order-table'])) {
			$html      = $fragments['.woocommerce-checkout-review-order-table'];
			$timestamp = time();

			$dom = new DOMDocument();
			@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

			// Find the table element
			$xpath  = new DOMXPath($dom);
			$tables = $xpath->query('//table[contains(@class, "shop_table")]');

			if ($tables->length > 0) {
				/** @var DOMElement $table */
				$table = $tables->item(0);
				$table->setAttribute('data-timestamp', (string) $timestamp);
			}

			// Get modified HTML
			$modifiedHtml = $dom->saveHTML();
			$modifiedHtml = str_replace('<?xml encoding="utf-8" ?>', '', $modifiedHtml);

			$fragments['.woocommerce-checkout-review-order-table'] = $modifiedHtml;
		}

		return $fragments;
	}
}
