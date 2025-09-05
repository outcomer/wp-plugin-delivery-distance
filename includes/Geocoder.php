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
 * Google Geocoding API integration
 */
class Geocoder
{
	private string $apiKey;
	private int $cacheTime = 900; // 15 minutes

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		$this->apiKey = ODD_GOOGLE_API_KEY;
	}

	/**
	 * Geocode address to get coordinates
	 */
	public function geocodeAddress(string $address): array|false
	{
		$cacheKey = 'odd_geocode_'.md5($address);
		$cached   = get_transient($cacheKey);

		if (false !== $cached) {
			return $cached;
		}

		$url = add_query_arg([
			'address' => urlencode($address),
			'key'     => $this->apiKey,
			'region'  => 'cz',
		], 'https://maps.googleapis.com/maps/api/geocode/json');

		$response = wp_remote_get($url, [
			'timeout' => 10,
			'headers' => ['Accept' => 'application/json'],
		]);

		if (is_wp_error($response)) {
			error_log('Geocoding API error: '.$response->get_error_message());

			return false;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (!$data || $data['status'] !== 'OK' || empty($data['results'])) {
			error_log('Geocoding API response error: '.($data['status'] ?? 'Unknown'));

			return false;
		}

		$result   = $data['results'][0];
		$location = $result['geometry']['location'];

		$geocodeResult = [
			'lat'               => (float) $location['lat'],
			'lng'               => (float) $location['lng'],
			'formatted_address' => $result['formatted_address'],
			'place_id'          => $result['place_id'],
		];

		// Cache the result
		set_transient($cacheKey, $geocodeResult, $this->cacheTime);

		return $geocodeResult;
	}

	/**
	 * Validate address by attempting to geocode it
	 */
	public function validateAddress(string $address): bool
	{
		$result = $this->geocodeAddress($address);

		return $result !== false;
	}

	/**
	 * Get place details by place ID
	 */
	public function getPlaceDetails(string $placeId): array|false
	{
		$cacheKey = 'odd_place_'.md5($placeId);
		$cached   = get_transient($cacheKey);

		if (false !== $cached) {
			return $cached;
		}

		$url = add_query_arg([
			'place_id' => $placeId,
			'key'      => $this->apiKey,
			'fields'   => 'geometry,formatted_address,address_components',
		], 'https://maps.googleapis.com/maps/api/place/details/json');

		$response = wp_remote_get($url, [
			'timeout' => 10,
			'headers' => ['Accept' => 'application/json'],
		]);

		if (is_wp_error($response)) {
			error_log('Place Details API error: '.$response->get_error_message());

			return false;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (!$data || $data['status'] !== 'OK' || empty($data['result'])) {
			error_log('Place Details API response error: '.($data['status'] ?? 'Unknown'));

			return false;
		}

		$result   = $data['result'];
		$location = $result['geometry']['location'];

		$placeDetails = [
			'lat'                => (float) $location['lat'],
			'lng'                => (float) $location['lng'],
			'formatted_address'  => $result['formatted_address'],
			'address_components' => $result['address_components'] ?? [],
		];

		// Cache the result
		set_transient($cacheKey, $placeDetails, $this->cacheTime);

		return $placeDetails;
	}

	/**
	 * Extract address components from geocoding result
	 */
	public function extractAddressComponents(array $addressComponents): array
	{
		$components = [
			'street_number' => '',
			'route'         => '',
			'locality'      => '',
			'postal_code'   => '',
			'country'       => '',
		];

		foreach ($addressComponents as $component) {
			$types = $component['types'];

			if (in_array('street_number', $types)) {
				$components['street_number'] = $component['long_name'];
			}
			if (in_array('route', $types)) {
				$components['route'] = $component['long_name'];
			}
			if (in_array('locality', $types) || in_array('administrative_area_level_1', $types)) {
				$components['locality'] = $component['long_name'];
			}
			if (in_array('postal_code', $types)) {
				$components['postal_code'] = $component['long_name'];
			}
			if (in_array('country', $types)) {
				$components['country'] = $component['short_name'];
			}
		}

		return $components;
	}
}
