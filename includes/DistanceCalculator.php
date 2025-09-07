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
 * Distance calculation using Haversine formula
 */
class DistanceCalculator
{
	private array $shippingClasses = [];
	private array $classCosts      = [];
	private bool $classesLoaded    = false;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		// Don't load classes in constructor - will load lazily when needed
	}

	/**
	 * Calculate distance between two points using Haversine formula
	 */
	public function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
	{
		$earthRadius = 6371; // Earth's radius in kilometers

		$latDelta = deg2rad($lat2 - $lat1);
		$lngDelta = deg2rad($lng2 - $lng1);

		$a = sin($latDelta / 2) * sin($latDelta / 2) +
			 cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
			 sin($lngDelta / 2) * sin($lngDelta / 2);

		$c = 2 * atan2(sqrt($a), sqrt(1 - $a));

		return $earthRadius * $c;
	}

	/**
	 * Get shipping class ID based on distance
	 */
	public function getShippingClassByDistance(float $distance): int|false
	{
		// Lazy load shipping classes if not loaded yet
		if (!$this->classesLoaded) {
			$this->loadShippingClassesAndCosts();
			$this->classesLoaded = true;
		}

		// Map distance to shipping class based on slug pattern
		foreach ($this->shippingClasses as $termId => $class) {
			$slug = $class['slug'];

			// Try each pattern matcher
			foreach (ODD_DISTANCE_MATCHERS as $pattern => $callback) {
				if (preg_match($pattern, $slug, $matches)) {
					$range = $callback($matches);
					if ($range && $distance >= $range['min'] && $distance < $range['max']) {
						return $termId;
					}
				}
			}
		}

		return false; // No matching class found
	}

	/**
	 * Get delivery zone based on distance (for backward compatibility)
	 */
	public function getDeliveryZone(float $distance): int|string|false
	{
		$classId = $this->getShippingClassByDistance($distance);
		if (false !== $classId && isset($this->shippingClasses[$classId])) {
			return $this->shippingClasses[$classId]['name'];
		}

		return false;
	}


	/**
	 * Check if distance is within delivery range
	 */
	public function isWithinDeliveryRange(float $distance): bool
	{
		return $this->getShippingClassByDistance($distance) !== false;
	}

	/**
	 * Get price based on distance
	 */
	public function getPriceByDistance(float $distance): float|false
	{
		// Lazy load shipping classes if not loaded yet
		if (!$this->classesLoaded) {
			$this->loadShippingClassesAndCosts();
			$this->classesLoaded = true;
		}

		$classId = $this->getShippingClassByDistance($distance);
		if (false !== $classId && isset($this->classCosts[$classId])) {
			return $this->classCosts[$classId];
		}

		return false;
	}

	/**
	 * Calculate distance from store to coordinates
	 */
	public function calculateDistanceFromStore(float $lat, float $lng): float
	{
		return $this->calculateDistance(ODD_STORE_LAT, ODD_STORE_LNG, $lat, $lng);
	}

	/**
	 * Load shipping classes and their costs from database
	 */
	private function loadShippingClassesAndCosts(): void
	{
		// Get shipping classes
		$terms = get_terms([
			'taxonomy'   => 'product_shipping_class',
			'hide_empty' => false,
		]);

		if (!is_wp_error($terms)) {
			foreach ($terms as $term) {
				$this->shippingClasses[$term->term_id] = [ // phpcs:ignore Zend.NamingConventions.ValidVariableName.NotCamelCaps
					'slug' => $term->slug,
					'name' => $term->name,
				];
			}
		}

		// Get shipping method settings for each enabled instance
		foreach (ODD_ENABLED_SHIPPING_INSTANCE_IDS as $instanceId) {
			$settings = get_option('woocommerce_flat_rate_'.$instanceId.'_settings');
			if ($settings) {
				foreach ($this->shippingClasses as $termId => $class) {
					$costKey = 'class_cost_'.$termId;
					if (isset($settings[$costKey])) {
						$this->classCosts[$termId] = (float) $settings[$costKey];
					}
				}
			}
		}
	}
}
