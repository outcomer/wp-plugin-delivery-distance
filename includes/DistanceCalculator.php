<?php

namespace OutcomerDelivery;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Distance calculation using Haversine formula
 */
class DistanceCalculator {
	
	/**
	 * Calculate distance between two points using Haversine formula
	 */
	public function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float {
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
	 * Get delivery zone based on distance
	 */
	public function getDeliveryZone(float $distance): int|false {
		foreach (ODD_DISTANCE_PRICING as $maxDistance => $price) {
			if ($distance < $maxDistance) {
				if ($price === false) {
					return false; // No delivery available
				}
				return array_search($maxDistance, array_keys(ODD_DISTANCE_PRICING)) + 1;
			}
		}
		return false;
	}
	
	/**
	 * Get price for delivery zone
	 */
	public function getZonePrice(int $zone): int|false {
		$zones = array_keys(ODD_DISTANCE_PRICING);
		if (isset($zones[$zone - 1])) {
			return ODD_DISTANCE_PRICING[$zones[$zone - 1]];
		}
		return false;
	}
	
	/**
	 * Check if distance is within delivery range
	 */
	public function isWithinDeliveryRange(float $distance): bool {
		return $this->getDeliveryZone($distance) !== false;
	}
	
	/**
	 * Get price based on distance
	 */
	public function getPriceByDistance(float $distance): int|false {
		foreach (ODD_DISTANCE_PRICING as $maxDistance => $price) {
			if ($distance < $maxDistance) {
				return $price;
			}
		}
		return false;
	}
	
	/**
	 * Calculate distance from store to coordinates
	 */
	public function calculateDistanceFromStore(float $lat, float $lng): float {
		return $this->calculateDistance(ODD_STORE_LAT, ODD_STORE_LNG, $lat, $lng);
	}
}