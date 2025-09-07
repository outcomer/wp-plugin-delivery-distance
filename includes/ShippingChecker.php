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

namespace OutcomerDelivery;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Utility class to check if distance-based shipping is selected
 */
class ShippingChecker
{
	/**
	 * Check if distance-based shipping method is selected
	 */
	public static function isDistanceBasedShippingSelected(): bool
	{
		$chosenMethods = WC()->session->get('chosen_shipping_methods');

		if (empty($chosenMethods)) {
			return false;
		}

		foreach ($chosenMethods as $method) {
			// Extract instance ID from method (e.g., "flat_rate:2" -> "2")
			$parts = explode(':', $method);
			if (count($parts) >= 2) {
				$instanceId = (int) $parts[1];
				if (in_array($instanceId, ODD_ENABLED_SHIPPING_INSTANCE_IDS)) {
					return true;
				}
			}
		}

		return false;
	}
}
