// Wait for DOM and jQuery to be ready
jQuery(document).ready(function($) {
	let selectedIndex = -1;
	let predictions = [];
	let activeInput = null;
	let activeSuggestions = null;

	// Initialize autocomplete for address fields
	initAutocomplete('#billing_address_1');
	initAutocomplete('#shipping_address_1');

	function initAutocomplete(inputSelector) {
		const input = $(inputSelector)[0];
		if (!input) return;

		// Create suggestions container
		const suggestionsContainer = document.createElement('div');
		suggestionsContainer.className = 'outcomer-autocomplete-suggestions';
		suggestionsContainer.style.display = 'none';
		input.parentNode.insertBefore(suggestionsContainer, input.nextSibling);

		// Bind events to this specific input
		bindAutocompleteEvents(input, suggestionsContainer);
	}

	function bindAutocompleteEvents(input, suggestionsContainer) {
		// Input event handler
		$(input).on('input', async function() {
			const query = this.value;
			activeInput = this;
			activeSuggestions = suggestionsContainer;
			
			if (query.length < 2) {
				hideSuggestions();
				return;
			}

			predictions = await getPredictions(query);
			selectedIndex = -1;
			renderSuggestions(predictions, suggestionsContainer);
		});

		// Keydown event handler
		$(input).on('keydown', function(e) {
			handleKeyNavigation(e, suggestionsContainer);
		});

		// Hide suggestions when input loses focus (with delay for clicks)
		$(input).on('blur', function() {
			setTimeout(() => hideSuggestions(), 150);
		});

		// Show suggestions when input gets focus and has value
		$(input).on('focus', function() {
			if (this.value && predictions.length > 0) {
				showSuggestions(suggestionsContainer);
			}
		});
	}

	function hideSuggestions() {
		$('.outcomer-autocomplete-suggestions').hide();
	}

	function showSuggestions(container) {
		$('.outcomer-autocomplete-suggestions').hide(); // Hide others
		$(container).show();
	}

// Запрос к Google Places API
async function getPredictions(query) {
	if (!query) return [];
	return new Promise((resolve) => {
		const service = new google.maps.places.AutocompleteService();
		service.getPlacePredictions(
			{ input: query, componentRestrictions: { country: outcomerDelivery.countryRestrict } },
			(preds, status) => {
				if (status === google.maps.places.PlacesServiceStatus.OK) {
					resolve(preds);
				} else {
					resolve([]);
				}
			}
		);
	});
}

	// Render suggestions list
	function renderSuggestions(items, container) {
		container.innerHTML = '';
		if (items.length === 0) {
			$(container).hide();
			return;
		}
		
		items.forEach((item, i) => {
			const div = document.createElement('div');
			div.className = 'outcomer-autocomplete-item';
			div.textContent = item.description;
			div.addEventListener('click', () => selectSuggestion(i));
			container.appendChild(div);
		});
		
		$(container).show();
	}

	// Select suggestion
	function selectSuggestion(index) {
		const item = predictions[index];
		if (!item || !activeInput) return;

		const service = new google.maps.places.PlacesService(document.createElement('div'));
		service.getDetails({ 
			placeId: item.place_id, 
			fields: ['formatted_address', 'geometry', 'address_components'] 
		}, (place, status) => {
			if (status === google.maps.places.PlacesServiceStatus.OK) {
				// Update the active input field
				activeInput.value = place.formatted_address || '';
				$(activeInput).trigger('change');
				
				// Fill all address fields
				fillAddressFieldsNew(place, null);
				
				// Validate and calculate delivery cost
				if (place.geometry?.location) {
					const lat = place.geometry.location.lat();
					const lng = place.geometry.location.lng();
					validateAddressWithCoordinates(place.formatted_address, lat, lng);
				}
				
				// Hide suggestions
				hideSuggestions();
			}
		});
	}

	// Handle keyboard navigation
	function handleKeyNavigation(e, container) {
		const items = container.querySelectorAll('.outcomer-autocomplete-item');
		if (!items.length) return;

		if (e.key === 'ArrowDown') {
			selectedIndex = (selectedIndex + 1) % items.length;
			updateActive(container);
			e.preventDefault();
		} else if (e.key === 'ArrowUp') {
			selectedIndex = (selectedIndex - 1 + items.length) % items.length;
			updateActive(container);
			e.preventDefault();
		} else if (e.key === 'Enter') {
			if (selectedIndex >= 0) {
				selectSuggestion(selectedIndex);
				e.preventDefault();
			}
		} else if (e.key === 'Escape') {
			hideSuggestions();
			e.preventDefault();
		}
	}

	function updateActive(container) {
		const items = container.querySelectorAll('.outcomer-autocomplete-item');
		items.forEach((el, i) => el.classList.toggle('active', i === selectedIndex));
	}

	// Initialize prefill for existing addresses
	function initializePrefill() {
		const billingInput = $('#billing_address_1')[0];
		const shippingInput = $('#shipping_address_1')[0];
		
		[billingInput, shippingInput].forEach(input => {
			if (input && input.value) {
				geocodeExistingAddress(input.value);
			}
		});
	}
	
	function geocodeExistingAddress(address) {
		const geocoder = new google.maps.Geocoder();
		geocoder.geocode({ 
			address: address, 
			componentRestrictions: { country: outcomerDelivery.countryRestrict[0] || 'CZ' } 
		}, (results, status) => {
			if (status === google.maps.GeocoderStatus.OK && results.length) {
				fillAddressFieldsNew(results[0], null);
				if (results[0].geometry?.location) {
					const lat = results[0].geometry.location.lat();
					const lng = results[0].geometry.location.lng();
					validateAddressWithCoordinates(results[0].formatted_address, lat, lng);
				}
			}
		});
	}
	
	// Initialize prefill after a delay to ensure WooCommerce has populated fields
	setTimeout(initializePrefill, 500);

	// Function to fill address fields from place data
	window.fillAddressFieldsNew = function(place, fieldSet) {
	
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
		$('#billing_address_1').val(fullAddress).trigger('change');
		$('#shipping_address_1').val(fullAddress).trigger('change');
	}

	// Fill city
	if (city) {
		const billingCity = $('#billing_city');
		const shippingCity = $('#shipping_city');
		
		if (billingCity.length) {
			billingCity.val(city).trigger('change');
		}
		if (shippingCity.length) {
			shippingCity.val(city).trigger('change');
		}
	}

	// Fill postcode
	if (postalCode) {
		const billingPostcode = $('#billing_postcode');
		const shippingPostcode = $('#shipping_postcode');
		
		if (billingPostcode.length) {
			billingPostcode.val(postalCode).trigger('change');
		}
		if (shippingPostcode.length) {
			shippingPostcode.val(postalCode).trigger('change');
		}
	}
	
		// Trigger WooCommerce update after all fields are filled
		setTimeout(() => {
			$('body').trigger('update_checkout');
		}, 100);
	};

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
		container.removeClass('woocommerce-error')
			.html(`
				<strong>Delivery Information:</strong><br>
				Distance: ${data.distance}km<br>
				Zone: ${data.zone}<br>
				Delivery cost: ${data.price} CZK
			`).show();
	}

	function showDeliveryError(message) {
		const container = $('#outcomer-delivery-messages');
		container.addClass('woocommerce-error')
			.html(`<strong>Delivery Error:</strong> ${message}`).show();
	}

	function showDeliveryLoading() {
		const container = $('#outcomer-delivery-messages');
		container.removeClass('woocommerce-error')
			.html('Validating address...').show();
	}

	function hideDeliveryLoading() {
		$('#outcomer-delivery-messages').hide();
	}

	function clearDeliveryMessages() {
		$('#outcomer-delivery-messages').hide();
	}

	window.validateAddressWithCoordinates = function(address, lat, lng) {
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
			success: function (response) {
				hideDeliveryLoading();

				if (response.success) {
					handleValidAddress(response.data);
					// Trigger WooCommerce checkout update
					$('body').trigger('update_checkout');
				} else {
					handleInvalidAddress(response.data);
				}
			},
			error: function (xhr, status, error) {
				hideDeliveryLoading();
				console.error('Address validation error:', error);
				showDeliveryError('Network error occurred while validating address');
			}
		});
	};

}); // End jQuery ready
