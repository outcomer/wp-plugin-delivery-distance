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

use OutcomerDelivery\DistanceCalculator;
use OutcomerDelivery\Geocoder;
use WC_Shipping_Rate;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Validates shipping method availability based on distance
 */
class ShippingValidator
{
	private DistanceCalculator $distanceCalculator;

	/**
	 * Constructor.
	 */
	public function __construct(DistanceCalculator $distanceCalculator)
	{
		$this->distanceCalculator = $distanceCalculator;
	}

	/**
	 * Validate if shipping is available to the given address
	 */
	public function validateShippingToAddress(string $address): array
	{
		// Check 0: No address provided
		if (empty($address)) {
			return [
				'available' => false,
				'reason'    => 'no_address',
				'distance'  => null,
			];
		}

		// Use geocoder to get coordinates
		$geocoder     = new Geocoder();
		$locationData = $geocoder->geocodeAddress($address);

		// Check 1: Geocoding failed
		if (!$locationData) {
			return [
				'available' => false,
				'reason'    => 'geocoding_failed',
				'distance'  => null,
			];
		}

		// Calculate distance
		$distance = $this->distanceCalculator->calculateDistanceFromStore(
			$locationData['lat'],
			$locationData['lng']
		);

		// Check 2: Distance too far
		if (!$this->distanceCalculator->isWithinDeliveryRange($distance)) {
			return [
				'available' => false,
				'reason'    => 'distance_too_far',
				'distance'  => $distance,
			];
		}

		// All checks passed
		return [
			'available' => true,
			'reason'    => null,
			'distance'  => $distance,
		];
	}

	/**
	 * Check if shipping rate should use distance-based validation
	 */
	public function isDistanceBasedShippingRate(WC_Shipping_Rate $rate): bool
	{
		// Extract instance ID from rate ID
		$parts = explode(':', $rate->get_id());

		if (count($parts) >= 2) {
			$instanceId = (int) $parts[1];

			return in_array($instanceId, ODD_ENABLED_SHIPPING_INSTANCE_IDS);
		}

		return false;
	}

	/**
	 * Check if a rate is marked as unavailable
	 */
	public function isRateUnavailable(WC_Shipping_Rate $rate): bool
	{
		$meta = $rate->get_meta_data();

		return isset($meta['_outcomer_available']) && !$meta['_outcomer_available'];
	}
}
