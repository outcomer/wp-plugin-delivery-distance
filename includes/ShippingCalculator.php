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

use DOMDocument;
use DomXPath;
use WC_Shipping_Rate;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Calculate shipping costs based on distance
 */
class ShippingCalculator
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
	 * Initialize shipping hooks
	 */
	public function init(): void
	{
		add_filter('woocommerce_package_rates', [$this, 'modifyShippingRates'], 10, 2);
		add_filter('woocommerce_update_order_review_fragments', [$this, 'addShippingMethodFragment']);
		add_action('woocommerce_review_order_before_shipping', [$this, 'validateCurrentShippingSelection']);
	}

	/**
	 * Modify shipping rates based on distance
	 */
	public function modifyShippingRates(array $rates, array $package): array
	{
		if (empty($rates)) {
			return $rates;
		}

		// Get delivery address from package
		$address = $this->getAddressFromPackage($package);

		// Check 0: No address provided
		if (empty($address)) {
			foreach ($rates as $rateId => $rate) {
				if ($this->isDistanceBasedShippingRate($rate)) {
					$this->markRateAsUnavailable($rate, 'no_address');
					$rates[$rateId] = $rate;
				}
			}
			$this->forceDefaultShippingMethod($rates);

			return $rates;
		}

		// Use geocoder to get coordinates
		$geocoder     = new Geocoder();
		$locationData = $geocoder->geocodeAddress($address);

		// Check 1: Geocoding failed
		if (!$locationData) {
			foreach ($rates as $rateId => $rate) {
				if ($this->isDistanceBasedShippingRate($rate)) {
					$this->markRateAsUnavailable($rate, 'geocoding_failed');
					$rates[$rateId] = $rate;
				}
			}
			$this->forceDefaultShippingMethod($rates);

			return $rates;
		}

		// Calculate distance
		$distance = $this->distanceCalculator->calculateDistanceFromStore(
			$locationData['lat'],
			$locationData['lng']
		);

		// Check 2: Distance too far
		if (!$this->distanceCalculator->isWithinDeliveryRange($distance)) {
			foreach ($rates as $rateId => $rate) {
				if ($this->isDistanceBasedShippingRate($rate)) {
					$this->markRateAsUnavailable($rate, 'distance_too_far', $distance);
					$rates[$rateId] = $rate;
				}
			}
			$this->forceDefaultShippingMethod($rates);

			return $rates;
		}

		// All checks passed - normal processing
		foreach ($rates as $rateId => $rate) {
			if ($this->isDistanceBasedShippingRate($rate)) {
				$rates[$rateId] = $this->updateRateWithDistance($rate, $distance);
			}
		}

		return $rates;
	}


	/**
	 * Add shipping method fragment to force update on checkout
	 */
	public function addShippingMethodFragment($fragments)
	{
		if (isset($fragments['.woocommerce-checkout-review-order-table'])) {
			$html      = $fragments['.woocommerce-checkout-review-order-table'];
			$timestamp = time();

			$dom = new DOMDocument();
			$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

			$xpath  = new DOMXPath($dom);
			$tables = $xpath->query('//table[contains(@class, "shop_table")]');

			if ($tables->length > 0) {
				$table = $tables->item(0);
				$table->setAttribute('data-timestamp', (string) $timestamp);
			}

			$modifiedHtml = $dom->saveHTML();
			$modifiedHtml = str_replace('<?xml encoding="utf-8" ?>', '', $modifiedHtml);

			$fragments['.woocommerce-checkout-review-order-table'] = $modifiedHtml;
		}

		return $fragments;
	}

	/**
	 * Validate current shipping selection before rendering
	 */
	public function validateCurrentShippingSelection()
	{
		$chosenMethods = WC()->session->get('chosen_shipping_methods');
		$packages      = WC()->shipping()->get_packages();

		if (empty($chosenMethods) || empty($packages)) {
			return;
		}

		$needsUpdate = false;

		foreach ($chosenMethods as $key => $chosenMethod) {
			if (!isset($packages[$key]['rates'][$chosenMethod])) {
				continue;
			}

			$rate = $packages[$key]['rates'][$chosenMethod];

			// Check if this is a distance-based shipping method
			if ($this->isDistanceBasedShippingRate($rate)) {
				$meta = $rate->get_meta_data();

				// If method is marked as unavailable
				if (isset($meta['_outcomer_available']) && !$meta['_outcomer_available']) {
					// Switch to default method
					$defaultMethod       = $this->getDefaultShippingMethod($packages[$key]['rates']);
					$chosenMethods[$key] = $defaultMethod;
					$needsUpdate         = true;
				}
			}
		}

		// Update session if needed
		if ($needsUpdate) {
			WC()->session->set('chosen_shipping_methods', $chosenMethods);
		}
	}

	/**
	 * Update rate with distance-based price
	 */
	private function updateRateWithDistance(WC_Shipping_Rate $rate, float $distance): WC_Shipping_Rate
	{
		// At this point we know delivery is available, so just update normally
		$originalLabel = $rate->get_label();

		// Get shipping class based on distance
		$shippingClassId = $this->distanceCalculator->getShippingClassByDistance($distance);

		// Get price for this shipping class
		$calculatedPrice = $this->distanceCalculator->getPriceByDistance($distance);

		if (false !== $calculatedPrice) {
			// Update rate cost only if we have valid price
			$rate->set_cost($calculatedPrice);
		}

		// Update rate label to include distance and zone info
		$zone = $this->distanceCalculator->getDeliveryZone($distance);

		$newLabel = sprintf(
			'%s (%s: %s, %s: %.1fkm)',
			$originalLabel,
			__('zone', 'outcomer-delivery-distance'),
			$zone,
			__('distance', 'outcomer-delivery-distance'),
			$distance
		);
		$rate->set_label($newLabel);

		// Add meta data for debugging
		$rate->add_meta_data('_outcomer_distance', $distance);
		$rate->add_meta_data('_outcomer_shipping_class_id', $shippingClassId);
		$rate->add_meta_data('_outcomer_calculated_price', $calculatedPrice);
		$rate->add_meta_data('_outcomer_zone', $zone);
		$rate->add_meta_data('_outcomer_available', true);

		return $rate;
	}

	/**
	 * Check if shipping rate should use distance-based pricing
	 */
	private function isDistanceBasedShippingRate(WC_Shipping_Rate $rate): bool
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
	 * Mark rate as unavailable
	 */
	private function markRateAsUnavailable(WC_Shipping_Rate $rate, string $reason, ?float $distance = null): void
	{
		$originalLabel = $rate->get_label();

		switch ($reason) {
			case 'no_address':
				$newLabel = sprintf(
					'%s (%s)',
					$originalLabel,
					__('No address provided', 'outcomer-delivery-distance')
				);
				break;

			case 'geocoding_failed':
				$newLabel = sprintf(
					'%s (%s)',
					$originalLabel,
					__('Address validation failed', 'outcomer-delivery-distance')
				);
				break;

			case 'distance_too_far':
				$newLabel = sprintf(
					'%s (%s: %s, %s: %.1fkm)',
					$originalLabel,
					__('zone', 'outcomer-delivery-distance'),
					__('unavailable', 'outcomer-delivery-distance'),
					__('distance', 'outcomer-delivery-distance'),
					$distance ?? 0
				);
				break;

			default:
				$newLabel = $originalLabel;
				break;
		}

		$rate->set_label($newLabel);
		$rate->set_cost(0);
		$rate->add_meta_data('_outcomer_available', false);
		$rate->add_meta_data('_outcomer_error_reason', $reason);

		if (!is_null($distance)) {
			$rate->add_meta_data('_outcomer_distance', $distance);
		}
	}

	/**
	 * Get address from package
	 */
	private function getAddressFromPackage(array $package): string
	{
		$destination = $package['destination'] ?? [];

		$addressParts = [];

		if (!empty($destination['address'])) {
			$addressParts[] = $destination['address'];
		}
		if (!empty($destination['city'])) {
			$addressParts[] = $destination['city'];
		}
		if (!empty($destination['postcode'])) {
			$addressParts[] = $destination['postcode'];
		}

		return trim(implode(', ', array_filter($addressParts)));
	}

	/**
	 * Get default shipping method from available rates
	 */
	private function getDefaultShippingMethod($rates)
	{
		if (empty(ODD_DEFAULT_SHIPPING_INSTANCE_IDS)) {
			// No default configured, return first available
			return key($rates);
		}

		// Look for default shipping method
		foreach (ODD_DEFAULT_SHIPPING_INSTANCE_IDS as $defaultId) {
			foreach ($rates as $rateId => $rate) {
				if (strpos($rateId, ':'.$defaultId) !== false) {
					return $rateId;
				}
			}
		}

		// Default not found, return first available
		return key($rates);
	}

	/**
	 * Force selection of default shipping method
	 */
	private function forceDefaultShippingMethod(array $rates): void
	{
		if (empty(ODD_DEFAULT_SHIPPING_INSTANCE_IDS)) {
			return;
		}

		$defaultInstanceId = ODD_DEFAULT_SHIPPING_INSTANCE_IDS[0];
		foreach ($rates as $rateId => $rate) {
			if (strpos($rateId, ':'.$defaultInstanceId) !== false) {
				// IMMEDIATELY set the chosen method - no delays, no hooks
				WC()->session->set('chosen_shipping_methods', [$rateId]);
				$_POST['shipping_method'] = [$rateId];
				break;
			}
		}
	}
}
