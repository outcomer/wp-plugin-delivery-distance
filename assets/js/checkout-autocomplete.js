// Google Maps callback function
window.initGoogleMapsCallback = function () {
	window.googleMapsReady = true;
	// Trigger custom event to notify that Google Maps is ready
	jQuery(document).trigger('googlemaps:ready');
};

// Wait for DOM and jQuery to be ready
jQuery(document).ready(function ($) {
	let selectedIndex = -1;
	let predictions = [];
	let activeInput = null;
	let activeSuggestions = null;

	// Wait for Google Maps to be ready before initializing
	function initWhenReady() {
		if (window.googleMapsReady) {
			initAutocomplete('#billing_address_1');
			initAutocomplete('#shipping_address_1');
		} else {
			// If Google Maps is not ready, wait for it
			$(document).on('googlemaps:ready', function () {
				initAutocomplete('#billing_address_1');
				initAutocomplete('#shipping_address_1');
			});
		}
	}

	initWhenReady();

	function initAutocomplete(inputSelector) {
		const input = $(inputSelector)[0];
		if (!input) return;

		// Clone wrapper template from PHP-generated HTML
		const template = $('#outcomer-autocomplete-templates .outcomer-autocomplete-wrapper');
		if (!template.length) {
			return;
		}

		const wrapper = template.clone()[0];

		// Get references to elements in cloned wrapper
		const searchIcon = wrapper.querySelector('.outcomer-search-icon');
		const clearButton = wrapper.querySelector('.outcomer-clear-button');
		const suggestionsContainer = wrapper.querySelector('.outcomer-autocomplete-suggestions');

		// Insert wrapper before input
		input.parentNode.insertBefore(wrapper, input);

		// Move input into wrapper (after search icon, before clear button)
		searchIcon.insertAdjacentElement('afterend', input);

		// Add class to input
		$(input).addClass('outcomer-with-icons');

		// Clear button handler
		$(clearButton).on('click', function (e) {
			e.preventDefault();
			input.value = '';
			$(input).trigger('input').trigger('change');
			clearButton.style.display = 'none';
			hideSuggestions();
		});

		// Show clear button if input has initial value
		if (input.value.length > 0) {
			clearButton.style.display = 'block';
		}

		// Bind events to this specific input
		bindAutocompleteEvents(input, suggestionsContainer, clearButton);
	}

	function bindAutocompleteEvents(input, suggestionsContainer, clearButton) {
		// Input event handler
		$(input).on('input', async function () {
			const query = this.value;
			activeInput = this;
			activeSuggestions = suggestionsContainer;

			// Show/hide clear button based on input value
			if (query.length > 0) {
				clearButton.style.display = 'block';
			} else {
				clearButton.style.display = 'none';
			}

			if (query.length < 2) {
				hideSuggestions();
				return;
			}

			predictions = await getPredictions(query);
			selectedIndex = -1;
			renderSuggestions(predictions, suggestionsContainer);
		});

		// Keydown event handler
		$(input).on('keydown', function (e) {
			handleKeyNavigation(e, suggestionsContainer);
		});

		// Hide suggestions when input loses focus (with delay for clicks)
		$(input).on('blur', function () {
			setTimeout(() => hideSuggestions(), 150);
		});

		// Show suggestions when input gets focus and has value
		$(input).on('focus', function () {
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

	// Запрос к Google Places API (new AutocompleteSuggestion API)
	async function getPredictions(query) {
		if (!query) return [];

		try {
			// Import the Places library if not already loaded
			const { AutocompleteSuggestion, AutocompleteSessionToken } =
				await google.maps.importLibrary("places");

			// Create a new session token for this search session
			if (!window.outcomerSessionToken) {
				window.outcomerSessionToken = new AutocompleteSessionToken();
			}

			// Create request object with the new API structure
			const request = {
				input: query,
				includedRegionCodes: outcomerDelivery.countryRestrict, // Changed from componentRestrictions.country
				sessionToken: window.outcomerSessionToken,
				language: 'cs' // Czech language for Czech Republic
			};

			// Fetch suggestions using the new API
			const { suggestions } = await AutocompleteSuggestion.fetchAutocompleteSuggestions(request);

			// Transform suggestions to match the old format for compatibility
			const predictions = suggestions.map(suggestion => {
				// Extract main text - usually the street/place name
				const fullText = suggestion.placePrediction.text?.text || '';
				const secondaryText = suggestion.placePrediction.secondaryText?.text || '';

				// Try to get just the street/place name without the full address
				let mainText = fullText;
				if (secondaryText && fullText.includes(secondaryText)) {
					// Remove the secondary part from the full text to get just the main part
					mainText = fullText.replace(`, ${secondaryText}`, '').replace(secondaryText, '');
				}

				return {
					place_id: suggestion.placePrediction.placeId,
					description: fullText,
					structured_formatting: {
						main_text: mainText.trim(),
						secondary_text: secondaryText
					},
					_suggestion: suggestion // Store original for later use
				};
			});

			return predictions;
		} catch (error) {
			console.error('Error fetching predictions:', error);
			return [];
		}
	}

	// Render suggestions list
	function renderSuggestions(items, container) {
		container.innerHTML = '';
		if (items.length === 0) {
			$(container).hide();
			return;
		}

		// Get item template
		const itemTemplate = $('#outcomer-autocomplete-templates .outcomer-autocomplete-item');
		if (!itemTemplate.length) {
			return;
		}

		items.forEach((item, i) => {
			// Clone template
			const itemElement = itemTemplate.clone()[0];

			// Fill in the text
			const mainText = itemElement.querySelector('.outcomer-autocomplete-item-main-text');
			const secondaryText = itemElement.querySelector('.outcomer-autocomplete-item-secondary-text');

			mainText.textContent = item.structured_formatting.main_text || item.description;

			if (item.structured_formatting.secondary_text) {
				secondaryText.textContent = item.structured_formatting.secondary_text;
			} else {
				// Hide secondary text if not needed
				secondaryText.style.display = 'none';
			}

			// Add click handler
			itemElement.addEventListener('click', () => selectSuggestion(i));

			// Append to container
			container.appendChild(itemElement);
		});

		// Add "Powered by" footer
		const poweredByTemplate = $('#outcomer-autocomplete-templates .outcomer-powered-by');
		if (poweredByTemplate.length) {
			const poweredByElement = poweredByTemplate.clone()[0];
			container.appendChild(poweredByElement);
		}

		$(container).show();
	}

	// Select suggestion
	async function selectSuggestion(index) {
		const item = predictions[index];
		if (!item || !activeInput) return;

		try {
			// If we have the new suggestion object, use it
			if (item._suggestion) {
				// Convert to Place and fetch required fields
				const place = await item._suggestion.placePrediction.toPlace();
				await place.fetchFields({
					fields: ['formattedAddress', 'location', 'addressComponents']
				});

				// Clear the session token after place selection (ends the session)
				window.outcomerSessionToken = null;

				// Update the active input field
				activeInput.value = place.formattedAddress || '';
				$(activeInput).trigger('change');

				// Transform to match old format for fillAddressFieldsNew
				const placeData = {
					formatted_address: place.formattedAddress,
					address_components: place.addressComponents,
					geometry: {
						location: place.location
					}
				};

				// Fill all address fields
				fillAddressFieldsNew(placeData, null);

				// Validate and calculate delivery cost
				if (place.location) {
					validateAddressFromClient(place.formattedAddress);
				}

				// Hide suggestions
				hideSuggestions();
			} else {
				// Fallback to old API if somehow we don't have the new suggestion
				const service = new google.maps.places.PlacesService(document.createElement('div'));
				service.getDetails({
					placeId: item.place_id,
					fields: ['formatted_address', 'geometry', 'address_components']
				}, (place, status) => {
					if (status === google.maps.places.PlacesServiceStatus.OK) {
						activeInput.value = place.formatted_address || '';
						$(activeInput).trigger('change');
						fillAddressFieldsNew(place, null);
						if (place.geometry?.location) {
							validateAddressFromClient(place.formatted_address);
						}
						hideSuggestions();
					}
				});
			}
		} catch (error) {
			console.error('Error selecting suggestion:', error);
		}
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
					validateAddressFromClient(results[0].formatted_address);
				}
			}
		});
	}

	// Initialize prefill after a delay to ensure WooCommerce has populated fields
	setTimeout(initializePrefill, 500);

	// Function to fill address fields from place data
	window.fillAddressFieldsNew = function (place, fieldSet) {

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
		// Show delivery information
		showDeliveryInfo(data);
	}

	function handleInvalidAddress(errorMessage) {
		clearDeliveryMessages();
		showDeliveryError(errorMessage);
	}

	function getDeliveryMessageContainer() {
		// Always use the container in review order table
		return $('.outcomer-delivery-messages').first();
	}

	function showDeliveryInfo(data) {
		const container = getDeliveryMessageContainer();
		container.removeClass('woocommerce-error')
			.html(`
				<strong>${outcomerDelivery.strings.deliveryInfo}</strong><br>
				${outcomerDelivery.strings.distance} ${data.distance}km<br>
				${outcomerDelivery.strings.zone} ${data.zone}<br>
				${outcomerDelivery.strings.deliveryCost} ${data.price} ${outcomerDelivery.currency}
			`).show();
	}

	function showDeliveryError(message) {
		const container = getDeliveryMessageContainer();
		container.addClass('woocommerce-error')
			.html(`<strong>${outcomerDelivery.strings.deliveryError}</strong> ${message}`).show();
	}

	function showDeliveryLoading() {
		const container = getDeliveryMessageContainer();
		container.removeClass('woocommerce-error')
			.html(outcomerDelivery.strings.validatingAddress).show();
	}

	function hideDeliveryLoading() {
		const container = getDeliveryMessageContainer();
		container.hide();
	}

	function clearDeliveryMessages() {
		$('.outcomer-delivery-messages').hide();
	}

	window.validateAddressFromClient = function (address) {
		if (!address) return;

		// Show loading indicator
		showDeliveryLoading();

		const data = {
			action: 'outcomer_calculate_delivery',
			nonce: outcomerDelivery.nonce,
			address: address
			// Server will geocode the address itself for security
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
