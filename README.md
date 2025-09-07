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
- Two Google API keys with activated services:
  - **Browser key**: Places API (New), Maps JavaScript API
  - **Server key**: Geocoding API, Places API

## Installation

1. Upload the plugin folder to `wp-content/plugins/`
2. Activate the plugin in WordPress admin panel
3. Make sure WooCommerce is active

## Configuration

All settings are located in the `outcomer-delivery-distance.php` file:

```php
// Google API keys
define('ODD_GOOGLE_API_KEY_BROWSER', 'your_browser_key_here'); // With referer restrictions
define('ODD_GOOGLE_API_KEY_SERVER', 'your_server_key_here');   // With IP restrictions

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

### Google API Key Setup

**Browser Key (for frontend autocomplete):**
1. Create API key in Google Cloud Console
2. Set Application restrictions: HTTP referrers
3. Add your domain: `yourdomain.com/*`, `*.yourdomain.com/*`
4. Set API restrictions: Places API (New), Maps JavaScript API

**Server Key (for backend validation):**
1. Create separate API key in Google Cloud Console
2. Set Application restrictions: IP addresses
3. Add your server IP address
4. Set API restrictions: Geocoding API, Places API

### Autocomplete Not Working

1. Check that browser API key is correct and active
2. Make sure Places API (New) and Maps JavaScript API are enabled
3. Verify referer restrictions include your domain
4. Check browser console for JavaScript errors

### Address Validation Not Working

1. Check that server API key is correct and active
2. Make sure Geocoding API and Places API are enabled
3. Verify IP restrictions include your server IP
4. Make sure API limits are not exceeded
5. Check WordPress logs for API errors

### Delivery Cost Not Updating

1. Make sure shipping method instance_id is added to configuration
2. Check that correct shipping method is selected
3. Make sure address is within delivery zone

## Security

- **Two-key architecture**: Separate API keys for client and server prevent misuse
- **Server-side validation**: All coordinates are calculated on server, not trusted from client
- **AJAX protection**: All requests protected with WordPress nonce
- **Data sanitization**: All input data goes through sanitization
- **Output escaping**: All output data goes through escaping
- **API key restrictions**: Browser key restricted by referer, server key by IP address

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
- Google Places autocomplete with new AutocompleteSuggestion API
- Server-side address validation and distance calculation
- Two-key security architecture (browser + server keys)
- Support for addresses in Czech Republic
- Distance-based pricing with 3 delivery zones (max 6km)
- Comprehensive localization support (Czech + English)
- Template-based UI with search and clear icons