jQuery(document).ready(function($) {
    'use strict';
    
    // Wait for Google Maps API to be loaded
    function waitForGoogleMaps(callback) {
        if (typeof google !== 'undefined' && google.maps && google.maps.places) {
            callback();
        } else {
            setTimeout(() => waitForGoogleMaps(callback), 100);
        }
    }
    
    // Initialize autocomplete when Google Maps is ready
    waitForGoogleMaps(function() {
        initAddressAutocomplete();
    });
    
    function initAddressAutocomplete() {
        const addressFields = [
            {
                input: '#billing_address_1',
                city: '#billing_city',
                postcode: '#billing_postcode'
            },
            {
                input: '#shipping_address_1',
                city: '#shipping_city',
                postcode: '#shipping_postcode'
            }
        ];
        
        addressFields.forEach(fieldSet => {
            const inputField = $(fieldSet.input);
            if (inputField.length === 0) return;
            
            setupAutocomplete(inputField[0], fieldSet);
        });
    }
    
    function setupAutocomplete(inputElement, fieldSet) {
        const autocomplete = new google.maps.places.Autocomplete(inputElement, {
            types: ['address'],
            componentRestrictions: {
                country: outcomerDelivery.countryRestrict
            }
        });
        
        autocomplete.addListener('place_changed', function() {
            const place = autocomplete.getPlace();
            
            if (!place.geometry) {
                console.log('No geometry data for place:', place.name);
                return;
            }
            
            // Fill address fields
            fillAddressFields(place, fieldSet);
            
            // Validate and calculate delivery cost using coordinates
            if (place.geometry && place.geometry.location) {
                validateAddressWithCoordinates(
                    place.formatted_address, 
                    place.geometry.location.lat(), 
                    place.geometry.location.lng()
                );
            }
        });
        
        // Handle manual input - disable for now as we need coordinates
        // Manual input would require geocoding on backend
    }
    
    function fillAddressFields(place, fieldSet) {
        const components = place.address_components || [];
        let streetNumber = '';
        let premise = '';
        let route = '';
        let city = '';
        let postalCode = '';
        
        components.forEach(component => {
            const types = component.types;
            
            if (types.includes('street_number')) {
                streetNumber = component.long_name;
            }
            if (types.includes('premise')) {
                premise = component.long_name;
            }
            if (types.includes('route')) {
                route = component.long_name;
            }
            if (types.includes('locality') || types.includes('sublocality') || types.includes('administrative_area_level_1')) {
                city = component.long_name;
            }
            if (types.includes('postal_code')) {
                postalCode = component.long_name;
            }
        });
        
        // Build address: "U Radosti 225/1"
        let addressParts = [];
        if (route) addressParts.push(route);
        if (premise && streetNumber) {
            addressParts.push(premise + '/' + streetNumber);
        } else if (premise) {
            addressParts.push(premise);
        } else if (streetNumber) {
            addressParts.push(streetNumber);
        }
        
        const fullAddress = addressParts.join(' ');
        if (fullAddress) {
            $(fieldSet.input).val(fullAddress);
        }
        
        // Fill city
        if (city && $(fieldSet.city).length) {
            $(fieldSet.city).val(city);
        }
        
        // Fill postcode
        if (postalCode && $(fieldSet.postcode).length) {
            $(fieldSet.postcode).val(postalCode);
        }
    }
    
    function validateAddressWithCoordinates(address, lat, lng) {
        if (!address || !lat || !lng) return;
        
        // Show loading indicator
        showDeliveryLoading();
        
        const data = {
            action: 'outcomer_calculate_delivery',
            nonce: outcomerDelivery.nonce,
            address: address,
            lat: lat,
            lng: lng
        };
        
        $.ajax({
            url: outcomerDelivery.ajaxUrl,
            type: 'POST',
            data: data,
            success: function(response) {
                hideDeliveryLoading();
                
                if (response.success) {
                    handleValidAddress(response.data);
                    // Trigger WooCommerce checkout update
                    $('body').trigger('update_checkout');
                } else {
                    handleInvalidAddress(response.data);
                }
            },
            error: function(xhr, status, error) {
                hideDeliveryLoading();
                console.error('Address validation error:', error);
                showDeliveryError('Network error occurred while validating address');
            }
        });
    }
    
    function handleValidAddress(data) {
        clearDeliveryMessages();
        
        // Store delivery data in session/form
        storeDeliveryData(data);
        
        // Show delivery information
        showDeliveryInfo(data);
    }
    
    function handleInvalidAddress(errorMessage) {
        clearDeliveryMessages();
        showDeliveryError(errorMessage);
    }
    
    function storeDeliveryData(data) {
        // Store in hidden fields for checkout processing
        let hiddenContainer = $('#outcomer-delivery-data');
        if (hiddenContainer.length === 0) {
            hiddenContainer = $('<div id="outcomer-delivery-data" style="display:none;"></div>');
            $('.checkout.woocommerce-checkout').append(hiddenContainer);
        }
        
        hiddenContainer.html(`
            <input type="hidden" name="outcomer_delivery_lat" value="${data.coordinates.lat}">
            <input type="hidden" name="outcomer_delivery_lng" value="${data.coordinates.lng}">
            <input type="hidden" name="outcomer_delivery_distance" value="${data.distance}">
            <input type="hidden" name="outcomer_delivery_zone" value="${data.zone}">
            <input type="hidden" name="outcomer_delivery_price" value="${data.price}">
        `);
    }
    
    function showDeliveryInfo(data) {
        const container = $('#outcomer-delivery-messages');
        container.removeClass('woocommerce-error').addClass('woocommerce-info')
            .html(`
                <strong>Delivery Information:</strong><br>
                Distance: ${data.distance}km<br>
                Zone: ${data.zone}<br>
                Delivery cost: ${data.price} CZK
            `).show();
    }
    
    function showDeliveryError(message) {
        const container = $('#outcomer-delivery-messages');
        container.removeClass('woocommerce-info').addClass('woocommerce-error')
            .html(`<strong>Delivery Error:</strong> ${message}`).show();
    }
    
    function showDeliveryLoading() {
        const container = $('#outcomer-delivery-messages');
        container.removeClass('woocommerce-error').addClass('woocommerce-info')
            .html('Validating address...').show();
    }
    
    function hideDeliveryLoading() {
        $('#outcomer-delivery-messages').hide();
    }
    
    function clearDeliveryMessages() {
        $('#outcomer-delivery-messages').hide();
    }
});