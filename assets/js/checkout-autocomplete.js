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
            
            // Validate and calculate delivery cost
            validateAddress(place.formatted_address, place.place_id);
        });
        
        // Handle manual input
        let timeout;
        $(inputElement).on('input', function() {
            clearTimeout(timeout);
            const value = $(this).val();
            
            if (value.length >= 3) {
                timeout = setTimeout(() => {
                    validateAddress(value);
                }, 1000);
            }
        });
    }
    
    function fillAddressFields(place, fieldSet) {
        const components = place.address_components || [];
        let streetNumber = '';
        let route = '';
        let city = '';
        let postalCode = '';
        
        components.forEach(component => {
            const types = component.types;
            
            if (types.includes('street_number')) {
                streetNumber = component.long_name;
            }
            if (types.includes('route')) {
                route = component.long_name;
            }
            if (types.includes('locality') || types.includes('administrative_area_level_1')) {
                city = component.long_name;
            }
            if (types.includes('postal_code')) {
                postalCode = component.long_name;
            }
        });
        
        // Fill address field
        const fullAddress = [streetNumber, route].filter(Boolean).join(' ');
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
    
    function validateAddress(address, placeId = null) {
        if (!address || address.length < 3) return;
        
        // Show loading indicator
        showDeliveryLoading();
        
        const data = {
            action: 'outcomer_validate_address',
            nonce: outcomerDelivery.nonce,
            address: address
        };
        
        if (placeId) {
            data.place_id = placeId;
        }
        
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
        let infoContainer = $('#outcomer-delivery-info');
        if (infoContainer.length === 0) {
            infoContainer = $('<div id="outcomer-delivery-info" class="woocommerce-info"></div>');
            $('.checkout.woocommerce-checkout').prepend(infoContainer);
        }
        
        infoContainer.html(`
            <strong>Delivery Information:</strong><br>
            Distance: ${data.distance}km<br>
            Zone: ${data.zone}<br>
            Delivery cost: ${data.price} CZK
        `).show();
    }
    
    function showDeliveryError(message) {
        let errorContainer = $('#outcomer-delivery-error');
        if (errorContainer.length === 0) {
            errorContainer = $('<div id="outcomer-delivery-error" class="woocommerce-error"></div>');
            $('.checkout.woocommerce-checkout').prepend(errorContainer);
        }
        
        errorContainer.html(`<strong>Delivery Error:</strong> ${message}`).show();
    }
    
    function showDeliveryLoading() {
        let loadingContainer = $('#outcomer-delivery-loading');
        if (loadingContainer.length === 0) {
            loadingContainer = $('<div id="outcomer-delivery-loading" class="woocommerce-info">Validating address...</div>');
            $('.checkout.woocommerce-checkout').prepend(loadingContainer);
        }
        loadingContainer.show();
    }
    
    function hideDeliveryLoading() {
        $('#outcomer-delivery-loading').hide();
    }
    
    function clearDeliveryMessages() {
        $('#outcomer-delivery-info, #outcomer-delivery-error').hide();
    }
});