# Outcomer Delivery Distance Plugin

WooCommerce plugin for automatic address input with Google Places API and delivery cost calculation based on distance.

## Features

- ✅ Address autocomplete on checkout page via Google Places API
- ✅ Automatic filling of address, city and postal code fields
- ✅ Server-side address validation via Google Geocoding API
- ✅ Dynamic delivery cost calculation based on distance from store
- ✅ Delivery zone limitation (maximum 6km)
- ✅ Support only for addresses in Czech Republic
- ✅ API request caching for performance optimization

## Requirements

- WordPress ≥ 6.4
- WooCommerce ≥ 10.0
- PHP ≥ 8.2
- Google API key with activated services:
  - Places API
  - Geocoding API
  - Maps JavaScript API

## Installation

1. Upload the plugin folder to `wp-content/plugins/`
2. Activate the plugin in WordPress admin panel
3. Make sure WooCommerce is active

## Configuration

All settings are located in the `outcomer-delivery-distance.php` file:

```php
// Google API key
define('ODD_GOOGLE_API_KEY', 'your_key_here');

// Store coordinates (Prague)
define('ODD_STORE_LAT', 50.1260);
define('ODD_STORE_LNG', 14.4698);

// Country restriction
define('ODD_COUNTRY_RESTRICT', ['CZ']);

// Shipping method IDs to apply logic
define('ODD_ENABLED_SHIPPING_INSTANCE_IDS', [2]);

// Pricing tiers (distance in km => price in CZK)
define('ODD_DISTANCE_PRICING', [
    1 => 100,   // < 1km = 100 CZK
    3 => 150,   // 1-3km = 150 CZK  
    6 => 160,   // 3-6km = 160 CZK
    PHP_INT_MAX => false // > 6km = delivery unavailable
]);
```

## Shipping Method Setup

1. Go to **WooCommerce → Settings → Shipping**
2. Select shipping zone
3. Find the needed shipping method and open it for editing
4. Look at the URL parameter `instance_id` (e.g., `instance_id=2`)
5. Add this ID to the `ODD_ENABLED_SHIPPING_INSTANCE_IDS` array

## How it Works

### On checkout page:

1. **Autocomplete**: Address suggestions appear from Google Places when typing
2. **Field filling**: Related fields are automatically filled when selecting an address
3. **Validation**: Address is verified via Google Geocoding API
4. **Calculation**: Distance from store to customer is calculated
5. **Rate application**: Delivery cost is updated according to zone

### Pricing Zones:

| Zone | Distance | Cost |
|------|----------|------|
| 1    | < 1 km   | 100 CZK |
| 2    | 1-3 km   | 150 CZK |  
| 3    | 3-6 km   | 160 CZK |
| -    | > 6 km   | Unavailable |

## File Structure

```
outcomer-delivery-distance/
├── outcomer-delivery-distance.php  # Main file
├── includes/                       # PHP classes
│   ├── autoload.php               # PSR-4 autoloader
│   ├── Plugin.php                 # Main plugin class
│   ├── DistanceCalculator.php     # Distance calculations
│   ├── Geocoder.php              # Google API integration
│   ├── AjaxHandler.php           # AJAX endpoints
│   ├── CheckoutHandler.php       # Checkout integration
│   └── ShippingCalculator.php    # Delivery cost calculation
├── assets/
│   └── js/
│       └── checkout-autocomplete.js # Frontend JavaScript
├── PRD.md                         # Technical specification
├── IMPLEMENTATION_PLAN.md         # Development plan
└── README.md                      # This documentation
```

## Debugging

### Enable Logging

Enable WordPress debug logging in `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

### Logs

- API errors are written to standard WordPress log
- Validation problems are displayed on checkout page
- Delivery data is saved in order metadata

### Order Data Verification

After order creation, check metadata:

- `_outcomer_delivery_lat` - address latitude
- `_outcomer_delivery_lng` - address longitude  
- `_outcomer_delivery_distance` - distance in km
- `_outcomer_delivery_zone` - delivery zone

## Troubleshooting

### Autocomplete Not Working

1. Check that Google API key is correct and active
2. Make sure Places API and Maps JavaScript API are enabled
3. Check browser console for JavaScript errors

### Address Validation Not Working

1. Check that Geocoding API is enabled
2. Make sure API limits are not exceeded
3. Check WordPress logs for API errors

### Delivery Cost Not Updating

1. Make sure shipping method instance_id is added to configuration
2. Check that correct shipping method is selected
3. Make sure address is within delivery zone

## Security

- All AJAX requests are protected with WordPress nonce
- Input data goes through sanitization
- Output data goes through escaping
- API key is passed only in controlled places

## Performance

- Geocoding results are cached for 15 minutes
- Autocomplete suggestions are cached for 5 minutes
- Minimal number of API requests
- Lazy loading Google Maps API

## Support

For technical support, create an issue in the project repository.

## Changelog

### 1.0.0
- First release
- Basic autocomplete and delivery calculation functionality
- Support only for addresses in Czech Republic
- Pricing zones 1-3 with 6km limitation